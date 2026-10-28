<?php
/**
 * Thank-you screen, order subscription summary card, and My Account orders
 * column presenter for VIP subscription orders (SOLID SRP + DRY).
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SubscriptionOrderPresenter {
	/**
	 * @param mixed $text  Default received text.
	 * @param mixed $order Optional WC_Order or ID.
	 */
	public function filter_thankyou_text( $text, $order = null ): string {
		$resolved = SubscriptionOrderInspector::resolve_order( $order );
		if ( null === $resolved ) {
			return SubscriptionOrderInspector::is_thankyou_page()
				? __( 'پرداخت شما با موفقیت انجام شد. اشتراک VIP شما فعال شد.', 'music-wave-vip' )
				: (string) $text;
		}

		if ( SubscriptionOrderInspector::order_contains_only_virtual_subscription( $resolved ) ) {
			return __( 'پرداخت شما با موفقیت انجام شد. اشتراک VIP شما فعال شد.', 'music-wave-vip' );
		}

		return (string) $text;
	}

	public function output_virtual_thankyou_css(): void {
		if ( ! SubscriptionOrderInspector::is_thankyou_page() ) {
			return;
		}

		$order      = SubscriptionOrderInspector::resolve_thankyou_order();
		$is_virtual = $order instanceof \WC_Order ? SubscriptionOrderInspector::order_contains_only_virtual_subscription( $order ) : true;

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<style id="mw-virtual-thankyou-css">';
		if ( $is_virtual ) {
			echo 'body.mw-thankyou--virtual-only .woocommerce-order-overview,'
				. 'body.mw-thankyou--virtual-only .woocommerce-order-details,'
				. 'body.mw-thankyou--virtual-only .woocommerce-customer-details,'
				. 'body.mw-thankyou--virtual-only .woocommerce-order-downloads,'
				. 'body.mw-thankyou--virtual-only .woocommerce-bacs-bank-details,'
				. 'body.mw-thankyou--virtual-only .woocommerce-thankyou-order-details,'
				. 'body.mw-thankyou--virtual-only .mw-order-subscription-details,'
				. 'body.mw-thankyou--virtual-only .woocommerce-thankyou-order-received + ul.woocommerce-order-overview,'
				. 'body.mw-thankyou--virtual-only .woocommerce-thankyou-order-received ~ .woocommerce-order-overview{ display:none !important; }'
				. 'body.mw-thankyou--virtual-only .mw-thankyou-minimal{ max-width:640px; margin:2.5rem auto; padding:2rem; border-radius:1rem; background:var(--wp--preset--color--base, #fff); border:1px solid var(--wp--preset--color--border, #e5e7eb); text-align:center; box-shadow:0 4px 24px rgba(0,0,0,0.06); }'
				. 'body.mw-thankyou--virtual-only .mw-thankyou-minimal__icon{ width:64px; height:64px; margin:0 auto 1rem; border-radius:9999px; background:var(--wp--preset--color--accent, #22c55e); color:#fff; display:flex; align-items:center; justify-content:center; font-size:32px; }'
				. 'body.mw-thankyou--virtual-only .mw-thankyou-minimal__title{ font-size:1.5rem; font-weight:700; margin:0 0 0.5rem; }'
				. 'body.mw-thankyou--virtual-only .mw-thankyou-minimal__desc{ color:var(--wp--preset--color--muted, #6b7280); margin:0 0 1.5rem; line-height:1.6; }'
				. 'body.mw-thankyou--virtual-only .mw-thankyou-minimal__actions{ display:flex; gap:0.75rem; justify-content:center; flex-wrap:wrap; }'
				. 'body.mw-thankyou--virtual-only .mw-thankyou-minimal__btn{ display:inline-flex; align-items:center; justify-content:center; padding:0.75rem 1.25rem; border-radius:9999px; font-weight:600; text-decoration:none; border:1px solid transparent; transition:all 0.15s ease; }'
				. 'body.mw-thankyou--virtual-only .mw-thankyou-minimal__btn--primary{ background:var(--wp--preset--color--accent, #111); color:var(--wp--preset--color--base, #fff); }'
				. 'body.mw-thankyou--virtual-only .mw-thankyou-minimal__btn--ghost{ background:transparent; border-color:var(--wp--preset--color--border, #e5e7eb); color:inherit; }'
				. 'body.woocommerce-order-received .woocommerce-order-overview{ display:none !important; }';
		}
		echo 'body.woocommerce-order-received .woocommerce-customer-details--billing,'
			. 'body.woocommerce-order-received .woocommerce-customer-details .col2-set .col-1,'
			. 'body.woocommerce-order-received .woocommerce-order-overview li.order,'
			. 'body.woocommerce-order-received .woocommerce-order-overview__order,'
			. 'body.woocommerce-order-received .woocommerce-order-overview li.woocommerce-order-overview__order{ display:none !important; }';
		echo '</style>';
		// phpcs:enable
	}

	/** @param mixed $order_id Order ID. */
	public function render_minimal_thankyou_for_virtual( $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof \WC_Order || ! SubscriptionOrderInspector::order_contains_only_virtual_subscription( $order ) ) {
			return;
		}
		$dashboard_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );
		if ( '' === $dashboard_url ) {
			$dashboard_url = home_url( '/' );
		}

		echo '<div class="mw-thankyou-minimal">';
		echo '<div class="mw-thankyou-minimal__icon" aria-hidden="true">✓</div>';
		echo '<h2 class="mw-thankyou-minimal__title">' . esc_html__( 'اشتراک شما فعال شد!', 'music-wave-vip' ) . '</h2>';
		echo '<p class="mw-thankyou-minimal__desc">' . esc_html__( 'پرداخت با موفقیت انجام شد و دسترسی VIP شما فعال شد. می‌توانید از پنل کاربری خود استفاده کنید و از محتوای ویژه لذت ببرید.', 'music-wave-vip' ) . '</p>';
		echo '<div class="mw-thankyou-minimal__actions">';
		echo '<a class="mw-thankyou-minimal__btn mw-thankyou-minimal__btn--primary" href="' . esc_url( $dashboard_url ) . '">' . esc_html__( 'رفتن به پنل کاربری', 'music-wave-vip' ) . '</a>';
		echo '<a class="mw-thankyou-minimal__btn mw-thankyou-minimal__btn--ghost" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'بازگشت به خانه', 'music-wave-vip' ) . '</a>';
		echo '</div>';
		echo '</div>';
	}

	/** @param mixed $order Order instance. */
	public function render_subscription_details_in_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$levels = $order->get_meta( '_mw_vip_granted_levels' );
		if ( empty( $levels ) && ! SubscriptionOrderInspector::order_has_subscription_item( $order ) ) {
			return;
		}

		$granted_at = (int) $order->get_meta( '_mw_vip_granted_at' );
		$expiries   = $order->get_meta( '_mw_vip_granted_expiries' );
		$expiries   = is_array( $expiries ) ? $expiries : array();

		echo '<section class="mw-order-subscription-details" style="margin:2rem 0;padding:1.25rem;background:var(--mw-color-surface, #fff);border:1px solid var(--mw-color-border, #ddd);border-radius:0.75rem;">';
		echo '<h3 style="margin:0 0 0.75rem;">' . esc_html__( 'جزئیات اشتراک', 'music-wave-vip' ) . '</h3>';

		if ( ! empty( $levels ) && is_array( $levels ) ) {
			echo '<ul style="margin:0;padding:0;list-style:none;display:grid;gap:0.5rem;">';
			foreach ( $levels as $level ) {
				$level = sanitize_key( (string) $level );
				$exp   = isset( $expiries[ $level ] ) ? (int) $expiries[ $level ] : 0;
				$label = 0 === $exp ? __( 'مادام‌العمر', 'music-wave-vip' ) : ( $exp > 0 && function_exists( 'date_i18n' ) ? date_i18n( get_option( 'date_format' ), $exp ) : '' );
				echo '<li><strong>' . esc_html( $level ) . '</strong> — ' . esc_html( $label );
				if ( $exp > 0 ) {
					$remaining = (int) ceil( ( $exp - time() ) / DAY_IN_SECONDS );
					if ( $remaining > 0 ) {
						/* translators: %d: value 1. */
						echo ' <small>(' . esc_html( sprintf( _n( '%d روز باقی‌مانده', '%d روز باقی‌مانده', $remaining, 'music-wave-vip' ), $remaining ) ) . ')</small>';
					} else {
						echo ' <small style="color:#d63638;">(' . esc_html__( 'منقضی شده', 'music-wave-vip' ) . ')</small>';
					}
				}
				echo '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p style="margin:0;color:var(--mw-color-text-muted, #666);">' . esc_html__( 'این سفارش شامل طرح اشتراک VIP است. دسترسی پس از پرداخت فعال شد.', 'music-wave-vip' ) . '</p>';
		}

		if ( $granted_at > 0 ) {
			/* translators: %s: value 1. */
			echo '<p style="margin:0.75rem 0 0;font-size:0.875rem;color:var(--mw-color-text-muted, #666);">' . esc_html( sprintf( __( 'تاریخ اعطا: %s', 'music-wave-vip' ), date_i18n( get_option( 'date_format' ), $granted_at ) ) ) . '</p>';
		}

		echo '</section>';
	}

	/** @param mixed $order_id Order ID. */
	public function render_subscription_details_in_thankyou( $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof \WC_Order || SubscriptionOrderInspector::order_contains_only_virtual_subscription( $order ) ) {
			return;
		}
		$this->render_subscription_details_in_order( $order );
	}

	/**
	 * @param mixed $columns Orders table columns.
	 * @return array<string, string>
	 */
	public function add_subscription_column_to_orders( $columns ): array {
		if ( ! is_array( $columns ) ) {
			return array();
		}
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order-total' === $key ) {
				$new['mw-subscription'] = __( 'اشتراک', 'music-wave-vip' );
			}
		}
		if ( ! isset( $new['mw-subscription'] ) ) {
			$new['mw-subscription'] = __( 'اشتراک', 'music-wave-vip' );
		}

		return $new;
	}

	/** @param mixed $order Order instance. */
	public function render_subscription_column( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			echo '<span>—</span>';

			return;
		}
		$levels = $order->get_meta( '_mw_vip_granted_levels' );
		if ( empty( $levels ) || ! is_array( $levels ) ) {
			echo SubscriptionOrderInspector::order_has_subscription_item( $order )
				? '<span class="mw-order-subscription-badge">' . esc_html__( 'VIP', 'music-wave-vip' ) . '</span>'
				: '<span>—</span>';

			return;
		}

		echo '<span class="mw-order-subscription-badge mw-order-subscription-badge--active">' . esc_html( implode( ', ', array_map( 'sanitize_key', $levels ) ) ) . '</span>';
	}
}
