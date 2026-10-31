<?php
/**
 * Client fingerprint hashing for playback sessions (single source of truth).
 *
 * Both session creation (`DownloadRoutes`) and session verification
 * (`SessionGate`) must derive the exact same `ip_hash`/`ua_hash`, otherwise
 * binding silently stops matching. The hashing lives here so there is one
 * implementation, and the raw IP / User-Agent are never stored.
 *
 * @package ManaCore\MusicWave\Core\Playback
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Playback;

use ManaCore\MusicWave\Core\Support\ClientIp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StreamFingerprint {
	/**
	 * SHA-256 of the client IP (resolved via `ClientIp`, honouring the
	 * `music_wave_client_ip` filter for trusted proxies).
	 */
	public static function ip_hash(): string {
		return hash( 'sha256', ClientIp::get() );
	}

	/**
	 * SHA-256 of the sanitized User-Agent (empty string when absent).
	 */
	public static function ua_hash(): string {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) && is_scalar( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) )
			: '';

		return hash( 'sha256', $ua );
	}

	private function __construct() {}
}
