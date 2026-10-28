<?php
/**
 * Plugin deactivation lifecycle.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Lifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Deactivator {
	/**
	 * Keep user data intact and clear only runtime rewrite state.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'music_wave_replay_cleanup' );
		}
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
		}
	}
}
