<?php
/**
 * WooCommerce plan-product management helpers.
 *
 * Plans are ordinary WooCommerce products; this class provisions the 1/6/12-month
 * presets with full capabilities descriptions & tier icons on demand, upgrades
 * legacy 1-line placeholder descriptions automatically without overwriting custom
 * edits, and builds the customer-facing product data used by `.mw-membership__plan`
 * cards and single product pages.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip;

use ManaCore\MusicWave\Vip\Plans\VipPlanCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PlanProducts {
	public const ACTION_CREATE_DEFAULTS = 'music_wave_vip_create_default_plans';
	public const ACTION_REMOVE_PLAN     = 'music_wave_vip_remove_plan';
	public const DURATION_META          = '_mw_vip_plan_duration_days';
	public const LEVEL_META             = '_mw_vip_plan_level';
	public const ICON_META              = '_mw_vip_plan_icon';

	/**
	 * Preset plans created by the one-click button: 1, 6, and 12 months.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function presets(): array {
		return VipPlanCatalog::presets();
	}

	/**
	 * Register the guarded admin actions used by the settings screen.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_CREATE_DEFAULTS, array( $this, 'handle_create_defaults' ) );
		add_action( 'admin_post_' . self::ACTION_REMOVE_PLAN, array( $this, 'handle_remove_plan' ) );
	}

	/**
	 * One-click provisioning of the 1/6/12-month plan products with rich capabilities & icons.
	 *
	 * Idempotent: existing mappings are preserved, AND if an existing mapped product still
	 * holds empty or legacy 1-line placeholder text, its `post_content`, `post_excerpt`,
	 * and tier icon meta are cleanly enriched without touching custom pricing or custom copy.
	 *
	 * @return array{created: int, upgraded: int, levels: array<int, string>, skipped: array<int, string>, failed: array<int, string>}
	 */
	public static function create_defaults(): array {
		$result = array(
			'created'  => 0,
			'upgraded' => 0,
			'levels'   => array(),
			'skipped'  => array(),
			'failed'   => array(),
		);
		if ( ! post_type_exists( 'product' ) ) {
			$result['failed'][] = 'woocommerce';
			return $result;
		}

		$config   = VipSettings::all();
		$plans    = isset( $config['vip_plans'] ) && is_array( $config['vip_plans'] ) ? $config['vip_plans'] : array();
		$by_level = array();
		foreach ( $plans as $plan ) {
			if ( is_array( $plan ) && ! empty( $plan['level'] ) ) {
				$by_level[ sanitize_key( (string) $plan['level'] ) ] = isset( $plan['product_ids'] ) && is_array( $plan['product_ids'] )
					? array_map( 'absint', $plan['product_ids'] )
					: array();
			}
		}

		$rows = (string) $config['plan_rows'];
		foreach ( VipPlanCatalog::presets() as $preset ) {
			$lvl = $preset['level'];
			if ( isset( $by_level[ $lvl ] ) ) {
				$result['skipped'][] = $lvl;
				// Enrich existing legacy placeholder products with full capabilities & icon.
				foreach ( $by_level[ $lvl ] as $existing_pid ) {
					if ( $existing_pid > 0 && self::maybe_enrich_existing_product( $existing_pid, $preset ) ) {
						++$result['upgraded'];
					}
				}
				continue;
			}

			$product_id = wp_insert_post(
				array(
					'post_title'   => $preset['title'],
					'post_status'  => 'publish',
					'post_type'    => 'product',
					'post_excerpt' => $preset['summary'],
					'post_content' => VipPlanCatalog::build_preset_html( $preset ),
				),
				true
			);
			if ( is_wp_error( $product_id ) || $product_id < 1 ) {
				$result['failed'][] = $lvl;
				continue;
			}
			update_post_meta( $product_id, '_virtual', 'yes' );
			// Single-seat: a plan is one line at quantity 1, and the cart holds at most one plan.
			update_post_meta( $product_id, '_sold_individually', 'yes' );
			update_post_meta( $product_id, '_manage_stock', 'no' );
			update_post_meta( $product_id, '_downloadable', 'no' );
			update_post_meta( $product_id, '_regular_price', '0' );
			update_post_meta( $product_id, '_price', '0' );
			// Ensure product is simple and purchasable.
			wp_set_object_terms( $product_id, array( 'simple' ), 'product_type', false );
			update_post_meta( $product_id, self::DURATION_META, (string) $preset['days'] );
			update_post_meta( $product_id, self::LEVEL_META, $lvl );
			update_post_meta( $product_id, self::ICON_META, $preset['icon'] );

			$rows = trim( $rows ) . "\n" . $lvl . ':' . (int) $product_id . ':' . $preset['days'];
			++$result['created'];
			$result['levels'][] = $lvl . ':' . (int) $product_id;
		}

		if ( $result['created'] > 0 ) {
			update_option( VipSettings::OPTION, VipSettings::sanitize( array( 'plan_rows' => trim( $rows ) ) ) );
		}

		return $result;
	}

	/**
	 * Guarded admin action: create or enrich the default plan products.
	 *
	 * @return void
	 */
	public function handle_create_defaults(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'اجازهٔ مدیریت طرح‌های VIP را ندارید.', 'music-wave-vip' ) );
		}
		check_admin_referer( self::ACTION_CREATE_DEFAULTS );

		$result  = self::create_defaults();
		$created = $result['created'] + $result['upgraded'];
		$notice  = 'mwvip_defaults_failed';
		if ( $created > 0 ) {
			$notice = 'mwvip_defaults_created';
		} elseif ( ! empty( $result['skipped'] ) && empty( $result['failed'] ) ) {
			$notice = 'mwvip_defaults_skipped';
		}
		set_transient( 'mwvip_plan_action_notice', $notice, 60 );

		wp_safe_redirect( SettingsPage::page_url() );
		exit;
	}

	/**
	 * Guarded admin action: remove one plan mapping row (not the product).
	 *
	 * FIX-15: State change via POST only, not GET (prefetch/scanners). Uses
	 * form attribute to avoid nested forms inside main settings form.
	 *
	 * @return void
	 */
	public function handle_remove_plan(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'اجازهٔ مدیریت طرح‌های VIP را ندارید.', 'music-wave-vip' ) );
		}
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== strtoupper( $request_method ) ) {
			wp_die( esc_html__( 'روش درخواست نامعتبر است. حذف طرح باید با POST انجام شود.', 'music-wave-vip' ), '', array( 'response' => 405 ) );
		}
		check_admin_referer( self::ACTION_REMOVE_PLAN );

		$level   = isset( $_POST['level'] ) ? sanitize_key( (string) wp_unslash( $_POST['level'] ) ) : '';
		$config  = VipSettings::all();
		$plans   = isset( $config['vip_plans'] ) && is_array( $config['vip_plans'] ) ? $config['vip_plans'] : array();
		$removed = false;
		$kept    = array();
		foreach ( $plans as $plan ) {
			if ( is_array( $plan ) && isset( $plan['level'] ) && sanitize_key( (string) $plan['level'] ) === $level ) {
				$removed = true;
				continue;
			}
			if ( is_array( $plan ) ) {
				$kept[] = $plan;
			}
		}
		if ( $removed ) {
			update_option(
				VipSettings::OPTION,
				VipSettings::sanitize(
					array(
						'vip_plans' => $kept,
						'plan_rows' => implode( "\n", VipPlans::plan_rows_for_display( $kept ) ),
					)
				)
			);
			set_transient( 'mwvip_plan_action_notice', 'mwvip_plan_removed', 60 );
		}

		wp_safe_redirect( SettingsPage::page_url() );
		exit;
	}

	/**
	 * Customer-facing plan/product data for `.mw-membership__plan` cards & product pages.
	 *
	 * @param int $user_id Account owner (0 for guests).
	 * @return array<string, mixed>
	 */
	public static function products_data( int $user_id ): array {
		$user_id = absint( $user_id );
		$config  = VipSettings::all();
		$plans   = isset( $config['vip_plans'] ) && is_array( $config['vip_plans'] ) ? $config['vip_plans'] : array();

		// Security: prevent one user viewing another's membership unless admin.
		if ( $user_id > 0 ) {
			$current = get_current_user_id();
			if ( $current !== $user_id && ! current_user_can( 'manage_options' ) && ! current_user_can( 'view_user_membership' ) ) {
				$user_id = 0;
			}
		}

		// Rate limiting: prevent rapid enumeration of membership data (max 30 calls per minute per user/IP).
		if ( $user_id > 0 ) {
			$rate_key = 'mwvip_panel_rate_' . $user_id;
			$count    = (int) get_transient( $rate_key );
			if ( $count > 30 ) {
				return array(
					'module_enabled'  => 'disabled' !== (string) $config['module_enabled'],
					'active'          => array(),
					'detailed_active' => array(),
					'renewal_needed'  => null,
					'plans'           => array(),
					'rate_limited'    => true,
				);
			}
			set_transient( $rate_key, $count + 1, 60 );
		}

		$active          = array();
		$detailed_active = array();
		$renewal_needed  = null;
		$by_level        = array();

		if ( $user_id > 0 ) {
			$vip_engine      = VipPlans::from_settings( $config );
			$detailed_active = $vip_engine->detailed_active_grants( $user_id );
			$renewal_needed  = $vip_engine->grant_needing_renewal( $user_id );

			foreach ( $detailed_active as $grant ) {
				if ( ! is_array( $grant ) || empty( $grant['level'] ) ) {
					continue;
				}
				$lvl = sanitize_key( (string) $grant['level'] );
				if ( '' === $lvl ) {
					continue;
				}
				$expires_label = isset( $grant['expires_label'] ) ? sanitize_text_field( (string) $grant['expires_label'] ) : '';
				$expires_at    = isset( $grant['expires_at'] ) ? absint( $grant['expires_at'] ) : 0;
				$granted_at    = isset( $grant['granted_at'] ) ? absint( $grant['granted_at'] ) : 0;
				$remaining     = isset( $grant['remaining_days'] ) ? max( 0, (int) $grant['remaining_days'] ) : 0;
				$percent       = isset( $grant['percent_used'] ) ? max( 0.0, min( 100.0, (float) $grant['percent_used'] ) ) : 0.0;
				$is_lifetime   = ! empty( $grant['is_lifetime'] );
				$order_id      = isset( $grant['order_id'] ) ? absint( $grant['order_id'] ) : 0;

				if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
					$order = wc_get_order( $order_id );
					if ( $order instanceof \WC_Order && (int) $order->get_customer_id() !== $user_id && ! current_user_can( 'manage_options' ) ) {
						$order_id = 0;
					}
				}

				$entry = array(
					'level'          => $lvl,
					'expires'        => $expires_label,
					'expires_label'  => $expires_label,
					'expires_at'     => $expires_at,
					'granted_at'     => $granted_at,
					'remaining_days' => $remaining,
					'percent_used'   => $percent,
					'is_lifetime'    => $is_lifetime,
					'is_reserved'    => ! empty( $grant['is_reserved'] ),
					'starts_label'   => isset( $grant['starts_label'] ) ? sanitize_text_field( (string) $grant['starts_label'] ) : '',
					'reserved_days'  => isset( $grant['reserved_days'] ) ? max( 0, (int) $grant['reserved_days'] ) : 0,
					'order_id'       => $order_id,
				);

				$existing = $by_level[ $lvl ] ?? null;
				if ( null === $existing ) {
					$by_level[ $lvl ] = $entry;
				} elseif ( ! empty( $existing['is_reserved'] ) && empty( $entry['is_reserved'] ) ) {
					// A live term always wins over a queued one for plan status.
					$by_level[ $lvl ] = $entry;
				} elseif ( ! empty( $existing['is_reserved'] ) === ! empty( $entry['is_reserved'] ) && ( $is_lifetime || $expires_at > (int) $existing['expires_at'] ) ) {
					$by_level[ $lvl ] = $entry;
				}
			}
			$active = array_values( $by_level );

			$detailed_active = apply_filters( 'music_wave_vip_detailed_active_grants', $detailed_active, $user_id );
			$detailed_active = is_array( $detailed_active ) ? $detailed_active : array();
		}

		$products = array();
		foreach ( $plans as $plan ) {
			if ( ! is_array( $plan ) || empty( $plan['level'] ) || empty( $plan['product_ids'] ) || ! is_array( $plan['product_ids'] ) ) {
				continue;
			}
			$level = sanitize_key( (string) $plan['level'] );
			if ( '' === $level ) {
				continue;
			}
			$days = isset( $plan['duration_days'] ) ? min( 3650, absint( $plan['duration_days'] ) ) : 0;

			foreach ( $plan['product_ids'] as $product_id ) {
				$product_id = absint( $product_id );
				if ( $product_id < 1 || 'product' !== get_post_type( $product_id ) ) {
					continue;
				}
				$product        = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
				$title          = sanitize_text_field( (string) get_the_title( $product_id ) );
				$url            = esc_url_raw( (string) get_permalink( $product_id ) );
				$cart           = '';
				$price          = '';
				$saving_percent = 0;

				if ( $product instanceof \WC_Product ) {
					$title     = sanitize_text_field( $product->get_name() );
					$price_raw = (string) $product->get_price_html();
					$price     = wp_kses(
						$price_raw,
						array(
							'del'  => array( 'aria-hidden' => array() ),
							'ins'  => array(),
							'span' => array( 'class' => array() ),
							'bdi'  => array(),
						)
					);
					$price     = trim( $price );

					$reg_price  = (float) $product->get_regular_price();
					$sale_price = (float) $product->get_sale_price();
					if ( $reg_price > 0 && $sale_price > 0 && $sale_price < $reg_price ) {
						$saving_percent = max( 1, (int) round( ( ( $reg_price - $sale_price ) / $reg_price ) * 100 ) );
					}

					if ( $product->is_purchasable() && $product->is_in_stock() ) {
						$direct = CheckoutCustomizer::build_direct_checkout_url( $product_id );
						$cart   = '' !== $direct ? esc_url_raw( $direct ) : esc_url_raw( $product->add_to_cart_url() );
					}
				}
				if ( '' !== $cart ) {
					$cart = esc_url_raw( $cart );
				}

				$copy       = VipPlanCatalog::extract_product_copy( $product_id, $level, $days );
				$saved_icon = function_exists( 'get_post_meta' ) ? (string) get_post_meta( $product_id, self::ICON_META, true ) : '';
				$icon       = '' !== trim( $saved_icon ) ? sanitize_key( $saved_icon ) : VipPlanCatalog::resolve_icon( $level, $days );
				$matched    = $by_level[ $level ] ?? null;

				$products[] = array(
					'id'             => $product_id,
					'level'          => $level,
					'title'          => $title,
					'price'          => $price,
					'saving_percent' => $saving_percent,
					'duration'       => sanitize_text_field( self::duration_label( $days ) ),
					'duration_days'  => $days,
					'icon'           => $icon,
					'badge'          => VipPlanCatalog::resolve_badge( $level, $days ),
					'description'    => $copy['description'],
					'features'       => $copy['features'],
					'url'            => $url,
					'cart_url'       => $cart,
					'is_active'      => null !== $matched && empty( $matched['is_reserved'] ),
					'is_reserved'    => null !== $matched && ! empty( $matched['is_reserved'] ),
					'is_lifetime'    => null !== $matched && ! empty( $matched['is_lifetime'] ),
					'remaining_days' => null !== $matched ? (int) $matched['remaining_days'] : null,
					'expires_label'  => null !== $matched ? (string) $matched['expires_label'] : '',
					'starts_label'   => null !== $matched && ! empty( $matched['is_reserved'] ) ? (string) $matched['starts_label'] : '',
					'reserved_days'  => null !== $matched && ! empty( $matched['is_reserved'] ) ? (int) $matched['reserved_days'] : 0,
				);
			}
		}

		// Sort products by duration ascending (lifetime `0` last) for natural progression.
		usort(
			$products,
			static function ( array $a, array $b ): int {
				$da = (int) $a['duration_days'];
				$db = (int) $b['duration_days'];
				if ( 0 === $da && 0 !== $db ) {
					return 1;
				}
				if ( 0 !== $da && 0 === $db ) {
					return -1;
				}
				return $da <=> $db;
			}
		);

		$data = array(
			'module_enabled'  => 'disabled' !== (string) $config['module_enabled'],
			'active'          => $active,
			'detailed_active' => $detailed_active,
			'renewal_needed'  => $renewal_needed,
			'plans'           => $products,
		);

		$data = apply_filters( 'music_wave_vip_membership_panel_final', $data, $user_id );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Human label for a plan duration in days.
	 */
	public static function duration_label( int $days ): string {
		if ( $days < 1 ) {
			return __( 'مادام‌العمر', 'music-wave-vip' );
		}
		if ( 0 === $days % 365 ) {
			/* translators: %d: number of years. */
			return sprintf( _n( '%d سال', '%d سال', intdiv( $days, 365 ), 'music-wave-vip' ), intdiv( $days, 365 ) );
		}
		if ( 0 === $days % 30 ) {
			/* translators: %d: number of months. */
			return sprintf( _n( '%d ماه', '%d ماه', intdiv( $days, 30 ), 'music-wave-vip' ), intdiv( $days, 30 ) );
		}
		/* translators: %d: number of days. */
		return sprintf( _n( '%d روز', '%d روز', $days, 'music-wave-vip' ), $days );
	}

	/**
	 * Upgrade legacy 1-line placeholder products without touching merchant-customized descriptions.
	 *
	 * @param array{icon: string, summary: string, features: array<int, string>} $preset Preset definition.
	 */
	private static function maybe_enrich_existing_product( int $product_id, array $preset ): bool {
		if ( 'product' !== get_post_type( $product_id ) ) {
			return false;
		}

		$current_content = VipPlanCatalog::post_field( 'post_content', $product_id );
		$updated         = false;

		if ( VipPlanCatalog::is_legacy_or_empty_content( $current_content ) && function_exists( 'wp_update_post' ) ) {
			$current_excerpt = VipPlanCatalog::post_field( 'post_excerpt', $product_id );
			wp_update_post(
				array(
					'ID'           => $product_id,
					'post_content' => VipPlanCatalog::build_preset_html( $preset ),
					'post_excerpt' => '' !== trim( $current_excerpt ) ? $current_excerpt : $preset['summary'],
				)
			);
			$updated = true;
		}

		$existing_icon = (string) get_post_meta( $product_id, self::ICON_META, true );
		if ( '' === trim( $existing_icon ) ) {
			update_post_meta( $product_id, self::ICON_META, $preset['icon'] );
			$updated = true;
		}

		foreach ( array(
			'_virtual'           => 'yes',
			'_sold_individually' => 'yes',
		) as $meta_key => $expected ) {
			if ( (string) get_post_meta( $product_id, $meta_key, true ) !== $expected ) {
				update_post_meta( $product_id, $meta_key, $expected );
				$updated = true;
			}
		}

		return $updated;
	}
}
