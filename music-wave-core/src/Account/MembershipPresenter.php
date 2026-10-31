<?php
/**
 * Account VIP Membership Presenter.
 *
 * Bridges `music-wave-vip` (`music_wave_vip_membership_panel` & `AccessPolicyEngine`)
 * with every customer account surface (`dashboard-welcome`, `dashboard-subscription`,
 * `membership-panel`, `account-dashboard`, and single plan product pages) using
 * canonical `.mw-membership*` components and design tokens.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Account;

use ManaCore\MusicWave\Core\Access\AccessPolicyEngine;
use ManaCore\MusicWave\Core\Support\VipModuleStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MembershipPresenter {
	/** @var AccessPolicyEngine */
	private $policy;

	/** @var array<int, array<string, mixed>> Per-request memoized context by user ID. */
	private $cache = array();

	public function __construct( AccessPolicyEngine $policy ) {
		$this->policy = $policy;
	}

	/**
	 * Resolve normalized subscription context once per user per request (DRY).
	 *
	 * @return array{
	 *   module_enabled: bool,
	 *   is_vip: bool,
	 *   plans: array<int, array<string, mixed>>,
	 *   active: array<int, array<string, mixed>>,
	 *   reserved: array<int, array<string, mixed>>,
	 *   primary: array<string, mixed>|null,
	 *   ends_at: int,
	 *   ends_label: string,
	 *   has_lifetime: bool,
	 *   renewal: array<string, mixed>|null,
	 *   renewal_url: string
	 * }
	 */
	public function context( int $user_id ): array {
		if ( isset( $this->cache[ $user_id ] ) ) {
			return $this->cache[ $user_id ];
		}

		$raw = apply_filters(
			'music_wave_vip_membership_panel',
			array(
				'module_enabled'  => false,
				'active'          => array(),
				'detailed_active' => array(),
				'renewal_needed'  => null,
				'plans'           => array(),
			),
			$user_id
		);
		$raw = is_array( $raw ) ? $raw : array();
		$raw = apply_filters( 'music_wave_account_membership_data', $raw, $user_id );
		$raw = is_array( $raw ) ? $raw : array();

		$raw_plans            = isset( $raw['plans'] ) && is_array( $raw['plans'] ) ? array_values( $raw['plans'] ) : array();
		$plan_titles_by_level = array();
		$plans                = array();

		foreach ( $raw_plans as $plan ) {
			if ( ! is_array( $plan ) ) {
				continue;
			}
			$product_id    = isset( $plan['id'] ) ? (int) $plan['id'] : 0;
			$level         = isset( $plan['level'] ) ? (string) $plan['level'] : (string) ( $plan['plan_id'] ?? '' );
			$duration_days = isset( $plan['duration_days'] ) ? (int) $plan['duration_days'] : 0;
			$title         = isset( $plan['title'] ) && '' !== trim( (string) $plan['title'] )
				? trim( (string) $plan['title'] )
				: trim( (string) ( $plan['name'] ?? $level ) );
			if ( '' !== $level && '' !== $title && ! isset( $plan_titles_by_level[ $level ] ) ) {
				$plan_titles_by_level[ $level ] = $title;
			}

			$description = isset( $plan['description'] ) ? trim( (string) $plan['description'] ) : '';
			$features    = array();
			if ( isset( $plan['features'] ) && is_array( $plan['features'] ) ) {
				foreach ( $plan['features'] as $feat ) {
					if ( is_scalar( $feat ) && '' !== trim( (string) $feat ) ) {
						$features[] = trim( (string) $feat );
					}
				}
			}
			if ( '' === $description && empty( $features ) && $product_id > 0 && function_exists( 'get_post_field' ) ) {
				$fallback_copy = self::extract_fallback_product_copy( $product_id );
				$description   = $fallback_copy['description'];
				$features      = $fallback_copy['features'];
			}

			$plans[] = array(
				'id'             => $product_id,
				'level'          => $level,
				'title'          => $title,
				'price'          => isset( $plan['price'] ) ? (string) $plan['price'] : (string) ( $plan['price_html'] ?? '' ),
				'saving_percent' => isset( $plan['saving_percent'] ) ? max( 0, (int) $plan['saving_percent'] ) : 0,
				'duration'       => isset( $plan['duration'] ) ? (string) $plan['duration'] : '',
				'duration_days'  => $duration_days,
				'icon'           => isset( $plan['icon'] ) && is_string( $plan['icon'] ) && '' !== $plan['icon']
					? $plan['icon']
					: self::default_plan_icon( $level, $duration_days ),
				'badge'          => isset( $plan['badge'] ) ? trim( (string) $plan['badge'] ) : '',
				'description'    => $description,
				'features'       => $features,
				'url'            => isset( $plan['url'] ) ? (string) $plan['url'] : '',
				'cart_url'       => isset( $plan['cart_url'] ) && '' !== (string) $plan['cart_url']
					? (string) $plan['cart_url']
					: (string) ( $plan['url'] ?? '' ),
				'is_active'      => ! empty( $plan['is_active'] ),
				'is_lifetime'    => ! empty( $plan['is_lifetime'] ),
				'remaining_days' => isset( $plan['remaining_days'] ) && is_numeric( $plan['remaining_days'] )
					? max( 0, (int) $plan['remaining_days'] )
					: null,
				'expires_label'  => isset( $plan['expires_label'] ) ? (string) $plan['expires_label'] : '',
			);
		}

		$source_grants = ! empty( $raw['detailed_active'] ) && is_array( $raw['detailed_active'] )
			? array_values( $raw['detailed_active'] )
			: ( isset( $raw['active'] ) && is_array( $raw['active'] ) ? array_values( $raw['active'] ) : array() );

		$active            = array();
		$reserved          = array();
		$active_by_levels  = array();
		$reserved_by_level = array();
		$ends_at           = 0;
		$has_lifetime      = false;
		foreach ( $source_grants as $grant ) {
			if ( ! is_array( $grant ) ) {
				continue;
			}
			$normalized = $this->normalize_grant( $grant, $plan_titles_by_level );
			$level      = (string) $normalized['level'];
			if ( ! empty( $normalized['is_reserved'] ) ) {
				$reserved[] = $normalized;
			} else {
				$active[] = $normalized;
			}
			if ( '' !== $level ) {
				if ( ! empty( $normalized['is_reserved'] ) ) {
					if ( ! isset( $reserved_by_level[ $level ] ) || (int) $normalized['expires_at'] > (int) $reserved_by_level[ $level ]['expires_at'] ) {
						$reserved_by_level[ $level ] = $normalized;
					}
				} elseif ( ! isset( $active_by_levels[ $level ] ) || (int) $normalized['expires_at'] > (int) $active_by_levels[ $level ]['expires_at'] ) {
					$active_by_levels[ $level ] = $normalized;
				}
			}
			$exp = (int) $normalized['expires_at'];
			if ( 0 === $exp ) {
				if ( ! empty( $normalized['is_lifetime'] ) ) {
					$has_lifetime = true;
				}
			} elseif ( $exp > $ends_at ) {
				$ends_at = $exp;
			}
		}
		if ( $has_lifetime ) {
			$ends_at = 0;
		}

		foreach ( $plans as $idx => $plan ) {
			$plan_level = (string) $plan['level'];
			if ( '' !== $plan_level && isset( $active_by_levels[ $plan_level ] ) ) {
				$matched                        = $active_by_levels[ $plan_level ];
				$plans[ $idx ]['is_active']     = true;
				$plans[ $idx ]['is_lifetime']   = ! empty( $matched['is_lifetime'] );
				$plans[ $idx ]['expires_label'] = (string) $matched['expires_label'];
				if ( null !== $matched['remaining_days'] ) {
					$plans[ $idx ]['remaining_days'] = (int) $matched['remaining_days'];
				}
			} elseif ( '' !== $plan_level && isset( $reserved_by_level[ $plan_level ] ) ) {
				$queued                         = $reserved_by_level[ $plan_level ];
				$plans[ $idx ]['is_reserved']   = true;
				$plans[ $idx ]['starts_label']  = (string) $queued['starts_label'];
				$plans[ $idx ]['reserved_days'] = (int) $queued['reserved_days'];
				$plans[ $idx ]['expires_label'] = (string) $queued['expires_label'];
			}
		}

		$primary          = isset( $active[0] ) ? $active[0] : null;
		$renewal          = isset( $raw['renewal_needed'] ) && is_array( $raw['renewal_needed'] )
			? $this->normalize_grant( $raw['renewal_needed'], $plan_titles_by_level )
			: null;
		$filtered_renewal = apply_filters( 'music_wave_account_renewal_grant', $renewal, $user_id );
		$renewal          = is_array( $filtered_renewal ) ? $this->normalize_grant( $filtered_renewal, $plan_titles_by_level ) : null;

		$is_vip = ! empty( $active )
			|| ( $user_id > 0 && $this->policy->has_active_membership( $user_id ) );

		$renewal_url = '';
		if ( null !== $renewal ) {
			$renewal_url = $this->resolve_plan_url( $plans, $renewal['level'], $renewal['title'] );
		}
		if ( '' === $renewal_url && null !== $primary ) {
			$renewal_url = $this->resolve_plan_url( $plans, $primary['level'], $primary['title'] );
		}
		if ( '' === $renewal_url ) {
			$renewal_url = $this->resolve_plan_url( $plans );
		}

		if ( $has_lifetime ) {
			$ends_label = __( 'مادام‌العمر', 'music-wave-core' );
		} elseif ( $ends_at > 0 && function_exists( 'date_i18n' ) ) {
			$ends_label = (string) date_i18n( (string) get_option( 'date_format' ), $ends_at );
		} else {
			$ends_label = '';
		}

		$this->cache[ $user_id ] = array(
			'module_enabled' => ! empty( $raw['module_enabled'] ) || VipModuleStatus::is_active(),
			'is_vip'         => $is_vip,
			'plans'          => $plans,
			'active'         => $active,
			'reserved'       => $reserved,
			'primary'        => $primary,
			'ends_at'        => $ends_at,
			'ends_label'     => $ends_label,
			'has_lifetime'   => $has_lifetime,
			'renewal'        => $renewal,
			'renewal_url'    => $renewal_url,
		);

		return $this->cache[ $user_id ];
	}

	/**
	 * Resolve checkout/cart URL for a matching plan, falling back to first available plan URL.
	 *
	 * @param array<int, array<string, mixed>> $plans Target plan cards.
	 */
	public function resolve_plan_url( array $plans, string $level = '', string $title = '' ): string {
		$fallback = '';
		foreach ( $plans as $plan ) {
			if ( ! is_array( $plan ) ) {
				continue;
			}
			$url = ! empty( $plan['cart_url'] ) ? (string) $plan['cart_url'] : (string) ( $plan['url'] ?? '' );
			if ( '' === $url ) {
				continue;
			}
			if ( '' === $fallback ) {
				$fallback = $url;
			}
			if ( '' !== $level && isset( $plan['level'] ) && (string) $plan['level'] === $level ) {
				return $url;
			}
			if ( '' !== $title && isset( $plan['title'] ) && (string) $plan['title'] === $title ) {
				return $url;
			}
		}

		return $fallback;
	}

	/**
	 * Render compact header welcome section matching `.mw-user-dashboard__welcome*` in `account-library.css`.
	 *
	 * @param array<string, mixed>      $context       Resolved subscription context.
	 * @param array<string, mixed>      $options       Display options.
	 * @param array<int, array<string>> $quick_links   Optional action links.
	 * @param string                    $wrapper_attrs Optional Gutenberg block wrapper attributes.
	 */
	public function render_welcome_header( array $context, array $options, array $quick_links = array(), string $wrapper_attrs = '' ): string {
		$user         = wp_get_current_user();
		$display_name = is_object( $user ) && property_exists( $user, 'display_name' ) ? (string) $user->display_name : '';
		$user_login   = is_object( $user ) && property_exists( $user, 'user_login' ) ? (string) $user->user_login : '';
		$avatar_uid   = is_object( $user ) && property_exists( $user, 'ID' ) ? (int) $user->ID : 0;
		$name         = '' !== trim( $display_name ) ? $display_name : ( '' !== trim( $user_login ) ? $user_login : __( 'کاربر', 'music-wave-core' ) );
		$intro_text   = isset( $options['intro_text'] ) ? trim( (string) $options['intro_text'] ) : '';
		$show_avatar  = ! isset( $options['show_avatar'] ) || ! empty( $options['show_avatar'] );
		$avatar_size  = isset( $options['avatar_size'] ) ? max( 32, min( 96, (int) $options['avatar_size'] ) ) : 56;
		$is_vip       = ! empty( $context['is_vip'] );
		$show_badge   = ( ! isset( $options['show_vip_badge'] ) || ! empty( $options['show_vip_badge'] ) ) && $is_vip;
		$show_meta    = ! isset( $options['show_sub_meta'] ) || ! empty( $options['show_sub_meta'] );
		$show_renewal = ! empty( $options['show_renewal'] );
		$state_class  = $is_vip ? 'mw-user-dashboard__welcome--vip' : 'mw-user-dashboard__welcome--standard';

		$open_header = '' !== $wrapper_attrs
			? '<header ' . $wrapper_attrs . '>'
			: '<header class="mw-user-dashboard__welcome ' . esc_attr( $state_class ) . '">';

		ob_start();
		echo $open_header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Wrapper escaped via BlockSupport or esc_attr above.
		?>
			<?php if ( $show_avatar ) : ?>
				<div class="mw-user-dashboard__avatar-wrap">
					<?php echo get_avatar( $avatar_uid, $avatar_size, '', '', array( 'class' => 'mw-user-dashboard__avatar' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress helper escapes attributes. ?>
					<?php if ( $show_badge ) : ?>
						<span class="mw-user-dashboard__vip-badge" aria-label="<?php esc_attr_e( 'عضو ویژه فعال', 'music-wave-core' ); ?>"><?php echo esc_html__( 'VIP', 'music-wave-core' ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="mw-user-dashboard__welcome-main">
				<div>
					<span class="mw-user-dashboard__eyebrow"><?php echo $is_vip ? esc_html__( 'حساب ویژه', 'music-wave-core' ) : esc_html__( 'حساب کاربری', 'music-wave-core' ); ?></span>
					<h2>
						<?php
						printf(
							/* translators: %s: user display name. */
							esc_html__( 'سلام، %s', 'music-wave-core' ),
							esc_html( $name )
						);
						?>
					</h2>
					<?php if ( '' !== $intro_text ) : ?>
						<p><?php echo esc_html( $intro_text ); ?></p>
					<?php endif; ?>
				</div>

				<?php if ( $show_meta ) : ?>
					<?php echo $this->subscription_meta_markup( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside subscription_meta_markup(). ?>
				<?php endif; ?>

				<?php if ( $show_renewal ) : ?>
					<?php echo $this->render_renewal_callout( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside render_renewal_callout(). ?>
				<?php endif; ?>

				<?php if ( ! empty( $quick_links ) ) : ?>
					<p class="mw-user-dashboard__welcome-actions">
						<?php foreach ( $quick_links as $index => $item ) : ?>
							<?php if ( $index > 0 ) : ?>
								<span aria-hidden="true"> · </span>
							<?php endif; ?>
							<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
			</div>
		</header>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render compact renewal banner matching `.mw-user-dashboard__renewal` in `account-library.css`.
	 *
	 * @param array<string, mixed> $context Resolved subscription context.
	 */
	public function render_renewal_callout( array $context, string $fallback_url = '#mw-membership' ): string {
		$renewal = isset( $context['renewal'] ) && is_array( $context['renewal'] ) ? $context['renewal'] : null;
		if ( null === $renewal ) {
			return '';
		}
		$cta_url = ! empty( $context['renewal_url'] ) ? (string) $context['renewal_url'] : $fallback_url;

		ob_start();
		?>
		<div class="mw-user-dashboard__renewal" role="status">
			<a class="wp-element-button mw-user-dashboard__renewal-button" href="<?php echo esc_url( $cta_url ); ?>">
				<?php echo esc_html__( 'تمدید اشتراک', 'music-wave-core' ); ?>
			</a>
			<?php if ( '' !== $renewal['expires_label'] ) : ?>
				<small>
					<?php
					printf(
						/* translators: %s: expiry date. */
						esc_html__( 'پایان دوره فعلی: %s', 'music-wave-core' ),
						esc_html( $renewal['expires_label'] )
					);
					?>
				</small>
			<?php endif; ?>
		</div>
		<?php
		return trim( (string) ob_get_clean() );
	}

	/**
	 * Render full VIP membership panel content (`account-library.css` `.mw-membership*`).
	 *
	 * @param array<string, mixed> $attributes Optional block attributes.
	 */
	public function render_panel( int $user_id, array $attributes = array() ): string {
		$context          = $this->context( $user_id );
		$plans            = $context['plans'];
		$active           = $context['active'];
		$reserved_list    = isset( $context['reserved'] ) && is_array( $context['reserved'] ) ? $context['reserved'] : array();
		$ends_label       = isset( $context['ends_label'] ) ? (string) $context['ends_label'] : '';
		$has_member       = $context['is_vip'];
		$show_active      = ! isset( $attributes['showActive'] ) || ! empty( $attributes['showActive'] );
		$show_plans       = ! isset( $attributes['showPlans'] ) || ! empty( $attributes['showPlans'] );
		$show_buy_buttons = ! isset( $attributes['showBuyButtons'] ) || ! empty( $attributes['showBuyButtons'] );
		$heading          = isset( $attributes['heading'] ) && is_scalar( $attributes['heading'] ) ? trim( (string) $attributes['heading'] ) : '';
		$intro            = isset( $attributes['intro'] ) && is_scalar( $attributes['intro'] ) ? trim( (string) $attributes['intro'] ) : '';
		$empty_text       = isset( $attributes['emptyText'] ) && is_scalar( $attributes['emptyText'] ) && '' !== trim( (string) $attributes['emptyText'] )
			? trim( (string) $attributes['emptyText'] )
			: __( 'در حال حاضر اشتراک ویژه فعالی ندارید.', 'music-wave-core' );

		ob_start();
		?>
		<div class="mw-membership">
			<?php if ( '' !== $heading ) : ?>
				<h3 class="mw-dashboard-subscription__heading"><?php echo esc_html( $heading ); ?></h3>
			<?php endif; ?>
			<?php if ( '' !== $intro ) : ?>
				<p class="mw-account-library__intro"><?php echo esc_html( $intro ); ?></p>
			<?php endif; ?>

			<?php if ( $show_active && ! empty( $active ) ) : ?>
				<ul class="mw-membership__levels" aria-label="<?php esc_attr_e( 'اشتراک‌های فعال شما', 'music-wave-core' ); ?>">
					<?php foreach ( $active as $grant ) : ?>
						<?php echo $this->render_grant_item( $grant, $plans, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside render_grant_item(). ?>
					<?php endforeach; ?>
				</ul>
			<?php elseif ( $show_active && $has_member ) : ?>
				<p class="mw-membership__notice"><?php echo esc_html__( 'اشتراک ویژه شما فعال است و به تمام آثار ویژه دسترسی کامل دارید.', 'music-wave-core' ); ?></p>
			<?php elseif ( $show_active ) : ?>
				<p class="mw-membership__empty"><?php echo esc_html( $empty_text ); ?></p>
			<?php endif; ?>

			<?php if ( $show_active && ( ! empty( $active ) || ! empty( $reserved_list ) ) && '' !== $ends_label ) : ?>
				<p class="mw-membership__summary">
					<?php
					printf(
						/* translators: %s: total membership end date (wrapped in <strong>). */
						esc_html__( 'اعتبار کل عضویت شما تا %s', 'music-wave-core' ),
						'<strong>' . esc_html( $ends_label ) . '</strong>'
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( $show_active && ! empty( $reserved_list ) ) : ?>
				<ul class="mw-membership__levels mw-membership__levels--reserved" aria-label="<?php esc_attr_e( 'پلن‌های رزرو‌شده', 'music-wave-core' ); ?>">
					<?php foreach ( $reserved_list as $grant ) : ?>
						<?php echo $this->render_grant_item( $grant, $plans, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside render_grant_item(). ?>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php echo $this->render_renewal_callout( $context, '#mw-membership-plans' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<?php if ( $show_plans && ! empty( $plans ) ) : ?>
				<ul id="mw-membership-plans" class="mw-membership__plans" aria-label="<?php esc_attr_e( 'پلن‌های اشتراک', 'music-wave-core' ); ?>">
					<?php foreach ( $plans as $plan ) : ?>
						<?php echo self::render_plan_card( $plan, $show_buy_buttons ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside render_plan_card(). ?>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php
			$cta_url = apply_filters( 'music_wave_dashboard_membership_cta_url', '', $user_id );
			if ( is_string( $cta_url ) && '' !== $cta_url ) :
				?>
				<a class="wp-element-button" href="<?php echo esc_url( $cta_url ); ?>"><?php echo esc_html__( 'مدیریت عضویت', 'music-wave-core' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
		return trim( (string) ob_get_clean() );
	}

	/**
	 * Single canonical `<li class="mw-membership__plan">` card renderer shared by
	 * `MembershipPresenter` and `VipProductPagePresenter` (SOLID DRY).
	 *
	 * @param array<string, mixed> $plan            Normalized plan array.
	 * @param bool                 $show_buy_button Whether to display the primary action button.
	 */
	public static function render_plan_card( array $plan, bool $show_buy_button = true ): string {
		$title          = isset( $plan['title'] ) ? trim( (string) $plan['title'] ) : '';
		$duration       = isset( $plan['duration'] ) ? trim( (string) $plan['duration'] ) : '';
		$duration_days  = isset( $plan['duration_days'] ) ? (int) $plan['duration_days'] : 0;
		$price          = isset( $plan['price'] ) ? trim( (string) $plan['price'] ) : '';
		$saving_percent = isset( $plan['saving_percent'] ) ? max( 0, (int) $plan['saving_percent'] ) : 0;
		$icon_key       = isset( $plan['icon'] ) && is_string( $plan['icon'] ) && '' !== $plan['icon']
			? $plan['icon']
			: self::default_plan_icon( (string) ( $plan['level'] ?? '' ), $duration_days );
		$badge          = isset( $plan['badge'] ) ? trim( (string) $plan['badge'] ) : '';
		$description    = isset( $plan['description'] ) ? trim( (string) $plan['description'] ) : '';
		$features       = isset( $plan['features'] ) && is_array( $plan['features'] ) ? $plan['features'] : array();
		$product_url    = isset( $plan['url'] ) ? trim( (string) $plan['url'] ) : '';
		$cart_url       = isset( $plan['cart_url'] ) && '' !== trim( (string) $plan['cart_url'] )
			? trim( (string) $plan['cart_url'] )
			: $product_url;
		$is_active      = ! empty( $plan['is_active'] );
		$is_lifetime    = ! empty( $plan['is_lifetime'] );
		$remaining_days = isset( $plan['remaining_days'] ) && is_numeric( $plan['remaining_days'] )
			? max( 0, (int) $plan['remaining_days'] )
			: null;
		$expires_label  = isset( $plan['expires_label'] ) ? trim( (string) $plan['expires_label'] ) : '';

		$card_classes = array( 'mw-membership__plan' );
		if ( $is_active ) {
			$card_classes[] = 'mw-membership__plan--active';
		} elseif ( '' !== $badge && $duration_days >= 180 ) {
			$card_classes[] = 'mw-membership__plan--featured';
		}

		ob_start();
		?>
		<li class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>">
			<div class="mw-membership__plan-details">
				<div class="mw-membership__plan-head">
					<span class="mw-membership__plan-icon" aria-hidden="true"><?php echo self::plan_icon_svg( $icon_key, 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG markup. ?></span>
					<div class="mw-membership__plan-title-wrap">
						<strong>
							<?php if ( '' !== $product_url ) : ?>
								<a class="mw-membership__plan-title-link" href="<?php echo esc_url( $product_url ); ?>"><?php echo esc_html( $title ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $title ); ?>
							<?php endif; ?>
						</strong>
						<div class="mw-membership__plan-tags">
							<?php if ( '' !== $duration ) : ?>
								<span class="mw-membership__plan-duration"><?php echo esc_html( $duration ); ?></span>
							<?php endif; ?>
							<?php if ( ! $is_active && ! empty( $plan['is_reserved'] ) ) : ?>
								<span class="mw-membership__plan-badge mw-membership__plan-badge--reserved"><?php echo esc_html__( 'رزرو', 'music-wave-core' ); ?></span>
							<?php elseif ( $is_active ) : ?>
								<span class="mw-membership__plan-badge mw-membership__plan-badge--active"><?php echo esc_html__( 'فعال برای شما', 'music-wave-core' ); ?></span>
							<?php elseif ( '' !== $badge ) : ?>
								<span class="mw-membership__plan-badge"><?php echo esc_html( $badge ); ?></span>
							<?php endif; ?>
							<?php if ( $saving_percent > 0 ) : ?>
								<span class="mw-membership__plan-badge mw-membership__plan-badge--sale">
									<?php
									printf(
										/* translators: %d: percentage discount. */
										esc_html__( '%d%% تخفیف', 'music-wave-core' ),
										(int) $saving_percent
									);
									?>
								</span>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<?php if ( '' !== $price ) : ?>
					<div class="mw-membership__plan-pricing">
						<span class="mw-membership__plan-price"><?php echo wp_kses_post( $price ); ?></span>
					</div>
				<?php endif; ?>

				<?php if ( '' !== $description ) : ?>
					<p class="mw-membership__plan-desc"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $features ) ) : ?>
					<ul class="mw-membership__plan-features" aria-label="<?php esc_attr_e( 'قابلیت‌های این طرح', 'music-wave-core' ); ?>">
						<?php foreach ( $features as $feature ) : ?>
							<li>
								<span class="mw-membership__plan-check" aria-hidden="true"><?php echo self::plan_icon_svg( 'check', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<span><?php echo esc_html( (string) $feature ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( $is_active && ( $is_lifetime || null !== $remaining_days || '' !== $expires_label ) ) : ?>
					<p class="mw-membership__plan-status">
						<?php
						if ( $is_lifetime ) {
							echo esc_html__( 'دسترسی دائمی فعال روی حساب شما', 'music-wave-core' );
						} elseif ( null !== $remaining_days && '' !== $expires_label ) {
							printf(
								/* translators: 1: remaining days, 2: expiry date label. */
								esc_html__( '%1$d روز باقی‌مانده (تا %2$s) — خرید مجدد به انتهای اعتبار اضافه می‌شود.', 'music-wave-core' ),
								(int) $remaining_days,
								esc_html( $expires_label )
							);
						} elseif ( '' !== $expires_label ) {
							printf(
								/* translators: %s: expiry date label. */
								esc_html__( 'اعتبار فعلی تا %s', 'music-wave-core' ),
								esc_html( $expires_label )
							);
						}
						?>
					</p>
<?php endif; ?>
					<?php
					$reserved_starts = isset( $plan['starts_label'] ) ? trim( (string) $plan['starts_label'] ) : '';
					if ( ! $is_active && '' !== $reserved_starts ) :
						?>
						<p class="mw-membership__plan-status mw-membership__plan-status--reserved">
							<?php
							printf(
								/* translators: %s: activation date of the reserved plan. */
								esc_html__( 'رزرو — فعال‌سازی از %s', 'music-wave-core' ),
								esc_html( $reserved_starts )
							);
							?>
						</p>
					<?php endif; ?>
				</div>

				<?php if ( ( $show_buy_button && '' !== $cart_url ) || '' !== $product_url ) : ?>
				<div class="mw-membership__plan-footer">
					<?php if ( $show_buy_button && '' !== $cart_url ) : ?>
						<a class="wp-element-button mw-membership__buy" href="<?php echo esc_url( $cart_url ); ?>">
							<?php echo $is_active ? esc_html__( 'تمدید اشتراک', 'music-wave-core' ) : esc_html__( 'خرید و فعال‌سازی', 'music-wave-core' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( '' !== $product_url ) : ?>
						<a class="mw-membership__plan-link" href="<?php echo esc_url( $product_url ); ?>">
							<?php echo esc_html__( 'جزئیات محصول', 'music-wave-core' ); ?>
						</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</li>
		<?php
		return trim( (string) ob_get_clean() );
	}

	/**
	 * Render granular subscription status card (`music-wave/dashboard-subscription`).
	 *
	 * @param array<string, mixed> $attributes    Block attributes.
	 * @param string               $wrapper_attrs Escaped block wrapper attributes.
	 */
	public function render_subscription_card( int $user_id, array $attributes, string $wrapper_attrs ): string {
		$heading         = isset( $attributes['heading'] ) && is_scalar( $attributes['heading'] ) ? trim( (string) $attributes['heading'] ) : '';
		$show_heading    = ! isset( $attributes['showHeading'] ) || ! empty( $attributes['showHeading'] );
		$show_active     = ! isset( $attributes['showActive'] ) || ! empty( $attributes['showActive'] );
		$show_progress   = ! isset( $attributes['showProgress'] ) || ! empty( $attributes['showProgress'] );
		$show_order_link = ! empty( $attributes['showOrderLink'] );
		$show_renewal    = ! isset( $attributes['showRenewal'] ) || ! empty( $attributes['showRenewal'] );
		$heading_text    = '' !== $heading ? $heading : __( 'اشتراک ویژه', 'music-wave-core' );

		$context = $this->context( $user_id );
		$plans   = $context['plans'];
		$active  = $context['active'];
		$is_vip  = $context['is_vip'];
		$cta_url = $context['renewal_url'];

		ob_start();
		?>
		<section <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php if ( $show_heading && '' !== $heading_text ) : ?>
				<h3 class="mw-dashboard-subscription__heading"><?php echo esc_html( $heading_text ); ?></h3>
			<?php endif; ?>

			<?php if ( $show_active && ! empty( $active ) ) : ?>
				<ul class="mw-membership__levels">
					<?php foreach ( $active as $grant ) : ?>
						<?php echo $this->render_grant_item( $grant, $plans, $show_progress, $show_order_link ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endforeach; ?>
				</ul>
			<?php elseif ( $is_vip ) : ?>
				<p class="mw-membership__notice"><?php echo esc_html__( 'اشتراک ویژه شما فعال است.', 'music-wave-core' ); ?></p>
			<?php else : ?>
				<p class="mw-membership__empty"><?php echo esc_html__( 'در حال حاضر اشتراک ویژه فعالی ندارید.', 'music-wave-core' ); ?></p>
				<?php if ( $show_renewal && '' !== $cta_url ) : ?>
					<a class="wp-element-button mw-membership__buy" href="<?php echo esc_url( $cta_url ); ?>">
						<?php echo esc_html__( 'خرید اشتراک ویژه', 'music-wave-core' ); ?>
					</a>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $show_renewal ) : ?>
				<?php
				$callout = $this->render_renewal_callout( $context, '#mw-membership' );
				if ( '' !== $callout ) {
					echo '<div class="mw-dashboard-subscription__renewal">' . $callout . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			<?php endif; ?>
		</section>
		<?php
		return trim( (string) ob_get_clean() );
	}

	/**
	 * Render guest sign-in prompt card.
	 */
	public function render_guest_card( string $wrapper_attrs, string $redirect_url ): string {
		return '<div ' . $wrapper_attrs . '>'
			. '<div class="mw-user-dashboard__welcome">'
			. '<div class="mw-user-dashboard__welcome-main">'
			. '<span class="mw-user-dashboard__eyebrow">' . esc_html__( 'حساب کاربری', 'music-wave-core' ) . '</span>'
			. '<h2>' . esc_html__( 'به حساب کاربری خود وارد شوید', 'music-wave-core' ) . '</h2>'
			. '<p>' . esc_html__( 'برای دسترسی به کتابخانه موسیقی، اشتراک ویژه، سفارش‌ها و پیگیری درخواست‌ها وارد شوید.', 'music-wave-core' ) . '</p>'
			. '<div><a class="wp-element-button mw-membership__buy" href="' . esc_url( AuthPolicy::login_url( $redirect_url ) ) . '">' . esc_html__( 'ورود به حساب', 'music-wave-core' ) . '</a></div>'
			. '</div></div></div>';
	}

	/**
	 * Render single active subscription row (`<li class="mw-membership__level mw-membership__level--active">`).
	 *
	 * @param array<string, mixed>             $grant           Normalized grant.
	 * @param array<int, array<string, mixed>> $plans           Available plans.
	 */
	private function render_grant_item( array $grant, array $plans, bool $show_progress = true, bool $show_order_link = false ): string {
		$item_classes = array( 'mw-membership__level', 'mw-membership__level--active' );
		$is_reserved  = ! empty( $grant['is_reserved'] );
		if ( ! empty( $grant['is_lifetime'] ) ) {
			$item_classes[] = 'mw-membership__level--lifetime';
		}
		if ( $is_reserved ) {
			$item_classes[] = 'mw-membership__level--reserved';
		} elseif ( ! empty( $grant['is_stacked'] ) ) {
			$item_classes[] = 'mw-membership__level--stacked';
		}
		$renew_url = empty( $grant['is_lifetime'] ) ? $this->resolve_plan_url( $plans, $grant['level'], $grant['title'] ) : '';

		ob_start();
		?>
		<li class="<?php echo esc_attr( implode( ' ', $item_classes ) ); ?>">
			<div class="mw-membership__level-main">
				<span class="mw-membership__level-name"><?php echo esc_html( $grant['title'] ); ?></span>
				<?php if ( $is_reserved ) : ?>
					<span class="mw-membership__level-stacked-badge mw-membership__level-stacked-badge--reserved"><?php echo esc_html__( 'رزرو', 'music-wave-core' ); ?></span>
				<?php elseif ( ! empty( $grant['is_stacked'] ) ) : ?>
					<span class="mw-membership__level-stacked-badge"><?php echo esc_html__( 'تمدیدشده', 'music-wave-core' ); ?></span>
				<?php endif; ?>
				<?php if ( $is_reserved ) : ?>
					<span class="mw-membership__level-expiry mw-membership__level-expiry--reserved">
						<?php
						printf(
							/* translators: %s: activation date of the reserved plan. */
							esc_html__( 'فعال‌سازی از %s', 'music-wave-core' ),
							esc_html( (string) $grant['starts_label'] )
						);
						?>
					</span>
				<?php elseif ( ! empty( $grant['is_lifetime'] ) ) : ?>
					<span class="mw-membership__level-expiry mw-membership__level-expiry--lifetime"><?php echo esc_html__( 'مادام‌العمر', 'music-wave-core' ); ?></span>
				<?php elseif ( '' !== $grant['expires_label'] ) : ?>
					<span class="mw-membership__level-expiry">
						<?php
						printf(
							/* translators: %s: formatted expiry date. */
							esc_html__( 'اعتبار تا %s', 'music-wave-core' ),
							esc_html( $grant['expires_label'] )
						);
						?>
					</span>
				<?php endif; ?>
				<?php if ( $is_reserved ) : ?>
					<span class="mw-membership__level-remaining">
						<?php
						printf(
							/* translators: %d: days until the reserved plan activates. */
							esc_html__( '%d روز تا فعال‌سازی', 'music-wave-core' ),
							(int) $grant['reserved_days']
						);
						?>
					</span>
				<?php elseif ( empty( $grant['is_lifetime'] ) && null !== $grant['remaining_days'] ) : ?>
					<span class="mw-membership__level-remaining">
						<?php
						printf(
							/* translators: %d: days remaining. */
							esc_html( _n( '%d روز باقی‌مانده', '%d روز باقی‌مانده', (int) $grant['remaining_days'], 'music-wave-core' ) ),
							(int) $grant['remaining_days']
						);
						?>
					</span>
				<?php endif; ?>
			</div>

			<?php if ( $show_progress && ! $is_reserved && empty( $grant['is_lifetime'] ) && null !== $grant['percent_width'] ) : ?>
				<div class="mw-membership__level-progress" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) round( $grant['percent_width'] ) ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'میزان مصرف دوره اشتراک', 'music-wave-core' ); ?>">
					<div class="mw-membership__level-progress-bar" style="width: <?php echo esc_attr( number_format( (float) $grant['percent_width'], 1, '.', '' ) ); ?>%;"></div>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $renew_url || ( $show_order_link && $grant['order_id'] > 0 && function_exists( 'wc_get_endpoint_url' ) ) ) : ?>
				<div class="mw-membership__level-actions">
					<?php if ( '' !== $renew_url ) : ?>
						<a class="wp-element-button mw-membership__level-extend" href="<?php echo esc_url( $renew_url ); ?>">
							<?php echo esc_html__( 'تمدید اشتراک', 'music-wave-core' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( $show_order_link && $grant['order_id'] > 0 && function_exists( 'wc_get_endpoint_url' ) ) : ?>
						<a class="mw-membership__level-reserve" href="<?php echo esc_url( wc_get_endpoint_url( 'view-order', (string) $grant['order_id'], wc_get_page_permalink( 'myaccount' ) ) ); ?>">
							<?php echo esc_html__( 'مشاهده سفارش', 'music-wave-core' ); ?>
						</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</li>
		<?php
		return trim( (string) ob_get_clean() );
	}

	/**
	 * Build escaped subscription status pill row inside welcome header.
	 *
	 * @param array<string, mixed> $context Resolved context.
	 */
	private function subscription_meta_markup( array $context ): string {
		$primary     = isset( $context['primary'] ) && is_array( $context['primary'] ) ? $context['primary'] : null;
		$renewal_url = isset( $context['renewal_url'] ) ? (string) $context['renewal_url'] : '';
		$is_vip      = ! empty( $context['is_vip'] );

		ob_start();
		if ( null !== $primary ) :
			?>
			<div class="mw-user-dashboard__subscription-meta">
				<span class="mw-user-dashboard__subscription-level">
					<span class="mw-user-dashboard__subscription-dot" aria-hidden="true"></span>
					<?php echo esc_html( $primary['title'] ); ?>
				</span>
				<?php if ( ! empty( $primary['is_lifetime'] ) ) : ?>
					<span class="mw-user-dashboard__subscription-expiry"><?php echo esc_html__( 'دسترسی دائمی', 'music-wave-core' ); ?></span>
				<?php elseif ( '' !== $primary['expires_label'] ) : ?>
					<span class="mw-user-dashboard__subscription-expiry">
						<?php
						printf(
							/* translators: %s: formatted expiry date. */
							esc_html__( 'اعتبار تا %s', 'music-wave-core' ),
							esc_html( $primary['expires_label'] )
						);
						?>
					</span>
				<?php endif; ?>
				<?php if ( empty( $primary['is_lifetime'] ) && null !== $primary['remaining_days'] ) : ?>
					<span class="mw-user-dashboard__subscription-remaining">
						<?php
						printf(
							/* translators: %d: days left. */
							esc_html( _n( '%d روز باقی‌مانده', '%d روز باقی‌مانده', (int) $primary['remaining_days'], 'music-wave-core' ) ),
							(int) $primary['remaining_days']
						);
						?>
					</span>
				<?php endif; ?>
			</div>
			<?php
		elseif ( $is_vip ) :
			?>
			<div class="mw-user-dashboard__subscription-meta">
				<span class="mw-user-dashboard__subscription-level">
					<span class="mw-user-dashboard__subscription-dot" aria-hidden="true"></span>
					<?php echo esc_html__( 'اشتراک ویژه فعال', 'music-wave-core' ); ?>
				</span>
			</div>
			<?php
		else :
			?>
			<div class="mw-user-dashboard__subscription-meta mw-user-dashboard__subscription-meta--standard">
				<span class="mw-user-dashboard__subscription-level"><?php echo esc_html__( 'کاربر عادی', 'music-wave-core' ); ?></span>
				<?php if ( '' !== $renewal_url ) : ?>
					<a class="mw-membership__level-reserve" href="<?php echo esc_url( $renewal_url ); ?>"><?php echo esc_html__( 'خرید اشتراک ویژه', 'music-wave-core' ); ?></a>
				<?php endif; ?>
			</div>
			<?php
		endif;

		return trim( (string) ob_get_clean() );
	}

	/**
	 * Normalize a single grant dictionary regardless of whether `percent_used` is `0.0..1.0` ratio or `0..100` percentage.
	 *
	 * @param array<string, mixed>  $grant       Raw grant entry.
	 * @param array<string, string> $plan_titles Map of `level => product title`.
	 * @return array<string, mixed>
	 */
	private function normalize_grant( array $grant, array $plan_titles ): array {
		$level = isset( $grant['level'] ) ? (string) $grant['level'] : (string) ( $grant['plan_id'] ?? '' );
		$title = isset( $grant['plan_name'] ) && '' !== trim( (string) $grant['plan_name'] )
			? trim( (string) $grant['plan_name'] )
			: ( isset( $plan_titles[ $level ] ) ? $plan_titles[ $level ] : ( '' !== $level ? strtoupper( $level ) : __( 'اشتراک ویژه', 'music-wave-core' ) ) );

		$expires = isset( $grant['expires_label'] ) && '' !== (string) $grant['expires_label']
			? (string) $grant['expires_label']
			: (string) ( $grant['expires'] ?? '' );

		$remaining = null;
		if ( isset( $grant['remaining_days'] ) && is_numeric( $grant['remaining_days'] ) ) {
			$remaining = max( 0, (int) $grant['remaining_days'] );
		} elseif ( isset( $grant['days_remaining'] ) && is_numeric( $grant['days_remaining'] ) ) {
			$remaining = max( 0, (int) $grant['days_remaining'] );
		}

		$percent_width = null;
		if ( isset( $grant['percent_used'] ) && is_numeric( $grant['percent_used'] ) ) {
			$raw_pct       = (float) $grant['percent_used'];
			$scaled        = ( $raw_pct > 0.0 && $raw_pct <= 1.0 ) ? ( $raw_pct * 100.0 ) : $raw_pct;
			$percent_width = max( 0.0, min( 100.0, $scaled ) );
		}

		return array(
			'level'          => $level,
			'title'          => $title,
			'order_id'       => isset( $grant['order_id'] ) ? max( 0, (int) $grant['order_id'] ) : 0,
			'is_lifetime'    => ! empty( $grant['is_lifetime'] ),
			'is_stacked'     => ! empty( $grant['is_stacked'] ),
			'is_reserved'    => ! empty( $grant['is_reserved'] ),
			'expires_at'     => isset( $grant['expires_at'] ) ? max( 0, (int) $grant['expires_at'] ) : 0,
			'starts_at'      => isset( $grant['starts_at'] ) ? max( 0, (int) $grant['starts_at'] ) : 0,
			'starts_label'   => isset( $grant['starts_label'] ) ? (string) $grant['starts_label'] : '',
			'reserved_days'  => isset( $grant['reserved_days'] ) ? max( 0, (int) $grant['reserved_days'] ) : 0,
			'expires_label'  => $expires,
			'remaining_days' => $remaining,
			'percent_width'  => $percent_width,
		);
	}

	private static function default_plan_icon( string $level, int $duration_days ): string {
		if ( 0 === $duration_days && '' !== $level ) {
			return 'infinity';
		}
		if ( $duration_days >= 300 || false !== strpos( $level, '12' ) || false !== strpos( $level, 'year' ) ) {
			return 'crown';
		}
		if ( $duration_days >= 90 || false !== strpos( $level, '6' ) ) {
			return 'diamond';
		}

		return 'bolt';
	}

	private static function plan_icon_svg( string $name, int $size = 18 ): string {
		$size = max( 12, min( 28, $size ) );
		$attr = 'width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"';

		switch ( $name ) {
			case 'crown':
				return '<svg ' . $attr . '><path d="M3 18h18l-2-11-5 4-2-6-2 6-5-4-2 11z"/><path d="M3 21h18"/></svg>';
			case 'diamond':
			case 'sparkles':
				return '<svg ' . $attr . '><path d="M6 3h12l4 6-10 12L2 9z"/><path d="M2 9h20"/><path d="M12 21L8 9l4-6 4 6z"/></svg>';
			case 'infinity':
				return '<svg ' . $attr . '><path d="M18.178 8c5.096 0 5.096 8 0 8-5.095 0-7.133-8-12.739-8-4.585 0-4.585 8 0 8 5.606 0 7.644-8 12.74-8z"/></svg>';
			case 'shield':
				return '<svg ' . $attr . '><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>';
			case 'check':
				return '<svg ' . $attr . '><path d="M20 6 9 17l-5-5"/></svg>';
			case 'bolt':
			default:
				return '<svg ' . $attr . '><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>';
		}
	}

	/**
	 * Fallback copy extractor when a custom filter passes a bare product ID without pre-extracted copy.
	 *
	 * @return array{description: string, features: array<int, string>}
	 */
	private static function extract_fallback_product_copy( int $product_id ): array {
		$raw_content = (string) get_post_field( 'post_content', $product_id );
		$raw_excerpt = (string) get_post_field( 'post_excerpt', $product_id );
		$features    = array();

		if ( preg_match_all( '/<li\b[^>]*>(.*?)<\/li>/is', $raw_content . "\n" . $raw_excerpt, $matches ) ) {
			foreach ( $matches[1] as $item ) {
				$clean = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $item ) ) ?? '' );
				if ( '' !== $clean && ! in_array( $clean, $features, true ) ) {
					$features[] = $clean;
				}
			}
		}

		$prose = preg_replace( '/<(ul|ol)\b[^>]*>.*?<\/\1>/is', '', $raw_content );
		$desc  = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $prose ) ) ?? '' );
		if ( '' === $desc && '' !== trim( $raw_excerpt ) ) {
			$desc = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $raw_excerpt ) ) ?? '' );
		}

		return array(
			'description' => $desc,
			'features'    => array_slice( $features, 0, 6 ),
		);
	}
}
