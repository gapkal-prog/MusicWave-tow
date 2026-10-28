<?php
/**
 * Indexed library table for personal library (FIX-26).
 *
 * Replaces LIKE scans over serialized user_meta with indexed lookups.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Migrations;

use ManaCore\MusicWave\Core\Contracts\Migration;
use ManaCore\MusicWave\Core\Library\LibraryRepository;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema0130 implements Migration {
	public function version(): string {
		return '0.13.0';
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

		$table   = $wpdb->prefix . 'mw_library_index';
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';

		dbDelta(
			"CREATE TABLE {$table} (
				user_id bigint(20) unsigned NOT NULL,
				item_id bigint(20) unsigned NOT NULL,
				type varchar(20) NOT NULL DEFAULT '',
				added bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (user_id, type, item_id),
				KEY item_lookup (item_id, type),
				KEY user_added (user_id, added)
			) {$charset};"
		);

		// Seed from existing user_meta if table empty.
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 0 !== (int) $count ) {
			return;
		}

		// Bounded seed: up to 10000 user_meta rows.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT %d",
				LibraryRepository::META_KEY,
				10000
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || array() === $rows ) {
			return;
		}

		foreach ( $rows as $row ) {
			$user_id = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
			if ( $user_id < 1 ) {
				continue;
			}
			$raw = isset( $row['meta_value'] ) ? $row['meta_value'] : '';
			// meta_value may be serialized.
			$decoded = maybe_unserialize( $raw );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			foreach ( $decoded as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$type = isset( $item['type'] ) && is_scalar( $item['type'] ) ? sanitize_key( (string) $item['type'] ) : '';
				$id   = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
				if ( '' === $type || $id < 1 ) {
					continue;
				}
				$added = isset( $item['added'] ) ? absint( $item['added'] ) : time();
				// Insert ignore duplicates.
				$wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$table} (user_id, item_id, type, added) VALUES (%d, %d, %s, %d)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$user_id,
						$id,
						$type,
						$added
					)
				);
			}
		}
	}
}
