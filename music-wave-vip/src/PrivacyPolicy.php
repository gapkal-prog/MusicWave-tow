<?php
/**
 * WordPress Privacy Policy guide disclosure for MusicWave VIP.
 *
 * Discloses VIP grant records, status index metadata, user history, and short-lived
 * audit options stored in user meta and options.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PrivacyPolicy {

	/**
	 * Register suggested privacy policy text on `admin_init`.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'add_suggested_text' ) );
	}

	/**
	 * Push suggested copy to WordPress's privacy guide when available.
	 *
	 * @return void
	 */
	public function add_suggested_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( 'MusicWave VIP', self::content() );
	}

	/**
	 * HTML fragment describing the plugin's data practices.
	 *
	 * @return string
	 */
	public static function content(): string {
		$blocks = array(
			'<h2>' . esc_html__( 'چه داده‌هایی توسط افزونه عضویت VIP MusicWave ذخیره می‌شود', 'music-wave-vip' ) . '</h2>',
			'<p>' . esc_html__( 'هنگام خرید یا اعطای اشتراک VIP، شناسهٔ سفارش ووکامرس، سطح دسترسی، زمان شروع و تاریخ انقضا در متادادهٔ حساب کاربری (mw_vip_plan_grants، mw_vip_effective_until، mw_vip_ever_granted و mw_vip_user_log) نگهداری می‌شود تا دسترسی به انتشارهای ویژه، صفحه مدیریت کاربران و انقضای خودکار بررسی شود.', 'music-wave-vip' ) . '</p>',
			'<p>' . esc_html__( 'هنگام دریافت فایل‌های صوتی یا ZIP حفاظت‌شده، در صورت فعال بودن محدودیت نرخ یا ثبت رویداد امنیتی، شمارنده‌های کوتاه‌مدت در جدول گزینه‌های وردپرس ذخیره می‌شوند و پس از پایان پنجرهٔ زمانی منقضی می‌گردند. در حالت تحویل راه‌دور، نشانی امضاشدهٔ کوتاه‌عمر تنها برای همان درخواست تولید می‌شود.', 'music-wave-vip' ) . '</p>',
			'<p>' . esc_html__( 'در صورت حذف کامل افزونه، تنظیمات، نقش mw_vip و متاداده‌های مجوز عضویت VIP از پایگاه‌داده پاک می‌شوند؛ فایل‌های بارگذاری‌شده در پوشهٔ حفاظت‌شده دست‌نخورده باقی می‌مانند تا محتوای کاتالوگ از بین نرود.', 'music-wave-vip' ) . '</p>',
		);
		return implode( "\n", $blocks );
	}
}
