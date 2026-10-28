<?php
/**
 * Resolves stored personal library items into display-ready summaries.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Library;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Catalog\ReleaseTermIndex;
use ManaCore\MusicWave\Core\Catalog\ReleaseVisibility;
use WP_Term;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LibraryCatalog {
	/** @var LibraryRepository */
	private $repository;

	/** @var ReleaseVisibility */
	private $visibility;

	/** @var ReleaseTermIndex */
	private $term_index;

	public function __construct( LibraryRepository $repository, ?ReleaseVisibility $visibility = null, ?ReleaseTermIndex $term_index = null ) {
		$this->repository = $repository;
		$this->visibility = null !== $visibility ? $visibility : new ReleaseVisibility();
		$this->term_index = null !== $term_index ? $term_index : new ReleaseTermIndex();
	}

	/**
	 * Return the library filter key used for the full collection.
	 */
	public const FILTER_ALL = 'all';

	/**
	 * Return the library filter key grouping followed artists.
	 */
	public const FILTER_ARTISTS = 'artists';

	/**
	 * Return the library filter key grouping wishlist items.
	 */
	public const FILTER_WISHLIST = 'wishlist';

	/**
	 * Return the library filter key grouping pre-saved upcoming releases.
	 */
	public const FILTER_PRESAVES = 'presaves';

	/**
	 * Summarize library items for display, optionally narrowed to one filter.
	 *
	 * Filters are "all", "artists", or any release-type term slug.
	 *
	 * @param int    $user_id Library owner.
	 * @param string $filter  Active library filter key.
	 * @param int    $limit   Maximum number of items returned.
	 * @return array<int, array<string, mixed>>
	 */
	public function summaries( int $user_id, string $filter = self::FILTER_ALL, int $limit = 24 ): array {
		$page = $this->paged_summaries( $user_id, $filter, $limit, 1 );

		return $page['items'];
	}

	/**
	 * Paginated library summaries so large collections stay reachable.
	 *
	 * The stored library is bounded (500 items) and already normalized, so
	 * offset pagination over the filtered stream is predictable and cheap
	 * (PROJECT_PLAN.md Stage 4 deliverable 5).
	 *
	 * @param int    $user_id Library owner.
	 * @param string $filter  Active library filter key.
	 * @param int    $limit   Items per page.
	 * @param int    $page    1-based page number.
	 * @return array{items: array<int, array<string, mixed>>, page: int, has_more: bool}
	 */
	public function paged_summaries( int $user_id, string $filter = self::FILTER_ALL, int $limit = 24, int $page = 1 ): array {
		$summaries = array();
		$limit     = min( 100, max( 1, $limit ) );
		$page      = max( 1, $page );
		$skip      = ( $page - 1 ) * $limit;
		$matched   = 0;
		$has_more  = false;
		$items     = $this->repository->all( $user_id );

		$this->prime_items( $items );

		foreach ( $items as $item ) {
			$summary = LibraryRepository::TYPE_ARTIST === $item['type']
				? $this->artist_summary( (int) $item['id'], (int) $item['added'] )
				: $this->release_summary( (int) $item['id'], (int) $item['added'], (string) $item['type'] );

			if ( null === $summary || ! $this->matches_filter( $summary, $filter ) ) {
				continue;
			}

			++$matched;
			if ( $matched <= $skip ) {
				continue;
			}
			if ( count( $summaries ) >= $limit ) {
				$has_more = true;
				break;
			}

			$summaries[] = $summary;
		}

		return array(
			'items'    => $summaries,
			'page'     => $page,
			'has_more' => $has_more,
		);
	}

	/**
	 * Count library items per filter key for tab rendering.
	 *
	 * Only filters with at least one item are included, plus "all".
	 *
	 * @return array<string, int>
	 */
	public function counts( int $user_id ): array {
		$counts = array( self::FILTER_ALL => 0 );
		$items  = $this->repository->all( $user_id );

		$this->prime_items( $items );

		foreach ( $items as $item ) {
			++$counts[ self::FILTER_ALL ];

			$grouped = $this->group_filter( (string) $item['type'] );
			if ( '' !== $grouped ) {
				$counts[ $grouped ] = isset( $counts[ $grouped ] ) ? $counts[ $grouped ] + 1 : 1;
				continue;
			}

			foreach ( $this->release_type_slugs( (int) $item['id'] ) as $slug ) {
				$counts[ $slug ] = isset( $counts[ $slug ] ) ? $counts[ $slug ] + 1 : 1;
			}
		}

		return $counts;
	}

	/**
	 * Return translated labels for known filter keys.
	 *
	 * @param array<string, int> $counts Filter counts keyed by filter key.
	 * @return array<string, string>
	 */
	public function filter_labels( array $counts ): array {
		$labels = array(
			self::FILTER_ALL      => __( 'همه', 'music-wave-core' ),
			self::FILTER_ARTISTS  => __( 'هنرمندان', 'music-wave-core' ),
			self::FILTER_WISHLIST => __( 'لیست دلخواه', 'music-wave-core' ),
			self::FILTER_PRESAVES => __( 'به زودی', 'music-wave-core' ),
		);

		foreach ( array_keys( $counts ) as $key ) {
			if ( isset( $labels[ $key ] ) || self::FILTER_ALL === $key ) {
				continue;
			}

			$term = get_term_by( 'slug', (string) $key, 'mw_release_type' );
			if ( $term instanceof WP_Term ) {
				$labels[ $key ] = $term->name;
			}
		}

		return $labels;
	}

	/**
	 * Build one summary for a stored release item.
	 *
	 * @return array<string, mixed>|null
	 */
	private function release_summary( int $release_id, int $added, string $item_type = LibraryRepository::TYPE_RELEASE ): ?array {
		if ( ReleasePostType::KEY !== get_post_type( $release_id ) || ! $this->visibility->can_read( $release_id ) ) {
			return null;
		}

		$artists = $this->term_index->names( $release_id, 'mw_artist' );
		$artist  = array() !== $artists ? implode( ', ', $artists ) : '';
		$types   = $this->term_index->names( $release_id, 'mw_release_type' );
		$type    = array() !== $types ? (string) $types[0] : __( 'انتشار', 'music-wave-core' );
		$title   = get_the_title( $release_id );
		$link    = get_permalink( $release_id );
		$year    = get_post_meta( $release_id, 'mw_release_year', true );

		$grouped = $this->group_filter( $item_type );
		if ( LibraryRepository::TYPE_WISHLIST === $item_type ) {
			$type = __( 'لیست دلخواه', 'music-wave-core' );
		}
		if ( LibraryRepository::TYPE_PRESAVE === $item_type ) {
			$type = __( 'به زودی', 'music-wave-core' );
		}

		return array(
			'type'       => $item_type,
			'id'         => $release_id,
			'title'      => '' !== $title ? $title : __( 'انتشار بدون عنوان', 'music-wave-core' ),
			'url'        => is_string( $link ) ? $link : '',
			'image'      => get_the_post_thumbnail(
				$release_id,
				'thumbnail',
				array(
					'class' => 'mw-music-library__image',
					'alt'   => '',
				)
			),
			'subtitle'   => $artist,
			'type_label' => $type,
			'year'       => is_scalar( $year ) && (int) $year > 0 ? (string) (int) $year : '',
			'added'      => $added,
			'type_slugs' => '' !== $grouped ? array( $grouped ) : $this->release_type_slugs( $release_id ),
		);
	}

	/**
	 * Build one summary for a stored artist item.
	 *
	 * @return array<string, mixed>|null
	 */
	private function artist_summary( int $term_id, int $added ): ?array {
		$term = get_term( $term_id, 'mw_artist' );
		if ( ! $term instanceof WP_Term ) {
			return null;
		}

		$image_id = absint( get_term_meta( $term_id, 'mw_artist_image_id', true ) );
		$image    = $image_id > 0
			? wp_get_attachment_image(
				$image_id,
				'thumbnail',
				false,
				array(
					'class' => 'mw-music-library__image mw-music-library__image--artist',
					'alt'   => '',
				)
			)
			: '';
		$link     = get_term_link( $term );

		return array(
			'type'       => LibraryRepository::TYPE_ARTIST,
			'id'         => $term_id,
			'title'      => $term->name,
			'url'        => ! is_wp_error( $link ) && is_string( $link ) ? $link : '',
			'image'      => $image,
			'subtitle'   => '',
			'type_label' => __( 'هنرمند', 'music-wave-core' ),
			'year'       => '',
			'added'      => $added,
			'type_slugs' => array( self::FILTER_ARTISTS ),
		);
	}

	/**
	 * Filter key that groups one library item type, or '' for plain releases.
	 */
	private function group_filter( string $item_type ): string {
		if ( LibraryRepository::TYPE_ARTIST === $item_type ) {
			return self::FILTER_ARTISTS;
		}
		if ( LibraryRepository::TYPE_WISHLIST === $item_type ) {
			return self::FILTER_WISHLIST;
		}
		if ( LibraryRepository::TYPE_PRESAVE === $item_type ) {
			return self::FILTER_PRESAVES;
		}

		return '';
	}

	/**
	 * Release type slugs bound to one release, used for tab filtering.
	 *
	 * @return array<int, string>
	 */
	private function release_type_slugs( int $release_id ): array {
		return $this->term_index->slugs( $release_id, 'mw_release_type' );
	}

	/**
	 * Batch-resolve everything the summaries below read per item.
	 *
	 * A library page renders the first N items but scans the stored stream,
	 * so an unprimed loop costs a post query, a meta query, and one query per
	 * cover attachment for every item it touches. Warm the release posts, the
	 * release meta, the cover attachments, and the two taxonomies once.
	 *
	 * @param array<int, array<string, mixed>> $items Normalized library items.
	 * @return void
	 */
	private function prime_items( array $items ): void {
		$release_ids = array();
		foreach ( $items as $item ) {
			if ( LibraryRepository::TYPE_ARTIST !== $item['type'] ) {
				$release_ids[] = (int) $item['id'];
			}
		}

		$this->visibility->prime( $release_ids, true );
		$this->term_index->prime( $release_ids, array( 'mw_artist', 'mw_release_type' ) );

		// Cover images resolve their attachment post after the meta is warm.
		$thumbnail_ids = array();
		foreach ( $release_ids as $release_id ) {
			$thumbnail_id = (int) get_post_meta( $release_id, '_thumbnail_id', true );
			if ( $thumbnail_id > 0 ) {
				$thumbnail_ids[] = $thumbnail_id;
			}
		}

		$this->visibility->prime( $thumbnail_ids, true );
	}

	/**
	 * Decide whether one summary belongs to the active filter.
	 *
	 * @param array<string, mixed> $summary Item summary.
	 * @param string               $filter  Active filter key.
	 */
	private function matches_filter( array $summary, string $filter ): bool {
		if ( self::FILTER_ALL === $filter || '' === $filter ) {
			return true;
		}

		$slugs = isset( $summary['type_slugs'] ) && is_array( $summary['type_slugs'] ) ? $summary['type_slugs'] : array();

		return in_array( $filter, $slugs, true );
	}
}
