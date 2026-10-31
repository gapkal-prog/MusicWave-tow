<?php
/**
 * Public catalog filter form & active result summary renderer
 * (`catalog-filters`, `catalog-results`) — SOLID SRP extraction.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks\Release;

use ManaCore\MusicWave\Core\Blocks\BlockSupport;
use ManaCore\MusicWave\Core\Catalog\ReleaseArchiveQuery;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Catalog\ReleaseTaxonomies;
use ManaCore\MusicWave\Core\Support\Settings;
use ManaCore\MusicWave\Core\Support\SuggestAssets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CatalogFilterRenderer {
	/**
	 * Render `music-wave/catalog-filters`.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_catalog_filters( array $attributes ): string {
		$archive_url = $this->catalog_action_url();
		if ( '' === $archive_url ) {
			return '';
		}

		$show_search = BlockSupport::bool_attribute( $attributes, 'showSearch', true );
		$show_sort   = BlockSupport::bool_attribute( $attributes, 'showSort', true );
		$show_reset  = BlockSupport::bool_attribute( $attributes, 'showReset', true );
		$max_terms   = BlockSupport::range_attribute( $attributes, 'maxTerms', 10, 200, 50 );

		$toggles = $this->filter_toggles();
		$fields  = array();
		foreach ( ReleaseTaxonomies::catalog_labels() as $taxonomy => $label ) {
			if ( ! BlockSupport::bool_attribute( $attributes, (string) ( $toggles[ $taxonomy ] ?? '' ), true ) ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					'number'     => $max_terms,
					'orderby'    => 'name',
					'order'      => 'ASC',
				)
			);
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			$selected = isset( $_GET[ $taxonomy ] ) && is_scalar( $_GET[ $taxonomy ] ) ? sanitize_title( wp_unslash( (string) $_GET[ $taxonomy ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$options  = '<option value="">' . esc_html(
				sprintf(
					/* translators: %s: filter label, e.g. Artist or Genre. */
					__( 'همه %s', 'music-wave-core' ),
					$label
				)
			) . '</option>';
			foreach ( $terms as $term ) {
				$options .= '<option value="' . esc_attr( $term->slug ) . '" ' . selected( $selected, $term->slug, false ) . '>' . esc_html( $term->name ) . '</option>';
			}
			$fields[] = '<label><span class="screen-reader-text">' . esc_html( $label ) . '</span><select name="' . esc_attr( $taxonomy ) . '">' . $options . '</select></label>';
		}
		if ( empty( $fields ) && ! $show_search && ! $show_sort ) {
			return '';
		}

		$search_html = '';
		if ( $show_search ) {
			$search      = isset( $_GET['s'] ) && is_scalar( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$placeholder = BlockSupport::text_attribute( $attributes, 'searchPlaceholder', __( 'جست‌وجوی انتشار', 'music-wave-core' ) );
			$search_html = '<div class="mw-catalog-suggest" data-mw-suggest="' . esc_attr( SuggestAssets::field_config() ) . '">'
				. '<label><span class="screen-reader-text">' . esc_html__( 'جست‌وجوی انتشار', 'music-wave-core' ) . '</span>'
				. '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr( $placeholder ) . '"></label>'
				. '<span class="screen-reader-text" role="status" aria-live="polite" data-mw-suggest-status></span>'
				. '</div>';
			if ( ! is_admin() ) {
				SuggestAssets::enqueue_with_defaults();
			}
		}

		$sort_markup = '';
		if ( $show_sort ) {
			$sort_options = ReleaseArchiveQuery::sort_options();
			$sort_var     = ReleaseArchiveQuery::SORT_QUERY_VAR;
			$has_sort     = isset( $_GET[ $sort_var ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw_sort     = $has_sort && is_scalar( $_GET[ $sort_var ] ) ? sanitize_key( wp_unslash( (string) $_GET[ $sort_var ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$sort         = ReleaseArchiveQuery::normalize_sort( $has_sort ? $raw_sort : Settings::get( 'archive_default_sort' ) );
			$sort_markup  = '<label class="mw-catalog-filters__sort"><span class="screen-reader-text">' . esc_html__( 'مرتب‌سازی انتشارات', 'music-wave-core' ) . '</span><select name="' . esc_attr( $sort_var ) . '">';
			foreach ( $sort_options as $value => $label ) {
				$sort_markup .= '<option value="' . esc_attr( $value ) . '" ' . selected( $sort, $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$sort_markup .= '</select></label>';
		}

		$submit_label = BlockSupport::text_attribute( $attributes, 'submitLabel', __( 'اعمال فیلتر', 'music-wave-core' ) );
		$actions      = '<button type="submit">' . esc_html( $submit_label ) . '</button>';
		if ( $show_reset ) {
			$reset_label = BlockSupport::text_attribute( $attributes, 'resetLabel', __( 'بازنشانی', 'music-wave-core' ) );
			$actions    .= '<a href="' . esc_url( $archive_url ) . '">' . esc_html( $reset_label ) . '</a>';
		}
		$actions = '<div class="mw-catalog-filters__actions">' . $actions . '</div>';

		$layout    = BlockSupport::key_attribute( $attributes, 'layout', array( 'inline', 'stacked' ), 'inline' );
		$variation = BlockSupport::style_variation( $attributes, array( 'stacked' ) );
		if ( '' !== $variation ) {
			$layout = $variation;
		}
		$class = 'mw-catalog-filters' . ( 'stacked' === $layout ? ' mw-catalog-filters--stacked' : '' );

		if ( ! is_admin() && function_exists( 'wp_enqueue_script' ) ) {
			wp_enqueue_script( 'music-wave-catalog-filters', MUSIC_WAVE_CORE_URL . 'assets/catalog-filters.js', array(), MUSIC_WAVE_CORE_VERSION, true );
			wp_localize_script(
				'music-wave-catalog-filters',
				'musicWaveCatalogFilters',
				array(
					'applying' => __( 'در حال اعمال…', 'music-wave-core' ),
				)
			);
		}

		$hidden = '<input type="hidden" name="post_type" value="' . esc_attr( ReleasePostType::KEY ) . '">';

		return '<form ' . BlockSupport::wrapper_attributes( $class ) . ' method="get" action="' . esc_url( $archive_url ) . '">' . $hidden . $search_html . implode( '', $fields ) . $sort_markup . $actions . '</form>';
	}

	/**
	 * Render `music-wave/catalog-results`.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_catalog_results( array $attributes ): string {
		$show_count = BlockSupport::bool_attribute( $attributes, 'showCount', true );
		$show_chips = BlockSupport::bool_attribute( $attributes, 'showChips', true );
		if ( ! $show_count && ! $show_chips ) {
			return '';
		}

		$archive_url = $this->catalog_action_url();
		if ( '' === $archive_url ) {
			return '';
		}

		$count_html = '';
		if ( $show_count ) {
			global $wp_query;
			$count   = is_object( $wp_query ) && isset( $wp_query->found_posts ) ? absint( $wp_query->found_posts ) : 0;
			$summary = sprintf(
				/* translators: %s: number of releases matching the current catalog filters. */
				_n( 'انتشار %s پیدا شد', 'انتشارهای %s پیدا شد', $count, 'music-wave-core' ),
				number_format_i18n( $count )
			);
			$count_html = '<p class="mw-catalog-results__count">' . esc_html( $summary ) . '</p>';
		}

		$chips      = $show_chips ? $this->active_catalog_filter_chips( $archive_url ) : array();
		$chips_html = empty( $chips ) ? '' : '<ul class="mw-catalog-results__filters" aria-label="' . esc_attr__( 'فیلترهای کاتالوگ فعال', 'music-wave-core' ) . '">' . implode( '', $chips ) . '</ul>';

		$variation     = BlockSupport::style_variation( $attributes, array( 'editorial' ) );
		$wrapper_class = 'mw-catalog-results' . ( '' !== $variation ? ' mw-catalog-results--' . $variation : '' );

		return '<div ' . BlockSupport::wrapper_attributes( $wrapper_class ) . ' role="status" aria-live="polite">' . $count_html . $chips_html . '</div>';
	}

	/**
	 * Block attribute that toggles each catalog filter field.
	 *
	 * @return array<string, string>
	 */
	private function filter_toggles(): array {
		return array(
			'mw_artist'       => 'showArtistFilter',
			'mw_genre'        => 'showGenreFilter',
			'mw_mood'         => 'showMoodFilter',
			'mw_release_type' => 'showTypeFilter',
		);
	}

	/**
	 * Resolve the URL the filter form, its reset link and its active-filter
	 * chips point at.
	 *
	 * Filtering must never drop a visitor out of the listing they are
	 * browsing: on a release taxonomy archive the links keep that term's
	 * permalink and only the query string changes, so filtering from an artist
	 * page stays on that artist instead of jumping to the release archive.
	 */
	private function catalog_action_url(): string {
		if ( is_tax( ReleaseTaxonomies::catalog_taxonomies() ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) && is_string( $link ) && '' !== $link ) {
					return $link;
				}
			}
		}

		$archive_url = get_post_type_archive_link( ReleasePostType::KEY );

		return is_string( $archive_url ) ? $archive_url : '';
	}

	/**
	 * @return array<int, string>
	 */
	private function active_catalog_filter_chips( string $base_url ): array {
		$args  = $this->current_catalog_query_args();
		$chips = array();

		if ( isset( $args['s'] ) ) {
			$without_search = $args;
			unset( $without_search['s'] );
			$chips[] = $this->catalog_filter_chip(
				sprintf(
					/* translators: %s: current search phrase. */
					__( 'جست‌وجو: %s', 'music-wave-core' ),
					$args['s']
				),
				$without_search,
				$base_url
			);
		}

		foreach ( ReleaseTaxonomies::catalog_labels() as $taxonomy => $label ) {
			if ( empty( $args[ $taxonomy ] ) ) {
				continue;
			}
			$term = get_term_by( 'slug', $args[ $taxonomy ], $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$without_filter = $args;
			unset( $without_filter[ $taxonomy ] );
			$chips[] = $this->catalog_filter_chip(
				sprintf(
					/* translators: 1: filter label such as Artist, 2: selected term name. */
					__( '%1$s: %2$s', 'music-wave-core' ),
					$label,
					$term->name
				),
				$without_filter,
				$base_url
			);
		}

		return $chips;
	}

	/**
	 * @return array<string, string>
	 */
	private function current_catalog_query_args(): array {
		$args = array();
		foreach ( ReleaseTaxonomies::catalog_taxonomies() as $taxonomy ) {
			$value = isset( $_GET[ $taxonomy ] ) && is_scalar( $_GET[ $taxonomy ] ) ? sanitize_title( wp_unslash( (string) $_GET[ $taxonomy ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '' !== $value ) {
				$args[ $taxonomy ] = $value;
			}
		}

		$search = isset( $_GET['s'] ) && is_scalar( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$sort_key = ReleaseArchiveQuery::SORT_QUERY_VAR;
		$sort     = isset( $_GET[ $sort_key ] ) && is_scalar( $_GET[ $sort_key ] ) ? sanitize_key( wp_unslash( (string) $_GET[ $sort_key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sort     = ReleaseArchiveQuery::normalize_sort( $sort );
		if ( 'latest' !== $sort && isset( $_GET[ $sort_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args[ $sort_key ] = $sort;
		}

		return $args;
	}

	/**
	 * @param array<string, string> $args     Remaining query arguments.
	 * @param string                $base_url Listing URL the chip returns to.
	 */
	private function catalog_filter_chip( string $label, array $args, string $base_url ): string {
		$url = empty( $args ) ? $base_url : add_query_arg( $args, $base_url );

		return '<li><a href="' . esc_url( $url ) . '"><span>' . esc_html( $label ) . '</span><span class="screen-reader-text"> ' . esc_html__( 'حذف فیلتر', 'music-wave-core' ) . '</span><span aria-hidden="true">×</span></a></li>';
	}
}
