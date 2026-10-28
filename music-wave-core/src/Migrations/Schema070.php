<?php
/**
 * Multiple download qualities and preview-limit migration.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Migrations;

use ManaCore\MusicWave\Core\Contracts\Migration;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema070 implements Migration {
	public function version(): string {
		return '0.7.0';
	}

	/**
	 * New metadata has safe defaults; existing single assets stay compatible.
	 *
	 * @return void
	 */
	public function up(): void {
		update_option( 'music_wave_core_version', MUSIC_WAVE_CORE_VERSION, false );
	}
}
