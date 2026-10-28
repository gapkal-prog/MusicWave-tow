<?php
/**
 * MusicWave theme bootstrap and OOP service facade.
 *
 * All theme domain logic lives in PSR-4 classes under `musicwave/src/`
 * (`ManaCore\MusicWave\Theme\*`). This file registers the autoloader, boots
 * `Theme::instance()`, and exposes typed procedural wrappers for WordPress
 * hook callbacks and template-level consumers.
 *
 * @package MusicWave
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once get_template_directory() . '/src/Autoloader.php';

\ManaCore\MusicWave\Theme\Autoloader::register( get_template_directory() . '/src' );

/**
 * Access the shared MusicWave OOP theme container.
 */
function musicwave_theme(): \ManaCore\MusicWave\Theme\Theme {
	return \ManaCore\MusicWave\Theme\Theme::instance();
}

function musicwave_core_admin_notice(): void {
	musicwave_theme()->assets()->render_core_admin_notice();
}

function musicwave_setup(): void {
	musicwave_theme()->templates()->setup();
}

function musicwave_skip_link(): void {
	musicwave_theme()->templates()->skip_link();
}

function musicwave_filter_main_id( string $block_content, array $block ): string {
	return musicwave_theme()->templates()->filter_main_id( $block_content, $block );
}

/**
 * @return array<string, array{file: string, dependencies: array<int, string>}>
 */
function musicwave_style_modules(): array {
	return musicwave_theme()->assets()->style_modules();
}

/**
 * @return array<int, string>
 */
function musicwave_editor_style_files(): array {
	return musicwave_theme()->assets()->editor_style_files();
}

function musicwave_enqueue_assets(): void {
	musicwave_theme()->assets()->enqueue_assets();
}

function musicwave_enqueue_conditional_styles(): void {
	musicwave_theme()->assets()->enqueue_conditional_styles();
}

function musicwave_defer_scripts( string $tag, string $handle ): string {
	return musicwave_theme()->assets()->defer_scripts( $tag, $handle );
}

function musicwave_preload_theme_preference(): void {
	musicwave_theme()->assets()->preload_theme_preference();
}

function musicwave_native_color_scheme(): string {
	return musicwave_theme()->assets()->native_color_scheme();
}

function musicwave_is_light_color( string $hex ): bool {
	return musicwave_theme()->assets()->is_light_color( $hex );
}

function musicwave_language_attributes( string $output ): string {
	return musicwave_theme()->assets()->language_attributes( $output );
}

/**
 * @param array<int|string, mixed> $urls          Resource hint URLs.
 * @param string                   $relation_type Relation type.
 * @return array<int|string, mixed>
 */
function musicwave_resource_hints( array $urls, string $relation_type ): array {
	return musicwave_theme()->assets()->resource_hints( $urls, $relation_type );
}

function musicwave_preload_local_font(): void {
	musicwave_theme()->assets()->preload_local_font();
}

function musicwave_filter_script_tag( string $tag, string $handle ): string {
	return musicwave_theme()->assets()->filter_script_tag( $tag, $handle );
}

/**
 * @param mixed $scripts WordPress scripts object.
 */
function musicwave_maybe_remove_jquery_migrate( $scripts ): void {
	musicwave_theme()->assets()->maybe_remove_jquery_migrate( $scripts );
}

function musicwave_register_pattern_categories(): void {
	musicwave_theme()->blocks()->register_pattern_categories();
}

/**
 * @param array<int, array<string, mixed>> $categories Existing categories.
 * @return array<int, array<string, mixed>>
 */
function musicwave_register_block_category( array $categories ): array {
	return musicwave_theme()->blocks()->register_block_category( $categories );
}

/**
 * @return array<string, array<string, array{label: string, hint: string}>>
 */
function musicwave_presentation_style_variations(): array {
	return musicwave_theme()->blocks()->presentation_style_variations();
}

/**
 * @param array<string, mixed> $attributes Block attributes.
 * @param array<int, string>   $allowed    Allowed variation slugs.
 */
function musicwave_style_variant( array $attributes, array $allowed ): string {
	return musicwave_theme()->blocks()->style_variant( $attributes, $allowed );
}

/**
 * @param array<string, mixed> $attributes Block attributes.
 * @param array<int, string>   $allowed    Allowed variation slugs.
 */
function musicwave_style_variant_class( array $attributes, array $allowed, string $component ): string {
	return musicwave_theme()->blocks()->style_variant_class( $attributes, $allowed, $component );
}

function musicwave_register_block_styles(): void {
	musicwave_theme()->blocks()->register_block_styles();
}

function musicwave_widgets_init(): void {
	musicwave_theme()->templates()->register_widgets();
}

/**
 * @return array<string, mixed>|null
 */
function musicwave_load_block_json( string $block_dir ): ?array {
	return musicwave_theme()->blocks()->load_block_json( $block_dir );
}

/**
 * @return array<string, mixed>|null
 */
function musicwave_block_metadata_entry( string $block_dir ): ?array {
	return musicwave_theme()->blocks()->block_metadata_entry( $block_dir );
}

/**
 * @return array<int, string>
 */
function musicwave_static_block_dirs(): array {
	return musicwave_theme()->blocks()->static_block_dirs();
}

function musicwave_register_static_blocks(): void {
	musicwave_theme()->blocks()->register_static_blocks();
}

/**
 * @return array<string, string>
 */
function musicwave_section_head_styles(): array {
	return musicwave_theme()->blocks()->section_head_styles();
}

/**
 * @param array<string, mixed> $metadata Decoded block.json metadata.
 */
function musicwave_register_legacy_presentation_block( string $dir, string $callback, array $metadata ): void {
	musicwave_theme()->blocks()->register_legacy_presentation_block( $dir, $callback, $metadata );
}

function musicwave_register_presentation_blocks(): void {
	musicwave_theme()->blocks()->register_presentation_blocks();
}

function musicwave_enqueue_presentation_editor_blocks(): void {
	musicwave_theme()->blocks()->enqueue_presentation_editor_blocks();
}

function musicwave_register_template_repair_page(): void {
	musicwave_theme()->templates()->register_template_repair_page();
}

/**
 * @return array<string, array<int, string>>
 */
function musicwave_repairable_template_slugs(): array {
	return musicwave_theme()->templates()->repairable_template_slugs();
}

function musicwave_account_template_slug(): string {
	return musicwave_theme()->templates()->account_template_slug();
}

/**
 * @return array<int, string>
 */
function musicwave_account_template_aliases(): array {
	return musicwave_theme()->templates()->account_template_aliases();
}

/**
 * @param mixed $template Resolved template or null.
 * @return mixed
 */
function musicwave_alias_account_template( $template, string $id, string $template_type ) {
	return musicwave_theme()->templates()->alias_account_template( $template, $id, $template_type );
}

/**
 * @param array<int, mixed>    $templates Found templates.
 * @param array<string, mixed> $query     Query args.
 * @return array<int, mixed>
 */
function musicwave_alias_account_templates_in_query( array $templates, array $query, string $template_type ): array {
	return musicwave_theme()->templates()->alias_account_templates_in_query( $templates, $query, $template_type );
}

/**
 * @return array<string, string>
 */
function musicwave_template_titles(): array {
	return musicwave_theme()->templates()->template_titles();
}

/**
 * @param mixed $template Resolved template or null.
 * @return mixed
 */
function musicwave_apply_template_title( $template ) {
	return musicwave_theme()->templates()->apply_template_title( $template );
}

/**
 * @param mixed $template Resolved template or null.
 * @return mixed
 */
function musicwave_filter_block_template_title( $template, string $id, string $template_type ) {
	unset( $id, $template_type );
	return musicwave_theme()->templates()->apply_template_title( $template );
}

/**
 * @param array<int, mixed>    $templates Found templates.
 * @param array<string, mixed> $query     Query args.
 * @return array<int, mixed>
 */
function musicwave_filter_block_templates_titles( array $templates, array $query, string $template_type ): array {
	unset( $query );
	if ( 'wp_template' !== $template_type ) {
		return $templates;
	}
	return array_map( 'musicwave_apply_template_title', $templates );
}

/**
 * @return array<int, WP_Post>
 */
function musicwave_customized_template_overrides(): array {
	return musicwave_theme()->templates()->customized_template_overrides();
}

function musicwave_render_template_repair_page(): void {
	musicwave_theme()->templates()->render_template_repair_page();
}

function musicwave_handle_template_repair(): void {
	musicwave_theme()->templates()->handle_template_repair();
}

/**
 * @return array<string, string>
 */
function musicwave_template_texts(): array {
	return musicwave_theme()->chrome()->template_texts();
}

/**
 * @param array<string, mixed> $attributes Block attributes.
 */
function musicwave_render_theme_text( array $attributes ): string {
	return musicwave_theme()->chrome()->render_theme_text( $attributes );
}

/**
 * @param array<string, mixed> $attributes Block attributes.
 */
function musicwave_block_wrapper_attributes( array $attributes, string $class_names = '' ): string {
	return musicwave_theme()->chrome()->block_wrapper_attributes( $attributes, $class_names );
}

/**
 * @param array<string, mixed> $attributes Block attributes.
 */
function musicwave_slider_toggle( array $attributes, string $attribute, string $setting ): bool {
	return musicwave_theme()->shelves()->slider_toggle( $attributes, $attribute, $setting );
}

function musicwave_slider_number( string $setting, int $fallback ): int {
	return musicwave_theme()->shelves()->slider_number( $setting, $fallback );
}

/**
 * @return array<string, mixed>
 */
function musicwave_release_presentation_data( int $release_id ): array {
	return musicwave_theme()->shelves()->release_presentation_data( $release_id );
}

/**
 * @param array<string, mixed> $item    Presentation payload.
 * @param array<string, mixed> $options Card render options.
 */
function musicwave_render_release_card( array $item, array $options ): string {
	return musicwave_theme()->shelves()->render_release_card( $item, $options );
}

function musicwave_render_shelf_header( string $eyebrow, string $title, string $description = '', string $section_url = '', string $section_link_label = '' ): string {
	return musicwave_theme()->shelves()->render_shelf_header( $eyebrow, $title, $description, $section_url, $section_link_label );
}

/**
 * @param array<string, mixed> $attributes Block attributes.
 */
function musicwave_render_release_slider( array $attributes ): string {
	return musicwave_theme()->shelves()->render_release_slider( $attributes );
}

/**
 * @param array<string, mixed> $attributes Block attributes.
 * @return array<string, mixed>
 */
function musicwave_release_query_args( array $attributes, int $items ): array {
	return musicwave_theme()->shelves()->release_query_args( $attributes, $items );
}

/**
 * @return array<int, array<string, mixed>>
 */
function musicwave_parse_filter_tabs( string $raw ): array {
	return musicwave_theme()->shelves()->parse_filter_tabs( $raw );
}

/**
 * @param array<string, mixed> $attributes Block attributes.
 * @return array{attributes: array<string, mixed>, tabs: array<int, array<string, mixed>>, active: string}
 */
function musicwave_apply_filter_tab( array $attributes, string $instance ): array {
	return musicwave_theme()->shelves()->apply_filter_tab( $attributes, $instance );
}

/**
 * @param array<int, array<string, mixed>> $tabs Parsed tab descriptors.
 */
function musicwave_render_filter_tabs( array $tabs, string $active, string $instance ): string {
	return musicwave_theme()->shelves()->render_filter_tabs( $tabs, $active, $instance );
}

function musicwave_current_request_url(): string {
	return musicwave_theme()->shelves()->current_request_url();
}

function musicwave_register_lyrics_meta(): void {
	musicwave_theme()->chrome()->register_lyrics_meta();
}

/**
 * @return array<int, array{time: float, text: string, translation: string}>
 */
function musicwave_parse_lrc( string $raw ): array {
	return musicwave_theme()->chrome()->parse_lrc( $raw );
}

/**
 * @param array<string, mixed> $attributes Block attributes.
 */
function musicwave_render_synced_lyrics( array $attributes ): string {
	return musicwave_theme()->chrome()->render_synced_lyrics( $attributes );
}

function musicwave_format_lyric_clock( float $seconds ): string {
	return musicwave_theme()->chrome()->format_lyric_clock( $seconds );
}

/**
 * @param array<string, mixed> $attributes Block attributes.
 */
function musicwave_render_playlist_shelf( array $attributes ): string {
	return musicwave_theme()->shelves()->render_playlist_shelf( $attributes );
}

function musicwave_shelf_nav_markup( string $layout, string $viewport_id = '' ): string {
	return musicwave_theme()->shelves()->shelf_nav_markup( $layout, $viewport_id );
}

/**
 * @param array<string, mixed> $attributes Block attributes.
 */
function musicwave_render_release_shelf( array $attributes ): string {
	return musicwave_theme()->shelves()->render_release_shelf( $attributes );
}

/**
 * @param array<string, mixed> $attributes Block attributes.
 * @param array<int, int>      $ids        Release IDs.
 */
function musicwave_render_feature_shelf( array $attributes, array $ids, string $eyebrow, string $title, string $description, string $section_url, string $section_link_label, string $variant_class = '' ): string {
	return musicwave_theme()->shelves()->render_feature_shelf( $attributes, $ids, $eyebrow, $title, $description, $section_url, $section_link_label, $variant_class );
}

/**
 * @param array<string, mixed> $attributes Block attributes.
 * @param array<int, int>      $ids        Release IDs.
 */
function musicwave_render_hero_slider( array $attributes, array $ids, string $eyebrow, string $title, string $description, string $section_url, string $section_link_label, string $variant_class = '' ): string {
	return musicwave_theme()->shelves()->render_hero_slider( $attributes, $ids, $eyebrow, $title, $description, $section_url, $section_link_label, $variant_class );
}

/**
 * @param array<string, mixed> $attributes Block attributes.
 */
function musicwave_render_theme_toggle( array $attributes = array() ): string {
	return musicwave_theme()->chrome()->render_theme_toggle( $attributes );
}

/**
 * @param array<string, mixed> $attributes Block attributes.
 */
function musicwave_render_rail_toggle( array $attributes = array() ): string {
	return musicwave_theme()->chrome()->render_rail_toggle( $attributes );
}

/**
 * @param array<string, mixed> $attributes Block attributes.
 */
function musicwave_render_header_search( array $attributes = array() ): string {
	return musicwave_theme()->chrome()->render_header_search( $attributes );
}

/**
 * @param array<string, mixed> $attributes Block attributes.
 */
function musicwave_render_breadcrumb( array $attributes = array() ): string {
	return musicwave_theme()->breadcrumbs()->render( $attributes );
}

/**
 * @return array<string, string>
 */
function musicwave_sidebar_widget_areas(): array {
	return musicwave_theme()->templates()->sidebar_widget_areas();
}

/**
 * @param array<string, mixed> $block Parsed block.
 */
function musicwave_inject_sidebar_widgets( string $content, array $block ): string {
	return musicwave_theme()->templates()->inject_sidebar_widgets( $content, $block );
}

require_once get_template_directory() . '/inc/routes.php';
require_once get_template_directory() . '/inc/site-header.php';
require_once get_template_directory() . '/inc/header-search.php';
require_once get_template_directory() . '/inc/nav-icons.php';

musicwave_theme()->boot();
