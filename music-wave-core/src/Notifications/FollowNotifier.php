<?php
/**
 * Follow notifications for new releases, pre-saves, and podcast episodes.
 *
 * Sending is opt-in, targeted, and bounded: the notifier only looks at
 * listeners who follow one of the release's artists (or the podcast show), only
 * sends to those who enabled the matching channel, only ever links publicly
 * published releases, and hands work to a scheduled batch so a publish request
 * never blocks on mail delivery. Every message carries a signed one-click
 * unsubscribe link (PROJECT_PLAN.md Stage 6 deliverable 2).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Notifications;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Catalog\ReleaseVisibility;
use ManaCore\MusicWave\Core\Library\LibraryRepository;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FollowNotifier {
	public const EVENT             = 'music_wave_notify_release';
	public const UNSUBSCRIBE_QUERY = 'mw-unsubscribe';
	public const MAX_RECIPIENTS    = 200;

	/** Post meta marking a release as announced; republishes never re-notify. */
	public const ANNOUNCED_META = 'mw_follow_announced_at';

	/** @var NotificationPreferences */
	private $preferences;

	/** @var ReleaseVisibility */
	private $visibility;

	/** @var FollowerDirectory */
	private $audience;

	public function __construct( LibraryRepository $library, NotificationPreferences $preferences, ?ReleaseVisibility $visibility = null, ?FollowerDirectory $audience = null ) {
		$this->preferences = $preferences;
		$this->visibility  = null !== $visibility ? $visibility : new ReleaseVisibility();
		$this->audience    = null !== $audience ? $audience : new LibraryFollowerDirectory( $library );
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'handle_transition' ), 20, 3 );
		add_action( self::EVENT, array( $this, 'notify_release' ), 10, 2 );
		add_action( 'music_wave_presave_fulfilled', array( $this, 'notify_presave' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'handle_unsubscribe' ) );
	}

	/**
	 * Queue notifications once a release becomes public.
	 *
	 * @param string $new_status New post status.
	 * @param string $old_status Previous post status.
	 * @param mixed  $post       Post object.
	 * @return void
	 */
	public function handle_transition( string $new_status, string $old_status, $post ): void {
		if ( 'publish' !== $new_status || $new_status === $old_status ) {
			return;
		}
		if ( ! is_object( $post ) || ! isset( $post->post_type, $post->ID ) || ReleasePostType::KEY !== $post->post_type ) {
			return;
		}

		$release_id = (int) $post->ID;
		// A release is announced exactly once in its lifetime: republish
		// cycles (publish → draft → publish) must not re-blast followers.
		if ( '' !== (string) get_post_meta( $release_id, self::ANNOUNCED_META, true ) ) {
			return;
		}
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			$this->notify_release( $release_id );

			return;
		}
		if ( function_exists( 'wp_next_scheduled' ) && false !== wp_next_scheduled( self::EVENT, array( $release_id ) ) ) {
			return;
		}

		// Publishing must not wait on mail delivery.
		wp_schedule_single_event( time() + 60, self::EVENT, array( $release_id ) );
	}

	/**
	 * Notify followers of one newly published release.
	 *
	 * Audiences larger than MAX_RECIPIENTS are paged: each batch schedules the
	 * next one with an offset so no follower is dropped by the cap.
	 *
	 * @param int $release_id Published release ID.
	 * @param int $offset     Audience offset for continuation batches.
	 * @return int Number of notifications sent.
	 */
	public function notify_release( int $release_id, int $offset = 0 ): int {
		if ( ! $this->visibility->is_public( $release_id ) ) {
			return 0;
		}
		if ( $offset < 1 && '' !== (string) get_post_meta( $release_id, self::ANNOUNCED_META, true ) ) {
			return 0;
		}

		$channel   = $this->is_podcast_episode( $release_id ) ? NotificationPreferences::CHANNEL_PODCAST : NotificationPreferences::CHANNEL_ARTIST_RELEASE;
		$followers = array_values( $this->followers( $release_id ) );
		$sent      = 0;
		$notified  = array();
		$processed = max( 0, $offset );
		foreach ( array_slice( $followers, $processed ) as $user_id ) {
			if ( count( $notified ) >= self::MAX_RECIPIENTS ) {
				break;
			}
			++$processed;
			if ( isset( $notified[ $user_id ] ) || ! $this->preferences->enabled( $user_id, $channel ) ) {
				continue;
			}
			$notified[ $user_id ] = true;
			if ( $this->send( $user_id, $release_id, $channel ) ) {
				++$sent;
			}
		}

		if ( $processed < count( $followers ) && function_exists( 'wp_schedule_single_event' ) ) {
			// Continuation batch for the remaining audience.
			wp_schedule_single_event( time() + 60, self::EVENT, array( $release_id, $processed ) );
		}
		if ( $offset < 1 ) {
			update_post_meta( $release_id, self::ANNOUNCED_META, time() );
		}

		return $sent;
	}

	/**
	 * Notify one listener that a pre-saved release is available.
	 *
	 * @param int $release_id Released release ID.
	 * @param int $user_id    Listener who pre-saved it.
	 * @return void
	 */
	public function notify_presave( int $release_id, int $user_id ): void {
		if ( ! $this->visibility->is_public( $release_id ) ) {
			return;
		}
		if ( ! $this->preferences->enabled( $user_id, NotificationPreferences::CHANNEL_PRESAVE ) ) {
			return;
		}

		$this->send( $user_id, $release_id, NotificationPreferences::CHANNEL_PRESAVE );
	}

	/**
	 * Handle a signed one-click unsubscribe request.
	 *
	 * @return void
	 */
	public function handle_unsubscribe(): void {
		// Signed request: the HMAC token replaces a nonce for links that must
		// work from an email client without a session.
		$channel = isset( $_GET[ self::UNSUBSCRIBE_QUERY ] ) && is_scalar( $_GET[ self::UNSUBSCRIBE_QUERY ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::UNSUBSCRIBE_QUERY ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $channel ) {
			return;
		}
		$user_id = isset( $_GET['mw-user'] ) ? absint( wp_unslash( (string) $_GET['mw-user'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token   = isset( $_GET['mw-token'] ) && is_scalar( $_GET['mw-token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['mw-token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->unsubscribe( $user_id, $channel, $token );
	}

	/**
	 * Apply one unsubscribe request. Exposed for tests: no superglobals.
	 */
	public function unsubscribe( int $user_id, string $channel, string $token ): bool {
		if ( ! $this->preferences->verify_token( $user_id, $channel, $token ) ) {
			return false;
		}

		return $this->preferences->disable( $user_id, $channel );
	}

	/**
	 * Listeners who follow one release through the configured directory.
	 *
	 * @return array<int, int>
	 */
	private function followers( int $release_id ): array {
		$followers = array();
		foreach ( $this->audience->followers( $release_id ) as $user_id ) {
			$user_id = absint( $user_id );
			if ( $user_id > 0 && ! in_array( $user_id, $followers, true ) ) {
				$followers[] = $user_id;
			}
		}

		/**
		 * Filter the audience for one release notification.
		 *
		 * Returned listeners still pass consent, channel preference, and
		 * recipient-cap checks, so this filter can narrow or extend the
		 * audience but never bypass opt-in.
		 *
		 * @param array<int, int> $followers  Candidate listener IDs.
		 * @param int             $release_id Published release ID.
		 */
		$filtered = apply_filters( 'music_wave_release_followers', $followers, $release_id );

		return is_array( $filtered ) ? $filtered : $followers;
	}

	/**
	 * Send one notification email.
	 */
	private function send( int $user_id, int $release_id, string $channel ): bool {
		$user = get_userdata( $user_id );
		if ( ! is_object( $user ) || empty( $user->user_email ) ) {
			return false;
		}

		$title   = get_the_title( $release_id );
		$url     = (string) get_permalink( $release_id );
		$subject = NotificationPreferences::CHANNEL_PRESAVE === $channel
			? sprintf(
				/* translators: %s: release title. */
				__( '%s اکنون منتشرشده است', 'music-wave-core' ),
				$title
			)
			: sprintf(
				/* translators: %s: release title. */
				__( 'انتشار جدید: %s', 'music-wave-core' ),
				$title
			);

		$unsubscribe = $this->preferences->unsubscribe_url( $user_id, $channel );
		$body        = $title . "\n" . $url . "\n\n" . __( 'شما این را دریافت می‌کنید زیرا این اعلان‌ها را فعال کرده اید.', 'music-wave-core' ) . "\n" . $unsubscribe;

		/**
		 * Filter one outgoing MusicWave notification.
		 *
		 * Return false to suppress delivery, or replace subject/body/headers to
		 * route notifications through another transport.
		 *
		 * @param array<string, mixed> $message    Message parts.
		 * @param int                  $user_id    Recipient.
		 * @param int                  $release_id Release the message is about.
		 * @param string               $channel    Notification channel.
		 */
		$message = apply_filters(
			'music_wave_notification_message',
			array(
				'to'      => (string) $user->user_email,
				'subject' => $subject,
				'body'    => $body,
				'headers' => array( 'List-Unsubscribe: <' . $unsubscribe . '>' ),
			),
			$user_id,
			$release_id,
			$channel
		);

		return \ManaCore\MusicWave\Core\Support\MailDispatcher::send( $message );
	}

	private function is_podcast_episode( int $release_id ): bool {
		$slugs = wp_get_post_terms( $release_id, 'mw_release_type', array( 'fields' => 'slugs' ) );

		return is_array( $slugs ) && in_array( 'podcast_episode', array_map( 'strval', $slugs ), true );
	}
}
