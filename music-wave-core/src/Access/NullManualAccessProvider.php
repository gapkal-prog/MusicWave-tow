<?php
/**
 * Safe default when no manual-grant integration is installed.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Access;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NullManualAccessProvider implements ManualAccessProvider {
	public function has_access( int $user_id, int $release_id ): bool {
		unset( $user_id, $release_id );

		return false;
	}
}
