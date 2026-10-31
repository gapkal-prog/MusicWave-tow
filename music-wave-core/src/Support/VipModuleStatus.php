<?php
/**
 * Single source of truth for detecting whether the MusicWave VIP module is
 * active and capable of providing protected delivery / membership gating.
 *
 * Centralizes the `defined( 'MUSIC_WAVE_VIP_FILE' )` probe so Core, editor
 * assets, and front-end renderers can all ask "is VIP active?" from one
 * place instead of scattering the check across files.
 *
 * @package ManaCore\MusicWave\Core\Support
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @immutable
 */
final class VipModuleStatus {
	/**
	 * Whether the MusicWave VIP plugin is loaded and active.
	 *
	 * VIP sets `MUSIC_WAVE_VIP_FILE` in its bootstrap file. Core only probes
	 * for the constant; it never loads or instantiates VIP classes, keeping
	 * the dependency one-way (VIP → Core, never Core → VIP).
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return defined( 'MUSIC_WAVE_VIP_FILE' );
	}

	/**
	 * Whether VIP protected delivery (`vip:` / `local:` assets) is available.
	 *
	 * When VIP is inactive, `direct:` assets still work via Core's
	 * `DirectLinkProvider`, but `vip:`/`local:` assets are unsupported.
	 *
	 * @return bool
	 */
	public static function delivery_available(): bool {
		return self::is_active();
	}

	/**
	 * Whether membership gating (VIP plans, roles, sources) is available.
	 *
	 * @return bool
	 */
	public static function membership_available(): bool {
		return self::is_active();
	}
}
