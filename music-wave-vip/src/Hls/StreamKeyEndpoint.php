<?php
/**
 * Session-scoped AES-128 key issuer for HLS playback.
 *
 * The 16-byte key is returned only when the caller presents a valid, live
 * session (cookie + signed URL). The response is deliberately tiny and
 * cache-disabled; the key never touches the CDN.
 *
 * @package ManaCore\MusicWave\Vip\Hls
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Hls;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StreamKeyEndpoint {
	/** @var SessionGate */
	private $gate;
	/** @var AssetKeyStore */
	private $key_store;
	/** @var int Session TTL (seconds) applied on sliding renewal. */
	private $session_ttl;

	public function __construct( SessionGate $gate, AssetKeyStore $key_store, int $session_ttl = 60 ) {
		$this->gate        = $gate;
		$this->key_store   = $key_store;
		$this->session_ttl = max( 30, $session_ttl );
	}

	/**
	 * Handle GET /streams/{id}/key?sid=...&exp=...&kid=...&sig=...
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function __invoke( WP_REST_Request $request ) {
		$release_id = absint( $request->get_param( 'id' ) );
		$session    = $this->gate->authorize(
			StreamUrl::with_query( $release_id, 'key', $request->get_query_params() ),
			$release_id,
			$request->get_param( 'sid' ),
			$request->get_param( 'exp' ),
			$request->get_param( 'sig' )
		);
		if ( null === $session ) {
			return $this->forbidden();
		}

		$kver = $request->get_param( 'kver' );
		if ( is_numeric( $kver ) && (int) $kver > 0 ) {
			// Pinned by the variant playlist that referenced this key, so a
			// repackage mid-playback cannot swap the key under a live session.
			$raw_key = $this->key_store->key_by_version( $session->asset_key(), (int) $kver );
		} else {
			$raw_key = $this->key_store->active_key( $session->asset_key() );
		}
		if ( null === $raw_key || 16 !== strlen( $raw_key ) ) {
			return $this->forbidden();
		}

		// Refresh the sliding lease on every successful key fetch.
		$this->gate->renew( $session, $this->session_ttl );

		$response = new WP_REST_Response( $raw_key, 200 );
		$response->header( 'Content-Type', 'application/octet-stream' );
		$response->header( 'Content-Length', '16' );
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'Vary', 'Cookie' );
		$response->header( 'Referrer-Policy', 'no-referrer' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );

		return $response;
	}

	private function forbidden(): WP_Error {
		return new WP_Error(
			'mw_hls_key_denied',
			__( 'دسترسی به کلید پخش مجاز نیست.', 'music-wave-vip' ),
			array( 'status' => 403 )
		);
	}

	private function __clone() {}
}
