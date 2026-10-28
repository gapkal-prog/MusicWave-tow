<?php
/**
 * Listening and discovery module: consented history, durable queue,
 * explainable recommendations, retention pruning.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Modules;

use ManaCore\MusicWave\Core\Blocks\ListeningBlocks;
use ManaCore\MusicWave\Core\Blocks\QueueBlock;
use ManaCore\MusicWave\Core\Contracts\Module;
use ManaCore\MusicWave\Core\Discovery\DiscoveryRoutes;
use ManaCore\MusicWave\Core\Listening\ListeningRepository;
use ManaCore\MusicWave\Core\Listening\ListeningRoutes;
use ManaCore\MusicWave\Core\Listening\QueueFormHandler;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Listening implements Module {
	/** @var ListeningRepository */
	private $repository;

	/** @var ListeningRoutes */
	private $routes;

	/** @var DiscoveryRoutes */
	private $discovery;

	/** @var ListeningBlocks|null */
	private $blocks;

	/** @var QueueFormHandler|null */
	private $queue_forms;

	/** @var QueueBlock|null */
	private $queue_block;

	public function __construct( ListeningRepository $repository, ListeningRoutes $routes, DiscoveryRoutes $discovery, ?ListeningBlocks $blocks = null, ?QueueFormHandler $queue_forms = null, ?QueueBlock $queue_block = null ) {
		$this->repository  = $repository;
		$this->routes      = $routes;
		$this->discovery   = $discovery;
		$this->blocks      = $blocks;
		$this->queue_forms = $queue_forms;
		$this->queue_block = $queue_block;
	}

	public function register(): void {
		$this->routes->register();
		$this->discovery->register();
		if ( null !== $this->blocks ) {
			add_action( 'init', array( $this->blocks, 'register' ), 23 );
		}
		if ( null !== $this->queue_forms ) {
			$this->queue_forms->register();
		}
		if ( null !== $this->queue_block ) {
			add_action( 'init', array( $this->queue_block, 'register' ), 23 );
			// The queue joins the one account shell as a dashboard panel instead
			// of duplicating an account surface.
			add_filter( 'music_wave_dashboard_panels', array( $this, 'add_dashboard_panel' ), 10, 2 );
		}
		// Reuse the daily hygiene event so listening rows honor retention.
		// The Listening module schedules the event itself so retention does
		// not silently depend on the Downloads module being present.
		add_action( Downloads::CLEANUP_EVENT, array( $this->repository, 'prune' ) );
		add_action( 'init', array( $this, 'schedule_cleanup' ), 20 );
	}

	/**
	 * Ensure the shared daily cleanup event is scheduled.
	 *
	 * @return void
	 */
	public function schedule_cleanup(): void {
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && false === wp_next_scheduled( Downloads::CLEANUP_EVENT ) ) {
			wp_schedule_event( time() + ( defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 ), 'daily', Downloads::CLEANUP_EVENT );
		}
	}

	/**
	 * Add the queue panel to the account dashboard.
	 *
	 * @param mixed $panels  Registered dashboard panels.
	 * @param int   $user_id Dashboard owner.
	 * @return array<string, array<string, string>>
	 */
	public function add_dashboard_panel( $panels, $user_id = 0 ): array {
		$panels = is_array( $panels ) ? $panels : array();
		if ( null === $this->queue_block || (int) $user_id < 1 ) {
			return $panels;
		}

		$panels['queue'] = array(
			'icon'        => '☰',
			'label'       => __( 'صف', 'music-wave-core' ),
			'description' => __( 'صف پخش خود را دوباره مرتب کنید، به‌صورت تصادفی پخش کنید و پاک کنید.', 'music-wave-core' ),
			'content'     => $this->queue_block->render( array( 'heading' => __( 'بعدی', 'music-wave-core' ) ) ),
		);

		return $panels;
	}
}
