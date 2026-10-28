<?php
/**
 * Typed, backwards-compatible VIP configuration.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip;

use ManaCore\MusicWave\Vip\Security\RemoteSigningKeyring;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VipSettings {
	public const OPTION             = 'music_wave_vip_settings';
	public const LEGACY_ROOT_OPTION = 'music_wave_vip_protected_root';

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return array(
			'module_enabled'           => 'enabled',
			'delivery_access'          => 'logged_in',
			'delivery_provider'        => 'local',
			'protected_root'           => '',
			'remote_base_url'          => '',
			'remote_path_prefix'       => '',
			'remote_signing_secret'    => '',
			'remote_signature_param'   => 'signature',
			'remote_expires_param'     => 'expires',
			'remote_ttl'               => 300,
			'remote_allowed_hosts'     => array(),
			'remote_key_id'            => '',
			'sendfile_mode'            => 'none',
			'xaccel_prefix'            => '',
			'membership_sources'       => array( 'role', 'filter', 'woocommerce_plans' ),
			'vip_plans'                => array(),
			'plan_rows'                => '',
			'promote_vip_role'         => 'enabled',
			'delete_data_on_uninstall' => 'disabled',
		);
	}

	/** @return array<string, mixed> */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		if ( ! isset( $stored['protected_root'] ) ) {
			$stored['protected_root'] = (string) get_option( self::LEGACY_ROOT_OPTION, '' );
		}

		$normalized = self::normalize( array_merge( self::defaults(), $stored ), false );
		$active_key = RemoteSigningKeyring::resolve_active_key( $normalized );
		if ( '' !== $active_key['secret'] ) {
			$normalized['remote_signing_secret'] = $active_key['secret'];
		}
		if ( '' !== $active_key['kid'] && '' === (string) $normalized['remote_key_id'] ) {
			$normalized['remote_key_id'] = $active_key['kid'];
		}

		return $normalized;
	}

	/** @param mixed $value @return array<string, mixed> */
	public static function sanitize( $value ): array {
		$current           = self::all();
		$value             = is_array( $value ) ? $value : array();
		$plan_rows_present = array_key_exists( 'plan_rows', $value );
		$value             = array_merge( $current, $value );

		if ( ! isset( $value['remote_signing_secret'] ) || '' === trim( (string) $value['remote_signing_secret'] ) ) {
			$value['remote_signing_secret'] = $current['remote_signing_secret'];
		}

		return self::normalize( $value, $plan_rows_present );
	}

	/**
	 * Convert a level-keyed plan map into the row-list shape.
	 *
	 * Backwards compatibility for older option payloads where plans were
	 * stored keyed by level slug instead of as a flat list of rows.
	 *
	 * @param array<string, mixed> $candidates Stored vip_plans value.
	 * @return array<int, array{level: string, product_ids: array<int, int>, duration_days: int}>
	 */
	private static function plan_list( array $candidates ): array {
		$rows = array();
		foreach ( $candidates as $level => $candidate ) {
			$rows[] = array(
				'level'         => (string) $level,
				'product_ids'   => is_array( $candidate ) && isset( $candidate['product_ids'] ) ? $candidate['product_ids'] : array(),
				'duration_days' => is_array( $candidate ) && isset( $candidate['duration_days'] ) ? (int) $candidate['duration_days'] : 0,
			);
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $value             Candidate settings.
	 * @param bool                 $plan_rows_present Whether the plan textarea
	 *                                                was submitted; an empty
	 *                                                submission clears plans,
	 *                                                a missing one keeps them.
	 * @return array<string, mixed>
	 */
	private static function normalize( array $value, bool $plan_rows_present = true ): array {
		$defaults        = self::defaults();
		$module_enabled  = isset( $value['module_enabled'] ) ? sanitize_key( (string) $value['module_enabled'] ) : '';
		$module_enabled  = in_array( $module_enabled, array( 'enabled', 'disabled' ), true ) ? $module_enabled : $defaults['module_enabled'];
		$delivery_access = isset( $value['delivery_access'] ) ? sanitize_key( (string) $value['delivery_access'] ) : '';
		$delivery_access = in_array( $delivery_access, array( 'logged_in', 'everyone' ), true ) ? $delivery_access : $defaults['delivery_access'];
		$provider        = isset( $value['delivery_provider'] ) ? sanitize_key( (string) $value['delivery_provider'] ) : '';
		$provider        = in_array( $provider, array( 'local', 'remote_redirect' ), true ) ? $provider : $defaults['delivery_provider'];

		$root_raw = isset( $value['protected_root'] ) && is_scalar( $value['protected_root'] ) ? (string) $value['protected_root'] : '';
		$root     = trim( wp_unslash( $root_raw ) );
		// Windows path fix: wp_unslash via stripslashes strips backslashes from
		// drive-letter paths (C:\...), corrupting them to C:Users... . Detect
		// and recover the original when any backslash was lost.
		if ( '' !== $root_raw && false !== strpos( $root_raw, '\\' ) && false === strpos( $root, '\\' ) ) {
			$root = trim( $root_raw );
		}
		if ( function_exists( 'wp_normalize_path' ) ) {
			$root = wp_normalize_path( $root );
		} else {
			$root = str_replace( '\\', '/', $root );
		}
		$base            = isset( $value['remote_base_url'] ) && is_scalar( $value['remote_base_url'] ) ? esc_url_raw( trim( (string) $value['remote_base_url'] ), array( 'https' ) ) : '';
		$prefix          = isset( $value['remote_path_prefix'] ) && is_scalar( $value['remote_path_prefix'] ) ? trim( sanitize_text_field( (string) $value['remote_path_prefix'] ) ) : '';
		$prefix          = trim( preg_replace( '/[^A-Za-z0-9._\/-]/', '', $prefix ), '/' );
		$secret          = isset( $value['remote_signing_secret'] ) && is_scalar( $value['remote_signing_secret'] ) ? trim( (string) $value['remote_signing_secret'] ) : '';
		$signature_param = isset( $value['remote_signature_param'] ) ? sanitize_key( (string) $value['remote_signature_param'] ) : '';
		$expires_param   = isset( $value['remote_expires_param'] ) ? sanitize_key( (string) $value['remote_expires_param'] ) : '';
		$ttl             = isset( $value['remote_ttl'] ) ? absint( $value['remote_ttl'] ) : 0;
		$allowed_hosts   = isset( $value['remote_allowed_hosts'] ) ? $value['remote_allowed_hosts'] : array();
		$allowed_hosts   = is_array( $allowed_hosts ) ? $allowed_hosts : explode( "\n", (string) $allowed_hosts );
		$allowed_hosts   = array_values(
			array_filter(
				array_unique(
					array_map(
						static function ( $host ): string {
							$host = strtolower( trim( (string) $host ) );
							return 1 === preg_match( '/^[a-z0-9.-]+$/', $host ) ? $host : '';
						},
						$allowed_hosts
					)
				)
			)
		);
		$key_id          = isset( $value['remote_key_id'] ) ? sanitize_key( (string) $value['remote_key_id'] ) : '';
		$sendfile_mode   = isset( $value['sendfile_mode'] ) ? sanitize_key( (string) $value['sendfile_mode'] ) : '';
		$sendfile_mode   = in_array( $sendfile_mode, array( 'none', 'xsendfile', 'xaccel' ), true ) ? $sendfile_mode : 'none';
		$xaccel_prefix   = isset( $value['xaccel_prefix'] ) && is_scalar( $value['xaccel_prefix'] ) ? trim( sanitize_text_field( (string) $value['xaccel_prefix'] ) ) : '';
		$xaccel_prefix   = '' !== $xaccel_prefix ? '/' . trim( preg_replace( '/[^A-Za-z0-9._\/-]/', '', $xaccel_prefix ), '/' ) : '';
		$sources         = isset( $value['membership_sources'] ) && is_array( $value['membership_sources'] ) ? $value['membership_sources'] : array();
		$alias_map       = array(
			'vip_plans'      => 'woocommerce_plans',
			'user_meta'      => 'woocommerce_plans',
			'roles'          => 'role',
			'wc_memberships' => 'woocommerce_memberships',
		);
		$sources         = array_map(
			static function ( $item ) use ( $alias_map ): string {
				$clean = sanitize_key( (string) $item );
				return isset( $alias_map[ $clean ] ) ? $alias_map[ $clean ] : $clean;
			},
			$sources
		);
		$sources         = array_values( array_unique( array_filter( $sources ) ) );
		$sources         = array_values( array_intersect( $sources, array( 'role', 'filter', 'woocommerce_plans', 'woocommerce_memberships', 'woocommerce_subscriptions' ) ) );
		// An intentionally emptied selection stays empty: silently restoring
		// defaults would re-enable entitlement sources an admin turned off.
		// Membership checks fail closed with no active sources.

		// VIP plan configuration: the admin textarea (`plan_rows`) is the
		// canonical input whenever the settings form was submitted — an empty
		// textarea intentionally clears every plan. Partial programmatic
		// updates without the field keep the stored rows. Every path
		// round-trips through parse_plan_rows() so storage stays normalized,
		// including the legacy level-keyed map shape.
		$plan_rows_input = isset( $value['plan_rows'] ) && is_string( $value['plan_rows'] ) ? trim( wp_unslash( $value['plan_rows'] ) ) : '';
		$plans_input     = isset( $value['vip_plans'] ) && is_array( $value['vip_plans'] ) ? $value['vip_plans'] : array();
		if ( $plan_rows_present ) {
			$plans = '' !== $plan_rows_input ? VipPlans::parse_plan_rows( $plan_rows_input ) : array();
		} elseif ( '' !== $plan_rows_input ) {
			$plans = VipPlans::parse_plan_rows( $plan_rows_input );
		} elseif ( ! empty( $plans_input ) ) {
			$plans = VipPlans::parse_plan_rows(
				implode(
					"\n",
					VipPlans::plan_rows_for_display( is_string( key( $plans_input ) ) ? self::plan_list( $plans_input ) : $plans_input )
				)
			);
		} else {
			$plans = array();
		}
		$plans = array_values(
			array_filter(
				$plans,
				static function ( array $plan ): bool {
					return ! empty( $plan['level'] ) && ! empty( $plan['product_ids'] );
				}
			)
		);

		$promote_vip_role = isset( $value['promote_vip_role'] ) && is_scalar( $value['promote_vip_role'] ) ? sanitize_key( (string) $value['promote_vip_role'] ) : '';
		$promote_vip_role = in_array( $promote_vip_role, array( 'enabled', 'disabled' ), true ) ? $promote_vip_role : $defaults['promote_vip_role'];

		$delete_data = isset( $value['delete_data_on_uninstall'] ) && is_scalar( $value['delete_data_on_uninstall'] ) ? sanitize_key( (string) $value['delete_data_on_uninstall'] ) : '';
		$delete_data = in_array( $delete_data, array( 'enabled', 'disabled' ), true ) ? $delete_data : $defaults['delete_data_on_uninstall'];

		return array(
			'module_enabled'           => $module_enabled,
			'delivery_access'          => $delivery_access,
			'delivery_provider'        => $provider,
			'protected_root'           => $root,
			'remote_base_url'          => is_string( $base ) ? untrailingslashit( $base ) : '',
			'remote_path_prefix'       => $prefix,
			'remote_signing_secret'    => $secret,
			'remote_signature_param'   => '' !== $signature_param ? $signature_param : $defaults['remote_signature_param'],
			'remote_expires_param'     => '' !== $expires_param ? $expires_param : $defaults['remote_expires_param'],
			'remote_ttl'               => $ttl >= 30 && $ttl <= 900 ? $ttl : $defaults['remote_ttl'],
			'remote_allowed_hosts'     => $allowed_hosts,
			'remote_key_id'            => $key_id,
			'sendfile_mode'            => $sendfile_mode,
			'xaccel_prefix'            => $xaccel_prefix,
			'membership_sources'       => $sources,
			'vip_plans'                => $plans,
			'plan_rows'                => implode( "\n", VipPlans::plan_rows_for_display( $plans ) ),
			'promote_vip_role'         => $promote_vip_role,
			'delete_data_on_uninstall' => $delete_data,
		);
	}
}
