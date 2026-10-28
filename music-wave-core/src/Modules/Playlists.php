<?php
/**
 * User playlists module.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Modules;

use ManaCore\MusicWave\Core\Blocks\PlaylistBlocks;
use ManaCore\MusicWave\Core\Contracts\Module;
use ManaCore\MusicWave\Core\Playlists\PlaylistFormHandler;
use ManaCore\MusicWave\Core\Playlists\PlaylistRepository;
use ManaCore\MusicWave\Core\Playlists\PlaylistRoutes;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Playlists implements Module {
	/** @var PlaylistRepository */
	private $repository;

	/** @var PlaylistRoutes */
	private $routes;

	/** @var PlaylistFormHandler|null */
	private $forms;

	/** @var PlaylistBlocks|null */
	private $blocks;

	public function __construct( PlaylistRepository $repository, PlaylistRoutes $routes, ?PlaylistFormHandler $forms = null, ?PlaylistBlocks $blocks = null ) {
		$this->repository = $repository;
		$this->routes     = $routes;
		$this->forms      = $forms;
		$this->blocks     = $blocks;
	}

	/**
	 * Add the playlists panel to the account dashboard.
	 *
	 * @param mixed $panels  Registered dashboard panels.
	 * @param int   $user_id Dashboard owner.
	 * @return array<string, array<string, string>>
	 */
	public function add_dashboard_panel( $panels, $user_id = 0 ): array {
		$panels = is_array( $panels ) ? $panels : array();
		if ( null === $this->blocks || (int) $user_id < 1 ) {
			return $panels;
		}

		$panels['playlists'] = array(
			'icon'        => '≡',
			'label'       => __( 'فهرست‌های پخش', 'music-wave-core' ),
			'description' => __( 'فهرست پخش خود را ایجاد کنید، سفارش دهید و به‌اشتراک بگذارید.', 'music-wave-core' ),
			'content'     => $this->blocks->render_manager( array( 'heading' => __( 'فهرست‌های پخش شما', 'music-wave-core' ) ) ),
		);

		return $panels;
	}

	public function register(): void {
		$this->repository->register();
		$this->routes->register();
		if ( null !== $this->forms ) {
			$this->forms->register();
		}
		if ( null !== $this->blocks ) {
			add_action( 'init', array( $this->blocks, 'register' ), 23 );
			// Playlists join the one account shell as a dashboard panel instead of
			// duplicating an account surface (PROJECT_PLAN.md Stage 4 deliverable 2).
			add_filter( 'music_wave_dashboard_panels', array( $this, 'add_dashboard_panel' ), 10, 2 );
		}
	}
}
