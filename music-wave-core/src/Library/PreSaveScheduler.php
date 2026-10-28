<?php
/**
 * Pre-save fulfillment.
 *
 * A pre-save is a promise: when the release date arrives (or the release is
 * published), the stored pre-save becomes a real library item and an action
 * fires so notification integrations can inform the customer. Fulfillment is
 * targeted — one scheduled single event per pre-saved release plus the publish
 * transition — so no unbounded catalog or user sweep is ever required
 * (PROJECT_PLAN.md Stage 5 deliverable 5).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Library;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PreSaveScheduler {
	public const EVENT = 'music_wave_presave_release';

	/** @var LibraryRepository */
	private $library;

	public function __construct( LibraryRepository $library ) {
		$this->library = $library;
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'music_wave_library_item_added', array( $this, 'handle_item_added' ), 10, 3 );
		add_action( self::EVENT, array( $this, 'fulfill' ) );
		add_action( 'transition_post_status', array( $this, 'handle_transition' ), 10, 3 );
	}

	/**
	 * Schedule fulfillment when a customer pre-saves a release.
	 *
	 * @param string $type    Library item type.
	 * @param int    $item_id Release ID.
	 * @param int    $user_id Library owner.
	 * @return void
	 */
	public function handle_item_added( string $type, int $item_id, int $user_id ): void {
		unset( $user_id );
		if ( LibraryRepository::TYPE_PRESAVE === $type ) {
			$this->schedule( $item_id );
		}
	}

	/**
	 * Ensure exactly one fulfillment event exists for a release.
	 *
	 * @return void
	 */
	public function schedule( int $release_id ): void {
		$timestamp = $this->library->release_timestamp( $release_id );
		if ( $release_id < 1 || $timestamp <= time() ) {
			return;
		}
		if ( ! function_exists( 'wp_schedule_single_event' ) || ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}
		if ( false !== wp_next_scheduled( self::EVENT, array( $release_id ) ) ) {
			return;
		}

		wp_schedule_single_event( $timestamp, self::EVENT, array( $release_id ) );
	}

	/**
	 * Fulfill every pending pre-save for one release.
	 *
	 * @return int Number of customers whose pre-save was fulfilled.
	 */
	public function fulfill( int $release_id ): int {
		if ( $release_id < 1 || $this->library->is_upcoming( $release_id ) ) {
			// The date moved; keep the promise pending instead of releasing early.
			$this->schedule( $release_id );

			return 0;
		}

		$fulfilled = 0;
		foreach ( $this->library->users_with( LibraryRepository::TYPE_PRESAVE, $release_id ) as $user_id ) {
			if ( $this->fulfill_for_user( $release_id, $user_id ) ) {
				++$fulfilled;
			}
		}

		return $fulfilled;
	}

	/**
	 * Convert one customer's pre-save into a saved library item.
	 */
	public function fulfill_for_user( int $release_id, int $user_id ): bool {
		if ( $release_id < 1 || $user_id < 1 || ! $this->library->has( $user_id, LibraryRepository::TYPE_PRESAVE, $release_id ) ) {
			return false;
		}
		if ( $this->library->is_upcoming( $release_id ) ) {
			return false;
		}

		// Add before removing so an interrupted run never loses the promise.
		$saved = $this->library->has( $user_id, LibraryRepository::TYPE_RELEASE, $release_id )
			|| $this->library->add( $user_id, LibraryRepository::TYPE_RELEASE, $release_id );
		if ( ! $saved ) {
			return false;
		}

		$this->library->remove( $user_id, LibraryRepository::TYPE_PRESAVE, $release_id );

		/**
		 * Fires when a pre-saved release becomes available for one customer.
		 *
		 * @param int $release_id Released release ID.
		 * @param int $user_id    Customer who pre-saved it.
		 */
		do_action( 'music_wave_presave_fulfilled', $release_id, $user_id );

		return true;
	}

	/**
	 * Fulfill pre-saves as soon as a release is published.
	 *
	 * Fulfillment scans every pre-saving account and fires one email per
	 * customer, so it is handed to cron instead of running inside the publish
	 * request — mirroring the follow-notification deferral.
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
		if ( function_exists( 'wp_schedule_single_event' ) && function_exists( 'wp_next_scheduled' ) ) {
			if ( false === wp_next_scheduled( self::EVENT, array( $release_id ) ) ) {
				wp_schedule_single_event( time() + 30, self::EVENT, array( $release_id ) );
			}

			return;
		}

		$this->fulfill( $release_id );
	}
}
