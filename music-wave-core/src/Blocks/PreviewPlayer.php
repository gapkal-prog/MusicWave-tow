<?php
/**
 * Accessible global queue player for public release previews.
 *
 * Refactored: DRY via ReleaseResolver & ReleasePlayability, SOLID SRP,
 * editor-safe placeholders to prevent Site Editor REST errors.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

use ManaCore\MusicWave\Core\Access\AccessPolicyEngine;
use ManaCore\MusicWave\Core\Blocks\Concerns\ActionButtonStyle;
use ManaCore\MusicWave\Core\Blocks\Concerns\ReleasePlayability;
use ManaCore\MusicWave\Core\Blocks\Concerns\ReleaseResolver;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PreviewPlayer {
	/** @var ReleaseRepository */
	private $releases;

	/** @var ReleasePlayability */
	private $playability;

	public function __construct( ReleaseRepository $releases, ?AccessPolicyEngine $policy = null ) {
		$this->releases    = $releases;
		$this->playability = new ReleasePlayability( $releases, $policy );
	}

	/**
	 * Register the preview block, public script, and global player markup.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_global_player' ), 5 );
		add_filter( 'music_wave_card_play_button', array( $this, 'card_play_button' ), 10, 3 );
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		BlockSupport::register_dynamic(
			'music-wave/preview-button',
			array( $this, 'render_block' ),
			array(
				'api_version'  => 3,
				'attributes'   => array(
					'releaseId'   => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'label'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'compact'     => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'buttonStyle' => array(
						'type'    => 'string',
						'default' => 'solid',
					),
					'showIcon'    => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'fullWidth'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'uses_context' => array( 'postId', 'postType' ),
				'supports'     => BlockSupport::appearance_tools(),
			)
		);
	}

	/**
	 * Load a small standalone player controller on public pages.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_script(
			'music-wave-runtime',
			MUSIC_WAVE_CORE_URL . 'assets/runtime.js',
			array( 'wp-api-fetch' ),
			MUSIC_WAVE_CORE_VERSION,
			true
		);
		wp_enqueue_script(
			'music-wave-preview-player',
			MUSIC_WAVE_CORE_URL . 'assets/preview-player.js',
			array( 'wp-api-fetch', 'music-wave-runtime' ),
			MUSIC_WAVE_CORE_VERSION,
			true
		);
		$lyrics_js = function_exists( 'get_theme_file_path' ) ? get_theme_file_path( 'assets/lyrics.js' ) : '';
		if ( is_string( $lyrics_js ) && is_readable( $lyrics_js ) ) {
			wp_enqueue_script(
				'musicwave-lyrics',
				get_theme_file_uri( 'assets/lyrics.js' ),
				array(),
				MUSIC_WAVE_CORE_VERSION,
				true
			);
		}
		wp_localize_script(
			'music-wave-preview-player',
			'musicWavePreviewPlayer',
			array(
				'restUrl'          => esc_url_raw( rest_url() ),
				'restNonce'        => wp_create_nonce( 'wp_rest' ),
				'loggedIn'         => get_current_user_id() > 0,
				'listeningEnabled' => \ManaCore\MusicWave\Core\Support\Settings::listening_enabled(),
				'persistentNav'    => (bool) apply_filters( 'music_wave_persistent_navigation', 'disabled' !== (string) \ManaCore\MusicWave\Core\Support\Settings::get( 'persistent_navigation' ) ),
				'labels'           => array(
					'play'         => __( 'پخش پیش‌نمایش', 'music-wave-core' ),
					'pause'        => __( 'توقف پیش‌نمایش', 'music-wave-core' ),
					'previous'     => __( 'پیش‌نمایش قبلی', 'music-wave-core' ),
					'next'         => __( 'پیش‌نمایش بعدی', 'music-wave-core' ),
					'close'        => __( 'بستن پخش‌کننده', 'music-wave-core' ),
					'queue'        => __( 'صف پیش‌نمایش', 'music-wave-core' ),
					'queueHeading' => __( 'بعدی', 'music-wave-core' ),
					'lyrics'       => __( 'متن هم‌زمان با آهنگ', 'music-wave-core' ),
					'lyricsClose'  => __( 'بستن متن', 'music-wave-core' ),
					'previewBadge' => __( 'پیش‌نمایش', 'music-wave-core' ),
					'loading'      => __( 'در حال بارگذاری…', 'music-wave-core' ),
					'error'        => __( 'پخش شروع نشد.', 'music-wave-core' ),
					'streamError'  => __( 'پخش امن شروع نشد.', 'music-wave-core' ),
					'sessionError' => __( 'جلسه شما تمام شده است. صفحه را تازه کنید یا دوباره وارد شوید.', 'music-wave-core' ),
					'volume'       => __( 'حجم', 'music-wave-core' ),
					'mute'         => __( 'بی‌صدا', 'music-wave-core' ),
					'unmute'       => __( 'باصدا', 'music-wave-core' ),
					'closeNotice'  => __( 'رد اطلاعیه', 'music-wave-core' ),
					/* translators: %s: value 1. */
					'playRelease'  => __( 'پخش %s', 'music-wave-core' ),
				),
			)
		);
	}

	/**
	 * Render the shared artwork play button for release cards.
	 *
	 * @param mixed  $button       Existing markup; anything non-empty is kept.
	 * @param mixed  $release_id   Release behind the card.
	 * @param mixed  $button_class Contextual presentation class.
	 */
	public function card_play_button( $button, $release_id = 0, $button_class = 'mw-release-shelf__play' ): string {
		if ( ! is_string( $button ) || '' !== $button ) {
			return is_string( $button ) ? $button : '';
		}

		$release_id = absint( $release_id );
		if ( $release_id < 1 || ReleasePostType::KEY !== get_post_type( $release_id ) || 'publish' !== get_post_status( $release_id ) ) {
			return '';
		}
		if ( ! $this->release_playable( $release_id ) ) {
			return '';
		}

		$title_class = is_string( $button_class ) && '' !== $button_class ? sanitize_html_class( $button_class ) : 'mw-release-shelf__play';
		/* translators: %s: value 1. */
		$label = sprintf( __( 'پخش %s', 'music-wave-core' ), get_the_title( $release_id ) );

		return '<button type="button" class="' . esc_attr( $title_class ) . ' mw-card-play" data-mw-release-id="' . esc_attr( (string) $release_id ) . '" aria-label="' . esc_attr( $label ) . '" aria-pressed="false"><span class="mw-card-play__icon" aria-hidden="true">&#9654;</span></button>';
	}

	/**
	 * Whether a release can produce any playable audio for the visitor.
	 * Delegates to shared ReleasePlayability for DRY.
	 */
	private function release_playable( int $release_id ): bool {
		return $this->playability->is_playable( $release_id );
	}

	/**
	 * Render a contextual release-preview button.
	 *
	 * Returns a safe placeholder in editor context when no release or no preview
	 * exists, preventing the JS ErrorResponsePlaceholder in Site Editor.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_block( array $attributes, string $content = '', $block = null ): string {
		unset( $content );
		try {
			$release_id = ReleaseResolver::resolve( $attributes, is_object( $block ) ? $block : null );

			$compact = ! empty( $attributes['compact'] );
			$label   = isset( $attributes['label'] ) ? sanitize_text_field( (string) $attributes['label'] ) : '';
			$style   = 'solid';
			if ( isset( $attributes['style'] ) && is_string( $attributes['style'] ) && '' !== trim( $attributes['style'] ) ) {
				$style = sanitize_key( $attributes['style'] );
			} elseif ( isset( $attributes['buttonStyle'] ) && is_scalar( $attributes['buttonStyle'] ) ) {
				$style = sanitize_key( (string) $attributes['buttonStyle'] );
			}
			$variation = BlockSupport::style_variation( $attributes, array( 'outline', 'ghost', 'vinyl' ) );
			if ( '' !== $variation ) {
				$style = $variation;
			}
			$show_icon  = 'icon' === $style || ! isset( $attributes['showIcon'] ) || (bool) $attributes['showIcon'];
			$full_width = ! empty( $attributes['fullWidth'] ) && 'icon' !== $style;

			if ( $release_id < 1 ) {
				return BlockSupport::is_editor_context() ? $this->placeholder_markup( $compact, $label, $style, $show_icon, $full_width, true, 0, $attributes ) : '';
			}

			$markup = $this->button_markup( $release_id, $compact, true, $label, $style, $show_icon, $full_width, $attributes );
			if ( '' === $markup && BlockSupport::is_editor_context() ) {
				$notice = \ManaCore\MusicWave\Core\Blocks\Concerns\EditorDiagnosticNotice::render(
					/* translators: %s: release title. */
					sprintf( __( 'برای «%s» فایل صوتی قابل‌پخش یا پیش‌نمایش ثبت نشده است.', 'music-wave-core' ), get_the_title( $release_id ) ),
					true
				);
				return $this->placeholder_markup( $compact, $label, $style, $show_icon, $full_width, false, $release_id, $attributes ) . $notice;
			}

			return $markup;
		} catch ( \Throwable $e ) {
			if ( BlockSupport::is_editor_context() ) {
				$compact    = ! empty( $attributes['compact'] );
				$label      = isset( $attributes['label'] ) ? sanitize_text_field( (string) $attributes['label'] ) : '';
				$style      = isset( $attributes['buttonStyle'] ) && is_scalar( $attributes['buttonStyle'] )
					? sanitize_key( (string) $attributes['buttonStyle'] )
					: ( isset( $attributes['style'] ) && is_scalar( $attributes['style'] ) ? sanitize_key( (string) $attributes['style'] ) : 'solid' );
				$show_icon  = 'icon' === $style || ! isset( $attributes['showIcon'] ) || (bool) $attributes['showIcon'];
				$full_width = ! empty( $attributes['fullWidth'] ) && 'icon' !== $style;
				return $this->placeholder_markup( $compact, $label, $style, $show_icon, $full_width, true, 0, $attributes );
			}
			return '';
		}
	}

	/**
	 * Build button modifier classes for standalone `music-wave/preview-button`.
	 *
	 * Preserves exact card snapshot markup whenever `$options` is empty.
	 *
	 * @param array<string, mixed> $options Block attributes when rendered as a block.
	 */
	private function resolve_preview_button_classes( bool $compact, string $style, bool $full_width, array $options ): string {
		if ( array() === $options ) {
			$legacy = in_array( $style, array( 'solid', 'outline', 'ghost', 'vinyl' ), true ) ? $style : 'solid';
			return 'mw-preview-button'
				. ( $compact ? ' mw-preview-button--compact' : '' )
				. ( 'solid' !== $legacy ? ' mw-preview-button--' . $legacy : '' )
				. ( $full_width ? ' mw-preview-button--block' : '' );
		}

		$is_icon = 'icon' === $style;
		$merged  = $options;
		if ( ! isset( $merged['variant'] ) && in_array( $style, array( 'outline', 'ghost' ), true ) ) {
			$merged['variant'] = $style;
		}
		$merged['style'] = $is_icon ? ActionButtonStyle::MODE_ICON : ActionButtonStyle::MODE_BUTTON;
		if ( $compact && ! isset( $options['size'] ) ) {
			$merged['size'] = ActionButtonStyle::SIZE_SM;
		}

		$classes = ActionButtonStyle::button_classes(
			$merged,
			'mw-preview-button',
			ActionButtonStyle::MODE_BUTTON,
			ActionButtonStyle::VARIANT_ACCENT
		);
		if ( $compact ) {
			$classes .= ' mw-preview-button--compact';
		}
		if ( in_array( $style, array( 'outline', 'ghost', 'vinyl' ), true ) ) {
			$classes .= ' mw-preview-button--' . $style;
		}
		if ( $full_width ) {
			$classes .= ' mw-preview-button--block mw-action-button--block';
		}
		return $classes;
	}

	/**
	 * Safe live visual preview for the Block/Site Editor when no release or audio exists yet.
	 *
	 * @param array<string, mixed> $options Block attributes.
	 */
	private function placeholder_markup( bool $compact, string $custom_label, string $style, bool $show_icon, bool $full_width, bool $no_release, int $release_id = 0, array $options = array() ): string {
		$is_icon     = 'icon' === $style;
		$class       = $this->resolve_preview_button_classes( $compact, $style, $full_width, $options ) . ' mw-preview-button--placeholder';
		$label       = '' !== $custom_label ? $custom_label : ( $compact ? __( 'پیش‌نمایش', 'music-wave-core' ) : __( 'پخش پیش‌نمایش', 'music-wave-core' ) );
		$icon_markup = '';
		if ( $show_icon ) {
			if ( array() === $options ) {
				$icon_markup = '<span class="mw-preview-button__icon" aria-hidden="true">▶</span>';
			} else {
				$icon_markup = '<span class="mw-preview-button__icon" aria-hidden="true">' . ActionButtonStyle::play_toggle_icon( 'play-filled', 18, $options, 'icon' ) . '</span>';
			}
		}
		$label_html = $is_icon
			? '<span class="screen-reader-text">' . esc_html( $label ) . '</span>'
			: '<span>' . esc_html( $label ) . '</span>';
		$wrapper    = BlockSupport::wrapper_attributes( $class );
		$title_attr = $no_release ? '' : ' data-preview-title="' . esc_attr( get_the_title( $release_id ) ) . '"';
		return '<button ' . $wrapper . ' type="button" aria-label="' . esc_attr( $label ) . '"' . $title_attr . '>' . $icon_markup . $label_html . '</button>';
	}

	/**
	 * Return a small or full preview trigger when the release has a public URL.
	 * In editor context returns live preview button instead of empty to avoid REST errors.
	 *
	 * @param array<string, mixed> $options Optional block attributes for standalone block mode.
	 */
	public function button_markup( int $release_id, bool $compact = false, bool $is_block_root = false, string $custom_label = '', string $style = 'solid', bool $show_icon = true, bool $full_width = false, array $options = array() ): string {
		if ( ReleasePostType::KEY !== get_post_type( $release_id ) ) {
			return BlockSupport::is_editor_context() ? $this->placeholder_markup( $compact, $custom_label, $style, $show_icon, $full_width, false, $release_id, $options ) : '';
		}

		$url = $this->releases->get( $release_id, 'mw_preview_url' );
		if ( ! \ManaCore\MusicWave\Core\Playback\TrackStreamPresenter::is_valid_preview_url( $url ) ) {
			return BlockSupport::is_editor_context() ? $this->placeholder_markup( $compact, $custom_label, $style, $show_icon, $full_width, false, $release_id, $options ) : '';
		}

		$title   = get_the_title( $release_id );
		$artists = wp_get_post_terms( $release_id, 'mw_artist', array( 'fields' => 'names' ) );
		$artist  = is_array( $artists ) && ! empty( $artists ) ? implode( ', ', $artists ) : '';
		$image   = get_the_post_thumbnail_url( $release_id, 'thumbnail' );
		$link    = get_permalink( $release_id );
		$limit   = absint( $this->releases->get( $release_id, 'mw_preview_duration' ) );
		$limit   = $limit >= 10 && $limit <= 120 ? $limit : 30;
		$is_icon = 'icon' === $style;
		$class   = $this->resolve_preview_button_classes( $compact, $style, $full_width, $options );
		$label   = '' !== $custom_label ? $custom_label : ( $compact ? __( 'پیش‌نمایش', 'music-wave-core' ) : __( 'پخش پیش‌نمایش', 'music-wave-core' ) );
		$icon    = '';
		if ( $show_icon ) {
			if ( array() === $options ) {
				$icon = '<span class="mw-preview-button__icon" aria-hidden="true">▶</span>';
			} else {
				$icon = '<span class="mw-preview-button__icon" aria-hidden="true">' . ActionButtonStyle::play_toggle_icon( 'play-filled', 18, $options, 'icon' ) . '</span>';
			}
		}
		$label_html = $is_icon
			? '<span class="screen-reader-text">' . esc_html( $label ) . '</span>'
			: '<span>' . esc_html( $label ) . '</span>';
		$aria_attr  = $is_icon ? ' aria-label="' . esc_attr( $label ) . '"' : '';
		$wrapper    = $is_block_root ? BlockSupport::wrapper_attributes( $class ) : 'class="' . esc_attr( $class ) . '"';

		return '<button ' . $wrapper . ' type="button" aria-pressed="false"' . $aria_attr . ' data-preview-url="' . esc_url( $url ) . '" data-preview-title="' . esc_attr( $title ) . '" data-preview-artist="' . esc_attr( $artist ) . '" data-preview-image="' . esc_url( is_string( $image ) ? $image : '' ) . '" data-preview-link="' . esc_url( is_string( $link ) ? $link : '' ) . '" data-preview-limit="' . esc_attr( (string) $limit ) . '">' . $icon . $label_html . '</button>';
	}

	/**
	 * Inline SVG icon used by the persistent player controls.
	 */
	private function icon( string $name, string $extra_class = '' ): string {
		$paths = array(
			'play'       => '<path d="M8 5.14v13.72c0 .79.87 1.27 1.54.84l10.63-6.86a1 1 0 0 0 0-1.68L9.54 4.3C8.87 3.87 8 4.35 8 5.14Z"/>',
			'pause'      => '<path d="M7 5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V5Zm6 0a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-2a1 1 0 0 1-1-1V5Z"/>',
			'previous'   => '<path d="M6 5a1 1 0 0 1 1 1v12a1 1 0 1 1-2 0V6a1 1 0 0 1 1-1Zm12.53.21A1 1 0 0 1 20 6.06v11.88a1 1 0 0 1-1.53.85L9.2 12.85a1 1 0 0 1 0-1.7l9.27-5.94a1 1 0 0 1 .06 0Z"/>',
			'next'       => '<path d="M18 5a1 1 0 0 1 1 1v12a1 1 0 1 1-2 0V6a1 1 0 0 1 1-1ZM5.47 5.21a1 1 0 0 1 1.06 0l9.27 5.94a1 1 0 0 1 0 1.7l-9.27 5.94A1 1 0 0 1 5 17.94V6.06a1 1 0 0 1 .47-.85Z"/>',
			'queue'      => '<path d="M4 6a1 1 0 0 1 1-1h14a1 1 0 1 1 0 2H5a1 1 0 0 1-1-1Zm0 5a1 1 0 0 1 1-1h14a1 1 0 1 1 0 2H5a1 1 0 0 1-1-1Zm1 4a1 1 0 1 0 0 2h8a1 1 0 1 0 0-2H5Zm12.5-1a1 1 0 0 1 1 1v1.5H20a1 1 0 1 1 0 2h-1.5V20a1 1 0 1 1-2 0v-1.5H15a1 1 0 1 1 0-2h1.5V15a1 1 0 0 1 1-1Z"/>',
			'subtitles'  => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v9A2.5 2.5 0 0 1 17.5 17H14l-1.8 2.4a.75.75 0 0 1-1.2 0L9.2 17H6.5A2.5 2.5 0 0 1 4 14.5v-9ZM7.75 9a.75.75 0 0 0 0 1.5h3.5a.75.75 0 0 0 0-1.5h-3.5Zm5 0a.75.75 0 0 0 0 1.5h3.5a.75.75 0 0 0 0-1.5h-3.5ZM7.75 12a.75.75 0 0 0 0 1.5h8.5a.75.75 0 0 0 0-1.5h-8.5Z"/>',
			'close'      => '<path d="M6.22 6.22a1 1 0 0 1 1.41 0L12 10.59l4.36-4.37a1 1 0 1 1 1.42 1.42L13.41 12l4.37 4.36a1 1 0 0 1-1.42 1.42L12 13.41l-4.37 4.37a1 1 0 0 1-1.41-1.42L10.59 12 6.22 7.63a1 1 0 0 1 0-1.41Z"/>',
			'volume'     => '<path d="M4 9.5A1.5 1.5 0 0 1 5.5 8H8l4.35-3.48A1 1 0 0 1 14 5.3v13.4a1 1 0 0 1-1.65.78L8 16H5.5A1.5 1.5 0 0 1 4 14.5v-5Zm12.7-1.03a1 1 0 0 1 1.4.13A5.98 5.98 0 0 1 19.5 12c0 1.3-.42 2.5-1.4 3.4a1 1 0 0 1-1.53-1.27c.62-.52.93-1.28.93-2.13 0-.85-.31-1.6-.93-2.13a1 1 0 0 1 .13-1.4Zm2.7-2.6a1 1 0 0 1 1.4.16A9.96 9.96 0 0 1 22.5 12a9.96 9.96 0 0 1-1.7 5.97 1 1 0 0 1-1.56-1.24A7.96 7.96 0 0 0 20.5 12c0-1.7-.5-3.28-1.26-4.73a1 1 0 0 1 .16-1.4Z"/>',
			'volume-off' => '<path d="M4 9.5A1.5 1.5 0 0 1 5.5 8H8l4.35-3.48A1 1 0 0 1 14 5.3v13.4a1 1 0 0 1-1.65.78L8 16H5.5A1.5 1.5 0 0 1 4 14.5v-5Zm12.3-.2a1 1 0 0 1 1.4 0L19 10.6l1.3-1.3a1 1 0 1 1 1.4 1.4L20.4 12l1.3 1.3a1 1 0 0 1-1.4 1.4L19 13.4l-1.3 1.3a1 1 0 0 1-1.4-1.4l1.3-1.3-1.3-1.3a1 1 0 0 1 0-1.4Z"/>',
			'spinner'    => '<path d="M12 3a1 1 0 0 1 1 1v2.5a1 1 0 1 1-2 0V4a1 1 0 0 1 1-1Zm0 13.5a1 1 0 0 1 1 1V20a1 1 0 1 1-2 0v-2.5a1 1 0 0 1 1-1ZM3 12a1 1 0 0 1 1-1h2.5a1 1 0 1 1 0 2H4a1 1 0 0 1-1-1Zm13.5 0a1 1 0 0 1 1-1H20a1 1 0 1 1 0 2h-2.5a1 1 0 0 1-1-1ZM5.64 5.64a1 1 0 0 1 1.41 0l1.77 1.77a1 1 0 1 1-1.41 1.41L5.64 7.05a1 1 0 0 1 0-1.41Zm9.54 9.54a1 1 0 0 1 1.41 0l1.77 1.77a1 1 0 0 1-1.41 1.41l-1.77-1.77a1 1 0 0 1 0-1.41Zm3.18-9.54a1 1 0 0 1 0 1.41l-1.77 1.77a1 1 0 1 1-1.41-1.41l1.77-1.77a1 1 0 0 1 1.41 0ZM8.82 15.18a1 1 0 0 1 0 1.41l-1.77 1.77a1 1 0 0 1-1.41-1.41l1.77-1.77a1 1 0 0 1 1.41 0Z"/>',
		);
		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}
		$classes = trim( 'mw-global-player__icon mw-global-player__icon--' . $name . ' ' . $extra_class );

		return '<svg class="' . esc_attr( $classes ) . '" viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}

	/**
	 * Render the persistent player; it stays hidden until a preview starts.
	 */
	public function render_global_player(): void {
		if ( is_admin() ) {
			return;
		}

		$queue = '<div class="mw-global-player__queue" data-mw-queue hidden>'
			. '<div class="mw-global-player__queue-header"><h3>' . esc_html__( 'بعدی', 'music-wave-core' ) . '</h3>'
			. '<button type="button" class="mw-global-player__queue-close" aria-label="' . esc_attr__( 'بستن صف', 'music-wave-core' ) . '">' . $this->icon( 'close' ) . '</button></div>'
			. '<ol class="mw-global-player__queue-list"></ol></div>';

		$lyrics = '<div class="mw-global-player__lyrics" data-mw-player-lyrics hidden>'
			. '<div class="mw-global-player__queue-header"><h3>' . esc_html__( 'متن هم‌زمان با آهنگ', 'music-wave-core' ) . '</h3>'
			. '<button type="button" class="mw-global-player__lyrics-close" aria-label="' . esc_attr__( 'بستن متن', 'music-wave-core' ) . '">' . $this->icon( 'close' ) . '</button></div>'
			. '<div class="mw-lyrics mw-lyrics--karaoke" data-mw-lyrics data-scroll="center" data-mw-lyric-offset="0"><div class="mw-lyrics__stage" data-mw-lyrics-stage></div></div></div>';

		$notice = '<div class="mw-global-player__notice" role="status" hidden>'
			. '<span class="mw-global-player__notice-message"></span>'
			. '<a class="mw-global-player__notice-cta wp-element-button" href="#" hidden></a>'
			. '<a class="mw-global-player__notice-login" href="#" hidden></a>'
			. '<button type="button" class="mw-global-player__notice-close" aria-label="' . esc_attr__( 'رد اطلاعیه', 'music-wave-core' ) . '">' . $this->icon( 'close' ) . '</button></div>';

		$track = '<div class="mw-global-player__track">'
			. '<img class="mw-global-player__art" alt="" hidden width="48" height="48" decoding="async">'
			. '<span class="mw-global-player__art-fallback" aria-hidden="true">' . $this->icon( 'play' ) . '</span>'
			. '<div class="mw-global-player__meta"><strong class="mw-global-player__title"></strong><span class="mw-global-player__artist"></span></div></div>';

		$controls = '<div class="mw-global-player__controls">'
			. '<button type="button" class="mw-global-player__previous" aria-label="' . esc_attr__( 'پیش‌نمایش قبلی', 'music-wave-core' ) . '">' . $this->icon( 'previous' ) . '</button>'
			. '<button type="button" class="mw-global-player__toggle" aria-label="' . esc_attr__( 'پخش پیش‌نمایش', 'music-wave-core' ) . '">' . $this->icon( 'play' ) . $this->icon( 'pause' ) . $this->icon( 'spinner' ) . '</button>'
			. '<button type="button" class="mw-global-player__next" aria-label="' . esc_attr__( 'پیش‌نمایش بعدی', 'music-wave-core' ) . '">' . $this->icon( 'next' ) . '</button>'
			. '</div>';

		$timeline = '<div class="mw-global-player__timeline">'
			. '<span class="mw-global-player__time mw-global-player__time--current" aria-hidden="true">0:00</span>'
			. '<label class="mw-global-player__progress"><span class="screen-reader-text">' . esc_html__( 'میزان پیشرفت پیش‌نمایش', 'music-wave-core' ) . '</span>'
			. '<input type="range" min="0" max="100" value="0" step="0.1" aria-valuetext="0:00"></label>'
			. '<span class="mw-global-player__duration" aria-hidden="true">0:00</span>'
			. '</div>';

		$tools = '<div class="mw-global-player__tools">'
			. '<button type="button" class="mw-global-player__lyrics-toggle" aria-expanded="false" aria-label="' . esc_attr__( 'متن هم‌زمان با آهنگ', 'music-wave-core' ) . '" hidden>' . $this->icon( 'subtitles' ) . '</button>'
			. '<button type="button" class="mw-global-player__queue-toggle" aria-expanded="false" aria-label="' . esc_attr__( 'صف پیش‌نمایش', 'music-wave-core' ) . '" hidden>' . $this->icon( 'queue' ) . '</button>'
			. '<div class="mw-global-player__volume-group">'
			. '<button type="button" class="mw-global-player__mute" aria-pressed="false" aria-label="' . esc_attr__( 'بی‌صدا', 'music-wave-core' ) . '">' . $this->icon( 'volume' ) . $this->icon( 'volume-off' ) . '</button>'
			. '<input type="range" class="mw-global-player__volume" min="0" max="1" value="1" step="0.01" aria-valuemin="0" aria-valuemax="100" aria-valuenow="100" aria-label="' . esc_attr__( 'حجم', 'music-wave-core' ) . '">'
			. '</div>'
			. '<button type="button" class="mw-global-player__close" aria-label="' . esc_attr__( 'بستن پخش‌کننده', 'music-wave-core' ) . '">' . $this->icon( 'close' ) . '</button>'
			. '</div>';

		$markup = '<aside class="mw-global-player" data-mw-preview-player hidden aria-label="' . esc_attr__( 'پخش‌کننده پیش‌نمایش موسیقی', 'music-wave-core' ) . '">'
			. $queue . $lyrics . $notice
			. '<audio preload="metadata"></audio>'
			. '<div class="mw-global-player__bar">' . $track . '<div class="mw-global-player__center">' . $controls . $timeline . '</div>' . $tools . '</div>'
			. '</aside>';

		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Assembled above from escaped fragments and static SVG markup.
	}
}
