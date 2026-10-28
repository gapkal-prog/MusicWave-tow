<?php
/**
 * Indexed per-user listening activity table.
 *
 * High-cardinality user activity (progress, plays) moves to a dedicated
 * indexed table instead of unbounded meta rows
 * (PROJECT_PLAN.md Stage 5 deliverable 1).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Migrations;

use ManaCore\MusicWave\Core\Contracts\Migration;
use ManaCore\MusicWave\Core\Listening\ListeningRepository;

final class Schema0100 implements Migration {
	public function version(): string {
		return '0.10.0';
	}

	/**
	 * Create the listening-activity table.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the dbDelta loader is unreadable, so the
	 *                           schema version stays un-persisted and the
	 *                           runner retries this migration.
	 */
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
				// Throwing keeps the schema version un-persisted so the runner
				// retries this migration; a silent return would mark the table
				// created while it never was.
				throw new \RuntimeException( 'MusicWave could not load dbDelta from ' . esc_html( $upgrade_file ) );
			}
			require_once $upgrade_file;
		}

		$table   = $wpdb->prefix . ListeningRepository::TABLE;
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				release_id bigint(20) unsigned NOT NULL,
				event varchar(20) NOT NULL DEFAULT 'progress',
				position int(10) unsigned NOT NULL DEFAULT 0,
				updated_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_release_event (user_id, release_id, event),
				KEY user_updated (user_id, updated_at)
			) {$charset};"
		);

		update_option( 'music_wave_core_version', MUSIC_WAVE_CORE_VERSION, false );
	}
}
