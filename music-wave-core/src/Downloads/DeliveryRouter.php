<?php
/**
 * Scheme-based download & stream delivery router.
 *
 * Routes `direct:<https-url>` assets to Core's `DirectLinkProvider` while
 * delegating opaque protected identifiers (`vip:`, `local:`, custom IDs) to the
 * registered external `DownloadProvider` without unsafe cross-provider fallback.
 *
 * @package ManaCore\MusicWave\Core\Downloads
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DeliveryRouter implements DownloadProvider, StreamableDownloadProvider {
	/** @var DownloadProvider */
	private $external_provider;

	/** @var DirectLinkProvider */
	private $direct_provider;

	public function __construct( DownloadProvider $external_provider, ?DirectLinkProvider $direct_provider = null ) {
		$this->external_provider = $external_provider;
		$this->direct_provider   = null !== $direct_provider ? $direct_provider : new DirectLinkProvider();
	}

	public function external_provider(): DownloadProvider {
		return $this->external_provider;
	}

	public function direct_provider(): DirectLinkProvider {
		return $this->direct_provider;
	}

	/**
	 * Whether a non-null external provider (e.g. MusicWave VIP) is active.
	 */
	public function has_external_provider(): bool {
		return ! ( $this->external_provider instanceof NullDownloadProvider );
	}

	/**
	 * Pre-issuance check: whether the configured providers can deliver `$asset_id`.
	 */
	public function can_deliver_asset( string $asset_id ): bool {
		if ( DirectLinkAsset::has_direct_scheme( $asset_id ) ) {
			return '' !== $this->direct_provider->resolve_url( $asset_id );
		}

		return $this->has_external_provider();
	}

	/**
	 * Pre-issuance check: whether `$asset_id` can be streamed inline.
	 */
	public function can_stream_asset( string $asset_id ): bool {
		if ( DirectLinkAsset::has_direct_scheme( $asset_id ) ) {
			return '' !== $this->direct_provider->resolve_url( $asset_id );
		}

		return $this->external_provider instanceof StreamableDownloadProvider;
	}

	public function deliver( string $asset_id, DownloadTokenClaims $claims ): bool {
		if ( DirectLinkAsset::has_direct_scheme( $asset_id ) ) {
			return $this->direct_provider->deliver( $asset_id, $claims );
		}

		return $this->external_provider->deliver( $asset_id, $claims );
	}

	public function stream( string $asset_id, DownloadTokenClaims $claims ): bool {
		if ( DirectLinkAsset::has_direct_scheme( $asset_id ) ) {
			return $this->direct_provider->stream( $asset_id, $claims );
		}

		if ( $this->external_provider instanceof StreamableDownloadProvider ) {
			return $this->external_provider->stream( $asset_id, $claims );
		}

		return false;
	}
}
