<?php
/**
 * Shared subscription product, cart, order, and thank-you query service
 * for MusicWave VIP checkout flows (SOLID SRP + DRY).
 *
 * Reuses `WooAccountBridge::VIRTUAL_ADDRESS_KEYS` when MusicWave Core is loaded
 * so both plugins stay coordinated from one canonical field policy.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Checkout;

use ManaCore\MusicWave\Core\Account\WooAccountBridge;
use ManaCore\MusicWave\Vip\PlanProducts;
use ManaCore\MusicWave\Vip\VipSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SubscriptionOrderInspector {
	/** Query flag that marks a direct-checkout add-to-cart request. */
	public const DIRECT_FLAG = 'mw_direct_checkout';

	/**
	 * Canonical address keys removed for virtual-only VIP subscriptions.
	 *
	 * @return array<int, string>
	 */
	public static function virtual_address_keys(): array {
		if ( class_exists( WooAccountBridge::class ) ) {
			return WooAccountBridge::VIRTUAL_ADDRESS_KEYS;
		}

		return array( 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' );
	}

	/**
	 * Prefixed `billing_*` keys matching `virtual_address_keys()`.
	 *
	 * @param bool $include_country Whether to include `billing_country`.
	 * @return array<int, string>
	 */
	public static function virtual_billing_keys( bool $include_country = true ): array {
		$keys = array();
		foreach ( self::virtual_address_keys() as $key ) {
			if ( ! $include_country && 'country' === $key ) {
				continue;
			}
			$keys[] = 'billing_' . $key;
		}

		return $keys;
	}

	public static function is_subscription_product( int $product_id ): bool {
		if ( $product_id < 1 ) {
			return false;
		}

		$config = VipSettings::all();
		$plans  = isset( $config['vip_plans'] ) && is_array( $config['vip_plans'] ) ? $config['vip_plans'] : array();
		foreach ( $plans as $plan ) {
			if ( ! is_array( $plan ) || empty( $plan['product_ids'] ) || ! is_array( $plan['product_ids'] ) ) {
				continue;
			}
			foreach ( $plan['product_ids'] as $pid ) {
				if ( absint( $pid ) === $product_id ) {
					return true;
				}
			}
		}

		$level = get_post_meta( $product_id, PlanProducts::LEVEL_META, true );

		return is_string( $level ) && '' !== trim( $level );
	}

	public static function build_direct_checkout_url( int $product_id ): string {
		if ( $product_id < 1 || ! function_exists( 'wc_get_checkout_url' ) || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return '';
		}

		$checkout = wc_get_checkout_url();
		if ( '' === $checkout ) {
			return $product->add_to_cart_url();
		}

		$url = add_query_arg(
			array(
				'add-to-cart'     => $product_id,
				self::DIRECT_FLAG => '1',
			),
			$checkout
		);

		return function_exists( 'wp_nonce_url' ) ? wp_nonce_url( $url, 'woocommerce-cart' ) : $url;
	}

	public static function cart_contains_only_virtual_subscription(): bool {
		if ( ! function_exists( 'did_action' ) || ! did_action( 'init' ) || ! function_exists( 'WC' ) ) {
			return false;
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! property_exists( $wc, 'cart' ) || ! is_object( $wc->cart ) || $wc->cart->is_empty() ) {
			return false;
		}

		foreach ( $wc->cart->get_cart() as $item ) {
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( $product_id < 1 || ! self::is_subscription_product( $product_id ) ) {
				return false;
			}
			if ( isset( $item['data'] ) && $item['data'] instanceof \WC_Product ) {
				if ( ! $item['data']->is_virtual() ) {
					return false;
				}
			} elseif ( 'yes' !== get_post_meta( $product_id, '_virtual', true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param mixed $order Candidate order object.
	 */
	public static function order_contains_only_virtual_subscription( $order ): bool {
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}
		$items = $order->get_items( 'line_item' );
		if ( empty( $items ) ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
				return false;
			}
			$product_id = (int) $item->get_product_id();
			if ( $product_id < 1 || ! self::is_subscription_product( $product_id ) ) {
				return false;
			}
			if ( method_exists( $item, 'get_product' ) ) {
				$product = $item->get_product();
				if ( $product instanceof \WC_Product && ! $product->is_virtual() ) {
					return false;
				}
			} elseif ( 'yes' !== get_post_meta( $product_id, '_virtual', true ) ) {
				return false;
			}
		}

		return true;
	}

	public static function order_has_subscription_item( \WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( is_object( $item ) && method_exists( $item, 'get_product_id' ) && self::is_subscription_product( (int) $item->get_product_id() ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param mixed $order_id_or_obj Order instance or numeric ID.
	 */
	public static function resolve_order( $order_id_or_obj ): ?\WC_Order {
		if ( ! function_exists( 'did_action' ) || ! did_action( 'init' ) ) {
			return null;
		}
		if ( $order_id_or_obj instanceof \WC_Order ) {
			return $order_id_or_obj;
		}
		if ( is_numeric( $order_id_or_obj ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( absint( $order_id_or_obj ) );

			return $order instanceof \WC_Order ? $order : null;
		}

		return self::resolve_thankyou_order();
	}

	/**
	 * Single canonical order resolver across `order-received` query vars and `?key=`.
	 */
	public static function resolve_thankyou_order(): ?\WC_Order {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order_id = 0;
		if ( isset( $GLOBALS['wp']->query_vars['order-received'] ) ) {
			$order_id = absint( $GLOBALS['wp']->query_vars['order-received'] );
		} elseif ( isset( $_GET['order-received'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_id = absint( $_GET['order-received'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order && isset( $_GET['key'] ) && function_exists( 'wc_get_order_id_by_order_key' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$oid = wc_get_order_id_by_order_key( sanitize_text_field( wp_unslash( (string) $_GET['key'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $oid > 0 ) {
				$order = wc_get_order( $oid );
			}
		}

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Single canonical resolver for the standard WooCommerce `order-pay` endpoint,
	 * used by the retry/failed-payment screen.
	 */
	public static function resolve_pay_order(): ?\WC_Order {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order_id = 0;
		if ( isset( $GLOBALS['wp']->query_vars['order-pay'] ) ) {
			$order_id = absint( $GLOBALS['wp']->query_vars['order-pay'] );
		} elseif ( isset( $_GET['order-pay'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_id = absint( $_GET['order-pay'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( $order_id < 1 ) {
			return null;
		}
		$order = wc_get_order( $order_id );

		return $order instanceof \WC_Order ? $order : null;
	}

	public static function is_thankyou_page(): bool {
		if ( ! function_exists( 'did_action' ) || ! did_action( 'init' ) ) {
			return false;
		}
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return true;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
			return true;
		}

		return isset( $GLOBALS['wp']->query_vars['order-received'] );
	}
}
