<?php
/**
 * Schema migration contract.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Migration {
	public function version(): string;

	/**
	 * Apply an idempotent schema or data change.
	 *
	 * @return void
	 */
	public function up(): void;
}
