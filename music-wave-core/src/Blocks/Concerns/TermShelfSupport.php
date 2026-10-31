<?php
/**
 * Shared term query & shelf header builder for ArtistShelfBlock and TaxonomyShelfBlock.
 *
 * Eliminates duplicated term ordering/shuffling/manual ID parsing and
 * `.mw-release-shelf__header` chrome rendering across taxonomy-backed shelves
 * (SOLID SRP + DRY).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks\Concerns;

use ManaCore\MusicWave\Core\Blocks\SectionHeader;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TermShelfSupport {
	/**
	 * Query terms either from a comma/space-separated manual ID list or from
	 * the taxonomy ordered by count, name, or shuffled pool.
	 *
	 * @param string $taxonomy   Taxonomy key.
	 * @param bool   $manual     Whether manual ID mode is active.
	 * @param string $raw_ids    Comma/whitespace-separated term IDs when manual.
	 * @param string $order_by   Requested order ('count' | 'name' | 'rand').
	 * @param int    $max_items  Maximum items allowed in shelf.
	 * @param int    $offset     Zero-based offset for paginated shelves.
	 * @return array<int, WP_Term>
	 */
	public static function query_terms( string $taxonomy, bool $manual, string $raw_ids, string $order_by, int $max_items, int $offset = 0 ): array {
		if ( $manual ) {
			$ids = preg_split( '/[\s,]+/', $raw_ids );
			$ids = is_array( $ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) : array();
			$ids = array_slice( $ids, 0, $max_items );
			if ( array() === $ids ) {
				return array();
			}

			$found = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'include'    => $ids,
					'orderby'    => 'include',
					'hide_empty' => false,
					'number'     => $max_items,
				)
			);

			return is_array( $found ) ? $found : array();
		}

		$order_by = in_array( $order_by, array( 'name', 'count', 'rand' ), true ) ? $order_by : 'count';
		$query    = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
			'number'     => $max_items,
		);

		// `offset` only means something together with a bounded `number`, which
		// the paginated artists index always supplies.
		if ( $offset > 0 && 'rand' !== $order_by ) {
			$query['offset'] = $offset;
		}

		if ( 'rand' === $order_by ) {
			$query['number'] = 100;
		} elseif ( 'name' === $order_by ) {
			$query['orderby'] = 'name';
			$query['order']   = 'ASC';
		} else {
			$query['orderby'] = 'count';
			$query['order']   = 'DESC';
		}

		$found = get_terms( $query );
		$terms = is_array( $found ) ? $found : array();

		if ( 'rand' === $order_by ) {
			shuffle( $terms );
		}

		return $terms;
	}

	/**
	 * Filter and re-validate resolved terms against an expected taxonomy.
	 *
	 * @param array<int, WP_Term>  $terms       Candidate terms.
	 * @param array<string, mixed> $attributes  Block attributes.
	 * @param int                  $max_items   Maximum cap.
	 * @param string               $filter_hook WordPress filter tag.
	 * @param string               $taxonomy    Expected taxonomy slug.
	 * @return array<int, WP_Term>
	 */
	public static function finalize_terms( array $terms, array $attributes, int $max_items, string $filter_hook, string $taxonomy ): array {
		$items = isset( $attributes['itemsToShow'] ) ? absint( $attributes['itemsToShow'] ) : 0;
		$items = min( $max_items, max( 1, $items > 0 ? $items : 8 ) );
		$terms = array_slice( $terms, 0, $items );

		$filtered = apply_filters( $filter_hook, $terms, $attributes );
		if ( ! is_array( $filtered ) ) {
			return array();
		}

		// The cap has to survive the filter: a hook may reorder or append
		// terms, but «حداکثر آیتم‌ها» is an author decision, not a suggestion.
		return array_slice(
			array_values(
				array_filter(
					$filtered,
					static function ( $term ) use ( $taxonomy ): bool {
						return $term instanceof WP_Term && $taxonomy === $term->taxonomy;
					}
				)
			),
			0,
			$items
		);
	}

	/**
	 * Render the shared `.mw-release-shelf__header` chrome.
	 *
	 * @param array<string, mixed> $options       Resolved header options.
	 * @param string               $default_title Fallback heading text.
	 * @param string               $default_more  Fallback "view all" link text.
	 */
	public static function render_header( array $options, string $default_title, string $default_more ): string {
		$heading = '' !== $options['heading'] ? (string) $options['heading'] : $default_title;

		$more = '';
		if ( '' !== $options['section_url'] ) {
			$label = '' !== $options['section_label'] ? (string) $options['section_label'] : $default_more;
			$more  = '<a class="mw-release-shelf__more" href="' . esc_url( (string) $options['section_url'] ) . '">' . esc_html( $label ) . '<span aria-hidden="true">&rarr;</span></a>';
		}

		return '<header class="mw-release-shelf__header"><div>'
			. ( '' !== $options['eyebrow'] ? '<span>' . esc_html( (string) $options['eyebrow'] ) . '</span>' : '' )
			. '<h2>' . SectionHeader::title( $heading, isset( $options['icon'] ) ? (string) $options['icon'] : '' ) . '</h2>'
			. ( '' !== $options['description'] ? '<p>' . esc_html( (string) $options['description'] ) . '</p>' : '' )
			. '</div>' . $more . '</header>';
	}
}
