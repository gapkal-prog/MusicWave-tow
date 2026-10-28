<?php
/**
 * Bridges stored MusicWave settings to runtime filters.
 *
 * Delivery, discovery, and privacy subsystems resolve their operational
 * limits through documented filters with safe defaults; this class applies
 * the values an administrator configured in the control center so no runtime
 * code needs to know about the settings store.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SettingsFilters {
	/**
	 * Register every settings-driven runtime filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter(
			'music_wave_download_token_ttl',
			static function ( $ttl ) {
				$configured = (int) Settings::get( 'download_token_ttl' );

				return $configured >= 30 && $configured <= 3600 ? $configured : $ttl;
			}
		);
		add_filter(
			'music_wave_stream_token_ttl',
			static function ( $ttl ) {
				$configured = (int) Settings::get( 'stream_token_ttl' );

				return $configured >= 60 && $configured <= 7200 ? $configured : $ttl;
			}
		);
		add_filter(
			'music_wave_download_rate_limit',
			static function ( $limit ) {
				$configured = (int) Settings::get( 'download_rate_limit' );

				return $configured >= 1 ? $configured : $limit;
			}
		);
		add_filter(
			'music_wave_download_rate_window',
			static function ( $window ) {
				$configured = (int) Settings::get( 'download_rate_window' );

				return $configured >= 10 ? $configured : $window;
			}
		);
		add_filter(
			'music_wave_download_daily_quota',
			static function ( $quota ) {
				// Zero stays zero: it deliberately means "no daily cap".
				$configured = (int) Settings::get( 'download_daily_quota' );

				return $configured >= 0 ? $configured : $quota;
			}
		);
		add_filter(
			'music_wave_discovery_rate_limit',
			static function ( $limit ) {
				$configured = (int) Settings::get( 'discovery_rate_limit' );

				return $configured >= 1 ? $configured : $limit;
			}
		);
		add_filter(
			'music_wave_discovery_rate_window',
			static function ( $window ) {
				$configured = (int) Settings::get( 'discovery_rate_window' );

				return $configured >= 10 ? $configured : $window;
			}
		);
		add_filter(
			'music_wave_catalog_discovery_ttl',
			static function ( $ttl ) {
				$configured = (int) Settings::get( 'discovery_cache_ttl' );

				return $configured >= 30 ? $configured : $ttl;
			}
		);
		add_filter(
			'music_wave_listening_retention_days',
			static function ( $days ) {
				$configured = (int) Settings::get( 'listening_retention_days' );

				return $configured >= 1 ? $configured : $days;
			}
		);
	}
}
