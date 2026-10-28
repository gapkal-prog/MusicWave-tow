<?php
/**
 * Authorized collection-child search for the release meta box.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Admin;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use WP_REST_Request;
use WP_REST_Response;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CollectionCandidateRoutes {
	/**
	 * Register the editor-only candidate search route.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'music-wave/v1',
			'/collection-candidates',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'can_edit_collection' ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request details.
	 * @return bool
	 */
	public function can_edit_collection( WP_REST_Request $request ): bool {
		$collection_id = absint( $request->get_param( 'collection_id' ) );

		return $collection_id > 0 && ReleasePostType::KEY === get_post_type( $collection_id ) && current_user_can( 'edit_post', $collection_id );
	}

	/**
	 * Return compatible child releases that the current editor may use.
	 *
	 * @param WP_REST_Request $request Request details.
	 * @return WP_REST_Response
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$collection_id = absint( $request->get_param( 'collection_id' ) );
		$role          = sanitize_key( (string) $request->get_param( 'role' ) );
		$search        = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$types         = 'episode' === $role ? array( 'podcast_episode' ) : array( 'track', 'single' );
		$limit         = 20;
		$candidates    = array();
		$title_matches = $this->query( $collection_id, $types, $search );
		$file_matches  = '' === $search ? array() : $this->query( $collection_id, $types, '', $search );

		foreach ( $title_matches as $post ) {
			if ( $post instanceof \WP_Post ) {
				$candidates[ $post->ID ] = array(
					'post'        => $post,
					'title_match' => true,
					'file_names'  => array(),
				);
			}
		}
		foreach ( $file_matches as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$matched_file_names = $this->matching_file_names( $post->ID, $search );
			if ( empty( $matched_file_names ) ) {
				continue;
			}
			if ( ! isset( $candidates[ $post->ID ] ) ) {
				$candidates[ $post->ID ] = array(
					'post'        => $post,
					'title_match' => false,
					'file_names'  => $matched_file_names,
				);
				continue;
			}

			$candidates[ $post->ID ]['file_names'] = $matched_file_names;
		}

		$items = array();
		foreach ( $candidates as $candidate ) {
			$post = $candidate['post'];
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}

			$items[] = array(
				'id'           => $post->ID,
				'title'        => get_the_title( $post->ID ),
				'duration'     => absint( get_post_meta( $post->ID, 'mw_duration', true ) ),
				'number'       => absint( get_post_meta( $post->ID, 'episode' === $role ? 'mw_episode_number' : 'mw_track_number', true ) ),
				'file_names'   => $candidate['file_names'],
				'matched_post' => $candidate['title_match'],
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/**
	 * Query compatible release posts either by native text search or file meta.
	 *
	 * @param array<int, string> $types Compatible release types.
	 * @return array<int, \WP_Post>
	 */
	private function query( int $collection_id, array $types, string $search = '', string $file_search = '' ): array {
		$args = array(
			'post_type'           => ReleasePostType::KEY,
			'post_status'         => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page'      => 40,
			'post__not_in'        => array( $collection_id ),
			'orderby'             => 'title',
			'order'               => 'ASC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'tax_query'           => array(
				array(
					'taxonomy' => 'mw_release_type',
					'field'    => 'slug',
					'terms'    => $types,
				),
			),
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( '' !== $file_search ) {
			$args['meta_query'] = array(
				array(
					'key'     => 'mw_download_assets',
					'value'   => $file_search,
					'compare' => 'LIKE',
				),
			);
		}

		$query = new \WP_Query( $args );

		return is_array( $query->posts ) ? $query->posts : array();
	}

	/**
	 * Return matching safe file names, never provider identifiers.
	 *
	 * @return array<int, string>
	 */
	private function matching_file_names( int $release_id, string $search ): array {
		$assets = get_post_meta( $release_id, 'mw_download_assets', true );
		if ( ! is_array( $assets ) || '' === $search ) {
			return array();
		}

		$needle  = strtolower( $search );
		$matches = array();
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['file_name'] ) || ! is_scalar( $asset['file_name'] ) ) {
				continue;
			}

			$file_name = sanitize_file_name( (string) $asset['file_name'] );
			if ( '' !== $file_name && false !== strpos( strtolower( $file_name ), $needle ) ) {
				$matches[] = $file_name;
			}
		}

		return array_slice( array_values( array_unique( $matches ) ), 0, 3 );
	}
}
