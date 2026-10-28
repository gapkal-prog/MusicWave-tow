<?php
/**
 * Value helper for `direct:<https-url>` download asset identifiers.
 *
 * @package ManaCore\MusicWave\Core\Downloads
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DirectLinkAsset {
	public const PREFIX = 'direct:';

	/**
	 * Case-insensitive check for any `direct:` scheme prefix so uppercase
	 * bypass attempts (e.g. `DIRECT:http://...`) never fall through as opaque IDs.
	 */
	public static function has_direct_scheme( string $asset_id ): bool {
		return 0 === stripos( trim( $asset_id ), self::PREFIX );
	}

	/**
	 * Whether the identifier uses the canonical lowercase `direct:` prefix.
	 */
	public static function is_direct( string $asset_id ): bool {
		return 0 === strpos( trim( $asset_id ), self::PREFIX );
	}

	/**
	 * Extract the raw URL part following `direct:`.
	 */
	public static function extract_url( string $asset_id ): string {
		$trimmed = trim( $asset_id );
		if ( ! self::has_direct_scheme( $trimmed ) ) {
			return '';
		}

		return trim( substr( $trimmed, strlen( self::PREFIX ) ) );
	}

	/**
	 * Validate a raw URL or `direct:<url>` identifier and build the canonical
	 * `direct:<https-url>` string (or empty string if invalid).
	 *
	 * @param string                  $raw           Raw URL or `direct:<url>`.
	 * @param array<int, string>|null $allowed_hosts Optional host allowlist override.
	 */
	public static function sanitize_asset_id( string $raw, ?array $allowed_hosts = null ): string {
		$candidate = self::has_direct_scheme( $raw ) ? self::extract_url( $raw ) : trim( $raw );
		$clean_url = DirectLinkUrlValidator::sanitize( $candidate, $allowed_hosts );

		return '' !== $clean_url ? self::PREFIX . $clean_url : '';
	}

	/**
	 * Validate a `direct:` asset ID or raw URL and return detailed error context.
	 *
	 * @param string                  $raw           Raw URL or `direct:<url>`.
	 * @param array<int, string>|null $allowed_hosts Optional host allowlist override.
	 * @return array{valid: bool, asset_id: string, url: string, error_code: string, message: string}
	 */
	public static function validate( string $raw, ?array $allowed_hosts = null ): array {
		$candidate = self::has_direct_scheme( $raw ) ? self::extract_url( $raw ) : trim( $raw );
		$result    = DirectLinkUrlValidator::validate( $candidate, $allowed_hosts );

		return array(
			'valid'      => $result['valid'],
			'asset_id'   => $result['valid'] ? self::PREFIX . $result['url'] : '',
			'url'        => $result['url'],
			'error_code' => $result['error_code'],
			'message'    => $result['message'],
		);
	}
}
