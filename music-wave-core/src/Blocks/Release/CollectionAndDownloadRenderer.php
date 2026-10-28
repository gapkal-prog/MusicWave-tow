<?php
/**
 * Collection tracklists, release playback triggers, and download actions
 * renderer (`collection-list`, `preview-player`, `download-button`).
 *
 * Clean Architecture / OOP / DRY:
 * - Delegates all button variants, sizes, shapes, and SVG glyphs to
 *   `ActionButtonStyle` so every playback & download control speaks one visual language.
 * - Renders true 1:1 grid-aligned headers and track rows with responsive container
 *   variables (`--mw-track-cols`) and crisp play/equalizer states.
 * - Provides realistic, zero-config live previews in the Block/Site Editor whenever
 *   no bound release or track assets exist yet.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks\Release;

use ManaCore\MusicWave\Core\Blocks\BlockSupport;
use ManaCore\MusicWave\Core\Blocks\Concerns\ActionButtonStyle;
use ManaCore\MusicWave\Core\Blocks\Concerns\EditorPreviewCatalog;
use ManaCore\MusicWave\Core\Blocks\Concerns\ReleaseResolver;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Downloads\ReleaseDownloadCatalog;
use ManaCore\MusicWave\Core\Playback\TrackStreamPresenter;
use WP_Post;
use ManaCore\MusicWave\Core\Account\AuthPolicy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CollectionAndDownloadRenderer {
	/** @var ReleaseBlockContext */
	private $context;

	public function __construct( ReleaseBlockContext $context ) {
		$this->context = $context;
	}

	/**
	 * Render `music-wave/collection-list`.
	 *
	 * @param array<string, mixed> $attributes     Block attributes.
	 * @param object|null          $render_context Active WP_Block context.
	 */
	public function render_collection_list( array $attributes, $render_context = null ): string {
		$release_id = ReleaseResolver::resolve_collection( $attributes, $render_context, $this->context->repository() );
		$is_editor  = BlockSupport::is_editor_context();
		if ( $release_id <= 0 && ! $is_editor ) {
			return '';
		}
		if ( $release_id > 0 && ! $is_editor && 'publish' !== (string) get_post_status( $release_id ) ) {
			return '';
		}

		$entries   = $release_id > 0 ? $this->collection_entries( $release_id, $is_editor ) : array();
		$diag_note = '';
		if ( empty( $entries ) && $is_editor ) {
			if ( $release_id > 0 ) {
				$diag_note = \ManaCore\MusicWave\Core\Blocks\Concerns\EditorDiagnosticNotice::render(
					/* translators: %s: release title. */
					sprintf( __( 'برای «%s» زیرقطعه‌ای ثبت نشده است (پیش‌نمایش ظاهری فهرست قطعات؛ در فرانت‌اند برای تک‌آهنگ پنهان می‌ماند).', 'music-wave-core' ), get_the_title( $release_id ) )
				);
			}
			foreach ( EditorPreviewCatalog::published_catalog_tracks( 6 ) as $idx => $catalog_post ) {
				$entries[] = array(
					'post'        => $catalog_post,
					'position'    => $idx + 1,
					'disc_number' => 1,
				);
			}
		}
		if ( empty( $entries ) && ! $is_editor ) {
			return '';
		}

		$config = $this->resolve_tracklist_config( $attributes );

		if ( empty( $entries ) ) {
			return $this->render_editor_sample_collection( $config, $release_id );
		}

		$groups     = array();
		$post_items = array();
		foreach ( $entries as $entry ) {
			$child        = $entry['post'];
			$post_items[] = $child;
			$disc         = $config['group_by_disc'] ? (int) $entry['disc_number'] : 1;
			$key          = $disc > 0 ? $disc : 1;
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array();
			}
			$groups[ $key ][] = $entry;
		}
		ksort( $groups );

		$sections     = array();
		$has_multiple = count( $groups ) > 1;
		foreach ( $groups as $disc => $tracks ) {
			$rows = array();
			foreach ( $tracks as $entry ) {
				$display_pos = count( $rows ) + 1;
				$row         = $this->render_real_track_row( $entry['post'], $display_pos, $config );
				if ( '' !== $row ) {
					$rows[] = $row;
				}
			}
			if ( empty( $rows ) ) {
				continue;
			}
			$disc_heading = '';
			if ( $config['group_by_disc'] && $has_multiple ) {
				/* translators: %s: disc number. */
				$disc_label   = sprintf( __( 'دیسک %s', 'music-wave-core' ), number_format_i18n( $disc ) );
				$disc_heading = '<h3 class="mw-collection-list__disc"><span class="mw-collection-list__disc-icon" aria-hidden="true">'
					. ActionButtonStyle::icon_svg( 'disc', 16 )
					. '</span><span>' . esc_html( $disc_label ) . '</span></h3>';
			}
			$sections[] = $disc_heading
				. '<div class="mw-collection-list__grid-shell">'
				. $this->tracklist_header_markup( $config )
				. '<ol class="mw-collection-list__tracks">' . implode( '', $rows ) . '</ol>'
				. '</div>';
		}
		if ( empty( $sections ) ) {
			return $is_editor ? $this->render_editor_sample_collection( $config, $release_id ) : '';
		}

		$heading_html = $this->section_heading_markup(
			$config,
			count( $post_items ),
			$this->total_duration_seconds( $post_items )
		);

		return '<section ' . BlockSupport::wrapper_attributes( $this->collection_root_classes( $config ), $this->collection_style_vars( $config ) )
			. ( '' === $heading_html ? ' aria-label="' . esc_attr__( 'فهرست قطعات', 'music-wave-core' ) . '"' : '' )
			. '>' . $heading_html . $diag_note . implode( '', $sections ) . '</section>';
	}

	/**
	 * Render `music-wave/preview-player` (`پخش‌کننده پیش‌نمایش`).
	 *
	 * Smart automatic audio mode:
	 * 1. Collection with playable tracks or multi-stream release -> queues collection (`all`).
	 * 2. Entitled single stream -> streams release (`single`).
	 * 3. Public preview clip (`mw_preview_url`) -> plays preview clip (`data-preview-url`).
	 * 4. Editor context without audio yet -> live button preview.
	 *
	 * @param array<string, mixed> $attributes     Block attributes.
	 * @param object|null          $render_context Active WP_Block context.
	 */
	public function render_preview_player( array $attributes, $render_context = null ): string {
		$release_id = $this->context->release_id( $attributes, $render_context );
		$is_editor  = BlockSupport::is_editor_context();
		$variation  = BlockSupport::style_variation( $attributes, array( 'outline', 'ghost', 'vinyl' ) );

		$style_opts = $attributes;
		if ( '' !== $variation && ! isset( $style_opts['variant'] ) && 'vinyl' !== $variation ) {
			$style_opts['variant'] = $variation;
		}
		if ( ! empty( $attributes['compact'] ) && ! isset( $style_opts['size'] ) ) {
			$style_opts['size'] = ActionButtonStyle::SIZE_SM;
		}

		$action_mode = ActionButtonStyle::resolve( $style_opts, ActionButtonStyle::MODE_ICON );
		$is_icon     = ActionButtonStyle::MODE_ICON === $action_mode;
		$size        = ActionButtonStyle::resolve_size( $style_opts, ActionButtonStyle::SIZE_LG );
		$icon_px     = 'sm' === $size ? 16 : ( 'lg' === $size ? 22 : 19 );

		$btn_classes  = array(
			ActionButtonStyle::button_classes(
				$style_opts,
				'mw-preview-button',
				ActionButtonStyle::MODE_ICON,
				ActionButtonStyle::VARIANT_ACCENT
			),
			'mw-preview-player__trigger',
			'mw-preview-player__trigger--' . $action_mode,
		);
		$legacy_style = isset( $attributes['style'] ) && is_string( $attributes['style'] ) && '' !== trim( $attributes['style'] )
			? BlockSupport::key_attribute( $attributes, 'style', array( 'solid', 'icon', 'outline', 'ghost', 'vinyl' ), 'icon' )
			: BlockSupport::key_attribute( $attributes, 'buttonStyle', array( 'solid', 'icon', 'outline', 'ghost', 'vinyl' ), 'icon' );
		$style_mod    = '' !== $variation ? $variation : $legacy_style;
		if ( ! in_array( $style_mod, array( 'solid', 'icon' ), true ) ) {
			$btn_classes[] = 'mw-preview-button--' . $style_mod;
		}
		if ( ! empty( $attributes['compact'] ) ) {
			$btn_classes[] = 'mw-preview-button--compact';
		}

		$show_icon = $is_icon || BlockSupport::bool_attribute( $attributes, 'showIcon', true );
		$icon_html = $show_icon
			? '<span class="mw-preview-button__icon" aria-hidden="true">' . ActionButtonStyle::play_toggle_icon( 'play-filled', $icon_px, $style_opts, 'icon' ) . '</span>'
			: '';
		$label_cls = $is_icon ? 'mw-preview-button__label screen-reader-text' : 'mw-preview-button__label';

		$repo          = $this->context->repository();
		$preview_url   = $release_id > 0 ? TrackStreamPresenter::preview_url( $release_id, $repo ) : '';
		$stream_groups = array();
		$playable_kids = 0;

		if ( $release_id > 0 ) {
			$allowed = $this->context->is_access_allowed( $release_id );
			if ( $allowed ) {
				$stream_groups = TrackStreamPresenter::streamable_file_groups( $release_id, $repo );
			}
			foreach ( $this->collection_entries( $release_id, $is_editor ) as $entry ) {
				$tid = (int) $entry['post']->ID;
				if ( '' !== TrackStreamPresenter::preview_url( $tid, $repo ) || ( $allowed && '' !== TrackStreamPresenter::streamable_quality( $tid, $repo ) ) ) {
					++$playable_kids;
				}
			}
		}

		if ( $playable_kids > 0 || count( $stream_groups ) > 1 ) {
			$visible = BlockSupport::text_attribute( $attributes, 'label', __( 'پخش همه', 'music-wave-core' ) );
			return '<section ' . BlockSupport::wrapper_attributes( 'mw-preview-player mw-preview-player--' . $action_mode ) . '>'
				. '<h2 class="screen-reader-text">' . esc_html__( 'پخش انتشار', 'music-wave-core' ) . '</h2>'
				. '<button class="' . esc_attr( implode( ' ', $btn_classes ) ) . '" type="button"'
				. ' data-mw-release-id="' . esc_attr( (string) $release_id ) . '"'
				. ' data-mw-play-mode="all"'
				. ' aria-pressed="false" aria-label="' . esc_attr( $visible ) . '">'
				. $icon_html
				. '<span class="' . esc_attr( $label_cls ) . '">' . esc_html( $visible ) . '</span>'
				. '</button></section>';
		}

		if ( '' !== $preview_url ) {
			$title   = (string) get_the_title( $release_id );
			$artists = wp_get_post_terms( $release_id, 'mw_artist', array( 'fields' => 'names' ) );
			$artist  = is_array( $artists ) && ! empty( $artists ) ? implode( ', ', $artists ) : '';
			$image   = function_exists( 'get_the_post_thumbnail_url' ) ? get_the_post_thumbnail_url( $release_id, 'thumbnail' ) : '';
			$link    = (string) get_permalink( $release_id );
			$limit   = TrackStreamPresenter::preview_limit( $release_id, $repo );
			$label   = BlockSupport::text_attribute( $attributes, 'label', __( 'پخش پیش‌نمایش', 'music-wave-core' ) );

			return '<section ' . BlockSupport::wrapper_attributes( 'mw-preview-player mw-preview-player--' . $action_mode ) . '>'
				. '<h2 class="screen-reader-text">' . esc_html__( 'پیش‌نمایش صوتی', 'music-wave-core' ) . '</h2>'
				. '<button class="' . esc_attr( implode( ' ', $btn_classes ) ) . '" type="button" aria-pressed="false" aria-label="' . esc_attr( $label ) . '"'
				. ' data-preview-url="' . esc_url( $preview_url ) . '"'
				. ' data-preview-title="' . esc_attr( $title ) . '"'
				. ' data-preview-artist="' . esc_attr( $artist ) . '"'
				. ' data-preview-image="' . esc_url( is_string( $image ) ? $image : '' ) . '"'
				. ' data-preview-link="' . esc_url( $link ) . '"'
				. ' data-preview-limit="' . esc_attr( (string) $limit ) . '">'
				. $icon_html
				. '<span class="' . esc_attr( $label_cls ) . '">' . esc_html( $label ) . '</span>'
				. '</button></section>';
		}

		if ( empty( $stream_groups ) ) {
			if ( ! $is_editor ) {
				return '';
			}
			$preview_label = BlockSupport::text_attribute( $attributes, 'label', __( 'پخش الان', 'music-wave-core' ) );
			$diag_note     = $release_id > 0
				? \ManaCore\MusicWave\Core\Blocks\Concerns\EditorDiagnosticNotice::render(
					/* translators: %s: release title. */
					sprintf( __( 'برای «%s» فایل صوتی یا پیش‌نمایش ثبت نشده است.', 'music-wave-core' ), get_the_title( $release_id ) ),
					true
				)
				: '';
			return '<section ' . BlockSupport::wrapper_attributes( 'mw-preview-player mw-preview-player--' . $action_mode . ' mw-preview-player--placeholder' ) . '>'
				. '<button class="' . esc_attr( implode( ' ', $btn_classes ) ) . '" type="button" aria-pressed="false" aria-label="' . esc_attr( $preview_label ) . '">'
				. $icon_html
				. '<span class="' . esc_attr( $label_cls ) . '">' . esc_html( $preview_label ) . '</span>'
				. '</button>'
				. $diag_note
				. '</section>';
		}

		$visible = BlockSupport::text_attribute( $attributes, 'label', __( 'پخش الان', 'music-wave-core' ) );

		return '<section ' . BlockSupport::wrapper_attributes( 'mw-preview-player mw-preview-player--' . $action_mode ) . '>'
			. '<h2 class="screen-reader-text">' . esc_html__( 'پخش انتشار', 'music-wave-core' ) . '</h2>'
			. '<button class="' . esc_attr( implode( ' ', $btn_classes ) ) . '" type="button"'
			. ' data-mw-release-id="' . esc_attr( (string) $release_id ) . '"'
			. ' data-mw-play-mode="single"'
			. ' aria-pressed="false" aria-label="' . esc_attr( $visible ) . '">'
			. $icon_html
			. '<span class="' . esc_attr( $label_cls ) . '">' . esc_html( $visible ) . '</span>'
			. '</button></section>';
	}

	/**
	 * Render `music-wave/download-button` (`دکمه دانلود`).
	 *
	 * Preserves 100% of signed-token delivery (`music-wave-download` REST flow,
	 * per-file `mw-secure-play-button`, no hidden duplicate `<audio>` tags, zero
	 * raw storage URL exposure) while unifying button styling, custom SVG icons,
	 * and self-contained Site/Block Editor live previews.
	 *
	 * @param array<string, mixed> $attributes     Block attributes.
	 * @param object|null          $render_context Active WP_Block context.
	 */
	public function render_download_button( array $attributes, $render_context = null ): string {
		$release_id = $this->context->release_id( $attributes, $render_context );
		$is_editor  = BlockSupport::is_editor_context();

		$mode          = ActionButtonStyle::resolve( $attributes, ActionButtonStyle::MODE_BUTTON );
		$variant       = ActionButtonStyle::resolve_variant( $attributes, ActionButtonStyle::VARIANT_ACCENT );
		$size          = ActionButtonStyle::resolve_size( $attributes, ActionButtonStyle::SIZE_MD );
		$shape         = ActionButtonStyle::resolve_shape( $attributes );
		$show_lbl      = ActionButtonStyle::shows_label( $mode );
		$icon_px       = 'sm' === $size ? 15 : ( 'lg' === $size ? 20 : 17 );
		$icon_svg      = ActionButtonStyle::render_icon( $attributes, 'download', $icon_px );
		$play_icon_svg = ActionButtonStyle::render_icon( $attributes, 'play-filled', $icon_px, 'playIcon' );
		$btn_cls       = ActionButtonStyle::button_classes(
			$attributes,
			'wp-element-button mw-download-button',
			ActionButtonStyle::MODE_BUTTON,
			ActionButtonStyle::VARIANT_ACCENT
		);
		$play_variant  = ActionButtonStyle::VARIANT_ACCENT === $variant
			? ActionButtonStyle::VARIANT_SURFACE
			: $variant;
		$play_btn_cls  = ActionButtonStyle::button_classes(
			array(
				'buttonStyle' => $mode,
				'variant'     => $play_variant,
				'size'        => $size,
				'shape'       => $shape,
			),
			'mw-secure-play-button',
			ActionButtonStyle::MODE_BUTTON,
			$play_variant
		);
		$quality_cls   = implode(
			' ',
			array(
				'mw-download-quality',
				'mw-download-quality--size-' . $size,
				'mw-download-quality--shape-' . $shape,
				'mw-download-quality--variant-' . $variant,
			)
		);
		$dl_label      = BlockSupport::text_attribute( $attributes, 'downloadLabel', __( 'دانلود ایمن', 'music-wave-core' ) );

		$row_options = array(
			'show_quality'   => BlockSupport::bool_attribute( $attributes, 'showQuality', true ),
			'show_stream'    => BlockSupport::bool_attribute( $attributes, 'showStream', true ),
			'download_label' => $dl_label,
			'play_label'     => isset( $attributes['playLabel'] ) && is_scalar( $attributes['playLabel'] ) ? sanitize_text_field( (string) $attributes['playLabel'] ) : '',
			'button_class'   => $btn_cls,
			'play_class'     => $play_btn_cls,
			'quality_class'  => $quality_cls,
			'icon_svg'       => $icon_svg,
			'play_icon_svg'  => $play_icon_svg,
			'show_label'     => $show_lbl,
		);

		if ( $release_id <= 0 ) {
			if ( ! $is_editor ) {
				return '';
			}
			$preview_files = $this->editor_fallback_download_files( 0 );
			$diag_note     = \ManaCore\MusicWave\Core\Blocks\Concerns\EditorDiagnosticNotice::render(
				__( 'هیچ انتشاری در سایت یافت نشد — پس از ثبت انتشار و فایل صوتی فعال می‌شود.', 'music-wave-core' ),
				! empty( $attributes['compact'] )
			);
			return ! empty( $attributes['compact'] )
				? $this->download_inline_markup( 0, $preview_files, $row_options, true, $diag_note )
				: $this->download_panel_markup( 0, $preview_files, $row_options, $attributes, $diag_note );
		}

		$access_allowed = $this->context->is_access_allowed( $release_id );
		if ( ! $access_allowed && ! $is_editor ) {
			return $this->download_sign_in_markup( $release_id, $attributes );
		}

		$files     = $this->resolve_download_files( $release_id, $is_editor );
		$diag_note = '';
		if ( empty( $files ) ) {
			if ( ! $is_editor ) {
				return '';
			}
			$files     = $this->editor_fallback_download_files( $release_id );
			$diag_note = \ManaCore\MusicWave\Core\Blocks\Concerns\EditorDiagnosticNotice::render(
				/* translators: %s: release title. */
				sprintf( __( 'برای «%s» هنوز فایل دانلودی ثبت نشده است. در صفحه ویرایش انتشار فایل اضافه کنید (در فرانت‌اند تا افزودن فایل نمایش داده نمی‌شود).', 'music-wave-core' ), get_the_title( $release_id ) ),
				! empty( $attributes['compact'] )
			);
		} else {
			$this->enqueue_download_runtime();
			if ( $is_editor && ! $access_allowed ) {
				$diag_note = \ManaCore\MusicWave\Core\Blocks\Concerns\EditorDiagnosticNotice::render(
					/* translators: %s: release title. */
					sprintf( __( 'دسترسی «%s» محدود به اشتراک ویژه یا خرید است — فایل‌های واقعی آن برای پیش‌نمایش نمایش داده شده‌اند.', 'music-wave-core' ), get_the_title( $release_id ) ),
					! empty( $attributes['compact'] )
				);
			}
		}

		if ( ! empty( $attributes['compact'] ) ) {
			return $this->download_inline_markup( $release_id, $files, $row_options, true, $diag_note );
		}

		return $this->download_panel_markup( $release_id, $files, $row_options, $attributes, $diag_note );
	}

	/**
	 * Resolve downloadable file groups for a release, falling back cleanly to child collection tracks
	 * when the release is an album/EP whose audio files live on its child track posts.
	 *
	 * @param int  $release_id Release post ID.
	 * @param bool $is_editor  Whether rendering inside Editor context.
	 * @return array<string, array<string, mixed>>
	 */
	private function resolve_download_files( int $release_id, bool $is_editor = false ): array {
		$catalog = new ReleaseDownloadCatalog( $this->context->repository() );
		$assets  = $catalog->assets( $release_id );
		if ( ! empty( $assets ) ) {
			$grouped = ReleaseDownloadCatalog::group_files( $assets, true );
			if ( ! empty( $grouped ) ) {
				return $grouped;
			}
		}

		$child_files = array();
		foreach ( $this->collection_entries( $release_id, $is_editor ) as $entry ) {
			$track_id = (int) $entry['post']->ID;
			if ( $track_id <= 0 || ( ! $is_editor && ! $this->context->is_access_allowed( $track_id ) ) ) {
				continue;
			}
			$track_assets = $catalog->assets( $track_id );
			if ( empty( $track_assets ) ) {
				continue;
			}
			$track_groups = ReleaseDownloadCatalog::group_files( $track_assets, true );
			$track_title  = trim( (string) get_the_title( $track_id ) );
			foreach ( $track_groups as $key => $group ) {
				$group['release_id']                   = $track_id;
				$group['label']                        = '' !== $track_title ? $track_title : (string) $group['label'];
				$child_files[ $track_id . ':' . $key ] = $group;
			}
		}

		return $child_files;
	}

	/**
	 * Project real release/track titles into the canonical `$files` structure when no audio files
	 * have been uploaded yet in Editor context, keeping 100% single-renderer parity with Frontend.
	 *
	 * @param int $release_id Release post ID (`0` when catalog has no releases yet).
	 * @return array<string, array<string, mixed>>
	 */
	private function editor_fallback_download_files( int $release_id ): array {
		$sample_qualities = array(
			array(
				'key'        => 'mp3_320',
				'label'      => 'MP3 · 320 kbps',
				'streamable' => true,
			),
			array(
				'key'        => 'mp3_128',
				'label'      => 'MP3 · 128 kbps',
				'streamable' => true,
			),
			array(
				'key'        => 'flac',
				'label'      => 'FLAC · Lossless',
				'streamable' => false,
			),
		);

		if ( $release_id > 0 ) {
			$entries = $this->collection_entries( $release_id, true );
			if ( ! empty( $entries ) ) {
				$rows = array();
				foreach ( array_slice( $entries, 0, 4 ) as $idx => $entry ) {
					$title                   = trim( (string) get_the_title( $entry['post']->ID ) );
					$rows[ 'track-' . $idx ] = array(
						'release_id' => (int) $entry['post']->ID,
						'label'      => '' !== $title ? $title : __( 'فایل صوتی قطعه', 'music-wave-core' ),
						'qualities'  => $sample_qualities,
					);
				}
				return $rows;
			}
		}

		$file_title = $release_id > 0 ? sanitize_text_field( (string) get_the_title( $release_id ) ) : '';
		$file_title = '' !== $file_title ? $file_title : __( 'فایل اصلی انتشار', 'music-wave-core' );

		return array(
			'primary' => array(
				'release_id' => $release_id,
				'label'      => $file_title,
				'qualities'  => $sample_qualities,
			),
		);
	}

	/**
	 * Enqueue the signed-token download runtime once per response.
	 */
	private function enqueue_download_runtime(): void {
		if ( ! function_exists( 'wp_enqueue_script' ) || ( function_exists( 'wp_script_is' ) && \wp_script_is( 'music-wave-download', 'enqueued' ) ) ) {
			return;
		}
		wp_enqueue_script( 'music-wave-download', MUSIC_WAVE_CORE_URL . 'assets/download.js', array( 'wp-api-fetch' ), MUSIC_WAVE_CORE_VERSION, true );
		wp_localize_script(
			'music-wave-download',
			'musicWaveDownload',
			array(
				'restUrl'      => esc_url_raw( rest_url() ),
				'restNonce'    => wp_create_nonce( 'wp_rest' ),
				'errorMessage' => __( 'دانلود شروع نشد.', 'music-wave-core' ),
				'streamError'  => __( 'پخش امن شروع نشد.', 'music-wave-core' ),
				'sessionError' => __( 'جلسه شما تمام شده است. صفحه را تازه کنید یا دوباره وارد شوید.', 'music-wave-core' ),
				'playLabel'    => __( 'پخش', 'music-wave-core' ),
				'pauseLabel'   => __( 'مکث', 'music-wave-core' ),
			)
		);
	}

	/**
	 * Render the standalone "download this release" panel.
	 *
	 * @param int                                 $release_id Release post ID.
	 * @param array<string, array<string, mixed>> $files      Downloadable files.
	 * @param array<string, mixed>                $options    Resolved row options.
	 * @param array<string, mixed>                $attributes Block attributes.
	 */
	private function download_panel_markup( int $release_id, array $files, array $options, array $attributes, string $editor_notice = '' ): string {
		$heading_html     = BlockSupport::bool_attribute( $attributes, 'showHeading', true )
			? '<strong>' . esc_html( BlockSupport::text_attribute( $attributes, 'heading', __( 'دانلود این انتشار', 'music-wave-core' ) ) ) . '</strong>'
			: '';
		$description_html = BlockSupport::bool_attribute( $attributes, 'showDescription', true )
			? '<span>' . esc_html( BlockSupport::text_attribute( $attributes, 'description', __( 'یک فایل صوتی موجود را پخش کنید یا کیفیت دلخواه خود را دانلود کنید.', 'music-wave-core' ) ) ) . '</span>'
			: '';
		$heading          = '' !== $heading_html || '' !== $description_html
			? '<div class="mw-download-action__heading">' . $heading_html . $description_html . '</div>'
			: '';

		$protected = function_exists( 'has_filter' ) && (bool) \has_filter( 'music_wave_download_provider' );
		$message   = $protected
			? __( 'حفاظت‌شده توسط MusicWave VIP: فایل‌ها از پوشه‌ای حفاظت‌شده و از طریق پیوندهای امضاشده و زمان‌دار پخش می‌شوند. نشانی مستقیم فایل هرگز نمایش داده نمی‌شود.', 'music-wave-core' )
			: __( 'از طریق پیوندهای امضاشده و منقضی‌شده تحویل داده می‌شود. نشانی مستقیم فایل هرگز در معرض دید قرار نمی‌گیرد.', 'music-wave-core' );
		$badge     = $protected ? '<span class="mw-download-action__security-badge">' . esc_html__( 'VIP حفاظت‌شده', 'music-wave-core' ) . '</span>' : '';
		$security  = '<p class="mw-download-action__security"><svg class="mw-download-action__security-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg><span>' . esc_html( $message ) . '</span>' . $badge . '</p>';

		$panel_extra  = count( $files ) === 1 ? ' mw-download-action--single' : '';
		$panel_extra .= ' mw-download-action--unified';
		$unified_head = '<div class="mw-download-action__table-header" aria-hidden="true"><span>' . esc_html__( 'فایل', 'music-wave-core' ) . '</span><span>' . esc_html__( 'کیفیت', 'music-wave-core' ) . '</span><span>' . esc_html__( 'اقدام', 'music-wave-core' ) . '</span></div>';

		return '<aside ' . BlockSupport::wrapper_attributes( 'mw-download-action mw-download-action--panel' . $panel_extra ) . '>'
			. $heading
			. $editor_notice
			. $unified_head
			. $this->download_file_rows( $release_id, $files, $options )
			. '<span class="mw-download-status" role="status" aria-live="polite"></span>'
			. $security
			. '</aside>';
	}

	/**
	 * Render file rows inside `download_panel_markup()`.
	 *
	 * @param int                                 $release_id Release post ID.
	 * @param array<string, array<string, mixed>> $files      Downloadable files.
	 * @param array<string, mixed>                $options    Resolved row options.
	 */
	private function download_file_rows( int $release_id, array $files, array $options ): string {
		$show_quality   = ! empty( $options['show_quality'] );
		$show_stream    = ! empty( $options['show_stream'] );
		$download_label = isset( $options['download_label'] ) && '' !== (string) $options['download_label'] ? (string) $options['download_label'] : __( 'دانلود ایمن', 'music-wave-core' );
		$play_label     = isset( $options['play_label'] ) && '' !== (string) $options['play_label'] ? (string) $options['play_label'] : __( 'پخش', 'music-wave-core' );
		$quality_cls    = isset( $options['quality_class'] ) ? (string) $options['quality_class'] : 'mw-download-quality';

		$rows = array();
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) || empty( $file['qualities'] ) || ! is_array( $file['qualities'] ) ) {
				continue;
			}
			$qualities = $this->download_quality_options( $file['qualities'] );
			if ( '' === $qualities['html'] ) {
				continue;
			}

			$target_release_id = isset( $file['release_id'] ) && (int) $file['release_id'] > 0
				? (int) $file['release_id']
				: $release_id;
			$default_quality   = $show_quality ? '' : ' data-download-quality="' . esc_attr( $qualities['first_key'] ) . '"';
			/* translators: %s: download file label. */
			$quality_aria = sprintf( __( 'انتخاب کیفیت برای %s', 'music-wave-core' ), (string) $file['label'] );
			$quality_col  = $show_quality
				? '<div class="mw-download-action__quality"><select class="' . esc_attr( $quality_cls ) . '" aria-label="' . esc_attr( $quality_aria ) . '">' . $qualities['html'] . '</select></div>'
				: '';

			$play_btn = $show_stream && $qualities['has_streamable']
				? $this->secure_play_button( $target_release_id, $play_label, $default_quality, ! $qualities['first_streamable'], $options )
				: '';
			$row_cls  = 'mw-download-file-row' . ( $show_quality ? '' : ' mw-download-file-row--no-quality' );

			$rows[] = '<div class="' . esc_attr( $row_cls ) . '" role="listitem">'
				. '<div class="mw-download-file-row__title"><span class="mw-download-file-row__icon mw-download-file-row__icon--wave" aria-hidden="true">' . ActionButtonStyle::icon_svg( 'waveform', 16 ) . '</span><strong>' . esc_html( (string) $file['label'] ) . '</strong></div>'
				. $quality_col
				. '<div class="mw-download-file-row__actions">'
				. $play_btn
				. $this->secure_download_button( $target_release_id, $download_label, $default_quality, $options )
				. '</div></div>';
		}

		return empty( $rows ) ? '' : '<div class="mw-download-file-list" role="list">' . implode( '', $rows ) . '</div>';
	}

	/**
	 * Build `<option>` list and streamability flags for one file's qualities.
	 *
	 * @param array<int, mixed> $qualities Quality records.
	 * @return array{html: string, first_key: string, has_streamable: bool, first_streamable: bool}
	 */
	private function download_quality_options( array $qualities ): array {
		$items            = array();
		$first_key        = '';
		$has_streamable   = false;
		$first_streamable = false;

		foreach ( $qualities as $quality ) {
			if ( ! is_array( $quality ) || empty( $quality['key'] ) || empty( $quality['label'] ) ) {
				continue;
			}
			$key        = sanitize_key( (string) $quality['key'] );
			$label      = sanitize_text_field( (string) $quality['label'] );
			$streamable = ! empty( $quality['streamable'] );
			if ( '' === $first_key ) {
				$first_key        = $key;
				$first_streamable = $streamable;
			}
			if ( $streamable ) {
				$has_streamable = true;
			}
			$items[] = '<option value="' . esc_attr( $key ) . '" data-streamable="' . ( $streamable ? '1' : '0' ) . '">' . esc_html( $label ) . '</option>';
		}

		return array(
			'html'             => implode( '', $items ),
			'first_key'        => $first_key,
			'has_streamable'   => $has_streamable,
			'first_streamable' => $first_streamable,
		);
	}

	/**
	 * Render the compact inline download group embedded in track rows.
	 *
	 * @param int                                 $release_id Release post ID.
	 * @param array<string, array<string, mixed>> $files      Downloadable files.
	 * @param array<string, mixed>                $options    Resolved row options.
	 */
	private function download_inline_markup( int $release_id, array $files, array $options, bool $standalone = false, string $editor_notice = '' ): string {
		$show_quality   = ! empty( $options['show_quality'] );
		$show_stream    = ! empty( $options['show_stream'] );
		$download_label = isset( $options['download_label'] ) && '' !== (string) $options['download_label'] ? (string) $options['download_label'] : __( 'دانلود ایمن', 'music-wave-core' );
		$play_label     = isset( $options['play_label'] ) && '' !== (string) $options['play_label'] ? (string) $options['play_label'] : __( 'پخش', 'music-wave-core' );
		$quality_cls    = isset( $options['quality_class'] ) ? (string) $options['quality_class'] : 'mw-download-quality';
		$multi_files    = count( $files ) > 1;

		$groups = array();
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) || empty( $file['qualities'] ) || ! is_array( $file['qualities'] ) ) {
				continue;
			}
			$qualities = $this->download_quality_options( $file['qualities'] );
			if ( '' === $qualities['html'] ) {
				continue;
			}
			$target_release_id = isset( $file['release_id'] ) && (int) $file['release_id'] > 0
				? (int) $file['release_id']
				: $release_id;
			$default_quality   = $show_quality ? '' : ' data-download-quality="' . esc_attr( $qualities['first_key'] ) . '"';
			/* translators: %s: download file label. */
			$select_aria = sprintf( __( 'انتخاب کیفیت برای %s', 'music-wave-core' ), (string) $file['label'] );
			$file_label  = $multi_files && '' !== trim( (string) $file['label'] )
				? '<span class="mw-download-inline__label" dir="auto">' . esc_html( (string) $file['label'] ) . '</span>'
				: '';
			$select      = $show_quality
				? '<select class="' . esc_attr( $quality_cls ) . '" aria-label="' . esc_attr( $select_aria ) . '">' . $qualities['html'] . '</select>'
				: '';
			$play        = $show_stream && $qualities['has_streamable']
				? $this->secure_play_button( $target_release_id, $play_label, $default_quality, ! $qualities['first_streamable'], $options )
				: '';
			$row_cls     = $multi_files ? 'mw-download-inline mw-download-inline--stacked' : 'mw-download-inline';
			$groups[]    = '<div class="' . esc_attr( $row_cls ) . '">' . $file_label . '<div class="mw-download-inline__controls">' . $select . $play . $this->secure_download_button( $target_release_id, $download_label, $default_quality, $options ) . '</div></div>';
			if ( ! $standalone && ! $show_quality && ! $show_stream ) {
				break;
			}
		}

		if ( empty( $groups ) ) {
			return '';
		}

		$extra_cls = $multi_files ? ' mw-download-action--multi' : '';
		$open_tag  = $standalone
			? '<div ' . BlockSupport::wrapper_attributes( 'mw-download-action mw-download-action--inline' . $extra_cls ) . '>'
			: '<div class="mw-download-action mw-download-action--inline' . $extra_cls . '">';

		return $open_tag . '<div class="mw-download-inline-list" role="group" aria-label="' . esc_attr__( 'اقدامات دانلود', 'music-wave-core' ) . '">' . implode( '', $groups ) . '</div>' . $editor_notice . '<span class="mw-download-status" role="status" aria-live="polite"></span></div>';
	}

	/**
	 * Render the opt-in sign-in prompt for visitors denied this release.
	 *
	 * @param int                  $release_id Release post ID.
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function download_sign_in_markup( int $release_id, array $attributes ): string {
		$login_label = BlockSupport::text_attribute( $attributes, 'loginLabel', '' );
		if ( '' === $login_label || is_user_logged_in() ) {
			return '';
		}
		$catalog = new ReleaseDownloadCatalog( $this->context->repository() );
		if ( empty( $catalog->assets( $release_id ) ) ) {
			return '';
		}
		$heading = BlockSupport::bool_attribute( $attributes, 'showHeading', true )
			? '<strong>' . esc_html( BlockSupport::text_attribute( $attributes, 'heading', __( 'دانلود این انتشار', 'music-wave-core' ) ) ) . '</strong>'
			: '';
		$icon    = ActionButtonStyle::icon_svg( 'shield-check', 16 );

		return '<aside ' . BlockSupport::wrapper_attributes( 'mw-download-action mw-download-action--signin' ) . '>'
			. ( '' === $heading ? '' : '<div class="mw-download-action__heading">' . $heading . '</div>' )
			. '<div class="mw-download-action__signin">' . $icon
			. '<a class="wp-element-button mw-download-action__signin-link" href="' . esc_url( AuthPolicy::login_url( BlockSupport::current_url() ) ) . '">' . esc_html( $login_label ) . '</a>'
			. '</div></aside>';
	}

	/**
	 * Build one secure playback button inside the download panel, sharing the
	 * exact `ActionButtonStyle` size, shape, mode, and custom icon controls.
	 *
	 * @param int                  $release_id        Release post ID.
	 * @param string               $play_label        Visible and accessible label.
	 * @param string               $quality_attribute Optional `data-download-quality` attribute.
	 * @param bool                 $disabled          Initial disabled state when non-streamable quality selected.
	 * @param array<string, mixed> $options           Synchronized button appearance options.
	 */
	private function secure_play_button( int $release_id, string $play_label, string $quality_attribute, bool $disabled, array $options = array() ): string {
		$btn_cls  = isset( $options['play_class'] ) ? (string) $options['play_class'] : 'mw-secure-play-button mw-action-button mw-action-button--button mw-action-button--surface mw-action-button--md mw-action-button--pill';
		$icon_svg = isset( $options['play_icon_svg'] ) ? (string) $options['play_icon_svg'] : ActionButtonStyle::icon_svg( 'play-filled', 17 );
		$show_lbl = ! isset( $options['show_label'] ) || ! empty( $options['show_label'] );
		$lbl_cls  = $show_lbl ? 'mw-secure-play-button__label' : 'mw-secure-play-button__label screen-reader-text';

		return '<button class="' . esc_attr( $btn_cls ) . '" type="button" aria-label="' . esc_attr( $play_label ) . '"'
			. ' data-release-id="' . esc_attr( (string) $release_id ) . '"'
			. $quality_attribute
			. ' data-play-label="' . esc_attr( $play_label ) . '"'
			. ' data-pause-label="' . esc_attr( __( 'مکث', 'music-wave-core' ) ) . '"'
			. ( $disabled ? ' disabled' : '' )
			. '><span class="mw-secure-play-button__icon" aria-hidden="true">' . $icon_svg . '</span>'
			. '<span class="' . esc_attr( $lbl_cls ) . '">' . esc_html( $play_label ) . '</span></button>';
	}

	/**
	 * Build one unified secure download button powered by `ActionButtonStyle`.
	 *
	 * @param int                  $release_id        Release post ID.
	 * @param string               $download_label    Visible and accessible label.
	 * @param string               $quality_attribute Pre-built `data-download-quality` attribute.
	 * @param array<string, mixed> $options           Button appearance options.
	 */
	private function secure_download_button( int $release_id, string $download_label, string $quality_attribute, array $options ): string {
		$btn_cls  = isset( $options['button_class'] ) ? (string) $options['button_class'] : 'wp-element-button mw-download-button mw-action-button mw-action-button--button mw-action-button--accent mw-action-button--md mw-action-button--pill';
		$icon_svg = isset( $options['icon_svg'] ) ? (string) $options['icon_svg'] : ActionButtonStyle::icon_svg( 'download', 17 );
		$show_lbl = ! isset( $options['show_label'] ) || ! empty( $options['show_label'] );
		$lbl_cls  = $show_lbl ? 'mw-download-button__label' : 'mw-download-button__label screen-reader-text';

		return '<button class="' . esc_attr( $btn_cls ) . '" type="button" aria-label="' . esc_attr( $download_label ) . '"'
			. ' data-release-id="' . esc_attr( (string) $release_id ) . '"'
			. $quality_attribute
			. '><span class="mw-download-button__icon" aria-hidden="true">' . $icon_svg . '</span>'
			. '<span class="' . esc_attr( $lbl_cls ) . '">' . esc_html( $download_label ) . '</span></button>';
	}

	/**
	 * Normalize all `collection-list` attributes into one typed configuration array.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<string, mixed>
	 */
	private function resolve_tracklist_config( array $attributes ): array {
		$variation       = BlockSupport::style_variation( $attributes, array( 'tracklist' ) );
		$density         = BlockSupport::key_attribute( $attributes, 'density', array( 'comfortable', 'compact', 'cards' ), 'comfortable' );
		$play_icon_html  = ActionButtonStyle::play_toggle_icon( 'play-filled', 16, $attributes );
		$dl_icon_html    = ActionButtonStyle::render_icon( $attributes, 'download', 16, 'downloadIcon' );
		$action_style    = BlockSupport::key_attribute( $attributes, 'actionStyle', array( 'icon', 'button' ), 'icon' );
		$actions_vis     = BlockSupport::key_attribute( $attributes, 'actionsVisibility', array( 'always', 'hover' ), 'always' );
		$show_position   = BlockSupport::bool_attribute( $attributes, 'showPosition', true );
		$show_artwork    = BlockSupport::bool_attribute( $attributes, 'showArtwork', true );
		$show_artist     = BlockSupport::bool_attribute( $attributes, 'showArtist', true );
		$show_bpm        = BlockSupport::bool_attribute( $attributes, 'showBpm', false );
		$show_duration   = BlockSupport::bool_attribute( $attributes, 'showDuration', true );
		$show_preview    = BlockSupport::bool_attribute( $attributes, 'showPreview', true );
		$show_download   = BlockSupport::bool_attribute( $attributes, 'showDownload', true );
		$show_quality    = BlockSupport::bool_attribute( $attributes, 'showQuality', false );
		$show_dl_quality = ! $show_quality && BlockSupport::bool_attribute( $attributes, 'showDownloadQuality', true );
		$has_row_actions = $show_preview || $show_download;

		return array(
			'variation'             => $variation,
			'density'               => $density,
			'play_icon_html'        => $play_icon_html,
			'download_icon_html'    => $dl_icon_html,
			'action_style'          => $action_style,
			'actions_vis'           => $actions_vis,
			'show_header'           => BlockSupport::bool_attribute( $attributes, 'showHeader', true ),
			'show_position'         => $show_position,
			'show_artwork'          => $show_artwork,
			'show_artist'           => $show_artist,
			'show_bpm'              => $show_bpm,
			'show_quality'          => $show_quality,
			'show_duration'         => $show_duration,
			'show_total'            => BlockSupport::bool_attribute( $attributes, 'showTotalDuration', true ),
			'show_preview'          => $show_preview,
			'show_download'         => $show_download,
			'show_download_quality' => $show_dl_quality,
			'has_row_actions'       => $has_row_actions,
			'group_by_disc'         => BlockSupport::bool_attribute( $attributes, 'groupByDisc', false ),
			'heading'               => BlockSupport::optional_text_attribute( $attributes, 'heading', __( 'فهرست قطعات', 'music-wave-core' ), 'showHeading' ),
		);
	}

	/**
	 * Build root CSS classes for `<section class="mw-collection-list ...">`.
	 *
	 * @param array<string, mixed> $config Normalized tracklist config.
	 */
	private function collection_root_classes( array $config ): string {
		$classes = array(
			'mw-collection-list',
			'mw-collection-list--density-' . $config['density'],
			'mw-collection-list--play-hover-number',
			'mw-collection-list--actions-' . $config['action_style'],
			'mw-collection-list--vis-' . $config['actions_vis'],
		);
		if ( '' !== $config['variation'] ) {
			$classes[] = 'mw-collection-list--' . $config['variation'];
		}
		if ( ! $config['has_row_actions'] ) {
			$classes[] = 'mw-collection-list--no-actions';
		}
		if ( ! $config['show_position'] ) {
			$classes[] = 'mw-collection-list--no-lead';
		}
		if ( $config['show_artist'] ) {
			$classes[] = 'mw-collection-list--has-artist';
		}
		if ( $config['show_bpm'] ) {
			$classes[] = 'mw-collection-list--has-bpm';
		}
		if ( $config['show_quality'] ) {
			$classes[] = 'mw-collection-list--has-quality';
		}
		if ( $config['show_duration'] ) {
			$classes[] = 'mw-collection-list--has-duration';
		}
		return implode( ' ', $classes );
	}

	/**
	 * Compute exact CSS custom properties (`--mw-track-cols`, `--mw-track-cols-md`, `--mw-track-cols-sm`)
	 * so header cells and track cells align 1:1 regardless of which columns are toggled!
	 *
	 * The `md` text tracks keep lower minimums than the desktop set on purpose: a
	 * labeled action cluster (`actionStyle: button`) is ~19rem wide, and the md
	 * geometry has to hold it without overflowing down to the 42rem card layout.
	 *
	 * @param array<string, mixed> $config Normalized config.
	 */
	private function collection_style_vars( array $config ): string {
		$full = array();
		$md   = array();
		$sm   = array();

		if ( $config['show_position'] ) {
			$full[] = '2.5rem';
			$md[]   = '2.5rem';
			$sm[]   = '2.25rem';
		}

		$full[] = 'minmax(11rem, 1.45fr)';
		$md[]   = 'minmax(7.5rem, 1.35fr)';
		$sm[]   = 'minmax(0, 1fr)';

		if ( $config['show_artist'] ) {
			$full[] = 'minmax(7.5rem, 0.72fr)';
			$md[]   = 'minmax(5rem, 0.6fr)';
		}
		if ( $config['show_bpm'] ) {
			$full[] = '4.5rem';
		}
		if ( $config['show_quality'] ) {
			$full[] = '6.75rem';
		}
		if ( $config['show_duration'] ) {
			$full[] = '3.75rem';
			$md[]   = '3.5rem';
			$sm[]   = '3.1rem';
		}
		if ( $config['has_row_actions'] ) {
			$full[] = 'max-content';
			$md[]   = 'max-content';
			$sm[]   = 'max-content';
		}

		return '--mw-track-cols:' . implode( ' ', $full )
			. ';--mw-track-cols-md:' . implode( ' ', $md )
			. ';--mw-track-cols-sm:' . implode( ' ', $sm ) . ';';
	}

	/**
	 * Render `<header class="mw-collection-list__header">` with optional track count + total runtime.
	 *
	 * @param array<string, mixed> $config        Normalized config.
	 * @param int                  $track_count   Number of tracks.
	 * @param int                  $total_seconds Combined duration in seconds.
	 */
	private function section_heading_markup( array $config, int $track_count, int $total_seconds ): string {
		$heading = (string) $config['heading'];
		if ( '' === $heading ) {
			return '';
		}

		$meta_pills = array();
		if ( $track_count > 0 ) {
			/* translators: %s: localized track count. */
			$meta_pills[] = '<span class="mw-collection-list__count">' . esc_html( sprintf( __( '%s قطعه', 'music-wave-core' ), number_format_i18n( $track_count ) ) ) . '</span>';
		}
		if ( $config['show_total'] && $total_seconds > 0 ) {
			$formatted = $this->format_seconds( $total_seconds );
			if ( '' !== $formatted ) {
				$meta_pills[] = '<span class="mw-collection-list__total">' . esc_html( $formatted ) . '</span>';
			}
		}

		$summary = ! empty( $meta_pills )
			? '<div class="mw-collection-list__summary">' . implode( '', $meta_pills ) . '</div>'
			: '';

		return '<header class="mw-collection-list__header"><h2 class="mw-collection-list__heading">' . esc_html( $heading ) . '</h2>' . $summary . '</header>';
	}

	/**
	 * Render `<div class="mw-collection-list__table-header">` with 1:1 cells matching every active column.
	 *
	 * @param array<string, mixed> $config Normalized config.
	 */
	private function tracklist_header_markup( array $config ): string {
		if ( ! $config['show_header'] || 'cards' === $config['density'] ) {
			return '';
		}

		$cells = array();
		if ( $config['show_position'] ) {
			$cells[] = '<span class="mw-collection-list__col mw-collection-list__col--lead">#</span>';
		}
		$cells[] = '<span class="mw-collection-list__col mw-collection-list__col--title">' . esc_html__( 'عنوان قطعه', 'music-wave-core' ) . '</span>';
		if ( $config['show_artist'] ) {
			$cells[] = '<span class="mw-collection-list__col mw-collection-list__col--artist">' . esc_html__( 'هنرمند', 'music-wave-core' ) . '</span>';
		}
		if ( $config['show_bpm'] ) {
			$cells[] = '<span class="mw-collection-list__col mw-collection-list__col--bpm">' . esc_html__( 'تمپو', 'music-wave-core' ) . '</span>';
		}
		if ( $config['show_quality'] ) {
			$cells[] = '<span class="mw-collection-list__col mw-collection-list__col--quality">' . esc_html__( 'کیفیت', 'music-wave-core' ) . '</span>';
		}
		if ( $config['show_duration'] ) {
			$cells[] = '<span class="mw-collection-list__col mw-collection-list__col--duration">' . esc_html__( 'مدت', 'music-wave-core' ) . '</span>';
		}
		if ( $config['has_row_actions'] ) {
			$cells[] = '<span class="mw-collection-list__col mw-collection-list__col--actions">' . esc_html__( 'اقدامات', 'music-wave-core' ) . '</span>';
		}

		return '<div class="mw-collection-list__table-header" aria-hidden="true">' . implode( '', $cells ) . '</div>';
	}

	/**
	 * Render a single track row for a real published child `mw_release` post.
	 *
	 * @param WP_Post              $child    Child release post.
	 * @param int                  $fallback Fallback 1-based position.
	 * @param array<string, mixed> $config   Normalized config.
	 */
	private function render_real_track_row( WP_Post $child, int $fallback, array $config ): string {
		$is_editor = BlockSupport::is_editor_context();
		if ( ! $is_editor && 'publish' !== $child->post_status ) {
			return '';
		}
		$allowed_child = $this->context->is_access_allowed( $child->ID );

		$position = $fallback > 0
			? $fallback
			: max( 1, absint( $this->context->repository()->get( $child->ID, 'mw_track_number' ) ) );
		$title    = (string) get_the_title( $child->ID );
		$link     = (string) get_permalink( $child->ID );

		$artwork_html = '';
		if ( $config['show_artwork'] ) {
			$artwork      = get_the_post_thumbnail(
				$child->ID,
				'thumbnail',
				array(
					'class'   => 'mw-collection-list__artwork',
					'loading' => 'lazy',
				)
			);
			$artwork_html = '' !== $artwork
				? $artwork
				: '<span class="mw-collection-list__artwork mw-collection-list__artwork--fallback" aria-hidden="true">' . ActionButtonStyle::icon_svg( 'waveform', 16 ) . '</span>';
		}

		$artist_text = '';
		$artist_html = '';
		$artists     = wp_get_post_terms( $child->ID, 'mw_artist', array( 'fields' => 'all' ) );
		if ( ! is_wp_error( $artists ) && ! empty( $artists ) ) {
			$artist_html = $this->context->terms_markup( $artists, true );
			$names       = array();
			foreach ( $artists as $term ) {
				if ( $term instanceof \WP_Term ) {
					$names[] = $term->name;
				}
			}
			$artist_text = implode( '، ', $names );
		}

		$repo    = $this->context->repository();
		$raw_bpm = absint( $repo->get( $child->ID, 'mw_bpm' ) );
		$bpm     = $raw_bpm > 0 ? number_format_i18n( $raw_bpm ) : '—';

		$assets  = ( new ReleaseDownloadCatalog( $repo ) )->assets( $child->ID );
		$quality = '';
		if ( ! empty( $assets ) && isset( $assets[0]['label'] ) && is_string( $assets[0]['label'] ) ) {
			$quality = trim( $assets[0]['label'] );
		}
		if ( '' === $quality ) {
			$q_terms = wp_get_post_terms( $child->ID, 'mw_audio_quality', array( 'fields' => 'names' ) );
			if ( is_array( $q_terms ) && ! empty( $q_terms ) ) {
				$quality = trim( (string) reset( $q_terms ) );
			}
		}

		$seconds  = $this->track_duration_seconds( $child->ID );
		$duration = $seconds > 0 ? $this->format_seconds( $seconds ) : '';

		$preview_url   = $repo->get( $child->ID, 'mw_preview_url' );
		$valid_preview = TrackStreamPresenter::is_valid_preview_url( $preview_url );
		$can_stream    = $allowed_child && '' !== TrackStreamPresenter::streamable_quality( $child->ID, $repo );
		$is_playable   = $valid_preview || $can_stream;
		$thumb_url     = function_exists( 'get_the_post_thumbnail_url' ) ? get_the_post_thumbnail_url( $child->ID, 'thumbnail' ) : '';
		$limit         = absint( $repo->get( $child->ID, 'mw_preview_duration' ) );
		$limit         = $limit >= 10 && $limit <= 120 ? $limit : 30;

		$play_attrs = '';
		if ( $valid_preview ) {
			$play_attrs = ' data-preview-url="' . esc_url( (string) $preview_url ) . '"'
				. ' data-preview-title="' . esc_attr( $title ) . '"'
				. ' data-preview-artist="' . esc_attr( $artist_text ) . '"'
				. ' data-preview-image="' . esc_url( is_string( $thumb_url ) ? $thumb_url : '' ) . '"'
				. ' data-preview-link="' . esc_url( $link ) . '"'
				. ' data-preview-limit="' . esc_attr( (string) $limit ) . '"';
		} elseif ( $can_stream ) {
			$play_attrs = ' data-mw-release-id="' . esc_attr( (string) $child->ID ) . '"';
		}

		$download_key         = '';
		$download_url         = '';
		$quality_options_html = '';
		if ( $config['show_download'] && ! empty( $assets ) ) {
			if ( $allowed_child ) {
				$grouped_track_files = ReleaseDownloadCatalog::group_files( $assets, true );
				$flat_qualities      = array();
				foreach ( $grouped_track_files as $track_group ) {
					if ( ! empty( $track_group['qualities'] ) && is_array( $track_group['qualities'] ) ) {
						foreach ( $track_group['qualities'] as $q_entry ) {
							$flat_qualities[] = $q_entry;
						}
					}
				}
				$quality_meta         = $this->download_quality_options( $flat_qualities );
				$download_key         = $quality_meta['first_key'];
				$quality_options_html = $quality_meta['html'];
				if ( '' !== $download_key ) {
					$this->enqueue_download_runtime();
				}
			} else {
				$download_url = $link;
			}
		} elseif ( $is_editor && $config['show_download'] ) {
			$quality_meta         = $this->download_quality_options(
				array(
					array(
						'key'   => 'mp3-320',
						'label' => 'MP3 320 kbps',
					),
					array(
						'key'   => 'flac',
						'label' => 'FLAC 24-bit',
					),
				)
			);
			$quality_options_html = $quality_meta['html'];
		}

		return $this->assemble_track_row_markup(
			array(
				'release_id'           => $child->ID,
				'position'             => $position,
				'title'                => $title,
				'link'                 => $link,
				'artwork_html'         => $artwork_html,
				'artist_html'          => '' !== $artist_html ? $artist_html : esc_html( $artist_text ),
				'artist_plain'         => $artist_text,
				'bpm'                  => $bpm,
				'quality'              => $quality,
				'duration'             => $duration,
				'playable'             => $is_playable || $is_editor,
				'play_attrs'           => $play_attrs,
				'download_key'         => $download_key,
				'download_url'         => $download_url,
				'quality_options_html' => $quality_options_html,
				'is_sample'            => $is_editor && '' === $download_key && '' === $download_url,
			),
			$config
		);
	}

	/**
	 * Assemble the exact 1:1 grid-aligned `<li class="mw-collection-list__row">` DOM for both
	 * real release tracks and Site Editor sample tracks (100% DRY parity).
	 *
	 * @param array<string, mixed> $track  Normalized track payload.
	 * @param array<string, mixed> $config Normalized tracklist config.
	 */
	private function assemble_track_row_markup( array $track, array $config ): string {
		$is_icon_mode = 'icon' === $config['action_style'];
		$play_svg     = (string) $config['play_icon_html'];
		$dl_svg       = (string) $config['download_icon_html'];
		$equalizer    = '<span class="mw-equalizer" aria-hidden="true"><span></span><span></span><span></span></span>';
		$pos_num      = number_format_i18n( (int) $track['position'] );
		$title        = (string) $track['title'];
		/* translators: %s: track title. */
		$play_aria = sprintf( __( 'پخش %s', 'music-wave-core' ), $title );

		$cells = array();
		if ( $config['show_position'] ) {
			// Prevent duplicate play triggers on the same row: render `lead-play--hover`
			// ONLY when the trailing play action button (`showPreview`) is turned off.
			$lead_inner = ( $track['playable'] && ! $config['show_preview'] )
				? '<button type="button" class="mw-preview-button mw-collection-list__lead-play mw-collection-list__lead-play--hover" aria-pressed="false" aria-label="' . esc_attr( $play_aria ) . '"' . $track['play_attrs'] . '>'
					. '<span class="mw-collection-list__position">' . esc_html( $pos_num ) . '</span>'
					. '<span class="mw-collection-list__hover-icon" aria-hidden="true">' . $play_svg . '</span>'
					. $equalizer
					. '</button>'
				: '<span class="mw-collection-list__lead-slot"><span class="mw-collection-list__position">' . esc_html( $pos_num ) . '</span>' . $equalizer . '</span>';
			$cells[]    = '<div class="mw-collection-list__cell mw-collection-list__lead">' . $lead_inner . '</div>';
		}

		$title_link = '' !== (string) $track['link']
			? '<a class="mw-collection-list__link" href="' . esc_url( (string) $track['link'] ) . '"><span class="mw-collection-list__title" dir="auto">' . esc_html( $title ) . '</span></a>'
			: '<span class="mw-collection-list__link"><span class="mw-collection-list__title" dir="auto">' . esc_html( $title ) . '</span></span>';

		$meta_parts = array();
		if ( '' !== (string) $track['artist_plain'] ) {
			$meta_parts[] = (string) $track['artist_plain'];
		}
		if ( '' !== (string) $track['duration'] ) {
			$meta_parts[] = (string) $track['duration'];
		}
		$inline_artist = ! empty( $meta_parts )
			? '<span class="mw-collection-list__artist-inline" dir="auto">' . esc_html( implode( ' · ', $meta_parts ) ) . '</span>'
			: '';

		$cells[] = '<div class="mw-collection-list__cell mw-collection-list__main">'
			. (string) $track['artwork_html']
			. '<div class="mw-collection-list__text">' . $title_link . $inline_artist . '</div>'
			. '</div>';

		if ( $config['show_artist'] ) {
			$artist_val = '' !== (string) $track['artist_html'] ? (string) $track['artist_html'] : '—';
			$cells[]    = '<div class="mw-collection-list__cell mw-collection-list__artist" dir="auto">' . $artist_val . '</div>';
		}
		if ( $config['show_bpm'] ) {
			$cells[] = '<div class="mw-collection-list__cell mw-collection-list__bpm">' . esc_html( (string) $track['bpm'] ) . '</div>';
		}
		if ( $config['show_quality'] ) {
			$q_label = '' !== (string) $track['quality'] ? '<span class="mw-collection-list__badge">' . esc_html( (string) $track['quality'] ) . '</span>' : '<span class="mw-collection-list__muted">—</span>';
			$cells[] = '<div class="mw-collection-list__cell mw-collection-list__quality">' . $q_label . '</div>';
		}
		if ( $config['show_duration'] ) {
			$dur     = '' !== (string) $track['duration'] ? (string) $track['duration'] : '—';
			$cells[] = '<div class="mw-collection-list__cell mw-collection-list__duration">' . esc_html( $dur ) . '</div>';
		}

		if ( $config['has_row_actions'] ) {
			$actions   = array();
			$lbl_class = $is_icon_mode ? 'mw-collection-list__action-label screen-reader-text' : 'mw-collection-list__action-label';
			$act_mode  = $is_icon_mode ? 'icon' : 'button';

			if ( $config['show_preview'] && $track['playable'] ) {
				$play_lbl  = __( 'پخش', 'music-wave-core' );
				$actions[] = '<button type="button" class="mw-preview-button mw-collection-list__action mw-collection-list__action--play mw-collection-list__action--' . $act_mode . '" aria-pressed="false" aria-label="' . esc_attr( $play_aria ) . '"' . $track['play_attrs'] . '>'
					. '<span class="mw-collection-list__action-icon" aria-hidden="true">' . $play_svg . '</span>'
					. '<span class="' . esc_attr( $lbl_class ) . '">' . esc_html( $play_lbl ) . '</span>'
					. '</button>';
			}

			$quality_options_html = isset( $track['quality_options_html'] ) ? (string) $track['quality_options_html'] : '';
			/* translators: %s: track title. */
			$select_aria = sprintf( __( 'انتخاب کیفیت برای %s', 'music-wave-core' ), $title );

			if ( $config['show_download'] && '' !== (string) ( $track['download_key'] ?? '' ) && (int) $track['release_id'] > 0 ) {
				$dl_lbl    = __( 'دانلود', 'music-wave-core' );
				$use_sel   = $config['show_download_quality'] && '' !== $quality_options_html;
				$qual_attr = $use_sel ? '' : ' data-download-quality="' . esc_attr( (string) $track['download_key'] ) . '"';
				if ( $use_sel ) {
					$actions[] = '<select class="mw-download-quality mw-collection-list__quality-select" aria-label="' . esc_attr( $select_aria ) . '">' . $quality_options_html . '</select>';
				}
				$actions[] = '<button type="button" class="mw-download-button mw-collection-list__download mw-collection-list__action mw-collection-list__action--download mw-collection-list__action--' . $act_mode . '" data-release-id="' . esc_attr( (string) $track['release_id'] ) . '"' . $qual_attr . ' aria-label="' . esc_attr( $dl_lbl . ' ' . $title ) . '">'
					. '<span class="mw-collection-list__action-icon" aria-hidden="true">' . $dl_svg . '</span>'
					. '<span class="' . esc_attr( $lbl_class ) . '">' . esc_html( $dl_lbl ) . '</span>'
					. '</button>';
			} elseif ( $config['show_download'] && '' !== (string) ( $track['download_url'] ?? '' ) ) {
				$dl_lbl    = __( 'دانلود', 'music-wave-core' );
				$actions[] = '<a class="mw-collection-list__download mw-collection-list__action mw-collection-list__action--download mw-collection-list__action--' . $act_mode . '" href="' . esc_url( (string) $track['download_url'] ) . '" aria-label="' . esc_attr( $dl_lbl . ' ' . $title ) . '">'
					. '<span class="mw-collection-list__action-icon" aria-hidden="true">' . $dl_svg . '</span>'
					. '<span class="' . esc_attr( $lbl_class ) . '">' . esc_html( $dl_lbl ) . '</span>'
					. '</a>';
			} elseif ( $config['show_download'] && $track['is_sample'] ) {
				$dl_lbl = __( 'دانلود', 'music-wave-core' );
				if ( $config['show_download_quality'] ) {
					$actions[] = '<select class="mw-download-quality mw-collection-list__quality-select" aria-label="' . esc_attr( $select_aria ) . '" disabled><option value="mp3_320">MP3 · 320 kbps</option><option value="mp3_128">MP3 · 128 kbps</option></select>';
				}
				$actions[] = '<button type="button" class="mw-collection-list__download mw-collection-list__action mw-collection-list__action--download mw-collection-list__action--' . $act_mode . '" aria-label="' . esc_attr( $dl_lbl ) . '">'
					. '<span class="mw-collection-list__action-icon" aria-hidden="true">' . $dl_svg . '</span>'
					. '<span class="' . esc_attr( $lbl_class ) . '">' . esc_html( $dl_lbl ) . '</span>'
					. '</button>';
			}

			$cells[] = '<div class="mw-collection-list__cell mw-collection-list__actions">' . implode( '', $actions ) . '</div>';
		}

		$rid_data = (int) $track['release_id'] > 0 ? ' data-mw-track-id="' . esc_attr( (string) $track['release_id'] ) . '"' : '';

		return '<li class="mw-collection-list__row"' . $rid_data . '>' . implode( '', $cells ) . '</li>';
	}

	/**
	 * Render a realistic 4-track preview inside the Block/Site Editor using the exact same
	 * `assemble_track_row_markup()` pipeline so every toggle/density/icon change updates live 1:1.
	 *
	 * @param array<string, mixed> $config     Normalized config.
	 * @param int                  $release_id Optional resolved release ID lacking child tracks.
	 */
	private function render_editor_sample_collection( array $config, int $release_id = 0 ): string {
		$artist_placeholder = __( 'نام هنرمند', 'music-wave-core' );
		$samples            = array(
			array(
				'title'    => __( 'قطعه ۱', 'music-wave-core' ),
				'artist'   => $artist_placeholder,
				'bpm'      => '۱۱۸',
				'quality'  => '۳۲۰kbps',
				'duration' => '۳:۴۲',
			),
			array(
				'title'    => __( 'قطعه ۲', 'music-wave-core' ),
				'artist'   => $artist_placeholder,
				'bpm'      => '۱۲۴',
				'quality'  => '۳۲۰kbps',
				'duration' => '۴:۱۵',
			),
			array(
				'title'    => __( 'قطعه ۳', 'music-wave-core' ),
				'artist'   => $artist_placeholder,
				'bpm'      => '۱۱۰',
				'quality'  => '۳۲۰kbps',
				'duration' => '۳:۲۸',
			),
			array(
				'title'    => __( 'قطعه ۴', 'music-wave-core' ),
				'artist'   => $artist_placeholder,
				'bpm'      => '۱۲۸',
				'quality'  => '۳۲۰kbps',
				'duration' => '۵:۰۴',
			),
		);

		$art_html = $config['show_artwork']
			? '<span class="mw-collection-list__artwork mw-collection-list__artwork--fallback" aria-hidden="true">' . ActionButtonStyle::icon_svg( 'waveform', 16 ) . '</span>'
			: '';

		$rows = array();
		foreach ( $samples as $idx => $item ) {
			$rows[] = $this->assemble_track_row_markup(
				array(
					'release_id'   => 0,
					'position'     => $idx + 1,
					'title'        => $item['title'],
					'link'         => '',
					'artwork_html' => $art_html,
					'artist_html'  => esc_html( $item['artist'] ),
					'artist_plain' => $item['artist'],
					'bpm'          => $item['bpm'],
					'quality'      => $item['quality'],
					'duration'     => $item['duration'],
					'playable'     => true,
					'play_attrs'   => '',
					'download_url' => '',
					'is_sample'    => true,
				),
				$config
			);
		}

		$heading_html = $this->section_heading_markup( $config, 4, 989 );
		$diag_note    = $release_id > 0
			? \ManaCore\MusicWave\Core\Blocks\Concerns\EditorDiagnosticNotice::render(
				/* translators: %s: release title. */
				sprintf( __( 'برای «%s» زیرقطعه‌ای ثبت نشده است (پیش‌نمایش ظاهری فهرست قطعات؛ در فرانت‌اند برای تک‌آهنگ پنهان می‌ماند).', 'music-wave-core' ), get_the_title( $release_id ) )
			)
			: '';

		return '<section ' . BlockSupport::wrapper_attributes( $this->collection_root_classes( $config ) . ' mw-collection-list--placeholder', $this->collection_style_vars( $config ) )
			. ( '' === $heading_html ? ' aria-label="' . esc_attr__( 'فهرست قطعات', 'music-wave-core' ) . '"' : '' )
			. '>'
			. $heading_html
			. $diag_note
			. '<div class="mw-collection-list__grid-shell">'
			. $this->tracklist_header_markup( $config )
			. '<ol class="mw-collection-list__tracks">' . implode( '', $rows ) . '</ol>'
			. '</div>'
			. '</section>';
	}

	/**
	 * Resolve ordered track entries for an album/EP/collection release:
	 * 1. Primary source: `mw_collection_items` via `$repository->collection_items( $parent_id )`
	 *    (preserving exact `position` and `disc_number` stored in the collection metabox).
	 * 2. Legacy fallback: child `mw_release` posts using `post_parent => $parent_id`.
	 *
	 * @param int  $parent_id          Parent collection release ID.
	 * @param bool $allow_unpublished  Whether editor drafts may appear in previews.
	 * @return array<int, array{post: WP_Post, position: int, disc_number: int}>
	 */
	private function collection_entries( int $parent_id, bool $allow_unpublished = false ): array {
		$repo    = $this->context->repository();
		$stored  = $repo->collection_items( $parent_id );
		$entries = array();
		$seen    = array();

		if ( ! empty( $stored ) ) {
			foreach ( $stored as $item ) {
				$track_id = isset( $item['release_id'] )
					? absint( $item['release_id'] )
					: ( isset( $item['track_id'] ) ? absint( $item['track_id'] ) : 0 );
				if ( $track_id <= 0 || isset( $seen[ $track_id ] ) || ! ReleaseResolver::is_release( $track_id, $allow_unpublished ) ) {
					continue;
				}
				$post = get_post( $track_id );
				if ( ! ( $post instanceof WP_Post ) ) {
					continue;
				}
				$seen[ $track_id ] = true;
				$pos               = isset( $item['position'] ) ? absint( $item['position'] ) : 0;
				if ( $pos <= 0 ) {
					$pos = absint( $repo->get( $track_id, 'mw_track_number' ) );
				}
				$disc = isset( $item['disc'] )
					? absint( $item['disc'] )
					: ( isset( $item['disc_number'] ) ? absint( $item['disc_number'] ) : 0 );
				if ( $disc <= 0 ) {
					$disc = absint( get_post_meta( $track_id, 'mw_disc_number', true ) );
				}
				$entries[] = array(
					'post'        => $post,
					'position'    => $pos,
					'disc_number' => $disc > 0 ? $disc : 1,
				);
			}
			if ( ! empty( $entries ) ) {
				return $entries;
			}
		}

		$posts = get_posts(
			array(
				'post_type'      => ReleasePostType::KEY,
				'post_parent'    => $parent_id,
				'post_status'    => $allow_unpublished ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : 'publish',
				'posts_per_page' => 100,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'no_found_rows'  => true,
			)
		);
		if ( ! is_array( $posts ) ) {
			return array();
		}

		foreach ( $posts as $post ) {
			if ( ! ( $post instanceof WP_Post ) ) {
				continue;
			}
			$disc      = absint( get_post_meta( $post->ID, 'mw_disc_number', true ) );
			$entries[] = array(
				'post'        => $post,
				'position'    => absint( $repo->get( $post->ID, 'mw_track_number' ) ),
				'disc_number' => $disc > 0 ? $disc : 1,
			);
		}

		usort(
			$entries,
			static function ( array $left, array $right ): int {
				if ( $left['disc_number'] !== $right['disc_number'] ) {
					return $left['disc_number'] <=> $right['disc_number'];
				}
				if ( $left['position'] !== $right['position'] ) {
					return $left['position'] <=> $right['position'];
				}
				return $left['post']->ID <=> $right['post']->ID;
			}
		);

		return $entries;
	}

	/**
	 * Read track duration in seconds (`mw_duration` primary meta key with `mw_duration_seconds` fallback).
	 */
	private function track_duration_seconds( int $track_id ): int {
		$repo    = $this->context->repository();
		$primary = absint( $repo->get( $track_id, 'mw_duration' ) );
		if ( $primary > 0 ) {
			return $primary;
		}
		return absint( get_post_meta( $track_id, 'mw_duration_seconds', true ) );
	}

	/**
	 * Sum track durations in seconds.
	 *
	 * @param array<int, WP_Post> $tracks Ordered tracks.
	 */
	private function total_duration_seconds( array $tracks ): int {
		$total = 0;
		foreach ( $tracks as $track ) {
			$total += $this->track_duration_seconds( $track->ID );
		}
		return $total;
	}

	/**
	 * Format duration seconds into human clock format (`M:SS` or `H:MM:SS`).
	 */
	private function format_seconds( int $seconds ): string {
		if ( $seconds <= 0 ) {
			return '';
		}
		$hours   = intdiv( $seconds, 3600 );
		$minutes = intdiv( $seconds % 3600, 60 );
		$remain  = $seconds % 60;

		if ( $hours > 0 ) {
			return sprintf( '%d:%02d:%02d', $hours, $minutes, $remain );
		}
		return sprintf( '%d:%02d', $minutes, $remain );
	}
}
