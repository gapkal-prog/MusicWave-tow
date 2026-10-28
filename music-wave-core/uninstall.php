<?php
/**
 * Clean uninstall for MusicWave Core.
 *
 * Always clears scheduled WP-Cron hooks so orphaned events never fire after plugin deletion.
 * Deletes database tables, options, transients, user meta, and custom roles/caps ONLY when
 * `music_wave_settings['delete_data_on_uninstall'] === 'enabled'`.
 * Self-contained: no autoloader, uses $wpdb directly.
 * NEVER deletes customer content: mw_release, mw_request posts, terms, media, protected files.
 *
 * @package ManaCore\MusicWave\Core
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$core_tables = array(
	'mw_user_activity',
	'mw_playlists',
	'mw_playlist_items',
	'mw_download_replays',
	'mw_release_views',
	'mw_library_index',
);

$core_crons = array(
	'music_wave_daily_cleanup',
	'music_wave_replay_cleanup',
	'music_wave_hourly_view_sync',
	'music_wave_playlist_cleanup',
	'music_wave_listening_cleanup',
	'music_wave_presave_check',
);

if ( ! function_exists( 'music_wave_core_should_delete' ) ) {
	function music_wave_core_should_delete(): bool {
		$settings = get_option( 'music_wave_settings', array() );
		if ( ! is_array( $settings ) ) {
			return false;
		}
		return isset( $settings['delete_data_on_uninstall'] ) && 'enabled' === $settings['delete_data_on_uninstall'];
	}
}

if ( ! function_exists( 'music_wave_core_vip_retains_role' ) ) {
	function music_wave_core_vip_retains_role(): bool {
		if ( defined( 'MUSIC_WAVE_VIP_FILE' ) ) {
			return true;
		}
		$vip_settings = get_option( 'music_wave_vip_settings', null );
		if ( is_array( $vip_settings ) ) {
			return ! isset( $vip_settings['delete_data_on_uninstall'] ) || 'enabled' !== $vip_settings['delete_data_on_uninstall'];
		}
		return false;
	}
}

if ( ! function_exists( 'music_wave_core_uninstall_site' ) ) {
	function music_wave_core_uninstall_site(): void {
		/** @var \wpdb|object|null $wpdb */
		global $wpdb;

		foreach ( $GLOBALS['music_wave_core_crons'] as $hook ) {
			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( $hook );
			}
		}

		if ( ! music_wave_core_should_delete() || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! property_exists( $wpdb, 'prefix' ) ) {
			return;
		}

		// Drop custom tables using safe identifier quoting across all MySQL/WP versions.
		foreach ( $GLOBALS['music_wave_core_tables'] as $table_suffix ) {
			$safe_table = str_replace( '`', '``', $wpdb->prefix . $table_suffix );
			$wpdb->query( "DROP TABLE IF EXISTS `{$safe_table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		delete_option( 'music_wave_settings' );
		delete_option( 'music_wave_download_secret' );
		delete_option( 'music_wave_schema_version' );

		// Preserve VIP options when VIP data retention is active; delete Core options, ticket fallbacks, and daily quota buckets.
		if ( isset( $wpdb->options ) ) {
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE ( option_name LIKE 'music_wave_%' AND option_name NOT LIKE 'music_wave_vip%' ) OR option_name LIKE 'mw_dl_quota_%' OR option_name LIKE 'mw_ticket_%' OR option_name LIKE 'mw_download_replay_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			// Delete Core transients (excluding `mwvip_` which belongs to MusicWave VIP).
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_music_wave_%' OR option_name LIKE '_transient_timeout_music_wave_%' OR option_name LIKE '_transient_mw_%' OR option_name LIKE '_transient_timeout_mw_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( function_exists( 'is_multisite' ) && is_multisite() && isset( $wpdb->sitemeta ) ) {
			$wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_music_wave_%' OR meta_key LIKE '_site_transient_timeout_music_wave_%' OR meta_key LIKE '_site_transient_mw_%' OR meta_key LIKE '_site_transient_timeout_mw_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		// Delete Core release/commerce post meta (never deletes `_mw_vip_*`).
		if ( isset( $wpdb->postmeta ) ) {
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE ( meta_key LIKE 'mw_%' AND meta_key NOT LIKE 'mw_vip_%' ) OR meta_key = '_mw_release_ids'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		// Delete Core user meta (`music_wave_*` / `_mw_favorites` — never deletes `mw_vip_*` or `_mw_vip_*`).
		if ( isset( $wpdb->usermeta ) ) {
			$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'music_wave_%' OR meta_key = '_mw_favorites'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		if ( function_exists( 'wp_roles' ) ) {
			$all_roles = wp_roles();
			if ( is_object( $all_roles ) && is_array( $all_roles->roles ) ) {
				foreach ( array_keys( $all_roles->roles ) as $role_name ) {
					$role = get_role( (string) $role_name );
					if ( ! $role ) {
						continue;
					}
					foreach ( array( 'manage_mw_protected_assets', 'manage_musicwave', 'manage_musicwave_requests', 'manage_mw_releases' ) as $cap ) {
						$role->remove_cap( $cap );
					}
				}
				remove_role( 'musicwave_listener' );
				if ( ! music_wave_core_vip_retains_role() ) {
					remove_role( 'mw_vip' );
				}
			}
		}
	}
}

$GLOBALS['music_wave_core_tables'] = $core_tables;
$GLOBALS['music_wave_core_crons']  = $core_crons;

if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {
	$sites = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $sites as $site_id ) {
		switch_to_blog( (int) $site_id );
		music_wave_core_uninstall_site();
		restore_current_blog();
	}
} else {
	music_wave_core_uninstall_site();
}
