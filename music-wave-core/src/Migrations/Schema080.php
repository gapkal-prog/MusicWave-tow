<?php
/**
 * Unified protected-file authoring migration.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Migrations;

use ManaCore\MusicWave\Core\Contracts\Migration;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema080 implements Migration {
	public function version(): string {
		return '0.8.0';
	}

	/**
	 * Existing download variants remain valid. Their optional file metadata is
	 * populated only when an editor updates or reselects a protected file.
	 *
	 * @return void
	 */
	public function up(): void {
		update_option( 'music_wave_core_version', MUSIC_WAVE_CORE_VERSION, false );
	}
}
