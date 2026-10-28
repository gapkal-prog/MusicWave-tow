<?php
/**
 * Release-type detail metadata migration.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Migrations;

use ManaCore\MusicWave\Core\Contracts\Migration;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema040 implements Migration {
	public function version(): string {
		return '0.4.0';
	}

	/**
	 * New metadata has safe registered defaults, so no row rewrite is required.
	 *
	 * @return void
	 */
	public function up(): void {
		update_option( 'music_wave_core_version', MUSIC_WAVE_CORE_VERSION, false );
	}
}
