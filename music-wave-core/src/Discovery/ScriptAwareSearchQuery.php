<?php
/**
 * Script-aware WP_Query search for the catalog.
 *
 * Fixes the "no results" failure for Persian and other non-ASCII content.
 * WordPress core matches `post_title`, `post_excerpt` and `post_content`
 * byte-for-byte, so a phrase typed with Arabic «ي/ك», a space instead of a
 * zero-width non-joiner, a Persian digit or a Latin accent (café/cafe) never
 * matches the stored spelling, and words that only appear as artist/genre/
 * mood term names or release metadata are never searched at all.
 *
 * This hook rewrites the search clause of qualifying queries:
 *
 * - every search word is expanded into its plausible spellings
 *   ({@see PersianSearchNormalizer::variants()}) and matched with OR;
 * - words are still combined with AND, exclusions (`-word`) still exclude;
 * - each word may also match the public catalog term names (artist, genre,
 *   mood, label, release type) or an allow-listed set of release meta keys
 *   through bounded `IN (SELECT …)` sub-queries — no JOIN, so row
 *   multiplication, GROUP BY and other plugins' joins are never touched;
 * - relevance ordering keeps title matches first using the same variants.
 *
 * The rewrite applies to every script — Persian, Arabic, Latin and anything
 * else — on the main query, release-scoped queries and queries opting in
 * through {@see self::QUERY_VAR}. Pure-ASCII words keep a single spelling, so
 * enabling the rewrite for Latin phrases only adds the bounded term/meta
 * sub-queries; core still cannot search metadata at all. Every value passes
 * through `$wpdb->prepare()` / `$wpdb->esc_like()`, and the whole expansion
 * is capped at {@see self::MAX_WORDS} words × {@see self::MAX_VARIANTS}
 * spellings. Queries may opt out through the `music_wave_script_aware_search`
 * filter ({@see self::applies()}).
 *
 * Secondary queries may opt in with {@see self::QUERY_VAR} and narrow or
 * widen the searched term names / meta keys for themselves with
 * {@see self::TAXONOMIES_VAR} and {@see self::META_KEYS_VAR}; a query that
 * supplies meta keys is rewritten for Latin phrases too, because core cannot
 * search meta at all.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Discovery;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ScriptAwareSearchQuery {
	/** Query var that opts a secondary query into the script-aware search. */
	public const QUERY_VAR = 'mw_script_search';

	/**
	 * Query var: meta keys to search for one query only.
	 *
	 * Replaces the default allow-list for that query and forces the rewrite
	 * (core cannot search meta at all), so internal listings such as the
	 * request inbox can match requester names and emails in any script.
	 */
	public const META_KEYS_VAR = 'mw_search_meta_keys';

	/**
	 * Query var: taxonomies to search by term name for one query only.
	 *
	 * Replaces the defaults; an empty array skips the taxonomy sub-query.
	 */
	public const TAXONOMIES_VAR = 'mw_search_taxonomies';

	/** Hard cap on LIKE variants per search word. */
	public const MAX_VARIANTS = 6;

	/** Hard cap on search words that receive the expansion. */
	public const MAX_WORDS = 8;

	/**
	 * Register the query filters.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'posts_search', array( $this, 'filter_search' ), 10, 2 );
		add_filter( 'posts_search_orderby', array( $this, 'filter_search_orderby' ), 10, 2 );
	}

	/**
	 * Taxonomies whose term names take part in the search.
	 *
	 * @return array<int, string>
	 */
	public static function taxonomies(): array {
		/**
		 * Filter the public taxonomies searched by name on the site search.
		 *
		 * @param array<int, string> $taxonomies Taxonomy keys.
		 */
		$taxonomies = apply_filters( 'music_wave_search_taxonomies', array( 'mw_artist', 'mw_genre', 'mw_mood', 'mw_label', 'mw_release_type' ) );

		return self::sanitize_keys( $taxonomies );
	}

	/**
	 * Release meta keys whose values take part in the search.
	 *
	 * Only public, descriptive fields: album name, catalogue number, ISRC and
	 * the credits list (serialized, so names remain LIKE-matchable). Access,
	 * product and asset fields are never searched.
	 *
	 * @return array<int, string>
	 */
	public static function meta_keys(): array {
		/**
		 * Filter the release meta keys searched on the site search.
		 *
		 * @param array<int, string> $keys Meta keys.
		 */
		$keys = apply_filters( 'music_wave_search_meta_keys', array( 'mw_album', 'mw_catalog_number', 'mw_isrc', 'mw_credits' ) );

		return self::sanitize_keys( $keys );
	}

	/**
	 * Whether the query should receive the script-aware search.
	 *
	 * Applies to the main query (front end and admin lists), to any query
	 * scoped to releases, and to queries that opt in through the query var.
	 *
	 * @param mixed $query WP_Query instance.
	 */
	public function applies( $query ): bool {
		if ( ! is_object( $query ) || ! method_exists( $query, 'get' ) ) {
			return false;
		}

		$phrase = $query->get( 's' );
		if ( ! is_string( $phrase ) || '' === trim( $phrase ) ) {
			return false;
		}

		if ( $query->get( self::QUERY_VAR ) ) {
			$eligible = true;
		} elseif ( method_exists( $query, 'is_main_query' ) && $query->is_main_query() ) {
			$eligible = true;
		} else {
			$post_type = $query->get( 'post_type' );
			$eligible  = in_array( ReleasePostType::KEY, (array) $post_type, true );
		}

		if ( ! $eligible ) {
			return false;
		}

		// Eligible queries are rewritten for every script. Core cannot search
		// taxonomy term names or metadata at all, so a Latin artist name, a
		// genre, a catalogue number, or an accented title would otherwise
		// match nothing. Pure-ASCII words expand to a single spelling, so the
		// only added cost is the bounded term/meta sub-queries below; the
		// `music_wave_script_aware_search` filter remains the per-query off
		// switch, and internal listings still opt in with their own keys.
		$default = true;

		/**
		 * Filter whether the script-aware search applies to one query.
		 *
		 * @param bool   $enabled Whether to rewrite the search clause.
		 * @param object $query   WP_Query instance.
		 */
		return (bool) apply_filters( 'music_wave_script_aware_search', $default, $query );
	}

	/**
	 * Build the search plan: one entry per word with its LIKE variants.
	 *
	 * Exposed for tests; pure apart from the normalizer.
	 *
	 * @param array<int, string> $search_terms Terms as parsed by WP_Query.
	 * @return array<int, array{exclude: bool, variants: array<int, string>}>
	 */
	public function plan( array $search_terms, string $exclusion_prefix = '-' ): array {
		$plan = array();
		foreach ( array_slice( $search_terms, 0, self::MAX_WORDS ) as $term ) {
			if ( ! is_string( $term ) ) {
				continue;
			}
			$term    = trim( $term );
			$exclude = '' !== $exclusion_prefix && 0 === strpos( $term, $exclusion_prefix );
			if ( $exclude ) {
				$term = trim( substr( $term, strlen( $exclusion_prefix ) ) );
			}
			if ( '' === $term ) {
				continue;
			}

			$variants = PersianSearchNormalizer::variants( $term );
			if ( ! in_array( $term, $variants, true ) ) {
				array_unshift( $variants, $term );
			}
			$plan[] = array(
				'exclude'  => $exclude,
				'variants' => array_slice( $variants, 0, self::MAX_VARIANTS ),
			);
		}

		return $plan;
	}

	/**
	 * Replace the core search clause with the script-aware clause.
	 *
	 * @param mixed $search Core search SQL fragment.
	 * @param mixed $query  WP_Query instance.
	 * @return mixed
	 */
	public function filter_search( $search, $query ) {
		global $wpdb;

		if ( ! is_string( $search ) || '' === trim( $search ) || ! $this->applies( $query ) ) {
			return $search;
		}
		if ( ! $wpdb instanceof \wpdb ) {
			return $search;
		}

		$terms = $query->get( 'search_terms' );
		$plan  = $this->plan( is_array( $terms ) ? $terms : array(), $this->exclusion_prefix() );
		if ( array() === $plan ) {
			return $search;
		}

		$columns    = $this->search_columns( $query );
		$exact      = (bool) $query->get( 'exact' );
		$taxonomies = $this->scoped_keys( $query, self::TAXONOMIES_VAR, self::taxonomies() );
		$meta_keys  = $this->scoped_keys( $query, self::META_KEYS_VAR, self::meta_keys() );
		$clauses    = array();
		foreach ( $plan as $entry ) {
			$clause = $this->word_clause( $wpdb, $entry['variants'], $columns, $exact, $entry['exclude'], $taxonomies, $meta_keys );
			if ( '' !== $clause ) {
				$clauses[] = $clause;
			}
		}
		if ( array() === $clauses ) {
			return $search;
		}

		$sql = ' AND (' . implode( ' AND ', $clauses ) . ') ';
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			$sql .= " AND ({$wpdb->posts}.post_password = '') ";
		}

		return $sql;
	}

	/**
	 * Keep relevance ordering meaningful for expanded spellings.
	 *
	 * Only runs when core decided that relevance ordering applies (the
	 * incoming clause is non-empty), so explicit sort choices stay intact.
	 *
	 * @param mixed $orderby Core relevance ORDER BY fragment.
	 * @param mixed $query   WP_Query instance.
	 * @return mixed
	 */
	public function filter_search_orderby( $orderby, $query ) {
		global $wpdb;

		if ( ! is_string( $orderby ) || '' === trim( $orderby ) || ! $this->applies( $query ) ) {
			return $orderby;
		}
		if ( ! $wpdb instanceof \wpdb ) {
			return $orderby;
		}

		$terms = $query->get( 'search_terms' );
		$words = array();
		foreach ( $this->plan( is_array( $terms ) ? $terms : array(), $this->exclusion_prefix() ) as $entry ) {
			if ( $entry['exclude'] ) {
				continue;
			}
			$likes = array();
			foreach ( $entry['variants'] as $variant ) {
				$likes[] = $wpdb->prepare( "{$wpdb->posts}.post_title LIKE %s", '%' . $wpdb->esc_like( $variant ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb.
			}
			if ( array() !== $likes ) {
				$words[] = '(' . implode( ' OR ', $likes ) . ')';
			}
		}
		if ( array() === $words ) {
			return $orderby;
		}

		$expression = '(CASE WHEN ' . implode( ' AND ', $words ) . ' THEN 1';
		if ( count( $words ) > 1 ) {
			$expression .= ' WHEN ' . implode( ' OR ', $words ) . ' THEN 2';
		}

		return $expression . ' ELSE 3 END) ASC';
	}

	/**
	 * SQL for one search word across the post columns, term names and meta.
	 *
	 * @param \wpdb              $wpdb       Database abstraction.
	 * @param array<int, string> $variants   Spellings to match.
	 * @param array<int, string> $columns    Post columns to search.
	 * @param array<int, string> $taxonomies Taxonomies searched by term name.
	 * @param array<int, string> $meta_keys  Meta keys searched by value.
	 */
	private function word_clause( \wpdb $wpdb, array $variants, array $columns, bool $exact, bool $exclude, array $taxonomies, array $meta_keys ): string {
		$wild  = $exact ? '' : '%';
		$likes = array();
		foreach ( $variants as $variant ) {
			$likes[] = $wild . $wpdb->esc_like( $variant ) . $wild;
		}
		if ( array() === $likes ) {
			return '';
		}

		$operator = $exclude ? 'NOT LIKE' : 'LIKE';
		$glue     = $exclude ? ' AND ' : ' OR ';
		$parts    = array();
		foreach ( $columns as $column ) {
			$column_parts = array();
			foreach ( $likes as $like ) {
				$column_parts[] = $wpdb->prepare( "{$wpdb->posts}.{$column} {$operator} %s", $like ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers are allow-listed.
			}
			$parts[] = '(' . implode( $glue, $column_parts ) . ')';
		}

		$membership = $exclude ? 'NOT IN' : 'IN';
		$term_sql   = $this->term_subquery( $wpdb, $likes, $taxonomies );
		if ( '' !== $term_sql ) {
			$parts[] = "({$wpdb->posts}.ID {$membership} ({$term_sql}))";
		}
		$meta_sql = $this->meta_subquery( $wpdb, $likes, $meta_keys );
		if ( '' !== $meta_sql ) {
			$parts[] = "({$wpdb->posts}.ID {$membership} ({$meta_sql}))";
		}
		if ( array() === $parts ) {
			return '';
		}

		return '(' . implode( $glue, $parts ) . ')';
	}

	/**
	 * Sub-query selecting posts whose catalog term names match a spelling.
	 *
	 * @param \wpdb              $wpdb       Database abstraction.
	 * @param array<int, string> $likes      Prepared LIKE patterns.
	 * @param array<int, string> $taxonomies Taxonomy keys.
	 */
	private function term_subquery( \wpdb $wpdb, array $likes, array $taxonomies ): string {
		if ( array() === $taxonomies || '' === $wpdb->term_relationships || '' === $wpdb->term_taxonomy || '' === $wpdb->terms ) {
			return '';
		}

		$name_likes = array();
		foreach ( $likes as $like ) {
			$name_likes[] = $wpdb->prepare( 'mw_t.name LIKE %s', $like );
		}
		$placeholders = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );
		$taxonomy_sql = $wpdb->prepare( "mw_tt.taxonomy IN ({$placeholders})", $taxonomies ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated per taxonomy.

		return "SELECT mw_tr.object_id FROM {$wpdb->term_relationships} AS mw_tr"
			. " INNER JOIN {$wpdb->term_taxonomy} AS mw_tt ON mw_tt.term_taxonomy_id = mw_tr.term_taxonomy_id"
			. " INNER JOIN {$wpdb->terms} AS mw_t ON mw_t.term_id = mw_tt.term_id"
			. " WHERE {$taxonomy_sql} AND (" . implode( ' OR ', $name_likes ) . ')';
	}

	/**
	 * Sub-query selecting posts whose allow-listed meta values match a spelling.
	 *
	 * @param \wpdb              $wpdb  Database abstraction.
	 * @param array<int, string> $likes Prepared LIKE patterns.
	 * @param array<int, string> $keys  Meta keys.
	 */
	private function meta_subquery( \wpdb $wpdb, array $likes, array $keys ): string {
		if ( array() === $keys || '' === $wpdb->postmeta ) {
			return '';
		}

		$value_likes = array();
		foreach ( $likes as $like ) {
			$value_likes[] = $wpdb->prepare( 'mw_pm.meta_value LIKE %s', $like );
		}
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$key_sql      = $wpdb->prepare( "mw_pm.meta_key IN ({$placeholders})", $keys ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated per key.

		return "SELECT mw_pm.post_id FROM {$wpdb->postmeta} AS mw_pm WHERE {$key_sql} AND (" . implode( ' OR ', $value_likes ) . ')';
	}

	/**
	 * Post columns to search, honouring the core `post_search_columns` filter.
	 *
	 * @param object $query WP_Query instance.
	 * @return array<int, string>
	 */
	private function search_columns( $query ): array {
		$defaults = array( 'post_title', 'post_excerpt', 'post_content' );
		$columns  = $query->get( 'search_columns' );
		$columns  = is_array( $columns ) && array() !== $columns ? $columns : $defaults;

		/** This filter is documented in wp-includes/class-wp-query.php */
		$columns = (array) apply_filters( 'post_search_columns', $columns, (string) $query->get( 's' ), $query );
		$columns = array_values( array_intersect( $columns, $defaults ) );

		return array() !== $columns ? $columns : $defaults;
	}

	/**
	 * Per-query override of a key list (taxonomies / meta keys).
	 *
	 * An array value replaces the defaults for this query only — including an
	 * empty array, which switches that sub-query off. Anything else keeps the
	 * filtered defaults.
	 *
	 * @param object             $query    WP_Query instance.
	 * @param array<int, string> $defaults Site-wide keys.
	 * @return array<int, string>
	 */
	private function scoped_keys( $query, string $query_var, array $defaults ): array {
		$scoped = $query->get( $query_var );

		return is_array( $scoped ) ? self::sanitize_keys( $scoped ) : $defaults;
	}

	/**
	 * Exclusion prefix as configured through the core filter.
	 */
	private function exclusion_prefix(): string {
		/** This filter is documented in wp-includes/class-wp-query.php */
		$prefix = apply_filters( 'wp_query_search_exclusion_prefix', '-' );

		return is_string( $prefix ) ? $prefix : '';
	}

	/**
	 * Keep only well-formed key names.
	 *
	 * @param mixed $keys Candidate keys.
	 * @return array<int, string>
	 */
	private static function sanitize_keys( $keys ): array {
		$clean = array();
		foreach ( (array) $keys as $key ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			$key = sanitize_key( $key );
			if ( '' !== $key && ! in_array( $key, $clean, true ) ) {
				$clean[] = $key;
			}
		}

		return $clean;
	}
}
