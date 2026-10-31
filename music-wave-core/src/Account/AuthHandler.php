<?php
/**
 * Authentication request handler and brute-force hardening.
 *
 * Owns every credentialed entry point so the rules live in exactly one place:
 * the two `admin-post.php` endpoints powering the bundled sign-in page, plus
 * verified filters that harden the native `wp-login.php` flow without
 * replacing it (defense in depth, not a fork).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Account;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AuthHandler {
	/** Routed admin-post action for sign-in. */
	private const LOGIN_ACTION = 'mw_auth_login';

	/** Routed admin-post action for sign-up. */
	private const REGISTER_ACTION = 'mw_auth_register';

	/** Minimum accepted password length (bytes). */
	private const MIN_PASSWORD = 8;

	/** Maximum accepted password length (bytes). */
	private const MAX_PASSWORD = 4096;

	/** Shortest accepted account name. */
	private const MIN_USERNAME = 3;

	/**
	 * Register endpoints and hardening filters.
	 */
	public function register(): void {
		add_action( 'admin_post_nopriv_' . self::LOGIN_ACTION, array( $this, 'handle_login' ) );
		add_action( 'admin_post_' . self::LOGIN_ACTION, array( $this, 'handle_login' ) );
		add_action( 'admin_post_nopriv_' . self::REGISTER_ACTION, array( $this, 'handle_register' ) );
		add_action( 'admin_post_' . self::REGISTER_ACTION, array( $this, 'handle_register' ) );

		add_action( 'wp_login_failed', array( $this, 'record_failure' ) );
		add_action( 'wp_login', array( $this, 'record_success' ), 10, 2 );
		add_filter( 'authenticate', array( $this, 'guard_locked_authentication' ), 30, 3 );
		add_filter( 'login_errors', array( $this, 'generic_login_error' ) );
		add_filter( 'registration_errors', array( $this, 'guard_native_registration' ), 10, 3 );
		add_action( 'register_post', array( $this, 'record_native_registration' ), 10, 3 );
	}

	/*
	 * ---------------------------------------------------------------------
	 * MusicWave form endpoints
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Handle a sign-in POST.
	 */
	public function handle_login(): void {
		$this->assert_submission( AuthPolicy::LOGIN_NONCE, AuthForms::PANEL_LOGIN );

		if ( is_user_logged_in() ) {
			$this->redirect( AuthPolicy::NOTICE_ARG, 'already-signed-in', AuthForms::PANEL_LOGIN );
		}

		$identifier = AuthPolicy::request_value( 'mw_login', 'post' );
		$password   = $this->raw_password( 'mw_password' );

		if ( '' === $identifier || '' === $password ) {
			$this->redirect( AuthPolicy::ERROR_ARG, 'empty-credentials', AuthForms::PANEL_LOGIN );
		}

		if ( AuthPolicy::is_locked( 'login', $identifier ) ) {
			$this->redirect( AuthPolicy::ERROR_ARG, 'locked', AuthForms::PANEL_LOGIN );
		}

		$user = wp_signon(
			array(
				'user_login'    => $identifier,
				'user_password' => $password,
				'remember'      => $this->posted_flag( 'mw_remember' ),
			),
			is_ssl()
		);

		if ( $user instanceof WP_Error ) {
			$code = AuthPolicy::is_locked( 'login', $identifier ) ? 'locked' : 'invalid-credentials';
			$this->redirect( AuthPolicy::ERROR_ARG, $code, AuthForms::PANEL_LOGIN );
		}

		AuthPolicy::clear( 'login', $identifier );

		wp_safe_redirect( AuthPolicy::safe_redirect( $this->posted_redirect(), AuthPolicy::account_url() ) );
		exit;
	}

	/**
	 * Handle a sign-up POST.
	 */
	public function handle_register(): void {
		$this->assert_submission( AuthPolicy::REGISTER_NONCE, AuthForms::PANEL_REGISTER );

		if ( ! AuthForms::registration_open() ) {
			$this->redirect( AuthPolicy::ERROR_ARG, 'registration-closed', AuthForms::PANEL_LOGIN );
		}

		if ( is_user_logged_in() ) {
			$this->redirect( AuthPolicy::NOTICE_ARG, 'already-signed-in', AuthForms::PANEL_LOGIN );
		}

		$login    = sanitize_user( AuthPolicy::request_value( 'mw_register_login', 'post' ), true );
		$email    = sanitize_email( AuthPolicy::request_value( 'mw_register_email', 'post' ) );
		$password = $this->raw_password( 'mw_register_password' );
		$confirm  = $this->raw_password( 'mw_register_confirm' );

		$error = $this->validate_registration( $login, $email, $password, $confirm );
		if ( '' !== $error ) {
			$this->redirect( AuthPolicy::ERROR_ARG, $error, AuthForms::PANEL_REGISTER );
		}

		$bucket = $login . '|' . $email;
		if ( AuthPolicy::is_locked( 'register', $bucket ) ) {
			$this->redirect( AuthPolicy::ERROR_ARG, 'locked', AuthForms::PANEL_REGISTER );
		}

		// Increment-first: a burst of parallel requests can never all pass.
		AuthPolicy::register_failure( 'register', $bucket );

		$user_id = wp_create_user( $login, $password, $email );
		if ( $user_id instanceof WP_Error ) {
			$conflict = in_array( $user_id->get_error_code(), array( 'existing_user_login', 'existing_user_email' ), true );
			$this->redirect( AuthPolicy::ERROR_ARG, $conflict ? 'register-conflict' : 'register-failed', AuthForms::PANEL_REGISTER );
		}

		/**
		 * Fires after a MusicWave sign-up created a user.
		 *
		 * @param int $user_id Newly created user ID.
		 */
		do_action( 'music_wave_auth_registered', (int) $user_id );

		// Notify the site owner only: the member chose their own password, so
		// no credential is ever sent by email.
		if ( function_exists( 'wp_new_user_notification' ) ) {
			wp_new_user_notification( (int) $user_id, null, 'admin' );
		}

		$this->redirect( AuthPolicy::NOTICE_ARG, 'registered', AuthForms::PANEL_LOGIN );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Native wp-login.php hardening
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Count one failed authentication attempt.
	 *
	 * @param mixed $username Attempted account name.
	 */
	public function record_failure( $username ): void {
		AuthPolicy::register_failure( 'login', is_string( $username ) ? $username : '' );
	}

	/**
	 * Clear counters after a successful authentication.
	 *
	 * @param mixed $user_login Authenticated account name.
	 * @param mixed $user       Authenticated user object.
	 */
	public function record_success( $user_login, $user = null ): void {
		unset( $user );

		AuthPolicy::clear( 'login', is_string( $user_login ) ? $user_login : '' );
	}

	/**
	 * Refuse authentication while an address or account is throttled.
	 *
	 * Runs after the core authenticators (priority 20) so a valid password
	 * cannot bypass an active lockout.
	 *
	 * @param mixed $user     Current authentication result.
	 * @param mixed $username Attempted account name.
	 * @param mixed $password Attempted password.
	 * @return mixed
	 */
	public function guard_locked_authentication( $user, $username = '', $password = '' ) {
		unset( $password );

		if ( ! AuthPolicy::is_locked( 'login', is_string( $username ) ? $username : '' ) ) {
			return $user;
		}

		return new WP_Error( 'mw_auth_locked', AuthPolicy::message( 'locked' ) );
	}

	/**
	 * Replace the native error copy with one generic sentence.
	 *
	 * Prevents user enumeration through distinct "unknown username" and
	 * "incorrect password" messages.
	 *
	 * @param mixed $error Current error HTML.
	 * @return mixed
	 */
	public function generic_login_error( $error ) {
		if ( ! is_string( $error ) || '' === $error ) {
			return $error;
		}

		// A throttled visitor gets the actionable lockout copy; everyone else
		// gets one neutral sentence, so the form never reveals whether an
		// account exists.
		$code    = AuthPolicy::is_locked( 'login', AuthPolicy::request_value( 'log', 'post' ) ) ? 'locked' : 'invalid-credentials';
		$message = AuthPolicy::message( $code );

		return '<strong>' . esc_html__( 'خطا:', 'music-wave-core' ) . '</strong> ' . esc_html( $message );
	}

	/**
	 * Apply the same lockout to the native registration form.
	 *
	 * @param mixed $errors               Collected registration errors.
	 * @param mixed $sanitized_user_login Proposed account name.
	 * @param mixed $user_email           Proposed e-mail address.
	 * @return mixed
	 */
	public function guard_native_registration( $errors, $sanitized_user_login = '', $user_email = '' ) {
		if ( ! $errors instanceof WP_Error ) {
			return $errors;
		}

		$bucket = $this->registration_bucket( $sanitized_user_login, $user_email );
		if ( AuthPolicy::is_locked( 'register', $bucket ) ) {
			$errors->add( 'mw_auth_locked', AuthPolicy::message( 'locked' ) );
		}

		return $errors;
	}

	/**
	 * Count every native registration attempt that reached the database.
	 *
	 * @param mixed $sanitized_user_login Proposed account name.
	 * @param mixed $user_email           Proposed e-mail address.
	 * @param mixed $errors               Collected registration errors.
	 */
	public function record_native_registration( $sanitized_user_login, $user_email, $errors = null ): void {
		if ( $errors instanceof WP_Error && $errors->has_errors() ) {
			return;
		}

		AuthPolicy::register_failure( 'register', $this->registration_bucket( $sanitized_user_login, $user_email ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Internals
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Validate method, CSRF nonce, form marker, and honeypot.
	 *
	 * @param string $nonce_action Expected nonce action.
	 * @param string $panel        Expected panel marker.
	 */
	private function assert_submission( string $nonce_action, string $panel ): void {
		if ( 'POST' !== $this->request_method() ) {
			$this->redirect( AuthPolicy::ERROR_ARG, 'invalid-request', $panel );
		}

		if ( '' !== AuthPolicy::request_value( 'mw_auth_website', 'post' ) ) {
			$this->redirect( AuthPolicy::ERROR_ARG, 'invalid-request', $panel );
		}

		if ( AuthPolicy::request_value( AuthPolicy::MARKER_FIELD, 'post' ) !== $panel ) {
			$this->redirect( AuthPolicy::ERROR_ARG, 'invalid-request', $panel );
		}

		$nonce = AuthPolicy::request_value( AuthPolicy::NONCE_FIELD, 'post' );
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			$this->redirect( AuthPolicy::ERROR_ARG, 'invalid-nonce', $panel );
		}
	}

	/**
	 * Validate the sign-up payload.
	 *
	 * @param string $login    Sanitized account name.
	 * @param string $email    Sanitized e-mail address.
	 * @param string $password Raw password.
	 * @param string $confirm  Raw confirmation.
	 * @return string Empty on success, otherwise an error code.
	 */
	private function validate_registration( string $login, string $email, string $password, string $confirm ): string {
		if ( '' === $login || '' === $email || '' === $password ) {
			return 'empty-credentials';
		}

		if ( strlen( $login ) < self::MIN_USERNAME || ! validate_username( $login ) ) {
			return 'register-invalid-username';
		}

		if ( ! is_email( $email ) ) {
			return 'register-invalid-email';
		}

		if ( strlen( $password ) < self::MIN_PASSWORD ) {
			return 'password-too-short';
		}

		if ( strlen( $password ) > self::MAX_PASSWORD ) {
			return 'password-too-long';
		}

		if ( $password !== $confirm ) {
			return 'password-mismatch';
		}

		$local_part = (string) strstr( $email, '@', true );
		if ( false !== stripos( $password, $login ) || ( '' !== $local_part && false !== stripos( $password, $local_part ) ) ) {
			return 'register-weak-password';
		}

		if ( ! $this->posted_flag( 'mw_terms' ) ) {
			return 'register-terms';
		}

		if ( username_exists( $login ) || email_exists( $email ) ) {
			return 'register-conflict';
		}

		return '';
	}

	/**
	 * Normalized throttle bucket for a registration attempt.
	 *
	 * @param mixed $login Proposed account name.
	 * @param mixed $email Proposed e-mail address.
	 */
	private function registration_bucket( $login, $email ): string {
		return ( is_string( $login ) ? $login : '' ) . '|' . ( is_string( $email ) ? $email : '' );
	}

	/**
	 * Read a raw password field without sanitizing it.
	 *
	 * Sanitizers strip characters, which would silently change the stored
	 * credential; only unslashing is applied, exactly like core does.
	 *
	 * @param string $key POST key.
	 */
	private function raw_password( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by assert_submission(); passwords must never be sanitized.
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/**
	 * Whether a checkbox-like POST flag is present.
	 *
	 * @param string $key POST key.
	 */
	private function posted_flag( string $key ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by assert_submission() before any read.
		return isset( $_POST[ $key ] ) && '' !== $_POST[ $key ];
	}

	/**
	 * Requested post-login destination from POST or query.
	 */
	private function posted_redirect(): string {
		$value = AuthPolicy::request_value( 'redirect_to', 'post' );

		return '' !== $value ? $value : AuthPolicy::request_value( 'redirect_to' );
	}

	/**
	 * Uppercased request method.
	 */
	private function request_method(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against a literal, never output.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_scalar( $_SERVER['REQUEST_METHOD'] ) ? (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) : '';

		return strtoupper( $method );
	}

	/**
	 * Redirect back to the auth page with a machine code and stop.
	 *
	 * @param string $argument AuthPolicy::ERROR_ARG or AuthPolicy::NOTICE_ARG.
	 * @param string $code     Machine code resolved by AuthPolicy::message().
	 * @param string $panel    Panel to reopen.
	 */
	private function redirect( string $argument, string $code, string $panel ): void {
		$url = add_query_arg(
			array(
				AuthPolicy::PANEL_ARG => $panel,
				$argument             => $code,
			),
			AuthPolicy::login_page_url()
		);

		$target = AuthPolicy::safe_redirect( $this->posted_redirect(), '' );
		if ( '' !== $target ) {
			$url = add_query_arg( 'redirect_to', $target, $url );
		}

		wp_safe_redirect( $url );
		exit;
	}
}
