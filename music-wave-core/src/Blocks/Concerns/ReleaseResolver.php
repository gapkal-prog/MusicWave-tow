<?php
/**
 * Shared release ID resolution across all MusicWave dynamic blocks (SOLID SRP + DRY).
 *
 * Resolution precedence:
 * 1. Explicit `releaseId` block attribute.
 * 2. Block `postId` context (`WP_Block` inside Query Loops or Single Release editor).
 * 3. Integer `$_GET['post_id']` in Block Editor REST `ServerSideRender` calls.
 * 4. Singular / global `mw_release` post.
 * 5. Automatic latest published `mw_release` fallback inside Editor context so
 *    templates (`single-mw_release.html`, `page.html`, etc.) and patterns preview
 *    real site catalog data out of the box with zero configuration or placeholders.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks\Concerns;

use ManaCore\MusicWave\Core\Blocks\BlockSupport;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleaseResolver {
	/**
	 * Resolve target `mw_release` post ID for a dynamic block render.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param object|null          $block      Optional WP_Block context instance.
	 */
	public static function resolve( array $attributes, $block = null ): int {
		$sample = EditorPreviewCatalog::requested_sample_id();
		if ( $sample > 0 && self::is_release( $sample, true ) ) {
			return $sample;
		}

		$contextual = self::resolve_explicit_or_contextual( $attributes, $block );
		if ( $contextual > 0 ) {
			return $contextual;
		}

		if ( BlockSupport::is_editor_context() ) {
			return self::latest_release_id();
		}

		return 0;
	}

	/**
	 * Resolve release ID specifically for `collection-list`:
	 * 1. Editor preview sample override (`mw_preview_sample`) when chosen in Inspector.
	 * 2. Explicit / contextual `mw_release` post ID when present.
	 * 3. In Editor context when unbound (e.g. Site Editor `single-mw_release.html`),
	 *    prefer the latest published album/collection that actually has tracks in
	 *    `mw_collection_items` (or `post_parent`), falling back to `latest_release_id()`.
	 *
	 * @param array<string, mixed>   $attributes Block attributes.
	 * @param object|null            $block      Optional WP_Block context instance.
	 * @param ReleaseRepository|null $repository Release repository for collection lookups.
	 */
	public static function resolve_collection(
		array $attributes,
		$block = null,
		?ReleaseRepository $repository = null
	): int {
		$sample = EditorPreviewCatalog::requested_sample_id();
		if ( $sample > 0 && self::is_release( $sample, true ) ) {
			return $sample;
		}

		$contextual = self::resolve_explicit_or_contextual( $attributes, $block );
		if ( $contextual > 0 ) {
			return $contextual;
		}

		if ( ! BlockSupport::is_editor_context() ) {
			return 0;
		}

		if ( null !== $repository && function_exists( 'get_posts' ) ) {
			$candidates = get_posts(
				array(
					'post_type'      => ReleasePostType::KEY,
					'post_status'    => 'publish',
					'posts_per_page' => 25,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			if ( is_array( $candidates ) ) {
				foreach ( $candidates as $candidate_id ) {
					$id = absint( $candidate_id );
					if ( $id > 0 && ! empty( $repository->collection_items( $id ) ) ) {
						return $id;
					}
				}
			}
		}

		return self::latest_release_id();
	}

	/**
	 * Resolve only explicit/contextual release IDs without falling back to the catalog latest release.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param object|null          $block      Optional WP_Block context instance.
	 */
	public static function resolve_explicit_or_contextual( array $attributes, $block = null ): int {
		$is_editor = BlockSupport::is_editor_context();
		$explicit  = isset( $attributes['releaseId'] ) ? absint( $attributes['releaseId'] ) : 0;
		if ( $explicit > 0 && self::is_release( $explicit, $is_editor ) ) {
			return $explicit;
		}

		if ( is_object( $block ) && isset( $block->context ) && is_array( $block->context ) ) {
			$post_type = isset( $block->context['postType'] ) ? (string) $block->context['postType'] : '';
			$post_id   = isset( $block->context['postId'] ) && is_numeric( $block->context['postId'] )
				? absint( $block->context['postId'] )
				: 0;
			if ( ReleasePostType::KEY === $post_type && $post_id > 0 && self::is_release( $post_id, $is_editor ) ) {
				return $post_id;
			}
		}

		if ( $is_editor ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only SSR editor post context.
			$raw_query_id = isset( $_GET['post_id'] ) && is_scalar( $_GET['post_id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['post_id'] ) ) : '';
			if ( '' !== $raw_query_id && ctype_digit( $raw_query_id ) ) {
				$query_id = absint( $raw_query_id );
				if ( $query_id > 0 && self::is_release( $query_id, true ) ) {
					return $query_id;
				}
			}
		}

		if ( function_exists( 'is_singular' ) && is_singular( ReleasePostType::KEY ) ) {
			$singular_id = absint( get_queried_object_id() );
			if ( $singular_id > 0 && self::is_release( $singular_id, $is_editor ) ) {
				return $singular_id;
			}
		}

		if ( function_exists( 'get_post' ) ) {
			$current = get_post();
			if ( $current instanceof \WP_Post ) {
				$current_id = absint( $current->ID );
				if ( $current_id > 0 && self::is_release( $current_id, $is_editor ) ) {
					return $current_id;
				}
			}
		}

		return 0;
	}

	/**
	 * Fetch the latest published `mw_release` post ID in the site catalog (`0` when empty).
	 */
	public static function latest_release_id(): int {
		if ( ! function_exists( 'get_posts' ) ) {
			return 0;
		}

		$posts = get_posts(
			array(
				'post_type'              => ReleasePostType::KEY,
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		if ( ! is_array( $posts ) || empty( $posts ) ) {
			return 0;
		}

		$first = reset( $posts );
		$id    = is_object( $first ) && isset( $first->ID ) ? absint( $first->ID ) : absint( $first );

		return $id > 0 && self::is_release( $id, false ) ? $id : 0;
	}

	/**
	 * Validate that `$post_id` belongs to `mw_release` with an acceptable status.
	 *
	 * On the public frontend only `publish` releases resolve; inside Editor context,
	 * `draft`, `pending`, `private`, `future`, and `auto-draft` releases also resolve
	 * so authors editing a brand-new release see live previews immediately.
	 */
	public static function is_release( int $post_id, bool $allow_unpublished = false ): bool {
		if ( $post_id < 1 ) {
			return false;
		}

		$type = get_post_type( $post_id );
		if ( ReleasePostType::KEY !== $type ) {
			return false;
		}

		if ( function_exists( 'get_post_status' ) ) {
			$status = get_post_status( $post_id );
			if ( false === $status || 'trash' === $status ) {
				return false;
			}
			if ( ! $allow_unpublished && 'publish' !== $status ) {
				return false;
			}
		}

		return true;
	}
}
