<?php
/**
 * Idempotent rebuild of derived reverse indexes from canonical metadata.
 *
 * Reverse indexes (`_mw_release_ids` on products, `_mw_collection_ids` on
 * child releases) are derived data: the canonical truth is `mw_product_ids`
 * and `mw_collection_items` on releases. Multi-step writes can leave the
 * derived side inconsistent after crashes or direct database edits, so the
 * reconciler audits and rebuilds them idempotently
 * (PROJECT_PLAN.md Stage 3 deliverable 3).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Support;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Commerce\ProductMapper;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class IndexReconciler {
	/**
	 * Rebuild every derived reverse index from canonical release metadata.
	 *
	 * @return array<string, int> Counts: releases scanned, product links, collection links.
	 */
	public function reconcile(): array {
		$release_ids = $this->all_release_ids();

		$product_index    = array();
		$collection_index = array();
		foreach ( $release_ids as $release_id ) {
			foreach ( $this->canonical_product_ids( $release_id ) as $product_id ) {
				$product_index[ $product_id ][] = $release_id;
			}
			foreach ( $this->canonical_child_ids( $release_id ) as $child_id ) {
				$collection_index[ $child_id ][] = $release_id;
			}
		}

		$product_links = 0;
		foreach ( $product_index as $product_id => $mapped_release_ids ) {
			if ( 'product' !== get_post_type( (int) $product_id ) ) {
				continue;
			}
			update_post_meta( (int) $product_id, ProductMapper::REVERSE_META_KEY, array_values( array_unique( array_map( 'absint', $mapped_release_ids ) ) ) );
			++$product_links;
		}

		$collection_links = 0;
		foreach ( $collection_index as $child_id => $parent_ids ) {
			if ( ReleasePostType::KEY !== get_post_type( (int) $child_id ) ) {
				continue;
			}
			update_post_meta( (int) $child_id, '_mw_collection_ids', array_values( array_unique( array_map( 'absint', $parent_ids ) ) ) );
			++$collection_links;
		}

		// Remove stale derived rows that no canonical mapping references.
		$stale = $this->purge_stale_indexes( $product_index, $collection_index );

		return array(
			'releases'         => count( $release_ids ),
			'product_links'    => $product_links,
			'collection_links' => $collection_links,
			'stale_removed'    => $stale,
		);
	}

	/** @return array<int, int> */
	private function all_release_ids(): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => ReleasePostType::KEY,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		return is_array( $ids ) ? array_values( array_map( 'absint', $ids ) ) : array();
	}

	/** @return array<int, int> */
	private function canonical_product_ids( int $release_id ): array {
		$value = get_post_meta( $release_id, 'mw_product_ids', true );

		return is_array( $value ) ? array_values( array_filter( array_map( 'absint', $value ) ) ) : array();
	}

	/** @return array<int, int> */
	private function canonical_child_ids( int $release_id ): array {
		$items = get_post_meta( $release_id, 'mw_collection_items', true );
		$ids   = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( is_array( $item ) && isset( $item['release_id'] ) ) {
				$ids[] = absint( $item['release_id'] );
			}
		}

		return array_values( array_filter( $ids ) );
	}

	/**
	 * Delete derived rows whose canonical source no longer references them.
	 *
	 * @param array<int|string, array<int, int>> $product_index    Fresh product index.
	 * @param array<int|string, array<int, int>> $collection_index Fresh collection index.
	 */
	private function purge_stale_indexes( array $product_index, array $collection_index ): int {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return 0;
		}

		$stale = 0;
		foreach ( array(
			ProductMapper::REVERSE_META_KEY => $product_index,
			'_mw_collection_ids'            => $collection_index,
		) as $meta_key => $fresh ) {
			$holders = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( is_array( $holders ) ? $holders : array() as $holder_id ) {
				$holder_id = absint( $holder_id );
				if ( $holder_id > 0 && ! isset( $fresh[ $holder_id ] ) ) {
					delete_post_meta( $holder_id, $meta_key );
					++$stale;
				}
			}
		}

		return $stale;
	}
}
