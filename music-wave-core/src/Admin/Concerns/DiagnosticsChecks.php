<?php
/**
 * Environment checks builder for DiagnosticsPage.
 *
 * Single Responsibility: build the list of environment checks with severity,
 * labels, details and action URLs. Keeps DiagnosticsPage focused on rendering
 * and request handling.
 *
 * @package ManaCore\MusicWave\Core\Admin\Concerns
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Admin\Concerns;

use ManaCore\MusicWave\Core\Downloads\DeliveryCapabilities;
use ManaCore\MusicWave\Core\Migrations\MigrationRunner;
use ManaCore\MusicWave\Core\Support\VipModuleStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DiagnosticsChecks {

	/**
	 * @return array<int, array<string, bool|string>>
	 */
	public static function build(): array {
		$schema_version = (string) get_option( MigrationRunner::OPTION, '0.0.0' );
		$schema_ready   = version_compare( $schema_version, MigrationRunner::LATEST_VERSION, '>=' );
		$theme          = wp_get_theme();
		$theme_ready    = 'musicwave' === $theme->get_stylesheet();
		$theme_name     = (string) $theme->get( 'Name' );
		$woo_ready      = class_exists( 'WooCommerce' );
		$vip_active     = VipModuleStatus::is_active();
		$provider_ready = DeliveryCapabilities::probe()->is_available();
		$permalinks     = '' !== (string) get_option( 'permalink_structure', '' );
		$cron_disabled  = defined( 'DISABLE_WP_CRON' ) && constant( 'DISABLE_WP_CRON' );

		return array(
			array(
				'label'        => __( 'شمای پایگاه داده MusicWave', 'music-wave-core' ),
				'passed'       => $schema_ready,
				'severity'     => 'required',
				'detail'       => $schema_ready
					? sprintf(
						/* translators: %1$s: value 1, %2$s: value 2. */
						__( 'نسخهٔ نصب‌شدهٔ طرح‌واره (%1$s) با افزونهٔ فعلی سازگار است (نسخهٔ %2$s لازم است).', 'music-wave-core' ),
						$schema_version,
						MigrationRunner::LATEST_VERSION
					)
					: sprintf(
						/* translators: %1$s: value 1, %2$s: value 2. */
						__( 'نسخهٔ نصب‌شدهٔ طرح‌واره: %1$s؛ نسخهٔ موردنیاز: %2$s. دفعهٔ بعد که مدیر داشبورد را بارگیری کند، به‌روزرسانی‌ها به‌طور خودکار اجرا می‌شوند.', 'music-wave-core' ),
						$schema_version,
						MigrationRunner::LATEST_VERSION
					),
				'action_url'   => '',
				'action_label' => '',
			),
			array(
				'label'        => __( 'پیوندهای ثابت زیبا', 'music-wave-core' ),
				'passed'       => $permalinks,
				'severity'     => 'required',
				'detail'       => __( 'URLهای انتشار، هنرمند و کاتالوگ به ساختار پیوند ثابت غیر پیش‌فرض نیاز دارند.', 'music-wave-core' ),
				'action_url'   => $permalinks ? '' : admin_url( 'options-permalink.php' ),
				'action_label' => __( 'باز کردن تنظیمات پیوندهای یکتا', 'music-wave-core' ),
			),
			array(
				'label'        => __( 'تم بلوک MusicWave', 'music-wave-core' ),
				'passed'       => $theme_ready,
				'severity'     => 'required',
				'detail'       => $theme_ready
					? __( 'قالب بلوکی همراه، کاتالوگ، هنرمند و قابلیت‌های تجاری را ارائه می‌دهد.', 'music-wave-core' )
					: sprintf(
						/* translators: %s: value 1. */
						__( 'قالب فعال: %s. برای نمایش مطلوب کاتالوگ، قالب همراه MusicWave را فعال کنید.', 'music-wave-core' ),
						'' !== $theme_name ? $theme_name : get_stylesheet()
					),
				'action_url'   => $theme_ready ? '' : admin_url( 'themes.php' ),
				'action_label' => __( 'باز کردن مرورگر تم', 'music-wave-core' ),
			),
			array(
				'label'        => __( 'WooCommerce', 'music-wave-core' ),
				'passed'       => $woo_ready,
				'severity'     => 'required',
				'detail'       => $woo_ready
					? __( 'نگاشت محصول، مالکیت خرید و پرداخت در دسترس است.', 'music-wave-core' )
					: __( 'برای فروش انتشارهای منتشرشده لازم است. محتوای کاتالوگ عمومی رایگان بدون آن کار می‌کند.', 'music-wave-core' ),
				'action_url'   => $woo_ready ? '' : admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ),
				'action_label' => __( 'نصب WooCommerce', 'music-wave-core' ),
			),
			array(
				'label'        => __( 'تحویل حفاظت‌شده', 'music-wave-core' ),
				'passed'       => $provider_ready,
				'severity'     => 'recommended',
				'detail'       => $provider_ready
					? __( 'یک ارائه‌دهنده دارایی‌های حفاظت‌شده غیرشفاف را پس از تصویب بررسی‌های دسترسی، پخش می‌کند.', 'music-wave-core' )
					: ( $vip_active
						? __( 'MusicWave VIP فعال است اما هنوز ارائه‌دهنده تحویل ثبت نشده است. برای فعال کردن دانلودهای حفاظت‌شده، تنظیمات تحویل VIP را ذخیره کنید.', 'music-wave-core' )
						: __( 'پیش از اختصاص دارایی‌های دانلود حفاظت‌شده به انتشارها، MusicWave VIP را فعال کنید.', 'music-wave-core' ) ),
				'action_url'   => $provider_ready ? '' : ( $vip_active ? admin_url( 'edit.php?post_type=mw_release&page=music-wave-vip' ) : admin_url( 'plugins.php' ) ),
				'action_label' => $vip_active ? __( 'تنظیمات MusicWave VIP', 'music-wave-core' ) : __( 'باز کردن صفحهٔ افزونه‌ها', 'music-wave-core' ),
			),
			array(
				'label'        => __( 'داده‌های ساختاریافتهٔ موسیقی', 'music-wave-core' ),
				'passed'       => true,
				'severity'     => 'recommended',
				'detail'       => \ManaCore\MusicWave\Core\Seo\SeoCompatibility::has_competing_plugin()
					? __( 'یک افزونهٔ SEO شناخته‌شده فعال است، بنابراین MusicWave JSON-LD به‌طور پیش‌فرض غیرفعال می‌ماند تا از طرح‌های تکراری جلوگیری شود.', 'music-wave-core' )
					: __( 'MusicWave می‌تواند ضبط موسیقی، آلبوم موسیقی و طرح‌وارهٔ پادکست را منتشر کند. هنگامی‌که هیچ افزونهٔ دیگری طرح موسیقی معادل را منتشر نمی‌کند، آن را از طریق فیلتر مستندشده فعال کنید.', 'music-wave-core' ),
				'action_url'   => '',
				'action_label' => '',
			),
			array(
				'label'        => __( 'نسخه PHP', 'music-wave-core' ),
				'passed'       => version_compare( PHP_VERSION, '8.0', '>=' ),
				'severity'     => 'recommended',
				'detail'       => sprintf(
					/* translators: %s: value 1. */
					__( 'در حال اجرا PHP %s. MusicWave به 7.4 یا جدیدتر نیاز دارد. 8.0 یا جدیدتر برای به‌روزرسانی‌های امنیتی و عملکرد توصیه می‌شود.', 'music-wave-core' ),
					PHP_VERSION
				),
				'action_url'   => '',
				'action_label' => '',
			),
			array(
				'label'        => __( 'پردازش پس زمینه', 'music-wave-core' ),
				'passed'       => ! $cron_disabled,
				'severity'     => 'recommended',
				'detail'       => $cron_disabled
					? __( 'WP-Cron غیرفعال است. MusicWave پاک‌سازی دانلود، اعلان انتشار و نگه‌داری عضویت را زمان‌بندی می‌کند؛ مطمئن شوید یک کار cron واقعی، wp-cron.php را اجرا می‌کند.', 'music-wave-core' )
					: __( 'رویدادهای پاک‌سازی، اعلان و نگه‌داری زمان‌بندی‌شده می‌توانند اجرا شوند.', 'music-wave-core' ),
				'action_url'   => '',
				'action_label' => '',
			),
		);
	}

	/**
	 * @param array<int, array<string, bool|string>> $checks
	 * @return array<string, int|bool>
	 */
	public static function summarize( array $checks ): array {
		$passed             = 0;
		$required_failed    = 0;
		$recommended_failed = 0;
		foreach ( $checks as $check ) {
			if ( $check['passed'] ) {
				++$passed;
				continue;
			}
			if ( 'required' === $check['severity'] ) {
				++$required_failed;
			} else {
				++$recommended_failed;
			}
		}
		return array(
			'total'              => count( $checks ),
			'passed'             => $passed,
			'required_failed'    => $required_failed,
			'recommended_failed' => $recommended_failed,
			'ready'              => 0 === $required_failed,
		);
	}
}
