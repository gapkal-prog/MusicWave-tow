<?php
/**
 * Hardened HTTP redirect emitter shared by Core direct-link delivery and VIP
 * remote HMAC redirects.
 *
 * Guarantees HTTPS-only targets, blocks CRLF/NUL header injection, emits strict
 * no-cache / no-referrer / nosniff headers, and supports non-terminating capture
 * during automated tests (`MUSIC_WAVE_TESTING`).
 *
 * @package ManaCore\MusicWave\Core\Downloads
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RedirectResponse {
	/** @var array{url: string, status: int, redirect_by: string, headers: array<string, string>}|null */
	private static $last_redirect = null;

	/**
	 * Clear captured redirect metadata between test assertions.
	 */
	public static function reset_last_redirect(): void {
		self::$last_redirect = null;
	}

	/**
	 * Inspect the most recently emitted redirect (primarily for E2E & security tests).
	 *
	 * @return array{url: string, status: int, redirect_by: string, headers: array<string, string>}|null
	 */
	public static function last_redirect(): ?array {
		return self::$last_redirect;
	}

	/**
	 * Emit a hardened temporary redirect to a validated HTTPS URL.
	 */
	public static function send( string $target_url, int $status = 302, string $redirect_by = 'MusicWave' ): bool {
		$trimmed = trim( $target_url );
		if ( '' === $trimmed || 1 === preg_match( '/[\x00-\x1F\x7F]/', $trimmed ) ) {
			return false;
		}

		$scheme = function_exists( 'wp_parse_url' ) ? wp_parse_url( $trimmed, PHP_URL_SCHEME ) : parse_url( $trimmed, PHP_URL_SCHEME ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( 'https' !== strtolower( (string) $scheme ) ) {
			return false;
		}

		$clean_url = esc_url_raw( $trimmed, array( 'https' ) );
		if ( '' === $clean_url ) {
			return false;
		}

		$code    = in_array( $status, array( 302, 303, 307 ), true ) ? $status : 302;
		$headers = array(
			'Cache-Control'          => 'private, no-store, max-age=0',
			'Referrer-Policy'        => 'no-referrer',
			'X-Content-Type-Options' => 'nosniff',
			'X-Robots-Tag'           => 'noindex, nofollow',
		);

		self::$last_redirect = array(
			'url'         => $clean_url,
			'status'      => $code,
			'redirect_by' => $redirect_by,
			'headers'     => $headers,
		);

		$is_test_mode  = defined( 'MUSIC_WAVE_TESTING' ) && MUSIC_WAVE_TESTING;
		$should_finish = (bool) apply_filters( 'music_wave_redirect_response_terminate', ! $is_test_mode, $clean_url, $code, $headers );
		if ( ! $should_finish ) {
			return true;
		}

		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		foreach ( $headers as $header_name => $header_value ) {
			header( $header_name . ': ' . $header_value );
		}

		wp_redirect( $clean_url, $code, $redirect_by ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External CDN/HTTPS hosts are strictly validated by DirectLinkUrlValidator or RemoteRedirectProvider::allowed_url() prior to RedirectResponse::send().
		exit;
	}
}
