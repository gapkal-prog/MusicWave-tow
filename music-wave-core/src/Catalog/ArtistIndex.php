<?php
/**
 * Artists index browsing state: filters, ordering, paging and URLs.
 *
 * Single source of truth for the `/artists/` browsing surface. The hero,
 * toolbar, alphabet, shelf and pagination blocks all read the same request
 * state and build the same links through this service, so a filter typed in
 * one block stays consistent across the whole template (SOLID SRP + DRY).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Catalog;

use ManaCore\MusicWave\Core\Blocks\BlockSupport;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ArtistIndex {
	/** Artist taxonomy key. */
	public const TAXONOMY = 'mw_artist';

	/** Public query vars shared by every artists browsing block. */
	public const QUERY_PAGE   = 'mw_artists_page';
	public const QUERY_SEARCH = 'mw_artists_search';
	public const QUERY_LETTER = 'mw_artists_letter';
	public const QUERY_SORT   = 'mw_artists_sort';

	/** Ordering keys used by the toolbar tabs. */
	public const SORT_POPULAR      = 'popular';
	public const SORT_ALPHABETICAL = 'alphabetical';

	/** Ceiling for one browsing request; keeps a runaway catalog bounded. */
	public const MAX_TERMS = 1000;

	/** Persian alphabet used by the letter filter, in reading order. */
	public const ALPHABET = array( 'آ', 'ا', 'ب', 'پ', 'ت', 'ث', 'ج', 'چ', 'ح', 'خ', 'د', 'ذ', 'ر', 'ز', 'ژ', 'س', 'ش', 'ص', 'ض', 'ط', 'ظ', 'ع', 'غ', 'ف', 'ق', 'ک', 'گ', 'ل', 'م', 'ن', 'و', 'ه', 'ی' );

	/**
	 * Characters that must resolve to the same alphabet bucket so names
	 * written with Arabic variants still answer to their Persian letter.
	 *
	 * @var array<string, string>
	 */
	private const LETTER_ALIASES = array(
		'أ' => 'ا',
		'إ' => 'ا',
		'ٱ' => 'ا',
		'ي' => 'ی',
		'ى' => 'ی',
		'ئ' => 'ی',
		'ك' => 'ک',
		'ة' => 'ه',
		'ۀ' => 'ه',
		'ؤ' => 'و',
	);

	/** @var array<string, array<int, WP_Term>> Memoized term lists keyed by order. */
	private $terms = array();

	/** @var array<string, int>|null Memoized per-letter counts. */
	private $letters;

	/** @var int|null Memoized published artist total. */
	private $total;

	/**
	 * Terms in the requested order, memoized for this instance.
	 *
	 * @param string $order Ordering key (`popular`, `alphabetical` or `random`).
	 * @return array<int, WP_Term>
	 */
	public function terms( string $order = self::SORT_POPULAR ): array {
		$order = in_array( $order, array( self::SORT_POPULAR, self::SORT_ALPHABETICAL, 'random' ), true )
			? $order
			: self::SORT_POPULAR;

		if ( isset( $this->terms[ $order ] ) ) {
			return $this->terms[ $order ];
		}

		$query = array(
			'taxonomy'   => self::TAXONOMY,
			'hide_empty' => true,
			'number'     => self::MAX_TERMS,
			'orderby'    => self::SORT_ALPHABETICAL === $order ? 'name' : 'count',
			'order'      => self::SORT_ALPHABETICAL === $order ? 'ASC' : 'DESC',
		);

		$found = get_terms( $query );
		$terms = is_array( $found ) ? $found : array();
		$terms = array_values(
			array_filter(
				$terms,
				static function ( $term ): bool {
					return $term instanceof WP_Term && self::TAXONOMY === $term->taxonomy;
				}
			)
		);

		if ( 'random' === $order ) {
			shuffle( $terms );
		}

		$this->terms[ $order ] = $terms;

		return $this->terms[ $order ];
	}

	/**
	 * Terms matching the active search text and letter filter.
	 *
	 * Pass explicit arguments to filter against a different state than the
	 * request (tests, previews); omit them to use the current URL.
	 *
	 * @param string      $order  Ordering key.
	 * @param string|null $search Search text, or null for the current request.
	 * @param string|null $letter Letter bucket, or null for the current request.
	 * @return array<int, WP_Term>
	 */
	public function filtered( string $order = self::SORT_POPULAR, ?string $search = null, ?string $letter = null ): array {
		$search = null === $search ? $this->search() : $search;
		$letter = null === $letter ? $this->letter() : $letter;
		$terms  = $this->terms( $order );

		if ( '' === $search && '' === $letter ) {
			return $terms;
		}

		$needle = self::lower( $search );
		$result = array();
		foreach ( $terms as $term ) {
			if ( '' !== $letter && self::term_letter( $term->name ) !== $letter ) {
				continue;
			}
			if ( '' !== $needle && false === strpos( self::lower( $term->name ), $needle ) && false === strpos( self::lower( $term->slug ), $needle ) ) {
				continue;
			}
			$result[] = $term;
		}

		return $result;
	}

	/**
	 * Published artist total, unaffected by the active filters.
	 */
	public function total(): int {
		if ( null !== $this->total ) {
			return $this->total;
		}

		$count       = wp_count_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => true,
			)
		);
		$this->total = is_wp_error( $count ) ? 0 : absint( $count );

		return $this->total;
	}

	/**
	 * Artist total after the active search and letter filters.
	 */
	public function matching_total(): int {
		if ( '' === $this->search() && '' === $this->letter() ) {
			return $this->total();
		}

		return count( $this->filtered( self::SORT_POPULAR ) );
	}

	/**
	 * Sum of published releases across every artist term.
	 *
	 * A release credited to several artists is counted once per artist; this
	 * is a catalogue-size indicator, not a release inventory.
	 */
	public function release_total(): int {
		$total = 0;
		foreach ( $this->terms( self::SORT_POPULAR ) as $term ) {
			$total += max( 0, (int) $term->count );
		}

		return $total;
	}

	/**
	 * Per-letter artist counts for the alphabet filter.
	 *
	 * @return array<string, int>
	 */
	public function letters(): array {
		if ( null !== $this->letters ) {
			return $this->letters;
		}

		$counts = array_fill_keys( self::ALPHABET, 0 );
		foreach ( $this->terms( self::SORT_ALPHABETICAL ) as $term ) {
			$letter = self::term_letter( $term->name );
			if ( isset( $counts[ $letter ] ) ) {
				++$counts[ $letter ];
			}
		}

		$this->letters = $counts;

		return $this->letters;
	}

	/**
	 * Page count for one page size (never less than one).
	 *
	 * @param int $per_page Artists per page.
	 */
	public function page_count( int $per_page ): int {
		$per_page = max( 1, $per_page );

		return max( 1, (int) ceil( $this->matching_total() / $per_page ) );
	}

	/**
	 * One-based page number requested by the URL.
	 */
	public function page(): int {
		$page = max( 1, absint( $this->param( self::QUERY_PAGE ) ) );

		/**
		 * Filter the requested artists index page.
		 *
		 * @param int $page One-based page number.
		 */
		return max( 1, (int) apply_filters( 'music_wave_artists_page', $page ) );
	}

	/**
	 * Active search text, or an empty string when no search is running.
	 */
	public function search(): string {
		return $this->param( self::QUERY_SEARCH );
	}

	/**
	 * Active letter bucket, or an empty string for "all letters".
	 */
	public function letter(): string {
		$letter = $this->param( self::QUERY_LETTER );

		return in_array( $letter, self::ALPHABET, true ) ? $letter : '';
	}

	/**
	 * Active ordering key, or an empty string when the visitor kept the default.
	 */
	public function sort(): string {
		$sort = sanitize_key( $this->param( self::QUERY_SORT ) );

		return in_array( $sort, array( self::SORT_POPULAR, self::SORT_ALPHABETICAL ), true ) ? $sort : '';
	}

	/**
	 * Build a URL for this artists index with selected query state replaced.
	 *
	 * Unknown/empty values are dropped so the canonical state stays short:
	 * page one and the popular order need no query var.
	 *
	 * @param array<string, string|int> $overrides Query var overrides.
	 */
	public function url( array $overrides = array() ): string {
		$args = array_merge(
			array(
				self::QUERY_PAGE   => $this->page(),
				self::QUERY_SEARCH => $this->search(),
				self::QUERY_LETTER => $this->letter(),
				self::QUERY_SORT   => $this->sort(),
			),
			$overrides
		);

		$query = array();
		foreach ( $args as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = (string) $value;
			if ( '' === $value ) {
				continue;
			}
			if ( self::QUERY_PAGE === $key && (int) $value <= 1 ) {
				continue;
			}
			if ( self::QUERY_SORT === $key && self::SORT_POPULAR === $value ) {
				continue;
			}
			$query[ $key ] = $value;
		}

		$base = $this->base_url();

		return array() === $query ? $base : (string) add_query_arg( $query, $base );
	}

	/**
	 * Current request URL with every artists query var removed.
	 */
	private function base_url(): string {
		// The raw request URI is kept as-is: percent-encoded artist slugs must
		// survive the round trip into the generated links.
		$request = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$url     = '' !== $request ? home_url( $request ) : home_url( '/' );

		return (string) remove_query_arg(
			array( self::QUERY_PAGE, self::QUERY_SEARCH, self::QUERY_LETTER, self::QUERY_SORT ),
			$url
		);
	}

	/**
	 * Read one browsing query var; editor previews always see the defaults.
	 *
	 * @param string $key Query var name.
	 */
	private function param( string $key ): string {
		if ( BlockSupport::is_editor_context() ) {
			return '';
		}

		// Read-only browsing state: nothing is written and no nonce applies.
		// The raw value is sanitized on the way out, after the scalar guard.
		$raw = isset( $_GET[ $key ] ) ? $_GET[ $key ] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.NonceVerification.Recommended

		return is_scalar( $raw ) ? sanitize_text_field( wp_unslash( (string) $raw ) ) : '';
	}

	/**
	 * Alphabet bucket of one artist name.
	 *
	 * @param string $name Artist name.
	 */
	public static function term_letter( string $name ): string {
		if ( 1 !== preg_match( '/\p{L}/u', $name, $matches ) ) {
			return '';
		}

		return self::LETTER_ALIASES[ $matches[0] ] ?? $matches[0];
	}

	/**
	 * Lowercase a search term without requiring the mbstring extension.
	 *
	 * @param string $value Raw text.
	 */
	private static function lower( string $value ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}
}
