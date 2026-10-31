<?php
/**
 * Database-backed StreamSessionStore with atomic lease counting.
 *
 * Stores only hashes (token_hash, ip_hash, ua_hash) — never raw tokens or
 * client IPs. Concurrency leases are taken inside a single transaction with
 * `SELECT ... FOR UPDATE` so two simultaneous requests cannot bypass
 * `session_max_concurrent`.
 *
 * @package ManaCore\MusicWave\Core\Playback
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Playback;

use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Playback-session store backed by a dedicated table.
 *
 * All durable state lives in the table; the instance only carries a memoised
 * table-availability flag.
 */
final class DbStreamSessionStore implements StreamSessionStore {
	/** @var wpdb|null */
	private $wpdb;
	/** @var string Table name with prefix. */
	private $table;
	/** @var bool|null Memoized availability. */
	private $table_ready = null;

	public function __construct( ?wpdb $wpdb = null ) {
		if ( $wpdb instanceof wpdb ) {
			$this->wpdb = $wpdb;
		} else {
			global $wpdb;
			$this->wpdb = $wpdb instanceof wpdb ? $wpdb : null;
		}
		$this->table = $this->wpdb instanceof wpdb
			? $this->wpdb->prefix . 'mw_stream_sessions'
			: 'mw_stream_sessions';
	}


	public function create(
		int $user_id,
		int $release_id,
		string $asset_key,
		string $ip_hash,
		string $ua_hash,
		string $token_hash,
		int $ttl,
		int $max_concurrent = 0
	): ?StreamSession {
		if ( ! $this->ensure_table() || '' === $token_hash ) {
			return null;
		}

		$session_id = StreamSession::PREFIX . bin2hex( random_bytes( 8 ) );
		$now        = time();
		$expires    = $now + max( 30, $ttl );
		$wpdb       = $this->wpdb;
		$table      = $this->table;
		if ( ! $wpdb instanceof wpdb ) {
			return null;
		}

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic lease creation within this transaction.
		$lock = '';
		try {
			if ( $max_concurrent > 0 ) {
				$lease_key = $user_id > 0 ? 'u' . $user_id : 'i' . $ip_hash;
				$bucket    = $this->concurrency_bucket( $lease_key );

				// `SELECT COUNT(*) ... FOR UPDATE` only blocks phantom inserts
				// under REPEATABLE READ. An advisory lock makes the lease check
				// atomic under any isolation level; failing to take it is a
				// fail-closed refusal rather than a silent bypass.
				$lock = 'mw_sess_' . substr( hash( 'sha256', $lease_key ), 0, 32 );
				$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- advisory lock name is a bound parameter.
				if ( '1' !== (string) $got ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					return null;
				}

				$count = $wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$bucket['sql']} AND revoked = 0 AND expires > %d FOR UPDATE", $bucket['value'], $now ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				);
				if ( is_numeric( $count ) && (int) $count >= $max_concurrent ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					return null;
				}
			}

			$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$this->table,
				array(
					'session_id' => $session_id,
					'token_hash' => $token_hash,
					'user_id'    => max( 0, $user_id ),
					'release_id' => max( 0, $release_id ),
					'asset_key'  => $asset_key,
					'ip_hash'    => $ip_hash,
					'ua_hash'    => $ua_hash,
					'created'    => $now,
					'expires'    => $expires,
					'last_seen'  => $now,
					'revoked'    => 0,
				),
				array(
					'%s',
					'%s',
					'%d',
					'%d',
					'%s',
					'%s',
					'%s',
					'%d',
					'%d',
					'%d',
					'%d',
				)
			);
			if ( false === $inserted ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return null;
			}
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return null;
		} finally {
			if ( '' !== $lock ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- advisory lock name is a bound parameter.
			}
		}

		return new StreamSession(
			$session_id,
			$token_hash,
			max( 0, $user_id ),
			max( 0, $release_id ),
			$asset_key,
			$ip_hash,
			$ua_hash,
			$now,
			$expires,
			$now,
			0
		);
	}

	public function get_by_id( string $session_id ): ?StreamSession {
		$wpdb  = $this->wpdb;
		$table = $this->table;
		if ( ! $this->ensure_table() || '' === $session_id ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %s AND revoked = 0 AND expires > %d LIMIT 1", $session_id, time() ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			ARRAY_A
		);

		return is_array( $row ) ? StreamSession::from_row( $row ) : null;
	}


	public function renew( string $session_id, string $token_hash, int $ttl ): ?StreamSession {
		if ( ! $this->ensure_table() || '' === $session_id || '' === $token_hash ) {
			return null;
		}

		$wpdb    = $this->wpdb;
		$table   = $this->table;
		$session = null;
		$expires = 0;
		$now     = time();

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic lease renewal.
		try {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %s AND token_hash = %s AND revoked = 0 FOR UPDATE", $session_id, $token_hash ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				ARRAY_A
			);
			if ( ! is_array( $row ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return null;
			}
			$session = StreamSession::from_row( $row );
			if ( null === $session || $session->is_expired( $now ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return null;
			}

			$expires = $now + max( 30, $ttl );
			$renewed = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$this->table,
				array(
					'expires'   => $expires,
					'last_seen' => $now,
				),
				array(
					'session_id' => $session_id,
					'token_hash' => $token_hash,
				),
				array( '%d', '%d' ),
				array( '%s', '%s' )
			);
			if ( false === $renewed ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return null;
			}
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return null;
		}

		return new StreamSession(
			$session_id,
			$token_hash,
			$session->user_id(),
			$session->release_id(),
			$session->asset_key(),
			$session->ip_hash(),
			$session->ua_hash(),
			$session->created(),
			$expires,
			$now,
			0
		);
	}


	public function revoke( string $session_id ): bool {
		$wpdb  = $this->wpdb;
		$table = $this->table;
		if ( ! $this->ensure_table() || '' === $session_id ) {
			return false;
		}

		$deleted = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array( 'revoked' => 1 ),
			array( 'session_id' => $session_id ),
			array( '%d' ),
			array( '%s' )
		);

		return false !== $deleted;
	}


	/**
	 * Revoke every active session for a user (logout, password reset, access loss).
	 *
	 * @return int Number of sessions revoked.
	 */
	public function revoke_for_user( int $user_id ): int {
		$wpdb  = $this->wpdb;
		$table = $this->table;
		if ( ! $this->ensure_table() || $user_id < 1 ) {
			return 0;
		}

		$revoked = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "UPDATE {$table} SET revoked = 1 WHERE user_id = %d AND revoked = 0", $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		);

		return false !== $revoked ? (int) $revoked : 0;
	}


	public function count_active( string $concurrency_key, int $now = 0 ): int {
		$wpdb  = $this->wpdb;
		$table = $this->table;
		if ( ! $this->ensure_table() || '' === $concurrency_key ) {
			return 0;
		}
		if ( 0 === $now ) {
			$now = time();
		}

		$bucket = $this->concurrency_bucket( $concurrency_key );
		$count  = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$bucket['sql']} AND revoked = 0 AND expires > %d", $bucket['value'], $now ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}


	public function cleanup( int $grace_seconds ): int {
		$wpdb  = $this->wpdb;
		$table = $this->table;
		if ( ! $this->ensure_table() ) {
			return 0;
		}
		$cutoff = time() - max( 60, $grace_seconds );

		$deleted = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE ( revoked = 1 AND expires < %d ) OR expires < %d", $cutoff, $cutoff ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		);

		return false !== $deleted ? (int) $deleted : 0;
	}


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

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) ) === $this->table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $exists ) {
			$this->migrate_columns();
			$this->table_ready = true;
			return true;
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
				session_id char(64) NOT NULL,
				token_hash char(64) NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				release_id bigint(20) unsigned NOT NULL DEFAULT 0,
				asset_key varchar(191) NOT NULL DEFAULT '',
				ip_hash char(64) NOT NULL DEFAULT '',
				ua_hash char(64) NOT NULL DEFAULT '',
				created bigint(20) unsigned NOT NULL DEFAULT 0,
				expires bigint(20) unsigned NOT NULL DEFAULT 0,
				last_seen bigint(20) unsigned NOT NULL DEFAULT 0,
				revoked tinyint(1) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (session_id),
				UNIQUE KEY token_hash (token_hash),
				KEY user_id (user_id),
				KEY user_active (user_id, revoked, expires),
				KEY expires (expires)
			) {$charset};"
		);

		$this->table_ready = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) ) === $this->table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $this->table_ready ) {
			$this->migrate_columns();
		}

		return $this->table_ready;
	}


	/**
	 * Widen `asset_key` for opaque identifiers such as `vip:<32hex>` and
	 * legacy `local:<path>` values. dbDelta is not reliable for narrowing the
	 * gap between char(32) and longer identifiers on every MySQL/MariaDB
	 * version, so verify the live column once per request.
	 */
	private function migrate_columns(): void {
		$wpdb  = $this->wpdb;
		$table = $this->table;
		if ( ! $this->wpdb instanceof wpdb ) {
			return;
		}
		$column = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'asset_key'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $column ) || ! isset( $column['Type'] ) ) {
			return;
		}
		if ( 1 === preg_match( '/^varchar\((\d+)\)/i', (string) $column['Type'], $matches ) && (int) $matches[1] >= 191 ) {
			return;
		}
		if ( 1 === preg_match( '/^char\((\d+)\)/i', (string) $column['Type'], $matches ) && (int) $matches[1] >= 191 ) {
			return;
		}
		$wpdb->query( "ALTER TABLE {$table} MODIFY asset_key varchar(191) NOT NULL DEFAULT ''" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Translate a concurrency key into a safe SQL predicate + value.
	 *
	 * @return array{sql: string, value: int|string}
	 */
	private function concurrency_bucket( string $key ): array {
		if ( 0 === strpos( $key, 'u' ) ) {
			return array(
				'sql'   => 'user_id = %d',
				'value' => absint( substr( $key, 1 ) ),
			);
		}

		return array(
			'sql'   => 'ip_hash = %s',
			'value' => 0 === strpos( $key, 'i' ) ? substr( $key, 1 ) : $key,
		);
	}


	private function __clone() {}
}
