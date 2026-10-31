<?php
declare(strict_types=1);

namespace ManaCore\MusicWave\Theme\Blocks;

use ManaCore\MusicWave\Core\Blocks\BlockSupport;
use ManaCore\MusicWave\Core\Blocks\ReleaseCard;
use ManaCore\MusicWave\Core\Blocks\SectionHeader;
use ManaCore\MusicWave\Core\Playlists\PlaylistRepository;
use ManaCore\MusicWave\Core\Support\Settings;
use WP_Block;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders release shelves, community playlist shelves, release sliders,
 * editorial feature layouts, and full-bleed hero crossfade sliders.
 */
final class ShelfAndSliderRenderer {

	/**
	 * Canonical release-card composition used by the standard Query Loop.
	 *
	 * It is the exact block list the bundled taxonomy templates ship
	 * (`taxonomy-mw_genre.html`, `taxonomy-mw_artist.html`). Keeping one copy
	 * here means a shelf that hands its full listing to WordPress renders the
	 * same card WYSIWYG in the Site Editor and on the front end.
	 */
	private const LOOP_CARD_BLOCKS = '<!-- wp:group {"className":"mw-release-card mw-surface","layout":{"type":"constrained"}} -->'
		. '<div class="wp-block-group mw-release-card mw-surface">'
		. '<!-- wp:group {"className":"mw-release-card__media","layout":{"type":"default"}} -->'
		. '<div class="wp-block-group mw-release-card__media">'
		. '<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"1"} /-->'
		. '<!-- wp:music-wave/preview-player {"buttonStyle":"icon","variant":"accent","size":"md","className":"mw-hero-slide__play"} /-->'
		. '</div><!-- /wp:group -->'
		. '<!-- wp:group {"className":"mw-release-card__body","layout":{"type":"constrained"}} -->'
		. '<div class="wp-block-group mw-release-card__body">'
		. '<!-- wp:post-title {"isLink":true,"fontSize":"medium"} /-->'
		. '<!-- wp:music-wave/release-meta /-->'
		. '</div><!-- /wp:group -->'
		. '</div><!-- /wp:group -->';

	/** @var BlockRegistry */
	private BlockRegistry $blocks;

	/**
	 * Extra query vars for Query Loop blocks rendered by this request, keyed by
	 * the synthetic `queryId` the shelf generated for that loop.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $loop_query_overrides = array();

	/** Per-request counter that keeps every shelf loop's `queryId` unique. */
	private static int $loop_sequence = 0;

	/** `queryId` base that cannot collide with the bundled templates (12/31/32). */
	private const LOOP_QUERY_ID_BASE = 900000;

	public function __construct( BlockRegistry $blocks ) {
		$this->blocks = $blocks;
	}

	/**
	 * Resolve a slider toggle attribute against global Core Settings.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function slider_toggle( array $attributes, string $attribute, string $setting ): bool {
		$value = isset( $attributes[ $attribute ] ) && is_scalar( $attributes[ $attribute ] ) ? sanitize_key( (string) $attributes[ $attribute ] ) : 'inherit';
		if ( 'enabled' === $value || 'disabled' === $value ) {
			return 'enabled' === $value;
		}

		if ( class_exists( Settings::class ) ) {
			return 'enabled' === Settings::get( $setting );
		}

		return true;
	}

	/**
	 * Resolve a numeric slider default from global Core Settings when available.
	 */
	public function slider_number( string $setting, int $fallback ): int {
		if ( class_exists( Settings::class ) ) {
			return absint( Settings::get( $setting ) );
		}

		return $fallback;
	}

	/**
	 * Fetch shared card presentation data from MusicWave Core.
	 *
	 * @return array<string, mixed>
	 */
	public function release_presentation_data( int $release_id ): array {
		if ( class_exists( ReleaseCard::class ) ) {
			return ReleaseCard::presentation_data( $release_id );
		}

		return array();
	}

	/**
	 * Render a single release card using Core's canonical ReleaseCard component.
	 *
	 * @param array<string, mixed> $item    Presentation payload.
	 * @param array<string, mixed> $options Card render options.
	 */
	public function render_release_card( array $item, array $options ): string {
		if ( ! class_exists( ReleaseCard::class ) ) {
			return '';
		}

		return ReleaseCard::render( $item, $options );
	}

	/**
	 * Render a shared shelf section header, delegating to Core's SectionHeader when active.
	 */
	public function render_shelf_header( string $eyebrow, string $title, string $description = '', string $section_url = '', string $section_link_label = '', string $icon = '' ): string {
		if ( '' === $eyebrow && '' === $title && '' === $description && '' === $section_url ) {
			return '';
		}

		$section_link_label = '' !== $section_link_label ? $section_link_label : __( 'مشاهده همه', 'musicwave' );

		if ( class_exists( SectionHeader::class ) ) {
			return SectionHeader::shelf_header( $eyebrow, $title, $description, $section_url, $section_link_label, $icon );
		}

		$link = '' !== $section_url
			? '<a class="mw-release-shelf__more" href="' . esc_url( $section_url ) . '">' . esc_html( $section_link_label ) . '<span aria-hidden="true">&rarr;</span></a>'
			: '';

		return '<header class="mw-release-shelf__header"><div>'
			. ( '' !== $eyebrow ? '<span>' . esc_html( $eyebrow ) . '</span>' : '' )
			. ( '' !== $title ? '<h2>' . esc_html( $title ) . '</h2>' : '' )
			. ( '' !== $description ? '<p>' . esc_html( $description ) . '</p>' : '' )
			. '</div>' . $link . '</header>';
	}

	private const FILTER_PERIOD_WINDOWS = array(
		'today' => '1 day ago',
		'day'   => '1 day ago',
		'week'  => '1 week ago',
		'7d'    => '7 days ago',
		'month' => '1 month ago',
		'30d'   => '30 days ago',
		'year'  => '1 year ago',
		'all'   => '',
	);

	/**
	 * Normalize a color input from block attributes so hex strings with or without `#`
	 * as well as safe `var(--wp--preset--color--...)` values work reliably.
	 */
	public function normalize_color_value( string $raw ): string {
		$value = trim( $raw );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value ) ) {
			$value = '#' . $value;
		}
		$hex = sanitize_hex_color( $value );
		if ( is_string( $hex ) && '' !== $hex ) {
			return $hex;
		}
		if ( preg_match( '/^var\(--[a-z0-9-]+\)$/i', $value ) ) {
			return $value;
		}

		return '';
	}

	/**
	 * Build a deterministic, page-stable scope key for filter tabs on a block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function filter_scope_key( string $prefix, array $attributes, string $fallback_instance ): string {
		$raw = isset( $attributes['filterTabs'] ) ? trim( (string) $attributes['filterTabs'] ) : '';
		if ( '' === $raw ) {
			return sanitize_html_class( $fallback_instance );
		}
		$seed = $prefix
			. '|' . (string) ( $attributes['title'] ?? '' )
			. '|' . (string) ( $attributes['eyebrow'] ?? '' )
			. '|' . (string) ( $attributes['layout'] ?? '' )
			. '|' . $raw;

		return sanitize_html_class( $prefix . '-' . substr( md5( $seed ), 0, 8 ) );
	}

	/**
	 * Build WP_Query arguments shared across release sliders and shelves.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<string, mixed>
	 */
	public function release_query_args( array $attributes, int $items ): array {
		$order_by = isset( $attributes['orderBy'] ) ? sanitize_key( (string) $attributes['orderBy'] ) : 'date';
		$order_by = in_array( $order_by, array( 'date', 'title', 'rand', 'modified', 'views' ), true ) ? $order_by : 'date';
		$order    = isset( $attributes['order'] ) && 'ASC' === strtoupper( (string) $attributes['order'] ) ? 'ASC' : 'DESC';
		$query    = array(
			'post_type'              => 'mw_release',
			'post_status'            => 'publish',
			'posts_per_page'         => $items,
			'fields'                 => 'ids',
			'orderby'                => 'views' === $order_by ? 'meta_value_num' : $order_by,
			'order'                  => $order,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => true,
		);

		if ( 'views' === $order_by ) {
			$query['meta_key'] = 'mw_views'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		}

		$release_ids = isset( $attributes['releaseIds'] ) ? (string) $attributes['releaseIds'] : '';
		$release_ids = preg_split( '/[\s,]+/', $release_ids );
		// Manual picks honour the block's item count, and stay unrestricted when
		// the author asked for every release (`showAll` passes -1).
		$id_limit    = $items > 0 ? max( 24, $items ) : 1000;
		$release_ids = is_array( $release_ids ) ? array_slice( array_values( array_unique( array_filter( array_map( 'absint', $release_ids ) ) ) ), 0, $id_limit ) : array();
		if ( ! empty( $release_ids ) ) {
			$query['post__in'] = $release_ids;
			$query['orderby']  = 'post__in';
		}

		$period = isset( $attributes['datePeriod'] ) ? sanitize_key( (string) $attributes['datePeriod'] ) : '';
		if ( '' !== $period && isset( self::FILTER_PERIOD_WINDOWS[ $period ] ) && '' !== self::FILTER_PERIOD_WINDOWS[ $period ] ) {
			$query['date_query'] = array(
				array(
					'after'     => self::FILTER_PERIOD_WINDOWS[ $period ],
					'inclusive' => true,
				),
			);
		}

		$taxonomy     = isset( $attributes['taxonomy'] ) ? sanitize_key( (string) $attributes['taxonomy'] ) : '';
		$term         = isset( $attributes['termSlug'] ) ? sanitize_title( (string) $attributes['termSlug'] ) : '';
		$content_type = isset( $attributes['contentType'] ) ? sanitize_key( (string) $attributes['contentType'] ) : '';
		$tax_query    = array();

		if ( in_array( $taxonomy, array( 'mw_artist', 'mw_genre', 'mw_mood', 'mw_release_type', 'mw_label' ), true ) && '' !== $term ) {
			$tax_query[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => array( $term ),
			);
		}
		if ( '' !== $content_type && taxonomy_exists( 'mw_release_type' ) ) {
			$tax_query[] = array(
				'taxonomy' => 'mw_release_type',
				'field'    => 'slug',
				'terms'    => array( $content_type ),
			);
		}
		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}
		if ( ! empty( $tax_query ) ) {
			$query['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		return $query;
	}

	/**
	 * Parse inspector filter-tab lines into structured descriptors.
	 *
	 * Supported single or `+`-combined directives after `|`:
	 * - `all`
	 * - `today` / `week` / `month` / `year` or `period:today|week|month|year|all`
	 * - `orderBy:date|views|rand|title|modified`
	 * - `taxonomy:mw_genre:pop` (or `mw_mood`, `mw_artist`, `mw_release_type`, `mw_label`)
	 * - `type:album|single|ep|podcast_show`
	 *
	 * @return array<int, array{slug: string, label: string, orderBy: string, taxonomy: string, termSlug: string, contentType: string, period: string, resetAll: bool}>
	 */
	public function parse_filter_tabs( string $raw ): array {
		$tabs = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			$label = sanitize_text_field( $parts[0] );
			if ( '' === $label ) {
				continue;
			}
			$spec         = isset( $parts[1] ) ? trim( $parts[1] ) : '';
			$spec_slug    = '' !== $spec ? trim( (string) preg_replace( '/-+/', '-', sanitize_key( str_replace( array( ':', '+', ',', ' ' ), '-', $spec ) ) ), '-' ) : '';
			$slug         = '' !== $spec_slug ? $spec_slug : sanitize_title( $label );
			$order        = '';
			$tax          = '';
			$term         = '';
			$content_type = '';
			$period       = '';
			$reset_all    = false;

			if ( '' !== $spec ) {
				$clauses = preg_split( '/\s*[+,]\s*/', $spec );
				$clauses = is_array( $clauses ) ? $clauses : array();
				foreach ( $clauses as $clause ) {
					$clause = trim( $clause );
					if ( '' === $clause ) {
						continue;
					}
					$bits = array_map( 'trim', explode( ':', $clause ) );
					$key  = isset( $bits[0] ) ? sanitize_key( $bits[0] ) : '';
					if ( 'all' === $key && 1 === count( $bits ) ) {
						$reset_all = true;
						$period    = 'all';
					} elseif ( isset( self::FILTER_PERIOD_WINDOWS[ $key ] ) && 1 === count( $bits ) ) {
						$period = $key;
					} elseif ( in_array( $key, array( 'date', 'title', 'rand', 'modified', 'views', 'plays' ), true ) && 1 === count( $bits ) ) {
						$order = 'plays' === $key ? 'views' : $key;
					} elseif ( ( 'period' === $key || 'range' === $key || 'window' === $key ) && isset( $bits[1] ) ) {
						$candidate = sanitize_key( $bits[1] );
						if ( isset( self::FILTER_PERIOD_WINDOWS[ $candidate ] ) ) {
							$period = $candidate;
						}
					} elseif ( 'orderby' === $key && isset( $bits[1] ) ) {
						$candidate = sanitize_key( $bits[1] );
						$order     = in_array( $candidate, array( 'date', 'title', 'rand', 'modified', 'views' ), true ) ? $candidate : '';
					} elseif ( 'taxonomy' === $key && isset( $bits[1], $bits[2] ) ) {
						$candidate = sanitize_key( $bits[1] );
						$tax       = in_array( $candidate, array( 'mw_artist', 'mw_genre', 'mw_mood', 'mw_release_type', 'mw_label' ), true ) ? $candidate : '';
						$term      = sanitize_title( $bits[2] );
					} elseif ( ( 'type' === $key || 'contenttype' === $key ) && isset( $bits[1] ) ) {
						$content_type = sanitize_key( $bits[1] );
					} elseif ( in_array( $key, array( 'mw_artist', 'mw_genre', 'mw_mood', 'mw_release_type', 'mw_label' ), true ) && isset( $bits[1] ) ) {
						$tax  = $key;
						$term = sanitize_title( $bits[1] );
					}
				}
			}
			if ( '' === $slug ) {
				$slug = 'tab-' . (string) ( count( $tabs ) + 1 );
			}
			$existing_slugs = array_column( $tabs, 'slug' );
			if ( in_array( $slug, $existing_slugs, true ) ) {
				$slug .= '-' . (string) ( count( $tabs ) + 1 );
			}
			$tabs[] = array(
				'slug'        => $slug,
				'label'       => $label,
				'orderBy'     => $order,
				'taxonomy'    => $tax,
				'termSlug'    => $term,
				'contentType' => $content_type,
				'period'      => $period,
				'resetAll'    => $reset_all,
			);
			if ( count( $tabs ) >= 8 ) {
				break;
			}
		}

		return $tabs;
	}

	/**
	 * Apply requested filter tab query parameter to block attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array{attributes: array<string, mixed>, tabs: array<int, array<string, mixed>>, active: string}
	 */
	public function apply_filter_tab( array $attributes, string $instance ): array {
		$raw  = isset( $attributes['filterTabs'] ) ? (string) $attributes['filterTabs'] : '';
		$tabs = $this->parse_filter_tabs( $raw );
		if ( array() === $tabs ) {
			return array(
				'attributes' => $attributes,
				'tabs'       => array(),
				'active'     => '',
			);
		}

		if ( function_exists( 'wp_enqueue_script' ) ) {
			wp_enqueue_script( 'musicwave-slider' );
		}

		$key    = 'mw_tab_' . sanitize_html_class( $instance );
		$active = '';
		if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$active = sanitize_title( wp_unslash( (string) $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$match = null;
		foreach ( $tabs as $tab ) {
			if ( $tab['slug'] === $active ) {
				$match = $tab;
				break;
			}
		}
		if ( null === $match ) {
			$match  = $tabs[0];
			$active = (string) $match['slug'];
		}
		if ( ! empty( $match['resetAll'] ) ) {
			$attributes['datePeriod'] = '';
		}
		if ( '' !== $match['period'] && 'all' !== $match['period'] ) {
			$attributes['datePeriod'] = $match['period'];
		}
		if ( '' !== $match['orderBy'] ) {
			$attributes['orderBy'] = $match['orderBy'];
		}
		if ( '' !== $match['taxonomy'] && '' !== $match['termSlug'] ) {
			$attributes['taxonomy'] = $match['taxonomy'];
			$attributes['termSlug'] = $match['termSlug'];
		}
		if ( '' !== $match['contentType'] ) {
			$attributes['contentType'] = $match['contentType'];
		}

		return array(
			'attributes' => $attributes,
			'tabs'       => $tabs,
			'active'     => $active,
		);
	}

	/**
	 * Render filter-tab pill bar with progressive enhancement hooks.
	 *
	 * @param array<int, array<string, mixed>> $tabs Parsed tab descriptors.
	 */
	public function render_filter_tabs( array $tabs, string $active, string $instance ): string {
		if ( array() === $tabs ) {
			return '';
		}

		$key     = 'mw_tab_' . sanitize_html_class( $instance );
		$current = class_exists( BlockSupport::class )
			? BlockSupport::current_url()
			: $this->current_request_url();
		$items   = '';
		foreach ( $tabs as $tab ) {
			$slug   = (string) $tab['slug'];
			$label  = (string) $tab['label'];
			$url    = add_query_arg( array( $key => $slug ), remove_query_arg( $key, $current ) );
			$is_on  = $slug === $active;
			$items .= '<a class="mw-filter-tabs__tab' . ( $is_on ? ' is-active' : '' ) . '" href="' . esc_url( $url ) . '" data-mw-filter-tab="' . esc_attr( $slug ) . '" data-mw-no-swup="1"' . ( $is_on ? ' aria-current="true"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}

		return '<nav class="mw-filter-tabs" data-mw-filter-tabs="' . esc_attr( sanitize_html_class( $instance ) ) . '" aria-label="' . esc_attr__( 'فیلتر بازه و سبک', 'musicwave' ) . '">' . $items . '</nav>';
	}

	/**
	 * Current request URL fallback when Core is inactive.
	 */
	public function current_request_url(): string {
		if ( function_exists( 'is_singular' ) && is_singular() ) {
			$permalink = get_permalink();
			if ( is_string( $permalink ) && '' !== $permalink ) {
				return $permalink;
			}
		}
		$request = isset( $_SERVER['REQUEST_URI'] ) && is_scalar( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';

		return home_url( $request );
	}

	/**
	 * Floating previous/next navigation buttons for horizontal scroll shelves.
	 */
	public function shelf_nav_markup( string $layout, string $viewport_id = '' ): string {
		if ( 'scroll' !== $layout ) {
			return '';
		}
		if ( function_exists( 'wp_enqueue_script' ) ) {
			wp_enqueue_script( 'musicwave-slider' );
		}

		$chevron_previous = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>';
		$chevron_next     = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 18 6-6-6-6"/></svg>';
		$controls         = '' !== $viewport_id ? ' aria-controls="' . esc_attr( $viewport_id ) . '"' : '';

		return '<div class="mw-release-shelf__nav">'
			. '<button type="button" class="mw-release-shelf__nav-button" data-mw-shelf-previous aria-label="' . esc_attr__( 'قبلی', 'musicwave' ) . '"' . $controls . ' disabled>' . $chevron_previous . '</button>'
			. '<button type="button" class="mw-release-shelf__nav-button" data-mw-shelf-next aria-label="' . esc_attr__( 'بعدی', 'musicwave' ) . '"' . $controls . ' disabled>' . $chevron_next . '</button>'
			. '</div>';
	}

	/**
	 * Render responsive accessible release slider block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_release_slider( array $attributes ): string {
		if ( ! post_type_exists( 'mw_release' ) || ! $this->slider_toggle( $attributes, 'enabled', 'slider_enabled' ) ) {
			return '';
		}

		if ( function_exists( 'wp_enqueue_script' ) ) {
			wp_enqueue_script( 'musicwave-slider' );
		}

		$instance   = wp_unique_id( 'mw-slider-' );
		$tab_scope  = $this->filter_scope_key( 'mw-slider', $attributes, $instance );
		$tabbed     = $this->apply_filter_tab( $attributes, $tab_scope );
		$attributes = $tabbed['attributes'];

		$items = isset( $attributes['itemsToShow'] ) ? absint( $attributes['itemsToShow'] ) : 0;
		$items = $items >= 3 && $items <= 12 ? $items : $this->slider_number( 'slider_items', 6 );
		// A rail only ever shows a bounded window of slides, so «load every
		// release» is never useful here: it costs an unbounded query and the
		// extra posts are unreachable. The saved attribute is ignored instead
		// of honoured, which keeps old content rendering (capped, not broken).
		$items = min( 12, max( 3, $items ) );
		$ids   = get_posts( $this->release_query_args( $attributes, $items ) );
		if ( ( ! is_array( $ids ) || empty( $ids ) ) && empty( $tabbed['tabs'] ) ) {
			return '';
		}
		$ids = is_array( $ids ) ? $ids : array();

		$cards      = array();
		$size_key   = isset( $attributes['imageSize'] ) ? sanitize_key( (string) $attributes['imageSize'] ) : 'medium';
		$size_key   = in_array( $size_key, array( 'small', 'medium', 'large' ), true ) ? $size_key : 'medium';
		$thumb_size = 'large' === $size_key ? 'large' : ( 'small' === $size_key ? 'medium' : 'medium_large' );
		$shape      = isset( $attributes['imageShape'] ) ? sanitize_key( (string) $attributes['imageShape'] ) : 'square';
		$shape      = in_array( $shape, array( 'square', 'landscape', 'portrait', 'circle' ), true ) ? $shape : 'square';

		// Columns drive the card width. A zero value (or legacy saved block)
		// falls back to the card-size preset so older sliders keep their look.
		$columns = isset( $attributes['columns'] ) ? absint( $attributes['columns'] ) : 0;
		if ( $columns < 2 || $columns > 6 ) {
			$columns = 'small' === $size_key ? 5 : ( 'large' === $size_key ? 3 : 4 );
		}
		$columns = min( 6, max( 2, $columns ) );

		foreach ( $ids as $release_id ) {
			$item = $this->release_presentation_data( absint( $release_id ) );
			if ( empty( $item ) ) {
				continue;
			}

			$release_id = (int) $item['id'];
			$link       = (string) $item['link'];
			$title      = (string) $item['title'];
			$artist     = '' !== (string) $item['artist'] ? (string) $item['artist'] : __( 'انتشار MusicWave', 'musicwave' );
			$image      = get_the_post_thumbnail(
				$release_id,
				$thumb_size,
				array(
					'class' => 'mw-release-slider__image',
					'alt'   => '',
				)
			);
			$initial    = (string) $item['initial'];
			$image      = '' !== $image
				? $image
				: '<span class="mw-release-slider__placeholder" aria-hidden="true">' . esc_html( $initial ) . '</span>';
			$excerpt    = '';
			if ( ! empty( $attributes['showExcerpt'] ) ) {
				$release_excerpt = get_the_excerpt( $release_id );
				$excerpt         = '' !== $release_excerpt ? '<p>' . esc_html( wp_trim_words( $release_excerpt, 16 ) ) . '</p>' : '';
			}
			$artist_markup = ( ! isset( $attributes['showArtist'] ) || false !== $attributes['showArtist'] ) ? '<span class="mw-release-slider__artist">' . esc_html( $artist ) . '</span>' : '';
			$date_markup   = ! empty( $attributes['showDate'] ) ? '<span class="mw-release-slider__date">' . esc_html( get_the_date( '', $release_id ) ) . '</span>' : '';
			$views_markup  = '';
			if ( ! empty( $attributes['showViews'] ) ) {
				$views        = absint( get_post_meta( $release_id, 'mw_views', true ) );
				$views_markup = $views > 0 ? '<span class="mw-release-slider__views">' . esc_html( number_format_i18n( $views ) ) . ' ' . esc_html__( 'بازدیدها', 'musicwave' ) . '</span>' : '';
			}

			$play_button = apply_filters( 'music_wave_card_play_button', '', $release_id, 'mw-release-slider__play' );
			$play_button = is_string( $play_button ) && '' !== $play_button ? $play_button : '<span class="mw-release-slider__play" aria-hidden="true">&#9654;</span>';

			/* translators: %s: music release title. */
			$slide_label = sprintf( __( 'باز کردن %s', 'musicwave' ), $title );
			$cards[]     = '<article class="mw-release-slider__slide" role="group" aria-label="' . esc_attr( $slide_label ) . '"><div class="mw-release-slider__artwrap"><a class="mw-release-slider__art mw-release-slider__art--' . esc_attr( $shape ) . '" href="' . esc_url( $link ) . '">' . $image . '</a>' . $play_button . '</div><div class="mw-release-slider__body"><h3><a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a></h3>' . $artist_markup . $date_markup . $views_markup . $excerpt . '</div></article>';
		}
		if ( empty( $cards ) && empty( $tabbed['tabs'] ) ) {
			return '';
		}

		$autoplay       = $this->slider_toggle( $attributes, 'autoplay', 'slider_autoplay' );
		$loop           = $this->slider_toggle( $attributes, 'loop', 'slider_loop' );
		$pause          = $this->slider_toggle( $attributes, 'pauseOnHover', 'slider_pause_on_hover' );
		$arrows         = ! empty( $cards ) && $this->slider_toggle( $attributes, 'showArrows', 'slider_show_arrows' );
		$dots           = ! empty( $cards ) && $this->slider_toggle( $attributes, 'showDots', 'slider_show_dots' );
		$slider_overlay = isset( $attributes['overlay'] ) ? min( 100, max( 0, absint( $attributes['overlay'] ) ) ) / 100 : 0.55;
		$interval       = isset( $attributes['interval'] ) ? absint( $attributes['interval'] ) : 0;
		$interval       = $interval >= 2000 && $interval <= 20000 ? $interval : $this->slider_number( 'slider_interval', 5000 );
		$interval       = min( 20000, max( 2000, $interval ) );
		$eyebrow        = isset( $attributes['eyebrow'] ) ? sanitize_text_field( (string) $attributes['eyebrow'] ) : '';
		$title          = isset( $attributes['title'] ) ? sanitize_text_field( (string) $attributes['title'] ) : '';
		// Fill one-insert copy only when a header is requested.
		if ( '' !== $eyebrow || '' !== $title ) {
			$eyebrow = '' !== $eyebrow ? $eyebrow : __( 'برای شما', 'musicwave' );
			$title   = '' !== $title ? $title : __( 'انتشارهای منتخب', 'musicwave' );
		}
		$id                 = $instance;
		$controls           = $arrows ? '<div class="mw-release-slider__arrows"><button type="button" data-mw-slider-previous aria-controls="' . esc_attr( $id ) . '" aria-label="' . esc_attr__( 'انتشارهای قبلی', 'musicwave' ) . '">&#8592;</button><button type="button" data-mw-slider-next aria-controls="' . esc_attr( $id ) . '" aria-label="' . esc_attr__( 'انتشارهای بعدی', 'musicwave' ) . '">&#8594;</button></div>' : '';
		$dot_container      = $dots ? '<div class="mw-release-slider__dots" data-mw-slider-dots aria-label="' . esc_attr__( 'صفحه‌بندی اسلایدر', 'musicwave' ) . '"></div>' : '';
		$section_url        = isset( $attributes['sectionUrl'] ) ? esc_url( (string) $attributes['sectionUrl'] ) : '';
		$section_link_label = isset( $attributes['sectionLinkLabel'] ) ? sanitize_text_field( (string) $attributes['sectionLinkLabel'] ) : '';
		$section_link_label = '' !== $section_link_label ? $section_link_label : __( 'مشاهده همه', 'musicwave' );
		$more               = '' !== $section_url
			? '<a class="mw-release-slider__more" href="' . esc_url( $section_url ) . '">' . esc_html( $section_link_label ) . '<span aria-hidden="true">&rarr;</span></a>'
			: '';
		$tabs_markup        = $this->render_filter_tabs( $tabbed['tabs'], $tabbed['active'], $tab_scope );

		$slider_variant = $this->blocks->style_variant_class(
			$attributes,
			array_keys( $this->blocks->presentation_style_variations()['release-slider'] ),
			'mw-release-slider'
		);

		$heading_markup = '' !== $title ? '<div><span class="mw-release-slider__eyebrow">' . esc_html( $eyebrow ) . '</span><h2>' . esc_html( $title ) . '</h2></div>' : '';
		$tools_markup   = $tabs_markup . $more . $controls;
		$header_markup  = '' !== $heading_markup || '' !== $tools_markup ? '<header class="mw-release-slider__header">' . $heading_markup . '<div class="mw-release-slider__tools">' . $tools_markup . '</div></header>' : '';
		$body_markup    = ! empty( $cards )
			? '<div id="' . esc_attr( $id ) . '" class="mw-release-slider__viewport" data-mw-slider-viewport tabindex="0"><div class="mw-release-slider__track">' . implode( '', $cards ) . '</div></div>'
			: '<p class="mw-filter-tabs__empty" role="status">' . esc_html__( 'در این بازه یا دسته هنوز انتشاری ثبت نشده است.', 'musicwave' ) . '</p>';
		$scope_attr     = ! empty( $tabbed['tabs'] ) ? ' data-mw-filter-scope="' . esc_attr( $tab_scope ) . '"' : '';

		return '<section ' . get_block_wrapper_attributes(
			array(
				'class' => 'mw-release-slider mw-release-slider--columns-' . $columns . ' mw-release-slider--shape-' . $shape . $slider_variant,
				'style' => '--mw-feature-overlay:' . (string) $slider_overlay . ';',
			)
		) . $scope_attr . ' data-mw-slider data-autoplay="' . esc_attr( $autoplay ? '1' : '0' ) . '" data-loop="' . esc_attr( $loop ? '1' : '0' ) . '" data-pause-hover="' . esc_attr( $pause ? '1' : '0' ) . '" data-interval="' . esc_attr( (string) $interval ) . '">' . $header_markup . $body_markup . $dot_container . '<p class="screen-reader-text" aria-live="polite" data-mw-slider-status></p></section>';
	}

	/**
	 * Render community public-playlist shelf.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_playlist_shelf( array $attributes ): string {
		if ( ! class_exists( PlaylistRepository::class ) ) {
			return '';
		}

		$items   = isset( $attributes['itemsToShow'] ) ? absint( $attributes['itemsToShow'] ) : 8;
		$items   = min( 24, max( 1, $items ) );
		$columns = isset( $attributes['columns'] ) ? absint( $attributes['columns'] ) : 4;
		$columns = min( 6, max( 2, $columns ) );
		$layout  = isset( $attributes['layout'] ) ? sanitize_key( (string) $attributes['layout'] ) : 'grid';
		$layout  = in_array( $layout, array( 'grid', 'scroll', 'list' ), true ) ? $layout : 'grid';
		$shape   = isset( $attributes['imageShape'] ) ? sanitize_key( (string) $attributes['imageShape'] ) : 'square';
		$shape   = in_array( $shape, array( 'square', 'landscape', 'portrait', 'circle' ), true ) ? $shape : 'square';

		$orderby = isset( $attributes['playlistOrderBy'] ) ? sanitize_key( (string) $attributes['playlistOrderBy'] ) : '';
		if ( '' === $orderby ) {
			$orderby = isset( $attributes['orderBy'] ) ? sanitize_key( (string) $attributes['orderBy'] ) : 'updated_at';
		}
		$orderby = in_array( $orderby, array( 'updated_at', 'created_at', 'title', 'date' ), true ) ? $orderby : 'updated_at';
		if ( 'date' === $orderby ) {
			$orderby = 'updated_at';
		}
		$search = isset( $attributes['playlistSearch'] ) ? sanitize_text_field( (string) $attributes['playlistSearch'] ) : '';
		$search = trim( $search );
		if ( '' === $search && isset( $_GET['s'] ) && is_scalar( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$search = sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$repo      = new PlaylistRepository();
		$viewer_id = get_current_user_id();
		$playlists = $repo->public_playlists( $items, 0, $search, $orderby, $viewer_id );
		if ( array() === $playlists ) {
			return '';
		}

		$eyebrow            = isset( $attributes['eyebrow'] ) ? sanitize_text_field( (string) $attributes['eyebrow'] ) : '';
		$title              = isset( $attributes['title'] ) ? sanitize_text_field( (string) $attributes['title'] ) : '';
		$description        = isset( $attributes['description'] ) ? sanitize_text_field( (string) $attributes['description'] ) : '';
		$section_url        = isset( $attributes['sectionUrl'] ) ? esc_url( (string) $attributes['sectionUrl'] ) : '';
		$section_link_label = isset( $attributes['sectionLinkLabel'] ) ? sanitize_text_field( (string) $attributes['sectionLinkLabel'] ) : '';
		$section_link_label = '' !== $section_link_label ? $section_link_label : __( 'مشاهدهٔ همهٔ فهرست‌های پخش', 'musicwave' );
		$header_icon        = isset( $attributes['icon'] ) ? trim( (string) $attributes['icon'] ) : '';
		if ( '' !== $eyebrow || '' !== $title ) {
			$title   = '' !== $title ? $title : __( 'فهرست‌های پخش اجتماعی', 'musicwave' );
			$eyebrow = '' !== $eyebrow ? $eyebrow : __( 'منتخب شنوندگان', 'musicwave' );
		}

		$playlist_url = $section_url;
		if ( '' === $playlist_url ) {
			$page         = get_page_by_path( 'playlists' );
			$playlist_url = $page instanceof WP_Post ? (string) get_permalink( $page ) : home_url( '/playlists/' );
		}
		if ( '' === $section_url && ( '' !== $eyebrow || '' !== $title ) ) {
			$section_url = $playlist_url;
		}

		$cards = array();
		foreach ( $playlists as $p_idx => $playlist ) {
			$pid    = (int) $playlist['id'];
			$ptitle = (string) $playlist['title'];
			$author = isset( $playlist['author_name'] ) ? (string) $playlist['author_name'] : '';
			$count  = isset( $playlist['count'] ) ? (int) $playlist['count'] : 0;
			$link   = add_query_arg( array( 'mw-playlist' => (string) $pid ), $playlist_url );

			$items_view = $repo->items_for_viewer( $pid, $viewer_id );
			$ids        = array_slice(
				array_map(
					static function ( array $i ): int {
						return absint( $i['release_id'] );
					},
					$items_view
				),
				0,
				4
			);

			if ( array() === $ids ) {
				$art_grid = '<span class="mw-playlists__art-grid mw-playlists__art-grid--empty" aria-hidden="true"><span class="mw-playlists__art-placeholder">♫</span></span>';
			} else {
				$cells = '';
				foreach ( $ids as $cell_idx => $rid ) {
					$is_first = 0 === $p_idx && 0 === $cell_idx;
					$thumb    = get_the_post_thumbnail(
						$rid,
						'medium',
						array(
							'class'         => 'mw-release-shelf__image',
							'alt'           => '',
							'loading'       => $is_first ? 'eager' : 'lazy',
							'fetchpriority' => $is_first ? 'high' : 'low',
							'decoding'      => 'async',
						)
					);
					if ( '' !== $thumb ) {
						$cells .= '<span class="mw-playlists__art-cell">' . $thumb . '</span>';
					} else {
						$init   = mb_substr( $ptitle, 0, 1 );
						$cells .= '<span class="mw-playlists__art-cell mw-playlists__art-cell--fallback" aria-hidden="true"><span>' . esc_html( $init ) . '</span></span>';
					}
				}
				for ( $p = count( $ids ); $p < 4; $p++ ) {
					$cells .= '<span class="mw-playlists__art-cell mw-playlists__art-cell--empty" aria-hidden="true"></span>';
				}
				$art_grid = '<span class="mw-playlists__art-grid">' . $cells . '</span>';
			}

			$play = '<button type="button" class="mw-release-shelf__play mw-card-play mw-release-shelf__play--playlist" data-mw-playlist-play data-playlist-id="' . esc_attr( (string) $pid ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: playlist title. */ __( 'پخش همه قطعه‌های %s', 'musicwave' ), $ptitle ) ) . '"' . ( 0 === $count ? ' disabled' : '' ) . '><span aria-hidden="true">▶</span></button>';

			/* translators: %s: playlist title. */
			$thumb_wrap = '<div class="mw-release-shelf__artwrap"><a class="mw-release-shelf__art mw-release-shelf__art--' . esc_attr( $shape ) . ' mw-release-shelf__art--playlist" href="' . esc_url( $link ) . '" aria-label="' . esc_attr( sprintf( __( 'باز کردن %s', 'musicwave' ), $ptitle ) ) . '">' . $art_grid . '</a>' . $play . '</div>';
			$artist_m   = '' !== $author ? '<span class="mw-release-shelf__artist">' . esc_html( $author ) . '</span>' : '';
			/* translators: %d: number of tracks in playlist. */
			$count_m = '<span class="mw-release-shelf__count">' . esc_html( sprintf( _n( '%d قطعه', '%d قطعه', $count, 'musicwave' ), $count ) ) . '</span>';
			$cards[] = '<article class="mw-release-shelf__item mw-release-shelf__item--playlist">' . $thumb_wrap . '<div class="mw-release-shelf__body"><h3><a href="' . esc_url( $link ) . '">' . esc_html( $ptitle ) . '</a></h3>' . $artist_m . $count_m . '</div></article>';
		}

		$header      = $this->render_shelf_header( $eyebrow, $title, $description, $section_url, $section_link_label, $header_icon );
		$viewport_id = wp_unique_id( 'mw-shelf-viewport-' );
		$nav         = $this->shelf_nav_markup( $layout, $viewport_id );

		$shelf_variant = $this->blocks->style_variant_class(
			$attributes,
			array_keys( $this->blocks->presentation_style_variations()['release-shelf'] ),
			'mw-release-shelf'
		);

		return '<section ' . get_block_wrapper_attributes( array( 'class' => 'mw-release-shelf mw-release-shelf--' . $layout . ' mw-release-shelf--playlists mw-release-shelf--columns-' . $columns . $shelf_variant ) ) . '>' . $header . $nav . '<div id="' . esc_attr( $viewport_id ) . '" class="mw-release-shelf__items" data-mw-shelf-viewport tabindex="0" role="region" aria-label="' . esc_attr__( 'قفسه فهرست‌های پخش', 'musicwave' ) . '" aria-live="polite">' . implode( '', $cards ) . '</div><p class="screen-reader-text" aria-live="polite" data-mw-shelf-status></p></section>';
	}

	/**
	 * Query Loop bridge: turn the shelf's own query into the standard loop.
	 *
	 * The shelf never re-implements paging. When «نمایش همهٔ انتشارها» is on it
	 * renders a real `core/query` → `core/post-template` → `core/query-pagination`
	 * composition, and hands its filters to core through
	 * `query_loop_block_query_vars` for that loop's `queryId` only, so every
	 * other Query Loop on the page (archives, taxonomy templates, related
	 * releases) keeps its own query untouched.
	 *
	 * @param array<string, mixed>                  $attributes  Block attributes.
	 * @param array<string, mixed>                  $tabbed      Filter-tab state.
	 * @param string                                $tab_scope   Filter tab scope key.
	 * @param string                                $layout      Resolved layout key.
	 * @param int                                   $columns     Resolved column count.
	 * @param string                                $shelf_class Extra wrapper classes.
	 * @param array{eyebrow:string,title:string,description:string,url:string,label:string,icon:string} $chrome Header chrome.
	 * @return string
	 */
	private function render_release_loop(
		array $attributes,
		array $tabbed,
		string $tab_scope,
		string $layout,
		int $columns,
		string $shelf_class,
		array $chrome
	): string {
		if ( ! function_exists( 'do_blocks' ) ) {
			return '';
		}

		++self::$loop_sequence;
		$query_id = self::LOOP_QUERY_ID_BASE + self::$loop_sequence;

		$per_page = isset( $attributes['perPage'] ) ? absint( $attributes['perPage'] ) : 12;
		$per_page = min( 24, max( 3, $per_page ) );

		// Reuse the shelf's own filter pipeline; only the vars the Query block
		// cannot express (taxonomy, date window, curated order, view ordering)
		// travel through the filter.
		$overrides = $this->release_query_args( $attributes, $per_page );
		unset(
			$overrides['fields'],
			$overrides['posts_per_page'],
			$overrides['no_found_rows'],
			$overrides['update_post_meta_cache'],
			$overrides['update_post_term_cache'],
			$overrides['post_type'],
			$overrides['post_status'],
			$overrides['orderby'],
			$overrides['order'],
			$overrides['meta_key']
		);
		$this->loop_query_overrides[ $query_id ] = $overrides;

		$apply_overrides = function ( $query, $block = null ) use ( $query_id ) {
			if ( ! is_array( $query ) || ! isset( $this->loop_query_overrides[ $query_id ] ) ) {
				return $query;
			}
			$block_query_id = $block instanceof WP_Block && isset( $block->attributes['queryId'] )
				? (int) $block->attributes['queryId']
				: 0;
			if ( $query_id !== $block_query_id ) {
				return $query;
			}

			return array_merge( $query, $this->loop_query_overrides[ $query_id ] );
		};
		add_filter( 'query_loop_block_query_vars', $apply_overrides, 10, 2 );

		// Grid layouts carry the author's column count; the flat list stays one
		// column, exactly like the hand-built layouts.
		$template_layout = ',"layout":{"type":"grid","columnCount":' . ( 'list' === $layout ? 1 : $columns ) . '}';

		// Ordering travels in the block attributes where core handles it. Only
		// page-stable orders are allowed: a shuffled listing would repeat and
		// drop releases between pages. «بازدید» is not a core order and travels
		// through the filter below, with a date tiebreaker.
		$order_by = isset( $attributes['orderBy'] ) ? (string) $attributes['orderBy'] : 'date';
		$order_by = in_array( $order_by, array( 'date', 'title', 'modified' ), true ) ? $order_by : 'date';
		$order     = isset( $attributes['order'] ) && 'ASC' === strtoupper( (string) $attributes['order'] ) ? 'ASC' : 'DESC';

		if ( 'views' === ( isset( $attributes['orderBy'] ) ? (string) $attributes['orderBy'] : '' ) ) {
			$this->loop_query_overrides[ $query_id ]['orderby'] = array(
				'meta_value_num' => $order,
				'date'           => 'DESC',
			);
			$this->loop_query_overrides[ $query_id ]['meta_key'] = 'mw_views'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		}

		if ( ! empty( $attributes['releaseIds'] ) && isset( $overrides['post__in'] ) ) {
			// Manual picks keep the editor's order, like the hand-built shelves.
			$this->loop_query_overrides[ $query_id ]['orderby'] = 'post__in';
		}

		$query_attributes = array(
			'queryId' => $query_id,
			'query'   => array(
				'perPage'   => $per_page,
				'pages'     => 0,
				'offset'    => 0,
				'postType'  => 'mw_release',
				'order'     => $order,
				'orderBy'   => $order_by,
				'author'    => '',
				'search'    => '',
				'exclude'   => array(),
				'sticky'    => '',
				'inherit'   => false,
			),
			'className' => 'mw-release-shelf__loop',
		);

		$empty_copy = __( 'هنوز انتشاری با این فیلترها پیدا نشد.', 'musicwave' );
		$markup     = '<!-- wp:query ' . $this->loop_json( $query_attributes ) . ' -->'
			. '<!-- wp:post-template {"className":"mw-release-shelf__items"' . $template_layout . '} -->'
			. self::LOOP_CARD_BLOCKS
			. '<!-- /wp:post-template -->'
			. '<!-- wp:query-no-results -->'
			. '<!-- wp:paragraph {"className":"mw-release-shelf__empty"} -->'
			. '<p class="mw-release-shelf__empty">' . esc_html( $empty_copy ) . '</p>'
			. '<!-- /wp:paragraph -->'
			. '<!-- /wp:query-no-results -->'
			. '<!-- wp:query-pagination {"className":"mw-catalog-pagination","layout":{"type":"flex","justifyContent":"space-between","flexWrap":"wrap"}} -->'
			. '<!-- wp:query-pagination-previous /-->'
			. '<!-- wp:query-pagination-numbers /-->'
			. '<!-- wp:query-pagination-next /-->'
			. '<!-- /wp:query-pagination -->'
			. '<!-- /wp:query -->';

		$rendered = do_blocks( $markup );

		remove_filter( 'query_loop_block_query_vars', $apply_overrides, 10 );
		unset( $this->loop_query_overrides[ $query_id ] );

		if ( ! is_string( $rendered ) || '' === trim( $rendered ) ) {
			return '';
		}

		$header      = $this->render_shelf_header( $chrome['eyebrow'], $chrome['title'], $chrome['description'], $chrome['url'], $chrome['label'], $chrome['icon'] );
		$tabs_markup = $this->render_filter_tabs( $tabbed['tabs'], $tabbed['active'], $tab_scope );
		$scope_attr  = ! empty( $tabbed['tabs'] ) ? ' data-mw-filter-scope="' . esc_attr( $tab_scope ) . '"' : '';
		$bento_class = 'bento' === $layout ? ' mw-release-shelf--bento' : '';

		return '<section ' . get_block_wrapper_attributes(
			array( 'class' => 'mw-release-shelf mw-release-shelf--' . ( 'bento' === $layout ? 'grid' : $layout ) . $bento_class . ' mw-release-shelf--columns-' . $columns . ' mw-release-shelf--loop' . $shelf_class )
		) . $scope_attr . '>' . $header . $tabs_markup . $rendered . '</section>';
	}

	/**
	 * JSON-encode a block attribute payload for inline block markup.
	 *
	 * @param array<string, mixed> $attributes Attributes to encode.
	 */
	private function loop_json( array $attributes ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $attributes ) : json_encode( $attributes );

		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * Render configurable release shelf across grid, scroll, list, feature, and slider layouts.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_release_shelf( array $attributes ): string {
		$source = isset( $attributes['source'] ) ? sanitize_key( (string) $attributes['source'] ) : 'releases';
		if ( 'playlists' === $source || 'public_playlists' === $source ) {
			return $this->render_playlist_shelf( $attributes );
		}
		if ( ! post_type_exists( 'mw_release' ) ) {
			return '';
		}

		$shelf_instance = wp_unique_id( 'mw-shelf-' );
		$tab_scope      = $this->filter_scope_key( 'mw-shelf', $attributes, $shelf_instance );
		$tabbed         = $this->apply_filter_tab( $attributes, $tab_scope );
		$attributes     = $tabbed['attributes'];

		$show_all = ! empty( $attributes['showAll'] );
		$items    = isset( $attributes['itemsToShow'] ) ? absint( $attributes['itemsToShow'] ) : 8;

		$layout  = isset( $attributes['layout'] ) ? sanitize_key( (string) $attributes['layout'] ) : 'grid';
		$layout  = in_array( $layout, array( 'grid', 'bento', 'scroll', 'list', 'feature', 'slider' ), true ) ? $layout : 'grid';
		$shape   = isset( $attributes['imageShape'] ) ? sanitize_key( (string) $attributes['imageShape'] ) : 'square';
		$shape   = in_array( $shape, array( 'square', 'landscape', 'portrait', 'circle' ), true ) ? $shape : 'square';
		$columns = isset( $attributes['columns'] ) ? absint( $attributes['columns'] ) : 4;
		$columns = min( 6, max( 2, $columns ) );

		$shelf_variant = $this->blocks->style_variant_class(
			$attributes,
			array_keys( $this->blocks->presentation_style_variations()['release-shelf'] ),
			'mw-release-shelf'
		);

		// Legacy backward compatibility: honor saved `styleVariant="bento"` or `is-style-bento` when layout is `grid`.
		$legacy_bento = $this->blocks->style_variant_class( $attributes, array( 'bento' ), 'mw-release-shelf' );
		if ( 'grid' === $layout && '' !== $legacy_bento ) {
			$layout = 'bento';
		}

		$eyebrow            = isset( $attributes['eyebrow'] ) ? sanitize_text_field( (string) $attributes['eyebrow'] ) : '';
		$section_title      = isset( $attributes['title'] ) ? sanitize_text_field( (string) $attributes['title'] ) : '';
		$description        = isset( $attributes['description'] ) ? sanitize_text_field( (string) $attributes['description'] ) : '';
		$section_url        = isset( $attributes['sectionUrl'] ) ? esc_url( (string) $attributes['sectionUrl'] ) : '';
		$section_link_label = isset( $attributes['sectionLinkLabel'] ) ? sanitize_text_field( (string) $attributes['sectionLinkLabel'] ) : '';
		$section_link_label = '' !== $section_link_label ? $section_link_label : __( 'مشاهده همه', 'musicwave' );
		$header_icon        = isset( $attributes['icon'] ) ? trim( (string) $attributes['icon'] ) : '';

		$tabs_markup = $this->render_filter_tabs( $tabbed['tabs'], $tabbed['active'], $tab_scope );
		$scope_attr  = ! empty( $tabbed['tabs'] ) ? ' data-mw-filter-scope="' . esc_attr( $tab_scope ) . '"' : '';

		/*
		 * «نمایش همهٔ انتشارها» is a publishing surface, not a bigger preview:
		 * it delegates the complete listing — and its paging — to the standard
		 * WordPress Query Loop instead of fetching an unbounded `get_posts()`
		 * result. Curated/editorial layouts keep their hand-built composition.
		 */
		if ( $show_all && in_array( $layout, array( 'grid', 'bento', 'list' ), true ) ) {
			$looped = $this->render_release_loop(
				$attributes,
				$tabbed,
				$tab_scope,
				$layout,
				$columns,
				$shelf_variant,
				array(
					'eyebrow'     => $eyebrow,
					'title'       => $section_title,
					'description' => $description,
					'url'         => $section_url,
					'label'       => $section_link_label,
					'icon'        => $header_icon,
				)
			);
			if ( '' !== $looped ) {
				return $looped;
			}
		}

		$items = $show_all ? -1 : min( 24, max( 1, $items ) );
		$ids   = get_posts( $this->release_query_args( $attributes, $items ) );
		if ( ( ! is_array( $ids ) || empty( $ids ) ) && empty( $tabbed['tabs'] ) ) {
			return '';
		}
		$ids = is_array( $ids ) ? $ids : array();

		if ( 'feature' === $layout && ! empty( $ids ) ) {
			return $this->render_feature_shelf( $attributes, $ids, $eyebrow, $section_title, $description, $section_url, $section_link_label, $shelf_variant, $tabs_markup, $scope_attr );
		}
		if ( 'slider' === $layout && ! empty( $ids ) ) {
			return $this->render_hero_slider( $attributes, $ids, $eyebrow, $section_title, $description, $section_url, $section_link_label, $shelf_variant, $tabs_markup, $scope_attr, $shape );
		}

		$action_label = isset( $attributes['actionLabel'] ) ? sanitize_text_field( (string) $attributes['actionLabel'] ) : '';
		$action_label = '' !== $action_label ? $action_label : __( 'باز کردن انتشار', 'musicwave' );
		$show_artwork = ! isset( $attributes['showArtwork'] ) || false !== $attributes['showArtwork'];
		$show_play    = ! isset( $attributes['showPlayButton'] ) || false !== $attributes['showPlayButton'];
		$cards        = array();

		foreach ( $ids as $idx => $release_id ) {
			$item = $this->release_presentation_data( absint( $release_id ) );
			if ( empty( $item ) ) {
				continue;
			}

			$release_id = (int) $item['id'];
			$is_first   = $idx < 2;

			$play_button = '';
			if ( $show_play ) {
				$play_button = apply_filters( 'music_wave_card_play_button', '', $release_id, 'mw-release-shelf__play' );
				$play_button = is_string( $play_button ) && '' !== $play_button ? $play_button : '<span class="mw-release-shelf__play" aria-hidden="true">&#9654;</span>';
			}

			$date_markup = ! empty( $attributes['showDate'] )
				? '<time datetime="' . esc_attr( get_the_date( 'c', $release_id ) ) . '">' . esc_html( get_the_date( '', $release_id ) ) . '</time>'
				: '';

			/* translators: %s: music release title. */
			$open_label = sprintf( __( 'باز کردن %s', 'musicwave' ), (string) $item['title'] );

			$card = $this->render_release_card(
				$item,
				array(
					'shape'            => $shape,
					'image_attributes' => array(
						'loading'       => $is_first ? 'eager' : 'lazy',
						'fetchpriority' => $is_first ? 'high' : 'low',
						'decoding'      => 'async',
					),
					'open_label'       => $open_label,
					'overlay'          => $play_button,
					'show_artwork'     => $show_artwork,
					'show_artist'      => ! isset( $attributes['showArtist'] ) || false !== $attributes['showArtist'],
					'show_excerpt'     => ! empty( $attributes['showExcerpt'] ),
					'show_action'      => ! isset( $attributes['showAction'] ) || false !== $attributes['showAction'],
					'action_label'     => $action_label,
					'meta_html'        => $date_markup,
				)
			);

			if ( '' !== $card ) {
				$cards[] = $card;
			}
		}
		if ( empty( $cards ) && empty( $tabbed['tabs'] ) ) {
			return '';
		}

		$header      = $this->render_shelf_header( $eyebrow, $section_title, $description, $section_url, $section_link_label, $header_icon );
		$viewport_id = $shelf_instance . '-viewport';
		$nav         = ! empty( $cards ) ? $this->shelf_nav_markup( $layout, $viewport_id ) : '';
		$items_html  = ! empty( $cards )
			? '<div id="' . esc_attr( $viewport_id ) . '" class="mw-release-shelf__items" data-mw-shelf-viewport tabindex="0" role="region" aria-label="' . esc_attr( $section_title ? $section_title : __( 'قفسه موسیقی', 'musicwave' ) ) . '" aria-live="polite">' . implode( '', $cards ) . '</div>'
			: '<p class="mw-filter-tabs__empty" role="status">' . esc_html__( 'در این بازه یا دسته هنوز انتشاری ثبت نشده است.', 'musicwave' ) . '</p>';

		$layout_classes = 'bento' === $layout
			? 'mw-release-shelf--grid mw-release-shelf--bento'
			: 'mw-release-shelf--' . $layout;

		return '<section ' . get_block_wrapper_attributes( array( 'class' => 'mw-release-shelf ' . $layout_classes . ' mw-release-shelf--columns-' . $columns . $shelf_variant ) ) . $scope_attr . '>' . $header . $tabs_markup . $nav . $items_html . '<p class="screen-reader-text" aria-live="polite" data-mw-shelf-status></p></section>';
	}

	/**
	 * Render editorial feature layout (hero release card + supporting side list).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<int, int>      $ids        Release IDs.
	 */
	public function render_feature_shelf( array $attributes, array $ids, string $eyebrow, string $title, string $description, string $section_url, string $section_link_label, string $variant_class = '', string $tabs_markup = '', string $scope_attr = '' ): string {
		$featured_id = isset( $attributes['featuredReleaseId'] ) ? absint( $attributes['featuredReleaseId'] ) : 0;
		if ( $featured_id < 1 || ! in_array( $featured_id, $ids, true ) ) {
			$featured_id = absint( $ids[0] );
		}
		$side_ids = array_slice( array_values( array_diff( $ids, array( $featured_id ) ) ), 0, 4 );

		$featured_item = $this->release_presentation_data( $featured_id );
		if ( empty( $featured_item ) ) {
			return '';
		}
		$featured_id     = (int) $featured_item['id'];
		$featured_link   = (string) $featured_item['link'];
		$featured_title  = (string) $featured_item['title'];
		$featured_artist = (string) $featured_item['artist'];
		$featured_art    = get_the_post_thumbnail(
			$featured_id,
			'large',
			array(
				'class' => 'mw-feature__art-img',
				'alt'   => '',
			)
		);

		$featured_source = isset( $attributes['featuredSource'] ) ? sanitize_key( (string) $attributes['featuredSource'] ) : 'excerpt';
		$featured_body   = '';
		if ( 'excerpt' === $featured_source ) {
			$excerpt = get_the_excerpt( $featured_id );
			if ( '' !== $excerpt ) {
				$featured_body = '<p class="mw-feature__excerpt">' . esc_html( wp_trim_words( $excerpt, 30 ) ) . '</p>';
			}
		} elseif ( 'custom' === $featured_source && '' !== $description ) {
			$featured_body = '<p class="mw-feature__excerpt">' . esc_html( $description ) . '</p>';
		}

		$overlay = isset( $attributes['overlay'] ) ? absint( $attributes['overlay'] ) : 50;
		$overlay = min( 100, max( 0, $overlay ) ) / 100;

		$cta_label   = isset( $attributes['ctaLabel'] ) && '' !== (string) $attributes['ctaLabel']
			? sanitize_text_field( (string) $attributes['ctaLabel'] )
			: ( isset( $attributes['actionLabel'] ) && '' !== (string) $attributes['actionLabel']
				? sanitize_text_field( (string) $attributes['actionLabel'] )
				: __( 'باز کردن انتشار', 'musicwave' ) );
		$cta_variant = isset( $attributes['ctaStyle'] ) ? sanitize_key( (string) $attributes['ctaStyle'] ) : 'solid';
		$cta_variant = in_array( $cta_variant, array( 'solid', 'outline', 'ghost' ), true ) ? $cta_variant : 'solid';
		$cta_bg      = $this->normalize_color_value( (string) ( $attributes['ctaBgColor'] ?? '' ) );
		$cta_txt     = $this->normalize_color_value( (string) ( $attributes['ctaTextColor'] ?? '' ) );
		$cta_radius  = isset( $attributes['ctaRadius'] ) ? max( 0, min( 999, absint( $attributes['ctaRadius'] ) ) ) : 999;
		$cta_style   = ( $cta_bg ? '--mw-cta-bg:' . esc_attr( $cta_bg ) . ';' : '' )
			. ( $cta_txt ? '--mw-cta-color:' . esc_attr( $cta_txt ) . ';' : '' )
			. 'border-radius:' . esc_attr( (string) $cta_radius ) . 'px;';

		$hero_title_color = $this->normalize_color_value( (string) ( $attributes['heroTitleColor'] ?? '' ) );
		$hero_title_size  = isset( $attributes['heroTitleSize'] ) ? absint( $attributes['heroTitleSize'] ) : 0;
		$hero_text_color  = $this->normalize_color_value( (string) ( $attributes['heroTextColor'] ?? '' ) );
		$title_css        = ( $hero_title_color ? 'color:' . esc_attr( $hero_title_color ) . ';' : '' )
			. ( $hero_title_size > 0 ? 'font-size:' . esc_attr( (string) $hero_title_size ) . 'px;' : '' );
		$text_css         = $hero_text_color
			? ' style="color:' . esc_attr( $hero_text_color ) . ';--mw-feature-text-color:' . esc_attr( $hero_text_color ) . ';"'
			: '';

		$featured_views = '';
		if ( ! empty( $attributes['showViews'] ) ) {
			$views = absint( get_post_meta( $featured_id, 'mw_views', true ) );
			if ( $views > 0 ) {
				$featured_views = '<span class="mw-feature__views">' . esc_html( number_format_i18n( $views ) ) . ' ' . esc_html__( 'بازدیدها', 'musicwave' ) . '</span>';
			}
		}

		$side_col_width = isset( $attributes['sideColumnWidth'] ) ? absint( $attributes['sideColumnWidth'] ) : 340;
		$side_col_width = min( 560, max( 200, $side_col_width ) );
		$aside_override = isset( $attributes['asideTitle'] ) ? sanitize_text_field( (string) $attributes['asideTitle'] ) : '';

		$hero = '<div class="mw-feature__hero">';
		if ( '' !== $featured_art ) {
			$hero .= '<div class="mw-feature__art">' . $featured_art . '</div>';
		}
		$hero .= '<div class="mw-feature__scrim" aria-hidden="true"></div>';
		$hero .= '<div class="mw-feature__content"' . $text_css . '>';
		if ( '' !== $eyebrow ) {
			$hero .= '<span class="mw-feature__eyebrow">' . esc_html( $eyebrow ) . '</span>';
		}
		$hero .= '<h2 class="mw-feature__title"' . ( $title_css ? ' style="' . esc_attr( $title_css ) . '"' : '' ) . '><a href="' . esc_url( $featured_link ) . '">' . esc_html( $featured_title ) . '</a></h2>';
		if ( '' !== $featured_artist ) {
			$hero .= '<span class="mw-feature__artist">' . esc_html( $featured_artist ) . '</span>';
		}
		$hero .= $featured_body . $featured_views;
		$hero .= '<a class="mw-feature__cta mw-feature__cta--' . esc_attr( $cta_variant ) . '" href="' . esc_url( $featured_link ) . '" style="' . esc_attr( $cta_style ) . '">' . esc_html( $cta_label ) . '<span aria-hidden="true">&rarr;</span></a>';
		$hero .= '</div></div>';

		$list  = '';
		$items = array();
		foreach ( $side_ids as $rank => $rid ) {
			$item = $this->release_presentation_data( absint( $rid ) );
			if ( empty( $item ) ) {
				continue;
			}
			$rid          = (int) $item['id'];
			$rlink        = (string) $item['link'];
			$rtitle       = (string) $item['title'];
			$rank_markup  = ! empty( $attributes['showRank'] ) ? '<span class="mw-feature__rank">' . ( $rank + 2 ) . '</span>' : '';
			$views_markup = '';
			if ( ! empty( $attributes['showViews'] ) ) {
				$sv = absint( get_post_meta( $rid, 'mw_views', true ) );
				if ( $sv > 0 ) {
					$views_markup = '<span class="mw-feature__side-views">' . esc_html( number_format_i18n( $sv ) ) . '</span>';
				}
			}
			$rthumb  = get_the_post_thumbnail(
				$rid,
				'thumbnail',
				array(
					'class' => 'mw-feature__side-img',
					'alt'   => '',
				)
			);
			$items[] = '<li class="mw-feature__side-item">' . $rank_markup . ( '' !== $rthumb ? '<a href="' . esc_url( $rlink ) . '" class="mw-feature__side-art">' . $rthumb . '</a>' : '' ) . '<div class="mw-feature__side-body"><a href="' . esc_url( $rlink ) . '" class="mw-feature__side-title">' . esc_html( $rtitle ) . '</a>' . $views_markup . '</div></li>';
		}
		if ( ! empty( $items ) || '' !== $aside_override ) {
			$list_title = '' !== $aside_override
				? $aside_override
				: ( '' !== $title ? $title : __( 'انتشارهای بیشتر', 'musicwave' ) );
			$list       = '<aside class="mw-feature__aside"><h3 class="mw-feature__aside-title">' . esc_html( $list_title ) . '</h3>'
				. ( ! empty( $items ) ? '<ul class="mw-feature__side-list">' . implode( '', $items ) . '</ul>' : '' );
			if ( '' !== $section_url ) {
				$list .= '<a class="mw-feature__more" href="' . esc_url( $section_url ) . '">' . esc_html( $section_link_label ) . '<span aria-hidden="true">&rarr;</span></a>';
			}
			$list .= '</aside>';
		}

		$style_vars = '--mw-feature-overlay:' . (string) $overlay . ';--mw-feature-side-width:' . (string) $side_col_width . 'px;';

		return '<section ' . get_block_wrapper_attributes(
			array(
				'class' => 'mw-release-shelf mw-release-shelf--feature' . $variant_class,
				'style' => $style_vars,
			)
		) . $scope_attr . '>' . $tabs_markup . '<div class="mw-feature">' . $hero . $list . '</div></section>';
	}

	/**
	 * Render full-bleed crossfading hero slider layout.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<int, int>      $ids        Release IDs.
	 */
	public function render_hero_slider( array $attributes, array $ids, string $eyebrow, string $title, string $description, string $section_url, string $section_link_label, string $variant_class = '', string $tabs_markup = '', string $scope_attr = '', string $shape = '' ): string {
		if ( function_exists( 'wp_enqueue_script' ) ) {
			wp_enqueue_script( 'musicwave-hero-slider' );
		}

		$shape        = '' !== $shape ? $shape : ( isset( $attributes['imageShape'] ) ? sanitize_key( (string) $attributes['imageShape'] ) : 'square' );
		$shape        = in_array( $shape, array( 'square', 'landscape', 'portrait', 'circle' ), true ) ? $shape : 'square';
		$ids          = array_slice( $ids, 0, 8 );
		$autoplay     = ! isset( $attributes['autoplay'] ) || false !== $attributes['autoplay'];
		$show_arrows  = ! isset( $attributes['showArrows'] ) || false !== $attributes['showArrows'];
		$show_dots    = ! isset( $attributes['showDots'] ) || false !== $attributes['showDots'];
		$interval     = isset( $attributes['interval'] ) ? absint( $attributes['interval'] ) : 0;
		$interval     = $interval >= 2000 && $interval <= 20000 ? $interval : 5000;
		$action_label = isset( $attributes['actionLabel'] ) ? sanitize_text_field( (string) $attributes['actionLabel'] ) : '';
		$action_label = '' !== $action_label ? $action_label : __( 'باز کردن انتشار', 'musicwave' );
		$show_play    = ! isset( $attributes['showPlayButton'] ) || false !== $attributes['showPlayButton'];

		$data = array();
		foreach ( $ids as $idx => $release_id ) {
			$item = $this->release_presentation_data( absint( $release_id ) );
			if ( empty( $item ) ) {
				continue;
			}

			$release_id = (int) $item['id'];
			$is_first   = 0 === $idx;
			/* translators: %s: music release title. */
			$open_label = sprintf( __( 'باز کردن %s', 'musicwave' ), (string) $item['title'] );

			$backdrop = get_the_post_thumbnail(
				$release_id,
				'large',
				array(
					'class'         => 'mw-hero-slide__backdrop',
					'alt'           => '',
					'loading'       => $is_first ? 'eager' : 'lazy',
					'fetchpriority' => $is_first ? 'high' : 'low',
					'decoding'      => 'async',
				)
			);
			$backdrop = '' !== $backdrop
				? $backdrop
				: '<div class="mw-hero-slide__backdrop mw-hero-slide__backdrop--gradient" role="img" aria-label="' . esc_attr( $open_label ) . '"></div>';

			$art = get_the_post_thumbnail(
				$release_id,
				'medium_large',
				array(
					'class'    => 'mw-hero-slide__art-img',
					'alt'      => '',
					'loading'  => $is_first ? 'eager' : 'lazy',
					'decoding' => 'async',
				)
			);
			$art = '' !== $art
				? $art
				: '<span class="mw-hero-slide__art-fallback" aria-hidden="true">' . esc_html( (string) $item['initial'] ) . '</span>';

			$types  = wp_get_post_terms( $release_id, 'mw_release_type', array( 'fields' => 'names' ) );
			$kicker = is_array( $types ) && ! empty( $types[0] ) ? (string) $types[0] : '';

			$meta_parts = array();
			$year       = (string) get_the_date( 'Y', $release_id );
			if ( '' !== $year ) {
				$meta_parts[] = $year;
			}
			$genres = wp_get_post_terms( $release_id, 'mw_genre', array( 'fields' => 'names' ) );
			if ( is_array( $genres ) && ! empty( $genres[0] ) ) {
				$meta_parts[] = (string) $genres[0];
			}

			$play_button = '';
			if ( $show_play ) {
				$play_button = apply_filters( 'music_wave_card_play_button', '', $release_id, 'mw-hero-slide__play' );
				$play_button = is_string( $play_button ) && '' !== $play_button
					? $play_button
					: '<span class="mw-hero-slide__play mw-hero-slide__play--static" aria-hidden="true">&#9654;</span>';
			}

			$data[] = array(
				'link'       => (string) $item['link'],
				'title'      => (string) $item['title'],
				'artist'     => (string) $item['artist'],
				'open_label' => $open_label,
				'backdrop'   => $backdrop,
				'art'        => $art,
				'kicker'     => $kicker,
				'meta'       => implode( " \u{00B7} ", $meta_parts ),
				'play'       => $play_button,
			);
		}
		if ( empty( $data ) ) {
			return '';
		}

		$hero_title_color = $this->normalize_color_value( (string) ( $attributes['heroTitleColor'] ?? '' ) );
		$hero_title_size  = isset( $attributes['heroTitleSize'] ) ? absint( $attributes['heroTitleSize'] ) : 0;
		$hero_text_color  = $this->normalize_color_value( (string) ( $attributes['heroTextColor'] ?? '' ) );
		$slide_title_css  = ( $hero_title_color ? 'color:' . esc_attr( $hero_title_color ) . ';' : '' )
			. ( $hero_title_size > 0 ? 'font-size:' . esc_attr( (string) $hero_title_size ) . 'px;' : '' );
		$slide_text_css   = $hero_text_color
			? ' style="color:' . esc_attr( $hero_text_color ) . ';--mw-feature-text-color:' . esc_attr( $hero_text_color ) . ';"'
			: '';

		$slides = array();
		foreach ( $data as $i => $slide ) {
			$is_active = 0 === $i;
			/* translators: 1: slide index, 2: music release title. */
			$slide_label = sprintf( __( 'اسلاید %1$d: %2$s', 'musicwave' ), $i + 1, $slide['title'] );

			$slides[] = '<article class="mw-hero-slide' . ( $is_active ? ' is-active' : '' ) . '" role="group" aria-label="' . esc_attr( $slide_label ) . '"' . ( $is_active ? '' : ' aria-hidden="true" inert' ) . '>'
				. $slide['backdrop']
				. '<div class="mw-hero-slide__scrim" aria-hidden="true"></div>'
				. '<div class="mw-hero-slide__content"' . $slide_text_css . '>'
				. ( '' !== $slide['kicker'] ? '<span class="mw-hero-slide__kicker">' . esc_html( $slide['kicker'] ) . '</span>' : '' )
				. '<h3 class="mw-hero-slide__title"' . ( $slide_title_css ? ' style="' . esc_attr( $slide_title_css ) . '"' : '' ) . '><a href="' . esc_url( $slide['link'] ) . '">' . esc_html( $slide['title'] ) . '</a></h3>'
				. ( '' !== $slide['artist'] ? '<span class="mw-hero-slide__artist">' . esc_html( $slide['artist'] ) . '</span>' : '' )
				. ( '' !== $slide['meta'] ? '<p class="mw-hero-slide__meta">' . esc_html( $slide['meta'] ) . '</p>' : '' )
				. '<div class="mw-hero-slide__actions">' . $slide['play'] . '<a class="mw-hero-slide__open" href="' . esc_url( $slide['link'] ) . '">' . esc_html( $action_label ) . '</a></div>'
				. '</div>'
				. '<a class="mw-hero-slide__art mw-hero-slide__art--' . esc_attr( $shape ) . '" href="' . esc_url( $slide['link'] ) . '" aria-label="' . esc_attr( $slide['open_label'] ) . '">' . $slide['art'] . '</a>'
				. '</article>';
		}

		$id = wp_unique_id( 'mw-hero-slider-' );

		$arrows = $show_arrows
			? '<div class="mw-hero-slider__arrows">'
				. '<button type="button" class="mw-hero-slider__arrow" data-mw-hero-previous aria-controls="' . esc_attr( $id ) . '" aria-label="' . esc_attr__( 'اسلاید قبلی', 'musicwave' ) . '"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg></button>'
				. '<button type="button" class="mw-hero-slider__arrow" data-mw-hero-next aria-controls="' . esc_attr( $id ) . '" aria-label="' . esc_attr__( 'اسلاید بعدی', 'musicwave' ) . '"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></button>'
				. '</div>'
			: '';

		$dot_buttons = '';
		foreach ( $data as $i => $slide ) {
			$dot_buttons .= '<button type="button" class="mw-hero-slider__dot' . ( 0 === $i ? ' is-active' : '' ) . '" data-mw-hero-dot="' . (string) $i . '" aria-label="' . esc_attr( sprintf( /* translators: %d: slide index. */ __( 'رفتن به اسلاید %d', 'musicwave' ), $i + 1 ) ) . '"' . ( 0 === $i ? ' aria-current="true"' : '' ) . '></button>';
		}
		$dot_container = $show_dots
			? '<div class="mw-hero-slider__dots" data-mw-hero-dots role="group" aria-label="' . esc_attr__( 'صفحهبندی اسلایدر', 'musicwave' ) . '">' . $dot_buttons . '</div>'
			: '';

		$region_label = '' !== $title ? $title : __( 'اسلایدر انتشارها', 'musicwave' );
		$header       = $this->render_shelf_header( $eyebrow, $title, $description, $section_url, $section_link_label, isset( $attributes['icon'] ) ? trim( (string) $attributes['icon'] ) : '' );
		$hero_overlay = isset( $attributes['overlay'] ) ? min( 100, max( 0, absint( $attributes['overlay'] ) ) ) / 100 : 0.55;

		return '<section ' . get_block_wrapper_attributes(
			array(
				'class' => 'mw-release-shelf mw-release-shelf--slider' . $variant_class,
				'style' => '--mw-feature-overlay:' . (string) $hero_overlay . ';',
			)
		) . $scope_attr . '>'
			. $header
			. $tabs_markup
			. '<div id="' . esc_attr( $id ) . '" class="mw-hero-slider mw-hero-slider--shape-' . esc_attr( $shape ) . '" data-mw-hero-slider data-autoplay="' . esc_attr( $autoplay ? '1' : '0' ) . '" data-pause-hover="1" data-interval="' . esc_attr( (string) $interval ) . '">'
			. '<div class="mw-hero-slider__viewport" tabindex="0" role="region" aria-roledescription="' . esc_attr__( 'اسلایدر', 'musicwave' ) . '" aria-label="' . esc_attr( $region_label ) . '">'
			. implode( '', $slides )
			. '</div>'
			. $arrows
			. $dot_container
			. '<p class="screen-reader-text" aria-live="polite" data-mw-hero-status></p>'
			. '</div>'
			. '</section>';
	}
}
