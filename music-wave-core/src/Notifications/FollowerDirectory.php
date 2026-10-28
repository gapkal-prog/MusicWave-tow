<?php
/**
 * Audience contract for follow notifications.
 *
 * The notifier never queries user data directly: it asks a directory who
 * follows one release. Core ships a library-backed implementation, and
 * integrations (labels, CRMs, membership platforms) can replace it without
 * touching delivery, consent, or unsubscribe behavior
 * (PROJECT_PLAN.md Stage 6 deliverable 2).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface FollowerDirectory {
	/**
	 * User IDs that follow one release, in no guaranteed order.
	 *
	 * Implementations must return listeners only; consent, channel
	 * preferences, and recipient caps are applied by the notifier.
	 *
	 * @param int $release_id Release that was published.
	 * @return array<int, int>
	 */
	public function followers( int $release_id ): array;
}
