<?php
/**
 * WordPress `users.php` Integration for MusicWave VIP.
 *
 * Equips `wp-admin/users.php` with:
 * - Status sub-views (`Active VIP`, `Expiring soon`, `Expired VIP`, `Non-VIP`) with counts.
 * - Plan level filter dropdown in the list table toolbar.
 * - Sortable `وضعیت و مدت VIP` column with inline AJAX Quick-Manage drawer.
 * - Batched zero-N+1 `فعالیت و پرداخت‌ها` column with deep links to WooCommerce
 *   orders/payments, MusicWave requests, listening stats, library, and playlists.
 * - Row quick actions (`مدیریت اشتراک VIP`, `سفارش‌های ووکامرس`).
 * - Bulk actions (`Grant 1m / 6m / 12m`, `Extend +30 days`, `Revoke all VIP`).
 *
 * @package ManaCore\MusicWave\Vip\Users
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Users;

use ManaCore\MusicWave\Vip\PlanProducts;
use ManaCore\MusicWave\Vip\VipPlans;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UsersListIntegration {
	public const COL_STATUS       = 'mw_vip_status';
	public const COL_ACTIVITY     = 'mw_vip_activity';
	public const AJAX_QUICK_ACT   = 'mw_vip_quick_manage';
	public const NONCE_QUICK_ACT  = 'mw_vip_quick_manage_nonce';
	public const FILTER_STATUS    = 'mw_vip_filter';
	public const FILTER_LEVEL     = 'mw_vip_level_filter';
	public const BULK_NOTICE_ARG  = 'mw_vip_bulk_done';
	public const BULK_COUNT_ARG   = 'mw_vip_bulk_count';
	private const EXPIRING_WINDOW = 604800; // 7 days in seconds.

	/** @var VipPlans */
	private $plans;

	/** @var UserActivityInspector */
	private $inspector;

	/** @var bool Whether page rows have been batch-warmed yet. */
	private $primed_table = false;

	public function __construct( VipPlans $plans, UserActivityInspector $inspector ) {
		$this->plans     = $plans;
		$this->inspector = $inspector;
	}

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'load-users.php', array( $this, 'on_load_users_screen' ) );
		add_filter( 'views_users', array( $this, 'register_status_views' ) );
		add_action( 'restrict_manage_users', array( $this, 'render_level_filter' ), 10, 1 );
		add_filter( 'manage_users_columns', array( $this, 'register_columns' ) );
		add_filter( 'manage_users_sortable_columns', array( $this, 'register_sortable_columns' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_column' ), 10, 3 );
		add_action( 'pre_get_users', array( $this, 'filter_and_sort_users_query' ) );
		add_filter( 'user_row_actions', array( $this, 'register_row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-users', array( $this, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-users', array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'render_bulk_notice' ) );
		add_action( 'wp_ajax_' . self::AJAX_QUICK_ACT, array( $this, 'handle_ajax_quick_manage' ) );
	}

	public function on_load_users_screen(): void {
		if ( current_user_can( 'manage_options' ) ) {
			$this->inspector->ensure_status_index_backfilled();
		}
	}

	public function enqueue_assets( string $hook_suffix ): void {
		$allowed = array( 'users.php', 'user-edit.php', 'profile.php' );
		if ( ! in_array( $hook_suffix, $allowed, true ) && ! \ManaCore\MusicWave\Vip\SettingsPage::is_vip_settings_screen( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style(
			'music-wave-vip-admin-users',
			plugins_url( 'assets/admin-vip-users.css', MUSIC_WAVE_VIP_FILE ),
			array(),
			MUSIC_WAVE_VIP_VERSION
		);

		wp_enqueue_script(
			'music-wave-vip-admin-users',
			plugins_url( 'assets/admin-vip-users.js', MUSIC_WAVE_VIP_FILE ),
			array(),
			MUSIC_WAVE_VIP_VERSION,
			true
		);

		wp_localize_script(
			'music-wave-vip-admin-users',
			'musicWaveVipAdmin',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( self::NONCE_QUICK_ACT ),
				'savingText'    => __( 'در حال ذخیره...', 'music-wave-vip' ),
				'errorText'     => __( 'خطا در ذخیره وضعیت VIP.', 'music-wave-vip' ),
				'confirmRevoke' => __( 'همه اشتراک‌های فعال این کاربر لغو شود؟', 'music-wave-vip' ),
			)
		);
	}

	/**
	 * Top status sub-views on `users.php` (`Active VIP`, `Expiring Soon`, `Expired`, `Non-VIP`).
	 *
	 * @param array<string, string> $views Existing role views.
	 * @return array<string, string>
	 */
	public function register_status_views( array $views ): array {
		if ( ! current_user_can( 'list_users' ) ) {
			return $views;
		}

		$current = $this->requested_status_filter();
		$counts  = $this->count_status_buckets();
		$base    = admin_url( 'users.php' );

		$items = array(
			'vip_active'   => array(
				'param' => 'active',
				'label' => __( 'اعضای VIP فعال', 'music-wave-vip' ),
				'count' => $counts['active'],
			),
			'vip_expiring' => array(
				'param' => 'expiring',
				'label' => __( 'نزدیک انقضا (۷ روز)', 'music-wave-vip' ),
				'count' => $counts['expiring'],
			),
			'vip_expired'  => array(
				'param' => 'expired',
				'label' => __( 'VIP منقضی‌شده', 'music-wave-vip' ),
				'count' => $counts['expired'],
			),
			'vip_none'     => array(
				'param' => 'none',
				'label' => __( 'بدون اشتراک VIP', 'music-wave-vip' ),
				'count' => $counts['none'],
			),
		);

		foreach ( $items as $key => $spec ) {
			$url           = (string) add_query_arg( array( self::FILTER_STATUS => $spec['param'] ), $base );
			$is_current    = ( $current === $spec['param'] );
			$views[ $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $url ),
				$is_current ? ' class="current" aria-current="page"' : '',
				esc_html( $spec['label'] ),
				esc_html( number_format_i18n( $spec['count'] ) )
			);
		}

		return $views;
	}

	/**
	 * Plan level filter dropdown inside the `users.php` bulk-actions bar.
	 */
	public function render_level_filter( string $which = 'top' ): void {
		if ( ! current_user_can( 'list_users' ) || 'bottom' === $which ) {
			return;
		}

		$selected = $this->requested_level_filter();
		$levels   = $this->known_plan_choices();

		echo '<label class="screen-reader-text" for="mw-vip-level-top">' . esc_html__( 'فیلتر سطح VIP', 'music-wave-vip' ) . '</label>';
		echo '<select name="' . esc_attr( self::FILTER_LEVEL ) . '" id="mw-vip-level-top" style="float:none;margin:0 4px;">';
		echo '<option value="">' . esc_html__( 'همه سطوح VIP', 'music-wave-vip' ) . '</option>';
		foreach ( $levels as $level => $label ) {
			echo '<option value="' . esc_attr( $level ) . '"' . selected( $selected, $level, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		submit_button( __( 'صافی VIP', 'music-wave-vip' ), 'secondary', 'mw_vip_apply_filter', false );
	}

	/**
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function register_columns( array $columns ): array {
		$columns[ self::COL_STATUS ]   = __( 'وضعیت و مدت VIP', 'music-wave-vip' );
		$columns[ self::COL_ACTIVITY ] = __( 'فعالیت و پرداخت‌ها', 'music-wave-vip' );
		return $columns;
	}

	/**
	 * @param array<string, string> $columns Sortable columns.
	 * @return array<string, string>
	 */
	public function register_sortable_columns( array $columns ): array {
		$columns[ self::COL_STATUS ] = self::COL_STATUS;
		return $columns;
	}

	/**
	 * Render custom `users.php` column cells.
	 */
	public function render_column( string $output, string $column_name, int $user_id ): string {
		if ( self::COL_STATUS !== $column_name && self::COL_ACTIVITY !== $column_name ) {
			return $output;
		}

		$this->prime_current_table_users( $user_id );

		if ( self::COL_STATUS === $column_name ) {
			return $this->render_status_cell( $user_id );
		}

		return $this->render_activity_cell( $user_id );
	}

	/**
	 * Render standalone status cell HTML (reused identically by `users.php` SSR & AJAX quick-edit!).
	 */
	public function render_status_cell( int $user_id ): string {
		$now        = time();
		$active     = $this->plans->detailed_active_grants( $user_id );
		$all_grants = $this->plans->all_grants_for_user( $user_id );
		$can_manage = current_user_can( 'manage_options' );
		$levels     = $this->known_plan_choices();

		$primary = isset( $active[0] ) ? $active[0] : null;
		$pill    = 'none';
		$badge   = __( 'کاربر عادی', 'music-wave-vip' );
		$meta    = '';
		$pct     = null;

		if ( null !== $primary ) {
			$lvl_slug = (string) ( $primary['level'] ?? '' );
			$title    = isset( $levels[ $lvl_slug ] ) ? $levels[ $lvl_slug ] : strtoupper( $lvl_slug );
			if ( ! empty( $primary['is_lifetime'] ) ) {
				$pill  = 'lifetime';
				$badge = sprintf(
					/* translators: %s: VIP plan title. */
					__( 'VIP مادام‌العمر · %s', 'music-wave-vip' ),
					$title
				);
				$meta = __( 'دسترسی دائمی بدون انقضا', 'music-wave-vip' );
			} else {
				$days = isset( $primary['remaining_days'] ) ? (int) $primary['remaining_days'] : 0;
				$pill = $days <= 7 ? 'expiring' : 'active';
				/* translators: %s: VIP plan title. */
				$badge = sprintf( __( 'VIP فعال · %s', 'music-wave-vip' ), $title );
				$meta  = sprintf(
					/* translators: 1: remaining days, 2: expiry date string. */
					__( '%1$d روز مانده (تا %2$s)', 'music-wave-vip' ),
					$days,
					(string) $primary['expires_label']
				);
				if ( isset( $primary['percent_used'] ) && is_numeric( $primary['percent_used'] ) ) {
					$pct = max( 0.0, min( 100.0, (float) $primary['percent_used'] * 100.0 ) );
				}
			}
		} elseif ( ! empty( $all_grants ) ) {
			$latest_until = 0;
			foreach ( $all_grants as $g ) {
				$u = isset( $g['until'] ) ? (int) $g['until'] : 0;
				if ( $u > $latest_until ) {
					$latest_until = $u;
				}
			}
			$pill  = 'expired';
			$badge = __( 'VIP منقضی‌شده', 'music-wave-vip' );
			if ( $latest_until > 0 && $latest_until < $now && function_exists( 'wp_date' ) ) {
				$meta = sprintf(
					/* translators: %s: expired date. */
					__( 'پایان در %s', 'music-wave-vip' ),
					wp_date( (string) get_option( 'date_format' ), $latest_until )
				);
			}
		}

		$order_id  = null !== $primary && isset( $primary['order_id'] ) ? (int) $primary['order_id'] : 0;
		$order_url = $order_id > 0 ? UserActivityInspector::order_edit_url( $order_id ) : '';
		$panel_url = UserActivityInspector::user_vip_panel_url( $user_id );
		$def_level = null !== $primary && '' !== (string) $primary['level']
			? (string) $primary['level']
			: (string) array_key_first( $levels );

		ob_start();
		?>
		<div class="mw-vip-cell" data-mw-vip-user-cell="<?php echo (int) $user_id; ?>">
			<div class="mw-vip-pill-row">
				<span class="mw-vip-pill mw-vip-pill--<?php echo esc_attr( $pill ); ?>"><?php echo esc_html( $badge ); ?></span>
				<?php if ( count( $active ) > 1 ) : ?>
					<span class="mw-vip-pill mw-vip-pill--none">
						<?php
						printf(
							/* translators: %d: extra active plans count. */
							esc_html__( '+%d طرح دیگر', 'music-wave-vip' ),
							count( $active ) - 1
						);
						?>
					</span>
				<?php endif; ?>
			</div>

			<?php if ( '' !== $meta ) : ?>
				<span class="mw-vip-cell__meta">
					<?php echo esc_html( $meta ); ?>
					<?php if ( $order_id > 0 && '' !== $order_url ) : ?>
						· <a href="<?php echo esc_url( $order_url ); ?>"><?php printf( '#%d', (int) $order_id ); ?></a>
					<?php endif; ?>
				</span>
			<?php endif; ?>

			<?php if ( null !== $pct ) : ?>
				<div class="mw-vip-cell__bar" aria-hidden="true">
					<span style="width:<?php echo esc_attr( number_format( 100.0 - $pct, 1, '.', '' ) ); ?>%"></span>
				</div>
			<?php endif; ?>

			<?php if ( $can_manage ) : ?>
				<details class="mw-vip-quick">
					<summary><?php echo esc_html__( '⚡ مدیریت سریع VIP', 'music-wave-vip' ); ?></summary>
					<div class="mw-vip-quick__box" data-mw-vip-form-scope data-user-id="<?php echo (int) $user_id; ?>">
						<select name="vip_level" aria-label="<?php esc_attr_e( 'سطح اشتراک', 'music-wave-vip' ); ?>">
							<?php foreach ( $levels as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"<?php selected( $def_level, $slug ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>

						<select name="vip_preset" data-mw-vip-preset aria-label="<?php esc_attr_e( 'مدت اعتبار', 'music-wave-vip' ); ?>">
							<option value="30"><?php echo esc_html__( '۳۰ روز (۱ ماهه)', 'music-wave-vip' ); ?></option>
							<option value="180"><?php echo esc_html__( '۱۸۰ روز (۶ ماهه)', 'music-wave-vip' ); ?></option>
							<option value="365"><?php echo esc_html__( '۳۶۵ روز (سالانه)', 'music-wave-vip' ); ?></option>
							<option value="lifetime"><?php echo esc_html__( 'مادام‌العمر (بدون انقضا)', 'music-wave-vip' ); ?></option>
							<option value="custom_days"><?php echo esc_html__( 'تعداد روز دلخواه...', 'music-wave-vip' ); ?></option>
							<option value="exact_date"><?php echo esc_html__( 'تاریخ انقضای دقیق...', 'music-wave-vip' ); ?></option>
						</select>

						<div data-mw-vip-custom-days hidden>
							<input type="number" name="vip_days" min="1" max="3650" value="45" placeholder="<?php esc_attr_e( 'تعداد روز', 'music-wave-vip' ); ?>">
						</div>

						<div data-mw-vip-custom-date hidden>
							<input type="date" name="vip_exact_date" value="<?php echo esc_attr( gmdate( 'Y-m-d', $now + ( 30 * 86400 ) ) ); ?>">
						</div>

						<select name="vip_apply_mode" aria-label="<?php esc_attr_e( 'نحوه اعمال', 'music-wave-vip' ); ?>">
							<option value="stack"><?php echo esc_html__( 'افزودن به انتهای اعتبار فعلی', 'music-wave-vip' ); ?></option>
							<option value="replace"><?php echo esc_html__( 'جایگزینی از امروز', 'music-wave-vip' ); ?></option>
						</select>

						<div class="mw-vip-quick__actions">
							<button type="button" class="button button-primary button-small" data-mw-vip-ajax-btn="grant">
								<?php echo esc_html__( 'ثبت / تمدید', 'music-wave-vip' ); ?>
							</button>
							<?php if ( null !== $primary ) : ?>
								<button type="button" class="button button-small button-link-delete" data-mw-vip-ajax-btn="revoke_all">
									<?php echo esc_html__( 'لغو VIP', 'music-wave-vip' ); ?>
								</button>
							<?php endif; ?>
							<a class="button-link" href="<?php echo esc_url( $panel_url ); ?>"><?php echo esc_html__( 'جزئیات کامل', 'music-wave-vip' ); ?></a>
						</div>
						<span class="mw-vip-quick__status" data-mw-vip-status role="status" aria-live="polite"></span>
					</div>
				</details>
			<?php endif; ?>
		</div>
		<?php
		return trim( (string) ob_get_clean() );
	}

	/**
	 * Render batched activity + payment links column inside `users.php`.
	 */
	private function render_activity_cell( int $user_id ): string {
		$batch = $this->inspector->inspect_batch( array( $user_id ) );
		$data  = $batch[ $user_id ] ?? array();
		$links = isset( $data['links'] ) && is_array( $data['links'] ) ? $data['links'] : array();

		$orders_count   = isset( $data['orders_count'] ) ? (int) $data['orders_count'] : 0;
		$spent_label    = isset( $data['total_spent_label'] ) ? (string) $data['total_spent_label'] : '';
		$plays_count    = isset( $data['plays_count'] ) ? (int) $data['plays_count'] : 0;
		$library_total  = isset( $data['library_total'] ) ? (int) $data['library_total'] : 0;
		$playlists_cnt  = isset( $data['playlists_total'] ) ? (int) $data['playlists_total'] : 0;
		$requests_cnt   = isset( $data['requests_total'] ) ? (int) $data['requests_total'] : 0;
		$requests_open  = isset( $data['requests_open'] ) ? (int) $data['requests_open'] : 0;
		$last_active_at = isset( $data['last_activity_at'] ) ? (int) $data['last_activity_at'] : 0;

		$orders_text = $orders_count > 0 && '' !== $spent_label
			/* translators: 1: orders count, 2: formatted total spend. */
			? sprintf( __( '%1$s سفارش (%2$s)', 'music-wave-vip' ), number_format_i18n( $orders_count ), $spent_label )
			/* translators: %s: orders count. */
			: sprintf( __( '%s سفارش', 'music-wave-vip' ), number_format_i18n( $orders_count ) );

		ob_start();
		?>
		<div class="mw-vip-activity">
			<?php if ( ! empty( $links['orders'] ) ) : ?>
				<a class="mw-vip-stat-chip" href="<?php echo esc_url( (string) $links['orders'] ); ?>" title="<?php esc_attr_e( 'مشاهده پرداخت‌ها و سفارش‌های ووکامرس این کاربر', 'music-wave-vip' ); ?>">
					<?php echo esc_html( '💳 ' . $orders_text ); ?>
				</a>
			<?php else : ?>
				<span class="mw-vip-stat-chip"><?php echo esc_html( '💳 ' . $orders_text ); ?></span>
			<?php endif; ?>

			<span class="mw-vip-stat-chip" title="<?php esc_attr_e( 'پخش کامل آهنگ‌ها و آیتم‌های ذخیره‌شده در کتابخانه', 'music-wave-vip' ); ?>">
				<?php
				printf(
					/* translators: 1: play count, 2: saved library items count. */
					esc_html__( '🎧 %1$s پخش · %2$s ذخیره', 'music-wave-vip' ),
					esc_html( number_format_i18n( $plays_count ) ),
					esc_html( number_format_i18n( $library_total ) )
				);
				?>
			</span>

			<?php if ( $playlists_cnt > 0 ) : ?>
				<a class="mw-vip-stat-chip" href="<?php echo esc_url( (string) ( $links['profile'] ?? '' ) ); ?>">
					<?php
					printf(
						/* translators: %s: user playlists count. */
						esc_html__( '🎶 %s فهرست پخش', 'music-wave-vip' ),
						esc_html( number_format_i18n( $playlists_cnt ) )
					);
					?>
				</a>
			<?php endif; ?>

			<?php if ( ! empty( $links['requests'] ) && $requests_cnt > 0 ) : ?>
				<a class="mw-vip-stat-chip" href="<?php echo esc_url( (string) $links['requests'] ); ?>">
					<?php
					echo esc_html(
						$requests_open > 0
							/* translators: 1: total requests, 2: open pending requests. */
							? sprintf( __( '📩 %1$s درخواست (%2$s باز)', 'music-wave-vip' ), number_format_i18n( $requests_cnt ), number_format_i18n( $requests_open ) )
							/* translators: %s: total requests. */
							: sprintf( __( '📩 %s درخواست', 'music-wave-vip' ), number_format_i18n( $requests_cnt ) )
					);
					?>
				</a>
			<?php endif; ?>

			<?php if ( $last_active_at > 0 && function_exists( 'human_time_diff' ) ) : ?>
				<span class="mw-vip-activity__last">
					<?php
					printf(
						/* translators: %s: relative human time difference. */
						esc_html__( 'آخرین فعالیت: %s پیش', 'music-wave-vip' ),
						esc_html( human_time_diff( $last_active_at, time() ) )
					);
					?>
				</span>
			<?php endif; ?>
		</div>
		<?php
		return trim( (string) ob_get_clean() );
	}

	/**
	 * Apply status filter, level filter, and VIP expiry sorting to `WP_User_Query` on `users.php`.
	 *
	 * @param \WP_User_Query $query Current user query.
	 */
	public function filter_and_sort_users_query( $query ): void {
		if ( ! is_admin() || ! is_object( $query ) || ! method_exists( $query, 'get' ) || ! method_exists( $query, 'set' ) ) {
			return;
		}

		global $pagenow;
		if ( 'users.php' !== ( $pagenow ?? '' ) ) {
			return;
		}

		$now           = time();
		$status_filter = $this->requested_status_filter();
		$level_filter  = $this->requested_level_filter();
		$meta_query    = $query->get( 'meta_query' );
		$meta_query    = is_array( $meta_query ) ? $meta_query : array();

		if ( 'active' === $status_filter ) {
			$meta_query[] = array(
				'key'     => VipPlans::STATUS_UNTIL_META,
				'value'   => $now,
				'compare' => '>',
				'type'    => 'NUMERIC',
			);
		} elseif ( 'expiring' === $status_filter ) {
			$meta_query[] = array(
				'key'     => VipPlans::STATUS_UNTIL_META,
				'value'   => array( $now + 1, $now + self::EXPIRING_WINDOW ),
				'compare' => 'BETWEEN',
				'type'    => 'NUMERIC',
			);
		} elseif ( 'expired' === $status_filter ) {
			$meta_query[] = array(
				'relation' => 'AND',
				array(
					'key'     => VipPlans::EVER_GRANTED_META,
					'value'   => '1',
					'compare' => '=',
				),
				array(
					'key'     => VipPlans::STATUS_UNTIL_META,
					'value'   => $now,
					'compare' => '<=',
					'type'    => 'NUMERIC',
				),
			);
		} elseif ( 'none' === $status_filter ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => VipPlans::EVER_GRANTED_META,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => VipPlans::EVER_GRANTED_META,
					'value'   => '0',
					'compare' => '=',
				),
			);
		}

		if ( '' !== $level_filter ) {
			$meta_query[] = array(
				'key'     => VipPlans::GRANTS_META_KEY,
				'value'   => '"' . $level_filter . '"',
				'compare' => 'LIKE',
			);
		}

		if ( ! empty( $meta_query ) ) {
			$query->set( 'meta_query', $meta_query );
		}

		if ( self::COL_STATUS === (string) $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', VipPlans::STATUS_UNTIL_META );
			$query->set( 'orderby', 'meta_value_num' );
		}
	}

	/**
	 * Quick links in each user row hover bar on `users.php`.
	 *
	 * @param array<string, string> $actions Existing row actions.
	 * @param \WP_User|object       $user    Row user object.
	 * @return array<string, string>
	 */
	public function register_row_actions( array $actions, $user ): array {
		if ( ! is_object( $user ) || ! property_exists( $user, 'ID' ) || ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}

		$uid                      = (int) $user->ID;
		$actions['mw_vip_manage'] = '<a href="' . esc_url( UserActivityInspector::user_vip_panel_url( $uid ) ) . '">' . esc_html__( 'مدیریت VIP و فعالیت', 'music-wave-vip' ) . '</a>';
		$orders_url               = UserActivityInspector::customer_orders_url( $uid );
		if ( '' !== $orders_url ) {
			$actions['mw_wc_orders'] = '<a href="' . esc_url( $orders_url ) . '">' . esc_html__( 'پرداخت‌های ووکامرس', 'music-wave-vip' ) . '</a>';
		}

		return $actions;
	}

	/**
	 * Bulk actions dropdown entries on `users.php`.
	 *
	 * @param array<string, string> $actions Existing bulk actions.
	 * @return array<string, string>
	 */
	public function register_bulk_actions( array $actions ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}

		$actions['mw_vip_grant_30']   = __( 'VIP: اعطای اشتراک ۱ ماهه (۳۰ روز)', 'music-wave-vip' );
		$actions['mw_vip_grant_180']  = __( 'VIP: اعطای اشتراک ۶ ماهه (۱۸۰ روز)', 'music-wave-vip' );
		$actions['mw_vip_grant_365']  = __( 'VIP: اعطای اشتراک سالانه (۳۶۵ روز)', 'music-wave-vip' );
		$actions['mw_vip_extend_30']  = __( 'VIP: تمدید اشتراک فعال (+۳۰ روز)', 'music-wave-vip' );
		$actions['mw_vip_revoke_all'] = __( 'VIP: لغو همه اشتراک‌های فعال', 'music-wave-vip' );

		return $actions;
	}

	/**
	 * Execute bulk VIP actions selected on `users.php`.
	 *
	 * @param string            $redirect_to Target redirect URL.
	 * @param string            $action      Selected bulk action key.
	 * @param array<int, mixed> $user_ids    Selected user IDs.
	 */
	public function handle_bulk_actions( string $redirect_to, string $action, array $user_ids ): string {
		$supported = array(
			'mw_vip_grant_30',
			'mw_vip_grant_180',
			'mw_vip_grant_365',
			'mw_vip_extend_30',
			'mw_vip_revoke_all',
		);
		if ( ! in_array( $action, $supported, true ) || ! current_user_can( 'manage_options' ) ) {
			return $redirect_to;
		}

		$actor_id = (int) get_current_user_id();
		$affected = 0;

		foreach ( $user_ids as $raw_id ) {
			$uid = (int) $raw_id;
			if ( $uid < 1 ) {
				continue;
			}

			switch ( $action ) {
				case 'mw_vip_grant_30':
					if ( $this->plans->grant_manual( $uid, 'vip-1m', 30, $actor_id, __( 'اعطای گروهی در users.php', 'music-wave-vip' ) ) ) {
						++$affected;
					}
					break;
				case 'mw_vip_grant_180':
					if ( $this->plans->grant_manual( $uid, 'vip-6m', 180, $actor_id, __( 'اعطای گروهی در users.php', 'music-wave-vip' ) ) ) {
						++$affected;
					}
					break;
				case 'mw_vip_grant_365':
					if ( $this->plans->grant_manual( $uid, 'vip-12m', 365, $actor_id, __( 'اعطای گروهی در users.php', 'music-wave-vip' ) ) ) {
						++$affected;
					}
					break;
				case 'mw_vip_extend_30':
					if ( $this->plans->extend_active_for_user( $uid, 30, $actor_id, __( 'تمدید گروهی +۳۰ روز در users.php', 'music-wave-vip' ) ) ) {
						++$affected;
					}
					break;
				case 'mw_vip_revoke_all':
					$this->plans->revoke_all_for_user( $uid, $actor_id, __( 'لغو گروهی در users.php', 'music-wave-vip' ) );
					++$affected;
					break;
			}
		}

		return (string) add_query_arg(
			array(
				self::BULK_NOTICE_ARG => $action,
				self::BULK_COUNT_ARG  => (string) $affected,
			),
			$redirect_to
		);
	}

	public function render_bulk_notice(): void {
		if ( ! isset( $_GET[ self::BULK_NOTICE_ARG ] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$action = sanitize_key( wp_unslash( (string) $_GET[ self::BULK_NOTICE_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$count  = isset( $_GET[ self::BULK_COUNT_ARG ] ) ? max( 0, absint( wp_unslash( $_GET[ self::BULK_COUNT_ARG ] ) ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$message = sprintf(
			/* translators: 1: affected users count, 2: bulk action slug. */
			__( 'عملیات گروهی اشتراک VIP برای %1$s کاربر با موفقیت اجرا شد (%2$s).', 'music-wave-vip' ),
			number_format_i18n( $count ),
			$action
		);

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Inline AJAX handler for quick VIP grant/extend/revoke directly inside `users.php`.
	 */
	public function handle_ajax_quick_manage(): void {
		check_ajax_referer( self::NONCE_QUICK_ACT );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی مجاز نیست.', 'music-wave-vip' ) ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$op      = isset( $_POST['vip_op'] ) ? sanitize_key( wp_unslash( (string) $_POST['vip_op'] ) ) : 'grant';
		if ( $user_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'کاربر نامعتبر است.', 'music-wave-vip' ) ), 400 );
		}

		$actor_id = (int) get_current_user_id();

		if ( 'revoke_all' === $op ) {
			$this->plans->revoke_all_for_user( $user_id, $actor_id, __( 'لغو سریع از users.php', 'music-wave-vip' ) );
			wp_send_json_success( array( 'cell_html' => $this->render_status_cell( $user_id ) ) );
		}

		$ok = self::apply_submitted_grant( $this->plans, $user_id, $actor_id, $_POST, __( 'ویرایش سریع در users.php', 'music-wave-vip' ) );
		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'پارامترهای تاریخ یا سطح اشتراک معتبر نیستند.', 'music-wave-vip' ) ), 400 );
		}

		wp_send_json_success( array( 'cell_html' => $this->render_status_cell( $user_id ) ) );
	}

	/**
	 * Shared DRY grant processor reused by both `UsersListIntegration` AJAX quick-edit
	 * and `UserProfilePanel` form submissions!
	 *
	 * @param array<string, mixed> $input Raw `$_POST` dictionary.
	 */
	public static function apply_submitted_grant( VipPlans $plans, int $user_id, int $actor_id, array $input, string $default_note = '' ): bool {
		$unslash   = static function ( string $val ): string {
			return function_exists( 'wp_unslash' ) ? (string) wp_unslash( $val ) : $val;
		};
		$raw_level = isset( $input['vip_level'] ) ? sanitize_key( $unslash( (string) $input['vip_level'] ) ) : '';
		$custom_lv = isset( $input['vip_custom_level'] ) ? sanitize_key( $unslash( (string) $input['vip_custom_level'] ) ) : '';
		$level     = '' !== $custom_lv ? $custom_lv : ( '' !== $raw_level ? $raw_level : 'vip-1m' );
		$preset    = isset( $input['vip_preset'] ) ? sanitize_key( $unslash( (string) $input['vip_preset'] ) ) : '30';
		$mode      = isset( $input['vip_apply_mode'] ) ? sanitize_key( $unslash( (string) $input['vip_apply_mode'] ) ) : 'stack';
		$note      = isset( $input['vip_note'] ) ? sanitize_text_field( $unslash( (string) $input['vip_note'] ) ) : '';
		$note      = '' !== $note ? $note : $default_note;
		$stack     = ( 'replace' !== $mode );

		if ( 'lifetime' === $preset ) {
			return $plans->grant_manual( $user_id, $level, 0, $actor_id, $note, 0, false );
		}

		if ( 'exact_date' === $preset ) {
			$raw_date = isset( $input['vip_exact_date'] ) ? sanitize_text_field( $unslash( (string) $input['vip_exact_date'] ) ) : '';
			$ts       = '' !== $raw_date ? strtotime( $raw_date . ' 23:59:59 UTC' ) : false;
			if ( false === $ts || $ts <= time() ) {
				return false;
			}
			return $plans->grant_manual( $user_id, $level, 1, $actor_id, $note, (int) $ts, false );
		}

		$days = 30;
		if ( 'custom_days' === $preset ) {
			$days = isset( $input['vip_days'] ) ? max( 1, min( 3650, absint( $unslash( (string) $input['vip_days'] ) ) ) ) : 30;
		} elseif ( is_numeric( $preset ) ) {
			$days = max( 1, min( 3650, (int) $preset ) );
		}

		return $plans->grant_manual( $user_id, $level, $days, $actor_id, $note, 0, $stack );
	}

	/**
	 * Return canonical `level_slug => readable title` options across VIP products & defaults.
	 *
	 * @return array<string, string>
	 */
	public function known_plan_choices(): array {
		$choices = array();
		$data    = PlanProducts::products_data( 0 );
		$items   = isset( $data['plans'] ) && is_array( $data['plans'] ) ? $data['plans'] : array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$lv    = isset( $item['level'] ) ? sanitize_key( (string) $item['level'] ) : '';
			$title = isset( $item['title'] ) ? trim( (string) $item['title'] ) : '';
			if ( '' !== $lv ) {
				$choices[ $lv ] = '' !== $title ? sprintf( '%s (%s)', $title, $lv ) : strtoupper( $lv );
			}
		}

		$defaults = array(
			'vip-1m'  => __( 'عضویت VIP ۱ ماهه (vip-1m)', 'music-wave-vip' ),
			'vip-6m'  => __( 'عضویت VIP ۶ ماهه (vip-6m)', 'music-wave-vip' ),
			'vip-12m' => __( 'عضویت VIP ۱۲ ماهه (vip-12m)', 'music-wave-vip' ),
		);
		foreach ( $defaults as $slug => $label ) {
			if ( ! isset( $choices[ $slug ] ) ) {
				$choices[ $slug ] = $label;
			}
		}

		return $choices;
	}

	private function prime_current_table_users( int $fallback_user_id ): void {
		if ( $this->primed_table ) {
			return;
		}
		$this->primed_table = true;

		$ids = array( $fallback_user_id );
		if ( isset( $GLOBALS['wp_list_table'] ) && is_object( $GLOBALS['wp_list_table'] ) && isset( $GLOBALS['wp_list_table']->items ) && is_array( $GLOBALS['wp_list_table']->items ) ) {
			foreach ( $GLOBALS['wp_list_table']->items as $item ) {
				if ( is_object( $item ) && isset( $item->ID ) ) {
					$ids[] = (int) $item->ID;
				} elseif ( is_numeric( $item ) ) {
					$ids[] = (int) $item;
				}
			}
		}

		$this->inspector->inspect_batch( $ids );
	}

	/**
	 * Fast SQL counts for the 4 VIP status views at the top of `users.php`.
	 *
	 * @return array{active: int, expiring: int, expired: int, none: int}
	 */
	private function count_status_buckets(): array {
		$buckets = array(
			'active'   => 0,
			'expiring' => 0,
			'expired'  => 0,
			'none'     => 0,
		);

		global $wpdb;
		if ( ! isset( $wpdb ) || ! ( $wpdb instanceof \wpdb ) ) {
			return $buckets;
		}

		$now  = time();
		$soon = $now + self::EXPIRING_WINDOW;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight single-pass aggregate for admin sub-views.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(u.ID) AS total_users,
					SUM(CASE WHEN CAST(COALESCE(mu.meta_value, '0') AS UNSIGNED) > %d THEN 1 ELSE 0 END) AS active_cnt,
					SUM(CASE WHEN CAST(COALESCE(mu.meta_value, '0') AS UNSIGNED) BETWEEN %d AND %d THEN 1 ELSE 0 END) AS expiring_cnt,
					SUM(CASE WHEN me.meta_value = '1' AND CAST(COALESCE(mu.meta_value, '0') AS UNSIGNED) <= %d THEN 1 ELSE 0 END) AS expired_cnt
				FROM {$wpdb->users} u
				LEFT JOIN {$wpdb->usermeta} mu ON (mu.user_id = u.ID AND mu.meta_key = %s)
				LEFT JOIN {$wpdb->usermeta} me ON (me.user_id = u.ID AND me.meta_key = %s)",
				$now,
				$now + 1,
				$soon,
				$now,
				VipPlans::STATUS_UNTIL_META,
				VipPlans::EVER_GRANTED_META
			),
			ARRAY_A
		);

		if ( is_array( $row ) ) {
			$total               = max( 0, (int) ( $row['total_users'] ?? 0 ) );
			$buckets['active']   = max( 0, (int) ( $row['active_cnt'] ?? 0 ) );
			$buckets['expiring'] = max( 0, (int) ( $row['expiring_cnt'] ?? 0 ) );
			$buckets['expired']  = max( 0, (int) ( $row['expired_cnt'] ?? 0 ) );
			$buckets['none']     = max( 0, $total - $buckets['active'] - $buckets['expired'] );
		}

		return $buckets;
	}

	private function requested_status_filter(): string {
		if ( ! isset( $_GET[ self::FILTER_STATUS ] ) || ! is_scalar( $_GET[ self::FILTER_STATUS ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}
		return sanitize_key( wp_unslash( (string) $_GET[ self::FILTER_STATUS ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	private function requested_level_filter(): string {
		if ( ! isset( $_GET[ self::FILTER_LEVEL ] ) || ! is_scalar( $_GET[ self::FILTER_LEVEL ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}
		return sanitize_key( wp_unslash( (string) $_GET[ self::FILTER_LEVEL ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
}
