<?php
/**
 * Protected-file storage operations for the local VIP provider.
 *
 * Refactored for SOLID:
 * - SRP: root resolution → Storage\RootResolver, path safety → Storage\PathValidator,
 *        MIME validation → Storage\MimeValidator, registry → ProtectedAssetRegistry.
 * - DRY: shared is_within, allowed_extensions, mime detection no longer duplicated.
 * - OCP: allowed extensions filterable via MimeValidator, registry injectable.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip;

use ManaCore\MusicWave\Vip\Delivery\LocalDeliveryDiagnostics;
use ManaCore\MusicWave\Vip\Hls\AssetKeyStore;
use ManaCore\MusicWave\Vip\Hls\HlsPackager;
use ManaCore\MusicWave\Vip\Hls\HlsRenditions;
use ManaCore\MusicWave\Vip\Storage\MimeValidator;
use ManaCore\MusicWave\Vip\Storage\PathValidator;
use ManaCore\MusicWave\Vip\Storage\RootResolver;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use FilesystemIterator;
use WP_Error;

final class ProtectedAssetStorage {

	/** @var ProtectedAssetRegistry */
	private $registry;

	/** @var RootResolver */
	private $root_resolver;

	/** @var array<string, mixed> */
	private $config;

	public function __construct( ?ProtectedAssetRegistry $registry = null, ?RootResolver $root_resolver = null, array $config = array() ) {
		$this->registry      = null !== $registry ? $registry : new ProtectedAssetRegistry();
		$this->root_resolver = null !== $root_resolver ? $root_resolver : new RootResolver();
		$this->config        = $config;
	}

	public function registry(): ProtectedAssetRegistry {
		return $this->registry;
	}

	/**
	 * @return string|false
	 */
	public function root() {
		return $this->root_resolver->resolve();
	}

	public function is_web_reachable( string $directory ): bool {
		return $this->root_resolver->is_web_reachable( $directory );
	}

	/** @return array<string, bool> */
	public function preflight(): array {
		return $this->root_resolver->preflight();
	}

	public function harden_directory( string $root ): void {
		$this->root_resolver->harden_directory( $root );
	}

	/**
	 * Provision (create if absent) and harden the configured or specified protected directory.
	 *
	 * @return string|false Hardened realpath or `false` if inside web root / unwritable.
	 */
	public function provision( string $candidate = '' ) {
		return $this->root_resolver->provision( $candidate );
	}

	/**
	 * Scan all valid files inside the protected root and synchronize SHA-256 inventory rows.
	 *
	 * @return array{scanned: int, registered: int, total_bytes: int}|WP_Error
	 */
	public function sync_inventory() {
		$root = $this->provision();
		if ( false === $root ) {
			$root = $this->root();
		}
		if ( false === $root || ! is_dir( $root ) || ! is_readable( $root ) ) {
			return new WP_Error( 'mw_protected_asset_root', __( 'پوشهٔ فایل‌های حفاظت‌شده در دسترس نیست یا داخل ریشهٔ وب قرار دارد.', 'music-wave-vip' ), array( 'status' => 400 ) );
		}

		$this->harden_directory( $root );

		$auto_refresh = 'enabled' === (string) ( $this->config['local_checksum_autorefresh'] ?? 'enabled' );

		$scanned     = 0;
		$registered  = 0;
		$refreshed   = 0;
		$total_bytes = 0;
		$iterator    = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		/** @var SplFileInfo $file */
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->isLink() ) {
				continue;
			}
			$path = $file->getRealPath();
			if ( false === $path || ! PathValidator::is_within( $path, $root ) ) {
				continue;
			}
			$filename = $file->getFilename();
			if ( in_array( $filename, array( '.htaccess', 'web.config', 'index.html' ), true ) ) {
				continue;
			}
			$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			if ( '' !== $extension && ! in_array( $extension, $this->allowed_extensions(), true ) ) {
				continue;
			}
			++$scanned;
			$size         = max( 0, (int) $file->getSize() );
			$total_bytes += $size;
			$relative     = ltrim( substr( $path, strlen( rtrim( $root, DIRECTORY_SEPARATOR ) ) ), DIRECTORY_SEPARATOR );
			$normalized   = str_replace( DIRECTORY_SEPARATOR, '/', $relative );

			if ( $auto_refresh ) {
				if ( $this->registry->refresh_checksum_if_newer( $normalized, $path, $size ) ) {
					++$refreshed;
				}
			}

			if ( false !== $this->registry->register( $normalized, $path ) ) {
				++$registered;
			}
		}

		return array(
			'scanned'     => $scanned,
			'registered'  => $registered,
			'refreshed'   => $refreshed,
			'total_bytes' => $total_bytes,
		);
	}

	/**
	 * Package a registered asset into AES-128 encrypted HLS renditions.
	 *
	 * @param string $asset_key Opaque `vip:<32hex>` identifier.
	 * @param string $source_path Absolute path to the source file.
	 * @return array{manifest: string, renditions: list<string>, bytes: int, duration: int}|WP_Error
	 */
	public function package_hls( string $asset_key, string $source_path ) {
		$config     = VipSettings::all();
		$renditions = new HlsRenditions( (array) ( $config['hls_renditions'] ?? array( 128, 320 ) ) );
		$key_store  = new AssetKeyStore();
		$key_store->ensure_table();

		$output_dir       = $this->root() !== false ? rtrim( (string) $this->root(), '/' ) . '/.hls' : sys_get_temp_dir() . '/music-wave-hls';
		$segment_base_url = '';

		$packager = new HlsPackager(
			(string) ( $config['ffmpeg_binary'] ?? 'ffmpeg' ),
			(string) ( $config['ffprobe_binary'] ?? 'ffprobe' ),
			$renditions,
			$key_store,
			$output_dir,
			$segment_base_url,
			(int) ( $config['hls_segment_seconds'] ?? HlsPackager::SEGMENT_SECONDS )
		);

		$result = $packager->package( $source_path, $asset_key );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->registry->set_hls_state(
			$asset_key,
			'ready',
			$result['manifest'],
			(int) $result['bytes'],
			(int) $result['duration']
		);

		/**
		 * Fires after an asset's HLS renditions are packaged and ready.
		 *
		 * @param string $asset_key Opaque `vip:<32hex>` identifier.
		 */
		do_action( 'music_wave_vip_hls_ready', $asset_key );

		return $result;
	}

	private function __clone() {}

	/**
	 * Aggregate protected asset count and byte size from the DB inventory,
	 * falling back cleanly to a safe filesystem scan when the DB table is empty/unavailable.
	 *
	 * @return array{count: int, total_size: int}
	 */
	public function stats(): array {
		$db_stats = $this->registry->stats();
		if ( $db_stats['count'] > 0 ) {
			return $db_stats;
		}

		$root = $this->root();
		if ( false === $root || ! is_dir( $root ) || ! is_readable( $root ) ) {
			return $db_stats;
		}

		$count = 0;
		$bytes = 0;
		$iter  = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		/** @var SplFileInfo $file */
		foreach ( $iter as $file ) {
			if ( ! $file->isFile() || $file->isLink() ) {
				continue;
			}
			$name = $file->getFilename();
			if ( in_array( $name, array( '.htaccess', 'web.config', 'index.html' ), true ) ) {
				continue;
			}
			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( '' !== $ext && ! in_array( $ext, $this->allowed_extensions(), true ) ) {
				continue;
			}
			++$count;
			$bytes += max( 0, (int) $file->getSize() );
		}

		return array(
			'count'      => $count,
			'total_size' => $bytes,
		);
	}

	/**
	 * Delete a registered (`vip:<32hex>`) or local (`local:<path>`) protected asset safely inside `$root`.
	 *
	 * @return true|WP_Error
	 */
	public function delete_asset( string $asset_key_or_id ) {
		$trimmed = trim( $asset_key_or_id );
		if ( 0 === strpos( $trimmed, 'local:' ) ) {
			$relative = substr( $trimmed, 6 );
			$root     = $this->root();
			if ( false === $root || ! PathValidator::is_safe_relative( $relative ) ) {
				return new WP_Error( 'mw_protected_asset_invalid', __( 'شناسهٔ فایل حفاظت‌شده نامعتبر است.', 'music-wave-vip' ), array( 'status' => 400 ) );
			}
			$candidate = realpath( $root . DIRECTORY_SEPARATOR . PathValidator::normalize_relative( $relative ) );
			if ( false === $candidate || ! PathValidator::is_within( $candidate, $root ) || ! is_file( $candidate ) ) {
				return new WP_Error( 'mw_protected_asset_missing', __( 'فایل حفاظت‌شده موردنظر یافت نشد.', 'music-wave-vip' ), array( 'status' => 404 ) );
			}
			unlink( $candidate ); // phpcs:ignore
			return true;
		}

		$asset_id = 0 === strpos( $trimmed, ProtectedAssetRegistry::PREFIX )
			? $trimmed
			: ProtectedAssetRegistry::PREFIX . strtolower( $trimmed );
		$row      = $this->registry->find( $asset_id );
		if ( null === $row ) {
			return new WP_Error( 'mw_protected_asset_missing', __( 'فایل حفاظت‌شده موردنظر یافت نشد.', 'music-wave-vip' ), array( 'status' => 404 ) );
		}

		$root = $this->root();
		if ( false !== $root && isset( $row['relative_path'] ) && PathValidator::is_safe_relative( (string) $row['relative_path'] ) ) {
			$candidate = realpath( $root . DIRECTORY_SEPARATOR . PathValidator::normalize_relative( (string) $row['relative_path'] ) );
			if ( false !== $candidate && PathValidator::is_within( $candidate, $root ) && is_file( $candidate ) ) {
				unlink( $candidate ); // phpcs:ignore
			}
		}

		$this->registry->delete( $asset_id );

		return true;
	}

	/**
	 * @return string|false
	 */
	public function resolve( string $asset_id ) {
		if ( 0 === strpos( $asset_id, ProtectedAssetRegistry::PREFIX ) ) {
			return $this->resolve_registered( $asset_id );
		}
		if ( 0 !== strpos( $asset_id, 'local:' ) ) {
			return false;
		}

		$root     = $this->root();
		$relative = substr( $asset_id, 6 );
		if ( false === $root || ! PathValidator::is_safe_relative( $relative ) ) {
			return false;
		}

		$file = realpath( $root . DIRECTORY_SEPARATOR . PathValidator::normalize_relative( $relative ) );
		if ( false === $file || ! PathValidator::is_within( $file, $root ) || ! is_file( $file ) || ! is_readable( $file ) ) {
			return false;
		}
		return $file;
	}

	/**
	 * @return string|false
	 */
	private function resolve_registered( string $asset_id ) {
		$row  = $this->registry->find( $asset_id );
		$root = $this->root();
		if ( null === $row || false === $root || ! isset( $row['relative_path'] ) ) {
			return false;
		}
		$relative = (string) $row['relative_path'];
		if ( ! PathValidator::is_safe_relative( $relative ) ) {
			return false;
		}
		$file = realpath( $root . DIRECTORY_SEPARATOR . PathValidator::normalize_relative( $relative ) );
		if ( false === $file || ! PathValidator::is_within( $file, $root ) || ! is_file( $file ) || ! is_readable( $file ) ) {
			return false;
		}
		$size = filesize( $file );
		if ( false === $size || (int) $size !== (int) $row['file_size'] ) {
			return false;
		}
		$checksum = isset( $row['checksum'] ) ? (string) $row['checksum'] : '';
		if ( '' !== $checksum ) {
			$actual = hash_file( 'sha256', $file );
			if ( ! is_string( $actual ) || ! hash_equals( $checksum, $actual ) ) {
				return false;
			}
		}
		return $file;
	}

	/**
	 * @return array<string, array<int, array<string, int|string>>|bool>
	 */
	public function list_assets( string $search = '', int $page = 1, int $per_page = 50 ): array {
		$root = $this->root();
		if ( false === $root ) {
			return array(
				'items'    => array(),
				'has_more' => false,
			);
		}

		$needle   = strtolower( trim( $search ) );
		$page     = max( 1, $page );
		$per_page = min( 100, max( 1, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$matches  = 0;
		$assets   = array();
		$has_more = false;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		/** @var SplFileInfo $file */
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->isLink() ) {
				continue;
			}
			$path = $file->getRealPath();
			if ( false === $path || ! PathValidator::is_within( $path, $root ) ) {
				continue;
			}
			$relative   = ltrim( substr( $path, strlen( rtrim( $root, DIRECTORY_SEPARATOR ) ) ), DIRECTORY_SEPARATOR );
			$normalized = str_replace( DIRECTORY_SEPARATOR, '/', $relative );
			$filename   = $file->getFilename();
			if ( in_array( $filename, array( '.htaccess', 'web.config', 'index.html' ), true ) ) {
				continue;
			}
			$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			if ( '' !== $extension && ! in_array( $extension, $this->allowed_extensions(), true ) ) {
				continue;
			}
			if ( '' !== $needle && false === strpos( strtolower( $normalized ), $needle ) ) {
				continue;
			}
			if ( $matches++ < $offset ) {
				continue;
			}
			if ( count( $assets ) >= $per_page ) {
				$has_more = true;
				break;
			}
			$opaque     = $this->registry->register( $normalized, $path );
			$identifier = false !== $opaque ? $opaque : 'local:' . $normalized;
			$assets[]   = $this->asset( $identifier, $relative, $path, (int) $file->getSize() );
		}

		return array(
			'items'    => $assets,
			'has_more' => $has_more,
		);
	}

	/**
	 * @param array<string, mixed> $file
	 * @return array<string, int|string>|WP_Error
	 */
	public function upload( array $file ) {
		$root = $this->root();
		if ( false === $root || ! wp_is_writable( $root ) ) {
			return new WP_Error( 'mw_protected_asset_root', __( 'پوشهٔ asset حفاظت‌شده در دسترس نیست یا قابل نوشتن نیست.', 'music-wave-vip' ), array( 'status' => 500 ) );
		}

		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $error || empty( $file['tmp_name'] ) || ! is_string( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'mw_protected_asset_upload', __( 'بارگذاری asset حفاظت‌شده معتبر نیست.', 'music-wave-vip' ), array( 'status' => 400 ) );
		}

		$original_name = isset( $file['name'] ) && is_string( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
		$extension     = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		$allowed       = $this->allowed_extensions();
		$size          = isset( $file['size'] ) ? (int) $file['size'] : 0;

		if ( '' === $original_name || ! in_array( $extension, $allowed, true ) || $size < 1 || $size > wp_max_upload_size() ) {
			return new WP_Error( 'mw_protected_asset_type', __( 'نوع یا اندازهٔ asset مجاز نیست.', 'music-wave-vip' ), array( 'status' => 400 ) );
		}

		if ( function_exists( 'finfo_open' ) ) {
			$mime = MimeValidator::detect_mime_type( (string) $file['tmp_name'] );
			if ( null !== $mime && ! MimeValidator::is_valid_mime_for_extension( $mime, $extension ) ) {
				if ( 'zip' === $extension ) {
					return new WP_Error( 'mw_protected_asset_mime', __( 'محتوای فایل با پسوند آن مطابقت ندارد.', 'music-wave-vip' ), array( 'status' => 400 ) );
				}
			}
		}
		if ( false !== strpos( $original_name, "\0" ) || preg_match( '/\.(php|phtml|phar|exe|sh)\./i', $original_name ) ) {
			return new WP_Error( 'mw_protected_asset_type', __( 'نام فایل مجاز نیست.', 'music-wave-vip' ), array( 'status' => 400 ) );
		}

		$filename    = wp_unique_filename( $root, $original_name );
		$destination = $root . DIRECTORY_SEPARATOR . $filename;
		if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) {
			return new WP_Error( 'mw_protected_asset_move', __( 'asset حفاظت‌شده ذخیره نشد.', 'music-wave-vip' ), array( 'status' => 500 ) );
		}
		if ( function_exists( 'finfo_open' ) && 'zip' === $extension ) {
			$dest_mime = MimeValidator::detect_mime_type( $destination );
			if ( null !== $dest_mime && 0 === stripos( $dest_mime, 'text/' ) ) {
                unlink($destination); // phpcs:ignore
				return new WP_Error( 'mw_protected_asset_mime', __( 'محتوای فایل یک بایگانی ZIP معتبر نیست.', 'music-wave-vip' ), array( 'status' => 400 ) );
			}
		}

		$opaque = $this->registry->register( $filename, $destination );
		return $this->asset( false !== $opaque ? $opaque : 'local:' . $filename, $filename, $destination, $size );
	}

	/**
	 * @return array<string, int|string>|WP_Error
	 */
	public function import_attachment( int $attachment_id ) {
		if ( $attachment_id < 1 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'mw_protected_asset_attachment', __( 'یک فایل معتبر از رسانه‌ها انتخاب کنید.', 'music-wave-vip' ), array( 'status' => 400 ) );
		}

		$source       = get_attached_file( $attachment_id );
		$source       = is_string( $source ) ? realpath( $source ) : false;
		$uploads      = wp_get_upload_dir();
		$uploads_root = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
		if ( false === $source || false === $uploads_root || ! PathValidator::is_within( $source, $uploads_root ) || ! is_file( $source ) || ! is_readable( $source ) ) {
			return new WP_Error( 'mw_protected_asset_attachment_file', __( 'فایل انتخاب‌شده از رسانه‌ها در دسترس نیست.', 'music-wave-vip' ), array( 'status' => 404 ) );
		}

		$root = $this->root();
		if ( false === $root || ! wp_is_writable( $root ) ) {
			return new WP_Error( 'mw_protected_asset_root', __( 'پوشهٔ asset حفاظت‌شده در دسترس نیست یا قابل نوشتن نیست.', 'music-wave-vip' ), array( 'status' => 500 ) );
		}

		$filename  = sanitize_file_name( basename( $source ) );
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$size      = filesize( $source );
		if ( '' === $filename || ! in_array( $extension, $this->allowed_extensions(), true ) || false === $size || $size < 1 || $size > wp_max_upload_size() ) {
			return new WP_Error( 'mw_protected_asset_type', __( 'نوع یا اندازهٔ asset مجاز نیست.', 'music-wave-vip' ), array( 'status' => 400 ) );
		}

		$filename    = wp_unique_filename( $root, $filename );
		$destination = $root . DIRECTORY_SEPARATOR . $filename;
		if ( ! copy( $source, $destination ) ) {
			return new WP_Error( 'mw_protected_asset_copy', __( 'فایل رسانه‌ها در ذخیره‌سازی حفاظت‌شده کپی نشد.', 'music-wave-vip' ), array( 'status' => 500 ) );
		}

		$opaque = $this->registry->register( $filename, $destination );
		return $this->asset( false !== $opaque ? $opaque : 'local:' . $filename, $filename, $destination, (int) $size );
	}

	/**
	 * @return array<string, int|string>
	 */
	private function asset( string $identifier, string $name, string $file, int $size ): array {
		$metadata = $this->audio_metadata( $file, $size );
		return array_merge(
			array(
				'id'   => $identifier,
				'name' => $name,
				'size' => $size,
			),
			$metadata
		);
	}

	/**
	 * @return array<string, int|string>
	 */
	private function audio_metadata( string $file, int $size ): array {
		$extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		$modified  = filemtime( $file );
		$cache_key = 'music_wave_vip_asset_' . md5( $file . '|' . (string) $size . '|' . (string) $modified );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$metadata = array(
			'format'   => $extension,
			'bitrate'  => 0,
			'duration' => 0,
		);
		if ( ! in_array( $extension, array( 'mp3', 'm4a', 'aac', 'ogg', 'wav', 'flac' ), true ) || ! $this->load_audio_metadata_reader() ) {
			set_transient( $cache_key, $metadata, 12 * HOUR_IN_SECONDS );
			return $metadata;
		}

		$audio = wp_read_audio_metadata( $file );
		if ( is_array( $audio ) ) {
			$format   = isset( $audio['fileformat'] ) && is_scalar( $audio['fileformat'] ) ? sanitize_key( strtolower( (string) $audio['fileformat'] ) ) : '';
			$bitrate  = isset( $audio['bitrate'] ) && is_scalar( $audio['bitrate'] ) ? absint( $audio['bitrate'] ) : 0;
			$duration = isset( $audio['length'] ) && is_scalar( $audio['length'] ) ? absint( $audio['length'] ) : 0;
			if ( '' !== $format ) {
				$metadata['format'] = $format;
			}
			if ( $bitrate > 0 ) {
				$metadata['bitrate'] = (int) round( $bitrate / 1000 );
			}
			if ( $duration > 0 ) {
				$metadata['duration'] = $duration;
			}
		}

		set_transient( $cache_key, $metadata, 12 * HOUR_IN_SECONDS );
		return $metadata;
	}

	private function load_audio_metadata_reader(): bool {
		if ( function_exists( 'wp_read_audio_metadata' ) ) {
			return true;
		}
		if ( ! defined( 'ABSPATH' ) ) {
			return false;
		}
		$media_file = ABSPATH . 'wp-admin/includes/media.php';
		if ( ! is_readable( $media_file ) ) {
			return false;
		}
		require_once $media_file;
		return function_exists( 'wp_read_audio_metadata' );
	}

	/** @return array<int, string> */
	public function allowed_extensions(): array {
		return MimeValidator::allowed_extensions();
	}
}
