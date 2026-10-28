<?php
/**
 * Policy resolver for release access when no membership provider is registered.
 *
 * Centralizes `membership_absent_behavior` (`deny` | `allow` | `open_download`)
 * so `AccessPolicyEngine`, admin settings, and diagnostics share one source of
 * truth without duplicating absent-provider branches.
 *
 * @package ManaCore\MusicWave\Core\Access
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Access;

use ManaCore\MusicWave\Core\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MembershipAbsencePolicy {
	public const MODE_DENY          = 'deny';
	public const MODE_ALLOW         = 'allow';
	public const MODE_OPEN_DOWNLOAD = 'open_download';
	public const DEFAULT_MODE       = self::MODE_ALLOW;

	public const REASON_ABSENT        = 'membership_provider_absent';
	public const REASON_OPEN_DOWNLOAD = 'membership_absent_open_download';

	/**
	 * Allowed setting values for `membership_absent_behavior`.
	 *
	 * @return array<int, string>
	 */
	public static function allowed_modes(): array {
		return array(
			self::MODE_DENY,
			self::MODE_ALLOW,
			self::MODE_OPEN_DOWNLOAD,
		);
	}

	/**
	 * Normalize a candidate mode against the canonical allowlist.
	 */
	public static function sanitize_mode( string $mode, string $fallback = self::DEFAULT_MODE ): string {
		$normalized = sanitize_key( $mode );
		if ( in_array( $normalized, self::allowed_modes(), true ) ) {
			return $normalized;
		}

		return in_array( $fallback, self::allowed_modes(), true ) ? $fallback : self::DEFAULT_MODE;
	}

	/**
	 * Resolve the active absent-provider behavior from Settings and runtime filters.
	 */
	public static function current_mode(): string {
		$configured = class_exists( Settings::class )
			? self::sanitize_mode( (string) Settings::get( 'membership_absent_behavior' ), self::DEFAULT_MODE )
			: self::DEFAULT_MODE;

		/**
		 * Filter how membership-gated releases behave while no membership module
		 * (such as MusicWave VIP) is active.
		 *
		 * @param string $behavior 'deny', 'allow' (default), or 'open_download'.
		 */
		$filtered = apply_filters( 'music_wave_membership_absent_behavior', $configured );

		return is_scalar( $filtered )
			? self::sanitize_mode( (string) $filtered, $configured )
			: $configured;
	}

	/**
	 * Evaluate access for `membership` or `purchase_or_membership` when no
	 * membership provider is registered (`NullMembershipProvider`).
	 *
	 * Callers must already have checked single-release purchase ownership on
	 * `purchase_or_membership` before delegating here.
	 */
	public static function decide( string $release_mode, bool $has_products = false ): AccessDecision {
		$behavior = self::current_mode();

		if ( 'membership' === $release_mode ) {
			if ( self::MODE_OPEN_DOWNLOAD === $behavior ) {
				return AccessDecision::allow( self::REASON_OPEN_DOWNLOAD, $release_mode );
			}
			if ( self::MODE_ALLOW === $behavior ) {
				return AccessDecision::allow( self::REASON_ABSENT, $release_mode );
			}

			return AccessDecision::deny( self::REASON_ABSENT, $release_mode );
		}

		if ( 'purchase_or_membership' === $release_mode ) {
			if ( self::MODE_OPEN_DOWNLOAD === $behavior ) {
				return AccessDecision::allow( self::REASON_OPEN_DOWNLOAD, $release_mode );
			}
			if ( $has_products ) {
				return AccessDecision::deny( 'purchase_required', $release_mode );
			}
			if ( self::MODE_ALLOW === $behavior ) {
				return AccessDecision::allow( self::REASON_ABSENT, $release_mode );
			}

			return AccessDecision::deny( self::REASON_ABSENT, $release_mode );
		}

		return AccessDecision::deny( 'restricted', $release_mode );
	}

	/**
	 * Translated admin option labels keyed by mode slug.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			self::MODE_DENY          => __( 'مسدود کردن حالت‌های اشتراکی (ایمن‌ترین حالت برای محتوای پولی)', 'music-wave-core' ),
			self::MODE_ALLOW         => __( 'مجاز کردن حالت فقط اشتراک (پیش‌فرض سازگار — حفظ قفل خرید در حالت ترکیبی)', 'music-wave-core' ),
			self::MODE_OPEN_DOWNLOAD => __( 'باز شدن دانلود برای تمام حالت‌های اشتراکی و ترکیبی (بدون ویرایش انتشارها)', 'music-wave-core' ),
		);
	}

	/**
	 * Build a clear operational status summary explaining what is currently active.
	 *
	 * @return array{state: string, tone: string, message: string}
	 */
	public static function effective_status(): array {
		$mode       = self::current_mode();
		$vip_active = defined( 'MUSIC_WAVE_VIP_FILE' ) || defined( 'MUSIC_WAVE_VIP_VERSION' );
		$vip_opts   = $vip_active && function_exists( 'get_option' ) ? get_option( 'music_wave_vip_settings', array() ) : array();
		$vip_switch = $vip_active && ( ! is_array( $vip_opts ) || ! isset( $vip_opts['module_enabled'] ) || 'disabled' !== (string) $vip_opts['module_enabled'] );
		$has_ext    = function_exists( 'has_filter' ) && (bool) has_filter( 'music_wave_membership_provider' );

		if ( $vip_active && $vip_switch ) {
			return array(
				'state'   => 'vip_enforced',
				'tone'    => 'info',
				'message' => __( 'وضعیت مؤثر فعلی: افزونه MusicWave VIP فعال و روشن است؛ قواعد سطح عضویت VIP اجرا می‌شوند و تنظیم غیاب عضویت تا زمان غیرفعال‌سازی افزونه اشتراک وارد عمل نمی‌شود.', 'music-wave-core' ),
			);
		}

		if ( $vip_active && ! $vip_switch ) {
			return array(
				'state'   => 'vip_switch_disabled',
				'tone'    => 'warning',
				'message' => __( 'وضعیت مؤثر فعلی: افزونه MusicWave VIP نصب است اما کلید اصلی آن خاموش است؛ حالت‌های اشتراکی و ترکیبی توسط کلید اصلی VIP باز شده‌اند.', 'music-wave-core' ),
			);
		}

		if ( $has_ext ) {
			return array(
				'state'   => 'external_provider',
				'tone'    => 'info',
				'message' => __( 'وضعیت مؤثر فعلی: یک ارائه‌دهنده عضویت سفارشی فعال است و تصمیم‌گیری سطح دسترسی را مدیریت می‌کند.', 'music-wave-core' ),
			);
		}

		if ( self::MODE_OPEN_DOWNLOAD === $mode ) {
			return array(
				'state'   => 'absent_open_download',
				'tone'    => 'warning',
				'message' => __( 'وضعیت مؤثر فعلی: هیچ افزونه اشتراکی فعال نیست و حالت «باز شدن دانلود» روشن است؛ تمام انتشارهای «فقط اشتراک» و «خرید یا اشتراک» هم‌اکنون بدون نیاز به خرید باز هستند (انتشارهای «فقط خرید» و «محدود» همچنان بسته می‌مانند).', 'music-wave-core' ),
			);
		}

		if ( self::MODE_DENY === $mode ) {
			return array(
				'state'   => 'absent_deny',
				'tone'    => 'success',
				'message' => __( 'وضعیت مؤثر فعلی: هیچ افزونه اشتراکی فعال نیست و حالت «مسدود کردن» اجرا می‌شود؛ انتشارهای اشتراکی بسته هستند و انتشارهای ترکیبی فقط از مسیر خرید تکی قابل دریافت‌اند.', 'music-wave-core' ),
			);
		}

		return array(
			'state'   => 'absent_allow',
			'tone'    => 'info',
			'message' => __( 'وضعیت مؤثر فعلی: هیچ افزونه اشتراکی فعال نیست؛ انتشارهای «فقط اشتراک» باز هستند اما انتشارهای ترکیبی دارای محصول همچنان نیازمند خرید تکی باقی می‌مانند.', 'music-wave-core' ),
		);
	}
}
