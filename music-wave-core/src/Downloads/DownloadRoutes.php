<?php
/**
 * Authenticated token issuance, protected delivery, and playback-session routes.
 *
 * Public session paths (`session`/`heartbeat`/`close`) live in Core. VIP owns
 * the HLS-only paths (`manifest`/`segment`/`key`) via `HlsRoutes`. Core decides
 * `effective_mode` only through the `music_wave_stream_effective_mode` filter
 * and never loads a VIP class.
 *
 * @package ManaCore\MusicWave\Core\Downloads
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

use ManaCore\MusicWave\Core\Access\AccessSubject;
use ManaCore\MusicWave\Core\Playback\StreamFingerprint;
use ManaCore\MusicWave\Core\Playback\StreamSessionCookie;
use ManaCore\MusicWave\Core\Playback\StreamSessionStore;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DownloadRoutes {
	/** @var DownloadResolver */
	private $resolver;

	/** @var DownloadRateLimiter */
	private $rate_limiter;

	/** @var StreamSessionStore|null */
	private $session_store;

	/** @var StreamSessionCookie|null */
	private $session_cookie;

	/** @var SegmentSigner|null */
	private $signer;

	public function __construct(
		?DownloadResolver $resolver = null,
		?DownloadRateLimiter $rate_limiter = null,
		?StreamSessionStore $session_store = null,
		?StreamSessionCookie $session_cookie = null,
		?SegmentSigner $signer = null
	) {
		$this->resolver       = $resolver;
		$this->rate_limiter   = null !== $rate_limiter ? $rate_limiter : new DownloadRateLimiter();
		$this->session_store  = $session_store;
		$this->session_cookie = $session_cookie;
		$this->signer         = $signer;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'music-wave/v1',
			'/releases/(?P<id>\d+)/download-token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'issue' ),
				'permission_callback' => array( $this, 'may_request' ),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/downloads/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'download' ),
				'permission_callback' => array( $this, 'may_request' ),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/streams/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'stream' ),
				'permission_callback' => array( $this, 'may_request' ),
			)
		);
		// Playback session lifecycle (ADR 0006). Only registered when the
		// session store is wired, so sites opting out of edge/session delivery
		// see no new surface area.
		if ( null !== $this->session_store && null !== $this->session_cookie ) {
			$this->register_session_routes();
		}
	}

	/**
	 * Register the session lifecycle routes.
	 *
	 * Only ever called from `register_routes()`, i.e. inside `rest_api_init`;
	 * registering a route on any earlier hook triggers WordPress' "called
	 * incorrectly" notice and can recursively fire `rest_api_init` before
	 * dependent plugins are ready.
	 */
	private function register_session_routes(): void {
		register_rest_route(
			'music-wave/v1',
			'/streams/(?P<id>\d+)/session',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_session' ),
				'permission_callback' => array( $this, 'may_request' ),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/streams/(?P<id>\d+)/heartbeat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'heartbeat' ),
				'permission_callback' => array( $this, 'may_request' ),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/streams/(?P<id>\d+)/close',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'close_session' ),
				'permission_callback' => array( $this, 'may_request' ),
			)
		);
	}

	/**
	 * Policy-aware endpoint gate.
	 *
	 * Logged-in visitors pass as before. Guests pass exactly when the access
	 * policy allows them for this release (public releases or a policy-level
	 * open gate such as the VIP "everyone including guests" delivery mode);
	 * everyone else receives an actionable 401 instead of REST's generic one.
	 *
	 * @param WP_REST_Request $request Request carrying the release id.
	 * @return true|WP_Error
	 */
	public function may_request( $request ) {
		if ( get_current_user_id() > 0 ) {
			return true;
		}
		$release_id = $request instanceof WP_REST_Request ? absint( $request->get_param( 'id' ) ) : 0;
		if ( null !== $this->resolver && $release_id > 0 && $this->resolver->can_request( $release_id ) ) {
			return true;
		}

		return new WP_Error(
			'mw_authentication_required',
			__( 'جلسه شما تمام شده است. برای دانلود این انتشار دوباره وارد شوید.', 'music-wave-core' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Issue a short-lived token bound to the current user, release and asset.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function issue( WP_REST_Request $request ) {
		if ( ! $this->rate_limiter->allow( get_current_user_id() ) ) {
			return new WP_Error(
				'mw_download_rate_limited',
				__( 'درخواست‌های دانلود خیلی زیاد است. یک لحظه صبر کنید و دوباره امتحان کنید.', 'music-wave-core' ),
				array( 'status' => 429 )
			);
		}

		$release_id = absint( $request->get_param( 'id' ) );
		$quality    = $request->get_param( 'quality' );
		$quality    = is_scalar( $quality ) ? sanitize_key( (string) $quality ) : '';
		$purpose    = $request->get_param( 'purpose' );
		$purpose    = is_scalar( $purpose ) && 'stream' === sanitize_key( (string) $purpose ) ? 'stream' : 'download';
		$nonce      = wp_create_nonce( 'music_wave_download_' . $release_id );
		$token      = $this->resolver->issue( $release_id, AccessSubject::current(), $nonce, $quality, $purpose );

		if ( null === $token ) {
			$reason = method_exists( $this->resolver, 'last_deny_reason' ) ? $this->resolver->last_deny_reason() : '';
			if ( 'authentication_required' === $reason ) {
				return new WP_Error(
					'mw_authentication_required',
					__( 'برای پخش یا دانلود فایل حفاظت‌شده، ابتدا وارد حساب کاربری خود شوید.', 'music-wave-core' ),
					array( 'status' => 401 )
				);
			}
			if ( 'provider_unavailable' === $reason ) {
				return new WP_Error(
					'mw_download_denied',
					__( 'دانلودهای حفاظت‌شده VIP در حال حاضر در دسترس نیستند. ماژول VIP غیرفعال است یا ذخیره‌سازی آن پیکربندی‌نشده است. لطفاً با مدیر سایت تماس بگیرید یا در صورت وجود از یک گزینهٔ مبتنی بر خرید استفاده کنید.', 'music-wave-core' ),
					array( 'status' => 503 )
				);
			}
			if ( 'stream_unsupported' === $reason ) {
				return new WP_Error(
					'mw_stream_denied',
					__( 'پخش امن برای این نوع فایل در دسترس نیست. به جای آن دانلود کنید.', 'music-wave-core' ),
					array( 'status' => 400 )
				);
			}
			return new WP_Error(
				'mw_download_denied',
				__( 'این فایل در دسترس نیست یا حساب شما به آن دسترسی ندارد.', 'music-wave-core' ),
				array( 'status' => 403 )
			);
		}

		$payload = array(
			'token'      => $token,
			'nonce'      => $nonce,
			'purpose'    => $purpose,
			'expires_in' => $this->resolver->ttl_for( $purpose ),
		);
		// Only expose wp_rest nonce to logged-in users (FIX-19). Guest delivery works via can_request without it.
		if ( get_current_user_id() > 0 ) {
			$payload['rest_nonce'] = wp_create_nonce( 'wp_rest' );
		}
		$response = new WP_REST_Response( $payload, 200 );
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'Referrer-Policy', 'no-referrer' );

		return $response;
	}

	/** @return WP_REST_Response|WP_Error */
	public function download( WP_REST_Request $request ) {
		$release_id = absint( $request->get_param( 'id' ) );
		$token      = $request->get_param( 'token' );
		$nonce      = $request->get_param( 'nonce' );
		$valid      = is_string( $token )
			&& is_string( $nonce )
			&& wp_verify_nonce( $nonce, 'music_wave_download_' . $release_id )
			&& $this->resolver->deliver( $release_id, get_current_user_id(), $token, $nonce );

		if ( $valid ) {
			return new WP_REST_Response( null, 204 );
		}
		if ( 'quota_exceeded' === $this->resolver->last_deny_reason() ) {
			return new WP_Error(
				'mw_download_quota_exceeded',
				__( 'سهمیهٔ مجاز دانلود روزانهٔ شما تکمیل شده است. لطفاً فردا دوباره تلاش کنید.', 'music-wave-core' ),
				array( 'status' => 429 )
			);
		}
		if ( 'authentication_required' === $this->resolver->last_deny_reason() ) {
			return new WP_Error(
				'mw_authentication_required',
				__( 'برای دانلود این فایل وارد حساب کاربری خود شوید.', 'music-wave-core' ),
				array( 'status' => 401 )
			);
		}

		return new WP_Error( 'mw_download_denied', __( 'پیوند دانلود امن نامعتبر است یا منقضی شده است.', 'music-wave-core' ), array( 'status' => 403 ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function stream( WP_REST_Request $request ) {
		$release_id = absint( $request->get_param( 'id' ) );
		$token      = $request->get_param( 'token' );
		$nonce      = $request->get_param( 'nonce' );

		// A playback stream issues many Range requests, so it cannot use the
		// one-time replay token (that would break seeking); throttle it instead.
		if ( ! $this->rate_limiter->allow( get_current_user_id() ) ) {
			return new WP_Error(
				'mw_stream_rate_limited',
				__( 'درخواستهای پخش پیوسته زیاد است. یک لحظه صبر کنید.', 'music-wave-core' ),
				array( 'status' => 429 )
			);
		}

		$valid = is_string( $token )
			&& is_string( $nonce )
			&& wp_verify_nonce( $nonce, 'music_wave_download_' . $release_id )
			&& $this->resolver->stream( $release_id, get_current_user_id(), $token, $nonce );

		return $valid
			? new WP_REST_Response( null, 204 )
			: new WP_Error( 'mw_stream_denied', __( 'پیوند پخش امن نامعتبر است یا منقضی شده است.', 'music-wave-core' ), array( 'status' => 403 ) );
	}

	/**
	 * Create a playback session, issue the HttpOnly cookie, and resolve the
	 * effective delivery mode (+ signed playback/manifest URL).
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_session( WP_REST_Request $request ) {
		$release_id = absint( $request->get_param( 'id' ) );
		$nonce      = $request->get_param( '_wpnonce' );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'mw_nonce_invalid',
				__( 'اعتبارسنجی nonce ناموفق است.', 'music-wave-core' ),
				array( 'status' => 403 )
			);
		}

		if ( null === $this->session_store || null === $this->session_cookie ) {
			return new WP_Error(
				'mw_session_unavailable',
				__( 'سیستم نشست پخش در این سایت فعال نیست.', 'music-wave-core' ),
				array( 'status' => 501 )
			);
		}

		if ( ! $this->rate_limiter->allow( get_current_user_id() ) ) {
			return new WP_Error(
				'mw_session_rate_limited',
				__( 'درخواست‌های پیوسته زیاد است. یک لحظه صبر کنید.', 'music-wave-core' ),
				array( 'status' => 429 )
			);
		}

		$subject   = AccessSubject::current();
		$asset_key = '';

		if ( null !== $this->resolver ) {
			if ( ! $this->resolver->can_request( $release_id ) ) {
				$reason = $this->resolver->last_deny_reason();
				if ( 'authentication_required' === $reason ) {
					return new WP_Error(
						'mw_authentication_required',
						__( 'برای پخش این فایل وارد حساب کاربری خود شوید.', 'music-wave-core' ),
						array( 'status' => 401 )
					);
				}
				return new WP_Error(
					'mw_stream_denied',
					__( 'پخش این فایل مجاز نیست.', 'music-wave-core' ),
					array( 'status' => 403 )
				);
			}
			$asset = $this->resolver->asset_for_session( $release_id );
			if ( null !== $asset && isset( $asset['asset_id'] ) ) {
				// Bind the session to the opaque asset identifier so the HLS key
				// endpoint can resolve the correct wrapped key later.
				$asset_key = (string) $asset['asset_id'];
			}
		} elseif ( $subject->user_id() < 1 ) {
			// Session-only router without a full resolver: only logged-in users
			// may open a session here.
			return new WP_Error(
				'mw_authentication_required',
				__( 'برای پخش این فایل وارد حساب کاربری خود شوید.', 'music-wave-core' ),
				array( 'status' => 401 )
			);
		}

		$ip_hash         = StreamFingerprint::ip_hash();
		$ua_hash         = StreamFingerprint::ua_hash();
		$ttl             = (int) apply_filters( 'music_wave_session_ttl', 60 );
		$max_conc        = max( 1, (int) apply_filters( 'music_wave_session_max_concurrent', 1 ) );
		$concurrency_key = $subject->user_id() > 0 ? 'u' . $subject->user_id() : 'i' . $ip_hash;

		if ( $this->session_store->count_active( $concurrency_key ) >= $max_conc ) {
			return new WP_Error(
				'mw_session_concurrent_limit',
				__( 'پخش همزمان مجاز برای این حساب تمام است.', 'music-wave-core' ),
				array( 'status' => 429 )
			);
		}

		// Issue the browser cookie first, then bind the session to its hash.
		$plain_token = $this->session_cookie->issue();
		$token_hash  = hash( 'sha256', $plain_token );

		$session = $this->session_store->create(
			$subject->user_id(),
			$release_id,
			$asset_key,
			$ip_hash,
			$ua_hash,
			$token_hash,
			$ttl,
			$max_conc
		);
		if ( null === $session ) {
			// A null result after passing the pre-check means the atomic lease
			// inside the store lost a race: report the concurrency limit, not a
			// generic server error.
			if ( $this->session_store->count_active( $concurrency_key ) >= $max_conc ) {
				return new WP_Error(
					'mw_session_concurrent_limit',
					__( 'پخش همزمان مجاز برای این حساب تمام است.', 'music-wave-core' ),
					array( 'status' => 429 )
				);
			}
			return new WP_Error(
				'mw_session_create_failed',
				__( 'ساخت نشست پخش ناموفق بود.', 'music-wave-core' ),
				array( 'status' => 500 )
			);
		}

		$effective_mode = (string) apply_filters(
			'music_wave_stream_effective_mode',
			'edge_signed',
			$release_id,
			$session->session_id()
		);

		$playback_url = '';
		$manifest_url = '';
		if ( null !== $this->signer ) {
			$now  = time();
			$base = home_url( '/' );
			if ( 'edge_hls' === $effective_mode ) {
				$manifest_url = $this->signer->sign(
					trailingslashit( $base ) . 'wp-json/music-wave/v1/streams/' . $release_id . '/manifest',
					$now + (int) apply_filters( 'music_wave_signed_url_ttl', 3600 ),
					array( 'sid' => $session->session_id() )
				);
			} else {
				$playback_url = $this->signer->sign(
					trailingslashit( $base ) . 'wp-json/music-wave/v1/streams/' . $release_id,
					$now + (int) apply_filters( 'music_wave_signed_url_ttl', 3600 ),
					array( 'sid' => $session->session_id() )
				);
			}
		}

		do_action( 'music_wave_stream_session_created', $release_id, $subject->user_id(), $session->session_id() );

		$response = new WP_REST_Response(
			array(
				'session_id'      => $session->session_id(),
				'effective_mode'  => $effective_mode,
				'playback_url'    => $playback_url,
				'manifest_url'    => $manifest_url,
				'expires_in'      => $ttl,
				'concurrency_key' => $concurrency_key,
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'Referrer-Policy', 'no-referrer' );

		return $response;
	}

	/**
	 * Extend the session lease (sliding renewal) and refresh last_seen.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function heartbeat( WP_REST_Request $request ) {
		$session_id = $request->get_param( 'session_id' );
		$nonce      = $request->get_param( '_wpnonce' );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'mw_nonce_invalid', __( 'اعتبارسنجی nonce ناموفق است.', 'music-wave-core' ), array( 'status' => 403 ) );
		}
		if ( ! is_string( $session_id ) || '' === $session_id ) {
			return new WP_Error( 'mw_session_missing', __( 'شناسهٔ نشست ارسال نشده است.', 'music-wave-core' ), array( 'status' => 400 ) );
		}

		$token = isset( $_COOKIE[ StreamSessionCookie::NAME ] ) && is_scalar( $_COOKIE[ StreamSessionCookie::NAME ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ StreamSessionCookie::NAME ] ) ) : '';
		if ( null === $this->session_store || null === $this->session_cookie ) {
			return new WP_Error( 'mw_session_unavailable', __( 'سیستم نشست پخش فعال نیست.', 'music-wave-core' ), array( 'status' => 501 ) );
		}

		$session = $this->session_store->get_by_id( $session_id );
		if ( null === $session || ! $this->session_cookie->matches( $token, $session->token_hash() ) ) {
			return new WP_Error( 'mw_session_invalid', __( 'نشست نامعتبر یا منقضی شده است.', 'music-wave-core' ), array( 'status' => 403 ) );
		}

		// Sliding renewal can be switched off (fixed-lifetime sessions); the
		// heartbeat still validates the session but does not extend it.
		if ( ! (bool) apply_filters( 'music_wave_session_sliding_renewal', true ) ) {
			return new WP_REST_Response(
				array(
					'expires_in' => 0,
					'renewed'    => false,
				),
				200
			);
		}

		$ttl     = (int) apply_filters( 'music_wave_session_ttl', 60 );
		$renewed = $this->session_store->renew( $session_id, hash( 'sha256', $token ), $ttl );
		if ( null === $renewed ) {
			return new WP_Error( 'mw_session_renew_failed', __( 'تأمین lease ناموفق بود.', 'music-wave-core' ), array( 'status' => 403 ) );
		}

		return new WP_REST_Response( array( 'expires_in' => $ttl ), 200 );
	}

	/**
	 * Revoke a session when playback ends (or on logout/access loss).
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function close_session( WP_REST_Request $request ) {
		$session_id = $request->get_param( 'session_id' );
		$nonce      = $request->get_param( '_wpnonce' );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'mw_nonce_invalid', __( 'اعتبارسنجی nonce ناموفق است.', 'music-wave-core' ), array( 'status' => 403 ) );
		}
		if ( ! is_string( $session_id ) || '' === $session_id ) {
			return new WP_Error( 'mw_session_missing', __( 'شناسهٔ نشست ارسال نشده است.', 'music-wave-core' ), array( 'status' => 400 ) );
		}

		$token = isset( $_COOKIE[ StreamSessionCookie::NAME ] ) && is_scalar( $_COOKIE[ StreamSessionCookie::NAME ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ StreamSessionCookie::NAME ] ) ) : '';
		if ( null === $this->session_store || null === $this->session_cookie ) {
			return new WP_Error( 'mw_session_unavailable', __( 'سیستم نشست پخش فعال نیست.', 'music-wave-core' ), array( 'status' => 501 ) );
		}

		$session = $this->session_store->get_by_id( $session_id );
		if ( null === $session || ! $this->session_cookie->matches( $token, $session->token_hash() ) ) {
			return new WP_Error( 'mw_session_invalid', __( 'نشست نامعتبر است.', 'music-wave-core' ), array( 'status' => 403 ) );
		}

		$this->session_store->revoke( $session_id );
		$this->session_cookie->clear();

		do_action( 'music_wave_stream_session_closed', $session->release_id(), $session->user_id(), $session_id );

		return new WP_REST_Response( null, 204 );
	}
}
