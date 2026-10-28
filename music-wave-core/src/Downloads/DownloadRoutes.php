<?php
/**
 * Authenticated token issuance and protected delivery routes.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

use ManaCore\MusicWave\Core\Access\AccessSubject;
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

	public function __construct( DownloadResolver $resolver, ?DownloadRateLimiter $rate_limiter = null ) {
		$this->resolver     = $resolver;
		$this->rate_limiter = null !== $rate_limiter ? $rate_limiter : new DownloadRateLimiter();
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
		if ( $release_id > 0 && $this->resolver->can_request( $release_id ) ) {
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
			if ( 'invalid_direct_url' === $reason ) {
				return new WP_Error(
					'mw_download_denied',
					__( 'نشانی لینک مستقیم این فایل معتبر نیست یا خارج از دامنه‌های مجاز تحویل قرار دارد.', 'music-wave-core' ),
					array( 'status' => 403 )
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
		if ( 'provider_unavailable' === $this->resolver->last_deny_reason() ) {
			return new WP_Error(
				'mw_download_denied',
				__( 'دانلودهای حفاظت‌شده VIP در حال حاضر در دسترس نیستند.', 'music-wave-core' ),
				array( 'status' => 503 )
			);
		}

		return new WP_Error( 'mw_download_denied', __( 'پیوند دانلود امن نامعتبر است یا منقضی شده است.', 'music-wave-core' ), array( 'status' => 403 ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function stream( WP_REST_Request $request ) {
		$release_id = absint( $request->get_param( 'id' ) );
		$token      = $request->get_param( 'token' );
		$nonce      = $request->get_param( 'nonce' );
		$valid      = is_string( $token )
			&& is_string( $nonce )
			&& wp_verify_nonce( $nonce, 'music_wave_download_' . $release_id )
			&& $this->resolver->stream( $release_id, get_current_user_id(), $token, $nonce );

		return $valid
			? new WP_REST_Response( null, 204 )
			: new WP_Error( 'mw_stream_denied', __( 'پیوند پخش امن نامعتبر است یا منقضی شده است.', 'music-wave-core' ), array( 'status' => 403 ) );
	}
}
