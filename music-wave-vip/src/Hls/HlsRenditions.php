<?php
/**
 * Target HLS rendition ladder (fixed bitrates, no ABR adaptation logic).
 *
 * 64/128/192/320 kbps. The playback client chooses quality; the packager
 * prepares each rendition once per asset.
 *
 * @package ManaCore\MusicWave\Vip\Hls
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Hls;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @immutable
 */
final class HlsRenditions {
	/** All supported bitrates in kbps, lowest to highest. */
	public const ALL_BITRATES = array( 64, 128, 192, 320 );

	/** @var list<int> */
	private $bitrates;

	/**
	 * @param list<int> $bitrates Subset of ALL_BITRATES; validated and sorted.
	 */
	public function __construct( array $bitrates = array() ) {
		$this->bitrates = self::normalize( $bitrates );
	}

	/**
	 * @return list<int>
	 */
	public function bitrates(): array {
		return $this->bitrates;
	}

	public function is_empty(): bool {
		return 0 === count( $this->bitrates );
	}

	public function contains( int $bitrate ): bool {
		return in_array( $bitrate, $this->bitrates, true );
	}

	/**
	 * Extension name used in file paths (e.g. `128k`).
	 */
	public function label( int $bitrate ): string {
		return $bitrate . 'k';
	}

	/**
	 * Default ladder when the admin has not overridden settings.
	 */
	public static function defaults(): self {
		return new self( array( 128, 320 ) );
	}

	/**
	 * @param list<int> $bitrates
	 * @return list<int>
	 */
	private static function normalize( array $bitrates ): array {
		$clean = array();
		foreach ( $bitrates as $b ) {
			$b = absint( $b );
			if ( $b < 1 || ! in_array( $b, self::ALL_BITRATES, true ) || in_array( $b, $clean, true ) ) {
				continue;
			}
			$clean[] = $b;
		}
		sort( $clean );

		return $clean;
	}

	private function __clone() {}
}
