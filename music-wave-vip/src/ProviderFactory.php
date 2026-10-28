<?php
/**
 * Compose the configured VIP adapters without coupling Core to a provider.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip;

use ManaCore\MusicWave\Core\Downloads\DownloadProvider;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProviderFactory {
	/** @return DownloadProvider */
	public static function download_provider( ProtectedAssetStorage $storage ): DownloadProvider {
		$config   = VipSettings::all();
		$provider = 'remote_redirect' === $config['delivery_provider']
			? new RemoteRedirectProvider( $config )
			: new ProtectedFileProvider( $storage );

		$provider = apply_filters( 'music_wave_vip_download_provider', $provider, $config, $storage );

		return $provider instanceof DownloadProvider ? $provider : new ProtectedFileProvider( $storage );
	}

	/** @return \ManaCore\MusicWave\Core\Access\MembershipProvider */
	public static function membership_provider() {
		$config = VipSettings::all();
		// The plan engine is composed lazily at decision time so grants read
		// from user meta on every access check; no entitlement is cached.
		$provider = new ConfigurableMembershipProvider( (array) $config['membership_sources'] );
		$provider = apply_filters( 'music_wave_vip_membership_provider', $provider, $config );

		return $provider instanceof \ManaCore\MusicWave\Core\Access\MembershipProvider ? $provider : new ConfigurableMembershipProvider( array( 'role', 'filter' ) );
	}
}
