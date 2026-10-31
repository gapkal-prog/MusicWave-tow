<?php
/**
 * One-shot HLS packager with AES-128 segment encryption.
 *
 * Calls ffmpeg/ffprobe (configurable binaries) to produce a master playlist,
 * variant playlists, and encrypted TS segments for each configured rendition.
 * Packaging runs in a background job, never inside the HTTP request that
 * triggered it.
 *
 * Segment keys are wrapped by `AssetKeyStore`. ffmpeg requires the raw 16-byte
 * key on disk (`-hls_key_info_file`); it is written to a `0600` temporary file
 * outside the web root for the duration of the run and removed on every exit
 * path, so no unwrapped key survives packaging.
 *
 * @package ManaCore\MusicWave\Vip\Hls
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Hls;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * This class is a thin ffmpeg/ffprobe driver: it shells out to the configured
 * binaries and writes segment files directly inside the protected storage root.
 * Neither operation is expressible through WP_Filesystem or the HTTP wrappers,
 * so the low-level calls below are intentional.
 */
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec, WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_is_writable, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir

/**
 * @immutable
 */
final class HlsPackager {
	/** Default output segment duration in seconds. */
	public const SEGMENT_SECONDS = 6;

	/** @var string ffmpeg executable. */
	private $ffmpeg;
	/** @var string ffprobe executable. */
	private $ffprobe;
	/** @var HlsRenditions */
	private $renditions;
	/** @var AssetKeyStore */
	private $key_store;
	/** @var string Base directory for packaged output (inside protected root). */
	private $output_dir;
	/** @var string Base URL for segment manifest placeholders. */
	private $segment_base_url;
	/** @var int Target segment duration in seconds. */
	private $segment_seconds;

	public function __construct(
		string $ffmpeg,
		string $ffprobe,
		HlsRenditions $renditions,
		AssetKeyStore $key_store,
		string $output_dir = '',
		string $segment_base_url = '',
		int $segment_seconds = self::SEGMENT_SECONDS
	) {
		$this->ffmpeg           = $ffmpeg;
		$this->ffprobe          = $ffprobe;
		$this->renditions       = $renditions;
		$this->key_store        = $key_store;
		$this->output_dir       = $output_dir;
		$this->segment_base_url = $segment_base_url;
		$this->segment_seconds  = max( 2, min( 15, $segment_seconds ) );
	}

	/**
	 * Check whether ffmpeg and ffprobe are executable.
	 */
	public function available(): bool {
		return '' !== $this->ffmpeg && $this->executable_exists( $this->ffmpeg )
			&& '' !== $this->ffprobe && $this->executable_exists( $this->ffprobe );
	}

	/**
	 * Package a source audio file into AES-128 encrypted HLS renditions.
	 *
	 * @param string $source_path Absolute path to the source file.
	 * @param string $asset_key   Opaque `vip:<32hex>` asset identifier.
	 * @return array{manifest: string, renditions: list<string>, bytes: int, duration: int}|WP_Error
	 */
	public function package( string $source_path, string $asset_key ) {
		if ( ! $this->available() ) {
			return new WP_Error(
				'mw_hls_ffmpeg_missing',
				__( 'ffmpeg/ffprobe روی سرور در دسترس نیست؛ بسته‌بندی HLS امکان‌پذیر نیست.', 'music-wave-vip' ),
				array( 'status' => 500 )
			);
		}
		if ( ! is_file( $source_path ) || ! is_readable( $source_path ) ) {
			return new WP_Error(
				'mw_hls_source_missing',
				__( 'فایل منبع برای بسته‌بندی یافت نشد.', 'music-wave-vip' ),
				array( 'status' => 404 )
			);
		}

		$asset_dir = $this->asset_output_dir( $asset_key );
		if ( ! @mkdir( $asset_dir, 0750, true ) && ! is_dir( $asset_dir ) ) {
			return new WP_Error(
				'mw_hls_output_dir',
				__( 'پوشهٔ خروجی بسته‌بندی قابل ایجاد نیست.', 'music-wave-vip' ),
				array( 'status' => 500 )
			);
		}

		// Derive + wrap the AES key for this asset (fail-closed if unavailable).
		$raw_key = $this->key_store->create( $asset_key );
		if ( null === $raw_key ) {
			$this->cleanup_dir( $asset_dir );
			return new WP_Error(
				'mw_hls_key_unavailable',
				__( 'کلید رمزنگاری HLS قابل تولید نیست (ثبت AUTH_KEY و SECURE_AUTH_SALT را بررسی کنید).', 'music-wave-vip' ),
				array( 'status' => 500 )
			);
		}

		$duration = $this->probe_duration( $source_path );
		if ( $duration < 1 ) {
			$this->cleanup_dir( $asset_dir );
			return new WP_Error(
				'mw_hls_duration_unknown',
				__( 'مدت فایل صوتی قابل شناسایی نیست.', 'music-wave-vip' ),
				array( 'status' => 400 )
			);
		}

		$key_info = $this->write_key_info_file( $asset_dir, $raw_key );
		if ( false === $key_info ) {
			$this->cleanup_dir( $asset_dir );
			return new WP_Error(
				'mw_hls_key_info_failed',
				__( 'نوشتن فایل موقت کلید ffmpeg ناموفق بود.', 'music-wave-vip' ),
				array( 'status' => 500 )
			);
		}

		$variant_playlists = array();
		$total_bytes       = 0;

		foreach ( $this->renditions->bitrates() as $bitrate ) {
			$result = $this->package_rendition( $source_path, $asset_dir, $bitrate, $key_info['info'] );
			if ( is_wp_error( $result ) ) {
				$this->remove_key_info_files( $key_info );
				$this->cleanup_dir( $asset_dir );
				return $result;
			}
			$variant_playlists[] = $result['playlist'];
			$total_bytes        += (int) $result['bytes'];
		}

		$this->remove_key_info_files( $key_info );

		$manifest = $this->write_master_playlist( $asset_dir, $variant_playlists );
		if ( is_wp_error( $manifest ) ) {
			$this->cleanup_dir( $asset_dir );
			return $manifest;
		}

		return array(
			'manifest'   => $manifest,
			'renditions' => $variant_playlists,
			'bytes'      => $total_bytes,
			'duration'   => $duration,
		);
	}

	/**
	 * Probe duration via ffprobe.
	 *
	 * @return int Duration in seconds (0 when unknown).
	 */
	public function probe_duration( string $source_path ): int {
		$cmd    = escapeshellcmd( $this->ffprobe )
			. ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
			. escapeshellarg( $source_path );
		$output = array();
		$return = 0;
		exec( $cmd, $output, $return ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- failures reported via $return.
		if ( 0 !== $return || ! isset( $output[0] ) ) {
			return 0;
		}
		return max( 0, (int) round( (float) $output[0] ) );
	}

	/**
	 * Executable exists and is executable.
	 */
	private function executable_exists( string $binary ): bool {
		if ( '' === $binary ) {
			return false;
		}
		if ( file_exists( $binary ) && is_executable( $binary ) ) {
			return true;
		}

		// `shell_exec()` yields null when the binary is absent, the command is
		// unavailable, or the function is disabled by the host: treat all three
		// as "not found" rather than crashing on a null trim(). The stderr
		// redirect keeps a failed probe from writing into the page output.
		$probe = 'Windows' === PHP_OS_FAMILY ? 'where ' : 'command -v ';
		$redir = 'Windows' === PHP_OS_FAMILY ? ' 2>NUL' : ' 2>/dev/null';
		$which = shell_exec( $probe . escapeshellarg( $binary ) . $redir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return is_string( $which ) && '' !== trim( $which );
	}

	/**
	 * Output directory for one asset (inside protected root/output/hls/<asset_key>/).
	 */
	private function asset_output_dir( string $asset_key ): string {
		if ( '' !== $this->output_dir ) {
			return rtrim( $this->output_dir, '/' ) . '/' . $asset_key;
		}
		return sys_get_temp_dir() . '/music-wave-hls/' . $asset_key;
	}

	/**
	 * Write the temporary key-info file for ffmpeg `-hls_key_info_file`.
	 *
	 * Two files are created outside the web root with mode `0600`: the key-info
	 * file ffmpeg reads, and the raw 16-byte key it points at. Both are returned
	 * so the caller can delete them on every exit path — persisting the raw key
	 * would defeat the wrapped-key design.
	 *
	 * @param string $dir     Preferred directory (used only if the system temp dir is unusable).
	 * @param string $raw_key Raw 16-byte AES key.
	 * @return array{info: string, key: string}|false Paths to both temp files, or false on failure.
	 */
	private function write_key_info_file( string $dir, string $raw_key ) {
		// ffmpeg key info file: line 1 = key URI, line 2 = local key file path.
		// IV is omitted so ffmpeg uses the segment sequence number (standard HLS).
		// The key URI here is a placeholder; HlsRoutes rewrites it to a
		// per-request signed same-origin URL when serving variant playlists.
		$key_uri = $this->segment_base_url . '/key';

		$tmp = sys_get_temp_dir();
		if ( '' === $tmp || ! is_dir( $tmp ) || ! is_writable( $tmp ) ) {
			$tmp = $dir;
		}

		$path = tempnam( $tmp, 'mw_hls_key_info_' );
		if ( false === $path ) {
			return false;
		}
		$key_file = tempnam( $tmp, 'mw_hls_key_bytes_' );
		if ( false === $key_file ) {
			@unlink( $path );
			return false;
		}
		$written = @file_put_contents( $key_file, $raw_key ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $written ) {
			@unlink( $path );
			@unlink( $key_file );
			return false;
		}
		@chmod( $key_file, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$contents = $key_uri . "\n" . $key_file . "\n";
		$written  = @file_put_contents( $path, $contents ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $written ) {
			@unlink( $path );
			@unlink( $key_file );
			return false;
		}
		@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return array(
			'info' => $path,
			'key'  => $key_file,
		);
	}

	/**
	 * Delete the temporary key-info and raw-key files.
	 *
	 * @param array{info: string, key: string} $files Paths returned by write_key_info_file().
	 */
	private function remove_key_info_files( array $files ): void {
		foreach ( array( $files['info'], $files['key'] ) as $file ) {
			if ( '' !== $file && is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}

	/**
	 * Remove a partially written output directory (best-effort).
	 *
	 * A failed packaging run must not leave half-written segments or playlists
	 * behind: the asset directory is keyed by asset and is recreated on retry.
	 *
	 * @param string $dir Asset output directory.
	 */
	private function cleanup_dir( string $dir ): void {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}

		$items = glob( $dir . '/*' );
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( is_file( $item ) ) {
					@unlink( $item ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Package one rendition via ffmpeg.
	 *
	 * @return array{playlist: string, bytes: int}|WP_Error
	 */
	private function package_rendition( string $source_path, string $asset_dir, int $bitrate, string $key_info_file ) {
		$playlist = $asset_dir . '/' . $this->renditions->label( $bitrate ) . '.m3u8';
		$pattern  = $asset_dir . '/' . $this->renditions->label( $bitrate ) . '-%03d.ts';

		$audio_bitrate = max( 32, $bitrate - 8 ); // small overhead headroom.
		$cmd           = sprintf(
			'%s -y -v error -i %s -map a -c:a aac -b:a %dk -f hls -hls_time %d -hls_playlist_type vod -hls_segment_filename %s -hls_key_info_file %s %s',
			escapeshellcmd( $this->ffmpeg ),
			escapeshellarg( $source_path ),
			$audio_bitrate,
			$this->segment_seconds,
			escapeshellarg( $pattern ),
			escapeshellarg( $key_info_file ),
			escapeshellarg( $playlist )
		); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- ffmpeg command built from validated, escaped inputs.
		$return        = 0;
		exec( $cmd, $output, $return ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( 0 !== $return || ! is_file( $playlist ) ) {
			return new WP_Error(
				'mw_hls_rendition_failed',
				sprintf(
					/* translators: %d: rendition bitrate in kbps. */
					__( 'بستهبندی رِندیشن %d کیلوبیت با خطای ffmpeg مواجه شد.', 'music-wave-vip' ),
					$bitrate
				),
				array( 'status' => 500 )
			);
		}

		$bytes = 0;
		foreach ( glob( $asset_dir . '/' . $this->renditions->label( $bitrate ) . '-*.ts' ) as $seg ) {
			$bytes += is_file( $seg ) ? (int) filesize( $seg ) : 0;
		}

		return array(
			'playlist' => $playlist,
			'bytes'    => $bytes,
		);
	}

	/**
	 * Write master playlist (relative URIs; resolved+signed per-request by HlsRoutes).
	 *
	 * @return string|WP_Error Path to master playlist.
	 */
	private function write_master_playlist( string $asset_dir, array $variant_playlists ) {
		$master = $asset_dir . '/index.m3u8';
		$lines  = array( '#EXTM3U', '#EXT-X-VERSION:3' );
		foreach ( $variant_playlists as $variant ) {
			$bitrate = $this->bitrate_from_path( $variant );
			$lines[] = '#EXT-X-STREAM-INF:BANDWIDTH=' . (string) ( $bitrate * 1000 ) . ',CODECS="mp4a.40.2"';
			$lines[] = basename( $variant );
		}
		$result = @file_put_contents( $master, implode( "\n", $lines ) . "\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $result ) {
			return new WP_Error(
				'mw_hls_master_failed',
				__( 'نوشتن پلیلیست اصلی HLS ناموفق بود.', 'music-wave-vip' ),
				array( 'status' => 500 )
			);
		}

		return $master;
	}

	/**
	 * Extract bitrate from variant playlist path (e.g. `128k.m3u8`).
	 */
	private function bitrate_from_path( string $path ): int {
		if ( 1 === preg_match( '/(\d+)k\.m3u8$/', basename( $path ), $matches ) ) {
			return (int) $matches[1];
		}
		return 128;
	}

	private function __clone() {}
}
