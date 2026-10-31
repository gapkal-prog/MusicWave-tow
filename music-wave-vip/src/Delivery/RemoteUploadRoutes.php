<?php
/**
 * Administrative REST routes for the remote-upload pipeline.
 *
 * All routes require `edit_mw_releases`/`edit_post`/`manage_options` — the same
 * gate as `/protected-assets`, so no new capability is introduced.
 *
 * @package ManaCore\MusicWave\Vip\Delivery
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Delivery;

use ManaCore\MusicWave\Vip\ProtectedAssetStorage;
use ManaCore\MusicWave\Vip\ProviderFactory;
use ManaCore\MusicWave\Vip\VipSettings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @immutable
 */
final class RemoteUploadRoutes {
	/** @var RemoteUploadQueue */
	private $queue;

	public function __construct( RemoteUploadQueue $queue ) {
		$this->queue = $queue;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'music-wave/v1',
			'/vip/remote-upload/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test' ),
				'permission_callback' => array( $this, 'may_manage_options' ),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/vip/remote-upload/sync-now',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'sync_now' ),
				'permission_callback' => array( $this, 'may_manage' ),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/vip/remote-upload/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'may_manage' ),
			)
		);
	}

	/**
	 * Mirror `/protected-assets`: the dedicated music-wave asset capability or
	 * administrator, with the existing `music_wave_can_assign_download_asset`
	 * filter as a fallback. (`edit_post` is intentionally NOT used here — a
	 * meta-capability without a post id is invalid.)
	 *
	 * @param mixed $request Incoming request.
	 * @return bool|WP_Error
	 */
	public function may_manage( $request ) {
		if ( current_user_can( 'manage_mw_protected_assets' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( has_filter( 'music_wave_can_assign_download_asset' ) && (bool) apply_filters( 'music_wave_can_assign_download_asset', false, $request ) ) {
			return true;
		}

		return new WP_Error(
			'mw_upload_forbidden',
			__( 'اجازهٔ مدیریت آپلود راهدور را ندارید.', 'music-wave-vip' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Restrict connection tests and state-changing provisioning strictly to
	 * administrators.
	 *
	 * @param mixed $request Incoming request.
	 * @return bool|WP_Error
	 */
	public function may_manage_options( $request ) {
		unset( $request );
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'mw_upload_forbidden',
			__( 'اجازهٔ مدیریت آپلود راهدور را ندارید.', 'music-wave-vip' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * POST /vip/remote-upload/test — run the configured adapter's connection test.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function test( WP_REST_Request $request ) {
		unset( $request );
		$config  = VipSettings::all();
		$adapter = ProviderFactory::remote_upload_adapter( $config );
		if ( null === $adapter ) {
			return new WP_Error( 'mw_upload_adapter_missing', __( 'آداپتور انتخابشده در دسترس نیست.', 'music-wave-vip' ), array( 'status' => 501 ) );
		}

		$check    = $adapter->test_connection();
		$response = new WP_REST_Response(
			array(
				'ok'    => (bool) $check['ok'],
				'error' => $check['error'] ?? null,
			),
			$check['ok'] ? 200 : 422
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * POST /vip/remote-upload/sync-now — enqueue one asset, or the whole library.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function sync_now( WP_REST_Request $request ) {
		$config = VipSettings::all();
		if ( ! RemoteUploadManager::remote_bytes_required( $config ) ) {
			return new WP_Error( 'mw_upload_not_required', __( 'آپلود خودکار در حالت تحویل فعلی لازم نیست.', 'music-wave-vip' ), array( 'status' => 400 ) );
		}

		$adapter = ProviderFactory::remote_upload_adapter( $config );
		if ( null === $adapter ) {
			return new WP_Error( 'mw_upload_adapter_missing', __( 'آداپتور انتخابشده در دسترس نیست.', 'music-wave-vip' ), array( 'status' => 501 ) );
		}

		$manager   = new RemoteUploadManager( $adapter, $this->queue, new ProtectedAssetStorage(), $config );
		$asset_key = $request->get_param( 'asset_key' );

		if ( is_string( $asset_key ) && '' !== $asset_key ) {
			$manager->enqueue( $asset_key );
			$result = $manager->sync( $asset_key );
		} else {
			// Library-wide sync uploads every asset: administrators only.
			if ( ! current_user_can( 'manage_options' ) ) {
				return new WP_Error( 'mw_upload_forbidden', __( 'همگامسازی کل کتابخانه فقط برای مدیران مجاز است.', 'music-wave-vip' ), array( 'status' => 403 ) );
			}
			$result = $manager->sync_all();
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /vip/remote-upload/status — queue counts plus readiness diagnostics.
	 *
	 * @return WP_REST_Response
	 */
	public function status( WP_REST_Request $request ) {
		unset( $request );
		$diagnostics = new RemoteUploadDiagnostics( VipSettings::all(), $this->queue );

		return new WP_REST_Response(
			array(
				'queue'       => $this->queue->stats(),
				'diagnostics' => $diagnostics->snapshot(),
			),
			200
		);
	}

	private function __clone() {}
}
