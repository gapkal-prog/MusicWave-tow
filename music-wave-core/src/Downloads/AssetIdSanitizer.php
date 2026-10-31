<?php
/**
 * Single source of truth for sanitizing download asset identifiers.
 *
 * Guarantees that `direct:<https-url>` identifiers preserve their
 * percent-encoding (e.g. `%20`, `%28`, `%29`) while legacy opaque
 * identifiers (`vip:`, `local:`) continue to pass through
 * `sanitize_text_field()`.
 *
 * @package ManaCore\MusicWave\Core\Downloads
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @immutable
 */
final class AssetIdSanitizer {
	/**
	 * Sanitize a single asset identifier.
	 *
	 * `direct:` URLs are only trimmed — never run through
	 * `sanitize_text_field()` which would strip `%20`, `%28`, `%29` etc.
	 * All other identifiers are sanitized normally.
	 *
	 * @param string $asset_id Raw asset identifier.
	 * @return string Sanitized identifier.
	 */
	public static function sanitize( string $asset_id ): string {
		$asset_id = trim( $asset_id );
		if ( 0 === strpos( $asset_id, DirectLinkAsset::PREFIX ) ) {
			return $asset_id;
		}

		return sanitize_text_field( $asset_id );
	}
}
