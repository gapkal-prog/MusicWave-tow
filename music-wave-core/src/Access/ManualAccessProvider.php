<?php
/**
 * Optional manual-grant adapter contract.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Access;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ManualAccessProvider {
	public function has_access( int $user_id, int $release_id ): bool;
}
