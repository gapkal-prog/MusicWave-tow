<?php
declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared accessible navigation and viewport chrome for horizontal scroll shelves.
 */
final class ShelfNavMarkup {

	private const CHEVRON_PREVIOUS = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>';

	private const CHEVRON_NEXT = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 18 6-6-6-6"/></svg>';

	/**
	 * Render previous/next shelf navigation buttons when layout is `scroll`.
	 *
	 * Buttons render without wrapping `aria-hidden="true"` so enabled controls
	 * remain WCAG 4.1.2 / WAVE `aria-hidden-focus` compliant.
	 */
	public static function controls( string $layout, string $viewport_id ): string {
		if ( 'scroll' !== $layout ) {
			return '';
		}

		if (
			function_exists( 'wp_script_is' )
			&& function_exists( 'wp_enqueue_script' )
			&& wp_script_is( 'musicwave-slider', 'registered' )
		) {
			wp_enqueue_script( 'musicwave-slider' );
		}

		$controls = '' !== $viewport_id ? ' aria-controls="' . esc_attr( $viewport_id ) . '"' : '';

		return '<div class="mw-release-shelf__nav">'
			. '<button type="button" class="mw-release-shelf__nav-button" data-mw-shelf-previous aria-label="' . esc_attr__( 'قبلی', 'music-wave-core' ) . '"' . $controls . ' disabled>' . self::CHEVRON_PREVIOUS . '</button>'
			. '<button type="button" class="mw-release-shelf__nav-button" data-mw-shelf-next aria-label="' . esc_attr__( 'بعدی', 'music-wave-core' ) . '"' . $controls . ' disabled>' . self::CHEVRON_NEXT . '</button>'
			. '</div>';
	}

	/**
	 * Render the items container and screen-reader status region consistently
	 * across both `grid`/`list` and `scroll` shelf layouts.
	 */
	public static function items_region(
		string $layout,
		string $cards_html,
		string $region_label = '',
		string $extra_attrs = ''
	): string {
		$extra = '' !== $extra_attrs ? ' ' . trim( $extra_attrs ) : '';
		if ( 'scroll' !== $layout ) {
			return '<div class="mw-release-shelf__items"' . $extra . '>' . $cards_html . '</div>';
		}

		$viewport_id = function_exists( 'wp_unique_id' )
			? wp_unique_id( 'mw-core-shelf-viewport-' )
			: 'mw-core-shelf-viewport-' . substr( md5( $region_label . $cards_html ), 0, 8 );
		$label       = '' !== $region_label ? $region_label : __( 'قفسه موسیقی', 'music-wave-core' );

		return self::controls( 'scroll', $viewport_id )
			. '<div id="' . esc_attr( $viewport_id ) . '" class="mw-release-shelf__items" data-mw-shelf-viewport tabindex="0" role="region" aria-label="' . esc_attr( $label ) . '"' . $extra . '>' . $cards_html . '</div>'
			. '<p class="screen-reader-text" aria-live="polite" data-mw-shelf-status></p>';
	}
}
