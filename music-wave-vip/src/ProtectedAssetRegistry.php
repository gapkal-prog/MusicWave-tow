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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

			$asset_id = self::PREFIX . (string) $existing['asset_key'];

			/**
			 * Fires when a protected asset is registered (or its content
			 * changed), so integrations (e.g. automatic remote upload) can react.
			 *
			 * @param string $asset_id Opaque `vip:<32hex>` identifier.
			 */
			do_action( 'music_wave_vip_asset_registered', $asset_id );

			return $asset_id;
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
		if ( false === $inserted ) {
			return false;
		}

		$asset_id = self::PREFIX . $key;

		/** This action is documented in this file (see the existing-row branch above). */
		do_action( 'music_wave_vip_asset_registered', $asset_id );

		return $asset_id;
	}

	/**
	 * Refresh the stored checksum when the file on disk is newer than its
	 * registration (same size, different content). Used by auto-refresh to
	 * avoid permanent fail-closed on same-size replacements.
	 *
	 * @return bool True when checksum was refreshed.
	 */
	public function refresh_checksum_if_newer( string $relative, string $absolute, int $size ): bool {
		global $wpdb;

		if ( ! $this->ensure_table() || ! $this->column_exists( 'checksum' ) || ! $this->column_exists( 'registered_at' ) || ! $this->column_exists( 'file_size' ) ) {
			return false;
		}

		$table     = $wpdb->prefix . self::TABLE;
		$path_hash = hash( 'sha256', $relative );
		$row       = $wpdb->get_row( $wpdb->prepare( "SELECT asset_key, checksum, file_size, registered_at FROM {$table} WHERE path_hash = %s LIMIT 1", $path_hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $row ) || ! isset( $row['registered_at'] ) ) {
			return false;
		}

		$registered_at = (int) $row['registered_at'];
		$mtime         = (int) filemtime( $absolute );
		if ( $mtime <= $registered_at ) {
			return false;
		}

		// Same size but newer content: recompute checksum.
		if ( (int) ( $row['file_size'] ?? 0 ) !== (int) $size ) {
			return false;
		}

		$checksum = hash_file( 'sha256', $absolute );
		if ( false === $checksum || (string) ( $row['checksum'] ?? '' ) === $checksum ) {
			return false;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array( 'checksum' => $checksum ),
			array( 'path_hash' => $path_hash ),
			array( '%s' ),
			array( '%s' )
		);

		return true;
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
	 * Set the HLS packaging state for an asset.
	 */
	public function set_hls_state( string $asset_id, string $state, ?string $manifest = null, int $bytes = 0, int $duration = 0 ): bool {
		global $wpdb;

		if ( ! $this->ensure_table() ) {
			return false;
		}
		$key = 0 === strpos( $asset_id, self::PREFIX )
			? substr( $asset_id, strlen( self::PREFIX ) )
			: $asset_id;
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			return false;
		}

		if ( ! $this->column_exists( 'hls_state' ) ) {
			return false;
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . self::TABLE,
			array(
				'hls_state'    => $state,
				'hls_manifest' => $manifest ?? '',
				'hls_bytes'    => (int) $bytes,
				'hls_duration' => (int) $duration,
				'packaged_at'  => 'ready' === $state ? time() : 0,
			),
			array( 'asset_key' => $key ),
			array( '%s', '%s', '%d', '%d', '%d' ),
			array( '%s' )
		);

		return false !== $updated;
	}

	/**
	 * Set the remote upload state for an asset.
	 */
	public function set_upload_state( string $asset_id, string $state, ?string $remote_path = null ): bool {
		global $wpdb;

		if ( ! $this->ensure_table() ) {
			return false;
		}
		$key = 0 === strpos( $asset_id, self::PREFIX )
			? substr( $asset_id, strlen( self::PREFIX ) )
			: $asset_id;
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			return false;
		}

		if ( ! $this->column_exists( 'upload_state' ) ) {
			return false;
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . self::TABLE,
			array(
				'upload_state' => $state,
				'remote_path'  => $remote_path ?? '',
				'uploaded_at'  => 'synced' === $state ? time() : 0,
			),
			array( 'asset_key' => $key ),
			array( '%s', '%s', '%d' ),
			array( '%s' )
		);

		return false !== $updated;
	}

	/**
	 * Return HLS metadata for an asset.
	 *
	 * @return array{hls_state: string, hls_manifest: string, hls_bytes: int, hls_duration: int}|null
	 */
	public function hls_meta( string $asset_id ): ?array {
		global $wpdb;

		if ( ! $this->ensure_table() ) {
			return null;
		}
		$key = 0 === strpos( $asset_id, self::PREFIX )
			? substr( $asset_id, strlen( self::PREFIX ) )
			: $asset_id;
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			return null;
		}

		if ( ! $this->column_exists( 'hls_state' ) ) {
			return null;
		}

		$table = $wpdb->prefix . self::TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT hls_state, hls_manifest, hls_bytes, hls_duration FROM {$table} WHERE asset_key = %s LIMIT 1", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Return upload metadata for an asset.
	 *
	 * @return array{upload_state: string, remote_path: string, uploaded_at: int}|null
	 */
	public function upload_meta( string $asset_id ): ?array {
		global $wpdb;

		if ( ! $this->ensure_table() ) {
			return null;
		}
		$key = 0 === strpos( $asset_id, self::PREFIX )
			? substr( $asset_id, strlen( self::PREFIX ) )
			: $asset_id;
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			return null;
		}

		if ( ! $this->column_exists( 'upload_state' ) ) {
			return null;
		}

		$table = $wpdb->prefix . self::TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT upload_state, remote_path, uploaded_at FROM {$table} WHERE asset_key = %s LIMIT 1", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
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
	 * Check whether a column exists on the assets table.
	 */
	public function column_exists( string $column ): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb || ! $this->ensure_table() ) {
			return false;
		}

		$table = $wpdb->prefix . self::TABLE;
		$col   = strtolower( $column );
		$cols  = $wpdb->get_col( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $col ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $cols ) && in_array( $col, $cols, true );
	}

	/**
	 * Add missing HLS/upload columns to an existing table (idempotent).
	 */
	private function add_missing_columns(): void {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$table      = $wpdb->prefix . self::TABLE;
		$statements = array();

		if ( ! $this->column_exists( 'hls_state' ) ) {
			$statements[] = "ALTER TABLE {$table} ADD COLUMN hls_state varchar(20) NOT NULL DEFAULT ''";
		}
		if ( ! $this->column_exists( 'hls_manifest' ) ) {
			$statements[] = "ALTER TABLE {$table} ADD COLUMN hls_manifest longtext NULL";
		}
		if ( ! $this->column_exists( 'hls_bytes' ) ) {
			$statements[] = "ALTER TABLE {$table} ADD COLUMN hls_bytes bigint(20) unsigned NOT NULL DEFAULT 0";
		}
		if ( ! $this->column_exists( 'hls_duration' ) ) {
			$statements[] = "ALTER TABLE {$table} ADD COLUMN hls_duration int(10) unsigned NOT NULL DEFAULT 0";
		}
		if ( ! $this->column_exists( 'packaged_at' ) ) {
			$statements[] = "ALTER TABLE {$table} ADD COLUMN packaged_at bigint(20) unsigned NOT NULL DEFAULT 0";
		}
		if ( ! $this->column_exists( 'upload_state' ) ) {
			$statements[] = "ALTER TABLE {$table} ADD COLUMN upload_state varchar(20) NOT NULL DEFAULT ''";
		}
		if ( ! $this->column_exists( 'remote_path' ) ) {
			$statements[] = "ALTER TABLE {$table} ADD COLUMN remote_path text NULL";
		}
		if ( ! $this->column_exists( 'uploaded_at' ) ) {
			$statements[] = "ALTER TABLE {$table} ADD COLUMN uploaded_at bigint(20) unsigned NOT NULL DEFAULT 0";
		}

		foreach ( $statements as $sql ) {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}
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
			// Mark ready *before* migrating columns: `add_missing_columns()`
			// calls `column_exists()`, which re-enters `ensure_table()`; without
			// this the memo would still be null and the call would recurse.
			$this->table_ready = true;
			$this->add_missing_columns();
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
				hls_state varchar(20) NOT NULL DEFAULT '',
				hls_manifest longtext NULL,
				hls_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				hls_duration int(10) unsigned NOT NULL DEFAULT 0,
				packaged_at bigint(20) unsigned NOT NULL DEFAULT 0,
				upload_state varchar(20) NOT NULL DEFAULT '',
				remote_path text NULL,
				uploaded_at bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (asset_key),
				UNIQUE KEY path_hash (path_hash)
			) {$charset};"
		);

		$this->table_ready = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $this->table_ready;
	}
}
