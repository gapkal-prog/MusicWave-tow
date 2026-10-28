<?php
/**
 * Per-listener notification preferences.
 *
 * Every channel is opt-in and defaults to off, so no email is ever sent to a
 * listener who did not ask for it. Each preference set carries a signed
 * unsubscribe token so one-click unsubscribe links work without a login and
 * cannot be forged or used to switch off someone else's notifications
 * (PROJECT_PLAN.md Stage 6 deliverable 2).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NotificationPreferences {
	public const META_KEY = 'mw_notification_prefs';

	public const CHANNEL_ARTIST_RELEASE = 'artist_release';
	public const CHANNEL_PRESAVE        = 'presave_ready';
	public const CHANNEL_PODCAST        = 'podcast_episode';

	/** @var string Secret used to sign unsubscribe tokens. */
	private $secret;

	public function __construct( string $secret = '' ) {
		if ( '' === $secret ) {
			$secret = function_exists( 'wp_salt' ) ? (string) wp_salt( 'nonce' ) : 'music-wave-notifications';
		}
		$this->secret = $secret;
	}

	/**
	 * Supported notification channels.
	 *
	 * @return array<int, string>
	 */
	public function channels(): array {
		return array( self::CHANNEL_ARTIST_RELEASE, self::CHANNEL_PRESAVE, self::CHANNEL_PODCAST );
	}

	/**
	 * Translated label for one channel.
	 */
	public function label( string $channel ): string {
		$labels = array(
			self::CHANNEL_ARTIST_RELEASE => __( 'انتشارات جدید از هنرمندانی که دنبال می‌کنم', 'music-wave-core' ),
			self::CHANNEL_PRESAVE        => __( 'وقتی چیزی که از قبل ذخیره کرده بودم خارج شد', 'music-wave-core' ),
			self::CHANNEL_PODCAST        => __( 'قسمت‌های جدید پادکست‌هایی که دنبال می‌کنم', 'music-wave-core' ),
		);

		return isset( $labels[ $channel ] ) ? $labels[ $channel ] : '';
	}

	/**
	 * Stored preferences for one listener, normalized and defaulting to off.
	 *
	 * @return array<string, bool>
	 */
	public function all( int $user_id ): array {
		$stored = $user_id > 0 ? get_user_meta( $user_id, self::META_KEY, true ) : array();
		$stored = is_array( $stored ) ? $stored : array();

		$preferences = array();
		foreach ( $this->channels() as $channel ) {
			$preferences[ $channel ] = isset( $stored[ $channel ] ) && ( true === $stored[ $channel ] || '1' === (string) $stored[ $channel ] );
		}

		return $preferences;
	}

	/**
	 * Whether one channel is enabled for a listener.
	 */
	public function enabled( int $user_id, string $channel ): bool {
		$preferences = $this->all( $user_id );

		return isset( $preferences[ $channel ] ) && true === $preferences[ $channel ];
	}

	/**
	 * Replace the full preference set, ignoring unknown channels.
	 *
	 * @param array<string, mixed> $preferences Raw submitted preferences.
	 */
	public function save( int $user_id, array $preferences ): bool {
		if ( $user_id < 1 ) {
			return false;
		}

		$clean = array();
		foreach ( $this->channels() as $channel ) {
			$clean[ $channel ] = ! empty( $preferences[ $channel ] ) ? '1' : '0';
		}

		return false !== update_user_meta( $user_id, self::META_KEY, $clean );
	}

	/**
	 * Switch off one channel (used by unsubscribe links).
	 */
	public function disable( int $user_id, string $channel ): bool {
		if ( $user_id < 1 || ! in_array( $channel, $this->channels(), true ) ) {
			return false;
		}

		$preferences             = $this->all( $user_id );
		$preferences[ $channel ] = false;

		return $this->save( $user_id, $preferences );
	}

	/**
	 * Signed unsubscribe token for one listener and channel.
	 */
	public function token( int $user_id, string $channel ): string {
		if ( $user_id < 1 || ! in_array( $channel, $this->channels(), true ) ) {
			return '';
		}

		return hash_hmac( 'sha256', $user_id . '|' . $channel, $this->secret );
	}

	/**
	 * Verify an unsubscribe token in constant time.
	 */
	public function verify_token( int $user_id, string $channel, string $token ): bool {
		$expected = $this->token( $user_id, $channel );

		return '' !== $expected && '' !== $token && hash_equals( $expected, $token );
	}

	/**
	 * One-click unsubscribe URL for one listener and channel.
	 */
	public function unsubscribe_url( int $user_id, string $channel ): string {
		$token = $this->token( $user_id, $channel );
		if ( '' === $token ) {
			return '';
		}

		return add_query_arg(
			array(
				'mw-unsubscribe' => $channel,
				'mw-user'        => (string) $user_id,
				'mw-token'       => $token,
			),
			home_url( '/' )
		);
	}

	/**
	 * Export preferences for the privacy exporter.
	 *
	 * @return array<string, bool>
	 */
	public function export( int $user_id ): array {
		return $this->all( $user_id );
	}

	/**
	 * Delete stored preferences for one listener.
	 */
	public function erase( int $user_id ): bool {
		return $user_id > 0 && delete_user_meta( $user_id, self::META_KEY );
	}
}
