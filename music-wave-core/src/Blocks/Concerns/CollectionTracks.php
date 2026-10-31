<?php
/**
 * Ordered track entries and playable-track counts for collection releases.
 *
 * One resolver serves the tracklist renderer, the smart play trigger, and the
 * shuffle trigger so all three agree on what "this collection's tracklist"
 * means (stored collection order first, legacy child posts as fallback).
 *
 * SOLID: SRP — collection membership and playability counting only.
 * DRY: the previous private renderer copy now has a single home.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks\Concerns;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use ManaCore\MusicWave\Core\Playback\TrackStreamPresenter;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CollectionTracks {
	/** @var ReleaseRepository */
	private $releases;

	public function __construct( ReleaseRepository $releases ) {
		$this->releases = $releases;
	}

	/**
	 * Resolve ordered track entries for an album/EP/collection release.
	 *
	 * 1. Primary source: `mw_collection_items` via `collection_items()`
	 *    (preserving the exact `position` and `disc_number` from the metabox).
	 * 2. Legacy fallback: child `mw_release` posts using `post_parent`.
	 *
	 * @param int  $parent_id         Parent collection release ID.
	 * @param bool $allow_unpublished Whether editor drafts may appear in previews.
	 * @return array<int, array{post: WP_Post, position: int, disc_number: int}>
	 */
	public function entries( int $parent_id, bool $allow_unpublished = false ): array {
		if ( $parent_id < 1 ) {
			return array();
		}

		$stored  = $this->releases->collection_items( $parent_id );
		$entries = array();
		$seen    = array();

		if ( ! empty( $stored ) ) {
			foreach ( $stored as $item ) {
				$track_id = isset( $item['release_id'] )
					? absint( $item['release_id'] )
					: ( isset( $item['track_id'] ) ? absint( $item['track_id'] ) : 0 );
				if ( $track_id <= 0 || isset( $seen[ $track_id ] ) || ! ReleaseResolver::is_release( $track_id, $allow_unpublished ) ) {
					continue;
				}
				$post = get_post( $track_id );
				if ( ! ( $post instanceof WP_Post ) ) {
					continue;
				}
				$seen[ $track_id ] = true;
				$pos               = isset( $item['position'] ) ? absint( $item['position'] ) : 0;
				if ( $pos <= 0 ) {
					$pos = absint( $this->releases->get( $track_id, 'mw_track_number' ) );
				}
				$disc = isset( $item['disc'] )
					? absint( $item['disc'] )
					: ( isset( $item['disc_number'] ) ? absint( $item['disc_number'] ) : 0 );
				if ( $disc <= 0 ) {
					$disc = absint( get_post_meta( $track_id, 'mw_disc_number', true ) );
				}
				$entries[] = array(
					'post'        => $post,
					'position'    => $pos,
					'disc_number' => $disc > 0 ? $disc : 1,
				);
			}
			if ( ! empty( $entries ) ) {
				return $entries;
			}
		}

		$posts = get_posts(
			array(
				'post_type'      => ReleasePostType::KEY,
				'post_parent'    => $parent_id,
				'post_status'    => $allow_unpublished ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : 'publish',
				'posts_per_page' => 100,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'no_found_rows'  => true,
			)
		);
		if ( ! is_array( $posts ) ) {
			return array();
		}

		foreach ( $posts as $post ) {
			if ( ! ( $post instanceof WP_Post ) ) {
				continue;
			}
			$disc      = absint( get_post_meta( $post->ID, 'mw_disc_number', true ) );
			$entries[] = array(
				'post'        => $post,
				'position'    => absint( $this->releases->get( $post->ID, 'mw_track_number' ) ),
				'disc_number' => $disc > 0 ? $disc : 1,
			);
		}

		usort(
			$entries,
			static function ( array $left, array $right ): int {
				if ( $left['disc_number'] !== $right['disc_number'] ) {
					return $left['disc_number'] <=> $right['disc_number'];
				}
				if ( $left['position'] !== $right['position'] ) {
					return $left['position'] <=> $right['position'];
				}
				return $left['post']->ID <=> $right['post']->ID;
			}
		);

		return $entries;
	}

	/**
	 * Count tracks a visitor can actually start playing (preview clip or an
	 * entitled streamable file).
	 *
	 * @param int  $parent_id         Parent collection release ID.
	 * @param bool $allow_unpublished Whether editor drafts may appear in previews.
	 * @param bool $access_allowed    Whether the visitor may stream full files.
	 */
	public function playable_count( int $parent_id, bool $allow_unpublished = false, bool $access_allowed = false ): int {
		$playable = 0;
		foreach ( $this->entries( $parent_id, $allow_unpublished ) as $entry ) {
			$track_id = (int) $entry['post']->ID;
			if ( $track_id < 1 ) {
				continue;
			}
			if ( '' !== TrackStreamPresenter::preview_url( $track_id, $this->releases ) ) {
				++$playable;
				continue;
			}
			if ( $access_allowed && '' !== TrackStreamPresenter::streamable_quality( $track_id, $this->releases ) ) {
				++$playable;
			}
		}

		return $playable;
	}
}
