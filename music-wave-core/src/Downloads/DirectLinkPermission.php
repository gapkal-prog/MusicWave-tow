<?php
/**
 * Capability gate for assigning direct-link assets to releases.
 *
 * Default: only administrators (`manage_options`). Extensible via the
 * `music_wave_can_assign_direct_link` filter without adding a new DB capability.
 *
 * @package ManaCore\MusicWave\Core\Downloads
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @immutable
 */
final class DirectLinkPermission {
	/**
	 * Whether the current user may assign direct (external HTTPS) asset URLs.
	 *
	 * @return bool
	 */
	public static function can_assign(): bool {
		/**
		 * Filter whether the current user can assign direct-link asset URLs.
		 *
		 * @param bool $can_assign Current decision (default: manage_options).
		 */
		return (bool) apply_filters(
			'music_wave_can_assign_direct_link',
			current_user_can( 'manage_options' )
		);
	}
}
