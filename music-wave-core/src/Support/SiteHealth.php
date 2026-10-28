<?php
/**
 * MusicWave checks surfaced through WordPress Site Health.
 *
 * Refactored:
 * - SRP: test registration vs. individual test logic separated.
 * - DRY: result builder single method, test definitions declarative.
 * - OCP: new tests added via add_tests filter without modifying result logic.
 *
 * @package ManaCore\MusicWave\Core\Support
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Support;

use ManaCore\MusicWave\Core\Migrations\MigrationRunner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteHealth {

	/**
	 * Return Site Health direct test definitions with translatable string literals.
	 *
	 * @return array<string, array{label: string, method: string}>
	 */
	private function test_definitions(): array {
		return array(
			'music_wave_schema'            => array(
				'label'  => __( 'طرح پایگاه داده MusicWave فعلی است', 'music-wave-core' ),
				'method' => 'test_schema',
			),
			'music_wave_permalinks'        => array(
				'label'  => __( 'MusicWave از پیوندهای ثابت زیبا استفاده می‌کند', 'music-wave-core' ),
				'method' => 'test_permalinks',
			),
			'music_wave_download_provider' => array(
				'label'  => __( 'تحویل حفاظت‌شدهٔ MusicWave پیکربندی‌شده است', 'music-wave-core' ),
				'method' => 'test_download_provider',
			),
			'music_wave_download_quota'    => array(
				'label'  => __( 'سهمیه دانلود MusicWave', 'music-wave-core' ),
				'method' => 'test_download_quota',
			),
			'music_wave_view_counter'      => array(
				'label'  => __( 'شمارنده بازدید MusicWave', 'music-wave-core' ),
				'method' => 'test_view_counter',
			),
			'music_wave_structured_data'   => array(
				'label'  => __( 'داده‌های ساختاریافتهٔ MusicWave از طرح‌وارهٔ سئوی تکراری جلوگیری می‌کند', 'music-wave-core' ),
				'method' => 'test_structured_data',
			),
			'music_wave_provider_health'   => array(
				'label'  => __( 'ارائه دهندگان ادغام MusicWave سالم هستند', 'music-wave-core' ),
				'method' => 'test_provider_health',
			),
		);
	}

	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
	}

	/**
	 * @param array<string, array<string, mixed>> $tests
	 * @return array<string, array<string, mixed>>
	 */
	public function add_tests( array $tests ): array {
		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}
		foreach ( $this->test_definitions() as $key => $def ) {
			$tests['direct'][ $key ] = array(
				'label' => $def['label'],
				'test'  => array( $this, $def['method'] ),
			);
		}
		return $tests;
	}

	/** @return array<string, string|array<string, string>> */
	public function test_provider_health(): array {
		$reports  = apply_filters( 'music_wave_provider_health', array() );
		$reports  = is_array( $reports ) ? $reports : array();
		$statuses = array( 'ok', 'misconfigured', 'unreachable', 'rate_limited', 'failed' );
		$broken   = array();
		foreach ( $reports as $slug => $report ) {
			$status = isset( $report['status'] ) && in_array( (string) $report['status'], $statuses, true ) ? (string) $report['status'] : 'failed';
			if ( 'ok' !== $status ) {
				$broken[] = sanitize_key( (string) $slug ) . ' (' . $status . ')';
			}
		}
		$healthy = array() === $broken;
		return $this->result(
			$healthy ? __( 'ارائه دهندگان ادغام MusicWave سالم هستند', 'music-wave-core' ) : __( 'یک ارائه‌دهنده یکپارچه سازی MusicWave یک مشکل را گزارش می‌کند', 'music-wave-core' ),
			$healthy ? 'good' : 'critical',
			$healthy
				? __( 'هیچ عضویت، فراداده، یا ارائه‌دهنده تحویلی مشکلی را گزارش نکرد.', 'music-wave-core' )
				/* translators: %s: value 1. */
				: sprintf( __( 'ارائه دهندگان ناموفق: %s. قطعی ارائه‌دهنده و اعتبارنامه‌های بد در اینجا گزارش می‌شوند به‌جای اینکه در سکوت به‌عنوان «بدون نتیجه» تلقی شوند.', 'music-wave-core' ), implode( ', ', $broken ) ),
			'music_wave_provider_health'
		);
	}

	/** @return array<string, string|array<string, string>> */
	public function test_schema(): array {
		$version = (string) get_option( MigrationRunner::OPTION, '0.0.0' );
		$ready   = version_compare( $version, MigrationRunner::LATEST_VERSION, '>=' );
		return $this->result(
			$ready ? __( 'طرح پایگاه داده MusicWave فعلی است', 'music-wave-core' ) : __( 'طرح پایگاه داده MusicWave نیاز به به‌روز رسانی دارد', 'music-wave-core' ),
			$ready ? 'good' : 'critical',
			$ready ? __( 'فراداده محتوای MusicWave برای نسخهٔ نصب‌شدهٔ افزونه آماده است.', 'music-wave-core' ) : __( 'برای اجرای انتقال طرح‌وارهٔ معلق، به‌عنوان مدیر از صفحهٔ راه‌اندازی MusicWave دیدن کنید.', 'music-wave-core' ),
			'music_wave_schema'
		);
	}

	/** @return array<string, string|array<string, string>> */
	public function test_permalinks(): array {
		$ready = '' !== (string) get_option( 'permalink_structure', '' );
		return $this->result(
			$ready ? __( 'پیوندهای دائمی‌زیبا MusicWave فعال هستند', 'music-wave-core' ) : __( 'MusicWave به پیوندهای ثابت زیبا نیاز دارد', 'music-wave-core' ),
			$ready ? 'good' : 'recommended',
			$ready ? __( 'بایگانی‌های انتشار، هنرمند و طبقه‌بندی می‌توانند از URL‌های متعارف قابل خواندن استفاده کنند.', 'music-wave-core' ) : __( 'برای پشتیبانی از کاتالوگ قابل پیش‌بینی و هنرمندان URL، هر ساختار پیوند ثابت غیر پیش‌فرض را ذخیره کنید.', 'music-wave-core' ),
			'music_wave_permalinks'
		);
	}

	/** @return array<string, string|array<string, string>> */
	public function test_download_provider(): array {
		$caps  = DeliveryCapabilities::describe();
		$ready = $caps['has_protected_provider'];
		return $this->result(
			$ready ? __( 'ارائه‌دهنده تحویل حفاظت‌شده MusicWave در دسترس است', 'music-wave-core' ) : __( 'تحویل پیوند مستقیم HTTPS فعال است؛ ارائه‌دهنده حفاظت‌شده اختیاری در دسترس نیست', 'music-wave-core' ),
			$ready ? 'good' : 'recommended',
			$caps['detail'],
			'music_wave_download_provider'
		);
	}

	/** @return array<string, string|array<string, string>> */
	public function test_download_quota(): array {
		$settings = Settings::all();
		$quota    = isset( $settings['download_daily_quota'] ) ? (int) $settings['download_daily_quota'] : 0;
		$limit    = isset( $settings['download_rate_limit'] ) ? (int) $settings['download_rate_limit'] : 30;
		$window   = isset( $settings['download_rate_window'] ) ? (int) $settings['download_rate_window'] : 60;

		if ( 0 === $quota ) {
			return $this->result(
				__( 'سهمیه دانلود MusicWave: بدون محدودیت روزانه', 'music-wave-core' ),
				'good',
				/* translators: %1$d: value 1, %2$d: value 2. */
				sprintf( __( 'سهمیه روزانه غیرفعال است (0=نامحدود). محدودیت نرخ %1$d درخواست در %2$d ثانیه فعال است. برای محدود کردن exfiltration انبوه، سهمیه را در تنظیمات تحویل و حریم خصوصی فعال کنید.', 'music-wave-core' ), $limit, $window ),
				'music_wave_download_quota'
			);
		}

		return $this->result(
			/* translators: %d: value 1, %1$d: value 2, %2$d: value 3, %3$d: value 4. */
			sprintf( __( 'سهمیه دانلود MusicWave: %d در روز', 'music-wave-core' ), $quota ),
			'recommended',
			/* translators: %1$d: value 1, %2$d: value 2, %3$d: value 3. */
			sprintf( __( 'سهمیه روزانه %1$d تحویل در روز فعال است (فیلتر music_wave_download_daily_quota و تنظیم download_daily_quota). محدودیت نرخ %2$d در %3$d ثانیه نیز اعمال می‌شود. این سهمیه از exfiltration انبوه از طریق حساب به خطر افتاده جلوگیری می‌کند. برای بدون محدودیت، 0 تنظیم کنید.', 'music-wave-core' ), $quota, $limit, $window ),
			'music_wave_download_quota'
		);
	}

	/** @return array<string, string|array<string, string>> */
	public function test_view_counter(): array {
		global $wpdb;
		$table_exists = false;
		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			$table        = $wpdb->prefix . 'mw_release_views';
			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		}
		if ( $table_exists ) {
			return $this->result(
				__( 'شمارنده بازدید MusicWave: جدول جدید فعال است', 'music-wave-core' ),
				'good',
				__( 'جدول mw_release_views وجود دارد، beacon REST POST music-wave/v1/releases/{id}/view با dwell 10 ثانیه (فیلتر music_wave_view_dwell_seconds)، dedupe 10 دقیقه، bot filter و rate limiter bucket views. cron ساعتی music_wave_hourly_view_sync به mw_views meta sync می‌کند.', 'music-wave-core' ),
				'music_wave_view_counter'
			);
		}
		return $this->result(
			__( 'شمارنده بازدید MusicWave: حالت قدیمی meta', 'music-wave-core' ),
			'recommended',
			__( 'جدول mw_release_views هنوز ایجاد نشده است (migration 0.12.0). پس از اجرای migration، شمارنده بدون write per-request کار می‌کند. فعلاً stub قدیمی بدون write است.', 'music-wave-core' ),
			'music_wave_view_counter'
		);
	}

	/** @return array<string, string|array<string, string>> */
	public function test_structured_data(): array {
		$has_competitor = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'AIOSEO_VERSION' );
		return $this->result(
			$has_competitor ? __( 'داده‌های ساختاریافتهٔ MusicWave از موارد تکراری محافظت می‌کند', 'music-wave-core' ) : __( 'داده‌های ساختاریافتهٔ عمومی MusicWave موجود است', 'music-wave-core' ),
			'good',
			$has_competitor ? __( 'یک افزونهٔ SEO شناخته‌شده فعال است، بنابراین MusicWave JSON-LD به‌طور پیش‌فرض غیرفعال می‌ماند.', 'music-wave-core' ) : __( 'MusicWave می‌تواند ضبط موسیقی، آلبوم موسیقی و طرح‌وارهٔ پادکست را از فیلدهای کاتالوگ عمومی منتشر کند.', 'music-wave-core' ),
			'music_wave_structured_data'
		);
	}

	/** @return array<string, string|array<string, string>> */
	private function result( string $label, string $status, string $description, string $test ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'MusicWave', 'music-wave-core' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '<p><a href="' . esc_url( admin_url( 'edit.php?post_type=mw_release&page=music-wave-setup' ) ) . '">' . esc_html__( 'راه‌اندازی MusicWave را باز کنید', 'music-wave-core' ) . '</a></p>',
			'test'        => $test,
		);
	}
}
