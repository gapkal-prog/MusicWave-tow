<?php
/**
 * Opaque identifier wrapper for direct (external HTTPS) download assets.
 *
 * Encodes/decodes `direct:<https-url>` so the storage layer never confuses a
 * raw public URL with a protected asset id. The `direct:` prefix is detected
 * before any other scheme handling so raw URLs are never persisted as asset ids.
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
final class DirectLinkAsset {
	public const PREFIX = 'direct:';

	/** @var string */
	private $url;

	private function __construct( string $url ) {
		$this->url = $url;
	}

	/**
	 * Whether an opaque asset id is a direct-link identifier.
	 */
	public static function is( string $asset_id ): bool {
		return 0 === strpos( $asset_id, self::PREFIX );
	}

	/**
	 * Encode an HTTPS URL into the opaque `direct:` identifier form.
	 *
	 * @return string|null Encoded identifier, or null when the URL is not a valid direct-link target.
	 */
	public static function encode( string $url ): ?string {
		$validator = new DirectLinkUrlValidator();
		$sanitized = $validator->validate( $url );
		if ( null === $sanitized ) {
			return null;
		}

		return self::PREFIX . $sanitized;
	}

	/**
	 * Decode a `direct:` identifier back into its HTTPS URL.
	 *
	 * @return string|null The URL, or null when the id is not a direct identifier.
	 */
	public static function decode( string $asset_id ): ?string {
		if ( ! self::is( $asset_id ) ) {
			return null;
		}

		return substr( $asset_id, strlen( self::PREFIX ) );
	}

	/**
	 * The underlying HTTPS URL.
	 */
	public function url(): string {
		return $this->url;
	}

	/**
	 * Create from a pre-validated URL (caller must have validated).
	 */
	public static function from_url( string $url ): self {
		return new self( $url );
	}
}
