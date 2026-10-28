<?php
/**
 * Release share button: native Web Share API with clipboard fallback.
 *
 * Server output is always a real button with data attributes so the action
 * works consistently across devices without layout shift or JS-required markup.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

use ManaCore\MusicWave\Core\Blocks\Concerns\ActionButtonStyle;
use ManaCore\MusicWave\Core\Blocks\Concerns\ReleaseResolver;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ShareButtonBlock {
	/** @var object|null */
	private $render_context = null;

	/**
	 * Register the dynamic share block and its frontend assets.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		BlockSupport::register_dynamic(
			'music-wave/share-button',
			function ( $attributes, $content, $block ): string {
				unset( $content );
				$previous             = $this->render_context;
				$this->render_context = is_object( $block ) ? $block : null;
				try {
					return $this->render( is_array( $attributes ) ? $attributes : array() );
				} finally {
					$this->render_context = $previous;
				}
			},
			array(
				'api_version'  => 3,
				'uses_context' => array( 'postId', 'postType' ),
				'supports'     => BlockSupport::appearance_tools(),
			)
		);

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue the share controller on public pages only when the block is present.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}

		if ( ! has_block( 'music-wave/share-button' ) && ! is_singular( ReleasePostType::KEY ) ) {
			return;
		}

		wp_enqueue_script(
			'music-wave-release-actions',
			MUSIC_WAVE_CORE_URL . 'assets/release-actions.js',
			array(),
			MUSIC_WAVE_CORE_VERSION,
			true
		);
		wp_localize_script(
			'music-wave-release-actions',
			'musicWaveReleaseActions',
			array(
				'copied'      => __( 'پیوند کپی شد', 'music-wave-core' ),
				'copyFailed'  => __( 'کپی انجام نشد. پیوند را به صورت دستی کپی کنید.', 'music-wave-core' ),
				'shareFailed' => __( 'اشتراک‌گذاری انجام نشد.', 'music-wave-core' ),
			)
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'music-wave-release-actions', 'music-wave-core', MUSIC_WAVE_CORE_PATH . 'languages' );
		}
	}

	/**
	 * Render the share trigger for one release.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render( array $attributes = array() ): string {
		$mode        = ActionButtonStyle::resolve( $attributes, ActionButtonStyle::MODE_ICON );
		$show_label  = ActionButtonStyle::shows_label( $mode );
		$btn_classes = ActionButtonStyle::button_classes( $attributes, 'mw-share-button', ActionButtonStyle::MODE_ICON, ActionButtonStyle::VARIANT_SURFACE );
		$label_class = $show_label ? 'mw-share-button__label' : 'mw-share-button__label screen-reader-text';

		$label = isset( $attributes['label'] ) && is_scalar( $attributes['label'] ) ? sanitize_text_field( (string) $attributes['label'] ) : '';
		$label = '' !== $label ? $label : __( 'اشتراک‌گذاری', 'music-wave-core' );

		$icon = ActionButtonStyle::render_icon( $attributes, 'share', 20 );

		$release_id = $this->release_id( $attributes );
		if ( $release_id < 1 ) {
			if ( ! BlockSupport::is_editor_context() ) {
				return '';
			}
			return '<div ' . BlockSupport::wrapper_attributes( 'mw-share-button-wrap' ) . '>'
				. '<button type="button" class="' . esc_attr( $btn_classes . ' mw-share-button--placeholder' ) . '" aria-label="' . esc_attr( $label ) . '">'
				. '<span class="mw-share-button__icon" aria-hidden="true">' . $icon . '</span>'
				. '<span class="' . esc_attr( $label_class ) . '">' . esc_html( $label ) . '</span>'
				. '</button>'
				. '</div>';
		}

		$url = get_permalink( $release_id );
		if ( ( ! is_string( $url ) || '' === $url ) && BlockSupport::is_editor_context() ) {
			$url = home_url( '/?p=' . $release_id );
		}
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$title       = get_the_title( $release_id );
		$title       = is_string( $title ) && '' !== $title ? $title : __( 'انتشار', 'music-wave-core' );
		$artists     = wp_get_post_terms( $release_id, 'mw_artist', array( 'fields' => 'names' ) );
		$artist_text = is_array( $artists ) && ! empty( $artists ) ? implode( '، ', $artists ) : '';
		$share_text  = '' !== $artist_text ? $title . ' — ' . $artist_text : $title;
		/* translators: %s: release title. */
		$aria_label = sprintf( __( 'اشتراک‌گذاری %s', 'music-wave-core' ), $title );

		return '<div ' . BlockSupport::wrapper_attributes( 'mw-share-button-wrap' ) . '>'
			. '<button type="button" class="' . esc_attr( $btn_classes ) . '" data-mw-share-button data-mw-share-url="' . esc_attr( $url ) . '" data-mw-share-title="' . esc_attr( $title ) . '" data-mw-share-text="' . esc_attr( $share_text ) . '" aria-label="' . esc_attr( $aria_label ) . '">'
			. '<span class="mw-share-button__icon" aria-hidden="true">' . $icon . '</span>'
			. '<span class="' . esc_attr( $label_class ) . '">' . esc_html( $label ) . '</span>'
			. '</button>'
			. '<span class="mw-share-button__status" role="status" aria-live="polite" hidden data-mw-share-status></span>'
			. '</div>';
	}

	/**
	 * Resolve the release the button acts on via shared `ReleaseResolver` (DRY).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function release_id( array $attributes ): int {
		return ReleaseResolver::resolve( $attributes, $this->render_context );
	}
}
