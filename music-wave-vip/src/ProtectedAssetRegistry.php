<?php
/**
 * Opaque, indexed inventory of protected assets.
 *
 * Legacy `local:<relative-path>` identifiers disclose the real layout of the
 * protected directory and are guessable. The registry maps random opaque
 * `vip:<key>` identifiers to files and records size, checksum, and MIME type
 * at registration so delivery can fail closed on tampered or replaced files
 * (PROJECT_PLAN.md Stage 2 deliverables 2 and 6).
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip;

final class ProtectedAssetRegistry {
	public const TABLE  = 'mw_vip_assets';
	public const PREFIX = 'vip:';

	/** @var bool|null Memoized table availability for this request. */
	private $table_ready = null;

	/**
	 * Register one protected file and return its opaque identifier.
	 *
	 * Idempotent per relative path: re-registering an unchanged file returns
	 * the existing identifier. A changed file (size or checksum drift) gets a
	 * refreshed fingerprint under the same identifier so editors keep their
	 * assignments after intentional re-uploads.
	 *
	 * @return string|false Opaque `vip:<key>` identifier.
	 */
	public function register( string $relative, string $absolute ) {
		global $wpdb;

		if ( ! $this->ensure_table() || ! is_file( $absolute ) || ! is_readable( $absolute ) ) {
			return false;
		}

		$table     = $wpdb->prefix . self::TABLE;
		$path_hash = hash( 'sha256', $relative );
		$existing  = $wpdb->get_row( $wpdb->prepare( "SELECT asset_key, file_size, checksum FROM {$table} WHERE path_hash = %s", $path_hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$size = filesize( $absolute );
		if ( false === $size || $size < 1 ) {
			return false;
		}

		if ( is_array( $existing ) && isset( $existing['asset_key'] ) ) {
			if ( (int) $existing['file_size'] !== (int) $size ) {
				$checksum = hash_file( 'sha256', $absolute );
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table,
					array(
						'file_size' => (int) $size,
						'checksum'  => is_string( $checksum ) ? $checksum : '',
						'mime'      => $this->mime_type( $absolute ),
					),
					array( 'path_hash' => $path_hash )
				);
			}

			return self::PREFIX . (string) $existing['asset_key'];
		}

		$checksum = hash_file( 'sha256', $absolute );
		$key      = bin2hex( random_bytes( 16 ) );
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'asset_key'     => $key,
				'path_hash'     => $path_hash,
				'relative_path' => $relative,
				'file_size'     => (int) $size,
				'checksum'      => is_string( $checksum ) ? $checksum : '',
				'mime'          => $this->mime_type( $absolute ),
				'registered_at' => time(),
			)
		);

		return false === $inserted ? false : self::PREFIX . $key;
	}

	/**
	 * Resolve an opaque identifier to its registered inventory row.
	 *
	 * @return array<string, int|string>|null
	 */
	public function find( string $asset_id ): ?array {
		global $wpdb;

		if ( 0 !== strpos( $asset_id, self::PREFIX ) || ! $this->ensure_table() ) {
			return null;
		}

		$key = substr( $asset_id, strlen( self::PREFIX ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			return null;
		}

		$table = $wpdb->prefix . self::TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT relative_path, file_size, checksum, mime FROM {$table} WHERE asset_key = %s", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Whether an opaque identifier exists in the inventory.
	 */
	public function exists( string $asset_id ): bool {
		return null !== $this->find( $asset_id );
	}

	/**
	 * Delete an asset row by `vip:<key>` or raw 32-char hex key.
	 */
	public function delete( string $asset_id_or_key ): bool {
		global $wpdb;

		if ( ! $this->ensure_table() ) {
			return false;
		}

		$key = 0 === strpos( $asset_id_or_key, self::PREFIX )
			? substr( $asset_id_or_key, strlen( self::PREFIX ) )
			: $asset_id_or_key;
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			return false;
		}

		$table   = $wpdb->prefix . self::TABLE;
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE asset_key = %s", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return false !== $deleted && (int) $deleted > 0;
	}

	/**
	 * Aggregate registered inventory count and cumulative byte size.
	 *
	 * @return array{count: int, total_size: int}
	 */
	public function stats(): array {
		global $wpdb;

		if ( ! $this->ensure_table() || ! ( $wpdb instanceof \wpdb ) ) {
			return array(
				'count'      => 0,
				'total_size' => 0,
			);
		}

		$table = $wpdb->prefix . self::TABLE;
		$row   = $wpdb->get_row( "SELECT COUNT(*) AS cnt, COALESCE(SUM(file_size), 0) AS total_bytes FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'count'      => is_array( $row ) && isset( $row['cnt'] ) ? absint( $row['cnt'] ) : 0,
			'total_size' => is_array( $row ) && isset( $row['total_bytes'] ) ? absint( $row['total_bytes'] ) : 0,
		);
	}

	/**
	 * Extension-based MIME map shared by registration and delivery.
	 */
	public function mime_type( string $file ): string {
		$types     = array(
			'mp3'  => 'audio/mpeg',
			'm4a'  => 'audio/mp4',
			'aac'  => 'audio/aac',
			'ogg'  => 'audio/ogg',
			'wav'  => 'audio/wav',
			'flac' => 'audio/flac',
			'zip'  => 'application/zip',
		);
		$extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		return isset( $types[ $extension ] ) ? $types[ $extension ] : 'application/octet-stream';
	}

	/**
	 * Create the inventory table on first use.
	 */
	private function ensure_table(): bool {
		global $wpdb;

		if ( null !== $this->table_ready ) {
			return $this->table_ready;
		}
		if ( ! $wpdb instanceof \wpdb ) {
			$this->table_ready = false;
			return false;
		}

		$table = $wpdb->prefix . self::TABLE;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table_ready = true;
			return true;
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			if ( ! defined( 'ABSPATH' ) ) {
				$this->table_ready = false;
				return false;
			}
			$upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( ! is_readable( $upgrade_file ) ) {
				$this->table_ready = false;
				return false;
			}
			require_once $upgrade_file;
		}

		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				asset_key char(32) NOT NULL,
				path_hash char(64) NOT NULL,
				relative_path text NOT NULL,
				file_size bigint(20) unsigned NOT NULL,
				checksum char(64) NOT NULL DEFAULT '',
				mime varchar(100) NOT NULL DEFAULT '',
				registered_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (asset_key),
				UNIQUE KEY path_hash (path_hash)
			) {$charset};"
		);

		$this->table_ready = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $this->table_ready;
	}
}
