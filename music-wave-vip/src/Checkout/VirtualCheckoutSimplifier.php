<?php
/**
 * Virtual subscription checkout simplification (Classic + WooCommerce Blocks)
 * using the canonical shared `SubscriptionOrderInspector::virtual_address_keys()` list
 * (SOLID SRP + DRY).
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VirtualCheckoutSimplifier {
	private const HIDDEN_CHECKOUT_BLOCKS = array(
		'woocommerce/checkout-shipping-address-block',
		'woocommerce/checkout-shipping-methods-block',
		'woocommerce/checkout-shipping-method-block',
		'woocommerce/checkout-pickup-options-block',
		'woocommerce/cart-order-summary-shipping-block',
		'woocommerce/checkout-order-summary-shipping-block',
		'woocommerce/checkout-billing-address-block',

		/*
		 * A plan cart renders a single line whose price is already shown in the totals,
		 * so the itemised product list inside «خلاصۀ سفارش» is dropped entirely.
		 */
		'woocommerce/checkout-order-summary-cart-items-block',
		'woocommerce/cart-order-summary-cart-items-block',
	);

	/** @param mixed $needs */
	public function filter_needs_shipping( $needs ): bool {
		return SubscriptionOrderInspector::cart_contains_only_virtual_subscription() ? false : (bool) $needs;
	}

	/** @param mixed $needs */
	public function filter_needs_shipping_address( $needs ): bool {
		return SubscriptionOrderInspector::cart_contains_only_virtual_subscription() ? false : (bool) $needs;
	}

	/**
	 * @param mixed $fields Checkout fields map.
	 * @return array<string, mixed>
	 */
	public function customize_checkout_fields( $fields ): array {
		if ( ! is_array( $fields ) || ! SubscriptionOrderInspector::cart_contains_only_virtual_subscription() ) {
			return is_array( $fields ) ? $fields : array();
		}

		unset( $fields['shipping'] );
		if ( isset( $fields['order']['order_comments'] ) ) {
			unset( $fields['order']['order_comments'] );
		}

		if ( isset( $fields['billing'] ) && is_array( $fields['billing'] ) ) {
			$keep   = array( 'billing_email', 'billing_first_name', 'billing_last_name' );
			$remove = SubscriptionOrderInspector::virtual_billing_keys( true );
			foreach ( $fields['billing'] as $key => $field ) {
				unset( $field );
				if ( in_array( $key, $keep, true ) ) {
					if ( 'billing_email' === $key ) {
						$fields['billing'][ $key ]['required'] = true;
						$fields['billing'][ $key ]['priority'] = 10;
					} else {
						$fields['billing'][ $key ]['required'] = false;
						$fields['billing'][ $key ]['priority'] = 'billing_first_name' === $key ? 20 : 30;
					}
					continue;
				}
				if ( in_array( $key, $remove, true ) ) {
					unset( $fields['billing'][ $key ] );
				}
			}

			if ( ! isset( $fields['billing']['billing_email'] ) ) {
				$fields['billing']['billing_email'] = array(
					'label'    => __( 'آدرس ایمیل', 'music-wave-vip' ),
					'required' => true,
					'type'     => 'email',
					'priority' => 10,
				);
			}
		}

		if ( is_user_logged_in() && isset( $fields['account'] ) ) {
			unset( $fields['account'] );
		}

		$filtered = apply_filters( 'music_wave_vip_virtual_checkout_fields', $fields );

		return is_array( $filtered ) ? $filtered : $fields;
	}

	/**
	 * @param mixed $fields Billing fields map.
	 * @return array<string, mixed>
	 */
	public function filter_billing_fields( $fields ): array {
		if ( ! is_array( $fields ) || ! SubscriptionOrderInspector::cart_contains_only_virtual_subscription() ) {
			return is_array( $fields ) ? $fields : array();
		}

		foreach ( SubscriptionOrderInspector::virtual_billing_keys( true ) as $key ) {
			unset( $fields[ $key ] );
		}

		return $fields;
	}

	/**
	 * @param mixed $address_fields Default address fields map.
	 * @return array<string, mixed>
	 */
	public function filter_default_address_fields( $address_fields ): array {
		if ( ! is_array( $address_fields ) || ! SubscriptionOrderInspector::cart_contains_only_virtual_subscription() ) {
			return is_array( $address_fields ) ? $address_fields : array();
		}

		foreach ( SubscriptionOrderInspector::virtual_address_keys() as $key ) {
			if ( isset( $address_fields[ $key ] ) ) {
				$address_fields[ $key ]['required'] = false;
				if ( isset( $address_fields[ $key ]['validate'] ) ) {
					$address_fields[ $key ]['validate'] = array();
				}
			}
		}

		return $address_fields;
	}

	/**
	 * @param mixed $data Posted checkout payload.
	 * @return array<string, mixed>
	 */
	public function filter_posted_data( $data ): array {
		if ( ! is_array( $data ) || ! SubscriptionOrderInspector::cart_contains_only_virtual_subscription() ) {
			return is_array( $data ) ? $data : array();
		}

		foreach ( SubscriptionOrderInspector::virtual_billing_keys( false ) as $key ) {
			unset( $data[ $key ] );
		}

		if ( empty( $data['billing_country'] ) ) {
			$base    = function_exists( 'wc_get_base_location' ) ? wc_get_base_location() : array();
			$country = isset( $base['country'] ) ? (string) $base['country'] : '';
			if ( '' !== $country ) {
				$data['billing_country'] = $country;
			} else {
				unset( $data['billing_country'] );
			}
		}

		return $data;
	}

	/**
	 * @param mixed  $value    Option value.
	 * @param string $option   Option name.
	 * @param mixed  $fallback Default value.
	 * @return mixed
	 */
	public function filter_hidden_for_virtual_subscription( $value, $option = '', $fallback = null ) {
		unset( $option, $fallback );

		return SubscriptionOrderInspector::cart_contains_only_virtual_subscription() ? 'hidden' : $value;
	}

	/**
	 * @param string               $block_content Rendered block HTML.
	 * @param array<string, mixed> $block         Parsed block array.
	 */
	public function filter_checkout_blocks( $block_content, $block ): string {
		if ( ! is_array( $block ) || empty( $block['blockName'] ) || ! SubscriptionOrderInspector::cart_contains_only_virtual_subscription() ) {
			return (string) $block_content;
		}

		return in_array( $block['blockName'], self::HIDDEN_CHECKOUT_BLOCKS, true ) ? '' : (string) $block_content;
	}

	/**
	 * @param mixed $classes Body CSS classes.
	 * @return array<int, string>
	 */
	public function filter_body_class( $classes ): array {
		if ( ! is_array( $classes ) ) {
			return array();
		}

		$virtual_cart = SubscriptionOrderInspector::cart_contains_only_virtual_subscription();
		if ( $virtual_cart ) {
			$classes[] = 'mw-checkout--virtual-only';
			$classes[] = 'mw-checkout--subscription-only';
			if ( function_exists( 'is_checkout' ) && is_checkout() ) {
				$classes[] = 'mw-checkout--simplified';
				$classes[] = 'mw-checkout--no-billing-address';
			}
		}

		if ( SubscriptionOrderInspector::is_thankyou_page() ) {
			$order = SubscriptionOrderInspector::resolve_thankyou_order();
			if ( $order instanceof \WC_Order && SubscriptionOrderInspector::order_contains_only_virtual_subscription( $order ) ) {
				$classes[] = 'mw-thankyou--virtual-only';
				$classes[] = 'mw-thankyou--subscription-only';
				$classes[] = 'mw-checkout--virtual-only';
			}
		}

		$pay_order = SubscriptionOrderInspector::resolve_pay_order();
		if ( $pay_order instanceof \WC_Order
			&& $pay_order->needs_payment()
			&& SubscriptionOrderInspector::order_has_subscription_item( $pay_order )
		) {
			$classes[] = 'mw-pay--subscription';
		}

		return $classes;
	}

	public function output_virtual_checkout_css(): void {
		if ( ! SubscriptionOrderInspector::cart_contains_only_virtual_subscription() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<style id="mw-virtual-checkout-css">'
			. 'body.mw-checkout--virtual-only .woocommerce-billing-fields__field-wrapper .form-row:not(.validate-email):not(.validate-required--email):not(#billing_first_name_field):not(#billing_last_name_field):not(#billing_email_field){display:none!important;}'
			. 'body.mw-checkout--virtual-only .woocommerce-billing-fields h3,'
			. 'body.mw-checkout--virtual-only .woocommerce-billing-fields__field-wrapper h3,'
			. 'body.mw-checkout--virtual-only .wc-block-checkout__billing-fields,'
			. 'body.mw-checkout--virtual-only .wp-block-woocommerce-checkout-billing-address-block,'
			. 'body.mw-checkout--virtual-only .wc-block-checkout__shipping-fields,'
			. 'body.mw-checkout--virtual-only .wp-block-woocommerce-checkout-shipping-address-block,'
			. 'body.mw-checkout--virtual-only .wc-block-checkout__shipping-methods,'
			. 'body.mw-checkout--virtual-only .wp-block-woocommerce-checkout-shipping-methods-block{display:none!important;}'
			. 'body.mw-checkout--virtual-only .wp-block-woocommerce-checkout-order-summary-cart-items-block,'
			. 'body.mw-checkout--virtual-only .wp-block-woocommerce-cart-order-summary-cart-items-block,'
			. 'body.mw-checkout--virtual-only .wc-block-components-order-summary.is-large .wc-block-components-order-summary__content,'
			. 'body.mw-checkout--virtual-only .wc-block-components-order-summary.is-large .wc-block-components-order-summary-item{display:none!important;}'
			. 'body.mw-checkout--virtual-only .mw-hidden-billing-field{display:none!important;}'
			. 'body.mw-checkout--virtual-only .woocommerce-checkout .woocommerce-billing-fields::before{content:"";display:none;}'
			. '</style>';
		// phpcs:enable
	}
}
