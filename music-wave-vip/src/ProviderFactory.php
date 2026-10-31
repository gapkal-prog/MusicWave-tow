<?php
/**
 * Single composition point for every VIP-owned adapter and provider.
 *
 * Keeping all "which implementation for this setting?" decisions here means
 * the bootstrap, the REST routes, and the cron worker never duplicate a mode
 * switch (DRY) and Core is never coupled to a VIP class (DIP): Core asks for a
 * provider through a filter, and this factory answers.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip;

use ManaCore\MusicWave\Core\Access\MembershipProvider;
use ManaCore\MusicWave\Core\Downloads\DownloadProvider;
use ManaCore\MusicWave\Core\Downloads\SegmentSigner;
use ManaCore\MusicWave\Core\Playback\DbStreamSessionStore;
use ManaCore\MusicWave\Core\Playback\StreamSessionCookie;
use ManaCore\MusicWave\Core\Playback\StreamSessionStore;
use ManaCore\MusicWave\Vip\Delivery\Adapters\S3CompatibleUploadAdapter;
use ManaCore\MusicWave\Vip\Delivery\Adapters\SftpUploadAdapter;
use ManaCore\MusicWave\Vip\Delivery\Adapters\WebdavUploadAdapter;
use ManaCore\MusicWave\Vip\Delivery\Adapters\WebhookUploadAdapter;
use ManaCore\MusicWave\Vip\Delivery\RemoteUploadAdapter;
use ManaCore\MusicWave\Vip\Delivery\RemoteUploadQueue;
use ManaCore\MusicWave\Vip\Delivery\RemoteUploadRoutes;
use ManaCore\MusicWave\Vip\Hls\AssetKeyStore;
use ManaCore\MusicWave\Vip\Hls\HlsRoutes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProviderFactory {
	/** Delivery modes that serve bytes through the site's protected storage. */
	public const LOCAL_MODES = array( 'local', 'proxy' );

	/** Every accepted `delivery_provider` value. */
	public const DELIVERY_MODES = array( 'local', 'remote_redirect', 'edge_signed', 'proxy', 'edge_hls' );

	/*
	------------------------------------------------------------------
	 *  Download providers                                                 *
	 * ------------------------------------------------------------------
	 */

	/**
	 * Build the active delivery provider for the configured mode.
	 *
	 * Edge/remote modes fail closed onto the local protected provider when
	 * their required secret or base URL is missing, so a misconfiguration
	 * degrades to safe local delivery instead of an unsigned public URL.
	 */
	public static function download_provider( ProtectedAssetStorage $storage ): DownloadProvider {
		$config   = VipSettings::all();
		$provider = self::provider_for_mode( $config, $storage );
		$provider = apply_filters( 'music_wave_vip_download_provider', $provider, $config, $storage );

		return $provider instanceof DownloadProvider ? $provider : new ProtectedFileProvider( $storage );
	}

	/**
	 * @param array<string, mixed> $config Normalized VIP settings.
	 */
	private static function provider_for_mode( array $config, ProtectedAssetStorage $storage ): DownloadProvider {
		$mode = (string) ( $config['delivery_provider'] ?? 'local' );
		$edge = self::edge_provider( $mode, $config );
		if ( null !== $edge ) {
			return $edge;
		}

		if ( 'remote_redirect' === $mode ) {
			return new RemoteRedirectProvider( $config );
		}

		// `local` and `proxy` both stream the protected file through the site
		// domain; `ProtectedFileProvider` already honours the X-Sendfile /
		// X-Accel-Redirect offload settings, so one implementation serves both.
		return new ProtectedFileProvider( $storage );
	}

	/**
	 * Build an edge provider (`edge_signed` / `edge_hls`) when its shared
	 * secret and base URL are strong enough; null otherwise (fail closed).
	 *
	 * @param array<string, mixed> $config Normalized VIP settings.
	 */
	public static function edge_provider( string $mode, array $config ): ?DownloadProvider {
		if ( ! in_array( $mode, array( 'edge_signed', 'edge_hls' ), true ) ) {
			return null;
		}

		$base   = trim( (string) ( $config['edge_base_url'] ?? '' ) );
		$secret = (string) ( $config['edge_signing_secret'] ?? '' );

		if ( '' === $base || strlen( $secret ) < 32 || 'https' !== wp_parse_url( $base, PHP_URL_SCHEME ) ) {
			return null;
		}

		$signer = new SegmentSigner( $secret, (string) ( $config['edge_signing_key_id'] ?? 'k1' ) );

		// Both edge modes deliver the original file; `edge_hls` only changes the
		// advertised mode (HLS playback runs on the site-side stream routes).
		return 'edge_hls' === $mode
			? new EdgeProvider( $signer, $config, EdgeProvider::MODE_HLS )
			: new EdgeProvider( $signer, $config, EdgeProvider::MODE_SIGNED );
	}

	/*
	------------------------------------------------------------------
	 *  Membership                                                         *
	 * ------------------------------------------------------------------
	 */

	/** @return MembershipProvider */
	public static function membership_provider(): MembershipProvider {
		$config   = VipSettings::all();
		$provider = new ConfigurableMembershipProvider( (array) $config['membership_sources'] );
		$provider = apply_filters( 'music_wave_vip_membership_provider', $provider, $config );

		return $provider instanceof MembershipProvider ? $provider : new ConfigurableMembershipProvider( array( 'role', 'filter' ) );
	}

	/*
	------------------------------------------------------------------
	 *  Playback sessions (resolved by Core through filters)               *
	 * ------------------------------------------------------------------
	 */

	/**
	 * Session store for Core's playback-session routes, or null when sessions
	 * are switched off (Core then registers no session routes at all).
	 */
	public static function stream_session_store(): ?StreamSessionStore {
		if ( ! self::sessions_enabled() ) {
			return null;
		}

		return new DbStreamSessionStore();
	}

	/**
	 * HttpOnly session cookie issuer, or null when sessions are off.
	 */
	public static function stream_session_cookie(): ?StreamSessionCookie {
		if ( ! self::sessions_enabled() ) {
			return null;
		}

		$config = VipSettings::all();

		return new StreamSessionCookie( absint( $config['session_ttl'] ?? 60 ), (bool) is_ssl() );
	}

	/**
	 * Shared URL signer, or null when no strong edge secret is configured.
	 */
	public static function stream_signer(): ?SegmentSigner {
		$config = VipSettings::all();
		$secret = isset( $config['edge_signing_secret'] ) ? (string) $config['edge_signing_secret'] : '';
		if ( strlen( $secret ) < 32 ) {
			return null;
		}

		return new SegmentSigner( $secret, (string) ( $config['edge_signing_key_id'] ?? 'k1' ) );
	}

	/**
	 * Effective delivery mode advertised to the player, derived from the
	 * configured provider (never a hard-coded default).
	 */
	public static function effective_stream_mode(): string {
		$provider = (string) ( VipSettings::all()['delivery_provider'] ?? 'local' );

		return in_array( $provider, self::DELIVERY_MODES, true ) ? $provider : 'local';
	}

	private static function sessions_enabled(): bool {
		return 'enabled' === (string) ( VipSettings::all()['session_enabled'] ?? 'enabled' );
	}

	/*
	------------------------------------------------------------------
	 *  HLS routes                                                         *
	 * ------------------------------------------------------------------
	 */

	/**
	 * Build the VIP-owned HLS route controller when HLS is enabled and the
	 * session services it depends on are available.
	 *
	 * Fails closed (null) without a strong edge secret: an unsigned HLS route
	 * would otherwise serve segment keys to anyone who can guess a session id.
	 */
	public static function hls_routes(): ?HlsRoutes {
		if ( ! class_exists( HlsRoutes::class ) ) {
			return null;
		}

		$config = VipSettings::all();
		if ( 'enabled' !== (string) ( $config['hls_enabled'] ?? 'disabled' ) ) {
			return null;
		}

		$store  = self::stream_session_store();
		$cookie = self::stream_session_cookie();
		$signer = self::stream_signer();
		if ( null === $store || null === $cookie || null === $signer ) {
			return null;
		}

		$policy = class_exists( \ManaCore\MusicWave\Core\Plugin::class )
			? \ManaCore\MusicWave\Core\Plugin::instance()->access_policy()
			: null;

		return new HlsRoutes(
			$store,
			$cookie,
			$signer,
			new AssetKeyStore(),
			$policy,
			absint( $config['signed_url_ttl'] ?? 3600 ),
			'enabled' === (string) ( $config['session_bind_ip'] ?? 'enabled' ),
			'enabled' === (string) ( $config['session_bind_ua'] ?? 'disabled' )
		);
	}

	/*
	------------------------------------------------------------------
	 *  Remote upload                                                      *
	 * ------------------------------------------------------------------
	 */

	/**
	 * Build the configured upload adapter, or null when unavailable in this
	 * environment (e.g. SFTP without ssh2/phpseclib).
	 *
	 * @param array<string, mixed> $config Normalized VIP settings.
	 */
	public static function remote_upload_adapter( array $config ): ?RemoteUploadAdapter {
		$mode = (string) ( $config['remote_upload_mode'] ?? 'webhook' );

		switch ( $mode ) {
			case 'webhook':
				$adapter = new WebhookUploadAdapter( $config );
				break;
			case 's3':
				$adapter = new S3CompatibleUploadAdapter( $config );
				break;
			case 'webdav':
				$adapter = new WebdavUploadAdapter( $config );
				break;
			case 'sftp':
				$adapter = new SftpUploadAdapter( $config );
				break;
			default:
				return null;
		}

		return $adapter->available() ? $adapter : null;
	}

	/**
	 * Build the remote-upload admin routes (adapter resolved per request).
	 */
	public static function remote_upload_routes(): ?RemoteUploadRoutes {
		if ( ! class_exists( RemoteUploadRoutes::class ) ) {
			return null;
		}

		return new RemoteUploadRoutes( new RemoteUploadQueue() );
	}

	private function __construct() {}
	private function __clone() {}
}
