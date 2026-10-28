<?php
/**
 * View counter with anti-bot, dedupe and rate limiting (FIX-11).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Analytics;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Support\ClientIp;
use ManaCore\MusicWave\Core\Support\DatabaseTable;
use ManaCore\MusicWave\Core\Support\RouteRateLimiter;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ViewCounter {
	public const TABLE         = 'mw_release_views';
	public const CRON_HOOK     = 'music_wave_hourly_view_sync';
	public const DEDUPE_TTL    = 600; // 10 minutes
	public const DWELL_DEFAULT = 10; // seconds.

	/** @var RouteRateLimiter */
	private $limiter;

	/** @var bool|null */
	private $table_available = null;

	public function __construct( ?RouteRateLimiter $limiter = null ) {
		$this->limiter = null !== $limiter ? $limiter : new RouteRateLimiter();
	}

	public function register(): void {
		add_action( 'init', array( $this, 'schedule_cron' ) );
		add_action( self::CRON_HOOK, array( $this, 'sync_to_meta' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}
		if ( ! is_singular( 'mw_release' ) ) {
			return;
		}
		// Only enqueue if not editor (editors not counted).
		if ( function_exists( 'current_user_can' ) && current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		wp_enqueue_script( 'music-wave-view-counter', MUSIC_WAVE_CORE_URL . 'assets/view-counter.js', array(), MUSIC_WAVE_CORE_VERSION, true );
		$dwell = (int) apply_filters( 'music_wave_view_dwell_seconds', self::DWELL_DEFAULT );
		wp_localize_script(
			'music-wave-view-counter',
			'musicWaveViewCounter',
			array(
				'releaseId'    => get_the_ID(),
				'dwellSeconds' => $dwell > 0 ? $dwell : self::DWELL_DEFAULT,
				'restUrl'      => function_exists( 'rest_url' ) ? rest_url() : '/wp-json/',
			)
		);
	}

	public function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Whether a view should be counted.
	 *
	 * @param int $release_id Release ID.
	 * @param string $user_agent UA string.
	 * @return bool|string true or error code
	 */
	public function should_count( int $release_id, string $user_agent = '' ) {
		if ( $release_id < 1 ) {
			return 'invalid_id';
		}
		$post = get_post( $release_id );
		if ( ! $post || ReleasePostType::KEY !== $post->post_type || 'publish' !== $post->post_status ) {
			return 'not_published';
		}
		// Visibility check via filter? Use ReleaseVisibility if available, else allow.
		if ( has_filter( 'music_wave_release_visibility' ) ) {
			$visible = apply_filters( 'music_wave_release_visibility', true, $release_id, get_current_user_id() );
			if ( ! $visible ) {
				return 'not_visible';
			}
		}
		// Editor users not counted.
		if ( current_user_can( 'edit_others_posts' ) ) {
			return 'is_editor';
		}
		// Bot filter.
		if ( $this->is_bot( $user_agent ) ) {
			return 'is_bot';
		}
		// Dedupe: same visitor in last 10 min for this release.
		if ( $this->recently_counted( $release_id ) ) {
			return 'recently_counted';
		}
		// Rate limit.
		$actor = ClientIp::current_actor();
		/**
		 * Filter view dwell seconds.
		 * @param int $seconds
		 */
		$dwell  = (int) apply_filters( 'music_wave_view_dwell_seconds', self::DWELL_DEFAULT );
		$limit  = (int) apply_filters( 'music_wave_view_rate_limit', 30, $release_id, $actor );
		$window = (int) apply_filters( 'music_wave_view_rate_window', 60, $release_id, $actor );
		if ( $limit > 0 ) {
			if ( ! $this->limiter->allow( 'views', $actor, $limit, max( 10, $window ) ) ) {
				return 'rate_limited';
			}
		}

		return true;
	}

	/**
	 * Record a view (atomic upsert).
	 */
	public function record( int $release_id ): bool {
		global $wpdb;
		if ( ! ( $wpdb instanceof \wpdb ) || ! DatabaseTable::exists( self::TABLE, $this->table_available ) ) {
			return false;
		}
		$table = $wpdb->prefix . self::TABLE;
		$now   = time();
		// INSERT ... ON DUPLICATE KEY UPDATE views = views + 1.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (release_id, views, updated_at) VALUES (%d, 1, %d) ON DUPLICATE KEY UPDATE views = views + 1, updated_at = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$release_id,
				$now,
				$now
			)
		);
		if ( false !== $result ) {
			$this->mark_counted( $release_id );
			return true;
		}
		return false;
	}

	/**
	 * Hourly cron: sync table -> mw_views meta for sorting compatibility (max 200 per run).
	 */
	public function sync_to_meta(): void {
		global $wpdb;
		if ( ! ( $wpdb instanceof \wpdb ) || ! DatabaseTable::exists( self::TABLE, $this->table_available ) ) {
			return;
		}
		$table = $wpdb->prefix . self::TABLE;
		$rows  = $wpdb->get_results( "SELECT release_id, views FROM {$table} ORDER BY updated_at DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $rows ) ) {
			return;
		}
		foreach ( $rows as $row ) {
			$rid   = isset( $row['release_id'] ) ? (int) $row['release_id'] : 0;
			$views = isset( $row['views'] ) ? (int) $row['views'] : 0;
			if ( $rid > 0 ) {
				update_post_meta( $rid, 'mw_views', $views );
			}
		}
	}

	private function recently_counted( int $release_id ): bool {
		return false !== get_transient( $this->dedupe_key( $release_id ) );
	}

	private function mark_counted( int $release_id ): void {
		set_transient( $this->dedupe_key( $release_id ), 1, self::DEDUPE_TTL );
	}

	private function dedupe_key( int $release_id ): string {
		return 'mw_view_dedupe_' . $release_id . '_' . ClientIp::current_actor();
	}

	/**
	 * Simple bot detection, filterable.
	 *
	 * @param string $ua
	 * @return bool
	 */
	private function is_bot( string $ua ): bool {
		if ( '' === $ua ) {
			return false;
		}
		/**
		 * Filter bot UA patterns.
		 * @param array $patterns
		 */
		$patterns = apply_filters(
			'music_wave_bot_ua_patterns',
			array( 'bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'baidu', 'yandex', 'sogou', 'exabot', 'facebot', 'ia_archiver' )
		);
		if ( ! is_array( $patterns ) ) {
			return false;
		}
		$ua_lower = strtolower( $ua );
		foreach ( $patterns as $p ) {
			if ( is_string( $p ) && '' !== $p && false !== strpos( $ua_lower, strtolower( $p ) ) ) {
				return true;
			}
		}
		return false;
	}
}
