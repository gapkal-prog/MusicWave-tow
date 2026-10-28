<?php
declare(strict_types=1);

namespace ManaCore\MusicWave\Theme\Header;

use ManaCore\MusicWave\Core\Support\SuggestAssets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unifies header search fields across inline stream top bars and popup headers,
 * wiring them to Core's ARIA 1.2 catalog suggest engine and preventing hidden
 * input DOM mismatches or duplicate legacy suggestion containers.
 */
final class HeaderSearchEnhancer {

	/** @var SiteHeaderManager */
	private SiteHeaderManager $header;

	public function __construct( SiteHeaderManager $header ) {
		$this->header = $header;
	}

	/**
	 * Register search block normalization and markup enhancement filters.
	 */
	public function register(): void {
		add_filter( 'render_block_data', array( $this, 'normalize_search_attributes' ), 10, 1 );
		add_filter( 'render_block', array( $this, 'enhance_header_search' ), 11, 2 );
		add_filter( 'render_block', array( $this, 'enhance_custom_header_search' ), 11, 2 );
		add_filter( 'render_block', array( $this, 'fix_stream_search_hidden_dom' ), 12, 2 );
		add_filter( 'render_block', array( $this, 'suppress_legacy_suggest_boxes' ), 20, 2 );
	}

	/**
	 * CSS classes identifying header search surfaces.
	 *
	 * @return array<int, string>
	 */
	public function search_classes(): array {
		return array( 'mw-header-search', 'mw-stream-search', 'mw-header-search-block' );
	}

	/**
	 * Maximum live suggestions displayed beneath header search inputs.
	 */
	public function suggest_limit(): int {
		$limit = (int) apply_filters( 'musicwave_header_suggest_limit', 3 );

		return max( 1, min( 10, $limit ) );
	}

	/**
	 * Build JSON configuration string and enqueue Core suggest assets when active.
	 */
	public function suggest_config( int $limit = 0 ): string {
		if ( ! class_exists( SuggestAssets::class ) ) {
			return '';
		}

		$effective = $limit > 0 ? $limit : $this->suggest_limit();
		$config    = SuggestAssets::field_config( $effective );
		SuggestAssets::enqueue();

		return $config;
	}

	/**
	 * Wrap legacy core/search header blocks in the unified autocomplete container.
	 *
	 * @param string               $block_content Rendered HTML.
	 * @param array<string, mixed> $block         Parsed block.
	 */
	public function enhance_header_search( string $block_content, array $block ): string {
		if ( 'core/search' !== ( $block['blockName'] ?? '' ) || $this->header->render_scope( 'peek' ) < 1 ) {
			return $block_content;
		}

		if ( false !== strpos( $block_content, 'mw-header-suggest' ) || false !== strpos( $block_content, 'mw-catalog-suggest' ) ) {
			return false !== strpos( $block_content, 'mw-stream-search' )
				? $this->strip_hidden_search_attributes( $block_content )
				: $block_content;
		}

		$is_header = false;
		foreach ( $this->search_classes() as $cls ) {
			if ( false !== strpos( $block_content, $cls ) ) {
				$is_header = true;
				break;
			}
		}
		if ( ! $is_header ) {
			return $block_content;
		}

		if ( false !== strpos( $block_content, 'mw-stream-search' ) ) {
			$block_content = $this->strip_hidden_search_attributes( $block_content, true );
		}

		$block_content = (string) preg_replace( '/<[^>]*class="[^"]*nbp-suggest[^"]*"[^>]*>.*?<\/[^>]+>/s', '', $block_content );
		$block_content = (string) preg_replace( '/<[^>]*class="[^"]*nbp-suggest[^"]*"[^>]*\/>/s', '', $block_content );

		$config = $this->suggest_config();
		$status = '<span class="screen-reader-text mw-catalog-suggest__status" role="status" aria-live="polite" data-mw-suggest-status></span>';

		return '<div class="mw-header-suggest' . ( '' !== $config ? ' mw-catalog-suggest' : '' ) . '"'
			. ( '' !== $config ? ' data-mw-suggest="' . esc_attr( $config ) . '"' : '' )
			. '>' . $block_content . $status . '</div>';
	}

	/**
	 * Remove duplicate legacy nbp-suggest containers when unified suggest is active.
	 *
	 * @param string               $block_content Rendered HTML.
	 * @param array<string, mixed> $block         Parsed block.
	 */
	public function suppress_legacy_suggest_boxes( string $block_content, array $block ): string {
		unset( $block );
		if ( false !== strpos( $block_content, 'nbp-suggest' )
			&& ( false !== strpos( $block_content, 'mw-catalog-suggest' ) || false !== strpos( $block_content, 'mw-header-suggest' ) )
		) {
			$block_content = (string) preg_replace( '/<ul[^>]*class="[^"]*nbp-suggest[^"]*"[^>]*>.*?<\/ul>/s', '', $block_content );
			$block_content = (string) preg_replace( '/<div[^>]*class="[^"]*nbp-suggest[^"]*"[^>]*>.*?<\/div>/s', '', $block_content );
		}

		return $block_content;
	}

	/**
	 * Normalize core/search block attributes for stream vs simple header layouts.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<string, mixed>
	 */
	public function normalize_search_attributes( array $block ): array {
		if ( 'core/search' !== ( $block['blockName'] ?? '' ) ) {
			return $block;
		}

		$classes         = isset( $block['attrs']['className'] ) && is_scalar( $block['attrs']['className'] ) ? (string) $block['attrs']['className'] : '';
		$is_stream       = (bool) preg_match( '/(^|\s)mw-stream-search(\s|$)/', $classes );
		$is_simple       = (bool) preg_match( '/(^|\s)mw-header-search(\s|$)/', $classes );
		$is_header_block = (bool) preg_match( '/(^|\s)mw-header-search-block(\s|$)/', $classes );

		if ( ! $is_stream && ! $is_simple && ! $is_header_block && $this->header->render_scope( 'peek' ) < 1 ) {
			return $block;
		}

		if ( $is_stream ) {
			$block['attrs']['buttonPosition'] = 'button-inside';
			if ( ! isset( $block['attrs']['showLabel'] ) ) {
				$block['attrs']['showLabel'] = false;
			}
			if ( isset( $block['attrs']['buttonUseIcon'] ) ) {
				$block['attrs']['buttonUseIcon'] = true;
			}
			return $block;
		}

		if ( $is_simple || $is_header_block ) {
			if ( isset( $block['attrs']['buttonPosition'] ) && is_string( $block['attrs']['buttonPosition'] ) && '' !== $block['attrs']['buttonPosition'] ) {
				return $block;
			}
			$block['attrs']['buttonPosition'] = 'button-only';
		}

		return $block;
	}

	/**
	 * Ensure mw-stream-search never ships hidden input attributes in rendered HTML.
	 *
	 * @param string               $content Rendered HTML.
	 * @param array<string, mixed> $block   Parsed block.
	 */
	public function fix_stream_search_hidden_dom( string $content, array $block ): string {
		if ( 'core/search' !== ( $block['blockName'] ?? '' ) ) {
			return $content;
		}
		$classes = $block['attrs']['className'] ?? '';
		if ( ! is_string( $classes ) || false === strpos( $classes, 'mw-stream-search' ) ) {
			return $content;
		}

		return $this->strip_hidden_search_attributes( $content, false, true );
	}

	/**
	 * Enqueue interactive popup behavior when rendering music-wave/header-search.
	 *
	 * @param string               $block_content Rendered HTML.
	 * @param array<string, mixed> $block         Parsed block.
	 */
	public function enhance_custom_header_search( string $block_content, array $block ): string {
		$name = $block['blockName'] ?? '';
		if ( 'music-wave/header-search' !== $name && 'musicwave/header-search' !== $name ) {
			return $block_content;
		}
		if ( function_exists( 'wp_enqueue_script' ) ) {
			wp_enqueue_script( 'musicwave-header-search' );
		}

		return $block_content;
	}

	/**
	 * Single canonical sanitizer stripping WordPress button-only hidden attributes
	 * from inline stream search inputs.
	 */
	private function strip_hidden_search_attributes( string $html, bool $expand_button = false, bool $visible_class = false ): string {
		$replacement = $visible_class ? 'wp-block-search__searchfield-visible' : '';
		$html        = str_replace( 'wp-block-search__searchfield-hidden', $replacement, $html );
		$html        = (string) preg_replace( '/\s*aria-hidden=(?:"true"|\'true\')/', '', $html );
		$html        = (string) preg_replace( '/\s*tabindex=(?:"-1"|\'-1\')/', '', $html );
		$html        = (string) preg_replace( '/\s+hidden(\s|>|\/)/', ' $1', $html );
		if ( $expand_button ) {
			$html = str_replace( 'aria-expanded="false"', 'aria-expanded="true"', $html );
		}

		return $html;
	}
}
