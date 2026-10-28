<?php
/**
 * Pure parser for VIP plan rows.
 *
 * SRP: only parsing/formatting/indexing logic. No WordPress side effects,
 * no user meta, no time. Easy to unit-test.
 *
 * @package ManaCore\MusicWave\Vip\Plans
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Plans;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VipPlanParser {

	public const MAX_PLANS_PER_LEVEL = 25;
	public const MAX_PRODUCT_IDS     = 20;
	public const MAX_DURATION_DAYS   = 3650;

	/**
	 * Parse admin textarea into normalized plan rows.
	 *
	 * @return array<int, array{level: string, product_ids: array<int, int>, duration_days: int}>
	 */
	public static function parse_plan_rows( string $rows ): array {
		$plans = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $rows ) as $row ) {
			$row = trim( wp_unslash( (string) $row ) );
			if ( '' === $row || 0 === strpos( $row, '#' ) ) {
				continue;
			}
			if ( 1 !== preg_match( '/^([A-Za-z0-9_\-]{1,32})\s*:\s*([0-9,\s]+)(?:\s*:\s*(\d{1,4}))?$/', $row, $matches ) ) {
				continue;
			}
			$product_ids = array_values(
				array_slice(
					array_values(
						array_filter(
							array_unique( array_map( 'absint', explode( ',', $matches[2] ) ) )
						)
					),
					0,
					self::MAX_PRODUCT_IDS
				)
			);

			$plans[] = array(
				'level'         => sanitize_key( $matches[1] ),
				'product_ids'   => $product_ids,
				'duration_days' => isset( $matches[3] ) ? min( self::MAX_DURATION_DAYS, absint( $matches[3] ) ) : 0,
			);

			if ( count( $plans ) >= 10 ) {
				break;
			}
		}
		return $plans;
	}

	/**
	 * Render normalized plans back into textarea representation.
	 *
	 * @param array<int, array<string, mixed>> $plans
	 * @return array<int, string>
	 */
	public static function plan_rows_for_display( array $plans ): array {
		$rows = array();
		foreach ( $plans as $plan ) {
			if ( ! is_array( $plan ) || empty( $plan['level'] ) || empty( $plan['product_ids'] ) ) {
				continue;
			}
			$ids  = array_values( array_filter( array_map( 'absint', (array) $plan['product_ids'] ) ) );
			$days = isset( $plan['duration_days'] ) ? (int) $plan['duration_days'] : 0;
			if ( empty( $ids ) || '' === sanitize_key( (string) $plan['level'] ) ) {
				continue;
			}
			$rows[] = sprintf( '%s:%s%s', sanitize_key( (string) $plan['level'] ), implode( ',', $ids ), $days > 0 ? ':' . $days : '' );
		}
		return $rows;
	}

	/**
	 * Convert list-form rows into level-keyed engine form.
	 *
	 * @param array<int, mixed> $rows
	 * @return array<string, array{product_ids: array<int, int>, duration_days: int}>
	 */
	public static function index_plans( array $rows ): array {
		$plans = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['level'] ) ) {
				continue;
			}
			$level = sanitize_key( (string) $row['level'] );
			$ids   = isset( $row['product_ids'] ) ? array_values( array_filter( array_map( 'absint', (array) $row['product_ids'] ) ) ) : array();
			if ( '' === $level || empty( $ids ) ) {
				continue;
			}
			$plans[ $level ] = array(
				'product_ids'   => $ids,
				'duration_days' => isset( $row['duration_days'] ) ? (int) $row['duration_days'] : 0,
			);
		}
		return $plans;
	}

	/**
	 * @param array<string, array<string, mixed>> $plans
	 * @return array<string, array{product_ids: array<int, int>, duration_days: int}>
	 */
	public static function normalize_plans( array $plans ): array {
		$result = array();
		foreach ( $plans as $level => $plan ) {
			$level = sanitize_key( (string) $level );
			if ( '' === $level || ! is_array( $plan ) ) {
				continue;
			}
			$ids = isset( $plan['product_ids'] ) ? array_values( array_filter( array_map( 'absint', (array) $plan['product_ids'] ) ) ) : array();
			$ids = array_slice( $ids, 0, self::MAX_PRODUCT_IDS );
			if ( empty( $ids ) ) {
				continue;
			}
			$duration         = isset( $plan['duration_days'] ) ? (int) $plan['duration_days'] : 0;
			$result[ $level ] = array(
				'product_ids'   => $ids,
				'duration_days' => $duration >= 0 ? min( $duration, self::MAX_DURATION_DAYS ) : 0,
			);
		}
		return $result;
	}
}
