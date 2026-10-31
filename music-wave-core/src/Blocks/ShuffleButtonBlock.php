<?php
/**
 * Shuffle (random play) button for the release action bar.
 *
 * Delegates to the global preview queue player, which already resolves collection
 * children via /playback-queue. Shuffle is applied client-side before playback.
 *
 * Mirrors the smart play-all trigger so the two read as one control group, but
 * deliberately omits the `mw-preview-button` hook: that class makes the global
 * player start a sequential release queue instead of a shuffled one.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

use ManaCore\MusicWave\Core\Access\AccessPolicyEngine;
use ManaCore\MusicWave\Core\Access\AccessSubject;
use ManaCore\MusicWave\Core\Blocks\Concerns\ActionButtonStyle;
use ManaCore\MusicWave\Core\Blocks\Concerns\CollectionTracks;
use ManaCore\MusicWave\Core\Blocks\Concerns\EditorDiagnosticNotice;
use ManaCore\MusicWave\Core\Blocks\Concerns\ReleaseResolver;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ShuffleButtonBlock {
	/** @var object|null */
	private $render_context = null;

	/** @var ReleaseRepository */
	private $releases;

	/** @var AccessPolicyEngine|null */
	private $policy;

	public function __construct( ReleaseRepository $releases, ?AccessPolicyEngine $policy = null ) {
		$this->releases = $releases;
		$this->policy   = $policy;
	}

	/**
	 * Register the dynamic shuffle block and its frontend assets.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		BlockSupport::register_dynamic(
			'music-wave/shuffle-button',
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
	 * Ensure the release-actions controller is available for shuffle.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}

		if ( ! has_block( 'music-wave/shuffle-button' ) && ! is_singular( ReleasePostType::KEY ) ) {
			return;
		}

		$handle = 'music-wave-release-actions';
		if ( ! wp_script_is( $handle, 'enqueued' ) && ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script( $handle, MUSIC_WAVE_CORE_URL . 'assets/release-actions.js', array(), MUSIC_WAVE_CORE_VERSION, true );
		}
		if ( ! wp_script_is( $handle, 'enqueued' ) ) {
			wp_enqueue_script( $handle, MUSIC_WAVE_CORE_URL . 'assets/release-actions.js', array(), MUSIC_WAVE_CORE_VERSION, true );
		}

		$data = wp_scripts()->get_data( $handle, 'data' );
		if ( false === $data || false === strpos( (string) $data, 'musicWaveReleaseActions' ) ) {
			wp_localize_script(
				$handle,
				'musicWaveReleaseActions',
				array(
					'copied'      => __( 'پیوند کپی شد', 'music-wave-core' ),
					'copyFailed'  => __( 'کپی انجام نشد. پیوند را به صورت دستی کپی کنید.', 'music-wave-core' ),
					'shareFailed' => __( 'اشتراکگذاری انجام نشد.', 'music-wave-core' ),
				)
			);
		}
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( $handle, 'music-wave-core', MUSIC_WAVE_CORE_PATH . 'languages' );
		}
	}

	/**
	 * Render the shuffle trigger for one release.
	 *
	 * The trigger only appears when the release exposes an active tracklist
	 * (at least one playable child). A lone streamable file — even in several
	 * qualities — is not a tracklist, so it keeps the button hidden.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render( array $attributes = array() ): string {
		$mode        = ActionButtonStyle::resolve( $attributes, ActionButtonStyle::MODE_ICON, true );
		$show_label  = ActionButtonStyle::shows_label( $mode );
		$def_variant = ActionButtonStyle::MODE_ACCENT === $mode
			? ActionButtonStyle::VARIANT_ACCENT
			: ActionButtonStyle::VARIANT_SURFACE;
		$btn_classes = ActionButtonStyle::button_classes( $attributes, 'mw-shuffle-button', ActionButtonStyle::MODE_ICON, $def_variant );
		if ( ActionButtonStyle::MODE_ACCENT === $mode ) {
			$btn_classes .= ' mw-shuffle-button--accent';
		}
		if ( ! empty( $attributes['fullWidth'] ) && $show_label ) {
			$btn_classes .= ' mw-shuffle-button--block mw-action-button--block';
		}
		$label_class = $show_label ? 'mw-shuffle-button__label' : 'mw-shuffle-button__label screen-reader-text';

		$label = isset( $attributes['label'] ) && is_scalar( $attributes['label'] ) ? sanitize_text_field( (string) $attributes['label'] ) : '';
		$label = '' !== $label ? $label : __( 'پخش تصادفی', 'music-wave-core' );

		$icon       = ActionButtonStyle::render_icon( $attributes, 'shuffle', 20 );
		$release_id = $this->release_id( $attributes );
		$is_editor  = BlockSupport::is_editor_context();
		$has_tracks = $release_id > 0 && $this->has_playable_tracklist( $release_id, $is_editor );

		if ( ! $has_tracks ) {
			if ( ! $is_editor ) {
				return '';
			}

			$note = $release_id > 0
				? EditorDiagnosticNotice::render(
					/* translators: %s: release title. */
					sprintf( __( 'برای «%s» فهرست قطعات قابلپخش ثبت نشده است؛ دکمه پخش تصادفی در سایت پنهان میماند.', 'music-wave-core' ), get_the_title( $release_id ) )
				)
				: '';

			return '<div ' . BlockSupport::wrapper_attributes( 'mw-preview-player mw-preview-player--' . $mode . ' mw-shuffle-button-wrap' ) . '>'
				. '<button type="button" class="' . esc_attr( $btn_classes . ' mw-shuffle-button--placeholder mw-shuffle-button--no-asset' ) . '" aria-label="' . esc_attr( $label ) . '">'
				. '<span class="mw-shuffle-button__icon" aria-hidden="true">' . $icon . '</span>'
				. '<span class="' . esc_attr( $label_class ) . '">' . esc_html( $label ) . '</span>'
				. '</button>'
				. $note
				. '</div>';
		}

		/* translators: %s: release title. */
		$aria_label = sprintf( __( 'پخش تصادفی %s', 'music-wave-core' ), get_the_title( $release_id ) );

		return '<section ' . BlockSupport::wrapper_attributes( 'mw-preview-player mw-preview-player--' . $mode . ' mw-shuffle-button-wrap' ) . '>'
			. '<h2 class="screen-reader-text">' . esc_html__( 'پخش تصادفی', 'music-wave-core' ) . '</h2>'
			. '<button type="button" class="' . esc_attr( $btn_classes ) . '" data-mw-shuffle-button data-mw-release-id="' . esc_attr( (string) $release_id ) . '" aria-pressed="false" aria-label="' . esc_attr( $aria_label ) . '">'
			. '<span class="mw-shuffle-button__icon" aria-hidden="true">' . $icon . '</span>'
			. '<span class="' . esc_attr( $label_class ) . '">' . esc_html( $label ) . '</span>'
			. '</button>'
			. '</section>';
	}

	/**
	 * Resolve the release the shuffle action acts on via shared `ReleaseResolver` (DRY).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function release_id( array $attributes ): int {
		return ReleaseResolver::resolve( $attributes, $this->render_context );
	}

	/**
	 * Whether the release exposes at least one playable tracklist entry.
	 *
	 * @param int  $release_id Release ID.
	 * @param bool $is_editor  Whether the request renders inside the Block/Site Editor.
	 */
	private function has_playable_tracklist( int $release_id, bool $is_editor ): bool {
		$access_allowed = null !== $this->policy
			&& $this->policy->decide( $release_id, AccessSubject::current() )->is_allowed();

		return ( new CollectionTracks( $this->releases ) )->playable_count( $release_id, $is_editor, $access_allowed ) > 0;
	}
}
