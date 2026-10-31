<?php
/**
 * SOLID Coordinator Facade for VIP direct checkout, virtual checkout
 * simplification, and order/thank-you subscription presentation.
 *
 * Delegates every responsibility cleanly to `Checkout\*` SRP collaborators
 * while preserving 100% backward-compatible hook and static contracts.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip;

use ManaCore\MusicWave\Vip\Checkout\DirectCheckoutHandler;
use ManaCore\MusicWave\Vip\Checkout\SubscriptionOrderInspector;
use ManaCore\MusicWave\Vip\Checkout\SubscriptionOrderPresenter;
use ManaCore\MusicWave\Vip\Checkout\VirtualCheckoutSimplifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CheckoutCustomizer {
	/** Query flag that marks a direct-checkout add-to-cart request. */
	public const DIRECT_FLAG = SubscriptionOrderInspector::DIRECT_FLAG;

	/** @var DirectCheckoutHandler */
	private $direct_checkout;

	/** @var VirtualCheckoutSimplifier */
	private $simplifier;

	/** @var SubscriptionOrderPresenter */
	private $presenter;

	public function __construct(
		?DirectCheckoutHandler $direct_checkout = null,
		?VirtualCheckoutSimplifier $simplifier = null,
		?SubscriptionOrderPresenter $presenter = null
	) {
		$this->direct_checkout = $direct_checkout ?? new DirectCheckoutHandler();
		$this->simplifier      = $simplifier ?? new VirtualCheckoutSimplifier();
		$this->presenter       = $presenter ?? new SubscriptionOrderPresenter();
	}

	public static function is_subscription_product( int $product_id ): bool {
		return SubscriptionOrderInspector::is_subscription_product( $product_id );
	}

	public static function build_direct_checkout_url( int $product_id ): string {
		return SubscriptionOrderInspector::build_direct_checkout_url( $product_id );
	}

	public static function cart_contains_only_virtual_subscription(): bool {
		return SubscriptionOrderInspector::cart_contains_only_virtual_subscription();
	}

	/** @param mixed $order */
	public static function order_contains_only_virtual_subscription( $order ): bool {
		return SubscriptionOrderInspector::order_contains_only_virtual_subscription( $order );
	}

	public function register(): void {
		add_action( 'save_post_product', array( $this->direct_checkout, 'ensure_product_props' ), 20, 3 );
		add_action( 'woocommerce_product_options_general_product_data', array( $this->direct_checkout, 'maybe_warn_non_virtual' ) );
		add_filter( 'woocommerce_add_to_cart_redirect', array( $this->direct_checkout, 'handle_add_to_cart_redirect' ), 20, 2 );
		add_action( 'woocommerce_add_to_cart', array( $this->direct_checkout, 'maybe_empty_cart_for_direct_checkout' ), 10, 6 );
		add_action( 'woocommerce_add_to_cart', array( $this->direct_checkout, 'enforce_single_subscription_plan' ), 11, 6 );
		add_action( 'template_redirect', array( $this->direct_checkout, 'handle_direct_checkout_flag' ), 5 );
		add_filter( 'woocommerce_add_to_cart_sold_individually_found_in_cart', array( $this->direct_checkout, 'filter_sold_individually_found' ), 10, 6 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this->direct_checkout, 'filter_add_to_cart_validation' ), 10, 5 );

		add_filter( 'woocommerce_cart_needs_shipping', array( $this->simplifier, 'filter_needs_shipping' ), 10, 1 );
		add_filter( 'woocommerce_cart_needs_shipping_address', array( $this->simplifier, 'filter_needs_shipping_address' ), 10, 1 );
		add_filter( 'woocommerce_checkout_fields', array( $this->simplifier, 'customize_checkout_fields' ), 100, 1 );
		add_filter( 'woocommerce_default_address_fields', array( $this->simplifier, 'filter_default_address_fields' ), 100, 1 );
		add_filter( 'woocommerce_billing_fields', array( $this->simplifier, 'filter_billing_fields' ), 100, 1 );
		add_filter( 'woocommerce_checkout_posted_data', array( $this->simplifier, 'filter_posted_data' ), 100, 1 );

		foreach ( SubscriptionOrderInspector::virtual_address_keys() as $field_key ) {
			add_filter( 'pre_option_woocommerce_checkout_' . $field_key . '_field', array( $this->simplifier, 'filter_hidden_for_virtual_subscription' ), 10, 3 );
		}

		add_filter( 'render_block', array( $this->simplifier, 'filter_checkout_blocks' ), 10, 2 );
		add_filter( 'body_class', array( $this->simplifier, 'filter_body_class' ), 10, 1 );
		add_action( 'wp_head', array( $this->simplifier, 'output_virtual_checkout_css' ), 20 );
		add_action( 'wp_head', array( $this->presenter, 'output_virtual_thankyou_css' ), 25 );
		add_action( 'before_woocommerce_pay', array( $this->presenter, 'render_pending_payment_notice' ), 10 );
		add_filter( 'woocommerce_thankyou_order_received_text', array( $this->presenter, 'filter_thankyou_text' ), 20, 2 );

		add_action( 'woocommerce_order_details_after_order_table', array( $this->presenter, 'render_subscription_details_in_order' ), 10, 1 );
		add_action( 'woocommerce_thankyou', array( $this->presenter, 'render_minimal_thankyou_for_virtual' ), 5, 1 );
		add_action( 'woocommerce_thankyou', array( $this->presenter, 'render_subscription_details_in_thankyou' ), 10, 1 );

		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '2.6', '<' ) ) {
			add_filter( 'woocommerce_my_account_my_orders_columns', array( $this->presenter, 'add_subscription_column_to_orders' ), 10, 1 );
		} else {
			add_filter( 'woocommerce_account_orders_columns', array( $this->presenter, 'add_subscription_column_to_orders' ), 10, 1 );
		}
		add_action( 'woocommerce_my_account_my_orders_column_mw-subscription', array( $this->presenter, 'render_subscription_column' ), 10, 1 );
	}

	/** @param mixed $post */
	public function ensure_product_props( int $post_id, $post, bool $update ): void {
		$this->direct_checkout->ensure_product_props( $post_id, $post, $update );
	}

	public function maybe_warn_non_virtual(): void {
		$this->direct_checkout->maybe_warn_non_virtual();
	}

	/**
	 * @param mixed $url
	 * @param mixed $product
	 */
	public function handle_add_to_cart_redirect( $url, $product ): string {
		return $this->direct_checkout->handle_add_to_cart_redirect( $url, $product );
	}

	/**
	 * @param mixed $cart_item_key
	 * @param mixed $product_id
	 * @param mixed $quantity
	 * @param mixed $variation_id
	 * @param mixed $variation
	 * @param mixed $cart_item_data
	 */
	public function maybe_empty_cart_for_direct_checkout( $cart_item_key, $product_id, $quantity = 1, $variation_id = 0, $variation = array(), $cart_item_data = array() ): void {
		$this->direct_checkout->maybe_empty_cart_for_direct_checkout( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data );
	}

	public function handle_direct_checkout_flag(): void {
		$this->direct_checkout->handle_direct_checkout_flag();
	}

	/** @param mixed $needs */
	public function filter_needs_shipping( $needs ): bool {
		return $this->simplifier->filter_needs_shipping( $needs );
	}

	/** @param mixed $needs */
	public function filter_needs_shipping_address( $needs ): bool {
		return $this->simplifier->filter_needs_shipping_address( $needs );
	}

	/**
	 * @param mixed $fields
	 * @return array<string, mixed>
	 */
	public function customize_checkout_fields( $fields ): array {
		return $this->simplifier->customize_checkout_fields( $fields );
	}

	/**
	 * @param mixed $fields
	 * @return array<string, mixed>
	 */
	public function filter_billing_fields( $fields ): array {
		return $this->simplifier->filter_billing_fields( $fields );
	}

	/**
	 * @param mixed $address_fields
	 * @return array<string, mixed>
	 */
	public function filter_default_address_fields( $address_fields ): array {
		return $this->simplifier->filter_default_address_fields( $address_fields );
	}

	/**
	 * @param mixed $data
	 * @return array<string, mixed>
	 */
	public function filter_posted_data( $data ): array {
		return $this->simplifier->filter_posted_data( $data );
	}

	/**
	 * @param mixed $cart_item_key
	 * @param mixed $product_id
	 * @param mixed $quantity
	 * @param mixed $variation_id
	 * @param mixed $variation
	 * @param mixed $cart_item_data
	 */
	public function enforce_single_subscription_plan( $cart_item_key, $product_id, $quantity = 1, $variation_id = 0, $variation = array(), $cart_item_data = array() ): void {
		$this->direct_checkout->enforce_single_subscription_plan( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data );
	}

	/**
	 * @param mixed $found
	 * @param mixed $product_id
	 * @param mixed $quantity
	 * @param mixed $variation_id
	 * @param mixed $cart_item_data
	 * @param mixed $cart_item_key
	 */
	public function filter_sold_individually_found( $found, $product_id = 0, $quantity = 1, $variation_id = 0, $cart_item_data = array(), $cart_item_key = '' ): bool {
		return $this->direct_checkout->filter_sold_individually_found( $found, $product_id, $quantity, $variation_id, $cart_item_data, $cart_item_key );
	}

	/**
	 * @param mixed $passed
	 * @param mixed $product_id
	 * @param mixed $quantity
	 * @param mixed $variation_id
	 * @param mixed $variations
	 */
	public function filter_add_to_cart_validation( $passed, $product_id = 0, $quantity = 0, $variation_id = 0, $variations = array() ): bool {
		return $this->direct_checkout->filter_add_to_cart_validation( $passed, $product_id, $quantity, $variation_id, $variations );
	}

	/**
	 * @param mixed  $value
	 * @param string $option
	 * @param mixed  $fallback
	 * @return mixed
	 */
	public function filter_hidden_for_virtual_subscription( $value, $option = '', $fallback = null ) {
		return $this->simplifier->filter_hidden_for_virtual_subscription( $value, $option, $fallback );
	}

	/**
	 * @param string               $block_content
	 * @param array<string, mixed> $block
	 */
	public function filter_checkout_blocks( $block_content, $block ): string {
		return $this->simplifier->filter_checkout_blocks( $block_content, $block );
	}

	/**
	 * @param mixed $classes
	 * @return array<int, string>
	 */
	public function filter_body_class( $classes ): array {
		return $this->simplifier->filter_body_class( $classes );
	}

	public function output_virtual_checkout_css(): void {
		$this->simplifier->output_virtual_checkout_css();
	}

	/**
	 * @param mixed $text
	 * @param mixed $order
	 */
	public function filter_thankyou_text( $text, $order = null ): string {
		return $this->presenter->filter_thankyou_text( $text, $order );
	}

	public function output_virtual_thankyou_css(): void {
		$this->presenter->output_virtual_thankyou_css();
	}

	public function render_pending_payment_notice(): void {
		$this->presenter->render_pending_payment_notice();
	}

	/** @param mixed $order_id */
	public function render_minimal_thankyou_for_virtual( $order_id ): void {
		$this->presenter->render_minimal_thankyou_for_virtual( $order_id );
	}

	/** @param mixed $order */
	public function render_subscription_details_in_order( $order ): void {
		$this->presenter->render_subscription_details_in_order( $order );
	}

	/** @param mixed $order_id */
	public function render_subscription_details_in_thankyou( $order_id ): void {
		$this->presenter->render_subscription_details_in_thankyou( $order_id );
	}

	/**
	 * @param mixed $columns
	 * @return array<string, string>
	 */
	public function add_subscription_column_to_orders( $columns ): array {
		return $this->presenter->add_subscription_column_to_orders( $columns );
	}

	/** @param mixed $order */
	public function render_subscription_column( $order ): void {
		$this->presenter->render_subscription_column( $order );
	}
}
