<?php
/**
 * Background upload queue backed by `mw_vip_upload_queue`.
 *
 * Idempotent per asset (one row max), exponential backoff retries, claim
 * with concurrency cap, and completion/failure tracking.
 *
 * @package ManaCore\MusicWave\Vip\Delivery
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Delivery;

use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FIFO queue of assets awaiting upload to the remote download server.
 *
 * All durable state lives in a dedicated table; the instance only carries a
 * memoised table-availability flag.
 */
final class RemoteUploadQueue {
	public const STATUS_PENDING   = 'pending';
	public const STATUS_UPLOADING = 'uploading';
	public const STATUS_SYNCED    = 'synced';
	public const STATUS_FAILED    = 'failed';

	/** @var wpdb */
	private $wpdb;
	/** @var string Table name. */
	private $table;
	/** @var bool|null Memoized availability. */
	private $table_ready = null;

	public function __construct( ?wpdb $wpdb = null ) {
		$global      = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof wpdb ? $GLOBALS['wpdb'] : null;
		$this->wpdb  = $wpdb instanceof wpdb ? $wpdb : $global;
		$this->table = $this->wpdb instanceof wpdb ? $this->wpdb->prefix . 'mw_vip_upload_queue' : 'mw_vip_upload_queue';
	}


	/**
	 * Insert a pending row if none exists for the asset (idempotent).
	 *
	 * @return bool True when a row exists/pending after this call.
	 */
	public function enqueue( string $asset_key ): bool {
		$wpdb  = $this->wpdb;
		$table = $this->table;
		if ( ! $this->ensure_table() || '' === $asset_key ) {
			return false;
		}

		$now      = time();
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, status FROM {$table} WHERE asset_key = %s LIMIT 1", $asset_key ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			ARRAY_A
		);

		if ( is_array( $existing ) && isset( $existing['status'] ) ) {
			$status = (string) $existing['status'];
			if ( self::STATUS_FAILED === $status ) {
				// Re-enqueue is an explicit operator retry: reset the row so a
				// permanently failed asset can be attempted again.
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$this->table,
					array(
						'status'        => self::STATUS_PENDING,
						'next_retry_at' => $now,
						'updated_at'    => $now,
					),
					array( 'asset_key' => $asset_key ),
					array( '%s', '%d', '%d' ),
					array( '%s' )
				);
			}
			// Already pending/uploading/synced; nothing more to do.
			return true;
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array(
				'asset_key'       => $asset_key,
				'status'          => self::STATUS_PENDING,
				'attempts'        => 0,
				'last_error'      => '',
				'remote_checksum' => '',
				'enqueued_at'     => $now,
				'updated_at'      => $now,
				'next_retry_at'   => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d' )
		);

		return false !== $inserted;
	}


	/**
	 * Claim up to `$limit` pending rows whose retry time has passed, marking
	 * them `uploading` so concurrent workers don't double-process.
	 *
	 * Rows stuck in `uploading` (a worker crashed mid-transfer) are first
	 * returned to `pending` so the queue always makes progress.
	 *
	 * @param int $limit        Maximum rows to claim.
	 * @param int $stale_after  Seconds after which an `uploading` row is reclaimed.
	 * @return array<string> List of claimed asset keys.
	 */
	public function claim( int $limit = 5, int $stale_after = 1800 ): array {
		$wpdb  = $this->wpdb;
		$table = $this->table;
		if ( ! $this->ensure_table() ) {
			return array();
		}

		$this->reclaim_stale( $stale_after );

		$now  = time();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT asset_key FROM {$table} WHERE status = %s AND next_retry_at <= %d ORDER BY enqueued_at ASC LIMIT %d", self::STATUS_PENDING, $now, $limit ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			ARRAY_A
		);

		$claimed = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$key = isset( $row['asset_key'] ) ? (string) $row['asset_key'] : '';
			if ( '' === $key ) {
				continue;
			}
			$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$this->table,
				array(
					'status'     => self::STATUS_UPLOADING,
					'updated_at' => $now,
				),
				array(
					'asset_key' => $key,
					'status'    => self::STATUS_PENDING,
				),
				array( '%s', '%d' ),
				array( '%s', '%s' )
			);
			// Only a row this worker actually flipped may be claimed; a `0`
			// means a concurrent worker got there first.
			if ( 1 === $updated ) {
				$claimed[] = $key;
			}
		}

		return $claimed;
	}

	/**
	 * Return rows stuck in `uploading` (e.g. a worker that died mid-transfer)
	 * back to `pending` so they are retried instead of blocking forever.
	 *
	 * @return int Number of rows reclaimed.
	 */
	public function reclaim_stale( int $stale_after = 1800 ): int {
		$wpdb  = $this->wpdb;
		$table = $this->table;
		if ( ! $this->ensure_table() ) {
			return 0;
		}

		$cutoff  = time() - max( 60, $stale_after );
		$updated = $wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET status = %s, next_retry_at = %d, updated_at = %d WHERE status = %s AND updated_at < %d", self::STATUS_PENDING, time(), time(), self::STATUS_UPLOADING, $cutoff ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		);

		return false !== $updated ? (int) $updated : 0;
	}

	/**
	 * Remove a queued row (asset deleted or reassigned).
	 */
	public function remove( string $asset_key ): void {
		$wpdb = $this->wpdb;
		if ( ! $this->ensure_table() || '' === $asset_key ) {
			return;
		}

		$wpdb->delete( $this->table, array( 'asset_key' => $asset_key ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}


	/**
	 * Mark a row as successfully synced.
	 */
	public function complete( string $asset_key ): void {
		$wpdb  = $this->wpdb;
		$table = $this->table;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array(
				'status'     => self::STATUS_SYNCED,
				'updated_at' => time(),
			),
			array( 'asset_key' => $asset_key ),
			array( '%s' ),
			array( '%s' )
		);
	}


	/**
	 * Mark a row as failed with an error message and schedule retry.
	 *
	 * @param int|null $attempts Explicit attempt count (avoids double-counting
	 *                           when called from increment_attempt()).
	 */
	public function fail( string $asset_key, string $error, ?int $attempts = null ): void {
		$wpdb     = $this->wpdb;
		$table    = $this->table;
		$now      = time();
		$attempts = null === $attempts ? $this->attempts_for( $asset_key ) + 1 : $attempts;
		$retry_at = $now + $this->backoff( $attempts );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array(
				'status'        => self::STATUS_FAILED,
				'attempts'      => $attempts,
				'last_error'    => substr( $error, 0, 1000 ),
				'updated_at'    => $now,
				'next_retry_at' => $retry_at,
			),
			array( 'asset_key' => $asset_key ),
			array( '%s', '%d', '%s', '%d', '%d' ),
			array( '%s' )
		);
	}


	/**
	 * Increment attempts and reschedule as pending for retry (when still
	 * under max retries), otherwise mark failed.
	 *
	 * @param int $max_retries
	 */
	public function increment_attempt( string $asset_key, int $max_retries = 5 ): void {
		$wpdb     = $this->wpdb;
		$table    = $this->table;
		$now      = time();
		$attempts = $this->attempts_for( $asset_key ) + 1;

		if ( $attempts > $max_retries ) {
			$this->fail( $asset_key, 'تعداد تلاش بیش از حد مجاز (' . $max_retries . ').', $attempts );
			return;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array(
				'status'        => self::STATUS_PENDING,
				'attempts'      => $attempts,
				'updated_at'    => $now,
				'next_retry_at' => $now + $this->backoff( $attempts ),
			),
			array( 'asset_key' => $asset_key ),
			array( '%s', '%d', '%d', '%d' ),
			array( '%s' )
		);
	}


	/**
	 * Stats per status.
	 *
	 * @return array<string, int>
	 */
	public function stats(): array {
		$wpdb  = $this->wpdb;
		$table = $this->table;
		if ( ! $this->ensure_table() ) {
			return array(
				'pending'   => 0,
				'uploading' => 0,
				'synced'    => 0,
				'failed'    => 0,
			);
		}

		$out = array(
			'pending'   => 0,
			'uploading' => 0,
			'synced'    => 0,
			'failed'    => 0,
		);
		foreach ( array_keys( $out ) as $status ) {
			$count          = $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			);
			$out[ $status ] = is_numeric( $count ) ? (int) $count : 0;
		}

		return $out;
	}


	/**
	 * Delete old synced rows (called by daily cron).
	 *
	 * @return int Number of deleted rows.
	 */
	public function cleanup_synced( int $older_than_seconds = 0 ): int {
		$wpdb  = $this->wpdb;
		$table = $this->table;
		if ( ! $this->ensure_table() || $older_than_seconds < 1 ) {
			return 0;
		}
		$cutoff = time() - $older_than_seconds;

		$deleted = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE status = %s AND updated_at < %d", self::STATUS_SYNCED, $cutoff ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		);

		return false !== $deleted ? (int) $deleted : 0;
	}


	/**
	 * Ensure the table exists and matches the current schema (idempotent).
	 *
	 * `dbDelta` runs on every call so the widened `asset_key` column reaches
	 * already-installed sites, not only fresh ones.
	 */
	public function ensure_table(): bool {
		$wpdb  = $this->wpdb;
		$table = $this->table;
		if ( null !== $this->table_ready ) {
			return $this->table_ready;
		}
		if ( ! $this->wpdb instanceof wpdb ) {
			$this->table_ready = false;
			return false;
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			$upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( ! is_readable( $upgrade_file ) ) {
				$this->table_ready = false;
				return false;
			}
			require_once $upgrade_file;
		}

		$charset = $wpdb->get_charset_collate();
		dbDelta( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				asset_key varchar(64) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				last_error text NULL,
				remote_checksum char(64) DEFAULT '',
				enqueued_at bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
				next_retry_at bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				UNIQUE KEY asset_key (asset_key),
				KEY status_retry (status, next_retry_at)
			) {$charset};"
		);

		$this->table_ready = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) ) === $this->table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $this->table_ready;
	}


	/**
	 * @return int
	 */
	private function attempts_for( string $asset_key ): int {
		$wpdb  = $this->wpdb;
		$table = $this->table;
		$val   = $wpdb->get_var(
			$wpdb->prepare( "SELECT attempts FROM {$table} WHERE asset_key = %s LIMIT 1", $asset_key ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		);

		return is_numeric( $val ) ? (int) $val : 0;
	}


	/**
	 * Exponential backoff seconds: 2^attempts, capped at 1 hour.
	 */
	private function backoff( int $attempts ): int {
		$seconds = (int) pow( 2, min( $attempts, 10 ) );
		return min( $seconds, 3600 );
	}


	private function __clone() {}
}
