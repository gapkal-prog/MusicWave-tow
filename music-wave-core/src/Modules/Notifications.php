<?php
/**
 * Follow notification module.
 *
 * Wires the opt-in notification surfaces into WordPress: the publish/pre-save
 * listener that queues messages for library followers, and the signed one-click
 * unsubscribe route each email carries.
 *
 * The listener-facing preference panel was removed from the account dashboard —
 * channels stay off until an integration writes them through
 * NotificationPreferences, so the delivery path remains fail-closed.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Modules;

use ManaCore\MusicWave\Core\Contracts\Module;
use ManaCore\MusicWave\Core\Notifications\FollowNotifier;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Notifications implements Module {
	/** @var FollowNotifier */
	private $notifier;

	public function __construct( FollowNotifier $notifier ) {
		$this->notifier = $notifier;
	}

	/**
	 * @return void
	 */
	public function register(): void {
		$this->notifier->register();
	}
}
