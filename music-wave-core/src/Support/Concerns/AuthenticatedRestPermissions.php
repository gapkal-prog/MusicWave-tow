<?php
/**
 * Shared authentication and rate-limit permission checks for listener REST routes.
 *
 * Eliminates duplicate authenticated() / may_write() methods across
 * LibraryRoutes, ListeningRoutes, and PlaylistRoutes (SOLID DRY + ISP).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Support\Concerns;

use ManaCore\MusicWave\Core\Support\RouteRateLimiter;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait AuthenticatedRestPermissions {
	/** @var RouteRateLimiter|null Atomic limiter for write mutations. */
	protected $rate_limiter = null;

	/**
	 * Localized prompt returned when an unauthenticated visitor calls the route.
	 */
	abstract protected function unauthenticated_message(): string;

	/**
	 * Default rate-limit bucket key for write operations on this route group.
	 */
	abstract protected function default_rate_bucket(): string;

	/**
	 * Return an actionable authentication error instead of REST's generic 401.
	 *
	 * @return true|WP_Error
	 */
	public function authenticated() {
		if ( get_current_user_id() > 0 ) {
			return true;
		}

		return new WP_Error(
			'mw_authentication_required',
			$this->unauthenticated_message(),
			array( 'status' => 401 )
		);
	}

	/**
	 * Bound authenticated write mutations per listener per window.
	 *
	 * Note: WP REST passes WP_REST_Request when registered directly as a
	 * permission_callback, while custom wrappers may pass a bucket slug string.
	 *
	 * @param mixed $bucket Optional rate-limit bucket slug override.
	 * @return true|WP_Error
	 */
	public function may_write( $bucket = '' ) {
		$authenticated = $this->authenticated();
		if ( true !== $authenticated ) {
			return $authenticated;
		}

		$bucket_key = is_string( $bucket ) && '' !== $bucket ? $bucket : $this->default_rate_bucket();
		$limiter    = null !== $this->rate_limiter ? $this->rate_limiter : new RouteRateLimiter();
		if ( ! $limiter->allow( $bucket_key, 'u' . (string) get_current_user_id() ) ) {
			return new WP_Error(
				'mw_rate_limited',
				__( 'تعداد درخواست‌های شما بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.', 'music-wave-core' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}
}
