<?php
/**
 * WordPress personal-data exporter and eraser for MusicWave user data.
 *
 * MusicWave stores four user-owned datasets: the personal music library
 * (`mw_music_library` user meta, including wishlist and pre-save items),
 * consented listening activity, playlists, and notification preferences.
 * Delivery audit events are emitted as hooks
 * and only persisted by integrations, which own their retention; rate and
 * quota counters are ephemeral transients (PROJECT_PLAN.md Stage 3
 * deliverable 6).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Privacy;

use ManaCore\MusicWave\Core\Library\LibraryRepository;
use ManaCore\MusicWave\Core\Listening\ListeningRepository;
use ManaCore\MusicWave\Core\Notifications\NotificationPreferences;
use ManaCore\MusicWave\Core\Playlists\PlaylistRepository;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PersonalData {
	/** @var LibraryRepository */
	private $library;

	/** @var ListeningRepository|null */
	private $listening;

	/** @var PlaylistRepository|null */
	private $playlists;

	/** @var NotificationPreferences|null */
	private $notifications;

	public function __construct( LibraryRepository $library, ?ListeningRepository $listening = null, ?PlaylistRepository $playlists = null, ?NotificationPreferences $notifications = null ) {
		$this->library       = $library;
		$this->listening     = $listening;
		$this->playlists     = $playlists;
		$this->notifications = $notifications;
	}

	/**
	 * Register with the WordPress privacy tooling.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * @param mixed $exporters Registered exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporter( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();

		$exporters['music-wave-library'] = array(
			'exporter_friendly_name' => __( 'کتابخانه شخصی MusicWave', 'music-wave-core' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * @param mixed $erasers Registered erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_eraser( $erasers ): array {
		$erasers = is_array( $erasers ) ? $erasers : array();

		$erasers['music-wave-library'] = array(
			'eraser_friendly_name' => __( 'کتابخانه شخصی MusicWave', 'music-wave-core' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export the personal library for one email address.
	 *
	 * @param string $email Email address under review.
	 * @param int    $page  Export page (single-page dataset).
	 * @return array<string, mixed>
	 */
	public function export( string $email, int $page = 1 ): array {
		unset( $page );
		$user = get_user_by( 'email', $email );
		if ( ! is_object( $user ) || ! property_exists( $user, 'ID' ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$items = array();
		foreach ( $this->library->all( (int) $user->ID ) as $item ) {
			$items[] = array(
				'group_id'    => 'music-wave-library',
				'group_label' => __( 'کتابخانه شخصی موسیقی', 'music-wave-core' ),
				'item_id'     => 'music-wave-library-' . (string) $item['type'] . '-' . (string) $item['id'],
				'data'        => array(
					array(
						'name'  => __( 'نوع مورد', 'music-wave-core' ),
						'value' => (string) $item['type'],
					),
					array(
						'name'  => __( 'کاتالوگ ID', 'music-wave-core' ),
						'value' => (string) $item['id'],
					),
					array(
						'name'  => __( 'ذخیره‌شده در', 'music-wave-core' ),
						'value' => (int) $item['added'] > 0 ? gmdate( 'Y-m-d H:i:s', (int) $item['added'] ) : '',
					),
				),
			);
		}

		if ( null !== $this->listening ) {
			foreach ( $this->listening->export( (int) $user->ID ) as $row ) {
				$items[] = array(
					'group_id'    => 'music-wave-listening',
					'group_label' => __( 'تاریخچه گوش‌دادن', 'music-wave-core' ),
					'item_id'     => 'music-wave-listening-' . (string) $row['event'] . '-' . (string) $row['release_id'],
					'data'        => array(
						array(
							'name'  => __( 'رویداد', 'music-wave-core' ),
							'value' => (string) $row['event'],
						),
						array(
							'name'  => __( 'کاتالوگ ID', 'music-wave-core' ),
							'value' => (string) $row['release_id'],
						),
						array(
							'name'  => __( 'موقعیت (ثانیه)', 'music-wave-core' ),
							'value' => (string) $row['position'],
						),
						array(
							'name'  => __( 'به‌روز شد', 'music-wave-core' ),
							'value' => (int) $row['updated_at'] > 0 ? gmdate( 'Y-m-d H:i:s', (int) $row['updated_at'] ) : '',
						),
					),
				);
			}
		}

		if ( null !== $this->playlists ) {
			foreach ( $this->playlists->export( (int) $user->ID ) as $playlist ) {
				$items[] = array(
					'group_id'    => 'music-wave-playlists',
					'group_label' => __( 'فهرست‌های پخش', 'music-wave-core' ),
					'item_id'     => 'music-wave-playlist-' . (string) $playlist['id'],
					'data'        => array(
						array(
							'name'  => __( 'فهرست پخش', 'music-wave-core' ),
							'value' => (string) $playlist['title'],
						),
						array(
							'name'  => __( 'دید', 'music-wave-core' ),
							'value' => (string) $playlist['visibility'],
						),
						array(
							'name'  => __( 'کاتالوگ شناسه‌ها', 'music-wave-core' ),
							'value' => implode( ', ', array_map( 'strval', $playlist['release_ids'] ) ),
						),
						array(
							'name'  => __( 'ایجاد شد', 'music-wave-core' ),
							'value' => (int) $playlist['created_at'] > 0 ? gmdate( 'Y-m-d H:i:s', (int) $playlist['created_at'] ) : '',
						),
					),
				);
			}
		}

		if ( null !== $this->notifications ) {
			$channels = array();
			foreach ( $this->notifications->export( (int) $user->ID ) as $channel => $enabled ) {
				$channels[] = array(
					'name'  => $this->notifications->label( (string) $channel ),
					'value' => $enabled ? __( 'فعال شد', 'music-wave-core' ) : __( 'غیرفعال', 'music-wave-core' ),
				);
			}
			if ( ! empty( $channels ) ) {
				$items[] = array(
					'group_id'    => 'music-wave-notifications',
					'group_label' => __( 'تنظیمات برگزیده اعلان', 'music-wave-core' ),
					'item_id'     => 'music-wave-notifications',
					'data'        => $channels,
				);
			}
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Erase the personal library for one email address.
	 *
	 * @param string $email Email address under erasure.
	 * @param int    $page  Erasure page (single-page dataset).
	 * @return array<string, mixed>
	 */
	public function erase( string $email, int $page = 1 ): array {
		unset( $page );
		$user    = get_user_by( 'email', $email );
		$user_id = is_object( $user ) && property_exists( $user, 'ID' ) ? (int) $user->ID : 0;
		$removed = false;
		if ( $user_id > 0 && $this->library->count( $user_id ) > 0 ) {
			$removed = delete_user_meta( $user_id, LibraryRepository::META_KEY );
		}
		if ( $user_id > 0 && null !== $this->listening ) {
			$removed = $this->listening->erase( $user_id ) || $removed;
			delete_user_meta( $user_id, ListeningRepository::CONSENT_META );
		}
		if ( $user_id > 0 && null !== $this->playlists ) {
			$removed = $this->playlists->erase( $user_id ) || $removed;
		}
		if ( $user_id > 0 && null !== $this->notifications ) {
			$removed = $this->notifications->erase( $user_id ) || $removed;
		}

		return array(
			'items_removed'  => (bool) $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
