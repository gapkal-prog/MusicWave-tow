<?php
/**
 * Fixed-window rate limit for the public discovery routes.
 *
 * Autocomplete and facet requests are cheap but public, so they are bounded
 * per actor (user ID when signed in, hashed client address otherwise) before
 * any query runs (PROJECT_PLAN.md Stage 5 deliverable 6). The counting itself
 * delegates to the shared RouteRateLimiter engine, so the counters increment
 * atomically — persistent cache incr, then a single UPDATE, then a
 * read-then-write fallback — instead of under-counting under concurrency
 * like the previous get/set pair (review finding S5).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Discovery;

use ManaCore\MusicWave\Core\Support\ClientIp;
use ManaCore\MusicWave\Core\Support\RouteRateLimiter;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DiscoveryRateLimiter {
	public const DEFAULT_LIMIT  = 60;
	public const DEFAULT_WINDOW = 60;

	/** @var int|null Explicit limit; otherwise resolved by filter. */
	private $limit;

	/** @var int|null Explicit window; otherwise resolved by filter. */
	private $window;

	/** @var RouteRateLimiter Shared atomic counting engine. */
	private $engine;

	public function __construct( ?int $limit = null, ?int $window = null ) {
		$this->limit  = $limit;
		$this->window = $window;
		$this->engine = new RouteRateLimiter();
	}

	/**
	 * Whether the current actor may run one more discovery query.
	 */
	public function allow( string $bucket = 'suggest' ): bool {
		$actor = $this->actor();

		/**
		 * Filter the maximum public discovery requests per actor per window.
		 *
		 * @param int    $limit  Maximum requests per window.
		 * @param string $bucket Route bucket (suggest|facets).
		 * @param string $actor  Stable actor key.
		 */
		$limit = null !== $this->limit ? $this->limit : (int) apply_filters( 'music_wave_discovery_rate_limit', RouteRateLimiter::DEFAULT_LIMIT, $bucket, $actor );
		if ( $limit < 1 ) {
			return true; // A non-positive limit disables rate limiting explicitly.
		}

		/**
		 * Filter the discovery rate-limit window in seconds.
		 *
		 * @param int    $window Window length in seconds.
		 * @param string $bucket Route bucket (suggest|facets).
		 * @param string $actor  Stable actor key.
		 */
		$window = max( 10, null !== $this->window ? $this->window : (int) apply_filters( 'music_wave_discovery_rate_window', RouteRateLimiter::DEFAULT_WINDOW, $bucket, $actor ) );

		return $this->engine->allow( $bucket, $actor, $limit, $window );
	}

	/**
	 * Stable, non-reversible actor key for the current request.
	 * Uses ClientIp helper (filter music_wave_client_ip) for proxy support.
	 */
	private function actor(): string {
		return ClientIp::current_actor();
	}
}
