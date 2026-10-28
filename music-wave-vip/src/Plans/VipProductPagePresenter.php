<?php
/**
 * Single WooCommerce VIP Plan Product Page Presenter.
 *
 * Bridges `musicwave/templates/single-product.html` (which renders hero
 * title/price/summary/button only) with full VIP plan details:
 * 1. Injects tier icon, duration chip, highlight badge, and the current user's
 *    active grant status directly above `woocommerce/product-summary`.
 * 2. Renders the full product description ("توضیحات محصول" / `post_content`)
 *    plus connected `.mw-membership__plan` sibling tier cards right beneath
 *    `.mw-product-hero` so product pages and account plan cards stay 100% unified.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Plans;

use ManaCore\MusicWave\Core\Account\MembershipPresenter;
use ManaCore\MusicWave\Vip\PlanProducts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VipProductPagePresenter {

	public function register(): void {
		add_filter( 'render_block', array( $this, 'enhance_product_blocks' ), 20, 2 );
	}

	/**
	 * Enhance single VIP plan product blocks without duplicating templates.
	 *
	 * @param string               $block_content Rendered HTML.
	 * @param array<string, mixed> $block         Parsed block array.
	 */
	public function enhance_product_blocks( string $block_content, array $block ): string {
		if ( ! function_exists( 'is_singular' ) || ! is_singular( 'product' ) ) {
			return $block_content;
		}

		$block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
		$attrs      = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$class_name = isset( $attrs['className'] ) ? (string) $attrs['className'] : '';

		if ( 'woocommerce/product-summary' !== $block_name && false === strpos( ' ' . $class_name . ' ', ' mw-product-hero ' ) ) {
			return $block_content;
		}

		$product_id = (int) get_queried_object_id();
		if ( $product_id < 1 ) {
			return $block_content;
		}

		$panel_data   = PlanProducts::products_data( (int) get_current_user_id() );
		$plans        = isset( $panel_data['plans'] ) && is_array( $panel_data['plans'] ) ? $panel_data['plans'] : array();
		$current_plan = null;
		$other_plans  = array();

		foreach ( $plans as $plan ) {
			if ( ! is_array( $plan ) ) {
				continue;
			}
			if ( (int) ( $plan['id'] ?? 0 ) === $product_id ) {
				$current_plan = $plan;
			} else {
				$other_plans[] = $plan;
			}
		}

		if ( null === $current_plan ) {
			return $block_content;
		}

		if ( 'woocommerce/product-summary' === $block_name ) {
			return $this->prepend_hero_meta( $block_content, $current_plan );
		}

		return $block_content . $this->render_details_and_siblings_panel( $product_id, $current_plan, $other_plans );
	}

	/**
	 * @param array<string, mixed> $plan Current plan metadata from `PlanProducts::products_data()`.
	 */
	private function prepend_hero_meta( string $summary_html, array $plan ): string {
		$icon     = isset( $plan['icon'] ) ? (string) $plan['icon'] : 'bolt';
		$duration = isset( $plan['duration'] ) ? (string) $plan['duration'] : '';
		$badge    = isset( $plan['badge'] ) ? (string) $plan['badge'] : '';
		$active   = ! empty( $plan['is_active'] );
		$desc     = isset( $plan['description'] ) ? (string) $plan['description'] : '';

		ob_start();
		?>
		<div class="mw-product-vip-meta">
			<span class="mw-membership__plan-icon" aria-hidden="true"><?php echo VipPlanCatalog::icon_svg( $icon, 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<?php if ( '' !== $duration ) : ?>
				<span class="mw-membership__plan-chip"><?php echo esc_html( $duration ); ?></span>
			<?php endif; ?>
			<?php if ( $active ) : ?>
				<span class="mw-membership__plan-badge mw-membership__plan-badge--active"><?php echo esc_html__( 'فعال روی حساب شما', 'music-wave-vip' ); ?></span>
			<?php elseif ( '' !== $badge ) : ?>
				<span class="mw-membership__plan-badge"><?php echo esc_html( $badge ); ?></span>
			<?php endif; ?>
		</div>
		<?php if ( '' === trim( wp_strip_all_tags( $summary_html ) ) && '' !== $desc ) : ?>
			<p class="mw-product-vip-lead"><?php echo esc_html( $desc ); ?></p>
		<?php endif; ?>
		<?php
		return trim( (string) ob_get_clean() ) . $summary_html;
	}

	/**
	 * @param array<string, mixed>             $current_plan Current VIP plan product.
	 * @param array<int, array<string, mixed>> $other_plans  Sibling VIP tier plans.
	 */
	private function render_details_and_siblings_panel( int $product_id, array $current_plan, array $other_plans ): string {
		$raw_content = VipPlanCatalog::post_field( 'post_content', $product_id );
		$prose_html  = '';
		if ( '' !== trim( $raw_content ) && ! VipPlanCatalog::is_legacy_or_empty_content( $raw_content ) ) {
			$prose_html = wp_kses_post( wpautop( $raw_content ) );
		} else {
			$features = isset( $current_plan['features'] ) && is_array( $current_plan['features'] ) ? $current_plan['features'] : array();
			$desc     = isset( $current_plan['description'] ) ? (string) $current_plan['description'] : '';
			$prose    = array();
			if ( '' !== $desc ) {
				$prose[] = '<p>' . esc_html( $desc ) . '</p>';
			}
			if ( ! empty( $features ) ) {
				$prose[] = '<ul>';
				foreach ( $features as $f ) {
					$prose[] = '<li>' . esc_html( (string) $f ) . '</li>';
				}
				$prose[] = '</ul>';
			}
			$prose_html = implode( "\n", $prose );
		}

		if ( '' === $prose_html && empty( $other_plans ) ) {
			return '';
		}

		ob_start();
		?>
		<section class="mw-product-details-panel mw-surface-card alignwide">
			<?php if ( '' !== $prose_html ) : ?>
				<div class="mw-product-details-panel__prose">
					<h2 class="mw-product-details-panel__heading"><?php echo esc_html__( 'توضیحات محصول و قابلیت‌های اشتراک', 'music-wave-vip' ); ?></h2>
					<?php echo $prose_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized via wp_kses_post / esc_html above. ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $other_plans ) && class_exists( MembershipPresenter::class ) ) : ?>
				<div class="mw-product-details-panel__plans">
					<h2 class="mw-product-details-panel__heading"><?php echo esc_html__( 'مقایسه سایر طرح‌های اشتراک ویژه', 'music-wave-vip' ); ?></h2>
					<ul class="mw-membership__plans">
						<?php foreach ( $other_plans as $sibling ) : ?>
							<?php echo MembershipPresenter::render_plan_card( $sibling, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
