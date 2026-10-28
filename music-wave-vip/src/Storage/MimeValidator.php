<?php
/**
 * MIME detection and extension allow-list for protected assets.
 *
 * @package ManaCore\MusicWave\Vip\Storage
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MimeValidator {

	/**
	 * @return array<int, string>
	 */
	public static function allowed_extensions(): array {
		$allowed = apply_filters(
			'music_wave_vip_allowed_asset_extensions',
			array( 'mp3', 'm4a', 'aac', 'ogg', 'wav', 'flac', 'zip' )
		);
		return is_array( $allowed ) ? array_values( array_filter( array_map( 'sanitize_key', $allowed ) ) ) : array();
	}

	public static function detect_mime_type( string $path ): ?string {
		if ( ! function_exists( 'finfo_open' ) || '' === $path || ! is_readable( $path ) ) {
			return null;
		}
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		if ( false === $finfo ) {
			return null;
		}
		$mime  = finfo_file( $finfo, $path );
		$finfo = null;
		return is_string( $mime ) && '' !== $mime ? strtolower( $mime ) : null;
	}

	public static function is_valid_mime_for_extension( string $mime, string $extension ): bool {
		$allowed_mimes = array(
			'mp3'  => array( 'audio/mpeg', 'audio/mp3' ),
			'm4a'  => array( 'audio/mp4', 'audio/m4a', 'audio/x-m4a' ),
			'aac'  => array( 'audio/aac' ),
			'ogg'  => array( 'audio/ogg', 'audio/x-ogg' ),
			'wav'  => array( 'audio/wav', 'audio/x-wav' ),
			'flac' => array( 'audio/flac', 'audio/x-flac' ),
			'zip'  => array( 'application/zip', 'application/x-zip-compressed' ),
		);
		if ( ! isset( $allowed_mimes[ $extension ] ) ) {
			return true;
		}
		if ( '' === $mime ) {
			return true;
		}
		if ( in_array( $mime, $allowed_mimes[ $extension ], true ) ) {
			return true;
		}
		if ( 0 === strpos( $mime, 'audio/' ) ) {
			return true;
		}
		if ( 'application/octet-stream' === $mime ) {
			return true;
		}
		return false;
	}
}
