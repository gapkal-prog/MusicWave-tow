<?php
/**
 * Authorized protected-asset browsing, upload, diagnostics, provisioning, and deletion endpoints.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip;

use ManaCore\MusicWave\Vip\Delivery\ProtectedDeliveryDiagnostics;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProtectedAssetRoutes {
	/** @var ProtectedAssetStorage */
	private $storage;

	public function __construct( ProtectedAssetStorage $storage ) {
		$this->storage = $storage;
	}

	/**
	 * Register provider-specific editor and admin delivery routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register protected asset routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'music-wave/v1',
			'/protected-assets',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_manage_assets' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'upload' ),
					'permission_callback' => array( $this, 'can_manage_assets' ),
				),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/protected-assets/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_attachment' ),
				'permission_callback' => array( $this, 'can_manage_assets' ),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/protected-assets/diagnostics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'diagnostics' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/protected-assets/provision',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'provision' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/protected-assets/(?P<key>[a-f0-9]{32})',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'destroy' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/protected-assets/edge-test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'edge_test' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
			)
		);
	}

	/**
	 * Require the dedicated MusicWave asset capability or administrator `manage_options`.
	 */
	public function can_manage_assets(): bool {
		return current_user_can( 'manage_mw_protected_assets' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Restrict provisioning, diagnostics, and destructive deletion strictly to administrators.
	 */
	public function can_manage_options(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * List private asset identifiers without exposing their filesystem paths.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$search   = $request->get_param( 'search' );
		$search   = is_scalar( $search ) ? sanitize_text_field( (string) $search ) : '';
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = absint( $request->get_param( 'per_page' ) );
		$per_page = min( 100, max( 1, $per_page > 0 ? $per_page : 50 ) );
		$assets   = $this->storage->list_assets( $search, $page, $per_page );

		return new WP_REST_Response(
			array(
				'items'    => $assets['items'],
				'page'     => $page,
				'per_page' => $per_page,
				'has_more' => $assets['has_more'],
			),
			200
		);
	}

	/**
	 * Accept one file and persist it outside the public WordPress root.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : array();
		$asset = $this->storage->upload( $file );
		if ( $asset instanceof WP_Error ) {
			return $asset;
		}

		return new WP_REST_Response( array( 'asset' => $asset ), 201 );
	}

	/**
	 * Copy a selected WordPress attachment into protected storage.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_attachment( WP_REST_Request $request ) {
		$asset = $this->storage->import_attachment( absint( $request->get_param( 'attachment_id' ) ) );
		if ( $asset instanceof WP_Error ) {
			return $asset;
		}

		return new WP_REST_Response( array( 'asset' => $asset ), 201 );
	}

	/**
	 * Return real-time protected delivery health and live sample signed URLs.
	 */
	public function diagnostics(): WP_REST_Response {
		$diagnostics = new ProtectedDeliveryDiagnostics( $this->storage );

		return new WP_REST_Response( $diagnostics->snapshot(), 200 );
	}

	/**
	 * Probe the configured edge base URL for `edge_signed`/`edge_hls` readiness.
	 *
	 * @return WP_REST_Response
	 */
	public function edge_test(): WP_REST_Response {
		$result = ( new \ManaCore\MusicWave\Vip\Delivery\EdgeConnectionManager( VipSettings::all() ) )->test();

		return new WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
	}

	/**
	 * Provision & harden the protected directory and synchronize inventory checksums.
	 */
	public function provision(): WP_REST_Response {
		$sync = $this->storage->sync_inventory();
		if ( $sync instanceof WP_Error ) {
			return new WP_REST_Response(
				array(
					'ok'          => false,
					'message'     => $sync->get_error_message(),
					'diagnostics' => ( new ProtectedDeliveryDiagnostics( $this->storage ) )->snapshot(),
				),
				400
			);
		}

		return new WP_REST_Response(
			array(
				'ok'          => true,
				'sync'        => $sync,
				'diagnostics' => ( new ProtectedDeliveryDiagnostics( $this->storage ) )->snapshot(),
			),
			200
		);
	}

	/**
	 * Delete a registered protected asset from storage and registry.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function destroy( WP_REST_Request $request ) {
		$key    = sanitize_key( (string) $request->get_param( 'key' ) );
		$result = $this->storage->delete_asset( $key );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'deleted'     => true,
				'diagnostics' => ( new ProtectedDeliveryDiagnostics( $this->storage ) )->snapshot(),
			),
			200
		);
	}
}
