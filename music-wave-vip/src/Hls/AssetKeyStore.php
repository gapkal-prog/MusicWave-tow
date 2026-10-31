<?php
/**
 * At-rest store for per-asset AES-128 HLS keys.
 *
 * Keys are never stored plaintext: `wrap()`/`unwrap()` from the shared
 * `SecretBoxCipher` (libsodium + HKDF from AUTH_KEY/SECURE_AUTH_SALT) are
 * used. Multiple key versions can coexist for zero-downtime rotation; only the
 * highest `key_version` is used for new packaging.
 *
 * @package ManaCore\MusicWave\Vip\Hls
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Hls;

use ManaCore\MusicWave\Core\Support\SecretBoxCipher;
use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @immutable
 */
final class AssetKeyStore {
	/** Context string for HLS key wrapping (distinct from upload credentials). */
	public const WRAP_CONTEXT = 'mw-hls-key-wrap';
	/** AES-128 key size in bytes. */
	public const KEY_SIZE = 16;

	/** @var wpdb */
	private $wpdb;
	/** @var string Table name with prefix. */
	private $table;

	public function __construct( ?wpdb $wpdb = null ) {
		$global      = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof wpdb ? $GLOBALS['wpdb'] : null;
		$this->wpdb  = $wpdb instanceof wpdb ? $wpdb : $global;
		$this->table = $this->wpdb instanceof wpdb ? $this->wpdb->prefix . 'mw_vip_asset_keys' : 'mw_vip_asset_keys';
	}

	/**
	 * Generate, wrap, and store a fresh AES-128 key for an asset.
	 *
	 * @return string|null 16-byte raw key on success, or null when encryption
	 *                     is unavailable (fail-closed).
	 */
	public function create( string $asset_key ): ?string {
		if ( ! SecretBoxCipher::available() ) {
			return null;
		}

		$raw_key = random_bytes( self::KEY_SIZE );
		$wrapped = SecretBoxCipher::wrap( $raw_key, self::WRAP_CONTEXT );
		if ( null === $wrapped ) {
			return null;
		}

		$version = $this->next_version( $asset_key );
		$nonce   = bin2hex( random_bytes( SecretBoxCipher::NONCE_LENGTH ) );
		$wpdb    = $this->wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array(
				'asset_key'   => $asset_key,
				'key_id'      => bin2hex( random_bytes( 8 ) ),
				'key_version' => $version,
				'wrapped_key' => $wrapped,
				'wrap_nonce'  => $nonce,
				'created'     => time(),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d' )
		);

		return $raw_key;
	}

	/**
	 * Unwrap the active (highest version) key for an asset.
	 *
	 * @return string|null 16-byte raw key, or null when not found / cannot
	 *                     unwrap (e.g. site salts rotated without re-wrap).
	 */
	public function active_key( string $asset_key ): ?string {
		$row = $this->fetch_active( $asset_key );
		if ( null === $row ) {
			return null;
		}

		return SecretBoxCipher::unwrap( (string) $row['wrapped_key'], self::WRAP_CONTEXT );
	}

	/**
	 * Highest key version stored for an asset (0 when none).
	 *
	 * Used to pin the version in a freshly rendered variant playlist so the key
	 * endpoint returns exactly the key that encrypted those segments.
	 */
	public function active_version( string $asset_key ): int {
		if ( ! $this->table_exists() ) {
			return 0;
		}

		$wpdb = $this->wpdb;
		$max  = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(key_version) FROM {$this->table} WHERE asset_key = %s", $asset_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_numeric( $max ) ? (int) $max : 0;
	}

	/**
	 * Unwrap a specific key version (used when playing back older renditions
	 * packaged under a previous key version).
	 *
	 * @return string|null 16-byte raw key, or null.
	 */
	public function key_by_version( string $asset_key, int $version ): ?string {
		$row = $this->fetch_by_version( $asset_key, $version );
		if ( null === $row ) {
			return null;
		}

		return SecretBoxCipher::unwrap( (string) $row['wrapped_key'], self::WRAP_CONTEXT );
	}

	/**
	 * Whether at least one wrapped key exists for any asset.
	 */
	public function has_any_key(): bool {
		if ( ! $this->table_exists() ) {
			return false;
		}
		$wpdb  = $this->wpdb;
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_numeric( $count ) && (int) $count > 0;
	}

	/**
	 * Whether the key table already exists.
	 *
	 * A read-only probe: diagnostics must be able to render before the schema
	 * has ever been migrated, so this never creates the table.
	 */
	private function table_exists(): bool {
		if ( ! $this->wpdb instanceof wpdb ) {
			return false;
		}

		$wpdb = $this->wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) ) === $this->table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Ensure the table exists and matches the current schema (idempotent).
	 *
	 * `dbDelta` runs on every call so widened columns (e.g. `asset_key` growing
	 * from `char(32)` to hold the opaque `vip:<32hex>` identifier) reach
	 * already-installed sites, not just fresh ones.
	 */
	public function ensure_table(): bool {
		if ( ! $this->wpdb instanceof wpdb ) {
			return false;
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			$upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( ! is_readable( $upgrade_file ) ) {
				return false;
			}
			require_once $upgrade_file;
		}

		$wpdb    = $this->wpdb;
		$charset = $wpdb->get_charset_collate();
		dbDelta( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- DDL only; table name is built from $wpdb->prefix.
			"CREATE TABLE {$this->table} (
				asset_key varchar(64) NOT NULL,
				key_id varchar(64) NOT NULL,
				key_version smallint(5) unsigned NOT NULL DEFAULT 1,
				wrapped_key text NOT NULL,
				wrap_nonce varchar(64) NOT NULL DEFAULT '',
				created bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY (asset_key, key_id)
			) {$charset};"
		);

		return $this->table_exists();
	}

	/**
	 * @return int Next version number (1-based).
	 */
	private function next_version( string $asset_key ): int {
		$wpdb = $this->wpdb;
		$max  = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(key_version) FROM {$this->table} WHERE asset_key = %s", $asset_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_numeric( $max ) ? (int) $max + 1 : 1;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function fetch_active( string $asset_key ): ?array {
		$wpdb = $this->wpdb;

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE asset_key = %s ORDER BY key_version DESC LIMIT 1", $asset_key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function fetch_by_version( string $asset_key, int $version ): ?array {
		$wpdb = $this->wpdb;

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE asset_key = %s AND key_version = %d ORDER BY created DESC LIMIT 1", $asset_key, $version ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function __clone() {}
}
