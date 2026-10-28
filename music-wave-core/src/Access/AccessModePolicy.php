<?php
/**
 * Canonical single source of truth for MusicWave release access modes,
 * localized labels, default VIP level resolution, and invariant enforcement.
 *
 * Eliminates duplicate access-mode maps and divergent validation logic across
 * Settings, ReleaseMetaSchema, ReleaseMetaBox, BulkAccessManager, and
 * ReleaseReadiness (SOLID SRP + DRY + OCP).
 *
 * @package ManaCore\MusicWave\Core\Access
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Access;

use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use ManaCore\MusicWave\Core\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AccessModePolicy {
	public const DEFAULT_VIP_LEVEL = 'mw_vip';

	/**
	 * Ordered canonical access mode slugs.
	 *
	 * @return array<int, string>
	 */
	public static function keys(): array {
		return array(
			'public',
			'logged_in',
			'purchase',
			'membership',
			'purchase_or_membership',
			'restricted',
		);
	}

	/**
	 * Localized administrator labels keyed by canonical mode slug.
	 *
	 * @param bool $short Compact labels for list-table badges.
	 * @return array<string, string>
	 */
	public static function labels( bool $short = false ): array {
		if ( $short ) {
			return array(
				'public'                 => __( 'عمومی', 'music-wave-core' ),
				'logged_in'              => __( 'فقط اعضا', 'music-wave-core' ),
				'purchase'               => __( 'خرید تکی', 'music-wave-core' ),
				'membership'             => __( 'اشتراک VIP', 'music-wave-core' ),
				'purchase_or_membership' => __( 'خرید یا اشتراک', 'music-wave-core' ),
				'restricted'             => __( 'محدود', 'music-wave-core' ),
			);
		}

		return array(
			'public'                 => __( 'عمومی (آزاد برای همه)', 'music-wave-core' ),
			'logged_in'              => __( 'فقط کاربران ثبت‌نام‌شده (رایگان با ورود)', 'music-wave-core' ),
			'purchase'               => __( 'خرید تکی الزامی است (WooCommerce)', 'music-wave-core' ),
			'membership'             => __( 'اشتراک VIP الزامی است', 'music-wave-core' ),
			'purchase_or_membership' => __( 'خرید تکی یا اشتراک VIP (دوگانه)', 'music-wave-core' ),
			'restricted'             => __( 'محدود / غیرقابل دسترس', 'music-wave-core' ),
		);
	}

	/**
	 * Human-readable label for a single mode key.
	 */
	public static function label( string $mode, bool $short = false ): string {
		$labels = self::labels( $short );
		$clean  = sanitize_key( $mode );

		return isset( $labels[ $clean ] ) ? $labels[ $clean ] : $labels['restricted'];
	}

	/**
	 * Sanitize any raw access mode candidate against the canonical allow-list.
	 */
	public static function sanitize_mode( string $mode, string $fallback = 'public' ): string {
		$clean = sanitize_key( $mode );
		if ( in_array( $clean, self::keys(), true ) ) {
			return $clean;
		}

		return in_array( $fallback, self::keys(), true ) ? $fallback : 'public';
	}

	/**
	 * Parse and sanitize comma-separated or array-form membership level slugs.
	 *
	 * @param mixed $raw Comma-delimited string or array of level slugs.
	 * @return array<int, string>
	 */
	public static function sanitize_levels( $raw ): array {
		$parts = array();
		if ( is_string( $raw ) ) {
			$parts = preg_split( '/[\s,|]+/', $raw );
			$parts = is_array( $parts ) ? $parts : array();
		} elseif ( is_array( $raw ) ) {
			foreach ( $raw as $item ) {
				if ( is_scalar( $item ) ) {
					$parts[] = (string) $item;
				}
			}
		}

		$clean = array();
		foreach ( $parts as $part ) {
			$slug = sanitize_key( $part );
			if ( '' !== $slug && ! in_array( $slug, $clean, true ) ) {
				$clean[] = $slug;
			}
		}

		return $clean;
	}

	/**
	 * Whether an access mode involves VIP/membership entitlement checks.
	 */
	public static function uses_membership( string $mode ): bool {
		return in_array( sanitize_key( $mode ), array( 'membership', 'purchase_or_membership' ), true );
	}

	/**
	 * Whether an access mode involves WooCommerce single/bundle product ownership.
	 */
	public static function uses_purchase( string $mode ): bool {
		return in_array( sanitize_key( $mode ), array( 'purchase', 'purchase_or_membership' ), true );
	}

	/**
	 * Resolve default membership level slugs for new or bulk-updated releases.
	 *
	 * Reads explicit administrator defaults from `Settings`, falls back to the
	 * `music_wave_default_membership_level` filter, and always guarantees at least
	 * `mw_vip` so selecting `membership` or `purchase_or_membership` never breaks.
	 *
	 * @return array<int, string>
	 */
	public static function default_membership_levels(): array {
		$configured = self::sanitize_levels( Settings::get( 'default_membership_levels' ) );
		if ( ! empty( $configured ) ) {
			return $configured;
		}

		$filtered = sanitize_key( (string) apply_filters( 'music_wave_default_membership_level', self::DEFAULT_VIP_LEVEL ) );
		if ( '' !== $filtered ) {
			return array( $filtered );
		}

		return array( self::DEFAULT_VIP_LEVEL );
	}

	/**
	 * Ensure release access invariants hold on save/bulk operations without
	 * destructively erasing `purchase_or_membership` when one side is still being configured.
	 *
	 * @param ReleaseRepository  $repository      Release repository.
	 * @param int                $release_id      Target release ID.
	 * @param string             $requested_mode  Desired target mode.
	 * @param array<int, string> $override_levels Explicit membership levels to apply if non-empty.
	 * @param array<int, int>    $override_pids   Explicit WooCommerce product IDs to merge if non-empty.
	 * @param bool               $strict_purchase Skip/restrict pure `purchase` when no product exists.
	 * @return array{mode: string|null, notice: string}
	 */
	public static function resolve_for_release(
		ReleaseRepository $repository,
		int $release_id,
		string $requested_mode,
		array $override_levels = array(),
		array $override_pids = array(),
		bool $strict_purchase = true
	): array {
		$mode = self::sanitize_mode( $requested_mode, 'restricted' );

		if ( ! empty( $override_pids ) ) {
			$existing_pids = $repository->product_ids( $release_id );
			$merged_pids   = array_values( array_unique( array_filter( array_map( 'absint', array_merge( $existing_pids, $override_pids ) ) ) ) );
			$repository->update( $release_id, 'mw_product_ids', $merged_pids );
		}

		$product_ids    = $repository->product_ids( $release_id );
		$has_products   = ! empty( $product_ids );
		$explicit_lvls  = self::sanitize_levels( $override_levels );
		$current_levels = self::sanitize_levels( $repository->get( $release_id, 'mw_membership_levels' ) );

		if ( ! empty( $explicit_lvls ) ) {
			$repository->update( $release_id, 'mw_membership_levels', $explicit_lvls );
			$current_levels = $explicit_lvls;
		} elseif ( empty( $current_levels ) && self::uses_membership( $mode ) ) {
			$defaults = self::default_membership_levels();
			$repository->update( $release_id, 'mw_membership_levels', $defaults );
			$current_levels = $defaults;
		}

		$has_membership = ! empty( $current_levels );

		if ( 'purchase' === $mode && ! $has_products ) {
			if ( $strict_purchase ) {
				return array(
					'mode'   => null,
					'notice' => __( 'MusicWave دسترسی را به «محدود» تغییر داد، زیرا حالت «خرید تکی» نیازمند انتخاب یا ساخت حداقل یک محصول WooCommerce است.', 'music-wave-core' ),
				);
			}
		}

		if ( 'membership' === $mode && ! $has_membership ) {
			return array(
				'mode'   => null,
				'notice' => __( 'MusicWave دسترسی را به «محدود» تغییر داد، زیرا هیچ کلید سطح عضویتی تنظیم نشده است.', 'music-wave-core' ),
			);
		}

		if ( 'purchase_or_membership' === $mode ) {
			$notice = '';
			if ( ! $has_products ) {
				$notice = __( 'حالت «خرید تکی یا اشتراک VIP» ذخیره شد و از هم‌اکنون با اشتراک VIP قابل بازگشایی است. برای نمایش دکمهٔ خرید تکی، یک محصول WooCommerce نیز پیوند دهید یا گزینهٔ ساخت سریع محصول را پر کنید.', 'music-wave-core' );
			}
			return array(
				'mode'   => 'purchase_or_membership',
				'notice' => $notice,
			);
		}

		return array(
			'mode'   => $mode,
			'notice' => '',
		);
	}
}
