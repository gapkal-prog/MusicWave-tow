<?php
/**
 * Centralized client IP helper with filter for reverse-proxy setups.
 *
 * Default is REMOTE_ADDR only (secure by default). Operators behind
 * Cloudflare, reverse proxy or load balancer can add a filter
 * `music_wave_client_ip` to trust CF-Connecting-IP or X-Forwarded-For,
 * but ONLY when origin only receives traffic from the proxy.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ClientIp {
	/**
	 * Return validated client IP string (may be empty).
	 * Always from REMOTE_ADDR unless filtered.
	 *
	 * @return string
	 */
	public static function get(): string {
		$raw = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) && is_scalar( $_SERVER['REMOTE_ADDR'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- strictly validated via FILTER_VALIDATE_IP below; guarded for standalone CLI harnesses.
			$addr = (string) $_SERVER['REMOTE_ADDR'];
			$raw  = function_exists( 'wp_unslash' ) ? (string) wp_unslash( $addr ) : $addr;
		}

		// Validate: must be valid IP (v4 or v6).
		$ip = filter_var( $raw, FILTER_VALIDATE_IP );
		$ip = false !== $ip ? $ip : '';

		/**
		 * Filter the client IP used for rate limiting and guest quota.
		 *
		 * WARNING: Only trust proxy headers when your origin is only reachable
		 * via that proxy. Otherwise spoofed headers allow bypassing rate limits.
		 *
		 * Example Cloudflare:
		 * add_filter('music_wave_client_ip', function($ip){
		 *   if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
		 *     $cf = filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP);
		 *     if ($cf) return $cf;
		 *   }
		 *   return $ip;
		 * });
		 *
		 * @param string $ip Current validated IP (from REMOTE_ADDR).
		 * @param string $raw_ip Raw REMOTE_ADDR before validation.
		 */
		$filtered = apply_filters( 'music_wave_client_ip', $ip, $raw );
		if ( ! is_string( $filtered ) ) {
			return $ip;
		}
		$validated = filter_var( $filtered, FILTER_VALIDATE_IP );
		return false !== $validated ? $validated : $ip;
	}

	/**
	 * Return hashed actor key for guest buckets (salted, non-reversible).
	 *
	 * @return string
	 */
	public static function hashed_actor(): string {
		$ip   = self::get();
		$salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'nonce' ) : 'music-wave';
		return 'a' . substr( hash( 'sha256', $salt . '|' . $ip ), 0, 16 );
	}

	/**
	 * Resolve stable actor bucket key for logged-in user (`u<id>`) or guest (`a<hash>`).
	 */
	public static function current_actor(): string {
		$user_id = get_current_user_id();

		return $user_id > 0 ? 'u' . $user_id : self::hashed_actor();
	}
}
