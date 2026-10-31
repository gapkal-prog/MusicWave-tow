<?php
declare(strict_types=1);

namespace ManaCore\MusicWave\Theme\Blocks;

use ManaCore\MusicWave\Core\Support\SuggestAssets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders theme chrome blocks (theme-text, theme-toggle, rail-toggle,
 * header-search) and live-synced LRC lyrics.
 */
final class ChromeBlocksRenderer {

	/**
	 * Register post meta fields owned by chrome presentation blocks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_lyrics_meta' ) );
	}

	/**
	 * Build wrapper attributes string with safe non-editor fallback.
	 *
	 * Support-generated styles (colour, border, spacing, typography) live on
	 * this element, so every chrome block styles itself through custom
	 * properties and never hard-codes what WordPress already prints.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $class_names Extra wrapper classes.
	 */
	public function block_wrapper_attributes( array $attributes, string $class_names = '' ): string {
		if ( function_exists( 'get_block_wrapper_attributes' ) ) {
			$args = array();
			if ( '' !== $class_names ) {
				$args['class'] = $class_names;
			}

			return get_block_wrapper_attributes( $args );
		}

		$class_name = isset( $attributes['className'] ) && is_scalar( $attributes['className'] ) ? (string) $attributes['className'] : '';
		$class_name = trim( (string) preg_replace( '/[^A-Za-z0-9_\-\s]/', '', $class_name . ' ' . $class_names ) );

		return 'class="' . esc_attr( $class_name ) . '"';
	}

	/**
	 * Default translated copy strings keyed for music-wave/theme-text.
	 *
	 * @return array<string, string>
	 */
	public function template_texts(): array {
		return array(
			'latest_releases'   => __( 'آخرین انتشارها', 'musicwave' ),
			'no_music_found'    => __( 'موسیقی‌ای پیدا نشد.', 'musicwave' ),
			'not_found_title'   => __( 'اینجا چیزی در حال پخش نیست.', 'musicwave' ),
			'not_found_message' => __( 'ممکن است صفحه جابه‌جا شده باشد. کاتالوگ را جست‌وجو کنید.', 'musicwave' ),
			'cart_title'        => __( 'سبد خرید شما', 'musicwave' ),
			'checkout_title'    => __( 'تسویه‌حساب', 'musicwave' ),
			'account_title'     => __( 'حساب کاربری من', 'musicwave' ),
			'powered_by'        => __( 'قدرت‌گرفته از MusicWave.', 'musicwave' ),
		);
	}

	/**
	 * Render translated template copy block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_theme_text( array $attributes ): string {
		$key   = isset( $attributes['key'] ) ? sanitize_key( (string) $attributes['key'] ) : '';
		$texts = $this->template_texts();
		if ( ! isset( $texts[ $key ] ) ) {
			return '';
		}

		$tag_name = isset( $attributes['tagName'] ) ? strtolower( (string) $attributes['tagName'] ) : 'p';
		if ( ! in_array( $tag_name, array( 'p', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
			$tag_name = 'p';
		}

		return '<' . $tag_name . ' ' . $this->block_wrapper_attributes( $attributes ) . '>' . esc_html( $texts[ $key ] ) . '</' . $tag_name . '>';
	}

	/**
	 * Render color-scheme preference toggle button (includes nbp-theme-toggle-fallback class).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_theme_toggle( array $attributes = array() ): string {
		$default_label = __( 'استفاده از پوسته سیستم', 'musicwave' );
		$custom_label  = isset( $attributes['label'] ) && is_scalar( $attributes['label'] ) ? sanitize_text_field( (string) $attributes['label'] ) : '';
		$label         = '' !== $custom_label ? $custom_label : $default_label;

		$icons = '<svg class="mw-theme-toggle__icon mw-theme-toggle__icon--system" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 2v16a8 8 0 0 1 0-16Z"/></svg>'
			. '<svg class="mw-theme-toggle__icon mw-theme-toggle__icon--light" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10Zm0-6a1 1 0 0 1 1 1v2a1 1 0 1 1-2 0V2a1 1 0 0 1 1-1Zm0 19a1 1 0 0 1 1 1v2a1 1 0 1 1 0 2v-2a1 1 0 0 1 1-1ZM1 12a1 1 0 0 1 1-1h2a1 1 0 1 1 0 2H2a1 1 0 0 1-1-1Zm19 0a1 1 0 0 1 1-1h2a1 1 0 1 1 0 2h-2a1 1 0 0 1-1-1ZM4.22 4.22a1 1 0 0 1 1.42 0l1.41 1.41a1 1 0 0 1-1.41 1.42L4.22 5.64a1 1 0 0 1 0-1.42Zm12.73 12.73a1 1 0 0 1 1.41 0l1.42 1.41a1 1 0 0 1-1.42 1.42l-1.41-1.42a1 1 0 0 1 0-1.41Zm2.83-12.73a1 1 0 0 1 0 1.42l-1.42 1.41a1 1 0 1 1-1.41-1.41l1.41-1.42a1 1 0 0 1 1.42 0ZM7.05 16.95a1 1 0 0 1 0 1.41l-1.41 1.42a1 1 0 0 1-1.42-1.42l1.42-1.41a1 1 0 0 1 1.41 0Z"/></svg>'
			. '<svg class="mw-theme-toggle__icon mw-theme-toggle__icon--dark" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M21.64 13.4A9 9 0 0 1 10.6 2.36a1 1 0 0 0-1.2-1.3A11 11 0 1 0 22.94 14.6a1 1 0 0 0-1.3-1.2ZM12 21a9 9 0 0 1-3.87-17.13A11 11 0 0 0 20.13 15.87 9 9 0 0 1 12 21Z"/></svg>';

		$text = ! empty( $attributes['showLabel'] )
			? '<span class="mw-theme-toggle__label">' . esc_html( $label ) . '</span>'
			: '';

		return '<button ' . $this->block_wrapper_attributes( $attributes, 'mw-theme-toggle nbp-theme-toggle-fallback' ) . ' type="button" data-mw-theme-value="system" aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '">' . $icons . $text . '</button>';
	}

	/**
	 * Render desktop rail collapse / mobile drawer trigger button.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_rail_toggle( array $attributes = array() ): string {
		$variant = isset( $attributes['variant'] ) && is_scalar( $attributes['variant'] ) ? sanitize_key( (string) $attributes['variant'] ) : 'bar';
		$variant = in_array( $variant, array( 'brand', 'bar' ), true ) ? $variant : 'bar';

		$icon_name = isset( $attributes['icon'] ) && is_scalar( $attributes['icon'] ) ? sanitize_key( (string) $attributes['icon'] ) : 'menu';
		$icon_name = in_array( $icon_name, array( 'menu', 'chevron', 'dots', 'arrow' ), true ) ? $icon_name : 'menu';

		$label = isset( $attributes['label'] ) && is_scalar( $attributes['label'] ) ? sanitize_text_field( (string) $attributes['label'] ) : '';
		$label = '' !== $label ? $label : __( 'باز و بستهٔ منو', 'musicwave' );

		$icons      = array(
			'menu'    => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" d="M4 6h16M4 12h10M4 18h16"/></svg>',
			'chevron' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6"/></svg>',
			'dots'    => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><circle cx="12" cy="5" r="1.8" fill="currentColor"/><circle cx="12" cy="12" r="1.8" fill="currentColor"/><circle cx="12" cy="19" r="1.8" fill="currentColor"/></svg>',
			'arrow'   => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" d="M15 6 9 12l6 6"/></svg>',
		);
		$show_icon  = ! isset( $attributes['showIcon'] ) || false !== $attributes['showIcon'];
		$show_label = ! empty( $attributes['showLabel'] );
		if ( ! $show_icon && ! $show_label ) {
			$show_icon = true;
		}
		$icon = $show_icon ? $icons[ $icon_name ] : '';

		$classes  = 'mw-rail__toggle mw-rail__toggle--icon-' . $icon_name;
		$classes .= 'bar' === $variant ? ' mw-rail__toggle--bar' : ' mw-rail__toggle--brand';
		$classes .= $show_icon ? ' mw-rail__toggle--has-icon' : ' mw-rail__toggle--no-icon';
		$classes .= $show_label ? ' mw-rail__toggle--has-label' : ' mw-rail__toggle--no-label';

		$text = $show_label ? '<span class="mw-rail__toggle-label">' . esc_html( $label ) . '</span>' : '';

		return '<button ' . $this->block_wrapper_attributes( $attributes, $classes ) . ' type="button" data-mw-rail-toggle aria-controls="mw-rail" aria-expanded="false" aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '">' . $icon . $text . '</button>';
	}

	/**
	 * Render responsive multi-viewport header search block (`music-wave/header-search`).
	 *
	 * Supports independent Desktop (`bar` | `compact` | `popup`), Tablet
	 * (`bar` | `compact` | `popup` | `hidden`), and Mobile (`popup` | `expand` |
	 * `compact` | `hidden`) presentation modes, global Command Palette (`Ctrl+K` /
	 * `⌘K` / `/`), live scope tabs, voice search (`fa-IR`), recent searches, and
	 * curated discovery tags — all sharing one clean ARIA 1.2 DOM structure.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_header_search( array $attributes = array() ): string {
		$raw_variant = isset( $attributes['variant'] ) && is_scalar( $attributes['variant'] )
			? sanitize_key( (string) $attributes['variant'] )
			: 'inline';
		if ( 'button' === $raw_variant || 'icon' === $raw_variant ) {
			$raw_variant = 'popup';
		}
		$legacy_popup = 'popup' === $raw_variant
			&& ! array_key_exists( 'desktopMode', $attributes )
			&& ! array_key_exists( 'tabletMode', $attributes )
			&& ! array_key_exists( 'mobileMode', $attributes );

		$desktop_mode = isset( $attributes['desktopMode'] ) && is_scalar( $attributes['desktopMode'] )
			? sanitize_key( (string) $attributes['desktopMode'] )
			: ( $legacy_popup ? 'popup' : 'bar' );
		$desktop_mode = in_array( $desktop_mode, array( 'bar', 'compact', 'popup' ), true ) ? $desktop_mode : 'bar';

		$tablet_mode = isset( $attributes['tabletMode'] ) && is_scalar( $attributes['tabletMode'] )
			? sanitize_key( (string) $attributes['tabletMode'] )
			: ( $legacy_popup ? 'popup' : 'compact' );
		$tablet_mode = in_array( $tablet_mode, array( 'bar', 'compact', 'popup', 'hidden' ), true ) ? $tablet_mode : 'compact';

		$mobile_mode = isset( $attributes['mobileMode'] ) && is_scalar( $attributes['mobileMode'] )
			? sanitize_key( (string) $attributes['mobileMode'] )
			: 'popup';
		$mobile_mode = in_array( $mobile_mode, array( 'popup', 'expand', 'compact', 'hidden' ), true ) ? $mobile_mode : 'popup';

		$enable_shortcut   = ! array_key_exists( 'enableShortcut', $attributes ) || ! empty( $attributes['enableShortcut'] );
		$show_scope_tabs   = ! array_key_exists( 'showScopeTabs', $attributes ) || ! empty( $attributes['showScopeTabs'] );
		$show_recent       = ! array_key_exists( 'showRecentSearches', $attributes ) || ! empty( $attributes['showRecentSearches'] );
		$show_voice        = ! array_key_exists( 'showVoiceSearch', $attributes ) || ! empty( $attributes['showVoiceSearch'] );
		$show_footer_hints = ! array_key_exists( 'showFooterHints', $attributes ) || ! empty( $attributes['showFooterHints'] );

		$placeholder = isset( $attributes['placeholder'] ) && is_scalar( $attributes['placeholder'] ) ? sanitize_text_field( (string) $attributes['placeholder'] ) : '';
		if ( '' === $placeholder ) {
			$placeholder = __( 'جستجوی آهنگ، هنرمند یا آلبوم…', 'musicwave' );
		}

		$button_text = isset( $attributes['buttonText'] ) && is_scalar( $attributes['buttonText'] ) ? sanitize_text_field( (string) $attributes['buttonText'] ) : '';
		if ( '' === $button_text ) {
			$button_text = __( 'جستجو', 'musicwave' );
		}

		$limit          = isset( $attributes['suggestLimit'] ) ? absint( $attributes['suggestLimit'] ) : 5;
		$limit          = max( 1, min( 10, $limit ) );
		$filtered_limit = apply_filters( 'musicwave_header_suggest_limit', $limit );
		$limit          = is_int( $filtered_limit ) ? max( 1, min( 10, $filtered_limit ) ) : $limit;

		$config = '';
		if ( class_exists( SuggestAssets::class ) ) {
			$config = SuggestAssets::field_config( $limit );
			SuggestAssets::enqueue();
		}
		if ( function_exists( 'wp_enqueue_script' ) ) {
			wp_enqueue_script( 'musicwave-header-search' );
		}

		$style_variant = isset( $attributes['styleVariant'] ) && is_scalar( $attributes['styleVariant'] ) ? sanitize_key( (string) $attributes['styleVariant'] ) : '';
		$style_suffix  = in_array( $style_variant, array( 'minimal', 'glass' ), true ) ? ' mw-header-search--' . $style_variant : '';
		$primary_mode  = 'popup' === $desktop_mode ? 'popup' : 'inline';

		$wrapper_classes = implode(
			' ',
			array_filter(
				array(
					'mw-header-search-block',
					'mw-header-search-block--' . $primary_mode,
					'mw-header-search--d-' . $desktop_mode,
					'mw-header-search--t-' . $tablet_mode,
					'mw-header-search--m-' . $mobile_mode,
					trim( $style_suffix ),
				)
			)
		);

		$action        = function_exists( 'home_url' ) ? home_url( '/' ) : '/';
		$inline_id     = wp_unique_id( 'mw-header-search-' );
		$dialog_id     = wp_unique_id( 'mw-header-dialog-input-' );
		$popup_id      = wp_unique_id( 'mw-search-popup-' );
		$search_icon   = '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.35-4.35"/></svg>';
		$close_icon    = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
		$clear_icon    = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
		$voice_icon    = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" x2="12" y1="19" y2="22"/></svg>';
		$shortcut_html = $enable_shortcut
			? '<button type="button" class="mw-header-search-block__shortcut" data-mw-search-palette-trigger aria-label="' . esc_attr__( 'باز کردن پنجره جستجوی سریع', 'musicwave' ) . '"><kbd>Ctrl</kbd><kbd>K</kbd></button>'
			: '';
		$voice_btn     = $show_voice
			? '<button type="button" class="mw-header-search-block__tool mw-header-search-block__voice" data-mw-voice-search hidden aria-label="' . esc_attr__( 'جستجوی صوتی', 'musicwave' ) . '" title="' . esc_attr__( 'جستجوی صوتی', 'musicwave' ) . '">' . $voice_icon . '</button>'
			: '';

		$inline_form = '<div class="mw-header-search-block__inline">'
			. '<div class="mw-header-suggest' . ( '' !== $config ? ' mw-catalog-suggest' : '' ) . '"' . ( '' !== $config ? ' data-mw-suggest="' . esc_attr( $config ) . '"' : '' ) . '>'
			. '<form role="search" method="get" action="' . esc_url( $action ) . '" class="wp-block-search__button-inside wp-block-search__icon-button mw-stream-search wp-block-search" data-mw-search-form>'
			. '<label for="' . esc_attr( $inline_id ) . '" class="wp-block-search__label screen-reader-text">' . esc_html( $button_text ) . '</label>'
			. '<div class="wp-block-search__inside-wrapper">'
			. '<button type="submit" class="mw-header-search-block__field-icon" aria-label="' . esc_attr( $button_text ) . '">' . $search_icon . '</button>'
			. '<input type="search" id="' . esc_attr( $inline_id ) . '" class="wp-block-search__input" name="s" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off" dir="auto" data-mw-search-input />'
			. '<input type="hidden" name="post_type" value="" data-mw-search-post-type disabled />'
			. '<button type="button" class="mw-header-search-block__tool mw-header-search-block__clear" data-mw-search-clear hidden aria-label="' . esc_attr__( 'پاک کردن متن جستجو', 'musicwave' ) . '">' . $clear_icon . '</button>'
			. $voice_btn
			. $shortcut_html
			. '</div>'
			. '</form>'
			. '<span class="screen-reader-text" role="status" aria-live="polite" data-mw-suggest-status></span>'
			. '</div>'
			. '</div>';

		$toggle_btn = '<button type="button" class="mw-header-search-block__toggle" aria-expanded="false" aria-controls="' . esc_attr( $popup_id ) . '" aria-label="' . esc_attr( $button_text ) . '">'
			. '<span class="mw-header-search-block__toggle-icon" aria-hidden="true">' . $search_icon . '</span>'
			. '<span class="mw-header-search-block__toggle-label screen-reader-text">' . esc_html( $button_text ) . '</span>'
			. '</button>';

		$scope_tabs_html = '';
		if ( $show_scope_tabs ) {
			$scopes        = array(
				array(
					'key'       => 'all',
					'post_type' => '',
					'label'     => __( 'همه', 'musicwave' ),
				),
				array(
					'key'       => 'releases',
					'post_type' => 'mw_release',
					'label'     => __( 'آهنگ و آلبوم', 'musicwave' ),
				),
				array(
					'key'       => 'artists',
					'post_type' => '',
					'tax_hint'  => 'mw_artist',
					'label'     => __( 'هنرمندان', 'musicwave' ),
				),
				array(
					'key'       => 'playlists',
					'post_type' => 'mw_playlist',
					'label'     => __( 'پلی‌لیست', 'musicwave' ),
				),
			);
			$scope_buttons = array();
			foreach ( $scopes as $idx => $scope ) {
				$is_active       = 0 === $idx;
				$scope_buttons[] = '<button type="button" role="tab" class="mw-header-search-block__scope' . ( $is_active ? ' is-active' : '' ) . '"'
					. ' aria-selected="' . ( $is_active ? 'true' : 'false' ) . '"'
					. ' data-mw-search-scope="' . esc_attr( $scope['key'] ) . '"'
					. ' data-mw-post-type="' . esc_attr( $scope['post_type'] ) . '">'
					. esc_html( $scope['label'] )
					. '</button>';
			}
			$scope_tabs_html = '<div class="mw-header-search-block__scopes" role="tablist" aria-label="' . esc_attr__( 'محدوده جستجو', 'musicwave' ) . '">'
				. implode( '', $scope_buttons )
				. '</div>';
		}

		$dialog_form = '<div class="mw-header-suggest' . ( '' !== $config ? ' mw-catalog-suggest' : '' ) . '"' . ( '' !== $config ? ' data-mw-suggest="' . esc_attr( $config ) . '"' : '' ) . '>'
			. '<form role="search" method="get" action="' . esc_url( $action ) . '" class="wp-block-search__button-inside wp-block-search__icon-button mw-header-search wp-block-search" data-mw-search-form>'
			. '<label for="' . esc_attr( $dialog_id ) . '" class="wp-block-search__label screen-reader-text">' . esc_html( $button_text ) . '</label>'
			. '<div class="wp-block-search__inside-wrapper">'
			. '<button type="submit" class="mw-header-search-block__field-icon" aria-label="' . esc_attr( $button_text ) . '">' . $search_icon . '</button>'
			. '<input type="search" id="' . esc_attr( $dialog_id ) . '" class="wp-block-search__input" name="s" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off" dir="auto" data-mw-search-dialog-input />'
			. '<input type="hidden" name="post_type" value="" data-mw-search-post-type disabled />'
			. '<button type="button" class="mw-header-search-block__tool mw-header-search-block__clear" data-mw-search-clear hidden aria-label="' . esc_attr__( 'پاک کردن متن جستجو', 'musicwave' ) . '">' . $clear_icon . '</button>'
			. $voice_btn
			. '</div>'
			. '</form>'
			. '<span class="screen-reader-text" role="status" aria-live="polite" data-mw-suggest-status></span>'
			. '</div>';

		$recent_html = $show_recent
			? '<div class="mw-header-search-block__section mw-header-search-block__recent" data-mw-search-recent hidden>'
				. '<div class="mw-header-search-block__section-head">'
				. '<span class="mw-header-search-block__section-title">' . esc_html__( 'جستجوهای اخیر', 'musicwave' ) . '</span>'
				. '<button type="button" class="mw-header-search-block__clear-all" data-mw-search-recent-clear>' . esc_html__( 'پاک‌سازی', 'musicwave' ) . '</button>'
				. '</div>'
				. '<div class="mw-header-search-block__chips" data-mw-search-recent-list></div>'
				. '</div>'
			: '';

		$tags      = $this->resolve_quick_tags( $attributes );
		$tags_html = '';
		if ( ! empty( $tags ) ) {
			$chips = array();
			foreach ( $tags as $tag ) {
				$chips[] = '<button type="button" class="mw-header-search-block__chip" data-mw-search-tag="' . esc_attr( $tag ) . '">'
					. '<span class="mw-header-search-block__chip-hash" aria-hidden="true">#</span>'
					. '<span>' . esc_html( $tag ) . '</span>'
					. '</button>';
			}
			$tags_html = '<div class="mw-header-search-block__section mw-header-search-block__tags" data-mw-search-tags>'
				. '<div class="mw-header-search-block__section-head">'
				. '<span class="mw-header-search-block__section-title">' . esc_html__( 'پیشنهادهای سریع', 'musicwave' ) . '</span>'
				. '</div>'
				. '<div class="mw-header-search-block__chips">' . implode( '', $chips ) . '</div>'
				. '</div>';
		}

		$footer_html = '';
		if ( $show_footer_hints ) {
			$kbd_nav     = '<span class="mw-header-search-block__kbd"><kbd>↑</kbd><kbd>↓</kbd> ' . esc_html__( 'پیمایش', 'musicwave' ) . '</span>';
			$kbd_select  = '<span class="mw-header-search-block__kbd"><kbd>↵</kbd> ' . esc_html__( 'انتخاب', 'musicwave' ) . '</span>';
			$kbd_close   = '<span class="mw-header-search-block__kbd"><kbd>Esc</kbd> ' . esc_html__( 'بستن', 'musicwave' ) . '</span>';
			$footer_html = '<div class="mw-header-search-block__popup-footer">' . $kbd_nav . $kbd_select . $kbd_close . '</div>';
		}

		$popup_html = '<div id="' . esc_attr( $popup_id ) . '" class="mw-header-search-block__popup" hidden role="dialog" aria-modal="true" aria-label="' . esc_attr( $button_text ) . '">'
			. '<div class="mw-header-search-block__backdrop" data-mw-search-backdrop aria-hidden="true"></div>'
			. '<div class="mw-header-search-block__popup-inner">'
			. '<div class="mw-header-search-block__popup-header">'
			. '<div class="mw-header-search-block__popup-heading">'
			. '<span class="mw-header-search-block__popup-badge" aria-hidden="true">' . $search_icon . '</span>'
			. '<div><p class="mw-header-search-block__popup-title">' . esc_html__( 'جستجوی هوشمند کاتالوگ', 'musicwave' ) . '</p>'
			. '<p class="mw-header-search-block__popup-subtitle">' . esc_html__( 'نام قطعه، خواننده، آلبوم یا حال‌وهوا را بنویسید', 'musicwave' ) . '</p></div>'
			. '</div>'
			. '<button type="button" class="mw-header-search-block__close" aria-label="' . esc_attr__( 'بستن جستجو', 'musicwave' ) . '">' . $close_icon . '</button>'
			. '</div>'
			. $scope_tabs_html
			. '<div class="mw-header-search-block__popup-body">'
			. $dialog_form
			. $recent_html
			. $tags_html
			. '</div>'
			. $footer_html
			. '</div>'
			. '</div>';

		$data_attrs = ' data-mw-search-mode="' . esc_attr( $primary_mode ) . '"'
			. ' data-desktop-mode="' . esc_attr( $desktop_mode ) . '"'
			. ' data-tablet-mode="' . esc_attr( $tablet_mode ) . '"'
			. ' data-mobile-mode="' . esc_attr( $mobile_mode ) . '"'
			. ( $enable_shortcut ? ' data-mw-shortcut="1"' : ' data-mw-shortcut="0"' )
			. ( $show_recent ? ' data-mw-recent="1"' : ' data-mw-recent="0"' );

		return '<div ' . $this->block_wrapper_attributes( $attributes, $wrapper_classes ) . $data_attrs . '>'
			. $toggle_btn
			. $inline_form
			. $popup_html
			. '</div>';
	}

	/**
	 * Resolve curated discovery quick-tags from block attributes or top `mw_genre` terms.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<int, string>
	 */
	private function resolve_quick_tags( array $attributes ): array {
		$raw = isset( $attributes['quickTags'] ) && is_scalar( $attributes['quickTags'] )
			? trim( (string) $attributes['quickTags'] )
			: '';
		if ( 'off' === strtolower( $raw ) || 'none' === strtolower( $raw ) ) {
			return array();
		}
		if ( '' !== $raw ) {
			$parts = preg_split( '/[,،|]+/u', $raw );
			$tags  = array();
			if ( is_array( $parts ) ) {
				foreach ( $parts as $part ) {
					$clean = sanitize_text_field( trim( $part ) );
					if ( '' !== $clean ) {
						$tags[] = $clean;
					}
				}
			}
			return array_slice( array_values( array_unique( $tags ) ), 0, 6 );
		}

		if ( function_exists( 'get_terms' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'mw_genre',
					'hide_empty' => true,
					'orderby'    => 'count',
					'order'      => 'DESC',
					'number'     => 5,
				)
			);
			if ( is_array( $terms ) && ! empty( $terms ) ) {
				$names = array();
				foreach ( $terms as $term ) {
					if ( is_object( $term ) && property_exists( $term, 'name' ) && is_string( $term->name ) ) {
						$name = trim( $term->name );
						if ( '' !== $name ) {
							$names[] = $name;
						}
					}
				}
				if ( array() !== $names ) {
					return $names;
				}
			}
		}

		return array(
			__( 'پاپ', 'musicwave' ),
			__( 'الکترونیک', 'musicwave' ),
			__( 'سنتی و تلفیقی', 'musicwave' ),
			__( 'بیکلام', 'musicwave' ),
		);
	}

	/**
	 * Register REST-exposed LRC post meta fields on mw_release with unified auth callback.
	 */
	public function register_lyrics_meta(): void {
		if ( ! function_exists( 'register_post_meta' ) ) {
			return;
		}

		$auth_callback = array( $this, 'authorize_release_meta_edit' );

		register_post_meta(
			'mw_release',
			'mw_lyrics_lrc',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_textarea_field',
				'auth_callback'     => $auth_callback,
			)
		);

		register_post_meta(
			'mw_release',
			'mw_lyrics_offset',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => true,
				'default'           => 0,
				'sanitize_callback' => static function ( $value ): int {
					return max( -10000, min( 10000, (int) $value ) );
				},
				'auth_callback'     => $auth_callback,
			)
		);
	}

	/**
	 * Object-level edit capability check shared by release lyrics meta fields.
	 *
	 * @param mixed $allowed  Default allowed flag.
	 * @param mixed $meta_key Meta key name.
	 * @param mixed $post_id  Target post ID.
	 * @param mixed $user_id  User ID.
	 */
	public function authorize_release_meta_edit( $allowed, $meta_key, $post_id, $user_id ): bool {
		unset( $allowed, $meta_key );
		$target_post_id = (int) $post_id;
		if ( $target_post_id > 0 ) {
			return (bool) user_can( (int) $user_id, 'edit_post', $target_post_id );
		}

		return (bool) user_can( (int) $user_id, 'edit_posts' );
	}

	/**
	 * Parse LRC document lines into sorted timed rows.
	 *
	 * @return array<int, array{time: float, text: string, translation: string}>
	 */
	public function parse_lrc( string $raw ): array {
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $row ) {
			$row = trim( (string) $row );
			if ( '' === $row || 1 === preg_match( '/^\[[a-z]+:/i', $row ) ) {
				continue;
			}
			if ( preg_match_all( '/\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\]/', $row, $stamps, PREG_SET_ORDER ) ) {
				$text = trim( (string) preg_replace( '/\[\d{1,2}:\d{2}(?:\.\d{1,3})?\]/', '', $row ) );
				if ( '' === $text ) {
					continue;
				}
				$parts       = preg_split( '/\s*(?:\||\/\/)\s+/', $text, 2 );
				$primary     = isset( $parts[0] ) ? trim( (string) $parts[0] ) : $text;
				$translation = isset( $parts[1] ) ? trim( (string) $parts[1] ) : '';
				foreach ( $stamps as $stamp ) {
					$ms      = isset( $stamp[3] ) ? (float) ( '0.' . $stamp[3] ) : 0.0;
					$seconds = ( (int) $stamp[1] * 60 ) + (int) $stamp[2] + $ms;
					$lines[] = array(
						'time'        => $seconds,
						'text'        => $primary,
						'translation' => $translation,
					);
				}
				continue;
			}
			$lines[] = array(
				'time'        => -1.0,
				'text'        => $row,
				'translation' => '',
			);
		}

		usort(
			$lines,
			static function ( array $a, array $b ): int {
				return $a['time'] <=> $b['time'];
			}
		);

		return $lines;
	}

	/**
	 * Format lyric timestamp seconds as mm:ss.
	 */
	public function format_lyric_clock( float $seconds ): string {
		if ( $seconds < 0 ) {
			return '';
		}

		return sprintf( '%02d:%02d', (int) floor( $seconds / 60 ), (int) floor( $seconds ) % 60 );
	}

	/**
	 * Render live karaoke-synced lyrics block for a release.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_synced_lyrics( array $attributes ): string {
		$is_editor  = class_exists( '\ManaCore\MusicWave\Core\Blocks\BlockSupport' )
			&& \ManaCore\MusicWave\Core\Blocks\BlockSupport::is_editor_context();
		$release_id = isset( $attributes['releaseId'] ) ? absint( $attributes['releaseId'] ) : 0;
		if ( $release_id < 1 ) {
			$release_id = absint( get_the_ID() );
		}
		if ( ( $release_id < 1 || 'mw_release' !== (string) get_post_type( $release_id ) ) && class_exists( '\ManaCore\MusicWave\Core\Blocks\Concerns\ReleaseResolver' ) ) {
			$release_id = \ManaCore\MusicWave\Core\Blocks\Concerns\ReleaseResolver::resolve( $attributes );
		}
		if ( ( $release_id < 1 || 'mw_release' !== (string) get_post_type( $release_id ) ) && ! $is_editor ) {
			return '';
		}

		$raw = $release_id > 0 ? (string) get_post_meta( $release_id, 'mw_lyrics_lrc', true ) : '';
		if ( '' === $raw ) {
			$raw = isset( $attributes['fallbackText'] ) ? (string) $attributes['fallbackText'] : '';
		}
		$raw = trim( $raw );
		if ( '' === $raw ) {
			if ( ! $is_editor ) {
				return '';
			}
			$heading   = isset( $attributes['heading'] ) ? sanitize_text_field( (string) $attributes['heading'] ) : '';
			$heading   = '' !== $heading ? $heading : __( 'متن هم‌زمان با آهنگ', 'musicwave' );
			$diag_note = class_exists( '\ManaCore\MusicWave\Core\Blocks\Concerns\EditorDiagnosticNotice' )
				? \ManaCore\MusicWave\Core\Blocks\Concerns\EditorDiagnosticNotice::render(
					$release_id > 0
						/* translators: %s: release title. */
						? sprintf( __( 'برای «%s» هنوز متن ترانه یا زمان‌بندی LRC ثبت نشده است؛ در نمای عمومی نمایش داده نمی‌شود.', 'musicwave' ), get_the_title( $release_id ) )
						: __( 'برای این انتشار هنوز متن ترانه یا زمان‌بندی LRC ثبت نشده است؛ در نمای عمومی نمایش داده نمی‌شود.', 'musicwave' )
				)
				: '';

			return '<section ' . get_block_wrapper_attributes( array( 'class' => 'mw-lyrics mw-lyrics--karaoke mw-lyrics--empty' ) ) . '><header class="mw-lyrics__header"><div class="mw-lyrics__heading"><span class="mw-lyrics__eyebrow">' . esc_html__( 'Live Sync Lyrics', 'musicwave' ) . '</span><h2>' . esc_html( $heading ) . '</h2></div></header>' . $diag_note . '</section>';
		}

		$parsed = $this->parse_lrc( $raw );
		if ( array() === $parsed ) {
			return '';
		}

		if ( function_exists( 'wp_enqueue_script' ) ) {
			wp_enqueue_script( 'musicwave-lyrics' );
		}

		$heading = isset( $attributes['heading'] ) ? sanitize_text_field( (string) $attributes['heading'] ) : '';
		$heading = '' !== $heading ? $heading : __( 'متن هم‌زمان با آهنگ', 'musicwave' );
		$offset  = (int) get_post_meta( $release_id, 'mw_lyrics_offset', true );

		$rows = '';
		foreach ( $parsed as $index => $line ) {
			$timed     = $line['time'] >= 0;
			$time_attr = $timed ? ' data-mw-lyric-time="' . esc_attr( (string) $line['time'] ) . '"' : '';
			$tag       = $timed ? 'button' : 'p';
			$type      = $timed ? ' type="button"' : '';
			$stamp     = $timed ? '<span class="mw-lyrics__stamp">' . esc_html( $this->format_lyric_clock( $line['time'] ) ) . '</span>' : '';
			$sub       = '' !== $line['translation'] ? '<span class="mw-lyrics__sub">' . esc_html( $line['translation'] ) . '</span>' : '';
			$rows     .= '<' . $tag . $type . ' class="mw-lyrics__line" data-mw-lyric-index="' . esc_attr( (string) $index ) . '"' . $time_attr . '><span class="mw-lyrics__text">' . esc_html( $line['text'] ) . '</span>' . $sub . $stamp . '</' . $tag . '>';
		}

		$tools = '<div class="mw-lyrics__toolbar">'
			. '<button type="button" class="mw-lyrics__tool is-active" data-mw-lyrics-autoscroll aria-pressed="true">' . esc_html__( 'اسکرول خودکار', 'musicwave' ) . '</button>'
			. '<span class="mw-lyrics__toolbar-sep" aria-hidden="true">·</span>'
			. '<span class="mw-lyrics__hint">' . esc_html__( 'برای پرش، روی خط بزنید', 'musicwave' ) . '</span>'
			. '<span class="mw-lyrics__grow"></span>'
			. '<button type="button" class="mw-lyrics__scale" data-mw-lyrics-scale="-1" aria-label="' . esc_attr__( 'کوچک‌تر کردن متن', 'musicwave' ) . '">A−</button>'
			. '<button type="button" class="mw-lyrics__scale" data-mw-lyrics-scale="1" aria-label="' . esc_attr__( 'بزرگ‌تر کردن متن', 'musicwave' ) . '">A+</button>'
			. '</div>';

		$foot = '<footer class="mw-lyrics__foot"><span>' . esc_html__( 'متن از کاتالوگ همین انتشار · هم‌زمان با پخش‌کننده سراسری', 'musicwave' ) . '</span></footer>';

		return '<section ' . get_block_wrapper_attributes( array( 'class' => 'mw-lyrics mw-lyrics--karaoke' ) ) . ' data-mw-lyrics data-scroll="center" data-mw-lyric-offset="' . esc_attr( (string) $offset ) . '" data-release-id="' . esc_attr( (string) $release_id ) . '"><header class="mw-lyrics__header"><div class="mw-lyrics__heading"><span class="mw-lyrics__eyebrow">' . esc_html__( 'Live Sync Lyrics', 'musicwave' ) . '</span><h2>' . esc_html( $heading ) . '</h2></div></header>' . $tools . '<div class="mw-lyrics__stage" data-mw-lyrics-stage>' . $rows . '</div>' . $foot . '</section>';
	}
}
