<?php
/**
 * Typed, backwards-compatible VIP configuration.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip;

use ManaCore\MusicWave\Core\Support\SecretBoxCipher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VipSettings {
	public const OPTION             = 'music_wave_vip_settings';
	public const LEGACY_ROOT_OPTION = 'music_wave_vip_protected_root';

	/**
	 * Secret fields rendered with a "clear stored value" checkbox. Blank
	 * submissions keep the stored value; an explicit clear checkbox wipes it.
	 */
	private const SECRET_KEYS = array( 'remote_signing_secret', 'edge_signing_secret', 'edge_origin_header_value', 'remote_upload_auth_token', 'remote_upload_secret_key', 'sftp_password' );

	/**
	 * Remote-upload credentials stored wrapped at rest (ADR 0006 §8):
	 * `sodium_crypto_secretbox` + HKDF, distinct context from the HLS key wrap.
	 */
	private const WRAPPED_KEYS = array( 'remote_upload_auth_token', 'remote_upload_secret_key', 'sftp_password' );
	private const WRAP_PREFIX  = 'enc:v1:';
	private const WRAP_CONTEXT = 'mw-upload-credential-wrap';

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return array(
			'module_enabled'             => 'enabled',
			'delivery_access'            => 'logged_in',
			'delivery_provider'          => 'local',
			'protected_root'             => '',
			'remote_base_url'            => '',
			'remote_path_prefix'         => '',
			'remote_signing_secret'      => '',
			'remote_signature_param'     => 'signature',
			'remote_expires_param'       => 'expires',
			'remote_ttl'                 => 300,
			'remote_allowed_hosts'       => array(),
			'remote_key_id'              => '',
			'remote_signing_keys'        => array(),
			'remote_active_kid'          => '',
			'sendfile_mode'              => 'none',
			'xaccel_prefix'              => '',
			// Edge / signed delivery.
			'edge_public_host'           => '',
			'edge_base_url'              => '',
			'edge_origin_header_name'    => 'X-Origin-Token',
			'edge_origin_header_value'   => '',
			'edge_allowed_hosts'         => array(),
			'edge_signing_secret'        => '',
			'edge_signing_key_id'        => 'k1',
			'signed_url_ttl'             => 3600,
			// Session / anti-sharing.
			'session_enabled'            => 'enabled',
			'session_bind_ip'            => 'enabled',
			'session_bind_ua'            => 'disabled',
			'session_ttl'                => 60,
			'session_sliding_renewal'    => 'enabled',
			'session_max_concurrent'     => 1,
			'session_lease_grace'        => 120,
			'session_store'              => 'table',
			// HLS + AES-128. Default on: an unencrypted segment leak is playable,
			// so `aes128` is the safe default when HLS is opted into.
			'stream_encryption'          => 'aes128',
			'hls_enabled'                => 'disabled',
			'hls_segment_seconds'        => 6,
			'hls_renditions'             => array( 128, 320 ),
			'hls_packaging'              => 'on_upload',
			'hls_proxy_segments'         => 'disabled',
			'ffmpeg_binary'              => 'ffmpeg',
			'ffprobe_binary'             => 'ffprobe',
			// Remote upload to download server.
			'remote_upload_enabled'      => 'disabled',
			'remote_upload_mode'         => 'webhook',
			'remote_upload_endpoint'     => '',
			'remote_upload_auth_token'   => '',
			'remote_upload_access_key'   => '',
			'remote_upload_secret_key'   => '',
			'remote_upload_bucket'       => '',
			'remote_upload_region'       => '',
			'sftp_host'                  => '',
			'sftp_port'                  => 22,
			'sftp_user'                  => '',
			'sftp_password'              => '',
			'sftp_host_fingerprint'      => '',
			'remote_upload_path_prefix'  => '',
			'remote_upload_max_file_mb'  => 0,
			'remote_upload_keep_local'   => 'enabled',
			'remote_upload_verify'       => 'enabled',
			'remote_upload_max_retries'  => 5,
			'remote_upload_concurrency'  => 2,
			's3_multipart_threshold_mb'  => 100,
			'remote_upload_insecure_tls' => 'disabled',
			// Smart local delivery.
			'local_sendfile_autodetect'  => 'enabled',
			'local_selfheal_enabled'     => 'enabled',
			'local_checksum_autorefresh' => 'enabled',
			'local_stream_chunk_kb'      => 1024,
			'membership_sources'         => array( 'role', 'filter', 'woocommerce_plans' ),
			'vip_plans'                  => array(),
			'plan_rows'                  => '',
			'promote_vip_role'           => 'enabled',
			'delete_data_on_uninstall'   => 'disabled',
		);
	}

	/**
	 * Rotate the remote signing key: generate a new 32-byte base64url secret,
	 * retire the previous active key, and make the new one active.
	 *
	 * @return array{kid: string, secret: string}
	 */
	public static function rotate_signing_key(): array {
		$current = self::all();
		$keys    = isset( $current['remote_signing_keys'] ) && is_array( $current['remote_signing_keys'] ) ? $current['remote_signing_keys'] : array();

		// Retire existing active keys.
		foreach ( $keys as &$key_entry ) {
			if ( is_array( $key_entry ) && 'active' === (string) ( $key_entry['status'] ?? '' ) ) {
				$key_entry['status'] = 'retired';
			}
		}
		unset( $key_entry );

		$new_kid = 'k' . bin2hex( random_bytes( 4 ) );
		$new_key = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of random key bytes, not obfuscation.
		$keys[]  = array(
			'kid'     => $new_kid,
			'secret'  => $new_key,
			'created' => time(),
			'status'  => 'active',
		);

		$updated = self::sanitize(
			array_merge(
				$current,
				array(
					'remote_signing_keys' => $keys,
					'remote_active_kid'   => $new_kid,
				)
			)
		);

		update_option( self::OPTION, $updated );

		do_action( 'music_wave_vip_signing_key_rotated', $new_kid, $current );

		return array(
			'kid'    => $new_kid,
			'secret' => $new_key,
		);
	}

	/** @return array<string, mixed> */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		if ( ! isset( $stored['protected_root'] ) ) {
			$stored['protected_root'] = (string) get_option( self::LEGACY_ROOT_OPTION, '' );
		}

		return self::unwrap_secrets( self::normalize( array_merge( self::defaults(), $stored ), false ) );
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

		// Secret fields round-trip blank to mean "keep the stored value": the
		// settings screen never echoes a secret back into the page, so an empty
		// submission must not silently wipe a configured credential.
		foreach ( self::SECRET_KEYS as $secret_key ) {
			if ( ! isset( $value[ $secret_key ] ) || '' === trim( (string) $value[ $secret_key ] ) ) {
				$value[ $secret_key ] = (string) ( $current[ $secret_key ] ?? '' );
			}
		}

		// An explicit "clear stored value" checkbox overrides keep-if-blank.
		foreach ( self::SECRET_KEYS as $secret_key ) {
			if ( ! empty( $value[ 'clear_' . $secret_key ] ) ) {
				$value[ $secret_key ] = '';
			}
		}

		return self::wrap_secrets( self::normalize( $value, $plan_rows_present ) );
	}

	/**
	 * Wrap credential secrets at rest (no-op when already wrapped or sodium is
	 * unavailable, so an unwrappable value is never silently dropped).
	 *
	 * @param array<string, mixed> $canonical
	 * @return array<string, mixed>
	 */
	private static function wrap_secrets( array $canonical ): array {
		if ( ! SecretBoxCipher::available() ) {
			return $canonical;
		}
		foreach ( self::WRAPPED_KEYS as $key ) {
			$plain = (string) ( $canonical[ $key ] ?? '' );
			if ( '' === $plain || 0 === strpos( $plain, self::WRAP_PREFIX ) ) {
				continue;
			}
			$wrapped = SecretBoxCipher::wrap( $plain, self::WRAP_CONTEXT );
			if ( null !== $wrapped ) {
				$canonical[ $key ] = self::WRAP_PREFIX . $wrapped;
			}
		}

		return $canonical;
	}

	/**
	 * Unwrap credential secrets for consumers; legacy plaintext passes through.
	 *
	 * @param array<string, mixed> $canonical
	 * @return array<string, mixed>
	 */
	private static function unwrap_secrets( array $canonical ): array {
		foreach ( self::WRAPPED_KEYS as $key ) {
			$stored = (string) ( $canonical[ $key ] ?? '' );
			if ( 0 !== strpos( $stored, self::WRAP_PREFIX ) ) {
				continue;
			}
			$plain             = SecretBoxCipher::unwrap( substr( $stored, strlen( self::WRAP_PREFIX ) ), self::WRAP_CONTEXT );
			$canonical[ $key ] = null !== $plain ? $plain : '';
		}

		return $canonical;
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

		// Multi-key signing store (4.7). Silent migration: a legacy
		// remote_signing_secret becomes the first active signing key.
		$signing_keys = isset( $value['remote_signing_keys'] ) && is_array( $value['remote_signing_keys'] ) ? $value['remote_signing_keys'] : array();
		$signing_keys = array_values(
			array_filter(
				$signing_keys,
				static function ( $k ) {
					return is_array( $k ) && ! empty( $k['kid'] ) && ! empty( $k['secret'] );
				}
			)
		);
		$clean_keys   = array();
		foreach ( $signing_keys as $key_entry ) {
			$kid        = sanitize_key( (string) ( $key_entry['kid'] ?? '' ) );
			$key_secret = trim( (string) ( $key_entry['secret'] ?? '' ) );
			$status     = isset( $key_entry['status'] ) && in_array( (string) $key_entry['status'], array( 'active', 'retired' ), true ) ? (string) $key_entry['status'] : 'active';
			if ( '' === $kid || '' === $key_secret || strlen( $key_secret ) < 32 ) {
				continue;
			}
			$clean_keys[] = array(
				'kid'     => $kid,
				'secret'  => $key_secret,
				'created' => isset( $key_entry['created'] ) && is_numeric( $key_entry['created'] ) ? absint( $key_entry['created'] ) : time(),
				'status'  => $status,
			);
		}
		// Silent migration: legacy single secret → one active key.
		if ( empty( $clean_keys ) && '' !== $secret && strlen( $secret ) >= 32 ) {
			$migration_kid = '' !== $key_id ? $key_id : 'k1';
			$clean_keys[]  = array(
				'kid'     => $migration_kid,
				'secret'  => $secret,
				'created' => time(),
				'status'  => 'active',
			);
		}
		// Ensure exactly one active key.
		$active_count = 0;
		foreach ( $clean_keys as &$ck ) {
			if ( 'active' === $ck['status'] ) {
				++$active_count;
			}
		}
		unset( $ck );
		if ( 0 === $active_count && ! empty( $clean_keys ) ) {
			$clean_keys[0]['status'] = 'active';
		} elseif ( $active_count > 1 ) {
			$first_active = true;
			foreach ( $clean_keys as &$ck ) {
				if ( 'active' === $ck['status'] ) {
					if ( $first_active ) {
						$first_active = false;
					} else {
						$ck['status'] = 'retired';
					}
				}
			}
			unset( $ck );
		}
		$active_kid = isset( $value['remote_active_kid'] ) ? sanitize_key( (string) $value['remote_active_kid'] ) : '';
		if ( '' === $active_kid && ! empty( $clean_keys ) ) {
			foreach ( $clean_keys as $ck ) {
				if ( 'active' === $ck['status'] ) {
					$active_kid = $ck['kid'];
					break;
				}
			}
		}

		$sendfile_mode = isset( $value['sendfile_mode'] ) ? sanitize_key( (string) $value['sendfile_mode'] ) : '';
		$sendfile_mode = in_array( $sendfile_mode, array( 'none', 'xsendfile', 'xaccel' ), true ) ? $sendfile_mode : 'none';
		$xaccel_prefix = isset( $value['xaccel_prefix'] ) && is_scalar( $value['xaccel_prefix'] ) ? trim( sanitize_text_field( (string) $value['xaccel_prefix'] ) ) : '';
		$xaccel_prefix = '' !== $xaccel_prefix ? '/' . trim( preg_replace( '/[^A-Za-z0-9._\/-]/', '', $xaccel_prefix ), '/' ) : '';
		$sources       = isset( $value['membership_sources'] ) && is_array( $value['membership_sources'] ) ? $value['membership_sources'] : array();
		$alias_map     = array(
			'vip_plans'      => 'woocommerce_plans',
			'user_meta'      => 'woocommerce_plans',
			'roles'          => 'role',
			'wc_memberships' => 'woocommerce_memberships',
		);
		$sources       = array_map(
			static function ( $item ) use ( $alias_map ): string {
				$clean = sanitize_key( (string) $item );
				return isset( $alias_map[ $clean ] ) ? $alias_map[ $clean ] : $clean;
			},
			$sources
		);
		$sources       = array_values( array_unique( array_filter( $sources ) ) );
		$sources       = array_values( array_intersect( $sources, array( 'role', 'filter', 'woocommerce_plans', 'woocommerce_memberships', 'woocommerce_subscriptions' ) ) );
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

		// Edge / signed delivery.
		$edge_public_host    = isset( $value['edge_public_host'] ) && is_scalar( $value['edge_public_host'] ) ? trim( (string) $value['edge_public_host'] ) : '';
		$edge_base_url_raw   = isset( $value['edge_base_url'] ) && is_scalar( $value['edge_base_url'] ) ? trim( (string) $value['edge_base_url'] ) : '';
		$edge_base_url       = '' !== $edge_base_url_raw ? esc_url_raw( $edge_base_url_raw, array( 'https' ) ) : '';
		$edge_header_name    = isset( $value['edge_origin_header_name'] ) ? sanitize_key( (string) $value['edge_origin_header_name'] ) : 'X-Origin-Token';
		$edge_header_value   = isset( $value['edge_origin_header_value'] ) ? trim( (string) $value['edge_origin_header_value'] ) : '';
		$edge_allowed_hosts  = isset( $value['edge_allowed_hosts'] ) ? $value['edge_allowed_hosts'] : array();
		$edge_allowed_hosts  = is_array( $edge_allowed_hosts ) ? $edge_allowed_hosts : preg_split( '/[\s,]+/', (string) $edge_allowed_hosts );
		$edge_allowed_hosts  = array_values(
			array_filter(
				array_map(
					static function ( $h ) {
						return strtolower( trim( (string) $h ) );
					},
					$edge_allowed_hosts
				)
			)
		);
		$edge_signing_secret = isset( $value['edge_signing_secret'] ) ? trim( (string) $value['edge_signing_secret'] ) : '';
		$edge_signing_key_id = isset( $value['edge_signing_key_id'] ) ? sanitize_key( (string) $value['edge_signing_key_id'] ) : 'k1';
		if ( '' === $edge_signing_key_id ) {
			$edge_signing_key_id = 'k1';
		}
		$signed_url_ttl = isset( $value['signed_url_ttl'] ) ? absint( $value['signed_url_ttl'] ) : 0;
		$signed_url_ttl = $signed_url_ttl >= 300 && $signed_url_ttl <= 14400 ? $signed_url_ttl : $defaults['signed_url_ttl'];

		// Session / anti-sharing.
		$session_enabled         = isset( $value['session_enabled'] ) ? sanitize_key( (string) $value['session_enabled'] ) : 'enabled';
		$session_enabled         = in_array( $session_enabled, array( 'enabled', 'disabled' ), true ) ? $session_enabled : 'enabled';
		$session_bind_ip         = isset( $value['session_bind_ip'] ) ? sanitize_key( (string) $value['session_bind_ip'] ) : 'enabled';
		$session_bind_ip         = in_array( $session_bind_ip, array( 'enabled', 'disabled' ), true ) ? $session_bind_ip : 'enabled';
		$session_bind_ua         = isset( $value['session_bind_ua'] ) ? sanitize_key( (string) $value['session_bind_ua'] ) : 'disabled';
		$session_bind_ua         = in_array( $session_bind_ua, array( 'enabled', 'disabled' ), true ) ? $session_bind_ua : 'disabled';
		$session_ttl             = isset( $value['session_ttl'] ) ? absint( $value['session_ttl'] ) : 0;
		$session_ttl             = $session_ttl >= 30 && $session_ttl <= 900 ? $session_ttl : $defaults['session_ttl'];
		$session_sliding_renewal = isset( $value['session_sliding_renewal'] ) ? sanitize_key( (string) $value['session_sliding_renewal'] ) : 'enabled';
		$session_sliding_renewal = in_array( $session_sliding_renewal, array( 'enabled', 'disabled' ), true ) ? $session_sliding_renewal : 'enabled';
		$session_max_concurrent  = isset( $value['session_max_concurrent'] ) ? absint( $value['session_max_concurrent'] ) : 0;
		$session_max_concurrent  = $session_max_concurrent >= 1 && $session_max_concurrent <= 10 ? $session_max_concurrent : $defaults['session_max_concurrent'];
		$session_lease_grace     = isset( $value['session_lease_grace'] ) ? absint( $value['session_lease_grace'] ) : 0;
		$session_lease_grace     = $session_lease_grace >= 30 && $session_lease_grace <= 600 ? $session_lease_grace : $defaults['session_lease_grace'];
		$session_store           = isset( $value['session_store'] ) ? sanitize_key( (string) $value['session_store'] ) : 'table';
		$session_store           = in_array( $session_store, array( 'table', 'transient' ), true ) ? $session_store : 'table';

		// HLS + AES-128.
		$stream_encryption = isset( $value['stream_encryption'] ) ? sanitize_key( (string) $value['stream_encryption'] ) : 'aes128';
		$stream_encryption = in_array( $stream_encryption, array( 'off', 'aes128' ), true ) ? $stream_encryption : 'aes128';
		$hls_enabled       = isset( $value['hls_enabled'] ) ? sanitize_key( (string) $value['hls_enabled'] ) : 'disabled';
		$hls_enabled       = in_array( $hls_enabled, array( 'enabled', 'disabled' ), true ) ? $hls_enabled : 'disabled';
		$hls_segment_secs  = isset( $value['hls_segment_seconds'] ) ? absint( $value['hls_segment_seconds'] ) : 0;
		$hls_segment_secs  = $hls_segment_secs >= 2 && $hls_segment_secs <= 15 ? $hls_segment_secs : $defaults['hls_segment_seconds'];
		$hls_renditions    = isset( $value['hls_renditions'] ) && is_array( $value['hls_renditions'] ) ? $value['hls_renditions'] : $defaults['hls_renditions'];
		$hls_renditions    = array_values(
			array_filter(
				array_map( 'absint', $hls_renditions ),
				static function ( $b ) {
					return in_array( $b, array( 64, 128, 192, 320 ), true );
				}
			)
		);
		if ( empty( $hls_renditions ) ) {
			$hls_renditions = $defaults['hls_renditions'];
		}
		$hls_packaging      = isset( $value['hls_packaging'] ) ? sanitize_key( (string) $value['hls_packaging'] ) : 'on_upload';
		$hls_packaging      = in_array( $hls_packaging, array( 'on_upload', 'on_demand' ), true ) ? $hls_packaging : 'on_upload';
		$hls_proxy_segments = isset( $value['hls_proxy_segments'] ) ? sanitize_key( (string) $value['hls_proxy_segments'] ) : 'disabled';
		$hls_proxy_segments = in_array( $hls_proxy_segments, array( 'enabled', 'disabled' ), true ) ? $hls_proxy_segments : 'disabled';
		$ffmpeg_binary      = isset( $value['ffmpeg_binary'] ) && is_scalar( $value['ffmpeg_binary'] ) ? trim( (string) $value['ffmpeg_binary'] ) : 'ffmpeg';
		$ffprobe_binary     = isset( $value['ffprobe_binary'] ) && is_scalar( $value['ffprobe_binary'] ) ? trim( (string) $value['ffprobe_binary'] ) : 'ffprobe';

		// Remote upload.
		$remote_upload_enabled    = isset( $value['remote_upload_enabled'] ) ? sanitize_key( (string) $value['remote_upload_enabled'] ) : 'disabled';
		$remote_upload_enabled    = in_array( $remote_upload_enabled, array( 'enabled', 'disabled' ), true ) ? $remote_upload_enabled : 'disabled';
		$remote_upload_mode       = isset( $value['remote_upload_mode'] ) ? sanitize_key( (string) $value['remote_upload_mode'] ) : 'webhook';
		$remote_upload_mode       = in_array( $remote_upload_mode, array( 'webhook', 's3', 'webdav', 'sftp' ), true ) ? $remote_upload_mode : 'webhook';
		$remote_upload_endpoint   = isset( $value['remote_upload_endpoint'] ) ? trim( (string) $value['remote_upload_endpoint'] ) : '';
		$remote_upload_auth_token = isset( $value['remote_upload_auth_token'] ) ? trim( (string) $value['remote_upload_auth_token'] ) : '';
		$remote_upload_access_key = isset( $value['remote_upload_access_key'] ) ? trim( (string) $value['remote_upload_access_key'] ) : '';
		$remote_upload_secret_key = isset( $value['remote_upload_secret_key'] ) ? trim( (string) $value['remote_upload_secret_key'] ) : '';
		$remote_upload_bucket     = isset( $value['remote_upload_bucket'] ) ? trim( (string) $value['remote_upload_bucket'] ) : '';
		$remote_upload_region     = isset( $value['remote_upload_region'] ) ? sanitize_key( (string) $value['remote_upload_region'] ) : '';
		$sftp_host                = isset( $value['sftp_host'] ) && is_scalar( $value['sftp_host'] ) ? trim( sanitize_text_field( (string) $value['sftp_host'] ) ) : '';
		$sftp_port                = isset( $value['sftp_port'] ) ? absint( $value['sftp_port'] ) : 0;
		$sftp_port                = $sftp_port >= 1 && $sftp_port <= 65535 ? $sftp_port : (int) $defaults['sftp_port'];
		$sftp_user                = isset( $value['sftp_user'] ) && is_scalar( $value['sftp_user'] ) ? trim( sanitize_text_field( (string) $value['sftp_user'] ) ) : '';
		$sftp_password            = isset( $value['sftp_password'] ) && is_scalar( $value['sftp_password'] ) ? (string) $value['sftp_password'] : '';
		// Host-key fingerprint, e.g. `SHA256:AbCd...`. Normalized to `SHA256:<b64>`
		// or a bare 64-char hex digest; anything else is discarded (fail closed).
		$sftp_host_fingerprint = isset( $value['sftp_host_fingerprint'] ) && is_scalar( $value['sftp_host_fingerprint'] ) ? trim( preg_replace( '/\s+/', '', (string) $value['sftp_host_fingerprint'] ) ) : '';
		if ( '' !== $sftp_host_fingerprint && 1 !== preg_match( '/^(SHA256:)?[A-Za-z0-9+\/=]{20,}$/', $sftp_host_fingerprint ) && 1 !== preg_match( '/^[A-Fa-f0-9]{64}$/', $sftp_host_fingerprint ) ) {
			$sftp_host_fingerprint = '';
		}
		$remote_upload_path_prefix  = isset( $value['remote_upload_path_prefix'] ) ? trim( preg_replace( '/[^A-Za-z0-9._\/-]/', '', (string) $value['remote_upload_path_prefix'] ), '/' ) : '';
		$remote_upload_max_file_mb  = isset( $value['remote_upload_max_file_mb'] ) ? absint( $value['remote_upload_max_file_mb'] ) : 0;
		$remote_upload_keep_local   = isset( $value['remote_upload_keep_local'] ) ? sanitize_key( (string) $value['remote_upload_keep_local'] ) : 'enabled';
		$remote_upload_keep_local   = in_array( $remote_upload_keep_local, array( 'enabled', 'disabled' ), true ) ? $remote_upload_keep_local : 'enabled';
		$remote_upload_verify       = isset( $value['remote_upload_verify'] ) ? sanitize_key( (string) $value['remote_upload_verify'] ) : 'enabled';
		$remote_upload_verify       = in_array( $remote_upload_verify, array( 'enabled', 'disabled' ), true ) ? $remote_upload_verify : 'enabled';
		$remote_upload_max_retries  = isset( $value['remote_upload_max_retries'] ) ? absint( $value['remote_upload_max_retries'] ) : 0;
		$remote_upload_max_retries  = $remote_upload_max_retries >= 1 && $remote_upload_max_retries <= 10 ? $remote_upload_max_retries : $defaults['remote_upload_max_retries'];
		$remote_upload_concurrency  = isset( $value['remote_upload_concurrency'] ) ? absint( $value['remote_upload_concurrency'] ) : 0;
		$remote_upload_concurrency  = $remote_upload_concurrency >= 1 && $remote_upload_concurrency <= 5 ? $remote_upload_concurrency : $defaults['remote_upload_concurrency'];
		$s3_multipart_threshold_mb  = isset( $value['s3_multipart_threshold_mb'] ) ? absint( $value['s3_multipart_threshold_mb'] ) : 0;
		$s3_multipart_threshold_mb  = $s3_multipart_threshold_mb >= 10 && $s3_multipart_threshold_mb <= 1024 ? $s3_multipart_threshold_mb : $defaults['s3_multipart_threshold_mb'];
		$remote_upload_insecure_tls = isset( $value['remote_upload_insecure_tls'] ) ? sanitize_key( (string) $value['remote_upload_insecure_tls'] ) : 'disabled';
		$remote_upload_insecure_tls = in_array( $remote_upload_insecure_tls, array( 'enabled', 'disabled' ), true ) ? $remote_upload_insecure_tls : 'disabled';

		// Smart local delivery.
		$local_sendfile_autodetect  = isset( $value['local_sendfile_autodetect'] ) ? sanitize_key( (string) $value['local_sendfile_autodetect'] ) : 'enabled';
		$local_sendfile_autodetect  = in_array( $local_sendfile_autodetect, array( 'enabled', 'disabled' ), true ) ? $local_sendfile_autodetect : 'enabled';
		$local_selfheal_enabled     = isset( $value['local_selfheal_enabled'] ) ? sanitize_key( (string) $value['local_selfheal_enabled'] ) : 'enabled';
		$local_selfheal_enabled     = in_array( $local_selfheal_enabled, array( 'enabled', 'disabled' ), true ) ? $local_selfheal_enabled : 'enabled';
		$local_checksum_autorefresh = isset( $value['local_checksum_autorefresh'] ) ? sanitize_key( (string) $value['local_checksum_autorefresh'] ) : 'enabled';
		$local_checksum_autorefresh = in_array( $local_checksum_autorefresh, array( 'enabled', 'disabled' ), true ) ? $local_checksum_autorefresh : 'enabled';
		$local_stream_chunk_kb      = isset( $value['local_stream_chunk_kb'] ) ? absint( $value['local_stream_chunk_kb'] ) : 0;
		$local_stream_chunk_kb      = $local_stream_chunk_kb >= 64 && $local_stream_chunk_kb <= 8192 ? $local_stream_chunk_kb : $defaults['local_stream_chunk_kb'];

		// Extend delivery_provider validation to include new opt-in states.
		$provider = isset( $value['delivery_provider'] ) ? sanitize_key( (string) $value['delivery_provider'] ) : '';
		$provider = in_array( $provider, array( 'local', 'remote_redirect', 'edge_signed', 'proxy', 'edge_hls' ), true ) ? $provider : $defaults['delivery_provider'];

		// `edge_hls` is meaningless without its routes: enforce the pairing so a
		// mode switch can never leave the player with dead manifest URLs.
		if ( 'edge_hls' === $provider ) {
			$hls_enabled = 'enabled';
		}

		return array(
			'module_enabled'             => $module_enabled,
			'delivery_access'            => $delivery_access,
			'delivery_provider'          => $provider,
			'protected_root'             => $root,
			'remote_base_url'            => is_string( $base ) ? untrailingslashit( $base ) : '',
			'remote_path_prefix'         => $prefix,
			'remote_signing_secret'      => $secret,
			'remote_signature_param'     => '' !== $signature_param ? $signature_param : $defaults['remote_signature_param'],
			'remote_expires_param'       => '' !== $expires_param ? $expires_param : $defaults['remote_expires_param'],
			'remote_ttl'                 => $ttl >= 30 && $ttl <= 900 ? $ttl : $defaults['remote_ttl'],
			'remote_allowed_hosts'       => $allowed_hosts,
			'remote_key_id'              => $key_id,
			'remote_signing_keys'        => $clean_keys,
			'remote_active_kid'          => $active_kid,
			'sendfile_mode'              => $sendfile_mode,
			'xaccel_prefix'              => $xaccel_prefix,
			// Edge / signed delivery.
			'edge_public_host'           => $edge_public_host,
			'edge_base_url'              => untrailingslashit( $edge_base_url ),
			'edge_origin_header_name'    => $edge_header_name,
			'edge_origin_header_value'   => $edge_header_value,
			'edge_allowed_hosts'         => $edge_allowed_hosts,
			'edge_signing_secret'        => $edge_signing_secret,
			'edge_signing_key_id'        => $edge_signing_key_id,
			'signed_url_ttl'             => $signed_url_ttl,
			// Session / anti-sharing.
			'session_enabled'            => $session_enabled,
			'session_bind_ip'            => $session_bind_ip,
			'session_bind_ua'            => $session_bind_ua,
			'session_ttl'                => $session_ttl,
			'session_sliding_renewal'    => $session_sliding_renewal,
			'session_max_concurrent'     => $session_max_concurrent,
			'session_lease_grace'        => $session_lease_grace,
			'session_store'              => $session_store,
			// HLS + AES-128.
			'stream_encryption'          => $stream_encryption,
			'hls_enabled'                => $hls_enabled,
			'hls_segment_seconds'        => $hls_segment_secs,
			'hls_renditions'             => $hls_renditions,
			'hls_packaging'              => $hls_packaging,
			'hls_proxy_segments'         => $hls_proxy_segments,
			'ffmpeg_binary'              => $ffmpeg_binary,
			'ffprobe_binary'             => $ffprobe_binary,
			// Remote upload.
			'remote_upload_enabled'      => $remote_upload_enabled,
			'remote_upload_mode'         => $remote_upload_mode,
			'remote_upload_endpoint'     => $remote_upload_endpoint,
			'remote_upload_auth_token'   => $remote_upload_auth_token,
			'remote_upload_access_key'   => $remote_upload_access_key,
			'remote_upload_secret_key'   => $remote_upload_secret_key,
			'remote_upload_bucket'       => $remote_upload_bucket,
			'remote_upload_region'       => $remote_upload_region,
			'sftp_host'                  => $sftp_host,
			'sftp_port'                  => $sftp_port,
			'sftp_user'                  => $sftp_user,
			'sftp_password'              => $sftp_password,
			'sftp_host_fingerprint'      => $sftp_host_fingerprint,
			'remote_upload_path_prefix'  => $remote_upload_path_prefix,
			'remote_upload_max_file_mb'  => $remote_upload_max_file_mb,
			'remote_upload_keep_local'   => $remote_upload_keep_local,
			'remote_upload_verify'       => $remote_upload_verify,
			'remote_upload_max_retries'  => $remote_upload_max_retries,
			'remote_upload_concurrency'  => $remote_upload_concurrency,
			's3_multipart_threshold_mb'  => $s3_multipart_threshold_mb,
			'remote_upload_insecure_tls' => $remote_upload_insecure_tls,
			// Smart local delivery.
			'local_sendfile_autodetect'  => $local_sendfile_autodetect,
			'local_selfheal_enabled'     => $local_selfheal_enabled,
			'local_checksum_autorefresh' => $local_checksum_autorefresh,
			'local_stream_chunk_kb'      => $local_stream_chunk_kb,
			'membership_sources'         => $sources,
			'vip_plans'                  => $plans,
			'plan_rows'                  => implode( "\n", VipPlans::plan_rows_for_display( $plans ) ),
			'promote_vip_role'           => $promote_vip_role,
			'delete_data_on_uninstall'   => $delete_data,
		);
	}
}
