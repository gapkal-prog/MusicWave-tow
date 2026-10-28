<?php
/**
 * Bounded catalog autocomplete and facet counts.
 *
 * Both surfaces are public, so they are deliberately cheap and defensive:
 * short terms are refused, every query is bounded and cached, results pass the
 * shared release visibility policy, and no private metadata, entitlement, or
 * user data is ever read. An optional external adapter may propose candidates
 * but never bypasses the visibility policy
 * (PROJECT_PLAN.md Stage 5 deliverable 6).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Discovery;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Catalog\ReleaseTermIndex;
use ManaCore\MusicWave\Core\Catalog\ReleaseVisibility;
use WP_Term;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CatalogSearch {
	public const MIN_TERM_LENGTH = 2;
	public const MAX_TERM_LENGTH = 60;
	public const MAX_SUGGESTIONS = 10;
	public const MAX_TERM_HITS   = 4;
	public const CACHE_TTL       = 300;

	public const FACET_SCAN_LIMIT  = 200;
	public const MAX_FACET_TERMS   = 20;
	public const MAX_FILTER_VALUES = 5;

	public const TYPE_RELEASE = 'release';

	/** @var ReleaseVisibility */
	private $visibility;

	/** @var CatalogSearchAdapter|null Explicit adapter; otherwise resolved by filter. */
	private $adapter;

	/** @var ReleaseTermIndex */
	private $term_index;

	public function __construct( ?ReleaseVisibility $visibility = null, ?CatalogSearchAdapter $adapter = null, ?ReleaseTermIndex $term_index = null ) {
		$this->visibility = null !== $visibility ? $visibility : new ReleaseVisibility();
		$this->adapter    = $adapter;
		$this->term_index = null !== $term_index ? $term_index : new ReleaseTermIndex();
	}

	/**
	 * Taxonomies exposed as catalog facets and suggestion groups.
	 *
	 * @return array<int, string>
	 */
	public function taxonomies(): array {
		return array( 'mw_artist', 'mw_genre', 'mw_mood', 'mw_release_type' );
	}

	/**
	 * Autocomplete suggestions for one term.
	 *
	 * @return array<int, array<string, string|int>>
	 */
	public function suggest( string $term, int $limit = 8 ): array {
		$term  = $this->sanitize_term( $term );
		$limit = min( self::MAX_SUGGESTIONS, max( 1, $limit ) );
		if ( '' === $term ) {
			return array();
		}

		$cache_key = 'mw_catalog_suggest_' . md5( strtolower( $term ) . '|' . $limit );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$suggestions = array();
		foreach ( $this->release_candidates( $term, $limit ) as $release_id ) {
			if ( count( $suggestions ) >= $limit ) {
				break;
			}
			$suggestions[] = array(
				'type'  => self::TYPE_RELEASE,
				'id'    => $release_id,
				'label' => get_the_title( $release_id ),
				'url'   => (string) get_permalink( $release_id ),
			);
		}

		foreach ( $this->term_candidates( $term ) as $suggestion ) {
			if ( count( $suggestions ) >= $limit ) {
				break;
			}
			$suggestions[] = $suggestion;
		}

		/**
		 * Filter catalog autocomplete suggestions.
		 *
		 * Integrations must keep suggestions public: only published releases
		 * and public taxonomy terms may appear.
		 *
		 * @param array<int, array<string, string|int>> $suggestions Suggestions.
		 * @param string                                $term        Sanitized term.
		 */
		$filtered    = apply_filters( 'music_wave_catalog_suggestions', $suggestions, $term );
		$suggestions = is_array( $filtered ) ? array_slice( $filtered, 0, $limit ) : $suggestions;

		set_transient( $cache_key, $suggestions, $this->cache_ttl() );

		return $suggestions;
	}

	/**
	 * Facet counts for the current filter selection.
	 *
	 * Counts are computed from a bounded scan of the filtered result set, so a
	 * huge catalog degrades to an explicitly flagged approximation instead of
	 * an unbounded query.
	 *
	 * @param array<string, mixed> $filters Taxonomy slug filters.
	 * @return array{filters: array<string, array<int, string>>, facets: array<string, array<int, array<string, string|int>>>, matched: int, approximate: bool}
	 */
	public function facets( array $filters, int $per_taxonomy = self::MAX_FACET_TERMS ): array {
		$filters      = $this->sanitize_filters( $filters );
		$per_taxonomy = min( self::MAX_FACET_TERMS, max( 1, $per_taxonomy ) );
		$cache_key    = 'mw_catalog_facets_' . md5( $this->filter_signature( $filters ) . '|' . $per_taxonomy );
		$cached       = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['facets'] ) ) {
			/** @var array{filters: array<string, array<int, string>>, facets: array<string, array<int, array<string, string|int>>>, matched: int, approximate: bool} $cached */
			return $cached;
		}

		$release_ids = $this->filtered_release_ids( $filters );
		// One batched taxonomy query for the whole scanned set instead of one
		// query per release per taxonomy.
		$this->term_index->prime( $release_ids, $this->taxonomies() );
		$counts = array();
		foreach ( $this->taxonomies() as $taxonomy ) {
			$counts[ $taxonomy ] = array();
		}

		foreach ( $release_ids as $release_id ) {
			foreach ( $this->taxonomies() as $taxonomy ) {
				foreach ( $this->slugs_and_names( $release_id, $taxonomy ) as $slug => $name ) {
					if ( ! isset( $counts[ $taxonomy ][ $slug ] ) ) {
						$counts[ $taxonomy ][ $slug ] = array(
							'slug'  => (string) $slug,
							'label' => $name,
							'count' => 0,
						);
					}
					++$counts[ $taxonomy ][ $slug ]['count'];
				}
			}
		}

		$facets = array();
		foreach ( $counts as $taxonomy => $terms ) {
			usort(
				$terms,
				static function ( array $left, array $right ): int {
					if ( $left['count'] === $right['count'] ) {
						return strcmp( (string) $left['slug'], (string) $right['slug'] );
					}

					return $right['count'] <=> $left['count'];
				}
			);
			$facets[ $taxonomy ] = array_slice( $terms, 0, $per_taxonomy );
		}

		$payload = array(
			'filters'     => $filters,
			'facets'      => $facets,
			'matched'     => count( $release_ids ),
			'approximate' => count( $release_ids ) >= self::FACET_SCAN_LIMIT,
		);

		set_transient( $cache_key, $payload, $this->cache_ttl() );

		return $payload;
	}

	/**
	 * Normalize a raw search term, refusing anything too short to be useful.
	 */
	public function sanitize_term( string $term ): string {
		$term = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $term ) : trim( strip_tags( $term ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		// Fold script variants (Arabic/Farsi ي/ی, ك/ک, digits, Arabic and
		// Latin diacritics, kashida) so the cache key, the adapter and the
		// re-checks all see one canonical form while spacing/ZWNJ stays
		// intact for the query expansion.
		$term = PersianSearchNormalizer::fold( $term );
		$term = trim( preg_replace( '/\s+/u', ' ', $term ) ?? '' );
		$term = function_exists( 'mb_substr' ) ? mb_substr( $term, 0, self::MAX_TERM_LENGTH ) : substr( $term, 0, self::MAX_TERM_LENGTH );
		$size = function_exists( 'mb_strlen' ) ? mb_strlen( $term ) : strlen( $term );

		return $size >= self::MIN_TERM_LENGTH ? $term : '';
	}

	/**
	 * Normalize requested facet filters to known taxonomies and term slugs.
	 *
	 * @param array<string, mixed> $filters Raw filters.
	 * @return array<string, array<int, string>>
	 */
	public function sanitize_filters( array $filters ): array {
		$clean = array();
		foreach ( $this->taxonomies() as $taxonomy ) {
			if ( ! isset( $filters[ $taxonomy ] ) ) {
				continue;
			}
			$values = is_array( $filters[ $taxonomy ] ) ? $filters[ $taxonomy ] : explode( ',', (string) $filters[ $taxonomy ] );
			$slugs  = array();
			foreach ( $values as $value ) {
				$slug = is_scalar( $value ) ? sanitize_title( (string) $value ) : '';
				if ( '' !== $slug && ! in_array( $slug, $slugs, true ) ) {
					$slugs[] = $slug;
				}
				if ( count( $slugs ) >= self::MAX_FILTER_VALUES ) {
					break;
				}
			}
			if ( array() !== $slugs ) {
				$clean[ $taxonomy ] = $slugs;
			}
		}

		return $clean;
	}

	/**
	 * Candidate release IDs for a term: adapter first, native search second.
	 *
	 * @return array<int, int>
	 */
	private function release_candidates( string $term, int $limit ): array {
		$adapter = null !== $this->adapter ? $this->adapter : apply_filters( 'music_wave_catalog_search_adapter', null );
		if ( $adapter instanceof CatalogSearchAdapter ) {
			$proposed = $adapter->search( $term, $limit );
			if ( is_array( $proposed ) ) {
				return $this->publicly_visible( $proposed );
			}
		}

		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'                       => ReleasePostType::KEY,
				'post_status'                     => 'publish',
				'fields'                          => 'ids',
				'posts_per_page'                  => min( 50, $limit * 3 ),
				's'                               => $term,
				'no_found_rows'                   => true,
				'orderby'                         => 'date',
				'order'                           => 'DESC',
				// get_posts() suppresses query filters by default; the
				// script-aware search must run so Persian spellings match.
				'suppress_filters'                => false,
				ScriptAwareSearchQuery::QUERY_VAR => true,
			)
		);

		$matched = array();
		foreach ( $this->publicly_visible( is_array( $ids ) ? $ids : array() ) as $release_id ) {
			// Re-check the term against the public title so the suggestion list
			// stays predictable regardless of the search backend in play.
			if ( PersianSearchNormalizer::contains( get_the_title( $release_id ), $term ) ) {
				$matched[] = $release_id;
			}
		}

		return $matched;
	}

	/**
	 * Taxonomy term suggestions matching the term.
	 *
	 * @return array<int, array<string, string|int>>
	 */
	private function term_candidates( string $term ): array {
		if ( ! function_exists( 'get_terms' ) ) {
			return array();
		}

		// Stored term names may use either script or spacing convention, so
		// each bounded lookup tries the few plausible spellings of the term.
		$spellings = array_slice( PersianSearchNormalizer::variants( $term ), 0, 3 );
		if ( array() === $spellings ) {
			$spellings = array( $term );
		}

		$suggestions = array();
		foreach ( $this->taxonomies() as $taxonomy ) {
			$terms = array();
			foreach ( $spellings as $spelling ) {
				$found_terms = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'search'     => $spelling,
						'number'     => self::MAX_TERM_HITS,
						'hide_empty' => true,
					)
				);
				if ( ! is_array( $found_terms ) ) {
					continue;
				}
				foreach ( $found_terms as $found_term ) {
					if ( $found_term instanceof WP_Term && ! isset( $terms[ (int) $found_term->term_id ] ) ) {
						$terms[ (int) $found_term->term_id ] = $found_term;
					}
				}
			}

			$hits = 0;
			foreach ( $terms as $found ) {
				if ( $hits >= self::MAX_TERM_HITS ) {
					break;
				}
				if ( ! PersianSearchNormalizer::contains( $found->name, $term ) && false === stripos( $found->slug, $term ) ) {
					continue;
				}
				$link          = get_term_link( $found );
				$suggestions[] = array(
					'type'  => str_replace( 'mw_', '', $taxonomy ),
					'id'    => (int) $found->term_id,
					'label' => (string) $found->name,
					'url'   => ! is_wp_error( $link ) && is_string( $link ) ? $link : '',
				);
				++$hits;
			}
		}

		return $suggestions;
	}

	/**
	 * Bounded set of published release IDs matching the active filters.
	 *
	 * @param array<string, array<int, string>> $filters Sanitized filters.
	 * @return array<int, int>
	 */
	private function filtered_release_ids( array $filters ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$args = array(
			'post_type'      => ReleasePostType::KEY,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => self::FACET_SCAN_LIMIT,
			'no_found_rows'  => true,
		);
		if ( array() !== $filters ) {
			$tax_query = array( 'relation' => 'AND' );
			foreach ( $filters as $taxonomy => $slugs ) {
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => $slugs,
				);
			}
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- bounded, cached facet scan.
		}

		$ids = get_posts( $args );

		return $this->publicly_visible( is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Term slug => name map for one release and taxonomy.
	 *
	 * @return array<string, string>
	 */
	private function slugs_and_names( int $release_id, string $taxonomy ): array {
		$map = array();
		foreach ( $this->term_index->terms( $release_id, $taxonomy ) as $term ) {
			if ( '' !== (string) $term->slug ) {
				$map[ (string) $term->slug ] = (string) $term->name;
			}
		}

		return $map;
	}

	/**
	 * Keep only publicly published releases.
	 *
	 * @param array<int, mixed> $ids Candidate IDs.
	 * @return array<int, int>
	 */
	private function publicly_visible( array $ids ): array {
		// One batched post query instead of one per candidate release.
		$this->visibility->prime( $ids );

		$visible = array();
		foreach ( $ids as $release_id ) {
			$release_id = absint( $release_id );
			if ( $release_id > 0 && ! in_array( $release_id, $visible, true ) && $this->visibility->is_public( $release_id ) ) {
				$visible[] = $release_id;
			}
		}

		return $visible;
	}

	/**
	 * Stable cache signature for one sanitized filter set.
	 *
	 * @param array<string, array<int, string>> $filters Sanitized filters.
	 */
	private function filter_signature( array $filters ): string {
		$parts = array();
		foreach ( $filters as $taxonomy => $slugs ) {
			sort( $slugs );
			$parts[] = $taxonomy . '=' . implode( ',', $slugs );
		}
		sort( $parts );

		return implode( '&', $parts );
	}

	private function cache_ttl(): int {
		/**
		 * Filter the catalog discovery cache lifetime in seconds.
		 *
		 * @param int $ttl Cache lifetime (default 300).
		 */
		return max( 30, (int) apply_filters( 'music_wave_catalog_discovery_ttl', self::CACHE_TTL ) );
	}
}
