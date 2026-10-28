<?php
/**
 * Batched Cross-Plugin User Activity & Payment Inspector.
 *
 * Aggregates a user's WooCommerce orders/payments, MusicWave listening
 * history (`mw_user_activity`), personal library (`mw_library_index`),
 * playlists (`mw_playlists`), and song requests (`mw_request`) without N+1
 * queries on `users.php`, plus detailed drill-downs on `user-edit.php`.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Users;

use ManaCore\MusicWave\Core\Playlists\PlaylistRepository;
use ManaCore\MusicWave\Vip\VipPlans;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UserActivityInspector {
	private const BACKFILL_OPTION = 'mw_vip_status_index_v1';

	/** @var VipPlans */
	private $plans;

	/** @var array<int, array<string, mixed>> Per-request cache indexed by user ID. */
	private $summary_cache = array();

	/** @var array<string, bool> Cached DB table existence flags. */
	private $table_exists = array();

	public function __construct( VipPlans $plans ) {
		$this->plans = $plans;
	}

	/**
	 * Ensure legacy `mw_vip_grants` user meta rows are indexed into
	 * `mw_vip_effective_until` and `mw_vip_ever_granted` once so `users.php`
	 * views and sortable columns immediately include historical members.
	 */
	public function ensure_status_index_backfilled(): void {
		if ( '1' === (string) get_option( self::BACKFILL_OPTION, '' ) ) {
			return;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! ( $wpdb instanceof \wpdb ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time index backfill guarded by persistent option.
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 2000",
				VipPlans::GRANTS_META_KEY
			)
		);

		if ( is_array( $user_ids ) ) {
			foreach ( $user_ids as $raw_id ) {
				$uid = (int) $raw_id;
				if ( $uid > 0 ) {
					$this->plans->sync_status_index( $uid );
				}
			}
		}

		update_option( self::BACKFILL_OPTION, '1', true );
	}

	/**
	 * Batched summary metrics + admin deep links for a list of user IDs (`users.php`).
	 *
	 * @param array<int, int> $user_ids User IDs on the current `users.php` page.
	 * @return array<int, array<string, mixed>>
	 */
	public function inspect_batch( array $user_ids ): array {
		$clean_ids = array();
		foreach ( $user_ids as $id ) {
			$uid = (int) $id;
			if ( $uid > 0 && ! isset( $this->summary_cache[ $uid ] ) ) {
				$clean_ids[ $uid ] = $uid;
			}
		}

		if ( ! empty( $clean_ids ) ) {
			$ids_list = array_values( $clean_ids );
			foreach ( $ids_list as $uid ) {
				$this->summary_cache[ $uid ] = $this->empty_summary( $uid );
			}

			$this->populate_listening_counts( $ids_list );
			$this->populate_library_counts( $ids_list );
			$this->populate_playlist_counts( $ids_list );
			$this->populate_request_counts( $ids_list );
			$this->populate_woocommerce_counts( $ids_list );
		}

		$out = array();
		foreach ( $user_ids as $id ) {
			$uid = (int) $id;
			if ( $uid > 0 ) {
				$out[ $uid ] = $this->summary_cache[ $uid ] ?? $this->empty_summary( $uid );
			}
		}

		return $out;
	}

	/**
	 * Single-user summary + recent items drill-down for `user-edit.php` / `profile.php`.
	 *
	 * @return array<string, mixed>
	 */
	public function inspect_user( int $user_id ): array {
		if ( $user_id < 1 ) {
			return $this->empty_summary( 0 );
		}

		$batch   = $this->inspect_batch( array( $user_id ) );
		$summary = $batch[ $user_id ] ?? $this->empty_summary( $user_id );

		$summary['recent_tracks']   = $this->recent_tracks_for_user( $user_id, 6 );
		$summary['user_playlists']  = $this->playlists_for_user( $user_id, 6 );
		$summary['recent_requests'] = $this->recent_requests_for_user( $user_id, 6 );

		return $summary;
	}

	/**
	 * HPOS-compatible WooCommerce customer orders list URL in `wp-admin`.
	 */
	public static function customer_orders_url( int $user_id ): string {
		if ( $user_id < 1 || ! self::is_woocommerce_active() ) {
			return '';
		}

		if ( self::is_hpos_enabled() ) {
			return (string) add_query_arg(
				array(
					'page'           => 'wc-orders',
					'_customer_user' => (string) $user_id,
				),
				admin_url( 'admin.php' )
			);
		}

		return (string) add_query_arg(
			array(
				'post_type'      => 'shop_order',
				'_customer_user' => (string) $user_id,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * HPOS-compatible single WooCommerce order edit URL in `wp-admin`.
	 */
	public static function order_edit_url( int $order_id ): string {
		if ( $order_id < 1 ) {
			return '';
		}

		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( is_object( $order ) && method_exists( $order, 'get_edit_order_url' ) ) {
				$url = (string) $order->get_edit_order_url();
				if ( '' !== $url ) {
					return $url;
				}
			}
		}

		if ( self::is_hpos_enabled() ) {
			return (string) add_query_arg(
				array(
					'page'   => 'wc-orders',
					'action' => 'edit',
					'id'     => (string) $order_id,
				),
				admin_url( 'admin.php' )
			);
		}

		return (string) add_query_arg(
			array(
				'post'   => (string) $order_id,
				'action' => 'edit',
			),
			admin_url( 'post.php' )
		);
	}

	/**
	 * Direct admin URL for MusicWave song requests submitted by a user.
	 */
	public static function user_requests_url( int $user_id ): string {
		if ( $user_id < 1 ) {
			return '';
		}

		return (string) add_query_arg(
			array(
				'post_type' => 'mw_request',
				'author'    => (string) $user_id,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Direct admin URL to a user's VIP management panel on `user-edit.php`.
	 */
	public static function user_vip_panel_url( int $user_id ): string {
		if ( $user_id < 1 ) {
			return admin_url( 'users.php' );
		}

		return (string) add_query_arg(
			array( 'user_id' => (string) $user_id ),
			admin_url( 'user-edit.php' )
		) . '#mw-vip-user-panel';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function empty_summary( int $user_id ): array {
		return array(
			'user_id'           => $user_id,
			'orders_count'      => 0,
			'total_spent_raw'   => 0.0,
			'total_spent_label' => '',
			'plays_count'       => 0,
			'in_progress_count' => 0,
			'last_activity_at'  => 0,
			'library_total'     => 0,
			'saved_releases'    => 0,
			'followed_artists'  => 0,
			'wishlist_count'    => 0,
			'playlists_total'   => 0,
			'public_playlists'  => 0,
			'requests_total'    => 0,
			'requests_open'     => 0,
			'recent_tracks'     => array(),
			'user_playlists'    => array(),
			'recent_requests'   => array(),
			'links'             => array(
				'orders'    => self::customer_orders_url( $user_id ),
				'requests'  => self::user_requests_url( $user_id ),
				'profile'   => self::user_vip_panel_url( $user_id ),
				'vip_admin' => admin_url( 'options-general.php?page=music-wave-vip' ),
				'playlists' => function_exists( 'home_url' ) ? home_url( '/playlists/' ) : '/playlists/',
			),
		);
	}

	/**
	 * @param array<int, int> $user_ids Clean positive user IDs.
	 */
	private function populate_listening_counts( array $user_ids ): void {
		$table = $this->prefixed_table( 'mw_user_activity' );
		if ( '' === $table || ! $this->has_table( $table ) ) {
			return;
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Validated prefixed table & dynamic numeric `%d` IN-list placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id,
					SUM(CASE WHEN event = 'played' THEN 1 ELSE 0 END) AS plays_count,
					SUM(CASE WHEN event = 'progress' THEN 1 ELSE 0 END) AS progress_count,
					MAX(updated_at) AS last_activity_at
				FROM {$table}
				WHERE user_id IN ({$placeholders})
				GROUP BY user_id",
				...$user_ids
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$uid = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
			if ( ! isset( $this->summary_cache[ $uid ] ) ) {
				continue;
			}
			$this->summary_cache[ $uid ]['plays_count']       = max( 0, (int) ( $row['plays_count'] ?? 0 ) );
			$this->summary_cache[ $uid ]['in_progress_count'] = max( 0, (int) ( $row['progress_count'] ?? 0 ) );
			$this->summary_cache[ $uid ]['last_activity_at']  = max( 0, (int) ( $row['last_activity_at'] ?? 0 ) );
		}
	}

	/**
	 * @param array<int, int> $user_ids Clean positive user IDs.
	 */
	private function populate_library_counts( array $user_ids ): void {
		$table = $this->prefixed_table( 'mw_library_index' );
		if ( '' !== $table && $this->has_table( $table ) ) {
			global $wpdb;
			$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Validated prefixed table & dynamic numeric `%d` IN-list placeholders.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id,
						COUNT(*) AS total_items,
						SUM(CASE WHEN type = 'release' THEN 1 ELSE 0 END) AS saved_releases,
						SUM(CASE WHEN type = 'artist' THEN 1 ELSE 0 END) AS followed_artists,
						SUM(CASE WHEN type = 'wishlist' THEN 1 ELSE 0 END) AS wishlist_count
					FROM {$table}
					WHERE user_id IN ({$placeholders})
					GROUP BY user_id",
					...$user_ids
				),
				ARRAY_A
			);
			// phpcs:enable

			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$uid = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
					if ( ! isset( $this->summary_cache[ $uid ] ) ) {
						continue;
					}
					$this->summary_cache[ $uid ]['library_total']    = max( 0, (int) ( $row['total_items'] ?? 0 ) );
					$this->summary_cache[ $uid ]['saved_releases']   = max( 0, (int) ( $row['saved_releases'] ?? 0 ) );
					$this->summary_cache[ $uid ]['followed_artists'] = max( 0, (int) ( $row['followed_artists'] ?? 0 ) );
					$this->summary_cache[ $uid ]['wishlist_count']   = max( 0, (int) ( $row['wishlist_count'] ?? 0 ) );
				}
				return;
			}
		}

		// User-meta fallback when `mw_library_index` has not been created yet.
		foreach ( $user_ids as $uid ) {
			$meta = get_user_meta( $uid, 'mw_music_library', true );
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$releases = isset( $meta['release'] ) && is_array( $meta['release'] ) ? count( $meta['release'] ) : 0;
			$artists  = isset( $meta['artist'] ) && is_array( $meta['artist'] ) ? count( $meta['artist'] ) : 0;
			$wishlist = isset( $meta['wishlist'] ) && is_array( $meta['wishlist'] ) ? count( $meta['wishlist'] ) : 0;

			$this->summary_cache[ $uid ]['saved_releases']   = $releases;
			$this->summary_cache[ $uid ]['followed_artists'] = $artists;
			$this->summary_cache[ $uid ]['wishlist_count']   = $wishlist;
			$this->summary_cache[ $uid ]['library_total']    = $releases + $artists + $wishlist;
		}
	}

	/**
	 * @param array<int, int> $user_ids Clean positive user IDs.
	 */
	private function populate_playlist_counts( array $user_ids ): void {
		$table = $this->prefixed_table( 'mw_playlists' );
		if ( '' === $table || ! $this->has_table( $table ) ) {
			return;
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Validated prefixed table & dynamic numeric `%d` IN-list placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id,
					COUNT(*) AS total_playlists,
					SUM(CASE WHEN visibility = 'public' THEN 1 ELSE 0 END) AS public_playlists
				FROM {$table}
				WHERE user_id IN ({$placeholders})
				GROUP BY user_id",
				...$user_ids
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$uid = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
			if ( ! isset( $this->summary_cache[ $uid ] ) ) {
				continue;
			}
			$this->summary_cache[ $uid ]['playlists_total']  = max( 0, (int) ( $row['total_playlists'] ?? 0 ) );
			$this->summary_cache[ $uid ]['public_playlists'] = max( 0, (int) ( $row['public_playlists'] ?? 0 ) );
		}
	}

	/**
	 * @param array<int, int> $user_ids Clean positive user IDs.
	 */
	private function populate_request_counts( array $user_ids ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! ( $wpdb instanceof \wpdb ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Validated core posts table & dynamic numeric `%d` IN-list placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_author AS user_id,
					COUNT(*) AS total_requests,
					SUM(CASE WHEN post_status IN ('mw_req_new', 'mw_req_review') THEN 1 ELSE 0 END) AS open_requests
				FROM {$wpdb->posts}
				WHERE post_type = 'mw_request'
					AND post_status NOT IN ('trash', 'auto-draft')
					AND post_author IN ({$placeholders})
				GROUP BY post_author",
				...$user_ids
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$uid = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
			if ( ! isset( $this->summary_cache[ $uid ] ) ) {
				continue;
			}
			$this->summary_cache[ $uid ]['requests_total'] = max( 0, (int) ( $row['total_requests'] ?? 0 ) );
			$this->summary_cache[ $uid ]['requests_open']  = max( 0, (int) ( $row['open_requests'] ?? 0 ) );
		}
	}

	/**
	 * @param array<int, int> $user_ids Clean positive user IDs.
	 */
	private function populate_woocommerce_counts( array $user_ids ): void {
		foreach ( $user_ids as $uid ) {
			$count = 0;
			$spent = 0.0;

			if ( function_exists( 'wc_get_customer_order_count' ) ) {
				$count = max( 0, (int) wc_get_customer_order_count( $uid ) );
			}
			if ( function_exists( 'wc_get_customer_total_spent' ) ) {
				$spent = max( 0.0, (float) wc_get_customer_total_spent( $uid ) );
			}

			// Fallback: count distinct WooCommerce order IDs recorded in VIP grants.
			if ( 0 === $count ) {
				$seen_orders = array();
				foreach ( $this->plans->all_grants_for_user( $uid ) as $entry ) {
					$oid = isset( $entry['order_id'] ) ? (int) $entry['order_id'] : 0;
					if ( $oid > 0 ) {
						$seen_orders[ $oid ] = true;
					}
				}
				$count = count( $seen_orders );
			}

			$spent_label = '';
			if ( $spent > 0 ) {
				$spent_label = function_exists( 'wc_price' )
					? wp_strip_all_tags( (string) wc_price( $spent ) )
					: number_format_i18n( $spent );
			}

			$this->summary_cache[ $uid ]['orders_count']      = $count;
			$this->summary_cache[ $uid ]['total_spent_raw']   = $spent;
			$this->summary_cache[ $uid ]['total_spent_label'] = $spent_label;
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function recent_tracks_for_user( int $user_id, int $limit ): array {
		$table = $this->prefixed_table( 'mw_user_activity' );
		if ( '' === $table || ! $this->has_table( $table ) ) {
			return array();
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Validated prefixed table name.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT release_id, event, position, updated_at
				FROM {$table}
				WHERE user_id = %d
				ORDER BY updated_at DESC
				LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$release_id = isset( $row['release_id'] ) ? (int) $row['release_id'] : 0;
			if ( $release_id < 1 ) {
				continue;
			}
			$title = function_exists( 'get_the_title' ) ? (string) get_the_title( $release_id ) : '';
			$out[] = array(
				'release_id' => $release_id,
				'title'      => '' !== trim( $title ) ? $title : sprintf( '#%d', $release_id ),
				'event'      => isset( $row['event'] ) ? (string) $row['event'] : 'played',
				'updated_at' => isset( $row['updated_at'] ) ? (int) $row['updated_at'] : 0,
				'edit_url'   => (string) add_query_arg(
					array(
						'post'   => (string) $release_id,
						'action' => 'edit',
					),
					admin_url( 'post.php' )
				),
				'view_url'   => function_exists( 'get_permalink' ) ? (string) get_permalink( $release_id ) : '',
			);
		}

		return $out;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function playlists_for_user( int $user_id, int $limit ): array {
		if ( class_exists( PlaylistRepository::class ) ) {
			$repo = new PlaylistRepository();
			$list = array_slice( $repo->for_user( $user_id ), 0, $limit );
			$out  = array();
			foreach ( $list as $pl ) {
				$vis   = (string) ( $pl['visibility'] ?? 'private' );
				$out[] = array(
					'id'         => (int) $pl['id'],
					'title'      => (string) $pl['title'],
					'visibility' => $vis,
					'share_url'  => $repo->public_url( (int) $pl['id'], $vis, (string) ( $pl['share_token'] ?? '' ) ),
					'updated_at' => (int) ( $pl['updated_at'] ?? 0 ),
				);
			}
			return $out;
		}

		return array();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function recent_requests_for_user( int $user_id, int $limit ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => 'mw_request',
				'post_status'    => array( 'mw_req_new', 'mw_req_review', 'mw_req_answered', 'mw_req_closed', 'publish' ),
				'author'         => $user_id,
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$out = array();
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$out[] = array(
				'id'       => (int) $post->ID,
				'title'    => '' !== trim( (string) $post->post_title ) ? (string) $post->post_title : sprintf( '#%d', $post->ID ),
				'status'   => (string) $post->post_status,
				'date'     => (string) $post->post_date,
				'edit_url' => (string) add_query_arg(
					array(
						'post'   => (string) $post->ID,
						'action' => 'edit',
					),
					admin_url( 'post.php' )
				),
			);
		}

		return $out;
	}

	private function prefixed_table( string $short_name ): string {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ) {
			return '';
		}

		return (string) $wpdb->prefix . $short_name;
	}

	private function has_table( string $table ): bool {
		if ( isset( $this->table_exists[ $table ] ) ) {
			return $this->table_exists[ $table ];
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! ( $wpdb instanceof \wpdb ) ) {
			$this->table_exists[ $table ] = false;
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found                        = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->table_exists[ $table ] = ( $found === $table );

		return $this->table_exists[ $table ];
	}

	private static function is_woocommerce_active(): bool {
		return function_exists( 'WC' )
			|| class_exists( 'WooCommerce' )
			|| function_exists( 'wc_get_order' )
			|| ( function_exists( 'post_type_exists' ) && post_type_exists( 'product' ) );
	}

	private static function is_hpos_enabled(): bool {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
