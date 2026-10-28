<?php
/**
 * Unified search results block — like mw-catalog-suggest__list but for search page
 *
 * Shows grouped results: releases (by type: track, album, podcast, etc), artists, genres, products
 * Uses CatalogSearch::suggest for releases + taxonomy terms, plus Woo products
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

use ManaCore\MusicWave\Core\Blocks\Concerns\EditorPreviewCatalog;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Discovery\CatalogSearch;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SearchUnifiedBlock {
	/** @var CatalogSearch */
	private $search;

	public function __construct( ?CatalogSearch $search = null ) {
		$this->search = null !== $search ? $search : new CatalogSearch();
	}

	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		BlockSupport::register_dynamic(
			'music-wave/search-unified',
			array( $this, 'render' ),
			array(
				'api_version' => 3,
				'attributes'  => array(
					'showArtists'   => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'showReleases'  => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'showProducts'  => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'showGenres'    => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'limitPerGroup' => array(
						'type'    => 'integer',
						'default' => 6,
					),
				),
				'supports'    => BlockSupport::appearance_tools(),
			)
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 * @return string
	 */
	public function render( array $attributes = array() ): string {
		try {
			$show_artists    = ! isset( $attributes['showArtists'] ) || (bool) $attributes['showArtists'];
			$show_releases   = ! isset( $attributes['showReleases'] ) || (bool) $attributes['showReleases'];
			$show_products   = ! isset( $attributes['showProducts'] ) || (bool) $attributes['showProducts'];
			$show_genres     = ! isset( $attributes['showGenres'] ) || (bool) $attributes['showGenres'];
			$limit_per_group = isset( $attributes['limitPerGroup'] ) ? absint( $attributes['limitPerGroup'] ) : 6;
			$limit_per_group = $limit_per_group >= 1 && $limit_per_group <= 20 ? $limit_per_group : 6;

			$term = $this->current_search_term();
			if ( '' === $term ) {
				if ( BlockSupport::is_editor_context() ) {
					return $this->render_editor_preview( $show_artists, $show_releases, $show_genres, $limit_per_group );
				}
				return '';
			}

			// Get suggestions from catalog search (includes releases + taxonomy terms).
			$suggestions = $this->search->suggest( $term, 30 );

			// Group by type.
			$grouped = array(
				'release'      => array(),
				'artist'       => array(),
				'genre'        => array(),
				'mood'         => array(),
				'release_type' => array(),
				'product'      => array(),
			);
			foreach ( $suggestions as $item ) {
				$type = isset( $item['type'] ) ? (string) $item['type'] : 'other';
				if ( ! isset( $grouped[ $type ] ) ) {
					$grouped[ $type ] = array();
				}
				$grouped[ $type ][] = $item;
			}

			// Also query products separately if not already injected (in case filter didn't run).
			if ( $show_products && class_exists( 'WooCommerce' ) && function_exists( 'wc_get_products' ) ) {
				if ( empty( $grouped['product'] ) ) {
					$products           = $this->query_products( $term, $limit_per_group );
					$grouped['product'] = $products;
				}
			}

			// Query releases by release_type (podcast, track, album etc) separately to ensure grouping by release_type.
			$release_type_groups = array();
			if ( $show_releases ) {
				$release_type_groups = $this->group_releases_by_type( $term, $limit_per_group );
			}

			// Build HTML.
			$has_any = false;
			foreach ( $grouped as $g ) {
				if ( ! empty( $g ) ) {
					$has_any = true;
					break;
				}
			}
			if ( ! $has_any && empty( $release_type_groups ) ) {
				return BlockSupport::is_editor_context()
					? $this->render_editor_preview( $show_artists, $show_releases, $show_genres, $limit_per_group, $term )
					: '';
			}

			$html  = '<section ' . BlockSupport::wrapper_attributes( 'mw-search-unified' ) . '>';
			$html .= '<div class="mw-search-unified__groups">';

			// Releases grouped by release_type (track, album, podcast, etc).
			if ( $show_releases && ! empty( $release_type_groups ) ) {
				foreach ( $release_type_groups as $type_slug => $releases ) {
					if ( empty( $releases ) ) {
						continue;
					}
					$type_label = $this->release_type_label( $type_slug );
					$html      .= $this->render_group( $type_label, $releases, $limit_per_group, 'release', $type_slug );
				}
			} elseif ( $show_releases && ! empty( $grouped['release'] ) ) {
				$html .= $this->render_group( __( 'انتشارها', 'music-wave-core' ), $grouped['release'], $limit_per_group, 'release' );
			}

			// Artists.
			if ( $show_artists && ! empty( $grouped['artist'] ) ) {
				$html .= $this->render_group( __( 'هنرمندان', 'music-wave-core' ), $grouped['artist'], $limit_per_group, 'artist' );
			}

			// Genres.
			if ( $show_genres && ! empty( $grouped['genre'] ) ) {
				$html .= $this->render_group( __( 'سبک‌ها', 'music-wave-core' ), $grouped['genre'], $limit_per_group, 'genre' );
			}

			// Release types (if any left).
			if ( ! empty( $grouped['release_type'] ) ) {
				$html .= $this->render_group( __( 'انواع انتشار', 'music-wave-core' ), $grouped['release_type'], $limit_per_group, 'release_type' );
			}

			// Moods.
			if ( ! empty( $grouped['mood'] ) ) {
				$html .= $this->render_group( __( 'حال‌وهوا', 'music-wave-core' ), $grouped['mood'], $limit_per_group, 'mood' );
			}

			// Products.
			if ( $show_products && ! empty( $grouped['product'] ) ) {
				$html .= $this->render_group( __( 'محصولات', 'music-wave-core' ), $grouped['product'], $limit_per_group, 'product' );
			}

			$html .= '</div></section>';

			return $html;
		} catch ( \Throwable $e ) {
			// Never break editor preview.
			return '';
		}
	}

	/**
	 * Build a live catalog-backed preview inside the Block/Site Editor (`search.html`, etc.)
	 * pulling exclusively real site releases & taxonomy terms through `render_group()`.
	 */
	private function render_editor_preview(
		bool $show_artists,
		bool $show_releases,
		bool $show_genres,
		int $limit_per_group,
		string $filter_term = ''
	): string {
		$needle        = $this->search->sanitize_term( $filter_term );
		$needle        = function_exists( 'mb_strtolower' ) ? mb_strtolower( $needle ) : strtolower( $needle );
		$release_items = array();
		if ( $show_releases ) {
			$posts = EditorPreviewCatalog::published_catalog_tracks( max( 6, $limit_per_group ) );
			foreach ( $posts as $post ) {
				$title = (string) get_the_title( (int) $post->ID );
				$norm  = $this->search->sanitize_term( $title );
				$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $norm ) : strtolower( $norm );
				if ( '' !== $needle && false === strpos( $lower, $needle ) ) {
					continue;
				}
				$thumb           = function_exists( 'get_the_post_thumbnail_url' )
					? get_the_post_thumbnail_url( (int) $post->ID, 'thumbnail' )
					: '';
				$release_items[] = array(
					'label' => $title,
					'url'   => (string) get_permalink( (int) $post->ID ),
					'thumb' => is_string( $thumb ) ? $thumb : '',
				);
			}
		}

		$artist_items = array();
		if ( $show_artists && function_exists( 'get_terms' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'mw_artist',
					'hide_empty' => false,
					'number'     => $limit_per_group,
				)
			);
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term_obj ) {
					if ( is_object( $term_obj ) && ! empty( $term_obj->name ) ) {
						$name  = (string) $term_obj->name;
						$norm  = $this->search->sanitize_term( $name );
						$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $norm ) : strtolower( $norm );
						if ( '' !== $needle && false === strpos( $lower, $needle ) ) {
							continue;
						}
						$artist_items[] = array(
							'label' => $name,
							'url'   => '',
							'thumb' => '',
						);
					}
				}
			}
		}

		$genre_items = array();
		if ( $show_genres && function_exists( 'get_terms' ) ) {
			$genres = get_terms(
				array(
					'taxonomy'   => 'mw_genre',
					'hide_empty' => false,
					'number'     => $limit_per_group,
				)
			);
			if ( is_array( $genres ) ) {
				foreach ( $genres as $genre_obj ) {
					if ( is_object( $genre_obj ) && ! empty( $genre_obj->name ) ) {
						$gname = (string) $genre_obj->name;
						$norm  = $this->search->sanitize_term( $gname );
						$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $norm ) : strtolower( $norm );
						if ( '' !== $needle && false === strpos( $lower, $needle ) ) {
							continue;
						}
						$genre_items[] = array(
							'label' => $gname,
							'url'   => '',
							'thumb' => '',
						);
					}
				}
			}
		}

		$html  = '<section ' . BlockSupport::wrapper_attributes( 'mw-search-unified' ) . '>';
		$html .= '<div class="mw-search-unified__groups">';
		if ( ! empty( $release_items ) ) {
			$html .= $this->render_group( __( 'انتشارها', 'music-wave-core' ), $release_items, $limit_per_group, 'release' );
		}
		if ( ! empty( $artist_items ) ) {
			$html .= $this->render_group( __( 'هنرمندان', 'music-wave-core' ), $artist_items, $limit_per_group, 'artist' );
		}
		if ( ! empty( $genre_items ) ) {
			$html .= $this->render_group( __( 'سبک‌ها', 'music-wave-core' ), $genre_items, $limit_per_group, 'genre' );
		}
		$html .= '</div></section>';

		return $html;
	}

	private function current_search_term(): string {
		$preview_query = EditorPreviewCatalog::requested_sample_query();
		if ( '' !== $preview_query ) {
			return $this->search->sanitize_term( $preview_query );
		}

		$s = function_exists( 'get_search_query' ) ? get_search_query() : '';
		if ( '' !== $s ) {
			return $this->search->sanitize_term( $s );
		}
		// Fallback from $_GET.
		if ( isset( $_GET['s'] ) && is_scalar( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $this->search->sanitize_term( $raw );
		}
		return '';
	}

	/**
	 * @return array<int, array<string, string|int>>
	 */
	private function query_products( string $term, int $limit ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		try {
			$products = wc_get_products(
				array(
					'status' => 'publish',
					'limit'  => $limit,
					's'      => $term,
				)
			);
		} catch ( \Throwable $e ) {
			return array();
		}
		if ( ! is_array( $products ) ) {
			return array();
		}
		$out = array();
		foreach ( $products as $product ) {
			if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
				continue;
			}
			$id = (int) $product->get_id();
			if ( $id <= 0 ) {
				continue;
			}
			$title = method_exists( $product, 'get_name' ) ? (string) $product->get_name() : (string) get_the_title( $id );
			$url   = (string) get_permalink( $id );
			if ( '' === $title || '' === $url ) {
				continue;
			}
			$out[] = array(
				'type'  => 'product',
				'id'    => $id,
				'label' => $title,
				'url'   => $url,
			);
		}
		return $out;
	}

	/**
	 * Group releases by their release_type term (track, album, podcast, etc)
	 *
	 * @return array<string, array<int, array<string, string|int>>>
	 */
	private function group_releases_by_type( string $term, int $limit_per_group ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'        => ReleasePostType::KEY,
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'posts_per_page'   => 60,
				's'                => $term,
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return array();
		}

		$groups = array();
		foreach ( $ids as $release_id ) {
			$release_id = absint( $release_id );
			if ( $release_id <= 0 ) {
				continue;
			}
			$types = wp_get_post_terms( $release_id, 'mw_release_type', array( 'fields' => 'all' ) );
			if ( is_wp_error( $types ) || empty( $types ) ) {
				$slug = 'other';
			} else {
				$first = $types[0];
				$slug  = $first instanceof WP_Term ? (string) $first->slug : 'other';
			}
			if ( ! isset( $groups[ $slug ] ) ) {
				$groups[ $slug ] = array();
			}
			if ( count( $groups[ $slug ] ) >= $limit_per_group ) {
				continue;
			}
			$groups[ $slug ][] = array(
				'type'  => 'release',
				'id'    => $release_id,
				'label' => (string) get_the_title( $release_id ),
				'url'   => (string) get_permalink( $release_id ),
				'thumb' => (string) get_the_post_thumbnail_url( $release_id, 'thumbnail' ),
			);
		}

		// Sort groups by preferred order: track, single, album, podcast, etc.
		$order = array( 'track', 'single', 'album', 'ep', 'podcast_episode', 'podcast_show', 'mix', 'playlist' );
		uksort(
			$groups,
			static function ( $a, $b ) use ( $order ) {
				$ia = array_search( $a, $order, true );
				$ib = array_search( $b, $order, true );
				if ( false === $ia && false === $ib ) {
					return strcmp( $a, $b );
				}
				if ( false === $ia ) {
					return 1;
				}
				if ( false === $ib ) {
					return -1;
				}
				return $ia <=> $ib;
			}
		);

		return $groups;
	}

	private function release_type_label( string $slug ): string {
		$labels = array(
			'track'           => __( 'قطعه‌ها', 'music-wave-core' ),
			'single'          => __( 'تک‌آهنگ‌ها', 'music-wave-core' ),
			'ep'              => __( 'EP', 'music-wave-core' ),
			'album'           => __( 'آلبوم‌ها', 'music-wave-core' ),
			'mix'             => __( 'میکس‌ها', 'music-wave-core' ),
			'playlist'        => __( 'فهرست‌های پخش', 'music-wave-core' ),
			'podcast_show'    => __( 'پادکست‌ها', 'music-wave-core' ),
			'podcast_episode' => __( 'قسمت‌های پادکست', 'music-wave-core' ),
			'other'           => __( 'انتشارها', 'music-wave-core' ),
		);
		return $labels[ $slug ] ?? ucfirst( str_replace( '_', ' ', $slug ) );
	}

	/**
	 * @param array<int, array<string, string|int>> $items
	 */
	private function render_group( string $title, array $items, int $limit, string $type, string $subtype = '' ): string {
		if ( empty( $items ) ) {
			return '';
		}
		$items       = array_slice( $items, 0, $limit );
		$group_class = 'mw-search-unified__group mw-search-unified__group--' . esc_attr( $type );
		if ( '' !== $subtype ) {
			$group_class .= ' mw-search-unified__group--' . esc_attr( $subtype );
		}

		$html  = '<div class="' . $group_class . '">';
		$html .= '<div class="mw-search-unified__group-header"><h3 class="mw-search-unified__group-title">' . esc_html( $title ) . '</h3><span class="mw-search-unified__group-count">' . esc_html( (string) count( $items ) ) . '</span></div>';
		$html .= '<ul class="mw-search-unified__list" role="list">';

		foreach ( $items as $item ) {
			$label = isset( $item['label'] ) ? (string) $item['label'] : '';
			$url   = isset( $item['url'] ) ? (string) $item['url'] : '';
			$thumb = isset( $item['thumb'] ) ? (string) $item['thumb'] : '';
			if ( '' === $label ) {
				continue;
			}
			$icon_svg = $this->icon_svg( $type, $subtype );
			$html    .= '<li class="mw-search-unified__item mw-search-unified__item--' . esc_attr( $type ) . ( '' !== $subtype ? ' mw-search-unified__item--' . esc_attr( $subtype ) : '' ) . '">';
			if ( '' !== $url ) {
				$html .= '<a href="' . esc_url( $url ) . '" class="mw-search-unified__link">';
			} else {
				$html .= '<span class="mw-search-unified__link">';
			}

			if ( '' !== $thumb ) {
				$html .= '<span class="mw-search-unified__thumb"><img src="' . esc_url( $thumb ) . '" alt="" loading="lazy" width="48" height="48"></span>';
			} else {
				$html .= '<span class="mw-search-unified__icon" aria-hidden="true">' . $icon_svg . '</span>';
			}

			$html .= '<span class="mw-search-unified__label">' . esc_html( $label ) . '</span>';
			$html .= '<span class="mw-search-unified__type">' . esc_html( $this->type_label( $type, $subtype ) ) . '</span>';

			if ( '' !== $url ) {
				$html .= '</a>';
			} else {
				$html .= '</span>';
			}
			$html .= '</li>';
		}

		$html .= '</ul></div>';

		return $html;
	}

	/**
	 * SVG icon library — 24x24 stroke currentColor, matches theme nav-icons
	 * Clean, standard, no emoji, harmonious with project
	 *
	 * @return array<string, string>
	 */
	private function icon_paths(): array {
		return array(
			'release'         => '<path d="M9 18V5.5l11-2.5v12"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="17.5" cy="15" r="2.5"/>',
			'track'           => '<path d="M9 18V5.5l11-2.5v12"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="17.5" cy="15" r="2.5"/>',
			'single'          => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.5"/><path d="M12 9.5v5"/>',
			'album'           => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.5"/>',
			'ep'              => '<rect x="4" y="4" width="16" height="16" rx="2"/><circle cx="12" cy="12" r="3"/>',
			'podcast_show'    => '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0"/><path d="M12 17.5V21M9 21h6"/>',
			'podcast_episode' => '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0"/><circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none"/>',
			'mix'             => '<path d="M3 8a4 4 0 0 1 4-4h2a4 4 0 0 1 4 4v8a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4z"/><path d="M13 8h4a4 4 0 0 1 4 4v4a4 4 0 0 1-4 4h-4"/>',
			'playlist'        => '<path d="M4 6h11M4 12h11M4 18h6"/><path d="M19 6v10"/><circle cx="16.5" cy="17" r="2.5"/>',
			'product'         => '<path d="M6 7h12l-1 12H7L6 7z"/><path d="M9 7a3 3 0 0 1 6 0"/>',
			'artist'          => '<circle cx="12" cy="8.5" r="3.75"/><path d="M4.75 20a7.25 7.25 0 0 1 14.5 0"/>',
			'genre'           => '<path d="M3.5 12.5v-8a1 1 0 0 1 1-1h8l8 8-9 9z"/><circle cx="8" cy="8" r="1.2"/>',
			'mood'            => '<path d="M12 20.5 4 12.9A4.8 4.8 0 1 1 12 6.7a4.8 4.8 0 1 1 8 6.2Z"/>',
			'release_type'    => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.5"/>',
			'search'          => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
			'other'           => '<circle cx="12" cy="12" r="3.5"/>',
		);
	}

	private function icon_svg( string $type, string $subtype = '' ): string {
		$paths = $this->icon_paths();
		$key   = $type;
		if ( 'release' === $type && '' !== $subtype && isset( $paths[ $subtype ] ) ) {
			$key = $subtype;
		}
		$path = $paths[ $key ] ?? $paths['other'];
		return '<svg class="mw-search-unified__icon-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
	}

	private function type_label( string $type, string $subtype = '' ): string {
		if ( 'release' === $type && '' !== $subtype ) {
			return $this->release_type_label( $subtype );
		}
		$map = array(
			'release'      => __( 'انتشار', 'music-wave-core' ),
			'artist'       => __( 'هنرمند', 'music-wave-core' ),
			'genre'        => __( 'سبک', 'music-wave-core' ),
			'mood'         => __( 'حال‌وهوا', 'music-wave-core' ),
			'release_type' => __( 'نوع انتشار', 'music-wave-core' ),
			'product'      => __( 'محصول', 'music-wave-core' ),
		);
		return $map[ $type ] ?? $type;
	}
}
