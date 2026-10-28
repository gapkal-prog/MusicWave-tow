<?php
/**
 * Fix protected asset capability mapping — grant to admin/editor.
 *
 * Phase 5 fix: previously map_meta_cap merged meta cap itself, causing
 * current_user_can('manage_mw_protected_assets') to always fail unless
 * role explicitly had the cap. This migration ensures existing installs
 * get the cap.
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

final class Schema0140 implements Migration {
	public function version(): string {
		return '0.14.0';
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
			$role->add_cap( 'manage_mw_protected_assets' );
		}
	}
}
