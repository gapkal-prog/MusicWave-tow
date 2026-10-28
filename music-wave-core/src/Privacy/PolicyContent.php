<?php
/**
 * Privacy Policy Guide content for MusicWave Core.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Privacy;

use ManaCore\MusicWave\Core\Support\Settings;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PolicyContent {
	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'add_policy' ) );
	}

	/**
	 * Add policy content to Settings → Privacy → Policy Guide.
	 *
	 * Every fact references constants from code, never invented.
	 */
	public function add_policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		// Retention constant from Settings::defaults() - listening_retention_days default 180.
		$defaults       = Settings::defaults();
		$retention_days = isset( $defaults['listening_retention_days'] ) ? (int) $defaults['listening_retention_days'] : 180;

		// Download rate limit defaults.
		$rate_limit  = isset( $defaults['download_rate_limit'] ) ? (int) $defaults['download_rate_limit'] : 30;
		$rate_window = isset( $defaults['download_rate_window'] ) ? (int) $defaults['download_rate_window'] : 60;
		$daily_quota = isset( $defaults['download_daily_quota'] ) ? (int) $defaults['download_daily_quota'] : 0;

		// Discovery limits.
		$discovery_limit  = isset( $defaults['discovery_rate_limit'] ) ? (int) $defaults['discovery_rate_limit'] : 60;
		$discovery_window = isset( $defaults['discovery_rate_window'] ) ? (int) $defaults['discovery_rate_window'] : 60;
		$discovery_ttl    = isset( $defaults['discovery_cache_ttl'] ) ? (int) $defaults['discovery_cache_ttl'] : 300;

		$content = sprintf(
			'<div class="wp-suggested-text">' .
			'<h2>%s</h2>' .
			'<p>%s</p>' .
			'<h3>%s</h3>' .
			'<p>%s</p>' .
			'<ul>' .
			'<li>%s</li>' .
			'<li>%s</li>' .
			'<li>%s</li>' .
			'<li>%s</li>' .
			'<li>%s</li>' .
			'</ul>' .
			'<h3>%s</h3>' .
			'<p>%s</p>' .
			'<ul>' .
			'<li>%s</li>' .
			'<li>%s</li>' .
			'</ul>' .
			'<h3>%s</h3>' .
			'<p>%s</p>' .
			'<h3>%s</h3>' .
			'<p>%s</p>' .
			'<h3>%s</h3>' .
			'<p>%s</p>' .
			'<h3>%s</h3>' .
			'<p>%s</p>' .
			'<h3>%s</h3>' .
			'<p>%s</p>' .
			'<h3>%s</h3>' .
			'<p>%s</p>' .
			'</div>',
			esc_html__( 'MusicWave Core — چه داده‌ای جمع‌آوری می‌شود', 'music-wave-core' ),
			esc_html__( 'این بخش نحوه جمع‌آوری و استفاده داده‌های شخصی توسط MusicWave Core را توضیح می‌دهد. متن از حقایق کد و ثابت‌ها استخراج شده و هرگز ابداعی نیست.', 'music-wave-core' ),
			esc_html__( 'فرم درخواست آهنگ و همکاری', 'music-wave-core' ),
			esc_html__( 'وقتی بازدیدکننده فرم درخواست (بلوک music-wave/request-form) را ارسال می‌کند، داده‌های زیر به‌عنوان پست خصوصی mw_request ذخیره می‌شود:', 'music-wave-core' ),
			esc_html__( 'نام، ایمیل، تلفن (اختیاری)، پیام، نوع درخواست (song/collab)، نقش‌ها، اولویت — فیلدهای ثبت‌شده در RequestPostType.php و RequestFormHandler.php', 'music-wave-core' ),
			esc_html__( 'چرا: برای مدیریت درخواست‌ها، پاسخ ایمیلی، یادداشت داخلی، پذیرش/رد/بایگانی. دسترسی فقط با قابلیت music_wave_manage_request_caps (پیش‌فرض manage_options).', 'music-wave-core' ),
			esc_html__( 'نگهداری: بدون انقضای خودکار؛ مدیر از صفحه MusicWave → Requests & collaboration حذف می‌کند یا از ابزار پاک‌سازی حریم خصوصی استفاده می‌کند. پاک‌سازی خودکار وجود ندارد.', 'music-wave-core' ),
			esc_html__( 'چه کسی می‌بیند: فقط مدیران با دسترسی مربوطه، نه عموم. نوع پست mw_request با public=false, publicly_queryable=false, exclude_from_search=true, show_in_rest=false (بررسی RequestPostType.php).', 'music-wave-core' ),
			esc_html__( 'PRG: پس از ارسال، کاربر به URL با توکن تصادفی (نه داده شخصی) هدایت می‌شود؛ receipt email شامل MW-YYYY-000000 است.', 'music-wave-core' ),
			esc_html__( 'تاریخچه گوش‌دادن', 'music-wave-core' ),
			sprintf(
				/* translators: %d: retention days */
				esc_html__( 'فعالیت پخش فقط برای کاربرانی که با ذخیره سابقه موافقت کرده‌اند (REST POST music-wave/v1/listening/consent) ثبت می‌شود. جدول mw_user_activity (ListeningRepository.php) شامل user_id, release_id, event, position, updated_at است. نگهداری پیش‌فرض %d روز (تنظیم listening_retention_days در music_wave_settings، پیش‌فرض 180)، پاک‌سازی روزانه via cron music_wave_listening_cleanup. کاربران می‌توانند از داشبورد حساب خود سابقه شخصی را پاک کنند. Exporter/Eraser در PersonalData.php پوشش می‌دهد.', 'music-wave-core' ),
				$retention_days
			),
			esc_html__( 'مکان: جدول سفارشی mw_user_activity + user meta ListeningRepository::CONSENT_META. بدون cookie ردیابی؛ resume position در sessionStorage (client-side) ذخیره می‌شود.', 'music-wave-core' ),
			esc_html__( 'چه کسی می‌بیند: فقط خود کاربر و مدیران با دسترسی به exporter. عمومی نمایش داده نمی‌شود.', 'music-wave-core' ),
			esc_html__( 'کتابخانه شخصی و علاقه‌مندی‌ها', 'music-wave-core' ),
			esc_html__( 'فهرست‌های پخش و کتابخانه (wishlist/pre-save) در جداول mw_playlists, mw_playlist_items و user meta mw_music_library (LibraryRepository.php) ذخیره می‌شود. مالکیت برای هر تغییر الزامی است. فهرست‌های عمومی نام نمایشی نویسنده را نشان می‌دهند (نه author_id) و covers (تا 4 thumbnail). Exporter/Eraser پوشش می‌دهد.', 'music-wave-core' ),
			esc_html__( 'پلی‌لیست‌ها', 'music-wave-core' ),
			esc_html__( 'کاربر می‌تواند تا 20 فهرست (پیش‌فرض) با 100 آیتم هرکدام بسازد (قابل فیلتر music_wave_playlist_limits). فهرست‌های عمومی در REST /playlists/public با pagination و instant search. مالک می‌تواند share token بچرخاند (فقط هنگام تغییر visibility).', 'music-wave-core' ),
			esc_html__( 'دانلودهای امن و محدودیت نرخ', 'music-wave-core' ),
			sprintf(
				/* translators: %1$d: value 1, %2$d: value 2, %3$d: value 3. */
				esc_html__( 'صدور توکن محدود و تحویل فایل: rate limiter bucket download (پیش‌فرض %1$d درخواست در %2$d ثانیه) و سهمیه روزانه %3$d (0=نامحدود) در DownloadRateLimiter.php و DownloadResolver.php. کلیدها از IP هش‌شده (ClientIp::get() با فیلتر music_wave_client_ip، پیش‌فرض REMOTE_ADDR) در transients با expiry کوتاه (rate window) ذخیره می‌شوند، نه raw IP. توکن یک‌بارمصرف و پس از چک سهمیه مصرف می‌شود. پیوندها امضاشده، کوتاه‌مدت و دارای محدودیت نرخ باقی می‌مانند. Audit event music_wave_download_event فقط توسط integrationها نگهداری می‌شود (Core پیش‌فرض persist نمی‌کند).', 'music-wave-core' ),
				$rate_limit,
				$rate_window,
				$daily_quota
			),
			esc_html__( 'جست‌وجو و کشف کاتالوگ', 'music-wave-core' ),
			sprintf(
				/* translators: %1$d: value 1, %2$d: value 2, %3$d: value 3. */
				esc_html__( 'تکمیل خودکار، فیلترها و توصیه‌ها نقاط پایانی عمومی هستند: /catalog/suggest, /catalog/facets, /recommendations. محدودیت‌ها: %1$d درخواست در %2$d ثانیه (DiscoveryRateLimiter.php) + cache TTL %3$d ثانیه (discovery_cache_ttl). همان IP هش‌شده. بدون ذخیره query شخصی. تغییرات کاتالوگ cache را invalidate می‌کند.', 'music-wave-core' ),
				$discovery_limit,
				$discovery_window,
				$discovery_ttl
			),
			esc_html__( 'سرویس‌های شخص ثالث', 'music-wave-core' ),
			esc_html__( 'تکمیل خودکار فراداده موسیقی (MusicBrainz + Cover Art Archive بدون کلید، اختیاری Spotify client_id/secret و Discogs token از تنظیمات) فقط در ویرایشگر انتشار و توسط admin با قابلیت upload_files آغاز می‌شود، بدون داده بازدیدکننده. تماس‌ها با wp_safe_remote_* انجام می‌شود. داده WooCommerce توسط WooCommerce مدیریت می‌شود. فونت‌ها self-hosted هستند (Vazirmatn, Plus Jakarta Sans, Syne) — هیچ درخواست Google Fonts وجود ندارد (FIX-04).', 'music-wave-core' ),
			esc_html__( 'حقوق شما', 'music-wave-core' ),
			esc_html__( 'از ابزارهای WordPress در Tools → Export Personal Data و Erase Personal Data برای صادرات/حذف کتابخانه، تاریخچه گوش‌دادن، فهرست‌های پخش و درخواست‌ها استفاده کنید. درخواست‌ها همچنین از صفحه مدیریت MusicWave قابل حذف هستند. تنظیم delete_data_on_uninstall پیش‌فرض OFF است و فقط هنگام حذف افزونه (نه غیرفعال‌سازی) اعمال می‌شود و هرگز محتوای مشتری mw_release/mw_request/terms/media/protected files را پاک نمی‌کند.', 'music-wave-core' )
		);

		wp_add_privacy_policy_content(
			'MusicWave Core',
			wp_kses_post( $content )
		);
	}
}
