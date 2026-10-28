<?php
/**
 * Theme route registry facade delegating to ManaCore\MusicWave\Theme\Routing\RouteManager.
 *
 * @package MusicWave
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return array<string, array<string, mixed>>
 */
function musicwave_routes(): array {
	return musicwave_theme()->routes()->routes();
}

/**
 * @return WP_Post|null
 */
function musicwave_route_page( string $route ): ?WP_Post {
	return musicwave_theme()->routes()->route_page( $route );
}

function musicwave_route_url( string $route ): string {
	return musicwave_theme()->routes()->route_url( $route );
}

function musicwave_route_from_url( string $url ): string {
	return musicwave_theme()->routes()->route_from_url( $url );
}

/**
 * @param mixed $parsed_block Parsed block array.
 * @return mixed
 */
function musicwave_resolve_block_routes( $parsed_block ) {
	return musicwave_theme()->routes()->resolve_block_routes( $parsed_block );
}

/**
 * @return array<string, array<string, mixed>>
 */
function musicwave_provisionable_routes(): array {
	return musicwave_theme()->routes()->provisionable_routes();
}

/**
 * @return array<int, string>
 */
function musicwave_provision_pages(): array {
	return musicwave_theme()->routes()->provision_pages();
}

function musicwave_maybe_provision_pages(): void {
	musicwave_theme()->routes()->maybe_provision_pages();
}

function musicwave_maybe_provision_missing_pages(): void {
	musicwave_theme()->routes()->maybe_provision_missing_pages();
}
