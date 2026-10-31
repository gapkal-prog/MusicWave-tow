<?php
/**
 * Artists toolbar block: search and ordering tabs.
 *
 * The toolbar is a standalone block. Every control is a plain link or a GET
 * form built from the shared {@see ArtistIndex}, so it works without
 * JavaScript and the shelf below it reacts to the same query state.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

use ManaCore\MusicWave\Core\Catalog\ArtistIndex;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ArtistsToolbarBlock {
	private const ICON_SEARCH = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>';

	private const ICON_CLOSE = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="m6 6 12 12M18 6 6 18"/></svg>';

	/**
	 * Register the server-rendered artists toolbar block.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		BlockSupport::register_dynamic(
			'music-wave/artists-toolbar',
			array( $this, 'render' ),
			array(
				'api_version' => 3,
				'supports'    => BlockSupport::appearance_tools(),
			)
		);
	}

	/**
	 * Render the artists toolbar.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render( array $attributes = array() ): string {
		$index = new ArtistIndex();
		$sort  = '' !== $index->sort()
			? $index->sort()
			: BlockSupport::key_attribute( $attributes, 'defaultSort', array( ArtistIndex::SORT_POPULAR, ArtistIndex::SORT_ALPHABETICAL ), ArtistIndex::SORT_POPULAR );

		return '<div ' . BlockSupport::wrapper_attributes( 'mw-artists-toolbar' ) . '>'
			. $this->sorts( $attributes, $index, $sort )
			. $this->search( $attributes, $index, $sort )
			. '</div>';
	}

	/**
	 * Popular / alphabetical ordering tabs.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param ArtistIndex          $index      Browsing service.
	 * @param string               $sort       Active ordering key.
	 */
	private function sorts( array $attributes, ArtistIndex $index, string $sort ): string {
		if ( ! BlockSupport::bool_attribute( $attributes, 'showSortTabs', true ) ) {
			return '';
		}

		$labels = array(
			ArtistIndex::SORT_POPULAR      => BlockSupport::text_attribute( $attributes, 'popularLabel', __( 'پیکارتین', 'music-wave-core' ) ),
			ArtistIndex::SORT_ALPHABETICAL => BlockSupport::text_attribute( $attributes, 'alphabeticalLabel', __( 'الفبایی', 'music-wave-core' ) ),
		);

		$links = '';
		foreach ( $labels as $key => $label ) {
			$active = $key === $sort;
			$links .= '<a class="mw-artists-toolbar__sort' . ( $active ? ' is-active' : '' ) . '" href="' . esc_url( $index->url( array( ArtistIndex::QUERY_SORT => $key ) ) ) . '"' . ( $active ? ' aria-current="true"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}

		return '<nav class="mw-artists-toolbar__sorts" aria-label="' . esc_attr__( 'ترتیب نمایش', 'music-wave-core' ) . '">' . $links . '</nav>';
	}

	/**
	 * GET search form that keeps the active ordering.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param ArtistIndex          $index      Browsing service.
	 * @param string               $sort       Active ordering key.
	 */
	private function search( array $attributes, ArtistIndex $index, string $sort ): string {
		if ( ! BlockSupport::bool_attribute( $attributes, 'showSearch', true ) ) {
			return '';
		}

		$field_id    = $this->field_id();
		$placeholder = BlockSupport::text_attribute( $attributes, 'searchPlaceholder', __( 'نام خواننده را جست‌وجو کنید…', 'music-wave-core' ) );
		$value       = $index->search();
		$action      = $index->url(
			array(
				ArtistIndex::QUERY_SEARCH => '',
				ArtistIndex::QUERY_LETTER => '',
				ArtistIndex::QUERY_PAGE   => 1,
			)
		);

		$hidden = '';
		if ( ArtistIndex::SORT_ALPHABETICAL === $sort ) {
			$hidden .= '<input type="hidden" name="' . esc_attr( ArtistIndex::QUERY_SORT ) . '" value="' . esc_attr( ArtistIndex::SORT_ALPHABETICAL ) . '">';
		}

		$clear = '';
		if ( '' !== $value ) {
			$clear = '<a class="mw-artists-toolbar__clear" href="' . esc_url(
				$index->url(
					array(
						ArtistIndex::QUERY_SEARCH => '',
						ArtistIndex::QUERY_PAGE   => 1,
					)
				)
			) . '" aria-label="' . esc_attr__( 'پاک کردن جست‌وجو', 'music-wave-core' ) . '">' . self::ICON_CLOSE . '</a>';
		}

		return '<form class="mw-artists-toolbar__search" role="search" method="get" action="' . esc_url( $action ) . '">'
			. '<label class="screen-reader-text" for="' . esc_attr( $field_id ) . '">' . esc_html__( 'جست‌وجوی خواننده', 'music-wave-core' ) . '</label>'
			. '<input id="' . esc_attr( $field_id ) . '" class="mw-artists-toolbar__field" type="search" name="' . esc_attr( ArtistIndex::QUERY_SEARCH ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off">'
			. $clear
			. $hidden
			. '<button class="mw-artists-toolbar__submit" type="submit">' . self::ICON_SEARCH . '<span class="screen-reader-text">' . esc_html__( 'جست‌وجو', 'music-wave-core' ) . '</span></button>'
			. '</form>';
	}

	/**
	 * Stable, unique id for the search field label.
	 */
	private function field_id(): string {
		if ( function_exists( 'wp_unique_id' ) ) {
			return wp_unique_id( 'mw-artists-search-' );
		}

		static $counter = 0;
		++$counter;

		return 'mw-artists-search-' . $counter;
	}
}
