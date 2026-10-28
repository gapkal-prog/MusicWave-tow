<?php
/**
 * Release archive sorting policy.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Catalog;

use ManaCore\MusicWave\Core\Support\Settings;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleaseArchiveQuery {
	public const SORT_QUERY_VAR = 'mw_sort';

	/**
	 * Register public query adjustments.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'pre_get_posts', array( $this, 'apply_sorting' ) );
		add_action( 'pre_get_posts', array( $this, 'scope_archive_search' ), 20 );
		add_action( 'template_redirect', array( $this, 'redirect_release_search' ) );
	}

	/**
	 * Whether a query renders one of the plugin's public release listings.
	 *
	 * The catalog owns its own page density and sorting, so the release
	 * post-type archive and every release taxonomy archive must be scoped
	 * identically. Without this the artist, genre, mood and release-type pages
	 * would fall back to the site-wide posts-per-page and their sort control
	 * would do nothing.
	 *
	 * @param \WP_Query $query WordPress query instance.
	 */
	private function is_catalog_query( \WP_Query $query ): bool {
		return $query->is_post_type_archive( ReleasePostType::KEY )
			|| $query->is_tax( ReleaseTaxonomies::catalog_taxonomies() );
	}

	/**
	 * Keep catalog search phrases inside the release archive view.
	 *
	 * The catalog filter form submits an `s` parameter, which WordPress would
	 * otherwise turn into a global search request using the search template
	 * and the default searched post types. The phrase must stay part of the
	 * release archive query so filters, sorting, and the catalog template
	 * keep working together.
	 *
	 * @param mixed $query WordPress query instance.
	 * @return void
	 */
	public function scope_archive_search( $query ): void {
		if ( is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() ) {
			return;
		}

		if ( ! $query->is_search() || ! $this->is_catalog_query( $query ) ) {
			return;
		}

		// The `s` query var still narrows the archive results; clearing the
		// flag only keeps template hierarchy and archive context intact.
		$query->is_search = false;
	}

	/**
	 * Route release-only searches to the catalog archive.
	 *
	 * Requests such as `/?s=term&post_type=mw_release` (the filter form's
	 * plain-permalink fallback or any release-scoped search form) are
	 * redirected to the release archive with every catalog parameter kept,
	 * so visitors always land on the filterable catalog view.
	 *
	 * @return void
	 */
	public function redirect_release_search(): void {
		if ( is_admin() || ! is_search() || is_post_type_archive( ReleasePostType::KEY ) ) {
			return;
		}
		if ( ReleasePostType::KEY !== get_query_var( 'post_type' ) ) {
			return;
		}

		$archive_url = get_post_type_archive_link( ReleasePostType::KEY );
		if ( ! is_string( $archive_url ) || '' === $archive_url ) {
			return;
		}

		$args = array();
		foreach ( ReleaseTaxonomies::catalog_taxonomies() as $taxonomy ) {
			$value = get_query_var( $taxonomy );
			if ( is_string( $value ) && '' !== $value ) {
				$args[ $taxonomy ] = sanitize_title( $value );
			}
		}

		$search = get_query_var( 's' );
		if ( is_string( $search ) && '' !== $search ) {
			$args['s'] = sanitize_text_field( $search );
		}

		$sort = get_query_var( self::SORT_QUERY_VAR );
		$sort = is_scalar( $sort ) ? self::normalize_sort( $sort ) : 'latest';
		if ( 'latest' !== $sort ) {
			$args[ self::SORT_QUERY_VAR ] = $sort;
		}

		$paged = absint( get_query_var( 'paged' ) );
		if ( $paged > 1 ) {
			$args['paged'] = $paged;
		}

		wp_safe_redirect( add_query_arg( rawurlencode_deep( $args ), $archive_url ) );
		exit;
	}

	/**
	 * Apply an allow-listed sort and the catalog page size to every public
	 * release listing, the release archive and each release taxonomy archive
	 * alike.
	 *
	 * @param mixed $query WordPress query instance.
	 * @return void
	 */
	public function apply_sorting( $query ): void {
		if ( is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() ) {
			return;
		}

		if ( ! $this->is_catalog_query( $query ) ) {
			return;
		}

		// Public archive query string; the value is sanitized and allow-listed by normalize_sort().
		$sort = isset( $_GET[ self::SORT_QUERY_VAR ] ) && is_scalar( $_GET[ self::SORT_QUERY_VAR ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? self::normalize_sort( sanitize_key( wp_unslash( (string) $_GET[ self::SORT_QUERY_VAR ] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: self::normalize_sort( Settings::get( 'archive_default_sort' ) );

		foreach ( self::sort_arguments( $sort ) as $key => $value ) {
			$query->set( $key, $value );
		}
		$query->set( 'posts_per_page', (int) Settings::get( 'archive_per_page' ) );
	}

	/**
	 * Return the public, translated sort options.
	 *
	 * @return array<string, string>
	 */
	public static function sort_options(): array {
		return array(
			'latest'     => __( 'جدیدترین اول', 'music-wave-core' ),
			'oldest'     => __( 'قدیمی‌ترین اول', 'music-wave-core' ),
			'title_asc'  => __( 'عنوان: A تا Z', 'music-wave-core' ),
			'title_desc' => __( 'عنوان: Z تا A', 'music-wave-core' ),
		);
	}

	/**
	 * Normalize an untrusted sort value to the default.
	 *
	 * @param mixed $sort Candidate sort value.
	 * @return string
	 */
	public static function normalize_sort( $sort ): string {
		$sort = is_scalar( $sort ) ? sanitize_key( strtolower( (string) $sort ) ) : '';

		return array_key_exists( $sort, self::sort_options() ) ? $sort : 'latest';
	}

	/**
	 * Return WordPress-native arguments for a normalized sorting choice.
	 *
	 * @return array<string, string>
	 */
	public static function sort_arguments( string $sort ): array {
		switch ( self::normalize_sort( $sort ) ) {
			case 'oldest':
				return array(
					'orderby' => 'date',
					'order'   => 'ASC',
				);
			case 'title_asc':
				return array(
					'orderby' => 'title',
					'order'   => 'ASC',
				);
			case 'title_desc':
				return array(
					'orderby' => 'title',
					'order'   => 'DESC',
				);
			case 'latest':
			default:
				return array(
					'orderby' => 'date',
					'order'   => 'DESC',
				);
		}
	}
}
