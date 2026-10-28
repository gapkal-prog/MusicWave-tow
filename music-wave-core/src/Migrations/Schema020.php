<?php
/**
 * Initial catalog schema migration marker.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Migrations;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Contracts\Migration;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema020 implements Migration {
	public function version(): string {
		return '0.2.0';
	}

	public function up(): void {
		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( null === $role ) {
				continue;
			}

			foreach ( ReleasePostType::primitive_capabilities() as $capability ) {
				$role->add_cap( $capability );
			}
			// Phase 5 fix: grant protected asset cap.
			$role->add_cap( 'manage_mw_protected_assets' );
		}

		update_option( 'music_wave_core_version', MUSIC_WAVE_CORE_VERSION, false );
		flush_rewrite_rules();
	}
}
