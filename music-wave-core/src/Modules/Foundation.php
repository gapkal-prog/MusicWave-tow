<?php
/**
 * Foundation module.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Modules;

use ManaCore\MusicWave\Core\Contracts\Module;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Foundation implements Module {
	/**
	 * Dedicated capability for managing protected download assets.
	 *
	 * Assigning new protected asset identifiers to releases requires this
	 * capability (or an explicit provider-side authorization), so ordinary
	 * release editors cannot attach guessed asset IDs (PROJECT_PLAN.md
	 * Stage 1 deliverable 5).
	 */
	public const MANAGE_ASSETS_CAP = 'manage_mw_protected_assets';

	/**
	 * Register hooks owned by the foundation module.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_filter( 'map_meta_cap', array( $this, 'map_asset_capability' ), 10, 2 );
		$this->register_audio_upload_mimes();
	}

	/**
	 * Map the dedicated asset capability to concrete site capabilities.
	 *
	 * Defaults to administrators (`manage_options`); sites can broaden or
	 * narrow this through the `music_wave_manage_asset_caps` filter.
	 *
	 * @param mixed  $caps Primitive capabilities required so far.
	 * @param string $cap  Requested meta capability.
	 * @return array<int, string>|mixed
	 */
	public function map_asset_capability( $caps, $cap ) {
		if ( self::MANAGE_ASSETS_CAP !== $cap ) {
			return $caps;
		}

		/**
		 * Filter the primitive capabilities required to manage protected assets.
		 *
		 * @param array<int, string> $required Primitive capabilities.
		 */
		$required = (array) apply_filters( 'music_wave_manage_asset_caps', array( 'manage_options' ) );

		// Phase 5 fix: return only primitive caps, not merged with meta cap itself.
		// Previously array_merge($caps, $required) kept the meta cap in the list,
		// causing current_user_can('manage_mw_protected_assets') to always fail
		// unless the role explicitly had that cap.
		return array_values( array_unique( $required ) );
	}

	/**
	 * Load translations from the plugin language directory.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'music-wave-core',
			false,
			dirname( plugin_basename( MUSIC_WAVE_CORE_FILE ) ) . '/languages'
		);
	}

	/**
	 * Allow audio uploads for users who may upload anyway.
	 *
	 * WordPress' default allow-list lacks several music formats the download
	 * and preview features are built around (docs/secure-downloads.md), so on
	 * restrictive hosts the mp3/flac/m4a/ogg upload simply failed. The filter
	 * only adds audio MIME types, and only for users who can already upload —
	 * the gate is capability-based, never request-based, so it cannot be used
	 * to smuggle a new type past an unprivileged request (review finding S7).
	 *
	 * @return void
	 */
	public function register_audio_upload_mimes(): void {
		add_filter( 'upload_mimes', array( $this, 'add_audio_mimes' ) );
	}

	/**
	 * Extend the MIME allow-list with the audio formats MusicWave ships.
	 *
	 * @param array<string, string> $mimes Existing MIME map.
	 * @return array<string, string>
	 */
	public function add_audio_mimes( $mimes ) {
		if ( ! function_exists( 'wp_get_current_user' ) || ! current_user_can( 'upload_files' ) ) {
			return $mimes;
		}

		$mimes = is_array( $mimes ) ? $mimes : array();
		foreach (
			array(
				'mp3'  => 'audio/mpeg',
				'm4a'  => 'audio/mp4',
				'aac'  => 'audio/aac',
				'ogg'  => 'audio/ogg',
				'oga'  => 'audio/ogg',
				'wav'  => 'audio/wav',
				'flac' => 'audio/flac',
			) as $extension => $mime
		) {
			if ( ! isset( $mimes[ $extension ] ) ) {
				$mimes[ $extension ] = $mime;
			}
		}

		return $mimes;
	}
}
