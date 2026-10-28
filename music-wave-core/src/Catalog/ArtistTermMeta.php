<?php
/**
 * Artist taxonomy metadata and authoring controls.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Catalog;

use WP_Term;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ArtistTermMeta {
	private const NONCE_ACTION = 'music_wave_save_artist';
	private const NONCE_NAME   = 'music_wave_artist_nonce';

	/**
	 * Register metadata, admin fields, and media controls.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_meta' ), 8 );
		add_action( 'mw_artist_add_form_fields', array( $this, 'render_add_fields' ) );
		add_action( 'mw_artist_edit_form_fields', array( $this, 'render_edit_fields' ) );
		add_action( 'created_mw_artist', array( $this, 'save' ) );
		add_action( 'edited_mw_artist', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register public artist metadata with type-specific REST policies.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$common = array(
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => array( self::class, 'can_edit' ),
		);

		register_term_meta(
			'mw_artist',
			'mw_artist_biography',
			array_merge(
				$common,
				array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'wp_kses_post',
				)
			)
		);
		register_term_meta(
			'mw_artist',
			'mw_artist_image_id',
			array_merge(
				$common,
				array(
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => array( self::class, 'sanitize_image_id' ),
				)
			)
		);
		register_term_meta(
			'mw_artist',
			'mw_artist_canonical_url',
			array_merge(
				$common,
				array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => array( self::class, 'sanitize_canonical_url' ),
				)
			)
		);
	}

	/**
	 * Render metadata fields for new artists.
	 *
	 * @return void
	 */
	public function render_add_fields(): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		$this->render_fields( 0 );
	}

	/**
	 * Render metadata fields for an existing artist.
	 *
	 * @return void
	 */
	public function render_edit_fields( WP_Term $term ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		$this->render_fields( $term->term_id );
	}

	/**
	 * Persist safely sanitized fields.
	 *
	 * @return void
	 */
	public function save( int $term_id ): void {
		$nonce = isset( $_POST[ self::NONCE_NAME ] ) && is_string( $_POST[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! self::can_edit( false, '', $term_id ) ) {
			return;
		}

		$biography = isset( $_POST['mw_artist_biography'] ) && is_string( $_POST['mw_artist_biography'] ) ? wp_kses_post( wp_unslash( $_POST['mw_artist_biography'] ) ) : '';
		$image_id  = isset( $_POST['mw_artist_image_id'] ) ? self::sanitize_image_id( absint( wp_unslash( $_POST['mw_artist_image_id'] ) ) ) : 0;
		$url       = isset( $_POST['mw_artist_canonical_url'] ) && is_string( $_POST['mw_artist_canonical_url'] ) ? self::sanitize_canonical_url( sanitize_text_field( wp_unslash( $_POST['mw_artist_canonical_url'] ) ) ) : '';

		update_term_meta( $term_id, 'mw_artist_biography', $biography );
		update_term_meta( $term_id, 'mw_artist_image_id', $image_id );
		update_term_meta( $term_id, 'mw_artist_canonical_url', $url );
	}

	/**
	 * Load the native WordPress media modal only on artist term screens.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$screen = get_current_screen();
		if ( ! is_object( $screen ) || ! property_exists( $screen, 'taxonomy' ) || 'mw_artist' !== $screen->taxonomy ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script(
			'music-wave-artist-term',
			MUSIC_WAVE_CORE_URL . 'assets/artist-term.js',
			array( 'jquery' ),
			MUSIC_WAVE_CORE_VERSION,
			true
		);
		wp_localize_script(
			'music-wave-artist-term',
			'musicWaveArtistTerm',
			array(
				'title'  => __( 'تصویر هنرمند را انتخاب کنید', 'music-wave-core' ),
				'button' => __( 'استفاده از تصویر هنرمند', 'music-wave-core' ),
			)
		);
	}

	/**
	 * @param bool   $allowed Existing decision.
	 * @param string $meta_key Metadata key.
	 * @param int    $term_id Artist term ID.
	 */
	public static function can_edit( bool $allowed, string $meta_key, int $term_id ): bool {
		unset( $allowed, $meta_key, $term_id );
		$taxonomy = get_taxonomy( 'mw_artist' );

		return false !== $taxonomy && current_user_can( $taxonomy->cap->edit_terms );
	}

	/**
	 * @param mixed $value Attachment ID.
	 */
	public static function sanitize_image_id( $value ): int {
		$image_id = absint( $value );

		return $image_id > 0 && wp_attachment_is_image( $image_id ) ? $image_id : 0;
	}

	/**
	 * @param mixed $value Canonical artist URL.
	 */
	public static function sanitize_canonical_url( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return esc_url_raw( (string) $value, array( 'https' ) );
	}

	private function render_fields( int $term_id ): void {
		$biography = $term_id > 0 ? (string) get_term_meta( $term_id, 'mw_artist_biography', true ) : '';
		$image_id  = $term_id > 0 ? absint( get_term_meta( $term_id, 'mw_artist_image_id', true ) ) : 0;
		$url       = $term_id > 0 ? (string) get_term_meta( $term_id, 'mw_artist_canonical_url', true ) : '';
		$image     = $image_id > 0 ? wp_get_attachment_image( $image_id, 'thumbnail', false, array( 'alt' => '' ) ) : '';

		echo '<div class="form-field term-group"><label for="mw_artist_biography">' . esc_html__( 'بیوگرافی هنرمند', 'music-wave-core' ) . '</label><textarea id="mw_artist_biography" name="mw_artist_biography" rows="6" class="large-text">' . esc_textarea( $biography ) . '</textarea><p>' . esc_html__( 'یک زندگی‌نامهٔ کوتاه در آرشیو عمومی هنرمندان نمایش داده‌شده است.', 'music-wave-core' ) . '</p></div>';
		echo '<div class="form-field term-group"><label for="mw_artist_image_id">' . esc_html__( 'تصویر هنرمند', 'music-wave-core' ) . '</label><input type="hidden" id="mw_artist_image_id" name="mw_artist_image_id" value="' . esc_attr( (string) $image_id ) . '"><div class="mw-artist-image-preview">' . wp_kses_post( $image ) . '</div><p><button type="button" class="button mw-artist-image-select">' . esc_html__( 'انتخاب تصویر', 'music-wave-core' ) . '</button> <button type="button" class="button-link-delete mw-artist-image-remove">' . esc_html__( 'حذف تصویر', 'music-wave-core' ) . '</button></p></div>';
		echo '<div class="form-field term-group"><label for="mw_artist_canonical_url">' . esc_html__( 'هنرمند متعارف URL', 'music-wave-core' ) . '</label><input type="url" id="mw_artist_canonical_url" name="mw_artist_canonical_url" value="' . esc_attr( $url ) . '" class="regular-text" placeholder="https://"><p>' . esc_html__( 'هنرمند یا برچسب رسمی اختیاری URL. فقط HTTPS.', 'music-wave-core' ) . '</p></div>';
	}
}
