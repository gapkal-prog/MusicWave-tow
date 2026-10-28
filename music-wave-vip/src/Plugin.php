<?php
/**
 * Whether the active MusicWave Core build satisfies VIP's floor.
 *
 * Missing Core and an unmet version floor are reported through the same
 * notice, so the bootstrap only needs one gate before wiring any hooks.
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	// Lowest Core schema/feature level VIP's provider and access-decision
	// integrations assume, per release-manifest.json (review finding A3).
	public const MIN_CORE_VERSION = '0.9.0';

	public static function core_meets_minimum(): bool {
		return defined( 'MUSIC_WAVE_CORE_VERSION' )
			&& version_compare( (string) MUSIC_WAVE_CORE_VERSION, self::MIN_CORE_VERSION, '>=' );
	}

	public static function core_notice(): void {
		if ( self::core_meets_minimum() ) {
			return;
		}
		if ( ! defined( 'MUSIC_WAVE_CORE_VERSION' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'MusicWave VIP به افزونهٔ MusicWave Core نیاز دارد.', 'music-wave-vip' ) . '</p></div>';
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html(
			sprintf(
				/* translators: 1: minimum required Core version, 2: currently active Core version. */
				__( 'MusicWave VIP به MusicWave Core نسخهٔ %1$s یا بالاتر نیاز دارد؛ نسخهٔ فعال %2$s است.', 'music-wave-vip' ),
				self::MIN_CORE_VERSION,
				(string) MUSIC_WAVE_CORE_VERSION
			)
		) . '</p></div>';
	}
}
