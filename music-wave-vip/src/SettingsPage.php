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
				'i18n'      => array(
					'provisioning'  => __( 'در حال ایجاد پوشه، ایمن‌سازی (.htaccess / web.config) و اسکن فایل‌ها…', 'music-wave-vip' ),
					'provisionDone' => __( 'پوشهٔ خصوصی با موفقیت ایمن‌سازی و فهرست فایل‌ها همگام‌سازی شد.', 'music-wave-vip' ),
					'uploading'     => __( 'در حال بارگذاری در مخزن خصوصی و محاسبه هش SHA-256…', 'music-wave-vip' ),
					'uploadDone'    => __( 'فایل با موفقیت در مخزن خصوصی ذخیره شد:', 'music-wave-vip' ),
					'uploadFailed'  => __( 'بارگذاری فایل در مخزن خصوصی ناموفق بود.', 'music-wave-vip' ),
					'copied'        => __( 'کپی شد!', 'music-wave-vip' ),
					'copyId'        => __( 'کپی شناسه', 'music-wave-vip' ),
					'deleteAsset'   => __( 'حذف', 'music-wave-vip' ),
					'confirmDelete' => __( 'تأیید حذف قطعی؟', 'music-wave-vip' ),
					'emptyVault'    => __( 'هنوز هیچ فایل حفاظت‌شده‌ای در مخزن خصوصی ثبت نشده است.', 'music-wave-vip' ),
				),
			)
		);
	}

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
		add_settings_section( 'music_wave_vip_delivery', __( 'تحویل حفاظت‌شده', 'music-wave-vip' ), array( $this, 'delivery_section' ), 'music-wave-vip' );
		$this->field( 'delivery_provider', __( 'ارائه‌دهندهٔ تحویل', 'music-wave-vip' ), 'delivery_provider_field' );
		$this->field( 'protected_root', __( 'پوشهٔ فایل‌های حفاظت‌شده', 'music-wave-vip' ), 'root_field' );
		$this->field( 'remote_base_url', __( 'نشانی پایهٔ میزبان راه‌دور', 'music-wave-vip' ), 'remote_base_url_field' );
		$this->field( 'remote_path_prefix', __( 'پیشوند مسیر راه‌دور', 'music-wave-vip' ), 'remote_path_prefix_field' );
		$this->field( 'remote_signing_secret', __( 'راز امضای راه‌دور', 'music-wave-vip' ), 'remote_signing_secret_field' );
		$this->field( 'remote_signature_param', __( 'پارامتر query امضا', 'music-wave-vip' ), 'remote_signature_param_field' );
		$this->field( 'remote_expires_param', __( 'پارامتر query انقضا', 'music-wave-vip' ), 'remote_expires_param_field' );
		$this->field( 'remote_ttl', __( 'طول عمر نشانی راه‌دور', 'music-wave-vip' ), 'remote_ttl_field' );
		$this->field( 'remote_allowed_hosts', __( 'میزبان‌های مجاز اضافی برای تغییرمسیر', 'music-wave-vip' ), 'remote_allowed_hosts_field' );
		$this->field( 'remote_key_id', __( 'شناسهٔ کلید امضا', 'music-wave-vip' ), 'remote_key_id_field' );
		$this->field( 'sendfile_mode', __( 'شتاب‌دهی سرور', 'music-wave-vip' ), 'sendfile_mode_field' );
		$this->field( 'xaccel_prefix', __( 'پیشوند داخلی X-Accel', 'music-wave-vip' ), 'xaccel_prefix_field' );

		add_settings_section( 'music_wave_vip_membership', __( 'آداپترهای عضویت و مجوز دسترسی', 'music-wave-vip' ), array( $this, 'membership_section' ), 'music-wave-vip' );
		$this->field( 'membership_sources', __( 'منابع عضویت', 'music-wave-vip' ), 'membership_sources_field', 'music_wave_vip_membership' );
		$this->field( 'plan_rows', __( 'محصولات طرح VIP', 'music-wave-vip' ), 'plan_rows_field', 'music-wave-vip_membership' );
		$this->field( 'promote_vip_role', __( 'ارتقای نقش VIP', 'music-wave-vip' ), 'promote_vip_role_field', 'music_wave_vip_membership' );
	}

	private function field( string $id, string $label, string $callback, string $section = 'music_wave_vip_delivery' ): void {
		add_settings_field( $id, $label, array( $this, $callback ), 'music-wave-vip', $section );
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
		$clear_secret      = ! empty( $value['clear_remote_signing_secret'] );
		$purge_retired     = ! empty( $value['purge_retired_remote_keys'] );
		$submitted_secret  = isset( $value['remote_signing_secret'] ) && is_scalar( $value['remote_signing_secret'] )
			? trim( (string) $value['remote_signing_secret'] )
			: '';
		unset( $value['mwvip_full_form'], $value['mwvip_controls_unlocked'], $value['mwvip_active_tab'], $value['clear_remote_signing_secret'], $value['purge_retired_remote_keys'] );

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
		if ( $clear_secret ) {
			\ManaCore\MusicWave\Vip\Security\RemoteSigningKeyring::clear_all();
			$settings['remote_signing_secret'] = '';
		} elseif ( '' !== $submitted_secret && ! \ManaCore\MusicWave\Vip\Security\RemoteSigningKeyring::has_constant_secret() ) {
			$raw_stored   = get_option( VipSettings::OPTION, array() );
			$legacy_sec   = is_array( $raw_stored ) && isset( $raw_stored['remote_signing_secret'] ) && is_scalar( $raw_stored['remote_signing_secret'] )
				? (string) $raw_stored['remote_signing_secret']
				: '';
			$legacy_kid   = is_array( $raw_stored ) && isset( $raw_stored['remote_key_id'] ) && is_scalar( $raw_stored['remote_key_id'] )
				? (string) $raw_stored['remote_key_id']
				: '';
			$assigned_kid = \ManaCore\MusicWave\Vip\Security\RemoteSigningKeyring::store_active_key(
				$submitted_secret,
				(string) $settings['remote_key_id'],
				! $purge_retired,
				$legacy_sec,
				$legacy_kid
			);
			if ( '' !== $assigned_kid && '' === (string) $settings['remote_key_id'] ) {
				$settings['remote_key_id'] = $assigned_kid;
			}
			// Do not duplicate the secret into the autoloaded option row.
			$settings['remote_signing_secret'] = '';
		} elseif ( ! \ManaCore\MusicWave\Vip\Security\RemoteSigningKeyring::has_constant_secret() ) {
			// If a keyring active key is already stored in non-autoloaded storage, keep autoloaded option clean.
			$keyring_state = \ManaCore\MusicWave\Vip\Security\RemoteSigningKeyring::load();
			if ( '' !== $keyring_state['active_kid'] ) {
				$settings['remote_signing_secret'] = '';
			}
		}
		if ( $purge_retired ) {
			\ManaCore\MusicWave\Vip\Security\RemoteSigningKeyring::purge_retired_keys();
		}
		$effective_key    = \ManaCore\MusicWave\Vip\Security\RemoteSigningKeyring::resolve_active_key( $settings );
		$effective_secret = $effective_key['secret'];

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

		if ( 'remote_redirect' === $settings['delivery_provider'] && ( '' === $settings['remote_base_url'] || '' === $effective_secret ) ) {
			add_settings_error(
				VipSettings::OPTION,
				'remote_incomplete',
				__( 'حالت تغییرمسیر راه‌دور هم به نشانی پایهٔ HTTPS و هم به راز امضا (حداقل ۳۲ کاراکتر) نیاز دارد. تا پیکربندی هر دو، تحویل با رد امن پیش‌فرض باقی می‌ماند.', 'music-wave-vip' ),
				'warning'
			);
		}
		if ( '' !== $effective_secret && strlen( $effective_secret ) < 32 ) {
			add_settings_error(
				VipSettings::OPTION,
				'weak_secret',
				__( 'راز امضای راه‌دور کوتاه‌تر از ۳۲ نویسه است. تا ذخیرهٔ راز قوی‌تر، نشانی امضاشده صادر نمی‌شود.', 'music-wave-vip' ),
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
		echo '<p><strong>' . esc_html__( 'فایل‌های حفاظت‌شده کجا قرار می‌گیرند و چگونه تحویل داده می‌شوند.', 'music-wave-vip' ) . '</strong></p>';
		echo '<p class="muted">' . esc_html__( 'حالت محلی فایل‌ها را خارج از ریشهٔ عمومی وب نگه می‌دارد و با PHP (پشتیبانی کامل HTTP Range) یا شتاب‌دهی وب‌سرور (X-Sendfile / Nginx X-Accel-Redirect) پخش می‌کند. حالت راه‌دور نشانی‌های کوتاه‌عمر HTTPS با امضای HMAC-SHA256 برای سرور دانلود، Cloudflare، R2 یا CDN می‌سازد.', 'music-wave-vip' ) . '</p>';
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
		return false;
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
		$value = VipSettings::all()['delivery_provider'];
		echo '<select name="' . esc_attr( VipSettings::OPTION ) . '[delivery_provider]" id="mwvip-delivery-provider" style="min-width:280px;">';
		foreach ( array(
			'local'           => __( 'پوشهٔ حفاظت‌شدهٔ محلی خارج از ریشهٔ وب (پیشنهادشده)', 'music-wave-vip' ),
			'remote_redirect' => __( 'تغییرمسیر راه‌دور امضاشده با HTTPS HMAC (سرور دانلود / CDN)', 'music-wave-vip' ),
		) as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $value, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>' . VipSettingsUi::tooltip( __( 'حالت محلی فایل‌ها را از دیسک خصوصی می‌فرستد؛ حالت راه‌دور نشانی کوتاه‌عمر امضاشده با HMAC-SHA256 صادر می‌کند.', 'music-wave-vip' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
		echo '<input class="regular-text code" type="url" inputmode="url" placeholder="https://downloads.example.com/files" name="' . esc_attr( VipSettings::OPTION ) . '[remote_base_url]" value="' . esc_attr( $value ) . '" style="min-width:420px;">' . VipSettingsUi::tooltip( __( 'باید HTTPS باشد. میزبان نشانی پایه خودکار در فهرست مجاز قرار می‌گیرد.', 'music-wave-vip' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function remote_path_prefix_field(): void {
		$value = (string) VipSettings::all()['remote_path_prefix'];
		echo '<input class="regular-text code" type="text" placeholder="musicwave" name="' . esc_attr( VipSettings::OPTION ) . '[remote_path_prefix]" value="' . esc_attr( $value ) . '">';
	}

	public function remote_signing_secret_field(): void {
		$settings    = VipSettings::all();
		$summary     = \ManaCore\MusicWave\Vip\Security\RemoteSigningKeyring::public_summary( $settings );
		$from_const  = 'constant' === $summary['source'];
		$has         = $summary['has_secret'];
		$placeholder = $from_const
			? __( 'از ثابت MUSIC_WAVE_VIP_REMOTE_SECRET در wp-config.php خوانده می‌شود', 'music-wave-vip' )
			: ( $has ? __( 'راز ذخیره‌شده — برای چرخش امن کلید جدید وارد کنید یا خالی بگذارید', 'music-wave-vip' ) : __( 'ایجاد با دکمهٔ «ایجاد» یا openssl rand -hex 32', 'music-wave-vip' ) );
		echo '<div class="mwvip-secret-row">';
		echo '<input class="regular-text code" type="password" autocomplete="new-password" id="mwvip-remote-secret" name="' . esc_attr( VipSettings::OPTION ) . '[remote_signing_secret]" value="" placeholder="' . esc_attr( $placeholder ) . '" ' . disabled( $from_const, true, false ) . ' style="min-width:360px;">';
		echo '<button type="button" class="button" id="mwvip-toggle-secret" ' . disabled( $from_const, true, false ) . ' aria-label="' . esc_attr__( 'نمایش/پنهان‌کردن راز', 'music-wave-vip' ) . '"><span class="dashicons dashicons-visibility"></span></button>';
		echo '<button type="button" class="button" id="mwvip-gen-secret" ' . disabled( $from_const, true, false ) . '>' . esc_html__( 'ایجاد کلید امن ۶۴ کاراکتری', 'music-wave-vip' ) . '</button>';
		echo '</div>';
		if ( $from_const ) {
			echo '<p class="description" style="color:#00a32a;"><span class="dashicons dashicons-lock"></span> ' . esc_html__( 'راز امضا با ثابت MUSIC_WAVE_VIP_REMOTE_SECRET در wp-config.php قفل شده است (امن‌ترین حالت — خارج از پایگاه داده).', 'music-wave-vip' ) . '</p>';
		} elseif ( $has ) {
			echo '<p class="description">' . esc_html__( 'راز در گزینهٔ غیرخودکار (autoload=no) ذخیره می‌شود. ذخیرهٔ کلید جدید به‌طور خودکار کلید قبلی را در وضعیت بازنشسته (retired) نگه می‌دارد تا لینک‌های در حال دانلود قطع نشوند.', 'music-wave-vip' ) . '</p>';
			if ( ! empty( $summary['retired_kids'] ) ) {
				echo '<p><label><input type="checkbox" name="' . esc_attr( VipSettings::OPTION ) . '[purge_retired_remote_keys]" value="1"> ' . esc_html(
					sprintf(
						/* translators: %s: comma-separated retired key IDs. */
						__( 'حذف کلیدهای بازنشسته پس از همگام‌سازی CDN (%s)', 'music-wave-vip' ),
						implode( ', ', $summary['retired_kids'] )
					)
				) . '</label></p>';
			}
			echo '<p><label><input type="checkbox" name="' . esc_attr( VipSettings::OPTION ) . '[clear_remote_signing_secret]" value="1"> ' . esc_html__( 'پاک‌سازی کامل همهٔ کلیدهای امضای ذخیره‌شده', 'music-wave-vip' ) . '</label></p>';
		}
		if ( $has && ! $summary['secret_strong'] ) {
			echo '<p style="color:#d63638;"><span class="dashicons dashicons-warning"></span> ' . esc_html__( 'رمز فعلی کوتاه‌تر از ۳۲ کاراکتر است. تا ذخیرهٔ رمز قوی‌تر، نشانی امضاشده صادر نمی‌شود.', 'music-wave-vip' ) . '</p>';
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
			'none'      => __( 'پخش جریانی مستقیم PHP با پشتیبانی کامل HTTP Range (پیش‌فرض)', 'music-wave-vip' ),
			'xsendfile' => __( 'Apache / LiteSpeed — هدر X-Sendfile', 'music-wave-vip' ),
			'xaccel'    => __( 'Nginx — هدر X-Accel-Redirect', 'music-wave-vip' ),
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
		$settings      = VipSettings::all();
		$remote_active = 'remote_redirect' === (string) $settings['delivery_provider'];
		$module_on     = 'enabled' === (string) $settings['module_enabled'];
		$diagnostics   = ( new ProtectedDeliveryDiagnostics() )->snapshot( $settings );

		echo '<div class="wrap" id="mwvip-settings">';
		$this->render_header( $module_on );
		settings_errors( VipSettings::OPTION );
		$this->render_preflight_notice();
		$this->render_plan_action_notice();
		$this->render_tabs();
		echo '<form action="options.php" method="post" id="mwvip-form">';
		settings_fields( self::OPTION_GROUP );
		echo '<input type="hidden" name="' . esc_attr( VipSettings::OPTION ) . '[mwvip_full_form]" value="1">';
		echo '<input type="hidden" name="' . esc_attr( VipSettings::OPTION ) . '[mwvip_controls_unlocked]" value="1" data-mwvip-controls-unlocked>';

		$this->render_general_panel();
		$this->render_delivery_panel( $settings, $remote_active, $diagnostics );
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
	 * Upgraded `تحویل حفاظت‌شده` panel:
	 * 1. Live Health & 6-Check Preflight Matrix + 1-Click Auto-Provision/Harden/Sync
	 * 2. Integrated Private Vault File Uploader, Instant Search, Copy `vip:<hash>` & Safe Delete
	 * 3. Local / Remote HTTPS HMAC Engine Configuration + Live Signed URL Preview
	 * 4. Apache X-Sendfile / Nginx X-Accel-Redirect Offload Helper
	 *
	 * @param array<string, mixed> $settings      Normalized VIP settings.
	 * @param bool                 $remote_active Whether `remote_redirect` is active.
	 * @param array<string, mixed> $diagnostics   Live diagnostic snapshot.
	 */
	private function render_delivery_panel( array $settings, bool $remote_active, array $diagnostics ): void {
		$local     = isset( $diagnostics['local'] ) && is_array( $diagnostics['local'] ) ? $diagnostics['local'] : array();
		$remote    = isset( $diagnostics['remote'] ) && is_array( $diagnostics['remote'] ) ? $diagnostics['remote'] : array();
		$checks    = isset( $local['checks'] ) && is_array( $local['checks'] ) ? $local['checks'] : array();
		$is_ready  = ! empty( $diagnostics['overall_ready'] );
		$storage   = new ProtectedAssetStorage();
		$inventory = $storage->list_assets( '', 1, 25 );

		echo '<div class="mwvip-panel" id="mwvip-panel-delivery" role="tabpanel" aria-labelledby="mwvip-tab-delivery" data-panel="delivery">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'مرکز کنترل تحویل حفاظت‌شده', 'music-wave-vip' ) . '</h2>';
		$this->delivery_section();

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

		// 3. Provider & Remote HMAC Settings.
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'ارائه‌دهندهٔ تحویل', 'music-wave-vip' ) . '</th><td>';
		$this->delivery_provider_field();
		echo '</td></tr>';
		echo '<tr class="mwvip-local-row"><th>' . esc_html__( 'پوشهٔ فایل‌های حفاظت‌شده', 'music-wave-vip' ) . '</th><td>';
		$this->root_field();
		echo '</td></tr>';
		echo '<tr class="mwvip-remote-row" ' . ( $remote_active ? '' : 'style="display:none;"' ) . '><th>' . esc_html__( 'نشانی پایهٔ میزبان راه‌دور', 'music-wave-vip' ) . '</th><td>';
		$this->remote_base_url_field();
		echo '</td></tr>';
		echo '<tr class="mwvip-remote-row" ' . ( $remote_active ? '' : 'style="display:none;"' ) . '><th>' . esc_html__( 'پیشوند مسیر راه‌دور', 'music-wave-vip' ) . '</th><td>';
		$this->remote_path_prefix_field();
		echo '</td></tr>';
		echo '<tr class="mwvip-remote-row" ' . ( $remote_active ? '' : 'style="display:none;"' ) . '><th>' . esc_html__( 'راز امضای راه‌دور', 'music-wave-vip' ) . '</th><td>';
		$this->remote_signing_secret_field();
		echo '</td></tr>';
		echo '<tr class="mwvip-remote-row" ' . ( $remote_active ? '' : 'style="display:none;"' ) . '><th>' . esc_html__( 'پارامتر query امضا', 'music-wave-vip' ) . '</th><td>';
		$this->remote_signature_param_field();
		echo '</td></tr>';
		echo '<tr class="mwvip-remote-row" ' . ( $remote_active ? '' : 'style="display:none;"' ) . '><th>' . esc_html__( 'پارامتر query انقضا', 'music-wave-vip' ) . '</th><td>';
		$this->remote_expires_param_field();
		echo '</td></tr>';
		echo '<tr class="mwvip-remote-row" ' . ( $remote_active ? '' : 'style="display:none;"' ) . '><th>' . esc_html__( 'طول عمر نشانی راه‌دور', 'music-wave-vip' ) . '</th><td>';
		$this->remote_ttl_field();
		echo '</td></tr>';
		echo '<tr class="mwvip-remote-row" ' . ( $remote_active ? '' : 'style="display:none;"' ) . '><th>' . esc_html__( 'میزبان‌های مجاز اضافی برای تغییرمسیر', 'music-wave-vip' ) . '</th><td>';
		$this->remote_allowed_hosts_field();
		echo '</td></tr>';
		echo '<tr class="mwvip-remote-row" ' . ( $remote_active ? '' : 'style="display:none;"' ) . '><th>' . esc_html__( 'شناسهٔ کلید امضا', 'music-wave-vip' ) . '</th><td>';
		$this->remote_key_id_field();
		if ( ! empty( $remote['sample_download_url'] ) ) {
			echo '<p class="description" style="margin-top:10px;"><strong>' . esc_html__( 'پیش‌نمایش زنده لینک امضاشده آزمایشی:', 'music-wave-vip' ) . '</strong></p>';
			echo '<code class="mwvip-sample-url">' . esc_html( (string) $remote['sample_download_url'] ) . '</code>';
		}
		echo '</td></tr>';
		echo '</table>';

		echo '<h3>' . esc_html__( 'شتاب‌دهی وب‌سرور', 'music-wave-vip' ) . '</h3>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'شتاب‌دهی سرور', 'music-wave-vip' ) . '</th><td>';
		$this->sendfile_mode_field();
		echo '</td></tr>';
		echo '<tr class="mwvip-xaccel-row" ' . ( 'xaccel' === (string) $settings['sendfile_mode'] ? '' : 'style="display:none;"' ) . '><th>' . esc_html__( 'پیشوند داخلی X-Accel', 'music-wave-vip' ) . '</th><td>';
		$this->xaccel_prefix_field();
		echo '</td></tr>';
		echo '</table></div>';
	}

	private function render_membership_panel( bool $module_on ): void {
		echo '<div class="mwvip-panel" id="mwvip-panel-membership" role="tabpanel" aria-labelledby="mwvip-tab-membership" data-panel="membership">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'عضویت و مجوز دسترسی', 'music-wave-vip' ) . '</h2>';
		echo '<div class="notice notice-info inline" id="mwvip-membership-note" style="margin:0 0 16px;' . ( $module_on ? 'display:none;' : '' ) . '"><p><span class="dashicons dashicons-info-outline" style="color:#2271b1;vertical-align:middle;"></span> ' . esc_html__( 'اعمال قوانین VIP در حال حاضر خاموش است؛ تمام کنترل‌های زیر برای پیش‌پیکربندی طرح‌ها و منابع عضویت باز هستند و تغییرات شما ذخیره می‌شود، اما تا روشن کردن کلید اصلی در تب «عمومی» قفل عضویت روی سایت اعمال نخواهد شد.', 'music-wave-vip' ) . '</p></div>';
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
		echo '<p style="margin:0 0 8px;color:#50575e;">' . esc_html__( 'به‌صورت پیش‌فرض خاموش و ایمن است: هنگام حذف موقت یا به‌روزرسانی دستی افزونه، تمام اشتراک‌های پرداخت‌شدهٔ مشتریان، جدول mw_vip_assets، گزارش حسابرسی و تنظیمات حفظ می‌شوند. تنها در صورت روشن کردن این گزینه، هنگام حذف کامل افزونه تمام داده‌های پایگاه داده پاک خواهند شد (فایل‌های روی دیسک هرگز پاک نمی‌شوند).', 'music-wave-vip' ) . '</p>';
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
		echo '<a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=mw_release&page=music-wave-settings&tab=access' ) ) . '"><span class="dashicons dashicons-forms"></span> ' . esc_html__( 'مدیریت حالت دسترسی انبوه کاتالوگ', 'music-wave-vip' ) . '</a>';
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
