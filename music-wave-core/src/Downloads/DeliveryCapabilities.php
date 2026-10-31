<?php
/**
 * Delivery capability detection, memoized per request.
 *
 * Replaces the scattered `has_filter( 'music_wave_download_provider' )`
 * checks (H11) with a single source of truth. State values:
 *  - `external_adapter` — VIP/third-party download provider is active
 *  - `direct_only`      — only core direct links can be delivered
 *  - `none`             — no delivery path available
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
final class DeliveryCapabilities {
	public const STATE_EXTERNAL_ADAPTER = 'external_adapter';
	public const STATE_DIRECT_ONLY      = 'direct_only';
	public const STATE_NONE             = 'none';

	/** @var DownloadProvider */
	private $external;

	/** @var string Memoized delivery state for this request. */
	private $state;

	/**
	 * Probe the current filter-based provider registration (status-check only).
	 */
	public static function probe(): self {
		$external = apply_filters( 'music_wave_download_provider', new NullDownloadProvider() );
		if ( ! $external instanceof DownloadProvider ) {
			$external = new NullDownloadProvider();
		}

		return new self( $external );
	}

	public function __construct( DownloadProvider $external ) {
		$this->external = $external;
		$this->state    = $external instanceof NullDownloadProvider
			? self::STATE_DIRECT_ONLY
			: self::STATE_EXTERNAL_ADAPTER;
	}

	/**
	 * Whether any delivery path is available at all.
	 */
	public function is_available(): bool {
		return self::STATE_NONE !== $this->state;
	}

	/**
	 * Whether the given asset id can be delivered through the current stack.
	 *
	 * `direct:` is always supported (core DirectLinkProvider).
	 * `vip:`/`local:` are supported only when a real external adapter is present.
	 */
	public function supports( string $asset_id ): bool {
		if ( DirectLinkAsset::is( $asset_id ) ) {
			return true;
		}
		if ( 0 === strpos( $asset_id, 'vip:' ) || 0 === strpos( $asset_id, 'local:' ) ) {
			return ! $this->external instanceof NullDownloadProvider;
		}

		return false;
	}

	/**
	 * Current delivery state, resolved once when the instance is built.
	 */
	public function state(): string {
		return $this->state;
	}
}
