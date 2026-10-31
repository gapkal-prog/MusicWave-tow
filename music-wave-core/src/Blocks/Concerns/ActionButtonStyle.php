<?php
/**
 * Unified OOP presentation & icon design system for all MusicWave buttons.
 *
 * Single source of truth (DRY + Open/Closed) across:
 * - `preview-player` & `preview-button`
 * - `library-button`
 * - `add-to-playlist`
 * - `add-to-queue`
 * - `share-button`
 * - `shuffle-button`
 * - `download-button` & `collection-list` row actions
 *
 * Custom icon input accepts:
 * 1. Empty string (`""`) -> semantic default SVG icon for that block.
 * 2. Project SVG library slug (`play-filled`, `waveform`, `heart`, `share`, etc.).
 * 3. Sanitized inline `<svg>...</svg>` vector markup.
 * 4. CSS icon class string (`dashicons dashicons-controls-play`, `ri-play-fill`, etc.).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks\Concerns;

use ManaCore\MusicWave\Core\Blocks\BlockSupport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ActionButtonStyle {
	public const MODE_ICON   = 'icon';
	public const MODE_BUTTON = 'button';
	public const MODE_ACCENT = 'accent';

	public const VARIANT_ACCENT  = 'accent';
	public const VARIANT_SURFACE = 'surface';
	public const VARIANT_OUTLINE = 'outline';
	public const VARIANT_GHOST   = 'ghost';

	public const SIZE_SM = 'sm';
	public const SIZE_MD = 'md';
	public const SIZE_LG = 'lg';

	public const SHAPE_PILL    = 'pill';
	public const SHAPE_ROUNDED = 'rounded';

	/**
	 * Canonical stroke/fill SVG inner paths on a 24x24 viewBox.
	 *
	 * @return array<string, array{body: string, filled?: bool}>
	 */
	private static function icon_definitions(): array {
		return array(
			'play'         => array(
				'body' => '<polygon points="7 4.5 19.5 12 7 19.5 7 4.5"></polygon>',
			),
			'play-filled'  => array(
				'body'   => '<path d="M7.5 4.84a1 1 0 0 1 1.52-.85l11.2 6.86a1.35 1.35 0 0 1 0 2.3l-11.2 6.86A1 1 0 0 1 7.5 19.16V4.84Z" fill="currentColor" stroke="none"></path>',
				'filled' => true,
			),
			'play-circle'  => array(
				'body' => '<circle cx="12" cy="12" r="9.5"></circle><polygon points="10 8.5 16 12 10 15.5 10 8.5" fill="currentColor" stroke="none"></polygon>',
			),
			'pause'        => array(
				'body' => '<rect x="6.5" cy="4.5" width="4" height="15" rx="1.4" fill="currentColor" stroke="none"></rect><rect x="13.5" cy="4.5" width="4" height="15" rx="1.4" fill="currentColor" stroke="none"></rect>',
			),
			'pause-circle' => array(
				'body' => '<circle cx="12" cy="12" r="9.5"></circle><rect x="9" cy="8" width="2.4" height="8" rx="1" fill="currentColor" stroke="none"></rect><rect x="12.6" cy="8" width="2.4" height="8" rx="1" fill="currentColor" stroke="none"></rect>',
			),
			'waveform'     => array(
				'body' => '<path d="M4 10v4"></path><path d="M8 6v12"></path><path d="M12 3v18"></path><path d="M16 7v10"></path><path d="M20 9v6"></path>',
			),
			'headphones'   => array(
				'body' => '<path d="M3 15v-3a9 9 0 0 1 18 0v3"></path><path d="M21 16a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3v5Z"></path><path d="M3 16a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3v5Z"></path>',
			),
			'disc'         => array(
				'body' => '<circle cx="12" cy="12" r="9.5"></circle><circle cx="12" cy="12" r="2.75"></circle><path d="M12 6.5a5.5 5.5 0 0 1 5.5 5.5"></path>',
			),
			'heart'        => array(
				'body' => '<path d="M12 20.8 4.4 13.4A4.9 4.9 0 0 1 11.3 6.5L12 7.2l.7-.7a4.9 4.9 0 0 1 6.9 6.9L12 20.8Z"></path>',
			),
			'user-plus'    => array(
				'body' => '<path d="M15.5 19.5v-1.2a3.8 3.8 0 0 0-3.8-3.8H6.8a3.8 3.8 0 0 0-3.8 3.8v1.2"></path><circle cx="9.25" cy="7.5" r="3.5"></circle><path d="M19 8.5v6"></path><path d="M16 11.5h6"></path>',
			),
			'user-check'   => array(
				'body' => '<path d="M15.5 19.5v-1.2a3.8 3.8 0 0 0-3.8-3.8H6.8a3.8 3.8 0 0 0-3.8 3.8v1.2"></path><circle cx="9.25" cy="7.5" r="3.5"></circle><path d="m16 11.8 2 2 4-4"></path>',
			),
			'bookmark'     => array(
				'body' => '<path d="M6 3.5h12a1 1 0 0 1 1 1V20l-7-4-7 4V4.5a1 1 0 0 1 1-1Z"></path>',
			),
			'star'         => array(
				'body' => '<polygon points="12 2.8 14.9 8.7 21.4 9.6 16.7 14.2 17.8 20.7 12 17.6 6.2 20.7 7.3 14.2 2.6 9.6 9.1 8.7 12 2.8"></polygon>',
			),
			'plus'         => array(
				'body' => '<path d="M12 5v14"></path><path d="M5 12h14"></path>',
			),
			'list-plus'    => array(
				'body' => '<path d="M3 6h12"></path><path d="M3 12h8"></path><path d="M3 18h8"></path><path d="M18 11v8"></path><path d="M14 15h8"></path>',
			),
			'playlist-add' => array(
				'body' => '<path d="M3 6h12"></path><path d="M3 12h8"></path><path d="M3 18h8"></path><path d="M18 11v8"></path><path d="M14 15h8"></path>',
			),
			'queue'        => array(
				'body' => '<path d="M4 6h16"></path><path d="M4 12h10"></path><path d="M4 18h10"></path><polygon points="16 13.5 21 16.5 16 19.5 16 13.5" fill="currentColor" stroke="none"></polygon>',
			),
			'queue-add'    => array(
				'body' => '<path d="M4 6h16"></path><path d="M4 12h10"></path><path d="M4 18h10"></path><polygon points="16 13.5 21 16.5 16 19.5 16 13.5" fill="currentColor" stroke="none"></polygon>',
			),
			'share'        => array(
				'body' => '<circle cx="18" cy="5" r="2.75"></circle><circle cx="6" cy="12" r="2.75"></circle><circle cx="18" cy="19" r="2.75"></circle><path d="m8.5 13.4 7 4.2"></path><path d="m15.5 6.4-7 4.2"></path>',
			),
			'send'         => array(
				'body' => '<path d="M21 3 10 14"></path><polygon points="21 3 14 21 10 14 3 10 21 3"></polygon>',
			),
			'link'         => array(
				'body' => '<path d="M10 13a5 5 0 0 0 7.07 0l2.83-2.83a5 5 0 0 0-7.07-7.07L11.2 4.7"></path><path d="M14 11a5 5 0 0 0-7.07 0L4.1 13.83a5 5 0 0 0 7.07 7.07L12.8 19.3"></path>',
			),
			'shuffle'      => array(
				'body' => '<path d="m18 14 4 4-4 4"></path><path d="m18 2 4 4-4 4"></path><path d="M2 18h2a4 4 0 0 0 3.3-1.7l5.4-8.6A4 4 0 0 1 16 6h6"></path><path d="M2 6h2a4 4 0 0 1 3.5 2.1"></path><path d="M22 18h-6a4 4 0 0 1-3.3-1.8l-.4-.5"></path>',
			),
			'sparkles'     => array(
				'body' => '<path d="m12 3 1.9 4.8L18.7 9.7l-4.8 1.9L12 16.4l-1.9-4.8-4.8-1.9 4.8-1.9L12 3Z"></path><path d="M19 15v4"></path><path d="M17 17h4"></path>',
			),
			'download'     => array(
				'body' => '<path d="M12 3.5v11.5"></path><path d="m7.5 10.5 4.5 4.5 4.5-4.5"></path><path d="M4.5 18.5v1a1.5 1.5 0 0 0 1.5 1.5h12a1.5 1.5 0 0 0 1.5-1.5v-1"></path>',
			),
			'folder-down'  => array(
				'body' => '<path d="M3 6.5A1.5 1.5 0 0 1 4.5 5h4.2l1.8 2H19.5A1.5 1.5 0 0 1 21 8.5v10a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18.5v-12Z"></path><path d="M12 10.5v6"></path><path d="m9.5 14 2.5 2.5 2.5-2.5"></path>',
			),
			'shield-check' => array(
				'body' => '<path d="M12 2.5 4.5 5.8v5.7c0 5 3.2 8.7 7.5 10 4.3-1.3 7.5-5 7.5-10V5.8L12 2.5Z"></path><path d="m9 12 2 2 4-4"></path>',
			),
		);
	}

	/**
	 * Normalize a requested icon slug against the canonical icon library.
	 *
	 * @param array<string, mixed> $attributes   Block attributes.
	 * @param string               $default_icon Semantic fallback icon slug.
	 * @param string               $attr_key     Attribute name (`icon` / `playIcon`).
	 */
	public static function resolve_icon_key( array $attributes, string $default_icon, string $attr_key = 'icon' ): string {
		$defs = self::icon_definitions();
		$raw  = isset( $attributes[ $attr_key ] ) && is_scalar( $attributes[ $attr_key ] )
			? sanitize_key( (string) $attributes[ $attr_key ] )
			: '';
		if ( '' !== $raw && isset( $defs[ $raw ] ) ) {
			return $raw;
		}

		return isset( $defs[ $default_icon ] ) ? $default_icon : 'play';
	}

	/**
	 * Render an action icon from a single custom input:
	 * - Empty string -> semantic default SVG icon for the block.
	 * - Registered project SVG library slug -> inline SVG from `icon_svg()`.
	 * - Inline `<svg>...</svg>` markup -> strictly sanitized vector markup.
	 * - CSS icon class string (`dashicons dashicons-controls-play`, `ri-play-fill`, etc.) -> `<i class="...">`.
	 *
	 * @param array<string, mixed> $attributes   Block attributes.
	 * @param string               $default_icon Semantic fallback icon slug.
	 * @param int                  $size         Pixel box size (12..40).
	 * @param string               $attr_key     Attribute name (`icon` / `playIcon`).
	 * @param string               $extra_class  Optional extra class on the rendered element.
	 */
	public static function render_icon(
		array $attributes,
		string $default_icon,
		int $size = 20,
		string $attr_key = 'icon',
		string $extra_class = ''
	): string {
		$dim = max( 12, min( 40, $size ) );
		$raw = isset( $attributes[ $attr_key ] ) && is_scalar( $attributes[ $attr_key ] )
			? trim( (string) $attributes[ $attr_key ] )
			: '';

		if ( '' === $raw ) {
			return self::icon_svg( $default_icon, $dim, $extra_class );
		}

		$defs = self::icon_definitions();
		$slug = sanitize_key( $raw );
		if ( '' !== $slug && strtolower( $raw ) === $slug && isset( $defs[ $slug ] ) ) {
			return self::icon_svg( $slug, $dim, $extra_class );
		}

		if ( false !== stripos( $raw, '<svg' ) ) {
			$sanitized_svg = self::sanitize_inline_svg( $raw, $dim, $extra_class );
			if ( '' !== $sanitized_svg ) {
				return $sanitized_svg;
			}
		}

		$class_html = self::render_css_icon_class( $raw, $dim, $extra_class );
		if ( '' !== $class_html ) {
			return $class_html;
		}

		return self::icon_svg( $default_icon, $dim, $extra_class );
	}

	/**
	 * Render an author-supplied icon, or nothing when the value is empty.
	 *
	 * Unlike `render_icon()`, an empty value yields no markup: section titles
	 * stay plain text by default and only gain a glyph when one is set.
	 *
	 * @param string $raw          Icon slug, CSS icon classes, or inline `<svg>`.
	 * @param int    $size         Pixel box size (12..40).
	 * @param string $extra_class  Optional extra class on the rendered element.
	 */
	public static function optional_icon( string $raw, int $size = 22, string $extra_class = '' ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		$dim  = max( 12, min( 40, $size ) );
		$defs = self::icon_definitions();
		$slug = sanitize_key( $raw );
		if ( '' !== $slug && strtolower( $raw ) === $slug && isset( $defs[ $slug ] ) ) {
			return self::icon_svg( $slug, $dim, $extra_class );
		}

		if ( false !== stripos( $raw, '<svg' ) ) {
			return self::sanitize_inline_svg( $raw, $dim, $extra_class );
		}

		return self::render_css_icon_class( $raw, $dim, $extra_class );
	}

	/**
	 * Strictly sanitize user-supplied inline `<svg>` vector markup (zero script/event vectors).
	 */
	public static function sanitize_inline_svg( string $raw_svg, int $size = 20, string $extra_class = '' ): string {
		$dim = max( 12, min( 40, $size ) );
		// Strip dangerous blocks completely before tag/attribute allowlisting.
		$cleaned = preg_replace( '#<(script|style|foreignObject|iframe|object|embed|use)\b[^>]*>.*?</\1>#is', '', $raw_svg );
		$cleaned = is_string( $cleaned ) ? $cleaned : '';
		$cleaned = preg_replace( '#<(script|style|foreignObject|iframe|object|embed|use)\b[^>]*/?>#is', '', $cleaned );
		$cleaned = is_string( $cleaned ) ? trim( $cleaned ) : '';
		if ( '' === $cleaned || false === stripos( $cleaned, '<svg' ) ) {
			return '';
		}

		$shape_attrs = array(
			'd'                 => true,
			'points'            => true,
			'cx'                => true,
			'cy'                => true,
			'r'                 => true,
			'rx'                => true,
			'ry'                => true,
			'x'                 => true,
			'y'                 => true,
			'x1'                => true,
			'y1'                => true,
			'x2'                => true,
			'y2'                => true,
			'width'             => true,
			'height'            => true,
			'fill'              => true,
			'fill-rule'         => true,
			'clip-rule'         => true,
			'fill-opacity'      => true,
			'stroke'            => true,
			'stroke-width'      => true,
			'stroke-linecap'    => true,
			'stroke-linejoin'   => true,
			'stroke-dasharray'  => true,
			'stroke-dashoffset' => true,
			'stroke-opacity'    => true,
			'opacity'           => true,
			'transform'         => true,
			'class'             => true,
		);

		$allowed_tags = array(
			'svg'      => array(
				'xmlns'           => true,
				'viewbox'         => true,
				'viewBox'         => true,
				'width'           => true,
				'height'          => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'class'           => true,
				'aria-hidden'     => true,
				'focusable'       => true,
			),
			'g'        => $shape_attrs,
			'path'     => $shape_attrs,
			'circle'   => $shape_attrs,
			'ellipse'  => $shape_attrs,
			'rect'     => $shape_attrs,
			'line'     => $shape_attrs,
			'polyline' => $shape_attrs,
			'polygon'  => $shape_attrs,
		);

		if ( function_exists( 'wp_kses' ) ) {
			$sanitized = wp_kses( $cleaned, $allowed_tags );
		} else {
			$sanitized = strip_tags( $cleaned, '<svg><g><path><circle><ellipse><rect><line><polyline><polygon>' );
			// Remove any residual on* attributes when running outside full WP kses.
			$sanitized = (string) preg_replace( '/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $sanitized );
		}

		// Preserve `viewBox` camelCase after HTML normalization so browsers scale vectors properly.
		$sanitized = (string) preg_replace( '/\bviewbox\s*=/i', 'viewBox=', trim( $sanitized ) );
		if ( '' === $sanitized || 0 !== stripos( $sanitized, '<svg' ) ) {
			return '';
		}

		$cls = trim( 'mw-btn-svg mw-btn-svg--custom' . ( '' !== $extra_class ? ' ' . $extra_class : '' ) );
		// Ensure crisp dimensions and screen-reader hiding on the root `<svg>`.
		$sanitized = (string) preg_replace_callback(
			'/^<svg\b([^>]*)>/i',
			static function ( array $matches ) use ( $dim, $cls ): string {
				$attrs = $matches[1];
				$attrs = (string) preg_replace( '/\s+(?:width|height|aria-hidden|focusable|class)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $attrs );
				return '<svg class="' . esc_attr( $cls ) . '" width="' . esc_attr( (string) $dim ) . '" height="' . esc_attr( (string) $dim ) . '" focusable="false" aria-hidden="true"' . $attrs . '>';
			},
			$sanitized,
			1
		);

		return $sanitized;
	}

	/**
	 * Render a clean `<i class="...">` icon element from CSS class tokens.
	 */
	private static function render_css_icon_class( string $raw_classes, int $size = 20, string $extra_class = '' ): string {
		// Reject raw HTML fragments that failed SVG validation so arbitrary markup never leaks into classes.
		if ( false !== strpos( $raw_classes, '<' ) || false !== strpos( $raw_classes, '>' ) ) {
			return '';
		}

		$tokens = preg_split( '/\s+/', trim( $raw_classes ) );
		$tokens = is_array( $tokens ) ? $tokens : array();
		$clean  = array();
		foreach ( $tokens as $token ) {
			$token = sanitize_html_class( $token );
			if ( '' !== $token ) {
				$clean[] = $token;
			}
		}
		if ( empty( $clean ) ) {
			return '';
		}

		if ( '' !== $extra_class ) {
			$extra_tokens = preg_split( '/\s+/', trim( $extra_class ) );
			if ( is_array( $extra_tokens ) ) {
				foreach ( $extra_tokens as $extra_token ) {
					$extra_token = sanitize_html_class( $extra_token );
					if ( '' !== $extra_token ) {
						$clean[] = $extra_token;
					}
				}
			}
		}
		$clean[] = 'mw-btn-icon-font';

		return '<i class="' . esc_attr( implode( ' ', array_unique( $clean ) ) ) . '" aria-hidden="true" style="font-size:' . esc_attr( (string) $size ) . 'px;width:' . esc_attr( (string) $size ) . 'px;height:' . esc_attr( (string) $size ) . 'px;line-height:1;display:inline-flex;align-items:center;justify-content:center;"></i>';
	}

	/**
	 * Render crisp inline SVG markup for any registered icon slug.
	 */
	public static function icon_svg( string $key, int $size = 20, string $extra_class = '' ): string {
		$defs = self::icon_definitions();
		$slug = isset( $defs[ $key ] ) ? $key : 'play';
		$def  = $defs[ $slug ];
		$dim  = max( 12, min( 40, $size ) );
		$cls  = trim( 'mw-btn-svg mw-btn-svg--' . $slug . ( '' !== $extra_class ? ' ' . $extra_class : '' ) );

		return '<svg class="' . esc_attr( $cls ) . '" viewBox="0 0 24 24" width="' . esc_attr( (string) $dim ) . '" height="' . esc_attr( (string) $dim ) . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true">'
			. $def['body']
			. '</svg>';
	}

	/**
	 * Render a play trigger glyph that swaps to "pause" while `aria-pressed="true"`.
	 *
	 * The two vectors share one grid cell, so the control keeps a rock-steady
	 * box between both states and the swap needs no JavaScript bookkeeping:
	 * `utilities.css` reveals the pause half through the pressed/playing state
	 * that the shared preview runtime already maintains (`aria-pressed`,
	 * `data-mw-playing`). Works for every action style — icon disc or labeled
	 * pill — and stays correct when the script never boots.
	 *
	 * @param string               $play_slug   Play icon slug.
	 * @param int                  $size        Pixel box size (12..40).
	 * @param array<string, mixed> $attributes  Block attributes (honours `playIcon` overrides).
	 * @param string               $attr_key    Attribute name carrying the icon override.
	 */
	public static function play_toggle_icon( string $play_slug = 'play-filled', int $size = 16, array $attributes = array(), string $attr_key = 'playIcon' ): string {
		$play = self::render_icon( $attributes, $play_slug, $size, $attr_key );

		return '<span class="mw-icon-swap">'
			. '<span class="mw-icon-swap__play">' . $play . '</span>'
			. '<span class="mw-icon-swap__pause">' . self::icon_svg( 'pause', $size ) . '</span>'
			. '</span>';
	}

	/**
	 * Resolve normalized button presentation mode (`icon` vs `button`).
	 *
	 * @param array<string, mixed> $attributes   Block attributes.
	 * @param string               $default_mode Default mode (`icon` or `button`).
	 * @param bool                 $allow_accent Legacy accent token compatibility.
	 */
	public static function resolve(
		array $attributes,
		string $default_mode = self::MODE_ICON,
		bool $allow_accent = false
	): string {
		$raw = '';
		if ( isset( $attributes['style'] ) && is_string( $attributes['style'] ) && '' !== trim( $attributes['style'] ) ) {
			$raw = sanitize_key( $attributes['style'] );
		}
		if ( '' === $raw && isset( $attributes['buttonStyle'] ) && is_scalar( $attributes['buttonStyle'] ) ) {
			$raw = sanitize_key( (string) $attributes['buttonStyle'] );
		}
		if ( '' === $raw && isset( $attributes['className'] ) && is_scalar( $attributes['className'] ) ) {
			$split_tokens = preg_split( '/\s+/', trim( (string) $attributes['className'] ) );
			$class_tokens = is_array( $split_tokens ) ? $split_tokens : array();
			if ( in_array( 'is-style-icon', $class_tokens, true ) ) {
				return self::MODE_ICON;
			}
			if ( in_array( 'is-style-button', $class_tokens, true ) ) {
				return self::MODE_BUTTON;
			}
		}

		if ( '' === $raw ) {
			return $default_mode;
		}
		if ( in_array( $raw, array( 'icon', 'heart' ), true ) ) {
			return self::MODE_ICON;
		}
		if ( $allow_accent && 'accent' === $raw ) {
			return self::MODE_ACCENT;
		}
		if ( in_array( $raw, array( 'button', 'solid', 'outline', 'ghost' ), true ) ) {
			return self::MODE_BUTTON;
		}

		return $default_mode;
	}

	/**
	 * Resolve visual surface variant (`accent`, `surface`, `outline`, `ghost`).
	 *
	 * @param array<string, mixed> $attributes      Block attributes.
	 * @param string               $default_variant Default variant token.
	 */
	public static function resolve_variant( array $attributes, string $default_variant = self::VARIANT_SURFACE ): string {
		$raw = isset( $attributes['variant'] ) && is_scalar( $attributes['variant'] )
			? sanitize_key( (string) $attributes['variant'] )
			: '';
		if ( in_array( $raw, array( self::VARIANT_ACCENT, self::VARIANT_SURFACE, self::VARIANT_OUTLINE, self::VARIANT_GHOST ), true ) ) {
			return $raw;
		}

		$legacy = isset( $attributes['style'] ) && is_scalar( $attributes['style'] )
			? sanitize_key( (string) $attributes['style'] )
			: '';
		if ( in_array( $legacy, array( 'outline', 'ghost' ), true ) ) {
			return $legacy;
		}
		if ( 'accent' === $legacy ) {
			return self::VARIANT_ACCENT;
		}

		return $default_variant;
	}

	/**
	 * Resolve button size token (`sm`, `md`, `lg`).
	 *
	 * @param array<string, mixed> $attributes   Block attributes.
	 * @param string               $default_size Default size token.
	 */
	public static function resolve_size( array $attributes, string $default_size = self::SIZE_MD ): string {
		$raw = isset( $attributes['size'] ) && is_scalar( $attributes['size'] )
			? sanitize_key( (string) $attributes['size'] )
			: '';
		if ( in_array( $raw, array( self::SIZE_SM, self::SIZE_MD, self::SIZE_LG ), true ) ) {
			return $raw;
		}
		if ( ! empty( $attributes['compact'] ) ) {
			return self::SIZE_SM;
		}

		return $default_size;
	}

	/**
	 * Resolve border corner shape token (`pill`, `rounded`).
	 *
	 * @param array<string, mixed> $attributes    Block attributes.
	 * @param string               $default_shape Default shape token.
	 */
	public static function resolve_shape( array $attributes, string $default_shape = self::SHAPE_PILL ): string {
		$raw = isset( $attributes['shape'] ) && is_scalar( $attributes['shape'] )
			? sanitize_key( (string) $attributes['shape'] )
			: '';
		return in_array( $raw, array( self::SHAPE_PILL, self::SHAPE_ROUNDED ), true ) ? $raw : $default_shape;
	}

	/**
	 * Whether visible button label text should render next to the icon.
	 */
	public static function shows_label( string $resolved_mode ): bool {
		return self::MODE_ICON !== $resolved_mode;
	}

	/**
	 * Compose full standardized class string for any action trigger button.
	 *
	 * @param array<string, mixed> $attributes      Block attributes.
	 * @param string               $component_class Component root class.
	 * @param string               $default_mode    Default display mode (`icon` or `button`).
	 * @param string               $default_variant Default surface variant.
	 */
	public static function button_classes(
		array $attributes,
		string $component_class,
		string $default_mode = self::MODE_ICON,
		string $default_variant = self::VARIANT_SURFACE
	): string {
		$mode     = self::resolve( $attributes, $default_mode );
		$css_mode = self::MODE_ICON === $mode ? self::MODE_ICON : self::MODE_BUTTON;
		$variant  = self::resolve_variant( $attributes, $default_variant );
		$size     = self::resolve_size( $attributes, self::SIZE_MD );
		$shape    = self::resolve_shape( $attributes, self::SHAPE_PILL );

		$classes = array(
			trim( $component_class ),
			'mw-action-button',
			'mw-action-button--' . $css_mode,
			'mw-action-button--' . $variant,
			'mw-action-button--' . $size,
			'mw-action-button--' . $shape,
			'mw-action-button--variant-' . $variant,
			'mw-action-button--size-' . $size,
			'mw-action-button--shape-' . $shape,
			trim( $component_class ) . '--' . $css_mode,
		);

		return implode( ' ', array_filter( $classes ) );
	}

	/**
	 * Export built-in vector icon presets with inline SVG markup for Editor Inspector pickers.
	 *
	 * @return array<int, array{slug: string, label: string, svg: string}>
	 */
	public static function preset_icons(): array {
		$labels = array(
			'play-filled' => __( 'پخش توپر', 'music-wave-core' ),
			'play'        => __( 'پخش خطی', 'music-wave-core' ),
			'play-circle' => __( 'پخش دایره‌ای', 'music-wave-core' ),
			'waveform'    => __( 'موج صوتی', 'music-wave-core' ),
			'headphones'  => __( 'هدفون', 'music-wave-core' ),
			'disc'        => __( 'دیسک موسیقی', 'music-wave-core' ),
			'download'    => __( 'دانلود', 'music-wave-core' ),
			'folder-down' => __( 'پوشه دانلود', 'music-wave-core' ),
			'queue'       => __( 'صف پخش', 'music-wave-core' ),
			'list-plus'   => __( 'افزودن لیست', 'music-wave-core' ),
			'heart'       => __( 'قلب', 'music-wave-core' ),
			'bookmark'    => __( 'نشانک', 'music-wave-core' ),
			'star'        => __( 'ستاره', 'music-wave-core' ),
			'user-plus'   => __( 'دنبال‌کردن', 'music-wave-core' ),
			'share'       => __( 'اشتراک', 'music-wave-core' ),
			'shuffle'     => __( 'تصادفی', 'music-wave-core' ),
		);

		$presets = array();
		foreach ( $labels as $slug => $label ) {
			$presets[] = array(
				'slug'  => $slug,
				'label' => $label,
				'svg'   => self::icon_svg( $slug, 16 ),
			);
		}

		return $presets;
	}
}
