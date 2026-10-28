<?php
/**
 * WooCommerce My Account Integration Bridge.
 *
 * Isolates all WooCommerce endpoint capture, active-tab resolution, session/customer
 * readiness guards, WooCommerce panel/block rendering, and compact accessible field
 * attribute normalization away from core library/membership logic.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Account;

use ManaCore\MusicWave\Core\Blocks\BlockSupport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WooAccountBridge {
	/**
	 * Canonical WooCommerce address keys suppressed or made optional for virtual digital subscriptions.
	 * Shared across MusicWave Core (`WooAccountBridge`) and MusicWave VIP (`Checkout/*`).
	 *
	 * @var array<int, string>
	 */
	public const VIRTUAL_ADDRESS_KEYS = array(
		'company',
		'address_1',
		'address_2',
		'city',
		'state',
		'postcode',
		'country',
		'phone',
	);

	/**
	 * Register WooCommerce account/checkout field normalization hooks once.
	 */
	public function register_field_filters(): void {
		add_filter( 'woocommerce_form_field_args', array( $this, 'filter_form_field_args' ), 10, 3 );
		add_filter( 'woocommerce_default_address_fields', array( $this, 'filter_default_address_fields' ), 10, 1 );
		add_filter( 'woocommerce_billing_fields', array( $this, 'filter_billing_fields' ), 20, 2 );
	}

	/**
	 * Map active WooCommerce or MusicWave query endpoint to dashboard panel key.
	 *
	 * @param array<int, string> $available_keys Active panel keys in the current dashboard.
	 */
	public function active_panel_key( array $available_keys, string $library_endpoint, string $membership_endpoint ): string {
		global $wp;
		if ( ! isset( $wp->query_vars ) || ! is_array( $wp->query_vars ) ) {
			return '';
		}

		$endpoint_map = array(
			$membership_endpoint         => 'membership',
			$library_endpoint            => 'library',
			'orders'                     => 'orders',
			'view-order'                 => 'orders',
			'downloads'                  => 'downloads',
			'edit-address'               => 'addresses',
			'payment-methods'            => 'payment',
			'add-payment-method'         => 'payment',
			'delete-payment-method'      => 'payment',
			'set-default-payment-method' => 'payment',
			'edit-account'               => 'account',
		);

		foreach ( $endpoint_map as $query_var => $panel_key ) {
			if ( array_key_exists( $query_var, $wp->query_vars ) && in_array( $panel_key, $available_keys, true ) ) {
				return $panel_key;
			}
		}

		return '';
	}

	/**
	 * Capture standard WooCommerce My Account output for one dashboard panel cleanly,
	 * replaying active WooCommerce endpoint actions (`view-order`, `edit-address`, etc.) first.
	 */
	public function capture_panel( string $panel ): string {
		$replayed = $this->capture_endpoint_action( $panel );
		if ( null !== $replayed ) {
			return $replayed;
		}

		if ( 'orders' === $panel ) {
			if ( ! function_exists( 'woocommerce_account_orders' ) ) {
				return $this->preview_placeholder( __( 'سفارش‌ها', 'music-wave-core' ) );
			}
			ob_start();
			woocommerce_account_orders( 1 );
			return trim( (string) ob_get_clean() );
		}

		if ( 'account' === $panel ) {
			if ( ! function_exists( 'woocommerce_account_edit_account' ) ) {
				return $this->preview_placeholder( __( 'مشخصات حساب', 'music-wave-core' ) );
			}
			ob_start();
			woocommerce_account_edit_account();
			return trim( (string) ob_get_clean() );
		}

		if ( ! $this->customer_ready() ) {
			$labels = array(
				'downloads' => __( 'دانلودها', 'music-wave-core' ),
				'addresses' => __( 'آدرس صورتحساب و حمل‌ونقل', 'music-wave-core' ),
				'payment'   => __( 'روش‌های پرداخت ذخیره‌شده', 'music-wave-core' ),
			);
			return $this->preview_placeholder( $labels[ $panel ] ?? $panel );
		}

		if ( 'downloads' === $panel && function_exists( 'woocommerce_account_downloads' ) ) {
			ob_start();
			woocommerce_account_downloads();
			return trim( (string) ob_get_clean() );
		}

		if ( 'addresses' === $panel && function_exists( 'woocommerce_account_edit_address' ) ) {
			$markup = '<div class="mw-user-dashboard__addresses">';
			ob_start();
			echo '<section class="mw-user-dashboard__address"><h4>' . esc_html__( 'آدرس صورتحساب', 'music-wave-core' ) . '</h4>';
			woocommerce_account_edit_address( 'billing' );
			echo '</section>';
			$markup .= (string) ob_get_clean();
			ob_start();
			echo '<section class="mw-user-dashboard__address"><h4>' . esc_html__( 'آدرس حمل‌ونقل', 'music-wave-core' ) . '</h4>';
			woocommerce_account_edit_address( 'shipping' );
			echo '</section>';
			$markup .= (string) ob_get_clean();
			return $markup . '</div>';
		}

		if ( 'payment' === $panel && function_exists( 'woocommerce_account_payment_methods' ) ) {
			ob_start();
			woocommerce_account_payment_methods();
			return trim( (string) ob_get_clean() );
		}

		return '';
	}

	/**
	 * Render granular WooCommerce orders block (`music-wave/dashboard-orders`).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_orders_block( array $attributes = array() ): string {
		$compact      = BlockSupport::bool_attribute( $attributes, 'compact', true );
		$per_page     = BlockSupport::range_attribute( $attributes, 'perPage', 1, 20, 6 );
		$heading_text = BlockSupport::optional_text_attribute( $attributes, 'heading', __( 'سفارش‌ها', 'music-wave-core' ), 'showHeading' );
		$show_heading = '' !== $heading_text;

		$classes = array( 'mw-dashboard-orders' );
		if ( $compact ) {
			$classes[] = 'mw-dashboard-orders--compact';
		}

		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return $this->guest_block( $classes, __( 'برای مشاهده سفارش‌ها وارد شوید.', 'music-wave-core' ) );
		}

		if ( ! function_exists( 'wc_get_template' ) || ! $this->customer_ready() ) {
			return '<section ' . BlockSupport::wrapper_attributes( implode( ' ', $classes ) ) . '>'
				. ( $show_heading ? '<h3 class="mw-dashboard-orders__heading">' . esc_html( $heading_text ) . '</h3>' : '' )
				. $this->preview_placeholder( __( 'سفارش‌ها', 'music-wave-core' ) )
				. '</section>';
		}

		$limit_filter = static function ( array $args ) use ( $per_page ): array {
			$args['limit'] = $per_page;
			return $args;
		};
		add_filter( 'woocommerce_my_account_my_orders_query', $limit_filter, 20 );

		ob_start();
		echo '<section ' . BlockSupport::wrapper_attributes( implode( ' ', $classes ) ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $show_heading ) {
			echo '<h3 class="mw-dashboard-orders__heading">' . esc_html( $heading_text ) . '</h3>';
		}
		echo '<div class="mw-dashboard-orders__inner">';
		wc_get_template(
			'myaccount/orders.php',
			array(
				'current_user' => get_user_by( 'id', $user_id ),
				'order_count'  => $per_page,
			)
		);
		echo '</div></section>';
		remove_filter( 'woocommerce_my_account_my_orders_query', $limit_filter, 20 );

		return (string) ob_get_clean();
	}

	/**
	 * Render granular WooCommerce downloads block (`music-wave/dashboard-downloads`).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_downloads_block( array $attributes = array() ): string {
		$compact      = BlockSupport::bool_attribute( $attributes, 'compact', true );
		$heading_text = BlockSupport::optional_text_attribute( $attributes, 'heading', __( 'دانلودها', 'music-wave-core' ), 'showHeading' );
		$show_heading = '' !== $heading_text;

		$classes = array( 'mw-dashboard-downloads' );
		if ( $compact ) {
			$classes[] = 'mw-dashboard-downloads--compact';
		}

		if ( get_current_user_id() < 1 ) {
			return $this->guest_block( $classes, __( 'برای مشاهده دانلودها وارد شوید.', 'music-wave-core' ) );
		}

		if ( ! function_exists( 'wc_get_template' ) || ! $this->customer_ready() ) {
			return '<section ' . BlockSupport::wrapper_attributes( implode( ' ', $classes ) ) . '>'
				. ( $show_heading ? '<h3 class="mw-dashboard-downloads__heading">' . esc_html( $heading_text ) . '</h3>' : '' )
				. $this->preview_placeholder( __( 'دانلودها', 'music-wave-core' ) )
				. '</section>';
		}

		ob_start();
		echo '<section ' . BlockSupport::wrapper_attributes( implode( ' ', $classes ) ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $show_heading ) {
			echo '<h3 class="mw-dashboard-downloads__heading">' . esc_html( $heading_text ) . '</h3>';
		}
		echo '<div class="mw-dashboard-downloads__inner">';
		wc_get_template( 'myaccount/downloads.php' );
		echo '</div></section>';

		return (string) ob_get_clean();
	}

	/**
	 * Render granular WooCommerce addresses block (`music-wave/dashboard-addresses`).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_addresses_block( array $attributes = array() ): string {
		$compact       = BlockSupport::bool_attribute( $attributes, 'compact', true );
		$show_billing  = BlockSupport::bool_attribute( $attributes, 'showBilling', true );
		$show_shipping = BlockSupport::bool_attribute( $attributes, 'showShipping', true );
		$heading_text  = BlockSupport::optional_text_attribute( $attributes, 'heading', __( 'آدرس‌ها', 'music-wave-core' ), 'showHeading' );
		$show_heading  = '' !== $heading_text;

		$classes = array( 'mw-dashboard-addresses' );
		if ( $compact ) {
			$classes[] = 'mw-dashboard-addresses--compact';
		}

		if ( get_current_user_id() < 1 ) {
			return $this->guest_block( $classes, __( 'برای مدیریت آدرس‌ها وارد شوید.', 'music-wave-core' ) );
		}

		if ( ! function_exists( 'wc_get_account_formatted_address' ) || ! $this->customer_ready() ) {
			return '<section ' . BlockSupport::wrapper_attributes( implode( ' ', $classes ) ) . '>'
				. ( $show_heading ? '<h3 class="mw-dashboard-addresses__heading">' . esc_html( $heading_text ) . '</h3>' : '' )
				. $this->preview_placeholder( __( 'آدرس صورتحساب و حمل‌ونقل', 'music-wave-core' ) )
				. '</section>';
		}

		ob_start();
		echo '<section ' . BlockSupport::wrapper_attributes( implode( ' ', $classes ) ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $show_heading ) {
			echo '<h3 class="mw-dashboard-addresses__heading">' . esc_html( $heading_text ) . '</h3>';
		}
		echo '<div class="mw-dashboard-addresses__grid">';
		if ( $show_billing ) {
			$this->render_address_card( 'billing', __( 'آدرس صورتحساب', 'music-wave-core' ) );
		}
		if ( $show_shipping ) {
			$this->render_address_card( 'shipping', __( 'آدرس حمل‌ونقل', 'music-wave-core' ) );
		}
		echo '</div></section>';

		return (string) ob_get_clean();
	}

	/**
	 * Render granular WooCommerce payment methods block (`music-wave/dashboard-payment`).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_payment_block( array $attributes = array() ): string {
		$compact      = BlockSupport::bool_attribute( $attributes, 'compact', true );
		$heading_text = BlockSupport::optional_text_attribute( $attributes, 'heading', __( 'روش‌های پرداخت', 'music-wave-core' ), 'showHeading' );
		$show_heading = '' !== $heading_text;

		$classes = array( 'mw-dashboard-payment' );
		if ( $compact ) {
			$classes[] = 'mw-dashboard-payment--compact';
		}

		if ( get_current_user_id() < 1 ) {
			return $this->guest_block( $classes, __( 'برای مدیریت روش‌های پرداخت وارد شوید.', 'music-wave-core' ) );
		}

		if ( ! function_exists( 'wc_get_template' ) || ! $this->customer_ready() ) {
			return '<section ' . BlockSupport::wrapper_attributes( implode( ' ', $classes ) ) . '>'
				. ( $show_heading ? '<h3 class="mw-dashboard-payment__heading">' . esc_html( $heading_text ) . '</h3>' : '' )
				. $this->preview_placeholder( __( 'روش‌های پرداخت ذخیره‌شده', 'music-wave-core' ) )
				. '</section>';
		}

		ob_start();
		echo '<section ' . BlockSupport::wrapper_attributes( implode( ' ', $classes ) ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $show_heading ) {
			echo '<h3 class="mw-dashboard-payment__heading">' . esc_html( $heading_text ) . '</h3>';
		}
		echo '<div class="mw-dashboard-payment__inner">';
		wc_get_template( 'myaccount/payment-methods.php' );
		echo '</div></section>';

		return (string) ob_get_clean();
	}

	/**
	 * Render granular account details / profile edit block (`dashboard-edit` & `dashboard-account`).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_account_edit_block( array $attributes = array() ): string {
		$compact      = BlockSupport::bool_attribute( $attributes, 'compact', true );
		$show_avatar  = BlockSupport::bool_attribute( $attributes, 'showAvatar', false );
		$show_notices = BlockSupport::bool_attribute( $attributes, 'showNotices', true );
		$heading_text = BlockSupport::optional_text_attribute( $attributes, 'heading', __( 'جزئیات و ویرایش حساب', 'music-wave-core' ), 'showHeading' );
		$show_heading = '' !== $heading_text;
		$description  = BlockSupport::optional_text_attribute( $attributes, 'description', '' );

		$classes = array( 'mw-dashboard-edit', 'mw-dashboard-account' );
		if ( $compact ) {
			$classes[] = 'mw-dashboard-edit--compact';
			$classes[] = 'mw-dashboard-account--compact';
		}

		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return $this->guest_block( $classes, __( 'برای ویرایش حساب وارد شوید.', 'music-wave-core' ) );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! function_exists( 'wc_get_template' ) ) {
			return '<section ' . BlockSupport::wrapper_attributes( implode( ' ', $classes ) ) . '>'
				. ( $show_heading ? '<h3 class="mw-dashboard-edit__heading">' . esc_html( $heading_text ) . '</h3>' : '' )
				. $this->preview_placeholder( __( 'مشخصات حساب', 'music-wave-core' ) )
				. '</section>';
		}

		ob_start();
		echo '<section ' . BlockSupport::wrapper_attributes( implode( ' ', $classes ) ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $show_heading ) {
			echo '<div class="mw-dashboard-edit__head">';
			if ( $show_avatar ) {
				echo '<div class="mw-dashboard-edit__avatar">' . get_avatar( $user_id, 44 ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '<div class="mw-dashboard-edit__head-text"><h3 class="mw-dashboard-edit__heading">' . esc_html( $heading_text ) . '</h3>';
			if ( '' !== $description ) {
				echo '<p class="mw-dashboard-edit__desc">' . esc_html( $description ) . '</p>';
			}
			echo '</div></div>';
		}
		if ( $show_notices && function_exists( 'wc_print_notices' ) ) {
			wc_print_notices();
		}
		echo '<div class="mw-dashboard-edit__form mw-dashboard-account__form">';
		wc_get_template( 'myaccount/form-edit-account.php', array( 'user' => $user ) );
		echo '</div></section>';

		return (string) ob_get_clean();
	}

	/**
	 * Ensure `WC()->customer` is initialized before calling WooCommerce account templates.
	 */
	public function customer_ready(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'WC' ) && is_object( WC() ) && null !== WC()->customer;
	}

	/**
	 * Add compact, accessible classes and autocomplete tokens to WooCommerce fields.
	 *
	 * @param array<string, mixed> $args  Field arguments.
	 * @param string               $key   Field key.
	 * @param mixed                $value Field value.
	 * @return array<string, mixed>
	 */
	public function filter_form_field_args( array $args, string $key, $value = null ): array {
		unset( $value );
		if ( ! function_exists( 'is_account_page' ) ) {
			return $args;
		}

		$args['class']       = array_values( array_unique( array_merge( isset( $args['class'] ) && is_array( $args['class'] ) ? $args['class'] : array( 'form-row' ), array( 'mw-field', 'mw-field--compact' ) ) ) );
		$args['input_class'] = array_values( array_unique( array_merge( isset( $args['input_class'] ) && is_array( $args['input_class'] ) ? $args['input_class'] : array(), array( 'mw-input' ) ) ) );
		$args['label_class'] = array_values( array_unique( array_merge( isset( $args['label_class'] ) && is_array( $args['label_class'] ) ? $args['label_class'] : array(), array( 'mw-label' ) ) ) );

		if ( empty( $args['placeholder'] ) && ! empty( $args['label'] ) && is_string( $args['label'] ) ) {
			$args['placeholder'] = wp_strip_all_tags( $args['label'] );
		}

		$args['custom_attributes'] = isset( $args['custom_attributes'] ) && is_array( $args['custom_attributes'] ) ? $args['custom_attributes'] : array();
		if ( ! isset( $args['custom_attributes']['autocomplete'] ) ) {
			$map = array(
				'billing_first_name'  => 'given-name',
				'shipping_first_name' => 'given-name',
				'account_first_name'  => 'given-name',
				'billing_last_name'   => 'family-name',
				'shipping_last_name'  => 'family-name',
				'account_last_name'   => 'family-name',
				'billing_email'       => 'email',
				'account_email'       => 'email',
				'billing_phone'       => 'tel',
				'billing_address_1'   => 'address-line1',
				'shipping_address_1'  => 'address-line1',
				'billing_city'        => 'address-level2',
				'shipping_city'       => 'address-level2',
				'billing_state'       => 'address-level1',
				'shipping_state'      => 'address-level1',
				'billing_postcode'    => 'postal-code',
				'shipping_postcode'   => 'postal-code',
				'billing_country'     => 'country',
				'shipping_country'    => 'country',
			);
			if ( isset( $map[ $key ] ) ) {
				$args['custom_attributes']['autocomplete'] = $map[ $key ];
			}
		}

		return $args;
	}

	/**
	 * Keep optional secondary street and company fields compact across address forms.
	 *
	 * @param array<string, mixed> $fields Address fields.
	 * @return array<string, mixed>
	 */
	public function filter_default_address_fields( array $fields ): array {
		if ( isset( $fields['address_2'] ) && is_array( $fields['address_2'] ) ) {
			$fields['address_2']['label']    = $fields['address_2']['label'] ?? __( 'آپارتمان، واحد (اختیاری)', 'music-wave-core' );
			$fields['address_2']['required'] = false;
			$fields['address_2']['class']    = array( 'form-row-wide', 'mw-field', 'mw-field--compact', 'mw-field--optional' );
		}
		if ( isset( $fields['company'] ) && is_array( $fields['company'] ) ) {
			$fields['company']['required'] = false;
			$fields['company']['class']    = array( 'form-row-wide', 'mw-field', 'mw-field--compact', 'mw-field--optional' );
			$fields['company']['priority'] = 120;
		}
		foreach ( $fields as &$field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$field['class'] = array_values( array_unique( array_merge( isset( $field['class'] ) && is_array( $field['class'] ) ? $field['class'] : array( 'form-row-wide' ), array( 'mw-field', 'mw-field--compact' ) ) ) );
		}
		unset( $field );

		return $fields;
	}

	/**
	 * Keep billing email prominent across account editing forms.
	 *
	 * @param mixed $fields  Billing fields.
	 * @param mixed $country Selected country code.
	 * @return array<string, mixed>
	 */
	public function filter_billing_fields( $fields, $country = '' ): array {
		unset( $country );
		if ( ! is_array( $fields ) ) {
			return array();
		}
		if ( isset( $fields['billing_email'] ) && is_array( $fields['billing_email'] ) ) {
			$fields['billing_email']['priority'] = 5;
			$fields['billing_email']['class']    = array( 'form-row-wide', 'mw-field', 'mw-field--compact', 'mw-field--email' );
		}

		return $this->filter_default_address_fields( $fields );
	}

	private function render_address_card( string $type, string $title ): void {
		$type    = 'shipping' === $type ? 'shipping' : 'billing';
		$address = (string) wc_get_account_formatted_address( $type );
		echo '<div class="mw-dashboard-addresses__card"><h4 class="mw-dashboard-addresses__card-title">' . esc_html( $title ) . '</h4>';
		if ( '' === $address ) {
			echo '<p class="mw-dashboard-addresses__empty">' . esc_html__( 'هنوز آدرسی ثبت نکرده‌اید.', 'music-wave-core' ) . '</p>';
		} else {
			echo '<address class="mw-dashboard-addresses__address">' . wp_kses_post( $address ) . '</address>';
		}
		echo '<a class="wp-element-button is-style-outline mw-dashboard-addresses__edit" href="' . esc_url( wc_get_endpoint_url( 'edit-address', $type ) ) . '">' . esc_html__( 'ویرایش', 'music-wave-core' ) . '</a></div>';
	}

	private function capture_endpoint_action( string $panel ): ?string {
		global $wp;
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! isset( $wp->query_vars ) || ! is_array( $wp->query_vars ) ) {
			return null;
		}
		$panels = array(
			'orders'    => array( 'orders', 'view-order' ),
			'downloads' => array( 'downloads' ),
			'addresses' => array( 'edit-address' ),
			'payment'   => array( 'payment-methods', 'add-payment-method', 'delete-payment-method', 'set-default-payment-method' ),
			'account'   => array( 'edit-account' ),
		);
		if ( ! isset( $panels[ $panel ] ) ) {
			return null;
		}
		foreach ( $wp->query_vars as $key => $value ) {
			if ( in_array( $key, $panels[ $panel ], true ) && has_action( 'woocommerce_account_' . $key . '_endpoint' ) ) {
				ob_start();
				do_action( 'woocommerce_account_' . $key . '_endpoint', $value );
				$captured = trim( (string) ob_get_clean() );
				return '' !== $captured ? $captured : null;
			}
		}

		return null;
	}

	private function preview_placeholder( string $section ): string {
		return '<p class="mw-user-dashboard__empty-panel">'
			. esc_html(
				sprintf(
					/* translators: %s: section name. */
					__( '%s زنده شما در اینجا در سایت ظاهر می‌شود.', 'music-wave-core' ),
					$section
				)
			)
			. '</p>';
	}

	/**
	 * @param array<int, string> $classes Wrapper CSS classes.
	 */
	private function guest_block( array $classes, string $message ): string {
		return '<section ' . BlockSupport::wrapper_attributes( implode( ' ', $classes ) ) . '>'
			. '<p>' . esc_html( $message ) . '</p>'
			. '<a class="wp-element-button is-style-outline" href="' . esc_url( AuthPolicy::login_url( BlockSupport::current_url() ) ) . '">' . esc_html__( 'ورود', 'music-wave-core' ) . '</a>'
			. '</section>';
	}
}
