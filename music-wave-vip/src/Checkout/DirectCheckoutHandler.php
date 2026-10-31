<?php
/**
 * Direct-checkout redirect, cart non-subscription pruning, virtual product
 * enforcement, and single-VIP-plan cart validation (SOLID SRP).
 *
 * A VIP plan behaves as a single-seat purchase: at most one plan may sit in the
 * cart, always at quantity 1, and every purchase button lands on checkout.
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
	 * Enforce virtual + single-seat + disabled stock management on VIP plan products.
	 *
	 * @param mixed $post Post object.
	 */
	public function ensure_product_props( int $post_id, $post, bool $update ): void {
		unset( $post, $update );
		if ( ! SubscriptionOrderInspector::is_subscription_product( $post_id ) || 'product' !== get_post_type( $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, '_virtual', 'yes' );
		update_post_meta( $post_id, '_sold_individually', 'yes' );
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
		echo esc_html__( 'این محصول بهعنوان طرح اشتراک VIP نگاشت شده اما مجازی نیست. برای حذف فیلدهای حملونقل و فعالسازی پرداخت مستقیم، آن را مجازی کنید.', 'music-wave-vip' );
		echo '</p></div>';
	}

	/**
	 * Send every plan purchase straight to the checkout page.
	 *
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
		$cart = $this->cart();
		if ( ! $this->is_plan_product( $product_id ) || null === $cart ) {
			return;
		}

		$this->prune_non_subscription_items( $cart, (string) $cart_item_key );
	}

	public function handle_direct_checkout_flag(): void {
		if ( ! isset( $_REQUEST[ SubscriptionOrderInspector::DIRECT_FLAG ], $_REQUEST['add-to-cart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$product_id = absint( $_REQUEST['add-to-cart'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $this->is_plan_product( $product_id )
			|| ! function_exists( 'wc_get_checkout_url' )
			|| ! function_exists( 'is_checkout' )
			|| ! is_checkout()
		) {
			return;
		}
		$cart = $this->cart();
		if ( null === $cart ) {
			return;
		}

		$this->prune_non_subscription_items( $cart );

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
	 * Release WooCommerce's sold-individually duplicate guard for plans.
	 *
	 * Plans are sold individually, but re-buying must not dead-end on
	 * "you cannot add another … to your cart": the guard is released here and the
	 * previously queued plan is swapped out in the same request by
	 * `filter_add_to_cart_validation()`.
	 *
	 * @param mixed $found          Whether a sold-individually item is already in the cart.
	 * @param mixed $product_id     Product ID.
	 * @param mixed $quantity       Quantity the guard would apply.
	 * @param mixed $variation_id   Variation ID.
	 * @param mixed $cart_item_data Cart item data.
	 * @param mixed $cart_item_key  Matching existing cart item key.
	 */
	public function filter_sold_individually_found( $found, $product_id = 0, $quantity = 1, $variation_id = 0, $cart_item_data = array(), $cart_item_key = '' ): bool {
		unset( $quantity, $cart_item_data, $cart_item_key );
		if ( $this->is_plan_product( $product_id ) || $this->is_plan_product( $variation_id ) ) {
			return false;
		}

		return (bool) $found;
	}

	/**
	 * Replace-on-add: queueing a plan clears whichever plan was queued before, so
	 * the click always succeeds and two plans can never share the cart.
	 *
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
		if ( ! $this->is_plan_product( $product_id ) ) {
			return true;
		}
		$cart = $this->cart();
		if ( null !== $cart ) {
			$this->clear_plan_items( $cart );
		}

		return true;
	}

	/**
	 * Post-add invariant: whatever path added the plan (form handler, REST, Store
	 * API, direct `WC_Cart::add_to_cart()`), exactly the new plan survives at quantity 1.
	 *
	 * @param mixed $cart_item_key  Newly added cart item key.
	 * @param mixed $product_id     Product ID.
	 * @param mixed $quantity       Requested quantity.
	 * @param mixed $variation_id   Variation ID.
	 * @param mixed $variation      Variation attributes.
	 * @param mixed $cart_item_data Cart item data.
	 */
	public function enforce_single_subscription_plan( $cart_item_key, $product_id, $quantity = 1, $variation_id = 0, $variation = array(), $cart_item_data = array() ): void {
		unset( $quantity, $variation_id, $variation, $cart_item_data );
		if ( ! $this->is_plan_product( $product_id ) ) {
			return;
		}
		$cart = $this->cart();
		if ( null === $cart ) {
			return;
		}

		$key = (string) $cart_item_key;
		$this->clear_plan_items( $cart, $key );

		$item = '' !== $key ? $cart->get_cart_item( $key ) : false;
		if ( is_array( $item ) && isset( $item['quantity'] ) && 1 !== (int) $item['quantity'] ) {
			$cart->set_quantity( $key, 1 );
		}
	}

	/**
	 * Remove every VIP plan from the cart, optionally preserving one item key.
	 *
	 * @param \WC_Cart $cart     Cart instance.
	 * @param string   $keep_key Cart item key to preserve.
	 */
	private function clear_plan_items( \WC_Cart $cart, string $keep_key = '' ): void {
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( $key === $keep_key ) {
				continue;
			}
			if ( $this->is_plan_product( isset( $item['product_id'] ) ? $item['product_id'] : 0 ) ) {
				$cart->remove_cart_item( $key );
			}
		}
	}

	/**
	 * Shared helper removing non-subscription items for the direct-checkout flow;
	 * the surviving plan itself is managed by `clear_plan_items()`.
	 *
	 * @param \WC_Cart $cart     Cart instance.
	 * @param string   $keep_key Optional newly added item key to preserve unconditionally.
	 */
	private function prune_non_subscription_items( \WC_Cart $cart, string $keep_key = '' ): void {
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( '' !== $keep_key && $key === $keep_key ) {
				continue;
			}
			if ( $this->is_plan_product( isset( $item['product_id'] ) ? $item['product_id'] : 0 ) ) {
				continue;
			}
			$cart->remove_cart_item( $key );
		}
	}

	/**
	 * Whether a product or variation ID maps to a VIP plan product.
	 *
	 * @param mixed $product_id Candidate product ID.
	 */
	private function is_plan_product( $product_id ): bool {
		return (int) $product_id > 0 && SubscriptionOrderInspector::is_subscription_product( (int) $product_id );
	}

	/**
	 * Live cart instance, or null when WooCommerce is unavailable in this request.
	 */
	private function cart(): ?\WC_Cart {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$woocommerce = WC();
		$cart        = is_object( $woocommerce ) ? $woocommerce->cart : null;

		return $cart instanceof \WC_Cart ? $cart : null;
	}
}
