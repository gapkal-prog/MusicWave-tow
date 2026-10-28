<?php
/**
 * Editorial readiness feedback for MusicWave releases.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Admin;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use WP_Post;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleaseReadiness {
	/** @var ReleaseRepository */
	private $releases;

	public function __construct( ReleaseRepository $releases ) {
		$this->releases = $releases;
	}

	/**
	 * Register editor feedback and release-list status columns.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ), 20 );
		add_filter( 'manage_' . ReleasePostType::KEY . '_posts_columns', array( $this, 'add_columns' ) );
		add_action( 'manage_' . ReleasePostType::KEY . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
	}

	/**
	 * Register a non-blocking quality checklist beside the editor.
	 *
	 * @return void
	 */
	public function register_meta_box(): void {
		add_meta_box(
			'music-wave-release-readiness',
			__( 'آمادگی انتشار', 'music-wave-core' ),
			array( $this, 'render_meta_box' ),
			ReleasePostType::KEY,
			'side',
			'high'
		);
	}

	/**
	 * Render editorial checks without preventing intentional draft workflows.
	 */
	public function render_meta_box( WP_Post $post ): void {
		$issues = $this->issues( $post->ID );
		if ( empty( $issues ) ) {
			echo '<p><strong>' . esc_html__( 'آماده برای بررسی', 'music-wave-core' ) . '</strong></p><p>' . esc_html__( 'کاتالوگ اصلی، دسترسی، و بررسی تحویل برای این انتشار کامل است.', 'music-wave-core' ) . '</p>';
			return;
		}

		echo '<p><strong>' . esc_html__( 'قبل از راه‌اندازی نیاز به توجه دارد', 'music-wave-core' ) . '</strong></p><ul class="ul-disc">';
		foreach ( $issues as $issue ) {
			echo '<li>' . esc_html( $issue ) . '</li>';
		}
		echo '</ul><p class="description">' . esc_html__( 'این فهرست بررسی مانع پیش‌نویس یا انتشار نمی‌شود؛ در عوض، داده‌های ناقص کاتالوگ را هنگام بازبینی تحریریه قابل مشاهده می‌کند.', 'music-wave-core' ) . '</p>';
	}

	/**
	 * @param array<string, string> $columns Existing release columns.
	 * @return array<string, string>
	 */
	public function add_columns( array $columns ): array {
		$result = array();
		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'title' === $key ) {
				$result['mw_readiness'] = __( 'آمادگی', 'music-wave-core' );
				$result['mw_access']    = __( 'دسترسی', 'music-wave-core' );
			}
		}

		return $result;
	}

	/**
	 * Render low-cost status values for the releases list table.
	 *
	 * @return void
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( 'mw_readiness' === $column ) {
			$issues = $this->issues( $post_id );
			if ( empty( $issues ) ) {
				echo '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'آماده', 'music-wave-core' );
			} else {
				$count = count( $issues );
				echo '<span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html(
					sprintf(
						/* translators: %d: number of outstanding readiness items. */
						_n( '%d مورد', '%d مورد', $count, 'music-wave-core' ),
						$count
					)
				);
			}
			return;
		}

		if ( 'mw_access' === $column ) {
			$mode = (string) $this->releases->get( $post_id, 'mw_access_mode' );
			echo esc_html( \ManaCore\MusicWave\Core\Access\AccessModePolicy::label( $mode, true ) );
		}
	}

	/**
	 * Return editorial action items based on the current saved release state.
	 *
	 * @return array<int, string>
	 */
	public function issues( int $release_id ): array {
		$issues = array();
		if ( ReleasePostType::KEY !== get_post_type( $release_id ) ) {
			return $issues;
		}

		$types = wp_get_post_terms( $release_id, 'mw_release_type', array( 'fields' => 'slugs' ) );
		$types = is_array( $types ) ? array_values( array_map( 'sanitize_key', $types ) ) : array();
		if ( empty( $types ) ) {
			$issues[] = __( 'یک نوع انتشار اختصاص دهید.', 'music-wave-core' );
		}
		if ( ! has_post_thumbnail( $release_id ) ) {
			$issues[] = __( 'تصویر جلد را اضافه کنید.', 'music-wave-core' );
		}
		if ( '' === (string) $this->releases->get( $release_id, 'mw_release_date' ) ) {
			$issues[] = __( 'تاریخ انتشار اضافه کنید.', 'music-wave-core' );
		}
		if ( (int) $this->releases->get( $release_id, 'mw_duration' ) < 1 ) {
			$issues[] = __( 'مدت زمان اضافه کنید.', 'music-wave-core' );
		}
		if ( $this->is_collection( $types ) && $this->collection_is_empty( $release_id ) ) {
			$issues[] = __( 'حداقل یک قطعه یا قسمت را به این مجموعه اضافه کنید.', 'music-wave-core' );
		}

		$mode = (string) $this->releases->get( $release_id, 'mw_access_mode' );
		if ( \ManaCore\MusicWave\Core\Access\AccessModePolicy::uses_purchase( $mode ) && empty( $this->releases->product_ids( $release_id ) ) ) {
			$issues[] = __( 'برای فعال‌سازی مسیر خرید تکی، حداقل یک محصول WooCommerce را نگاشت کنید.', 'music-wave-core' );
		}
		if ( \ManaCore\MusicWave\Core\Access\AccessModePolicy::uses_membership( $mode ) && empty( $this->membership_levels( $release_id ) ) ) {
			$issues[] = __( 'یک سطح عضویت برای دسترسی به عضویت اضافه کنید.', 'music-wave-core' );
		}
		if ( in_array( $mode, array( 'purchase', 'membership', 'purchase_or_membership' ), true ) && ! $this->has_download_assets( $release_id ) ) {
			$issues[] = __( 'یک دارایی دانلود حفاظت‌شده برای این انتشار دردار انتخاب کنید.', 'music-wave-core' );
		}

		/**
		 * Filter non-blocking editorial readiness issues.
		 *
		 * @param array<int, string> $issues     Human-readable action items.
		 * @param int                $release_id Release ID.
		 */
		$issues = apply_filters( 'music_wave_release_readiness_issues', $issues, $release_id );

		return is_array( $issues ) ? array_values( array_filter( array_map( 'strval', $issues ) ) ) : array();
	}

	/**
	 * @param array<int, string> $types Release type slugs.
	 */
	private function is_collection( array $types ): bool {
		return ! empty( array_intersect( array( 'album', 'ep', 'mix', 'playlist', 'podcast_show' ), $types ) );
	}

	private function collection_is_empty( int $release_id ): bool {
		if ( ! method_exists( $this->releases, 'collection_items' ) ) {
			return false;
		}

		return empty( $this->releases->collection_items( $release_id ) );
	}

	/**
	 * @return array<int, string>
	 */
	private function membership_levels( int $release_id ): array {
		$levels = $this->releases->get( $release_id, 'mw_membership_levels' );

		return is_array( $levels ) ? array_values( array_filter( array_map( 'sanitize_key', $levels ) ) ) : array();
	}

	private function has_download_assets( int $release_id ): bool {
		$assets = $this->releases->get( $release_id, 'mw_download_assets' );
		if ( is_array( $assets ) && ! empty( $assets ) ) {
			return true;
		}

		return '' !== (string) $this->releases->get( $release_id, 'mw_download_asset_id' );
	}
}
