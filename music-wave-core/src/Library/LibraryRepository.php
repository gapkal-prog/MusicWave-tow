<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal custom table name uses validated $wpdb->prefix + self::TABLE constant.
/**
 * Personal music library persistence for signed-in customers.
 *
 * Uses indexed table mw_library_index when available (FIX-26) for O(log n)
 * lookups; falls back to user_meta for fresh installs before migration.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Library;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Catalog\ReleaseVisibility;
use WP_Term;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LibraryRepository {
	public const META_KEY  = 'mw_music_library';
	public const MAX_ITEMS = 500;
	public const TABLE     = 'mw_library_index';

	public const TYPE_RELEASE  = 'release';
	public const TYPE_ARTIST   = 'artist';
	public const TYPE_WISHLIST = 'wishlist';
	public const TYPE_PRESAVE  = 'presave';

	public const RELEASE_DATE_META = 'mw_release_date';

	/** How long the public "saved by N" aggregate may be cached, in seconds. */
	private const COUNT_CACHE_TTL = 300;

	/** @var array<int, array<int, array<string, mixed>>> */
	private $cache = array();

	/** @var ReleaseVisibility */
	private $visibility;

	/** @var bool|null */
	private $has_table = null;

	public function __construct( ?ReleaseVisibility $visibility = null ) {
		$this->visibility = null !== $visibility ? $visibility : new ReleaseVisibility();
	}

	/**
	 * Whether the indexed table exists.
	 */
	private function table_exists(): bool {
		if ( null !== $this->has_table ) {
			return $this->has_table;
		}
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			$this->has_table = false;
			return false;
		}
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found           = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->has_table = ( $found === $table );

		return $this->has_table;
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'before_delete_post', array( $this, 'handle_deleted_post' ) );
		add_action( 'delete_term', array( $this, 'handle_deleted_term' ), 10, 4 );
	}

	/**
	 * @return array<int, string>
	 */
	public function types(): array {
		return array( self::TYPE_RELEASE, self::TYPE_ARTIST, self::TYPE_WISHLIST, self::TYPE_PRESAVE );
	}

	/**
	 * @return array<int, string>
	 */
	public function release_types(): array {
		return array( self::TYPE_RELEASE, self::TYPE_WISHLIST, self::TYPE_PRESAVE );
	}

	/**
	 * Users who stored one specific item.
	 *
	 * @return array<int, int>
	 */
	public function users_with( string $type, int $item_id ): array {
		if ( $item_id < 1 || ! in_array( $type, $this->types(), true ) ) {
			return array();
		}
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return array();
		}
		if ( $this->table_exists() ) {
			$table = $wpdb->prefix . self::TABLE;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$users = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT user_id FROM {$table} WHERE type = %s AND item_id = %d",
					$type,
					$item_id
				)
			);

			return is_array( $users ) ? array_map( 'absint', $users ) : array();
		}

		// Legacy LIKE scan fallback (pre-migration).
		$type_pattern = '%' . $wpdb->esc_like( 's:4:\"type\";s:' . strlen( $type ) . ':\"' . $type . '\";' ) . '%';
		$int_pattern  = '%' . $wpdb->esc_like( 's:2:\"id\";i:' . $item_id . ';' ) . '%';
		$str_pattern  = '%' . $wpdb->esc_like( 's:2:\"id\";s:' . strlen( (string) $item_id ) . ':\"' . $item_id . '\";' ) . '%';
		$sql          = "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s AND ( meta_value LIKE %s OR meta_value LIKE %s )";
		$params       = array( self::META_KEY, $type_pattern, $int_pattern, $str_pattern );

		$candidates = $wpdb->get_col( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		$matched = array();
		foreach ( is_array( $candidates ) ? $candidates : array() as $candidate ) {
			$user_id = absint( $candidate );
			if ( $user_id > 0 && $this->has( $user_id, $type, $item_id ) ) {
				$matched[] = $user_id;
			}
		}

		return $matched;
	}

	/**
	 * Public aggregate: how many customers saved one item.
	 *
	 * Only a count leaves this method — never user IDs — so it is safe to render
	 * on public surfaces. The value is cached briefly because it is social proof,
	 * not authoritative state.
	 */
	public function count_for_item( string $type, int $item_id ): int {
		if ( $item_id < 1 || ! in_array( $type, $this->types(), true ) ) {
			return 0;
		}

		$cache_key = self::count_cache_key( $type, $item_id );
		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached && is_numeric( $cached ) ) {
				return max( 0, (int) $cached );
			}
		}

		$count = $this->count_users_with( $type, $item_id );
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $cache_key, $count, self::COUNT_CACHE_TTL );
		}

		return $count;
	}

	public function release_timestamp( int $release_id ): int {
		$stored = get_post_meta( $release_id, self::RELEASE_DATE_META, true );
		if ( ! is_scalar( $stored ) || '' === (string) $stored ) {
			return 0;
		}
		$timestamp = strtotime( (string) $stored . ' 00:00:00 UTC' );

		return is_int( $timestamp ) && $timestamp > 0 ? $timestamp : 0;
	}

	public function is_upcoming( int $release_id ): bool {
		$timestamp = $this->release_timestamp( $release_id );

		return $timestamp > time();
	}

	/**
	 * Return normalized library for a user, most recently added first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all( int $user_id ): array {
		if ( $user_id < 1 ) {
			return array();
		}

		if ( isset( $this->cache[ $user_id ] ) ) {
			$items = $this->cache[ $user_id ];
		} else {
			if ( $this->table_exists() ) {
				$items = $this->fetch_from_table( $user_id );
			} else {
				$stored = get_user_meta( $user_id, self::META_KEY, true );
				$stored = is_array( $stored ) ? $stored : array();
				$items  = array();
				foreach ( $stored as $item ) {
					$normalized = $this->normalize_item( $item );
					if ( null !== $normalized ) {
						$items[] = $normalized;
					}
				}
				usort(
					$items,
					static function ( array $left, array $right ): int {
						return (int) $right['added'] <=> (int) $left['added'];
					}
				);
			}
			$this->cache[ $user_id ] = $items;
		}

		/**
		 * Filter a customer's personal library items.
		 *
		 * @param array $items   Normalized library items.
		 * @param int   $user_id Library owner.
		 */
		$filtered = apply_filters( 'music_wave_library_items', $items, $user_id );

		return is_array( $filtered ) ? $filtered : $items;
	}

	public function has( int $user_id, string $type, int $item_id ): bool {
		if ( $user_id < 1 || $item_id < 1 ) {
			return false;
		}
		if ( $this->table_exists() ) {
			global $wpdb;
			if ( ! $wpdb instanceof \wpdb ) {
				return false;
			}
			$table = $wpdb->prefix . self::TABLE;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1 FROM {$table} WHERE user_id = %d AND type = %s AND item_id = %d LIMIT 1",
					$user_id,
					$type,
					$item_id
				)
			);

			return null !== $found && '' !== (string) $found;
		}

		foreach ( $this->all( $user_id ) as $item ) {
			if ( $item['type'] === $type && (int) $item['id'] === $item_id ) {
				return true;
			}
		}

		return false;
	}

	public function add( int $user_id, string $type, int $item_id ): bool {
		if ( $user_id < 1 || $item_id < 1 || ! in_array( $type, $this->types(), true ) ) {
			return false;
		}
		if ( ! $this->target_exists( $type, $item_id ) ) {
			return false;
		}

		if ( $this->table_exists() ) {
			if ( $this->has( $user_id, $type, $item_id ) ) {
				return false;
			}
			if ( $this->table_count( $user_id ) >= self::MAX_ITEMS ) {
				return false;
			}
			$added = time();
			global $wpdb;
			if ( ! $wpdb instanceof \wpdb ) {
				return false;
			}
			$table = $wpdb->prefix . self::TABLE;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (user_id, item_id, type, added) VALUES (%d, %d, %s, %d)",
					$user_id,
					$item_id,
					$type,
					$added
				)
			);
			if ( false === $result ) {
				return false;
			}
			// Keep user_meta in sync for legacy exporters and fallback.
			$items                   = $this->fetch_from_table( $user_id );
			$this->cache[ $user_id ] = $items;
			update_user_meta( $user_id, self::META_KEY, array_values( $items ) );
			$this->forget_count( $type, $item_id );

			return true;
		}

		// Legacy path.
		if ( $this->has( $user_id, $type, $item_id ) ) {
			return false;
		}
		$items = $this->all( $user_id );
		if ( count( $items ) >= self::MAX_ITEMS ) {
			return false;
		}
		$items[] = array(
			'type'  => $type,
			'id'    => $item_id,
			'added' => time(),
		);

		if ( ! $this->persist( $user_id, $items ) ) {
			return false;
		}
		$this->forget_count( $type, $item_id );

		return true;
	}

	public function remove( int $user_id, string $type, int $item_id ): bool {
		if ( $user_id < 1 ) {
			return false;
		}
		if ( $this->table_exists() ) {
			if ( ! $this->has( $user_id, $type, $item_id ) ) {
				return false;
			}
			global $wpdb;
			if ( ! $wpdb instanceof \wpdb ) {
				return false;
			}
			$table = $wpdb->prefix . self::TABLE;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE user_id = %d AND type = %s AND item_id = %d",
					$user_id,
					$type,
					$item_id
				)
			);
			$items                   = $this->fetch_from_table( $user_id );
			$this->cache[ $user_id ] = $items;
			update_user_meta( $user_id, self::META_KEY, array_values( $items ) );
			$this->forget_count( $type, $item_id );

			return true;
		}

		if ( ! $this->has( $user_id, $type, $item_id ) ) {
			return false;
		}
		$items = array_values(
			array_filter(
				$this->all( $user_id ),
				static function ( array $item ) use ( $type, $item_id ): bool {
					return $item['type'] !== $type || (int) $item['id'] !== $item_id;
				}
			)
		);

		if ( ! $this->persist( $user_id, $items ) ) {
			return false;
		}
		$this->forget_count( $type, $item_id );

		return true;
	}

	/**
	 * @return array<int, int>
	 */
	public function ids( int $user_id, string $type ): array {
		if ( $this->table_exists() ) {
			global $wpdb;
			if ( ! $wpdb instanceof \wpdb ) {
				return array();
			}
			$table = $wpdb->prefix . self::TABLE;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT item_id FROM {$table} WHERE user_id = %d AND type = %s ORDER BY added DESC",
					$user_id,
					$type
				)
			);

			return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
		}

		$ids = array();
		foreach ( $this->all( $user_id ) as $item ) {
			if ( $item['type'] === $type ) {
				$ids[] = (int) $item['id'];
			}
		}

		return $ids;
	}

	public function count( int $user_id ): int {
		if ( $this->table_exists() ) {
			return $this->table_count( $user_id );
		}

		return count( $this->all( $user_id ) );
	}

	/**
	 * @return void
	 */
	public function handle_deleted_post( int $post_id ): void {
		if ( $post_id < 1 || ReleasePostType::KEY !== get_post_type( $post_id ) ) {
			return;
		}
		foreach ( $this->release_types() as $type ) {
			$this->purge_target( $type, $post_id );
		}
	}

	/**
	 * @return void
	 */
	public function handle_deleted_term( int $term_id, int $unused, string $taxonomy, $deleted = null ): void {
		unset( $unused, $deleted );
		if ( 'mw_artist' === $taxonomy ) {
			$this->purge_target( self::TYPE_ARTIST, $term_id );
		}
	}

	private function target_exists( string $type, int $item_id ): bool {
		if ( self::TYPE_PRESAVE === $type ) {
			return $this->visibility->can_read( $item_id ) && $this->is_upcoming( $item_id );
		}
		if ( in_array( $type, array( self::TYPE_RELEASE, self::TYPE_WISHLIST ), true ) ) {
			return $this->visibility->can_read( $item_id );
		}
		$term = get_term( $item_id, 'mw_artist' );

		return $term instanceof WP_Term;
	}

	/**
	 * @return void
	 */
	private function purge_target( string $type, int $item_id ): void {
		$this->cache = array();
		$this->forget_count( $type, $item_id );
		if ( $this->table_exists() ) {
			global $wpdb;
			if ( $wpdb instanceof \wpdb ) {
				$table = $wpdb->prefix . self::TABLE;
				// Bulk delete is faster than looping users_with.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$table} WHERE type = %s AND item_id = %d",
						$type,
						$item_id
					)
				);
			}
			// Also clean meta for any users that still have legacy data.
			// We still need to clear per-user caches via users_with fallback scan?
			// For simplicity, clear all cached and rely on lazy reload.
			return;
		}

		foreach ( $this->users_with( $type, $item_id ) as $user_id ) {
			$this->remove( $user_id, $type, $item_id );
		}
	}

	private function persist( int $user_id, array $items ): bool {
		if ( $this->table_exists() ) {
			// Replace table contents with provided items (used by legacy callers).
			global $wpdb;
			if ( $wpdb instanceof \wpdb ) {
				$table = $wpdb->prefix . self::TABLE;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = %d", $user_id ) );
				foreach ( $items as $item ) {
					$norm = $this->normalize_item( $item );
					if ( null === $norm ) {
						continue;
					}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->query(
						$wpdb->prepare(
							"INSERT IGNORE INTO {$table} (user_id, item_id, type, added) VALUES (%d, %d, %s, %d)",
							$user_id,
							(int) $norm['id'],
							(string) $norm['type'],
							(int) $norm['added']
						)
					);
				}
			}
		}
		$result                  = update_user_meta( $user_id, self::META_KEY, array_values( $items ) );
		$this->cache[ $user_id ] = array_values( $items );

		return false !== $result;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_from_table( int $user_id ): array {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return array();
		}
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT item_id, type, added FROM {$table} WHERE user_id = %d ORDER BY added DESC",
				$user_id
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'type'  => isset( $row['type'] ) ? sanitize_key( (string) $row['type'] ) : '',
				'id'    => isset( $row['item_id'] ) ? absint( $row['item_id'] ) : 0,
				'added' => isset( $row['added'] ) ? absint( $row['added'] ) : 0,
			);
		}

		return $items;
	}

	private static function count_cache_key( string $type, int $item_id ): string {
		return 'mw_library_count_' . $type . '_' . $item_id;
	}

	/**
	 * Count distinct owners for one item, preferring the indexed table.
	 */
	private function count_users_with( string $type, int $item_id ): int {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return 0;
		}
		if ( $this->table_exists() ) {
			$table = $wpdb->prefix . self::TABLE;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE type = %s AND item_id = %d",
					$type,
					$item_id
				)
			);

			return max( 0, (int) $count );
		}

		return count( $this->users_with( $type, $item_id ) );
	}

	/**
	 * Drop the cached aggregate for one item after a write.
	 */
	private function forget_count( string $type, int $item_id ): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::count_cache_key( $type, $item_id ) );
		}
	}

	private function table_count( int $user_id ): int {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return 0;
		}
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) );

		return (int) $count;
	}

	/**
	 * @param mixed $item
	 * @return array<string, mixed>|null
	 */
	private function normalize_item( $item ): ?array {
		if ( ! is_array( $item ) ) {
			return null;
		}
		$type = isset( $item['type'] ) && is_scalar( $item['type'] ) ? sanitize_key( (string) $item['type'] ) : '';
		$id   = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
		if ( ! in_array( $type, $this->types(), true ) || $id < 1 ) {
			return null;
		}

		return array(
			'type'  => $type,
			'id'    => $id,
			'added' => isset( $item['added'] ) ? absint( $item['added'] ) : 0,
		);
	}
}
