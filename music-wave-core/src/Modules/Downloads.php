<?php
/**
 * Downloads module wiring routes, cleanup cron, and replay/ticket stores.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Modules;

use ManaCore\MusicWave\Core\Contracts\Module;
use ManaCore\MusicWave\Core\Downloads\DatabaseReplayStore;
use ManaCore\MusicWave\Core\Downloads\DownloadRoutes;
use ManaCore\MusicWave\Core\Downloads\DownloadAssetRoutes;
use ManaCore\MusicWave\Core\Downloads\OpaqueTicketStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Downloads implements Module {
	public const CLEANUP_EVENT = 'music_wave_replay_cleanup';

	/** @var DownloadRoutes */
	private $routes;

	/** @var DownloadAssetRoutes */
	private $asset_routes;

	/** @var DatabaseReplayStore|null */
	private $replay_store;

	/** @var OpaqueTicketStore|null */
	private $ticket_store;

	public function __construct( DownloadRoutes $routes, DownloadAssetRoutes $asset_routes, ?DatabaseReplayStore $replay_store = null, ?OpaqueTicketStore $ticket_store = null ) {
		$this->routes       = $routes;
		$this->asset_routes = $asset_routes;
		$this->replay_store = $replay_store;
		$this->ticket_store = $ticket_store; }
	public function register(): void {
		$this->routes->register();
		$this->asset_routes->register();
		if ( null !== $this->replay_store ) {
			add_action( self::CLEANUP_EVENT, array( $this->replay_store, 'cleanup' ) );
		}
		if ( null !== $this->ticket_store ) {
			add_action( self::CLEANUP_EVENT, array( $this->ticket_store, 'cleanup' ) );
		}
		if ( null !== $this->replay_store || null !== $this->ticket_store ) {
			add_action( 'init', array( $this, 'schedule_cleanup' ), 20 );
		} }

	/**
	 * Keep the replay store bounded with a daily cleanup event
	 * (PROJECT_PLAN.md Stage 2 deliverable 3).
	 *
	 * @return void
	 */
	public function schedule_cleanup(): void {
		if ( function_exists( 'wp_next_scheduled' ) && false === wp_next_scheduled( self::CLEANUP_EVENT ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CLEANUP_EVENT );
		}
	}
}
