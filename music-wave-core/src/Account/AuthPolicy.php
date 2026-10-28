<?php
/**
 * Shared authentication contract: nonce names, safe redirects, user-facing
 * message catalogue, and a hardened two-axis (IP + identifier) throttle.
 *
 * One single source of truth for the auth forms, the `wp-login.php` hardening
 * hooks, and any future provider, so security rules cannot drift apart
 * (DRY / SOLID SRP).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Account;

use ManaCore\MusicWave\Core\Support\ClientIp;
use ManaCore\MusicWave\Core\Support\TransientCounter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AuthPolicy {
	/** Nonce action protecting the sign-in form. */
	public const LOGIN_NONCE = 'mw_auth_login';

	/** Nonce action protecting the sign-up form. */
	public const REGISTER_NONCE = 'mw_auth_register';

	/** Hidden field carrying the nonce value. */
	public const NONCE_FIELD = 'mw_auth_nonce';

	/** Marker field proving the submission came from a MusicWave form. */
	public const MARKER_FIELD = 'mw_auth_context';

	/** Query argument carrying a machine-readable error code. */
	public const ERROR_ARG = 'mw_auth_error';

	/** Query argument carrying a machine-readable success code. */
	public const NOTICE_ARG = 'mw_auth_notice';

	/** Query argument selecting the visible panel (`login`|`register`). */
	public const PANEL_ARG = 'mw_auth';

	/** Failed attempts allowed per client address inside one window. */
	public const MAX_ATTEMPTS_IP = 10;

	/** Failed attempts allowed per account identifier inside one window. */
	public const MAX_ATTEMPTS_ID = 5;

	/** Sliding window length in seconds. */
	public const WINDOW = 900;

	/** Lockout duration in seconds once the window is exhausted. */
	public const LOCKOUT = 900;

	/**
	 * Read one sanitized request value.
	 *
	 * @param string $key  Superglobal key.
	 * @param string $from `get` or `post`.
	 */
	public static function request_value( string $key, string $from = 'get' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- read-only value, sanitized immediately below.
		$source = 'post' === $from ? $_POST : $_GET;
		if ( ! isset( $source[ $key ] ) || ! is_scalar( $source[ $key ] ) ) {
			return '';
		}

		return function_exists( 'wp_unslash' ) ? sanitize_text_field( wp_unslash( (string) $source[ $key ] ) ) : sanitize_text_field( (string) $source[ $key ] );
	}

	/**
	 * Resolve the page that hosts the sign-in / sign-up forms.
	 *
	 * Prefers a published page using the bundled `login` template, then falls
	 * back to `wp-login.php` so the flow never dead-ends.
	 */
	public static function login_page_url(): string {
		$page = get_page_by_path( 'login' );
		if ( $page instanceof \WP_Post && 'publish' === $page->post_status ) {
			$permalink = get_permalink( $page );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				return $permalink;
			}
		}

		return wp_login_url();
	}

	/**
	 * Sign-in URL, optionally carrying a validated post-login destination.
	 *
	 * @param string $redirect Requested destination on this site.
	 */
	public static function login_url( string $redirect = '' ): string {
		$base     = self::login_page_url();
		$redirect = self::safe_redirect( $redirect, '' );

		return '' === $redirect ? $base : add_query_arg( 'redirect_to', $redirect, $base );
	}

	/**
	 * Destination used after a successful sign-in when no explicit target exists.
	 */
	public static function account_url(): string {
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$url = wc_get_page_permalink( 'myaccount' );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		$page = get_page_by_path( 'account' );
		if ( $page instanceof \WP_Post && 'publish' === $page->post_status ) {
			$permalink = get_permalink( $page );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				return $permalink;
			}
		}

		return home_url( '/' );
	}

	/**
	 * Safely resolve a post-login destination.
	 *
	 * Only same-host URLs survive `wp_validate_redirect()`, which blocks open
	 * redirects and header injection.
	 *
	 * @param string $requested Requested destination.
	 * @param string $fallback  Local fallback when the request is unsafe.
	 */
	public static function safe_redirect( string $requested, string $fallback = '' ): string {
		$requested = trim( $requested );
		if ( '' === $requested ) {
			return '' !== $fallback ? $fallback : home_url( '/' );
		}

		$validated = function_exists( 'wp_validate_redirect' )
			? wp_validate_redirect( $requested, '' )
			: '';

		if ( ! is_string( $validated ) || '' === $validated ) {
			return '' !== $fallback ? $fallback : home_url( '/' );
		}

		return $validated;
	}

	/**
	 * Human-readable, translated copy for one machine code.
	 *
	 * @param string $code Machine code.
	 */
	public static function message( string $code ): string {
		$messages = array(
			'invalid-nonce'             => __( 'اعتبار فرم منقضی شده است. صفحه را تازه کنید و دوباره تلاش کنید.', 'music-wave-core' ),
			'locked'                    => __( 'تعداد تلاش‌های ناموفق بیش از حد مجاز است. چند دقیقه بعد دوباره تلاش کنید.', 'music-wave-core' ),
			'invalid-credentials'       => __( 'نام کاربری یا گذرواژه درست نیست.', 'music-wave-core' ),
			'empty-credentials'         => __( 'نام کاربری و گذرواژه را کامل وارد کنید.', 'music-wave-core' ),
			'registered'                => __( 'حساب شما ساخته شد. برای ادامه وارد شوید.', 'music-wave-core' ),
			'registration-closed'       => __( 'ثبت‌نام در این سایت غیرفعال است.', 'music-wave-core' ),
			'password-mismatch'         => __( 'گذرواژه‌ها یکسان نیستند.', 'music-wave-core' ),
			'password-too-short'        => __( 'گذرواژه باید دست‌کم ۸ نویسه باشد.', 'music-wave-core' ),
			'invalid-request'           => __( 'درخواست نامعتبر بود. صفحه را تازه کنید و دوباره تلاش کنید.', 'music-wave-core' ),
			'already-signed-in'         => __( 'شما از قبل وارد حساب کاربری خود شده‌اید.', 'music-wave-core' ),
			'register-invalid-username' => __( 'نام کاربری فقط می‌تواند شامل حروف لاتین، عدد، نقطه، خط تیره و زیرخط باشد.', 'music-wave-core' ),
			'register-invalid-email'    => __( 'نشانی ایمیل معتبر نیست.', 'music-wave-core' ),
			'register-terms'            => __( 'برای ساخت حساب باید قوانین و سیاست حریم خصوصی را بپذیرید.', 'music-wave-core' ),
			'register-conflict'         => __( 'این نام کاربری یا ایمیل قبلاً ثبت شده است. وارد شوید یا گذرواژه را بازیابی کنید.', 'music-wave-core' ),
			'register-failed'           => __( 'ساخت حساب انجام نشد. دوباره تلاش کنید.', 'music-wave-core' ),
			'register-weak-password'    => __( 'گذرواژه بیش از حد ساده است؛ ترکیبی از حروف، عدد و نمادها انتخاب کنید.', 'music-wave-core' ),
			'password-too-long'         => __( 'گذرواژه بیش از حد بلند است.', 'music-wave-core' ),
		);

		return $messages[ $code ] ?? __( 'درخواست شما انجام نشد. دوباره تلاش کنید.', 'music-wave-core' );
	}

	/**
	 * Hash used inside transient keys; raw IPs and usernames are never stored.
	 */
	public static function hash( string $value ): string {
		$salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'nonce' ) : 'musicwave-auth';

		return substr( hash_hmac( 'sha256', strtolower( trim( $value ) ), $salt ), 0, 24 );
	}

	/**
	 * Current client actor key (validated IP, salted hash).
	 */
	public static function client_key(): string {
		return ClientIp::hashed_actor();
	}

	/**
	 * Normalized identifier key for a username or e-mail.
	 */
	public static function identifier_key( string $identifier ): string {
		return self::hash( '' !== trim( $identifier ) ? trim( $identifier ) : 'unknown-identifier' );
	}

	/**
	 * Whether the current visitor must wait before trying again.
	 *
	 * @param string $scope      `login` or `register`.
	 * @param string $identifier Username / e-mail (optional).
	 */
	public static function is_locked( string $scope, string $identifier = '' ): bool {
		if ( false !== get_transient( self::lock_key( $scope, 'ip' ) ) ) {
			return true;
		}

		return '' !== $identifier && false !== get_transient( self::lock_key( $scope, 'id', $identifier ) );
	}

	/**
	 * Count one failed attempt and arm the lockout once the window is exhausted.
	 *
	 * @param string $scope      `login` or `register`.
	 * @param string $identifier Username / e-mail (optional).
	 */
	public static function register_failure( string $scope, string $identifier = '' ): void {
		$ip_count = TransientCounter::increment( self::attempt_key( $scope, 'ip' ), self::WINDOW );
		if ( $ip_count >= self::MAX_ATTEMPTS_IP ) {
			set_transient( self::lock_key( $scope, 'ip' ), 1, self::LOCKOUT );
		}

		if ( '' === $identifier ) {
			return;
		}

		$id_count = TransientCounter::increment( self::attempt_key( $scope, 'id', $identifier ), self::WINDOW );
		if ( $id_count >= self::MAX_ATTEMPTS_ID ) {
			set_transient( self::lock_key( $scope, 'id', $identifier ), 1, self::LOCKOUT );
		}
	}

	/**
	 * Clear the counters after a successful authentication or registration.
	 *
	 * @param string $scope      `login` or `register`.
	 * @param string $identifier Username / e-mail (optional).
	 */
	public static function clear( string $scope, string $identifier = '' ): void {
		delete_transient( self::attempt_key( $scope, 'ip' ) );
		delete_transient( self::lock_key( $scope, 'ip' ) );
		if ( '' === $identifier ) {
			return;
		}
		delete_transient( self::attempt_key( $scope, 'id', $identifier ) );
		delete_transient( self::lock_key( $scope, 'id', $identifier ) );
	}

	/**
	 * Build a namespaced transient key.
	 *
	 * @param string $scope      `login` or `register`.
	 * @param string $axis       `ip` or `id`.
	 * @param string $identifier Identifier for the `id` axis.
	 */
	private static function attempt_key( string $scope, string $axis, string $identifier = '' ): string {
		return 'mw_auth_' . $scope . '_' . $axis . '_' . ( 'id' === $axis ? self::identifier_key( $identifier ) : self::client_key() );
	}

	/**
	 * Build a namespaced lockout key.
	 *
	 * @param string $scope      `login` or `register`.
	 * @param string $axis       `ip` or `id`.
	 * @param string $identifier Identifier for the `id` axis.
	 */
	private static function lock_key( string $scope, string $axis, string $identifier = '' ): string {
		return 'mw_auth_lock_' . $scope . '_' . $axis . '_' . ( 'id' === $axis ? self::identifier_key( $identifier ) : self::client_key() );
	}
}
