<?php
/**
 * Introspects active file delivery capabilities across Core and VIP/extensions.
 *
 * Replaces scattered `has_filter( 'music_wave_download_provider' )` checks so
 * diagnostics, Site Health, admin settings, and frontend download panels report
 * accurate security postures (`vip_local`, `vip_remote`, `external_provider`,
 * or `direct_only`).
 *
 * @package ManaCore\MusicWave\Core\Support
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Support;

use ManaCore\MusicWave\Core\Downloads\DirectLinkAsset;
use ManaCore\MusicWave\Core\Downloads\DownloadProvider;
use ManaCore\MusicWave\Core\Downloads\NullDownloadProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DeliveryCapabilities {
	public const MODE_VIP_LOCAL         = 'vip_local';
	public const MODE_VIP_REMOTE        = 'vip_remote';
	public const MODE_EXTERNAL_PROVIDER = 'external_provider';
	public const MODE_DIRECT_ONLY       = 'direct_only';

	/**
	 * Whether a protected external/VIP provider (e.g. `ProtectedFileProvider` or
	 * `RemoteRedirectProvider`) is registered and active on `music_wave_download_provider`.
	 */
	public static function has_protected_provider( ?DownloadProvider $provider = null ): bool {
		$caps = self::describe( $provider );

		return $caps['has_protected_provider'];
	}

	/**
	 * Describe the active site-wide delivery architecture.
	 *
	 * @return array{
	 *   mode: string,
	 *   has_protected_provider: bool,
	 *   has_external_provider: bool,
	 *   supports_protected_storage: bool,
	 *   supports_direct_links: bool,
	 *   is_vip_provider: bool,
	 *   label: string,
	 *   summary: string,
	 *   detail: string,
	 *   badge_label: string,
	 *   security_notice: string,
	 *   diagnostics_summary: string
	 * }
	 */
	public static function describe( ?DownloadProvider $provider = null ): array {
		$resolved = $provider;
		if ( null === $resolved && function_exists( 'apply_filters' ) ) {
			$candidate = apply_filters( 'music_wave_download_provider', new NullDownloadProvider() );
			$resolved  = $candidate instanceof DownloadProvider ? $candidate : new NullDownloadProvider();
		}
		if ( null === $resolved ) {
			$resolved = new NullDownloadProvider();
		}

		$class_name = get_class( $resolved );
		if ( 'ManaCore\\MusicWave\\Vip\\ProtectedFileProvider' === $class_name ) {
			$label  = __( 'پوشه خصوصی MusicWave VIP + لینک مستقیم HTTPS', 'music-wave-core' );
			$detail = __( 'تحویل پوشه خصوصی MusicWave VIP (همراه با پشتیبانی لینک مستقیم HTTPS در هسته) فعال است.', 'music-wave-core' );
			return array(
				'mode'                       => self::MODE_VIP_LOCAL,
				'has_protected_provider'     => true,
				'has_external_provider'      => true,
				'supports_protected_storage' => true,
				'supports_direct_links'      => true,
				'is_vip_provider'            => true,
				'label'                      => $label,
				'summary'                    => $label,
				'detail'                     => $detail,
				'badge_label'                => __( 'VIP حفاظت‌شده', 'music-wave-core' ),
				'security_notice'            => __( 'حفاظت‌شده توسط MusicWave VIP: فایل‌ها از پوشه‌ای حفاظت‌شده و از طریق پیوندهای امضاشده و زمان‌دار تحویل داده می‌شوند. نشانی مستقیم فایل هرگز نمایش داده نمی‌شود.', 'music-wave-core' ),
				'diagnostics_summary'        => $detail,
			);
		}

		if ( 'ManaCore\\MusicWave\\Vip\\RemoteRedirectProvider' === $class_name ) {
			$label  = __( 'تغییرمسیر راه‌دور امضاشده HMAC (MusicWave VIP) + لینک مستقیم HTTPS', 'music-wave-core' );
			$detail = __( 'تحویل راه‌دور امضاشده HMAC در MusicWave VIP (همراه با پشتیبانی لینک مستقیم HTTPS در هسته) فعال است.', 'music-wave-core' );
			return array(
				'mode'                       => self::MODE_VIP_REMOTE,
				'has_protected_provider'     => true,
				'has_external_provider'      => true,
				'supports_protected_storage' => true,
				'supports_direct_links'      => true,
				'is_vip_provider'            => true,
				'label'                      => $label,
				'summary'                    => $label,
				'detail'                     => $detail,
				'badge_label'                => __( 'VIP امضاشده', 'music-wave-core' ),
				'security_notice'            => __( 'حفاظت‌شده توسط MusicWave VIP: فایل‌ها با پیوند راه‌دور امضاشده HMAC و کوتاه‌مدت تحویل داده می‌شوند.', 'music-wave-core' ),
				'diagnostics_summary'        => $detail,
			);
		}

		if ( ! ( $resolved instanceof NullDownloadProvider ) || ( function_exists( 'has_filter' ) && (bool) has_filter( 'music_wave_download_provider' ) ) ) {
			$label  = __( 'ارائه‌دهنده تحویل امن سفارشی + لینک مستقیم HTTPS', 'music-wave-core' );
			$detail = __( 'یک ارائه‌دهنده تحویل امن سفارشی در کنار لینک مستقیم HTTPS هسته فعال است.', 'music-wave-core' );
			return array(
				'mode'                       => self::MODE_EXTERNAL_PROVIDER,
				'has_protected_provider'     => true,
				'has_external_provider'      => true,
				'supports_protected_storage' => true,
				'supports_direct_links'      => true,
				'is_vip_provider'            => false,
				'label'                      => $label,
				'summary'                    => $label,
				'detail'                     => $detail,
				'badge_label'                => __( 'تحویل امضاشده', 'music-wave-core' ),
				'security_notice'            => __( 'از طریق ارائه‌دهنده تحویل امن و بلیت‌های یک‌بارمصرف تحویل داده می‌شود. نشانی مستقیم فایل در صفحه نمایش داده نمی‌شود.', 'music-wave-core' ),
				'diagnostics_summary'        => $detail,
			);
		}

		$label  = __( 'تحویل لینک مستقیم HTTPS کنترل‌شده در هسته (بدون مخزن خصوصی VIP)', 'music-wave-core' );
		$detail = __( 'تحویل لینک مستقیم HTTPS در هسته فعال است؛ برای فایل‌های خصوصی (`vip:` / `local:`) افزونه MusicWave VIP مورد نیاز است.', 'music-wave-core' );
		return array(
			'mode'                       => self::MODE_DIRECT_ONLY,
			'has_protected_provider'     => false,
			'has_external_provider'      => false,
			'supports_protected_storage' => false,
			'supports_direct_links'      => true,
			'is_vip_provider'            => false,
			'label'                      => $label,
			'summary'                    => $label,
			'detail'                     => $detail,
			'badge_label'                => __( 'تحویل کنترل‌شده', 'music-wave-core' ),
			'security_notice'            => __( 'دسترسی و سهمیه توسط MusicWave بررسی و بلیت یک‌بارمصرف صادر می‌شود؛ برای نگهداری فایل در مخزن خصوصی خارج از وب‌روت، افزونه MusicWave VIP را فعال کنید.', 'music-wave-core' ),
			'diagnostics_summary'        => $detail,
		);
	}

	/**
	 * Resolve accurate badge and security notice for a specific release's grouped files.
	 *
	 * Prevents displaying a misleading "VIP Protected" badge on releases whose
	 * downloadable qualities are exclusively `direct:` HTTPS redirects.
	 *
	 * @param array<string, array<string, mixed>> $grouped_files Grouped download files from `ReleaseDownloadCatalog::group_files()`.
	 * @param array<int, array<string, mixed>>    $raw_assets    Optional raw asset rows from `ReleaseDownloadCatalog::assets()`.
	 * @return array{badge_label: string, message: string, is_vip_protected: bool}
	 */
	public static function describe_release_panel( array $grouped_files = array(), array $raw_assets = array() ): array {
		$caps          = self::describe();
		$has_direct    = false;
		$has_protected = false;

		foreach ( $raw_assets as $asset ) {
			$id = isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '';
			if ( '' === $id ) {
				continue;
			}
			if ( DirectLinkAsset::has_direct_scheme( $id ) ) {
				$has_direct = true;
			} else {
				$has_protected = true;
			}
		}

		if ( $has_direct && ! $has_protected ) {
			return array(
				'badge_label'      => __( 'تحویل کنترل‌شده', 'music-wave-core' ),
				'message'          => __( 'دسترسی توسط MusicWave بررسی شده و پس از صدور بلیت یک‌بارمصرف به میزبان امن فایل هدایت می‌شوید.', 'music-wave-core' ),
				'is_vip_protected' => false,
			);
		}

		if ( $has_direct && $has_protected && $caps['has_external_provider'] ) {
			return array(
				'badge_label'      => $caps['badge_label'],
				'message'          => __( 'کیفیت‌های این انتشار از طریق بلیت یک‌بارمصرف (ترکیب مخزن حفاظت‌شده و تغییرمسیر امن HTTPS) تحویل داده می‌شوند.', 'music-wave-core' ),
				'is_vip_protected' => $caps['is_vip_provider'],
			);
		}

		unset( $grouped_files );

		return array(
			'badge_label'      => $caps['has_external_provider'] ? $caps['badge_label'] : __( 'تحویل کنترل‌شده', 'music-wave-core' ),
			'message'          => $caps['security_notice'],
			'is_vip_protected' => $caps['is_vip_provider'],
		);
	}
}
