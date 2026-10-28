<?php
/**
 * Storage boundary for user playlists.
 *
 * The domain rules (ownership, privacy, ordering, limits, visibility
 * filtering) live in PlaylistRepository; this contract only persists rows so
 * the rules stay testable without a database
 * (PROJECT_PLAN.md Stage 5 deliverable 4).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Playlists;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface PlaylistStore {
	/**
	 * Whether the backing storage is ready (migration has run).
	 */
	public function available(): bool;

	/**
	 * One playlist row, or null when it does not exist.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $playlist_id ): ?array;

	/**
	 * One playlist row resolved by its share token.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_share_token( string $share_token ): ?array;

	/**
	 * Playlist rows owned by one user, most recently updated first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function for_user( int $user_id, int $limit = 50, int $offset = 0 ): array;

	/**
	 * Number of playlists owned by one user.
	 */
	public function count_for_user( int $user_id ): int;

	/**
	 * Insert one playlist row.
	 *
	 * @param array<string, mixed> $row Validated row values.
	 * @return int New playlist ID, or 0 on failure.
	 */
	public function insert( array $row ): int;

	/**
	 * Update selected columns of one playlist row.
	 *
	 * @param array<string, mixed> $fields Validated column values.
	 */
	public function update( int $playlist_id, array $fields ): bool;

	/**
	 * Delete one playlist row and its items.
	 */
	public function delete( int $playlist_id ): bool;

	/**
	 * Ordered items of one playlist.
	 *
	 * @return array<int, array{release_id: int, position: int, added_at: int}>
	 */
	public function items( int $playlist_id ): array;

	/**
	 * Replace the complete ordered item list of one playlist.
	 *
	 * @param array<int, int> $release_ids Ordered release IDs.
	 */
	public function replace_items( int $playlist_id, array $release_ids ): bool;

	/**
	 * Delete every playlist owned by one user.
	 */
	public function delete_for_user( int $user_id ): bool;

	/**
	 * Remove one release from every playlist that still references it.
	 *
	 * @return void
	 */
	public function purge_release( int $release_id ): void;

	/**
	 * Public playlists, most recently updated first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function public_playlists( int $limit = 24, int $offset = 0, string $search = '', string $orderby = 'updated_at' ): array;

	/**
	 * Count public playlists, optionally filtered by search.
	 */
	public function count_public( string $search = '' ): int;

	/**
	 * Item counts for several playlists in one query (kills the N+1 in
	 * PlaylistRepository::for_user()).
	 *
	 * @param array<int, int> $playlist_ids Playlist IDs.
	 * @return array<int, int> Map of playlist_id => item count.
	 */
	public function item_counts_for( array $playlist_ids ): array;
}
