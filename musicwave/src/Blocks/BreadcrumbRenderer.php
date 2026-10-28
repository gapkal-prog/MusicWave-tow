<?php
declare(strict_types=1);

namespace ManaCore\MusicWave\Theme\Blocks;

use ManaCore\MusicWave\Core\Seo\BreadcrumbTrail;
use WP_Post;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the dynamic `music-wave/breadcrumb` presentation block.
 *
 * Delegates route resolution to Core's canonical `BreadcrumbTrail` when active
 * (preserving 100% DRY parity with `SiteStructuredData` JSON-LD) with a self-contained
 * native WordPress fallback when the theme runs standalone, plus a live interactive
 * sample preview (`نمونه مسیر راهنما (Breadcrumb)`) inside the Site Editor / Block Editor.
 */
final class BreadcrumbRenderer {

	private const SEPARATORS = array( 'chevron', 'slash', 'dot', 'arrow', 'pipe', 'dash' );

	/** @var BlockRegistry */
	private BlockRegistry $blocks;

	/** @var ChromeBlocksRenderer */
	private ChromeBlocksRenderer $chrome;

	public function __construct( BlockRegistry $blocks, ChromeBlocksRenderer $chrome ) {
		$this->blocks = $blocks;
		$this->chrome = $chrome;
	}

	/**
	 * Render accessible `<nav aria-label="مسیر راهنما"><ol>...</ol></nav>` trail.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( array $attributes = array() ): string {
		$show_home         = ! isset( $attributes['showHome'] ) || false !== $attributes['showHome'];
		$home_label        = isset( $attributes['homeLabel'] ) && is_scalar( $attributes['homeLabel'] )
			? sanitize_text_field( trim( (string) $attributes['homeLabel'] ) )
			: '';
		$show_home_icon    = ! isset( $attributes['showHomeIcon'] ) || false !== $attributes['showHomeIcon'];
		$separator         = isset( $attributes['separator'] ) && is_scalar( $attributes['separator'] )
			? sanitize_key( (string) $attributes['separator'] )
			: 'chevron';
		$separator         = in_array( $separator, self::SEPARATORS, true ) ? $separator : 'chevron';
		$show_current      = ! isset( $attributes['showCurrent'] ) || false !== $attributes['showCurrent'];
		$show_catalog_root = ! isset( $attributes['showCatalogRoot'] ) || false !== $attributes['showCatalogRoot'];
		$hide_front        = ! isset( $attributes['hideOnFrontPage'] ) || false !== $attributes['hideOnFrontPage'];
		$preview_sample    = ! empty( $attributes['previewSample'] );
		$max_length        = isset( $attributes['maxLength'] ) ? min( 120, max( 0, absint( $attributes['maxLength'] ) ) ) : 0;
		$aria_label        = isset( $attributes['ariaLabel'] ) && is_scalar( $attributes['ariaLabel'] )
			? sanitize_text_field( trim( (string) $attributes['ariaLabel'] ) )
			: '';

		$options = array(
			'home_label'         => $home_label,
			'show_catalog_root'  => $show_catalog_root,
			'hide_on_front_page' => $hide_front,
		);

		$is_editor_preview = $preview_sample || $this->is_editor_preview_request();
		$items             = $preview_sample ? $this->sample_items( $options ) : $this->resolve_items( $options );

		if ( empty( $items ) && $is_editor_preview ) {
			$items = $this->sample_items( $options );
		}

		if ( empty( $items ) ) {
			return '';
		}

		if ( ! $show_home && count( $items ) > 1 ) {
			array_shift( $items );
		}

		if ( ! $show_current && count( $items ) > 1 ) {
			array_pop( $items );
		}

		if ( empty( $items ) ) {
			return '';
		}

		$variant_map   = $this->blocks->presentation_style_variations()['breadcrumb'] ?? array();
		$variant_class = $this->blocks->style_variant_class(
			$attributes,
			array_keys( $variant_map ),
			'mw-breadcrumb'
		);

		$classes = 'mw-breadcrumb mw-breadcrumb--sep-' . $separator . $variant_class;
		if ( $is_editor_preview ) {
			$classes .= ' mw-breadcrumb--preview';
		}

		$separator_markup = $this->separator_markup( $separator );
		$home_icon        = $show_home_icon ? $this->home_icon_markup() : '';
		$list_items       = array();
		$last_index       = count( $items ) - 1;

		foreach ( $items as $index => $item ) {
			$raw_label = isset( $item['name'] ) ? trim( (string) $item['name'] ) : '';
			if ( '' === $raw_label ) {
				continue;
			}
			$display_label = $this->truncate_label( $raw_label, $index > 0 ? $max_length : 0 );
			$title_attr    = $display_label !== $raw_label ? ' title="' . esc_attr( $raw_label ) . '"' : '';
			$url           = isset( $item['url'] ) ? (string) $item['url'] : '';
			$is_current    = ! empty( $item['current'] ) || ( $show_current && $index === $last_index );
			$icon_prefix   = 0 === $index && $show_home ? $home_icon : '';

			if ( $is_current || '' === $url ) {
				$inner = '<span class="mw-breadcrumb__current" aria-current="page"' . $title_attr . '>' . $icon_prefix . '<span class="mw-breadcrumb__text">' . esc_html( $display_label ) . '</span></span>';
			} else {
				$inner = '<a class="mw-breadcrumb__link" href="' . esc_url( $url ) . '"' . $title_attr . '>' . $icon_prefix . '<span class="mw-breadcrumb__text">' . esc_html( $display_label ) . '</span></a>';
			}

			$sep_html     = $index < $last_index ? $separator_markup : '';
			$item_classes = 'mw-breadcrumb__item' . ( $is_current ? ' is-current' : '' );
			$list_items[] = '<li class="' . esc_attr( $item_classes ) . '">' . $inner . $sep_html . '</li>';
		}

		if ( empty( $list_items ) ) {
			return '';
		}

		$preview_attr  = $is_editor_preview ? ' data-mw-breadcrumb-preview="1"' : '';
		$resolved_aria = '' !== $aria_label ? $aria_label : __( 'مسیر راهنما', 'musicwave' );

		return '<nav ' . $this->chrome->block_wrapper_attributes( $attributes, $classes ) . ' aria-label="' . esc_attr( $resolved_aria ) . '"' . $preview_attr . '><ol class="mw-breadcrumb__list">' . implode( '', $list_items ) . '</ol></nav>';
	}

	/**
	 * Detect WordPress Block Editor / Site Editor REST ServerSideRender requests.
	 */
	public function is_editor_preview_request(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$context = isset( $_GET['context'] ) && is_scalar( $_GET['context'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				? sanitize_key( wp_unslash( (string) $_GET['context'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				: '';
			if ( 'edit' === $context ) {
				return true;
			}
		}

		return function_exists( 'is_admin' ) && is_admin();
	}

	/**
	 * @param array<string, mixed> $options Resolver options.
	 * @return array<int, array{name: string, url: string, current: bool}>
	 */
	private function resolve_items( array $options ): array {
		if ( class_exists( BreadcrumbTrail::class ) ) {
			return BreadcrumbTrail::resolve( $options );
		}

		$hide_front = ! isset( $options['hide_on_front_page'] ) || (bool) $options['hide_on_front_page'];
		if ( function_exists( 'is_front_page' ) && is_front_page() ) {
			return $hide_front
				? array()
				: array(
					array(
						'name'    => $this->fallback_home_label( $options ),
						'url'     => home_url( '/' ),
						'current' => true,
					),
				);
		}

		$items = array(
			array(
				'name'    => $this->fallback_home_label( $options ),
				'url'     => home_url( '/' ),
				'current' => false,
			),
		);

		if ( function_exists( 'is_singular' ) && is_singular() ) {
			$post = get_post();
			if ( $post instanceof WP_Post ) {
				if ( ! empty( $post->post_parent ) && function_exists( 'get_post_ancestors' ) ) {
					foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor_id ) {
						$items[] = array(
							'name'    => get_the_title( (int) $ancestor_id ),
							'url'     => (string) get_permalink( (int) $ancestor_id ),
							'current' => false,
						);
					}
				}
				$items[] = array(
					'name'    => get_the_title( $post ),
					'url'     => (string) get_permalink( $post ),
					'current' => true,
				);
			}
		} elseif ( function_exists( 'is_tax' ) && is_tax() && function_exists( 'get_queried_object' ) ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$link    = get_term_link( $term );
				$items[] = array(
					'name'    => $term->name,
					'url'     => is_string( $link ) ? $link : '',
					'current' => true,
				);
			}
		} elseif ( function_exists( 'is_search' ) && is_search() ) {
			$items[] = array(
				'name'    => __( 'جست‌وجو', 'musicwave' ),
				'url'     => home_url( '/' ),
				'current' => true,
			);
		} elseif ( function_exists( 'is_404' ) && is_404() ) {
			$items[] = array(
				'name'    => __( 'صفحه پیدا نشد (۴۰۴)', 'musicwave' ),
				'url'     => '',
				'current' => true,
			);
		}

		return count( $items ) >= 2 ? $items : array();
	}

	/**
	 * @param array<string, mixed> $options Resolver options.
	 * @return array<int, array{name: string, url: string, current: bool}>
	 */
	private function sample_items( array $options ): array {
		if ( class_exists( BreadcrumbTrail::class ) ) {
			return BreadcrumbTrail::sample_items( $options );
		}

		$items = array(
			array(
				'name'    => $this->fallback_home_label( $options ),
				'url'     => home_url( '/' ),
				'current' => false,
			),
		);
		if ( ! isset( $options['show_catalog_root'] ) || (bool) $options['show_catalog_root'] ) {
			$items[] = array(
				'name'    => __( 'انتشارها', 'musicwave' ),
				'url'     => home_url( '/music/' ),
				'current' => false,
			);
		}
		$items[] = array(
			'name'    => __( 'آلبوم‌ها', 'musicwave' ),
			'url'     => home_url( '/release-type/album/' ),
			'current' => false,
		);
		$items[] = array(
			'name'    => __( 'نمونه مسیر راهنما (Breadcrumb)', 'musicwave' ),
			'url'     => '',
			'current' => true,
		);

		return $items;
	}

	/**
	 * @param array<string, mixed> $options Resolver options.
	 */
	private function fallback_home_label( array $options ): string {
		$label = isset( $options['home_label'] ) && is_scalar( $options['home_label'] )
			? trim( (string) $options['home_label'] )
			: '';

		return '' !== $label ? $label : __( 'خانه', 'musicwave' );
	}

	private function separator_markup( string $separator ): string {
		switch ( $separator ) {
			case 'slash':
				return '<span class="mw-breadcrumb__sep" aria-hidden="true">/</span>';
			case 'dot':
				return '<span class="mw-breadcrumb__sep" aria-hidden="true">·</span>';
			case 'arrow':
				return '<span class="mw-breadcrumb__sep mw-breadcrumb__sep--directional" aria-hidden="true">→</span>';
			case 'pipe':
				return '<span class="mw-breadcrumb__sep" aria-hidden="true">|</span>';
			case 'dash':
				return '<span class="mw-breadcrumb__sep" aria-hidden="true">—</span>';
			case 'chevron':
			default:
				return '<span class="mw-breadcrumb__sep mw-breadcrumb__sep--directional" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="m9 18 6-6-6-6"/></svg></span>';
		}
	}

	private function home_icon_markup(): string {
		return '<svg class="mw-breadcrumb__home-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>';
	}

	private function truncate_label( string $label, int $max_length ): string {
		if ( $max_length < 8 ) {
			return $label;
		}
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $label ) > $max_length ? rtrim( mb_substr( $label, 0, $max_length ) ) . '…' : $label;
		}

		return strlen( $label ) > $max_length ? rtrim( substr( $label, 0, $max_length ) ) . '…' : $label;
	}
}
