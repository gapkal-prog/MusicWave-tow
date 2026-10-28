<?php
/**
 * Fixed-window rate limit for download/stream token issuance.
 *
 * Bounds token-issue abuse per user before entitlement work runs
 * (PROJECT_PLAN.md Stage 2 deliverable 4).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

use ManaCore\MusicWave\Core\Support\ClientIp;
use ManaCore\MusicWave\Core\Support\Settings;
use ManaCore\MusicWave\Core\Support\TransientCounter;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DownloadRateLimiter {
	public const DEFAULT_LIMIT  = 30;
	public const DEFAULT_WINDOW = 60;

	/** @var int */
	private $default_limit;

	/** @var int */
	private $default_window;

	public function __construct( ?int $limit = null, ?int $window = null ) {
		$this->default_limit  = null !== $limit
			? $limit
			: ( class_exists( Settings::class ) ? max( 1, (int) Settings::get( 'download_rate_limit' ) ) : self::DEFAULT_LIMIT );
		$this->default_window = null !== $window
			? $window
			: ( class_exists( Settings::class ) ? max( 10, (int) Settings::get( 'download_rate_window' ) ) : self::DEFAULT_WINDOW );
	}

	/**
	 * Whether the user may request one more token in the current window.
	 *
	 * Uses a fixed-window transient counter: cheap, host-cache friendly, and
	 * bounded. The limit and window are filterable per site. Anonymous
	 * visitors (user 0, allowed by policy-level open gates) get their own
	 * per-IP bucket so guests cannot share or exhaust a single counter.
	 */
	public function allow( int $user_id ): bool {
		/**
		 * Filter the maximum token issues per user per window.
		 *
		 * @param int $limit   Maximum requests per window.
		 * @param int $user_id Acting user.
		 */
		$limit = (int) apply_filters( 'music_wave_download_rate_limit', $this->default_limit, $user_id );
		if ( $limit < 1 ) {
			return true; // A non-positive limit disables rate limiting explicitly.
		}

		/**
		 * Filter the rate-limit window length in seconds.
		 *
		 * @param int $window  Window length in seconds.
		 * @param int $user_id Acting user.
		 */
		$window = max( 10, (int) apply_filters( 'music_wave_download_rate_window', $this->default_window, $user_id ) );

		$key   = $this->bucket_key( $user_id ) . '_' . (int) floor( time() / $window );
		$count = TransientCounter::increment( $key, $window * 2 );

		return $count <= $limit;
	}

	/**
	 * Stable per-visitor bucket identity: user ID, or a hashed IP for guests.
	 * Uses ClientIp helper with filter music_wave_client_ip (default REMOTE_ADDR).
	 */
	private function bucket_key( int $user_id ): string {
		if ( $user_id > 0 ) {
			return 'mw_dl_rate_' . $user_id;
		}

		$ip = ClientIp::get();
		return 'mw_dl_rate_g_' . hash( 'sha256', '' !== $ip ? $ip : 'unknown' );
	}
}
