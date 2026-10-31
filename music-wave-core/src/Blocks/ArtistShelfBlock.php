<?php
/**
 * Public artists shelf block: multi-artist discovery surfaces.
 *
 * Renders the catalog's artists as a responsive grid, horizontal scroll
 * shelf, or compact list. Sources cover every editorial need: all artists,
 * artists behind one genre or mood, or a hand-picked ordered list. Cards
 * reuse the shared MusicWave release-shelf chrome so every shelf on the
 * site shares one visual system, and the follow control reuses the personal
 * library button so follows stay consistent everywhere.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

use ManaCore\MusicWave\Core\Catalog\ArtistIndex;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Catalog\TermHue;
use ManaCore\MusicWave\Core\Blocks\Concerns\ArtistsPager;
use ManaCore\MusicWave\Core\Blocks\Concerns\TermShelfSupport;
use ManaCore\MusicWave\Core\Library\LibraryButton;
use ManaCore\MusicWave\Core\Library\LibraryRepository;
use WP_Term;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ArtistShelfBlock {
	/** Hard ceiling for one render; keeps queries and DOM bounded. */
	private const MAX_ITEMS = 24;

	/** Smallest page the paginated index may request. */
	private const MIN_PER_PAGE = 4;

	/** Public query var that carries the artists index page number. */
	public const PAGE_QUERY_VAR = ArtistIndex::QUERY_PAGE;

	/**
	 * Browsing state for the render in progress (filters, paging, URLs).
	 *
	 * @var ArtistIndex|null
	 */
	private ?ArtistIndex $index = null;

	/**
	 * Register the artists browsing surface.
	 *
	 * The shelf, hero, toolbar, alphabet and pagination blocks compose one
	 * page, so they are registered together here even though each block owns
	 * its own markup and editor panel.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		// A paged index is a distinct URL: point its canonical tag at the page
		// actually being read instead of always collapsing to page one.
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical_url' ), 10, 2 );

		BlockSupport::register_dynamic(
			'music-wave/artists-shelf',
			array( $this, 'render' ),
			array(
				'api_version' => 3,
				'supports'    => BlockSupport::appearance_tools(),
			)
		);

		$siblings = array(
			new ArtistsHeroBlock(),
			new ArtistsToolbarBlock(),
			new ArtistsAlphabetBlock(),
			new ArtistsPaginationBlock(),
		);
		foreach ( $siblings as $block ) {
			$block->register();
		}
	}

	/**
	 * Browsing service for this request, created on first use.
	 */
	private function index(): ArtistIndex {
		if ( null === $this->index ) {
			$this->index = new ArtistIndex();
		}

		return $this->index;
	}

	/**
	 * Render the artists shelf.
	 *
	 * @param array<string, mixed> $attributes Block attributes from the editor.
	 *
	 * @return string
	 */
	public function render( array $attributes = array() ): string {
		// One render owns one browsing state: filters and page number come
		// from the request and must never leak into the next render.
		$this->index = new ArtistIndex();

		$options = $this->resolve_options( $attributes );
		$terms   = $this->resolve_terms( $attributes, $options );

		if ( array() === $terms ) {
			if ( BlockSupport::is_editor_context() ) {
				return $this->render_editor_placeholder( $attributes );
			}

			return '<section '
				. BlockSupport::wrapper_attributes( 'mw-artists-shelf mw-artists-shelf--empty' )
				. '>' . $this->empty_state( $options ) . '</section>';
		}

		$show_ranks = $options['show_crowns']
			&& 'count' === $options['order_by']
			&& 1 === $this->index()->page();

		$cards    = array();
		$position = 0;
		foreach ( $terms as $term ) {
			++$position;
			$card = $this->card( $term, $options, $show_ranks && $position <= 3 ? $position : 0 );
			if ( '' !== $card ) {
				$cards[] = $card;
			}
		}

		if ( array() === $cards ) {
			return '';
		}

		$header = '';
		if ( $options['show_heading'] ) {
			$header = $this->header( $attributes, $options );
		}

		$variation   = BlockSupport::style_variation( $attributes, array( 'minimal', 'editorial', 'glass' ) );
		$shelf_class = 'mw-artists-shelf mw-release-shelf mw-release-shelf--' . $options['layout'];
		// Columns also drive the horizontal scroll card width, not just the grid.
		if ( in_array( $options['layout'], array( 'grid', 'scroll' ), true ) ) {
			$shelf_class .= ' mw-release-shelf--columns-' . $options['columns'];
		}
		if ( '' !== $variation ) {
			$shelf_class .= ' mw-release-shelf--' . $variation . ' is-style-' . $variation;
		}
		// Image size now drives a BEM modifier so CSS can visually scale the avatar.
		$shelf_class .= ' mw-artists-shelf--size-' . $options['image_size'];

		return '<section '
			. BlockSupport::wrapper_attributes( $shelf_class )
			. '>'
			. $header
			. $this->summary( $options, count( $terms ) )
			. \ManaCore\MusicWave\Core\Support\ShelfNavMarkup::items_region(
				(string) $options['layout'],
				implode( '', $cards ),
				isset( $options['heading'] ) ? (string) $options['heading'] : ''
			)
			. $this->pager( $attributes, $options )
			. '</section>';
	}

	/**
	 * Empty-state copy, distinguishing "no artists" from "no matches".
	 *
	 * @param array<string, mixed> $options Resolved display options.
	 */
	private function empty_state( array $options ): string {
		if ( '' !== $options['no_match_message'] && ( '' !== $this->index()->search() || '' !== $this->index()->letter() ) ) {
			return '<p class="mw-artists-shelf__empty">' . esc_html( (string) $options['no_match_message'] ) . '</p>';
		}

		$message = '' !== $options['empty_message']
			? (string) $options['empty_message']
			: __( 'هنوز هنرمندی برای نمایش وجود ندارد.', 'music-wave-core' );

		return '<p class="mw-artists-shelf__empty">' . esc_html( $message ) . '</p>';
	}

	/**
	 * "Showing X of Y artists" line above the cards.
	 *
	 * @param array<string, mixed> $options Resolved display options.
	 * @param int                  $showing Number of cards on this page.
	 */
	private function summary( array $options, int $showing ): string {
		if ( ! $options['show_result_count'] ) {
			return '';
		}

		$total = $this->index()->matching_total();

		return '<p class="mw-artists-shelf__summary">' . esc_html(
			sprintf(
				/* translators: 1: artists on this page, 2: matching artist total. */
				__( 'نمایش %1$s از %2$s خواننده', 'music-wave-core' ),
				number_format_i18n( $showing ),
				number_format_i18n( $total )
			)
		) . '</p>';
	}

	/**
	 * Resolve sanitized display options once per render.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<string, mixed>
	 */
	private function resolve_options( array $attributes ): array {
		$source = isset( $attributes['source'] ) ? sanitize_key( (string) $attributes['source'] ) : 'all';
		$source = in_array( $source, array( 'all', 'genre', 'mood', 'manual' ), true ) ? $source : 'all';

		$layout = isset( $attributes['layout'] ) ? sanitize_key( (string) $attributes['layout'] ) : 'grid';
		$layout = in_array( $layout, array( 'grid', 'scroll', 'list' ), true ) ? $layout : 'grid';

		$image_shape = isset( $attributes['imageShape'] ) ? sanitize_key( (string) $attributes['imageShape'] ) : 'circle';
		// Backward compat: square was removed from UI (duplicate of rounded) – map it to rounded.
		if ( 'square' === $image_shape ) {
			$image_shape = 'rounded';
		}
		$image_shape = in_array( $image_shape, array( 'circle', 'rounded' ), true ) ? $image_shape : 'circle';

		$image_size = isset( $attributes['imageSize'] ) ? sanitize_key( (string) $attributes['imageSize'] ) : 'medium';
		$image_size = in_array( $image_size, array( 'thumbnail', 'medium', 'large', 'full' ), true ) ? $image_size : 'medium';

		$items = isset( $attributes['itemsToShow'] ) ? absint( $attributes['itemsToShow'] ) : 0;
		// "Show all" disables the shelf ceiling so the full index renders
		// (still bounded by the index's own MAX_TERMS query ceiling).
		$show_all = ! empty( $attributes['showAll'] );
		$items    = $show_all
			? ArtistIndex::MAX_TERMS
			: min( self::MAX_ITEMS, max( 1, $items > 0 ? $items : 8 ) );

		$columns = isset( $attributes['columns'] ) ? absint( $attributes['columns'] ) : 4;
		$columns = min( 6, max( 2, $columns > 0 ? $columns : 4 ) );

		$bio_length = isset( $attributes['bioLength'] ) ? absint( $attributes['bioLength'] ) : 20;
		$bio_length = min( 80, $bio_length );

		$order_by = isset( $attributes['orderBy'] ) ? sanitize_key( (string) $attributes['orderBy'] ) : 'count';
		$order_by = in_array( $order_by, array( 'name', 'count', 'rand' ), true ) ? $order_by : 'count';

		// The toolbar ordering tabs write their choice into the URL and win
		// over the block default, otherwise clicking a tab would do nothing.
		$requested_sort = $this->index()->sort();
		if ( 'all' === $source && '' !== $requested_sort ) {
			$order_by = ArtistIndex::SORT_ALPHABETICAL === $requested_sort ? 'name' : 'count';
		}

		$order = isset( $attributes['order'] ) && 'ASC' === strtoupper( (string) $attributes['order'] ) ? 'ASC' : 'DESC';
		if ( 'name' === $order_by ) {
			// Alphabetical shelves read naturally in one direction.
			$order = 'ASC';
		}

		$heading_val = BlockSupport::optional_text_attribute( $attributes, 'heading', '', 'showHeading' );

		// A paged index never asks for more artists per page than the shelf
		// ceiling allows; `itemsToShow` stays the fallback page size so a shelf
		// that shipped before pagination existed keeps its density.
		$per_page = isset( $attributes['perPage'] ) ? absint( $attributes['perPage'] ) : 0;
		$per_page = $per_page > 0 ? $per_page : $items;
		$per_page = min( self::MAX_ITEMS, max( self::MIN_PER_PAGE, $per_page ) );

		// Paging needs a stable order: a shuffled or hand-picked list cannot be
		// split across pages without repeating or dropping artists. Showing every
		// artist at once is the opposite of paging, so it wins over both modes.
		$pagination = ! $show_all && ! empty( $attributes['pagination'] );
		$browse     = ! $show_all && ! empty( $attributes['browseIndex'] );
		$paged      = ( $pagination || $browse ) && 'all' === $source && 'rand' !== $order_by;

		return array(
			'source'            => $source,
			'layout'            => $layout,
			'paged'             => $paged,
			'pager'             => $pagination && $paged,
			'per_page'          => $per_page,
			'show_all'          => $show_all,
			'image_shape'       => $image_shape,
			'image_size'        => $image_size,
			'items'             => $items,
			'columns'           => $columns,
			'bio_length'        => $bio_length,
			'order_by'          => $order_by,
			'order'             => $order,
			'show_heading'      => ! isset( $attributes['showHeading'] ) || (bool) $attributes['showHeading'],
			'show_image'        => ! isset( $attributes['showImage'] ) || (bool) $attributes['showImage'],
			'show_name'         => ! isset( $attributes['showName'] ) || (bool) $attributes['showName'],
			'show_bio'          => ! isset( $attributes['showBio'] ) || (bool) $attributes['showBio'],
			'show_count'        => ! isset( $attributes['showReleaseCount'] ) || (bool) $attributes['showReleaseCount'],
			'show_follow'       => ! isset( $attributes['showFollowButton'] ) || (bool) $attributes['showFollowButton'],
			'show_crowns'       => ! empty( $attributes['showCrowns'] ),
			'show_result_count' => ! empty( $attributes['showResultCount'] ),
			'eyebrow'           => isset( $attributes['eyebrow'] ) ? sanitize_text_field( (string) $attributes['eyebrow'] ) : '',
			'heading'           => $heading_val,
			'icon'              => isset( $attributes['icon'] ) ? (string) $attributes['icon'] : '',
			'description'       => isset( $attributes['description'] ) ? sanitize_text_field( (string) $attributes['description'] ) : '',
			'section_url'       => isset( $attributes['sectionUrl'] ) ? esc_url_raw( (string) $attributes['sectionUrl'] ) : '',
			'section_label'     => isset( $attributes['sectionLinkLabel'] ) ? sanitize_text_field( (string) $attributes['sectionLinkLabel'] ) : '',
			'empty_message'     => isset( $attributes['emptyMessage'] ) ? sanitize_text_field( (string) $attributes['emptyMessage'] ) : '',
			'no_match_message'  => isset( $attributes['noResultsMessage'] ) && '' !== (string) $attributes['noResultsMessage']
				? sanitize_text_field( (string) $attributes['noResultsMessage'] )
				: __( 'خواننده‌ای با این فیلترها پیدا نشد. جست‌وجو را پاک کنید یا حرف دیگری را امتحان کنید.', 'music-wave-core' ),
		);
	}

	/**
	 * Resolve the artist terms for this render according to the source.
	 *
	 * Term counts on the non-hierarchical `mw_artist` taxonomy track only
	 * published releases (WordPress core term counting), so `hide_empty`
	 * filtering costs no extra queries.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<string, mixed> $options    Resolved display options.
	 * @return array<int, WP_Term>
	 */
	private function resolve_terms( array $attributes, array $options ): array {
		$terms = array();
		// Effective number of artists this render may output: the page size on a
		// paged shelf, otherwise `itemsToShow` (or the whole index for "show all").
		$limit = (int) $options['items'];

		if ( 'all' === $options['source'] ) {
			$terms = $this->index()->filtered( $this->index_order( (string) $options['order_by'] ) );

			if ( $options['paged'] ) {
				$per_page = (int) $options['per_page'];
				$terms    = array_slice( $terms, ( $this->current_page( $options ) - 1 ) * $per_page, $per_page );
				// `finalize_terms()` honours `itemsToShow`; the page size is the
				// output size on a paged shelf, so hand it the effective number.
				$attributes['itemsToShow'] = $per_page;
				$limit                     = max( $limit, $per_page );
			}
		} elseif ( 'manual' === $options['source'] ) {
			$terms = TermShelfSupport::query_terms(
				ArtistIndex::TAXONOMY,
				true,
				isset( $attributes['artistIds'] ) ? (string) $attributes['artistIds'] : '',
				'count',
				$limit
			);
		} elseif ( 'genre' === $options['source'] || 'mood' === $options['source'] ) {
			$terms = $this->terms_behind_taxonomy( (string) $options['source'], isset( $attributes['termSlug'] ) ? sanitize_title( (string) $attributes['termSlug'] ) : '' );
		}

		if ( ! $options['paged'] ) {
			$attributes['itemsToShow'] = $limit;
		}

		return TermShelfSupport::finalize_terms( $terms, $attributes, $limit, 'music_wave_artists_shelf_terms', ArtistIndex::TAXONOMY );
	}

	/**
	 * Map the block's ordering key onto the index ordering key.
	 *
	 * @param string $order_by Block ordering key (count|name|rand).
	 */
	private function index_order( string $order_by ): string {
		if ( 'name' === $order_by ) {
			return ArtistIndex::SORT_ALPHABETICAL;
		}
		if ( 'rand' === $order_by ) {
			return 'random';
		}

		return ArtistIndex::SORT_POPULAR;
	}

	/**
	 * Resolve artists that have at least one release in a genre or mood.
	 *
	 * Two bounded queries: the matching release IDs, then one term lookup
	 * across all of them via `object_ids`.
	 *
	 * @param string $taxonomy Taxonomy key (mw_genre|mood).
	 * @param string $slug     Term slug filter; empty means the whole taxonomy.
	 * @return array<int, WP_Term>
	 */
	private function terms_behind_taxonomy( string $taxonomy, string $slug ): array {
		$taxonomy = 'mood' === $taxonomy ? 'mw_mood' : 'mw_genre';
		if ( '' === $slug || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$release_ids = get_posts(
			array(
				'post_type'              => ReleasePostType::KEY,
				'post_status'            => 'publish',
				'posts_per_page'         => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded release sample used to resolve visible artist terms.
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- bounded indexed taxonomy filter.
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'slug',
						'terms'    => array( $slug ),
					),
				),
			)
		);
		if ( ! is_array( $release_ids ) || array() === $release_ids ) {
			return array();
		}

		$found = get_terms(
			array(
				'taxonomy'   => ArtistIndex::TAXONOMY,
				'object_ids' => $release_ids,
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 100,
			)
		);

		return is_array( $found ) ? $found : array();
	}

	/**
	 * Build one artist card.
	 *
	 * @param WP_Term              $term    Artist term.
	 * @param array<string, mixed> $options Resolved options.
	 * @param int                  $rank    Popularity rank (1-3), zero when unranked.
	 * @return string
	 */
	private function card( WP_Term $term, array $options, int $rank = 0 ): string {
		$link = get_term_link( $term );
		if ( is_wp_error( $link ) || ! is_string( $link ) || '' === $link ) {
			return '';
		}

		$name = '' !== $term->name ? $term->name : __( 'هنرمند بی نام', 'music-wave-core' );
		/* translators: %s: artist name. */
		$open_label = sprintf( __( 'باز کردن %s', 'music-wave-core' ), $name );

		$avatar = '';
		if ( $options['show_image'] ) {
			$image_id   = absint( get_term_meta( $term->term_id, 'mw_artist_image_id', true ) );
			$image_html = $image_id > 0
				? wp_get_attachment_image(
					$image_id,
					$options['image_size'],
					false,
					array(
						'class'    => 'mw-artists-shelf__image',
						'alt'      => '',
						'loading'  => 'lazy',
						'decoding' => 'async',
					)
				)
				: '';
			$initial    = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 );
			$hue_class  = '' === $image_html ? TermHue::modifier( TermHue::for_term( $term ) ) : '';
			$avatar     = '<a class="mw-artists-shelf__avatar mw-artists-shelf__avatar--' . esc_attr( (string) $options['image_shape'] ) . ( '' !== $hue_class ? ' ' . $hue_class : '' ) . '" href="' . esc_url( $link ) . '" aria-label="' . esc_attr( $open_label ) . '">'
				. ( '' !== $image_html ? $image_html : '<span class="mw-artists-shelf__initial" aria-hidden="true">' . esc_html( $initial ) . '</span>' )
				. '</a>';
		}

		$name_html = '';
		if ( $options['show_name'] ) {
			$name_html = '<h3 class="mw-artists-shelf__name"><a href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a></h3>';
		}

		$count_html = '';
		if ( $options['show_count'] && $term->count > 0 ) {
			$count_html = '<p class="mw-artists-shelf__count">' . esc_html(
				sprintf(
					/* translators: %s: number of published releases. */
					__( '%s اثر', 'music-wave-core' ),
					number_format_i18n( (int) $term->count )
				)
			) . '</p>';
		}

		$bio_html = '';
		if ( $options['show_bio'] && $options['bio_length'] >= 5 ) {
			$biography = (string) get_term_meta( $term->term_id, 'mw_artist_biography', true );
			// Fall back to the artist's term description so the toggle produces
			// a visible excerpt even when no biography meta has been authored.
			if ( '' === trim( $biography ) && '' !== trim( $term->description ) ) {
				$biography = (string) $term->description;
			}
			if ( '' !== $biography ) {
				$bio_html = '<p class="mw-artists-shelf__bio">' . esc_html( wp_trim_words( wp_strip_all_tags( $biography ), $options['bio_length'] ) ) . '</p>';
			}
		}

		$follow_html = '';
		if ( $options['show_follow'] ) {
			$button = LibraryButton::markup(
				LibraryRepository::TYPE_ARTIST,
				(int) $term->term_id,
				array(
					'style'   => 'ghost',
					'compact' => true,
				)
			);
			if ( '' !== $button ) {
				$follow_html = '<div class="mw-artists-shelf__follow">' . $button . '</div>';
			}
		}

		return '<article class="mw-artists-shelf__item">'
			. ( '' !== $avatar || $rank > 0 ? $this->media( $avatar, $rank ) : $avatar )
			. '<div class="mw-artists-shelf__body">' . $name_html . $count_html . $bio_html . $follow_html . '</div>'
			. '</article>';
	}

	/**
	 * Card media shell: the cover plus the rank badge.
	 *
	 * The wrapper keeps the cover's own sizing (it stretches to the card width,
	 * like the avatar did before) and gives the crown a positioning context.
	 *
	 * @param string $avatar Avatar link markup.
	 * @param int    $rank   Popularity rank (1-3), zero when unranked.
	 */
	private function media( string $avatar, int $rank ): string {
		return '<div class="mw-artists-shelf__media">' . $avatar . $this->crown( $rank ) . '</div>';
	}

	/**
	 * Popularity badge for the first three artists of the popular ordering.
	 *
	 * @param int $rank One-based rank.
	 */
	private function crown( int $rank ): string {
		if ( $rank < 1 ) {
			return '';
		}

		/* translators: %s: popularity rank. */
		$label = sprintf( __( 'رتبهٔ %s در محبوبیت', 'music-wave-core' ), number_format_i18n( $rank ) );

		return '<span class="mw-artists-shelf__crown mw-artists-shelf__crown--' . (int) $rank . '" role="img" aria-label="' . esc_attr( $label ) . '">'
			. '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M3 8.5 7.5 12 12 5l4.5 7L21 8.5V18a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1Z"/></svg>'
			. '<span aria-hidden="true">' . esc_html( number_format_i18n( $rank ) ) . '</span>'
			. '</span>';
	}

	/**
	 * Build the shared shelf header chrome.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<string, mixed> $options    Resolved options.
	 */
	private function header( array $attributes, array $options ): string {
		unset( $attributes );

		return TermShelfSupport::render_header(
			$options,
			__( 'هنرمندان محبوب', 'music-wave-core' ),
			__( 'دیدن همه هنرمندان', 'music-wave-core' )
		);
	}

	/**
	 * One-based page number requested by the current URL, clamped to the index.
	 *
	 * @param array<string, mixed> $options Resolved display options.
	 */
	private function current_page( array $options ): int {
		return min( $this->index()->page(), $this->index()->page_count( (int) $options['per_page'] ) );
	}

	/**
	 * Keep the canonical URL of a paged artists index on the current page.
	 *
	 * @param mixed $canonical Canonical URL produced by core.
	 * @param mixed $post      Post the URL belongs to (unused).
	 * @return mixed
	 */
	public function filter_canonical_url( $canonical, $post = null ) {
		unset( $post );
		if ( ! is_string( $canonical ) || '' === $canonical ) {
			return $canonical;
		}

		$page = $this->index()->page();

		return $page > 1 ? add_query_arg( self::PAGE_QUERY_VAR, $page, $canonical ) : $canonical;
	}

	/**
	 * Render the paginated index navigation under the shelf.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<string, mixed> $options    Resolved display options.
	 */
	private function pager( array $attributes, array $options ): string {
		unset( $attributes );

		if ( empty( $options['pager'] ) ) {
			return '';
		}

		return ArtistsPager::render( $this->index(), (int) $options['per_page'], 'mw-artists-shelf__pager' );
	}

	/**
	 * Editor-only placeholder so ServerSideRender never shows an error notice.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_editor_placeholder( array $attributes ): string {
		$label = __( 'ویترین هنرمندان', 'music-wave-core' );
		$help  = isset( $attributes['source'] ) && 'manual' === $attributes['source']
			? __( 'هیچ هنرمند منطبقی پیدا نشد. شناسه‌های هنرمند انتخاب‌شده را بررسی کنید.', 'music-wave-core' )
			: __( 'هنوز هیچ هنرمندی با انتشارهای منتشرشده یافت نشد. هنرمندان را به انتشارات اختصاص دهید و آن‌ها به‌طور خودکار در اینجا ظاهر می‌شوند.', 'music-wave-core' );

		return BlockSupport::editor_placeholder( $label, $help, 'mw-artists-shelf mw-artists-shelf--placeholder', 'dashicons-admin-users' );
	}
}
