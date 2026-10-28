<?php
/**
 * Playlist limits enforcement (FIX-25).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Playlists;

use ManaCore\MusicWave\Core\Support\Settings;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PlaylistLimits {
	public function register(): void {
		add_filter( 'music_wave_playlist_limits', array( $this, 'filter_limits' ), 10, 2 );
	}

	/**
	 * @param array $limits
	 * @param int $user_id
	 * @return array
	 */
	public function filter_limits( $limits, $user_id ) {
		$limits        = is_array( $limits ) ? $limits : array();
		$settings      = Settings::all();
		$max_playlists = isset( $settings['max_playlists_per_user'] ) ? (int) $settings['max_playlists_per_user'] : 20;
		$max_items     = isset( $settings['max_playlist_items'] ) ? (int) $settings['max_playlist_items'] : 100;

		// Allow filter override per user.
		$max_playlists = (int) apply_filters( 'music_wave_max_playlists_per_user', $max_playlists, $user_id );
		$max_items     = (int) apply_filters( 'music_wave_max_playlist_items', $max_items, $user_id );

		$limits['max_playlists'] = max( 1, min( 100, $max_playlists ) );
		$limits['max_items']     = max( 10, min( 500, $max_items ) );

		return $limits;
	}

	/**
	 * Get limits for user.
	 *
	 * @param int $user_id
	 * @return array{max_playlists:int,max_items:int}
	 */
	public static function for_user( int $user_id ): array {
		$defaults = array(
			'max_playlists' => 20,
			'max_items'     => 100,
		);
		$limits   = apply_filters( 'music_wave_playlist_limits', $defaults, $user_id );
		$limits   = is_array( $limits ) ? $limits : $defaults;
		return array(
			'max_playlists' => isset( $limits['max_playlists'] ) ? max( 1, (int) $limits['max_playlists'] ) : 20,
			'max_items'     => isset( $limits['max_items'] ) ? max( 10, (int) $limits['max_items'] ) : 100,
		);
	}
}
