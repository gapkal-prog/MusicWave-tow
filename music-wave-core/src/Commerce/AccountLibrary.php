<?php
/**
 * Unified Customer Account Coordinator & Entitled Downloads Library.
 *
 * Coordinates all 12 Site Editor account/membership blocks, WooCommerce My Account
 * endpoints, shortcodes, and the entitled release download shelf by delegating each
 * concern to dedicated single-responsibility presenters in `ManaCore\MusicWave\Core\Account`:
 * - `MembershipPresenter`     — real VIP grants, renewal callouts, plan cards
 * - `StatsPresenter`          — batched library & entitlement counters
 * - `WooAccountBridge`        — WooCommerce panels, endpoint replay, compact form fields
 * - `RequestTrackingRenderer` — user request list & collapsible reply/chat drawer
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Commerce;

use ManaCore\MusicWave\Core\Access\AccessPolicyEngine;
use ManaCore\MusicWave\Core\Access\AccessSubject;
use ManaCore\MusicWave\Core\Account\MembershipPresenter;
use ManaCore\MusicWave\Core\Account\RequestTrackingRenderer;
use ManaCore\MusicWave\Core\Account\StatsPresenter;
use ManaCore\MusicWave\Core\Account\WooAccountBridge;
use ManaCore\MusicWave\Core\Blocks\BlockSupport;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use ManaCore\MusicWave\Core\Downloads\ReleaseDownloadCatalog;
use ManaCore\MusicWave\Core\Library\LibraryRepository;
use ManaCore\MusicWave\Core\Support\VipModuleStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AccountLibrary {
	public const ENDPOINT            = 'music-library';
	public const MEMBERSHIP_ENDPOINT = 'membership';

	/**
	 * Canonical map of account/membership blocks to their render callbacks.
	 * `dashboard-edit` was consolidated into `dashboard-account` and remains registered
	 * as a hidden server-side legacy alias in `register_dashboard_block()`.
	 *
	 * @var array<string, string>
	 */
	public const BLOCK_RENDERERS = array(
		'music-wave/account-dashboard'      => 'render_dashboard_block',
		'music-wave/membership-panel'       => 'render_membership_block',
		'music-wave/dashboard-welcome'      => 'render_welcome_block',
		'music-wave/dashboard-stats'        => 'render_stats_block',
		'music-wave/dashboard-subscription' => 'render_subscription_block',
		'music-wave/dashboard-account'      => 'render_account_block',
		'music-wave/dashboard-addresses'    => 'render_addresses_block',
		'music-wave/dashboard-payment'      => 'render_payment_block',
		'music-wave/dashboard-orders'       => 'render_orders_block',
		'music-wave/dashboard-downloads'    => 'render_downloads_block',
		'music-wave/dashboard-requests'     => 'render_requests_block',
	);

	/** @var AccessPolicyEngine */
	private $policy;

	/** @var ReleaseRepository */
	private $releases;

	/** @var MembershipPresenter */
	private $membership;

	/** @var StatsPresenter */
	private $stats;

	/** @var WooAccountBridge */
	private $woo_bridge;

	/** @var RequestTrackingRenderer */
	private $requests_renderer;

	public function __construct(
		AccessPolicyEngine $policy,
		ReleaseRepository $releases,
		?LibraryRepository $library = null,
		?MembershipPresenter $membership = null,
		?StatsPresenter $stats = null,
		?WooAccountBridge $woo_bridge = null,
		?RequestTrackingRenderer $requests_renderer = null
	) {
		$this->policy            = $policy;
		$this->releases          = $releases;
		$this->membership        = null !== $membership ? $membership : new MembershipPresenter( $policy );
		$this->stats             = null !== $stats ? $stats : new StatsPresenter( $library );
		$this->woo_bridge        = null !== $woo_bridge ? $woo_bridge : new WooAccountBridge();
		$this->requests_renderer = null !== $requests_renderer ? $requests_renderer : new RequestTrackingRenderer();
	}

	/**
	 * Register endpoints early enough for WordPress & WooCommerce rewrite routing.
	 */
	public static function register_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( self::MEMBERSHIP_ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Register blocks, WooCommerce account hooks, shortcodes, and form enhancements.
	 */
	public function register(): void {
		add_action( 'init', array( self::class, 'register_endpoint' ) );
		add_action( 'init', array( $this, 'register_dashboard_block' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ), 40 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_action( 'woocommerce_account_' . self::MEMBERSHIP_ENDPOINT . '_endpoint', array( $this, 'render_membership_endpoint' ) );
		add_filter( 'pre_do_shortcode_tag', array( $this, 'skip_my_account_shortcode' ), 10, 2 );
		add_shortcode( 'musicwave_dashboard', array( $this, 'dashboard_shortcode' ) );
		add_shortcode( 'musicwave_membership', array( $this, 'membership_shortcode' ) );

		$this->woo_bridge->register_field_filters();
	}

	/**
	 * Neutralize a duplicate `[woocommerce_my_account]` shortcode on account pages.
	 *
	 * @param string|null $output Shortcode output, null by default.
	 * @param string      $tag    Shortcode tag.
	 * @return string|null
	 */
	public function skip_my_account_shortcode( $output, string $tag ) {
		if ( 'woocommerce_my_account' === $tag && function_exists( 'is_account_page' ) && is_account_page() ) {
			return '';
		}

		return $output;
	}

	/**
	 * Register all portable account & membership blocks from `blocks/<slug>/block.json`,
	 * plus a hidden legacy alias for `music-wave/dashboard-edit` -> `dashboard-account`.
	 */
	public function register_dashboard_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		foreach ( self::BLOCK_RENDERERS as $block_name => $method ) {
			BlockSupport::register_dynamic( $block_name, array( $this, $method ) );
		}
		/** @var array<string, mixed> $legacy_args */
		$legacy_args = array(
			'api_version'     => 3,
			'category'        => 'music-wave',
			'render_callback' => array( $this, 'render_account_block' ),
			'supports'        => array_merge( BlockSupport::appearance_tools(), array( 'inserter' => false ) ),
		);
		register_block_type( 'music-wave/dashboard-edit', $legacy_args );
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_dashboard_block( array $attributes = array() ): string {
		ob_start();
		$this->render_dashboard( $attributes );
		return (string) ob_get_clean();
	}

	/**
	 * @param mixed $attributes Shortcode attributes.
	 */
	public function dashboard_shortcode( $attributes = array() ): string {
		return $this->render_dashboard_block( is_array( $attributes ) ? $attributes : array() );
	}

	/**
	 * @param mixed $attributes Shortcode attributes.
	 */
	public function membership_shortcode( $attributes = array() ): string {
		return $this->render_membership_block( is_array( $attributes ) ? $attributes : array() );
	}

	public function render_membership_endpoint(): void {
		echo $this->membership->render_panel( get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside MembershipPresenter.
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_membership_block( array $attributes = array() ): string {
		return '<section ' . BlockSupport::wrapper_attributes( 'mw-membership-block' ) . '>'
			. $this->membership->render_panel( get_current_user_id(), $attributes )
			. '</section>';
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_welcome_block( array $attributes = array() ): string {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return $this->membership->render_guest_card(
				BlockSupport::wrapper_attributes( 'mw-user-dashboard mw-user-dashboard--guest' ),
				BlockSupport::current_url()
			);
		}

		$context = $this->membership->context( $user_id );
		$state   = ! empty( $context['is_vip'] ) ? 'mw-user-dashboard__welcome--vip' : 'mw-user-dashboard__welcome--standard';

		return $this->membership->render_welcome_header(
			$context,
			array(
				'show_avatar'    => BlockSupport::bool_attribute( $attributes, 'showAvatar', true ),
				'show_vip_badge' => BlockSupport::bool_attribute( $attributes, 'showVipBadge', true ),
				'show_sub_meta'  => BlockSupport::bool_attribute( $attributes, 'showSubscriptionMeta', true ),
				'show_renewal'   => BlockSupport::bool_attribute( $attributes, 'showRenewal', true ),
				'intro_text'     => BlockSupport::text_attribute( $attributes, 'introText', '' ),
			),
			array(),
			BlockSupport::wrapper_attributes( 'mw-user-dashboard__welcome ' . $state )
		);
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_stats_block( array $attributes = array() ): string {
		return $this->stats->render_block(
			$attributes,
			function ( int $user_id ): int {
				return count( $this->entitled_release_ids( new AccessSubject( $user_id ) ) );
			}
		);
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_subscription_block( array $attributes = array() ): string {
		return $this->membership->render_subscription_card(
			get_current_user_id(),
			$attributes,
			BlockSupport::wrapper_attributes( 'mw-dashboard-subscription' )
		);
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_account_block( array $attributes = array() ): string {
		return $this->woo_bridge->render_account_edit_block( $attributes );
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_edit_account_block( array $attributes = array() ): string {
		return $this->woo_bridge->render_account_edit_block( $attributes );
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_addresses_block( array $attributes = array() ): string {
		return $this->woo_bridge->render_addresses_block( $attributes );
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_payment_block( array $attributes = array() ): string {
		return $this->woo_bridge->render_payment_block( $attributes );
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_orders_block( array $attributes = array() ): string {
		return $this->woo_bridge->render_orders_block( $attributes );
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_downloads_block( array $attributes = array() ): string {
		return $this->woo_bridge->render_downloads_block( $attributes );
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_requests_block( array $attributes = array() ): string {
		return $this->requests_renderer->render( $attributes );
	}

	/**
	 * @param array<string, string> $items Existing WooCommerce account menu items.
	 * @return array<string, string>
	 */
	public function add_menu_item( array $items ): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return $items;
		}
		$logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : '';
		unset( $items['customer-logout'] );
		$items[ self::ENDPOINT ]            = __( 'کتابخانه علاقه‌مندی‌ها', 'music-wave-core' );
		$items[ self::MEMBERSHIP_ENDPOINT ] = __( 'اشتراک VIP', 'music-wave-core' );
		if ( '' !== $logout ) {
			$items['customer-logout'] = $logout;
		}

		return $items;
	}

	/**
	 * Render entitled secure downloads list.
	 */
	public function render(): void {
		$subject = AccessSubject::current();
		if ( $subject->user_id() < 1 ) {
			return;
		}

		$items = array();
		foreach ( $this->entitled_release_ids( $subject ) as $release_id ) {
			$markup = $this->item_markup( $release_id );
			if ( '' !== $markup ) {
				$items[] = $markup;
			}
		}

		echo '<section class="mw-account-library"><h2>' . esc_html__( 'کتابخانه علاقه‌مندی‌ها', 'music-wave-core' ) . '</h2><p class="mw-account-library__intro">' . esc_html__( 'انتشارهای حفاظت‌شده فعلی شما در اینجا ظاهر می‌شوند. با شروع هر بارگیری، دسترسی دوباره بررسی می‌شود.', 'music-wave-core' ) . '</p>';
		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'در حال حاضر هیچ انتشار حفاظت‌شده‌ای در کتابخانه شما موجود نیست.', 'music-wave-core' ) . '</p></section>';
			return;
		}

		$this->enqueue_download_script();
		echo '<ul class="mw-account-library__items">' . implode( '', $items ) . '</ul></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each item is escaped inside item_markup().
	}

	/**
	 * Resolve releases the given subject currently has permission to download.
	 *
	 * @return array<int, int>
	 */
	public function entitled_release_ids( ?AccessSubject $subject = null ): array {
		$subject = null === $subject ? AccessSubject::current() : $subject;
		if ( $subject->user_id() < 1 ) {
			return array();
		}

		$release_ids = apply_filters( 'music_wave_account_library_release_ids', $this->candidate_release_ids(), $subject->user_id() );
		$release_ids = is_array( $release_ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $release_ids ) ) ) ) : array();

		return array_values(
			array_filter(
				$release_ids,
				function ( int $release_id ) use ( $subject ): bool {
					return ReleasePostType::KEY === get_post_type( $release_id ) && $this->policy->decide( $release_id, $subject )->is_allowed();
				}
			)
		);
	}

	/**
	 * Compose and output the unified tabbed account dashboard (`music-wave/account-dashboard`).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_dashboard( array $attributes ): void {
		$user_id = get_current_user_id();
		if ( $user_id < 1 && ! BlockSupport::is_editor_context() ) {
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- guest card and wrapper attributes are escaped in presenter/BlockSupport.
			echo $this->membership->render_guest_card(
				BlockSupport::wrapper_attributes( 'mw-user-dashboard mw-user-dashboard--guest' ),
				BlockSupport::current_url()
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		$context = $this->membership->context( $user_id );
		$has_vip = ! empty( $context['is_vip'] );
		$classes = 'mw-user-dashboard ' . ( $has_vip ? 'mw-user-dashboard--vip' : 'mw-user-dashboard--standard' );

		$show_welcome  = BlockSupport::bool_attribute( $attributes, 'showWelcome', false );
		$show_stats    = BlockSupport::bool_attribute( $attributes, 'showStats', false );
		$show_entitled = BlockSupport::bool_attribute( $attributes, 'showLibrary', true );
		$show_sections = BlockSupport::bool_attribute( $attributes, 'showQuickLinks', true );

		$panels      = $show_sections ? $this->dashboard_panels( $user_id, $show_entitled, $attributes ) : array();
		$panel_keys  = array_keys( $panels );
		$first_key   = ! empty( $panel_keys ) ? (string) $panel_keys[0] : '';
		$initial_tab = ! empty( $panels )
			? $this->woo_bridge->active_panel_key( $panel_keys, self::ENDPOINT, self::MEMBERSHIP_ENDPOINT )
			: '';
		if ( '' === $initial_tab || ! isset( $panels[ $initial_tab ] ) ) {
			$initial_tab = $first_key;
		}

		$initial_attr = '' !== $initial_tab ? ' data-mw-initial-tab="' . esc_attr( $initial_tab ) . '"' : '';
		echo '<section ' . BlockSupport::wrapper_attributes( $classes ) . ' data-mw-dashboard' . $initial_attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $show_welcome ) {
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- welcome header escapes every context value internally.
			echo $this->membership->render_welcome_header(
				$context,
				array(
					'show_avatar'    => true,
					'show_vip_badge' => true,
					'show_sub_meta'  => true,
					'show_renewal'   => true,
					'intro_text'     => BlockSupport::text_attribute(
						$attributes,
						'introText',
						__( 'میانبرهای حساب، دسترسی به موسیقی و کتابخانه علاقه‌مندی‌ها در یک مکان.', 'music-wave-core' )
					),
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( $show_stats ) {
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- stats list escapes counts and labels internally.
			echo $this->stats->render_list(
				$user_id,
				function ( int $uid ): int {
					return count( $this->entitled_release_ids( new AccessSubject( $uid ) ) );
				}
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( ! $show_sections ) {
			if ( $show_entitled ) {
				$this->render();
			}
			echo '</section>';
			return;
		}

		$logout_url = function_exists( 'wc_logout_url' ) ? wc_logout_url() : wp_logout_url( home_url( '/' ) );
		echo '<nav class="mw-user-dashboard__tabs" aria-label="' . esc_attr__( 'بخش‌های حساب', 'music-wave-core' ) . '">';
		foreach ( $panels as $key => $panel ) {
			$is_default_tab = ( $key === $initial_tab );
			$tab_classes    = 'mw-user-dashboard__tab' . ( $is_default_tab ? ' is-active' : '' );
			echo '<button type="button" class="' . esc_attr( $tab_classes ) . '" data-mw-dashboard-tab="' . esc_attr( $key ) . '" aria-controls="mw-dashboard-panel-' . esc_attr( $key ) . '" aria-expanded="true" aria-selected="' . ( $is_default_tab ? 'true' : 'false' ) . '">'
				. '<span aria-hidden="true">' . esc_html( (string) $panel['icon'] ) . '</span>'
				. '<strong>' . esc_html( (string) $panel['label'] ) . '</strong>'
				. '<small>' . esc_html( (string) $panel['description'] ) . '</small>'
				. '</button>';
		}
		if ( BlockSupport::bool_attribute( $attributes, 'showSignOut', true ) ) {
			echo '<a class="mw-user-dashboard__tab mw-user-dashboard__tab--logout" href="' . esc_url( $logout_url ) . '">'
				. '<span aria-hidden="true">&rarr;</span>'
				. '<strong>' . esc_html__( 'خروج از سیستم', 'music-wave-core' ) . '</strong>'
				. '<small>' . esc_html__( 'این جلسه حساب را ایمن ببندید.', 'music-wave-core' ) . '</small>'
				. '</a>';
		}
		echo '</nav>';

		// Progressive enhancement: panels stay visible on the server when JS fails;
		// `assets/dashboard.js` collapses them cleanly on load (`PROJECT_PLAN.md` §14).
		echo '<div class="mw-user-dashboard__panels">';
		foreach ( $panels as $key => $panel ) {
			echo '<section class="mw-user-dashboard__panel is-open" id="mw-dashboard-panel-' . esc_attr( $key ) . '" data-mw-dashboard-panel="' . esc_attr( $key ) . '">'
				. '<h3 class="mw-user-dashboard__panel-title">' . esc_html( (string) $panel['label'] ) . '</h3>'
				. '<div class="mw-user-dashboard__panel-content">' . $panel['content'] . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each panel builds escaped markup.
				. '</section>';
		}
		echo '</div></section>';

		$this->enqueue_dashboard_script();
	}

	/**
	 * Build visible panels for the current plugin environment & block settings.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<string, array<string, string>>
	 */
	private function dashboard_panels( int $user_id, bool $show_entitled, array $attributes = array() ): array {
		$on        = static function ( string $key ) use ( $attributes ): bool {
			return BlockSupport::bool_attribute( $attributes, $key, true );
		};
		$heading   = static function ( string $key, string $fallback ) use ( $attributes ): string {
			return BlockSupport::text_attribute( $attributes, $key, $fallback );
		};
		$is_editor = BlockSupport::is_editor_context();
		$has_woo   = class_exists( 'WooCommerce' );

		if ( $has_woo && function_exists( 'WC' ) && is_object( WC() ) && method_exists( WC(), 'frontend_includes' ) ) {
			WC()->frontend_includes();
		}

		$panels = array();
		if ( $on( 'showLibrary' ) ) {
			$panels['library'] = array(
				'icon'        => '♥',
				'label'       => $heading( 'libraryHeading', __( 'کتابخانه علاقه‌مندی‌ها', 'music-wave-core' ) ),
				'description' => __( 'آهنگ‌ها، آلبوم‌ها و هنرمندان ذخیره‌شده شما.', 'music-wave-core' ),
				'content'     => $this->library_panel_content( $show_entitled ),
			);
		}

		if ( $has_woo || $is_editor ) {
			if ( $on( 'showOrders' ) ) {
				$panels['orders'] = array(
					'icon'        => '◎',
					'label'       => $heading( 'ordersHeading', __( 'سفارش‌ها', 'music-wave-core' ) ),
					'description' => __( 'وضعیت پرداخت و جزئیات اشتراک را بررسی کنید.', 'music-wave-core' ),
					'content'     => $has_woo ? $this->woo_bridge->capture_panel( 'orders' ) : $this->woo_bridge->render_orders_block( array( 'showHeading' => false ) ),
				);
			}
			if ( $on( 'showDownloads' ) && ( ! $has_woo || function_exists( 'woocommerce_account_downloads' ) || $is_editor ) ) {
				$panels['downloads'] = array(
					'icon'        => '↓',
					'label'       => $heading( 'downloadsHeading', __( 'دانلودها', 'music-wave-core' ) ),
					'description' => __( 'فایل‌های خریداری‌شده و قابل دانلود حساب شما.', 'music-wave-core' ),
					'content'     => ( $has_woo && function_exists( 'woocommerce_account_downloads' ) ) ? $this->woo_bridge->capture_panel( 'downloads' ) : $this->woo_bridge->render_downloads_block( array( 'showHeading' => false ) ),
				);
			}
			if ( $on( 'showAddresses' ) && ( ! $has_woo || function_exists( 'woocommerce_account_edit_address' ) || $is_editor ) ) {
				$panels['addresses'] = array(
					'icon'        => '⌂',
					'label'       => $heading( 'addressesHeading', __( 'آدرس‌ها', 'music-wave-core' ) ),
					'description' => __( 'آدرس صورتحساب و حمل‌ونقل خود را ویرایش کنید.', 'music-wave-core' ),
					'content'     => ( $has_woo && function_exists( 'woocommerce_account_edit_address' ) ) ? $this->woo_bridge->capture_panel( 'addresses' ) : $this->woo_bridge->render_addresses_block( array( 'showHeading' => false ) ),
				);
			}
			if ( $on( 'showPaymentMethods' ) && ( ! $has_woo || function_exists( 'woocommerce_account_payment_methods' ) || $is_editor ) ) {
				$panels['payment'] = array(
					'icon'        => '◈',
					'label'       => $heading( 'paymentHeading', __( 'روش‌های پرداخت', 'music-wave-core' ) ),
					'description' => __( 'روش‌های پرداخت ذخیره‌شده خود را مدیریت کنید.', 'music-wave-core' ),
					'content'     => ( $has_woo && function_exists( 'woocommerce_account_payment_methods' ) ) ? $this->woo_bridge->capture_panel( 'payment' ) : $this->woo_bridge->render_payment_block( array( 'showHeading' => false ) ),
				);
			}
		}

		if ( $on( 'showAccountDetails' ) ) {
			$panels['account'] = array(
				'icon'        => '✎',
				'label'       => $heading( 'accountHeading', __( 'جزئیات و ویرایش حساب', 'music-wave-core' ) ),
				'description' => __( 'نام نمایشی، ایمیل و گذرواژه خود را ویرایش کنید.', 'music-wave-core' ),
				'content'     => $has_woo
					? $this->woo_bridge->capture_panel( 'account' )
					: $this->woo_bridge->render_account_edit_block(
						array(
							'showHeading' => false,
							'compact'     => true,
						)
					),
			);
		}

		if ( $on( 'showRequests' ) ) {
			$panels['requests'] = array(
				'icon'        => '✦',
				'label'       => $heading( 'requestsHeading', __( 'پیگیری درخواست‌ها', 'music-wave-core' ) ),
				'description' => __( 'وضعیت درخواست‌های آهنگ و همکاری و پاسخ مدیریت.', 'music-wave-core' ),
				'content'     => $this->requests_renderer->render(
					array(
						'showHeading'   => false,
						'compact'       => true,
						'perPage'       => 6,
						'showStatus'    => true,
						'showReference' => true,
					)
				),
			);
		}

		if ( ( VipModuleStatus::is_active() || $is_editor ) && $on( 'showMembershipPanel' ) ) {
			$panels['membership'] = array(
				'icon'        => '★',
				'label'       => $heading( 'membershipHeading', __( 'اشتراک VIP', 'music-wave-core' ) ),
				'description' => __( 'وضعیت اشتراک ویژه و طرح‌های قابل خرید.', 'music-wave-core' ),
				'content'     => $this->membership->render_panel( $user_id ),
			);
		}

		$filtered = apply_filters( 'music_wave_dashboard_panels', $panels, $user_id );
		$panels   = is_array( $filtered ) ? $filtered : $panels;

		if ( ! $on( 'showPlaylists' ) ) {
			unset( $panels['playlists'] );
		} else {
			if ( ! isset( $panels['playlists'] ) && $is_editor ) {
				$panels['playlists'] = array(
					'icon'        => '♫',
					'label'       => __( 'فهرست‌های پخش', 'music-wave-core' ),
					'description' => __( 'فهرست‌های پخش شخصی و اشتراکی ساخته‌شده توسط شما.', 'music-wave-core' ),
					'content'     => '<p class="mw-user-dashboard__empty-panel">' . esc_html__( 'فهرست‌های پخش شخصی کاربر در این بخش نمایش داده می‌شوند.', 'music-wave-core' ) . '</p>',
				);
			}
			$playlists_heading = $heading( 'playlistsHeading', '' );
			if ( '' !== $playlists_heading && isset( $panels['playlists'] ) && is_array( $panels['playlists'] ) ) {
				$panels['playlists']['label'] = $playlists_heading;
			}
		}

		$preset = isset( $attributes['panelOrder'] ) && is_string( $attributes['panelOrder'] ) ? $attributes['panelOrder'] : 'default';
		return $this->order_panels( $panels, $preset );
	}

	/**
	 * Order panels by preset key (`default`, `commerce_first`, `membership_first`)
	 * OR by an explicit comma-separated key sequence from the interactive panel sorter.
	 *
	 * @param array<string, array<string, string>> $panels Panels keyed by id.
	 * @return array<string, array<string, string>>
	 */
	private function order_panels( array $panels, string $preset ): array {
		$order   = array( 'library', 'playlists', 'orders', 'downloads', 'addresses', 'payment', 'account', 'requests', 'membership' );
		$aliases = array(
			'payment-methods' => 'payment',
			'account-details' => 'account',
		);
		if ( 'commerce_first' === $preset ) {
			$order = array( 'orders', 'downloads', 'addresses', 'payment', 'account', 'requests', 'library', 'playlists', 'membership' );
		} elseif ( 'membership_first' === $preset ) {
			$order = array( 'membership', 'library', 'playlists', 'orders', 'downloads', 'addresses', 'payment', 'account', 'requests' );
		} elseif ( false !== strpos( $preset, ',' ) ) {
			$raw_keys = array_map( 'sanitize_key', explode( ',', $preset ) );
			$mapped   = array();
			foreach ( $raw_keys as $raw_key ) {
				if ( '' !== $raw_key ) {
					$mapped[] = isset( $aliases[ $raw_key ] ) ? $aliases[ $raw_key ] : $raw_key;
				}
			}
			$custom = array_values( array_unique( $mapped ) );
			if ( ! empty( $custom ) ) {
				$order = array_values( array_unique( array_merge( $custom, $order ) ) );
			}
		}

		$ordered = array();
		foreach ( $order as $key ) {
			if ( isset( $panels[ $key ] ) ) {
				$ordered[ $key ] = $panels[ $key ];
				unset( $panels[ $key ] );
			}
		}

		return $ordered + $panels;
	}

	private function library_panel_content( bool $show_entitled ): string {
		$personal = function_exists( 'do_blocks' )
			? do_blocks( '<!-- wp:music-wave/music-library {"showHeading":false} /-->' )
			: '';
		$entitled = '';
		if ( $show_entitled ) {
			ob_start();
			$this->render();
			$entitled = (string) ob_get_clean();
		}
		$content = $personal . $entitled;
		if ( '' === trim( $content ) ) {
			return '<p class="mw-user-dashboard__empty-panel">' . esc_html__( 'هنوز چیزی در کتابخانه شما موجود نیست. انتشارات را با دکمه «افزودن به کتابخانه» ذخیره کنید یا قفل موسیقی حفاظت‌شده را باز کنید.', 'music-wave-core' ) . '</p>';
		}

		return $content;
	}

	/**
	 * @return array<int, int>
	 */
	private function candidate_release_ids(): array {
		$posts = get_posts(
			array(
				'post_type'      => ReleasePostType::KEY,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => 'mw_access_mode',
						'value'   => array( 'purchase', 'membership', 'purchase_or_membership' ),
						'compare' => 'IN',
					),
				),
			)
		);

		return is_array( $posts ) ? array_map( 'absint', $posts ) : array();
	}

	private function item_markup( int $release_id ): string {
		$assets = ( new ReleaseDownloadCatalog( $this->releases ) )->assets( $release_id );
		$files  = ReleaseDownloadCatalog::group_files( $assets, false );
		$first  = reset( $files );
		if ( ! is_array( $first ) || empty( $first['qualities'] ) || ! is_array( $first['qualities'] ) ) {
			return '';
		}

		$title   = get_the_title( $release_id );
		$title   = '' !== $title ? $title : __( 'انتشار بدون عنوان', 'music-wave-core' );
		$link    = get_permalink( $release_id );
		$image   = get_the_post_thumbnail(
			$release_id,
			'thumbnail',
			array(
				'class' => 'mw-account-library__image',
				'alt'   => '',
			)
		);
		$type    = wp_get_post_terms( $release_id, 'mw_release_type', array( 'fields' => 'names' ) );
		$label   = is_array( $type ) && ! empty( $type ) ? implode( ', ', $type ) : __( 'انتشار', 'music-wave-core' );
		$quality = $first['qualities'][0];

		$encoded_files = function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( array_values( $files ) ) : (string) json_encode( array_values( $files ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- standalone CLI test fallback.

		return '<li class="mw-account-library__item">'
			. $image
			. '<div class="mw-account-library__details"><span>' . esc_html( $label ) . '</span><strong>'
			. ( is_string( $link ) ? '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) )
			. '</strong></div>'
			. '<div class="mw-account-library__actions mw-download-action" data-download-files="' . esc_attr( $encoded_files ) . '">'
			. $this->download_controls( $files )
			. '<button class="button mw-download-button" type="button" data-release-id="' . esc_attr( (string) $release_id ) . '" data-download-quality="' . esc_attr( (string) $quality['key'] ) . '">' . esc_html__( 'دانلود ایمن', 'music-wave-core' ) . '</button>'
			. '<span class="mw-download-status" role="status" aria-live="polite"></span>'
			. '</div></li>';
	}

	/**
	 * @param array<string, array<string, mixed>> $files Grouped downloadable files.
	 */
	private function download_controls( array $files ): string {
		$first = reset( $files );
		if ( ! is_array( $first ) || empty( $first['qualities'] ) || ! is_array( $first['qualities'] ) ) {
			return '';
		}

		$markup = '<div class="mw-download-action__controls">';
		if ( count( $files ) > 1 ) {
			$options = array();
			foreach ( $files as $file ) {
				$options[] = '<option value="' . esc_attr( (string) $file['key'] ) . '">' . esc_html( (string) $file['label'] ) . '</option>';
			}
			$markup .= '<label class="mw-download-action__file"><span>' . esc_html__( 'دانلود فایل', 'music-wave-core' ) . '</span><select class="mw-download-file">' . implode( '', $options ) . '</select></label>';
		}

		$options = array();
		foreach ( $first['qualities'] as $quality ) {
			$options[] = '<option value="' . esc_attr( (string) $quality['key'] ) . '">' . esc_html( (string) $quality['label'] ) . '</option>';
		}

		return $markup . '<label class="mw-download-action__quality"><span>' . esc_html__( 'کیفیت دانلود', 'music-wave-core' ) . '</span><select class="mw-download-quality">' . implode( '', $options ) . '</select></label></div>';
	}

	/**
	 * Enqueue lightweight delegated account dashboard tab runtime across public routes
	 * so client-side SPA navigations into `/account/` hydrate in 0ms without reload.
	 */
	public function enqueue_frontend_assets(): void {
		$this->enqueue_dashboard_script();
	}

	private function enqueue_dashboard_script(): void {
		if ( is_admin() ) {
			return;
		}
		wp_enqueue_script(
			'music-wave-dashboard',
			MUSIC_WAVE_CORE_URL . 'assets/dashboard.js',
			array(),
			MUSIC_WAVE_CORE_VERSION,
			true
		);
	}

	private function enqueue_download_script(): void {
		wp_enqueue_script( 'music-wave-download', MUSIC_WAVE_CORE_URL . 'assets/download.js', array( 'wp-api-fetch' ), MUSIC_WAVE_CORE_VERSION, true );
		wp_localize_script(
			'music-wave-download',
			'musicWaveDownload',
			array(
				'restUrl'      => esc_url_raw( rest_url() ),
				'restNonce'    => wp_create_nonce( 'wp_rest' ),
				'errorMessage' => __( 'دانلود شروع نشد.', 'music-wave-core' ),
				'sessionError' => __( 'جلسه شما تمام شده است. صفحه را تازه کنید یا دوباره وارد شوید.', 'music-wave-core' ),
			)
		);
	}
}
