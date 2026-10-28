<?php
/**
 * Module contract.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Module {
	/**
	 * Register WordPress hooks and module services.
	 *
	 * @return void
	 */
	public function register(): void;
}
