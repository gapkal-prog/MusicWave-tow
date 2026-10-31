<?php
/**
 * Canonical track metadata shared by every playback trigger.
 *
 * The persistent global player reads its now-playing card (title, artist,
 * artwork, permalink) from `data-*` attributes on the trigger that started
 * playback. Rendering those attributes in one place keeps preview clips,
 * secure full-track buttons, download rows, and account-library rows from
 * drifting apart — a missing attribute is a blank cover in the player.
 *
 * SOLID: SRP — metadata resolution only, no markup decisions.
 * DRY: one artist/artwork/title lookup reused by every renderer.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks\Concerns;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TrackMetadata {
	/** Artist taxonomy backing every release and track post. */
	public const ARTIST_TAXONOMY = 'mw_artist';

	/**
	 * Artist terms attached to a track or release.
	 *
	 * @return array<int, \WP_Term>
	 */
	public static function artist_terms( int $post_id ): array {
		if ( $post_id < 1 || ! function_exists( 'wp_get_post_terms' ) ) {
			return array();
		}

		$terms = wp_get_post_terms( $post_id, self::ARTIST_TAXONOMY );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_values( array_filter( $terms, static fn( $term ): bool => $term instanceof \WP_Term ) );
	}

	/**
	 * Comma separated artist names for a track or release.
	 */
	public static function artist_names( int $post_id ): string {
		$names = array_map(
			static fn( \WP_Term $term ): string => trim( $term->name ),
			self::artist_terms( $post_id )
		);

		return implode( ', ', array_filter( $names ) );
	}

	/**
	 * Artwork URL for a track, falling back to the owning release cover.
	 *
	 * Child tracks frequently carry no featured image of their own: the album
	 * artwork lives on the parent release. Without that fallback the player
	 * shows its empty art fallback even though the release page displays a
	 * cover.
	 *
	 * @param int    $post_id  Track or release post ID.
	 * @param int    $cover_id Release that owns the track (`0` for a release itself).
	 * @param string $size     Registered image size.
	 */
	public static function artwork_url( int $post_id, int $cover_id = 0, string $size = 'thumbnail' ): string {
		if ( ! function_exists( 'get_the_post_thumbnail_url' ) ) {
			return '';
		}

		$candidates = array( $post_id, $cover_id );
		foreach ( $candidates as $candidate ) {
			if ( $candidate < 1 ) {
				continue;
			}
			$url = get_the_post_thumbnail_url( $candidate, $size );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Build the `data-*` metadata attributes consumed by `preview-player.js`.
	 *
	 * @param int    $track_id Track or release post ID.
	 * @param int    $cover_id Release cover fallback for artwork-less tracks.
	 * @param string $prefix   Attribute family: `data-preview` or `data-mw-track`.
	 */
	public static function attributes( int $track_id, int $cover_id = 0, string $prefix = 'data-preview' ): string {
		if ( $track_id < 1 ) {
			return '';
		}

		$prefix = 'data-mw-track' === $prefix ? 'data-mw-track' : 'data-preview';
		$title  = function_exists( 'get_the_title' ) ? trim( (string) get_the_title( $track_id ) ) : '';
		$link   = function_exists( 'get_permalink' ) ? get_permalink( $track_id ) : '';

		return ' ' . $prefix . '-title="' . esc_attr( $title ) . '"'
			. ' ' . $prefix . '-artist="' . esc_attr( self::artist_names( $track_id ) ) . '"'
			. ' ' . $prefix . '-image="' . esc_url( self::artwork_url( $track_id, $cover_id ) ) . '"'
			. ' ' . $prefix . '-link="' . esc_url( is_string( $link ) ? $link : '' ) . '"';
	}
}
