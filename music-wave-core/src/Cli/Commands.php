<?php
/**
 * WP-CLI operations for migrations, reconciliation, and delivery hygiene.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Cli;

use ManaCore\MusicWave\Core\Downloads\DatabaseReplayStore;
use ManaCore\MusicWave\Core\Migrations\MigrationRunner;
use ManaCore\MusicWave\Core\Support\IndexReconciler;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Commands {
	/** @var MigrationRunner */
	private $migrations;

	public function __construct( MigrationRunner $migrations ) {
		$this->migrations = $migrations;
	}

	/**
	 * Register the `wp musicwave` command namespace when WP-CLI is active.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'musicwave migrate', array( $this, 'migrate' ) );
		\WP_CLI::add_command( 'musicwave reconcile', array( $this, 'reconcile' ) );
		\WP_CLI::add_command( 'musicwave replay-cleanup', array( $this, 'replay_cleanup' ) );
	}

	/**
	 * Run pending schema migrations under the migration lock.
	 *
	 * ## EXAMPLES
	 *
	 *     wp musicwave migrate
	 *
	 * @return void
	 */
	public function migrate(): void {
		$executed = $this->migrations->run_pending();
		\WP_CLI::success( sprintf( '%d migration(s) executed. Schema version: %s', $executed, (string) get_option( MigrationRunner::OPTION, '0.0.0' ) ) );
	}

	/**
	 * Audit and rebuild derived product/collection reverse indexes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp musicwave reconcile
	 *
	 * @return void
	 */
	public function reconcile(): void {
		$result = ( new IndexReconciler() )->reconcile();
		\WP_CLI::success(
			sprintf(
				'Scanned %d release(s); rebuilt %d product link(s) and %d collection link(s); removed %d stale row(s).',
				$result['releases'],
				$result['product_links'],
				$result['collection_links'],
				$result['stale_removed']
			)
		);
	}

	/**
	 * Prune expired one-time download replay markers immediately.
	 *
	 * ## EXAMPLES
	 *
	 *     wp musicwave replay-cleanup
	 *
	 * @return void
	 */
	public function replay_cleanup(): void {
		( new DatabaseReplayStore() )->cleanup();
		\WP_CLI::success( 'Expired replay markers pruned.' );
	}
}
