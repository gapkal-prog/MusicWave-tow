<?php
/**
 * Deezer API provider — no key required, cover up to 1000px (cover_xl).
 *
 * Phase 5: provides high-quality cover via album cover_xl.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Metadata;

use ManaCore\MusicWave\Core\Metadata\Http\HttpClientInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DeezerProvider extends AbstractMetadataProvider implements MetadataEnrichmentProvider {
	private const SEARCH_TRACK_ENDPOINT = 'https://api.deezer.com/search';
	private const SEARCH_ALBUM_ENDPOINT = 'https://api.deezer.com/search/album';
	private const TRACK_ENDPOINT        = 'https://api.deezer.com/track/';
	private const ALBUM_ENDPOINT        = 'https://api.deezer.com/album/';

	public function name(): string {
		return 'deezer';
	}

	public function is_enabled(): bool {
		return true;
	}

	public function priority(): int {
		return 40;
	}

	public function search( MetadataQuery $query ): array {
		if ( ! $query->is_valid() ) {
			return array();
		}
		if ( $query->is_podcast() || 'playlist' === $query->primary_release_type() ) {
			// Deezer podcast search is limited, skip for now.
			return array();
		}

		$is_album = in_array( $query->primary_release_type(), array( 'album', 'ep', 'mix' ), true );
		$endpoint = $is_album ? self::SEARCH_ALBUM_ENDPOINT : self::SEARCH_TRACK_ENDPOINT;
		$q        = $this->build_query( $query );
		if ( '' === $q ) {
			return array();
		}

		$url = $this->build_url(
			$endpoint,
			array(
				'q'     => $q,
				'limit' => 10,
			)
		);

		$response = $this->http->get(
			$url,
			array(
				'timeout'             => 10,
				'limit_response_size' => 1024 * 1024,
			)
		);
		$data     = $this->decode_json( $response );
		if ( null === $data || ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
			throw new \RuntimeException( 'Deezer returned an invalid search payload.' );
		}

		$out = array();
		foreach ( $data['data'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$result = $is_album ? $this->normalize_album( $item ) : $this->normalize_track( $item );
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

		$is_album = 'album' === $result->entity_type;
		$endpoint = $is_album ? self::ALBUM_ENDPOINT : self::TRACK_ENDPOINT;
		$url      = $endpoint . rawurlencode( $result->reference_id );

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
		if ( ! is_array( $data ) ) {
			return $result;
		}

		$genres = array();
		if ( isset( $data['genres']['data'] ) && is_array( $data['genres']['data'] ) ) {
			foreach ( $data['genres']['data'] as $g ) {
				if ( is_array( $g ) && ! empty( $g['name'] ) ) {
					$genres[] = array(
						'name'        => (string) $g['name'],
						'external_id' => isset( $g['id'] ) ? (string) $g['id'] : '',
					);
				}
			}
		}

		$publisher = '';
		if ( isset( $data['label'] ) && is_scalar( $data['label'] ) ) {
			$publisher = (string) $data['label'];
		}

		$country  = $result->country;
		$explicit = $result->explicit;
		if ( isset( $data['explicit_lyrics'] ) ) {
			$explicit = (bool) $data['explicit_lyrics'];
		}

		$cover = $this->extract_cover( $data );

		$track_number = $result->track_number;
		$total_tracks = $result->total_tracks;
		if ( isset( $data['track_position'] ) ) {
			$track_number = (int) $data['track_position'];
		}
		if ( isset( $data['nb_tracks'] ) ) {
			$total_tracks = (int) $data['nb_tracks'];
		}

		$release_date = $result->release_date;
		if ( isset( $data['release_date'] ) && is_scalar( $data['release_date'] ) ) {
			$release_date = (string) $data['release_date'];
		}

		return MetadataResult::from_array(
			array_merge(
				$result->to_array(),
				array(
					'genres'       => ! empty( $genres ) ? $genres : $result->genres,
					'publisher'    => '' !== $publisher ? $publisher : $result->publisher,
					'explicit'     => $explicit,
					'cover_url'    => '' !== $cover ? $cover : $result->cover_url,
					'cover_mime'   => '' !== $cover ? 'image/jpeg' : $result->cover_mime,
					'cover_width'  => '' !== $cover ? 1000 : $result->cover_width,
					'cover_height' => '' !== $cover ? 1000 : $result->cover_height,
					'track_number' => $track_number,
					'total_tracks' => $total_tracks,
					'release_date' => $release_date,
					'country'      => $country,
				)
			)
		);
	}

	/**
	 * @param array<string, mixed> $track
	 */
	private function normalize_track( array $track ): MetadataResult {
		$title  = isset( $track['title'] ) ? (string) $track['title'] : '';
		$artist = '';
		if ( isset( $track['artist']['name'] ) && is_scalar( $track['artist']['name'] ) ) {
			$artist = (string) $track['artist']['name'];
		}
		$album = '';
		if ( isset( $track['album']['title'] ) && is_scalar( $track['album']['title'] ) ) {
			$album = (string) $track['album']['title'];
		}

		$cover = $this->extract_cover( isset( $track['album'] ) && is_array( $track['album'] ) ? $track['album'] : $track );

		$explicit = ! empty( $track['explicit_lyrics'] );

		$duration = 0;
		if ( isset( $track['duration'] ) && is_numeric( $track['duration'] ) ) {
			$duration = (int) $track['duration'];
		}

		$track_number = isset( $track['track_position'] ) ? (int) $track['track_position'] : 0;

		return MetadataResult::from_array(
			array(
				'provider'     => $this->name(),
				'entity_type'  => 'track',
				'reference_id' => isset( $track['id'] ) ? (string) $track['id'] : '',
				'title'        => $title,
				'artist'       => $artist,
				'album'        => $album,
				'duration'     => $duration,
				'cover_url'    => $cover,
				'cover_mime'   => '' !== $cover ? 'image/jpeg' : '',
				'cover_width'  => '' !== $cover ? 1000 : 0,
				'cover_height' => '' !== $cover ? 1000 : 0,
				'source_url'   => isset( $track['link'] ) ? (string) $track['link'] : '',
				'track_number' => $track_number,
				'explicit'     => $explicit,
			)
		);
	}

	/**
	 * @param array<string, mixed> $album
	 */
	private function normalize_album( array $album ): MetadataResult {
		$title  = isset( $album['title'] ) ? (string) $album['title'] : '';
		$artist = '';
		if ( isset( $album['artist']['name'] ) && is_scalar( $album['artist']['name'] ) ) {
			$artist = (string) $album['artist']['name'];
		}

		$cover = $this->extract_cover( $album );

		$total_tracks = isset( $album['nb_tracks'] ) ? (int) $album['nb_tracks'] : 0;

		return MetadataResult::from_array(
			array(
				'provider'     => $this->name(),
				'entity_type'  => 'album',
				'reference_id' => isset( $album['id'] ) ? (string) $album['id'] : '',
				'title'        => $title,
				'artist'       => $artist,
				'album'        => $title,
				'cover_url'    => $cover,
				'cover_mime'   => '' !== $cover ? 'image/jpeg' : '',
				'cover_width'  => '' !== $cover ? 1000 : 0,
				'cover_height' => '' !== $cover ? 1000 : 0,
				'source_url'   => isset( $album['link'] ) ? (string) $album['link'] : '',
				'total_tracks' => $total_tracks,
			)
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function extract_cover( array $data ): string {
		// Prefer xl (1000), then big (500), then cover_big etc.
		$keys = array( 'cover_xl', 'cover_big', 'cover_medium', 'cover', 'picture_xl', 'picture_big' );
		foreach ( $keys as $k ) {
			if ( ! empty( $data[ $k ] ) && is_scalar( $data[ $k ] ) ) {
				return (string) $data[ $k ];
			}
		}
		return '';
	}

	private function build_query( MetadataQuery $query ): string {
		$parts = array();
		if ( '' !== $query->track ) {
			$parts[] = 'track:\"' . $query->track . '\"';
		}
		if ( '' !== $query->artist ) {
			$parts[] = 'artist:\"' . $query->artist . '\"';
		}
		if ( '' !== $query->album ) {
			$parts[] = 'album:\"' . $query->album . '\"';
		}
		if ( empty( $parts ) && '' !== $query->free_text ) {
			return $query->free_text;
		}
		return trim( implode( ' ', $parts ) );
	}
}
