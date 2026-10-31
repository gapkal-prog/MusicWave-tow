<?php
/**
 * Single source of truth for the "no membership provider" access behavior.
 *
 * Replaces scattered `'allow'` / `'deny'` literals across Settings, Plugin,
 * AccessPolicyEngine and admin UI. Adds the `open_download` mode: membership
 * releases open AND hybrid `purchase_or_membership` releases open on the
 * membership side (purchase-only behavior is untouched).
 *
 * @package ManaCore\MusicWave\Core\Access
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Access;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @immutable
 */
final class MembershipAbsencePolicy {
	public const MODE_DENY          = 'deny';
	public const MODE_ALLOW         = 'allow';
	public const MODE_OPEN_DOWNLOAD = 'open_download';

	/** Filter name used by Plugin.php to read the configured behavior. */
	public const FILTER_NAME = 'music_wave_membership_absent_behavior';

	/** Reason key emitted when open_download grants access without a provider. */
	public const REASON_OPEN = 'membership_provider_absent_open';

	/** @return array<int, string> All canonical modes. */
	public static function modes(): array {
		return array( self::MODE_DENY, self::MODE_ALLOW, self::MODE_OPEN_DOWNLOAD );
	}

	/**
	 * Resolve the effective mode: filter takes priority, then saved setting,
	 * then `allow` default.
	 */
	public static function mode(): string {
		$filtered = sanitize_key( (string) apply_filters( self::FILTER_NAME, '' ) );
		if ( in_array( $filtered, self::modes(), true ) ) {
			return $filtered;
		}

		$setting = \ManaCore\MusicWave\Core\Support\Settings::get( 'membership_absent_behavior' );
		if ( in_array( $setting, self::modes(), true ) ) {
			return $setting;
		}

		return self::MODE_ALLOW;
	}

	/**
	 * Administrator-facing choices: key => [label, description].
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function choices(): array {
		return array(
			self::MODE_DENY          => array(
				__( 'قفل کامل', 'music-wave-core' ),
				__( 'تا فعال‌شدن یک ماژول عضویت (مانند MusicWave VIP)، انتشارهای عضویتی مسدود می‌مانند.', 'music-wave-core' ),
			),
			self::MODE_ALLOW         => array(
				__( 'مشاهدهٔ آزاد انتشارهای عضویتی', 'music-wave-core' ),
				__( 'انتشارهای صرفاً عضویتی باز می‌شوند؛ «خرید یا عضویت» با محصول نگاشت‌شده همچنان خرید می‌خواهد. فایل‌های حفاظت‌شدهٔ VIP بدون ماژول تحویل نمی‌شوند.', 'music-wave-core' ),
			),
			self::MODE_OPEN_DOWNLOAD => array(
				__( 'دسترسی کامل و لینک دانلود', 'music-wave-core' ),
				__( 'همهٔ انتشارهای عضویتی و «خرید یا عضویت» برای همه باز می‌شوند. فایل‌های با لینک مستقیم پخش و دانلود می‌شوند. فایل‌های مخزن حفاظت‌شدهٔ VIP همچنان به ماژول VIP نیاز دارند.', 'music-wave-core' ),
			),
		);
	}

	/**
	 * Sanitize a raw mode candidate; invalid values fall back to `allow`.
	 */
	public static function sanitize( string $value ): string {
		$clean = sanitize_key( $value );
		return in_array( $clean, self::modes(), true ) ? $clean : self::MODE_ALLOW;
	}

	/**
	 * Whether this mode opens membership-gated releases when no provider exists.
	 */
	public static function opens_membership( string $mode ): bool {
		return in_array( sanitize_key( $mode ), array( self::MODE_ALLOW, self::MODE_OPEN_DOWNLOAD ), true );
	}

	/**
	 * Whether this mode opens hybrid `purchase_or_membership` releases on the
	 * membership side when no provider exists. `allow` keeps the paid-product
	 * purchase requirement; only `open_download` opens it.
	 */
	public static function opens_hybrid( string $mode ): bool {
		return self::MODE_OPEN_DOWNLOAD === sanitize_key( $mode );
	}
}
