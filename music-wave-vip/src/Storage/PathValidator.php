<?php
/**
 * Path safety helpers – prevents traversal, null bytes, and ensures containment.
 *
 * @package ManaCore\MusicWave\Vip\Storage
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PathValidator {

	public static function is_safe_relative( string $relative ): bool {
		if ( '' === $relative ) {
			return false;
		}
		if ( false !== strpos( $relative, "\0" ) ) {
			return false;
		}
		if ( false !== strpos( $relative, '..' ) ) {
			return false;
		}
		return true;
	}

	public static function is_within( string $path, string $root ): bool {
		$prefix = rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		return 0 === strpos( $path, $prefix );
	}

	public static function normalize_relative( string $relative ): string {
		return str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $relative );
	}
}
