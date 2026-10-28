<?php
/**
 * Bidirectional release/product mapping and single-release WooCommerce product builder.
 *
 * Keeps `mw_product_ids` on `mw_release` posts and `_mw_release_ids` on `product`
 * posts 100% synchronized in both directions without infinite hook recursion,
 * cleans up orphaned mappings when either a product or release is deleted, and
 * provides atomic one-click creation of virtual WooCommerce single-release products.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Commerce;

use ManaCore\MusicWave\Core\Access\AccessModePolicy;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProductMapper {
	public const REVERSE_META_KEY = '_mw_release_ids';

	/** @var bool Re-entrancy guard preventing recursive save loops. */
	private $syncing = false;

	/**
	 * Keep the private product-side reverse index synchronized from a saved release.
	 *
	 * @param array<int, int> $old_product_ids Previous product IDs.
	 * @param array<int, int> $new_product_ids New product IDs.
	 */
	public function sync_reverse_index( int $release_id, array $old_product_ids, array $new_product_ids ): void {
		if ( $this->syncing ) {
			return;
		}
		$this->syncing = true;

		try {
			$old_product_ids = $this->normalize_ids( $old_product_ids );
			$new_product_ids = $this->normalize_ids( $new_product_ids );

			foreach ( array_diff( $old_product_ids, $new_product_ids ) as $product_id ) {
				$this->remove_release( $product_id, $release_id );
			}

			foreach ( $new_product_ids as $product_id ) {
				if ( 'product' === get_post_type( $product_id ) ) {
					$this->add_release( $product_id, $release_id );
				}
			}
		} finally {
			$this->syncing = false;
		}
	}

	/**
	 * Synchronize mappings initiated from the WooCommerce `product` editor screen.
	 *
	 * Updates `_mw_release_ids` on `$product_id`, adds/removes `$product_id` inside
	 * each release's `mw_product_ids`, and optionally transitions newly linked releases
	 * to `purchase` or `purchase_or_membership`.
	 *
	 * @param array<int, int> $old_release_ids Previous mapped release IDs.
	 * @param array<int, int> $new_release_ids Desired mapped release IDs.
	 * @param string          $apply_mode      Optional mode (`''`, `'purchase'`, `'purchase_or_membership'`).
	 */
	public function sync_from_product( int $product_id, array $old_release_ids, array $new_release_ids, string $apply_mode = '' ): void {
		if ( $this->syncing || 'product' !== get_post_type( $product_id ) ) {
			return;
		}
		$this->syncing = true;

		try {
			$old_ids = $this->normalize_ids( $old_release_ids );
			$valid   = array();
			foreach ( $this->normalize_ids( $new_release_ids ) as $candidate_id ) {
				if ( ReleasePostType::KEY === get_post_type( $candidate_id ) ) {
					$valid[] = $candidate_id;
				}
			}

			if ( empty( $valid ) ) {
				delete_post_meta( $product_id, self::REVERSE_META_KEY );
			} else {
				update_post_meta( $product_id, self::REVERSE_META_KEY, $valid );
			}

			foreach ( array_diff( $old_ids, $valid ) as $removed_release_id ) {
				$this->remove_product_from_release( $removed_release_id, $product_id );
			}

			$clean_mode = in_array( $apply_mode, array( 'purchase', 'purchase_or_membership' ), true ) ? $apply_mode : '';
			foreach ( $valid as $linked_release_id ) {
				$this->add_product_to_release( $linked_release_id, $product_id );
				if ( '' !== $clean_mode ) {
					update_post_meta( $linked_release_id, 'mw_access_mode', $clean_mode );
					if ( 'purchase_or_membership' === $clean_mode ) {
						$existing_levels = AccessModePolicy::sanitize_levels( get_post_meta( $linked_release_id, 'mw_membership_levels', true ) );
						if ( empty( $existing_levels ) ) {
							update_post_meta( $linked_release_id, 'mw_membership_levels', AccessModePolicy::default_membership_levels() );
						}
					}
				}
			}
		} finally {
			$this->syncing = false;
		}
	}

	/**
	 * Atomically create a virtual WooCommerce single-release product and link it bidirectionally.
	 *
	 * @param int    $release_id    Target `mw_release` ID.
	 * @param string $regular_price Numeric price string (e.g. `"99000"` or `"4.99"`).
	 * @param string $sale_price    Optional sale price string.
	 * @return int Newly created product ID, or `0` on failure.
	 */
	public function create_product_for_release( int $release_id, string $regular_price, string $sale_price = '' ): int {
		if ( ReleasePostType::KEY !== get_post_type( $release_id ) || ! post_type_exists( 'product' ) ) {
			return 0;
		}

		$clean_regular = $this->sanitize_decimal_price( $regular_price );
		if ( '' === $clean_regular ) {
			return 0;
		}
		$clean_sale = $this->sanitize_decimal_price( $sale_price );

		$title = trim( (string) get_the_title( $release_id ) );
		if ( '' === $title ) {
			/* translators: %d: release ID. */
			$title = sprintf( __( 'انتشار موسیقی #%d', 'music-wave-core' ), $release_id );
		}

		$post = function_exists( 'get_post' ) ? get_post( $release_id ) : null;
		$desc = $post instanceof \WP_Post && '' !== trim( (string) $post->post_excerpt )
			? (string) $post->post_excerpt
			: '';

		$product_id = wp_insert_post(
			array(
				'post_type'    => 'product',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_excerpt' => $desc,
			)
		);
		if ( ! is_int( $product_id ) || $product_id < 1 ) {
			return 0;
		}

		$active_price = '' !== $clean_sale && (float) $clean_sale < (float) $clean_regular
			? $clean_sale
			: $clean_regular;

		update_post_meta( $product_id, '_virtual', 'yes' );
		update_post_meta( $product_id, '_downloadable', 'no' );
		update_post_meta( $product_id, '_sold_individually', 'yes' );
		update_post_meta( $product_id, '_manage_stock', 'no' );
		update_post_meta( $product_id, '_stock_status', 'instock' );
		update_post_meta( $product_id, '_visibility', 'visible' );
		update_post_meta( $product_id, '_regular_price', $clean_regular );
		update_post_meta( $product_id, '_price', $active_price );
		if ( '' !== $clean_sale && (float) $clean_sale < (float) $clean_regular ) {
			update_post_meta( $product_id, '_sale_price', $clean_sale );
		}

		if ( function_exists( 'get_post_thumbnail_id' ) ) {
			$thumb_id = (int) get_post_thumbnail_id( $release_id );
			if ( $thumb_id > 0 ) {
				update_post_meta( $product_id, '_thumbnail_id', $thumb_id );
			}
		}

		$current_pids = $this->product_ids_on_release( $release_id );
		$next_pids    = $current_pids;
		$next_pids[]  = $product_id;
		$next_pids    = $this->normalize_ids( $next_pids );
		update_post_meta( $release_id, 'mw_product_ids', $next_pids );
		$this->sync_reverse_index( $release_id, $current_pids, $next_pids );

		return $product_id;
	}

	/** @return array<int, int> */
	public function release_ids( int $product_id ): array {
		$value = get_post_meta( $product_id, self::REVERSE_META_KEY, true );

		return is_array( $value ) ? $this->normalize_ids( $value ) : array();
	}

	/**
	 * Remove a deleted product OR deleted release symmetrically from all reverse mappings.
	 *
	 * @param int $post_id Deleted post ID.
	 */
	public function handle_deleted_post( int $post_id ): void {
		$post_type = get_post_type( $post_id );

		if ( ReleasePostType::KEY === $post_type ) {
			foreach ( $this->product_ids_on_release( $post_id ) as $product_id ) {
				$this->remove_release( $product_id, $post_id );
			}
			return;
		}

		if ( 'product' !== $post_type && empty( $this->release_ids( $post_id ) ) ) {
			return;
		}

		foreach ( $this->release_ids( $post_id ) as $release_id ) {
			$this->remove_product_from_release( $release_id, $post_id );
		}
	}

	private function add_release( int $product_id, int $release_id ): void {
		if ( ReleasePostType::KEY !== get_post_type( $release_id ) ) {
			return;
		}

		$release_ids   = $this->release_ids( $product_id );
		$release_ids[] = $release_id;
		update_post_meta( $product_id, self::REVERSE_META_KEY, $this->normalize_ids( $release_ids ) );
	}

	private function remove_release( int $product_id, int $release_id ): void {
		$release_ids = array_values( array_diff( $this->release_ids( $product_id ), array( $release_id ) ) );
		if ( empty( $release_ids ) ) {
			delete_post_meta( $product_id, self::REVERSE_META_KEY );
			return;
		}

		update_post_meta( $product_id, self::REVERSE_META_KEY, $release_ids );
	}

	private function add_product_to_release( int $release_id, int $product_id ): void {
		$product_ids   = $this->product_ids_on_release( $release_id );
		$product_ids[] = $product_id;
		update_post_meta( $release_id, 'mw_product_ids', $this->normalize_ids( $product_ids ) );
	}

	private function remove_product_from_release( int $release_id, int $product_id ): void {
		$product_ids = array_values( array_diff( $this->product_ids_on_release( $release_id ), array( $product_id ) ) );
		update_post_meta( $release_id, 'mw_product_ids', $product_ids );
	}

	/**
	 * @return array<int, int>
	 */
	private function product_ids_on_release( int $release_id ): array {
		$raw = get_post_meta( $release_id, 'mw_product_ids', true );

		return is_array( $raw ) ? $this->normalize_ids( $raw ) : array();
	}

	/**
	 * @param array<int|string, mixed> $ids Candidate IDs.
	 * @return array<int, int>
	 */
	private function normalize_ids( array $ids ): array {
		return array_values( array_filter( array_unique( array_map( 'absint', $ids ) ) ) );
	}

	private function sanitize_decimal_price( string $raw ): string {
		$persian_digits = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', ',', '٬', ' ' );
		$ascii_digits   = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '', '', '' );
		$normalized     = str_replace( $persian_digits, $ascii_digits, trim( $raw ) );
		if ( '' === $normalized || ! is_numeric( $normalized ) || (float) $normalized < 0 ) {
			return '';
		}

		return $normalized;
	}
}
