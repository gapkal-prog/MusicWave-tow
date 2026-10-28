<?php
/**
 * Atomic fixed-window counter backed by persistent object cache or transients.
 *
 * Centralizes increment-first semantics so rate limiters never duplicate
 * object-cache or direct-SQL transient increments (SOLID SRP + DRY).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TransientCounter {
	/**
	 * Increment one window counter as atomically as the environment allows.
	 *
	 * Persistent object caches get a backend increment; database-backed
	 * transients get a single UPDATE statement; everything else falls back to
	 * a read-then-write. Increment-first means concurrent requests receive
	 * distinct counts and can never all pass a check against the same value.
	 *
	 * @param string $key Transient key of the window counter.
	 * @param int    $ttl Counter lifetime in seconds.
	 * @return int Counter value after the increment.
	 */
	public static function increment( string $key, int $ttl ): int {
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_incr' ) ) {
			$cache_key = '_transient_' . $key;
			$count     = wp_cache_incr( $cache_key, 1, '' );
			if ( false !== $count && null !== $count && (int) $count > 0 ) {
				return (int) $count;
			}
			if ( function_exists( 'wp_cache_add' ) ) {
				wp_cache_add( $cache_key, 1, '', $ttl );
			}

			return 1;
		}

		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && property_exists( $wpdb, 'options' ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'query' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$updated = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s", '_transient_' . $key ) );
			if ( $updated > 0 ) {
				$count = get_transient( $key );

				return false === $count ? 1 : (int) $count;
			}
		}

		$count = get_transient( $key );
		$count = false === $count ? 0 : (int) $count;
		set_transient( $key, $count + 1, $ttl );

		return $count + 1;
	}
}
