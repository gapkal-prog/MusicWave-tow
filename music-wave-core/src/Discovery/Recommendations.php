<?php
/**
 * Explainable, consent-aware release recommendations.
 *
 * Every recommendation carries a machine reason plus a translatable
 * explanation. Personalization uses only the consented listening history;
 * without consent the service is purely editorial. Only publicly visible
 * releases are ever recommended (PROJECT_PLAN.md Stage 6 deliverable 1).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Discovery;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Catalog\ReleaseTermIndex;
use ManaCore\MusicWave\Core\Catalog\ReleaseVisibility;
use ManaCore\MusicWave\Core\Listening\ListeningRepository;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Recommendations {
	public const REASON_EDITORIAL    = 'editorial_latest';
	public const REASON_RECENT_GENRE = 'recent_genre';

	/** @var ListeningRepository */
	private $listening;

	/** @var ReleaseVisibility */
	private $visibility;

	/** @var ReleaseTermIndex */
	private $term_index;

	public function __construct( ?ListeningRepository $listening = null, ?ReleaseVisibility $visibility = null, ?ReleaseTermIndex $term_index = null ) {
		$this->visibility = null !== $visibility ? $visibility : new ReleaseVisibility();
		$this->listening  = null !== $listening ? $listening : new ListeningRepository( $this->visibility );
		$this->term_index = null !== $term_index ? $term_index : new ReleaseTermIndex();
	}

	/**
	 * Build explainable recommendations for one (possibly anonymous) user.
	 *
	 * @return array<int, array<string, int|string>>
	 */
	public function recommend( int $user_id, int $limit = 8 ): array {
		$limit = min( 24, max( 1, $limit ) );
		$seen  = array();
		$items = array();

		// Consented personalization first: releases sharing a genre with the
		// user's recent listening.
		if ( $user_id > 0 && $this->listening->has_consent( $user_id ) ) {
			$recent_genres = $this->recent_genres( $user_id, $seen );
			if ( array() !== $recent_genres['genres'] ) {
				foreach ( $this->query_releases( $limit, $recent_genres['genres'] ) as $release_id ) {
					if ( isset( $seen[ $release_id ] ) || count( $items ) >= $limit ) {
						continue;
					}
					$seen[ $release_id ] = true;
					$items[]             = $this->item( $release_id, self::REASON_RECENT_GENRE );
				}
			}
		}

		// Editorial fallback keeps the surface useful without any signals.
		foreach ( $this->query_releases( $limit * 2, array() ) as $release_id ) {
			if ( isset( $seen[ $release_id ] ) || count( $items ) >= $limit ) {
				continue;
			}
			$seen[ $release_id ] = true;
			$items[]             = $this->item( $release_id, self::REASON_EDITORIAL );
		}

		/**
		 * Filter the recommendation list (editorial curation hook).
		 *
		 * Integrations must keep every item explainable: `release_id`,
		 * `reason`, and `explanation` are required and only public releases
		 * may appear.
		 *
		 * @param array<int, array<string, int|string>> $items   Recommendations.
		 * @param int                                   $user_id Target user (0 = anonymous).
		 */
		$filtered = apply_filters( 'music_wave_recommendations', $items, $user_id );

		return is_array( $filtered ) ? array_slice( $filtered, 0, $limit ) : $items;
	}

	/**
	 * Human explanation for one machine reason.
	 */
	public function explanation( string $reason ): string {
		if ( self::REASON_RECENT_GENRE === $reason ) {
			return __( 'به دلیل ژانرهایی که اخیراً گوش داده‌اید.', 'music-wave-core' );
		}

		return __( 'جدید در کاتالوگ', 'music-wave-core' );
	}

	/** @return array<string, int|string> */
	private function item( int $release_id, string $reason ): array {
		return array(
			'release_id'  => $release_id,
			'reason'      => $reason,
			'explanation' => $this->explanation( $reason ),
		);
	}

	/**
	 * Genres from the consented recent history; also marks seen releases.
	 *
	 * @param array<int, bool> $seen Seen release map (by reference semantics via return merge).
	 * @return array{genres: array<int, string>}
	 */
	private function recent_genres( int $user_id, array &$seen ): array {
		$genres = array();
		$recent = $this->listening->recent( $user_id, ListeningRepository::EVENT_PLAYED, 10 );
		$ids    = array();
		foreach ( $recent as $row ) {
			$ids[] = (int) $row['release_id'];
		}
		// Resolve the history's genres in one batched query.
		$this->term_index->prime( $ids, array( 'mw_genre' ) );

		foreach ( $recent as $row ) {
			$seen[ (int) $row['release_id'] ] = true;
			foreach ( $this->term_index->slugs( (int) $row['release_id'], 'mw_genre' ) as $slug ) {
				$slug = sanitize_key( $slug );
				if ( '' !== $slug && ! in_array( $slug, $genres, true ) ) {
					$genres[] = $slug;
				}
			}
		}

		return array( 'genres' => array_slice( $genres, 0, 5 ) );
	}

	/**
	 * Query public releases, optionally narrowed to genre slugs.
	 *
	 * @param array<int, string> $genres Genre slugs (empty = latest).
	 * @return array<int, int>
	 */
	private function query_releases( int $limit, array $genres ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$args = array(
			'post_type'      => ReleasePostType::KEY,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => min( 50, max( 1, $limit ) ),
			'no_found_rows'  => true,
		);
		if ( array() !== $genres ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- bounded, cached discovery query.
				array(
					'taxonomy' => 'mw_genre',
					'field'    => 'slug',
					'terms'    => $genres,
				),
			);
		}

		$ids = get_posts( $args );
		$out = array();
		foreach ( is_array( $ids ) ? $ids : array() as $release_id ) {
			$release_id = absint( $release_id );
			if ( $release_id > 0 && $this->visibility->is_public( $release_id ) ) {
				$out[] = $release_id;
			}
		}

		return $out;
	}
}
