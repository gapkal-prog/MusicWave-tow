<?php
/**
 * Artists alphabet block: the Persian letter filter row.
 *
 * Letters are plain links built from {@see ArtistIndex}, so the filter works
 * without JavaScript and combines with the active search, ordering and view.
 * Letters without any artist render as inert labels instead of dead links.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

use ManaCore\MusicWave\Core\Catalog\ArtistIndex;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ArtistsAlphabetBlock {
	/** Id the toolbar menu icon links to when the admin has not set an anchor. */
	public const DEFAULT_ID = 'mw-artists-alphabet';

	/**
	 * Register the server-rendered artists alphabet block.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		BlockSupport::register_dynamic(
			'music-wave/artists-alphabet',
			array( $this, 'render' ),
			array(
				'api_version' => 3,
				'supports'    => BlockSupport::appearance_tools(),
			)
		);
	}

	/**
	 * Render the alphabet filter row.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render( array $attributes = array() ): string {
		$index  = new ArtistIndex();
		$active = $index->letter();
		$counts = $index->letters();

		$items = '';
		if ( BlockSupport::bool_attribute( $attributes, 'showAll', true ) ) {
			$all_label = BlockSupport::text_attribute( $attributes, 'allLabel', __( 'همه', 'music-wave-core' ) );
			$items    .= '<a class="mw-artists-alphabet__item' . ( '' === $active ? ' is-active' : '' ) . '" href="' . esc_url(
				$index->url(
					array(
						ArtistIndex::QUERY_LETTER => '',
						ArtistIndex::QUERY_PAGE   => 1,
					)
				)
			) . '" data-count="' . esc_attr( (string) $index->matching_total() ) . '"' . ( '' === $active ? ' aria-current="true"' : '' ) . '>' . esc_html( $all_label ) . '</a>';
		}

		$show_counts = BlockSupport::bool_attribute( $attributes, 'showCounts', false );
		foreach ( ArtistIndex::ALPHABET as $letter ) {
			$count = isset( $counts[ $letter ] ) ? (int) $counts[ $letter ] : 0;
			if ( $count < 1 ) {
				$items .= '<span class="mw-artists-alphabet__item is-empty" data-count="0">' . esc_html( $letter ) . '</span>';
				continue;
			}

			$is_active = $letter === $active;
			$label     = sprintf(
				/* translators: 1: letter, 2: number of artists. */
				__( 'خوانندگان با حرف %1$s، %2$s نفر', 'music-wave-core' ),
				$letter,
				number_format_i18n( $count )
			);

			$items .= '<a class="mw-artists-alphabet__item' . ( $is_active ? ' is-active' : '' ) . '" href="' . esc_url(
				$index->url(
					array(
						ArtistIndex::QUERY_LETTER => $letter,
						ArtistIndex::QUERY_PAGE   => 1,
					)
				)
			) . '" aria-label="' . esc_attr( $label ) . '" data-count="' . esc_attr( (string) $count ) . '"' . ( $is_active ? ' aria-current="true"' : '' ) . '>'
				. esc_html( $letter )
				. ( $show_counts ? '<span class="mw-artists-alphabet__count" aria-hidden="true">' . esc_html( number_format_i18n( $count ) ) . '</span>' : '' )
				. '</a>';
		}

		// The toolbar menu icon links here, so the row needs a stable id unless
		// the editor already gave the block a custom anchor.
		$anchor = isset( $attributes['anchor'] ) && is_scalar( $attributes['anchor'] ) ? sanitize_title( (string) $attributes['anchor'] ) : '';
		$id     = '' === $anchor ? ' id="' . esc_attr( self::DEFAULT_ID ) . '"' : '';

		return '<nav ' . BlockSupport::wrapper_attributes( 'mw-artists-alphabet' ) . $id . ' aria-label="' . esc_attr__( 'فیلتر الفبایی خوانندگان', 'music-wave-core' ) . '">'
			. $items
			. '</nav>';
	}
}
