<?php
/**
 * Canonical ordered list of schema migrations.
 *
 * One source of truth for every caller that needs the runner: the composition
 * root, the activation hook, and WP-CLI. Building the list in each caller let
 * the activator and the runtime drift apart, which is how a fresh activation
 * could end up with no tables at all (review finding B1).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Migrations;

use ManaCore\MusicWave\Core\Contracts\Migration;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MigrationSet {
	/**
	 * Every migration shipped by this plugin version, in author order.
	 *
	 * The runner sorts by version itself, so ordering here is for readability.
	 *
	 * @return array<int, Migration>
	 */
	public static function all(): array {
		return array(
			new Schema020(),
			new Schema030(),
			new Schema040(),
			new Schema050(),
			new Schema070(),
			new Schema080(),
			new Schema090(),
			new Schema0100(),
			new Schema0110(),
			new Schema0120(),
			new Schema0130(),
			new Schema0140(),
			new Schema0150(),
		);
	}

	/**
	 * Build a runner over the canonical migration set.
	 *
	 * @return MigrationRunner
	 */
	public static function runner(): MigrationRunner {
		return new MigrationRunner( self::all() );
	}

	/**
	 * Highest schema version shipped by this plugin build.
	 *
	 * Derived from the migration list rather than a hand-maintained constant, so
	 * adding a SchemaXxx class cannot leave the advertised version behind
	 * (review finding B4).
	 *
	 * @return string
	 */
	public static function latest_version(): string {
		$latest = '0.0.0';
		foreach ( self::all() as $migration ) {
			if ( version_compare( $migration->version(), $latest, '>' ) ) {
				$latest = $migration->version();
			}
		}

		return $latest;
	}
}
