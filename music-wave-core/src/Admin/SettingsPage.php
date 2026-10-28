<?php
/**
 * MusicWave administration hub and settings page.
 *
 * Refactored for SOLID, DRY and clean code:
 * - SRP: each tab delegated to focused private renderer; form helpers via trait.
 * - DRY: canonical access labels, levels, and defaults delegated to `AccessModePolicy`.
 * - OCP: integration cards filterable, live VIP & protected delivery status bridged cleanly.
 *
 * @package ManaCore\MusicWave\Core\Admin
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Admin;

use ManaCore\MusicWave\Core\Access\AccessModePolicy;
use ManaCore\MusicWave\Core\Admin\Concerns\SettingsFormTrait;
use ManaCore\MusicWave\Core\Catalog\ReleaseArchiveQuery;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Listening\ListeningRepository;
use ManaCore\MusicWave\Core\Migrations\MigrationRunner;
use ManaCore\MusicWave\Core\Playlists\PlaylistRepository;
use ManaCore\MusicWave\Core\Support\Settings;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SettingsPage {

	use SettingsFormTrait;

	private const PAGE = 'music-wave-settings';
	private const TABS = array( 'overview', 'content', 'access', 'delivery', 'integrations', 'manage' );

	/** @var BulkAccessManager */
	private $bulk_access;

	/** @var PlaylistRepository|null */
	private $playlists;

	/** @var ListeningRepository|null */
	private $listening;

	/** @var string */
	private $page_hook = '';

	public function __construct( BulkAccessManager $bulk_access, ?PlaylistRepository $playlists = null, ?ListeningRepository $listening = null ) {
		$this->bulk_access = $bulk_access;
		$this->playlists   = $playlists;
		$this->listening   = $listening;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		$this->bulk_access->register();
	}

	public function add_page(): void {
		$hook            = add_submenu_page(
			'edit.php?post_type=' . ReleasePostType::KEY,
			__( 'تنظیمات MusicWave', 'music-wave-core' ),
			__( 'تنظیمات و نمای کلی', 'music-wave-core' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
		$this->page_hook = is_string( $hook ) ? $hook : '';
	}

	public function register_settings(): void {
		register_setting(
			'music_wave_core',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_and_sync_settings' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Sanitize core settings and keep RequestSettings::chat_enabled synchronized
	 * so both admin settings screens share one single source of truth.
	 *
	 * @param mixed $value Raw core settings payload.
	 * @return array<string, mixed>
	 */
	public function sanitize_and_sync_settings( $value ): array {
		if ( isset( $_POST['mw_request_chat_enabled'] ) && is_scalar( $_POST['mw_request_chat_enabled'] ) && current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by options.php settings_fields('music_wave_core').
			$raw_chat = sanitize_key( wp_unslash( (string) $_POST['mw_request_chat_enabled'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			\ManaCore\MusicWave\Core\Requests\RequestSettings::set_chat_enabled( 'enabled' === $raw_chat );
		}
		return Settings::sanitize( $value );
	}

	public function enqueue_assets( string $hook_suffix ): void {
		AdminAssets::enqueue_settings_style( self::PAGE, $hook_suffix, $this->page_hook );
		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::PAGE === $page || ( '' !== $this->page_hook && $this->page_hook === $hook_suffix ) ) {
			wp_enqueue_style(
				'music-wave-admin-release-meta',
				MUSIC_WAVE_CORE_URL . 'assets/admin-release-meta.css',
				array(),
				MUSIC_WAVE_CORE_VERSION
			);
			wp_enqueue_script(
				'music-wave-admin-release-meta',
				MUSIC_WAVE_CORE_URL . 'assets/admin-release-meta.js',
				array(),
				MUSIC_WAVE_CORE_VERSION,
				true
			);
		}
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab = $this->current_tab();
		$this->register_help_tabs();

		echo '<div class="wrap mw-settings"><h1>' . esc_html__( 'مرکز کنترل MusicWave', 'music-wave-core' ) . '</h1>';
		echo '<p class="mw-settings__lead">' . esc_html__( 'پیش‌فرض‌های کاتالوگ، رفتار دسترسی، محدودیت‌های تحویل، ادغام‌ها و صفحه‌های WordPress را که نمایش را کنترل می‌کنند، مدیریت کنید.', 'music-wave-core' ) . '</p>';
		$this->render_tabs( $tab );
		settings_errors();

		switch ( $tab ) {
			case 'content':
				$this->render_content_settings();
				break;
			case 'access':
				$this->render_access();
				break;
			case 'delivery':
				$this->render_delivery_settings();
				break;
			case 'integrations':
				$this->render_integrations();
				break;
			case 'manage':
				$this->render_management_links();
				break;
			case 'overview':
			default:
				$this->render_overview();
				break;
		}
		echo '</div>';
	}

	private function current_tab(): string {
		$tab = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation, sanitized
		return in_array( $tab, self::TABS, true ) ? $tab : 'overview';
	}

	private function render_tabs( string $active ): void {
		$tabs = array(
			'overview'     => __( 'نمای کلی', 'music-wave-core' ),
			'content'      => __( 'محتوا و نمایش', 'music-wave-core' ),
			'access'       => __( 'دسترسی', 'music-wave-core' ),
			'delivery'     => __( 'تحویل و حریم خصوصی', 'music-wave-core' ),
			'integrations' => __( 'ادغام', 'music-wave-core' ),
			'manage'       => __( 'پیوندهای مدیریت', 'music-wave-core' ),
		);
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'تنظیمات MusicWave', 'music-wave-core' ) . '">';
		foreach ( $tabs as $key => $label ) {
			$url   = add_query_arg(
				array(
					'post_type' => ReleasePostType::KEY,
					'page'      => self::PAGE,
					'tab'       => $key,
				),
				admin_url( 'edit.php' )
			);
			$class = $active === $key ? ' nav-tab-active' : '';
			echo '<a class="nav-tab' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	// ------------------------------------------------------------------
	// Overview – stats + system status + quick actions
	// ------------------------------------------------------------------
	private function render_overview(): void {
		$counts    = wp_count_posts( ReleasePostType::KEY );
		$published = isset( $counts->publish ) ? absint( $counts->publish ) : 0;
		$drafts    = isset( $counts->draft ) ? absint( $counts->draft ) : 0;
		$theme     = wp_get_theme();

		echo '<div class="mw-settings__grid mw-settings__grid--stats">';
		$this->stat( __( 'انتشارات منتشرشده', 'music-wave-core' ), number_format_i18n( $published ), 'dashicons-album' );
		$this->stat( __( 'انتشارهای پیش‌نویس', 'music-wave-core' ), number_format_i18n( $drafts ), 'dashicons-edit-page' );
		if ( null !== $this->playlists ) {
			$this->stat( __( 'فهرست پخش عمومی', 'music-wave-core' ), number_format_i18n( $this->playlists->count_public() ), 'dashicons-playlist' );
		}
		if ( null !== $this->listening ) {
			$this->stat( __( 'رویدادهای شنیداری', 'music-wave-core' ), number_format_i18n( $this->listening->count_all() ), 'dashicons-controls-play' );
		}
		$this->stat( __( 'قالب فعال', 'music-wave-core' ), (string) $theme->get( 'Name' ), 'dashicons-admin-appearance' );
		$this->stat( __( 'نسخهٔ اصلی', 'music-wave-core' ), MUSIC_WAVE_CORE_VERSION, 'dashicons-update' );
		echo '</div>';

		echo '<div class="mw-settings__columns"><section class="mw-settings__panel"><h2>' . esc_html__( 'اقدامات سریع', 'music-wave-core' ) . '</h2>';
		$this->action_link( admin_url( 'post-new.php?post_type=' . ReleasePostType::KEY ), __( 'افزودن انتشار', 'music-wave-core' ), __( 'محتوای کاتالوگ را ایجاد و پیکربندی کنید.', 'music-wave-core' ) );
		$this->action_link( admin_url( 'edit.php?post_type=' . ReleasePostType::KEY ), __( 'مرور همهٔ انتشارها', 'music-wave-core' ), __( 'وضعیت آمادگی و دسترسی را بررسی کنید.', 'music-wave-core' ) );
		if ( defined( 'MUSIC_WAVE_VIP_FILE' ) ) {
			$this->action_link( admin_url( 'edit.php?post_type=' . ReleasePostType::KEY . '&page=music-wave-vip&tab=delivery' ), __( 'تحویل حفاظت‌شده و طرح‌های VIP', 'music-wave-core' ), __( 'مدیریت پوشهٔ خصوصی، امضای HMAC و طرح‌های اشتراک VIP.', 'music-wave-core' ) );
		}
		$this->action_link( \ManaCore\MusicWave\Core\Requests\RequestsAdminPage::url( array( 'view' => 'settings' ) ), __( 'درخواست‌ها و تنظیمات چت', 'music-wave-core' ), __( 'مدیریت درخواست‌های کاربران و فعال‌سازی چت و گفتگو.', 'music-wave-core' ) );
		$this->action_link( admin_url( 'edit.php?post_type=' . ReleasePostType::KEY . '&page=music-wave-setup' ), __( 'راه‌اندازی و عیب‌یابی', 'music-wave-core' ), __( 'طرح‌واره، تحویل، سئو و سلامت محیط را بررسی کنید.', 'music-wave-core' ) );
		echo '</section><section class="mw-settings__panel"><h2>' . esc_html__( 'وضعیت سیستم', 'music-wave-core' ) . '</h2>';
		$this->status_row( __( 'WooCommerce', 'music-wave-core' ), class_exists( 'WooCommerce' ), __( 'نگاشت دوطرفه محصول و دسترسی خرید', 'music-wave-core' ) );
		$schema_version = (string) get_option( MigrationRunner::OPTION, '0.0.0' );
		$this->status_row(
			__( 'طرح کاتالوگ', 'music-wave-core' ),
			version_compare( $schema_version, MigrationRunner::LATEST_VERSION, '>=' ),
			sprintf(
				/* translators: 1: installed schema version, 2: required schema version. */
				__( '%1$s نصب‌شده است. %2$s موردنیاز است', 'music-wave-core' ),
				$schema_version,
				MigrationRunner::LATEST_VERSION
			)
		);
		$delivery_caps = \ManaCore\MusicWave\Core\Support\DeliveryCapabilities::describe();
		$this->status_row( __( 'تحویل حفاظت‌شده و مستقیم', 'music-wave-core' ), $delivery_caps['has_protected_provider'], $delivery_caps['summary'] );
		$this->status_row( __( 'ارائه‌دهنده عضویت', 'music-wave-core' ), has_filter( 'music_wave_membership_provider' ), __( 'تصمیمات دسترسی مبتنی بر عضویت', 'music-wave-core' ) );
		$this->status_row( __( 'تم بلوک همراه', 'music-wave-core' ), 'musicwave' === $theme->get_stylesheet(), __( 'قالب‌ها و سبک‌های MusicWave', 'music-wave-core' ) );
		echo '</section></div>';
	}

	// ------------------------------------------------------------------
	// Content settings – defaults, archive, slider, persistent nav, SEO
	// ------------------------------------------------------------------
	private function render_content_settings(): void {
		$settings = Settings::all();
		$toggles  = array(
			'enabled'  => __( 'فعال شد', 'music-wave-core' ),
			'disabled' => __( 'غیرفعال', 'music-wave-core' ),
		);

		$this->settings_form_start();
		echo '<section class="mw-settings__panel"><h2>' . esc_html__( 'پیش‌فرض‌های انتشار جدید', 'music-wave-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'این مقادیر هنگام ایجاد هر انتشار جدید (چه در ویرایشگر بلوکی و چه کلاسیک/REST) به‌صورت خودکار ثبت می‌شوند. انتشارهای قبلی هرگز بازنویسی نمی‌شوند.', 'music-wave-core' ) . '</p><table class="form-table" role="presentation">';
		$this->select_row( 'default_access_mode', __( 'حالت دسترسی پیش‌فرض', 'music-wave-core' ), (string) $settings['default_access_mode'], $this->access_labels(), __( 'زمانی که انتشارهای جدید باید به‌صورت پیش‌فرض نیازمند اشتراک یا خرید باشند، حالت موردنظر را انتخاب کنید.', 'music-wave-core' ) );
		$default_levels = (string) $settings['default_membership_levels'];
		echo '<tr><th scope="row"><label for="mw-setting-default_membership_levels">' . esc_html__( 'سطح عضویت پیش‌فرض انتشارهای جدید', 'music-wave-core' ) . '</label></th><td>';
		echo '<input class="regular-text" type="text" id="mw-setting-default_membership_levels" name="' . esc_attr( Settings::OPTION ) . '[default_membership_levels]" value="' . esc_attr( $default_levels ) . '">';
		$this->render_level_picker_chips( 'mw-setting-default_membership_levels', AccessModePolicy::sanitize_levels( $default_levels ) );
		echo '<p class="description">' . esc_html__( 'هنگام ایجاد انتشار با حالت «اشتراک VIP» یا «خرید تکی یا اشتراک VIP»، این کلیدها خودکار روی انتشار ثبت می‌شوند (پیش‌فرض: mw_vip برای همه مشترکین فعال VIP).', 'music-wave-core' ) . '</p></td></tr>';
		$this->number_row( 'default_preview_duration', __( 'مدت پیش‌نمایش پیش‌فرض', 'music-wave-core' ), (int) $settings['default_preview_duration'], 10, 120, __( 'ثانیه؛ هر انتشار می‌تواند این مقدار را به‌طور مستقل تغییر دهد.', 'music-wave-core' ) );
		echo '</table></section>';

		echo '<section class="mw-settings__panel"><h2>' . esc_html__( 'آرشیو کاتالوگ', 'music-wave-core' ) . '</h2><table class="form-table" role="presentation">';
		$this->number_row( 'archive_per_page', __( 'انتشار در هر صفحه', 'music-wave-core' ), (int) $settings['archive_per_page'], 1, 100, __( 'فقط آرشیو عمومی MusicWave را کنترل می‌کند.', 'music-wave-core' ) );
		$this->select_row( 'archive_default_sort', __( 'مرتب‌سازی پیش‌فرض', 'music-wave-core' ), (string) $settings['archive_default_sort'], ReleaseArchiveQuery::sort_options(), __( 'بازدیدکنندگان همچنان می‌توانند حالت مرتب‌سازی دیگری را انتخاب کنند.', 'music-wave-core' ) );
		$this->select_row( 'show_same_artist_releases', __( 'انتشارهای بیشتر از همین هنرمند', 'music-wave-core' ), (string) $settings['show_same_artist_releases'], $toggles, __( 'انتشارهای دیگری را نشان می‌دهد که در صفحات انتشار، هنرمند مشترکی دارند.', 'music-wave-core' ) );
		$this->select_row( 'show_similar_releases', __( 'انتشارهای مشابه', 'music-wave-core' ), (string) $settings['show_similar_releases'], $toggles, __( 'از اصطلاحات ژانر، حال‌وهوا، و نوع انتشار مشترک بدون افشای فرادادهٔ دسترسی خصوصی استفاده می‌کند.', 'music-wave-core' ) );
		$this->number_row( 'related_items_per_section', __( 'موارد مرتبط در هر بخش', 'music-wave-core' ), (int) $settings['related_items_per_section'], 2, 12, __( 'هر بخش مرتبط را به جست‌وجویی قابل‌پیش‌بینی و کم‌هزینه محدود می‌کند.', 'music-wave-core' ) );
		echo '</table></section>';

		echo '<section class="mw-settings__panel"><h2>' . esc_html__( 'نوار لغزنده انتشار ویژه', 'music-wave-core' ) . '</h2><table class="form-table" role="presentation">';
		$this->select_row( 'slider_enabled', __( 'اسلایدر', 'music-wave-core' ), (string) $settings['slider_enabled'], $toggles, __( 'لغزنده‌های انتشار MusicWave را به‌صورت جهانی فعال یا غیرفعال می‌کند. بلوک‌های منفرد همچنان می‌توانند این تنظیم را به ارث ببرند یا لغو کنند.', 'music-wave-core' ) );
		$this->select_row( 'slider_autoplay', __( 'پخش خودکار', 'music-wave-core' ), (string) $settings['slider_autoplay'], $toggles, __( 'پخش خودکار برای بازدیدکنندگانی که حرکت کمتر را ترجیح می‌دهند، به‌طور خودکار غیرفعال می‌شود.', 'music-wave-core' ) );
		$this->select_row( 'slider_loop', __( 'حلقه', 'music-wave-core' ), (string) $settings['slider_loop'], $toggles, __( 'پس از آخرین گروه از انتشارها به ابتدا بازمی‌گردد.', 'music-wave-core' ) );
		$this->select_row( 'slider_pause_on_hover', __( 'مکث هنگام قرارگیری نشانگر یا فوکوس', 'music-wave-core' ), (string) $settings['slider_pause_on_hover'], $toggles, __( 'از حرکت خودکار در زمانی که بازدیدکننده در حال تعامل با لغزنده است جلوگیری می‌کند.', 'music-wave-core' ) );
		$this->select_row( 'slider_show_arrows', __( 'فلش‌های ناوبری', 'music-wave-core' ), (string) $settings['slider_show_arrows'], $toggles, __( 'کنترل‌های قبلی و بعدی را نشان می‌دهد.', 'music-wave-core' ) );
		$this->select_row( 'slider_show_dots', __( 'نقاط صفحه‌بندی', 'music-wave-core' ), (string) $settings['slider_show_dots'], $toggles, __( 'گروه اسلاید فعلی و کنترل‌های پیمایش مستقیم را نشان می‌دهد.', 'music-wave-core' ) );
		$this->number_row( 'slider_interval', __( 'فاصلهٔ پخش خودکار', 'music-wave-core' ), (int) $settings['slider_interval'], 2000, 20000, __( 'میلی ثانیه بین حرکات خودکار.', 'music-wave-core' ) );
		$this->number_row( 'slider_items', __( 'تعداد انتشارهای لغزنده', 'music-wave-core' ), (int) $settings['slider_items'], 3, 12, __( 'حداکثر تعداد کارت‌های انتشار که توسط هر لغزنده درخواست شده است.', 'music-wave-core' ) );
		echo '</table></section>';

		echo '<section class="mw-settings__panel"><h2>' . esc_html__( 'پخش مداوم', 'music-wave-core' ) . '</h2><table class="form-table" role="presentation">';
		$this->select_row( 'persistent_navigation', __( 'به پخش در صفحات ادامه دهید', 'music-wave-core' ), (string) $settings['persistent_navigation'], $toggles, __( 'محتوای صفحه را در پس‌زمینه تغییر می‌دهد تا پخش‌کننده پیش‌نمایش موسیقی در حین مرور بازدیدکنندگان به پخش ادامه دهد. صفحه‌های سبد خرید، تسویه‌حساب و مدیریت هرگز رهگیری نمی‌شوند.', 'music-wave-core' ) );
		echo '</table></section>';

		echo '<section class="mw-settings__panel"><h2>' . esc_html__( 'سئو و داده‌های ساختاریافته', 'music-wave-core' ) . '</h2><table class="form-table" role="presentation">';
		$this->select_row(
			'json_ld_mode',
			__( 'داده‌های ساختاریافتهٔ موسیقی', 'music-wave-core' ),
			(string) $settings['json_ld_mode'],
			array(
				'auto'     => __( 'تشخیص خودکار تعارض', 'music-wave-core' ),
				'enabled'  => __( 'همیشه فعال', 'music-wave-core' ),
				'disabled' => __( 'غیرفعال', 'music-wave-core' ),
			),
			__( 'حالت خودکار وقتی یک افزونهٔ SEO شناخته‌شده فعال باشد، JSON-LD هسته را غیرفعال می‌کند.', 'music-wave-core' )
		);
		echo '</table></section>';

		$request_chat_on = ( new \ManaCore\MusicWave\Core\Requests\RequestSettings() )->chat_enabled();
		echo '<section class="mw-settings__panel"><h2>' . esc_html__( 'پیگیری درخواست‌ها و گفتگو', 'music-wave-core' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="mw-request-chat-enabled">' . esc_html__( 'چت و گفتگو با کاربر', 'music-wave-core' ) . '</label></th><td><select id="mw-request-chat-enabled" name="mw_request_chat_enabled"><option value="enabled"' . selected( $request_chat_on, true, false ) . '>' . esc_html__( 'فعال شد', 'music-wave-core' ) . '</option><option value="disabled"' . selected( $request_chat_on, false, false ) . '>' . esc_html__( 'غیرفعال', 'music-wave-core' ) . '</option></select><p class="description">' . esc_html__( 'در صورت فعال بودن، کاربر در تب «پیگیری درخواست‌ها» علاوه بر مشاهده متن پاسخ داده شده، می‌تواند به گفتگو ادامه دهد.', 'music-wave-core' ) . '</p></td></tr>';
		echo '</table></section>';
		submit_button( __( 'ذخیره تنظیمات محتوا', 'music-wave-core' ) );
		echo '</form>';
	}

	// ------------------------------------------------------------------
	// Access – membership behavior, messages, bulk
	// ------------------------------------------------------------------
	private function render_access(): void {
		$notice = $this->bulk_access->consume_notice();
		if ( is_array( $notice ) ) {
			$type = isset( $notice['type'] ) ? sanitize_html_class( (string) $notice['type'] ) : 'info';
			echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>';
		}
		$job = $this->bulk_access->active_job();
		if ( is_array( $job ) ) {
			echo '<section class="mw-settings__panel mw-settings__panel--job"><h2>' . esc_html__( 'عملیات دسترسی گروهی در حال انجام است', 'music-wave-core' ) . '</h2>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: number of releases updated, 2: number of releases skipped. */
					__( '%1$d مورد به‌روزرسانی شد و %2$d مورد رد شد. پردازش بستهٔ امن بعدی را ادامه دهید.', 'music-wave-core' ),
					(int) $job['updated'],
					(int) $job['skipped']
				)
			) . '</p>';
			echo '<div class="mw-settings__actions">';
			$this->job_form( 'music_wave_bulk_access_continue', __( 'ادامهٔ مرحلهٔ بعد', 'music-wave-core' ), 'button button-primary' );
			$this->job_form( 'music_wave_bulk_access_cancel', __( 'کار را لغو کنید', 'music-wave-core' ), 'button button-secondary' );
			echo '</div></section>';
		}

		$settings = Settings::all();
		$this->settings_form_start();
		$absent = \ManaCore\MusicWave\Core\Access\MembershipAbsencePolicy::sanitize_mode( (string) $settings['membership_absent_behavior'] );
		echo '<section class="mw-settings__panel"><h2>' . esc_html__( 'رفتار ماژول عضویت', 'music-wave-core' ) . '</h2>';
		$this->render_live_vip_status_banner();
		echo '<p>' . esc_html__( 'وقتی هیچ ماژول عضویتی مانند MusicWave VIP فعال نیست، سیاست زیر تعیین می‌کند انتشارهای «فقط عضویت» و «خرید یا عضویت» چگونه ارزیابی شوند (انتشارهای «فقط خرید تکی» و «محدود» هرگز باز نمی‌شوند).', 'music-wave-core' ) . '</p><table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'در حالی که هیچ ماژول عضویت فعال نیست', 'music-wave-core' ) . '</th><td>';
		echo '<label style="display:block;margin-bottom:8px;"><input type="radio" name="' . esc_attr( Settings::OPTION ) . '[membership_absent_behavior]" value="allow" ' . checked( $absent, 'allow', false ) . '> <strong>' . esc_html__( 'دسترسی آزاد (سازگار با پیش‌فرض قبلی)', 'music-wave-core' ) . '</strong> — ' . esc_html__( 'انتشارهای صرفاً عضویتی بدون افزونه VIP باز می‌مانند، اما در حالت دوگانه «خرید یا اشتراک» اگر محصول WooCommerce متصل باشد مسیر خرید تکی الزامی می‌ماند.', 'music-wave-core' ) . '</label>';
		echo '<label style="display:block;margin-bottom:8px;"><input type="radio" name="' . esc_attr( Settings::OPTION ) . '[membership_absent_behavior]" value="open_download" ' . checked( $absent, 'open_download', false ) . '> <strong>' . esc_html__( 'دانلود آزاد کامل محتوای عضویتی (Open Download)', 'music-wave-core' ) . '</strong> — ' . esc_html__( 'هر دو حالت «فقط عضویت» و «خرید یا اشتراک» در غیاب افزونه عضویت بدون نیاز به خرید برای دانلود و پخش باز می‌شوند (انتشارهای «فقط خرید تکی» همچنان پولی می‌مانند).', 'music-wave-core' ) . '</label>';
		echo '<label style="display:block;"><input type="radio" name="' . esc_attr( Settings::OPTION ) . '[membership_absent_behavior]" value="deny" ' . checked( $absent, 'deny', false ) . '> <strong>' . esc_html__( 'مسدودسازی ایمن (Fail-Closed)', 'music-wave-core' ) . '</strong> — ' . esc_html__( 'تا زمان فعال‌شدن یک ماژول عضویت، دسترسی عضویتی قفل می‌ماند و فقط خریداران محصول متصل دسترسی دارند.', 'music-wave-core' ) . '</label>';
		echo '</td></tr>';
		echo '</table></section>';

		echo '<section class="mw-settings__panel"><h2>' . esc_html__( 'پیام‌های دسترسی مشتری', 'music-wave-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'برای استفاده از پیش‌فرض MusicWave ترجمه‌شده، یک فیلد خالی بگذارید. متن سفارشی قبل از خروجی ایمن‌سازی می‌شود.', 'music-wave-core' ) . '</p><table class="form-table" role="presentation">';
		$this->text_row( 'purchase_message', __( 'پیام خرید تکی', 'music-wave-core' ), (string) $settings['purchase_message'] );
		$this->text_row( 'purchase_cta_label', __( 'برچسب دکمه خرید تکی', 'music-wave-core' ), (string) $settings['purchase_cta_label'] );
		$this->text_row( 'membership_message', __( 'پیام اشتراک VIP', 'music-wave-core' ), (string) $settings['membership_message'] );
		$this->text_row( 'membership_cta_label', __( 'برچسب دکمه اشتراک VIP', 'music-wave-core' ), (string) $settings['membership_cta_label'] );
		$this->url_row( 'membership_cta_url', __( 'نشانی صفحهٔ اشتراک VIP (خالی = تشخیص خودکار)', 'music-wave-core' ), (string) $settings['membership_cta_url'] );
		$this->text_row( 'purchase_or_membership_message', __( 'پیام حالت دوگانه (خرید تکی یا اشتراک VIP)', 'music-wave-core' ), (string) $settings['purchase_or_membership_message'] );
		$this->text_row( 'login_required_message', __( 'پیام حالت «فقط کاربران ثبت‌نام‌شده»', 'music-wave-core' ), (string) $settings['login_required_message'] );
		$this->text_row( 'restricted_message', __( 'پیام دسترسی محدودشده', 'music-wave-core' ), (string) $settings['restricted_message'] );
		$this->text_row( 'access_granted_message', __( 'پیام دسترسی اعطاشده', 'music-wave-core' ), (string) $settings['access_granted_message'] );
		echo '</table></section>';
		submit_button( __( 'ذخیره تنظیمات و پیام‌های دسترسی', 'music-wave-core' ) );
		echo '</form>';

		if ( null === $job ) {
			$this->render_bulk_form();
		}
	}

	private function render_live_vip_status_banner(): void {
		$has_provider   = has_filter( 'music_wave_membership_provider' );
		$effective_mode = \ManaCore\MusicWave\Core\Access\MembershipAbsencePolicy::current_mode();
		$vip_url        = admin_url( 'edit.php?post_type=' . ReleasePostType::KEY . '&page=music-wave-vip' );
		if ( ! $has_provider ) {
			$mode_summary = array(
				'allow'         => __( 'سیاست مؤثر فعلی: «دسترسی آزاد» — انتشارهای عضویتی باز هستند و انتشارهای دوگانه دارای محصول، مسیر خرید تکی را حفظ می‌کنند.', 'music-wave-core' ),
				'open_download' => __( 'سیاست مؤثر فعلی: «دانلود آزاد کامل» — انتشارهای عضویتی و دوگانه بدون نیاز به VIP قابل دانلود هستند.', 'music-wave-core' ),
				'deny'          => __( 'سیاست مؤثر فعلی: «مسدودسازی ایمن» — انتشارهای عضویتی تا فعال‌شدن افزونه عضویت قفل هستند.', 'music-wave-core' ),
			);
			$summary_text = isset( $mode_summary[ $effective_mode ] ) ? $mode_summary[ $effective_mode ] : $mode_summary['allow'];
			echo '<div class="notice notice-warning inline" style="margin:0 0 12px;"><p><strong>' . esc_html__( 'وضعیت فعلی: هیچ ماژول عضویتی فعال نیست.', 'music-wave-core' ) . '</strong> ' . esc_html( $summary_text ) . '</p></div>';
			return;
		}

		$vip_opts = get_option( 'music_wave_vip_settings', array() );
		$vip_on   = ! is_array( $vip_opts ) || ! isset( $vip_opts['module_enabled'] ) || 'disabled' !== (string) $vip_opts['module_enabled'];
		$status   = $vip_on
			? __( 'ماژول عضویت فعال است: این تنظیم فعلاً بدون اثر است، زیرا تصمیم‌های عضویت مستقیماً توسط ارائه‌دهنده فعال ارزیابی می‌شوند.', 'music-wave-core' )
			: __( 'ماژول MusicWave VIP نصب است، اما کلید «اعمال قوانین VIP» خاموش (حالت پروموشن باز) است؛ اولویت با کلید اصلی VIP است.', 'music-wave-core' );

		echo '<div class="notice notice-' . esc_attr( $vip_on ? 'info' : 'warning' ) . ' inline" style="margin:0 0 12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">';
		echo '<span><strong>' . esc_html( $status ) . '</strong></span>';
		if ( defined( 'MUSIC_WAVE_VIP_FILE' ) ) {
			echo '<a class="button button-secondary" href="' . esc_url( $vip_url ) . '">' . esc_html__( 'تنظیمات کامل MusicWave VIP و طرح‌ها', 'music-wave-core' ) . '</a>';
		}
		echo '</div>';
	}

	// ------------------------------------------------------------------
	// Delivery & Privacy – token TTLs, rate limits, discovery, listening
	// ------------------------------------------------------------------
	private function render_delivery_settings(): void {
		$settings = Settings::all();
		$toggles  = array(
			'enabled'  => __( 'فعال شد', 'music-wave-core' ),
			'disabled' => __( 'غیرفعال', 'music-wave-core' ),
		);
		$this->settings_form_start();

		echo '<section class="mw-settings__panel"><h2>' . esc_html__( 'دانلودهای ایمن و مسیریابی تحویل', 'music-wave-core' ) . '</h2>';
		$delivery_caps = \ManaCore\MusicWave\Core\Support\DeliveryCapabilities::describe();
		echo '<div class="notice notice-' . esc_attr( $delivery_caps['has_protected_provider'] ? 'info' : 'warning' ) . ' inline" style="margin:0 0 12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;"><span><strong>' . esc_html( $delivery_caps['summary'] ) . ':</strong> ' . esc_html( $delivery_caps['detail'] ) . '</span>';
		if ( defined( 'MUSIC_WAVE_VIP_FILE' ) ) {
			echo '<a class="button button-secondary" href="' . esc_url( admin_url( 'edit.php?post_type=' . ReleasePostType::KEY . '&page=music-wave-vip&tab=delivery' ) ) . '">' . esc_html__( 'باز کردن تب تحویل حفاظت‌شده VIP', 'music-wave-core' ) . '</a>';
		}
		echo '</div>';
		echo '<p>' . esc_html__( 'صدور توکن امضاشدهٔ یک‌بارمصرف، طول عمر پیوندها، سهمیهٔ تحویل و میزبان‌های مجاز پیوند مستقیم HTTPS (direct:) را کنترل کنید.', 'music-wave-core' ) . '</p><table class="form-table" role="presentation">';
		$this->number_row( 'download_token_ttl', __( 'مدت اعتبار پیوند دانلود (ثانیه)', 'music-wave-core' ), (int) $settings['download_token_ttl'], 30, 3600, __( 'طول عمر تیکت یک‌بارمصرف دانلود فایل (پیش‌فرض: 300 ثانیه = ۵ دقیقه).', 'music-wave-core' ) );
		$this->number_row( 'stream_token_ttl', __( 'مدت اعتبار پیوند پخش امن (ثانیه)', 'music-wave-core' ), (int) $settings['stream_token_ttl'], 60, 7200, __( 'طول عمر پیوند استریم امن برای پخش بی‌وقفه در پلیر سراسری (پیش‌فرض: 900 ثانیه = ۱۵ دقیقه).', 'music-wave-core' ) );
		$this->number_row( 'download_rate_limit', __( 'درخواست توکن در هر پنجره', 'music-wave-core' ), (int) $settings['download_rate_limit'], 1, 1000, __( 'حداکثر تعداد توکن‌های دانلود یا پخش جریانی که یک بازدیدکننده می‌تواند پیش از اعمال محدودیت ۴۲۹ درخواست کند.', 'music-wave-core' ) );
		$this->number_row( 'download_rate_window', __( 'پنجرهٔ محدودیت نرخ (ثانیه)', 'music-wave-core' ), (int) $settings['download_rate_window'], 10, 3600, __( 'طول پنجرهٔ ثابت استفاده‌شده توسط محدودیت نرخ رمز.', 'music-wave-core' ) );
		$this->number_row( 'download_daily_quota', __( 'سهمیهٔ تحویل روزانه', 'music-wave-core' ), (int) $settings['download_daily_quota'], 0, 10000, __( 'حداکثر تحویل فایل تکمیل‌شده برای هر کاربر/بازدیدکننده در شبانه‌روز. 0 را برای بدون سقف روزانه تنظیم کنید.', 'music-wave-core' ) );
		$allowed_hosts = isset( $settings['direct_link_allowed_hosts'] ) ? (string) $settings['direct_link_allowed_hosts'] : '';
		echo '<tr><th scope="row"><label for="mw-setting-direct_link_allowed_hosts">' . esc_html__( 'میزبان‌های مجاز پیوند مستقیم HTTPS (اختیاری)', 'music-wave-core' ) . '</label></th><td>';
		echo '<textarea class="regular-text code" rows="3" id="mw-setting-direct_link_allowed_hosts" name="' . esc_attr( Settings::OPTION ) . '[direct_link_allowed_hosts]" placeholder="cdn.example.com&#10;*.r2.dev">' . esc_textarea( $allowed_hosts ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'در هر خط یک دامنه (مانند cdn.example.com یا *.example.com). خالی گذاشتن به معنای پذیرش هر میزبان عمومی HTTPS معتبر است (نشانی‌های داخلی، localhost و IPهای خصوصی همیشه مسدود می‌شوند). توجه: تغییرمسیر 302 نشانی نهایی را به مرورگر کاربر مجاز نشان می‌دهد؛ برای فایل‌های پولی حساس از تحویل حفاظت‌شده VIP استفاده کنید.', 'music-wave-core' ) . '</p></td></tr>';
		echo '</table></section>';

		echo '<section class="mw-settings__panel"><h2>' . esc_html__( 'جست‌وجو و کشف کاتالوگ', 'music-wave-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'تکمیل خودکار، فیلترها و توصیه‌ها نقاط پایانی عمومی هستند. این محدودیت‌ها آن‌ها را سریع و در برابر سوء استفاده مقاوم می‌کند.', 'music-wave-core' ) . '</p><table class="form-table" role="presentation">';
		$this->number_row( 'discovery_rate_limit', __( 'درخواست در هر پنجره', 'music-wave-core' ), (int) $settings['discovery_rate_limit'], 1, 1000, __( 'حداکثر درخواست‌های کشف که یک بازدیدکننده می‌تواند قبل از محدودشدن انجام دهد.', 'music-wave-core' ) );
		$this->number_row( 'discovery_rate_window', __( 'پنجرهٔ محدودیت نرخ (ثانیه)', 'music-wave-core' ), (int) $settings['discovery_rate_window'], 10, 3600, __( 'طول پنجرهٔ ثابت استفاده‌شده توسط محدودیت نرخ کشف.', 'music-wave-core' ) );
		$this->number_row( 'discovery_cache_ttl', __( 'مدت نگه‌داری حافظهٔ نهان جست‌وجو (ثانیه)', 'music-wave-core' ), (int) $settings['discovery_cache_ttl'], 30, 86400, __( 'چه مدت پیشنهادها و نتایج فیلتر در حافظهٔ نهان ذخیره می‌شوند.', 'music-wave-core' ) );
		echo '</table></section>';

		echo '<section class="mw-settings__panel"><h2>' . esc_html__( 'تاریخچه گوش‌دادن', 'music-wave-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'فعالیت پخش فقط برای بازدیدکنندگانی که با ذخیرهٔ سابقهٔ گوش‌دادن موافقت کرده‌اند، ثبت می‌شود. ورودی‌های قدیمی‌تر در پاک‌سازی روزانه حذف می‌شوند و کاربران می‌توانند سابقهٔ شخصی خود را از حسابشان پاک کنند.', 'music-wave-core' ) . '</p><table class="form-table" role="presentation">';
		$this->select_row( 'listening_history', __( 'ثبت تاریخچه گوش‌دادن', 'music-wave-core' ), (string) $settings['listening_history'], $toggles, __( 'با غیرفعال کردن این گزینه، ثبت سابقهٔ گوش‌دادن و نمایش بخش ادامهٔ گوش‌دادن در سراسر سایت خاموش می‌شود.', 'music-wave-core' ) );
		$this->number_row( 'listening_retention_days', __( 'مدت نگهداری (روز)', 'music-wave-core' ), (int) $settings['listening_retention_days'], 1, 3650, __( 'فعالیت قدیمی‌تر از این پنجره به‌طور خودکار حذف می‌شود.', 'music-wave-core' ) );
		echo '</table></section>';

		echo '<section class="mw-settings__panel"><h2>' . esc_html__( 'فهرست پخش و کتابخانه', 'music-wave-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'محدودیت‌های قابل تنظیم برای جلوگیری از سوءاستفاده و N+1 query. پیش‌فرض‌ها: 20 فهرست در هر کاربر، 100 آیتم در هر فهرست.', 'music-wave-core' ) . '</p><table class="form-table" role="presentation">';
		$this->number_row( 'max_playlists_per_user', __( 'حداکثر فهرست پخش در هر کاربر', 'music-wave-core' ), (int) $settings['max_playlists_per_user'], 1, 100, __( 'فیلتر music_wave_max_playlists_per_user برای override per user.', 'music-wave-core' ) );
		$this->number_row( 'max_playlist_items', __( 'حداکثر آیتم در هر فهرست', 'music-wave-core' ), (int) $settings['max_playlist_items'], 10, 500, __( 'فیلتر music_wave_max_playlist_items.', 'music-wave-core' ) );
		$this->select_row(
			'public_playlists_indexable',
			__( 'فهرست‌های عمومی قابل ایندکس', 'music-wave-core' ),
			(string) $settings['public_playlists_indexable'],
			array(
				'disabled' => __( 'noindex پیش‌فرض (امن)', 'music-wave-core' ),
				'enabled'  => __( 'قابل ایندکس', 'music-wave-core' ),
			),
			__( 'پیش‌فرض noindex، meta robots nofollow روی صفحات حاوی بلوک public-playlists، sitemap exclude.', 'music-wave-core' )
		);
		echo '</table></section>';

		echo '<section class="mw-settings__panel"><h2>' . esc_html__( 'امنیت و حریم خصوصی', 'music-wave-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'محافظت enumeration کاربر اختیاری است (پیش‌فرض خاموش). وقتی روشن باشد، ?author=1 و REST /wp/v2/users برای کاربران کم‌مجوز مسدود و به خانه ریدایرکت می‌شود.', 'music-wave-core' ) . '</p><table class="form-table" role="presentation">';
		$this->select_row(
			'disable_author_enumeration',
			__( 'محافظت enumeration کاربر', 'music-wave-core' ),
			(string) $settings['disable_author_enumeration'],
			array(
				'disabled' => __( 'خاموش (رفتار فعلی)', 'music-wave-core' ),
				'enabled'  => __( 'روشن — مسدود کردن', 'music-wave-core' ),
			),
			__( 'با روشن بودن، آرشیو نویسنده و REST users برای نقش‌های کم‌مجوز مسدود می‌شود.', 'music-wave-core' )
		);
		echo '</table></section>';

		echo '<section class="mw-settings__panel mw-settings__panel--danger"><h2>' . esc_html__( 'حذف داده‌ها هنگام حذف افزونه', 'music-wave-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'به‌صورت پیش‌فرض، داده‌های MusicWave Core هنگام حذف افزونه نگه‌داشته می‌شود تا حذف تصادفی در staging فاجعه نشود. اگر می‌خواهید با حذف افزونه همه جداول سفارشی، تنظیمات، transients، user meta و cronها پاک شود، این گزینه را فعال کنید. محتوای مشتری (انتشارها mw_release، درخواست‌ها mw_request، ترم‌ها، رسانه‌ها و فایل‌های حفاظت‌شده روی دیسک) هرگز پاک نمی‌شود.', 'music-wave-core' ) . '</p><table class="form-table" role="presentation">';
		$this->select_row(
			'delete_data_on_uninstall',
			__( 'حذف داده‌ها هنگام حذف', 'music-wave-core' ),
			(string) $settings['delete_data_on_uninstall'],
			array(
				'disabled' => __( 'نگه‌داشتن داده‌ها (پیش‌فرض امن)', 'music-wave-core' ),
				'enabled'  => __( 'حذف همه داده‌های افزونه', 'music-wave-core' ),
			),
			__( 'فقط هنگام حذف از افزونه‌ها → حذف اعمال می‌شود، نه هنگام غیرفعال‌سازی.', 'music-wave-core' )
		);
		echo '</table></section>';

		submit_button( __( 'ذخیره تنظیمات تحویل و حریم خصوصی', 'music-wave-core' ) );
		echo '</form>';
	}

	private function render_bulk_form(): void {
		$counts = $this->bulk_access->mode_counts();
		echo '<section class="mw-settings__panel mw-settings__panel--danger"><h2>' . esc_html__( 'حالت دسترسی انبوه', 'music-wave-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'انتشارها را در دسته‌های ایمن ۲۰۰تایی بر اساس وضعیت، نوع انتشار، هنرمند یا سبک فیلتر و به‌روزرسانی کنید. در صورت انتخاب «اشتراک VIP» یا «خرید یا اشتراک VIP»، سطح عضویت پیش‌فرض یا انتخابی خودکار اعمال می‌شود و در صورت انتخاب محصول WooCommerce، نگاشت دوطرفه نیز هم‌زمان ثبت می‌گردد.', 'music-wave-core' ) . '</p>';

		echo '<div style="display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px;">';
		foreach ( AccessModePolicy::labels( true ) as $mode_slug => $short_label ) {
			$num = isset( $counts[ $mode_slug ] ) ? (int) $counts[ $mode_slug ] : 0;
			echo '<span style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:4px 10px;font-size:12px;">' . esc_html( $short_label ) . ': <strong>' . esc_html( number_format_i18n( $num ) ) . '</strong></span>';
		}
		echo '</div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'music_wave_bulk_access_start' );
		echo '<input type="hidden" name="action" value="music_wave_bulk_access_start"><div class="mw-settings__bulk-grid">';
		$this->plain_select( 'target_mode', __( 'حالت دسترسی جدید', 'music-wave-core' ), $this->access_labels(), 'membership' );
		$this->plain_select( 'current_mode', __( 'فیلتر حالت فعلی', 'music-wave-core' ), array_merge( array( 'any' => __( 'هر حالت', 'music-wave-core' ) ), $this->access_labels() ), 'any' );
		$this->plain_select(
			'post_status',
			__( 'فیلتر وضعیت پست', 'music-wave-core' ),
			array(
				'any'     => __( 'هر وضعیت فعال', 'music-wave-core' ),
				'publish' => __( 'منتشر شد', 'music-wave-core' ),
				'draft'   => __( 'پیش‌نویس', 'music-wave-core' ),
				'pending' => __( 'در انتظار بررسی', 'music-wave-core' ),
				'private' => __( 'خصوصی', 'music-wave-core' ),
				'future'  => __( 'زمان‌بندی‌شده', 'music-wave-core' ),
			),
			'any'
		);
		$this->plain_select( 'release_type', __( 'فیلتر نوع انتشار', 'music-wave-core' ), $this->term_options( 'mw_release_type', __( 'هر نوع انتشار', 'music-wave-core' ) ), 'any' );
		$this->plain_select( 'artist_slug', __( 'فیلتر هنرمند', 'music-wave-core' ), $this->term_options( 'mw_artist', __( 'همه هنرمندان', 'music-wave-core' ) ), 'any' );
		$this->plain_select( 'genre_slug', __( 'فیلتر سبک', 'music-wave-core' ), $this->term_options( 'mw_genre', __( 'همه سبک‌ها', 'music-wave-core' ) ), 'any' );

		if ( post_type_exists( 'product' ) ) {
			$product_options = array( '0' => __( '— بدون تغییر محصولات فعلی —', 'music-wave-core' ) );
			$prod_query      = new WP_Query(
				array(
					'post_type'              => 'product',
					'post_status'            => array( 'publish', 'private', 'draft' ),
					'posts_per_page'         => 100,
					'orderby'                => 'ID',
					'order'                  => 'DESC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			foreach ( is_array( $prod_query->posts ) ? $prod_query->posts : array() as $pid ) {
				$pid                              = absint( $pid );
				$product_options[ (string) $pid ] = '#' . $pid . ' — ' . get_the_title( $pid );
			}
			$this->plain_select( 'bulk_product_id', __( 'پیوند محصول WooCommerce (اختیاری)', 'music-wave-core' ), $product_options, '0' );
		}
		echo '</div>';

		echo '<div style="margin:12px 0;">';
		echo '<label for="mw-bulk-levels" style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html__( 'کلیدهای سطح عضویت اعمالی (اختیاری — خالی = سطح پیش‌فرض):', 'music-wave-core' ) . '</label>';
		echo '<input type="text" id="mw-bulk-levels" name="bulk_membership_levels" value="" placeholder="' . esc_attr( implode( ', ', AccessModePolicy::default_membership_levels() ) ) . '" class="regular-text">';
		$this->render_level_picker_chips( 'mw-bulk-levels', array() );
		echo '</div>';

		echo '<label class="mw-settings__confirm"><input type="checkbox" required> ' . esc_html__( 'من تأیید می‌کنم که سیاست دسترسی جدید برای انتشارهای منطبق با فیلترهای بالا اعمال شود.', 'music-wave-core' ) . '</label>';
		submit_button( __( 'شروع به‌روزرسانی انبوه', 'music-wave-core' ), 'primary', 'submit', false );
		echo '</form></section>';
	}

	/**
	 * @return array<string, string>
	 */
	private function term_options( string $taxonomy, string $any_label ): array {
		$options = array( 'any' => $any_label );
		$terms   = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( $term instanceof \WP_Term ) {
					$options[ (string) $term->slug ] = (string) $term->name;
				}
			}
		}

		return $options;
	}

	/**
	 * @param array<int, string> $active_slugs Active slugs.
	 */
	private function render_level_picker_chips( string $input_id, array $active_slugs ): void {
		$available = apply_filters( 'music_wave_available_membership_levels', array() );
		if ( ! is_array( $available ) || empty( $available ) ) {
			$available = array(
				AccessModePolicy::DEFAULT_VIP_LEVEL => __( 'همه مشترکین فعال VIP (mw_vip)', 'music-wave-core' ),
			);
		}
		echo '<span class="mw-membership-picker" data-mw-level-picker="' . esc_attr( $input_id ) . '">';
		foreach ( $available as $slug => $label ) {
			$clean = sanitize_key( (string) $slug );
			if ( '' === $clean ) {
				continue;
			}
			$is_on = in_array( $clean, $active_slugs, true );
			echo '<button type="button" class="button button-small mw-level-chip' . ( $is_on ? ' is-active' : '' ) . '" data-mw-level-slug="' . esc_attr( $clean ) . '" aria-pressed="' . ( $is_on ? 'true' : 'false' ) . '">' . esc_html( (string) $label ) . '</button>';
		}
		echo '</span>';
	}

	// ------------------------------------------------------------------
	// Integrations – cards + metadata provider keys
	// ------------------------------------------------------------------
	private function render_integrations(): void {
		$theme = wp_get_theme();
		$cards = array(
			array(
				'id'          => 'woocommerce',
				'name'        => __( 'WooCommerce', 'music-wave-core' ),
				'active'      => class_exists( 'WooCommerce' ),
				'description' => __( 'محصولات، مالکیت خرید، پرداخت، و کتابخانه مشتری.', 'music-wave-core' ),
				'url'         => class_exists( 'WooCommerce' ) ? admin_url( 'admin.php?page=wc-settings' ) : admin_url( 'plugin-install.php?s=WooCommerce&tab=search&type=term' ),
				'action'      => class_exists( 'WooCommerce' ) ? __( 'تنظیمات WooCommerce را باز کنید', 'music-wave-core' ) : __( 'نصب WooCommerce', 'music-wave-core' ),
			),
			array(
				'id'          => 'music-wave-vip',
				'name'        => __( 'MusicWave VIP', 'music-wave-core' ),
				'active'      => defined( 'MUSIC_WAVE_VIP_FILE' ),
				'description' => __( 'فایل‌های محلی حفاظت‌شده، تغییرمسیر HMAC و عضویت طرح‌های ووکامرس.', 'music-wave-core' ),
				'url'         => defined( 'MUSIC_WAVE_VIP_FILE' ) ? admin_url( 'edit.php?post_type=mw_release&page=music-wave-vip' ) : admin_url( 'plugins.php' ),
				'action'      => defined( 'MUSIC_WAVE_VIP_FILE' ) ? __( 'باز کردن تنظیمات VIP', 'music-wave-core' ) : __( 'بررسی افزونه‌ها', 'music-wave-core' ),
			),
			array(
				'id'          => 'musicwave-theme',
				'name'        => __( 'تم MusicWave', 'music-wave-core' ),
				'active'      => 'musicwave' === $theme->get_stylesheet(),
				'description' => __( 'قالب‌ها، سبک‌ها، الگوها و ویرایش بصری را مسدود کنید.', 'music-wave-core' ),
				'url'         => admin_url( 'site-editor.php' ),
				'action'      => __( 'باز کردن ویرایشگر سایت', 'music-wave-core' ),
			),
		);

		/** @var array<int, array<string, mixed>> $cards Filtered integration cards */
		$cards = apply_filters( 'music_wave_admin_integrations', $cards );
		echo '<div class="mw-settings__grid mw-settings__grid--cards">';
		if ( is_array( $cards ) ) {
			foreach ( $cards as $card ) {
				if ( ! is_array( $card ) || empty( $card['name'] ) ) {
					continue;
				}
				$active = ! empty( $card['active'] );
				echo '<section class="mw-settings__panel mw-integration"><div class="mw-integration__heading"><h2>' . esc_html( (string) $card['name'] ) . '</h2><span class="mw-status mw-status--' . esc_attr( $active ? 'active' : 'inactive' ) . '">' . esc_html( $active ? __( 'فعال', 'music-wave-core' ) : __( 'غیرفعال', 'music-wave-core' ) ) . '</span></div>';
				echo '<p>' . esc_html( isset( $card['description'] ) ? (string) $card['description'] : '' ) . '</p>';
				if ( ! empty( $card['url'] ) ) {
					echo '<a class="button button-secondary" href="' . esc_url( (string) $card['url'] ) . '">' . esc_html( isset( $card['action'] ) ? (string) $card['action'] : __( 'مدیریت کنید', 'music-wave-core' ) ) . '</a>';
				}
				echo '</section>';
			}
		}
		echo '</div>';

		$this->render_metadata_provider_settings();
		do_action( 'music_wave_admin_integration_settings' );
	}

	private function render_metadata_provider_settings(): void {
		$settings = Settings::all();
		$this->settings_form_start();
		echo '<section class="mw-settings__panel"><h2>' . esc_html__( 'تکمیل خودکار فرادادهٔ موسیقی', 'music-wave-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'ویرایشگران انتشار می‌توانند عنوان، توضیح رسمی، هنرمند، آلبوم، سال، سبک‌ها، حال‌وهواها، برچسب‌ها و تصویر جلد را خودکار تکمیل کنند. جست‌وجو با قطعه‌ها، آلبوم‌ها، فهرست‌های پخش و پادکست‌ها سازگار می‌شود. کلیدی لازم نیست: جست‌وجوی داخلی MusicBrainz، Cover Art Archive، iTunes (۶۰۰px) و Deezer (۱۰۰۰px) بدون تنظیمات اضافی کار می‌کند.', 'music-wave-core' ) . '</p><table class="form-table" role="presentation">';
		$this->text_row( 'spotify_client_id', __( 'شناسهٔ کلاینت Spotify', 'music-wave-core' ), (string) $settings['spotify_client_id'] );
		$this->text_row( 'spotify_client_secret', __( 'راز کلاینت Spotify', 'music-wave-core' ), (string) $settings['spotify_client_secret'] );
		$this->text_row( 'discogs_token', __( 'توکن دسترسی شخصی Discogs', 'music-wave-core' ), (string) $settings['discogs_token'] );
		$this->text_row( 'lastfm_api_key', __( 'کلید API Last.fm (اختیاری)', 'music-wave-core' ), (string) ( $settings['lastfm_api_key'] ?? '' ) );
		echo '</table></section>';
		submit_button( __( 'ذخیره کلیدهای ارائه‌دهنده فراداده', 'music-wave-core' ) );
		echo '</form>';
	}

	private function render_management_links(): void {
		$links = array(
			array( __( 'ویرایشگر سایت', 'music-wave-core' ), __( 'قالب‌ها، قطعات قالب، سربرگ، پابرگ و سبک‌های جهانی را ویرایش کنید.', 'music-wave-core' ), admin_url( 'site-editor.php' ), 'dashicons-edit-site' ),
			array( __( 'ابزارک‌ها', 'music-wave-core' ), __( 'هنگامی‌که قالب یا افزونهٔ فعال آن‌ها را ثبت می‌کند، ناحیه‌های ابزارک را مدیریت کنید.', 'music-wave-core' ), admin_url( 'widgets.php' ), 'dashicons-screenoptions' ),
			array( __( 'ناوبری', 'music-wave-core' ), __( 'بلوک‌های ناوبری و منوها را مدیریت کنید.', 'music-wave-core' ), admin_url( 'site-editor.php?path=%2Fnavigation' ), 'dashicons-menu' ),
			array( __( 'کتابخانهٔ رسانه', 'music-wave-core' ), __( 'آثار هنری روی جلد و رسانه پیش‌نمایش عمومی را مدیریت کنید.', 'music-wave-core' ), admin_url( 'upload.php' ), 'dashicons-format-audio' ),
			array( __( 'هنرمندان', 'music-wave-core' ), __( 'بیوگرافی‌ها، تصاویر و URLهای متعارف هنرمند را ویرایش کنید.', 'music-wave-core' ), admin_url( 'edit-tags.php?taxonomy=mw_artist&post_type=' . ReleasePostType::KEY ), 'dashicons-groups' ),
			array( __( 'انواع انتشار', 'music-wave-core' ), __( 'طبقه‌بندی‌های مورد استفاده برای قطعه‌ها، آلبوم‌ها و پادکست‌ها را مدیریت کنید.', 'music-wave-core' ), admin_url( 'edit-tags.php?taxonomy=mw_release_type&post_type=' . ReleasePostType::KEY ), 'dashicons-category' ),
			array( __( 'پیوندهای ثابت', 'music-wave-core' ), __( 'ساختار URL کاتالوگ و طبقه‌بندی‌های متعارف را پیکربندی کنید.', 'music-wave-core' ), admin_url( 'options-permalink.php' ), 'dashicons-admin-links' ),
			array( __( 'کاربران', 'music-wave-core' ), __( 'مدیران، ویراستاران، مشتریان و نقش‌های عضویت را مدیریت کنید.', 'music-wave-core' ), admin_url( 'users.php' ), 'dashicons-admin-users' ),
		);
		echo '<div class="mw-settings__grid mw-settings__grid--links">';
		foreach ( $links as $link ) {
			echo '<a class="mw-management-link" href="' . esc_url( $link[2] ) . '"><span class="dashicons ' . esc_attr( $link[3] ) . '" aria-hidden="true"></span><span><strong>' . esc_html( $link[0] ) . '</strong><small>' . esc_html( $link[1] ) . '</small></span></a>';
		}
		echo '</div>';
	}

	private function register_help_tabs(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! is_object( $screen ) || ! method_exists( $screen, 'add_help_tab' ) ) {
			return;
		}
		$screen->add_help_tab(
			array(
				'id'      => 'music-wave-settings-guide',
				'title'   => __( 'تنظیمات MusicWave', 'music-wave-core' ),
				'content' =>
					'<p>' . esc_html__( 'مرکز کنترل پیکربندی MusicWave را بر اساس موضوع گروه‌بندی می‌کند:', 'music-wave-core' ) . '</p>' .
					'<ul>' .
					'<li>' . esc_html__( 'محتوا و نمایش - پیش‌فرض‌های انتشار جدید، رفتار بایگانی، لغزنده، پخش، و داده‌های ساختاریافته.', 'music-wave-core' ) . '</li>' .
					'<li>' . esc_html__( 'دسترسی - رفتار ماژول عضویت، پیام‌های مشتری و ابزارهای دسترسی انبوه.', 'music-wave-core' ) . '</li>' .
					'<li>' . esc_html__( 'تحویل و حریم خصوصی — طول عمر توکن دانلود، محدودیت سرعت، سهمیه‌های تحویل، حافظهٔ نهان و نگه‌داری سابقهٔ شنیداری.', 'music-wave-core' ) . '</li>' .
					'<li>' . esc_html__( 'ادغام - WooCommerce، MusicWave VIP، تم بلوک، و کلیدهای ارائه‌دهنده فراداده.', 'music-wave-core' ) . '</li>' .
					'</ul>' .
					'<p>' . esc_html__( 'هر برگه به‌طور مستقل ذخیره می‌شود. تنظیمات برگه‌های دیگر نیز حفظ می‌شوند.', 'music-wave-core' ) . '</p>',
			)
		);
		$screen->add_help_tab(
			array(
				'id'      => 'music-wave-settings-diagnostics',
				'title'   => __( 'راهنما و تشخیص', 'music-wave-core' ),
				'content' =>
					'<p>' . esc_html__( 'اگر چیزی مطابق انتظار عمل نکرد، با بررسی محیط و راهنمای شروع سریع شروع کنید، سپس صفحه سلامت سایت WordPress را مرور کنید.', 'music-wave-core' ) . '</p>' .
					'<p><a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=' . ReleasePostType::KEY . '&page=music-wave-setup' ) ) . '">' . esc_html__( 'راه‌اندازی و عیب‌یابی را باز کنید', 'music-wave-core' ) . '</a> <a class="button" href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'باز کردن سلامت سایت', 'music-wave-core' ) . '</a></p>',
			)
		);
	}

	/** @return array<string,string> */
	private function access_labels(): array {
		return AccessModePolicy::labels();
	}
}
