<?php
/**
 * Shared database table existence helper with exact LIKE wildcard escaping
 * and per-instance memoization (SOLID SRP + DRY).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DatabaseTable {
	/**
	 * Probe whether `$wpdb->prefix . $table_suffix` exists, escaping LIKE wildcards
	 * and caching the boolean result in `$cache`.
	 *
	 * @param string    $table_suffix Unprefixed table name (e.g. `mw_download_replay`).
	 * @param bool|null $cache        Per-repository boolean cache slot.
	 * @param-out bool  $cache
	 */
	public static function exists( string $table_suffix, ?bool &$cache ): bool {
		global $wpdb;

		if ( null !== $cache ) {
			return $cache;
		}
		if ( ! $wpdb instanceof \wpdb ) {
			$cache = false;

			return false;
		}

		$table = $wpdb->prefix . $table_suffix;
		$like  = $wpdb->esc_like( $table );
		$cache = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $cache;
	}
}
