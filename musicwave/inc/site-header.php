<?php
/**
 * Site-wide header chrome facade delegating to ManaCore\MusicWave\Theme\Header\SiteHeaderManager.
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
function musicwave_builtin_header_choices(): array {
	return musicwave_theme()->header()->builtin_choices();
}

/**
 * @return array<string, string>
 */
function musicwave_registered_header_choices(): array {
	return musicwave_theme()->header()->registered_choices();
}

/**
 * @return array<string, string>
 */
function musicwave_site_header_choices(): array {
	return musicwave_theme()->header()->site_header_choices();
}

/**
 * @param mixed $value Raw input.
 */
function musicwave_sanitize_site_header( $value ): string {
	return musicwave_theme()->header()->sanitize_site_header( $value );
}

function musicwave_active_header_slug(): string {
	return musicwave_theme()->header()->active_header_slug();
}

function musicwave_site_header_slug(): string {
	return musicwave_theme()->header()->site_header_slug();
}

/**
 * Bottom navigation bar scopes offered in the Customizer.
 *
 * @return array<string, string>
 */
function musicwave_tabbar_choices(): array {
	return musicwave_theme()->header()->tabbar_choices();
}

/**
 * @param mixed $value Raw input.
 */
function musicwave_sanitize_tabbar_scope( $value ): string {
	return musicwave_theme()->header()->sanitize_tabbar_scope( $value );
}

function musicwave_tabbar_scope(): string {
	return musicwave_theme()->header()->tabbar_scope();
}

function musicwave_tabbar_visible(): bool {
	return musicwave_theme()->header()->tabbar_visible();
}

/**
 * @param mixed $value Raw input.
 */
function musicwave_sanitize_tabbar_labels( $value ): bool {
	return musicwave_theme()->header()->sanitize_tabbar_labels( $value );
}

function musicwave_tabbar_labels_enabled(): bool {
	return musicwave_theme()->header()->tabbar_labels_enabled();
}

function musicwave_tabbar_block_markup(): string {
	return musicwave_theme()->header()->tabbar_block_markup();
}

function musicwave_shape_header_part_content( string $content ): string {
	return musicwave_theme()->header()->shape_header_part_content( $content );
}

function musicwave_append_header_tabbar( string $block_content, array $block ): string {
	return musicwave_theme()->header()->append_header_tabbar( $block_content, $block );
}

/**
 * @return array<string, string>
 */
function musicwave_header_chrome_attrs(): array {
	return musicwave_theme()->header()->chrome_attrs();
}

function musicwave_mobile_header_language_attributes( string $output ): string {
	return musicwave_theme()->header()->filter_language_attributes( $output );
}

/**
 * @param array<string, mixed> $block Parsed block.
 */
function musicwave_remap_header_template_part( string $block_content, array $block ): string {
	return musicwave_theme()->header()->remap_header_template_part( $block_content, $block );
}

function musicwave_rewrite_header_part_slugs( string $content ): string {
	return musicwave_theme()->header()->rewrite_header_part_slugs( $content );
}

/**
 * @param mixed $template Template object or null.
 * @return mixed
 */
function musicwave_sync_header_template_content( $template, string $id, string $template_type ) {
	return musicwave_theme()->header()->sync_header_template_content( $template, $id, $template_type );
}

/**
 * @param array<int, mixed>    $templates Template objects.
 * @param array<string, mixed> $query     Query args.
 * @return array<int, mixed>
 */
function musicwave_sync_header_templates_content( array $templates, array $query, string $template_type ): array {
	return musicwave_theme()->header()->sync_header_templates_content( $templates, $query, $template_type );
}

function musicwave_site_editor_part_url( string $slug ): string {
	return musicwave_theme()->header()->site_editor_part_url( $slug );
}

/**
 * @return array<int, string>
 */
function musicwave_header_replaced_blocks(): array {
	return musicwave_theme()->header()->replaced_commerce_blocks();
}

function musicwave_header_render_scope( string $action, string $slug = '' ): int {
	return musicwave_theme()->header()->render_scope( $action, $slug );
}

/**
 * @param mixed                $pre          Pre-rendered content or null.
 * @param array<string, mixed> $parsed_block Parsed block.
 * @return mixed
 */
function musicwave_flag_header_template_part( $pre, array $parsed_block ) {
	return musicwave_theme()->header()->flag_header_template_part( $pre, $parsed_block );
}

/**
 * @param array<string, mixed> $block Parsed block.
 */
function musicwave_release_header_template_part( string $block_content, array $block ): string {
	return musicwave_theme()->header()->release_header_template_part( $block_content, $block );
}

/**
 * @param mixed                $pre          Pre-rendered content or null.
 * @param array<string, mixed> $parsed_block Parsed block.
 * @return mixed
 */
function musicwave_strip_header_commerce_blocks( $pre, array $parsed_block ) {
	return musicwave_theme()->header()->strip_header_commerce_blocks( $pre, $parsed_block );
}

/**
 * @param mixed $wp_customize Customizer manager.
 */
function musicwave_register_header_customizer( $wp_customize ): void {
	musicwave_theme()->header()->register_customizer( $wp_customize );
}

/**
 * @param array<int, string> $classes Body classes.
 * @return array<int, string>
 */
function musicwave_release_body_class( array $classes ): array {
	return musicwave_theme()->header()->filter_release_body_class( $classes );
}

/**
 * @return array<string, string>
 */
function musicwave_header_width_choices(): array {
	return musicwave_theme()->header()->header_width_choices();
}

/**
 * @param mixed $value Raw input.
 */
function musicwave_sanitize_header_width( $value ): string {
	return musicwave_theme()->header()->sanitize_header_width( $value );
}

function musicwave_header_width_mode(): string {
	return musicwave_theme()->header()->header_width_mode();
}

function musicwave_header_width_style(): string {
	return musicwave_theme()->header()->header_width_style();
}

/**
 * @param array<string, mixed> $block Parsed block.
 */
function musicwave_apply_header_width_mode( string $block_content, array $block ): string {
	return musicwave_theme()->header()->apply_header_width_mode( $block_content, $block );
}
