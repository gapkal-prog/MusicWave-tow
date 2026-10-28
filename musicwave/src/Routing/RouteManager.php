<?php
declare(strict_types=1);

namespace ManaCore\MusicWave\Theme\Routing;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves logical theme routes across pages, templates, WooCommerce endpoints,
 * and Core archives, and provisions missing header pages idempotently.
 */
final class RouteManager {

	/** Option holding the provisioned routes version. */
	private const PROVISION_OPTION = 'musicwave_routes_version';

	/**
	 * Bumped whenever a bundled route gains a provisionable page, so existing
	 * sites pick the new page up on the next admin request instead of waiting
	 * for a theme switch.
	 */
	private const PROVISION_VERSION = '2';

	/**
	 * Cached resolved WP_Post instances keyed by route slug.
	 *
	 * @var array<string, WP_Post|null>
	 */
	private array $page_cache = array();

	/**
	 * Register route rewriting and provisioning hooks.
	 */
	public function register(): void {
		add_filter( 'render_block_data', array( $this, 'resolve_block_routes' ), 10, 1 );
		add_action( 'after_switch_theme', array( $this, 'maybe_provision_pages' ) );
		add_action( 'admin_init', array( $this, 'maybe_provision_missing_pages' ) );
	}

	/**
	 * Clear in-memory page cache after provisioning or manual route remapping.
	 */
	public function reset_cache(): void {
		$this->page_cache = array();
	}

	/**
	 * Logical header and rail route definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function routes(): array {
		$routes = array(
			'home'      => array(
				'label' => __( 'خانه', 'musicwave' ),
				'icon'  => 'home',
				'path'  => '/',
			),
			'browse'    => array(
				'label'    => __( 'مرور', 'musicwave' ),
				'icon'     => 'browse',
				'path'     => '/browse/',
				'aliases'  => array( '/discover/', '/explore/', '/search-catalog/' ),
				'slug'     => 'browse',
				'template' => 'page-browse',
			),
			'shop'      => array(
				'label'    => __( 'فروشگاه', 'musicwave' ),
				'icon'     => 'charts',
				'path'     => '/shop/',
				'slug'     => 'shop',
				'template' => 'page-shop',
				'wc'       => 'shop',
			),
			'playlists' => array(
				'label'    => __( 'فهرست‌های پخش', 'musicwave' ),
				'icon'     => 'playlists',
				'path'     => '/playlists/',
				'slug'     => 'playlists',
				'template' => 'page-playlists',
			),
			'requests'  => array(
				'label'    => __( 'درخواست‌ها', 'musicwave' ),
				'icon'     => 'radio',
				'path'     => '/requests/',
				'slug'     => 'requests',
				'template' => 'page-requests',
			),
			'account'   => array(
				'label'    => __( 'حساب کاربری', 'musicwave' ),
				'icon'     => 'account',
				'path'     => '/account/',
				'aliases'  => array(
					'/account/favorites/',
					'/account/downloads/',
					'/account/history/',
					'/account/settings/',
					'/account/notifications/',
				),
				'slug'     => 'account',
				'template' => 'page-account',
				'wc'       => 'myaccount',
			),
			'vip'       => array(
				'label'    => __( 'ارتقا به VIP', 'musicwave' ),
				'icon'     => 'vip',
				'path'     => '/vip/',
				'slug'     => 'vip',
				'fallback' => 'account',
			),
			'login'     => array(
				'label'    => __( 'ورود و ثبت‌نام', 'musicwave' ),
				'icon'     => 'account',
				'path'     => '/login/',
				'slug'     => 'login',
				'template' => 'page-login',
			),
			'tracks'    => array(
				'label'   => __( 'قطعه‌ها', 'musicwave' ),
				'icon'    => 'tracks',
				'path'    => '/music/',
				'aliases' => array( '/tracks/', '/releases/' ),
			),
			'albums'    => array(
				'label'   => __( 'آلبوم‌ها', 'musicwave' ),
				'icon'    => 'albums',
				'path'    => '/release-type/album/',
				'aliases' => array( '/albums/' ),
			),
			'artists'   => array(
				'label'    => __( 'هنرمندان', 'musicwave' ),
				'icon'     => 'artists',
				'path'     => '/artists/',
				'aliases'  => array( '/artist/' ),
				'slug'     => 'artists',
				'template' => 'page-artists',
			),
			'genres'    => array(
				'label'    => __( 'سبک‌ها', 'musicwave' ),
				'icon'     => 'genres',
				'path'     => '/genres/',
				'aliases'  => array( '/genre/', '/mood/' ),
				'slug'     => 'genres',
				'template' => 'page-genres',
			),
			'podcasts'  => array(
				'label'   => __( 'پادکست‌ها', 'musicwave' ),
				'icon'    => 'podcasts',
				'path'    => '/release-type/podcast_show/',
				'aliases' => array( '/podcasts/' ),
			),
		);

		$filtered = apply_filters( 'musicwave_routes', $routes );

		return is_array( $filtered ) ? $filtered : $routes;
	}

	/**
	 * Resolve the published WP_Post backing a route, if any.
	 */
	public function route_page( string $route ): ?WP_Post {
		if ( array_key_exists( $route, $this->page_cache ) ) {
			return $this->page_cache[ $route ];
		}

		$this->page_cache[ $route ] = null;
		$routes                     = $this->routes();
		$definition                 = $routes[ $route ] ?? null;
		if ( ! is_array( $definition ) ) {
			return null;
		}

		$override = (int) get_theme_mod( 'musicwave_route_page_' . $route, 0 );
		if ( $override > 0 ) {
			$page = get_post( $override );
			if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
				$this->page_cache[ $route ] = $page;
				return $page;
			}
		}

		$slug = isset( $definition['slug'] ) ? (string) $definition['slug'] : '';
		if ( '' === $slug ) {
			return null;
		}

		$by_slug = get_page_by_path( $slug );
		if ( $by_slug instanceof WP_Post && 'publish' === $by_slug->post_status ) {
			$this->page_cache[ $route ] = $by_slug;
			return $by_slug;
		}

		$template = isset( $definition['template'] ) ? (string) $definition['template'] : '';
		if ( '' === $template ) {
			return null;
		}

		$by_template = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'all',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_key'               => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'             => $template, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'                => 'ID',
				'order'                  => 'ASC',
			)
		);
		if ( isset( $by_template[0] ) && $by_template[0] instanceof WP_Post ) {
			$this->page_cache[ $route ] = $by_template[0];
		}

		return $this->page_cache[ $route ];
	}

	/**
	 * Resolve a logical route slug to its live public URL.
	 */
	public function route_url( string $route ): string {
		$routes     = $this->routes();
		$definition = $routes[ $route ] ?? null;
		if ( ! is_array( $definition ) ) {
			return home_url( '/' );
		}

		$page = $this->route_page( $route );
		$url  = $page instanceof WP_Post ? (string) get_permalink( $page ) : '';

		if ( '' === $url && ! empty( $definition['wc'] ) && function_exists( 'wc_get_page_id' ) ) {
			$wc_id = (int) wc_get_page_id( (string) $definition['wc'] );
			if ( $wc_id > 0 ) {
				$permalink = get_permalink( $wc_id );
				if ( is_string( $permalink ) && '' !== $permalink ) {
					$url = $permalink;
				}
			}
		}

		if ( '' === $url && isset( $definition['fallback'] ) && isset( $routes[ (string) $definition['fallback'] ] ) ) {
			return $this->route_url( (string) $definition['fallback'] );
		}

		if ( '' === $url ) {
			$path = isset( $definition['path'] ) ? (string) $definition['path'] : '/';
			$url  = home_url( '/' === $path ? '' : $path );
		}

		return (string) apply_filters( 'musicwave_route_url', $url, $route, $page );
	}

	/**
	 * Map a candidate URL back to its logical route slug when applicable.
	 */
	public function route_from_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return '';
		}

		$normalized = '/' . trim( $path, '/' ) . '/';
		$routes     = $this->routes();

		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' !== $query ) {
			$args = array();
			parse_str( $query, $args );
			if ( isset( $args['post_type'] ) && 'mw_release' === (string) $args['post_type'] ) {
				return isset( $routes['tracks'] ) ? 'tracks' : '';
			}
		}

		foreach ( $routes as $route => $definition ) {
			$candidates = array();
			if ( isset( $definition['path'] ) ) {
				$candidates[] = (string) $definition['path'];
			}
			if ( isset( $definition['aliases'] ) && is_array( $definition['aliases'] ) ) {
				foreach ( $definition['aliases'] as $alias ) {
					$candidates[] = (string) $alias;
				}
			}
			foreach ( $candidates as $candidate ) {
				if ( '/' . trim( $candidate, '/' ) . '/' === $normalized ) {
					return (string) $route;
				}
			}
		}

		return '';
	}

	/**
	 * Upgrade authored link URLs inside navigation links and buttons to live route permalinks.
	 *
	 * @param mixed $parsed_block Parsed block array.
	 * @return mixed
	 */
	public function resolve_block_routes( $parsed_block ) {
		if ( ! is_array( $parsed_block ) ) {
			return $parsed_block;
		}

		if ( ! in_array( (string) ( $parsed_block['blockName'] ?? '' ), array( 'core/navigation-link', 'core/button' ), true ) ) {
			return $parsed_block;
		}

		$url = $parsed_block['attrs']['url'] ?? '';
		if ( ! is_string( $url ) ) {
			return $parsed_block;
		}

		$route = $this->route_from_url( $url );
		if ( '' === $route ) {
			return $parsed_block;
		}

		$parsed_block['attrs']['url'] = $this->route_url( $route );

		return $parsed_block;
	}

	/**
	 * Subset of logical routes backed by provisionable WordPress pages.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function provisionable_routes(): array {
		return array_filter(
			$this->routes(),
			static function ( array $definition ): bool {
				return ! empty( $definition['template'] ) && ! empty( $definition['slug'] );
			}
		);
	}

	/**
	 * Idempotently create missing header route pages and assign their templates.
	 *
	 * @return array<int, string>
	 */
	public function provision_pages(): array {
		$created = array();

		foreach ( $this->provisionable_routes() as $route => $definition ) {
			$slug = (string) $definition['slug'];

			if ( 'shop' === $route && function_exists( 'wc_get_page_id' ) && (int) wc_get_page_id( 'shop' ) > 0 ) {
				continue;
			}
			if ( null !== get_page_by_path( $slug ) ) {
				continue;
			}

			$page_id = wp_insert_post(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'post_title'     => (string) $definition['label'],
					'post_name'      => $slug,
					'post_excerpt'   => (string) $definition['label'],
					'post_content'   => '',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				),
				true
			);

			if ( is_wp_error( $page_id ) || $page_id <= 0 ) {
				continue;
			}

			update_post_meta( $page_id, '_wp_page_template', (string) $definition['template'] );
			$created[] = $slug;
		}

		if ( array() !== $created ) {
			$this->reset_cache();
			flush_rewrite_rules( false );
		}

		return $created;
	}

	/**
	 * Provision missing route pages upon theme activation.
	 */
	public function maybe_provision_pages(): void {
		if ( ! current_user_can( 'edit_theme_options' ) || wp_installing() ) {
			return;
		}

		$this->provision_pages();
		update_option( self::PROVISION_OPTION, self::PROVISION_VERSION, false );
	}

	/**
	 * Provision route pages added by a theme upgrade.
	 *
	 * Runs once per `PROVISION_VERSION`, capability-gated and without any form
	 * or nonce: provisioning is idempotent (existing slugs are skipped), so the
	 * routine only ever creates the pages a newer build introduced.
	 */
	public function maybe_provision_missing_pages(): void {
		if ( wp_installing() || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		if ( self::PROVISION_VERSION === (string) get_option( self::PROVISION_OPTION, '' ) ) {
			return;
		}

		$this->provision_pages();
		update_option( self::PROVISION_OPTION, self::PROVISION_VERSION, false );
	}
}
