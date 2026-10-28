<?php
/**
 * Indexed playlist storage backed by two dedicated tables.
 *
 * Playlists and their items are high-cardinality per-user operational data, so
 * they live in indexed tables instead of serialized user meta. Every method
 * degrades to an empty result until the 0.11.0 migration has run
 * (PROJECT_PLAN.md Stage 5 deliverable 4).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Playlists;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DatabasePlaylistStore implements PlaylistStore {
	public const TABLE       = 'mw_playlists';
	public const ITEMS_TABLE = 'mw_playlist_items';

	/** @var bool|null Memoized availability for this request. */
	private $available = null;

	public function available(): bool {
		global $wpdb;

		if ( null !== $this->available ) {
			return $this->available;
		}
		if ( ! $wpdb instanceof \wpdb ) {
			$this->available = false;

			return false;
		}

		$playlists       = $this->table();
		$items           = $this->items_table();
		$this->available = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table_like_pattern( $playlists ) ) ) === $playlists // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table_like_pattern( $items ) ) ) === $items; // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $this->available;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $playlist_id ): ?array {
		global $wpdb;

		if ( $playlist_id < 1 || ! $this->available() ) {
			return null;
		}

		$table = $this->table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $playlist_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_by_share_token( string $share_token ): ?array {
		global $wpdb;

		if ( '' === $share_token || ! $this->available() ) {
			return null;
		}

		$table = $this->table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE share_token = %s", $share_token ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function for_user( int $user_id, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		if ( $user_id < 1 || ! $this->available() ) {
			return array();
		}

		$table = $this->table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				max( 1, $limit ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function count_for_user( int $user_id ): int {
		global $wpdb;

		if ( $user_id < 1 || ! $this->available() ) {
			return 0;
		}

		$table = $this->table();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @param array<string, mixed> $row Validated row values.
	 */
	public function insert( array $row ): int {
		global $wpdb;

		if ( ! $this->available() ) {
			return 0;
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'user_id'     => isset( $row['user_id'] ) ? absint( $row['user_id'] ) : 0,
				'title'       => isset( $row['title'] ) ? (string) $row['title'] : '',
				'visibility'  => isset( $row['visibility'] ) ? (string) $row['visibility'] : PlaylistRepository::VISIBILITY_PRIVATE,
				'share_token' => isset( $row['share_token'] ) && '' !== (string) $row['share_token'] ? (string) $row['share_token'] : null,
				'created_at'  => isset( $row['created_at'] ) ? absint( $row['created_at'] ) : time(),
				'updated_at'  => isset( $row['updated_at'] ) ? absint( $row['updated_at'] ) : time(),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d' )
		);

		return false !== $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param array<string, mixed> $fields Validated column values.
	 */
	public function update( int $playlist_id, array $fields ): bool {
		global $wpdb;

		if ( $playlist_id < 1 || array() === $fields || ! $this->available() ) {
			return false;
		}

		$data    = array();
		$formats = array();
		foreach ( array( 'title', 'visibility', 'share_token' ) as $column ) {
			if ( array_key_exists( $column, $fields ) ) {
				$value           = (string) $fields[ $column ];
				$data[ $column ] = 'share_token' === $column && '' === $value ? null : $value;
				$formats[]       = '%s';
			}
		}
		$data['updated_at'] = isset( $fields['updated_at'] ) ? absint( $fields['updated_at'] ) : time();
		$formats[]          = '%d';

		return false !== $wpdb->update( $this->table(), $data, array( 'id' => $playlist_id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function delete( int $playlist_id ): bool {
		global $wpdb;

		if ( $playlist_id < 1 || ! $this->available() ) {
			return false;
		}

		$items = $this->items_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$items} WHERE playlist_id = %d", $playlist_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return false !== $wpdb->delete( $this->table(), array( 'id' => $playlist_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @return array<int, array{release_id: int, position: int, added_at: int}>
	 */
	public function items( int $playlist_id ): array {
		global $wpdb;

		if ( $playlist_id < 1 || ! $this->available() ) {
			return array();
		}

		$items = $this->items_table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT release_id, position, added_at FROM {$items} WHERE playlist_id = %d ORDER BY position ASC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$playlist_id,
				PlaylistRepository::MAX_ITEMS
			),
			ARRAY_A
		);

		$normalized = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$normalized[] = array(
				'release_id' => isset( $row['release_id'] ) ? absint( $row['release_id'] ) : 0,
				'position'   => isset( $row['position'] ) ? absint( $row['position'] ) : 0,
				'added_at'   => isset( $row['added_at'] ) ? absint( $row['added_at'] ) : 0,
			);
		}

		return $normalized;
	}

	/**
	 * @param array<int, int> $release_ids Ordered release IDs.
	 */
	public function replace_items( int $playlist_id, array $release_ids ): bool {
		global $wpdb;

		if ( $playlist_id < 1 || ! $this->available() ) {
			return false;
		}

		$items = $this->items_table();
		$now   = time();
		$kept  = $this->added_at_map( $playlist_id );

		// The delete-then-insert swap runs in a transaction so concurrent
		// mutations can never observe (or leave behind) a half-written list.
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$items} WHERE playlist_id = %d", $playlist_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$position = 0;
		foreach ( $release_ids as $release_id ) {
			$release_id = absint( $release_id );
			if ( $release_id < 1 ) {
				continue;
			}
			$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$items,
				array(
					'playlist_id' => $playlist_id,
					'release_id'  => $release_id,
					'position'    => $position,
					'added_at'    => isset( $kept[ $release_id ] ) ? $kept[ $release_id ] : $now,
				),
				array( '%d', '%d', '%d', '%d' )
			);
			if ( false === $inserted ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

				return false;
			}
			++$position;
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		return true;
	}

	public function delete_for_user( int $user_id ): bool {
		global $wpdb;

		if ( $user_id < 1 || ! $this->available() ) {
			return false;
		}

		$deleted = false;
		foreach ( $this->for_user( $user_id, PlaylistRepository::MAX_PLAYLISTS ) as $playlist ) {
			$deleted = $this->delete( isset( $playlist['id'] ) ? absint( $playlist['id'] ) : 0 ) || $deleted;
		}

		return $deleted;
	}

	/**
	 * @return void
	 */
	public function purge_release( int $release_id ): void {
		global $wpdb;

		if ( $release_id < 1 || ! $this->available() ) {
			return;
		}

		$items     = $this->items_table();
		$playlists = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT playlist_id FROM {$items} WHERE release_id = %d", $release_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$items} WHERE release_id = %d", $release_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Keep positions contiguous in every affected playlist.
		foreach ( is_array( $playlists ) ? $playlists : array() as $playlist_id ) {
			$playlist_id = absint( $playlist_id );
			if ( $playlist_id < 1 ) {
				continue;
			}
			$ordered = array();
			foreach ( $this->items( $playlist_id ) as $item ) {
				$ordered[] = (int) $item['release_id'];
			}
			$this->replace_items( $playlist_id, $ordered );
		}
	}

	public function public_playlists( int $limit = 24, int $offset = 0, string $search = '', string $orderby = 'updated_at' ): array {
		global $wpdb;

		if ( ! $this->available() ) {
			return array();
		}

		$limit   = min( 50, max( 1, $limit ) );
		$offset  = max( 0, $offset );
		$orderby = in_array( $orderby, array( 'updated_at', 'created_at', 'title' ), true ) ? $orderby : 'updated_at';
		$order   = 'title' === $orderby ? 'ASC' : 'DESC';
		$field   = 'title' === $orderby ? 'title' : $orderby;

		$table  = $this->table();
		$search = trim( $search );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted, field allow-listed.
		$sql  = "SELECT * FROM {$table} WHERE visibility = %s";
		$args = array( PlaylistRepository::VISIBILITY_PUBLIC );
		if ( '' !== $search ) {
			$sql   .= ' AND title LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$sql   .= " ORDER BY {$field} {$order}, id DESC LIMIT %d OFFSET %d";
		$args[] = $limit;
		$args[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	public function count_public( string $search = '' ): int {
		global $wpdb;

		if ( ! $this->available() ) {
			return 0;
		}

		$table  = $this->table();
		$search = trim( $search );
		if ( '' !== $search ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE visibility = %s AND title LIKE %s", PlaylistRepository::VISIBILITY_PUBLIC, '%' . $wpdb->esc_like( $search ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE visibility = %s", PlaylistRepository::VISIBILITY_PUBLIC ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function item_counts_for( array $playlist_ids ): array {
		global $wpdb;

		$ids = array();
		foreach ( $playlist_ids as $playlist_id ) {
			$playlist_id = absint( $playlist_id );
			if ( $playlist_id > 0 ) {
				$ids[] = $playlist_id;
			}
		}
		if ( array() === $ids || ! $this->available() ) {
			return array();
		}

		$items        = $this->items_table();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// One grouped query replaces the per-playlist items() call that made
		// PlaylistRepository::for_user() an N+1 (review finding P4). DISTINCT
		// keeps the count identical to the deduplicated ordered_ids() view.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT playlist_id, COUNT( DISTINCT release_id ) AS item_count FROM {$items} WHERE playlist_id IN ( {$placeholders} ) GROUP BY playlist_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the %d list is built above.
				$ids
			),
			ARRAY_A
		);

		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$playlist_id = isset( $row['playlist_id'] ) ? absint( $row['playlist_id'] ) : 0;
			if ( $playlist_id > 0 ) {
				$counts[ $playlist_id ] = isset( $row['item_count'] ) ? absint( $row['item_count'] ) : 0;
			}
		}

		return $counts;
	}

	/**
	 * Preserve original add timestamps across a reorder.
	 *
	 * @return array<int, int> Map of release_id => added_at.
	 */
	private function added_at_map( int $playlist_id ): array {
		global $wpdb;

		if ( $playlist_id < 1 || ! $this->available() ) {
			return array();
		}

		$items = $this->items_table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT release_id, added_at FROM {$items} WHERE playlist_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$playlist_id
			),
			ARRAY_A
		);

		$map = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$release_id = isset( $row['release_id'] ) ? absint( $row['release_id'] ) : 0;
			if ( $release_id > 0 && ! isset( $map[ $release_id ] ) ) {
				$map[ $release_id ] = isset( $row['added_at'] ) ? absint( $row['added_at'] ) : 0;
			}
		}

		return $map;
	}

	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	private function items_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::ITEMS_TABLE;
	}

	/**
	 * Escape LIKE wildcards so the existence probe matches exactly one table.
	 */
	private function table_like_pattern( string $table ): string {
		global $wpdb;

		return method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $table ) : $table;
	}
}
