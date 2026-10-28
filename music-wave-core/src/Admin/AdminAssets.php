<?php
/**
 * Shared admin asset loader for MusicWave admin pages (SOLID SRP + DRY).
 *
 * Handles translated parent menu hook suffixes (e.g. Persian `fa_IR`
 * `%d8%a7%d9%86%d8%aa%d8%b4%d8%a7%d8%b1%d9%87%d8%a7_page_<slug>`) cleanly.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminAssets {
	public static function is_submenu_screen( string $page_slug, string $hook_suffix, string $registered_hook = '' ): bool {
		if ( '' !== $registered_hook && $registered_hook === $hook_suffix ) {
			return true;
		}
		if ( 1 === preg_match( '/(?:^|_page_)' . preg_quote( $page_slug, '/' ) . '$/', $hook_suffix ) ) {
			return true;
		}
		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $page_slug === $page;
	}

	public static function enqueue_settings_style( string $page_slug, string $hook_suffix, string $registered_hook = '' ): void {
		if ( ! self::is_submenu_screen( $page_slug, $hook_suffix, $registered_hook ) ) {
			return;
		}

		wp_enqueue_style(
			'music-wave-admin-settings',
			MUSIC_WAVE_CORE_URL . 'assets/admin-settings.css',
			array(),
			MUSIC_WAVE_CORE_VERSION
		);
	}
}
