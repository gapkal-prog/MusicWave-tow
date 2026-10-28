<?php
/**
 * Resolves customer-facing purchase, VIP membership, and sign-in calls to action
 * for gated releases and download panels (SOLID SRP + DRY).
 *
 * @package ManaCore\MusicWave\Core\Access
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Access;

use ManaCore\MusicWave\Core\Blocks\BlockSupport;
use ManaCore\MusicWave\Core\Blocks\Release\ReleaseBlockContext;
use ManaCore\MusicWave\Core\Support\Settings;
use ManaCore\MusicWave\Core\Account\AuthPolicy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AccessCtaResolver {
	/** @var ReleaseBlockContext */
	private $context;

	public function __construct( ReleaseBlockContext $context ) {
		$this->context = $context;
	}

	/**
	 * Build complete denied-access message and action buttons markup for a decision.
	 *
	 * @param int                  $release_id Release post ID.
	 * @param AccessDecision       $decision   Evaluated policy decision.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array{message: string, actions_html: string}
	 */
	public function resolve_panel_content( int $release_id, AccessDecision $decision, array $attributes ): array {
		$reason  = $decision->reason();
		$buttons = array();

		if ( 'login_required' === $reason ) {
			$message   = $this->context->message_attribute(
				$attributes,
				'loginMessage',
				'login_required_message',
				__( 'برای دسترسی رایگان و پخش کامل این انتشار، وارد حساب کاربری خود شوید.', 'music-wave-core' )
			);
			$login_cta = $this->login_button_html(
				$this->context->message_attribute(
					$attributes,
					'loginLabel',
					'',
					__( 'ورود / ثبت‌نام در سایت', 'music-wave-core' )
				),
				true
			);
			if ( '' !== $login_cta ) {
				$buttons[] = $login_cta;
			}

			return array(
				'message'      => $message,
				'actions_html' => $this->wrap_buttons( $buttons ),
			);
		}

		if ( 'purchase_or_membership_required' === $reason ) {
			$message = $this->context->message_attribute(
				$attributes,
				'hybridMessage',
				'purchase_or_membership_message',
				__( 'این انتشار را به‌صورت تکی خریداری کنید یا با تهیهٔ اشتراک ویژه VIP به تمام آرشیو ویژه دسترسی فوری داشته باشید.', 'music-wave-core' )
			);

			$buy_btn = $this->purchase_button_html( $release_id, $attributes, true );
			$vip_btn = $this->membership_button_html( $attributes, '' === $buy_btn );

			if ( '' !== $buy_btn ) {
				$buttons[] = $buy_btn;
			}
			if ( '' !== $vip_btn ) {
				$buttons[] = $vip_btn;
			}
			if ( get_current_user_id() < 1 ) {
				$buttons[] = $this->login_button_html( __( 'ورود به حساب کاربری', 'music-wave-core' ), empty( $buttons ) );
			}

			return array(
				'message'      => $message,
				'actions_html' => $this->wrap_buttons( $buttons ),
			);
		}

		if ( 'purchase_required' === $reason ) {
			$message = $this->context->message_attribute(
				$attributes,
				'purchaseMessage',
				'purchase_message',
				__( 'برای باز کردن تجربهٔ کامل و دانلود فایل اصلی، این انتشار را خریداری کنید.', 'music-wave-core' )
			);
			$buy_btn = $this->purchase_button_html( $release_id, $attributes, true );
			if ( '' !== $buy_btn ) {
				$buttons[] = $buy_btn;
			}
			if ( get_current_user_id() < 1 && '' !== $buy_btn ) {
				$buttons[] = $this->login_button_html( __( 'قبلاً خریده‌اید؟ ورود', 'music-wave-core' ), false );
			}

			return array(
				'message'      => $message,
				'actions_html' => $this->wrap_buttons( $buttons ),
			);
		}

		if ( 'membership_required' === $reason ) {
			$message = $this->context->message_attribute(
				$attributes,
				'membershipMessage',
				'membership_message',
				__( 'برای دسترسی نامحدود به این انتشار و کیفیت‌های اصلی به اشتراک فعال VIP نیاز است.', 'music-wave-core' )
			);
			$vip_btn = $this->membership_button_html( $attributes, true );
			if ( '' !== $vip_btn ) {
				$buttons[] = $vip_btn;
			}
			if ( get_current_user_id() < 1 && '' !== $vip_btn ) {
				$buttons[] = $this->login_button_html( __( 'مشترک VIP هستید؟ ورود', 'music-wave-core' ), false );
			}

			return array(
				'message'      => $message,
				'actions_html' => $this->wrap_buttons( $buttons ),
			);
		}

		$message = $this->context->message_attribute(
			$attributes,
			'restrictedMessage',
			'restricted_message',
			__( 'این انتشار در حال حاضر در دسترس نیست.', 'music-wave-core' )
		);

		return array(
			'message'      => $message,
			'actions_html' => '',
		);
	}

	/**
	 * Build primary/secondary WooCommerce purchase button HTML for a release.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function purchase_button_html( int $release_id, array $attributes, bool $primary = true ): string {
		$base_label  = $this->context->message_attribute(
			$attributes,
			'purchaseCtaLabel',
			'purchase_cta_label',
			__( 'خرید تکی انتشار', 'music-wave-core' )
		);
		$product_ids = $this->context->repository()->product_ids( $release_id );

		foreach ( $product_ids as $product_id ) {
			if ( 'product' !== get_post_type( $product_id ) ) {
				continue;
			}
			$link = get_permalink( $product_id );
			if ( ! is_string( $link ) || '' === $link ) {
				continue;
			}
			$price_hint = $this->formatted_product_price( $product_id );
			$full_label = '' !== $price_hint ? sprintf( '%s (%s)', $base_label, $price_hint ) : $base_label;
			$variant    = $primary ? 'mw-access-panel__cta--primary' : 'mw-access-panel__cta--secondary';

			return '<a class="wp-element-button mw-access-panel__cta ' . esc_attr( $variant ) . '" href="' . esc_url( $link ) . '">' . esc_html( $full_label ) . '</a>';
		}

		return '';
	}

	/**
	 * Build VIP membership subscription button HTML with automatic URL fallback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function membership_button_html( array $attributes, bool $primary = true ): string {
		$url = $this->resolve_membership_url( $attributes );
		if ( '' === $url ) {
			return '';
		}

		$label   = $this->context->message_attribute(
			$attributes,
			'membershipCtaLabel',
			'membership_cta_label',
			__( 'مشاهده و تهیه اشتراک VIP', 'music-wave-core' )
		);
		$variant = $primary ? 'mw-access-panel__cta--primary' : 'mw-access-panel__cta--secondary';

		return '<a class="wp-element-button mw-access-panel__cta ' . esc_attr( $variant ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Build sign-in link button redirecting cleanly back to the current page.
	 */
	public function login_button_html( string $label, bool $primary = false ): string {
		if ( '' === trim( $label ) ) {
			return '';
		}
		$variant = $primary ? 'mw-access-panel__cta--primary' : 'mw-access-panel__cta--ghost';

		return '<a class="wp-element-button mw-access-panel__cta ' . esc_attr( $variant ) . '" href="' . esc_url( AuthPolicy::login_url( BlockSupport::current_url() ) ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Resolve membership destination URL: explicit block attribute -> Core setting -> VIP filter.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function resolve_membership_url( array $attributes ): string {
		$explicit = $this->context->url_attribute( $attributes, 'membershipCtaUrl' );
		if ( '' !== $explicit ) {
			return $explicit;
		}

		$configured = trim( (string) Settings::get( 'membership_cta_url' ) );
		if ( '' !== $configured ) {
			return $configured;
		}

		$filtered = apply_filters( 'music_wave_membership_cta_url', '' );

		return is_string( $filtered ) ? trim( $filtered ) : '';
	}

	/**
	 * Plain-text formatted WooCommerce price summary without raw HTML tags.
	 */
	private function formatted_product_price( int $product_id ): string {
		$raw_price = get_post_meta( $product_id, '_price', true );
		if ( '' === $raw_price || false === $raw_price || ! is_numeric( $raw_price ) ) {
			$raw_price = get_post_meta( $product_id, '_regular_price', true );
		}
		if ( '' === $raw_price || false === $raw_price || ! is_numeric( $raw_price ) ) {
			return '';
		}
		if ( function_exists( 'wc_price' ) ) {
			return trim( html_entity_decode( wp_strip_all_tags( (string) wc_price( (float) $raw_price ) ), ENT_QUOTES, 'UTF-8' ) );
		}

		return number_format_i18n( (float) $raw_price );
	}

	/**
	 * @param array<int, string> $buttons Rendered CTA anchors.
	 */
	private function wrap_buttons( array $buttons ): string {
		$buttons = array_values( array_filter( $buttons ) );
		if ( empty( $buttons ) ) {
			return '';
		}

		return '<div class="mw-access-panel__actions">' . implode( '', $buttons ) . '</div>';
	}
}
