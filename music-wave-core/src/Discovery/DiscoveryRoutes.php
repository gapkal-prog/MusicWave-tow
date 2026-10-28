<?php
/**
 * Public discovery REST surface: explainable recommendations, catalog
 * autocomplete, and facet counts.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Discovery;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DiscoveryRoutes {
	/** @var Recommendations */
	private $recommendations;

	/** @var CatalogSearch */
	private $search;

	/** @var DiscoveryRateLimiter */
	private $rate_limiter;

	public function __construct( Recommendations $recommendations, ?CatalogSearch $search = null, ?DiscoveryRateLimiter $rate_limiter = null ) {
		$this->recommendations = $recommendations;
		$this->search          = null !== $search ? $search : new CatalogSearch();
		$this->rate_limiter    = null !== $rate_limiter ? $rate_limiter : new DiscoveryRateLimiter();
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'music-wave/v1',
			'/recommendations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				// Anonymous visitors receive the editorial list only.
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/catalog/suggest',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'suggest' ),
				// Public catalog data only; rate limited per actor.
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/catalog/facets',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'facets' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Bounded catalog autocomplete.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function suggest( WP_REST_Request $request ) {
		if ( ! $this->rate_limiter->allow( 'suggest' ) ) {
			return $this->throttled();
		}

		$term  = (string) $request->get_param( 'term' );
		$limit = absint( $request->get_param( 'per_page' ) );
		$clean = $this->search->sanitize_term( $term );
		if ( '' === $clean ) {
			return new WP_Error(
				'mw_search_term_too_short',
				sprintf(
					/* translators: %d: minimum number of characters. */
					__( 'برای جست‌وجو در کاتالوگ حداقل نویسه‌های %d را وارد کنید.', 'music-wave-core' ),
					CatalogSearch::MIN_TERM_LENGTH
				),
				array( 'status' => 400 )
			);
		}

		$response = new WP_REST_Response(
			array(
				'term'  => $clean,
				'items' => $this->search->suggest( $clean, $limit > 0 ? $limit : 8 ),
			),
			200
		);
		// Public catalog data: safe to cache for shared caches as well.
		$response->header( 'Cache-Control', 'public, max-age=300' );

		return $response;
	}

	/**
	 * Facet counts for the active catalog filters.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function facets( WP_REST_Request $request ) {
		if ( ! $this->rate_limiter->allow( 'facets' ) ) {
			return $this->throttled();
		}

		$filters = array();
		foreach ( $this->search->taxonomies() as $taxonomy ) {
			$value = $request->get_param( $taxonomy );
			if ( null !== $value ) {
				$filters[ $taxonomy ] = $value;
			}
		}

		$per_taxonomy = absint( $request->get_param( 'per_taxonomy' ) );
		$per_taxonomy = $per_taxonomy > 0 ? $per_taxonomy : CatalogSearch::MAX_FACET_TERMS;

		$response = new WP_REST_Response( $this->search->facets( $filters, $per_taxonomy ), 200 );
		$response->header( 'Cache-Control', 'public, max-age=300' );

		return $response;
	}

	private function throttled(): WP_Error {
		return new WP_Error( 'mw_discovery_throttled', __( 'جست‌وجوهای کاتالوگ بسیار زیاد است. یک لحظه دیگر دوباره امتحان کنید.', 'music-wave-core' ), array( 'status' => 429 ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function index( WP_REST_Request $request ) {
		if ( ! $this->rate_limiter->allow( 'recommendations' ) ) {
			return $this->throttled();
		}

		$limit = absint( $request->get_param( 'per_page' ) );
		$items = array();
		foreach ( $this->recommendations->recommend( get_current_user_id(), $limit > 0 ? $limit : 8 ) as $item ) {
			$release_id = isset( $item['release_id'] ) ? absint( $item['release_id'] ) : 0;
			if ( $release_id < 1 ) {
				continue;
			}
			$items[] = array(
				'release_id'  => $release_id,
				'title'       => get_the_title( $release_id ),
				'url'         => (string) get_permalink( $release_id ),
				'reason'      => isset( $item['reason'] ) ? (string) $item['reason'] : '',
				'explanation' => isset( $item['explanation'] ) ? (string) $item['explanation'] : '',
			);
		}

		$response = new WP_REST_Response( array( 'items' => $items ), 200 );
		$response->header( 'Cache-Control', get_current_user_id() > 0 ? 'no-store, private' : 'public, max-age=300' );

		return $response;
	}
}
