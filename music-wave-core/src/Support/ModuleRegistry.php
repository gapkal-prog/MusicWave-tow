<?php
/**
 * Module registry.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Support;

use InvalidArgumentException;
use ManaCore\MusicWave\Core\Contracts\Module;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ModuleRegistry {
	/** @var array<string, Module> */
	private $modules = array();

	/**
	 * Add a module once, keyed by its class name.
	 *
	 * @param Module $module Module instance.
	 * @return void
	 */
	public function add( Module $module ): void {
		$this->modules[ get_class( $module ) ] = $module;
	}

	/**
	 * Add modules supplied through an extension point.
	 *
	 * @param mixed $modules Filtered module list.
	 * @return void
	 * @throws InvalidArgumentException When the filtered list is not an array of Module instances.
	 */
	public function add_filtered( $modules ): void {
		if ( ! is_array( $modules ) ) {
			throw new InvalidArgumentException( 'MusicWave modules must be provided as an array.' );
		}

		foreach ( $modules as $module ) {
			if ( ! $module instanceof Module ) {
				throw new InvalidArgumentException( 'Every MusicWave module must implement the Module contract.' );
			}

			$this->add( $module );
		}
	}

	/**
	 * Register every module.
	 *
	 * @return void
	 */
	public function register_all(): void {
		foreach ( $this->modules as $module ) {
			$module->register();
		}
	}
}
