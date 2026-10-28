<?php
/**
 * Shared release publication and read-visibility policy.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleaseVisibility {
	/**
	 * Whether an ID belongs to a MusicWave release.
	 */
	public function is_release( int $release_id ): bool {
		return $release_id > 0 && ReleasePostType::KEY === get_post_type( $release_id );
	}

	/**
	 * Whether a release may appear in public, cacheable projections.
	 */
	public function is_public( int $release_id ): bool {
		return $this->is_release( $release_id ) && 'publish' === get_post_status( $release_id );
	}

	/**
	 * Warm the post cache for a batch of release IDs.
	 *
	 * Every check above reads post type and status, which costs one database
	 * query per uncached post. Callers that already know their candidate set
	 * (library pages, facet scans, playlist views) call this once so the
	 * visibility loop reads a single batched query instead of N.
	 *
	 * @param array<int, mixed> $release_ids Candidate post IDs.
	 * @param bool              $with_meta   Also warm post meta, for callers
	 *                                       that render release summaries
	 *                                       (release year, thumbnail id).
	 * @return void
	 */
	public function prime( array $release_ids, bool $with_meta = false ): void {
		$ids = array();
		foreach ( $release_ids as $release_id ) {
			$release_id = absint( $release_id );
			if ( $release_id > 0 ) {
				$ids[ $release_id ] = $release_id;
			}
		}

		if ( array() === $ids ) {
			return;
		}

		// Term caches stay for the callers that prime them per taxonomy.
		_prime_post_caches( array_values( $ids ), false, $with_meta );
	}

	/**
	 * Whether the current request actor may read the release record.
	 *
	 * Published releases are publicly readable. Non-published releases require
	 * WordPress' object-level read capability so drafts/private releases are not
	 * enumerated through MusicWave library, SEO, or REST helper paths.
	 */
	public function can_read( int $release_id ): bool {
		if ( ! $this->is_release( $release_id ) ) {
			return false;
		}
		if ( $this->is_public( $release_id ) ) {
			return true;
		}

		return current_user_can( 'read_post', $release_id );
	}
}
