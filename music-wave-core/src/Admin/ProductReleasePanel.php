<?php
/**
 * Bidirectional MusicWave release mapping panel on WooCommerce products.
 *
 * Allows store editors to view, attach, or detach `mw_release` posts directly
 * from the WooCommerce product edit screen and optionally set their access mode
 * (`purchase` or `purchase_or_membership`) in one step.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Admin;

use ManaCore\MusicWave\Core\Access\AccessModePolicy;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Commerce\ProductMapper;
use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProductReleasePanel {
	private const NONCE_ACTION = 'music_wave_save_product_releases';
	private const NONCE_NAME   = 'music_wave_product_releases_nonce';

	/** @var ProductMapper */
	private $mapper;

	public function __construct( ProductMapper $mapper ) {
		$this->mapper = $mapper;
	}

	public function register(): void {
		if ( ! post_type_exists( 'product' ) ) {
			return;
		}

		add_meta_box(
			'music-wave-product-releases',
			__( 'انتشارهای MusicWave مرتبط', 'music-wave-core' ),
			array( $this, 'render' ),
			'product',
			'side',
			'default'
		);
	}

	public function render( WP_Post $post ): void {
		$release_ids = $this->mapper->release_ids( $post->ID );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		if ( empty( $release_ids ) ) {
			echo '<p class="description">' . esc_html__( 'هنوز هیچ انتشاری به این محصول پیوند نشده است. از فهرست زیر یک یا چند انتشار را انتخاب و محصول را ذخیره کنید.', 'music-wave-core' ) . '</p>';
		} else {
			echo '<ul style="margin:0 0 10px;">';
			foreach ( $release_ids as $release_id ) {
				$link  = get_edit_post_link( $release_id );
				$mode  = AccessModePolicy::label( (string) get_post_meta( $release_id, 'mw_access_mode', true ), true );
				$title = get_the_title( $release_id );
				$label = '' !== trim( (string) $title ) ? (string) $title : '#' . $release_id;
				echo '<li style="margin-bottom:4px;display:flex;align-items:center;justify-content:space-between;gap:6px;">';
				if ( null !== $link ) {
					echo '<a href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a>';
				} else {
					echo '<span>' . esc_html( $label ) . '</span>';
				}
				echo '<code style="font-size:11px;">' . esc_html( $mode ) . '</code></li>';
			}
			echo '</ul>';
		}

		$candidates = $this->candidate_releases( $release_ids );
		if ( empty( $candidates ) ) {
			return;
		}

		echo '<input type="hidden" name="mw_mapped_release_ids[]" value="0">';
		echo '<label for="mw-mapped-release-ids" style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html__( 'انتخاب انتشارها (نگاشت دوطرفه):', 'music-wave-core' ) . '</label>';
		echo '<select id="mw-mapped-release-ids" name="mw_mapped_release_ids[]" multiple="multiple" class="widefat" size="' . esc_attr( (string) min( 8, max( 4, count( $candidates ) ) ) ) . '">';
		foreach ( $candidates as $rid => $rlabel ) {
			echo '<option value="' . esc_attr( (string) $rid ) . '" ' . selected( in_array( (int) $rid, $release_ids, true ), true, false ) . '>' . esc_html( $rlabel ) . '</option>';
		}
		echo '</select>';

		echo '<label for="mw-product-apply-mode" style="display:block;font-weight:600;margin:8px 0 4px;">' . esc_html__( 'حالت دسترسی انتشارهای انتخاب‌شده:', 'music-wave-core' ) . '</label>';
		echo '<select id="mw-product-apply-mode" name="mw_product_apply_mode" class="widefat">';
		echo '<option value="">' . esc_html__( 'بدون تغییر حالت فعلی انتشار', 'music-wave-core' ) . '</option>';
		echo '<option value="purchase">' . esc_html__( 'تنظیم روی «خرید تکی الزامی است»', 'music-wave-core' ) . '</option>';
		echo '<option value="purchase_or_membership">' . esc_html__( 'تنظیم روی «خرید تکی یا اشتراک VIP»', 'music-wave-core' ) . '</option>';
		echo '</select>';
	}

	public function save( int $product_id, WP_Post $post ): void {
		if ( 'product' !== $post->post_type || wp_is_post_autosave( $product_id ) || wp_is_post_revision( $product_id ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_NAME ] ) && is_string( $_POST[ self::NONCE_NAME ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_post', $product_id ) ) {
			return;
		}

		if ( ! isset( $_POST['mw_mapped_release_ids'] ) || ! is_array( $_POST['mw_mapped_release_ids'] ) ) {
			return;
		}

		$raw_ids    = array_map( 'absint', wp_unslash( $_POST['mw_mapped_release_ids'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$apply_mode = isset( $_POST['mw_product_apply_mode'] ) && is_scalar( $_POST['mw_product_apply_mode'] )
			? sanitize_key( wp_unslash( (string) $_POST['mw_product_apply_mode'] ) )
			: '';
		$old_ids    = $this->mapper->release_ids( $product_id );

		$this->mapper->sync_from_product( $product_id, $old_ids, $raw_ids, $apply_mode );
	}

	/**
	 * @param array<int, int> $selected_ids Always include already-mapped IDs even in large catalogs.
	 * @return array<int, string>
	 */
	private function candidate_releases( array $selected_ids ): array {
		$query = new WP_Query(
			array(
				'post_type'              => ReleasePostType::KEY,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => 100,
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$ids = array_values( array_unique( array_merge( $selected_ids, array_map( 'absint', is_array( $query->posts ) ? $query->posts : array() ) ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			if ( $id < 1 || ReleasePostType::KEY !== get_post_type( $id ) ) {
				continue;
			}
			$title      = trim( (string) get_the_title( $id ) );
			$out[ $id ] = sprintf( '#%d — %s', $id, '' !== $title ? $title : __( '(بدون عنوان)', 'music-wave-core' ) );
		}

		return $out;
	}
}
