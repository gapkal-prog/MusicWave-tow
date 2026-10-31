<?php
/**
 * `[musicwave_block]` bridge for page builders (Elementor, etc.).
 *
 * Elementor and similar builders embed arbitrary markup through a Shortcode
 * widget, so a single shortcode that renders a registered MusicWave block is
 * the dependency-free way to reuse the block library inside those editors.
 *
 * Only the project's own `music-wave/*` namespace is accepted, and the JSON
 * attribute bag is intersected with the block's declared attributes, so the
 * shortcode cannot be used to reach core blocks or inject undeclared data.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BlockShortcode {
	/** Shortcode tag consumed by builder widgets. */
	public const TAG = 'musicwave_block';

	/** Only blocks registered by this project are renderable. */
	public const NAMESPACE_PREFIX = 'music-wave/';

	/**
	 * Resolves the declared attribute names for a block.
	 *
	 * @var callable(string):(array<int, string>|null)
	 */
	private $attribute_provider;

	/**
	 * @param callable(string):(array<int, string>|null)|null $attribute_provider
	 *        Optional resolver, mainly for tests. Returning null means "registry
	 *        unavailable, do not filter"; an array is the allow-list.
	 */
	public function __construct( ?callable $attribute_provider = null ) {
		$this->attribute_provider = $attribute_provider ?? array( self::class, 'registered_attribute_names' );
	}

	/**
	 * Register the shortcode.
	 */
	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'shortcode' ) );
	}

	/**
	 * Render one block for a page builder.
	 *
	 * Usage:
	 *     [musicwave_block name="music-wave/release-shelf" attributes='{"layout":"grid","itemsToShow":8}']
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes.
	 * @param string|null                 $content Enclosed content (unused).
	 * @param string                      $tag     Shortcode tag (unused).
	 * @return string Rendered block markup, or an empty string on invalid input.
	 */
	public function shortcode( $atts = array(), $content = null, $tag = '' ): string {
		unset( $content, $tag );

		$atts = is_array( $atts ) ? $atts : array();
		$name = isset( $atts['name'] ) && is_string( $atts['name'] ) ? trim( $atts['name'] ) : '';

		if ( ! $this->is_supported_name( $name ) ) {
			return '';
		}

		$attributes = $this->parse_attributes( $atts );
		if ( null === $attributes ) {
			return '';
		}

		$allowed = call_user_func( $this->attribute_provider, $name );
		if ( is_array( $allowed ) ) {
			$attributes = array_intersect_key( $attributes, array_flip( $allowed ) );
		}

		if ( ! function_exists( 'render_block' ) ) {
			return '';
		}

		$html = render_block(
			array(
				'blockName'    => $name,
				'attrs'        => $attributes,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Whether the block name is a well-formed MusicWave block.
	 */
	private function is_supported_name( string $name ): bool {
		if ( 0 !== strpos( $name, self::NAMESPACE_PREFIX ) ) {
			return false;
		}

		return 1 === preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $name );
	}

	/**
	 * Decode the JSON attribute bag.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @return array<string, mixed>|null Null when the JSON is invalid.
	 */
	private function parse_attributes( array $atts ): ?array {
		$raw = $atts['attributes'] ?? ( $atts['attrs'] ?? '' );
		if ( ! is_string( $raw ) ) {
			return array();
		}

		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Declared attribute names from the block registry.
	 *
	 * @return array<int, string>|null Null when block metadata is unavailable.
	 */
	public static function registered_attribute_names( string $name ): ?array {
		if ( ! class_exists( '\WP_Block_Type_Registry' ) ) {
			return null;
		}

		$registry = \WP_Block_Type_Registry::get_instance();
		$block    = $registry->get_registered( $name );

		if ( ! is_object( $block ) || ! isset( $block->attributes ) ) {
			// Unknown block: "registry unavailable, do not filter" so the
			// shortcode's explicit attributes reach render_block() untouched
			// (returning an empty array would strip every attribute).
			return null;
		}

		return is_array( $block->attributes ) ? array_keys( $block->attributes ) : array();
	}
}
