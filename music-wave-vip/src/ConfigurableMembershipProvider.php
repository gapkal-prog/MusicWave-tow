<?php
/**
 * Membership adapter combining roles, custom integrations and Woo extensions.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip;

use ManaCore\MusicWave\Core\Access\MembershipProvider;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ConfigurableMembershipProvider implements MembershipProvider {
	/** @var array<int, string> */
	private $sources;

	/** @var VipPlans|null */
	private $vip_plans;

	/**
	 * @param array<int, string> $sources
	 * @param VipPlans|null      $vip_plans Injectable plan engine (tests/overrides).
	 */
	public function __construct( array $sources, ?VipPlans $vip_plans = null ) {
		$this->sources   = array_values( array_unique( array_map( 'sanitize_key', $sources ) ) );
		$this->vip_plans = $vip_plans;
	}

	public function has_access( int $user_id, array $levels ): bool {
		if ( $user_id < 1 || empty( $levels ) ) {
			return false;
		}

		$levels = array_values( array_filter( array_map( 'sanitize_key', $levels ) ) );
		if ( empty( $levels ) ) {
			return false;
		}

		foreach ( $this->sources as $source ) {
			if ( $this->source_has_access( $source, $user_id, $levels ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether the user currently holds any active VIP grant or mapped role.
	 */
	public function has_any_active( int $user_id ): bool {
		if ( $user_id < 1 ) {
			return false;
		}
		foreach ( $this->sources as $source ) {
			if ( 'woocommerce_plans' === $source ) {
				if ( null === $this->vip_plans ) {
					$this->vip_plans = VipPlans::from_settings();
				}
				if ( ! empty( $this->vip_plans->active_levels( $user_id ) ) ) {
					return true;
				}
			} elseif ( 'role' === $source && $this->role_access( $user_id, VipPlans::VIP_ROLE ) ) {
				return true;
			} elseif ( 'filter' === $source && $this->filter_access( $user_id, VipPlans::VIP_ROLE ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<int, string> $levels */
	private function source_has_access( string $source, int $user_id, array $levels ): bool {
		foreach ( $levels as $level ) {
			$filtered = apply_filters( 'music_wave_vip_membership_access', null, $user_id, $level, $source );
			if ( true === $filtered ) {
				return true;
			}

			if ( 'role' === $source && $this->role_access( $user_id, $level ) ) {
				return true;
			}
			if ( 'filter' === $source && $this->filter_access( $user_id, $level ) ) {
				return true;
			}
			if ( 'woocommerce_plans' === $source && $this->plan_access( $user_id, $level ) ) {
				return true;
			}
			if ( 'woocommerce_memberships' === $source && $this->woocommerce_membership_access( $user_id, $level ) ) {
				return true;
			}
			if ( 'woocommerce_subscriptions' === $source && $this->subscription_access( $user_id, $level ) ) {
				return true;
			}
		}

		return false;
	}

	private function role_access( int $user_id, string $level ): bool {
		$user = get_userdata( $user_id );
		return is_object( $user ) && property_exists( $user, 'roles' ) && is_array( $user->roles ) && in_array( $level, array_map( 'sanitize_key', $user->roles ), true );
	}

	/**
	 * VIP plan grants purchased through WooCommerce plan products.
	 *
	 * Grants are stored verbatim and re-checked live on every decision, so a
	 * refund revokes access immediately (ADR 0004 parity). Fails closed when
	 * the plans engine cannot be composed.
	 */
	private function plan_access( int $user_id, string $level ): bool {
		if ( null === $this->vip_plans ) {
			$this->vip_plans = VipPlans::from_settings();
		}

		return $this->vip_plans->user_has_access( $user_id, $level );
	}

	private function filter_access( int $user_id, string $level ): bool {
		$user_levels = get_userdata( $user_id );
		$roles       = is_object( $user_levels ) && property_exists( $user_levels, 'roles' ) && is_array( $user_levels->roles ) ? $user_levels->roles : array();
		$mapped      = apply_filters( 'music_wave_vip_membership_levels_for_user', $roles, $user_id );

		return is_array( $mapped ) && in_array( $level, array_map( 'sanitize_key', $mapped ), true );
	}

	private function woocommerce_membership_access( int $user_id, string $level ): bool {
		if ( ! function_exists( 'wc_memberships_is_user_active_member' ) ) {
			return false;
		}

		$plan = 0 === strpos( $level, 'plan-' ) ? substr( $level, 5 ) : $level;
		return '' !== $plan && (bool) wc_memberships_is_user_active_member( $user_id, $plan );
	}

	private function subscription_access( int $user_id, string $level ): bool {
		if ( ! function_exists( 'wcs_user_has_subscription' ) ) {
			return false;
		}

		$product_id = 0 === strpos( $level, 'subscription-' ) ? absint( substr( $level, 13 ) ) : absint( $level );
		return $product_id > 0 && (bool) wcs_user_has_subscription( $user_id, $product_id, 'active' );
	}
}
