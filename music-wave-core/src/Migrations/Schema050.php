<?php
/**
 * Customer library endpoint migration.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Migrations;

use ManaCore\MusicWave\Core\Commerce\AccountLibrary;
use ManaCore\MusicWave\Core\Contracts\Migration;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema050 implements Migration {
	public function version(): string {
		return '0.5.0';
	}

	/**
	 * Persist the My Account endpoint rewrite rule for existing installs.
	 *
	 * @return void
	 */
	public function up(): void {
		AccountLibrary::register_endpoint();
		update_option( 'music_wave_core_version', MUSIC_WAVE_CORE_VERSION, false );
		flush_rewrite_rules();
	}
}
