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
	public const TABLE      = 'mw_download_replays';
	public const BATCH_SIZE = 500;

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
	 * Delete expired replay rows, legacy replay options, and stale daily quota
	 * buckets in bounded primary-key batches to avoid long table locks on MySQL
	 * while remaining 100% compatible with SQLite test environments.
	 *
	 * @return void
	 */
	public function cleanup(): void {
		/** @var \wpdb|null $wpdb */
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		$now = time();
		if ( $this->table_available() && method_exists( $wpdb, 'get_col' ) && method_exists( $wpdb, 'prepare' ) ) {
			$table  = $wpdb->prefix . self::TABLE;
			$hashes = $wpdb->get_col( $wpdb->prepare( "SELECT token_hash FROM {$table} WHERE expires_at < %d LIMIT %d", $now, self::BATCH_SIZE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( is_array( $hashes ) && ! empty( $hashes ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE token_hash IN ({$placeholders})", ...array_map( 'strval', $hashes ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			}
		}

		if ( property_exists( $wpdb, 'options' ) && method_exists( $wpdb, 'get_col' ) && method_exists( $wpdb, 'prepare' ) ) {
			$legacy_opts = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS INTEGER) < %d LIMIT %d", 'mw_download_replay_%', $now, self::BATCH_SIZE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( is_array( $legacy_opts ) ? $legacy_opts : array() as $opt_name ) {
				$name = (string) $opt_name;
				if ( 0 === strpos( $name, 'mw_download_replay_' ) ) {
					delete_option( $name );
				}
			}

			$today_tag    = '_' . gmdate( 'Ymd' );
			$stale_quotas = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s LIMIT %d", 'mw_dl_quota_%', '%' . $today_tag, self::BATCH_SIZE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( is_array( $stale_quotas ) ? $stale_quotas : array() as $quota_opt ) {
				$qname = (string) $quota_opt;
				if ( 0 === strpos( $qname, 'mw_dl_quota_' ) && substr( $qname, -strlen( $today_tag ) ) !== $today_tag ) {
					delete_option( $qname );
				}
			}
		}
	}

	private function table_available(): bool {
		return \ManaCore\MusicWave\Core\Support\DatabaseTable::exists( self::TABLE, $this->table_available );
	}
}
