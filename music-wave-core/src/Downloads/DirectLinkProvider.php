<?php
/**
 * Delivery provider for `direct:` (external HTTPS) assets.
 *
 * After the resolver has already confirmed entitlement, re-validates the URL
 * (the DB row is not trusted: imports, WP-CLI, direct `update_post_meta`)
 * and issues a shared 302 redirect. `stream()` does not consume a replay
 * token, so multiple HTTP Range requests work.
 *
 * Never serves `vip:`/`local:` — those belong to the VIP adapter.
 *
 * @package ManaCore\MusicWave\Core\Downloads
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DirectLinkProvider implements DownloadProvider, StreamableDownloadProvider {
	public function deliver( string $asset_id, DownloadTokenClaims $claims ): bool {
		unset( $claims );

		$url = DirectLinkAsset::decode( $asset_id );
		if ( null === $url ) {
			return false;
		}

		// Defense in depth: the stored value can be tampered with outside the
		// editor (import, WP-CLI, direct meta update). Re-validate before redirect.
		$validator = new DirectLinkUrlValidator();
		$validated = $validator->validate( $url );
		if ( null === $validated ) {
			return false;
		}

		( new RedirectResponse( $validated ) )->send();

		return true;
	}

	public function stream( string $asset_id, DownloadTokenClaims $claims ): bool {
		// Streamable: does not consume the one-time replay token, so the
		// browser may request multiple ranges / seek repeatedly.
		return $this->deliver( $asset_id, $claims );
	}
}
