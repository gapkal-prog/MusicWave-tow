<?php
/**
 * Membership adapter contract.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Access;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface MembershipProvider {
	/**
	 * Return whether a user has at least one required membership level.
	 *
	 * @param int                $user_id User ID.
	 * @param array<int, string> $levels Required level keys.
	 */
	public function has_access( int $user_id, array $levels ): bool;
}
