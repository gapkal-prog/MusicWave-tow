<?php
/**
 * Real-time diagnostics, preflight matrix, and live HMAC signed-URL tester
 * for the MusicWave VIP Protected Delivery control center (SOLID SRP).
 *
 * @package ManaCore\MusicWave\Vip\Delivery
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Delivery;

use ManaCore\MusicWave\Core\Downloads\DownloadTokenClaims;
use ManaCore\MusicWave\Vip\ProtectedAssetStorage;
use ManaCore\MusicWave\Vip\RemoteRedirectProvider;
use ManaCore\MusicWave\Vip\VipSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProtectedDeliveryDiagnostics {
	/** @var ProtectedAssetStorage */
	private $storage;

	public function __construct( ?ProtectedAssetStorage $storage = null ) {
		$this->storage = null !== $storage ? $storage : new ProtectedAssetStorage();
	}

	/**
	 * Build a complete, non-sensitive diagnostic snapshot for admin inspection and REST refresh.
	 *
	 * Never includes raw signing secrets.
	 *
	 * @param array<string, mixed>|null $settings Optional preloaded settings array.
	 * @return array<string, mixed>
	 */
	public function snapshot( ?array $settings = null ): array {
		$config    = is_array( $settings ) ? $settings : VipSettings::all();
		$provider  = isset( $config['delivery_provider'] ) ? (string) $config['delivery_provider'] : 'local';
		$preflight = $this->storage->preflight();
		$root      = $this->storage->root();
		$stats     = $this->storage->stats();

		$local_ready = ! in_array( false, $preflight, true );

		$remote_base   = isset( $config['remote_base_url'] ) ? trim( (string) $config['remote_base_url'] ) : '';
		$remote_secret = isset( $config['remote_signing_secret'] ) ? (string) $config['remote_signing_secret'] : '';
		$remote_ttl    = isset( $config['remote_ttl'] ) ? absint( $config['remote_ttl'] ) : 300;
		$https_ok      = '' !== $remote_base && 'https' === wp_parse_url( $remote_base, PHP_URL_SCHEME );
		$secret_set    = '' !== $remote_secret;
		$secret_strong = strlen( $remote_secret ) >= 32;
		$ttl_ok        = $remote_ttl >= 30 && $remote_ttl <= 900;

		$sample_download_url = '';
		$sample_stream_url   = '';
		$host_allowlist_ok   = false;

		if ( $https_ok && $secret_strong && class_exists( DownloadTokenClaims::class ) ) {
			$remote_provider     = new RemoteRedirectProvider( $config );
			$sample_claims       = new DownloadTokenClaims( 1, 1, time() + 120, 'self-test', 'self-test-nonce', 'flac', 'download' );
			$sample_download_url = $remote_provider->create_url( 'diagnostics/sample-track.flac', $sample_claims, false );
			$sample_stream_url   = $remote_provider->create_url( 'diagnostics/sample-track.flac', $sample_claims, true );
			$host_allowlist_ok   = '' !== $sample_download_url && $remote_provider->allowed_url( $sample_download_url );
		}

		$remote_ready = $https_ok && $secret_strong && $ttl_ok && $host_allowlist_ok;
		$active_ready = 'remote_redirect' === $provider ? $remote_ready : $local_ready;

		$sendfile_mode = isset( $config['sendfile_mode'] ) ? (string) $config['sendfile_mode'] : 'none';
		$xaccel_prefix = isset( $config['xaccel_prefix'] ) ? trim( (string) $config['xaccel_prefix'] ) : '';
		$xaccel_valid  = 'xaccel' !== $sendfile_mode || ( '' !== $xaccel_prefix && 0 === strpos( $xaccel_prefix, '/' ) );

		return array(
			'active_provider' => $provider,
			'overall_ready'   => $active_ready && $xaccel_valid,
			'delivery_access' => isset( $config['delivery_access'] ) ? (string) $config['delivery_access'] : 'logged_in',
			'local'           => array(
				'ready'           => $local_ready,
				'resolved_path'   => false !== $root ? $root : '',
				'checks'          => $preflight,
				'inventory_count' => $stats['count'],
				'inventory_bytes' => $stats['total_size'],
				'sendfile_mode'   => $sendfile_mode,
				'xaccel_prefix'   => $xaccel_prefix,
				'xaccel_valid'    => $xaccel_valid,
			),
			'remote'          => array(
				'ready'               => $remote_ready,
				'base_url'            => $remote_base,
				'https_valid'         => $https_ok,
				'secret_configured'   => $secret_set,
				'secret_length'       => strlen( $remote_secret ),
				'secret_strong'       => $secret_strong,
				'ttl'                 => $remote_ttl,
				'ttl_valid'           => $ttl_ok,
				'host_allowlist_ok'   => $host_allowlist_ok,
				'sample_download_url' => $sample_download_url,
				'sample_stream_url'   => $sample_stream_url,
			),
		);
	}
}
