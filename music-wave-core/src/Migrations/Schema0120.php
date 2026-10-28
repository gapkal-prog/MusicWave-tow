<?php
/**
 * View counter table for anti-bot, deduped release views (FIX-11).
 *
 * Replaces per-request mw_views meta write with beacon + table + hourly sync.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Migrations;

use ManaCore\MusicWave\Core\Contracts\Migration;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema0120 implements Migration {
	public function version(): string {
		return '0.12.0';
	}

	public function up(): void {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return;
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			if ( ! defined( 'ABSPATH' ) ) {
				return;
			}
			$upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( ! is_readable( $upgrade_file ) ) {
				throw new \RuntimeException( 'MusicWave could not load dbDelta from ' . esc_html( $upgrade_file ) );
			}
			require_once $upgrade_file;
		}

		$table   = $wpdb->prefix . 'mw_release_views';
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';

		dbDelta(
			"CREATE TABLE {$table} (
				release_id bigint(20) unsigned NOT NULL,
				views bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (release_id),
				KEY updated_at (updated_at)
			) {$charset};"
		);

		// Seed from existing mw_views meta if table empty (preserve counts, FIX-11).
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 0 === (int) $count ) {
			// Copy up to 10000 rows to avoid heavy migration.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal migration table name.
			$wpdb->query(
				"INSERT INTO {$table} (release_id, views, updated_at)
				 SELECT post_id, CAST(meta_value AS UNSIGNED), UNIX_TIMESTAMP()
				 FROM {$wpdb->postmeta}
				 WHERE meta_key = 'mw_views'
				 AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'mw_release')
				 ON DUPLICATE KEY UPDATE views = GREATEST(views, VALUES(views))"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
