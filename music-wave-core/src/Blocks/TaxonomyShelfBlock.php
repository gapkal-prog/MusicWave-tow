<?php
/**
 * Public taxonomy shelf block: genre, mood, and label browse surfaces.
 *
 * Renders catalog taxonomies as tappable browse tiles — the discovery layer
 * global music platforms put on their home page. Colourful tiles carry the
 * term's own stable hue, derived from the term ID alone, so every grid and
 * rail stays varied without repainting a genre when a list re-orders,
 * reuse the shared MusicWave release-shelf chrome for grids
 * and rails, and always link to real term archives.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

use ManaCore\MusicWave\Core\Blocks\Concerns\TermShelfSupport;
use ManaCore\MusicWave\Core\Catalog\TermHue;
use WP_Term;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TaxonomyShelfBlock {
	/** Hard ceiling for one render; keeps queries and DOM bounded. */
	private const MAX_ITEMS = 24;

	/** Taxonomies this block may browse. */
	private const ALLOWED_TAXONOMIES = array( 'mw_genre', 'mw_mood', 'mw_label' );

	/**
	 * Register the server-rendered taxonomy shelf block.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		BlockSupport::register_dynamic(
			'music-wave/taxonomy-shelf',
			array( $this, 'render' ),
			array(
				'api_version' => 3,
				'supports'    => BlockSupport::appearance_tools(),
			)
		);
	}

	/**
	 * Render the taxonomy shelf.
	 *
	 * @param array<string, mixed> $attributes Block attributes from the editor.
	 *
	 * @return string
	 */
	public function render( array $attributes = array() ): string {
		$terms = $this->resolve_terms( $attributes );
		if ( array() === $terms ) {
			if ( BlockSupport::is_editor_context() ) {
				return $this->render_editor_placeholder( $attributes );
			}

			$options = $this->resolve_options( $attributes );
			$message = '' !== $options['empty_message']
				? $options['empty_message']
				: __( 'هنوز چیزی برای مرور وجود ندارد.', 'music-wave-core' );

			return '<section '
				. BlockSupport::wrapper_attributes( 'mw-terms-shelf mw-terms-shelf--empty' )
				. '><p class="mw-terms-shelf__empty">' . esc_html( $message ) . '</p></section>';
		}

		$options = $this->resolve_options( $attributes );

		$tiles = array();
		foreach ( array_values( $terms ) as $term ) {
			$tile = $this->tile( $term, $options );
			if ( '' !== $tile ) {
				$tiles[] = $tile;
			}
		}

		if ( array() === $tiles ) {
			return '';
		}

		$header = '';
		if ( $options['show_heading'] ) {
			$header = $this->header( $options );
		}

		$shelf_class = 'mw-terms-shelf mw-release-shelf mw-release-shelf--' . $options['layout'];
		if ( 'grid' === $options['layout'] ) {
			$shelf_class .= ' mw-release-shelf--columns-' . $options['columns'];
		}

		return '<section '
			. BlockSupport::wrapper_attributes( $shelf_class )
			. '>'
			. $header
			. \ManaCore\MusicWave\Core\Support\ShelfNavMarkup::items_region(
				(string) $options['layout'],
				implode( '', $tiles ),
				isset( $options['heading'] ) ? (string) $options['heading'] : ''
			)
			. '</section>';
	}

	/**
	 * Resolve sanitized display options once per render.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<string, mixed>
	 */
	private function resolve_options( array $attributes ): array {
		$layout = isset( $attributes['layout'] ) ? sanitize_key( (string) $attributes['layout'] ) : 'grid';
		$layout = in_array( $layout, array( 'grid', 'scroll', 'list' ), true ) ? $layout : 'grid';

		$card_style = isset( $attributes['cardStyle'] ) ? sanitize_key( (string) $attributes['cardStyle'] ) : 'colorful';
		$card_style = in_array( $card_style, array( 'colorful', 'plain' ), true ) ? $card_style : 'colorful';

		$items = isset( $attributes['itemsToShow'] ) ? absint( $attributes['itemsToShow'] ) : 0;
		$items = min( self::MAX_ITEMS, max( 1, $items > 0 ? $items : 8 ) );

		$columns = isset( $attributes['columns'] ) ? absint( $attributes['columns'] ) : 4;
		$columns = min( 6, max( 2, $columns > 0 ? $columns : 4 ) );

		$order_by = isset( $attributes['orderBy'] ) ? sanitize_key( (string) $attributes['orderBy'] ) : 'count';
		$order_by = in_array( $order_by, array( 'name', 'count', 'rand' ), true ) ? $order_by : 'count';

		$heading_val = BlockSupport::optional_text_attribute( $attributes, 'heading', '', 'showHeading' );

		return array(
			'layout'        => $layout,
			'card_style'    => $card_style,
			'items'         => $items,
			'columns'       => $columns,
			'order_by'      => $order_by,
			'show_heading'  => ! isset( $attributes['showHeading'] ) || (bool) $attributes['showHeading'],
			'show_count'    => ! isset( $attributes['showCount'] ) || (bool) $attributes['showCount'],
			'eyebrow'       => isset( $attributes['eyebrow'] ) ? sanitize_text_field( (string) $attributes['eyebrow'] ) : '',
			'heading'       => $heading_val,
			'icon'          => isset( $attributes['icon'] ) ? (string) $attributes['icon'] : '',
			'description'   => isset( $attributes['description'] ) ? sanitize_text_field( (string) $attributes['description'] ) : '',
			'section_url'   => isset( $attributes['sectionUrl'] ) ? esc_url_raw( (string) $attributes['sectionUrl'] ) : '',
			'section_label' => isset( $attributes['sectionLinkLabel'] ) ? sanitize_text_field( (string) $attributes['sectionLinkLabel'] ) : '',
			'empty_message' => isset( $attributes['emptyMessage'] ) ? sanitize_text_field( (string) $attributes['emptyMessage'] ) : '',
		);
	}

	/**
	 * Resolve the terms for this render according to the source.
	 *
	 * Term counts track only published releases for these non-hierarchical
	 * taxonomies, so `hide_empty` filtering costs no extra queries.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<int, WP_Term>
	 */
	private function resolve_terms( array $attributes ): array {
		$taxonomy = isset( $attributes['taxonomy'] ) ? sanitize_key( (string) $attributes['taxonomy'] ) : 'mw_genre';
		if ( ! in_array( $taxonomy, self::ALLOWED_TAXONOMIES, true ) || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$source = isset( $attributes['source'] ) ? sanitize_key( (string) $attributes['source'] ) : 'all';
		$terms  = TermShelfSupport::query_terms(
			$taxonomy,
			'manual' === $source,
			isset( $attributes['termIds'] ) ? (string) $attributes['termIds'] : '',
			isset( $attributes['orderBy'] ) ? sanitize_key( (string) $attributes['orderBy'] ) : 'count',
			self::MAX_ITEMS
		);

		return TermShelfSupport::finalize_terms( $terms, $attributes, self::MAX_ITEMS, 'music_wave_terms_shelf_terms', $taxonomy );
	}

	/**
	 * Build one browse tile.
	 *
	 * @param WP_Term              $term    Term.
	 * @param array<string, mixed> $options Resolved options.
	 * @return string
	 */
	private function tile( WP_Term $term, array $options ): string {
		$link = get_term_link( $term );
		if ( is_wp_error( $link ) || ! is_string( $link ) || '' === $link ) {
			return '';
		}

		$name = '' !== $term->name ? $term->name : __( 'بدون عنوان', 'music-wave-core' );

		$count_html = '';
		if ( $options['show_count'] && (int) $term->count > 0 ) {
			$count_html = '<span class="mw-terms-shelf__count">' . esc_html(
				sprintf(
					/* translators: %s: number of published releases. */
					_n( 'انتشار %s', 'انتشار %s', (int) $term->count, 'music-wave-core' ),
					number_format_i18n( (int) $term->count )
				)
			) . '</span>';
		}
		/* translators: %s: taxonomy term name. */
		$open_label = sprintf( __( 'مرور %s', 'music-wave-core' ), $name );

		// Canonical per-term hue: derived from the term's own ID, so sorting,
		// reordering, or switching sources never repaints a term
		// (musicwave/assets/css/hues.css, 8 steps).
		$hue = TermHue::for_term( $term );

		$media_class = '';
		if ( 'colorful' === $options['card_style'] ) {
			$hue_class = TermHue::modifier( $hue );
			if ( '' !== $hue_class ) {
				$media_class = ' mw-term-media ' . $hue_class;
			}
		}

		return '<a class="mw-terms-shelf__tile' . $media_class
			. ' mw-terms-shelf__tile--style-' . esc_attr( (string) $options['card_style'] )
			. '" href="' . esc_url( $link ) . '" aria-label="' . esc_attr( $open_label ) . '">'
			. '<span class="mw-terms-shelf__name">' . esc_html( $name ) . '</span>'
			. $count_html
			. '</a>';
	}

	/**
	 * Build the shared shelf header chrome.
	 *
	 * @param array<string, mixed> $options Resolved options.
	 */
	private function header( array $options ): string {
		return TermShelfSupport::render_header(
			$options,
			__( 'فهرست کاتالوگ را مرور کنید', 'music-wave-core' ),
			__( 'همه چیز را ببینید', 'music-wave-core' )
		);
	}

	/**
	 * Editor-only placeholder so ServerSideRender never shows an error notice.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_editor_placeholder( array $attributes ): string {
		$label = __( 'ویترین طبقه‌بندی', 'music-wave-core' );
		$help  = isset( $attributes['source'] ) && 'manual' === $attributes['source']
			? __( 'هیچ اصطلاح منطبقی پیدا نشد. شناسه‌های اصطلاح انتخاب‌شده را بررسی کنید.', 'music-wave-core' )
			: __( 'هنوز هیچ اصطلاحی با انتشارهای منتشرشده پیدا نشده است. این طبقه‌بندی را به انتشارها اختصاص دهید تا خودکار اینجا ظاهر شوند.', 'music-wave-core' );

		return BlockSupport::editor_placeholder( $label, $help, 'mw-terms-shelf mw-terms-shelf--placeholder', 'dashicons-tag' );
	}
}
