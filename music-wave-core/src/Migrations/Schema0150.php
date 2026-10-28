<?php
/**
 * Enable seamless persistent background navigation on existing installs.
 *
 * Previously `persistent_navigation` defaulted to `disabled`, which caused any
 * saved `music_wave_settings` option row to keep hard full-page navigations and
 * interrupt audio playback during page loads. Running once at schema 0.15.0
 * upgrades existing installs cleanly while keeping the admin setting editable.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Migrations;

use ManaCore\MusicWave\Core\Contracts\Migration;
use ManaCore\MusicWave\Core\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema0150 implements Migration {
	public function version(): string {
		return '0.15.0';
	}

	public function up(): void {
		$stored = get_option( Settings::OPTION, null );
		if ( ! is_array( $stored ) ) {
			return;
		}
		if ( isset( $stored['persistent_navigation'] ) && 'enabled' === $stored['persistent_navigation'] ) {
			return;
		}
		$stored['persistent_navigation'] = 'enabled';
		update_option( Settings::OPTION, Settings::sanitize( $stored ) );
	}
}
