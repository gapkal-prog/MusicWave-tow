<?php
/**
 * Plugin activation lifecycle.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Lifecycle;

use ManaCore\MusicWave\Core\Catalog\ReleasePermalinks;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Catalog\ReleaseTaxonomies;
use ManaCore\MusicWave\Core\Commerce\AccountLibrary;
use ManaCore\MusicWave\Core\Migrations\MigrationSet;
use RuntimeException;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Activator {
	/**
	 * Persist versions and bring the custom schema up to date.
	 *
	 * @return void
	 */
	public static function activate(): void {
		update_option( 'music_wave_core_version', MUSIC_WAVE_CORE_VERSION, false );
		add_option( 'music_wave_schema_version', '0.0.0', '', false );

		self::run_migrations();

		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( null === $role ) {
				continue;
			}

			foreach ( ReleasePostType::primitive_capabilities() as $capability ) {
				$role->add_cap( $capability );
			}
			// Phase 5 fix: ensure protected asset capability is granted to admin/editor.
			// Previously map_meta_cap merged meta cap itself, causing failure.
			$role->add_cap( 'manage_mw_protected_assets' );
		}

		$permalinks = new ReleasePermalinks();
		( new ReleasePostType() )->register();
		( new ReleaseTaxonomies() )->register();
		$permalinks->register_permastructs();
		AccountLibrary::register_endpoint();
		flush_rewrite_rules();
		update_option( ReleasePermalinks::OPTION_RULES_SIGNATURE, $permalinks->rules_signature() );
	}

	/**
	 * Create the custom tables at activation instead of on first admin request.
	 *
	 * `MigrationRunner::maybe_run()` is bound to `admin_init` and gated on
	 * `manage_options`, so before this call a freshly activated site had no
	 * playlist or replay tables until an administrator happened to load the
	 * dashboard: playlists degraded to silently empty and one-time download
	 * replay protection fell back to a non-atomic transient. Headless and CI
	 * installs never migrated at all (review finding B1).
	 *
	 * Activation must not fatal, so a failing migration is logged and left for
	 * the `admin_init` runner to retry — the schema version stays un-persisted,
	 * which preserves the runner's retry semantics.
	 *
	 * @return void
	 */
	private static function run_migrations(): void {
		try {
			MigrationSet::runner()->run_pending();
		} catch ( RuntimeException $exception ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'MusicWave Core activation migration failed: ' . $exception->getMessage() ); } // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
