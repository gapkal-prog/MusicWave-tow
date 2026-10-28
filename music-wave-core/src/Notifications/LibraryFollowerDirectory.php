<?php
/**
 * Library-backed notification audience.
 *
 * A listener follows a release when they follow one of its artists, and — for
 * podcast episodes and other collection children — when they saved the parent
 * show or album (series follow). Nothing here decides whether a message is
 * sent: the notifier still applies consent, channel preferences, and caps
 * (PROJECT_PLAN.md Stage 6 deliverables 2 and 3).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Notifications;

use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use ManaCore\MusicWave\Core\Library\LibraryRepository;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LibraryFollowerDirectory implements FollowerDirectory {
	/** @var LibraryRepository */
	private $library;

	/** @var ReleaseRepository|null */
	private $releases;

	public function __construct( LibraryRepository $library, ?ReleaseRepository $releases = null ) {
		$this->library  = $library;
		$this->releases = $releases;
	}

	/**
	 * @return array<int, int>
	 */
	public function followers( int $release_id ): array {
		if ( $release_id < 1 ) {
			return array();
		}

		$followers = array();
		foreach ( $this->artist_terms( $release_id ) as $term_id ) {
			foreach ( $this->library->users_with( LibraryRepository::TYPE_ARTIST, $term_id ) as $user_id ) {
				$followers[ (int) $user_id ] = (int) $user_id;
			}
		}
		foreach ( $this->series_ids( $release_id ) as $series_id ) {
			foreach ( $this->library->users_with( LibraryRepository::TYPE_RELEASE, $series_id ) as $user_id ) {
				$followers[ (int) $user_id ] = (int) $user_id;
			}
		}

		return array_values( $followers );
	}

	/**
	 * Artist term IDs attached to one release.
	 *
	 * @return array<int, int>
	 */
	private function artist_terms( int $release_id ): array {
		$terms = wp_get_post_terms( $release_id, 'mw_artist', array( 'fields' => 'ids' ) );
		$ids   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$term_id = is_object( $term ) && isset( $term->term_id ) ? absint( $term->term_id ) : absint( $term );
			if ( $term_id > 0 ) {
				$ids[] = $term_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Parent collections (podcast show, album) that own this release.
	 *
	 * @return array<int, int>
	 */
	private function series_ids( int $release_id ): array {
		if ( null === $this->releases || ! method_exists( $this->releases, 'collection_ids' ) ) {
			return array();
		}

		$ids = array();
		foreach ( (array) $this->releases->collection_ids( $release_id ) as $collection_id ) {
			$collection_id = absint( $collection_id );
			if ( $collection_id > 0 ) {
				$ids[] = $collection_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
