<?php
/**
 * WooCommerce plan-product membership engine.
 *
 * SOLID architecture:
 * - SRP: parsing in `Plans\VipPlanParser`, CAS storage in `Plans\VipGrantsStore`,
 *        expiry sweep in `Plans\VipExpiryManager`.
 * - Per-level grant lifecycle: every purchased plan level persists its own grant
 *   rows, renews idempotently when the same order replays, stacks cleanly onto
 *   existing active expiry for the same level, and revokes atomically per order.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip;

use ManaCore\MusicWave\Vip\Plans\VipExpiryManager;
use ManaCore\MusicWave\Vip\Plans\VipGrantsStore;
use ManaCore\MusicWave\Vip\Plans\VipPlanParser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VipPlans {
	public const GRANTS_META_KEY   = 'mw_vip_plan_grants';
	public const STATUS_UNTIL_META = 'mw_vip_effective_until';
	public const EVER_GRANTED_META = 'mw_vip_ever_granted';
	public const USER_HISTORY_META = 'mw_vip_user_log';
	public const ADMIN_NOTE_META   = 'mw_vip_admin_note';
	public const LIFETIME_SENTINEL = 9999999999;
	public const VIP_ROLE          = 'mw_vip';
	public const EXPIRY_CRON       = 'music_wave_vip_expiry_check';
	public const SWEEP_TRANSIENT   = 'mwvip_expiry_sweep_cooldown';

	private const SECONDS_PER_DAY = 86400;

	/** @var array<string, array{product_ids: array<int, int>, duration_days: int}> */
	private $plans;

	/** @var bool */
	private $promote_role;

	/** @var callable|null */
	private $now_source;

	/** @var VipGrantsStore */
	private $store;

	/** @var VipExpiryManager|null */
	private $expiry_manager = null;

	/**
	 * @param array<string, array<string, mixed>> $plans
	 */
	public function __construct( array $plans = array(), bool $promote_role = true, ?callable $now_source = null ) {
		$this->plans        = VipPlanParser::normalize_plans( $plans );
		$this->promote_role = $promote_role;
		$this->now_source   = $now_source;
		$this->store        = new VipGrantsStore( $now_source );
	}

	/**
	 * @param array<string, mixed>|null $config
	 */
	public static function from_settings( ?array $config = null, ?callable $now_source = null ): self {
		$config  = is_array( $config ) ? $config : VipSettings::all();
		$plans   = isset( $config['vip_plans'] ) && is_array( $config['vip_plans'] ) ? $config['vip_plans'] : array();
		$promote = ! isset( $config['promote_vip_role'] ) || 'disabled' !== sanitize_key( (string) $config['promote_vip_role'] );

		return new self( self::index_plans( $plans ), $promote, $now_source );
	}

	public function register_hooks(): void {
		$granting = apply_filters( 'music_wave_vip_granting_statuses', array( 'processing', 'completed' ) );
		foreach ( is_array( $granting ) ? $granting : array() as $status ) {
			$status = sanitize_key( (string) $status );
			if ( '' !== $status ) {
				add_action( 'woocommerce_order_status_' . $status, array( $this, 'on_granting_status' ), 10, 1 );
			}
		}
		foreach ( array( 'cancelled', 'refunded', 'failed' ) as $void_status ) {
			add_action( 'woocommerce_order_status_' . $void_status, array( $this, 'on_void_status' ), 10, 1 );
		}
	}

	public static function ensure_role(): void {
		if ( ! function_exists( 'add_role' ) || ! function_exists( 'get_role' ) ) {
			return;
		}
		if ( null !== get_role( self::VIP_ROLE ) ) {
			return;
		}
		add_role( self::VIP_ROLE, __( 'MusicWave VIP', 'music-wave-vip' ), array( 'read' => true ) );
	}

	/** @param mixed $order_id */
	public function on_granting_status( $order_id ): void {
		$this->grant_for_order( absint( $order_id ) );
	}

	/** @param mixed $order_id */
	public function on_void_status( $order_id ): void {
		$this->revoke_for_order( absint( $order_id ) );
	}

	/**
	 * Grant or idempotently renew every matching VIP level for a paid order.
	 */
	public function grant_for_order( int $order_id ): int {
		if ( $order_id < 1 || empty( $this->plans ) ) {
			return 0;
		}

		$user_id    = self::order_customer_id( $order_id );
		$quantities = self::order_product_quantities( $order_id );
		if ( $user_id < 1 || empty( $quantities ) ) {
			return 0;
		}

		$product_ids = array_keys( $quantities );
		$now         = $this->now();
		$matching    = array();

		foreach ( $this->plans as $level => $plan ) {
			$intersect = array_intersect( $plan['product_ids'], $product_ids );
			if ( empty( $intersect ) ) {
				continue;
			}
			$total_qty = 0;
			foreach ( $intersect as $pid ) {
				$total_qty += isset( $quantities[ $pid ] ) ? (int) $quantities[ $pid ] : 1;
			}
			$total_qty         = min( max( 1, $total_qty ), 24 );
			$duration_per_unit = (int) $plan['duration_days'];
			$total_duration    = $duration_per_unit > 0 ? $duration_per_unit * $total_qty : 0;

			$matching[ $level ] = array(
				'total_qty'      => $total_qty,
				'total_duration' => $total_duration,
				'duration_days'  => $duration_per_unit,
			);
		}

		if ( empty( $matching ) ) {
			return 0;
		}

		$grant_levels = array_keys( $matching );

		$mutated = $this->store->mutate_grants(
			$user_id,
			static function ( array $grants ) use ( $order_id, $now, $matching ): array {
				$next = $grants;

				foreach ( $matching as $level => $info ) {
					$existing       = isset( $next[ $level ] ) && is_array( $next[ $level ] ) ? $next[ $level ] : array();
					$other_entries  = array();
					$previous_entry = null;
					$max_other_exp  = 0;

					foreach ( $existing as $entry ) {
						if ( ! is_array( $entry ) ) {
							continue;
						}
						if ( isset( $entry['order_id'] ) && (int) $entry['order_id'] === $order_id ) {
							$previous_entry = $entry;
							continue;
						}
						$other_entries[] = $entry;
						$exp             = isset( $entry['expires_at'] ) ? (int) $entry['expires_at'] : 0;
						if ( $exp > $max_other_exp ) {
							$max_other_exp = $exp;
						}
					}

					$total_duration = (int) $info['total_duration'];
					$granted_at     = ( null !== $previous_entry && ! empty( $previous_entry['granted_at'] ) )
						? (int) $previous_entry['granted_at']
						: $now;
					$base_time      = max( $max_other_exp, $now );
					$expires_at     = $total_duration > 0 ? $base_time + ( $total_duration * self::SECONDS_PER_DAY ) : 0;

					$new_entry = array(
						'order_id'   => $order_id,
						'granted_at' => $granted_at,
						'expires_at' => $expires_at,
					);
					if ( $total_duration > 0 ) {
						$new_entry['duration_days'] = $total_duration;
					}

					$other_entries[] = $new_entry;
					if ( count( $other_entries ) > VipPlanParser::MAX_PLANS_PER_LEVEL ) {
						$other_entries = array_values(
							array_slice(
								$other_entries,
								count( $other_entries ) - VipPlanParser::MAX_PLANS_PER_LEVEL
							)
						);
					}
					$next[ $level ] = $other_entries;
				}

				return $next;
			}
		);

		if ( ! $mutated ) {
			$this->audit( 'grant_contention_failed', $order_id, $user_id );
			return 0;
		}

		$this->sync_user_role( $user_id );
		$this->record_order_grant_meta( $order_id, $user_id, $grant_levels, $matching, $quantities );
		$this->record_history( $user_id, 'order_granted', implode( ', ', array_unique( $grant_levels ) ), sprintf( '#%d', $order_id ), 0 );

		do_action( 'music_wave_vip_plan_granted', $user_id, $order_id, $grant_levels );

		return count( $grant_levels );
	}

	/**
	 * Revoke every VIP plan grant originally issued by `$order_id`.
	 */
	public function revoke_for_order( int $order_id ): int {
		if ( $order_id < 1 ) {
			return 0;
		}
		$user_id = self::order_customer_id( $order_id );
		if ( $user_id < 1 ) {
			return 0;
		}

		$removed = 0;
		$mutated = $this->store->mutate_grants(
			$user_id,
			static function ( array $grants ) use ( $order_id, &$removed ): ?array {
				$removed = 0;
				$next    = array();
				foreach ( $grants as $level => $entries ) {
					$kept = array();
					foreach ( $entries as $entry ) {
						if ( isset( $entry['order_id'] ) && (int) $entry['order_id'] === $order_id ) {
							++$removed;
							continue;
						}
						$kept[] = $entry;
					}
					if ( ! empty( $kept ) ) {
						$next[ $level ] = $kept;
					}
				}
				return $removed > 0 ? $next : null;
			}
		);

		if ( $removed < 1 || ! $mutated ) {
			if ( $removed > 0 && ! $mutated ) {
				$this->audit( 'revoke_contention_failed', $order_id, $user_id );
			}
			return 0;
		}

		$this->sync_user_role( $user_id );
		$this->record_history( $user_id, 'order_revoked', '', sprintf( '#%d', $order_id ), 0 );

		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$order->add_order_note( __( 'اشتراک VIP لغو شد (سفارش لغو/بازپرداخت/ناموفق). دسترسی به سطح عادی بازگشت.', 'music-wave-vip' ) );
				$order->delete_meta_data( '_mw_vip_granted_levels' );
				$order->delete_meta_data( '_mw_vip_granted_expiries' );
				$order->save();
			}
		}

		do_action( 'music_wave_vip_plan_revoked', $user_id, $order_id );

		return $removed;
	}

	public function user_has_access( int $user_id, string $level ): bool {
		if ( $user_id < 1 ) {
			return false;
		}
		$level = sanitize_key( $level );
		if ( '' === $level ) {
			return false;
		}
		// Umbrella VIP key (`mw_vip`): any active non-expired plan grant qualifies,
		// keeping `mw_vip`-gated releases accessible even when `promote_vip_role` is off.
		if ( self::VIP_ROLE === $level ) {
			return ! empty( $this->active_levels( $user_id ) );
		}
		$grants = $this->grants_for_user( $user_id );
		if ( empty( $grants[ $level ] ) ) {
			return false;
		}
		$now = $this->now();
		foreach ( $grants[ $level ] as $entry ) {
			$expires_at = isset( $entry['expires_at'] ) ? (int) $entry['expires_at'] : 0;
			if ( 0 === $expires_at || $expires_at > $now ) {
				return true;
			}
		}

		return false;
	}

	/** @return array<int, string> */
	public function active_levels( int $user_id ): array {
		if ( $user_id < 1 ) {
			return array();
		}
		$now    = $this->now();
		$levels = array();
		foreach ( $this->grants_for_user( $user_id ) as $level => $entries ) {
			foreach ( $entries as $entry ) {
				$expires_at = isset( $entry['expires_at'] ) ? (int) $entry['expires_at'] : 0;
				if ( 0 === $expires_at || $expires_at > $now ) {
					$levels[] = (string) $level;
					break;
				}
			}
		}
		sort( $levels );

		return $levels;
	}

	/**
	 * Return active grants enriched with progress (`0.0..1.0`), remaining days, and labels,
	 * sorted soonest-expiry first and lifetime last.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function detailed_active_grants( int $user_id ): array {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return array();
		}
		$current           = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$can_inspect_other = current_user_can( 'manage_options' )
			|| current_user_can( 'list_users' )
			|| current_user_can( 'edit_users' )
			|| current_user_can( 'edit_user', $user_id );
		if ( $current > 0 && $current !== $user_id && ! $can_inspect_other ) {
			if ( ! apply_filters( 'music_wave_vip_allow_cross_user_detailed', false, $user_id, $current ) ) {
				return array();
			}
		}

		$now    = $this->now();
		$result = array();
		$grants = $this->grants_for_user( $user_id );

		foreach ( $grants as $level => $entries ) {
			$level = sanitize_key( (string) $level );
			if ( '' === $level || ! is_array( $entries ) ) {
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$order_id      = isset( $entry['order_id'] ) ? absint( $entry['order_id'] ) : 0;
				$expires_at    = isset( $entry['expires_at'] ) ? absint( $entry['expires_at'] ) : 0;
				$granted_at    = isset( $entry['granted_at'] ) ? absint( $entry['granted_at'] ) : 0;
				$duration_days = isset( $entry['duration_days'] ) ? absint( $entry['duration_days'] ) : 0;

				if ( $expires_at > 0 && $expires_at > $now + ( VipPlanParser::MAX_DURATION_DAYS * self::SECONDS_PER_DAY ) ) {
					$expires_at = $now + ( VipPlanParser::MAX_DURATION_DAYS * self::SECONDS_PER_DAY );
				}
				if ( $granted_at > $now + 300 ) {
					$granted_at = $now;
				}
				$is_lifetime = 0 === $expires_at;
				if ( ! $is_lifetime && $expires_at <= $now ) {
					continue;
				}
				if ( $granted_at < 1 ) {
					$granted_at = absint( $this->infer_granted_at_from_order( $order_id, $expires_at, $duration_days, $now ) );
				}
				if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
					$order = wc_get_order( $order_id );
					if ( $order instanceof \WC_Order ) {
						$owner = (int) $order->get_customer_id();
						if ( $owner > 0 && $owner !== $user_id && ! current_user_can( 'manage_options' ) ) {
							$order_id = 0;
						}
					}
				}

				$remaining_days = $is_lifetime ? 0 : max( 0, (int) ceil( ( $expires_at - $now ) / self::SECONDS_PER_DAY ) );
				$percent_used   = $is_lifetime ? 0.0 : $this->calculate_percent_used( $granted_at, $expires_at, $now );
				$expires_label  = $is_lifetime
					? __( 'مادام‌العمر', 'music-wave-vip' )
					: ( $expires_at > 0 && function_exists( 'date_i18n' ) ? date_i18n( (string) get_option( 'date_format' ), $expires_at ) : '' );
				$granted_label  = $granted_at > 0 && function_exists( 'date_i18n' )
					? date_i18n( (string) get_option( 'date_format' ), $granted_at )
					: '';
				$is_stacked     = $duration_days > 0 && $granted_at > 0 && ( $expires_at - $granted_at ) > ( $duration_days * self::SECONDS_PER_DAY + self::SECONDS_PER_DAY );

				$result[] = array(
					'level'            => $level,
					'order_id'         => $order_id,
					'source'           => isset( $entry['source'] ) && 'admin' === (string) $entry['source'] ? 'admin' : 'order',
					'note'             => isset( $entry['note'] ) ? self::clean_text( (string) $entry['note'] ) : '',
					'granted_at'       => $granted_at,
					'expires_at'       => $expires_at,
					'duration_days'    => $duration_days,
					'is_lifetime'      => $is_lifetime,
					'remaining_days'   => $remaining_days,
					'percent_used'     => $percent_used,
					'expires_label'    => self::clean_text( $expires_label ),
					'granted_at_label' => self::clean_text( $granted_label ),
					'can_extend'       => ! $is_lifetime,
					'can_reserve'      => true,
					'extension_base'   => $is_lifetime ? 0 : max( $expires_at, $now ),
					'is_stacked'       => $is_stacked,
				);
			}
		}

		usort(
			$result,
			static function ( array $a, array $b ): int {
				if ( $a['is_lifetime'] && ! $b['is_lifetime'] ) {
					return 1;
				}
				if ( ! $a['is_lifetime'] && $b['is_lifetime'] ) {
					return -1;
				}
				return $a['expires_at'] <=> $b['expires_at'];
			}
		);

		$result = apply_filters( 'music_wave_vip_detailed_active_grants', $result, $user_id );
		return is_array( $result ) ? $result : array();
	}

	/**
	 * Return all non-lifetime active grants at or above the renewal threshold (`0.0..1.0` or `1..99`).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function grants_needing_renewal( int $user_id, float $threshold = 0.9 ): array {
		$raw_threshold = (float) apply_filters( 'music_wave_subscription_renewal_threshold', $threshold, $user_id );
		$ratio         = $raw_threshold > 1.0 ? ( $raw_threshold / 100.0 ) : $raw_threshold;
		if ( $ratio <= 0.0 ) {
			$ratio = 0.9;
		} elseif ( $ratio > 0.99 ) {
			$ratio = 0.99;
		}

		$needing = array();
		foreach ( $this->detailed_active_grants( $user_id ) as $grant ) {
			if ( ! empty( $grant['is_lifetime'] ) ) {
				continue;
			}
			if ( (float) $grant['percent_used'] >= $ratio ) {
				$needing[] = $grant;
			}
		}

		return $needing;
	}

	/**
	 * Convenience single-grant wrapper over `grants_needing_renewal()` (DRY).
	 *
	 * @return array<string, mixed>|null
	 */
	public function grant_needing_renewal( int $user_id, float $threshold = 0.9 ): ?array {
		$needing = $this->grants_needing_renewal( $user_id, $threshold );
		return isset( $needing[0] ) ? $needing[0] : null;
	}

	/** @return array<string, array<int, array<string, mixed>>> */
	public function grants_for_user( int $user_id ): array {
		return $this->store->grants_for_user( $user_id );
	}

	/**
	 * Issue or stack an admin-managed VIP grant for `$user_id` without requiring a WooCommerce order.
	 *
	 * Pass `$duration_days = 0` and `$explicit_expires_at = 0` for a lifetime grant,
	 * `$duration_days > 0` to add/extend days cleanly on top of any current expiry on `$level`,
	 * or `$explicit_expires_at > 0` to pin an exact expiration timestamp.
	 */
	public function grant_manual(
		int $user_id,
		string $level,
		int $duration_days,
		int $admin_id = 0,
		string $note = '',
		int $explicit_expires_at = 0,
		bool $stack = true
	): bool {
		if ( $user_id < 1 ) {
			return false;
		}
		$level = sanitize_key( $level );
		if ( '' === $level || strlen( $level ) > 32 ) {
			$level = 'vip-1m';
		}

		$now           = $this->now();
		$duration_days = max( 0, min( VipPlanParser::MAX_DURATION_DAYS, $duration_days ) );
		$clean_note    = self::clean_text( $note );
		$grant_id      = 'adm_' . dechex( $now ) . '_' . bin2hex( random_bytes( 3 ) );

		$mutated = $this->store->mutate_grants(
			$user_id,
			static function ( array $grants ) use ( $level, $duration_days, $explicit_expires_at, $stack, $now, $admin_id, $clean_note, $grant_id ): array {
				$entries  = $stack ? ( $grants[ $level ] ?? array() ) : array();
				$existing = $stack ? self::max_expiry( $entries ) : 0;

				if ( $explicit_expires_at > $now ) {
					$new_expiry    = min( $explicit_expires_at, $now + ( VipPlanParser::MAX_DURATION_DAYS * self::SECONDS_PER_DAY ) );
					$computed_days = max( 1, (int) ceil( ( $new_expiry - $now ) / self::SECONDS_PER_DAY ) );
				} elseif ( 0 === $duration_days || null === $existing ) {
					$new_expiry    = 0;
					$computed_days = 0;
				} else {
					$base          = max( $now, $existing );
					$new_expiry    = min( $base + ( $duration_days * self::SECONDS_PER_DAY ), $now + ( VipPlanParser::MAX_DURATION_DAYS * self::SECONDS_PER_DAY ) );
					$computed_days = $duration_days;
				}

				$entry = array(
					'order_id'   => 0,
					'source'     => 'admin',
					'grant_id'   => $grant_id,
					'granted_at' => $now,
					'expires_at' => $new_expiry,
				);
				if ( $computed_days > 0 ) {
					$entry['duration_days'] = $computed_days;
				}
				if ( $admin_id > 0 ) {
					$entry['granted_by'] = $admin_id;
				}
				if ( '' !== $clean_note ) {
					$entry['note'] = $clean_note;
				}

				$entries[]        = $entry;
				$grants[ $level ] = $entries;
				return $grants;
			}
		);

		if ( ! $mutated ) {
			return false;
		}

		$this->sync_user_role( $user_id );
		$this->record_history(
			$user_id,
			'admin_granted',
			$level,
			'' !== $clean_note ? $clean_note : ( 0 === $duration_days && 0 === $explicit_expires_at ? 'lifetime' : (string) $duration_days . 'd' ),
			$admin_id
		);
		do_action( 'music_wave_vip_admin_granted', $user_id, $level, $duration_days, $admin_id );

		return true;
	}

	/**
	 * Extend a user's current primary VIP level (or issue `$fallback_level` if none is active).
	 */
	public function extend_active_for_user(
		int $user_id,
		int $add_days,
		int $admin_id = 0,
		string $note = '',
		string $fallback_level = 'vip-1m'
	): bool {
		if ( $user_id < 1 || $add_days < 1 ) {
			return false;
		}
		$levels = $this->active_levels( $user_id );
		$target = ! empty( $levels ) ? (string) $levels[0] : sanitize_key( $fallback_level );
		if ( '' === $target ) {
			$target = 'vip-1m';
		}

		return $this->grant_manual( $user_id, $target, $add_days, $admin_id, $note );
	}

	/**
	 * Edit an individual grant row's expiration timestamp, lifetime flag, or note.
	 *
	 * `$grant_key` matches keys returned by `all_grants_for_user()`.
	 */
	public function update_grant_entry(
		int $user_id,
		string $grant_key,
		int $new_expires_at,
		bool $lifetime = false,
		string $note = '',
		int $admin_id = 0
	): bool {
		if ( $user_id < 1 || '' === $grant_key ) {
			return false;
		}
		$now           = $this->now();
		$clean_note    = self::clean_text( $note );
		$matched_level = '';
		$found         = false;

		$raw = get_user_meta( $user_id, self::GRANTS_META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$expected = VipGrantsStore::grants_signature( $raw );
		$stored   = $this->store->normalize_grants( $raw, 0 );

		foreach ( $stored as $level => &$entries ) {
			foreach ( $entries as $idx => &$entry ) {
				$row_key = self::make_grant_key( (string) $level, (int) $idx, $entry );
				if ( $row_key !== $grant_key ) {
					continue;
				}
				$found         = true;
				$matched_level = (string) $level;
				if ( $lifetime ) {
					$entry['expires_at'] = 0;
					unset( $entry['duration_days'] );
				} else {
					$clamped                = max( $now + 60, min( $new_expires_at, $now + ( VipPlanParser::MAX_DURATION_DAYS * self::SECONDS_PER_DAY ) ) );
					$entry['expires_at']    = $clamped;
					$granted_base           = isset( $entry['granted_at'] ) && (int) $entry['granted_at'] > 0 ? (int) $entry['granted_at'] : $now;
					$entry['granted_at']    = min( $granted_base, $clamped - 60 );
					$entry['duration_days'] = max( 1, (int) ceil( ( $clamped - (int) $entry['granted_at'] ) / self::SECONDS_PER_DAY ) );
				}
				if ( '' !== $clean_note ) {
					$entry['source'] = isset( $entry['source'] ) && 'admin' === (string) $entry['source'] ? 'admin' : 'order';
					$entry['note']   = $clean_note;
				}
				break 2;
			}
		}
		unset( $entries, $entry );

		if ( ! $found || ! $this->store->cas_write_grants( $user_id, $expected, $stored ) ) {
			return false;
		}

		$this->sync_user_role( $user_id );
		$this->record_history( $user_id, 'admin_updated', $matched_level, $clean_note, $admin_id );
		return true;
	}

	/**
	 * Remove a single grant entry identified by `$grant_key`.
	 */
	public function revoke_grant_entry( int $user_id, string $grant_key, int $admin_id = 0, string $note = '' ): bool {
		if ( $user_id < 1 || '' === $grant_key ) {
			return false;
		}

		$raw = get_user_meta( $user_id, self::GRANTS_META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$expected      = VipGrantsStore::grants_signature( $raw );
		$stored        = $this->store->normalize_grants( $raw, 0 );
		$next          = array();
		$removed       = false;
		$matched_level = '';

		foreach ( $stored as $level => $entries ) {
			$kept = array();
			foreach ( $entries as $idx => $entry ) {
				$row_key = self::make_grant_key( (string) $level, (int) $idx, $entry );
				if ( ! $removed && $row_key === $grant_key ) {
					$removed       = true;
					$matched_level = (string) $level;
					continue;
				}
				$kept[] = $entry;
			}
			if ( ! empty( $kept ) ) {
				$next[ $level ] = $kept;
			}
		}

		if ( ! $removed || ! $this->store->cas_write_grants( $user_id, $expected, $next ) ) {
			return false;
		}

		$this->sync_user_role( $user_id );
		$this->record_history( $user_id, 'admin_revoked', $matched_level, self::clean_text( $note ), $admin_id );
		do_action( 'music_wave_vip_admin_revoked', $user_id, $matched_level, $admin_id );
		return true;
	}

	/**
	 * Revoke all VIP grants for `$user_id` and sync their role + status meta immediately.
	 */
	public function revoke_all_for_user( int $user_id, int $admin_id = 0, string $note = '' ): int {
		if ( $user_id < 1 ) {
			return 0;
		}

		$count   = 0;
		$mutated = $this->store->mutate_grants(
			$user_id,
			static function ( array $grants ) use ( &$count ): array {
				$count = 0;
				foreach ( $grants as $entries ) {
					$count += count( $entries );
				}
				return array();
			}
		);

		// Also purge any expired leftovers in raw meta so admin grant table reflects clean slate.
		delete_user_meta( $user_id, self::GRANTS_META_KEY );
		$this->sync_user_role( $user_id );

		if ( $mutated || $count > 0 ) {
			$this->record_history( $user_id, 'admin_revoked_all', '', self::clean_text( $note ), $admin_id );
			do_action( 'music_wave_vip_admin_revoked', $user_id, '*', $admin_id );
		}

		return max( 1, $count );
	}

	/**
	 * Return flat list of all stored grants (active + expired) keyed by stable grant key for admin UI.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all_grants_for_user( int $user_id ): array {
		if ( $user_id < 1 ) {
			return array();
		}
		$stored = $this->store->all_stored_for_admin( $user_id );
		$flat   = array();

		foreach ( $stored as $level => $entries ) {
			foreach ( $entries as $idx => $entry ) {
				$key          = self::make_grant_key( (string) $level, (int) $idx, $entry );
				$expires_at   = isset( $entry['expires_at'] ) ? absint( $entry['expires_at'] ) : 0;
				$flat[ $key ] = array(
					'key'           => $key,
					'level'         => (string) $level,
					'order_id'      => isset( $entry['order_id'] ) ? absint( $entry['order_id'] ) : 0,
					'source'        => isset( $entry['source'] ) && 'admin' === (string) $entry['source'] ? 'admin' : 'order',
					'granted_at'    => isset( $entry['granted_at'] ) ? absint( $entry['granted_at'] ) : 0,
					'expires_at'    => $expires_at,
					'until'         => $expires_at,
					'duration_days' => isset( $entry['duration_days'] ) ? absint( $entry['duration_days'] ) : 0,
					'note'          => isset( $entry['note'] ) ? self::clean_text( (string) $entry['note'] ) : '',
					'granted_by'    => isset( $entry['granted_by'] ) ? absint( $entry['granted_by'] ) : 0,
				);
			}
		}

		return $flat;
	}

	/**
	 * Return the user's chronological VIP audit log (newest first, max 25 entries).
	 *
	 * @return array<int, array{at: int, event: string, level: string, note: string, actor_id: int}>
	 */
	public function user_history( int $user_id ): array {
		if ( $user_id < 1 ) {
			return array();
		}
		$raw = get_user_meta( $user_id, self::USER_HISTORY_META, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$clean = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) || empty( $row['event'] ) ) {
				continue;
			}
			$clean[] = array(
				'at'       => isset( $row['at'] ) ? absint( $row['at'] ) : 0,
				'event'    => sanitize_key( (string) $row['event'] ),
				'level'    => isset( $row['level'] ) ? self::clean_text( (string) $row['level'] ) : '',
				'note'     => isset( $row['note'] ) ? self::clean_text( (string) $row['note'] ) : '',
				'actor_id' => isset( $row['actor_id'] ) ? absint( $row['actor_id'] ) : 0,
			);
		}
		return $clean;
	}

	/**
	 * Append a bounded per-user VIP audit entry (keeps last 25 events).
	 */
	public function record_history( int $user_id, string $event, string $level = '', string $note = '', int $actor_id = 0 ): void {
		if ( $user_id < 1 ) {
			return;
		}
		$log = $this->user_history( $user_id );
		array_unshift(
			$log,
			array(
				'at'       => $this->now(),
				'event'    => sanitize_key( $event ),
				'level'    => self::clean_text( $level ),
				'note'     => self::clean_text( $note ),
				'actor_id' => max( 0, $actor_id ),
			)
		);
		update_user_meta( $user_id, self::USER_HISTORY_META, array_slice( $log, 0, 25 ) );
		$parsed_order_id = 1 === preg_match( '/^#(\d+)$/', trim( $note ), $m ) ? absint( $m[1] ) : 0;
		VipAuditLog::record(
			$event,
			$user_id,
			array(
				'level'    => $level,
				'order_id' => $parsed_order_id,
				'actor_id' => max( 0, $actor_id ),
			)
		);
	}

	/**
	 * Synchronize queryable `WP_User_Query` meta (`mw_vip_effective_until`, `mw_vip_ever_granted`)
	 * and the `mw_vip` role for `$user_id`.
	 */
	public function sync_user_role( int $user_id ): void {
		if ( $user_id < 1 ) {
			return;
		}
		$active_grants = $this->grants_for_user( $user_id );
		$this->sync_status_index( $user_id, $active_grants );

		if ( ! $this->promote_role ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! is_object( $user ) || ! method_exists( $user, 'add_role' ) || ! method_exists( $user, 'remove_role' ) ) {
			return;
		}
		if ( ! empty( $active_grants ) ) {
			$user->add_role( self::VIP_ROLE );
		} else {
			$user->remove_role( self::VIP_ROLE );
		}
	}

	/**
	 * Keep `mw_vip_effective_until` & `mw_vip_ever_granted` aligned with actual active grants.
	 *
	 * @param array<string, array<int, array<string, mixed>>>|null $active_grants Preloaded active grants if available.
	 */
	public function sync_status_index( int $user_id, ?array $active_grants = null ): void {
		if ( $user_id < 1 ) {
			return;
		}
		$grants    = null !== $active_grants ? $active_grants : $this->grants_for_user( $user_id );
		$max_until = 0;

		foreach ( $grants as $entries ) {
			$level_max = self::max_expiry( $entries );
			if ( null === $level_max ) {
				$max_until = self::LIFETIME_SENTINEL;
				break;
			}
			if ( $level_max > $max_until ) {
				$max_until = $level_max;
			}
		}

		update_user_meta( $user_id, self::STATUS_UNTIL_META, (string) $max_until );
		if ( $max_until > 0 ) {
			update_user_meta( $user_id, self::EVER_GRANTED_META, '1' );
		}
	}

	/**
	 * Compute the highest expiration timestamp across `$entries`, or `null` when a lifetime grant exists.
	 *
	 * @param array<int, array<string, mixed>> $entries Grant entries on one level.
	 */
	private static function max_expiry( array $entries ): ?int {
		if ( empty( $entries ) ) {
			return 0;
		}
		$max = 0;
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$exp = isset( $entry['expires_at'] ) ? absint( $entry['expires_at'] ) : 0;
			if ( 0 === $exp ) {
				return null;
			}
			if ( $exp > $max ) {
				$max = $exp;
			}
		}
		return $max;
	}

	/**
	 * Build a stable row key for an entry inside `$stored[ $level ][ $idx ]`.
	 *
	 * @param array<string, mixed> $entry Grant entry.
	 */
	private static function make_grant_key( string $level, int $idx, array $entry ): string {
		if ( ! empty( $entry['grant_id'] ) && is_string( $entry['grant_id'] ) ) {
			return $level . ':' . $entry['grant_id'];
		}
		$order_id   = isset( $entry['order_id'] ) ? absint( $entry['order_id'] ) : 0;
		$granted_at = isset( $entry['granted_at'] ) ? absint( $entry['granted_at'] ) : 0;
		return sprintf( '%s:ord_%d_%d_%d', $level, $order_id, $granted_at, $idx );
	}

	/** @return array<int, array{level: string, product_ids: array<int, int>, duration_days: int}> */
	public static function parse_plan_rows( string $rows ): array {
		return VipPlanParser::parse_plan_rows( $rows );
	}

	/** @return array<int, string> */
	public static function plan_rows_for_display( array $plans ): array {
		return VipPlanParser::plan_rows_for_display( $plans );
	}

	/** @return array<string, array{product_ids: array<int, int>, duration_days: int}> */
	public static function index_plans( array $rows ): array {
		return VipPlanParser::index_plans( $rows );
	}

	public static function schedule_expiry_checks(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		if ( false === wp_next_scheduled( self::EXPIRY_CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::EXPIRY_CRON );
		}
	}

	public static function clear_expiry_checks(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::EXPIRY_CRON );
		}
	}

	/** @return array<string, int> */
	public function handle_expiry_check(): array {
		return $this->expiry_manager()->handle_expiry_check();
	}

	/** @return array<string, int> */
	public function maybe_handle_expiry_check(): array {
		return $this->expiry_manager()->maybe_handle_expiry_check();
	}

	private function calculate_percent_used( int $granted_at, int $expires_at, int $now ): float {
		if ( $granted_at < 1 || $expires_at < 1 || $expires_at <= $granted_at ) {
			return 0.0;
		}
		$ratio = ( $now - $granted_at ) / ( $expires_at - $granted_at );
		return (float) max( 0.0, min( 1.0, $ratio ) );
	}

	private function infer_granted_at_from_order( int $order_id, int $expires_at, int $duration_days, int $now ): int {
		if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( is_object( $order ) && method_exists( $order, 'get_date_created' ) ) {
				$date = $order->get_date_created();
				if ( is_object( $date ) && method_exists( $date, 'getTimestamp' ) ) {
					return (int) $date->getTimestamp();
				}
			}
		}
		if ( $expires_at > 0 && $duration_days > 0 ) {
			return $expires_at - ( $duration_days * self::SECONDS_PER_DAY );
		}

		return $now;
	}

	/**
	 * @param array<int, string>               $grant_levels
	 * @param array<string, array<string,int>> $matching
	 * @param array<int, int>                  $quantities
	 */
	private function record_order_grant_meta( int $order_id, int $user_id, array $grant_levels, array $matching, array $quantities ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! ( $order instanceof \WC_Order ) ) {
			return;
		}

		$expiries   = array();
		$grants_now = $this->grants_for_user( $user_id );
		foreach ( $grant_levels as $lvl ) {
			$max_exp      = 0;
			$has_lifetime = false;
			if ( isset( $grants_now[ $lvl ] ) && is_array( $grants_now[ $lvl ] ) ) {
				foreach ( $grants_now[ $lvl ] as $entry ) {
					$exp = isset( $entry['expires_at'] ) ? (int) $entry['expires_at'] : 0;
					if ( 0 === $exp ) {
						$has_lifetime = true;
						break;
					}
					if ( $exp > $max_exp ) {
						$max_exp = $exp;
					}
				}
			}
			$expiries[ $lvl ] = $has_lifetime ? 0 : $max_exp;
		}

		$order->update_meta_data( '_mw_vip_granted_levels', $grant_levels );
		$order->update_meta_data( '_mw_vip_granted_expiries', $expiries );
		$order->update_meta_data( '_mw_vip_granted_at', (string) $this->now() );
		$order->update_meta_data( '_mw_vip_granted_quantities', $quantities );
		$order->save();

		$notes = array();
		foreach ( $matching as $lvl => $info ) {
			$notes[] = sprintf( '%s (x%d, %d روز)', $lvl, $info['total_qty'], $info['total_duration'] );
		}
		/* translators: %s: value 1. */
		$order->add_order_note( sprintf( __( 'اشتراک VIP اعطا شد: %s', 'music-wave-vip' ), implode( ', ', $notes ) ) );
	}

	private function audit( string $event, int $order_id, int $user_id ): void {
		do_action( 'music_wave_vip_grants_write_failed', $event, $user_id, $order_id );
	}

	private static function order_customer_id( int $order_id ): int {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}
		$order = wc_get_order( $order_id );
		return is_object( $order ) && method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;
	}

	/** @return array<int, int> */
	private static function order_product_quantities( int $order_id ): array {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return array();
		}
		$order = wc_get_order( $order_id );
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return array();
		}
		$items = $order->get_items();
		$map   = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			$product_id = (int) $item->get_product_id();
			$qty        = method_exists( $item, 'get_quantity' ) ? min( max( 1, (int) $item->get_quantity() ), 24 ) : 1;
			if ( $product_id > 0 ) {
				$map[ $product_id ] = isset( $map[ $product_id ] ) ? $map[ $product_id ] + $qty : $qty;
			}
			if ( method_exists( $item, 'get_variation_id' ) ) {
				$variation_id = (int) $item->get_variation_id();
				if ( $variation_id > 0 ) {
					$map[ $variation_id ] = isset( $map[ $variation_id ] ) ? $map[ $variation_id ] + $qty : $qty;
				}
			}
		}

		return $map;
	}

	private static function clean_text( string $text ): string {
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $text ) : trim( $text );
	}

	private function now(): int {
		return null !== $this->now_source ? (int) call_user_func( $this->now_source ) : time();
	}

	private function expiry_manager(): VipExpiryManager {
		if ( null === $this->expiry_manager ) {
			$this->expiry_manager = new VipExpiryManager( $this );
		}

		return $this->expiry_manager;
	}
}
