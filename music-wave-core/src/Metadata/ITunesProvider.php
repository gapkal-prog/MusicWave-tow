<?php
/**
 * iTunes Search API provider — no key required, cover 600px.
 *
 * Phase 5: provides high-quality cover via artworkUrl100 → 600x600.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Metadata;

use ManaCore\MusicWave\Core\Metadata\Http\HttpClientInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ITunesProvider extends AbstractMetadataProvider implements MetadataEnrichmentProvider {
	private const SEARCH_ENDPOINT = 'https://itunes.apple.com/search';
	private const LOOKUP_ENDPOINT = 'https://itunes.apple.com/lookup';

	public function name(): string {
		return 'itunes';
	}

	public function is_enabled(): bool {
		return true; // No key required.
	}

	public function priority(): int {
		return 30;
	}

	public function search( MetadataQuery $query ): array {
		if ( ! $query->is_valid() ) {
			return array();
		}

		$entity = $this->map_entity( $query->primary_release_type() );
		$term   = $this->build_term( $query );
		if ( '' === $term ) {
			return array();
		}

		$url = $this->build_url(
			self::SEARCH_ENDPOINT,
			array(
				'term'   => $term,
				'entity' => $entity,
				'limit'  => 10,
				'media'  => $this->map_media( $query->primary_release_type() ),
			)
		);

		$response = $this->http->get(
			$url,
			array(
				'timeout'             => 10,
				'limit_response_size' => 1024 * 1024,
			)
		);

		$data = $this->decode_json( $response );
		if ( null === $data || ! isset( $data['results'] ) || ! is_array( $data['results'] ) ) {
			throw new \RuntimeException( 'iTunes returned an invalid search payload.' );
		}

		$out = array();
		foreach ( $data['results'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$result = $this->normalize_item( $item, $query->primary_release_type() );
			if ( $result->is_usable() ) {
				$out[] = $result;
			}
		}

		return $out;
	}

	public function cover_for( MetadataResult $result ): string {
		return $result->cover_url;
	}

	public function enrich( MetadataResult $result ): MetadataResult {
		if ( '' === $result->reference_id ) {
			return $result;
		}

		$url = $this->build_url(
			self::LOOKUP_ENDPOINT,
			array(
				'id'     => $result->reference_id,
				'entity' => 'song',
			)
		);

		$response = $this->http->get(
			$url,
			array(
				'timeout'             => 10,
				'limit_response_size' => 1024 * 1024,
			)
		);
		if ( $response->is_error() || $response->is_rate_limited() || ! $response->is_success() ) {
			return $result;
		}

		$data = $response->json();
		if ( ! is_array( $data ) || empty( $data['results'][0] ) || ! is_array( $data['results'][0] ) ) {
			return $result;
		}

		$detail = $data['results'][0];

		$genres = array();
		if ( ! empty( $detail['primaryGenreName'] ) && is_scalar( $detail['primaryGenreName'] ) ) {
			$genres[] = array(
				'name'        => (string) $detail['primaryGenreName'],
				'external_id' => '',
			);
		}

		$publisher = '';
		if ( isset( $detail['collectionName'] ) && isset( $detail['artistName'] ) ) {
			// iTunes doesn't have explicit publisher, use copyright or label if present.
			$publisher = isset( $detail['copyright'] ) && is_scalar( $detail['copyright'] ) ? (string) $detail['copyright'] : '';
		}

		$country = $result->country;
		if ( isset( $detail['country'] ) && is_scalar( $detail['country'] ) && '' !== trim( (string) $detail['country'] ) ) {
			$country = (string) $detail['country'];
		}
		$explicit = isset( $detail['trackExplicitness'] ) ? 'explicit' === (string) $detail['trackExplicitness'] : $result->explicit;

		$release_date = $result->release_date;
		if ( isset( $detail['releaseDate'] ) && is_scalar( $detail['releaseDate'] ) ) {
			// iTunes date is ISO8601 like 2021-05-20T07:00:00Z — now normalized to YYYY-MM-DD via MetadataResult.
			$date_str = (string) $detail['releaseDate'];
			if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $date_str, $m ) ) {
				$release_date = $m[1];
			} else {
				$release_date = $date_str;
			}
		}

		$cover = $this->extract_cover( $detail );

		$duration = $result->duration;
		if ( isset( $detail['trackTimeMillis'] ) && is_numeric( $detail['trackTimeMillis'] ) ) {
			$duration = (int) round( (int) $detail['trackTimeMillis'] / 1000 );
		}

		return MetadataResult::from_array(
			array_merge(
				$result->to_array(),
				array(
					'genres'       => ! empty( $genres ) ? $genres : $result->genres,
					'publisher'    => '' !== $publisher ? $publisher : $result->publisher,
					'country'      => $country,
					'explicit'     => $explicit,
					'release_date' => $release_date,
					'duration'     => $duration,
					'cover_url'    => '' !== $cover ? $cover : $result->cover_url,
					'cover_mime'   => '' !== $cover ? 'image/jpeg' : $result->cover_mime,
					'cover_width'  => '' !== $cover ? 600 : $result->cover_width,
					'cover_height' => '' !== $cover ? 600 : $result->cover_height,
				)
			)
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function normalize_item( array $item, string $primary_release_type ): MetadataResult {
		$track_id = isset( $item['trackId'] ) ? (string) $item['trackId'] : ( isset( $item['collectionId'] ) ? (string) $item['collectionId'] : '' );
		$title    = isset( $item['trackName'] ) ? (string) $item['trackName'] : ( isset( $item['collectionName'] ) ? (string) $item['collectionName'] : '' );
		$artist   = isset( $item['artistName'] ) ? (string) $item['artistName'] : '';
		$album    = isset( $item['collectionName'] ) ? (string) $item['collectionName'] : $title;
		$cover    = $this->extract_cover( $item );

		$entity_type = 'track';
		if ( in_array( $primary_release_type, array( 'album', 'ep', 'mix' ), true ) ) {
			$entity_type = 'album';
		} elseif ( 'playlist' === $primary_release_type ) {
			$entity_type = 'playlist';
		} elseif ( in_array( $primary_release_type, array( 'podcast_show', 'podcast_episode' ), true ) ) {
			$entity_type = 'track'; // iTunes podcast still track-like.
		}

		$release_date = '';
		if ( isset( $item['releaseDate'] ) && is_scalar( $item['releaseDate'] ) ) {
			$date_str = (string) $item['releaseDate'];
			if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $date_str, $m ) ) {
				$release_date = $m[1];
			}
		}

		$genres = array();
		if ( ! empty( $item['primaryGenreName'] ) && is_scalar( $item['primaryGenreName'] ) ) {
			$genres[] = array(
				'name'        => (string) $item['primaryGenreName'],
				'external_id' => '',
			);
		}

		$explicit = isset( $item['trackExplicitness'] ) ? 'explicit' === (string) $item['trackExplicitness'] : false;
		$country  = isset( $item['country'] ) && is_scalar( $item['country'] ) ? (string) $item['country'] : '';

		$track_number = isset( $item['trackNumber'] ) ? (int) $item['trackNumber'] : 0;
		$total_tracks = isset( $item['trackCount'] ) ? (int) $item['trackCount'] : 0;
		$duration     = 0;
		if ( isset( $item['trackTimeMillis'] ) && is_numeric( $item['trackTimeMillis'] ) ) {
			$duration = (int) round( (int) $item['trackTimeMillis'] / 1000 );
		}

		return MetadataResult::from_array(
			array(
				'provider'     => $this->name(),
				'entity_type'  => $entity_type,
				'reference_id' => $track_id,
				'title'        => $title,
				'artist'       => $artist,
				'album'        => $album,
				'release_date' => $release_date,
				'genres'       => $genres,
				'cover_url'    => $cover,
				'cover_mime'   => '' !== $cover ? 'image/jpeg' : '',
				'cover_width'  => '' !== $cover ? 600 : 0,
				'cover_height' => '' !== $cover ? 600 : 0,
				'source_url'   => isset( $item['trackViewUrl'] ) ? (string) $item['trackViewUrl'] : ( isset( $item['collectionViewUrl'] ) ? (string) $item['collectionViewUrl'] : '' ),
				'track_number' => $track_number,
				'total_tracks' => $total_tracks,
				'explicit'     => $explicit,
				'country'      => $country,
				'duration'     => $duration,
			)
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function extract_cover( array $item ): string {
		$raw = '';
		if ( ! empty( $item['artworkUrl100'] ) && is_scalar( $item['artworkUrl100'] ) ) {
			$raw = (string) $item['artworkUrl100'];
		} elseif ( ! empty( $item['artworkUrl60'] ) && is_scalar( $item['artworkUrl60'] ) ) {
			$raw = (string) $item['artworkUrl60'];
		}
		if ( '' === $raw ) {
			return '';
		}
		// Upgrade 100x100 → 600x600, 60x60 → 600x600.
		$upgraded = str_replace( array( '100x100', '60x60bb', '60x60' ), '600x600', $raw );
		return $upgraded;
	}

	private function map_entity( string $release_type ): string {
		$map = array(
			'album'           => 'album',
			'ep'              => 'album',
			'mix'             => 'album',
			'playlist'        => 'musicTrack',
			'podcast_show'    => 'podcast',
			'podcast_episode' => 'podcastEpisode',
			'track'           => 'musicTrack',
			'single'          => 'musicTrack',
		);
		return $map[ $release_type ] ?? 'musicTrack';
	}

	private function map_media( string $release_type ): string {
		if ( in_array( $release_type, array( 'podcast_show', 'podcast_episode' ), true ) ) {
			return 'podcast';
		}
		return 'music';
	}

	private function build_term( MetadataQuery $query ): string {
		$parts = array_filter( array( $query->track, $query->artist, $query->album, $query->free_text ) );
		return trim( implode( ' ', $parts ) );
	}
}
