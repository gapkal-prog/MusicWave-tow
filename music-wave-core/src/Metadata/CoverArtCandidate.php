<?php
/**
 * Value object for a cover art candidate with quality scoring.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CoverArtCandidate {
	/** @var string */
	public $url;

	/** @var int */
	public $width;

	/** @var int */
	public $height;

	/** @var string Provider source (spotify, discogs, musicbrainz, etc). */
	public $source;

	/** @var bool Whether this is a front cover. */
	public $is_front;

	/** @var bool Whether this is primary image (Discogs). */
	public $is_primary;

	/** @var bool Whether release is official. */
	public $is_official;

	public function __construct(
		string $url,
		int $width = 0,
		int $height = 0,
		string $source = '',
		bool $is_front = true,
		bool $is_primary = false,
		bool $is_official = false
	) {
		$this->url         = $url;
		$this->width       = max( 0, $width );
		$this->height      = max( 0, $height );
		$this->source      = $source;
		$this->is_front    = $is_front;
		$this->is_primary  = $is_primary;
		$this->is_official = $is_official;
	}

	/**
	 * Scoring: quality ignored per requirement, only front/primary/official matter.
	 * Each source's own cover is used, not cross-source quality comparison.
	 */
	public function score(): int {
		$score = 0;
		if ( $this->is_front ) {
			$score += 1000;
		}
		if ( $this->is_primary ) {
			$score += 500;
		}
		if ( $this->is_official ) {
			$score += 100;
		}
		if ( 0 === stripos( $this->url, 'https://' ) ) {
			$score += 10;
		}

		return $score;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'url'         => $this->url,
			'width'       => $this->width,
			'height'      => $this->height,
			'source'      => $this->source,
			'is_front'    => $this->is_front,
			'is_primary'  => $this->is_primary,
			'is_official' => $this->is_official,
			'score'       => $this->score(),
		);
	}
}
