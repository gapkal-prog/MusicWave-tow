<?php
/**
 * WordPress `user-edit.php` & `profile.php` VIP Management & Activity Panel.
 *
 * Renders inside WordPress's native `#your-profile` `<form>` (zero nested forms!)
 * so administrators can inspect and edit every grant's level/expiry/lifetime state,
 * issue manual grants or extensions, save internal VIP notes, inspect listening /
 * library / playlist / request activity, and jump straight to WooCommerce orders.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Users;

use ManaCore\MusicWave\Vip\VipPlans;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UserProfilePanel {
	public const NONCE_ACTION = 'mw_vip_save_user_profile';
	public const NONCE_NAME   = 'mw_vip_profile_nonce';

	/** @var VipPlans */
	private $plans;

	/** @var UsersListIntegration */
	private $users_list;

	/** @var UserActivityInspector */
	private $inspector;

	public function __construct( VipPlans $plans, UsersListIntegration $users_list, UserActivityInspector $inspector ) {
		$this->plans      = $plans;
		$this->users_list = $users_list;
		$this->inspector  = $inspector;
	}

	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render_panel' ), 20 );
		add_action( 'edit_user_profile', array( $this, 'render_panel' ), 20 );
		add_action( 'personal_options_update', array( $this, 'handle_save' ), 20 );
		add_action( 'edit_user_profile_update', array( $this, 'handle_save' ), 20 );
	}

	/**
	 * Render complete VIP control center + activity summary on `user-edit.php` / `profile.php`.
	 *
	 * @param \WP_User|object $user Edited WordPress user.
	 */
	public function render_panel( $user ): void {
		if ( ! is_object( $user ) || ! property_exists( $user, 'ID' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user_id    = (int) $user->ID;
		$now        = time();
		$all_grants = $this->plans->all_grants_for_user( $user_id );
		$activity   = $this->inspector->inspect_user( $user_id );
		$links      = isset( $activity['links'] ) && is_array( $activity['links'] ) ? $activity['links'] : array();
		$levels     = $this->users_list->known_plan_choices();
		$admin_note = (string) get_user_meta( $user_id, VipPlans::ADMIN_NOTE_META, true );
		$history    = $this->plans->user_history( $user_id );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<section id="mw-vip-user-panel" class="mw-vip-profile-panel">
			<div class="mw-vip-profile-panel__header">
				<div>
					<h2><?php echo esc_html__( 'مدیریت اشتراک ویژه (MusicWave VIP) و کارنامه فعالیت کاربر', 'music-wave-vip' ); ?></h2>
				</div>
				<div class="mw-vip-pill-row">
					<a class="button" href="<?php echo esc_url( admin_url( 'users.php?mw_vip_filter=active' ) ); ?>">
						<?php echo esc_html__( 'همه اعضای VIP فعال', 'music-wave-vip' ); ?>
					</a>
					<?php if ( ! empty( $links['orders'] ) ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( (string) $links['orders'] ); ?>">
							<?php echo esc_html__( 'مشاهده پرداخت‌ها و سفارش‌ها در ووکامرس', 'music-wave-vip' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( ! empty( $links['vip_admin'] ) ) : ?>
						<a class="button" href="<?php echo esc_url( (string) $links['vip_admin'] ); ?>">
							<?php echo esc_html__( 'تنظیمات و طرح‌های VIP', 'music-wave-vip' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>

			<div class="mw-vip-profile-grid">
				<div class="mw-vip-kpi">
					<span class="mw-vip-kpi__label"><?php echo esc_html__( 'پرداخت‌ها و سفارش‌های ووکامرس', 'music-wave-vip' ); ?></span>
					<span class="mw-vip-kpi__value">
						<?php
						echo esc_html( number_format_i18n( (int) ( $activity['orders_count'] ?? 0 ) ) );
						if ( ! empty( $activity['total_spent_label'] ) ) {
							echo ' <small>(' . esc_html( (string) $activity['total_spent_label'] ) . ')</small>';
						}
						?>
					</span>
					<?php if ( ! empty( $links['orders'] ) ) : ?>
						<a class="mw-vip-kpi__link" href="<?php echo esc_url( (string) $links['orders'] ); ?>"><?php echo esc_html__( 'باز کردن سفارش‌های این کاربر ←', 'music-wave-vip' ); ?></a>
					<?php endif; ?>
				</div>

				<div class="mw-vip-kpi">
					<span class="mw-vip-kpi__label"><?php echo esc_html__( 'پخش کامل آهنگ‌ها / در حال شنیدن', 'music-wave-vip' ); ?></span>
					<span class="mw-vip-kpi__value">
						<?php
						printf(
							'%1$s / %2$s',
							esc_html( number_format_i18n( (int) ( $activity['plays_count'] ?? 0 ) ) ),
							esc_html( number_format_i18n( (int) ( $activity['in_progress_count'] ?? 0 ) ) )
						);
						?>
					</span>
				</div>

				<div class="mw-vip-kpi">
					<span class="mw-vip-kpi__label"><?php echo esc_html__( 'کتابخانه موسیقی و فهرست‌های پخش', 'music-wave-vip' ); ?></span>
					<span class="mw-vip-kpi__value">
						<?php
						printf(
							/* translators: 1: library items count, 2: playlists count. */
							esc_html__( '%1$s ذخیره · %2$s پلی‌لیست', 'music-wave-vip' ),
							esc_html( number_format_i18n( (int) ( $activity['library_total'] ?? 0 ) ) ),
							esc_html( number_format_i18n( (int) ( $activity['playlists_total'] ?? 0 ) ) )
						);
						?>
					</span>
				</div>

				<div class="mw-vip-kpi">
					<span class="mw-vip-kpi__label"><?php echo esc_html__( 'درخواست‌های موسیقی ثبت‌شده', 'music-wave-vip' ); ?></span>
					<span class="mw-vip-kpi__value">
						<?php echo esc_html( number_format_i18n( (int) ( $activity['requests_total'] ?? 0 ) ) ); ?>
					</span>
					<?php if ( ! empty( $links['requests'] ) ) : ?>
						<a class="mw-vip-kpi__link" href="<?php echo esc_url( (string) $links['requests'] ); ?>"><?php echo esc_html__( 'بررسی درخواست‌های کاربر ←', 'music-wave-vip' ); ?></a>
					<?php endif; ?>
				</div>
			</div>

			<h3><?php echo esc_html__( 'دسترسی‌ها و دوره‌های VIP ثبت‌شده برای کاربر', 'music-wave-vip' ); ?></h3>
			<?php if ( empty( $all_grants ) ) : ?>
				<p class="description"><?php echo esc_html__( 'هیچ دوره اشتراک فعالی یا قبلی برای این کاربر ثبت نشده است.', 'music-wave-vip' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="margin:8px 0 16px;">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'سطح / طرح', 'music-wave-vip' ); ?></th>
							<th><?php echo esc_html__( 'منبع / سفارش', 'music-wave-vip' ); ?></th>
							<th><?php echo esc_html__( 'شروع دوره', 'music-wave-vip' ); ?></th>
							<th><?php echo esc_html__( 'وضعیت فعلی', 'music-wave-vip' ); ?></th>
							<th><?php echo esc_html__( 'ویرایش تاریخ پایان (UTC)', 'music-wave-vip' ); ?></th>
							<th><?php echo esc_html__( 'مادام‌العمر', 'music-wave-vip' ); ?></th>
							<th><?php echo esc_html__( 'حذف دسترسی', 'music-wave-vip' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $all_grants as $entry_key => $entry ) : ?>
							<?php
							$lv       = isset( $entry['level'] ) ? (string) $entry['level'] : '';
							$oid      = isset( $entry['order_id'] ) ? (int) $entry['order_id'] : 0;
							$src      = isset( $entry['source'] ) ? (string) $entry['source'] : ( $oid > 0 ? 'order' : 'admin' );
							$until    = isset( $entry['until'] ) ? (int) $entry['until'] : 0;
							$start    = isset( $entry['granted_at'] ) ? (int) $entry['granted_at'] : 0;
							$note     = isset( $entry['note'] ) ? (string) $entry['note'] : '';
							$active   = ( 0 === $until ) || ( $until > $now );
							$date_val = $until > 0 ? gmdate( 'Y-m-d', $until ) : '';
							$ord_url  = $oid > 0 ? UserActivityInspector::order_edit_url( $oid ) : '';
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $levels[ $lv ] ?? strtoupper( $lv ) ); ?></strong>
									<?php if ( '' !== $note ) : ?>
										<br><small style="color:#646970;"><?php echo esc_html( $note ); ?></small>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $oid > 0 && '' !== $ord_url ) : ?>
										<a href="<?php echo esc_url( $ord_url ); ?>">
											<?php
											printf(
												/* translators: %d: WooCommerce order number. */
												esc_html__( 'سفارش ووکامرس #%d', 'music-wave-vip' ),
												(int) $oid
											);
											?>
										</a>
									<?php else : ?>
										<span><?php echo esc_html( 'admin' === $src ? __( 'دستی توسط مدیر', 'music-wave-vip' ) : $src ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo $start > 0 ? esc_html( function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d H:i', $start ) : gmdate( 'Y-m-d H:i', $start ) ) : '—'; ?></td>
								<td>
									<?php if ( 0 === $until ) : ?>
										<span class="mw-vip-pill mw-vip-pill--lifetime"><?php echo esc_html__( 'مادام‌العمر', 'music-wave-vip' ); ?></span>
									<?php elseif ( $active ) : ?>
										<span class="mw-vip-pill mw-vip-pill--active">
											<?php
											printf(
												/* translators: %d: remaining days. */
												esc_html__( 'فعال (%d روز مانده)', 'music-wave-vip' ),
												(int) max( 1, (int) ceil( ( $until - $now ) / 86400 ) )
											);
											?>
										</span>
									<?php else : ?>
										<span class="mw-vip-pill mw-vip-pill--expired"><?php echo esc_html__( 'منقضی‌شده', 'music-wave-vip' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<input type="hidden" name="mw_vip_existing_keys[]" value="<?php echo esc_attr( (string) $entry_key ); ?>">
									<input type="date" name="mw_vip_entry_until[<?php echo esc_attr( (string) $entry_key ); ?>]" value="<?php echo esc_attr( $date_val ); ?>">
								</td>
								<td>
									<label>
										<input type="checkbox" name="mw_vip_entry_lifetime[<?php echo esc_attr( (string) $entry_key ); ?>]" value="1"<?php checked( 0 === $until ); ?>>
										<?php echo esc_html__( 'دائمی', 'music-wave-vip' ); ?>
									</label>
								</td>
								<td>
									<label style="color:#b91c1c;">
										<input type="checkbox" name="mw_vip_entry_revoke[<?php echo esc_attr( (string) $entry_key ); ?>]" value="1">
										<?php echo esc_html__( 'لغو', 'music-wave-vip' ); ?>
									</label>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<div class="mw-vip-grant-box" data-mw-vip-form-scope>
				<h4 style="margin:0 0 10px;"><?php echo esc_html__( 'اعطای اشتراک جدید یا تمدید مدت VIP کاربر', 'music-wave-vip' ); ?></h4>
				<div class="mw-vip-grant-box__row">
					<div class="mw-vip-grant-box__field">
						<label for="mw-vip-grant-enable">
							<input type="checkbox" id="mw-vip-grant-enable" name="mw_vip_do_grant" value="1">
							<?php echo esc_html__( 'اعمال اشتراک / تمدید جدید هنگام ذخیره پروفایل', 'music-wave-vip' ); ?>
						</label>
					</div>

					<div class="mw-vip-grant-box__field">
						<label for="mw-vip-profile-level"><?php echo esc_html__( 'طرح / سطح اشتراک', 'music-wave-vip' ); ?></label>
						<select id="mw-vip-profile-level" name="vip_level">
							<?php foreach ( $levels as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="mw-vip-grant-box__field">
						<label for="mw-vip-profile-preset"><?php echo esc_html__( 'مدت اعتبار', 'music-wave-vip' ); ?></label>
						<select id="mw-vip-profile-preset" name="vip_preset" data-mw-vip-preset>
							<option value="30"><?php echo esc_html__( '۳۰ روز (۱ ماهه)', 'music-wave-vip' ); ?></option>
							<option value="180"><?php echo esc_html__( '۱۸۰ روز (۶ ماهه)', 'music-wave-vip' ); ?></option>
							<option value="365"><?php echo esc_html__( '۳۶۵ روز (سالانه)', 'music-wave-vip' ); ?></option>
							<option value="lifetime"><?php echo esc_html__( 'مادام‌العمر (بدون انقضا)', 'music-wave-vip' ); ?></option>
							<option value="custom_days"><?php echo esc_html__( 'تعداد روز دلخواه', 'music-wave-vip' ); ?></option>
							<option value="exact_date"><?php echo esc_html__( 'تاریخ انقضای دقیق', 'music-wave-vip' ); ?></option>
						</select>
					</div>

					<div class="mw-vip-grant-box__field" data-mw-vip-custom-days hidden>
						<label for="mw-vip-profile-days"><?php echo esc_html__( 'تعداد روز', 'music-wave-vip' ); ?></label>
						<input id="mw-vip-profile-days" type="number" name="vip_days" min="1" max="3650" value="45">
					</div>

					<div class="mw-vip-grant-box__field" data-mw-vip-custom-date hidden>
						<label for="mw-vip-profile-date"><?php echo esc_html__( 'تاریخ پایان', 'music-wave-vip' ); ?></label>
						<input id="mw-vip-profile-date" type="date" name="vip_exact_date" value="<?php echo esc_attr( gmdate( 'Y-m-d', $now + ( 30 * 86400 ) ) ); ?>">
					</div>

					<div class="mw-vip-grant-box__field">
						<label for="mw-vip-profile-mode"><?php echo esc_html__( 'نحوه محاسبه', 'music-wave-vip' ); ?></label>
						<select id="mw-vip-profile-mode" name="vip_apply_mode">
							<option value="stack"><?php echo esc_html__( 'انباشت روی انتهای اعتبار فعلی', 'music-wave-vip' ); ?></option>
							<option value="replace"><?php echo esc_html__( 'محاسبه از همین لحظه', 'music-wave-vip' ); ?></option>
						</select>
					</div>

					<div class="mw-vip-grant-box__field" style="flex:1 1 200px;">
						<label for="mw-vip-profile-note"><?php echo esc_html__( 'توضیح ثبت در گزارش', 'music-wave-vip' ); ?></label>
						<input id="mw-vip-profile-note" type="text" name="vip_note" placeholder="<?php esc_attr_e( 'مثلاً هدیه پشتیبانی یا تمدید تلفنی', 'music-wave-vip' ); ?>">
					</div>
				</div>
			</div>

			<p>
				<label for="mw-vip-admin-note" style="display:block;font-weight:600;margin-bottom:4px;">
					<?php echo esc_html__( 'یادداشت داخلی مدیریت برای این مشترک (فقط قابل مشاهده برای مدیر)', 'music-wave-vip' ); ?>
				</label>
				<textarea id="mw-vip-admin-note" name="mw_vip_admin_note" rows="2" class="large-text"><?php echo esc_textarea( $admin_note ); ?></textarea>
			</p>

			<div class="mw-vip-columns-2">
				<div class="mw-vip-card-list">
					<h4><?php echo esc_html__( 'آخرین آهنگ‌های پخش‌شده توسط کاربر', 'music-wave-vip' ); ?></h4>
					<?php if ( empty( $activity['recent_tracks'] ) ) : ?>
						<p class="description"><?php echo esc_html__( 'هنوز سابقه پخشی ثبت نشده است.', 'music-wave-vip' ); ?></p>
					<?php else : ?>
						<ul>
							<?php foreach ( $activity['recent_tracks'] as $track ) : ?>
								<li>
									<a href="<?php echo esc_url( '' !== (string) $track['view_url'] ? (string) $track['view_url'] : (string) $track['edit_url'] ); ?>" target="_blank" rel="noopener noreferrer">
										<?php echo esc_html( (string) $track['title'] ); ?>
									</a>
									<span style="color:#646970;">
										<?php echo 'played' === $track['event'] ? esc_html__( 'پخش کامل', 'music-wave-vip' ) : esc_html__( 'در حال پخش', 'music-wave-vip' ); ?>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>

				<div class="mw-vip-card-list">
					<h4><?php echo esc_html__( 'فهرست‌های پخش ساخته‌شده توسط کاربر', 'music-wave-vip' ); ?></h4>
					<?php if ( empty( $activity['user_playlists'] ) ) : ?>
						<p class="description"><?php echo esc_html__( 'کاربر هنوز فهرست پخشی نساخته است.', 'music-wave-vip' ); ?></p>
					<?php else : ?>
						<ul>
							<?php foreach ( $activity['user_playlists'] as $pl ) : ?>
								<li>
									<strong><?php echo esc_html( (string) $pl['title'] ); ?></strong>
									<span>
										<?php if ( ! empty( $pl['share_url'] ) ) : ?>
											<a href="<?php echo esc_url( (string) $pl['share_url'] ); ?>" target="_blank" rel="noopener noreferrer">
												<?php echo esc_html( (string) $pl['visibility'] . ' ↗' ); ?>
											</a>
										<?php else : ?>
											<?php echo esc_html( (string) $pl['visibility'] ); ?>
										<?php endif; ?>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( ! empty( $history ) ) : ?>
				<div class="mw-vip-card-list" style="margin-top:16px;">
					<h4><?php echo esc_html__( 'تاریخچه تغییرات اشتراک VIP این کاربر', 'music-wave-vip' ); ?></h4>
					<ul>
						<?php foreach ( array_slice( $history, 0, 10 ) as $log ) : ?>
							<li>
								<span>
									<strong><?php echo esc_html( (string) ( $log['event'] ?? '' ) ); ?></strong>
									<?php if ( ! empty( $log['level'] ) ) : ?>
										· <code><?php echo esc_html( (string) $log['level'] ); ?></code>
									<?php endif; ?>
									<?php if ( ! empty( $log['note'] ) ) : ?>
										— <?php echo esc_html( (string) $log['note'] ); ?>
									<?php endif; ?>
								</span>
								<small style="color:#646970;">
									<?php echo ! empty( $log['at'] ) && function_exists( 'wp_date' ) ? esc_html( wp_date( 'Y-m-d H:i', (int) $log['at'] ) ) : ''; ?>
								</small>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Persist profile-level grant edits, revocations, new manual grants, and admin notes.
	 */
	public function handle_save( int $user_id ): void {
		if ( $user_id < 1 || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_NAME ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		$actor_id = (int) get_current_user_id();

		// 1. Save admin internal VIP note.
		if ( isset( $_POST['mw_vip_admin_note'] ) ) {
			$clean_note = sanitize_textarea_field( wp_unslash( (string) $_POST['mw_vip_admin_note'] ) );
			if ( '' === trim( $clean_note ) ) {
				delete_user_meta( $user_id, VipPlans::ADMIN_NOTE_META );
			} else {
				update_user_meta( $user_id, VipPlans::ADMIN_NOTE_META, $clean_note );
			}
		}

		// 2. Process updates / revocations on existing grant rows.
		$existing_current = $this->plans->all_grants_for_user( $user_id );
		$submitted_keys   = isset( $_POST['mw_vip_existing_keys'] ) && is_array( $_POST['mw_vip_existing_keys'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['mw_vip_existing_keys'] ) )
			: array();
		$revoke_map       = isset( $_POST['mw_vip_entry_revoke'] ) && is_array( $_POST['mw_vip_entry_revoke'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['mw_vip_entry_revoke'] ) )
			: array();
		$lifetime_map     = isset( $_POST['mw_vip_entry_lifetime'] ) && is_array( $_POST['mw_vip_entry_lifetime'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['mw_vip_entry_lifetime'] ) )
			: array();
		$until_map        = isset( $_POST['mw_vip_entry_until'] ) && is_array( $_POST['mw_vip_entry_until'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['mw_vip_entry_until'] ) )
			: array();

		foreach ( $submitted_keys as $entry_key ) {
			if ( ! isset( $existing_current[ $entry_key ] ) ) {
				continue;
			}

			if ( ! empty( $revoke_map[ $entry_key ] ) ) {
				$this->plans->revoke_grant_entry( $user_id, $entry_key, $actor_id, __( 'لغو از صفحه ویرایش کاربر', 'music-wave-vip' ) );
				continue;
			}

			$cur_until   = (int) ( $existing_current[ $entry_key ]['until'] ?? 0 );
			$is_lifetime = ! empty( $lifetime_map[ $entry_key ] );

			if ( $is_lifetime && 0 !== $cur_until ) {
				$this->plans->update_grant_entry( $user_id, $entry_key, 0, true, __( 'تغییر به اشتراک مادام‌العمر', 'music-wave-vip' ), $actor_id );
				continue;
			}

			if ( ! $is_lifetime && isset( $until_map[ $entry_key ] ) ) {
				$raw_date = sanitize_text_field( (string) $until_map[ $entry_key ] );
				$cur_date = $cur_until > 0 ? gmdate( 'Y-m-d', $cur_until ) : '';
				if ( '' !== $raw_date && $raw_date !== $cur_date ) {
					$parsed = strtotime( $raw_date . ' 23:59:59 UTC' );
					if ( false !== $parsed && $parsed > 0 ) {
						$this->plans->update_grant_entry( $user_id, $entry_key, (int) $parsed, false, __( 'ویرایش تاریخ انقضا در پروفایل کاربر', 'music-wave-vip' ), $actor_id );
					}
				}
			}
		}

		// 3. Apply new grant / extension if requested.
		if ( ! empty( $_POST['mw_vip_do_grant'] ) ) {
			UsersListIntegration::apply_submitted_grant(
				$this->plans,
				$user_id,
				$actor_id,
				$_POST,
				__( 'اعطای دستی از ویرایش کاربر', 'music-wave-vip' )
			);
		}
	}
}
