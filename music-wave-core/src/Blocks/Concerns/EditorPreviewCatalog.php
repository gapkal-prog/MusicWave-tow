<?php
/**
 * Dynamic Editor Preview Sample Discovery & Real-Catalog Projection Service.
 *
 * Clean Architecture / OOP / DRY / SOLID:
 * - Single Responsibility: discovers real entities (`mw_release` albums & tracks,
 *   `mw_artist` / `mw_genre` / `mw_mood` / `mw_label` terms, and `PlaylistRepository`
 *   playlists) so editors can switch live preview samples inside Gutenberg & Site
 *   Editor without saving hardcoded IDs into frontend templates.
 * - Zero Duplicate Renderers: projects real site data directly into the canonical
 *   block render pipelines (`render_real_track_row`, `download_panel_markup`,
 *   `public_card_markup`, `render_profile_card`, etc.) so Editor and Frontend HTML
 *   never diverge.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks\Concerns;

use ManaCore\MusicWave\Core\Blocks\BlockSupport;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use ManaCore\MusicWave\Core\Playlists\PlaylistRepository;
use WP_Post;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EditorPreviewCatalog {
	public const SAMPLE_QUERY_ARG = 'mw_preview_sample';
	public const SEARCH_QUERY_ARG = 'mw_preview_query';

	/**
	 * Read the editor-only preview sample ID selected in Gutenberg Inspector (`0` = automatic).
	 */
	public static function requested_sample_id(): int {
		if ( ! BlockSupport::is_editor_context() ) {
			return 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only Block Editor SSR query parameter.
		$raw = isset( $_GET[ self::SAMPLE_QUERY_ARG ] ) && is_scalar( $_GET[ self::SAMPLE_QUERY_ARG ] )
			? sanitize_text_field( wp_unslash( (string) $_GET[ self::SAMPLE_QUERY_ARG ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

		if ( '' === $raw || ! ctype_digit( $raw ) ) {
			return 0;
		}

		return absint( $raw );
	}

	/**
	 * Read the editor-only preview search query selected in Gutenberg Inspector (`""` = automatic).
	 */
	public static function requested_sample_query(): string {
		if ( ! BlockSupport::is_editor_context() ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only Block Editor SSR query parameter.
		$raw = isset( $_GET[ self::SEARCH_QUERY_ARG ] ) && is_scalar( $_GET[ self::SEARCH_QUERY_ARG ] )
			? sanitize_text_field( wp_unslash( (string) $_GET[ self::SEARCH_QUERY_ARG ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

		return trim( $raw );
	}

	/**
	 * Build localized preview entity options for Gutenberg / Site Editor Inspector controls.
	 *
	 * @return array<string, mixed>
	 */
	public static function editor_choices(
		?ReleaseRepository $release_repo = null,
		?PlaylistRepository $playlist_repo = null
	): array {
		try {
			$releases = self::release_choices( $release_repo );
		} catch ( \Throwable $e ) {
			$releases = array();
		}
		try {
			$artists = self::term_choices( array( 'mw_artist' ) );
			$terms   = self::term_choices( array( 'mw_artist', 'mw_genre', 'mw_mood', 'mw_label' ) );
		} catch ( \Throwable $e ) {
			$artists = array();
			$terms   = array();
		}
		try {
			$playlists = self::playlist_choices( $playlist_repo );
		} catch ( \Throwable $e ) {
			$playlists = array();
		}
		try {
			$queries = self::search_query_choices();
		} catch ( \Throwable $e ) {
			$queries = array();
		}

		return array(
			'releases'  => $releases,
			'artists'   => $artists,
			'terms'     => $terms,
			'playlists' => $playlists,
			'queries'   => $queries,
			'icons'     => ActionButtonStyle::preset_icons(),
		);
	}

	/**
	 * Return published `mw_release` posts from the site catalog so Editor previews
	 * render real site releases through the single canonical row renderer.
	 *
	 * @return array<int, WP_Post>
	 */
	public static function published_catalog_tracks( int $limit = 6 ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => ReleasePostType::KEY,
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 12, $limit ) ),
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		if ( empty( $posts ) ) {
			$posts = get_posts(
				array(
					'post_type'      => ReleasePostType::KEY,
					'post_status'    => array( 'draft', 'private' ),
					'posts_per_page' => max( 1, min( 12, $limit ) ),
					'orderby'        => 'date',
					'order'          => 'DESC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
		}
		if ( ! is_array( $posts ) ) {
			return array();
		}

		$valid = array();
		foreach ( $posts as $item ) {
			$post = $item instanceof WP_Post
				? $item
				: ( is_numeric( $item ) && function_exists( 'get_post' ) ? get_post( absint( $item ) ) : null );
			if ( $post instanceof WP_Post ) {
				$valid[] = $post;
			}
		}

		return $valid;
	}

	/**
	 * Resolve normalized playlist rows for `music-wave/public-playlists` in Editor context
	 * so the block previews real playlists or real published site releases via `public_card_markup()`.
	 *
	 * @param PlaylistRepository $repository Playlist repository.
	 * @param int                $limit      Max playlists to display.
	 * @param int                $viewer_id  Active editor user ID.
	 * @param int                $sample_id  Optional preview playlist ID chosen in Inspector.
	 * @return array<int, array<string, mixed>>
	 */
	public static function resolve_public_playlists_for_editor(
		PlaylistRepository $repository,
		int $limit,
		int $viewer_id,
		int $sample_id = 0
	): array {
		if ( $sample_id > 0 ) {
			$picked = $repository->find( $sample_id );
			if ( is_array( $picked ) ) {
				return array( self::normalize_playlist_for_card( $picked, $repository, $viewer_id ) );
			}
		}

		$public = $repository->public_playlists( $limit, 0, '', 'updated_at', $viewer_id );
		if ( ! empty( $public ) ) {
			return $public;
		}

		if ( $viewer_id > 0 ) {
			$owned = $repository->for_user( $viewer_id, min( $limit, PlaylistRepository::MAX_PLAYLISTS ) );
			if ( ! empty( $owned ) ) {
				$normalized = array();
				foreach ( array_slice( $owned, 0, $limit ) as $row ) {
					if ( is_array( $row ) ) {
						$normalized[] = self::normalize_playlist_for_card( $row, $repository, $viewer_id );
					}
				}
				if ( ! empty( $normalized ) ) {
					return $normalized;
				}
			}
		}

		$catalog_posts = self::published_catalog_tracks( 8 );
		if ( empty( $catalog_posts ) ) {
			return array();
		}

		$chunks      = array_chunk( $catalog_posts, 4 );
		$author_name = self::default_editor_author_name( $viewer_id );
		$projected   = array();

		foreach ( array_slice( $chunks, 0, min( 3, $limit ) ) as $index => $group ) {
			$ids = array();
			foreach ( $group as $post ) {
				$ids[] = (int) $post->ID;
			}
			$lead_title = (string) get_the_title( $group[0]->ID );
			$card_title = 0 === $index
				? sprintf(
					/* translators: %s: real release title from catalog. */
					__( 'منتخب کاتالوگ — %s', 'music-wave-core' ),
					'' !== $lead_title ? $lead_title : __( 'تازه‌ها', 'music-wave-core' )
				)
				: sprintf(
					/* translators: %s: localized collection index. */
					__( 'گلچین آثار منتشرشده %s', 'music-wave-core' ),
					number_format_i18n( $index + 1 )
				);
			$projected[] = array(
				'id'          => 0,
				'title'       => $card_title,
				'author_name' => $author_name,
				'count'       => count( $ids ),
				'updated_at'  => time(),
				'release_ids' => $ids,
			);
		}

		return $projected;
	}

	/**
	 * Build `releases` dropdown choices for Gutenberg Inspector.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function release_choices( ?ReleaseRepository $release_repo ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'        => ReleasePostType::KEY,
				'post_status'      => 'publish',
				'posts_per_page'   => 40,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		if ( empty( $posts ) ) {
			$posts = get_posts(
				array(
					'post_type'        => ReleasePostType::KEY,
					'post_status'      => array( 'draft', 'private', 'pending', 'future' ),
					'posts_per_page'   => 40,
					'orderby'          => 'date',
					'order'            => 'DESC',
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			);
		}
		if ( ! is_array( $posts ) ) {
			return array();
		}

		if ( function_exists( 'update_meta_cache' ) ) {
			$ids_to_prime = array();
			foreach ( $posts as $raw ) {
				$pid = $raw instanceof WP_Post ? (int) $raw->ID : ( is_numeric( $raw ) ? absint( $raw ) : 0 );
				if ( $pid > 0 ) {
					$ids_to_prime[] = $pid;
				}
			}
			if ( ! empty( $ids_to_prime ) ) {
				update_meta_cache( 'post', $ids_to_prime );
			}
		}

		$choices = array();
		foreach ( $posts as $item ) {
			$post = $item instanceof WP_Post
				? $item
				: ( is_numeric( $item ) && function_exists( 'get_post' ) ? get_post( absint( $item ) ) : null );
			if ( ! ( $post instanceof WP_Post ) ) {
				continue;
			}
			$id            = (int) $post->ID;
			$title         = trim( (string) get_the_title( $id ) );
			$title         = '' !== $title ? $title : '#' . $id;
			$is_collection = null !== $release_repo && ! empty( $release_repo->collection_items( $id ) );
			$badge         = $is_collection
				? __( 'آلبوم / مجموعه', 'music-wave-core' )
				: __( 'انتشار', 'music-wave-core' );

			$choices[] = array(
				'id'           => $id,
				'title'        => $title . ' (' . $badge . ')',
				'isCollection' => $is_collection,
			);
		}

		return $choices;
	}

	/**
	 * Build taxonomy term dropdown choices (`mw_artist`, `mw_genre`, `mw_mood`, `mw_label`).
	 *
	 * @param array<int, string> $taxonomies Taxonomy slugs.
	 * @return array<int, array<string, mixed>>
	 */
	private static function term_choices( array $taxonomies ): array {
		if ( ! function_exists( 'get_terms' ) ) {
			return array();
		}

		$tax_labels = array(
			'mw_artist' => __( 'هنرمند', 'music-wave-core' ),
			'mw_genre'  => __( 'سبک', 'music-wave-core' ),
			'mw_mood'   => __( 'حال‌وهوا', 'music-wave-core' ),
			'mw_label'  => __( 'ناشر', 'music-wave-core' ),
		);

		$choices = array();
		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 25,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);
			if ( ! is_array( $terms ) ) {
				continue;
			}
			$show_tax_badge = count( $taxonomies ) > 1;
			foreach ( $terms as $term ) {
				if ( ! ( $term instanceof WP_Term ) ) {
					continue;
				}
				$suffix    = $show_tax_badge && isset( $tax_labels[ $term->taxonomy ] )
					? ' (' . $tax_labels[ $term->taxonomy ] . ')'
					: '';
				$choices[] = array(
					'id'       => (int) $term->term_id,
					'name'     => (string) $term->name . $suffix,
					'taxonomy' => (string) $term->taxonomy,
				);
			}
		}

		return $choices;
	}

	/**
	 * Build playlist dropdown choices for Gutenberg Inspector.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function playlist_choices( ?PlaylistRepository $playlist_repo ): array {
		if ( null === $playlist_repo ) {
			return array();
		}

		$seen    = array();
		$choices = array();
		$viewer  = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		foreach ( $playlist_repo->public_playlists( 25, 0, '', 'updated_at', $viewer ) as $pl ) {
			$pid = isset( $pl['id'] ) ? (int) $pl['id'] : 0;
			if ( $pid <= 0 || isset( $seen[ $pid ] ) ) {
				continue;
			}
			$seen[ $pid ] = true;
			$choices[]    = array(
				'id'    => $pid,
				'title' => (string) ( $pl['title'] ?? ( '#' . $pid ) ),
			);
		}

		if ( $viewer > 0 ) {
			foreach ( $playlist_repo->for_user( $viewer, PlaylistRepository::MAX_PLAYLISTS ) as $pl ) {
				$pid = isset( $pl['id'] ) ? (int) $pl['id'] : 0;
				if ( $pid <= 0 || isset( $seen[ $pid ] ) ) {
					continue;
				}
				$seen[ $pid ] = true;
				$choices[]    = array(
					'id'    => $pid,
					'title' => (string) ( $pl['title'] ?? ( '#' . $pid ) ) . ' (' . __( 'شخصی', 'music-wave-core' ) . ')',
				);
			}
		}

		return $choices;
	}

	/**
	 * Build sample search queries derived directly from real site artists and releases.
	 *
	 * @return array<int, string>
	 */
	private static function search_query_choices(): array {
		$queries = array();

		if ( function_exists( 'get_terms' ) ) {
			$artists = get_terms(
				array(
					'taxonomy'   => 'mw_artist',
					'hide_empty' => false,
					'number'     => 5,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);
			if ( is_array( $artists ) ) {
				foreach ( $artists as $term ) {
					if ( $term instanceof WP_Term && '' !== trim( $term->name ) ) {
						$queries[] = trim( $term->name );
					}
				}
			}
		}

		foreach ( self::published_catalog_tracks( 5 ) as $post ) {
			$title = trim( (string) get_the_title( $post->ID ) );
			if ( '' !== $title && ! in_array( $title, $queries, true ) ) {
				$queries[] = $title;
			}
		}

		return array_slice( $queries, 0, 8 );
	}

	/**
	 * Ensure any playlist row has `author_name` populated for `public_card_markup()`.
	 *
	 * @param array<string, mixed> $playlist   Raw repository row.
	 * @param PlaylistRepository   $repository Playlist repository.
	 * @param int                  $viewer_id  Viewer user ID.
	 * @return array<string, mixed>
	 */
	private static function normalize_playlist_for_card(
		array $playlist,
		PlaylistRepository $repository,
		int $viewer_id
	): array {
		if ( ! isset( $playlist['author_name'] ) || '' === (string) $playlist['author_name'] ) {
			$owner_id                = isset( $playlist['user_id'] ) ? (int) $playlist['user_id'] : $viewer_id;
			$playlist['author_name'] = self::default_editor_author_name( $owner_id );
		}
		if ( ! isset( $playlist['count'] ) ) {
			$pid               = isset( $playlist['id'] ) ? (int) $playlist['id'] : 0;
			$items             = $pid > 0 ? $repository->items_for_viewer( $pid, $viewer_id ) : array();
			$playlist['count'] = count( $items );
		}
		return $playlist;
	}

	/**
	 * Resolve display name for a WordPress user cleanly.
	 */
	private static function default_editor_author_name( int $user_id ): string {
		if ( $user_id > 0 && function_exists( 'get_userdata' ) ) {
			$user = get_userdata( $user_id );
			if ( is_object( $user ) && ! empty( $user->display_name ) ) {
				return (string) $user->display_name;
			}
		}
		return (string) get_bloginfo( 'name' );
	}
}
