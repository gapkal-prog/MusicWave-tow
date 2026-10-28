<?php
/**
 * Shared registration, editor context, placeholder, form, and wrapper helpers
 * for dynamic MusicWave blocks (SOLID SRP + DRY).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BlockSupport {
	/**
	 * Return the inspector controls supported by presentational dynamic blocks.
	 *
	 * @return array<string, mixed>
	 */
	public static function appearance_tools(): array {
		return array(
			'anchor'     => true,
			'align'      => array( 'wide', 'full' ),
			'border'     => array(
				'color'  => true,
				'radius' => true,
				'style'  => true,
				'width'  => true,
			),
			'color'      => array(
				'background' => true,
				'link'       => true,
				'text'       => true,
			),
			'spacing'    => array(
				'blockGap' => true,
				'margin'   => true,
				'padding'  => true,
			),
			'typography' => array(
				'fontSize'   => true,
				'lineHeight' => true,
			),
		);
	}

	/**
	 * Build an attribute string that includes WordPress block support styles
	 * and merges optional inline CSS declarations into a single valid HTML5
	 * `style="..."` attribute.
	 *
	 * @param string $class_names   Theme component classes for the root element.
	 * @param string $inline_styles Optional inline CSS declarations (`--mw-x:1;`).
	 * @return string
	 */
	public static function wrapper_attributes( string $class_names, string $inline_styles = '' ): string {
		$style = trim( $inline_styles );
		if ( function_exists( 'get_block_wrapper_attributes' ) ) {
			$extra = array( 'class' => $class_names );
			if ( '' !== $style ) {
				$extra['style'] = $style;
			}
			return get_block_wrapper_attributes( $extra );
		}

		return 'class="' . esc_attr( $class_names ) . '"' . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' );
	}

	/**
	 * Read the active editor style variation from the block className.
	 *
	 * Returns the first allow-listed variation present as an is-style-* class,
	 * or an empty string when the default style is active.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<int, string>   $allowed    Allowed variation names.
	 */
	public static function style_variation( array $attributes, array $allowed ): string {
		$class_name = isset( $attributes['className'] ) && is_scalar( $attributes['className'] ) ? trim( (string) $attributes['className'] ) : '';
		if ( '' === $class_name && defined( 'REST_REQUEST' ) && REST_REQUEST && isset( $_GET['mw_style_class'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only SSR style preview query argument.
			$raw_query_class = wp_unslash( $_GET['mw_style_class'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$class_name      = is_scalar( $raw_query_class ) ? sanitize_text_field( (string) $raw_query_class ) : '';
		}
		if ( '' === $class_name ) {
			return '';
		}

		$tokens = preg_split( '/\s+/', $class_name );
		$tokens = is_array( $tokens ) ? $tokens : array();
		foreach ( $allowed as $style_name ) {
			if ( in_array( 'is-style-' . $style_name, $tokens, true ) ) {
				return $style_name;
			}
		}

		return '';
	}

	/**
	 * Register one dynamic block from its block.json folder when present.
	 *
	 * @param string               $block_name      Full block name, e.g. music-wave/release-meta.
	 * @param callable             $render_callback Server-side renderer.
	 * @param array<string, mixed> $fallback_args   Arguments used when block.json is unavailable.
	 * @return void
	 */
	public static function register_dynamic( string $block_name, callable $render_callback, array $fallback_args = array() ): void {
		$metadata = BlockMetadata::load( $block_name );
		if ( null !== $metadata ) {
			register_block_type(
				BlockMetadata::directory( $block_name ),
				array( 'render_callback' => $render_callback )
			);

			return;
		}

		$args                    = array_merge( BlockMetadata::registration_args( array() ), $fallback_args );
		$args['render_callback'] = $render_callback;
		register_block_type( $block_name, $args );
	}

	/**
	 * Read a sanitized plain-text attribute with a translated fallback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $key        Attribute name.
	 * @param string               $fallback   Translated default text.
	 */
	public static function text_attribute( array $attributes, string $key, string $fallback ): string {
		$value = isset( $attributes[ $key ] ) && is_scalar( $attributes[ $key ] ) ? sanitize_text_field( (string) $attributes[ $key ] ) : '';

		return '' !== $value ? $value : $fallback;
	}

	/**
	 * Resolve optional heading/description text where clearing the field hides the element.
	 *
	 * - Honors legacy explicit boolean flag (`$legacy_toggle => false`) for backwards compatibility.
	 * - Returns `''` when `$key` is explicitly present in `$attributes` as empty whitespace (`""`).
	 * - Falls back to `$fallback` only when `$key` is omitted entirely (bare block comment / PHP call).
	 *
	 * @param array<string, mixed> $attributes    Block attributes.
	 * @param string               $key           Attribute name (`heading`, `description`, etc.).
	 * @param string               $fallback      Default text when attribute is absent.
	 * @param string               $legacy_toggle Optional legacy boolean attribute name.
	 */
	public static function optional_text_attribute(
		array $attributes,
		string $key,
		string $fallback = '',
		string $legacy_toggle = ''
	): string {
		if ( '' !== $legacy_toggle && array_key_exists( $legacy_toggle, $attributes ) && ! $attributes[ $legacy_toggle ] ) {
			return '';
		}

		if ( array_key_exists( $key, $attributes ) ) {
			return is_scalar( $attributes[ $key ] ) ? trim( sanitize_text_field( (string) $attributes[ $key ] ) ) : '';
		}

		return $fallback;
	}

	/**
	 * Read an allow-listed key attribute, falling back when the value is unknown.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $key        Attribute name.
	 * @param array<int, string>   $allowed    Allowed key values.
	 * @param string               $fallback   Value used for unknown keys.
	 */
	public static function key_attribute( array $attributes, string $key, array $allowed, string $fallback ): string {
		$value = isset( $attributes[ $key ] ) && is_scalar( $attributes[ $key ] ) ? sanitize_key( (string) $attributes[ $key ] ) : '';

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Read a bounded integer block attribute, falling back when out of range.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $key        Attribute name.
	 * @param int                  $min        Minimum allowed integer.
	 * @param int                  $max        Maximum allowed integer.
	 * @param int                  $fallback   Value used when missing or out of range.
	 */
	public static function range_attribute( array $attributes, string $key, int $min, int $max, int $fallback ): int {
		if ( ! isset( $attributes[ $key ] ) || ! is_numeric( $attributes[ $key ] ) ) {
			return $fallback;
		}

		$value = (int) $attributes[ $key ];

		return $value >= $min && $value <= $max ? $value : $fallback;
	}

	/**
	 * Read a boolean block attribute with an explicit default.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $key        Attribute name.
	 * @param bool                 $fallback   Value used when the attribute is absent.
	 */
	public static function bool_attribute( array $attributes, string $key, bool $fallback ): bool {
		if ( ! isset( $attributes[ $key ] ) ) {
			return $fallback;
		}

		return (bool) $attributes[ $key ];
	}

	/**
	 * Whether the current request is rendering inside the Block Editor / Site Editor.
	 *
	 * Matches genuine editorial surfaces (`wp-admin` non-AJAX screens, `/wp/v2/block-renderer/`,
	 * and explicit `?context=edit` REST preloads) while NEVER leaking editor placeholders or
	 * diagnostics onto public `context=view` REST routes or `admin-ajax.php` requests.
	 */
	public static function is_editor_context(): bool {
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			if ( ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() ) {
				return true;
			}
		}
		$raw_uri     = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$request_uri = function_exists( 'sanitize_text_field' ) && function_exists( 'wp_unslash' ) ? sanitize_text_field( wp_unslash( $raw_uri ) ) : $raw_uri;
		if ( false !== strpos( $request_uri, '/wp/v2/block-renderer/' ) ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor context check.
		$context = isset( $_GET['context'] ) && is_scalar( $_GET['context'] ) ? sanitize_key( wp_unslash( (string) $_GET['context'] ) ) : '';
		if ( 'edit' === $context ) {
			return true;
		}
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen instanceof \WP_Screen && $screen->is_block_editor() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Shared editor-only placeholder card so ServerSideRender never triggers
	 * ErrorResponsePlaceholder when contextual data is empty in Site Editor.
	 */
	public static function editor_placeholder( string $label, string $help, string $class_names, string $dashicon = 'dashicons-format-audio' ): string {
		return '<div class="' . esc_attr( $class_names ) . '" style="border:1px dashed currentColor;border-radius:12px;padding:2.5rem 1.5rem;text-align:center;opacity:.8;">'
			. '<span class="dashicons ' . esc_attr( $dashicon ) . '" aria-hidden="true" style="font-size:2rem;width:2rem;height:2rem;"></span>'
			. '<p style="margin:.5rem 0 0;"><strong>' . esc_html( $label ) . '</strong></p>'
			. '<p style="margin:.25rem 0 0;font-size:.875em;">' . esc_html( $help ) . '</p>'
			. '</div>';
	}

	/**
	 * Compact localized relative-time label shared across listening and playlist blocks.
	 */
	public static function relative_time_label( int $timestamp ): string {
		$diff = time() - $timestamp;
		if ( $diff < 60 ) {
			return __( 'همین الان', 'music-wave-core' );
		}
		if ( $diff < HOUR_IN_SECONDS ) {
			return sprintf( /* translators: %d: minutes. */ _n( '%d دقیقه پیش', '%d دقیقه پیش', (int) floor( $diff / 60 ), 'music-wave-core' ), (int) floor( $diff / 60 ) );
		}
		if ( $diff < DAY_IN_SECONDS ) {
			return sprintf( /* translators: %d: hours. */ _n( '%d ساعت قبل', '%d ساعت پیش', (int) floor( $diff / 3600 ), 'music-wave-core' ), (int) floor( $diff / 3600 ) );
		}
		if ( $diff < 30 * DAY_IN_SECONDS ) {
			return sprintf( /* translators: %d: days. */ _n( '%d روز پیش', '%d روز پیش', (int) floor( $diff / 86400 ), 'music-wave-core' ), (int) floor( $diff / 86400 ) );
		}

		return gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Shared hidden CSRF/action/operation/redirect inputs for front-end admin-post forms.
	 */
	public static function hidden_action_fields( string $nonce_action, string $post_action, string $operation = '' ): string {
		return wp_nonce_field( $nonce_action, '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="' . esc_attr( $post_action ) . '">'
			. ( '' !== $operation ? '<input type="hidden" name="mw_operation" value="' . esc_attr( $operation ) . '">' : '' )
			. '<input type="hidden" name="mw_redirect" value="' . esc_attr( self::current_url() ) . '">';
	}

	/**
	 * Shared accessible PRG status notice markup for front-end forms.
	 */
	public static function prg_notice( string $message, bool $is_error, string $base_class ): string {
		if ( '' === $message ) {
			return '';
		}

		$class = $base_class . ( $is_error ? ' ' . $base_class . '--error' : '' );

		return '<p class="' . esc_attr( $class ) . '" role="status" aria-live="polite">' . esc_html( $message ) . '</p>';
	}

	/**
	 * Best-effort current URL for sign-in redirects and self-referencing forms.
	 */
	public static function current_url(): string {
		if ( function_exists( 'is_singular' ) && is_singular() ) {
			$permalink = get_permalink();
			if ( is_string( $permalink ) && '' !== $permalink ) {
				return $permalink;
			}
		}

		$request = isset( $_SERVER['REQUEST_URI'] ) && is_scalar( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $request ) {
			return home_url( '/' );
		}

		$subdirectory = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$prefix       = '' !== $subdirectory ? rtrim( $subdirectory, '/' ) : '';
		if ( '' !== $prefix && 0 === strpos( $request, $prefix ) ) {
			$remainder = substr( $request, strlen( $prefix ) );
			if ( '' === $remainder || 0 === strpos( $remainder, '/' ) || 0 === strpos( $remainder, '?' ) ) {
				$request = '' === $remainder ? '/' : $remainder;
			}
		}

		return home_url( $request );
	}
}
