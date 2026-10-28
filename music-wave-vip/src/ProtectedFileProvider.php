<?php
/**
 * Local protected file download and HTTP range stream provider.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip;

use ManaCore\MusicWave\Core\Downloads\DownloadProvider;
use ManaCore\MusicWave\Core\Downloads\DownloadTokenClaims;
use ManaCore\MusicWave\Core\Downloads\StreamableDownloadProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProtectedFileProvider implements DownloadProvider, StreamableDownloadProvider {
	/** @var ProtectedAssetStorage */
	private $storage;

	/** @var array<int, string> */
	private $last_sent_headers = array();

	/** @return array<int, string> */
	public function last_sent_headers(): array {
		return $this->last_sent_headers;
	}

	public function __construct( ?ProtectedAssetStorage $storage = null ) {
		$this->storage = null === $storage ? new ProtectedAssetStorage() : $storage;
	}

	public function deliver( string $asset_id, DownloadTokenClaims $claims ): bool {
		unset( $claims );
		return $this->output( $asset_id, false );
	}

	public function stream( string $asset_id, DownloadTokenClaims $claims ): bool {
		unset( $claims );
		return $this->output( $asset_id, true );
	}

	private function output( string $asset_id, bool $inline ): bool {
		$file = $this->storage->resolve( $asset_id );
		if ( false === $file ) {
			return false;
		}

		$size = filesize( $file );
		if ( false === $size || $size < 1 ) {
			return false;
		}

		$settings         = VipSettings::all();
		$sendfile_headers = self::sendfile_headers(
			(string) $settings['sendfile_mode'],
			$file,
			$this->relative_to_root( $file ),
			(string) $settings['xaccel_prefix'],
			$inline ? $this->content_type( $file ) : 'application/octet-stream',
			$this->content_disposition( $file, $inline )
		);
		if ( null !== $sendfile_headers ) {
			$this->last_sent_headers = $sendfile_headers;
			if ( defined( 'MUSIC_WAVE_TESTING' ) && MUSIC_WAVE_TESTING ) {
				return true;
			}
			// Hand the transfer to the web server: it applies its own range and
			// length handling, freeing PHP workers for large protected files
			// (PROJECT_PLAN.md Stage 2 deliverable 7).
			nocache_headers();
			foreach ( $sendfile_headers as $sendfile_header ) {
				header( $sendfile_header );
			}
			exit;
		}

		$range = $this->requested_range( (int) $size );
		nocache_headers();
		header( 'Accept-Ranges: bytes' );
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Accel-Buffering: no' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . ( $inline ? $this->content_type( $file ) : 'application/octet-stream' ) );
		header( 'Content-Disposition: ' . $this->content_disposition( $file, $inline ) );
		if ( false === $range ) {
			status_header( 416 );
			header( 'Content-Range: bytes */' . (string) $size );
			exit;
		}

		$start  = $range[0];
		$length = $range[1];
		if ( 0 !== $start || $length !== (int) $size ) {
			status_header( 206 );
			header( 'Content-Range: bytes ' . (string) $start . '-' . (string) ( $start + $length - 1 ) . '/' . (string) $size );
		} else {
			status_header( 200 );
		}
		header( 'Content-Length: ' . (string) $length );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		$this->stream_file( $file, $start, $length );
		exit;
	}

	/**
	 * Build server-offload headers for the configured acceleration mode.
	 *
	 * Returns null when PHP should stream the file itself. Pure so the header
	 * contract stays unit-testable without emitting output.
	 *
	 * @return array<int, string>|null
	 */
	public static function sendfile_headers( string $mode, string $file, string $relative, string $xaccel_prefix, string $content_type, string $disposition ): ?array {
		$common = array(
			'Referrer-Policy: no-referrer',
			'X-Content-Type-Options: nosniff',
			'Cache-Control: private, no-store, max-age=0',
			'Content-Type: ' . $content_type,
			'Content-Disposition: ' . $disposition,
		);

		if ( 'xsendfile' === $mode && '' !== $file ) {
			$common[] = 'X-Sendfile: ' . $file;
			return $common;
		}
		if ( 'xaccel' === $mode && '' !== $relative && '' !== $xaccel_prefix && 0 === strpos( $xaccel_prefix, '/' ) ) {
			$encoded  = implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) );
			$common[] = 'X-Accel-Redirect: ' . rtrim( $xaccel_prefix, '/' ) . '/' . $encoded;
			return $common;
		}

		return null;
	}

	/**
	 * Compute the forward-slash relative path of a resolved protected file.
	 */
	private function relative_to_root( string $file ): string {
		$root = $this->storage->root();
		if ( false === $root || 0 !== strpos( $file, rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) {
			return '';
		}

		$relative = ltrim( substr( $file, strlen( rtrim( $root, DIRECTORY_SEPARATOR ) ) ), DIRECTORY_SEPARATOR );

		return str_replace( DIRECTORY_SEPARATOR, '/', $relative );
	}

	private function content_disposition( string $file, bool $inline ): string {
		$name     = basename( $file );
		$fallback = preg_replace( '/[^A-Za-z0-9._-]/', '_', $name );
		$fallback = is_string( $fallback ) && '' !== $fallback ? $fallback : 'musicwave-audio';
		$mode     = $inline ? 'inline' : 'attachment';

		return $mode . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode( $name );
	}

	private function content_type( string $file ): string {
		$types     = array(
			'mp3'  => 'audio/mpeg',
			'm4a'  => 'audio/mp4',
			'aac'  => 'audio/aac',
			'ogg'  => 'audio/ogg',
			'wav'  => 'audio/wav',
			'flac' => 'audio/flac',
		);
		$extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		return isset( $types[ $extension ] ) ? $types[ $extension ] : 'application/octet-stream';
	}

	/**
	 * @return array<int, int>|false
	 */
	private function requested_range( int $size ) {
		$header = isset( $_SERVER['HTTP_RANGE'] ) && is_string( $_SERVER['HTTP_RANGE'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) ) : '';
		if ( '' === $header ) {
			return array( 0, $size );
		}
		if ( 1 !== preg_match( '/^bytes=(\d*)-(\d*)$/', $header, $matches ) ) {
			return false;
		}

		$start = '' === $matches[1] ? null : (int) $matches[1];
		$end   = '' === $matches[2] ? null : (int) $matches[2];
		if ( null === $start && null === $end ) {
			return false;
		}
		if ( null === $start ) {
			$length = min( $end, $size );
			return $length < 1 ? false : array( $size - $length, $length );
		}
		if ( $start >= $size ) {
			return false;
		}
		$end = null === $end ? $size - 1 : min( $end, $size - 1 );
		if ( $end < $start ) {
			return false;
		}

		return array( $start, $end - $start + 1 );
	}

	private function stream_file( string $file, int $start, int $length ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Raw binary range streaming requires a native resource handle.
		$handle = fopen( $file, 'rb' );
		if ( false === $handle ) {
			return;
		}

		fseek( $handle, $start );
		while ( $length > 0 && ! feof( $handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read exact byte ranges without loading protected audio into memory.
			$chunk = fread( $handle, min( 1024 * 1024, $length ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw binary file stream; escaping would corrupt audio bytes.
			$length -= strlen( $chunk );
			flush();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native range-stream handle opened above.
		fclose( $handle );
	}
}
