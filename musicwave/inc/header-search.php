<?php
/**
 * Header search facade delegating to ManaCore\MusicWave\Theme\Header\HeaderSearchEnhancer.
 *
 * @package MusicWave
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return array<int, string>
 */
function musicwave_header_search_classes(): array {
	return musicwave_theme()->header_search()->search_classes();
}

function musicwave_header_suggest_limit(): int {
	return musicwave_theme()->header_search()->suggest_limit();
}

function musicwave_header_suggest_config( int $limit = 0 ): string {
	return musicwave_theme()->header_search()->suggest_config( $limit );
}

/**
 * @param array<string, mixed> $block Parsed block.
 */
function musicwave_enhance_header_search( string $block_content, array $block ): string {
	return musicwave_theme()->header_search()->enhance_header_search( $block_content, $block );
}

/**
 * @param array<string, mixed> $block Parsed block.
 */
function musicwave_suppress_legacy_suggest_boxes( string $block_content, array $block ): string {
	return musicwave_theme()->header_search()->suppress_legacy_suggest_boxes( $block_content, $block );
}

/**
 * @param array<string, mixed> $block Parsed block.
 * @return array<string, mixed>
 */
function musicwave_normalize_header_search_attributes( array $block ): array {
	return musicwave_theme()->header_search()->normalize_search_attributes( $block );
}

/**
 * @param array<string, mixed> $block Parsed block.
 */
function musicwave_fix_stream_search_hidden_dom( string $content, array $block ): string {
	return musicwave_theme()->header_search()->fix_stream_search_hidden_dom( $content, $block );
}

/**
 * @param array<string, mixed> $block Parsed block.
 */
function musicwave_enhance_custom_header_search( string $block_content, array $block ): string {
	return musicwave_theme()->header_search()->enhance_custom_header_search( $block_content, $block );
}
