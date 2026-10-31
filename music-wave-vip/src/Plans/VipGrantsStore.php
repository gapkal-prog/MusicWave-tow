<?php
/**
 * Grants persistence with compare-and-set safety.
 *
 * SRP: only storage concerns — read, normalize/prune expired rows, CAS write,
 * and bounded optimistic retry under concurrent order hooks & admin actions.
 *
 * @package ManaCore\MusicWave\Vip\Plans
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Plans;

use ManaCore\MusicWave\Vip\VipPlans;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VipGrantsStore {
	private const MAX_WRITE_ATTEMPTS = 5;

	/** @var callable|null */
	private $now_source;

	public function __construct( ?callable $now_source = null ) {
		$this->now_source = $now_source;
	}

	/**
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function grants_for_user( int $user_id ): array {
		$raw = get_user_meta( $user_id, VipPlans::GRANTS_META_KEY, true );
		if ( '' !== $raw && false !== $raw && ! is_array( $raw ) ) {
			$this->remove_meta( $user_id );
			return array();
		}

		$stored     = is_array( $raw ) ? $raw : array();
		$normalized = $this->normalize_grants( $stored, $this->now() );
		if ( ! hash_equals( self::grants_signature( $normalized ), self::grants_signature( $stored ) ) ) {
			$this->cas_write_grants( $user_id, self::grants_signature( $stored ), $normalized );
		}

		return $normalized;
	}

	/**
	 * Read all unpruned normalized grants (including expired entries) for admin inspection.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function all_stored_for_admin( int $user_id ): array {
		$raw = get_user_meta( $user_id, VipPlans::GRANTS_META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		return $this->normalize_grants( $raw, 0 );
	}

	/**
	 * @param callable(array<string, array<int, array<string, mixed>>>):?array<string, array<int, array<string, mixed>>> $mutate
	 */
	public function mutate_grants( int $user_id, callable $mutate ): bool {
		for ( $attempt = 0; $attempt < self::MAX_WRITE_ATTEMPTS; ++$attempt ) {
			$stored = get_user_meta( $user_id, VipPlans::GRANTS_META_KEY, true );
			if ( '' !== $stored && false !== $stored && ! is_array( $stored ) ) {
				$this->remove_meta( $user_id );
				$stored = array();
			}
			$stored   = is_array( $stored ) ? $stored : array();
			$expected = self::grants_signature( $stored );
			$current  = $this->normalize_grants( $stored, $this->now() );

			$next = $mutate( $current );
			if ( null === $next ) {
				return true;
			}
			if ( $this->cas_write_grants( $user_id, $expected, $next ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, array<int, array<string, mixed>>> $grants
	 */
	public function cas_write_grants( int $user_id, string $expected, array $grants ): bool {
		$stored = get_user_meta( $user_id, VipPlans::GRANTS_META_KEY, true );
		if ( '' !== $stored && false !== $stored && ! is_array( $stored ) ) {
			$stored = array();
		}
		$stored = is_array( $stored ) ? $stored : array();
		if ( ! hash_equals( $expected, self::grants_signature( $stored ) ) ) {
			return false;
		}
		if ( empty( $grants ) ) {
			return $this->remove_meta( $user_id );
		}
		if ( empty( $stored ) && function_exists( 'add_user_meta' ) ) {
			return false !== add_user_meta( $user_id, VipPlans::GRANTS_META_KEY, $grants, true );
		}

		return false !== update_user_meta( $user_id, VipPlans::GRANTS_META_KEY, $grants );
	}

	/**
	 * @param mixed $stored
	 */
	public static function grants_signature( $stored ): string {
		return function_exists( 'wp_json_encode' )
			? (string) wp_json_encode( $stored, JSON_UNESCAPED_SLASHES )
			: (string) json_encode( $stored, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * Normalize stored grants (`$now = 0` preserves expired rows for admin history).
	 *
	 * @param array<string, mixed> $stored
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function normalize_grants( array $stored, int $now ): array {
		$grants     = array();
		$ref_now    = $now > 0 ? $now : $this->now();
		$max_future = $ref_now + ( VipPlanParser::MAX_DURATION_DAYS * 86400 );

		foreach ( $stored as $level => $entries ) {
			$level = sanitize_key( (string) $level );
			if ( '' === $level || ! is_array( $entries ) ) {
				continue;
			}
			if ( strlen( $level ) > 32 || ! preg_match( '/^[a-z0-9_\-]+$/', $level ) ) {
				continue;
			}

			$kept = array();
			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$source   = isset( $entry['source'] ) && 'admin' === (string) $entry['source'] ? 'admin' : 'order';
				$order_id = isset( $entry['order_id'] ) ? absint( $entry['order_id'] ) : 0;
				if ( 'admin' !== $source && $order_id < 1 ) {
					continue;
				}

				$expires_at = isset( $entry['expires_at'] ) ? absint( $entry['expires_at'] ) : 0;
				if ( $now > 0 && $expires_at > 0 && $expires_at <= $now ) {
					continue;
				}
				if ( $expires_at > $max_future ) {
					$expires_at = $max_future;
				}

				$granted_at        = isset( $entry['granted_at'] ) ? absint( $entry['granted_at'] ) : 0;
					$duration_days = isset( $entry['duration_days'] ) ? absint( $entry['duration_days'] ) : 0;
					$starts_at     = isset( $entry['starts_at'] ) ? absint( $entry['starts_at'] ) : 0;
				if ( $duration_days > VipPlanParser::MAX_DURATION_DAYS ) {
					$duration_days = VipPlanParser::MAX_DURATION_DAYS;
				}
				if ( $starts_at > 0 ) {
					$starts_at = min( $starts_at, $max_future );
				}
					// Future timestamps are legitimate for reserved terms, so the
					// clock-skew guard allows the same horizon as `expires_at`.
				if ( $granted_at > $max_future ) {
					$granted_at = $max_future;
				}
				if ( $granted_at < 1 && $starts_at > 0 ) {
					$granted_at = $starts_at;
				}
				if ( $granted_at < 1 && $expires_at > 0 && $duration_days > 0 ) {
					$granted_at = max( 1, $expires_at - ( $duration_days * 86400 ) );
				}
				if ( $expires_at > 0 && $granted_at > 0 && $expires_at <= $granted_at ) {
					continue;
				}
				if ( $starts_at > 0 && $expires_at > 0 && $starts_at >= $expires_at ) {
					continue;
				}

					$normalized = array(
						'order_id'   => $order_id,
						'expires_at' => $expires_at,
					);
					if ( $granted_at > 0 ) {
						$normalized['granted_at'] = $granted_at;
					}
					if ( $starts_at > 0 ) {
						$normalized['starts_at'] = $starts_at;
					}
					if ( $duration_days > 0 ) {
						$normalized['duration_days'] = $duration_days;
					}
					if ( 'admin' === $source ) {
						$normalized['source'] = 'admin';
						if ( ! empty( $entry['grant_id'] ) && is_string( $entry['grant_id'] ) ) {
							$normalized['grant_id'] = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $entry['grant_id'] ) ?? '';
						}
						if ( ! empty( $entry['granted_by'] ) ) {
							$normalized['granted_by'] = absint( $entry['granted_by'] );
						}
						if ( ! empty( $entry['note'] ) && is_string( $entry['note'] ) ) {
							$normalized['note'] = function_exists( 'sanitize_text_field' )
							? sanitize_text_field( $entry['note'] )
							: trim( $entry['note'] );
						}
					}
					$kept[] = $normalized;
			}

			if ( count( $kept ) > VipPlanParser::MAX_PLANS_PER_LEVEL ) {
				usort(
					$kept,
					static function ( array $a, array $b ): int {
						$ea = $a['expires_at'];
						$eb = $b['expires_at'];
						if ( 0 === $ea ) {
							return -1;
						}
						if ( 0 === $eb ) {
							return 1;
						}
						return $eb <=> $ea;
					}
				);
				$kept = array_values( array_slice( $kept, 0, VipPlanParser::MAX_PLANS_PER_LEVEL ) );
			}

			if ( ! empty( $kept ) ) {
				$grants[ $level ] = $kept;
			}
		}

		return $grants;
	}

	private function remove_meta( int $user_id ): bool {
		if ( function_exists( 'delete_user_meta' ) ) {
			return delete_user_meta( $user_id, VipPlans::GRANTS_META_KEY );
		}
		if ( isset( $GLOBALS['mw_test_user_meta'][ $user_id ][ VipPlans::GRANTS_META_KEY ] ) ) {
			unset( $GLOBALS['mw_test_user_meta'][ $user_id ][ VipPlans::GRANTS_META_KEY ] );
		}

		return true;
	}

	private function now(): int {
		return null !== $this->now_source ? (int) call_user_func( $this->now_source ) : time();
	}
}
