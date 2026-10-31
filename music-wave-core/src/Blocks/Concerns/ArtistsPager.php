<?php
/**
 * Shared artists pagination markup.
 *
 * One implementation feeds both the legacy `pagination` attribute on the
 * shelf and the standalone `music-wave/artists-pagination` block, so the two
 * surfaces can never drift apart (SOLID SRP + DRY).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks\Concerns;

use ManaCore\MusicWave\Core\Catalog\ArtistIndex;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ArtistsPager {
	/**
	 * Render the numbered pager for the current browsing state.
	 *
	 * @param ArtistIndex $index       Browsing state (filters, page, URLs).
	 * @param int         $per_page    Artists per page.
	 * @param string      $class_name  Root BEM class of the navigation.
	 * @param bool        $show_status Whether to print the page summary line.
	 */
	public static function render( ArtistIndex $index, int $per_page, string $class_name, bool $show_status = true ): string {
		$total       = $index->matching_total();
		$total_pages = $index->page_count( $per_page );
		if ( $total_pages < 2 || $total < 1 ) {
			return '';
		}

		$current = min( $index->page(), $total_pages );
		$numbers = array( 1, $total_pages );
		$first   = max( 1, $current - 1 );
		$last    = min( $total_pages, $current + 1 );
		for ( $number = $first; $number <= $last; $number++ ) {
			$numbers[] = $number;
		}
		$numbers = array_values( array_unique( $numbers ) );
		sort( $numbers );

		$items  = '';
		$before = 0;
		foreach ( $numbers as $number ) {
			if ( $before > 0 && $number - $before > 1 ) {
				$items .= '<li class="mw-pagination__item mw-pagination__item--gap"><span class="mw-pagination__ellipsis" aria-hidden="true">...</span></li>';
			}
			$before = $number;

			if ( $number === $current ) {
				$items .= '<li class="mw-pagination__item"><span class="mw-pagination__link is-current" aria-current="page">' . esc_html( number_format_i18n( $number ) ) . '</span></li>';
				continue;
			}

			$items .= '<li class="mw-pagination__item"><a class="mw-pagination__link" href="' . esc_url( $index->url( array( ArtistIndex::QUERY_PAGE => $number ) ) ) . '">' . esc_html( number_format_i18n( $number ) ) . '</a></li>';
		}

		if ( $current > 1 ) {
			$items = '<li class="mw-pagination__item"><a class="mw-pagination__link mw-pagination__link--prev" rel="prev" href="' . esc_url( $index->url( array( ArtistIndex::QUERY_PAGE => $current - 1 ) ) ) . '">' . esc_html__( 'قبلی', 'music-wave-core' ) . '</a></li>' . $items;
		}
		if ( $current < $total_pages ) {
			$items .= '<li class="mw-pagination__item"><a class="mw-pagination__link mw-pagination__link--next" rel="next" href="' . esc_url( $index->url( array( ArtistIndex::QUERY_PAGE => $current + 1 ) ) ) . '">' . esc_html__( 'بعدی', 'music-wave-core' ) . '</a></li>';
		}

		return '<nav class="' . esc_attr( $class_name ) . '" aria-label="' . esc_attr__( 'صفحه‌بندی هنرمندان', 'music-wave-core' ) . '">'
			. ( $show_status ? '<p class="' . esc_attr( $class_name ) . '-status">' . esc_html( self::status( $current, $total_pages, $total ) ) . '</p>' : '' )
			. '<ul class="mw-pagination">' . $items . '</ul>'
			. '</nav>';
	}

	/**
	 * Localized "page X of Y · N artists" status line.
	 *
	 * @param int $current     Current page.
	 * @param int $total_pages Total pages.
	 * @param int $total       Matching artist total.
	 */
	public static function status( int $current, int $total_pages, int $total ): string {
		return sprintf(
			/* translators: 1: current page, 2: total pages, 3: total artist count. */
			__( 'صفحهٔ %1$s از %2$s · %3$s هنرمند', 'music-wave-core' ),
			number_format_i18n( $current ),
			number_format_i18n( $total_pages ),
			number_format_i18n( $total )
		);
	}
}
