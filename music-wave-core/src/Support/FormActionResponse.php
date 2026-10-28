<?php
/**
 * Shared HTTP response & nonce helpers for front-end `admin-post.php` handlers.
 *
 * Eliminates duplicated AJAX detection, non-die nonce verification, JSON
 * responses, safe redirect targets, and testable termination across queue,
 * playlist, and request-chat form handlers (DRY + SOLID SRP).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait FormActionResponse {
	/**
	 * Whether the current request was initiated via fetch / XHR / wp-ajax.
	 */
	protected function is_ajax_request(): bool {
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return true;
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return true;
		}
		$raw_xrw        = isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ? (string) $_SERVER['HTTP_X_REQUESTED_WITH'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$requested_with = function_exists( 'wp_unslash' ) ? (string) wp_unslash( $raw_xrw ) : $raw_xrw;
		if ( 'xmlhttprequest' === strtolower( $requested_with ) ) {
			return true;
		}
		$raw_accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? (string) $_SERVER['HTTP_ACCEPT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$accept     = strtolower( function_exists( 'wp_unslash' ) ? (string) wp_unslash( $raw_accept ) : $raw_accept );
		return false !== strpos( $accept, 'application/json' );
	}

	/**
	 * Verify a form nonce without calling wp_nonce_ays() / wp_die() HTML 403.
	 *
	 * On classic form POST without AJAX we delegate to check_admin_referer()
	 * when valid, while returning false cleanly on stale/invalid nonces so
	 * callers can respond with structured JSON or a safe PRG error notice.
	 */
	protected function verify_form_nonce( string $action, string $field = '_wpnonce' ): bool {
		if ( $this->is_ajax_request() ) {
			if ( ! isset( $_REQUEST[ $field ] ) || ! is_scalar( $_REQUEST[ $field ] ) ) {
				return false;
			}
			$nonce = sanitize_text_field( wp_unslash( (string) $_REQUEST[ $field ] ) );
			return function_exists( 'wp_verify_nonce' ) ? false !== wp_verify_nonce( $nonce, $action ) : false;
		}

		if ( isset( $_REQUEST[ $field ] ) && is_scalar( $_REQUEST[ $field ] ) && function_exists( 'wp_verify_nonce' ) ) {
			$nonce = sanitize_text_field( wp_unslash( (string) $_REQUEST[ $field ] ) );
			if ( false === wp_verify_nonce( $nonce, $action ) ) {
				return false;
			}
		}

		return (bool) check_admin_referer( $action, $field );
	}

	/**
	 * Send a UTF-8 JSON payload and terminate cleanly (test-safe).
	 *
	 * @param array<string, mixed> $payload Response payload.
	 */
	protected function send_json_payload( array $payload, int $status_code = 200 ): void {
		if ( function_exists( 'wp_send_json' ) && ! defined( 'MUSIC_WAVE_TESTING' ) ) {
			wp_send_json( $payload, $status_code );
		}
		if ( ! headers_sent() ) {
			if ( function_exists( 'status_header' ) ) {
				status_header( $status_code );
			}
			header( 'Content-Type: application/json; charset=utf-8' );
		}
		echo wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
		$this->finish_request();
	}

	/**
	 * Safe same-host return URL after a form mutation.
	 */
	protected function redirect_target(): string {
		$referer = isset( $_POST['mw_redirect'] ) && is_scalar( $_POST['mw_redirect'] ) ? esc_url_raw( wp_unslash( (string) $_POST['mw_redirect'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by caller and restricted by wp_safe_redirect().
		if ( '' === $referer ) {
			$referer = (string) wp_get_referer();
		}

		return '' !== $referer ? $referer : home_url( '/' );
	}

	/**
	 * Redirect with query arguments (303 See Other for PRG).
	 *
	 * @param array<string, int|string> $query_args Query parameters to append.
	 */
	protected function redirect_with_args( string $target, array $query_args, int $status = 303 ): void {
		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( add_query_arg( $query_args, $target ), $status );
		}
		$this->finish_request();
	}

	/**
	 * Allow subscriber / customer front-end form POSTs to reach admin-post.php
	 * without WooCommerce redirecting non-admin users away on admin_init.
	 */
	protected function allow_frontend_admin_post( string $action_name ): void {
		add_filter(
			'woocommerce_prevent_admin_access',
			static function ( $prevent ) use ( $action_name ): bool {
				global $pagenow;
				$current_action = isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( 'admin-post.php' === (string) $pagenow && $action_name === $current_action ) {
					return false;
				}
				return (bool) $prevent;
			}
		);
	}

	/**
	 * Terminate normal HTTP execution while keeping test suites alive.
	 */
	protected function finish_request(): void {
		if ( ! defined( 'MUSIC_WAVE_TESTING' ) ) {
			exit;
		}
	}
}
