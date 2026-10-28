<?php
/**
 * Opaque browser tickets for signed delivery tokens.
 *
 * The signed token embeds structured claims (user, release, expiry, asset)
 * in a browser-readable base64 payload. Browsers must only ever see a random
 * ticket identifier; the signed token stays server-side with a matching TTL
 * (PROJECT_PLAN.md Stage 2 deliverable 5).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OpaqueTicketStore {
	public const PREFIX  = 'mwt_';
	public const MAX_TTL = 7200;

	/**
	 * Store a signed token and return the opaque browser ticket.
	 *
	 * Uses transients for speed and an options fallback for hosts where
	 * transients are volatile or object-cache eviction is aggressive.
	 * Both entries share the same TTL and are cleared on unwrap expiry.
	 */
	public function wrap( string $signed_token, int $ttl ): string {
		$ticket = self::PREFIX . bin2hex( random_bytes( 20 ) );
		$ttl    = max( 30, min( $ttl, self::MAX_TTL ) );
		$key    = $this->key( $ticket );
		// Primary: transient (object-cache aware).
		set_transient( $key, $signed_token, $ttl );
		// Fallback: non-autoloaded option with explicit expiry for reliable unwrap
		// even when transients are flushed. Options are cleaned lazily on read.
		update_option(
			$key,
			array(
				'token'   => $signed_token,
				'expires' => time() + $ttl,
			),
			false
		);

		return $ticket;
	}

	/**
	 * Exchange an opaque ticket for its stored signed token.
	 *
	 * Tickets are not deleted on read: stream tickets stay valid for byte-range
	 * request sequences, and download replay protection is enforced by the
	 * one-time replay store on the inner token id.
	 */
	public function unwrap( string $ticket ): ?string {
		if ( 1 !== preg_match( '/^' . self::PREFIX . '[a-f0-9]{40}$/', $ticket ) ) {
			return null;
		}

		$key    = $this->key( $ticket );
		$stored = get_transient( $key );
		if ( is_string( $stored ) && '' !== $stored ) {
			return $stored;
		}
		// Fallback: check option store when transient missed (e.g., cache flush).
		$fallback = get_option( $key, null );
		if ( is_array( $fallback ) && isset( $fallback['token'], $fallback['expires'] ) && is_string( $fallback['token'] ) && (int) $fallback['expires'] >= time() ) {
			// Repopulate transient for subsequent byte-range requests.
			set_transient( $key, $fallback['token'], max( 30, (int) $fallback['expires'] - time() ) );
			return $fallback['token'];
		}
		if ( is_array( $fallback ) ) {
			delete_option( $key );
		}

		return null;
	}

	/**
	 * Purge expired ticket fallback rows from wp_options.
	 *
	 * Transients expire on their own, but the non-autoloaded option fallback
	 * is only removed lazily when a client happens to retry an expired ticket
	 * — successful downloads never revisit their row. The daily cleanup event
	 * calls this so finished tickets do not accumulate forever.
	 *
	 * @return int Number of option rows removed.
	 */
	public function cleanup(): int {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! property_exists( $wpdb, 'options' ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return 0;
		}

		$pattern = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( 'mw_ticket_' ) . '%' : 'mw\_ticket\_%';
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 5000", $pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$removed = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$value   = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $row->option_value ) : $row->option_value;
			$expires = is_array( $value ) && isset( $value['expires'] ) ? (int) $value['expires'] : 0;
			if ( $expires > time() ) {
				continue;
			}
			// Rows with an unparsable or elapsed expiry are dropped.
			delete_option( (string) $row->option_name );
			++$removed;
		}

		return $removed;
	}

	/**
	 * Whether a value is shaped like an opaque browser ticket.
	 */
	public static function looks_like_ticket( string $value ): bool {
		return 0 === strpos( $value, self::PREFIX );
	}

	private function key( string $ticket ): string {
		return 'mw_ticket_' . hash( 'sha256', $ticket );
	}
}
