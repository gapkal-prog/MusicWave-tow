<?php
/**
 * Plugin Name:       MusicWave VIP Integration
 * Description:       Configurable protected-file delivery, remote-host signing, WooCommerce plan membership, WordPress user management integration, and membership adapters for MusicWave Core.
 * Version:           0.5.2
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            ManaCore
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       music-wave-vip
 * Domain Path:       /languages
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MUSIC_WAVE_VIP_FILE', __FILE__ );
define( 'MUSIC_WAVE_VIP_PATH', plugin_dir_path( __FILE__ ) );
define( 'MUSIC_WAVE_VIP_URL', plugin_dir_url( __FILE__ ) );
define( 'MUSIC_WAVE_VIP_VERSION', '0.5.2' );

require_once MUSIC_WAVE_VIP_PATH . 'src/Autoloader.php';
ManaCore\MusicWave\Vip\Autoloader::register();

/**
 * Load translations from the plugin language directory.
 */
function music_wave_vip_load_textdomain(): void {
	load_plugin_textdomain(
		'music-wave-vip',
		false,
		dirname( plugin_basename( MUSIC_WAVE_VIP_FILE ) ) . '/languages'
	);
}
add_action( 'init', 'music_wave_vip_load_textdomain', 1 );

add_action( 'admin_notices', array( ManaCore\MusicWave\Vip\Plugin::class, 'core_notice' ) );

if ( ! ManaCore\MusicWave\Vip\Plugin::core_meets_minimum() ) {
	return;
}

$music_wave_vip_storage = new ManaCore\MusicWave\Vip\ProtectedAssetStorage();
add_filter(
	'music_wave_membership_provider',
	static function () {
		return ManaCore\MusicWave\Vip\ProviderFactory::membership_provider();
	}
);
add_filter(
	'music_wave_download_provider',
	static function () use ( $music_wave_vip_storage ) {
		return ManaCore\MusicWave\Vip\ProviderFactory::download_provider( $music_wave_vip_storage );
	}
);

/**
 * Whether VIP entitlement enforcement is switched on.
 */
function music_wave_vip_module_enabled(): bool {
	$config = ManaCore\MusicWave\Vip\VipSettings::all();

	return 'disabled' !== (string) $config['module_enabled'];
}

/**
 * Master switch (`module_enabled`) promotional override:
 * - Opens `membership_required` and `purchase_or_membership_required` ONLY when
 *   `module_enabled === 'disabled'`.
 * - NEVER opens paid VIP releases merely because `delivery_access === 'everyone'`.
 * - Pure WooCommerce `purchase` and `restricted` releases always remain protected.
 */
add_filter(
	'music_wave_access_decision',
	static function ( $decision ) {
		if ( ! $decision instanceof ManaCore\MusicWave\Core\Access\AccessDecision || $decision->is_allowed() ) {
			return $decision;
		}
		if ( ! in_array( $decision->reason(), array( 'membership_required', 'purchase_or_membership_required' ), true ) ) {
			return $decision;
		}
		if ( ! music_wave_vip_module_enabled() ) {
			return ManaCore\MusicWave\Core\Access\AccessDecision::allow(
				'vip_module_disabled',
				$decision->mode()
			);
		}

		return $decision;
	},
	20,
	1
);

/**
 * Enforce `delivery_access` (`logged_in` vs `everyone`) cleanly at the download/stream
 * boundary without leaking paid VIP content to anonymous visitors.
 */
add_filter(
	'music_wave_guest_delivery_allowed',
	static function (): bool {
		$config = ManaCore\MusicWave\Vip\VipSettings::all();

		return 'everyone' === (string) $config['delivery_access'];
	}
);

/**
 * Smart automatic fallback for `membership_cta_url` when unconfigured in Core settings:
 * discovers a published page carrying VIP/dashboard blocks or the first active VIP plan permalink.
 */
add_filter(
	'music_wave_membership_cta_url',
	static function ( string $url ): string {
		if ( '' !== trim( $url ) ) {
			return $url;
		}

		static $resolved = null;
		if ( null !== $resolved ) {
			return $resolved;
		}

		$resolved = '';
		if ( class_exists( 'WP_Query' ) ) {
			$pages = new WP_Query(
				array(
					'post_type'              => 'page',
					'post_status'            => 'publish',
					'posts_per_page'         => 20,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			foreach ( is_array( $pages->posts ) ? $pages->posts : array() as $page_id ) {
				$post = get_post( absint( $page_id ) );
				if ( ! is_object( $post ) || ! property_exists( $post, 'post_content' ) ) {
					continue;
				}
				$content = (string) $post->post_content;
				if (
					false !== strpos( $content, 'music-wave/vip-plans' )
					|| false !== strpos( $content, 'music-wave/account-library' )
					|| false !== strpos( $content, 'music-wave/user-dashboard' )
				) {
					$link = get_permalink( absint( $page_id ) );
					if ( is_string( $link ) && '' !== $link ) {
						$resolved = $link;
						return $resolved;
					}
				}
			}
		}

		$panel = ManaCore\MusicWave\Vip\PlanProducts::products_data( 0 );
		if ( ! empty( $panel['plans'] ) && is_array( $panel['plans'] ) ) {
			foreach ( $panel['plans'] as $plan ) {
				if ( is_array( $plan ) && ! empty( $plan['url'] ) && is_string( $plan['url'] ) ) {
					$resolved = $plan['url'];
					return $resolved;
				}
			}
		}

		return $resolved;
	}
);

/**
 * Bootstrap WooCommerce order grants/revocations, expiry sweeps, and WP user management panels.
 *
 * Order lifecycle hooks remain active even when `module_enabled === 'disabled'` so customers
 * purchasing subscriptions during promotional free windows always receive their paid VIP time.
 */
function music_wave_vip_bootstrap_plans(): void {
	$plans = ManaCore\MusicWave\Vip\VipPlans::from_settings();

	$inspector  = new ManaCore\MusicWave\Vip\Users\UserActivityInspector( $plans );
	$users_list = new ManaCore\MusicWave\Vip\Users\UsersListIntegration( $plans, $inspector );
	$users_list->register();
	( new ManaCore\MusicWave\Vip\Users\UserProfilePanel( $plans, $users_list, $inspector ) )->register();

	$plans->register_hooks();

	add_action( ManaCore\MusicWave\Vip\VipPlans::EXPIRY_CRON, array( $plans, 'handle_expiry_check' ) );
	add_action( 'admin_init', array( $plans, 'maybe_handle_expiry_check' ), 30 );
	add_action(
		'wp_login',
		static function ( $user_login, $user ) use ( $plans ): void {
			unset( $user_login );
			if ( is_object( $user ) && isset( $user->ID ) ) {
				$plans->maybe_handle_expiry_check();
			}
		},
		10,
		2
	);

	$GLOBALS['music_wave_vip_plans'] = $plans;
}
add_action( 'plugins_loaded', 'music_wave_vip_bootstrap_plans', 20 );
add_action( 'init', array( ManaCore\MusicWave\Vip\VipPlans::class, 'ensure_role' ), 5 );
add_action( 'init', array( ManaCore\MusicWave\Vip\VipPlans::class, 'schedule_expiry_checks' ), 20 );

register_activation_hook(
	MUSIC_WAVE_VIP_FILE,
	static function () use ( $music_wave_vip_storage ): void {
		ManaCore\MusicWave\Vip\VipPlans::schedule_expiry_checks();
		$music_wave_vip_storage->provision();
	}
);
register_deactivation_hook( MUSIC_WAVE_VIP_FILE, array( ManaCore\MusicWave\Vip\VipPlans::class, 'clear_expiry_checks' ) );

/**
 * Surface active plan levels alongside roles in dashboard and level displays.
 *
 * @param mixed $levels  Existing level slugs for the user.
 * @param mixed $user_id Requested user.
 */
add_filter(
	'music_wave_vip_membership_levels_for_user',
	static function ( $levels, $user_id ) {
		if ( ! music_wave_vip_module_enabled() ) {
			return $levels;
		}
		$config = ManaCore\MusicWave\Vip\VipSettings::all();
		if ( ! in_array( 'woocommerce_plans', (array) $config['membership_sources'], true ) ) {
			return $levels;
		}
		$existing = is_array( $levels ) ? array_map( 'strval', $levels ) : array();
		$plans    = ManaCore\MusicWave\Vip\VipPlans::from_settings( $config );
		return array_values( array_unique( array_merge( $existing, $plans->active_levels( absint( $user_id ) ) ) ) );
	},
	20,
	2
);

/**
 * Bootstrap plan product provisioner, direct virtual checkout, and single-product VIP presenter.
 */
function music_wave_vip_bootstrap_products_and_checkout(): void {
	$plan_products = new ManaCore\MusicWave\Vip\PlanProducts();
	$plan_products->register();

	$checkout = new ManaCore\MusicWave\Vip\CheckoutCustomizer();
	$checkout->register();

	$product_presenter = new ManaCore\MusicWave\Vip\Plans\VipProductPagePresenter();
	$product_presenter->register();

	$GLOBALS['music_wave_vip_plan_products'] = $plan_products;
	$GLOBALS['music_wave_vip_checkout']      = $checkout;
}
add_action( 'plugins_loaded', 'music_wave_vip_bootstrap_products_and_checkout', 21 );

add_filter(
	'music_wave_vip_membership_panel',
	static function ( $data, $user_id ) {
		unset( $data );
		return ManaCore\MusicWave\Vip\PlanProducts::products_data( absint( $user_id ) );
	},
	10,
	2
);

/**
 * Expose all configured VIP levels (`mw_vip` umbrella + WooCommerce plans) to
 * `ReleaseMetaBox` and `BulkAccessManager` so editors can pick levels with one click.
 */
add_filter(
	'music_wave_available_membership_levels',
	static function ( $levels ): array {
		$items = is_array( $levels ) ? $levels : array();
		$items[ ManaCore\MusicWave\Vip\VipPlans::VIP_ROLE ] = __( 'همه مشترکین فعال VIP (mw_vip)', 'music-wave-vip' );

		$config = ManaCore\MusicWave\Vip\VipSettings::all();
		$plans  = isset( $config['vip_plans'] ) && is_array( $config['vip_plans'] ) ? $config['vip_plans'] : array();
		foreach ( $plans as $plan ) {
			if ( ! is_array( $plan ) || empty( $plan['level'] ) ) {
				continue;
			}
			$slug = sanitize_key( (string) $plan['level'] );
			if ( '' === $slug ) {
				continue;
			}
			$title = '';
			$pids  = isset( $plan['product_ids'] ) && is_array( $plan['product_ids'] ) ? array_map( 'absint', $plan['product_ids'] ) : array();
			foreach ( $pids as $pid ) {
				if ( $pid > 0 ) {
					$candidate = get_the_title( $pid );
					if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
						$title = trim( $candidate );
						break;
					}
				}
			}
			if ( '' === $title ) {
				$days   = isset( $plan['duration_days'] ) ? (int) $plan['duration_days'] : 0;
				$preset = ManaCore\MusicWave\Vip\Plans\VipPlanCatalog::preset_for( $slug, $days );
				$title  = null !== $preset && ! empty( $preset['title'] ) ? (string) $preset['title'] : strtoupper( $slug );
			}
			$items[ $slug ] = sprintf( '%s (%s)', $title, $slug );
		}

		return $items;
	}
);

add_filter(
	'music_wave_default_membership_level',
	static function (): string {
		return ManaCore\MusicWave\Vip\VipPlans::VIP_ROLE;
	}
);

$music_wave_vip_settings = new ManaCore\MusicWave\Vip\SettingsPage();
add_action( 'admin_menu', array( $music_wave_vip_settings, 'add_page' ) );
add_action( 'admin_init', array( $music_wave_vip_settings, 'register' ) );
add_filter( 'music_wave_admin_integrations', array( $music_wave_vip_settings, 'integration_card' ) );

add_filter(
	'plugin_action_links_' . plugin_basename( MUSIC_WAVE_VIP_FILE ),
	static function ( array $links ): array {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'users.php?mw_vip_filter=active' ) ) . '">' . esc_html__( 'اعضای VIP', 'music-wave-vip' ) . '</a>',
			'<a href="' . esc_url( ManaCore\MusicWave\Vip\SettingsPage::page_url() ) . '">' . esc_html__( 'تنظیمات', 'music-wave-vip' ) . '</a>'
		);
		return $links;
	}
);

$music_wave_vip_asset_routes = new ManaCore\MusicWave\Vip\ProtectedAssetRoutes( $music_wave_vip_storage );
$music_wave_vip_asset_routes->register();
( new ManaCore\MusicWave\Vip\PrivacyPolicy() )->register();

/**
 * Provider-side authorization for assigning local protected assets.
 *
 * Accepts either dedicated `manage_mw_protected_assets` or administrator `manage_options`.
 *
 * @param mixed $authorized Current authorization decision.
 * @param mixed $asset_id   Candidate asset identifier.
 */
add_filter(
	'music_wave_can_assign_download_asset',
	static function ( $authorized, $asset_id ) use ( $music_wave_vip_storage ) {
		$asset_id = (string) $asset_id;
		$is_vip   = 0 === strpos( $asset_id, ManaCore\MusicWave\Vip\ProtectedAssetRegistry::PREFIX );
		if ( null !== $authorized || ( ! $is_vip && 0 !== strpos( $asset_id, 'local:' ) ) ) {
			return $authorized;
		}
		if ( ! current_user_can( 'manage_mw_protected_assets' ) && ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( $is_vip ) {
			return $music_wave_vip_storage->registry()->exists( $asset_id );
		}

		return false !== $music_wave_vip_storage->resolve( $asset_id );
	},
	10,
	2
);

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MUSIC_WAVE_VIP_FILE, true );
		}
	}
);
