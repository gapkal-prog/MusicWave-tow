<?php
/**
 * Sanitized music metadata search query.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Metadata;

use ManaCore\MusicWave\Core\Discovery\PersianSearchNormalizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MetadataQuery {
	/** @var string */
	public $free_text = '';

	/** @var string */
	public $track = '';

	/** @var string */
	public $artist = '';

	/** @var string */
	public $album = '';

	/** @var string 4-digit year filter, or empty. */
	public $year = '';

	/** @var array<int, string> Canonical MusicWave release-type slugs. */
	public $release_types = array();

	/**
	 * Build a normalized query from raw request input.
	 *
	 * @param string $free_text Free-text track or artist search.
	 * @param string $track     Explicit track or release title.
	 * @param string $artist    Explicit artist name.
	 * @param string $album     Explicit album title.
	 * @param string $year      Explicit 4-digit release year.
	 */
	public static function from_strings( string $free_text = '', string $track = '', string $artist = '', string $album = '', string $year = '', array $release_types = array() ): self {
		$query                = new self();
		$query->free_text     = self::clean( $free_text );
		$query->track         = self::clean( $track );
		$query->artist        = self::clean( $artist );
		$query->album         = self::clean( $album );
		$query->year          = self::normalize_year( $year );
		$query->release_types = array_values(
			array_intersect(
				array( 'track', 'single', 'ep', 'album', 'mix', 'playlist', 'podcast_show', 'podcast_episode' ),
				array_values( array_unique( array_map( 'sanitize_key', $release_types ) ) )
			)
		);

		// Parse "Artist - Title" and "Title (2021)" patterns out of free text.
		if ( '' === $query->track && '' === $query->artist && '' !== $query->free_text ) {
			$text = str_replace( array( ' – ', ' — ' ), ' - ', $query->free_text );
			if ( '' === $query->year && preg_match( '/\((\d{4})\)/', $text, $match ) ) {
				$query->year = $match[1];
				$text        = trim( str_replace( $match[0], '', $text ) );
			}
			if ( false !== strpos( $text, ' - ' ) ) {
				list( $left, $right ) = explode( ' - ', $text, 2 );
				$query->artist        = self::clean( (string) $left );
				$query->track         = self::clean( (string) $right );
			} else {
				$query->track = $text;
			}
		}

		return $query;
	}

	public function is_podcast(): bool {
		return ! empty( array_intersect( $this->release_types, array( 'podcast_show', 'podcast_episode' ) ) );
	}

	public function primary_release_type(): string {
		return isset( $this->release_types[0] ) ? $this->release_types[0] : 'track';
	}

	/**
	 * Return true when the query carries enough information to search.
	 */
	public function is_valid(): bool {
		// A year alone is not enough to search, but any other field is.
		return '' !== $this->track || '' !== $this->artist || '' !== $this->album;
	}

	/**
	 * Return a short human-readable summary of the query.
	 */
	public function label(): string {
		$parts = array_filter( array( $this->track, $this->artist, $this->album, $this->year ) );

		return implode( ' - ', $parts );
	}

	private static function clean( string $value ): string {
		$value = PersianSearchNormalizer::digits_to_ascii( sanitize_text_field( $value ) );
		$value = (string) preg_replace( '/\s+/', ' ', $value );

		return trim( $value );
	}

	private static function normalize_year( string $value ): string {
		if ( preg_match( '/^(\d{4})$/', trim( PersianSearchNormalizer::digits_to_ascii( $value ) ), $match ) ) {
			return $match[1];
		}

		return '';
	}
}
