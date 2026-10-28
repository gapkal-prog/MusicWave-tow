<?php
/**
 * Shared atomic fixed-window rate limiter for route-level buckets.
 *
 * One engine for every per-actor request budget (downloads, discovery,
 * playlists, library, listening, forms). The counter increments first —
 * persistent object cache, then a single UPDATE on the transient row, then a
 * read-then-write fallback — so concurrent requests receive distinct counts
 * and can never all pass a check against the same value. The write routes
 * named in review finding P6 adopt this engine through their own limiter
 * classes, and the non-atomic discovery counter (S5) now delegates to it.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RouteRateLimiter {
	public const DEFAULT_LIMIT  = 60;
	public const DEFAULT_WINDOW = 60;

	/** @var int|null Explicit limit; otherwise resolved by filter. */
	private $limit;

	/** @var int|null Explicit window; otherwise resolved by filter. */
	private $window;

	public function __construct( ?int $limit = null, ?int $window = null ) {
		$this->limit  = $limit;
		$this->window = $window;
	}

	/**
	 * Whether the current actor may run one more request in this bucket.
	 *
	 * @param string   $bucket  Route bucket name (download|suggest|facets|requests|...).
	 * @param string   $actor   Stable actor key, e.g. "u7" or a salted digest.
	 * @param int|null $limit   Optional per-call limit override; otherwise the constructor value or the filter.
	 * @param int|null $window  Optional per-call window override; otherwise the constructor value or the filter.
	 * @return bool
	 */
	public function allow( string $bucket, string $actor, ?int $limit = null, ?int $window = null ): bool {
		/**
		 * Filter the maximum requests per actor per window for this bucket.
		 *
		 * @param int    $limit  Maximum requests per window.
		 * @param string $bucket Route bucket name.
		 * @param string $actor  Stable actor key.
		 */
		$limit = null !== $limit ? $limit : ( null !== $this->limit ? $this->limit : (int) apply_filters( 'music_wave_route_rate_limit', self::DEFAULT_LIMIT, $bucket, $actor ) );
		if ( $limit < 1 ) {
			return true; // A non-positive limit disables rate limiting explicitly.
		}

		/**
		 * Filter the rate-limit window length in seconds for this bucket.
		 *
		 * @param int    $window Window length in seconds.
		 * @param string $bucket Route bucket name.
		 * @param string $actor  Stable actor key.
		 */
		$window = max( 10, null !== $window ? $window : ( null !== $this->window ? $this->window : (int) apply_filters( 'music_wave_route_rate_window', self::DEFAULT_WINDOW, $bucket, $actor ) ) );

		$key   = 'mw_route_rate_' . sanitize_key( $bucket ) . '_' . $actor . '_' . (int) floor( time() / $window );
		$count = TransientCounter::increment( $key, $window * 2 );

		return $count <= $limit;
	}
}
