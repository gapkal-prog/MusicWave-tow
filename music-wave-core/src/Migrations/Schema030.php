<?php
/**
 * Editorial collection relationship migration.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Migrations;

use ManaCore\MusicWave\Core\Contracts\Migration;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema030 implements Migration {
	public function version(): string {
		return '0.3.0';
	}

	/**
	 * The relation is stored in post meta, so this migration is intentionally
	 * idempotent. Registration of the new schema happens during normal boot.
	 *
	 * @return void
	 */
	public function up(): void {
		update_option( 'music_wave_core_version', MUSIC_WAVE_CORE_VERSION, false );
		flush_rewrite_rules();
	}
}
