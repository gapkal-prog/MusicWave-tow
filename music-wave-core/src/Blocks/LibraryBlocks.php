<?php
/**
 * Personal music library blocks: the full library view and the toggle button.
 *
 * Refactored: DRY via ReleaseResolver, editor-safe placeholders,
 * icon-only heart handling, SOLID SRP.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

use ManaCore\MusicWave\Core\Blocks\Concerns\ReleaseResolver;
use ManaCore\MusicWave\Core\Library\LibraryButton;
use ManaCore\MusicWave\Core\Library\LibraryCatalog;
use ManaCore\MusicWave\Core\Library\LibraryRepository;
use ManaCore\MusicWave\Core\Account\AuthPolicy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LibraryBlocks {
	public const FILTER_QUERY_ARG = 'mw-library';
	public const PAGE_QUERY_ARG   = 'mw-library-page';

	/** @var LibraryCatalog */
	private $catalog;

	/** @var object|null */
	private $render_context;

	public function __construct( LibraryCatalog $catalog ) {
		$this->catalog = $catalog;
	}

	/**
	 * Register both library blocks.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		BlockSupport::register_dynamic(
			'music-wave/music-library',
			array( $this, 'render_library' ),
			array(
				'api_version' => 3,
				'attributes'  => $this->library_attributes(),
				'supports'    => BlockSupport::appearance_tools(),
			)
		);
		BlockSupport::register_dynamic(
			'music-wave/library-button',
			function ( $attributes, $content, $block ): string {
				unset( $content );
				$previous_context     = $this->render_context;
				$this->render_context = is_object( $block ) ? $block : null;

				try {
					return $this->render_button( is_array( $attributes ) ? $attributes : array() );
				} catch ( \Throwable $e ) {
					// Never throw in block-renderer REST – return safe placeholder in editor.
					if ( BlockSupport::is_editor_context() ) {
						return $this->editor_placeholder( $attributes );
					}
					return '';
				} finally {
					$this->render_context = $previous_context;
				}
			},
			array(
				'api_version'  => 3,
				'attributes'   => $this->button_attributes(),
				'uses_context' => array( 'postId', 'postType' ),
				'supports'     => BlockSupport::appearance_tools(),
			)
		);
	}

	/**
	 * Attribute schema for the personal library block.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function library_attributes(): array {
		return array(
			'heading'      => array(
				'type'    => 'string',
				'default' => '',
			),
			'showHeading'  => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'intro'        => array(
				'type'    => 'string',
				'default' => '',
			),
			'showFilters'  => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'showCounts'   => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'layout'       => array(
				'type'    => 'string',
				'default' => 'list',
			),
			'columns'      => array(
				'type'    => 'integer',
				'default' => 4,
			),
			'itemsToShow'  => array(
				'type'    => 'integer',
				'default' => 24,
			),
			'showArtist'   => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'showType'     => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'showYear'     => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'showRemove'   => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'emptyMessage' => array(
				'type'    => 'string',
				'default' => '',
			),
		);
	}

	/**
	 * Attribute schema for the add-to-library button block.
	 * Heart style is icon-only by design; label is SR-only but kept for a11y/JS.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function button_attributes(): array {
		return array(
			'releaseId'  => array(
				'type'    => 'integer',
				'default' => 0,
			),
			'itemType'   => array(
				'type'    => 'string',
				'default' => 'release',
			),
			'termId'     => array(
				'type'    => 'integer',
				'default' => 0,
			),
			'label'      => array(
				'type'    => 'string',
				'default' => '',
			),
			'addedLabel' => array(
				'type'    => 'string',
				'default' => '',
			),
			'style'      => array(
				'type'    => 'string',
				'default' => 'heart',
			),
			'variant'    => array(
				'type'    => 'string',
				'default' => 'surface',
			),
			'size'       => array(
				'type'    => 'string',
				'default' => 'md',
			),
			'shape'      => array(
				'type'    => 'string',
				'default' => 'pill',
			),
			'icon'       => array(
				'type'    => 'string',
				'default' => '',
			),
			'compact'    => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);
	}

	/**
	 * Render the complete personal music library.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_library( array $attributes ): string {
		if ( ! apply_filters( 'music_wave_library_enabled', true ) ) {
			return '';
		}

		$user_id      = get_current_user_id();
		$heading      = BlockSupport::optional_text_attribute( $attributes, 'heading', __( 'کتابخانه موسیقی من', 'music-wave-core' ), 'showHeading' );
		$show_heading = '' !== $heading;
		$intro        = BlockSupport::optional_text_attribute( $attributes, 'intro', __( 'هر آهنگ، آلبوم، پادکست و هنرمندی که ذخیره می‌کنید در اینجا نشان داده می‌شود. موارد را با دکمه "افزودن به کتابخانه" در هر صفحه انتشار یا هنرمند اضافه کنید.', 'music-wave-core' ) );

		if ( $user_id < 1 ) {
			$guest_heading = $show_heading ? '<h2>' . esc_html( $heading ) . '</h2>' : '';
			return '<section ' . BlockSupport::wrapper_attributes( 'mw-music-library mw-music-library--guest' ) . '><div class="mw-music-library__guest"><span class="mw-music-library__eyebrow">' . esc_html__( 'مجموعه شما', 'music-wave-core' ) . '</span>' . $guest_heading . '<p>' . esc_html__( 'برای ایجاد کتابخانه شخصی خود وارد سیستم شوید: آهنگ‌ها، آلبوم‌ها، پادکست‌ها را ذخیره کنید و هنرمندان موردعلاقهٔ خود را دنبال کنید.', 'music-wave-core' ) . '</p><a class="wp-element-button" href="' . esc_url( AuthPolicy::login_url( home_url( '/' ) ) ) . '">' . esc_html__( 'وارد شوید', 'music-wave-core' ) . '</a></div></section>';
		}

		$filter  = $this->active_filter();
		$counts  = $this->catalog->counts( $user_id );
		$page    = $this->active_page();
		$paged   = $this->catalog->paged_summaries( $user_id, $filter, BlockSupport::range_attribute( $attributes, 'itemsToShow', 1, 100, 24 ), $page );
		$items   = $paged['items'];
		$layout  = BlockSupport::key_attribute( $attributes, 'layout', array( 'list', 'grid' ), 'list' );
		$columns = BlockSupport::range_attribute( $attributes, 'columns', 2, 6, 4 );

		LibraryButton::enqueue_assets();

		$header = '';
		if ( $show_heading ) {
			$total      = isset( $counts[ LibraryCatalog::FILTER_ALL ] ) ? (int) $counts[ LibraryCatalog::FILTER_ALL ] : 0;
			$badge      = ( ! isset( $attributes['showCounts'] ) || false !== $attributes['showCounts'] ) && $total > 0
				? '<span class="mw-music-library__total" data-mw-library-count="all">' . esc_html( (string) $total ) . '</span>'
				: '';
			$intro_html = '' !== $intro ? '<p class="mw-music-library__intro">' . esc_html( $intro ) . '</p>' : '';
			$header     = '<header class="mw-music-library__header"><div><h2>' . esc_html( $heading ) . '</h2>' . $intro_html . '</div>' . $badge . '</header>';
		}

		$tabs = '';
		if ( ( ! isset( $attributes['showFilters'] ) || false !== $attributes['showFilters'] ) && count( $counts ) > 1 ) {
			$tabs = $this->filter_tabs( $counts, $filter, ! isset( $attributes['showCounts'] ) || false !== $attributes['showCounts'] );
		}

		$empty = BlockSupport::text_attribute(
			$attributes,
			'emptyMessage',
			LibraryCatalog::FILTER_ALL === $filter
			? __( 'کتابخانه شما خالی است هر صفحه انتشار یا هنرمندی را باز کنید و از دکمه "افزودن به کتابخانه" برای ذخیره آن در اینجا استفاده کنید.', 'music-wave-core' )
			: __( 'هنوز چیزی در این مجموعه ذخیره نشده است.', 'music-wave-core' )
		);

		$body = '';
		if ( empty( $items ) ) {
			$body = '<p class="mw-music-library__empty" data-mw-library-empty>' . esc_html( $empty ) . '</p>';
		} else {
			$rows = array();
			foreach ( $items as $summary ) {
				$rows[] = $this->item_markup( $summary, $attributes );
			}
			$class = 'mw-music-library__items mw-music-library__items--' . $layout
				. ( 'grid' === $layout ? ' mw-music-library__items--columns-' . $columns : '' );
			$body  = '<ul class="' . esc_attr( $class ) . '" data-mw-library-items>' . implode( '', $rows ) . '</ul>'
				. '<p class="mw-music-library__empty" data-mw-library-empty hidden>' . esc_html( $empty ) . '</p>'
				. $this->pagination_markup( $filter, $page, (bool) $paged['has_more'] );
		}

		return '<section ' . BlockSupport::wrapper_attributes( 'mw-music-library' ) . ' data-mw-library-panel>' . $header . $tabs . $body . '</section>';
	}

	/**
	 * Render a standalone add-to-library button.
	 * Icon-only heart by default; safe placeholder in editor to avoid REST errors.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_button( array $attributes ): string {
		$settings  = $this->button_settings( $attributes );
		$term_id   = isset( $attributes['termId'] ) ? absint( $attributes['termId'] ) : 0;
		$item_type = BlockSupport::key_attribute(
			$attributes,
			'itemType',
			array(
				LibraryRepository::TYPE_RELEASE,
				LibraryRepository::TYPE_WISHLIST,
				LibraryRepository::TYPE_PRESAVE,
				LibraryRepository::TYPE_ARTIST,
			),
			$term_id > 0 ? LibraryRepository::TYPE_ARTIST : LibraryRepository::TYPE_RELEASE
		);

		if ( LibraryRepository::TYPE_ARTIST === $item_type || $term_id > 0 ) {
			$resolved_term_id = $this->resolve_artist_term_id( $term_id, $attributes );
			if ( $resolved_term_id < 1 ) {
				return BlockSupport::is_editor_context() ? $this->editor_placeholder( $attributes, LibraryRepository::TYPE_ARTIST ) : '';
			}
			$markup = LibraryButton::markup( LibraryRepository::TYPE_ARTIST, $resolved_term_id, $settings );
			if ( '' === $markup ) {
				return BlockSupport::is_editor_context() ? $this->editor_placeholder( $attributes, LibraryRepository::TYPE_ARTIST ) : '';
			}
			return '<div ' . BlockSupport::wrapper_attributes( 'mw-library-button-wrap mw-library-button-wrap--artist' ) . '>' . $markup . '</div>';
		}

		$release_id = ReleaseResolver::resolve( $attributes, $this->render_context );
		if ( $release_id < 1 ) {
			return BlockSupport::is_editor_context() ? $this->editor_placeholder( $attributes, $item_type ) : '';
		}

		$markup = LibraryButton::markup( $item_type, $release_id, $settings );
		if ( '' === $markup ) {
			return BlockSupport::is_editor_context() ? $this->editor_placeholder( $attributes, $item_type ) : '';
		}

		return '<div ' . BlockSupport::wrapper_attributes( 'mw-library-button-wrap mw-library-button-wrap--' . $item_type ) . '>' . $markup . '</div>';
	}

	/**
	 * Resolve target artist term ID from explicit pin, queried artist archive,
	 * or primary artist on the current release.
	 *
	 * @param int                  $explicit_term_id Explicit `termId` attribute.
	 * @param array<string, mixed> $attributes       Block attributes.
	 */
	private function resolve_artist_term_id( int $explicit_term_id, array $attributes ): int {
		if ( $explicit_term_id > 0 ) {
			return $explicit_term_id;
		}

		if ( function_exists( 'get_queried_object' ) ) {
			$queried = get_queried_object();
			if ( is_object( $queried ) && isset( $queried->term_id, $queried->taxonomy ) && 'mw_artist' === $queried->taxonomy ) {
				return absint( $queried->term_id );
			}
		}

		$release_id = ReleaseResolver::resolve( $attributes, $this->render_context );
		if ( $release_id > 0 && function_exists( 'wp_get_post_terms' ) ) {
			$terms = wp_get_post_terms( $release_id, 'mw_artist', array( 'fields' => 'ids' ) );
			if ( is_array( $terms ) && ! empty( $terms[0] ) ) {
				return absint( $terms[0] );
			}
		}

		if ( BlockSupport::is_editor_context() && function_exists( 'get_terms' ) ) {
			$artist_terms = get_terms(
				array(
					'taxonomy'   => 'mw_artist',
					'hide_empty' => false,
					'number'     => 1,
					'orderby'    => 'term_id',
					'order'      => 'DESC',
					'fields'     => 'ids',
				)
			);
			if ( is_array( $artist_terms ) && ! empty( $artist_terms[0] ) ) {
				return absint( $artist_terms[0] );
			}
		}

		return 0;
	}

	/**
	 * Safe placeholder for Site Editor mirroring the active `style`, `itemType`,
	 * `label`, and `compact` settings live in real time.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $item_type  Target library item type.
	 */
	private function editor_placeholder( array $attributes, string $item_type = LibraryRepository::TYPE_RELEASE ): string {
		$settings = $this->button_settings( $attributes );
		$button   = LibraryButton::placeholder_markup( $item_type, $settings );
		return '<div ' . BlockSupport::wrapper_attributes( 'mw-library-button-wrap mw-library-button-wrap--' . $item_type . ' mw-library-button-wrap--placeholder' ) . '>' . $button . '</div>';
	}

	/**
	 * Build one list or grid row for a stored library item.
	 *
	 * @param array<string, mixed> $summary    Item summary from the catalog.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	private function item_markup( array $summary, array $attributes ): string {
		$type       = (string) $summary['type'];
		$item_id    = (int) $summary['id'];
		$title      = (string) $summary['title'];
		$url        = (string) $summary['url'];
		$artist     = (string) $summary['subtitle'];
		$type_label = (string) $summary['type_label'];
		$year       = (string) $summary['year'];

		$image = (string) $summary['image'];
		if ( '' === $image ) {
			$initial = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 1 ) : substr( $title, 0, 1 );
			$image   = '<span class="mw-music-library__placeholder" aria-hidden="true">' . esc_html( $initial ) . '</span>';
		}

		$title_markup = '' !== $url
			? '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>'
			: esc_html( $title );

		$meta = array();
		if ( ! isset( $attributes['showType'] ) || false !== $attributes['showType'] ) {
			$meta[] = '<span class="mw-music-library__badge">' . esc_html( $type_label ) . '</span>';
		}
		if ( LibraryRepository::TYPE_RELEASE === $type && '' !== $artist && ( ! isset( $attributes['showArtist'] ) || false !== $attributes['showArtist'] ) ) {
			$meta[] = '<span class="mw-music-library__artist">' . esc_html( $artist ) . '</span>';
		}
		if ( LibraryRepository::TYPE_RELEASE === $type && '' !== $year && ! empty( $attributes['showYear'] ) ) {
			$meta[] = '<span class="mw-music-library__year">' . esc_html( $year ) . '</span>';
		}

		$remove = '';
		if ( ! isset( $attributes['showRemove'] ) || false !== $attributes['showRemove'] ) {
			/* translators: %s: value 1. */
			$remove_label = sprintf( __( '%s را از کتابخانه حذف کنید', 'music-wave-core' ), $title );
			$remove       = '<button type="button" class="mw-library-remove" data-mw-library-type="' . esc_attr( $type ) . '" data-mw-library-id="' . esc_attr( (string) $item_id ) . '" aria-label="' . esc_attr( $remove_label ) . '" title="' . esc_attr( $remove_label ) . '">&times;</button>';
		}

		$item_class = 'mw-music-library__item mw-music-library__item--' . esc_attr( $type );

		return '<li class="' . $item_class . '" data-mw-library-item="' . esc_attr( $type . '-' . $item_id ) . '">'
			. '<span class="mw-music-library__art">' . $image . '</span>'
			. '<div class="mw-music-library__details"><strong>' . $title_markup . '</strong>'
			. ( empty( $meta ) ? '' : '<div class="mw-music-library__meta">' . implode( '', $meta ) . '</div>' )
			. '</div>' . $remove . '</li>';
	}

	/**
	 * Render the filter tab navigation for the active library.
	 *
	 * @param array<string, int> $counts     Filter counts.
	 * @param string             $active     Active filter key.
	 * @param bool               $show_counts Whether tabs display their count.
	 * @return string
	 */
	private function filter_tabs( array $counts, string $active, bool $show_counts ): string {
		$labels = $this->catalog->filter_labels( $counts );
		$tabs   = array();

		$trailing = array( LibraryCatalog::FILTER_WISHLIST, LibraryCatalog::FILTER_PRESAVES, LibraryCatalog::FILTER_ARTISTS );
		$ordered  = array( LibraryCatalog::FILTER_ALL );
		foreach ( array_keys( $counts ) as $key ) {
			if ( LibraryCatalog::FILTER_ALL !== $key && ! in_array( (string) $key, $trailing, true ) ) {
				$ordered[] = (string) $key;
			}
		}
		foreach ( $trailing as $key ) {
			if ( isset( $counts[ $key ] ) ) {
				$ordered[] = $key;
			}
		}

		foreach ( $ordered as $key ) {
			if ( ! isset( $counts[ $key ] ) || $counts[ $key ] < 1 ) {
				continue;
			}
			$label  = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
			$count  = $show_counts ? '<span data-mw-library-count="' . esc_attr( $key ) . '">' . esc_html( (string) $counts[ $key ] ) . '</span>' : '';
			$class  = 'mw-music-library__tab' . ( $key === $active ? ' is-active' : '' );
			$tabs[] = '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $this->filter_url( $key ) ) . '" aria-current="' . ( $key === $active ? 'true' : 'false' ) . '">' . esc_html( $label ) . $count . '</a>';
		}

		if ( count( $tabs ) < 2 ) {
			return '';
		}

		return '<nav class="mw-music-library__tabs" aria-label="' . esc_attr__( 'فیلترهای کتابخانه', 'music-wave-core' ) . '">' . implode( '', $tabs ) . '</nav>';
	}

	/**
	 * Build the current page URL narrowed to one library filter.
	 */
	private function filter_url( string $filter ): string {
		$base = is_singular() ? (string) get_permalink() : (string) home_url( '/' );
		if ( LibraryCatalog::FILTER_ALL === $filter ) {
			$cleaned = remove_query_arg( self::FILTER_QUERY_ARG, $base );

			return is_string( $cleaned ) ? $cleaned : $base;
		}

		return add_query_arg( self::FILTER_QUERY_ARG, rawurlencode( $filter ), $base );
	}

	/**
	 * Accessible previous/next pagination preserving the active filter.
	 */
	private function pagination_markup( string $filter, int $page, bool $has_more ): string {
		if ( $page <= 1 && ! $has_more ) {
			return '';
		}

		$links = array();
		if ( $page > 1 ) {
			$links[] = '<a class="mw-music-library__page-link mw-music-library__page-link--previous" href="' . esc_url( $this->page_url( $filter, $page - 1 ) ) . '">' . esc_html__( 'موارد جدیدتر', 'music-wave-core' ) . '</a>';
		}
		if ( $has_more ) {
			$links[] = '<a class="mw-music-library__page-link mw-music-library__page-link--next" href="' . esc_url( $this->page_url( $filter, $page + 1 ) ) . '">' . esc_html__( 'اقلام قدیمی', 'music-wave-core' ) . '</a>';
		}

		/* translators: %d: value 1. */
		$status = sprintf( __( 'صفحه کتابخانه %d', 'music-wave-core' ), $page );

		return '<nav class="mw-music-library__pagination" aria-label="' . esc_attr__( 'صفحات کتابخانه', 'music-wave-core' ) . '"><span class="mw-music-library__page-status">' . esc_html( $status ) . '</span>' . implode( '', $links ) . '</nav>';
	}

	/**
	 * Build the current page URL for one library page, preserving the filter.
	 */
	private function page_url( string $filter, int $page ): string {
		$base = $this->filter_url( $filter );
		if ( $page <= 1 ) {
			$cleaned = remove_query_arg( self::PAGE_QUERY_ARG, $base );

			return is_string( $cleaned ) ? $cleaned : $base;
		}

		return add_query_arg( self::PAGE_QUERY_ARG, (string) $page, $base );
	}

	/**
	 * Read the active library page from the public query string.
	 */
	private function active_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination query arg.
		$page = isset( $_GET[ self::PAGE_QUERY_ARG ] ) && is_scalar( $_GET[ self::PAGE_QUERY_ARG ] ) ? absint( wp_unslash( (string) $_GET[ self::PAGE_QUERY_ARG ] ) ) : 0;

		return max( 1, $page );
	}

	/**
	 * Read the active filter from the public query string.
	 */
	private function active_filter(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab filter query arg.
		$filter = isset( $_GET[ self::FILTER_QUERY_ARG ] ) && is_scalar( $_GET[ self::FILTER_QUERY_ARG ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::FILTER_QUERY_ARG ] ) ) : '';

		return '' !== $filter ? $filter : LibraryCatalog::FILTER_ALL;
	}

	/**
	 * Convert block attributes into LibraryButton settings.
	 * Heart is icon-only by design; label is kept for a11y.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<string, mixed>
	 */
	private function button_settings( array $attributes ): array {
		$style = '';
		if ( isset( $attributes['style'] ) && is_string( $attributes['style'] ) && '' !== trim( $attributes['style'] ) ) {
			$style = sanitize_key( $attributes['style'] );
		} elseif ( isset( $attributes['buttonStyle'] ) && is_scalar( $attributes['buttonStyle'] ) ) {
			$style = sanitize_key( (string) $attributes['buttonStyle'] );
		} else {
			$style = 'heart';
		}
		$style = LibraryButton::normalize_style( $style );
		return array(
			'label'      => isset( $attributes['label'] ) && is_scalar( $attributes['label'] ) ? sanitize_text_field( (string) $attributes['label'] ) : '',
			'addedLabel' => isset( $attributes['addedLabel'] ) && is_scalar( $attributes['addedLabel'] ) ? sanitize_text_field( (string) $attributes['addedLabel'] ) : '',
			'style'      => $style,
			'variant'    => isset( $attributes['variant'] ) && is_scalar( $attributes['variant'] ) ? sanitize_key( (string) $attributes['variant'] ) : 'surface',
			'size'       => isset( $attributes['size'] ) && is_scalar( $attributes['size'] ) ? sanitize_key( (string) $attributes['size'] ) : 'md',
			'shape'      => isset( $attributes['shape'] ) && is_scalar( $attributes['shape'] ) ? sanitize_key( (string) $attributes['shape'] ) : 'pill',
			'icon'       => isset( $attributes['icon'] ) && is_scalar( $attributes['icon'] ) ? trim( (string) $attributes['icon'] ) : 'heart',
			'compact'    => ! empty( $attributes['compact'] ),
			'showCount'  => ! empty( $attributes['showCount'] ),
		);
	}
}
