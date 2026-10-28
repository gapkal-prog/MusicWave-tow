<?php
/**
 * Authorization boundary for assigning controlled `direct:` HTTPS download links.
 *
 * Defaults to the dedicated `manage_mw_protected_assets` capability (held by
 * administrators and release editors, denied to authors/contributors/subscribers)
 * and allows multi-author sites to tighten it to `manage_options` via filter.
 *
 * @package ManaCore\MusicWave\Core\Downloads
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DirectLinkPermission {
	public const DEFAULT_CAPABILITY = 'manage_mw_protected_assets';

	/**
	 * Required capability slug for adding or modifying `direct:` URLs.
	 */
	public static function required_capability(): string {
		/**
		 * Filter the capability required to assign or change `direct:` HTTPS asset URLs.
		 *
		 * @param string $capability Default `'manage_mw_protected_assets'`.
		 */
		$filtered = apply_filters( 'music_wave_direct_link_capability', self::DEFAULT_CAPABILITY );
		$cap      = is_scalar( $filtered ) ? sanitize_key( (string) $filtered ) : '';

		return '' !== $cap ? $cap : self::DEFAULT_CAPABILITY;
	}

	/**
	 * Whether the current user may assign or modify `direct:` links on a release.
	 */
	public static function can_manage( int $release_id = 0 ): bool {
		$capability = self::required_capability();
		$allowed    = current_user_can( $capability );
		if ( $allowed && $release_id > 0 ) {
			$allowed = current_user_can( 'edit_post', $release_id );
		}

		/**
		 * Filter whether the current user may assign `direct:` HTTPS download links.
		 *
		 * @param bool $allowed    Evaluated permission result.
		 * @param int  $release_id Target release post ID (0 when generic).
		 */
		return (bool) apply_filters( 'music_wave_can_manage_direct_links', $allowed, $release_id );
	}
}
