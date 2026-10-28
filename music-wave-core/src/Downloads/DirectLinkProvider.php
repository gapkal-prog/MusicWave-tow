<?php
/**
 * Core download & stream provider for controlled `direct:<https-url>` assets.
 *
 * Re-validates the stored HTTPS URL at delivery time against SSRF/IP rules and
 * any active host allowlist before issuing a hardened 302 redirect.
 *
 * @package ManaCore\MusicWave\Core\Downloads
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DirectLinkProvider implements DownloadProvider, StreamableDownloadProvider {
	/**
	 * Whether this provider handles the given asset identifier.
	 */
	public function can_handle( string $asset_id ): bool {
		return DirectLinkAsset::has_direct_scheme( $asset_id );
	}

	/**
	 * Validate and resolve the target HTTPS URL for a `direct:` asset ID.
	 */
	public function resolve_url( string $asset_id, ?DownloadTokenClaims $claims = null, bool $inline = false ): string {
		if ( ! DirectLinkAsset::is_direct( $asset_id ) ) {
			return '';
		}

		$raw_url   = DirectLinkAsset::extract_url( $asset_id );
		$validated = DirectLinkUrlValidator::sanitize( $raw_url );
		if ( '' === $validated ) {
			return '';
		}

		/**
		 * Optional filter to customize a validated direct-link URL at delivery time.
		 * Any modified URL is strictly re-validated through `DirectLinkUrlValidator`.
		 *
		 * @param string                   $url    Validated HTTPS URL.
		 * @param DownloadTokenClaims|null $claims Verified token claims when delivering.
		 * @param bool                     $inline True for stream, false for download.
		 */
		$filtered = apply_filters( 'music_wave_direct_link_delivery_url', $validated, $claims, $inline );
		if ( ! is_string( $filtered ) || '' === trim( $filtered ) ) {
			return '';
		}

		return $filtered === $validated ? $validated : DirectLinkUrlValidator::sanitize( $filtered );
	}

	public function deliver( string $asset_id, DownloadTokenClaims $claims ): bool {
		$url = $this->resolve_url( $asset_id, $claims, false );
		if ( '' === $url ) {
			return false;
		}

		return RedirectResponse::send( $url, 302, 'MusicWave Core' );
	}

	public function stream( string $asset_id, DownloadTokenClaims $claims ): bool {
		$url = $this->resolve_url( $asset_id, $claims, true );
		if ( '' === $url ) {
			return false;
		}

		return RedirectResponse::send( $url, 302, 'MusicWave Core' );
	}
}
