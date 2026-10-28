<?php
/**
 * Request-scoped batched taxonomy index for release lists.
 *
 * List surfaces (library, facets, shelves, related releases, recommendations)
 * used to call `wp_get_post_terms()` once per release *per taxonomy*, which is
 * an N+1 pattern that grows with catalog and library size. This index resolves
 * a whole set of releases in one `wp_get_object_terms()` call and memoizes it
 * for the rest of the request (PROJECT_PLAN.md Stage 5 deliverable 7).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Catalog;

use WP_Term;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleaseTermIndex {
	/** @var array<string, array<int, array<int, WP_Term>>> taxonomy => release ID => terms */
	private $cache = array();

	/**
	 * Resolve every requested release/taxonomy pair in one batched query.
	 *
	 * @param array<int, int>    $release_ids Release IDs.
	 * @param array<int, string> $taxonomies  Taxonomy names.
	 * @return void
	 */
	public function prime( array $release_ids, array $taxonomies ): void {
		$taxonomies = array_values( array_unique( array_filter( array_map( 'strval', $taxonomies ) ) ) );
		if ( array() === $taxonomies ) {
			return;
		}

		$pending = array();
		foreach ( $release_ids as $release_id ) {
			$release_id = absint( $release_id );
			if ( $release_id < 1 ) {
				continue;
			}
			foreach ( $taxonomies as $taxonomy ) {
				if ( ! isset( $this->cache[ $taxonomy ][ $release_id ] ) && ! in_array( $release_id, $pending, true ) ) {
					$pending[] = $release_id;
				}
			}
		}
		if ( array() === $pending ) {
			return;
		}

		// Reserve the slots first so a taxonomy with no terms is still treated
		// as resolved and never re-queried.
		foreach ( $taxonomies as $taxonomy ) {
			foreach ( $pending as $release_id ) {
				if ( ! isset( $this->cache[ $taxonomy ][ $release_id ] ) ) {
					$this->cache[ $taxonomy ][ $release_id ] = array();
				}
			}
		}

		if ( ! function_exists( 'wp_get_object_terms' ) ) {
			$this->prime_individually( $pending, $taxonomies );

			return;
		}

		$terms = wp_get_object_terms( $pending, $taxonomies, array( 'fields' => 'all_with_object_id' ) );
		if ( ! is_array( $terms ) ) {
			return;
		}

		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term || ! isset( $term->object_id ) ) {
				continue;
			}
			$release_id = absint( $term->object_id );
			$taxonomy   = (string) $term->taxonomy;
			if ( $release_id < 1 || ! in_array( $taxonomy, $taxonomies, true ) ) {
				continue;
			}
			$this->cache[ $taxonomy ][ $release_id ][] = $term;
		}
	}

	/**
	 * Terms bound to one release, resolving lazily when not primed.
	 *
	 * @return array<int, WP_Term>
	 */
	public function terms( int $release_id, string $taxonomy ): array {
		$release_id = absint( $release_id );
		if ( $release_id < 1 || '' === $taxonomy ) {
			return array();
		}
		if ( ! isset( $this->cache[ $taxonomy ][ $release_id ] ) ) {
			$this->prime( array( $release_id ), array( $taxonomy ) );
		}

		return isset( $this->cache[ $taxonomy ][ $release_id ] ) ? $this->cache[ $taxonomy ][ $release_id ] : array();
	}

	/**
	 * Term slugs bound to one release.
	 *
	 * @return array<int, string>
	 */
	public function slugs( int $release_id, string $taxonomy ): array {
		$slugs = array();
		foreach ( $this->terms( $release_id, $taxonomy ) as $term ) {
			if ( '' !== (string) $term->slug ) {
				$slugs[] = (string) $term->slug;
			}
		}

		return $slugs;
	}

	/**
	 * Term names bound to one release.
	 *
	 * @return array<int, string>
	 */
	public function names( int $release_id, string $taxonomy ): array {
		$names = array();
		foreach ( $this->terms( $release_id, $taxonomy ) as $term ) {
			if ( '' !== (string) $term->name ) {
				$names[] = (string) $term->name;
			}
		}

		return $names;
	}

	/**
	 * Term IDs bound to one release.
	 *
	 * @return array<int, int>
	 */
	public function ids( int $release_id, string $taxonomy ): array {
		$ids = array();
		foreach ( $this->terms( $release_id, $taxonomy ) as $term ) {
			$term_id = absint( $term->term_id );
			if ( $term_id > 0 ) {
				$ids[] = $term_id;
			}
		}

		return $ids;
	}

	/**
	 * Drop the memoized index (used after catalog writes within one request).
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->cache = array();
	}

	/**
	 * Fallback path for environments without `wp_get_object_terms()`.
	 *
	 * @param array<int, int>    $release_ids Release IDs.
	 * @param array<int, string> $taxonomies  Taxonomy names.
	 * @return void
	 */
	private function prime_individually( array $release_ids, array $taxonomies ): void {
		if ( ! function_exists( 'wp_get_post_terms' ) ) {
			return;
		}

		foreach ( $taxonomies as $taxonomy ) {
			foreach ( $release_ids as $release_id ) {
				$terms = wp_get_post_terms( $release_id, $taxonomy, array( 'fields' => 'all' ) );
				foreach ( is_array( $terms ) ? $terms : array() as $term ) {
					if ( $term instanceof WP_Term ) {
						$this->cache[ $taxonomy ][ $release_id ][] = $term;
					}
				}
			}
		}
	}
}
