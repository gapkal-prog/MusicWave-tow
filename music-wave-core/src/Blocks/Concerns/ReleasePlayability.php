<?php
/**
 * Determines whether a release can produce playable audio for the current visitor,
 * including multi-track albums, EPs, mixes, podcast shows, and collections.
 *
 * SOLID: SRP – only playability, no rendering.
 * DRY: single preview URL check & streamable format check via TrackStreamPresenter.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks\Concerns;

use ManaCore\MusicWave\Core\Access\AccessPolicyEngine;
use ManaCore\MusicWave\Core\Access\AccessSubject;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use ManaCore\MusicWave\Core\Playback\TrackStreamPresenter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleasePlayability {

	/** @var ReleaseRepository */
	private $releases;

	/** @var AccessPolicyEngine|null */
	private $policy;

	public function __construct( ReleaseRepository $releases, ?AccessPolicyEngine $policy = null ) {
		$this->releases = $releases;
		$this->policy   = $policy;
	}

	/**
	 * Whether a release exposes a usable public preview URL.
	 */
	public function has_preview( int $release_id ): bool {
		$url = $this->releases->get( $release_id, 'mw_preview_url' );
		return TrackStreamPresenter::is_valid_preview_url( $url );
	}

	/**
	 * Whether a release (or any nested collection child up to depth 3) can produce
	 * playable audio for the current visitor.
	 *
	 * @param array<int, true> $visited Cycle guard for recursive collections.
	 */
	public function is_playable( int $release_id, array $visited = array(), int $depth = 0 ): bool {
		if ( $release_id < 1 || isset( $visited[ $release_id ] ) || $depth > TrackStreamPresenter::MAX_EXPANSION_DEPTH ) {
			return false;
		}
		if ( $this->has_preview( $release_id ) ) {
			return true;
		}
		if ( $this->has_streamable_asset( $release_id ) ) {
			return true;
		}
		if ( $depth >= TrackStreamPresenter::MAX_EXPANSION_DEPTH ) {
			return false;
		}

		$visited[ $release_id ] = true;
		foreach ( $this->releases->collection_items( $release_id ) as $item ) {
			$child_id = is_array( $item ) && isset( $item['release_id'] ) ? absint( $item['release_id'] ) : 0;
			if ( $child_id > 0 && $this->is_playable( $child_id, $visited, $depth + 1 ) ) {
				return true;
			}
		}

		return false;
	}

	private function has_streamable_asset( int $release_id ): bool {
		if ( null === $this->policy || ! $this->policy->decide( $release_id, AccessSubject::current() )->is_allowed() ) {
			return false;
		}
		return '' !== TrackStreamPresenter::streamable_quality( $release_id, $this->releases );
	}
}
