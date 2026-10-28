<?php
/**
 * Clean uninstall for MusicWave VIP.
 *
 * Strictly honors `music_wave_vip_settings['delete_data_on_uninstall']`:
 * - Default `'disabled'`: clears ONLY scheduled cron hooks and ephemeral sweep transients,
 *   preserving 100% of paid customer VIP grants, audit logs, inventory metadata, roles, and settings.
 * - `'enabled'`: removes VIP tables (`mw_vip_assets`), options, transients, `mw_vip_*` user meta,
 *   `_mw_vip_*` product/order meta, scheduled crons, and `mw_vip` role across single-site or Multisite.
 * NEVER deletes customer content (`mw_release` posts, media library attachments, or protected files on disk).
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'music_wave_vip_should_delete' ) ) {
	function music_wave_vip_should_delete(): bool {
		$settings = get_option( 'music_wave_vip_settings', array() );
		if ( ! is_array( $settings ) ) {
			return false;
		}
		return isset( $settings['delete_data_on_uninstall'] ) && 'enabled' === $settings['delete_data_on_uninstall'];
	}
}

if ( ! function_exists( 'music_wave_vip_uninstall_site' ) ) {
	function music_wave_vip_uninstall_site(): void {
		/** @var \wpdb|object|null $wpdb */
		global $wpdb;

		// Always clear scheduled VIP cron hooks and ephemeral sweep cooldown transient.
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'music_wave_vip_expiry_check' );
		}
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( 'mwvip_expiry_sweep_cooldown' );
		}

		// Preserve all customer VIP entitlements, logs, inventory records, and settings unless explicitly enabled.
		if ( ! music_wave_vip_should_delete() ) {
			return;
		}

		delete_option( 'music_wave_vip_settings' );
		delete_option( 'music_wave_vip_remote_keyring' );
		delete_option( 'music_wave_vip_protected_root' );
		delete_option( 'music_wave_vip_audit_log' );

		if ( is_object( $wpdb ) && method_exists( $wpdb, 'query' ) && property_exists( $wpdb, 'prefix' ) ) {
			$safe_table = str_replace( '`', '``', $wpdb->prefix . 'mw_vip_assets' );
			$wpdb->query( "DROP TABLE IF EXISTS `{$safe_table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( isset( $wpdb->options ) ) {
				$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mwvip_%' OR option_name LIKE '_transient_timeout_mwvip_%' OR option_name LIKE '_transient_music_wave_vip_%' OR option_name LIKE '_transient_timeout_music_wave_vip_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			if ( function_exists( 'is_multisite' ) && is_multisite() && isset( $wpdb->sitemeta ) ) {
				$wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_mwvip_%' OR meta_key LIKE '_site_transient_timeout_mwvip_%' OR meta_key LIKE '_site_transient_music_wave_vip_%' OR meta_key LIKE '_site_transient_timeout_music_wave_vip_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			if ( isset( $wpdb->usermeta ) ) {
				$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'mw_vip_%' OR meta_key LIKE '_mw_vip_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			if ( isset( $wpdb->postmeta ) ) {
				$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_mw_vip_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}

		if ( function_exists( 'remove_role' ) ) {
			remove_role( 'mw_vip' );
		}
	}
}

if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {
	$sites = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $sites as $site_id ) {
		switch_to_blog( (int) $site_id );
		music_wave_vip_uninstall_site();
		restore_current_blog();
	}
} else {
	music_wave_vip_uninstall_site();
}
