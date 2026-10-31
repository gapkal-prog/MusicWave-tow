<?php
/**
 * HLS playback routes (VIP-owned).
 *
 * Registers `manifest`, `variant`, `segment`, and `key` under `/streams/{id}`.
 * Core's `DownloadRoutes` owns the public session lifecycle
 * (`session`/`heartbeat`/`close`) and decides `effective_mode` through the
 * `music_wave_stream_effective_mode` filter; this class never loads a Core
 * provider class and only depends on Core contracts.
 *
 * Playlists are regenerated on every request with fresh short-lived signatures,
 * so a cached playlist cannot outlive its `exp`. Every rendition selector lives
 * in the request **path** (not the query), because `SegmentSigner` covers the
 * path — that is what makes a signed variant/segment URL tamper-proof.
 *
 * @package ManaCore\MusicWave\Vip\Hls
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Hls;

use ManaCore\MusicWave\Core\Access\AccessPolicyEngine;
use ManaCore\MusicWave\Core\Access\AccessSubject;
use ManaCore\MusicWave\Core\Downloads\SegmentSigner;
use ManaCore\MusicWave\Core\Playback\StreamSession;
use ManaCore\MusicWave\Core\Playback\StreamSessionCookie;
use ManaCore\MusicWave\Core\Playback\StreamSessionStore;
use ManaCore\MusicWave\Vip\ProtectedAssetRegistry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HlsRoutes {
	/** Rendition selector segment, e.g. `128k`. */
	private const VARIANT_PATTERN = '[a-z0-9]+';
	/** Segment file name, e.g. `128k-000.ts`. Never contains a slash. */
	private const SEGMENT_PATTERN = '[A-Za-z0-9_-]+\.[A-Za-z0-9]+';

	/** @var SessionGate */
	private $gate;
	/** @var AssetKeyStore */
	private $key_store;
	/** @var SegmentSigner */
	private $segment_signer;
	/** @var AccessPolicyEngine|null */
	private $policy;
	/** @var int Signed URL lifetime in seconds. */
	private $signed_url_ttl;

	public function __construct(
		StreamSessionStore $session_store,
		StreamSessionCookie $session_cookie,
		SegmentSigner $segment_signer,
		AssetKeyStore $key_store,
		?AccessPolicyEngine $policy = null,
		int $signed_url_ttl = 3600,
		bool $bind_ip = false,
		bool $bind_ua = false
	) {
		$this->segment_signer = $segment_signer;
		$this->key_store      = $key_store;
		$this->policy         = $policy;
		$this->signed_url_ttl = max( 300, $signed_url_ttl );
		$this->gate           = new SessionGate( $session_store, $session_cookie, $segment_signer, $policy, $bind_ip, $bind_ua );
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$id = '(?P<id>\d+)';
		register_rest_route(
			'music-wave/v1',
			'/streams/' . $id . '/manifest',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'manifest' ),
				'permission_callback' => array( $this, 'may_request' ),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/streams/' . $id . '/variant/(?P<name>' . self::VARIANT_PATTERN . ')',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'variant' ),
				'permission_callback' => array( $this, 'may_request' ),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/streams/' . $id . '/segment/(?P<file>' . self::SEGMENT_PATTERN . ')',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'segment' ),
				'permission_callback' => array( $this, 'may_request' ),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/streams/' . $id . '/key',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'key' ),
				'permission_callback' => array( $this, 'may_request' ),
			)
		);
	}

	/**
	 * Coarse gate: a logged-in visitor always passes the route check; guests
	 * pass only when the access policy itself allows them. Finer session
	 * binding is enforced per request by `SessionGate`.
	 *
	 * @param mixed $request Incoming request.
	 * @return bool|WP_Error
	 */
	public function may_request( $request ) {
		if ( get_current_user_id() > 0 ) {
			return true;
		}
		$release_id = $request instanceof WP_REST_Request ? absint( $request->get_param( 'id' ) ) : 0;
		if ( null !== $this->policy && $release_id > 0 && $this->policy->decide( $release_id, AccessSubject::current() )->is_allowed() ) {
			return true;
		}

		return new WP_Error(
			'mw_authentication_required',
			__( 'جلسهٔ شما تمام شده است. برای پخش دوباره وارد شوید.', 'music-wave-vip' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * GET /streams/{id}/manifest?sid=...&exp=...&kid=...&sig=...
	 *
	 * Returns `index.m3u8` with per-request signed variant URLs.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function manifest( WP_REST_Request $request ) {
		$release_id = absint( $request->get_param( 'id' ) );
		$session    = $this->gate->authorize(
			StreamUrl::with_query( $release_id, 'manifest', $request->get_query_params() ),
			$release_id,
			$request->get_param( 'sid' ),
			$request->get_param( 'exp' ),
			$request->get_param( 'sig' )
		);
		if ( null === $session ) {
			return $this->denied();
		}

		$content = $this->read_playlist( $release_id, 'index.m3u8' );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		return $this->playlist_response( $this->sign_master_variants( $content, $session ) );
	}

	/**
	 * GET /streams/{id}/variant/{name}?sid=...&exp=...&kid=...&sig=...
	 *
	 * Returns one rendition playlist with signed segment and key URLs.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function variant( WP_REST_Request $request ) {
		$release_id = absint( $request->get_param( 'id' ) );
		$name       = (string) $request->get_param( 'name' );
		if ( 1 !== preg_match( '/^' . self::VARIANT_PATTERN . '$/D', $name ) ) {
			return $this->denied();
		}

		$session = $this->gate->authorize(
			StreamUrl::with_query( $release_id, 'variant/' . $name, $request->get_query_params() ),
			$release_id,
			$request->get_param( 'sid' ),
			$request->get_param( 'exp' ),
			$request->get_param( 'sig' )
		);
		if ( null === $session ) {
			return $this->denied();
		}

		$content = $this->read_playlist( $release_id, $name . '.m3u8' );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		return $this->playlist_response( $this->sign_variant_playlist( $content, $session ) );
	}

	/**
	 * GET /streams/{id}/segment/{file}?sid=...&exp=...&kid=...&sig=...
	 *
	 * Serves an encrypted TS segment. The file name lives in the signed path,
	 * so it cannot be swapped for a different asset's segment.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function segment( WP_REST_Request $request ) {
		$release_id = absint( $request->get_param( 'id' ) );
		$file       = (string) $request->get_param( 'file' );
		if ( 1 !== preg_match( '/^' . self::SEGMENT_PATTERN . '$/D', $file ) || false !== strpos( $file, '..' ) ) {
			return $this->denied();
		}

		$session = $this->gate->authorize(
			StreamUrl::with_query( $release_id, 'segment/' . $file, $request->get_query_params() ),
			$release_id,
			$request->get_param( 'sid' ),
			$request->get_param( 'exp' ),
			$request->get_param( 'sig' )
		);
		if ( null === $session ) {
			return $this->denied();
		}

		$dir     = $this->asset_dir( $release_id );
		$segment = '' !== $dir ? $dir . '/' . $file : '';
		if ( '' === $segment || ! is_file( $segment ) || ! is_readable( $segment ) ) {
			return new WP_Error( 'mw_hls_segment_missing', __( 'تکهٔ مدیا یافت نشد.', 'music-wave-vip' ), array( 'status' => 404 ) );
		}

		$data = file_get_contents( $segment ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local segment inside the protected root; never a remote URL.
		if ( false === $data ) {
			return $this->denied();
		}

		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Content-Type', 'video/mp2t' );
		$response->header( 'Cache-Control', 'private, no-store' );
		$response->header( 'Vary', 'Cookie' );
		$response->header( 'Referrer-Policy', 'no-referrer' );

		return $response;
	}

	/**
	 * GET /streams/{id}/key?sid=...&exp=...&kid=...&sig=...
	 *
	 * Thin route wrapper delegating to `StreamKeyEndpoint`.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function key( WP_REST_Request $request ) {
		$endpoint = new StreamKeyEndpoint(
			$this->gate,
			$this->key_store,
			(int) apply_filters( 'music_wave_session_ttl', 60 )
		);

		return $endpoint( $request );
	}

	/**
	 * Read one playlist file for a release, guarded against traversal.
	 *
	 * @return string|WP_Error Playlist contents, or an error response.
	 */
	private function read_playlist( int $release_id, string $filename ) {
		$dir = $this->asset_dir( $release_id );
		if ( '' === $dir ) {
			return new WP_Error( 'mw_hls_not_ready', __( 'پلیلیست HLS برای این انتشار بستهبندی نشده است.', 'music-wave-vip' ), array( 'status' => 404 ) );
		}

		$path = $dir . '/' . $filename;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'mw_hls_manifest_missing', __( 'فایل پلیلیست روی سرور یافت نشد.', 'music-wave-vip' ), array( 'status' => 404 ) );
		}

		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local playlist inside the protected root; never a remote URL.
		if ( false === $content ) {
			return $this->denied();
		}

		return $content;
	}

	/**
	 * The packaged-output directory for a release's primary asset, or ''.
	 */
	private function asset_dir( int $release_id ): string {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return '';
		}

		$asset_id = $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = 'mw_download_asset_id' LIMIT 1",
				$release_id
			)
		);
		if ( ! is_string( $asset_id ) || 0 !== strpos( $asset_id, ProtectedAssetRegistry::PREFIX ) ) {
			return '';
		}

		$manifest = $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT hls_manifest FROM {$wpdb->prefix}mw_vip_assets WHERE asset_key = %s LIMIT 1",
				$asset_id
			)
		);
		if ( ! is_string( $manifest ) || '' === $manifest ) {
			return '';
		}

		return dirname( $manifest );
	}

	/**
	 * Rewrite master-playlist variant URIs to signed per-request URLs.
	 */
	private function sign_master_variants( string $content, StreamSession $session ): string {
		$exp = time() + $this->signed_url_ttl;
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $content ) as $line ) {
			$line = (string) $line;
			if ( '' === trim( $line ) || 0 === strpos( $line, '#' ) ) {
				$out[] = $line;
				continue;
			}
			$label = preg_replace( '/\.m3u8$/', '', basename( trim( $line ) ) );
			if ( ! is_string( $label ) || 1 !== preg_match( '/^' . self::VARIANT_PATTERN . '$/D', $label ) ) {
				$out[] = $line;
				continue;
			}
			$out[] = $this->signer()->sign( $this->stream_url( $session->release_id(), 'variant/' . $label ), $exp, array( 'sid' => $session->session_id() ) );
		}

		return implode( "\n", $out );
	}

	/**
	 * Rewrite a rendition playlist: key URI and segment URIs to signed URLs.
	 */
	private function sign_variant_playlist( string $content, StreamSession $session ): string {
		$release_id  = $session->release_id();
		$exp         = time() + $this->signed_url_ttl;
		$key_version = $this->key_store->active_version( $session->asset_key() );
		$out         = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $content ) as $line ) {
			$line = (string) $line;
			if ( '' === trim( $line ) ) {
				$out[] = $line;
				continue;
			}
			if ( 0 === strpos( $line, '#EXT-X-KEY' ) ) {
				$out[] = $this->sign_key_uri( $line, $session, $exp, $key_version );
				continue;
			}
			if ( 0 === strpos( $line, '#' ) ) {
				$out[] = $line;
				continue;
			}
			$file = basename( trim( $line ) );
			if ( 1 !== preg_match( '/^' . self::SEGMENT_PATTERN . '$/D', $file ) ) {
				$out[] = $line;
				continue;
			}
			$out[] = $this->signer()->sign( $this->stream_url( $release_id, 'segment/' . $file ), $exp, array( 'sid' => $session->session_id() ) );
		}

		return implode( "\n", $out );
	}

	/**
	 * Replace the `URI="..."` attribute of an `#EXT-X-KEY` tag with a signed
	 * same-origin key URL.
	 */
	private function sign_key_uri( string $line, StreamSession $session, int $exp, int $key_version ): string {
		$extra = array( 'sid' => $session->session_id() );
		if ( $key_version > 0 ) {
			$extra['kver'] = (string) $key_version;
		}
		$url = $this->signer()->sign( $this->stream_url( $session->release_id(), 'key' ), $exp, $extra );

		$rewritten = preg_replace( '/URI="[^"]*"/', 'URI="' . $url . '"', $line, 1 );

		return is_string( $rewritten ) ? $rewritten : $line;
	}

	/**
	 * Same-origin REST URL for a stream sub-resource, matching Core's
	 * `home_url()`-based URL shape.
	 */
	private function stream_url( int $release_id, string $suffix ): string {
		return StreamUrl::build( $release_id, $suffix );
	}

	private function signer(): SegmentSigner {
		return $this->segment_signer;
	}

	private function playlist_response( string $content ): WP_REST_Response {
		$response = new WP_REST_Response( $content, 200 );
		$response->header( 'Content-Type', 'application/vnd.apple.mpegurl' );
		$response->header( 'Cache-Control', 'private, no-store' );
		$response->header( 'Vary', 'Cookie' );
		$response->header( 'Referrer-Policy', 'no-referrer' );

		return $response;
	}

	/**
	 * Denied response, identical shape regardless of reason (no oracle).
	 */
	private function denied(): WP_Error {
		return new WP_Error(
			'mw_hls_denied',
			__( 'دسترسی به پخش HLS مجاز نیست.', 'music-wave-vip' ),
			array( 'status' => 403 )
		);
	}

	private function __clone() {}
}
