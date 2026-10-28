<?php
/**
 * Guided setup and diagnostics administration page.
 *
 * Final clean version – demo catalog fully removed per user request:
 * - No DemoContentImporter dependency
 * - No import/remove demo handlers
 * - No demo transient notices
 * - No demo UI section
 *
 * Refactored for SOLID:
 * - SRP: checks building extracted to Concerns\DiagnosticsChecks
 * - DRY: shared render helpers
 *
 * @package ManaCore\MusicWave\Core\Admin
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Admin;

use ManaCore\MusicWave\Core\Admin\Concerns\DiagnosticsChecks;
use ManaCore\MusicWave\Core\Migrations\MigrationRunner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DiagnosticsPage {

	private const PAGE = 'music-wave-setup';

	/** @var string */
	private $page_hook = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_page(): void {
		$hook            = add_submenu_page(
			'edit.php?post_type=mw_release',
			__( 'راه‌اندازی MusicWave', 'music-wave-core' ),
			__( 'راه‌اندازی و عیب‌یابی', 'music-wave-core' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
		$this->page_hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * @param string|null $hook_suffix Current admin screen hook suffix.
	 */
	public function enqueue_assets( $hook_suffix = '' ): void {
		AdminAssets::enqueue_settings_style( self::PAGE, is_string( $hook_suffix ) ? $hook_suffix : '', $this->page_hook );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->register_help_tabs();

		$checks  = DiagnosticsChecks::build();
		$summary = DiagnosticsChecks::summarize( $checks );

		echo '<div class="wrap mw-settings"><h1>' . esc_html__( 'راه‌اندازی و عیب‌یابی MusicWave', 'music-wave-core' ) . '</h1>';
		echo '<p class="mw-settings__lead">' . esc_html__( 'قبل از وارد کردن اطلاعات کاتالوگ یا باز کردن فروشگاه برای مشتریان، اطمینان حاصل کنید که محیط آماده است.', 'music-wave-core' ) . '</p>';

		$this->render_summary_banner( $summary );
		$this->render_quick_start();
		$this->render_environment_checks( $checks );
		$this->render_system_info();

		echo '<div class="mw-settings__actions">';
		echo '<a class="button" href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'باز کردن سلامت سایت WordPress', 'music-wave-core' ) . '</a>';
		echo '<a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=mw_release&page=music-wave-settings' ) ) . '">' . esc_html__( 'مرکز کنترل MusicWave را باز کنید', 'music-wave-core' ) . '</a>';
		echo '</div></div>';
	}

	/** @param array<string, int|bool> $summary */
	private function render_summary_banner( array $summary ): void {
		$ready              = (bool) $summary['ready'];
		$total              = (int) $summary['total'];
		$passed             = (int) $summary['passed'];
		$required_failed    = (int) $summary['required_failed'];
		$recommended_failed = (int) $summary['recommended_failed'];

		echo '<div class="mw-setup-banner' . ( $ready ? '' : ' mw-setup-banner--attention' ) . '">';
		echo '<span class="dashicons ' . esc_attr( $ready ? 'dashicons-yes-alt' : 'dashicons-warning' ) . '" aria-hidden="true"></span><div>';
		if ( $ready ) {
			echo '<strong>' . esc_html__( 'الزامات هسته MusicWave برآورده شده است', 'music-wave-core' ) . '</strong>';
			/* translators: %1$d: value 1, %2$d: value 2, %d: value 3. */
			echo '<small>' . esc_html( sprintf( __( 'بررسی‌های محیطی %1$d از %2$d انجام شد.', 'music-wave-core' ), $passed, $total ) );
			if ( $recommended_failed > 0 ) {
				/* translators: %d: value 1. */
				echo ' ' . esc_html( sprintf( __( 'توصیه اختیاری %d هنوز نیاز به بررسی دارد.', 'music-wave-core' ), $recommended_failed ) );
			}
			echo '</small>';
		} else {
			/* translators: %d: value 1, %1$d: value 2, %2$d: value 3. */
			echo '<strong>' . esc_html( sprintf( __( 'بررسی‌های لازم: %d مورد به توجه نیاز دارند.', 'music-wave-core' ), $required_failed ) ) . '</strong>';
			/* translators: %1$d: value 1, %2$d: value 2. */
			echo '<small>' . esc_html( sprintf( __( 'بررسی‌های محیطی %1$d از %2$d انجام شد. قبل از راه‌اندازی فروشگاه، مواردی را که در زیر «لازم است» مشخص شده‌اند حل کنید.', 'music-wave-core' ), $passed, $total ) ) . '</small>';
		}
		echo '</div><div class="mw-setup-banner__actions">';
		if ( $ready ) {
			echo '<a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=mw_release' ) ) . '">' . esc_html__( 'ایجاد اولین انتشار', 'music-wave-core' ) . '</a>';
		} else {
			echo '<a class="button button-primary" href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'بررسی در سلامت سایت', 'music-wave-core' ) . '</a>';
		}
		echo '</div></div>';
	}

	private function render_quick_start(): void {
		$steps = array(
			array( __( 'ایجاد اولین انتشار', 'music-wave-core' ), __( 'قطعه، آلبوم یا پادکست را با تصویر جلد، فایل پیش‌نمایش و قیمت اضافه کنید.', 'music-wave-core' ), admin_url( 'post-new.php?post_type=mw_release' ), 'dashicons-format-audio' ),
			array( __( 'بررسی انواع انتشار و هنرمندان', 'music-wave-core' ), __( 'طبقه‌بندی‌ها و نمایه‌های هنرمند مورد استفاده در کاتالوگ را تأیید کنید.', 'music-wave-core' ), admin_url( 'edit-tags.php?taxonomy=mw_release_type&post_type=mw_release' ), 'dashicons-category' ),
			array( __( 'ذخیره تنظیمات پیوندهای یکتا', 'music-wave-core' ), __( 'برای URLهای کاتالوگ، هنرمند و بایگانی قابل خواندن از یک ساختار غیر پیش‌فرض استفاده کنید.', 'music-wave-core' ), admin_url( 'options-permalink.php' ), 'dashicons-admin-links' ),
			array( __( 'پیکربندی تحویل حفاظت‌شده', 'music-wave-core' ), __( 'MusicWave VIP را فعال کنید و قبل از فعال کردن دانلودهای خصوصی، یک پوشهٔ حفاظت‌شده تنظیم کنید.', 'music-wave-core' ), admin_url( 'edit.php?post_type=mw_release&page=music-wave-vip' ), 'dashicons-lock' ),
		);

		echo '<div class="mw-settings__panel"><h2>' . esc_html__( 'شروع سریع', 'music-wave-core' ) . '</h2>';
		echo '<div class="mw-settings__grid mw-settings__grid--steps">';
		foreach ( $steps as $step ) {
			echo '<a class="mw-management-link" href="' . esc_url( (string) $step[2] ) . '"><span class="dashicons ' . esc_attr( (string) $step[3] ) . '" aria-hidden="true"></span><span><strong>' . esc_html( (string) $step[0] ) . '</strong><small>' . esc_html( (string) $step[1] ) . '</small></span></a>';
		}
		echo '</div></div>';
	}

	/** @param array<int, array<string, bool|string>> $checks */
	private function render_environment_checks( array $checks ): void {
		$groups = array(
			'required'    => __( 'بررسی‌های لازم', 'music-wave-core' ),
			'recommended' => __( 'بررسی‌های پیشنهادی', 'music-wave-core' ),
		);
		foreach ( $groups as $severity => $heading ) {
			echo '<div class="mw-settings__panel"><h2>' . esc_html( (string) $heading ) . '</h2>';
			foreach ( $checks as $check ) {
				if ( $check['severity'] !== $severity ) {
					continue;
				}
				$this->render_check_row( $check );
			}
			echo '</div>';
		}
	}

	/** @param array<string, bool|string> $check */
	private function render_check_row( array $check ): void {
		$passed   = (bool) $check['passed'];
		$required = 'required' === $check['severity'];
		if ( $passed ) {
			$state = 'active';
			$label = __( 'آماده', 'music-wave-core' );
		} elseif ( $required ) {
			$state = 'attention';
			$label = __( 'نیاز به توجه دارد', 'music-wave-core' );
		} else {
			$state = 'warn';
			$label = __( 'بررسی', 'music-wave-core' );
		}
		echo '<div class="mw-status-row"><span class="mw-status mw-status--' . esc_attr( $state ) . '">' . esc_html( $label ) . '</span>';
		echo '<div><strong>' . esc_html( (string) $check['label'] ) . '</strong><small>' . esc_html( (string) $check['detail'] ) . '</small></div>';
		if ( ! $passed && '' !== (string) $check['action_url'] ) {
			echo '<a class="button button-small mw-check-action" href="' . esc_url( (string) $check['action_url'] ) . '">' . esc_html( (string) $check['action_label'] ) . '</a>';
		}
		echo '</div>';
	}

	private function render_system_info(): void {
		$stats = array(
			array( MUSIC_WAVE_CORE_VERSION, __( 'هسته MusicWave', 'music-wave-core' ), 'dashicons-controls-play' ),
			array( (string) get_option( MigrationRunner::OPTION, '0.0.0' ), __( 'طرح‌وارهٔ پایگاه داده', 'music-wave-core' ), 'dashicons-database' ),
			array( get_bloginfo( 'version' ), __( 'WordPress', 'music-wave-core' ), 'dashicons-wordpress' ),
			array( PHP_VERSION, __( 'PHP', 'music-wave-core' ), 'dashicons-performance' ),
		);
		echo '<div class="mw-settings__panel"><h2>' . esc_html__( 'عکس فوری سیستم', 'music-wave-core' ) . '</h2>';
		echo '<div class="mw-settings__grid mw-settings__grid--stats">';
		foreach ( $stats as $stat ) {
			echo '<div class="mw-stat"><span class="dashicons ' . esc_attr( (string) $stat[2] ) . '" aria-hidden="true"></span><div><strong>' . esc_html( (string) $stat[0] ) . '</strong><span>' . esc_html( (string) $stat[1] ) . '</span></div></div>';
		}
		echo '</div></div>';
	}

	private function register_help_tabs(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! is_object( $screen ) || ! method_exists( $screen, 'add_help_tab' ) ) {
			return;
		}
		$screen->add_help_tab(
			array(
				'id'      => 'music-wave-setup-guide',
				'title'   => __( 'راهنمای راه‌اندازی', 'music-wave-core' ),
				'content' =>
					'<p>' . esc_html__( 'این صفحه شما را در راه‌اندازی فروشگاه MusicWave راهنمایی می‌کند:', 'music-wave-core' ) . '</p>' .
					'<ul>' .
					'<li>' . esc_html__( 'خلاصهٔ آمادگی — آیا همهٔ بررسی‌های محیطی لازم انجام شده‌اند یا خیر.', 'music-wave-core' ) . '</li>' .
					'<li>' . esc_html__( 'شروع سریع — چهار گام نخست: ایجاد انتشار، بررسی طبقه‌بندی، ذخیرهٔ پیوندهای یکتا و پیکربندی تحویل حفاظت‌شده.', 'music-wave-core' ) . '</li>' .
					'<li>' . esc_html__( 'بررسی‌های محیطی — موارد لازم برای راه‌اندازی را مسدود می‌کنند. موارد توصیه‌شده صرفاً جنبهٔ راهنمایی دارند.', 'music-wave-core' ) . '</li>' .
					'<li>' . esc_html__( 'عکس فوری سیستم — نسخه‌ها در یک نگاه برای عیب‌یابی.', 'music-wave-core' ) . '</li>' .
					'</ul>',
			)
		);
		$screen->add_help_tab(
			array(
				'id'      => 'music-wave-setup-diagnostics',
				'title'   => __( 'راهنما و تشخیص', 'music-wave-core' ),
				'content' =>
					'<p>' . esc_html__( 'همین بررسی‌های محیطی در صفحه سلامت سایت WordPress همراه با گزارش‌های سلامت ارائه‌دهنده یکپارچه‌سازی MusicWave نیز اجرا می‌شوند.', 'music-wave-core' ) . '</p>' .
					'<p><a class="button" href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'باز کردن سلامت سایت', 'music-wave-core' ) . '</a> <a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=mw_release&page=music-wave-settings' ) ) . '">' . esc_html__( 'باز کردن مرکز کنترل', 'music-wave-core' ) . '</a></p>',
			)
		);
	}
}
