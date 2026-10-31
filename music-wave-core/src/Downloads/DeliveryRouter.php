<?php
/**
 * Scheme-aware delivery router.
 *
 * Routes `direct:` assets to `DirectLinkProvider` and everything else to the
 * configured external (VIP) provider. The `music_wave_download_provider`
 * filter contract for third-party developers is unchanged.
 *
 * @package ManaCore\MusicWave\Core\Downloads
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DeliveryRouter implements DownloadProvider, StreamableDownloadProvider {
	/** @var DirectLinkProvider */
	private $direct;

	/** @var DownloadProvider */
	private $external;

	public function __construct( DirectLinkProvider $direct, DownloadProvider $external ) {
		$this->direct   = $direct;
		$this->external = $external;
	}

	public function deliver( string $asset_id, DownloadTokenClaims $claims ): bool {
		if ( DirectLinkAsset::is( $asset_id ) ) {
			return $this->direct->deliver( $asset_id, $claims );
		}

		return $this->external->deliver( $asset_id, $claims );
	}

	public function stream( string $asset_id, DownloadTokenClaims $claims ): bool {
		if ( DirectLinkAsset::is( $asset_id ) ) {
			return $this->direct->stream( $asset_id, $claims );
		}

		if ( $this->external instanceof StreamableDownloadProvider ) {
			return $this->external->stream( $asset_id, $claims );
		}

		return false;
	}
}
