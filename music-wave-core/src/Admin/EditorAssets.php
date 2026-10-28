<?php
/**
 * MusicWave release meta-box assets.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Admin;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Downloads\DirectLinkPermission;
use ManaCore\MusicWave\Core\Downloads\DirectLinkUrlValidator;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EditorAssets {
	public function enqueue(): void {
		$screen = get_current_screen();
		if ( null === $screen || ReleasePostType::KEY !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script(
			'music-wave-editor',
			MUSIC_WAVE_CORE_URL . 'assets/editor.js',
			array( 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' ),
			MUSIC_WAVE_CORE_VERSION,
			true
		);
		wp_localize_script(
			'music-wave-editor',
			'musicWaveEditorConfig',
			array(
				'canManageDirectLinks' => DirectLinkPermission::can_manage(),
				'directAllowedHosts'   => DirectLinkUrlValidator::configured_allowed_hosts(),
			)
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'music-wave-editor', 'music-wave-core', MUSIC_WAVE_CORE_PATH . 'languages' );
		}
		wp_enqueue_style(
			'music-wave-editor',
			MUSIC_WAVE_CORE_URL . 'assets/editor.css',
			array( 'wp-components' ),
			MUSIC_WAVE_CORE_VERSION
		);
	}
}
