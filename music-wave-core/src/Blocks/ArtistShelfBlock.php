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

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Catalog\TermHue;
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
	public const PAGE_QUERY_VAR = 'mw_artists_page';

	/** Published artist total, memoized so one render costs one count query. */
	private ?int $artist_total = null;

	/**
	 * Register the server-rendered artists shelf block.
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
	}

	/**
	 * Render the artists shelf.
	 *
	 * @param array<string, mixed> $attributes Block attributes from the editor.
	 *
	 * @return string
	 */
	public function render( array $attributes = array() ): string {
		// One render owns one count query; the memo never outlives the render.
		$this->artist_total = null;

		$options = $this->resolve_options( $attributes );
		$terms   = $this->resolve_terms( $attributes, $options );

		if ( array() === $terms ) {
			if ( BlockSupport::is_editor_context() ) {
				return $this->render_editor_placeholder( $attributes );
			}

			$message = '' !== $options['empty_message']
				? $options['empty_message']
				: __( 'هنوز هنرمندی برای نمایش وجود ندارد.', 'music-wave-core' );

			return '<section '
				. BlockSupport::wrapper_attributes( 'mw-artists-shelf mw-artists-shelf--empty' )
				. '><p class="mw-artists-shelf__empty">' . esc_html( $message ) . '</p></section>';
		}

		$cards = array();
		foreach ( $terms as $term ) {
			$card = $this->card( $term, $options );
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

		$variation   = \ManaCore\MusicWave\Core\Blocks\BlockSupport::style_variation( $attributes, array( 'minimal', 'editorial', 'glass' ) );
		$shelf_class = 'mw-artists-shelf mw-release-shelf mw-release-shelf--' . $options['layout'];
		if ( 'grid' === $options['layout'] ) {
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
			. \ManaCore\MusicWave\Core\Support\ShelfNavMarkup::items_region(
				(string) $options['layout'],
				implode( '', $cards ),
				isset( $options['heading'] ) ? (string) $options['heading'] : ''
			)
			. $this->pager( $attributes, $options )
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

		$image_shape = isset( $attributes['imageShape'] ) ? sanitize_key( (string) $attributes['imageShape'] ) : 'circle';
		// Backward compat: square was removed from UI (duplicate of rounded) – map it to rounded.
		if ( 'square' === $image_shape ) {
			$image_shape = 'rounded';
		}
		$image_shape = in_array( $image_shape, array( 'circle', 'rounded' ), true ) ? $image_shape : 'circle';

		$image_size = isset( $attributes['imageSize'] ) ? sanitize_key( (string) $attributes['imageSize'] ) : 'medium';
		$image_size = in_array( $image_size, array( 'thumbnail', 'medium', 'large', 'full' ), true ) ? $image_size : 'medium';

		$items = isset( $attributes['itemsToShow'] ) ? absint( $attributes['itemsToShow'] ) : 0;
		$items = min( self::MAX_ITEMS, max( 1, $items > 0 ? $items : 8 ) );

		$columns = isset( $attributes['columns'] ) ? absint( $attributes['columns'] ) : 4;
		$columns = min( 6, max( 2, $columns > 0 ? $columns : 4 ) );

		$bio_length = isset( $attributes['bioLength'] ) ? absint( $attributes['bioLength'] ) : 20;
		$bio_length = min( 80, $bio_length );

		$order_by = isset( $attributes['orderBy'] ) ? sanitize_key( (string) $attributes['orderBy'] ) : 'count';
		$order_by = in_array( $order_by, array( 'name', 'count', 'rand' ), true ) ? $order_by : 'count';

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

		return array(
			'layout'        => $layout,
			'pagination'    => ! empty( $attributes['pagination'] ),
			'per_page'      => $per_page,
			'image_shape'   => $image_shape,
			'image_size'    => $image_size,
			'items'         => $items,
			'columns'       => $columns,
			'bio_length'    => $bio_length,
			'order_by'      => $order_by,
			'order'         => $order,
			'show_heading'  => ! isset( $attributes['showHeading'] ) || (bool) $attributes['showHeading'],
			'show_image'    => ! isset( $attributes['showImage'] ) || (bool) $attributes['showImage'],
			'show_name'     => ! isset( $attributes['showName'] ) || (bool) $attributes['showName'],
			'show_bio'      => ! isset( $attributes['showBio'] ) || (bool) $attributes['showBio'],
			'show_count'    => ! isset( $attributes['showReleaseCount'] ) || (bool) $attributes['showReleaseCount'],
			'show_follow'   => ! isset( $attributes['showFollowButton'] ) || (bool) $attributes['showFollowButton'],
			'eyebrow'       => isset( $attributes['eyebrow'] ) ? sanitize_text_field( (string) $attributes['eyebrow'] ) : '',
			'heading'       => $heading_val,
			'description'   => isset( $attributes['description'] ) ? sanitize_text_field( (string) $attributes['description'] ) : '',
			'section_url'   => isset( $attributes['sectionUrl'] ) ? esc_url_raw( (string) $attributes['sectionUrl'] ) : '',
			'section_label' => isset( $attributes['sectionLinkLabel'] ) ? sanitize_text_field( (string) $attributes['sectionLinkLabel'] ) : '',
			'empty_message' => isset( $attributes['emptyMessage'] ) ? sanitize_text_field( (string) $attributes['emptyMessage'] ) : '',
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
		$source = isset( $attributes['source'] ) ? sanitize_key( (string) $attributes['source'] ) : 'all';
		$source = in_array( $source, array( 'all', 'genre', 'mood', 'manual' ), true ) ? $source : 'all';

		$terms = array();

		if ( 'manual' === $source ) {
			$terms = TermShelfSupport::query_terms(
				'mw_artist',
				true,
				isset( $attributes['artistIds'] ) ? (string) $attributes['artistIds'] : '',
				'count',
				self::MAX_ITEMS
			);
		} elseif ( 'genre' === $source || 'mood' === $source ) {
			$terms = $this->terms_behind_taxonomy( $source, isset( $attributes['termSlug'] ) ? sanitize_title( (string) $attributes['termSlug'] ) : '' );
		} elseif ( $this->pagination_enabled( $attributes, $options ) ) {
			$per_page = (int) $options['per_page'];
			$terms    = TermShelfSupport::query_terms(
				'mw_artist',
				false,
				'',
				(string) $options['order_by'],
				$per_page,
				( $this->current_page( $options ) - 1 ) * $per_page
			);
			// `finalize_terms()` honours `itemsToShow`; the page size is the
			// ceiling on a paged shelf, so hand it the effective number.
			$attributes['itemsToShow'] = $per_page;
		} else {
			$terms = TermShelfSupport::query_terms(
				'mw_artist',
				false,
				'',
				isset( $attributes['orderBy'] ) ? sanitize_key( (string) $attributes['orderBy'] ) : 'count',
				self::MAX_ITEMS
			);
		}

		return TermShelfSupport::finalize_terms( $terms, $attributes, self::MAX_ITEMS, 'music_wave_artists_shelf_terms', 'mw_artist' );
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
				'taxonomy'   => 'mw_artist',
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
	 * @return string
	 */
	private function card( WP_Term $term, array $options ): string {
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
					_n( 'انتشار %s', 'انتشار %s', (int) $term->count, 'music-wave-core' ),
					number_format_i18n( (int) $term->count )
				)
			) . '</p>';
		}

		$bio_html = '';
		if ( $options['show_bio'] && $options['bio_length'] >= 5 ) {
			$biography = (string) get_term_meta( $term->term_id, 'mw_artist_biography', true );
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
			. $avatar
			. '<div class="mw-artists-shelf__body">' . $name_html . $count_html . $bio_html . $follow_html . '</div>'
			. '</article>';
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
	 * Whether this render is a page of the paginated artists index.
	 *
	 * Pagination is deliberately limited to the "all artists" source: paged
	 * genre/mood shelves and hand-picked lists are editorial curves, not
	 * indexes, and a random order cannot be paged without repeating items.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<string, mixed> $options    Resolved display options.
	 */
	private function pagination_enabled( array $attributes, array $options ): bool {
		if ( empty( $options['pagination'] ) ) {
			return false;
		}

		$source = isset( $attributes['source'] ) ? sanitize_key( (string) $attributes['source'] ) : 'all';

		return 'all' === $source && 'rand' !== $options['order_by'];
	}

	/**
	 * One-based page number requested by the current URL, clamped to the index.
	 *
	 * @param array<string, mixed> $options Resolved display options.
	 */
	private function current_page( array $options ): int {
		return min( $this->requested_page_number(), $this->total_pages( $options ) );
	}

	/**
	 * Raw, one-based page number from the URL (never clamped, never filtered by
	 * the index size) so the canonical tag can mirror the requested page.
	 */
	private function requested_page_number(): int {
		if ( BlockSupport::is_editor_context() ) {
			return 1;
		}

		// Read-only navigation state: no nonce is involved and nothing is written.
		$raw  = isset( $_GET[ self::PAGE_QUERY_VAR ] ) ? wp_unslash( $_GET[ self::PAGE_QUERY_VAR ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = is_scalar( $raw ) ? absint( $raw ) : 0;
		$page = max( 1, (int) apply_filters( 'music_wave_artists_page', $page ) );

		return $page;
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

		$page = $this->requested_page_number();

		return $page > 1 ? add_query_arg( self::PAGE_QUERY_VAR, $page, $canonical ) : $canonical;
	}

	/**
	 * Published artist terms in the index, ignoring the browsing page.
	 */
	private function total_artists(): int {
		if ( null !== $this->artist_total ) {
			return $this->artist_total;
		}

		if ( ! function_exists( 'wp_count_terms' ) ) {
			$this->artist_total = 0;

			return $this->artist_total;
		}

		$total = wp_count_terms(
			array(
				'taxonomy'   => 'mw_artist',
				'hide_empty' => true,
			)
		);

		$this->artist_total = is_wp_error( $total ) ? 0 : absint( $total );

		return $this->artist_total;
	}

	/**
	 * Page count for the current page size (never less than one).
	 *
	 * @param array<string, mixed> $options Resolved display options.
	 */
	private function total_pages( array $options ): int {
		$per_page = (int) ( $options['per_page'] ?? 0 );
		if ( $per_page < 1 ) {
			return 1;
		}

		return max( 1, (int) ceil( $this->total_artists() / $per_page ) );
	}

	/**
	 * Absolute URL of one artists-index page.
	 *
	 * The current request is the base, so the pager survives blocks that live
	 * on a filtered URL (search, taxonomy, custom query args) instead of
	 * hard-coding `/artists/`.
	 *
	 * @param int $page One-based page number.
	 */
	private function page_url( int $page ): string {
		$base = isset( $_SERVER['REQUEST_URI'] ) ? remove_query_arg( self::PAGE_QUERY_VAR ) : home_url( '/' );

		return (string) add_query_arg( self::PAGE_QUERY_VAR, $page, $base );
	}

	/**
	 * Render the paginated index navigation under the shelf.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<string, mixed> $options    Resolved display options.
	 */
	private function pager( array $attributes, array $options ): string {
		if ( ! $this->pagination_enabled( $attributes, $options ) ) {
			return '';
		}

		$total_pages = $this->total_pages( $options );
		$total_items = $this->total_artists();
		if ( $total_pages < 2 || $total_items < 1 ) {
			return '';
		}

		$current = $this->current_page( $options );

		$numbers = array( 1, $total_pages );
		for ( $number = max( 1, $current - 1 ); $number <= min( $total_pages, $current + 1 ); $number++ ) {
			$numbers[] = $number;
		}
		$numbers = array_values( array_unique( $numbers ) );
		sort( $numbers );

		$items  = '';
		$before = 0;
		foreach ( $numbers as $number ) {
			if ( $before > 0 && $number - $before > 1 ) {
				$items .= '<li class="mw-pagination__item mw-pagination__item--gap"><span class="mw-pagination__ellipsis" aria-hidden="true">…</span></li>';
			}
			$before = $number;

			if ( $number === $current ) {
				$items .= '<li class="mw-pagination__item"><span class="mw-pagination__link is-current" aria-current="page">' . esc_html( number_format_i18n( $number ) ) . '</span></li>';
				continue;
			}

			$items .= '<li class="mw-pagination__item"><a class="mw-pagination__link" href="' . esc_url( $this->page_url( $number ) ) . '">' . esc_html( number_format_i18n( $number ) ) . '</a></li>';
		}

		if ( $current > 1 ) {
			$items = '<li class="mw-pagination__item"><a class="mw-pagination__link mw-pagination__link--prev" rel="prev" href="' . esc_url( $this->page_url( $current - 1 ) ) . '">' . esc_html__( 'قبلی', 'music-wave-core' ) . '</a></li>' . $items;
		}
		if ( $current < $total_pages ) {
			$items .= '<li class="mw-pagination__item"><a class="mw-pagination__link mw-pagination__link--next" rel="next" href="' . esc_url( $this->page_url( $current + 1 ) ) . '">' . esc_html__( 'بعدی', 'music-wave-core' ) . '</a></li>';
		}

		$status = sprintf(
			/* translators: 1: current page, 2: total pages, 3: total artist count. */
			__( 'صفحهٔ %1$s از %2$s · %3$s هنرمند', 'music-wave-core' ),
			number_format_i18n( $current ),
			number_format_i18n( $total_pages ),
			number_format_i18n( $total_items )
		);

		return '<nav class="mw-artists-shelf__pager" aria-label="' . esc_attr__( 'صفحه‌بندی هنرمندان', 'music-wave-core' ) . '">'
			. '<p class="mw-artists-shelf__pager-status">' . esc_html( $status ) . '</p>'
			. '<ul class="mw-pagination">' . $items . '</ul>'
			. '</nav>';
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
