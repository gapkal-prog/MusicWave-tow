<?php
/**
 * Consent-gated, indexed, retained listening activity storage.
 *
 * Nothing is recorded without the user's explicit opt-in; rows live in the
 * indexed `{prefix}mw_user_activity` table, are pruned by the daily cleanup
 * event after the retention window, and are covered by the privacy
 * exporter/eraser (PROJECT_PLAN.md Stage 5 deliverables 1 and 3).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Listening;

use ManaCore\MusicWave\Core\Catalog\ReleaseVisibility;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ListeningRepository {
	public const TABLE        = 'mw_user_activity';
	public const CONSENT_META = 'mw_listening_consent';
	public const QUEUE_META   = 'mw_playback_queue';
	public const MAX_QUEUE    = 100;

	public const EVENT_PROGRESS = 'progress';
	public const EVENT_PLAYED   = 'played';

	/** @var ReleaseVisibility */
	private $visibility;

	/** @var bool|null */
	private $table_available = null;

	public function __construct( ?ReleaseVisibility $visibility = null ) {
		$this->visibility = null !== $visibility ? $visibility : new ReleaseVisibility();
	}

	/**
	 * Whether the user opted into listening history.
	 */
	public function has_consent( int $user_id ): bool {
		return $user_id > 0 && '1' === (string) get_user_meta( $user_id, self::CONSENT_META, true );
	}

	/**
	 * Store the explicit consent decision.
	 * Fixed: treat unchanged meta as success.
	 */
	public function set_consent( int $user_id, bool $consent ): bool {
		if ( $user_id < 1 ) {
			return false;
		}
		$desired = $consent ? '1' : '0';
		$current = (string) get_user_meta( $user_id, self::CONSENT_META, true );
		if ( $current === $desired ) {
			if ( ! $consent ) {
				$this->erase( $user_id );
			}
			return true;
		}
		if ( ! $consent ) {
			$this->erase( $user_id );
		}
		$updated = update_user_meta( $user_id, self::CONSENT_META, $desired );
		if ( false === $updated ) {
			return (string) get_user_meta( $user_id, self::CONSENT_META, true ) === $desired;
		}
		return true;
	}

	/**
	 * Record one progress/played event (consent- and visibility-gated).
	 */
	public function record( int $user_id, int $release_id, string $event, int $position = 0 ): bool {
		global $wpdb;

		$event = in_array( $event, array( self::EVENT_PROGRESS, self::EVENT_PLAYED ), true ) ? $event : self::EVENT_PROGRESS;
		if ( $user_id < 1 || ! $this->has_consent( $user_id ) || ! $this->visibility->can_read( $release_id ) ) {
			return false;
		}
		if ( ! $this->table_available() ) {
			return false;
		}

		$table = $wpdb->prefix . self::TABLE;
		$saved = false !== $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$table} (user_id, release_id, event, position, updated_at) VALUES (%d, %d, %s, %d, %d) ON DUPLICATE KEY UPDATE position = VALUES(position), updated_at = VALUES(updated_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$release_id,
				$event,
				max( 0, $position ),
				time()
			)
		);

		// Completing a release graduates it from "continue listening" into "recently played".
		if ( $saved && self::EVENT_PLAYED === $event ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE user_id = %d AND release_id = %d AND event = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$user_id,
					$release_id,
					self::EVENT_PROGRESS
				)
			);
		}

		return $saved;
	}

	/**
	 * Remove a single release from a user's listening activity.
	 */
	public function remove_item( int $user_id, int $release_id, string $event = '' ): bool {
		global $wpdb;

		if ( $user_id < 1 || $release_id < 1 || ! $this->table_available() ) {
			return false;
		}

		$table = $wpdb->prefix . self::TABLE;
		if ( in_array( $event, array( self::EVENT_PROGRESS, self::EVENT_PLAYED ), true ) ) {
			return false !== $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE user_id = %d AND release_id = %d AND event = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$user_id,
					$release_id,
					$event
				)
			);
		}

		return false !== $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d AND release_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$release_id
			)
		);
	}

	/**
	 * Clear listening activity rows for one user while keeping their saved playback queue intact.
	 */
	public function clear_history( int $user_id, string $event = '' ): bool {
		global $wpdb;

		if ( $user_id < 1 ) {
			return false;
		}
		if ( ! $this->table_available() ) {
			return true;
		}

		$table = $wpdb->prefix . self::TABLE;
		if ( in_array( $event, array( self::EVENT_PROGRESS, self::EVENT_PLAYED ), true ) ) {
			return false !== $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE user_id = %d AND event = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$user_id,
					$event
				)
			);
		}

		return false !== $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			)
		);
	}

	/**
	 * Most recent activity rows for one user and event, visibility-filtered.
	 *
	 * @return array<int, array<string, int>>
	 */
	public function recent( int $user_id, string $event, int $limit = 12 ): array {
		global $wpdb;

		if ( $user_id < 1 || ! $this->has_consent( $user_id ) || ! $this->table_available() ) {
			return array();
		}

		$table = $wpdb->prefix . self::TABLE;
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT release_id, position, updated_at FROM {$table} WHERE user_id = %d AND event = %s ORDER BY updated_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$event,
				min( 50, max( 1, $limit ) )
			),
			\ARRAY_A
		);

		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$release_id = isset( $row['release_id'] ) ? absint( $row['release_id'] ) : 0;
			if ( $release_id < 1 || ! $this->visibility->can_read( $release_id ) ) {
				continue;
			}
			$items[] = array(
				'release_id' => $release_id,
				'position'   => isset( $row['position'] ) ? absint( $row['position'] ) : 0,
				'updated_at' => isset( $row['updated_at'] ) ? absint( $row['updated_at'] ) : 0,
			);
		}

		return $items;
	}

	/**
	 * Validated durable playback queue (bounded user meta).
	 *
	 * @return array{ids: array<int, int>, position: int, shuffle: bool, repeat: string}
	 */
	public function queue( int $user_id ): array {
		$stored = $user_id > 0 ? get_user_meta( $user_id, self::QUEUE_META, true ) : array();
		$stored = is_array( $stored ) ? $stored : array();

		return $this->normalize_queue( $stored );
	}

	/**
	 * Replace the durable queue after validating every entry.
	 * Fixed: update_user_meta returns false when value unchanged – treat as success (DRY, SOLID).
	 *
	 * @param array<string, mixed> $queue Raw queue payload.
	 */
	public function save_queue( int $user_id, array $queue ): bool {
		if ( $user_id < 1 ) {
			return false;
		}

		$normalized = $this->normalize_queue( $queue );
		$existing   = $this->queue( $user_id );

		// If normalized equals existing, consider it success – no DB write needed.
		if ( $normalized === $existing ) {
			return true;
		}

		$updated = update_user_meta( $user_id, self::QUEUE_META, $normalized );

		// update_user_meta returns false when same value; we already handled equality, so false here is real failure.
		// For safety, re-read and compare – if matches desired, treat as success.
		if ( false === $updated ) {
			$current = $this->queue( $user_id );
			return $current === $normalized;
		}

		return true;
	}

	/**
	 * Prune activity rows older than the retention window.
	 *
	 * @return void
	 */
	public function prune(): void {
		global $wpdb;

		if ( ! $this->table_available() ) {
			return;
		}

		/**
		 * Filter the listening-history retention window in days.
		 *
		 * @param int $days Retention in days (default 180).
		 */
		$days  = max( 1, (int) apply_filters( 'music_wave_listening_retention_days', 180 ) );
		$table = $wpdb->prefix . self::TABLE;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE updated_at < %d", time() - ( $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Count stored activity rows across every consenting user.
	 *
	 * Administrative dashboard metric only; rows are consent-gated on write
	 * and pruned by the retention window, so the aggregate count exposes no
	 * individual listening data.
	 */
	public function count_all(): int {
		global $wpdb;

		if ( ! $this->table_available() ) {
			return 0;
		}

		$table = $wpdb->prefix . self::TABLE;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Erase every stored activity row and the durable queue for one user.
	 */
	public function erase( int $user_id ): bool {
		global $wpdb;

		if ( $user_id < 1 ) {
			return false;
		}

		delete_user_meta( $user_id, self::QUEUE_META );

		if ( ! $this->table_available() ) {
			return true;
		}
		$table = $wpdb->prefix . self::TABLE;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return true;
	}

	/**
	 * Export stored activity for the privacy exporter.
	 *
	 * @return array<int, array<string, int|string>>
	 */
	public function export( int $user_id ): array {
		global $wpdb;

		if ( $user_id < 1 || ! $this->table_available() ) {
			return array();
		}

		$table = $wpdb->prefix . self::TABLE;
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT release_id, event, position, updated_at FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC LIMIT 500", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<string, mixed> $queue Raw queue payload.
	 * @return array{ids: array<int, int>, position: int, shuffle: bool, repeat: string}
	 */
	private function normalize_queue( array $queue ): array {
		$ids = array();
		foreach ( isset( $queue['ids'] ) && is_array( $queue['ids'] ) ? $queue['ids'] : array() as $release_id ) {
			$release_id = absint( $release_id );
			if ( $release_id > 0 && ! in_array( $release_id, $ids, true ) && $this->visibility->can_read( $release_id ) ) {
				$ids[] = $release_id;
			}
			if ( count( $ids ) >= self::MAX_QUEUE ) {
				break;
			}
		}

		$position = isset( $queue['position'] ) ? absint( $queue['position'] ) : 0;
		$repeat   = isset( $queue['repeat'] ) && is_scalar( $queue['repeat'] ) ? sanitize_key( (string) $queue['repeat'] ) : 'off';

		return array(
			'ids'      => $ids,
			'position' => min( $position, count( $ids ) > 0 ? count( $ids ) - 1 : 0 ),
			'shuffle'  => ! empty( $queue['shuffle'] ),
			'repeat'   => in_array( $repeat, array( 'off', 'all', 'one' ), true ) ? $repeat : 'off',
		);
	}

	private function table_available(): bool {
		return \ManaCore\MusicWave\Core\Support\DatabaseTable::exists( self::TABLE, $this->table_available );
	}
}
