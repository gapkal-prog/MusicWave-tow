<?php
/**
 * MusicWave VIP settings — unified 4-tab admin control center with integrated
 * WooCommerce plan management, live Protected Delivery diagnostics, one-click
 * directory provisioning & hardening, private asset vault uploader, HMAC URL
 * preview, and VIP audit log.
 *
 * Refactored for SOLID, DRY and clean code:
 * - SRP: UI helpers in `Admin\VipSettingsUi`, diagnostics in `Delivery\ProtectedDeliveryDiagnostics`,
 *        storage/provisioning in `ProtectedAssetStorage` + `Storage\RootResolver`.
 * - 100% CSP-clean: zero inline scripts (`onclick=`), externalized CSS & JS.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip;

use ManaCore\MusicWave\Vip\Admin\VipSettingsUi;
use ManaCore\MusicWave\Vip\Delivery\LocalDeliveryDiagnostics;
use ManaCore\MusicWave\Vip\Delivery\ProtectedDeliveryDiagnostics;
use ManaCore\MusicWave\Vip\Plans\VipPlanCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SettingsPage {

	public const PAGE_SLUG     = 'music-wave-vip';
	private const OPTION_GROUP = 'music_wave_vip';

	/** @var array<string, string> Collected remove-plan forms output outside main form (FIX-15). */
	private $remove_forms = array();

	/** @var string Registered WordPress hook suffix for the submenu page (locale-dependent). */
	private $page_hook = '';

	/**
	 * Canonical admin URL of the VIP settings screen.
	 */
	public static function page_url( string $tab = '' ): string {
		$url = admin_url( 'edit.php?post_type=mw_release&page=' . self::PAGE_SLUG );
		if ( '' !== $tab ) {
			$url = add_query_arg( array( 'tab' => sanitize_key( $tab ) ), $url );
		}
		return $url;
	}

	/**
	 * Locale-independent screen check for `MusicWave VIP` settings.
	 */
	public static function is_vip_settings_screen( string $hook, string $registered_hook = '' ): bool {
		if ( '' !== $registered_hook && $registered_hook === $hook ) {
			return true;
		}
		if ( 1 === preg_match( '/(?:^|_page_)' . preg_quote( self::PAGE_SLUG, '/' ) . '$/', $hook ) ) {
			return true;
		}
		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return self::PAGE_SLUG === $page;
	}

	public function add_page(): void {
		$hook            = add_submenu_page(
			'edit.php?post_type=mw_release',
			__( 'تنظیمات MusicWave VIP', 'music-wave-vip' ),
			__( 'MusicWave VIP', 'music-wave-vip' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
		$this->page_hook = is_string( $hook ) ? $hook : '';
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function enqueue_admin_assets( string $hook ): void {
		if ( ! self::is_vip_settings_screen( $hook, $this->page_hook ) ) {
			return;
		}
		$base_url = defined( 'MUSIC_WAVE_VIP_URL' ) ? MUSIC_WAVE_VIP_URL : plugin_dir_url( dirname( __DIR__ ) . '/music-wave-vip.php' );
		$ver      = defined( 'MUSIC_WAVE_VIP_VERSION' ) ? MUSIC_WAVE_VIP_VERSION : '0.5.1';

		wp_enqueue_style(
			'music-wave-vip-admin',
			$base_url . 'assets/admin-vip-settings.css',
			array( 'dashicons' ),
			$ver
		);
		wp_enqueue_script(
			'music-wave-vip-admin',
			$base_url . 'assets/admin-vip-settings.js',
			array(),
			$ver,
			true
		);
		wp_localize_script(
			'music-wave-vip-admin',
			'mwVipSettings',
			array(
				'restBase'  => rest_url( 'music-wave/v1/protected-assets' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'tools'     => array(
					'edgeTest'     => rest_url( 'music-wave/v1/protected-assets/edge-test' ),
					'uploadTest'   => rest_url( 'music-wave/v1/vip/remote-upload/test' ),
					'uploadSync'   => rest_url( 'music-wave/v1/vip/remote-upload/sync-now' ),
					'uploadStatus' => rest_url( 'music-wave/v1/vip/remote-upload/status' ),
				),
				'i18n'      => array(
					'provisioning'  => __( 'در حال ایجاد پوشه، ایمنسازی (.htaccess / web.config) و اسکن فایلها...', 'music-wave-vip' ),
					'provisionDone' => __( 'پوشهٔ خصوصی با موفقیت ایمنسازی و فهرست فایلها همگامسازی شد.', 'music-wave-vip' ),
					'uploading'     => __( 'در حال بارگذاری در مخزن خصوصی و محاسبه هش SHA-256...', 'music-wave-vip' ),
					'uploadDone'    => __( 'فایل با موفقیت در مخزن خصوصی ذخیره شد:', 'music-wave-vip' ),
					'uploadFailed'  => __( 'بارگذاری فایل در مخزن خصوصی ناموفق بود.', 'music-wave-vip' ),
					'copied'        => __( 'کپی شد!', 'music-wave-vip' ),
					'copyId'        => __( 'کپی شناسه', 'music-wave-vip' ),
					'deleteAsset'   => __( 'حذف', 'music-wave-vip' ),
					'confirmDelete' => __( 'تأیید حذف قطعی؟', 'music-wave-vip' ),
					'emptyVault'    => __( 'هنوز هیچ فایل حفاظتشدهای در مخزن خصوصی ثبت نشده است.', 'music-wave-vip' ),
					'testing'       => __( 'در حال آزمایش اتصال...', 'music-wave-vip' ),
					'testOk'        => __( 'اتصال سالم است.', 'music-wave-vip' ),
					'testFailed'    => __( 'آزمایش اتصال ناموفق بود:', 'music-wave-vip' ),
					'syncing'       => __( 'در حال صفکردن کتابخانه برای همگامسازی...', 'music-wave-vip' ),
					'syncDone'      => __( 'همگامسازی انجام شد.', 'music-wave-vip' ),
					'statusLoading' => __( 'در حال خواندن وضعیت صف...', 'music-wave-vip' ),
					'appliesNow'    => __( 'در حالت فعلی فعال', 'music-wave-vip' ),
					'appliesNo'     => __( 'در حالت فعلی بیاثر', 'music-wave-vip' ),
				),
			)
		);
	}

	/**
	 * Register the option with the Settings API.
	 *
	 * `render()` draws its own tabbed markup, so no `add_settings_section()`
	 * or `add_settings_field()` calls are needed here — `settings_fields()` plus
	 * this registration are enough for `options.php` to persist the option.
	 */
	public function register(): void {
		register_setting(
			self::OPTION_GROUP,
			VipSettings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize_settings' ),
				'default'           => VipSettings::defaults(),
			)
		);
	}

	/**
	 * Sanitize VIP settings safely across full-form saves, browser unlock toggles,
	 * tab-scoped submissions, and headless programmatic calls.
	 *
	 * @param mixed $value Candidate settings payload.
	 * @return array<string, mixed>
	 */
	public static function sanitize_settings( $value ): array {
		$value             = is_array( $value ) ? $value : array();
		$current           = VipSettings::all();
		$full_form         = ! empty( $value['mwvip_full_form'] );
		$controls_unlocked = ! empty( $value['mwvip_controls_unlocked'] );
		$active_tab        = isset( $value['mwvip_active_tab'] ) ? sanitize_key( (string) $value['mwvip_active_tab'] ) : '';

		// Secret "keep vs clear" semantics live in exactly one place:
		// `VipSettings::sanitize()` owns the `clear_<key>` flags and the
		// keep-if-blank rule, so the two can never drift apart here.
		unset( $value['mwvip_full_form'], $value['mwvip_controls_unlocked'], $value['mwvip_active_tab'] );

		if ( $full_form ) {
			$module_was_enabled      = 'disabled' !== (string) $current['module_enabled'];
			$module_is_enabled       = isset( $value['module_enabled'] ) && 'disabled' !== (string) $value['module_enabled'];
			$value['module_enabled'] = $module_is_enabled ? 'enabled' : 'disabled';

			$membership_submitted = ( '' === $active_tab || 'membership' === $active_tab || 'plans' === $active_tab )
				&& ( ( $module_was_enabled && $module_is_enabled ) || $controls_unlocked || array_key_exists( 'membership_sources', $value ) );
			if ( $membership_submitted ) {
				$value['membership_sources'] = isset( $value['membership_sources'] ) && is_array( $value['membership_sources'] ) ? $value['membership_sources'] : array();
				$value['promote_vip_role']   = isset( $value['promote_vip_role'] ) && 'disabled' !== (string) $value['promote_vip_role'] ? 'enabled' : 'disabled';
			}

			if ( '' === $active_tab || 'advanced' === $active_tab || 'plans' === $active_tab ) {
				$value['delete_data_on_uninstall'] = isset( $value['delete_data_on_uninstall'] ) && 'disabled' !== (string) $value['delete_data_on_uninstall'] ? 'enabled' : 'disabled';
			}
		}

		$settings = VipSettings::sanitize( $value );

		$root = (string) $settings['protected_root'];
		if ( '' !== $root ) {
			$storage     = new ProtectedAssetStorage();
			$provisioned = $storage->provision( $root );
			if ( false === $provisioned ) {
				add_settings_error(
					VipSettings::OPTION,
					'invalid_root',
					__( 'پوشهٔ حفاظت‌شده باید خارج از ریشهٔ عمومی وب (ABSPATH / DOCUMENT_ROOT) بوده و قابل خواندن و نوشتن باشد. مقدار قبلی حفظ شد.', 'music-wave-vip' )
				);
				$settings['protected_root'] = $current['protected_root'];
			} else {
				$settings['protected_root'] = $provisioned;
			}
		}

		if ( 'remote_redirect' === $settings['delivery_provider'] && ( '' === $settings['remote_base_url'] || '' === $settings['remote_signing_secret'] ) ) {
			add_settings_error(
				VipSettings::OPTION,
				'remote_incomplete',
				__( 'حالت تغییرمسیر راه‌دور هم به نشانی پایهٔ HTTPS و هم به کلید مخفی امضا (حداقل ۳۲ کاراکتر) نیاز دارد. تا پیکربندی هر دو، تحویل با رد امن پیش‌فرض باقی می‌ماند.', 'music-wave-vip' ),
				'warning'
			);
		}
		if ( '' !== (string) $settings['remote_signing_secret'] && strlen( (string) $settings['remote_signing_secret'] ) < 32 ) {
			add_settings_error(
				VipSettings::OPTION,
				'weak_secret',
				__( 'کلید مخفی امضای راه‌دور کوتاه‌تر از ۳۲ نویسه است. تا ذخیرهٔ کلید مخفی قوی‌تر، لینک امضاشده ساخته نمی‌شود.', 'music-wave-vip' ),
				'warning'
			);
		}

		return $settings;
	}

	// ------------------------------------------------------------------
	// Sections & Fields
	// ------------------------------------------------------------------

	public function delivery_section(): void {
		echo '<div class="mwvip-section-intro mwvip-section-intro--blue">';
		echo '<p><strong>' . esc_html__( 'فایل‌های حفاظت‌شده کجا نگه داشته می‌شوند و چگونه به کاربر مجاز می‌رسند.', 'music-wave-vip' ) . '</strong></p>';
		echo '<p class="muted">' . esc_html__( 'در هر حالتی لینک دائمی و عمومی وجود ندارد: هنگام پخش یا دانلود، یک نشانی کوتاه‌عمرِ امضاشده ساخته می‌شود و اگر کسی آن را دست‌کاری کند باطل است.', 'music-wave-vip' ) . '</p>';
		echo '<p class="mwvip-highlights">';
		echo '<span class="mwvip-highlight"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span> ' . esc_html__( 'نشانی کوتاه‌عمر امضاشده با HMAC', 'music-wave-vip' ) . '</span>';
		echo '<span class="mwvip-highlight"><span class="dashicons dashicons-lock" aria-hidden="true"></span> ' . esc_html__( 'کلید رمز هرگز روی CDN نمی‌رود', 'music-wave-vip' ) . '</span>';
		echo '<span class="mwvip-highlight"><span class="dashicons dashicons-superhero" aria-hidden="true"></span> ' . esc_html__( 'پیکربندی ناقص، ایمن به تحویل محلی برمی‌گردد', 'music-wave-vip' ) . '</span>';
		echo '</p>';
		echo '</div>';
	}

	public function general_section(): void {
		$enabled = 'enabled' === (string) VipSettings::all()['module_enabled'];
		if ( $enabled ) {
			echo '<div class="mwvip-section-intro mwvip-section-intro--green">';
			echo '<p><strong>' . esc_html__( 'اعمال عضویت VIP روشن است.', 'music-wave-vip' ) . '</strong> ' . esc_html__( 'انتشارهای حالت «فقط عضویت» و «خرید یا عضویت» بر اساس سطح اشتراک یا خرید تکی بررسی می‌شوند و تحویل فایل‌ها با توکن امضاشده انجام می‌گیرد.', 'music-wave-vip' ) . '</p>';
			echo '</div>';
			return;
		}
		echo '<div class="notice notice-warning inline" style="margin:0 0 16px;"><p><span class="dashicons dashicons-unlock" style="color:#d63638;"></span> <strong>' . esc_html__( 'اعمال عضویت VIP خاموش است — حالت پروموشن باز فعال است.', 'music-wave-vip' ) . '</strong></p><p style="margin:6px 0 0;">' . esc_html__( 'در این حالت انتشارهای «فقط عضویت» و «خرید یا عضویت» موقتاً رایگان‌اند، اما انتشارهای «فقط خرید تکی» و «محدود» همچنان بسته می‌مانند و سفارش‌های جدید اشتراک همچنان زمان VIP مشتریان را ثبت می‌کنند.', 'music-wave-vip' ) . '</p></div>';
	}

	public function module_enabled_field(): void {
		$value  = (string) VipSettings::all()['module_enabled'];
		$border = 'enabled' === $value ? '#00a32a' : '#d63638';
		$bg     = 'enabled' === $value ? '#f0f9f1' : '#fcf0f1';
		echo '<label style="display:inline-flex;align-items:flex-start;gap:10px;padding:14px;border:2px solid ' . esc_attr( $border ) . ';border-radius:6px;background:' . esc_attr( $bg ) . ';max-width:760px;box-sizing:border-box;"><input type="checkbox" id="mwvip-module-enabled" name="' . esc_attr( VipSettings::OPTION ) . '[module_enabled]" value="enabled" ' . checked( $value, 'enabled', false ) . ' style="margin-top:4px;"> <span><strong style="font-size:14px;">' . esc_html__( 'فعال‌سازی اعمال عضویت VIP (کلید اصلی)', 'music-wave-vip' ) . '</strong><br><span class="description">' . esc_html__( 'روشن: قفل‌های عضویت، خرید یا عضویت، سطح‌های پلن و زمان انقضا اعمال می‌شوند. خاموش: حالت پروموشن باز برای محتوای عضویت فعال می‌شود، بدون آنکه خریدهای تکی WooCommerce یا ثبت اشتراک سفارش‌های جدید مختل شود.', 'music-wave-vip' ) . '</span></span></label>';
	}

	private function membership_controls_disabled(): bool {
		return 'disabled' === (string) VipSettings::all()['module_enabled'];
	}

	public function delivery_access_field(): void {
		$value = (string) VipSettings::all()['delivery_access'];
		foreach ( array(
			'logged_in' => array(
				__( 'فقط کاربران ثبت‌نام‌شده (الزام به ورود برای دانلود/استریم فایل حفاظت‌شده)', 'music-wave-vip' ),
				__( 'بازدیدکنندگان مهمان حتی روی انتشارهای عمومی/پروموشن برای پخش یا دانلود فایل حفاظت‌شده باید وارد حساب کاربری شوند.', 'music-wave-vip' ),
			),
			'everyone'  => array(
				__( 'بر اساس حالت دسترسی هر انتشار (شامل مهمانان در آثار عمومی)', 'music-wave-vip' ),
				__( 'روی آثار عمومی ثبت‌نام اختیاری است، اما آثار VIP و خریدنی همچنان صددرصد نیازمند اشتراک معتبر یا خرید محصول‌اند (این گزینه هرگز آثار پولی را رایگان نمی‌کند).', 'music-wave-vip' ),
			),
		) as $key => $info ) {
			echo '<label style="display:flex;align-items:flex-start;gap:8px;margin:0.6em 0;padding:10px;border:1px solid ' . esc_attr( $key === $value ? '#2271b1' : '#ccd0d4' ) . ';border-radius:4px;background:' . esc_attr( $key === $value ? '#f0f6fc' : '#fff' ) . ';max-width:760px;"><input type="radio" name="' . esc_attr( VipSettings::OPTION ) . '[delivery_access]" value="' . esc_attr( $key ) . '" ' . checked( $value, $key, false ) . ' style="margin-top:3px;"> <span><strong>' . esc_html( $info[0] ) . '</strong><br><span class="description" style="font-size:12px;">' . esc_html( $info[1] ) . '</span></span></label>';
		}
	}

	private function render_plans_manager(): void {
		$config = VipSettings::all();
		$plans  = isset( $config['vip_plans'] ) && is_array( $config['vip_plans'] ) ? $config['vip_plans'] : array();
		$woo    = post_type_exists( 'product' );

		echo '<div class="mwvip-plans-box">';
		echo '<div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;">';
		echo '<h3 style="margin:0;">' . esc_html__( 'مدیریت طرح‌های اشتراک VIP', 'music-wave-vip' ) . '</h3>';
		echo '<div style="display:flex;flex-wrap:wrap;gap:6px;">';
		echo '<a href="' . esc_url( admin_url( 'users.php?mw_vip_filter=active' ) ) . '" class="button"><span class="dashicons dashicons-groups" style="vertical-align:middle;font-size:16px;"></span> ' . esc_html__( 'اعضای VIP فعال', 'music-wave-vip' ) . '</a>';
		echo '<a href="' . esc_url( admin_url( 'users.php?mw_vip_filter=expired' ) ) . '" class="button">' . esc_html__( 'منقضی‌شده‌ها', 'music-wave-vip' ) . '</a>';
		if ( $woo ) {
			echo '<a href="' . esc_url( VipSettingsUi::wc_products_url() ) . '" class="button">' . esc_html__( 'محصولات ووکامرس', 'music-wave-vip' ) . '</a>';
		}
		echo '</div></div>';
		if ( $woo ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . PlanProducts::ACTION_CREATE_DEFAULTS ), PlanProducts::ACTION_CREATE_DEFAULTS );
			echo '<p><a href="' . esc_url( $url ) . '" class="button button-primary"><span class="dashicons dashicons-superhero" style="vertical-align:middle;font-size:16px;"></span> ' . esc_html__( 'ایجاد طرح‌های پیش‌فرض (۱، ۶ و ۱۲ ماهه)', 'music-wave-vip' ) . '</a> <span class="description" style="margin-left:8px;">' . esc_html__( 'سه محصول مجازی ووکامرس همراه با آیکن اختصاصی، توضیحات کامل قابلیت‌ها و چک‌لیست امکانات ایجاد و به‌عنوان طرح VIP نگاشت می‌کند.', 'music-wave-vip' ) . '</span></p>';
		} else {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'برای ایجاد و فروش محصولات طرح، ووکامرس را فعال کنید.', 'music-wave-vip' ) . '</p></div>';
		}

		if ( empty( $plans ) ) {
			echo '<p class="description" style="color:#d63638;">' . esc_html__( 'هنوز نگاشتی برای طرح وجود ندارد. از دکمهٔ بالا استفاده کنید یا در کادر نگاشت پایین خط اضافه کنید.', 'music-wave-vip' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:920px;"><thead><tr><th>' . esc_html__( 'سطح و آیکن', 'music-wave-vip' ) . '</th><th>' . esc_html__( 'محصول و توضیحات طرح', 'music-wave-vip' ) . '</th><th>' . esc_html__( 'مدت', 'music-wave-vip' ) . '</th><th>' . esc_html__( 'عملیات', 'music-wave-vip' ) . '</th></tr></thead><tbody>';
			foreach ( $plans as $plan ) {
				if ( ! is_array( $plan ) || empty( $plan['level'] ) ) {
					continue;
				}
				$level    = sanitize_key( (string) $plan['level'] );
				$days     = isset( $plan['duration_days'] ) ? absint( $plan['duration_days'] ) : 0;
				$ids      = isset( $plan['product_ids'] ) && is_array( $plan['product_ids'] ) ? array_map( 'absint', $plan['product_ids'] ) : array();
				$products = array();
				$summary  = '';
				foreach ( $ids as $id ) {
					$edit = get_edit_post_link( $id );
					$view = get_permalink( $id );
					$line = $edit ? '<a href="' . esc_url( $edit ) . '" target="_blank" rel="noopener"><strong>#' . esc_html( (string) $id ) . ' ' . esc_html( get_the_title( $id ) ) . '</strong></a>' : '<strong>#' . esc_html( (string) $id ) . '</strong>';
					if ( $view ) {
						$line .= ' <a href="' . esc_url( $view ) . '" target="_blank" rel="noopener" style="font-size:12px;">↗</a>';
					}
					$products[] = $line;
					if ( '' === $summary ) {
						$copy    = VipPlanCatalog::extract_product_copy( $id, $level, $days );
						$summary = $copy['description'];
					}
				}
				$icon_slug                      = VipPlanCatalog::resolve_icon( $level, $days );
				$icon_html                      = VipPlanCatalog::icon_svg( $icon_slug, 18, 'mwvip-plan-icon' );
				$form_id                        = 'mw-remove-plan-' . $level;
				$this->remove_forms[ $form_id ] = '<form id="' . esc_attr( $form_id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:none;">' .
					'<input type="hidden" name="action" value="' . esc_attr( PlanProducts::ACTION_REMOVE_PLAN ) . '">' .
					'<input type="hidden" name="level" value="' . esc_attr( $level ) . '">' .
					wp_nonce_field( PlanProducts::ACTION_REMOVE_PLAN, '_wpnonce', true, false ) .
					'</form>';
				echo '<tr><td style="white-space:nowrap;"><span style="display:inline-flex;align-items:center;gap:6px;vertical-align:middle;">' . $icon_html . '<code>' . esc_html( $level ) . '</code></span></td><td>' . wp_kses_post( implode( ', ', $products ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $icon_html is hardcoded safe inline SVG from VipPlanCatalog::icon_svg().
				if ( '' !== $summary ) {
					echo '<div class="description" style="margin-top:4px;">' . esc_html( $summary ) . '</div>';
				}
				echo '</td><td>' . esc_html( PlanProducts::duration_label( $days ) ) . '</td><td style="white-space:nowrap;">';
				echo '<a class="button button-small" style="margin-left:4px;" href="' . esc_url( add_query_arg( array( 'mw_vip_level_filter' => $level ), admin_url( 'users.php' ) ) ) . '">' . esc_html__( 'مشترکین', 'music-wave-vip' ) . '</a> ';
				if ( $woo ) {
					echo '<button type="submit" class="button button-small" form="' . esc_attr( $form_id ) . '" data-mwvip-confirm="' . esc_attr__( 'تأیید حذف نگاشت؟', 'music-wave-vip' ) . '">' . esc_html__( 'حذف نگاشت', 'music-wave-vip' ) . '</button>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<p class="description">' . esc_html__( 'توضیحات، چک‌لیست امکانات و قیمت هر طرح مستقیماً از صفحه ویرایش محصول در ووکامرس خوانده شده و در کارت‌های عضویت (.mw-membership__plan) و صفحه تک‌محصول نمایش داده می‌شود.', 'music-wave-vip' ) . '</p>';
		}
		echo '</div>';
	}

	private function render_plan_action_notice(): void {
		echo VipSettingsUi::plan_action_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function membership_section(): void {
		echo '<div class="mwvip-section-intro mwvip-section-intro--green">';
		echo '<p style="margin:0 0 8px;">' . esc_html__( 'برای دسترسی به انتشارهای «فقط عضویت» یا «خرید یا عضویت»، سطح پیش‌فرض mw_vip همهٔ مشترکین فعال VIP را پوشش می‌دهد و نامک اختصاصی طرح (مانند vip-12m) فقط دارندگان همان طرح را مجاز می‌کند.', 'music-wave-vip' ) . '</p>';
		echo '<p style="margin:8px 0 0;background:#f6f7f7;padding:8px;border-radius:4px;"><strong>' . esc_html__( 'طرح‌های ووکامرس:', 'music-wave-vip' ) . '</strong> ' . esc_html__( 'با تکمیل سفارش، سطح طرح و نقش mw_vip فوراً اعطا می‌شوند؛ تمدید زودتر روزها را روی باقیمانده قبلی تجمیع می‌کند؛ لغو یا استرداد وجه بلافاصله دسترسی همان سفارش را پس می‌گیرد.', 'music-wave-vip' ) . '</p>';
		echo '</div>';
	}

	public function plan_rows_field(): void {
		$value    = (string) VipSettings::all()['plan_rows'];
		$disabled = $this->membership_controls_disabled();
		$all      = VipSettings::all();
		$parsed   = isset( $all['vip_plans'] ) && is_array( $all['vip_plans'] ) ? $all['vip_plans'] : array();
		$count    = count( $parsed );
		echo VipSettingsUi::product_reference(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div style="margin-top:16px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px;max-width:780px;">';
		echo '<label for="mwvip-plan-rows" style="font-weight:600;">' . esc_html__( 'نگاشت طرح VIP', 'music-wave-vip' ) . VipSettingsUi::tooltip( __( 'در هر خط یک طرح با فرمت level:product_id[:days].', 'music-wave-vip' ) ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<textarea id="mwvip-plan-rows" class="large-text code mwvip-module-control" rows="5" placeholder="vip-1m:123:30&#10;vip-6m:124:180&#10;vip-12m:125:365" name="' . esc_attr( VipSettings::OPTION ) . '[plan_rows]" spellcheck="false" ' . disabled( $disabled, true, false ) . ' style="font-family:Consolas,Monaco,monospace;margin-top:8px;">' . esc_textarea( $value ) . '</textarea>';
		echo VipSettingsUi::example_box( __( 'نمونهٔ نگاشت کامل', 'music-wave-vip' ), "vip-1m:101:30\nvip-12m:102,103:365\nvip-lifetime:104", __( 'شناسه‌های محصول، شناسهٔ محصولات ووکامرس هستند. مدت به‌روز است (۱ تا ۳۶۵۰)؛ برای مادام‌العمر خالی بگذارید.', 'music-wave-vip' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $count > 0 ) {
			/* translators: %d: active plan count. */
			echo '<p class="description" style="margin-top:8px;"><span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span> ' . esc_html( sprintf( __( 'در حال حاضر %d طرح فعال پیکربندی‌شده است.', 'music-wave-vip' ), $count ) ) . '</p>';
		} else {
			echo '<p class="description" style="margin-top:8px;color:#d63638;"><span class="dashicons dashicons-warning"></span> ' . esc_html__( 'هنوز طرحی پیکربندی‌نشده است. دست‌کم یک خط اضافه و ذخیره کنید.', 'music-wave-vip' ) . '</p>';
		}
		echo '</div>';
	}

	public function promote_vip_role_field(): void {
		$value    = (string) VipSettings::all()['promote_vip_role'];
		$disabled = $this->membership_controls_disabled();
		echo '<label style="display:inline-flex;align-items:center;gap:8px;padding:10px;border:1px solid #ccd0d4;border-radius:4px;background:#fff;"><input type="checkbox" class="mwvip-module-control" name="' . esc_attr( VipSettings::OPTION ) . '[promote_vip_role]" value="enabled" ' . checked( $value, 'enabled', false ) . disabled( $disabled, true, false ) . '> <span><strong>' . esc_html__( 'اعطای خودکار نقش VIP MusicWave (mw_vip)', 'music-wave-vip' ) . '</strong><br><span class="description">' . esc_html__( 'حساب‌های VIP فعال را در فهرست کاربران وردپرس مشخص می‌کند و پس از انقضا یا لغو سفارش خودکار بازپس گرفته می‌شود.', 'music-wave-vip' ) . '</span></span></label>';
	}

	public function delivery_provider_field(): void {
		$value  = (string) VipSettings::all()['delivery_provider'];
		$labels = $this->mode_labels();
		$guides = $this->mode_guides();

		echo '<select name="' . esc_attr( VipSettings::OPTION ) . '[delivery_provider]" id="mwvip-delivery-provider" style="min-width:380px;">';
		foreach ( $this->mode_order() as $key ) {
			if ( ! isset( $labels[ $key ] ) ) {
				continue;
			}
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $value, $key, false ) . '>' . esc_html( $labels[ $key ] ) . '</option>';
		}
		echo '</select>';

		foreach ( $this->mode_order() as $key ) {
			if ( ! isset( $guides[ $key ] ) ) {
				continue;
			}
			$guide = $guides[ $key ];
			$shown = $value === $key ? '' : 'display:none;';
			echo '<div class="mwvip-mode-hint" data-mwvip-mode-hint="' . esc_attr( $key ) . '" style="' . esc_attr( $shown ) . '">';
			echo '<p class="mwvip-mode-hint__what"><span class="dashicons ' . esc_attr( $guide['icon'] ) . '" aria-hidden="true"></span> ' . esc_html( $guide['does'] ) . '</p>';
			echo '<p class="mwvip-mode-hint__needs"><strong>' . esc_html__( 'برای این حالت این بخش‌ها را پر کنید:', 'music-wave-vip' ) . '</strong>';
			foreach ( $guide['needs'] as $need ) {
				echo '<span class="mwvip-chip">' . esc_html( $need ) . '</span>';
			}
			echo '</p>';
			echo '<p class="mwvip-mode-hint__req"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <strong>' . esc_html__( 'الزامی:', 'music-wave-vip' ) . '</strong> ' . esc_html( $guide['required'] ) . '</p>';
			echo '</div>';
		}

		echo VipSettingsUi::tooltip( __( 'حالت محلی و پروکسی بایت‌ها را از دیسک خصوصی می‌فرستند؛ حالت‌های لبه و راهدور نشانی کوتاه‌عمر امضاشده با HMAC-SHA256 صادر می‌کنند. حالت‌های لبه در صورت نبود کلید قوی، ایمن به تحویل محلی برمی‌گردند و هرگز نشانی بدون امضا صادر نمی‌کنند.', 'music-wave-vip' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function root_field(): void {
		$settings = VipSettings::all();
		$value    = defined( 'MUSIC_WAVE_VIP_PROTECTED_ROOT' ) ? (string) constant( 'MUSIC_WAVE_VIP_PROTECTED_ROOT' ) : (string) $settings['protected_root'];
		$default  = dirname( rtrim( ABSPATH, '/\\' ) ) . DIRECTORY_SEPARATOR . 'musicwave-private';
		$locked   = defined( 'MUSIC_WAVE_VIP_PROTECTED_ROOT' );
		echo '<input class="regular-text code" type="text" name="' . esc_attr( VipSettings::OPTION ) . '[protected_root]" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $default ) . '" ' . disabled( $locked, true, false ) . ' style="min-width:420px;">' . VipSettingsUi::tooltip( __( 'مسیر مطلق خارج از public_html؛ در صورت نبود، هنگام ذخیره به صورت خودکار ساخته و با .htaccess / web.config ایمن‌سازی می‌شود.', 'music-wave-vip' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $locked ) {
			echo '<p class="description" style="color:#d63638;"><span class="dashicons dashicons-lock"></span> ' . esc_html__( 'با ثابت MUSIC_WAVE_VIP_PROTECTED_ROOT در wp-config.php قفل شده است.', 'music-wave-vip' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'خالی بگذارید تا پوشهٔ خودکار کنار وردپرس ساخته شود، یا مسیر دلخواه خارج از ریشهٔ وب (مانند /var/private/musicwave) را وارد کنید تا خودکار ایجاد و ایمن‌سازی شود.', 'music-wave-vip' ) . '</p>';
		}
	}

	public function remote_base_url_field(): void {
		$value = (string) VipSettings::all()['remote_base_url'];
		echo '<input class="regular-text code" type="url" inputmode="url" placeholder="https://downloads.example.com/files" name="' . esc_attr( VipSettings::OPTION ) . '[remote_base_url]" value="' . esc_attr( $value ) . '" style="min-width:420px;">' . VipSettingsUi::tooltip( __( 'باید HTTPS باشد. دامنهٔ همین نشانی پایه به‌طور خودکار به فهرست دامنه‌های مجاز اضافه می‌شود.', 'music-wave-vip' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function remote_path_prefix_field(): void {
		$value = (string) VipSettings::all()['remote_path_prefix'];
		echo '<input class="regular-text code" type="text" placeholder="musicwave" name="' . esc_attr( VipSettings::OPTION ) . '[remote_path_prefix]" value="' . esc_attr( $value ) . '">';
	}

	public function remote_signing_secret_field(): void {
		$value = (string) VipSettings::all()['remote_signing_secret'];
		$this->secret_field(
			array(
				'key'        => 'remote_signing_secret',
				'clear_flag' => 'clear_remote_signing_secret',
				'generate'   => 32,
				'help'       => __( 'کلیدی که MusicWave با آن نشانی فایل روی سرور دانلود شما را امضا می‌کند. حداقل ۳۲ نویسه؛ با دکمهٔ «ایجاد» یک کلید ۶۴ نویسه‌ای تصادفی بسازید و همان مقدار را در پیکربندی سرور دانلود هم بگذارید. اگر خالی یا کوتاه باشد، نشانی امضاشده ساخته نمی‌شود.', 'music-wave-vip' ),
			)
		);
		if ( '' !== $value && strlen( $value ) < 32 ) {
			echo '<p class="mwvip-inline-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html__( 'کلید فعلی کوتاه‌تر از ۳۲ نویسه است. تا ذخیرهٔ کلید قوی‌تر، نشانی امضاشده صادر نمی‌شود.', 'music-wave-vip' ) . '</p>';
		}
	}

	public function remote_signature_param_field(): void {
		$this->text_field( 'remote_signature_param', 'signature', __( 'کلید query که امضای base64url با HMAC-SHA256 را دریافت می‌کند.', 'music-wave-vip' ) );
	}

	public function remote_expires_param_field(): void {
		$this->text_field( 'remote_expires_param', 'expires', __( 'کلید query که timestamp انقضای Unix را دریافت می‌کند.', 'music-wave-vip' ) );
	}

	public function remote_ttl_field(): void {
		$value = absint( VipSettings::all()['remote_ttl'] );
		echo '<input class="small-text" type="number" min="30" max="900" step="1" name="' . esc_attr( VipSettings::OPTION ) . '[remote_ttl]" value="' . esc_attr( (string) $value ) . '"> <span>' . esc_html__( 'ثانیه (۳۰ تا ۹۰۰)', 'music-wave-vip' ) . '</span>';
	}

	public function remote_allowed_hosts_field(): void {
		$hosts = (array) VipSettings::all()['remote_allowed_hosts'];
		echo '<textarea class="regular-text code" rows="3" name="' . esc_attr( VipSettings::OPTION ) . '[remote_allowed_hosts]" placeholder="mirror.example.net&#10;cdn2.example.com" style="max-width:420px;">' . esc_textarea( implode( "\n", array_map( 'strval', $hosts ) ) ) . '</textarea>';
	}

	public function remote_key_id_field(): void {
		$value = (string) VipSettings::all()['remote_key_id'];
		echo '<input class="regular-text code" type="text" name="' . esc_attr( VipSettings::OPTION ) . '[remote_key_id]" value="' . esc_attr( $value ) . '" placeholder="k2026">';
	}

	public function sendfile_mode_field(): void {
		$value = (string) VipSettings::all()['sendfile_mode'];
		$modes = array(
			'none'      => __( 'ارسال با PHP (با همهٔ وبسرورها سازگار؛ سادهترین و پیشفرض)', 'music-wave-vip' ),
			'xsendfile' => __( 'سریعتر: ارسال فایل توسط وبسرور آپاچی/LiteSpeed (هدر X-Sendfile)', 'music-wave-vip' ),
			'xaccel'    => __( 'سریعتر: ارسال فایل توسط وبسرور Nginx (هدر X-Accel-Redirect)', 'music-wave-vip' ),
		);
		echo '<select name="' . esc_attr( VipSettings::OPTION ) . '[sendfile_mode]" id="mwvip-sendfile-mode">';
		foreach ( $modes as $mode => $label ) {
			echo '<option value="' . esc_attr( $mode ) . '" ' . selected( $value, $mode, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public function xaccel_prefix_field(): void {
		$value = (string) VipSettings::all()['xaccel_prefix'];
		$root  = ( new ProtectedAssetStorage() )->root();
		$alias = false !== $root ? rtrim( $root, '/' ) . '/' : '/var/private/musicwave/';
		$pref  = '' !== $value ? rtrim( $value, '/' ) : '/musicwave-protected';
		echo '<input class="regular-text code" type="text" placeholder="/musicwave-protected" name="' . esc_attr( VipSettings::OPTION ) . '[xaccel_prefix]" value="' . esc_attr( $value ) . '">';
		echo '<pre class="mwvip-code-snippet">location ' . esc_html( $pref ) . "/ {\n    internal;\n    alias " . esc_html( $alias ) . ";\n}</pre>";
	}

	// ------------------------------------------------------------------
	// ADR 0006 reusable controls & grouped panels
	// ------------------------------------------------------------------

	/**
	 * Reusable `enabled`/`disabled` toggle bound to a settings key.
	 *
	 * @param string $id Element id used by the panel's show/hide JS.
	 */
	private function toggle_field( string $key, string $label, string $help, string $id ): void {
		$checked = 'enabled' === (string) VipSettings::all()[ $key ];
		echo '<label style="display:inline-flex;align-items:flex-start;gap:8px;padding:10px;border:1px solid ' . esc_attr( $checked ? '#00a32a' : '#ccd0d4' ) . ';border-radius:4px;background:' . esc_attr( $checked ? '#f0f9f1' : '#fff' ) . ';max-width:760px;box-sizing:border-box;"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( VipSettings::OPTION ) . '[' . esc_attr( $key ) . ']" value="enabled" ' . checked( $checked, true, false ) . ' style="margin-top:4px;"> <span><strong>' . esc_html( $label ) . '</strong>' . VipSettingsUi::tooltip( $help ) . '<br><span class="description" style="font-size:12px;">' . esc_html( $help ) . '</span></span></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tooltip() returns pre-escaped HTML.
	}

	/**
	 * Reusable select bound to a settings key.
	 *
	 * @param array<string, string> $options Value => label pairs.
	 * @param string                $help    Optional help text under the select.
	 * @param string                $id      Element id for the panel JS.
	 */
	private function select_field( string $key, array $options, string $help, string $id ): void {
		$value = (string) VipSettings::all()[ $key ];
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( VipSettings::OPTION ) . '[' . esc_attr( $key ) . ']" style="min-width:300px;">';
		foreach ( $options as $option => $label ) {
			echo '<option value="' . esc_attr( (string) $option ) . '" ' . selected( $value, (string) $option, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		if ( '' !== $help ) {
			echo VipSettingsUi::tooltip( $help ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tooltip() returns pre-escaped HTML.
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
	}

	/**
	 * Reusable integer input bound to a settings key.
	 */
	private function number_field( string $key, int $min, int $max, string $suffix, string $help = '' ): void {
		$value = absint( VipSettings::all()[ $key ] );
		echo '<input class="small-text" type="number" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" step="1" name="' . esc_attr( VipSettings::OPTION ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $value ) . '"> <span>' . esc_html( $suffix ) . '</span>';
		if ( '' !== $help ) {
			echo VipSettingsUi::tooltip( $help ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tooltip() returns pre-escaped HTML.
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
	}

	/**
	 * Reusable text/url input bound to a settings key.
	 */
	private function plain_text_field( string $key, string $placeholder, string $help = '', string $type = 'text', bool $disabled = false ): void {
		$value = (string) VipSettings::all()[ $key ];
		echo '<input class="regular-text code" type="' . esc_attr( $type ) . '" name="' . esc_attr( VipSettings::OPTION ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" style="min-width:420px;" ' . disabled( $disabled, true, false ) . '>';
		if ( '' !== $help ) {
			echo VipSettingsUi::tooltip( $help ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tooltip() returns pre-escaped HTML.
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
	}

	/**
	 * Reusable comma/newline-separated host list bound to an array settings key.
	 */
	private function list_field( string $key, string $placeholder, string $help = '' ): void {
		$value = (array) VipSettings::all()[ $key ];
		echo '<input class="regular-text code" type="text" name="' . esc_attr( VipSettings::OPTION ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( implode( ', ', array_map( 'strval', $value ) ) ) . '" placeholder="' . esc_attr( $placeholder ) . '" style="min-width:420px;">';
		if ( '' !== $help ) {
			echo VipSettingsUi::tooltip( $help ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tooltip() returns pre-escaped HTML.
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
	}

	/**
	 * Human-readable delivery-mode names, shared by the chooser, the bounded
	 * section headers and the quick-guide matrix, so one mode can never be
	 * called two different things on the same screen.
	 *
	 * @return array<string, string>
	 */
	private function mode_labels(): array {
		return array(
			'local'           => __( 'روی همین سرور (بدون سرور خارجی)', 'music-wave-vip' ),
			'proxy'           => __( 'عبور از خودِ سایت (مبدأ پنهان)', 'music-wave-vip' ),
			'edge_signed'     => __( 'نشانی امضاشده روی CDN (بدون HLS)', 'music-wave-vip' ),
			'edge_hls'        => __( 'پخش HLS رمزنگاری‌شده از CDN', 'music-wave-vip' ),
			'remote_redirect' => __( 'تغییرمسیر به سرور دانلود/CDN شما', 'music-wave-vip' ),
		);
	}

	/**
	 * Recommended presentation order: no external server first, then CDN modes,
	 * then the custom download-server mode.
	 *
	 * @return list<string>
	 */
	private function mode_order(): array {
		return array( 'local', 'proxy', 'edge_signed', 'edge_hls', 'remote_redirect' );
	}

	/**
	 * Per-mode guide used by BOTH the live hint under the chooser and the
	 * quick-guide matrix, so the two can never describe a mode differently.
	 *
	 * @return array<string, array{label:string, icon:string, does:string, needs:list<string>, required:string}>
	 */
	private function mode_guides(): array {
		$labels = $this->mode_labels();

		return array(
			'local'           => array(
				'label'    => $labels['local'],
				'icon'     => 'dashicons-database',
				'does'     => __( 'فایل بیرون از ریشهٔ وب می‌ماند و از همین سرور با PHP یا وب‌سرور سرو می‌شود.', 'music-wave-vip' ),
				'needs'    => array( __( 'مخزن فایل', 'music-wave-vip' ), __( 'تحویل محلی', 'music-wave-vip' ), __( 'تحویل محلی هوشمند', 'music-wave-vip' ) ),
				'required' => __( 'فقط پوشهٔ فایل', 'music-wave-vip' ),
			),
			'proxy'           => array(
				'label'    => $labels['proxy'],
				'icon'     => 'dashicons-hidden',
				'does'     => __( 'مثل حالت محلی، اما نشانی کوتاه‌عمر از دامنهٔ سایت عبور می‌کند و آدرس مبدأ پنهان می‌ماند.', 'music-wave-vip' ),
				'needs'    => array( __( 'مخزن فایل', 'music-wave-vip' ), __( 'تحویل محلی', 'music-wave-vip' ), __( 'نشست‌های پخش', 'music-wave-vip' ), __( 'تحویل محلی هوشمند', 'music-wave-vip' ) ),
				'required' => __( 'پوشهٔ فایل و روشن‌بودن نشست‌ها', 'music-wave-vip' ),
			),
			'edge_signed'     => array(
				'label'    => $labels['edge_signed'],
				'icon'     => 'dashicons-cloud',
				'does'     => __( 'فایل روی CDN سرو می‌شود و کاربر نشانی کوتاه‌عمرِ امضاشده روی دامنهٔ CDN می‌گیرد؛ مبدأ پنهان می‌ماند.', 'music-wave-vip' ),
				'needs'    => array( __( 'اتصال به لبه/CDN', 'music-wave-vip' ), __( 'آپلود خودکار', 'music-wave-vip' ) ),
				'required' => __( 'نشانی پایهٔ HTTPS لبه و کلید مخفی امضای لینک', 'music-wave-vip' ),
			),
			'edge_hls'        => array(
				'label'    => $labels['edge_hls'],
				'icon'     => 'dashicons-media-audio',
				'does'     => __( 'فایل به قطعه‌های کوچک شکسته و با AES-128 رمز می‌شود و از CDN پخش می‌گردد؛ کلید رمز فقط روی سایت می‌ماند.', 'music-wave-vip' ),
				'needs'    => array( __( 'اتصال به لبه/CDN', 'music-wave-vip' ), __( 'بسته‌بندی HLS', 'music-wave-vip' ), __( 'آپلود خودکار', 'music-wave-vip' ) ),
				'required' => __( 'همان مقادیر لبه به‌همراه ffmpeg روی سرور', 'music-wave-vip' ),
			),
			'remote_redirect' => array(
				'label'    => $labels['remote_redirect'],
				'icon'     => 'dashicons-networking',
				'does'     => __( 'نشانی امضاشده با HMAC روی سرور دانلود شما ساخته می‌شود و مرورگر با تغییرمسیر ۳۰۲ به آن می‌رود.', 'music-wave-vip' ),
				'needs'    => array( __( 'تحویل راهدور', 'music-wave-vip' ), __( 'آپلود خودکار', 'music-wave-vip' ) ),
				'required' => __( 'نشانی پایهٔ HTTPS و کلید مخفی امضا (۳۲ نویسه یا بیشتر)', 'music-wave-vip' ),
			),
		);
	}

	/**
	 * Comma-separated human names for a list of mode slugs.
	 *
	 * @param list<string> $modes Mode slugs.
	 */
	private function mode_names( array $modes ): string {
		$labels = $this->mode_labels();
		$names  = array();
		foreach ( $modes as $mode ) {
			$names[] = isset( $labels[ $mode ] ) ? $labels[ $mode ] : $mode;
		}
		return implode( ' · ', $names );
	}
	/**
	 * Open a visually bounded settings section with a live "applies / does not
	 * apply in the current mode" badge, so the admin can tell exactly where each
	 * group starts and ends and whether it takes effect.
	 *
	 * @param string       $id       Section slug (used by the panel JS).
	 * @param string       $title    Section heading.
	 * @param string       $icon     dashicons class (e.g. `dashicons-cloud`).
	 * @param list<string> $modes    Delivery modes this section applies to (empty = always).
	 * @param bool         $required Whether the section is mandatory for its mode(s).
	 * @param string       $summary  One-line plain-Persian explanation.
	 * @param string       $js_class Extra class for the panel show/hide JS.
	 * @param bool         $needs_upload_for_remote Whether the section also requires the
	 *                                              automatic-upload queue in `remote_redirect`
	 *                                              mode (the vault is only staging there).
	 */
	private function section_open( string $id, string $title, string $icon, array $modes, bool $required, string $summary, string $js_class = '', bool $needs_upload_for_remote = false ): void {
		$mode    = (string) VipSettings::all()['delivery_provider'];
		$applies = array() === $modes || in_array( $mode, $modes, true );
		if ( $applies && $needs_upload_for_remote && 'remote_redirect' === $mode ) {
			$applies = 'enabled' === (string) VipSettings::all()['remote_upload_enabled'];
		}
		$classes  = trim( 'mwvip-section ' . $js_class . ( $applies ? '' : ' is-collapsed' ) );
		$mode_txt = array() === $modes ? '' : $this->mode_names( $modes );

		echo '<section class="' . esc_attr( $classes ) . '" data-mwvip-section="' . esc_attr( $id ) . '" data-mwvip-section-modes="' . esc_attr( implode( ' ', $modes ) ) . '" data-mwvip-needs-upload="' . ( $needs_upload_for_remote ? '1' : '0' ) . '">';
		echo '<header class="mwvip-section__head">';
		echo '<h3><span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span> ' . esc_html( $title ) . '</h3>';
		echo '<span class="mwvip-section__badges">';
		if ( $required ) {
			echo '<span class="mwvip-section__badge is-req" data-mwvip-req>' . esc_html__( 'ضروری', 'music-wave-vip' ) . '</span>';
		} else {
			echo '<span class="mwvip-section__badge is-opt">' . esc_html__( 'اختیاری', 'music-wave-vip' ) . '</span>';
		}
		echo '<span class="mwvip-section__badge ' . ( $applies ? 'is-on' : 'is-off' ) . '" data-mwvip-applies>' . esc_html( $applies ? __( 'در حالت فعلی فعال', 'music-wave-vip' ) : __( 'در حالت فعلی بیاثر', 'music-wave-vip' ) ) . '</span>';
		echo '</span>';
		echo '</header>';
		if ( '' !== $summary ) {
			echo '<p class="mwvip-section__summary">' . esc_html( $summary ) . '</p>';
		}
		if ( '' !== $mode_txt ) {
			echo '<p class="mwvip-section__modes"><span class="dashicons dashicons-randomize" aria-hidden="true"></span> ' . esc_html__( 'مربوط به حالتهای:', 'music-wave-vip' ) . ' <span class="mwvip-section__mode-list">' . esc_html( $mode_txt ) . '</span></p>';
		}
		echo '<div class="mwvip-section__body">';
	}

	private function section_close(): void {
		echo '</div></section>';
	}

	/**
	 * A one-glance matrix: for every delivery mode, which behaviour it has, which
	 * sections it needs, and what is mandatory versus optional.
	 */
	private function render_mode_matrix(): void {
		$guides = $this->mode_guides();
		$mode   = (string) VipSettings::all()['delivery_provider'];

		echo '<div class="mwvip-matrix">';
		echo '<h4><span class="dashicons dashicons-networking" aria-hidden="true"></span> ' . esc_html__( 'راهنمای سریع: هر حالت به چه بخش‌هایی نیاز دارد؟', 'music-wave-vip' ) . '</h4>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'حالت تحویل', 'music-wave-vip' ) . '</th>';
		echo '<th>' . esc_html__( 'چه می‌کند؟', 'music-wave-vip' ) . '</th>';
		echo '<th>' . esc_html__( 'بخش‌های موردنیاز', 'music-wave-vip' ) . '</th>';
		echo '<th>' . esc_html__( 'الزامی برای این حالت', 'music-wave-vip' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $this->mode_order() as $key ) {
			if ( ! isset( $guides[ $key ] ) ) {
				continue;
			}
			$guide = $guides[ $key ];
			$chips = '';
			foreach ( $guide['needs'] as $need ) {
				$chips .= '<span class="mwvip-chip">' . esc_html( $need ) . '</span>';
			}
			$current = $key === $mode;
			echo '<tr class="' . ( $current ? 'is-current' : '' ) . '" data-mwvip-matrix-mode="' . esc_attr( $key ) . '">';
			echo '<td class="mwvip-matrix__mode"><span class="dashicons ' . esc_attr( $guide['icon'] ) . '" aria-hidden="true"></span> <strong>' . esc_html( $guide['label'] ) . '</strong><br><code>' . esc_html( $key ) . '</code></td>';
			echo '<td>' . esc_html( $guide['does'] ) . '</td>';
			echo '<td class="mwvip-matrix__chips">' . $chips . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each chip is escaped above.
			echo '<td>' . esc_html( $guide['required'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="mwvip-matrix__note"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span> ' . esc_html__( 'ردیف پررنگ همان حالتی است که همین حالا انتخاب کرده‌اید. بخش‌هایی که در حالت جاری اثری ندارند، پایین‌تر جمع می‌شوند ولی سرتیترشان می‌ماند.', 'music-wave-vip' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Password input for a stored secret: always rendered blank, with an
	 * explicit-clear checkbox so a saved value can still be removed.
	 *
	 * @param string $key        Settings key holding the secret.
	 * @param string $clear_flag Boolean flag name processed by `sanitize_settings()`.
	 */
	/**
	 * Password input for a stored secret.
	 *
	 * A blank submission keeps the stored value; the optional clear checkbox
	 * wipes it. A generator is offered only for secrets MusicWave itself owns
	 * (`generate` > 0). Third-party credentials — S3 Access/Secret keys, WebDAV
	 * app passwords, SFTP passwords — are entered by hand and carry a `source`
	 * note saying exactly where to obtain them, so no field invites the admin to
	 * invent a value the remote service would reject.
	 *
	 * @param array{key:string, clear_flag:string, help?:string, generate?:int, generate_label?:string, source?:string, reveal?:bool} $spec Field specification.
	 */
	private function secret_field( array $spec ): void {
		$key      = (string) $spec['key'];
		$generate = isset( $spec['generate'] ) ? (int) $spec['generate'] : 0;
		$help     = isset( $spec['help'] ) ? (string) $spec['help'] : '';
		$has      = '' !== (string) VipSettings::all()[ $key ];
		$input_id = 'mwvip-field-' . $key;
		$disabled = ! empty( $spec['disabled'] );

		if ( $has ) {
			$placeholder = __( 'ذخیره شده — برای حفظ آن خالی بگذارید', 'music-wave-vip' );
		} elseif ( $generate > 0 ) {
			$placeholder = __( 'خالی؛ با دکمهٔ «ایجاد» پر کنید', 'music-wave-vip' );
		} else {
			$placeholder = __( 'مقدار را از سرویس خود بردارید (خودکار ساخته نمیشود)', 'music-wave-vip' );
		}

		echo '<div class="mwvip-secret-row">';
		echo '<input id="' . esc_attr( $input_id ) . '" class="regular-text code" type="password" autocomplete="new-password" name="' . esc_attr( VipSettings::OPTION ) . '[' . esc_attr( $key ) . ']" value="" placeholder="' . esc_attr( $placeholder ) . '" style="min-width:340px;" ' . disabled( $disabled, true, false ) . '>';
		if ( $generate > 0 ) {
			$label = isset( $spec['generate_label'] ) && '' !== (string) $spec['generate_label']
				? (string) $spec['generate_label']
				/* translators: %d: length of the generated key in characters. */
				: sprintf( __( 'ایجاد کلید %d نویسهای', 'music-wave-vip' ), $generate * 2 );
			echo '<button type="button" class="button" data-mwvip-generate="' . esc_attr( $input_id ) . '" data-mwvip-generate-bytes="' . esc_attr( (string) $generate ) . '" ' . disabled( $disabled, true, false ) . '>' . esc_html( $label ) . '</button>';
		}
		if ( ! isset( $spec['reveal'] ) || $spec['reveal'] ) {
			echo '<button type="button" class="button" data-mwvip-reveal="' . esc_attr( $input_id ) . '" aria-label="' . esc_attr__( 'نمایش/پنهانکردن', 'music-wave-vip' ) . '" ' . disabled( $disabled, true, false ) . '><span class="dashicons dashicons-visibility" aria-hidden="true"></span></button>';
		}
		echo '</div>';
		if ( ! empty( $spec['source'] ) ) {
			echo '<p class="mwvip-source-note"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span> ' . esc_html( (string) $spec['source'] ) . '</p>';
		}
		if ( $has ) {
			echo '<p><label><input type="checkbox" name="' . esc_attr( VipSettings::OPTION ) . '[' . esc_attr( (string) $spec['clear_flag'] ) . ']" value="1" ' . disabled( $disabled, true, false ) . '> ' . esc_html__( 'پاکسازی مقدار ذخیرهشده', 'music-wave-vip' ) . '</label></p>';
		}
		if ( '' !== $help ) {
			echo VipSettingsUi::tooltip( $help ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tooltip() returns pre-escaped HTML.
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
	}

	/**
	 * Edge/CDN delivery group (`edge_signed`, `edge_hls`).
	 *
	 */
	private function render_edge_settings(): void {
		$this->section_open(
			'edge',
			__( 'اتصال به لبه / CDN (شبکهٔ توزیع محتوا)', 'music-wave-vip' ),
			'dashicons-cloud',
			array( 'edge_signed', 'edge_hls' ),
			true,
			__( '«لبه» یعنی دامنهٔ عمومی که کاربر می‌بیند (مثلاً files.example.com). فایل‌ها روی سرور شما/CDN می‌مانند و لینک‌های کوتاه‌عمرِ امضاشده ساخته می‌شوند؛ آدرس واقعی سرور هرگز در مرورگر ظاهر نمی‌شود.', 'music-wave-vip' )
		);
		echo '<div class="mwvip-steps"><strong>' . esc_html__( 'برای اتصال به CDN این چهار کار را انجام دهید:', 'music-wave-vip' ) . '</strong><ol>';
		echo '<li>' . esc_html__( 'در پنل CDN یک توزیع (Distribution/Zone) بسازید و دامنهٔ عمومی آن را در «دامنهٔ عمومی لینک‌ها» و «نشانی پایهٔ HTTPS لبه» وارد کنید.', 'music-wave-vip' ) . '</li>';
		echo '<li>' . esc_html__( 'با دکمهٔ «ایجاد» یک کلید مخفی ۶۴ نویسه‌ای بسازید و همان مقدار را در پیکربندی لبه/CDN ثبت کنید.', 'music-wave-vip' ) . '</li>';
		echo '<li>' . esc_html__( 'اگر CDN فقط درخواست‌های دارای یک هدر مخفی را به مبدأ می‌پذیرد، نام و مقدار آن هدر را پایین وارد کنید (اختیاری).', 'music-wave-vip' ) . '</li>';
		echo '<li>' . esc_html__( 'بخش «آپلود خودکار» را روشن کنید تا فایل‌ها بدون انتقال دستی به CDN بروند.', 'music-wave-vip' ) . '</li>';
		echo '</ol></div>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'دامنهٔ عمومی لینک‌ها', 'music-wave-vip' ) . '</th><td>';
		$this->plain_text_field( 'edge_public_host', 'files.example.com', __( 'دامنه‌ای که در لینک نهایی به کاربر نشان داده می‌شود (بدون https و بدون اسلش). اگر خالی بماند، دامنهٔ «نشانی پایه» استفاده می‌شود.', 'music-wave-vip' ) );
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'نشانی پایهٔ HTTPS لبه (CDN)', 'music-wave-vip' ) . '</th><td>';
		$this->plain_text_field( 'edge_base_url', 'https://cdn.example.com/private', __( 'آدرسی که فایل‌ها از آن سرو می‌شوند؛ باید با https:// شروع شود وگرنه حالت لبه فعال نمی‌شود.', 'music-wave-vip' ), 'url' );
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'کلید مخفی امضای لینک (Edge secret)', 'music-wave-vip' ) . '</th><td>';
		$this->secret_field(
			array(
				'key'        => 'edge_signing_secret',
				'clear_flag' => 'clear_edge_signing_secret',
				'generate'   => 32,
				'help'       => __( 'با همین کلید، لینک‌های manifest/قطعه/کلید امضا می‌شوند تا کسی نتواند نشانی را دست‌کاری کند. اگر خالی یا کوتاه باشد، حالت لبه به‌صورت ایمن به تحویل محلی برمی‌گردد.', 'music-wave-vip' ),
			)
		);
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'شناسهٔ کلید امضا (برای چرخش کلید)', 'music-wave-vip' ) . '</th><td>';
		$this->plain_text_field( 'edge_signing_key_id', 'k1', __( 'برچسبی کوتاه در لینک (مثل k1). هنگام تعویض کلید، این مقدار را عوض کنید تا لینک‌های قدیمی همچنان تا انقضا کار کنند.', 'music-wave-vip' ) );
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'عمر هر لینک امضاشده', 'music-wave-vip' ) . '</th><td>';
		$this->number_field( 'signed_url_ttl', 300, 14400, __( 'ثانیه (۳۰۰ تا ۱۴۴۰۰)', 'music-wave-vip' ), __( 'هر لینک بعد از این مدت می‌میرد. باید از طولانی‌ترین آهنگ بیشتر باشد (مثلاً ۳۶۰۰ = یک ساعت).', 'music-wave-vip' ) );
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'هدر مخفی احراز هویت مبدأ (Origin header)', 'music-wave-vip' ) . '</th><td>';
		$this->plain_text_field( 'edge_origin_header_name', 'X-Origin-Token', __( 'نام یک هدر سفارشی که فقط لبه و سرور شما آن را می‌شناسند. لبه این هدر را به سرور مبدأ می‌فرستد و سرور فقط درخواست‌های دارای این هدر را قبول می‌کند. این هدر از کاربر پنهان است؛ نامش را مثلاً X-Origin-Token بگذارید.', 'music-wave-vip' ) );
		$this->secret_field(
			array(
				'key'            => 'edge_origin_header_value',
				'clear_flag'     => 'clear_edge_origin_header_value',
				'generate'       => 24,
				'generate_label' => __( 'ایجاد توکن تصادفی', 'music-wave-vip' ),
				'help'           => __( 'مقدار مخفی همان هدر. باید در پیکربندی لبه و سرور مبدأ یکسان تنظیم شود.', 'music-wave-vip' ),
			)
		);
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'دامنه‌های مجاز اضافی', 'music-wave-vip' ) . '</th><td>';
		$this->list_field( 'edge_allowed_hosts', 'files.example.com, *.cdn.example.com', __( 'اگر لینک‌ها روی چند دامنه ساخته می‌شوند، اینجا با کاما جدا کنید. برای زیردامنه‌ها از *. استفاده کنید (مثلاً *.cdn.example.com).', 'music-wave-vip' ) );
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'تست اتصال لبه/CDN', 'music-wave-vip' ) . '</th><td>';
		echo '<div class="mwvip-tools-row">';
		echo '<button type="button" class="button" id="mwvip-edge-test" data-mwvip-tool="edgeTest">' . esc_html__( 'آزمایش اتصال به لبه/CDN', 'music-wave-vip' ) . '</button>';
		echo '<p class="mwvip-inline-feedback" id="mwvip-edge-feedback" aria-live="polite"></p>';
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'یک درخواست سبک با هدر مخفی مبدأ می‌فرستد تا از درست‌بودن نشانی پایه و هدر مطمئن شوید.', 'music-wave-vip' ) . '</p>';
		echo '</td></tr>';
		echo '</table>';
		$this->section_close();
	}

	/**
	 * Playback-session / anti-sharing group.
	 */
	private function render_local_delivery_section(): void {
		$this->section_open(
			'local-file',
			__( 'تحویل محلی — پوشه و روش ارسال فایل', 'music-wave-vip' ),
			'dashicons-database',
			array( 'local', 'proxy' ),
			false,
			__( 'فایل روی همان سرور سایت می‌ماند و از پوشه‌ای خارج از ریشهٔ وب سرو می‌شود. برای حالت‌های «محلی» و «پروکسی» اثر دارد و به هیچ سرور خارجی نیاز ندارد.', 'music-wave-vip' )
		);
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'پوشهٔ فایل‌های حفاظت‌شده', 'music-wave-vip' ) . '</th><td>';
		$this->root_field();
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'روش ارسال فایل', 'music-wave-vip' ) . '</th><td>';
		$this->sendfile_mode_field();
		echo '</td></tr>';
		echo '<tr class="mwvip-xaccel-row" ' . ( 'xaccel' === (string) VipSettings::all()['sendfile_mode'] ? '' : 'style="display:none;"' ) . '><th>' . esc_html__( 'پیشوند داخلی X-Accel', 'music-wave-vip' ) . '</th><td>';
		$this->xaccel_prefix_field();
		echo '</td></tr>';
		echo '</table>';
		$this->section_close();
	}

	private function render_remote_redirect_settings( array $remote ): void {
		$this->section_open(
			'remote',
			__( 'تحویل راهدور — تغییرمسیر امضاشده به سرور دانلود/CDN شما', 'music-wave-vip' ),
			'dashicons-networking',
			array( 'remote_redirect' ),
			true,
			__( 'بازدیدکننده یک نشانی کوتاه‌عمرِ امضاشده با HMAC-SHA256 دریافت می‌کند که به فایل روی سرور دانلود یا CDN خودتان اشاره می‌کند. هیچ بایتی از سرور وردپرس عبور نمی‌کند و مبدأ پنهان می‌ماند.', 'music-wave-vip' )
		);
		echo '<div class="mwvip-steps"><strong>' . esc_html__( 'برای اتصال به سرور دانلود این چهار کار را انجام دهید:', 'music-wave-vip' ) . '</strong><ol>';
		echo '<li>' . esc_html__( 'فایل‌ها را روی سرور دانلود/CDN قرار دهید (دستی یا با روشن‌کردن بخش «آپلود خودکار» = پیشنهادی). ساختار مسیر: نشانی پایه + پیشوند مسیر + نام فایل.', 'music-wave-vip' ) . '</li>';
		echo '<li>' . esc_html__( '«نشانی پایهٔ سرور راهدور» را با https وارد کنید؛ همان آدرسی که فایل از آن دانلود می‌شود (بدون اسلش آخر).', 'music-wave-vip' ) . '</li>';
		echo '<li>' . esc_html__( 'با دکمهٔ «ایجاد» کلید مخفی امضا بسازید و همان مقدار را روی سرور دانلود ثبت کنید تا بتواند امضا را بررسی کند.', 'music-wave-vip' ) . '</li>';
		echo '<li>' . esc_html__( 'اگر سرور شما امضای HMAC را بررسی نمی‌کند (مثلاً فقط یک CDN استاتیک است)، به‌جای این حالت از «لبه» یا «HLS» استفاده کنید.', 'music-wave-vip' ) . '</li>';
		echo '</ol></div>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'نشانی پایهٔ سرور راهدور', 'music-wave-vip' ) . '</th><td>';
		$this->remote_base_url_field();
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'پیشوند مسیر راهدور', 'music-wave-vip' ) . '</th><td>';
		$this->remote_path_prefix_field();
		echo '<p class="description">' . esc_html__( 'پوشه‌ای داخل سرور مقصد که فایل‌ها در آن قرار می‌گیرند، مثلاً musicwave. خالی بگذارید تا فایل‌ها در ریشهٔ نشانی پایه باشند.', 'music-wave-vip' ) . '</p>';
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'کلید مخفی امضای راهدور', 'music-wave-vip' ) . '</th><td>';
		$this->remote_signing_secret_field();
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'پارامتر query امضا', 'music-wave-vip' ) . '</th><td>';
		$this->remote_signature_param_field();
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'پارامتر query انقضا', 'music-wave-vip' ) . '</th><td>';
		$this->remote_expires_param_field();
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'طول عمر نشانی راهدور', 'music-wave-vip' ) . '</th><td>';
		$this->remote_ttl_field();
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'دامنه‌های مجاز اضافی برای لینک‌های راهدور', 'music-wave-vip' ) . '</th><td>';
		$this->remote_allowed_hosts_field();
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'شناسهٔ کلید امضا', 'music-wave-vip' ) . '</th><td>';
		$this->remote_key_id_field();
		if ( ! empty( $remote['sample_download_url'] ) ) {
			echo '<p class="description" style="margin-top:10px;"><strong>' . esc_html__( 'پیش‌نمایش زندهٔ نشانی امضاشدهٔ آزمایشی:', 'music-wave-vip' ) . '</strong></p>';
			echo '<code class="mwvip-sample-url">' . esc_html( (string) $remote['sample_download_url'] ) . '</code>';
		} else {
			echo '<p class="description">' . esc_html__( 'پس از ذخیرهٔ نشانی پایه و کلید مخفی، اینجا یک نمونهٔ واقعی از لینک امضاشده نمایش داده می‌شود.', 'music-wave-vip' ) . '</p>';
		}
		echo '</td></tr>';
		echo '</table>';
		$this->section_close();
	}
	private function render_session_settings(): void {
		$this->section_open(
			'session',
			__( 'نشستهای پخش و جلوگیری از اشتراکگذاری لینک', 'music-wave-vip' ),
			'dashicons-lock',
			array( 'edge_signed', 'edge_hls', 'proxy' ),
			false,
			__( 'هر پخش یک «نشست» کوتاهعمر میسازد که به کاربر و دستگاه او گره میخورد. اگر کسی لینک یا کوکی را کپی کند، در جای دیگر کار نمیکند. این بخش برای حالتهای لبه و پروکسی توصیه میشود.', 'music-wave-vip' )
		);
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'فعالسازی نشستهای پخش', 'music-wave-vip' ) . '</th><td>';
		$this->toggle_field( 'session_enabled', __( 'الزام نشست امن برای پخش جریانی', 'music-wave-vip' ), __( 'خاموش: مسیرهای نشست ثبت نمیشوند. روشن: اجارهٔ همزمانی و کوکی HttpOnly اعمال میشود.', 'music-wave-vip' ), 'mwvip-session-enabled' );
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'طول عمر نشست', 'music-wave-vip' ) . '</th><td>';
		$this->number_field( 'session_ttl', 30, 900, __( 'ثانیه (۳۰ تا ۹۰۰)', 'music-wave-vip' ), __( 'با هر درخواست معتبر تمدید میشود؛ مقدار کوتاهتر امنتر است.', 'music-wave-vip' ) );
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'حداکثر پخش همزمان', 'music-wave-vip' ) . '</th><td>';
		$this->number_field( 'session_max_concurrent', 1, 10, __( 'نشست همزمان برای هر کاربر', 'music-wave-vip' ), __( 'با عبور از این سقف، قدیمیترین نشست آزاد میشود تا اشتراکگذاری حساب محدود شود.', 'music-wave-vip' ) );
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'مهلت آزادسازی اجاره', 'music-wave-vip' ) . '</th><td>';
		$this->number_field( 'session_lease_grace', 30, 600, __( 'ثانیه (۳۰ تا ۶۰۰)', 'music-wave-vip' ), __( 'نشستهای بلااستفاده پس از این مدت توسط پاکسازی دورهای آزاد میشوند.', 'music-wave-vip' ) );
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'اتصال به آیپی', 'music-wave-vip' ) . '</th><td>';
		$this->toggle_field( 'session_bind_ip', __( 'نشست به نشانی آیپی کاربر گره بخورد', 'music-wave-vip' ), __( 'روشن (پیشنهادی): لینک کپیشده در آیپی دیگر کار نمیکند. اگر کاربران موبایل زیاد جابهجا میشوند و قطعی آزارنده است، خاموش کنید و به رمزنگاری HLS تکیه کنید.', 'music-wave-vip' ), 'mwvip-session-bind-ip' );
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'اتصال به مرورگر', 'music-wave-vip' ) . '</th><td>';
		$this->toggle_field( 'session_bind_ua', __( 'نشست به User-Agent گره بخورد', 'music-wave-vip' ), __( 'پیشفرض خاموش است؛ روشنکردن آن امنیت را بالا میبرد اما ممکن است برخی پخشکنندههای ثالث را محدود کند.', 'music-wave-vip' ), 'mwvip-session-bind-ua' );
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'تمدید کشویی', 'music-wave-vip' ) . '</th><td>';
		$this->toggle_field( 'session_sliding_renewal', __( 'تمدید خودکار نشست تا وقتی پخش ادامه دارد', 'music-wave-vip' ), __( 'روشن: هر heartbeat معتبر عمر نشست را تازه میکند تا آهنگهای بلند قطع نشوند.', 'music-wave-vip' ), 'mwvip-session-renewal' );
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'محل نگهداری نشست', 'music-wave-vip' ) . '</th><td>';
		echo '<p class="description">' . esc_html__( 'نشستها در جدول اختصاصی و اتمیک ذخیره میشوند تا شمارش همزمانی دقیق باشد. (روی هاستهای بسیار ضعیف در نسخهٔ آینده میتوان حالت سبکتر را اضافه کرد.)', 'music-wave-vip' ) . '</p>';
		echo '</td></tr>';
		echo '</table>';
		$this->section_close();
	}

	/**
	 * HLS packaging + AES-128 encryption group.
	 */
	private function render_hls_settings(): void {
		$this->section_open(
			'hls',
			__( 'بستهبندی HLS و رمزنگاری (مخصوص حالت edge_hls)', 'music-wave-vip' ),
			'dashicons-media-audio',
			array( 'edge_hls' ),
			true,
			__( 'فایل صوتی به قطعههای کوچک تقسیم و با AES-128 رمز میشود. کلید رمز فقط روی سایت شما میماند (نه روی CDN)، پس اگر قطعهای لو برود بدون کلید بیارزش است. این بخش فقط در حالت edge_hls لازم است و به نصب ffmpeg روی سرور نیاز دارد.', 'music-wave-vip' )
		);
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'فعالسازی مسیرهای HLS', 'music-wave-vip' ) . '</th><td>';
		$this->toggle_field( 'hls_enabled', __( 'ثبت مسیرهای manifest / variant / segment / key', 'music-wave-vip' ), __( 'نیازمند کلید مخفی امضای لبه/CDN حداقل ۳۲ نویسه‌ای است؛ در غیر این صورت مسیرها ایمن ثبت نمی‌شوند. در حالت «edge_hls» این گزینه خودکار روشن می‌ماند.', 'music-wave-vip' ), 'mwvip-hls-enabled' );
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'رمزنگاری جریان', 'music-wave-vip' ) . '</th><td>';
		$this->select_field(
			'stream_encryption',
			array(
				'off'    => __( 'خاموش (قطعههای HLS بدون رمز)', 'music-wave-vip' ),
				'aes128' => __( 'AES-128 — کلید فقط با نشست معتبر صادر میشود', 'music-wave-vip' ),
			),
			__( 'کلید AES هرگز روی CDN منتشر نمیشود و تنها از مسیر key با امضای نشست تحویل داده میشود.', 'music-wave-vip' ),
			'mwvip-stream-encryption'
		);
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'کیفیتهای خروجی (kbps)', 'music-wave-vip' ) . '</th><td>';
		$this->render_renditions_field();
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'طول هر قطعه', 'music-wave-vip' ) . '</th><td>';
		$this->number_field( 'hls_segment_seconds', 2, 15, __( 'ثانیه (۲ تا ۱۵)', 'music-wave-vip' ), __( 'قطعهٔ کوتاهتر پخش را سریعتر شروع میکند اما فایلهای بیشتری میسازد؛ ۶ ثانیه تعادل خوبی است.', 'music-wave-vip' ) );
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'زمان بستهبندی', 'music-wave-vip' ) . '</th><td>';
		$this->select_field(
			'hls_packaging',
			array(
				'on_upload' => __( 'هنگام آپلود (ارزان و یکباره — پیشنهادی)', 'music-wave-vip' ),
				'on_demand' => __( 'در لحظهٔ پخش (سنگین؛ بهصورت صفپسزمینه)', 'music-wave-vip' ),
			),
			__( 'بستهبندی همیشه در پسزمینه اجرا میشود و هرگز درخواست کاربر را معطل نمیکند.', 'music-wave-vip' ),
			'mwvip-hls-packaging'
		);
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'مسیر قطعات', 'music-wave-vip' ) . '</th><td>';
		$this->toggle_field( 'hls_proxy_segments', __( 'قطعات از سایت عبور کنند (بهجای CDN)', 'music-wave-vip' ), __( 'خاموش (پیشنهادی): قطعات رمزنگاریشده از CDN میآیند و بار سرور کم میماند. روشن: قطعات از همان دامنهٔ سایت سرو میشوند.', 'music-wave-vip' ), 'mwvip-hls-proxy-segments' );
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'مسیر اجرایی رسانه', 'music-wave-vip' ) . '</th><td>';
		$this->plain_text_field( 'ffmpeg_binary', 'ffmpeg', __( 'نام یا مسیر مطلق ffmpeg روی سرور.', 'music-wave-vip' ) );
		$this->plain_text_field( 'ffprobe_binary', 'ffprobe', __( 'نام یا مسیر مطلق ffprobe روی سرور.', 'music-wave-vip' ) );
		echo '</td></tr>';
		echo '</table>';
		$this->section_close();
	}

	/**
	 * Checkbox list for the allowed HLS renditions.
	 */
	private function render_renditions_field(): void {
		$selected = array_map( 'absint', (array) VipSettings::all()['hls_renditions'] );
		foreach ( array( 64, 128, 192, 320 ) as $bitrate ) {
			$checked = in_array( $bitrate, $selected, true );
			echo '<label style="display:inline-flex;align-items:center;gap:6px;margin:0 14px 0 0;"><input type="checkbox" name="' . esc_attr( VipSettings::OPTION ) . '[hls_renditions][]" value="' . esc_attr( (string) $bitrate ) . '" ' . checked( $checked, true, false ) . '> ' . esc_html( (string) $bitrate ) . ' kbps</label>';
		}
		echo '<p class="description">' . esc_html__( 'دستکم یک کیفیت لازم است؛ اگر هیچکدام انتخاب نشود، پیشفرضهای ۱۲۸ و ۳۲۰ اعمال میشوند.', 'music-wave-vip' ) . '</p>';
	}

	/**
	 * Remote-upload pipeline group (webhook / S3 / WebDAV / SFTP).
	 *
	 * @param array<string, mixed> $settings Normalized VIP settings.
	 */
	private function render_remote_upload_settings( array $settings ): void {
		$enabled = 'enabled' === (string) $settings['remote_upload_enabled'];
		$hidden  = $enabled ? '' : 'display:none;';
		$mode    = (string) $settings['remote_upload_mode'];
		$this->section_open(
			'upload',
			__( 'آپلود خودکار به سرور دانلود/CDN', 'music-wave-vip' ),
			'dashicons-upload',
			array( 'remote_redirect', 'edge_signed', 'edge_hls' ),
			false,
			__( 'اگر فایل‌ها باید روی سرور دانلود جداگانه یا CDN قرار بگیرند، این بخش را روشن کنید تا فایل‌های تازه خودکار منتقل شوند و نیازی به FTP دستی نباشد. اگر فایل‌ها روی همان سرور سایت می‌مانند، این بخش لازم نیست.', 'music-wave-vip' )
		);
		echo '<div class="mwvip-steps"><strong>' . esc_html__( 'روش کار:', 'music-wave-vip' ) . '</strong><ol>';
		echo '<li>' . esc_html__( 'در سرور دانلود/CDN خود یک مقصد بسازید: یک endpoint برای Webhook، یک سطل S3/R2، یک پوشهٔ WebDAV، یا یک حساب SFTP.', 'music-wave-vip' ) . '</li>';
		echo '<li>' . esc_html__( 'اطلاعات اتصال همان مقصد را پایین وارد کنید. فقط فیلدهایی که MusicWave می‌سازد دکمهٔ «ایجاد» دارند؛ کلیدهای S3 و رمزهای WebDAV/SFTP را از پنل سرویس خود بردارید.', 'music-wave-vip' ) . '</li>';
		echo '<li>' . esc_html__( 'با «تست اتصال آپلود» درستی اطلاعات را بسنجید و با «همگام‌سازی کل کتابخانه» فایل‌های موجود را یک‌باره منتقل کنید.', 'music-wave-vip' ) . '</li>';
		echo '</ol>';
		echo '<p class="mwvip-steps__note"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span> ' . esc_html__( 'برای لینک فایل نیازی به کار دستی نیست: MusicWave شناسهٔ هر فایل را در مخزن نگه می‌دارد و هنگام پخش، خودش نشانی امضاشدهٔ کوتاه‌عمر روی دامنهٔ CDN/سرور شما را می‌سازد. کافی است فایل را در «مخزن فایل‌های خصوصی» بالا بارگذاری و شناسهٔ vip: آن را در انتشار انتخاب کنید.', 'music-wave-vip' ) . '</p></div>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'فعال‌سازی صف بارگذاری', 'music-wave-vip' ) . '</th><td>';
		$this->toggle_field( 'remote_upload_enabled', __( 'همگام‌سازی خودکار فایل‌های حفاظت‌شده با سرور دانلود', 'music-wave-vip' ), __( 'روشن: هر فایل تازه در صف قرار می‌گیرد و با کرون دقیقه‌ای به مقصد راهدور منتقل می‌شود. خاموش: هیچ بایتی از سرور خارج نمی‌شود.', 'music-wave-vip' ), 'mwvip-upload-enabled' );
		echo '</td></tr>';
		echo '<tr class="mwvip-upload-group" style="' . esc_attr( $hidden ) . '"><th>' . esc_html__( 'روش انتقال', 'music-wave-vip' ) . '</th><td>';
		$this->select_field(
			'remote_upload_mode',
			array(
				'webhook' => __( 'Webhook امضاشده (سرور دانلود پیشرفته)', 'music-wave-vip' ),
				's3'      => __( 'S3 یا سازگار (Cloudflare R2، MinIO، Wasabi)', 'music-wave-vip' ),
				'webdav'  => __( 'WebDAV (Nextcloud، ownCloud)', 'music-wave-vip' ),
				'sftp'    => __( 'SFTP (نیازمند افزونهٔ ssh2 یا phpseclib)', 'music-wave-vip' ),
			),
			__( 'مقصدی که MusicWave فایل‌ها را به آن می‌فرستد. پس از تغییر، فقط همان گروه از فیلدها نمایش داده می‌شود.', 'music-wave-vip' ),
			'mwvip-upload-mode'
		);
		echo '</td></tr>';

		$cred_rows = array(
			'webhook' => array(
				array( __( 'نشانی endpoint امضاشده', 'music-wave-vip' ), 'remote_upload_endpoint', 'https://downloads.example.com/api/ingest', 'url', __( 'نشانی‌ای که MusicWave فایل را به آن می‌فرستد؛ باید HTTPS و عمومی باشد. آدرس‌های خصوصی/loopback رد می‌شوند.', 'music-wave-vip' ), '' ),
				array( __( 'توکن احراز', 'music-wave-vip' ), 'remote_upload_auth_token', '', 'gen', __( 'توکنی که همراه هر آپلود به‌عنوان Bearer فرستاده می‌شود.', 'music-wave-vip' ), __( 'با دکمهٔ «ایجاد» بسازید و همان مقدار را در سرور دانلود خود هم تنظیم کنید.', 'music-wave-vip' ) ),
			),
			'webdav'  => array(
				array( __( 'نشانی پایهٔ WebDAV', 'music-wave-vip' ), 'remote_upload_endpoint', 'https://cloud.example.com/remote.php/dav/files/user', 'url', __( 'پوشه‌ای که فایل در آن ذخیره می‌شود.', 'music-wave-vip' ), '' ),
				array( __( 'نام کاربری WebDAV', 'music-wave-vip' ), 'remote_upload_access_key', 'username', 'text', '', '' ),
				array( __( 'توکن/رمز WebDAV', 'music-wave-vip' ), 'remote_upload_auth_token', '', 'secret', __( 'رمز یا App Password حساب WebDAV شما.', 'music-wave-vip' ), __( 'این مقدار از سرویس شما می‌آید (مثلاً Nextcloud → Settings → Security → App password) و MusicWave آن را نمی‌سازد.', 'music-wave-vip' ) ),
			),
			's3'      => array(
				array( __( 'نشانی endpoint سازگار با S3', 'music-wave-vip' ), 'remote_upload_endpoint', 'https://s3.example.com', 'url', '', '' ),
				array( __( 'نام سطل (Bucket)', 'music-wave-vip' ), 'remote_upload_bucket', 'musicwave-private', 'text', '', '' ),
				array( __( 'منطقه (Region)', 'music-wave-vip' ), 'remote_upload_region', 'auto', 'text', '', '' ),
				array( __( 'Access Key', 'music-wave-vip' ), 'remote_upload_access_key', '', 'text', '', __( 'از پنل S3/R2 خود؛ MusicWave آن را نمی‌سازد.', 'music-wave-vip' ) ),
				array( __( 'Secret Key', 'music-wave-vip' ), 'remote_upload_secret_key', '', 'secret', '', __( 'از پنل S3/R2 خود. این کلید معمولاً فقط یک‌بار نمایش داده می‌شود؛ همان‌جا کپی کنید.', 'music-wave-vip' ) ),
			),
			'sftp'    => array(
				array( __( 'نشانی سرور SFTP', 'music-wave-vip' ), 'sftp_host', 'sftp.example.com', 'text', '', '' ),
				array( __( 'کاربر SFTP', 'music-wave-vip' ), 'sftp_user', 'musicwave', 'text', '', '' ),
				array( __( 'رمز SFTP', 'music-wave-vip' ), 'sftp_password', '', 'secret', '', __( 'رمز حساب SFTP روی سرور دانلود شما؛ MusicWave آن را نمی‌سازد.', 'music-wave-vip' ) ),
				array( __( 'اثر انگشت کلید میزبان (اختیاری)', 'music-wave-vip' ), 'sftp_host_fingerprint', 'SHA256:AbCd...', 'text', __( 'برای جلوگیری از حملهٔ مرد میانی، اثر انگشت کلید سرور را وارد کنید.', 'music-wave-vip' ), __( 'روی لینوکس: ssh-keyscan -t rsa sftp.example.com | ssh-keygen -lf -', 'music-wave-vip' ) ),
			),
		);

		foreach ( $cred_rows as $row_mode => $rows ) {
			foreach ( $rows as $row ) {
				list( $label, $key, $placeholder, $kind, $help, $source ) = $row;
				$row_hidden   = ( $enabled && $row_mode === $mode ) ? '' : 'display:none;';
				$row_disabled = '' !== $row_hidden;
				echo '<tr class="mwvip-upload-group mwvip-upload-creds" data-modes="' . esc_attr( $row_mode ) . '" style="' . esc_attr( $row_hidden ) . '"><th>' . esc_html( $label ) . '</th><td>';
				if ( 'secret' === $kind ) {
					$this->secret_field(
						array(
							'key'        => $key,
							'clear_flag' => 'clear_' . $key,
							'help'       => $help,
							'source'     => $source,
							'disabled'   => $row_disabled,
						)
					);
				} elseif ( 'gen' === $kind ) {
					$this->secret_field(
						array(
							'key'            => $key,
							'clear_flag'     => 'clear_' . $key,
							'help'           => $help,
							'source'         => $source,
							'generate'       => 32,
							'generate_label' => __( 'ایجاد توکن تصادفی', 'music-wave-vip' ),
							'disabled'       => $row_disabled,
						)
					);
				} else {
					$this->plain_text_field( $key, $placeholder, $help, 'url' === $kind ? 'url' : 'text', $row_disabled );
					if ( '' !== $source ) {
						echo '<p class="mwvip-source-note"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span> ' . esc_html( $source ) . '</p>';
					}
				}
				echo '</td></tr>';
			}
		}

		echo '<tr class="mwvip-upload-group" style="' . esc_attr( $hidden ) . '"><th>' . esc_html__( 'پورت SFTP', 'music-wave-vip' ) . '</th><td>';
		$this->number_field( 'sftp_port', 1, 65535, __( '۱ تا ۶۵۵۳۵ (پیش‌فرض ۲۲)', 'music-wave-vip' ) );
		echo '</td></tr>';
		echo '<tr class="mwvip-upload-group" style="' . esc_attr( $hidden ) . '"><th>' . esc_html__( 'پیشوند مسیر مقصد', 'music-wave-vip' ) . '</th><td>';
		$this->plain_text_field( 'remote_upload_path_prefix', 'musicwave/protected', __( 'مسیر نسبی داخل سطل/سرور مقصد.', 'music-wave-vip' ) );
		echo '</td></tr>';
		echo '<tr class="mwvip-upload-group" style="' . esc_attr( $hidden ) . '"><th>' . esc_html__( 'سقف اندازهٔ فایل', 'music-wave-vip' ) . '</th><td>';
		$this->number_field( 'remote_upload_max_file_mb', 0, 100000, __( 'مگابایت (۰ = بی‌توجه به اندازه)', 'music-wave-vip' ) );
		echo '</td></tr>';
		echo '<tr class="mwvip-upload-group" style="' . esc_attr( $hidden ) . '"><th>' . esc_html__( 'سیاست محلی و تأیید', 'music-wave-vip' ) . '</th><td>';
		$this->toggle_field( 'remote_upload_keep_local', __( 'حفظ نسخهٔ محلی پس از بارگذاری موفق', 'music-wave-vip' ), __( 'خاموش: فایل محلی پس از تأیید انتقال راهدور آزاد می‌شود.', 'music-wave-vip' ), 'mwvip-upload-keep-local' );
		$this->toggle_field( 'remote_upload_verify', __( 'تأیید اندازهٔ فایل در مقصد', 'music-wave-vip' ), __( 'پس از انتقال، اندازهٔ مقصد با منبع مقایسه می‌شود.', 'music-wave-vip' ), 'mwvip-upload-verify' );
		echo '</td></tr>';
		echo '<tr class="mwvip-upload-group" style="' . esc_attr( $hidden ) . '"><th>' . esc_html__( 'تلاش مجدد و هم‌روندی', 'music-wave-vip' ) . '</th><td>';
		$this->number_field( 'remote_upload_max_retries', 1, 10, __( 'تلاش مجدد (۱ تا ۱۰)', 'music-wave-vip' ) );
		$this->number_field( 'remote_upload_concurrency', 1, 5, __( 'انتقال هم‌روند در هر اجرا (۱ تا ۵)', 'music-wave-vip' ) );
		$this->number_field( 's3_multipart_threshold_mb', 10, 1024, __( 'آستانهٔ چندبخشی S3 (مگابایت)', 'music-wave-vip' ) );
		echo '</td></tr>';
		echo '<tr class="mwvip-upload-group" style="' . esc_attr( $hidden ) . '"><th>' . esc_html__( 'گزینهٔ آزمایشی', 'music-wave-vip' ) . '</th><td>';
		$this->toggle_field( 'remote_upload_insecure_tls', __( 'غیرفعال‌کردن بررسی گواهی TLS (فقط آزمایش محلی)', 'music-wave-vip' ), __( 'در سایت واقعی هرگز روشن نکنید؛ فقط برای سرور آزمایشی با گواهی خودامضا.', 'music-wave-vip' ), 'mwvip-upload-insecure-tls' );
		echo '</td></tr>';
		echo '<tr class="mwvip-upload-group" style="' . esc_attr( $hidden ) . '"><th>' . esc_html__( 'ابزارها', 'music-wave-vip' ) . '</th><td>';
		echo '<div class="mwvip-tools-row">';
		echo '<button type="button" class="button" id="mwvip-upload-test" data-mwvip-tool="uploadTest">' . esc_html__( 'تست اتصال آپلود', 'music-wave-vip' ) . '</button> ';
		echo '<button type="button" class="button" id="mwvip-upload-sync" data-mwvip-tool="uploadSync">' . esc_html__( 'همگام‌سازی کل کتابخانه', 'music-wave-vip' ) . '</button> ';
		echo '<button type="button" class="button" id="mwvip-upload-status" data-mwvip-tool="uploadStatus">' . esc_html__( 'وضعیت صف', 'music-wave-vip' ) . '</button>';
		echo '<p class="mwvip-inline-feedback" id="mwvip-upload-feedback" aria-live="polite"></p>';
		echo '</div>';
		echo '</td></tr>';
		echo '</table>';
		$this->section_close();
	}

	/**
	 * Smart local delivery group.
	 */
	private function render_smart_local_settings(): void {
		$this->section_open(
			'local',
			__( 'تحویل محلی هوشمند و خودترمیمگر', 'music-wave-vip' ),
			'dashicons-database',
			array( 'local', 'proxy' ),
			false,
			__( 'قواعدی که وقتی فایل روی همان سرور سایت میماند بهکار میآیند: ترمیم خودکار فایلهای محافظتی، تازهسازی خودکار اثر انگشت فایل، اندازهٔ تکهٔ پخش و پیشنهاد بهترین روش ارسال فایل. بدون نیاز به سرور خارجی.', 'music-wave-vip' )
		);
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'تشخیص خودکار روش offload', 'music-wave-vip' ) . '</th><td>';
		$this->toggle_field( 'local_sendfile_autodetect', __( 'پیشنهاد خودکار بهترین روش ارسال فایل (nginx/آپاچی)', 'music-wave-vip' ), __( 'فقط پیشنهاد میدهد و هیچچیز را خودکار تغییر نمیدهد؛ تغییر فقط با کلیک صریح شما ذخیره میشود.', 'music-wave-vip' ), 'mwvip-local-autodetect' );
		$this->render_sendfile_suggestion();
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'ترمیم خودکار', 'music-wave-vip' ) . '</th><td>';
		$this->toggle_field( 'local_selfheal_enabled', __( 'ترمیم خودکار پوشه و سپرهای حفاظتی', 'music-wave-vip' ), __( 'در صورت نبود یا آسیب .htaccess / web.config، هنگام سرویسدهی بازسازی میشود.', 'music-wave-vip' ), 'mwvip-local-selfheal' );
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'تازهسازی checksum', 'music-wave-vip' ) . '</th><td>';
		$this->toggle_field( 'local_checksum_autorefresh', __( 'محاسبهٔ مجدد SHA-256 هنگام تغییر فایل', 'music-wave-vip' ), __( 'یکپارچگی فایلهای حفاظتشده را با فهرست مخزن همگام نگه میدارد.', 'music-wave-vip' ), 'mwvip-local-checksum' );
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'اندازهٔ قطعهٔ پخش', 'music-wave-vip' ) . '</th><td>';
		$this->number_field( 'local_stream_chunk_kb', 64, 8192, __( 'کیلوبایت (۶۴ تا ۸۱۹۲)', 'music-wave-vip' ), __( 'قطعهٔ بزرگتر توان کمتری میبرد؛ قطعهٔ کوچکتر حافظهٔ کمتری مصرف میکند.', 'music-wave-vip' ) );
		echo '</td></tr>';
		echo '</table>';
		$this->section_close();
	}

	/**
	 * Inline "recommended sendfile mode" suggestion from server detection.
	 *
	 * Advisory only: the stored value is never changed automatically.
	 */
	private function render_sendfile_suggestion(): void {
		if ( 'enabled' !== (string) VipSettings::all()['local_sendfile_autodetect'] ) {
			return;
		}
		if ( ! class_exists( LocalDeliveryDiagnostics::class ) ) {
			return;
		}

		$suggestion = ( new LocalDeliveryDiagnostics( new ProtectedAssetStorage(), VipSettings::all() ) )->detect_sendfile();
		$current    = (string) VipSettings::all()['sendfile_mode'];
		$is_same    = (string) $suggestion['mode'] === $current;

		echo '<p class="mwvip-suggestion" data-mwvip-suggestion="' . esc_attr( (string) $suggestion['mode'] ) . '">';
		echo '<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span> ';
		echo esc_html( (string) $suggestion['reason'] );
		if ( ! $is_same ) {
			echo ' <strong>' . esc_html__( 'روش فعلی شما با این پیشنهاد تفاوت دارد.', 'music-wave-vip' ) . '</strong>';
		}
		echo '</p>';
	}

	public function membership_sources_field(): void {
		$selected = (array) VipSettings::all()['membership_sources'];
		$disabled = $this->membership_controls_disabled();
		$options  = array(
			'woocommerce_plans'         => array( __( 'محصولات طرح ووکامرس و اعطای دستی پنل کاربران — پیشنهادشده', 'music-wave-vip' ), __( 'خرید طرح یا اعطای دستی مدیر سطح را فوراً فعال می‌کند و بازپرداخت یا انقضا آن را خودکار پس می‌گیرد.', 'music-wave-vip' ) ),
			'role'                      => array( __( 'نقش‌های وردپرس (مانند نقش mw_vip)', 'music-wave-vip' ), __( 'بررسی مستقیم نقش وردپرس هم‌نام با نامک سطح عضویت.', 'music-wave-vip' ) ),
			'filter'                    => array( __( 'فیلتر عضویت خارجی (آداپتر توسعه‌دهنده)', 'music-wave-vip' ), __( 'یکپارچه‌سازی سفارشی از طریق فیلترهای music_wave_vip_membership_*.', 'music-wave-vip' ) ),
			'woocommerce_memberships'   => array( __( 'افزونه WooCommerce Memberships', 'music-wave-vip' ), __( 'بررسی طرح‌های فعال افزونه WooCommerce Memberships.', 'music-wave-vip' ) ),
			'woocommerce_subscriptions' => array( __( 'افزونه WooCommerce Subscriptions', 'music-wave-vip' ), __( 'بررسی اشتراک‌های تکرارشونده WooCommerce Subscriptions.', 'music-wave-vip' ) ),
		);
		foreach ( $options as $key => $info ) {
			list( $label, $help ) = $info;
			$checked              = in_array( $key, $selected, true );
			echo '<label style="display:flex;align-items:center;gap:8px;margin:0.6em 0;padding:8px;border:1px solid ' . esc_attr( $checked ? '#00a32a' : '#ccd0d4' ) . ';border-radius:4px;background:' . esc_attr( $checked ? '#f0f6fc' : '#fff' ) . '"><input type="checkbox" class="mwvip-module-control" name="' . esc_attr( VipSettings::OPTION ) . '[membership_sources][]" value="' . esc_attr( $key ) . '" ' . checked( $checked, true, false ) . disabled( $disabled, true, false ) . '> <span><strong>' . esc_html( $label ) . '</strong><br><span class="description" style="font-size:12px;">' . esc_html( $help ) . '</span></span></label>';
		}
	}

	private function text_field( string $key, string $placeholder, string $description ): void {
		$value = (string) VipSettings::all()[ $key ];
		echo '<input class="regular-text code" type="text" name="' . esc_attr( VipSettings::OPTION ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" style="min-width:260px;">' . VipSettingsUi::tooltip( $description ) . '<p class="description">' . esc_html( $description ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	// ------------------------------------------------------------------
	// Main render – 4 instant client-side tabs inside one atomic form
	// ------------------------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->register_help_tabs();
		$settings    = VipSettings::all();
		$module_on   = 'enabled' === (string) $settings['module_enabled'];
		$diagnostics = ( new ProtectedDeliveryDiagnostics() )->snapshot( $settings );

		echo '<div class="wrap" id="mwvip-settings">';
		$this->render_header( $module_on );
		settings_errors( VipSettings::OPTION );
		$this->render_preflight_notice();
		$this->render_plan_action_notice();
		$this->render_tabs();
		echo '<form action="options.php" method="post" id="mwvip-form">';
		settings_fields( self::OPTION_GROUP );
		echo '<input type="hidden" name="' . esc_attr( VipSettings::OPTION ) . '[mwvip_full_form]" value="1">';
		echo '<input type="hidden" name="' . esc_attr( VipSettings::OPTION ) . '[mwvip_controls_unlocked]" value="' . ( $module_on ? '1' : '0' ) . '" data-mwvip-controls-unlocked>';

		$this->render_general_panel();
		$this->render_delivery_panel( $settings, $diagnostics );
		$this->render_membership_panel( $module_on );
		$this->render_advanced_panel( $settings );

		echo '<p style="background:#fff;border:1px solid #ccd0d4;padding:12px;border-radius:4px;display:flex;align-items:center;gap:12px;">';
		submit_button( __( 'ذخیرهٔ تنظیمات VIP', 'music-wave-vip' ), 'primary', 'submit', false );
		echo '<span class="description">' . esc_html__( 'تمام تب‌ها یکجا اعتبارسنجی و ذخیره می‌شوند؛ پوشهٔ حفاظت‌شده خارج از ریشهٔ وب به صورت خودکار ایجاد و ایمن‌سازی می‌گردد.', 'music-wave-vip' ) . '</span></p>';
		echo '</form>';
		if ( ! empty( $this->remove_forms ) ) {
			foreach ( $this->remove_forms as $form_html ) {
				echo $form_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
		echo '</div>';
	}

	private function render_header( bool $module_on ): void {
		$version    = defined( 'MUSIC_WAVE_VIP_VERSION' ) ? MUSIC_WAVE_VIP_VERSION : 'VIP';
		$badge_bg   = $module_on ? '#00a32a' : '#d63638';
		$badge_text = $module_on ? __( 'اعمال قوانین روشن است', 'music-wave-vip' ) : __( 'حالت پروموشن باز (VIP خاموش)', 'music-wave-vip' );
		echo '<div class="mwvip-header"><div>';
		echo '<h1><span class="dashicons dashicons-shield-alt" style="font-size:26px;color:#2271b1;"></span> ' . esc_html__( 'MusicWave VIP', 'music-wave-vip' ) . ' <span class="mwvip-badge">' . esc_html( (string) $version ) . '</span> <span class="mwvip-badge" style="background:' . esc_attr( $badge_bg ) . ';">' . esc_html( $badge_text ) . '</span></h1>';
		echo '<p>' . esc_html__( 'تحویل فایل حفاظت‌شده، مخزن خصوصی فایل‌ها خارج از ریشهٔ وب، نشانی‌های امضاشده HMAC و اشتراک‌های زمان‌دار ووکامرس.', 'music-wave-vip' ) . '</p>';
		echo '</div><div class="mwvip-quicklinks">';
		echo '<a href="' . esc_url( VipSettingsUi::wc_products_url() ) . '" class="button" target="_blank" rel="noopener"><span class="dashicons dashicons-cart"></span> ' . esc_html__( 'محصولات ووکامرس', 'music-wave-vip' ) . '</a>';
		echo '<a href="' . esc_url( admin_url( 'users.php?mw_vip_filter=active' ) ) . '" class="button"><span class="dashicons dashicons-groups"></span> ' . esc_html__( 'اعضای VIP', 'music-wave-vip' ) . '</a>';
		echo '<a href="' . esc_url( admin_url( 'edit.php?post_type=mw_release' ) ) . '" class="button"><span class="dashicons dashicons-album"></span> ' . esc_html__( 'انتشارها', 'music-wave-vip' ) . '</a>';
		echo '</div></div>';
	}

	private function render_tabs(): void {
		echo '<div class="mwvip-tabs" role="tablist">';
		echo '<button type="button" id="mwvip-tab-general" role="tab" aria-selected="true" aria-controls="mwvip-panel-general" data-tab="general" class="active"><span class="dashicons dashicons-admin-settings" style="vertical-align:middle;"></span> ' . esc_html__( 'عمومی', 'music-wave-vip' ) . '</button>';
		echo '<button type="button" id="mwvip-tab-delivery" role="tab" aria-selected="false" aria-controls="mwvip-panel-delivery" tabindex="-1" data-tab="delivery"><span class="dashicons dashicons-cloud" style="vertical-align:middle;"></span> ' . esc_html__( 'تحویل حفاظت‌شده', 'music-wave-vip' ) . '</button>';
		echo '<button type="button" id="mwvip-tab-membership" role="tab" aria-selected="false" aria-controls="mwvip-panel-membership" tabindex="-1" data-tab="membership"><span class="dashicons dashicons-groups" style="vertical-align:middle;"></span> ' . esc_html__( 'عضویت و طرح‌ها', 'music-wave-vip' ) . '</button>';
		echo '<button type="button" id="mwvip-tab-advanced" role="tab" aria-selected="false" aria-controls="mwvip-panel-advanced" tabindex="-1" data-tab="advanced"><span class="dashicons dashicons-admin-generic" style="vertical-align:middle;"></span> ' . esc_html__( 'پیشرفته و حسابرسی', 'music-wave-vip' ) . '</button>';
		echo '</div>';
	}

	private function render_general_panel(): void {
		echo '<div class="mwvip-panel active" id="mwvip-panel-general" role="tabpanel" aria-labelledby="mwvip-tab-general" data-panel="general">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'عمومی — کلید اصلی و سیاست تحویل', 'music-wave-vip' ) . '</h2>';
		$this->general_section();
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'اعمال قوانین VIP', 'music-wave-vip' ) . '</th><td>';
		$this->module_enabled_field();
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'سیاست پخش و دانلود مهمانان در تحویل حفاظت‌شده', 'music-wave-vip' ) . '</th><td>';
		$this->delivery_access_field();
		echo '</td></tr>';
		echo '</table></div>';
	}

	/**
	 * The `تحویل حفاظتشده` panel.
	 *
	 * 1. Live health + one-click provision/harden/sync.
	 * 2. Private vault uploader and asset browser.
	 * 3. Delivery-mode chooser, then one bounded section per concern in a fixed
	 *    reading order: local file, edge/CDN, remote redirect, playback sessions,
	 *    HLS packaging, automatic upload, and smart local delivery.
	 *
	 * @param array<string, mixed> $settings    Normalized VIP settings.
	 * @param array<string, mixed> $diagnostics Live diagnostic snapshot.
	 */
	private function render_delivery_panel( array $settings, array $diagnostics ): void {
		$local     = isset( $diagnostics['local'] ) && is_array( $diagnostics['local'] ) ? $diagnostics['local'] : array();
		$remote    = isset( $diagnostics['remote'] ) && is_array( $diagnostics['remote'] ) ? $diagnostics['remote'] : array();
		$checks    = isset( $local['checks'] ) && is_array( $local['checks'] ) ? $local['checks'] : array();
		$is_ready  = ! empty( $diagnostics['overall_ready'] );
		$storage   = new ProtectedAssetStorage();
		$inventory = $storage->list_assets( '', 1, 25 );

		echo '<div class="mwvip-panel" id="mwvip-panel-delivery" role="tabpanel" aria-labelledby="mwvip-tab-delivery" data-panel="delivery">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'مرکز کنترل تحویل حفاظت‌شده', 'music-wave-vip' ) . '</h2>';
		$this->delivery_section();
		$this->render_mode_matrix();
		// Delivery-mode chooser. The matrix above already names the sections each
		// mode needs; the hint under the select repeats it for the chosen mode.
		echo '<div class="mwvip-chooser">';
		echo '<label class="mwvip-chooser__label" for="mwvip-delivery-provider"><span class="dashicons dashicons-randomize" aria-hidden="true"></span> ' . esc_html__( 'حالت تحویل (Delivery mode) — کدام روش تحویل فایل‌ها را می‌خواهید؟', 'music-wave-vip' ) . '</label>';
		$this->delivery_provider_field();
		echo '</div>';

		// Shared storage layer: every mode starts here (it is either the served
		// location or the upload staging area). In `remote_redirect` it only
		// matters when the automatic-upload queue is on, so the section collapses
		// with a clear header otherwise instead of showing irrelevant controls.
		$this->section_open(
			'storage',
			__( 'مخزن فایل‌های خصوصی و سلامت آن', 'music-wave-vip' ),
			'dashicons-shield-alt',
			array( 'local', 'proxy', 'edge_signed', 'edge_hls', 'remote_redirect' ),
			true,
			__( 'همهٔ فایل‌ها ابتدا همین‌جا بارگذاری می‌شوند و شناسهٔ vip: می‌گیرند. در حالت‌های محلی/پروکسی از همین سرور سرو می‌شوند، در حالت‌های لبه مبدأ فایل‌اند، و در حالت راهدور فقط وقتی «آپلود خودکار» روشن باشد محل بارگذاری اولیه‌اند.', 'music-wave-vip' ),
			'',
			true
		);

		// 1. Live Operational Health & One-Click Provision/Sync Box.
		echo '<div class="mwvip-delivery-box" data-mwvip-diagnostics>';
		echo '<div class="mwvip-delivery-box__head">';
		echo '<div><h3 style="margin:0 0 4px;">' . esc_html__( 'وضعیت زنده و پیش‌بررسی امنیتی مخزن', 'music-wave-vip' ) . '</h3>';
		echo '<span class="description">' . esc_html__( 'بررسی خودکار مسیر خصوصی، مجوزهای خواندن/نوشتن، سپر .htaccess/web.config و فهرست فایل‌های ثبت‌شده.', 'music-wave-vip' ) . '</span></div>';
		echo '<div class="mwvip-health-actions">';
		echo '<span class="mwvip-status-pill ' . ( $is_ready ? 'is-ok' : 'is-warn' ) . '" data-mwvip-overall-pill>' . esc_html( $is_ready ? __( 'آماده سرویس‌دهی ایمن', 'music-wave-vip' ) : __( 'نیازمند تکمیل پیکربندی', 'music-wave-vip' ) ) . '</span>';
		echo '<button type="button" class="button button-primary" id="mwvip-provision-btn">' . esc_html__( 'ایجاد، ایمن‌سازی و همگام‌سازی خودکار پوشه', 'music-wave-vip' ) . '</button>';
		echo '</div></div>';

		$check_labels = array(
			'root_resolved'    => __( 'شناسایی مسیر پوشهٔ خصوصی', 'music-wave-vip' ),
			'directory_exists' => __( 'وجود فیزیکی پوشه روی سرور', 'music-wave-vip' ),
			'outside_web_root' => __( 'خارج بودن از ریشهٔ عمومی وب (ABSPATH / DOCUMENT_ROOT)', 'music-wave-vip' ),
			'readable'         => __( 'مجوز خواندن توسط سرور', 'music-wave-vip' ),
			'writable'         => __( 'مجوز نوشتن برای بارگذاری امن', 'music-wave-vip' ),
			'deny_files'       => __( 'وجود فایل‌های حفاظتی (.htaccess / web.config)', 'music-wave-vip' ),
		);
		echo '<div class="mwvip-checks-grid">';
		foreach ( $check_labels as $check_key => $check_label ) {
			$passed = ! empty( $checks[ $check_key ] );
			echo '<div class="mwvip-check-item ' . ( $passed ? 'is-ok' : 'is-fail' ) . '" data-check-key="' . esc_attr( $check_key ) . '">';
			echo '<span class="mwvip-check-item__dot" aria-hidden="true">' . ( $passed ? '✓' : '✕' ) . '</span>';
			echo '<span>' . esc_html( $check_label ) . '</span>';
			echo '</div>';
		}
		echo '</div>';

		echo '<div class="mwvip-health-meta">';
		echo '<div><strong>' . esc_html__( 'مسیر فعال فعلی:', 'music-wave-vip' ) . '</strong> <code data-mwvip-resolved-path>' . esc_html( ! empty( $local['resolved_path'] ) ? (string) $local['resolved_path'] : __( 'هنوز ایجاد نشده است — روی دکمهٔ بالا کلیک کنید', 'music-wave-vip' ) ) . '</code></div>';
		echo '<div><strong>' . esc_html__( 'فایل‌های ثبت‌شده در مخزن:', 'music-wave-vip' ) . '</strong> <strong data-mwvip-inventory-count>' . esc_html( (string) ( $local['inventory_count'] ?? 0 ) ) . '</strong> (' . esc_html( function_exists( 'size_format' ) ? (string) size_format( (int) ( $local['inventory_bytes'] ?? 0 ) ) : ( (int) ( $local['inventory_bytes'] ?? 0 ) . ' B' ) ) . ')</div>';
		echo '</div>';
		echo '<p class="mwvip-inline-feedback" id="mwvip-provision-status" aria-live="polite"></p>';
		echo '</div>';

		// 2. Integrated Protected Asset Uploader & Vault Browser.
		echo '<div class="mwvip-delivery-box">';
		echo '<div class="mwvip-delivery-box__head">';
		echo '<div><h3 style="margin:0 0 4px;">' . esc_html__( 'مخزن فایل‌های خصوصی (بارگذاری مستقیم و مدیریت شناسه‌های vip:)', 'music-wave-vip' ) . '</h3>';
		echo '<span class="description">' . esc_html__( 'فایل صوتی (MP3, FLAC, WAV, M4A, AAC, OGG) یا بسته ZIP آلبوم را مستقیماً خارج از پوشهٔ عمومی وردپرس ذخیره کنید.', 'music-wave-vip' ) . '</span></div>';
		echo '<div class="mwvip-vault-upload">';
		echo '<input type="file" id="mwvip-vault-file" accept=".mp3,.flac,.wav,.m4a,.aac,.ogg,.zip">';
		echo '<button type="button" class="button button-secondary" id="mwvip-vault-upload-btn">' . esc_html__( 'بارگذاری در مخزن خصوصی', 'music-wave-vip' ) . '</button>';
		echo '</div></div>';
		echo '<div class="mwvip-vault-toolbar">';
		echo '<input type="search" id="mwvip-vault-search" class="regular-text" placeholder="' . esc_attr__( 'جستجو در نام فایل‌های حفاظت‌شده…', 'music-wave-vip' ) . '">';
		echo '<span class="mwvip-inline-feedback" id="mwvip-vault-status" aria-live="polite"></span>';
		echo '</div>';
		echo '<table class="widefat striped mwvip-vault-table"><thead><tr>';
		echo '<th>' . esc_html__( 'نام فایل', 'music-wave-vip' ) . '</th>';
		echo '<th>' . esc_html__( 'فرمت / مشخصات', 'music-wave-vip' ) . '</th>';
		echo '<th>' . esc_html__( 'حجم', 'music-wave-vip' ) . '</th>';
		echo '<th>' . esc_html__( 'شناسه امن تحویل (Asset ID)', 'music-wave-vip' ) . '</th>';
		echo '<th>' . esc_html__( 'عملیات', 'music-wave-vip' ) . '</th>';
		echo '</tr></thead><tbody id="mwvip-vault-tbody">';
		if ( empty( $inventory['items'] ) ) {
			echo '<tr data-empty-row><td colspan="5">' . esc_html__( 'هنوز هیچ فایل حفاظت‌شده‌ای در مخزن خصوصی ثبت نشده است.', 'music-wave-vip' ) . '</td></tr>';
		} else {
			foreach ( $inventory['items'] as $asset ) {
				$asset_id = isset( $asset['id'] ) ? (string) $asset['id'] : '';
				$raw_key  = 0 === strpos( $asset_id, ProtectedAssetRegistry::PREFIX ) ? substr( $asset_id, strlen( ProtectedAssetRegistry::PREFIX ) ) : '';
				$specs    = strtoupper( (string) ( $asset['format'] ?? '' ) );
				if ( ! empty( $asset['bitrate'] ) ) {
					$specs .= ' • ' . (int) $asset['bitrate'] . ' kbps';
				}
				$fmt_size = function_exists( 'size_format' ) ? (string) size_format( (int) ( $asset['size'] ?? 0 ) ) : ( (int) ( $asset['size'] ?? 0 ) . ' B' );
				echo '<tr data-asset-row="' . esc_attr( $raw_key ) . '">';
				echo '<td><strong>' . esc_html( (string) ( $asset['name'] ?? '' ) ) . '</strong></td>';
				echo '<td>' . esc_html( '' !== $specs ? $specs : '—' ) . '</td>';
				echo '<td>' . esc_html( $fmt_size ) . '</td>';
				echo '<td><code>' . esc_html( $asset_id ) . '</code></td>';
				echo '<td class="mwvip-vault-row-actions">';
				echo '<button type="button" class="button button-small" data-mwvip-copy="' . esc_attr( $asset_id ) . '">' . esc_html__( 'کپی شناسه', 'music-wave-vip' ) . '</button> ';
				if ( '' !== $raw_key ) {
					echo '<button type="button" class="button-link button-link-delete" data-mwvip-delete-asset="' . esc_attr( $raw_key ) . '" data-mwvip-confirm="' . esc_attr__( 'تأیید حذف قطعی؟', 'music-wave-vip' ) . '">' . esc_html__( 'حذف', 'music-wave-vip' ) . '</button>';
				}
				echo '</td></tr>';
			}
		}
		echo '</tbody></table></div>';
		$this->section_close();

		$this->render_local_delivery_section();
		$this->render_edge_settings();
		$this->render_remote_redirect_settings( $remote );
		$this->render_session_settings();
		$this->render_hls_settings();
		$this->render_remote_upload_settings( $settings );
		$this->render_smart_local_settings();

		echo '</div>';
	}

	private function render_membership_panel( bool $module_on ): void {
		echo '<div class="mwvip-panel" id="mwvip-panel-membership" role="tabpanel" aria-labelledby="mwvip-tab-membership" data-panel="membership">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'عضویت و مجوز دسترسی', 'music-wave-vip' ) . '</h2>';
		if ( ! $module_on ) {
			echo '<div class="notice notice-info inline" id="mwvip-membership-note" style="margin:0 0 16px;"><p><span class="dashicons dashicons-hidden" style="color:#2271b1;vertical-align:middle;"></span> ' . esc_html__( 'اعمال قوانین خاموش است؛ با روشن کردن کلید اصلی در تب «عمومی» این گزینه‌ها بلافاصله برای ویرایش باز می‌شوند.', 'music-wave-vip' ) . '</p></div>';
		}
		$this->render_plans_manager();
		$this->membership_section();
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'منابع عضویت', 'music-wave-vip' ) . '</th><td>';
		$this->membership_sources_field();
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'محصولات طرح VIP', 'music-wave-vip' ) . '</th><td>';
		$this->plan_rows_field();
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'ارتقای نقش VIP', 'music-wave-vip' ) . '</th><td>';
		$this->promote_vip_role_field();
		echo '</td></tr>';
		echo '</table></div>';
	}

	/**
	 * @param array<string, mixed> $settings Normalized VIP settings.
	 */
	private function render_advanced_panel( array $settings ): void {
		echo '<div class="mwvip-panel" id="mwvip-panel-advanced" role="tabpanel" aria-labelledby="mwvip-tab-advanced" data-panel="advanced">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'پیشرفته، حسابرسی و مدیریت چرخهٔ داده', 'music-wave-vip' ) . '</h2>';

		$entries = VipAuditLog::entries( 50 );
		echo '<div class="mwvip-delivery-box">';
		echo '<h3 style="margin:0 0 8px;">' . esc_html__( 'آخرین رویدادهای حسابرسی عضویت VIP', 'music-wave-vip' ) . '</h3>';
		if ( empty( $entries ) ) {
			echo '<p class="description">' . esc_html__( 'هنوز رویداد اعطا، تمدید، لغو یا انقضایی در گزارش حسابرسی ثبت نشده است.', 'music-wave-vip' ) . '</p>';
		} else {
			echo '<table class="widefat striped mwvip-audit-table"><thead><tr><th>' . esc_html__( 'زمان', 'music-wave-vip' ) . '</th><th>' . esc_html__( 'کاربر', 'music-wave-vip' ) . '</th><th>' . esc_html__( 'رویداد', 'music-wave-vip' ) . '</th><th>' . esc_html__( 'سطح', 'music-wave-vip' ) . '</th><th>' . esc_html__( 'سفارش / مدیر', 'music-wave-vip' ) . '</th></tr></thead><tbody>';
			foreach ( $entries as $entry ) {
				$uid  = isset( $entry['user_id'] ) ? absint( $entry['user_id'] ) : 0;
				$oid  = isset( $entry['order_id'] ) ? absint( $entry['order_id'] ) : 0;
				$aid  = isset( $entry['actor_id'] ) ? absint( $entry['actor_id'] ) : 0;
				$at   = absint( $entry['at'] ?? time() );
				$when = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i', $at ) : gmdate( 'Y-m-d H:i', $at );
				echo '<tr>';
				echo '<td>' . esc_html( (string) $when ) . '</td>';
				echo '<td>#' . esc_html( (string) $uid ) . '</td>';
				echo '<td><span class="mwvip-event mwvip-event--' . esc_attr( sanitize_html_class( (string) ( $entry['event'] ?? '' ) ) ) . '">' . esc_html( VipAuditLog::event_label( (string) ( $entry['event'] ?? '' ) ) ) . '</span></td>';
				echo '<td><code>' . esc_html( (string) ( $entry['level'] ?? '—' ) ) . '</code></td>';
				echo '<td>' . ( $oid > 0 ? '#' . esc_html( (string) $oid ) : ( $aid > 0 ? esc_html__( 'مدیر #', 'music-wave-vip' ) . esc_html( (string) $aid ) : '—' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		echo '<div class="mwvip-danger-box"><h4><span class="dashicons dashicons-trash" style="vertical-align:middle;"></span> ' . esc_html__( 'حذف داده‌ها هنگام حذف افزونه', 'music-wave-vip' ) . '</h4>';
		echo '<p style="margin:0 0 8px;color:#50575e;">' . esc_html__( 'به‌صورت پیش‌فرض خاموش و ایمن است: هنگام حذف موقت یا به‌روزرسانی دستی افزونه، تمام اشتراک‌های پرداخت‌شدهٔ مشتریان، جدول‌های مخزن (mw_vip_assets)، کلید HLS (mw_vip_asset_keys)، صف آپلود (mw_vip_upload_queue)، نشست‌های پخش (mw_stream_sessions)، گزارش حسابرسی و تنظیمات حفظ می‌شوند. تنها در صورت روشن کردن این گزینه، هنگام حذف کامل افزونه تمام داده‌های پایگاه داده پاک خواهند شد (فایل‌های روی دیسک هرگز پاک نمی‌شوند).', 'music-wave-vip' ) . '</p>';
		$delete_data = (string) $settings['delete_data_on_uninstall'];
		echo '<label style="display:inline-flex;align-items:center;gap:8px;padding:10px;border:1px solid ' . esc_attr( 'enabled' === $delete_data ? '#d63638' : '#ccd0d4' ) . ';border-radius:4px;background:' . esc_attr( 'enabled' === $delete_data ? '#fcf0f1' : '#fff' ) . ';">';
		echo '<input type="checkbox" name="' . esc_attr( VipSettings::OPTION ) . '[delete_data_on_uninstall]" value="enabled" ' . checked( $delete_data, 'enabled', false ) . '>';
		echo '<span><strong>' . esc_html__( 'حذف همهٔ داده‌های پایگاه دادهٔ VIP هنگام حذف افزونه', 'music-wave-vip' ) . '</strong><br><span class="description">' . esc_html__( 'فقط هنگام حذف کامل افزونه اعمال می‌شود، نه غیرفعال‌سازی. پیش‌فرض امن: خاموش.', 'music-wave-vip' ) . '</span></span></label></div>';

		echo '<div class="mwvip-info-grid"><div class="mwvip-info-card"><h4>' . esc_html__( 'یادداشت‌های امنیتی', 'music-wave-vip' ) . '</h4><ul style="margin:0 0 0 18px;list-style:disc;">';
		echo '<li>' . esc_html__( 'رازهای راه‌دور هرگز در معرض مرورگر قرار نمی‌گیرند؛ فقط نشانی‌های کوتاه‌عمر امضاشده با Referrer-Policy: no-referrer صادر می‌شوند.', 'music-wave-vip' ) . '</li>';
		echo '<li>' . esc_html__( 'هر تصمیم دسترسی به‌صورت زنده دوباره بررسی می‌شود تا لغو سفارش یا انقضا بلافاصله اثر کند.', 'music-wave-vip' ) . '</li>';
		echo '<li>' . esc_html__( 'پوشهٔ حفاظت‌شده خارج از تمام ریشه‌های وب (ABSPATH و DOCUMENT_ROOT) نگهداری و با .htaccess / web.config سخت‌سازی می‌شود.', 'music-wave-vip' ) . '</li>';
		echo '</ul></div><div class="mwvip-info-card"><h4>' . esc_html__( 'تنظیمات مرتبط در هستهٔ MusicWave', 'music-wave-vip' ) . '</h4><div class="mwvip-related">';
		echo '<a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=mw_release&page=music-wave-settings&tab=content' ) ) . '"><span class="dashicons dashicons-album"></span> ' . esc_html__( 'پیش‌فرض‌های انتشار جدید و سطح‌های عضویت پیش‌فرض', 'music-wave-vip' ) . '</a>';
		echo '<a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=mw_release&page=music-wave-settings&tab=access' ) ) . '"><span class="dashicons dashicons-lock"></span> ' . esc_html__( 'پیام‌های دسترسی (شامل خرید یا عضویت) و رفتار ماژول عضویت', 'music-wave-vip' ) . '</a>';
		echo '<a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=mw_release&page=music-wave-settings&tab=delivery' ) ) . '"><span class="dashicons dashicons-shield"></span> ' . esc_html__( 'محدودیت نرخ دانلود، سهمیهٔ روزانه و TTL توکن‌ها', 'music-wave-vip' ) . '</a>';
		echo '<a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=mw_release&page=music-wave-bulk-access' ) ) . '"><span class="dashicons dashicons-forms"></span> ' . esc_html__( 'مدیریت حالت دسترسی انبوه کاتالوگ', 'music-wave-vip' ) . '</a>';
		echo '</div></div></div></div>';
	}

	private function register_help_tabs(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! is_object( $screen ) || ! method_exists( $screen, 'add_help_tab' ) ) {
			return;
		}
		$screen->add_help_tab(
			array(
				'id'      => 'music-wave-vip-guide',
				'title'   => __( 'تنظیمات VIP', 'music-wave-vip' ),
				'content' => '<p>' . esc_html__( 'مدیریت یکپارچه کلید اصلی VIP، تحویل حفاظت‌شده، طرح‌های اشتراک ووکامرس و چرخهٔ حیات داده‌ها.', 'music-wave-vip' ) . '</p>',
			)
		);
	}

	private function render_preflight_notice(): void {
		if ( 'local' !== (string) VipSettings::all()['delivery_provider'] ) {
			return;
		}
		$checks = ( new ProtectedAssetStorage() )->preflight();
		$labels = array(
			'root_resolved'    => __( 'مسیر پوشهٔ حفاظت‌شده تنظیم شده یا قابل تشخیص است.', 'music-wave-vip' ),
			'directory_exists' => __( 'پوشهٔ حفاظت‌شده وجود دارد.', 'music-wave-vip' ),
			'readable'         => __( 'وب‌سرور می‌تواند پوشهٔ حفاظت‌شده را بخواند.', 'music-wave-vip' ),
			'writable'         => __( 'وب‌سرور می‌تواند بارگذاری‌ها را در پوشهٔ حفاظت‌شده بنویسد.', 'music-wave-vip' ),
			'outside_web_root' => __( 'پوشه خارج از همهٔ ریشه‌های وب عمومی است.', 'music-wave-vip' ),
			'deny_files'       => __( 'فایل‌های بازدارندهٔ لایهٔ دفاعی (.htaccess / web.config) وجود دارند.', 'music-wave-vip' ),
		);
		$failed = array_keys(
			array_filter(
				$checks,
				static function ( bool $passed ): bool {
					return ! $passed;
				}
			)
		);
		if ( array() === $failed ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'پیش‌آزمایش تحویل حفاظت‌شده نیازمند اقدام است (در تب «تحویل حفاظت‌شده» روی «ایجاد، ایمن‌سازی و همگام‌سازی خودکار پوشه» کلیک کنید):', 'music-wave-vip' ) . '</strong></p><ul style="list-style:disc;padding-left:20px">';
		foreach ( $failed as $check ) {
			if ( isset( $labels[ $check ] ) ) {
				echo '<li>' . esc_html( $labels[ $check ] ) . '</li>';
			}
		}
		echo '</ul></div>';
	}

	/** @param array<int, array<string, mixed>> $cards @return array<int, array<string, mixed>> */
	public function integration_card( array $cards ): array {
		$configured   = VipSettings::all();
		$remote_ready = 'remote_redirect' === $configured['delivery_provider'] && '' !== $configured['remote_base_url'] && '' !== $configured['remote_signing_secret'];
		$active       = 'local' === $configured['delivery_provider'] || $remote_ready;
		$plans        = isset( $configured['vip_plans'] ) && is_array( $configured['vip_plans'] ) ? count( $configured['vip_plans'] ) : 0;
		$free_mode    = 'disabled' === (string) $configured['module_enabled'];
		$card         = array(
			'id'          => 'music-wave-vip',
			'name'        => __( 'تحویل حفاظت‌شده و عضویت‌های VIP', 'music-wave-vip' ),
			'active'      => $active,
			'description' => $free_mode
				? __( 'کلید اصلی خاموش است: انتشارهای عضویت در حالت پروموشن رایگان‌اند؛ خریدهای تکی ووکامرس و تحویل امن فعال‌اند.', 'music-wave-vip' )
				: ( $active
					? sprintf(
						/* translators: %1$s: delivery mode label, %2$d: active VIP plans count. */
						__( 'متصل شد: تحویل %1$s فعال است و %2$d طرح اشتراک VIP پیکربندی شده است.', 'music-wave-vip' ),
						'local' === $configured['delivery_provider'] ? __( 'محلی', 'music-wave-vip' ) : __( 'راه‌دورِ امضاشده', 'music-wave-vip' ),
						$plans
					)
					: __( 'ذخیره‌سازی محلی خارج از ریشهٔ وب، سرور دانلود امضاشده با HMAC و اشتراک‌های زمان‌دار ووکامرس.', 'music-wave-vip' ) ),
			'url'         => self::page_url(),
			'action'      => __( 'باز کردن تنظیمات VIP', 'music-wave-vip' ),
		);
		foreach ( $cards as $index => $existing ) {
			if ( is_array( $existing ) && isset( $existing['id'] ) && 'music-wave-vip' === $existing['id'] ) {
				$cards[ $index ] = $card;
				return $cards;
			}
		}
		$cards[] = $card;
		return $cards;
	}
}
