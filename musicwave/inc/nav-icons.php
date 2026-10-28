<?php
/**
 * Navigation icon facade delegating to ManaCore\MusicWave\Theme\Navigation\NavigationIconService.
 *
 * @package MusicWave
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return array<string, string>
 */
function musicwave_nav_icons(): array {
	return musicwave_theme()->nav_icons()->icons();
}

/**
 * @return array<string, array<int, string>>
 */
function musicwave_nav_icon_keywords(): array {
	return musicwave_theme()->nav_icons()->keywords();
}

function musicwave_nav_icon_key( string $class_name, string $label, string $url, bool $auto ): string {
	return musicwave_theme()->nav_icons()->resolve_key( $class_name, $label, $url, $auto );
}

/**
 * @param array<string, mixed> $args Block type args.
 * @return array<string, mixed>
 */
function musicwave_nav_icon_context( array $args, string $name ): array {
	return musicwave_theme()->nav_icons()->filter_block_context( $args, $name );
}

/**
 * @param array<string, mixed> $block    Parsed block.
 * @param mixed                $instance Block instance.
 */
function musicwave_nav_icon_render( string $content, array $block, $instance = null ): string {
	return musicwave_theme()->nav_icons()->render_nav_icon( $content, $block, $instance );
}

/**
 * @param array<string, mixed> $block Parsed block.
 */
function musicwave_render_rail_user( string $content, array $block ): string {
	return musicwave_theme()->nav_icons()->render_rail_user( $content, $block );
}
