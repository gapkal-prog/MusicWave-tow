<?php
/**
 * Safe batched access-mode and entitlement management for releases.
 *
 * Supports all canonical modes (`public`, `logged_in`, `purchase`, `membership`,
 * `purchase_or_membership`, `restricted`), taxonomy filtering (`mw_release_type`,
 * `mw_artist`, `mw_genre`), optional bulk VIP level assignment, bidirectional
 * WooCommerce product attachment, and live catalog mode counts.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Admin;

use ManaCore\MusicWave\Core\Access\AccessModePolicy;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Commerce\ProductMapper;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BulkAccessManager {
	public const COUNTS_TRANSIENT = 'mw_bulk_access_mode_counts';
	private const BATCH_SIZE      = 200;
	private const JOB_KEY         = 'music_wave_bulk_access_job_';
	private const NOTICE_KEY      = 'music_wave_bulk_access_notice_';

	/** @var ReleaseRepository */
	private $releases;

	/** @var ProductMapper */
	private $product_mapper;

	/** @var int */
	private $query_cursor = 0;

	public function __construct( ReleaseRepository $releases, ?ProductMapper $product_mapper = null ) {
		$this->releases       = $releases;
		$this->product_mapper = null !== $product_mapper ? $product_mapper : new ProductMapper();
	}

	public function register(): void {
		add_action( 'admin_post_music_wave_bulk_access_start', array( $this, 'start' ) );
		add_action( 'admin_post_music_wave_bulk_access_continue', array( $this, 'continue_job' ) );
		add_action( 'admin_post_music_wave_bulk_access_cancel', array( $this, 'cancel' ) );
		add_action( 'save_post_' . ReleasePostType::KEY, array( self::class, 'invalidate_counts_cache' ) );
		add_action( 'deleted_post', array( self::class, 'invalidate_counts_cache_for_post' ) );
		add_action( 'trashed_post', array( self::class, 'invalidate_counts_cache_for_post' ) );
		add_action( 'untrashed_post', array( self::class, 'invalidate_counts_cache_for_post' ) );
	}

	public static function invalidate_counts_cache(): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::COUNTS_TRANSIENT );
		}
	}

	/**
	 * @param int $post_id Post ID being deleted, trashed, or restored.
	 */
	public static function invalidate_counts_cache_for_post( $post_id ): void {
		$id = absint( $post_id );
		if ( $id < 1 || ReleasePostType::KEY === get_post_type( $id ) ) {
			self::invalidate_counts_cache();
		}
	}

	public function start(): void {
		$this->authorize( 'music_wave_bulk_access_start' );

		$target       = $this->request_key( 'target_mode' );
		$current      = $this->request_key( 'current_mode' );
		$status       = $this->request_key( 'post_status' );
		$release_type = $this->request_key( 'release_type' );
		$artist_slug  = $this->request_key( 'artist_slug' );
		$genre_slug   = $this->request_key( 'genre_slug' );
		$raw_levels   = isset( $_POST['bulk_membership_levels'] ) && is_scalar( $_POST['bulk_membership_levels'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by authorize().
			? AccessModePolicy::sanitize_levels( sanitize_text_field( wp_unslash( (string) $_POST['bulk_membership_levels'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: array();
		$bulk_product = isset( $_POST['bulk_product_id'] ) ? absint( $_POST['bulk_product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $bulk_product > 0 && 'product' !== get_post_type( $bulk_product ) ) {
			$bulk_product = 0;
		}

		if ( ! in_array( $target, AccessModePolicy::keys(), true ) ) {
			$this->fail( __( 'یک حالت دسترسی هدف معتبر را انتخاب کنید.', 'music-wave-core' ) );
		}
		if ( 'any' !== $current && ! in_array( $current, AccessModePolicy::keys(), true ) ) {
			$current = 'any';
		}
		if ( ! in_array( $status, array( 'any', 'publish', 'draft', 'pending', 'private', 'future' ), true ) ) {
			$status = 'any';
		}

		$job = array(
			'target_mode'            => $target,
			'current_mode'           => $current,
			'post_status'            => $status,
			'release_type'           => $release_type,
			'artist_slug'            => $artist_slug,
			'genre_slug'             => $genre_slug,
			'bulk_membership_levels' => implode( ',', $raw_levels ),
			'bulk_product_id'        => $bulk_product,
			'cursor'                 => 0,
			'updated'                => 0,
			'skipped'                => 0,
		);
		set_transient( $this->job_key(), $job, HOUR_IN_SECONDS );
		$this->process( $job );
	}

	public function continue_job(): void {
		$this->authorize( 'music_wave_bulk_access_continue' );
		$job = get_transient( $this->job_key() );
		if ( ! is_array( $job ) ) {
			$this->fail( __( 'عملیات دسترسی گروهی منقضی شده است. آن را دوباره اجرا کنید.', 'music-wave-core' ) );
		}

		$this->process( $job );
	}

	public function cancel(): void {
		$this->authorize( 'music_wave_bulk_access_cancel' );
		delete_transient( $this->job_key() );
		$this->set_notice( __( 'عملیات دسترسی گروهی لغو شد.', 'music-wave-core' ), 'warning', false );
		$this->redirect();
	}

	/**
	 * Count catalog releases grouped by canonical access mode for live admin preview.
	 *
	 * Uses a 5-minute transient cache invalidated on release mutations and a single
	 * aggregated SQL `GROUP BY` query (`O(1)` DB round-trips regardless of catalog size),
	 * with a seamless `WP_Query` fallback for non-SQL unit test harnesses.
	 *
	 * @param bool $force_refresh Bypass transient cache when true.
	 * @return array<string, int>
	 */
	public function mode_counts( bool $force_refresh = false ): array {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::COUNTS_TRANSIENT );
			if ( is_array( $cached ) ) {
				$normalized = array_fill_keys( AccessModePolicy::keys(), 0 );
				foreach ( $normalized as $mode => $zero ) {
					unset( $zero );
					if ( isset( $cached[ $mode ] ) ) {
						$normalized[ $mode ] = max( 0, (int) $cached[ $mode ] );
					}
				}
				return $normalized;
			}
		}

		$counts = $this->sql_mode_counts();
		if ( null === $counts ) {
			$counts = $this->fallback_mode_counts();
		}

		$ttl = defined( 'MINUTE_IN_SECONDS' ) ? 5 * MINUTE_IN_SECONDS : 300;
		set_transient( self::COUNTS_TRANSIENT, $counts, $ttl );

		return $counts;
	}

	/**
	 * @return array<string, int>|null
	 */
	private function sql_mode_counts(): ?array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->posts ) || empty( $wpdb->postmeta ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return null;
		}

		$sql  = $wpdb->prepare(
			"SELECT COALESCE(NULLIF(pm.meta_value, ''), 'public') AS raw_mode, COUNT(DISTINCT p.ID) AS total
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			WHERE p.post_type = %s
			  AND p.post_status IN ('publish', 'draft', 'pending', 'private', 'future')
			GROUP BY COALESCE(NULLIF(pm.meta_value, ''), 'public')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'mw_access_mode',
			ReleasePostType::KEY
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) ) {
			return null;
		}

		$counts = array_fill_keys( AccessModePolicy::keys(), 0 );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$mode             = AccessModePolicy::sanitize_mode( isset( $row['raw_mode'] ) ? (string) $row['raw_mode'] : 'public', 'public' );
			$counts[ $mode ] += isset( $row['total'] ) ? max( 0, (int) $row['total'] ) : 0;
		}

		return $counts;
	}

	/**
	 * @return array<string, int>
	 */
	private function fallback_mode_counts(): array {
		$counts = array_fill_keys( AccessModePolicy::keys(), 0 );
		$page   = 1;
		do {
			$query       = new WP_Query(
				array(
					'post_type'              => ReleasePostType::KEY,
					'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page'         => 100,
					'paged'                  => $page,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				)
			);
			$ids         = is_array( $query->posts ) ? array_map( 'absint', $query->posts ) : array();
			$batch_count = count( $ids );
			foreach ( $ids as $id ) {
				$mode = AccessModePolicy::sanitize_mode( (string) get_post_meta( $id, 'mw_access_mode', true ), 'public' );
				++$counts[ $mode ];
			}
			++$page;
		} while ( 100 === $batch_count && $page <= 50 );

		return $counts;
	}

	/**
	 * Return and consume the current user's operation notice.
	 *
	 * @return array<string, bool|string>|null
	 */
	public function consume_notice(): ?array {
		$notice = get_transient( self::NOTICE_KEY . get_current_user_id() );
		if ( ! is_array( $notice ) ) {
			return null;
		}

		delete_transient( self::NOTICE_KEY . get_current_user_id() );

		return $notice;
	}

	/**
	 * Return the active batch job for the current user.
	 *
	 * @return array<string, int|string>|null
	 */
	public function active_job(): ?array {
		$job = get_transient( $this->job_key() );

		return is_array( $job ) ? $job : null;
	}

	/**
	 * @param array<string, int|string> $job Current operation.
	 */
	private function process( array $job ): void {
		$query   = $this->query( $job );
		$ids     = is_array( $query->posts ) ? array_map( 'absint', $query->posts ) : array();
		$levels  = isset( $job['bulk_membership_levels'] ) ? AccessModePolicy::sanitize_levels( (string) $job['bulk_membership_levels'] ) : array();
		$prod_id = isset( $job['bulk_product_id'] ) ? absint( $job['bulk_product_id'] ) : 0;
		$pids    = $prod_id > 0 ? array( $prod_id ) : array();

		foreach ( $ids as $release_id ) {
			$job['cursor'] = max( (int) $job['cursor'], $release_id );
			$old_pids      = $this->releases->product_ids( $release_id );
			$resolved      = AccessModePolicy::resolve_for_release( $this->releases, $release_id, (string) $job['target_mode'], $levels, $pids );
			if ( ! empty( $pids ) ) {
				$this->product_mapper->sync_reverse_index( $release_id, $old_pids, $this->releases->product_ids( $release_id ) );
			}
			if ( null === $resolved['mode'] ) {
				++$job['skipped'];
				continue;
			}
			$current = (string) $this->releases->get( $release_id, 'mw_access_mode' );
			if ( $current === $resolved['mode'] || $this->releases->update( $release_id, 'mw_access_mode', $resolved['mode'] ) ) {
				++$job['updated'];
			} else {
				++$job['skipped'];
			}
		}

		if ( count( $ids ) >= self::BATCH_SIZE ) {
			set_transient( $this->job_key(), $job, HOUR_IN_SECONDS );
			$message = sprintf(
				/* translators: 1: number of releases updated so far, 2: number of releases skipped so far. */
				__( 'دسترسی انبوه همچنان در حال اجرا است: %1$d به‌روزرسانی شد و %2$d تاکنون نادیده گرفته شده است.', 'music-wave-core' ),
				(int) $job['updated'],
				(int) $job['skipped']
			);
			$this->set_notice( $message, 'info', true );
		} else {
			delete_transient( $this->job_key() );
			$message = sprintf(
				/* translators: 1: number of releases updated, 2: number of releases skipped. */
				__( 'دسترسی انبوه تکمیل شد: %1$d انتشار با موفقیت به‌روزرسانی شدند و %2$d مورد به دلیل نداشتن محصول خرید پیوندشده نادیده گرفته شد.', 'music-wave-core' ),
				(int) $job['updated'],
				(int) $job['skipped']
			);
			$this->set_notice( $message, 'success', false );
		}

		self::invalidate_counts_cache();
		$this->redirect();
	}

	/**
	 * @param array<string, int|string> $job Current operation.
	 */
	private function query( array $job ): WP_Query {
		$arguments = array(
			'post_type'              => ReleasePostType::KEY,
			'post_status'            => 'any' === $job['post_status'] ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : (string) $job['post_status'],
			'posts_per_page'         => self::BATCH_SIZE,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'cache_results'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( 'any' !== $job['current_mode'] ) {
			$registered_default = 'public';
			if ( $registered_default === $job['current_mode'] ) {
				$arguments['meta_query'] = array(
					'relation' => 'OR',
					array(
						'key'     => 'mw_access_mode',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => 'mw_access_mode',
						'value' => $registered_default,
					),
				);
			} else {
				$arguments['meta_query'] = array(
					array(
						'key'   => 'mw_access_mode',
						'value' => (string) $job['current_mode'],
					),
				);
			}
		}

		$tax_clauses = array();
		if ( ! empty( $job['release_type'] ) && 'any' !== $job['release_type'] ) {
			$tax_clauses[] = array(
				'taxonomy' => 'mw_release_type',
				'field'    => 'slug',
				'terms'    => (string) $job['release_type'],
			);
		}
		if ( ! empty( $job['artist_slug'] ) && 'any' !== $job['artist_slug'] ) {
			$tax_clauses[] = array(
				'taxonomy' => 'mw_artist',
				'field'    => 'slug',
				'terms'    => (string) $job['artist_slug'],
			);
		}
		if ( ! empty( $job['genre_slug'] ) && 'any' !== $job['genre_slug'] ) {
			$tax_clauses[] = array(
				'taxonomy' => 'mw_genre',
				'field'    => 'slug',
				'terms'    => (string) $job['genre_slug'],
			);
		}
		if ( ! empty( $tax_clauses ) ) {
			$arguments['tax_query'] = $tax_clauses;
		}

		$this->query_cursor = (int) $job['cursor'];
		add_filter( 'posts_where', array( $this, 'filter_after_cursor' ), 10, 2 );
		$query = new WP_Query( $arguments );
		remove_filter( 'posts_where', array( $this, 'filter_after_cursor' ), 10 );
		$this->query_cursor = 0;

		return $query;
	}

	/**
	 * @param string $where Existing SQL clause.
	 * @param mixed  $query Query instance.
	 */
	public function filter_after_cursor( string $where, $query ): string {
		unset( $query );
		if ( $this->query_cursor < 1 ) {
			return $where;
		}

		global $wpdb;

		return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $this->query_cursor );
	}

	private function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( $action ) ) {
			wp_die( esc_html__( 'شما مجاز به تغییر تنظیمات دسترسی MusicWave نیستید.', 'music-wave-core' ) );
		}
	}

	private function request_key( string $key ): string {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_key( wp_unslash( (string) $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by authorize().
	}

	private function fail( string $message ): void {
		$this->set_notice( $message, 'error', false );
		$this->redirect();
	}

	private function set_notice( string $message, string $type, bool $pending ): void {
		set_transient(
			self::NOTICE_KEY . get_current_user_id(),
			array(
				'message' => $message,
				'type'    => $type,
				'pending' => $pending,
			),
			MINUTE_IN_SECONDS
		);
	}

	private function job_key(): string {
		return self::JOB_KEY . get_current_user_id();
	}

	private function redirect(): void {
		wp_safe_redirect( admin_url( 'edit.php?post_type=mw_release&page=music-wave-settings&tab=access' ) );
		exit;
	}
}
