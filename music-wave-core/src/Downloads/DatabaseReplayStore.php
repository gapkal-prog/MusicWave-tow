<?php
/**
 * Atomic, expiring, cleanable one-time token store backed by a custom table.
 *
 * The previous options-based store accumulated one row per issued token in
 * `wp_options` forever and its check-then-insert sequence was not atomic.
 * This store uses an indexed dedicated table with a single-statement
 * `INSERT IGNORE` consume and supports bulk cleanup of expired rows
 * (PROJECT_PLAN.md Stage 2 deliverable 3).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DatabaseReplayStore implements ReplayStore {
	public const TABLE = 'mw_download_replays';

	/** @var ReplayStore Fallback used until the table migration has run. */
	private $fallback;

	/** @var bool|null Memoized table availability for this request. */
	private $table_available = null;

	public function __construct( ?ReplayStore $fallback = null ) {
		$this->fallback = null !== $fallback ? $fallback : new TransientReplayStore();
	}

	/**
	 * Atomically consume a one-time token.
	 *
	 * The primary-key `INSERT IGNORE` makes concurrent replays race-safe:
	 * exactly one request can insert the hash. Expired rows are reclaimed
	 * with a guarded DELETE before the insert.
	 */
	public function consume( string $token_id, int $expires_at ): bool {
		global $wpdb;

		if ( ! $this->table_available() ) {
			return $this->fallback->consume( $token_id, $expires_at );
		}

		$table = $wpdb->prefix . self::TABLE;
		$hash  = hash( 'sha256', $token_id );

		// Reclaim an expired row for this hash so the token id can be reused
		// only after its previous window has fully elapsed.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE token_hash = %s AND expires_at < %d", $hash, time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (token_hash, expires_at) VALUES (%s, %d)", $hash, $expires_at ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return 1 === (int) $inserted;
	}

	/**
	 * Delete expired replay rows and stale legacy option rows.
	 *
	 * Runs from the scheduled cleanup event; both storage backends stay
	 * bounded even when the site later switches providers.
	 *
	 * @return void
	 */
	public function cleanup(): void {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return;
		}

		if ( $this->table_available() ) {
			$table = $wpdb->prefix . self::TABLE;
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %d", time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// Purge expired rows left behind by the legacy options-based store.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d", $wpdb->esc_like( 'mw_download_replay_' ) . '%', time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function table_available(): bool {
		return \ManaCore\MusicWave\Core\Support\DatabaseTable::exists( self::TABLE, $this->table_available );
	}
}
