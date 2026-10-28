<?php
/**
 * Admin authoring module.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Modules;

use ManaCore\MusicWave\Core\Admin\ReleaseMetaBox;
use ManaCore\MusicWave\Core\Admin\CollectionCandidateRoutes;
use ManaCore\MusicWave\Core\Admin\EditorAssets;
use ManaCore\MusicWave\Core\Admin\ReleaseReadiness;
use ManaCore\MusicWave\Core\Admin\SettingsPage;
use ManaCore\MusicWave\Core\Contracts\Module;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin implements Module {
	/** @var ReleaseMetaBox */
	private $meta_box;
	/** @var EditorAssets|null */
	private $editor_assets;

	/** @var ReleaseReadiness|null */
	private $readiness;

	/** @var CollectionCandidateRoutes|null */
	private $collection_candidates;

	/** @var SettingsPage|null */
	private $settings_page;

	public function __construct( ReleaseMetaBox $meta_box, ?EditorAssets $editor_assets = null, ?ReleaseReadiness $readiness = null, ?CollectionCandidateRoutes $collection_candidates = null, ?SettingsPage $settings_page = null ) {
		$this->meta_box              = $meta_box;
		$this->editor_assets         = $editor_assets;
		$this->readiness             = $readiness;
		$this->collection_candidates = $collection_candidates;
		$this->settings_page         = $settings_page;
	}

	public function register(): void {
		if ( null !== $this->collection_candidates ) {
			$this->collection_candidates->register();
		}

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'add_meta_boxes', array( $this->meta_box, 'register' ) );
		add_action( 'save_post_mw_release', array( $this->meta_box, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this->meta_box, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this->meta_box, 'render_notice' ) );
		if ( null !== $this->readiness ) {
			$this->readiness->register();
		}
		if ( null !== $this->editor_assets ) {
			add_action( 'admin_enqueue_scripts', array( $this->editor_assets, 'enqueue' ) );
		}
		if ( null !== $this->settings_page ) {
			$this->settings_page->register();
		}
	}
}
