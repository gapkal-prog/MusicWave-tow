<?php
/**
 * Optional WooCommerce integration module.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Modules;

use ManaCore\MusicWave\Core\Admin\ProductReleasePanel;
use ManaCore\MusicWave\Core\Commerce\ProductMapper;
use ManaCore\MusicWave\Core\Commerce\AccountLibrary;
use ManaCore\MusicWave\Core\Contracts\Module;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Commerce implements Module {
	/** @var ProductMapper */
	private $mapper;

	/** @var ProductReleasePanel */
	private $panel;

	/** @var AccountLibrary|null */
	private $account_library;

	public function __construct( ProductMapper $mapper, ProductReleasePanel $panel, ?AccountLibrary $account_library = null ) {
		$this->mapper          = $mapper;
		$this->panel           = $panel;
		$this->account_library = $account_library;
	}

	public function register(): void {
		add_action( 'before_delete_post', array( $this->mapper, 'handle_deleted_post' ) );
		add_action( 'add_meta_boxes_product', array( $this->panel, 'register' ) );
		add_action( 'save_post_product', array( $this->panel, 'save' ), 10, 2 );
		// The documented ownership extension point is
		// `music_wave_purchase_owns_release`, applied inside PurchaseChecker
		// (ADR 0004). No module-level wrapper is needed here.
		if ( null !== $this->account_library ) {
			$this->account_library->register();
		}
	}
}
