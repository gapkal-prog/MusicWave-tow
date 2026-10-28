<?php
/**
 * Public playlists robots & sitemap indexing control (FIX-27).
 *
 * Uses WordPress `wp_robots` filter rather than printing a duplicate raw
 * meta-robots tag in `wp_head`.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Playlists;

use ManaCore\MusicWave\Core\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PublicPlaylistSeo {

	public function register(): void {
		add_filter( 'wp_robots', array( $this, 'filter_robots' ), 25 );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'filter_sitemap_query' ), 10, 2 );
		add_filter( 'wp_sitemaps_post_types', array( $this, 'exclude_from_sitemap' ) );
	}

	/**
	 * @param array<string, bool|string> $robots Robots directives map.
	 * @return array<string, bool|string>
	 */
	public function filter_robots( $robots ): array {
		if ( ! is_array( $robots ) || self::is_indexable() ) {
			return is_array( $robots ) ? $robots : array();
		}

		if ( $this->current_post_has_public_playlists_block() ) {
			unset( $robots['index'], $robots['follow'], $robots['max-image-preview'], $robots['max-snippet'], $robots['max-video-preview'] );
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}

		return $robots;
	}

	/**
	 * Exclude pages containing public-playlists block from sitemap when not indexable.
	 *
	 * @param array<string, mixed> $args      Query args.
	 * @param string               $post_type Post type.
	 * @return array<string, mixed>
	 */
	public function filter_sitemap_query( $args, $post_type ) {
		if ( self::is_indexable() ) {
			return $args;
		}
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return $args;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = %s AND post_content LIKE %s LIMIT 100",
				$post_type,
				'%music-wave/public-playlists%'
			)
		);
		if ( is_array( $ids ) && array() !== $ids ) {
			$existing             = isset( $args['post__not_in'] ) && is_array( $args['post__not_in'] ) ? $args['post__not_in'] : array();
			$args['post__not_in'] = array_unique( array_merge( $existing, array_map( 'absint', $ids ) ) );
		}

		return $args;
	}

	/**
	 * @param array<string, mixed> $post_types Post types map.
	 * @return array<string, mixed>
	 */
	public function exclude_from_sitemap( $post_types ) {
		return $post_types;
	}

	public static function is_indexable(): bool {
		$settings  = Settings::all();
		$indexable = isset( $settings['public_playlists_indexable'] ) && 'enabled' === $settings['public_playlists_indexable'];

		return (bool) apply_filters( 'music_wave_public_playlists_indexable', $indexable );
	}

	private function current_post_has_public_playlists_block(): bool {
		global $post;
		if ( ! $post || ! isset( $post->post_content ) ) {
			return false;
		}
		$content = (string) $post->post_content;
		return false !== strpos( $content, 'music-wave/public-playlists' )
			|| false !== strpos( $content, 'mw-public-playlists' )
			|| ( function_exists( 'has_block' ) && has_block( 'music-wave/public-playlists', $post ) );
	}
}
