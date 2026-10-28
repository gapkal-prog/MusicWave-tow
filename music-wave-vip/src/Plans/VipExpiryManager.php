<?php
/**
 * Expiry automation for VIP plans.
 *
 * SRP: only expiry scanning, role sync, cron scheduling.
 *
 * @package ManaCore\MusicWave\Vip\Plans
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Plans;

use ManaCore\MusicWave\Vip\VipPlans;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VipExpiryManager {

	/** @var VipPlans */
	private $plans;

	public function __construct( VipPlans $plans ) {
		$this->plans = $plans;
	}

	/**
	 * @return array<string, int>
	 */
	public function handle_expiry_check(): array {
		$pruned_users = 0;
		$demoted      = 0;
		$scanned      = 0;

		$user_ids = $this->collect_vip_user_ids();
		foreach ( $user_ids as $user_id ) {
			++$scanned;
			$before = $this->plans->active_levels( $user_id );
			$this->plans->grants_for_user( $user_id );
			$after = $this->plans->active_levels( $user_id );

			if ( count( $after ) < count( $before ) || ( empty( $after ) && ! empty( $before ) ) ) {
				++$pruned_users;
			}
			$had_role = $this->user_has_role( $user_id );
			$this->plans->sync_user_role( $user_id );
			$has_role = $this->user_has_role( $user_id );

			if ( $had_role && ! $has_role ) {
				++$demoted;
				do_action( 'music_wave_vip_expired', $user_id, array_values( array_diff( $before, $after ) ) );
			}
		}

		return array(
			'scanned'      => $scanned,
			'pruned_users' => $pruned_users,
			'demoted'      => $demoted,
		);
	}

	/**
	 * @return array<string, int>
	 */
	public function maybe_handle_expiry_check(): array {
		$cooldown = 15 * ( defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60 );
		if ( function_exists( 'get_transient' ) && false !== get_transient( VipPlans::SWEEP_TRANSIENT ) ) {
			return array(
				'scanned'      => 0,
				'pruned_users' => 0,
				'demoted'      => 0,
			);
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( VipPlans::SWEEP_TRANSIENT, 1, $cooldown );
		}
		return $this->handle_expiry_check();
	}

	/**
	 * @return array<int, int>
	 */
	private function collect_vip_user_ids(): array {
		$ids = array();
		if ( function_exists( 'get_users' ) ) {
			$role_users = get_users(
				array(
					'role'   => VipPlans::VIP_ROLE,
					'fields' => 'ID',
					'number' => 500,
				)
			);
			foreach ( is_array( $role_users ) ? $role_users : array() as $uid ) {
				$ids[ (int) $uid ] = true;
			}
			global $wpdb;
			if ( $wpdb instanceof \wpdb ) {
				$meta_users = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT %d",
						VipPlans::GRANTS_META_KEY,
						500
					)
				);
				foreach ( is_array( $meta_users ) ? $meta_users : array() as $uid ) {
					$ids[ (int) $uid ] = true;
				}
			}
		} else {
			if ( isset( $GLOBALS['mw_test_user_meta'] ) && is_array( $GLOBALS['mw_test_user_meta'] ) ) {
				foreach ( $GLOBALS['mw_test_user_meta'] as $uid => $meta ) {
					if ( isset( $meta[ VipPlans::GRANTS_META_KEY ] ) ) {
						$ids[ (int) $uid ] = true;
					}
				}
			}
			if ( isset( $GLOBALS['mw_test_users'] ) && is_array( $GLOBALS['mw_test_users'] ) ) {
				foreach ( $GLOBALS['mw_test_users'] as $uid => $user ) {
					if ( is_object( $user ) && isset( $user->roles ) && is_array( $user->roles ) && in_array( VipPlans::VIP_ROLE, $user->roles, true ) ) {
						$ids[ (int) $uid ] = true;
					}
				}
			}
		}
		return array_values( array_map( 'intval', array_keys( $ids ) ) );
	}

	private function user_has_role( int $user_id ): bool {
		$user = get_userdata( $user_id );
		return is_object( $user ) && property_exists( $user, 'roles' ) && is_array( $user->roles ) && in_array( VipPlans::VIP_ROLE, $user->roles, true );
	}
}
