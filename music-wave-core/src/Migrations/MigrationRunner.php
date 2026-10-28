<?php
/**
 * Versioned schema migration runner.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Migrations;

use ManaCore\MusicWave\Core\Contracts\Migration;
use RuntimeException;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MigrationRunner {
	public const OPTION       = 'music_wave_schema_version';
	public const LOCK_OPTION  = 'music_wave_migration_lock';
	public const LOCK_TIMEOUT = 300;

	/**
	 * Highest schema version (NOT the plugin release version).
	 *
	 * This constant intentionally tracks the schema lineage, so it stays at
	 * the latest SchemaXxx version even when the plugin release is newer;
	 * tests/run.php pins it to the maximum of MigrationSet::all() so a new
	 * schema class cannot drift away from it (review finding B4).
	 */
	public const LATEST_VERSION = '0.15.0';

	/** @var array<int, Migration> */
	private $migrations;

	/** @param array<int, Migration> $migrations Ordered migrations. */
	public function __construct( array $migrations ) {
		usort(
			$migrations,
			static function ( Migration $left, Migration $right ): int {
				return version_compare( $left->version(), $right->version() );
			}
		);
		$this->migrations = $migrations;
	}

	/** @return array<int, Migration> */
	public function pending( string $current_version ): array {
		return array_values(
			array_filter(
				$this->migrations,
				static function ( Migration $migration ) use ( $current_version ): bool {
					return version_compare( $migration->version(), $current_version, '>' );
				}
			)
		);
	}

	/**
	 * Run pending migrations for administrators only.
	 *
	 * @return void
	 */
	public function maybe_run(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		try {
			$this->run_pending();
		} catch ( RuntimeException $exception ) {
			// A failed migration keeps its retry semantics (the version stays
			// un-persisted and the lock is released); surface it in the debug
			// log instead of failing the whole admin request.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'MusicWave Core migration failed: ' . $exception->getMessage() ); } // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Run pending migrations under a stale-safe lock.
	 *
	 * The lock prevents two concurrent admin requests (or a request racing a
	 * WP-CLI run) from executing the same migration twice; progress persists
	 * after every step, so an interrupted run resumes from the next version
	 * (PROJECT_PLAN.md Stage 3 deliverable 5).
	 *
	 * @return int Number of migrations executed.
	 * @throws RuntimeException When the schema version cannot be persisted.
	 */
	public function run_pending(): int {
		// Cheap pre-check so fully migrated sites never touch the lock option
		// on every admin page load.
		if ( array() === $this->pending( (string) get_option( self::OPTION, '0.0.0' ) ) ) {
			return 0;
		}

		if ( ! $this->acquire_lock() ) {
			return 0;
		}

		try {
			return $this->run_locked();
		} finally {
			$this->release_lock();
		}
	}

	private function acquire_lock(): bool {
		$existing = get_option( self::LOCK_OPTION, false );
		if ( false !== $existing && (int) $existing > time() - self::LOCK_TIMEOUT ) {
			return false;
		}
		if ( false !== $existing ) {
			delete_option( self::LOCK_OPTION );
		}

		// add_option() is atomic on the option name, so exactly one concurrent
		// caller wins the lock.
		return add_option( self::LOCK_OPTION, time(), '', false );
	}

	private function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * @return int Number of migrations executed.
	 * @throws RuntimeException When the schema version cannot be persisted.
	 */
	private function run_locked(): int {
		$executed = 0;
		$current  = (string) get_option( self::OPTION, '0.0.0' );
		foreach ( $this->pending( $current ) as $migration ) {
			$migration->up();
			if ( ! update_option( self::OPTION, $migration->version(), false ) ) {
				$stored = (string) get_option( self::OPTION, '0.0.0' );
				if ( $stored !== $migration->version() ) {
					throw new RuntimeException( 'MusicWave could not persist its schema version.' );
				}
			}
			$current = $migration->version();
			++$executed;
		}

		return $executed;
	}
}
