<?php
/**
 * Safe default when no membership integration is installed.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Access;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NullMembershipProvider implements MembershipProvider {
	/** @param array<int, string> $levels */
	public function has_access( int $user_id, array $levels ): bool {
		unset( $user_id, $levels );

		return false;
	}
}
