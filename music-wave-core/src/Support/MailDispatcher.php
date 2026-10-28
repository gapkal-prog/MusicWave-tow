<?php
/**
 * Shared validated email dispatcher for filtered MusicWave notification payloads.
 *
 * Centralizes payload guard + `wp_mail()` dispatch across `FollowNotifier`
 * and `RequestMailer` (SOLID SRP + DRY).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MailDispatcher {
	/**
	 * Validate a filtered message payload and dispatch via `wp_mail()`.
	 *
	 * @param mixed $message Filtered message array or `false` when cancelled.
	 */
	public static function send( $message ): bool {
		if ( false === $message || ! is_array( $message ) || empty( $message['to'] ) || ! function_exists( 'wp_mail' ) ) {
			return false;
		}

		return (bool) wp_mail(
			(string) $message['to'],
			(string) ( $message['subject'] ?? '' ),
			(string) ( $message['body'] ?? '' ),
			isset( $message['headers'] ) && is_array( $message['headers'] ) ? $message['headers'] : array()
		);
	}
}
