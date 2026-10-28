<?php
/**
 * Canonical route-aware breadcrumb trail resolver shared between the visible
 * `music-wave/breadcrumb` FSE block and `SiteStructuredData` JSON-LD `@graph`.
 *
 * Single Responsibility + DRY: route hierarchy logic lives in exactly one class
 * so HTML breadcrumbs and Schema.org `BreadcrumbList` never drift out of sync.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Seo;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use WP_Post;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BreadcrumbTrail {

	private const CATALOG_TAXONOMIES = array(
		'mw_artist',
		'mw_genre',
		'mw_mood',
		'mw_release_type',
		'mw_label',
	);

	/**
	 * Resolve the active WordPress request into an ordered breadcrumb trail.
	 *
	 * @param array<string, mixed> $options Optional overrides (`home_label`, `show_catalog_root`, `hide_on_front_page`).
	 * @return array<int, array{name: string, url: string, current: bool}>
	 */
	public static function resolve( array $options = array() ): array {
		$hide_front = ! isset( $options['hide_on_front_page'] ) || (bool) $options['hide_on_front_page'];
		if ( function_exists( 'is_front_page' ) && is_front_page() ) {
			if ( $hide_front ) {
				return array();
			}
			$home_label = self::resolve_home_label( $options );
			return array(
				array(
					'name'    => $home_label,
					'url'     => home_url( '/' ),
					'current' => true,
				),
			);
		}

		$home_url          = home_url( '/' );
		$home_label        = self::resolve_home_label( $options );
		$show_catalog_root = ! isset( $options['show_catalog_root'] ) || (bool) $options['show_catalog_root'];
		$archive_link      = function_exists( 'get_post_type_archive_link' )
			? (string) get_post_type_archive_link( ReleasePostType::KEY )
			: '';

		$items = array(
			array(
				'name'    => $home_label,
				'url'     => $home_url,
				'current' => false,
			),
		);

		if ( function_exists( 'is_singular' ) && is_singular( ReleasePostType::KEY ) ) {
			$post = get_post();
			if ( ! $post instanceof WP_Post ) {
				return array();
			}
			if ( $show_catalog_root && '' !== $archive_link ) {
				$items[] = array(
					'name'    => __( 'انتشارها', 'music-wave-core' ),
					'url'     => $archive_link,
					'current' => false,
				);
			}
			$artists = wp_get_post_terms( $post->ID, 'mw_artist' );
			if ( is_array( $artists ) && ! empty( $artists[0] ) && $artists[0] instanceof WP_Term ) {
				$artist_url = get_term_link( $artists[0] );
				if ( is_string( $artist_url ) && '' !== $artist_url ) {
					$items[] = array(
						'name'    => $artists[0]->name,
						'url'     => $artist_url,
						'current' => false,
					);
				}
			}
			$permalink = get_permalink( $post );
			$items[]   = array(
				'name'    => get_the_title( $post ),
				'url'     => is_string( $permalink ) ? $permalink : '',
				'current' => true,
			);
		} elseif ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( ReleasePostType::KEY ) ) {
			$items[] = array(
				'name'    => __( 'انتشارها', 'music-wave-core' ),
				'url'     => $archive_link,
				'current' => true,
			);
		} elseif ( function_exists( 'is_tax' ) && is_tax() && function_exists( 'get_queried_object' ) ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				if ( $show_catalog_root && '' !== $archive_link && in_array( $term->taxonomy, self::CATALOG_TAXONOMIES, true ) ) {
					$items[] = array(
						'name'    => __( 'انتشارها', 'music-wave-core' ),
						'url'     => $archive_link,
						'current' => false,
					);
				}
				self::append_term_ancestors( $items, $term );
				$term_link = get_term_link( $term );
				$items[]   = array(
					'name'    => $term->name,
					'url'     => is_string( $term_link ) ? $term_link : '',
					'current' => true,
				);
			}
		} elseif ( function_exists( 'is_category' ) && is_category() && function_exists( 'get_queried_object' ) ) {
			$cat = get_queried_object();
			if ( $cat instanceof WP_Term ) {
				self::append_term_ancestors( $items, $cat );
				$cat_link = get_term_link( $cat );
				$items[]  = array(
					'name'    => $cat->name,
					'url'     => is_string( $cat_link ) ? $cat_link : '',
					'current' => true,
				);
			}
		} elseif ( function_exists( 'is_tag' ) && is_tag() && function_exists( 'get_queried_object' ) ) {
			$tag = get_queried_object();
			if ( $tag instanceof WP_Term ) {
				$tag_link = get_term_link( $tag );
				$items[]  = array(
					'name'    => $tag->name,
					'url'     => is_string( $tag_link ) ? $tag_link : '',
					'current' => true,
				);
			}
		} elseif ( function_exists( 'is_singular' ) && is_singular() ) {
			$post = get_post();
			if ( $post instanceof WP_Post ) {
				if ( 'post' === $post->post_type ) {
					$cats = function_exists( 'get_the_category' ) ? get_the_category( $post->ID ) : array();
					if ( is_array( $cats ) && ! empty( $cats[0] ) && $cats[0] instanceof WP_Term ) {
						$cat_link = get_term_link( $cats[0] );
						if ( is_string( $cat_link ) && '' !== $cat_link ) {
							$items[] = array(
								'name'    => $cats[0]->name,
								'url'     => $cat_link,
								'current' => false,
							);
						}
					}
				} elseif ( 'product' === $post->post_type && function_exists( 'wc_get_page_permalink' ) ) {
					$shop_url = (string) wc_get_page_permalink( 'shop' );
					if ( '' !== $shop_url ) {
						$items[] = array(
							'name'    => __( 'فروشگاه', 'music-wave-core' ),
							'url'     => $shop_url,
							'current' => false,
						);
					}
				} elseif ( ! empty( $post->post_parent ) && function_exists( 'get_post_ancestors' ) ) {
					$ancestors = array_reverse( get_post_ancestors( $post ) );
					foreach ( $ancestors as $ancestor_id ) {
						$anc_url   = get_permalink( (int) $ancestor_id );
						$anc_title = get_the_title( (int) $ancestor_id );
						if ( is_string( $anc_url ) && '' !== $anc_url && '' !== $anc_title ) {
							$items[] = array(
								'name'    => $anc_title,
								'url'     => $anc_url,
								'current' => false,
							);
						}
					}
				}
				$permalink = get_permalink( $post );
				$items[]   = array(
					'name'    => get_the_title( $post ),
					'url'     => is_string( $permalink ) ? $permalink : '',
					'current' => true,
				);
			}
		} elseif ( function_exists( 'is_search' ) && is_search() ) {
			$query = function_exists( 'get_search_query' ) ? trim( (string) get_search_query( false ) ) : '';
			$label = '' !== $query
				/* translators: %s: search query string. */
				? sprintf( __( 'نتایج جست‌وجو برای «%s»', 'music-wave-core' ), $query )
				: __( 'جست‌وجو', 'music-wave-core' );
			$items[] = array(
				'name'    => $label,
				'url'     => add_query_arg( array( 's' => $query ), $home_url ),
				'current' => true,
			);
		} elseif ( function_exists( 'is_404' ) && is_404() ) {
			$items[] = array(
				'name'    => __( 'صفحه پیدا نشد (۴۰۴)', 'music-wave-core' ),
				'url'     => '',
				'current' => true,
			);
		} elseif ( function_exists( 'is_archive' ) && is_archive() && function_exists( 'get_the_archive_title' ) ) {
			$title = wp_strip_all_tags( (string) get_the_archive_title() );
			if ( '' !== $title ) {
				$items[] = array(
					'name'    => $title,
					'url'     => '',
					'current' => true,
				);
			}
		}

		if ( count( $items ) < 2 ) {
			return array();
		}

		$paged = function_exists( 'get_query_var' ) ? absint( get_query_var( 'paged' ) ) : 0;
		if ( $paged > 1 ) {
			$last_idx                      = count( $items ) - 1;
			$items[ $last_idx ]['current'] = false;
			$items[]                       = array(
				/* translators: %s: localized page number. */
				'name'    => sprintf( __( 'صفحهٔ %s', 'music-wave-core' ), number_format_i18n( $paged ) ),
				'url'     => function_exists( 'get_pagenum_link' ) ? (string) get_pagenum_link( $paged ) : '',
				'current' => true,
			);
		}

		/**
		 * Filter resolved breadcrumb items across visible HTML and JSON-LD `@graph`.
		 *
		 * @param array<int, array{name: string, url: string, current: bool}> $items   Trail items.
		 * @param array<string, mixed>                                        $options Resolver options.
		 */
		$filtered = apply_filters( 'music_wave_breadcrumb_items', $items, $options );

		return is_array( $filtered ) ? array_values( $filtered ) : $items;
	}

	/**
	 * Sample preview trail rendered inside the Block / Site Editor canvas.
	 *
	 * @param array<string, mixed> $options Optional overrides (`home_label`, `show_catalog_root`).
	 * @return array<int, array{name: string, url: string, current: bool}>
	 */
	public static function sample_items( array $options = array() ): array {
		$home_label        = self::resolve_home_label( $options );
		$show_catalog_root = ! isset( $options['show_catalog_root'] ) || (bool) $options['show_catalog_root'];

		$items = array(
			array(
				'name'    => $home_label,
				'url'     => home_url( '/' ),
				'current' => false,
			),
		);
		if ( $show_catalog_root ) {
			$items[] = array(
				'name'    => __( 'انتشارها', 'music-wave-core' ),
				'url'     => home_url( '/music/' ),
				'current' => false,
			);
		}
		$items[] = array(
			'name'    => __( 'آلبوم‌ها', 'music-wave-core' ),
			'url'     => home_url( '/release-type/album/' ),
			'current' => false,
		);
		$items[] = array(
			'name'    => __( 'نمونه مسیر راهنما (Breadcrumb)', 'music-wave-core' ),
			'url'     => '',
			'current' => true,
		);

		return $items;
	}

	/**
	 * @param array<string, mixed> $options Resolver options.
	 */
	private static function resolve_home_label( array $options ): string {
		$label = isset( $options['home_label'] ) && is_scalar( $options['home_label'] )
			? trim( (string) $options['home_label'] )
			: '';

		return '' !== $label ? $label : __( 'خانه', 'music-wave-core' );
	}

	/**
	 * @param array<int, array{name: string, url: string, current: bool}> $items Trail accumulator.
	 */
	private static function append_term_ancestors( array &$items, WP_Term $term ): void {
		if ( empty( $term->parent ) || ! function_exists( 'get_ancestors' ) ) {
			return;
		}
		$ancestors = array_reverse( get_ancestors( (int) $term->term_id, $term->taxonomy, 'taxonomy' ) );
		foreach ( $ancestors as $ancestor_id ) {
			$ancestor = get_term( (int) $ancestor_id, $term->taxonomy );
			if ( ! $ancestor instanceof WP_Term ) {
				continue;
			}
			$link = get_term_link( $ancestor );
			if ( is_string( $link ) && '' !== $link ) {
				$items[] = array(
					'name'    => $ancestor->name,
					'url'     => $link,
					'current' => false,
				);
			}
		}
	}
}
