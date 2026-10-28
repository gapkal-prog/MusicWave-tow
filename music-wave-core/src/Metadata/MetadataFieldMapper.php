<?php
/**
 * Phase 4: Smart field mapping based on release type and provider entity type.
 *
 * Decides which meta fields should be filled for a given release type,
 * preventing irrelevant data (e.g., track_number for album, episode_number for track).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MetadataFieldMapper {

	/**
	 * Release type groups.
	 */
	private const TYPE_TRACK           = array( 'track', 'single' );
	private const TYPE_ALBUM           = array( 'album', 'ep', 'mix' );
	private const TYPE_PLAYLIST        = array( 'playlist' );
	private const TYPE_PODCAST_SHOW    = array( 'podcast_show' );
	private const TYPE_PODCAST_EPISODE = array( 'podcast_episode' );

	/**
	 * Map MetadataResult to post meta array, filtered by release type.
	 *
	 * @param MetadataResult $result Enriched result.
	 * @param string[]       $release_types Current post's mw_release_type slugs.
	 * @return array<string, mixed> meta_key => value
	 */
	public static function map_to_meta( MetadataResult $result, array $release_types = array() ): array {
		$primary_type = self::primary_type( $release_types, $result->entity_type );
		$meta         = array();

		// Common for all types.
		if ( '' !== $result->release_date ) {
			$meta['mw_release_date'] = $result->release_date;
		} elseif ( '' !== $result->date_for_meta() ) {
			$meta['mw_release_date'] = $result->date_for_meta();
		}
		if ( '' !== $result->year ) {
			$meta['mw_release_year'] = (int) $result->year;
		}
		if ( '' !== $result->album && self::should_fill_album_field( $primary_type ) ) {
			$meta['mw_album'] = $result->album;
		}
		if ( '' !== $result->country ) {
			$meta['mw_country'] = $result->country;
		}
		if ( '' !== $result->barcode ) {
			$meta['mw_barcode'] = $result->barcode;
		}
		if ( '' !== $result->publisher ) {
			$meta['mw_publisher'] = $result->publisher;
		}
		if ( '' !== $result->language ) {
			$meta['mw_language'] = $result->language;
		}
		if ( '' !== $result->isrc && self::is_track_type( $primary_type ) ) {
			$meta['mw_isrc'] = $result->isrc;
		}
		if ( '' !== $result->source_url ) {
			$meta['mw_metadata_source'] = $result->source_url;
		}
		if ( '' !== $result->provider ) {
			$meta['mw_metadata_provider'] = $result->provider;
		}

		// Type-specific.
		if ( self::is_track_type( $primary_type ) ) {
			if ( $result->duration > 0 ) {
				$meta['mw_duration'] = $result->duration;
			}
			if ( $result->track_number > 0 ) {
				$meta['mw_track_number'] = $result->track_number;
			}
			if ( $result->total_tracks > 0 ) {
				$meta['mw_total_tracks'] = $result->total_tracks;
			}
			if ( $result->explicit ) {
				$meta['mw_explicit'] = 1;
			}
		} elseif ( self::is_album_type( $primary_type ) ) {
			if ( $result->total_tracks > 0 ) {
				$meta['mw_total_tracks'] = $result->total_tracks;
			}
			// For album, duration is less meaningful but keep if available as total? Skip track_number.
			if ( $result->explicit ) {
				$meta['mw_explicit'] = 1;
			}
		} elseif ( self::is_podcast_episode_type( $primary_type ) ) {
			if ( $result->duration > 0 ) {
				$meta['mw_duration'] = $result->duration;
			}
			if ( $result->track_number > 0 ) {
				// Map track_number to episode_number for podcast episode.
				$meta['mw_episode_number'] = $result->track_number;
			}
			if ( $result->total_tracks > 0 ) {
				// Sometimes total_tracks can be season's episode count, but we don't overwrite season_number.
				// Keep as total_tracks for reference if needed.
				$meta['mw_total_tracks'] = $result->total_tracks;
			}
			if ( $result->explicit ) {
				$meta['mw_explicit'] = 1;
			}
		} elseif ( self::is_podcast_show_type( $primary_type ) ) {
			if ( $result->explicit ) {
				$meta['mw_explicit'] = 1;
			}
			// Podcast show typically no duration/track_number.
		} elseif ( self::is_playlist_type( $primary_type ) ) {
			// Playlist: keep publisher, language, etc. No track_number/duration.
			if ( $result->total_tracks > 0 ) {
				$meta['mw_total_tracks'] = $result->total_tracks;
			}
		}

		return $meta;
	}

	/**
	 * Determine primary release type from post's types + entity_type fallback.
	 *
	 * @param string[] $release_types
	 * @param string   $entity_type
	 * @return string
	 */
	public static function primary_type( array $release_types, string $entity_type = '' ): string {
		if ( ! empty( $release_types ) ) {
			$first = sanitize_key( $release_types[0] );
			if ( '' !== $first ) {
				return $first;
			}
		}
		// Fallback from entity_type.
		$map = array(
			'track'    => 'track',
			'album'    => 'album',
			'playlist' => 'playlist',
			'show'     => 'podcast_show',
			'episode'  => 'podcast_episode',
		);
		if ( isset( $map[ $entity_type ] ) ) {
			return $map[ $entity_type ];
		}
		return 'track';
	}

	public static function is_track_type( string $type ): bool {
		return in_array( $type, self::TYPE_TRACK, true );
	}

	public static function is_album_type( string $type ): bool {
		return in_array( $type, self::TYPE_ALBUM, true );
	}

	public static function is_playlist_type( string $type ): bool {
		return in_array( $type, self::TYPE_PLAYLIST, true );
	}

	public static function is_podcast_show_type( string $type ): bool {
		return in_array( $type, self::TYPE_PODCAST_SHOW, true );
	}

	public static function is_podcast_episode_type( string $type ): bool {
		return in_array( $type, self::TYPE_PODCAST_EPISODE, true );
	}

	/**
	 * Should we fill mw_album field? For track/single it makes sense, for album it's redundant.
	 */
	private static function should_fill_album_field( string $primary_type ): bool {
		if ( self::is_album_type( $primary_type ) ) {
			return false; // Album title is post_title, not mw_album.
		}
		if ( self::is_podcast_show_type( $primary_type ) ) {
			return false;
		}
		return true;
	}
}
