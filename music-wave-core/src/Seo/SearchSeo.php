<?php
/**
 * Unified robots directives and canonical link resolution across non-singular routes.
 *
 * Consolidates all `robots` directives through WordPress Core `wp_robots` filter
 * so `<meta name="robots">` is never emitted twice in `<head>`.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Seo;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SearchSeo {

	private const CATALOG_TAXONOMIES = array(
		'mw_artist',
		'mw_genre',
		'mw_mood',
		'mw_label',
		'mw_release_type',
	);

	public function register(): void {
		add_filter( 'wp_robots', array( $this, 'filter_robots' ), 20 );
		add_action( 'wp_head', array( $this, 'render_canonical' ), 9 );
	}

	/**
	 * Normalize robot directives for search engines, Google Discover, Bing, and AI search crawlers.
	 *
	 * @param array<string, bool|string> $robots Robots directives map.
	 * @return array<string, bool|string>
	 */
	public function filter_robots( $robots ): array {
		if ( ! is_array( $robots ) ) {
			return array();
		}

		if (
			( function_exists( 'is_search' ) && is_search() )
			|| ( function_exists( 'is_404' ) && is_404() )
		) {
			unset( $robots['index'] );
			$robots['noindex'] = true;
			$robots['follow']  = true;
			return $robots;
		}

		if ( empty( $robots['noindex'] ) ) {
			$robots['max-image-preview'] = 'large';
			$robots['max-snippet']       = '-1';
			$robots['max-video-preview'] = '-1';
		}

		return $robots;
	}

	/**
	 * Render canonical tag for indexable non-singular routes where WordPress Core
	 * `rel_canonical` outputs nothing (front page, release archive, taxonomy archives).
	 */
	public function render_canonical(): void {
		if ( SeoCompatibility::has_competing_plugin() ) {
			return;
		}
		if ( function_exists( 'is_singular' ) && is_singular() ) {
			return;
		}
		if (
			( function_exists( 'is_search' ) && is_search() )
			|| ( function_exists( 'is_404' ) && is_404() )
		) {
			return;
		}

		$canonical = $this->canonical_url();
		if ( '' === $canonical ) {
			return;
		}

		echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
	}

	/**
	 * Resolve normalized canonical URL for front page, release archive, and catalog taxonomies.
	 */
	public function canonical_url(): string {
		$paged = function_exists( 'get_query_var' ) ? max( 1, (int) get_query_var( 'paged' ) ) : 1;

		if ( function_exists( 'is_front_page' ) && is_front_page() ) {
			return $this->apply_pagination( home_url( '/' ), $paged );
		}

		if ( function_exists( 'is_home' ) && is_home() ) {
			$posts_page_id = (int) get_option( 'page_for_posts', 0 );
			$base          = $posts_page_id > 0 ? get_permalink( $posts_page_id ) : home_url( '/' );
			return is_string( $base ) && '' !== $base ? $this->apply_pagination( $base, $paged ) : '';
		}

		if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( ReleasePostType::KEY ) ) {
			$archive_url = get_post_type_archive_link( ReleasePostType::KEY );
			if ( ! is_string( $archive_url ) || '' === $archive_url ) {
				return '';
			}
			$args = array();
			foreach ( self::CATALOG_TAXONOMIES as $taxonomy ) {
				$value = function_exists( 'get_query_var' ) ? get_query_var( $taxonomy ) : '';
				if ( is_string( $value ) && '' !== $value ) {
					$args[ $taxonomy ] = sanitize_title( $value );
				}
			}
			$base = empty( $args ) ? $archive_url : add_query_arg( $args, $archive_url );
			return $this->apply_pagination( (string) $base, $paged );
		}

		if ( function_exists( 'is_tax' ) && is_tax() && function_exists( 'get_queried_object' ) ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$term_link = get_term_link( $term );
				if ( is_string( $term_link ) && '' !== $term_link ) {
					return $this->apply_pagination( $term_link, $paged );
				}
			}
		}

		return '';
	}

	private function apply_pagination( string $url, int $paged ): string {
		if ( $paged <= 1 ) {
			return $url;
		}
		if ( function_exists( 'get_pagenum_link' ) && '' !== (string) get_option( 'permalink_structure', '' ) ) {
			$parts = wp_parse_url( $url );
			$base  = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . trailingslashit( (string) ( $parts['path'] ?? '/' ) ) . user_trailingslashit( 'page/' . $paged, 'paged' );
			return isset( $parts['query'] ) && '' !== $parts['query'] ? $base . '?' . $parts['query'] : $base;
		}

		return add_query_arg( array( 'paged' => $paged ), $url );
	}
}
