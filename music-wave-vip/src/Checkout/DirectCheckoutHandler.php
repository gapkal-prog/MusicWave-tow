<?php
/**
 * Direct-checkout redirect, cart non-subscription pruning, virtual product
 * enforcement, and subscription stacking validation (SOLID SRP).
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DirectCheckoutHandler {
	/**
	 * Enforce virtual + allow stacking/reservation + disable stock management on VIP plan products.
	 *
	 * @param mixed $post Post object.
	 */
	public function ensure_product_props( int $post_id, $post, bool $update ): void {
		unset( $post, $update );
		if ( ! SubscriptionOrderInspector::is_subscription_product( $post_id ) || 'product' !== get_post_type( $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, '_virtual', 'yes' );
		update_post_meta( $post_id, '_sold_individually', 'no' );
		update_post_meta( $post_id, '_manage_stock', 'no' );
	}

	public function maybe_warn_non_virtual(): void {
		global $post;
		if ( ! $post instanceof \WP_Post || ! SubscriptionOrderInspector::is_subscription_product( (int) $post->ID ) ) {
			return;
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $post->ID ) : null;
		if ( ! $product instanceof \WC_Product || $product->is_virtual() ) {
			return;
		}

		echo '<div class="notice notice-warning inline" style="margin:12px 0;"><p>';
		echo esc_html__( 'این محصول به‌عنوان طرح اشتراک VIP نگاشت شده اما مجازی نیست. برای حذف فیلدهای حمل‌ونقل و فعال‌سازی پرداخت مستقیم، آن را مجازی کنید.', 'music-wave-vip' );
		echo '</p></div>';
	}

	/**
	 * @param mixed $url     Default redirect URL.
	 * @param mixed $product Added WC_Product.
	 */
	public function handle_add_to_cart_redirect( $url, $product ): string {
		if ( ! $product instanceof \WC_Product || ! SubscriptionOrderInspector::is_subscription_product( (int) $product->get_id() ) ) {
			return (string) $url;
		}

		if ( function_exists( 'wc_get_checkout_url' ) ) {
			$checkout = wc_get_checkout_url();
			if ( '' !== $checkout ) {
				return $checkout;
			}
		}

		return (string) $url;
	}

	/**
	 * @param mixed $cart_item_key  Cart item key.
	 * @param mixed $product_id     Product ID.
	 * @param mixed $quantity       Quantity.
	 * @param mixed $variation_id   Variation ID.
	 * @param mixed $variation      Variation attributes.
	 * @param mixed $cart_item_data Cart item data.
	 */
	public function maybe_empty_cart_for_direct_checkout( $cart_item_key, $product_id, $quantity = 1, $variation_id = 0, $variation = array(), $cart_item_data = array() ): void {
		unset( $quantity, $variation_id, $variation, $cart_item_data );
		if ( ! isset( $_REQUEST[ SubscriptionOrderInspector::DIRECT_FLAG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$flag = sanitize_text_field( wp_unslash( (string) $_REQUEST[ SubscriptionOrderInspector::DIRECT_FLAG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '1' !== $flag && 'true' !== $flag ) {
			return;
		}
		if ( ! SubscriptionOrderInspector::is_subscription_product( (int) $product_id ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$this->prune_non_subscription_items( WC()->cart, (string) $cart_item_key );
	}

	public function handle_direct_checkout_flag(): void {
		if ( ! isset( $_REQUEST[ SubscriptionOrderInspector::DIRECT_FLAG ], $_REQUEST['add-to-cart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$product_id = absint( $_REQUEST['add-to-cart'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! SubscriptionOrderInspector::is_subscription_product( $product_id )
			|| ! function_exists( 'WC' )
			|| ! WC()->cart
			|| ! function_exists( 'wc_get_checkout_url' )
			|| ! function_exists( 'is_checkout' )
			|| ! is_checkout()
		) {
			return;
		}

		$this->prune_non_subscription_items( WC()->cart );

		$clean_url   = wc_get_checkout_url();
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' !== $clean_url
			&& ( isset( $_GET['add-to-cart'] ) || isset( $_GET[ SubscriptionOrderInspector::DIRECT_FLAG ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& false !== strpos( $request_uri, 'add-to-cart' )
		) {
			wp_safe_redirect( $clean_url );
			exit;
		}
	}

	/**
	 * @param mixed $found          Whether sold-individually item is already in cart.
	 * @param mixed $product_id     Product ID.
	 * @param mixed $variation_id   Variation ID.
	 * @param mixed $cart_item_data Cart item data.
	 * @param mixed $cart_item_key  Cart item key.
	 */
	public function filter_sold_individually_found( $found, $product_id = 0, $variation_id = 0, $cart_item_data = array(), $cart_item_key = '' ): bool {
		unset( $cart_item_data, $cart_item_key );
		if ( ( (int) $product_id > 0 && SubscriptionOrderInspector::is_subscription_product( (int) $product_id ) )
			|| ( (int) $variation_id > 0 && SubscriptionOrderInspector::is_subscription_product( (int) $variation_id ) )
		) {
			return false;
		}

		return (bool) $found;
	}

	/**
	 * @param mixed $passed       Validation state.
	 * @param mixed $product_id   Product ID.
	 * @param mixed $quantity     Quantity.
	 * @param mixed $variation_id Variation ID.
	 * @param mixed $variations   Variation map.
	 */
	public function filter_add_to_cart_validation( $passed, $product_id = 0, $quantity = 0, $variation_id = 0, $variations = array() ): bool {
		unset( $quantity, $variation_id, $variations );
		if ( ! $passed ) {
			return false;
		}
		if ( (int) $product_id > 0 && SubscriptionOrderInspector::is_subscription_product( (int) $product_id ) ) {
			return true;
		}

		return (bool) $passed;
	}

	/**
	 * Shared helper removing non-subscription items while preserving VIP plan stacking.
	 *
	 * @param object $cart     WC_Cart instance.
	 * @param string $keep_key Optional newly added item key to preserve unconditionally.
	 */
	private function prune_non_subscription_items( $cart, string $keep_key = '' ): void {
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( '' !== $keep_key && $key === $keep_key ) {
				continue;
			}
			$pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( $pid > 0 && SubscriptionOrderInspector::is_subscription_product( $pid ) ) {
				continue;
			}
			$cart->remove_cart_item( $key );
		}
	}
}
