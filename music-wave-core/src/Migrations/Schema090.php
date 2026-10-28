<?php
/**
 * Dedicated replay-protection table for one-time download tokens.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Migrations;

use ManaCore\MusicWave\Core\Contracts\Migration;
use ManaCore\MusicWave\Core\Downloads\DatabaseReplayStore;

final class Schema090 implements Migration {
	public function version(): string {
		return '0.9.0';
	}

	/**
	 * Create the indexed replay table used by the download token store.
	 *
	 * Existing option-based replay rows stay valid until they expire; the
	 * scheduled cleanup event removes them once elapsed
	 * (PROJECT_PLAN.md Stage 2 deliverable 3).
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

		$table   = $wpdb->prefix . DatabaseReplayStore::TABLE;
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';

		dbDelta(
			"CREATE TABLE {$table} (
				token_hash char(64) NOT NULL,
				expires_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (token_hash),
				KEY expires_at (expires_at)
			) {$charset};"
		);

		update_option( 'music_wave_core_version', MUSIC_WAVE_CORE_VERSION, false );
	}
}
