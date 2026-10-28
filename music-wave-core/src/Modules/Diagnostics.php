<?php
/**
 * Merchant onboarding and support diagnostics module.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Modules;

use ManaCore\MusicWave\Core\Admin\DiagnosticsPage;
use ManaCore\MusicWave\Core\Contracts\Module;
use ManaCore\MusicWave\Core\Support\SiteHealth;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Diagnostics implements Module {
	/** @var DiagnosticsPage */
	private $page;

	/** @var SiteHealth */
	private $site_health;

	public function __construct( DiagnosticsPage $page, SiteHealth $site_health ) {
		$this->page        = $page;
		$this->site_health = $site_health;
	}

	/**
	 * @return void
	 */
	public function register(): void {
		$this->page->register();
		$this->site_health->register();
	}
}
