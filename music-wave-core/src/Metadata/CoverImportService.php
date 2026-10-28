<?php
/**
 * Dedicated service for importing remote cover art.
 *
 * Extracted from MetadataResolver to follow SRP.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CoverImportService {
	private const META_SOURCE_URL  = '_mw_cover_source_url';
	private const META_SOURCE_HASH = '_mw_cover_source_hash';

	/**
	 * Find existing attachment for same source URL (dedup).
	 *
	 * @return array<string, int|string>|null
	 */
	private function find_existing( string $url ): ?array {
		$hash = md5( $url );

		// Try transient cache first.
		$cached_id = get_transient( 'mw_cover_hash_' . $hash );
		if ( is_int( $cached_id ) && $cached_id > 0 ) {
			$attachment_url = wp_get_attachment_url( $cached_id );
			if ( is_string( $attachment_url ) && '' !== $attachment_url ) {
				return array(
					'id'  => $cached_id,
					'url' => $attachment_url,
				);
			}
		}

		// Query attachments by hash meta.
		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => self::META_SOURCE_HASH,
						'value' => $hash,
					),
				),
				'no_found_rows'  => true,
			)
		);

		if ( ! empty( $query->posts ) && is_array( $query->posts ) ) {
			$attachment_id = (int) $query->posts[0];
			if ( $attachment_id > 0 ) {
				set_transient( 'mw_cover_hash_' . $hash, $attachment_id, DAY_IN_SECONDS );
				return array(
					'id'  => $attachment_id,
					'url' => (string) wp_get_attachment_url( $attachment_id ),
				);
			}
		}

		return null;
	}

	/**
	 * Download a remote cover into the media library.
	 *
	 * Phase 2: dedup via hash + stores source URL meta.
	 *
	 * @return array<string, int|string>|\WP_Error
	 */
	public function import( string $url, string $title = '', int $post_id = 0 ) {
		$url = (string) esc_url_raw( $url, array( 'https' ) );
		if ( '' === $url ) {
			return new \WP_Error( 'invalid_cover', __( 'جلد URL نامعتبر است.', 'music-wave-core' ) );
		}

		// Phase 2 dedup: if same URL already imported, reuse.
		$existing = $this->find_existing( $url );
		if ( null !== $existing ) {
			// If post_id given and no thumbnail yet, set it? Leave to caller.
			return $existing;
		}

		/**
		 * Filter the download budgets for imported covers.
		 *
		 * @param array<string, int> $budgets timeout (s), max_bytes, max_pixels (per side).
		 */
		$budgets = apply_filters(
			'music_wave_cover_import_budgets',
			array(
				'timeout'    => 15,
				'max_bytes'  => 10 * 1024 * 1024,
				'max_pixels' => 5000,
			)
		);

		$budgets   = is_array( $budgets ) ? $budgets : array();
		$max_bytes = isset( $budgets['max_bytes'] ) ? max( 1, min( 20 * 1024 * 1024, (int) $budgets['max_bytes'] ) ) : 10 * 1024 * 1024;
		$tmp       = wp_tempnam( 'musicwave-cover' );
		if ( ! $tmp ) {
			return new \WP_Error( 'cover_temp_failed', __( 'ایجاد فایل موقت جلد ممکن نیست.', 'music-wave-core' ) );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => isset( $budgets['timeout'] ) ? max( 5, min( 30, (int) $budgets['timeout'] ) ) : 15,
				'redirection'         => 3,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => $max_bytes + 1,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'cover_download_failed', __( 'دریافت جلد از سرویس خارجی ممکن نیست؛ اتصال HTTPS یا وجود تصویر را بررسی کنید.', 'music-wave-core' ) );
		}

		$bytes = filesize( $tmp );
		if ( false === $bytes || $bytes < 1 || $bytes > $max_bytes ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'cover_too_large', __( 'اندازهٔ جلد دانلودشده از حد تعیین‌شده بیشتر است.', 'music-wave-core' ) );
		}

		$dimensions = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- probing untrusted bytes; failures handled below.
		$max_pixels = isset( $budgets['max_pixels'] ) ? max( 1, min( 5000, (int) $budgets['max_pixels'] ) ) : 5000;
		if ( ! is_array( $dimensions ) || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > $max_pixels || $dimensions[1] > $max_pixels ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'invalid_cover_dimensions', __( 'جلد دانلودشده تصویری قابل رمزگشایی در محدودهٔ پیکسلی تعیین‌شده نیست.', 'music-wave-core' ) );
		}

		$mime       = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $tmp ) : false;
		$extensions = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
		);
		if ( ! is_string( $mime ) || ! isset( $extensions[ $mime ] ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'invalid_cover_type', __( 'جلد دانلود شده یک تصویر پشتیبانی نمی‌شود.', 'music-wave-core' ) );
		}

		$title    = '' !== $title ? $title : __( 'جلد وارداتی', 'music-wave-core' );
		$basename = 'music-cover-' . substr( md5( $url ), 0, 12 ) . '.' . $extensions[ $mime ];
		$file     = array(
			'name'     => sanitize_file_name( $basename ),
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file, $post_id, $title );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return $attachment_id;
		}
		$attachment_id = (int) $attachment_id;
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $title ) );
		// Store source for dedup.
		update_post_meta( $attachment_id, self::META_SOURCE_URL, $url );
		update_post_meta( $attachment_id, self::META_SOURCE_HASH, md5( $url ) );
		set_transient( 'mw_cover_hash_' . md5( $url ), $attachment_id, DAY_IN_SECONDS );

		return array(
			'id'  => $attachment_id,
			'url' => (string) wp_get_attachment_url( $attachment_id ),
		);
	}
}
