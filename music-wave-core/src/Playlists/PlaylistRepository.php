<?php
/**
 * User playlists: ownership, ordering, privacy, and share rules.
 *
 * Ownership is required for every mutation. Privacy is deny-by-default:
 * private playlists are owner-only, unlisted playlists require the exact
 * share token, and public playlists are readable by anyone. Non-owner views
 * only ever expose publicly published releases, so drafts, scheduled, and
 * private releases cannot leak through a shared playlist
 * (PROJECT_PLAN.md Stage 5 deliverable 4).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Playlists;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Catalog\ReleaseVisibility;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PlaylistRepository {
	public const MAX_PLAYLISTS = 50;
	public const MAX_ITEMS     = 500;
	public const MAX_TITLE     = 120;

	public const VISIBILITY_PRIVATE  = 'private';
	public const VISIBILITY_UNLISTED = 'unlisted';
	public const VISIBILITY_PUBLIC   = 'public';

	/** @var PlaylistStore */
	private $store;

	/** @var ReleaseVisibility */
	private $visibility;

	public function __construct( ?PlaylistStore $store = null, ?ReleaseVisibility $visibility = null ) {
		$this->store      = null !== $store ? $store : new DatabasePlaylistStore();
		$this->visibility = null !== $visibility ? $visibility : new ReleaseVisibility();
	}

	/**
	 * Register the hooks that keep stored playlists consistent.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'before_delete_post', array( $this, 'handle_deleted_post' ) );
		add_action( 'deleted_user', array( $this, 'handle_deleted_user' ) );
	}

	/**
	 * Supported playlist visibilities.
	 *
	 * @return array<int, string>
	 */
	public function visibilities(): array {
		return array( self::VISIBILITY_PRIVATE, self::VISIBILITY_UNLISTED, self::VISIBILITY_PUBLIC );
	}

	/**
	 * Create one playlist for a user.
	 *
	 * @return int New playlist ID, or 0 when the request is invalid.
	 */
	public function create( int $user_id, string $title, string $visibility = self::VISIBILITY_PRIVATE ): int {
		$title      = $this->sanitize_title( $title );
		$visibility = $this->sanitize_visibility( $visibility );

		if ( $user_id < 1 || '' === $title ) {
			return 0;
		}
		if ( $this->store->count_for_user( $user_id ) >= self::MAX_PLAYLISTS ) {
			return 0;
		}

		$now = time();

		return $this->store->insert(
			array(
				'user_id'     => $user_id,
				'title'       => $title,
				'visibility'  => $visibility,
				'share_token' => self::VISIBILITY_PRIVATE === $visibility ? '' : $this->generate_share_token(),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
	}

	/**
	 * Rename a playlist or change its visibility. Owner only.
	 *
	 * Downgrading to private revokes the share token immediately; re-sharing
	 * mints a fresh one so an old link can never be resurrected.
	 *
	 * @param array<string, mixed> $changes Accepts `title` and `visibility`.
	 */
	public function update( int $user_id, int $playlist_id, array $changes ): bool {
		$playlist = $this->owned( $user_id, $playlist_id );
		if ( null === $playlist ) {
			return false;
		}

		$fields               = array();
		$visibility_unchanged = false;
		if ( array_key_exists( 'title', $changes ) ) {
			$title = $this->sanitize_title( (string) $changes['title'] );
			if ( '' === $title ) {
				return false;
			}
			$fields['title'] = $title;
		}
		if ( array_key_exists( 'visibility', $changes ) ) {
			$visibility = $this->sanitize_visibility( (string) $changes['visibility'] );
			if ( $visibility === (string) $playlist['visibility'] ) {
				// Re-posting the current visibility must not rotate the share
				// token and silently break previously shared links.
				$visibility_unchanged = true;
			} else {
				$fields['visibility']  = $visibility;
				$fields['share_token'] = self::VISIBILITY_PRIVATE === $visibility ? '' : $this->generate_share_token();
			}
		}
		if ( array() === $fields ) {
			return $visibility_unchanged;
		}

		return $this->store->update( $playlist_id, $fields );
	}

	/**
	 * Delete a playlist. Owner only.
	 */
	public function delete( int $user_id, int $playlist_id ): bool {
		if ( null === $this->owned( $user_id, $playlist_id ) ) {
			return false;
		}

		return $this->store->delete( $playlist_id );
	}

	/**
	 * Append one release to a playlist. Owner only.
	 */
	public function add_item( int $user_id, int $playlist_id, int $release_id ): bool {
		if ( null === $this->owned( $user_id, $playlist_id ) || $release_id < 1 ) {
			return false;
		}
		if ( ! $this->visibility->can_read( $release_id ) ) {
			return false;
		}

		$ordered = $this->ordered_ids( $playlist_id );
		if ( in_array( $release_id, $ordered, true ) || count( $ordered ) >= self::MAX_ITEMS ) {
			return false;
		}

		$ordered[] = $release_id;
		$saved     = $this->store->replace_items( $playlist_id, $ordered );
		if ( $saved ) {
			$this->store->update( $playlist_id, array( 'updated_at' => time() ) );
		}

		return $saved;
	}

	/**
	 * Remove one release from a playlist. Owner only.
	 */
	public function remove_item( int $user_id, int $playlist_id, int $release_id ): bool {
		if ( null === $this->owned( $user_id, $playlist_id ) ) {
			return false;
		}

		$ordered = $this->ordered_ids( $playlist_id );
		if ( ! in_array( $release_id, $ordered, true ) ) {
			return false;
		}

		$remaining = array_values(
			array_filter(
				$ordered,
				static function ( int $candidate ) use ( $release_id ): bool {
					return $candidate !== $release_id;
				}
			)
		);

		$saved = $this->store->replace_items( $playlist_id, $remaining );
		if ( $saved ) {
			$this->store->update( $playlist_id, array( 'updated_at' => time() ) );
		}

		return $saved;
	}

	/**
	 * Reorder a playlist. Owner only.
	 *
	 * Unknown IDs are ignored and any stored item the caller omitted keeps its
	 * relative order at the end, so a stale client can never silently drop
	 * items from the playlist.
	 *
	 * @param array<int, mixed> $release_ids Requested order.
	 */
	public function reorder( int $user_id, int $playlist_id, array $release_ids ): bool {
		if ( null === $this->owned( $user_id, $playlist_id ) ) {
			return false;
		}

		$stored    = $this->ordered_ids( $playlist_id );
		$requested = array();
		foreach ( $release_ids as $release_id ) {
			$release_id = absint( $release_id );
			if ( in_array( $release_id, $stored, true ) && ! in_array( $release_id, $requested, true ) ) {
				$requested[] = $release_id;
			}
		}
		foreach ( $stored as $release_id ) {
			if ( ! in_array( $release_id, $requested, true ) ) {
				$requested[] = $release_id;
			}
		}

		$saved = $this->store->replace_items( $playlist_id, $requested );
		if ( $saved ) {
			$this->store->update( $playlist_id, array( 'updated_at' => time() ) );
		}

		return $saved;
	}

	/**
	 * Playlists owned by one user, with item counts.
	 *
	 * Counts come from one grouped store query instead of a per-playlist
	 * items() call, so opening a 50-playlist list costs two queries total
	 * instead of fifty-one (review finding P4).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function for_user( int $user_id, int $limit = 50, int $offset = 0 ): array {
		$playlists = array();
		$rows      = array();
		foreach ( $this->store->for_user( $user_id, min( self::MAX_PLAYLISTS, max( 1, $limit ) ), max( 0, $offset ) ) as $row ) {
			$rows[] = $row;
		}

		$counts = $this->store->item_counts_for(
			array_map(
				static function ( array $row ): int {
					return isset( $row['id'] ) ? absint( $row['id'] ) : 0;
				},
				$rows
			)
		);

		foreach ( $rows as $row ) {
			$playlist = $this->normalize( $row );
			if ( null === $playlist ) {
				continue;
			}
			$playlist_id       = (int) $playlist['id'];
			$playlist['count'] = isset( $counts[ $playlist_id ] ) ? (int) $counts[ $playlist_id ] : 0;
			$playlists[]       = $playlist;
		}

		return $playlists;
	}

	public function count_for_user( int $user_id ): int {
		if ( $user_id < 1 ) {
			return 0;
		}

		return $this->store->count_for_user( $user_id );
	}

	/**
	 * One playlist, normalized, regardless of viewer.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $playlist_id ): ?array {
		$row = $this->store->find( $playlist_id );

		return null !== $row ? $this->normalize( $row ) : null;
	}

	/**
	 * Resolve a shared playlist by token. Private playlists are never returned.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_shared( string $share_token ): ?array {
		$share_token = $this->sanitize_share_token( $share_token );
		if ( '' === $share_token ) {
			return null;
		}

		$row      = $this->store->find_by_share_token( $share_token );
		$playlist = null !== $row ? $this->normalize( $row ) : null;
		if ( null === $playlist || self::VISIBILITY_PRIVATE === $playlist['visibility'] ) {
			return null;
		}

		return $playlist;
	}

	/**
	 * Whether one viewer may read a playlist.
	 *
	 * @param array<string, mixed> $playlist Normalized playlist.
	 */
	public function can_view( array $playlist, int $viewer_id, string $share_token = '' ): bool {
		$owner      = isset( $playlist['user_id'] ) ? (int) $playlist['user_id'] : 0;
		$visibility = isset( $playlist['visibility'] ) ? (string) $playlist['visibility'] : self::VISIBILITY_PRIVATE;

		if ( $viewer_id > 0 && $owner === $viewer_id ) {
			return true;
		}
		if ( self::VISIBILITY_PUBLIC === $visibility ) {
			return true;
		}
		if ( self::VISIBILITY_UNLISTED !== $visibility ) {
			return false;
		}

		$stored    = isset( $playlist['share_token'] ) ? (string) $playlist['share_token'] : '';
		$presented = $this->sanitize_share_token( $share_token );

		return '' !== $stored && '' !== $presented && hash_equals( $stored, $presented );
	}

	/**
	 * Ordered items visible to one viewer.
	 *
	 * Owners see everything they may read; every other viewer only sees
	 * published releases.
	 *
	 * @return array<int, array{release_id: int, position: int}>
	 */
	public function items_for_viewer( int $playlist_id, int $viewer_id, string $share_token = '' ): array {
		$playlist = $this->find( $playlist_id );
		if ( null === $playlist || ! $this->can_view( $playlist, $viewer_id, $share_token ) ) {
			return array();
		}

		$is_owner = $viewer_id > 0 && (int) $playlist['user_id'] === $viewer_id;
		$rows     = $this->store->items( $playlist_id );

		// Long playlists are the norm; warm the post cache for every stored
		// release so the per-item visibility check below is a cache read.
		$release_ids = array();
		foreach ( $rows as $item ) {
			$release_ids[] = (int) $item['release_id'];
		}
		$this->visibility->prime( $release_ids );

		$items    = array();
		$position = 0;
		foreach ( $rows as $item ) {
			$release_id = (int) $item['release_id'];
			$readable   = $is_owner ? $this->visibility->can_read( $release_id ) : $this->visibility->is_public( $release_id );
			if ( $release_id < 1 || ! $readable ) {
				continue;
			}
			$items[] = array(
				'release_id' => $release_id,
				'position'   => $position,
			);
			++$position;
		}

		return $items;
	}

	/**
	 * Canonical public/share URL for a playlist (empty when `private` or invalid).
	 *
	 * Always points to the public `/playlists/` route so shared links work for
	 * every visitor including logged-out guests without hitting `/account/` walls.
	 */
	public function public_url( int $playlist_id, string $visibility, string $share_token = '' ): string {
		if ( $playlist_id < 1 || self::VISIBILITY_PRIVATE === $visibility ) {
			return '';
		}
		if ( self::VISIBILITY_UNLISTED === $visibility && '' === $share_token ) {
			return '';
		}

		$base = '/playlists/';
		if ( function_exists( 'get_page_by_path' ) && function_exists( 'get_permalink' ) ) {
			$page = get_page_by_path( 'playlists' );
			if ( null !== $page ) {
				$permalink = get_permalink( $page );
				if ( is_string( $permalink ) && '' !== $permalink ) {
					$base = $permalink;
				}
			} elseif ( function_exists( 'home_url' ) ) {
				$base = home_url( '/playlists/' );
			}
		} elseif ( function_exists( 'home_url' ) ) {
			$base = home_url( '/playlists/' );
		}

		$args = array( 'mw-playlist' => (string) $playlist_id );
		if ( self::VISIBILITY_UNLISTED === $visibility && '' !== $share_token ) {
			$args['mw-share'] = $share_token;
		}

		return (string) add_query_arg( $args, $base );
	}

	/**
	 * Public projection of a playlist for one viewer, or null when denied.
	 *
	 * @return array<string, mixed>|null
	 */
	public function view( int $playlist_id, int $viewer_id, string $share_token = '' ): ?array {
		$playlist = $this->find( $playlist_id );
		if ( null === $playlist || ! $this->can_view( $playlist, $viewer_id, $share_token ) ) {
			return null;
		}

		$is_owner        = $viewer_id > 0 && (int) $playlist['user_id'] === $viewer_id;
		$items           = $this->items_for_viewer( $playlist_id, $viewer_id, $share_token );
		$effective_token = $is_owner ? (string) $playlist['share_token'] : $this->sanitize_share_token( $share_token );

		return array(
			'id'          => (int) $playlist['id'],
			'title'       => (string) $playlist['title'],
			'visibility'  => (string) $playlist['visibility'],
			'owner'       => $is_owner,
			'author_name' => $this->author_name( (int) $playlist['user_id'] ),
			// The share token is a capability: only the owner ever receives it directly.
			'share_token' => $is_owner ? (string) $playlist['share_token'] : '',
			'share_url'   => $this->public_url( (int) $playlist['id'], (string) $playlist['visibility'], $effective_token ),
			'count'       => count( $items ),
			'items'       => $items,
			'updated_at'  => (int) $playlist['updated_at'],
		);
	}

	/**
	 * Export every playlist owned by one user for the privacy exporter.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function export( int $user_id ): array {
		$exported = array();
		foreach ( $this->for_user( $user_id, self::MAX_PLAYLISTS ) as $playlist ) {
			$release_ids = $this->ordered_ids( (int) $playlist['id'] );
			$exported[]  = array(
				'id'          => (int) $playlist['id'],
				'title'       => (string) $playlist['title'],
				'visibility'  => (string) $playlist['visibility'],
				'release_ids' => $release_ids,
				'created_at'  => (int) $playlist['created_at'],
			);
		}

		return $exported;
	}

	/**
	 * Public playlists for the community page: only `public` visibility, with
	 * viewer-filtered item counts and author display name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function public_playlists( int $limit = 24, int $offset = 0, string $search = '', string $orderby = 'updated_at', int $viewer_id = 0 ): array {
		$search  = $this->sanitize_search( $search );
		$orderby = in_array( $orderby, array( 'updated_at', 'created_at', 'title' ), true ) ? $orderby : 'updated_at';
		$limit   = min( 50, max( 1, $limit ) );
		$offset  = max( 0, $offset );
		$rows    = $this->store->public_playlists( $limit, $offset, $search, $orderby );
		$items   = array();
		foreach ( $rows as $row ) {
			$playlist = $this->normalize( $row );
			if ( null === $playlist ) {
				continue;
			}
			// Public only, but double-check after normalize.
			if ( self::VISIBILITY_PUBLIC !== $playlist['visibility'] ) {
				continue;
			}
			$playlist_id             = (int) $playlist['id'];
			$view_items              = $this->items_for_viewer( $playlist_id, $viewer_id );
			$playlist['count']       = count( $view_items );
			$playlist['author_name'] = $this->author_name( (int) $playlist['user_id'] );
			// Privacy: do not expose numeric author_id in public payload (FIX-14).
			// Use display name + author URL (computed in routes) for linking.
			$playlist['author_url'] = $this->author_url( (int) $playlist['user_id'] );
			// Provide normalized view-like shape for the card.
			$items[] = $playlist;
		}

		return $items;
	}

	public function count_public( string $search = '' ): int {
		return $this->store->count_public( $this->sanitize_search( $search ) );
	}

	/**
	 * Delete every playlist owned by one user.
	 */
	public function erase( int $user_id ): bool {
		return $user_id > 0 && $this->store->delete_for_user( $user_id );
	}

	/**
	 * Drop a deleted release from every playlist referencing it.
	 *
	 * @param int $post_id Deleted post ID.
	 * @return void
	 */
	public function handle_deleted_post( int $post_id ): void {
		// Playlist items only ever reference releases; skip every other post
		// type so unrelated deletions do not hit the playlist tables.
		if ( $post_id > 0 && ReleasePostType::KEY === get_post_type( $post_id ) ) {
			$this->store->purge_release( $post_id );
		}
	}

	/**
	 * Remove playlists belonging to a deleted user account.
	 *
	 * @param int $user_id Deleted user ID.
	 * @return void
	 */
	public function handle_deleted_user( int $user_id ): void {
		$this->erase( $user_id );
	}

	/**
	 * Whether a release already lives in a playlist (raw, not viewer-filtered).
	 */
	public function contains( int $playlist_id, int $release_id ): bool {
		if ( $playlist_id < 1 || $release_id < 1 ) {
			return false;
		}

		return in_array( $release_id, $this->ordered_ids( $playlist_id ), true );
	}

	/**
	 * Number of stored items in a playlist.
	 */
	public function count_items( int $playlist_id ): int {
		if ( $playlist_id < 1 ) {
			return 0;
		}

		return count( $this->ordered_ids( $playlist_id ) );
	}

	/**
	 * Ordered stored release IDs of one playlist.
	 *
	 * @return array<int, int>
	 */
	private function ordered_ids( int $playlist_id ): array {
		$ids = array();
		foreach ( $this->store->items( $playlist_id ) as $item ) {
			$release_id = (int) $item['release_id'];
			if ( $release_id > 0 && ! in_array( $release_id, $ids, true ) ) {
				$ids[] = $release_id;
			}
		}

		return $ids;
	}

	/**
	 * Return the playlist only when the given user owns it.
	 *
	 * @return array<string, mixed>|null
	 */
	private function owned( int $user_id, int $playlist_id ): ?array {
		if ( $user_id < 1 || $playlist_id < 1 ) {
			return null;
		}

		$playlist = $this->find( $playlist_id );
		if ( null === $playlist || (int) $playlist['user_id'] !== $user_id ) {
			return null;
		}

		return $playlist;
	}

	/**
	 * @param array<string, mixed> $row Raw stored row.
	 * @return array<string, mixed>|null
	 */
	private function normalize( array $row ): ?array {
		$id      = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
		$user_id = isset( $row['user_id'] ) ? absint( $row['user_id'] ) : 0;
		if ( $id < 1 || $user_id < 1 ) {
			return null;
		}

		return array(
			'id'          => $id,
			'user_id'     => $user_id,
			'title'       => $this->sanitize_title( isset( $row['title'] ) ? (string) $row['title'] : '' ),
			'visibility'  => $this->sanitize_visibility( isset( $row['visibility'] ) ? (string) $row['visibility'] : '' ),
			'share_token' => $this->sanitize_share_token( isset( $row['share_token'] ) ? (string) $row['share_token'] : '' ),
			'created_at'  => isset( $row['created_at'] ) ? absint( $row['created_at'] ) : 0,
			'updated_at'  => isset( $row['updated_at'] ) ? absint( $row['updated_at'] ) : 0,
		);
	}

	private function sanitize_search( string $search ): string {
		$search = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $search ) : trim( strip_tags( $search ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$search = trim( $search );
		if ( '' === $search ) {
			return '';
		}
		$search = preg_replace( '/\s+/', ' ', $search );
		$search = is_string( $search ) ? $search : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $search, 0, 60 ) : substr( $search, 0, 60 );
	}

	private function author_name( int $user_id ): string {
		$user = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;
		if ( $user && isset( $user->display_name ) && is_string( $user->display_name ) && '' !== trim( $user->display_name ) ) {
			return trim( $user->display_name );
		}
		if ( $user && isset( $user->user_login ) && is_string( $user->user_login ) ) {
			return $user->user_login;
		}
		return __( 'شنونده MusicWave', 'music-wave-core' );
	}

	private function author_url( int $user_id ): string {
		if ( $user_id < 1 ) {
			return '';
		}
		$url = function_exists( 'get_author_posts_url' ) ? get_author_posts_url( $user_id ) : '';
		return is_string( $url ) ? $url : '';
	}

	private function sanitize_title( string $title ): string {
		$title = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $title ) : trim( strip_tags( $title ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$title = trim( $title );

		return function_exists( 'mb_substr' ) ? mb_substr( $title, 0, self::MAX_TITLE ) : substr( $title, 0, self::MAX_TITLE );
	}

	private function sanitize_visibility( string $visibility ): string {
		$visibility = sanitize_key( $visibility );

		return in_array( $visibility, $this->visibilities(), true ) ? $visibility : self::VISIBILITY_PRIVATE;
	}

	private function sanitize_share_token( string $share_token ): string {
		$share_token = preg_replace( '/[^A-Za-z0-9]/', '', $share_token );
		$share_token = is_string( $share_token ) ? $share_token : '';

		return strlen( $share_token ) >= 24 ? substr( $share_token, 0, 64 ) : '';
	}

	private function generate_share_token(): string {
		if ( function_exists( 'wp_generate_password' ) ) {
			$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_generate_password( 40, false, false ) );
			if ( is_string( $token ) && strlen( $token ) >= 32 ) {
				return substr( $token, 0, 32 );
			}
		}

		return bin2hex( random_bytes( 16 ) );
	}
}
