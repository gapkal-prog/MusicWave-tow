<?php
/**
 * Shared UI helpers for MusicWave VIP admin screens.
 *
 * Extracted from SettingsPage to satisfy SRP and DRY: tooltip, example box,
 * product reference table and action notices are now reusable and testable
 * without coupling to the full SettingsPage rendering lifecycle.
 *
 * @package ManaCore\MusicWave\Vip\Admin
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VipSettingsUi {

	public static function tooltip( string $text ): string {
		return ' <span class="mwvip-tooltip" title="' . esc_attr( $text ) . '" aria-label="' . esc_attr( $text ) . '"><span class="dashicons dashicons-editor-help" style="font-size:16px;vertical-align:middle;color:#646970;"></span></span>';
	}

	public static function example_box( string $title, string $code, string $note = '' ): string {
		$html  = '<div class="mwvip-example"><strong>' . esc_html( $title ) . '</strong>';
		$html .= '<code>' . esc_html( $code ) . '</code>';
		if ( '' !== $note ) {
			$html .= '<em>' . esc_html( $note ) . '</em>';
		}
		$html .= '</div>';
		return $html;
	}

	public static function wc_products_url(): string {
		return admin_url( 'edit.php?post_type=product' );
	}

	public static function wc_add_product_url(): string {
		return admin_url( 'post-new.php?post_type=product' );
	}

	public static function product_reference(): string {
		$html  = '<div class="mwvip-product-ref">';
		$html .= '<p><a href="' . esc_url( self::wc_products_url() ) . '" class="button" target="_blank" rel="noopener">' . esc_html__( 'باز کردن ووکامرس → محصولات', 'music-wave-vip' ) . ' <span class="dashicons dashicons-external" style="font-size:16px;vertical-align:middle;"></span></a> ';
		$html .= '<a href="' . esc_url( self::wc_add_product_url() ) . '" class="button button-primary" target="_blank" rel="noopener" style="margin-left:6px;">' . esc_html__( '+ ایجاد محصول طرح جدید', 'music-wave-vip' ) . '</a></p>';
		$html .= '<p class="description">' . esc_html__( 'نکته: یک محصول عادی ووکامرس بسازید (محصول ساده، قیمت و غیره). شناسهٔ آن را از فهرست محصولات یادداشت کنید (روی آن بروید → ID). سپس شناسه را پایین وارد کنید.', 'music-wave-vip' ) . '</p>';

		if ( function_exists( 'wc_get_products' ) || post_type_exists( 'product' ) ) {
			$products = get_posts(
				array(
					'post_type'      => 'product',
					'posts_per_page' => 8,
					'post_status'    => array( 'publish', 'draft' ),
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);
			if ( ! empty( $products ) ) {
				$html .= '<table class="widefat striped mwvip-product-table"><thead><tr><th>' . esc_html__( 'شناسه', 'music-wave-vip' ) . '</th><th>' . esc_html__( 'محصول', 'music-wave-vip' ) . '</th><th>' . esc_html__( 'وضعیت', 'music-wave-vip' ) . '</th><th>' . esc_html__( 'عملیات', 'music-wave-vip' ) . '</th></tr></thead><tbody>';
				foreach ( $products as $p ) {
					$pid = $p instanceof \WP_Post
						? absint( $p->ID )
						: ( is_object( $p ) && property_exists( $p, 'ID' ) ? absint( $p->ID ) : ( is_numeric( $p ) ? absint( $p ) : 0 ) );
					if ( $pid < 1 ) {
						continue;
					}
					$edit  = get_edit_post_link( $pid );
					$html .= '<tr><td><code>' . esc_html( (string) $pid ) . '</code></td><td>' . esc_html( get_the_title( $pid ) ) . '</td><td>' . esc_html( (string) get_post_status( $pid ) ) . '</td><td><a href="' . esc_url( (string) $edit ) . '">' . esc_html__( 'ویرایش', 'music-wave-vip' ) . '</a></td></tr>';
				}
				$html .= '</tbody></table>';
				$html .= '<p class="description">' . esc_html__( 'شناسه را در فیلد طرح پایین وارد کنید. اگر یک سطح چند محصول می‌پذیرد، شناسه‌ها را با کاما جدا کنید.', 'music-wave-vip' ) . '</p>';
			} else {
				$html .= '<p class="description">' . esc_html__( 'محصولی پیدا نشد. برای شروع، نخستین محصول طرح VIP خود را ایجاد کنید.', 'music-wave-vip' ) . '</p>';
			}
		} else {
			$html .= '<div class="notice notice-warning inline" style="margin:10px 0;"><p>' . esc_html__( 'ووکامرس فعال نیست. برای مدیریت محصولات طرح VIP آن را فعال کنید. پس از فعال‌شدن ووکامرس، شناسه‌های محصول در دسترس خواهند بود.', 'music-wave-vip' ) . '</p></div>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Render one-shot notice after plan admin actions.
	 *
	 * @return string HTML or empty when no notice.
	 */
	public static function plan_action_notice(): string {
		$notice = get_transient( 'mwvip_plan_action_notice' );
		if ( ! is_string( $notice ) || '' === $notice ) {
			return '';
		}
		delete_transient( 'mwvip_plan_action_notice' );
		$messages = array(
			'mwvip_defaults_created' => __( 'محصولات طرح پیش‌فرض ایجاد شدند. قیمت آن‌ها را در ووکامرس → محصولات تعیین کنید.', 'music-wave-vip' ),
			'mwvip_defaults_skipped' => __( 'طرح‌های پیش‌فرض از قبل پیکربندی‌شده‌اند؛ مورد جدیدی برای ایجاد نیست.', 'music-wave-vip' ),
			'mwvip_defaults_failed'  => __( 'ایجاد محصولات طرح پیش‌فرض ناموفق بود. آیا ووکامرس فعال است؟', 'music-wave-vip' ),
			'mwvip_plan_removed'     => __( 'نگاشت طرح حذف شد. خود محصول حذف نشده است.', 'music-wave-vip' ),
		);
		if ( ! isset( $messages[ $notice ] ) ) {
			return '';
		}
		$class = 'mwvip_defaults_failed' === $notice ? 'notice-error' : 'notice-success';
		return '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
	}
}
