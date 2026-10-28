<?php
/**
 * Keyless MusicBrainz + Cover Art Archive provider (built-in default).
 *
 * Refactored to use shared HttpClient (Phase 1 clean).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Metadata;

use ManaCore\MusicWave\Core\Metadata\Http\HttpClientInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MusicBrainzProvider extends AbstractMetadataProvider implements MetadataEnrichmentProvider {
	private const SEARCH_ENDPOINT         = 'https://musicbrainz.org/ws/2/recording/';
	private const RELEASE_SEARCH_ENDPOINT = 'https://musicbrainz.org/ws/2/release/';
	private const RELEASE_ENDPOINT        = 'https://musicbrainz.org/ws/2/release/';
	private const COVER_ENDPOINT          = 'https://coverartarchive.org/release/';

	public function name(): string {
		return 'musicbrainz';
	}

	public function is_enabled(): bool {
		return true;
	}

	public function priority(): int {
		return 100;
	}

	public function search( MetadataQuery $query ): array {
		if ( ! $query->is_valid() || $query->is_podcast() || 'playlist' === $query->primary_release_type() ) {
			return array();
		}

		$release_search = in_array( $query->primary_release_type(), array( 'album', 'ep', 'mix' ), true );
		$lucene         = $release_search ? $this->release_lucene( $query ) : $this->lucene( $query );
		if ( '' === $lucene ) {
			return array();
		}

		$url  = add_query_arg(
			array(
				'query' => rawurlencode( $lucene ),
				'fmt'   => 'json',
				'limit' => 10,
			),
			$release_search ? self::RELEASE_SEARCH_ENDPOINT : self::SEARCH_ENDPOINT
		);
		$data = $this->decode_json( $this->request( $url ) );

		$bucket = $release_search ? 'releases' : 'recordings';
		if ( null === $data || ! isset( $data[ $bucket ] ) || ! is_array( $data[ $bucket ] ) ) {
			throw new \RuntimeException( 'MusicBrainz returned an invalid search payload.' );
		}

		$results = array();
		foreach ( $data[ $bucket ] as $item ) {
			$result = $release_search
				? $this->normalize_release( is_array( $item ) ? $item : array() )
				: $this->normalize_recording( is_array( $item ) ? $item : array() );
			if ( $result->is_usable() ) {
				$results[] = $result;
			}
		}

		return $results;
	}

	private function release_lucene( MetadataQuery $query ): string {
		$parts = array();
		$title = '' !== $query->album ? $query->album : $query->track;
		if ( '' !== $title ) {
			$title_term = 'release:"' . self::escape_lucene( $title ) . '"';
			$parts[]    = '' !== $query->free_text && '' === $query->artist && '' === $query->album
				? '(' . $title_term . ' OR artist:"' . self::escape_lucene( $title ) . '")'
				: $title_term;
		}
		if ( '' !== $query->artist ) {
			$parts[] = 'artist:"' . self::escape_lucene( $query->artist ) . '"';
		}
		if ( '' !== $query->year ) {
			$parts[] = 'date:' . self::escape_lucene( $query->year ) . '*';
		}
		return implode( ' AND ', $parts );
	}

	/**
	 * @param array<string, mixed> $release
	 */
	private function normalize_release( array $release ): MetadataResult {
		$artists = $this->artist_entities( isset( $release['artist-credit'] ) && is_array( $release['artist-credit'] ) ? $release['artist-credit'] : array() );
		$genres  = array();
		if ( isset( $release['release-group']['genres'] ) && is_array( $release['release-group']['genres'] ) ) {
			$genres = $this->named_entities( $release['release-group']['genres'] );
		}
		return MetadataResult::from_array(
			array(
				'provider'     => $this->name(),
				'entity_type'  => 'album',
				'reference_id' => isset( $release['id'] ) ? (string) $release['id'] : '',
				'title'        => isset( $release['title'] ) ? (string) $release['title'] : '',
				'album'        => isset( $release['title'] ) ? (string) $release['title'] : '',
				'artist'       => implode( ', ', array_column( $artists, 'name' ) ),
				'artists'      => $artists,
				'genres'       => $genres,
				'release_date' => isset( $release['date'] ) ? (string) $release['date'] : '',
				'source_url'   => isset( $release['id'] ) ? 'https://musicbrainz.org/release/' . rawurlencode( (string) $release['id'] ) : '',
			)
		);
	}

	public function cover_for( MetadataResult $result ): string {
		if ( '' === $result->reference_id ) {
			return '';
		}

		// Try release cover first.
		$url = $this->best_cover_from_release( $result->reference_id );
		if ( '' !== $url ) {
			return $url;
		}

		// Phase 2 fallback: try release-group if release has no artwork.
		// Fetch release to get release-group id.
		try {
			$release_data = $this->decode_json(
				$this->request(
					add_query_arg(
						array( 'fmt' => 'json' ),
						trailingslashit( self::RELEASE_ENDPOINT ) . rawurlencode( $result->reference_id )
					)
				),
				true
			);
			$rg_id        = isset( $release_data['release-group']['id'] ) ? (string) $release_data['release-group']['id'] : '';
			if ( '' !== $rg_id ) {
				$rg_url = $this->best_cover_from_release_group( $rg_id );
				if ( '' !== $rg_url ) {
					return $rg_url;
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return '';
	}

	/**
	 * Phase 2: get best cover from CAA /release/{id} with scoring.
	 */
	private function best_cover_from_release( string $release_id ): string {
		$data = $this->decode_json( $this->request( trailingslashit( self::COVER_ENDPOINT ) . rawurlencode( $release_id ) ), true );
		if ( null === $data || empty( $data['images'] ) || ! is_array( $data['images'] ) ) {
			return '';
		}

		$candidates = $this->candidates_from_caa_images( $data['images'], $this->name() );
		if ( empty( $candidates ) ) {
			return '';
		}

		$resolver = new CoverArtResolver();
		$best     = $resolver->best( $candidates );

		return $best instanceof CoverArtCandidate ? $best->url : '';
	}

	/**
	 * Phase 2: fallback to CAA /release-group/{id}.
	 */
	private function best_cover_from_release_group( string $rg_id ): string {
		$rg_endpoint = 'https://coverartarchive.org/release-group/' . rawurlencode( $rg_id );
		$data        = $this->decode_json( $this->request( $rg_endpoint ), true );
		if ( null === $data || empty( $data['images'] ) || ! is_array( $data['images'] ) ) {
			return '';
		}

		$candidates = $this->candidates_from_caa_images( $data['images'], $this->name() );
		$resolver   = new CoverArtResolver();
		$best       = $resolver->best( $candidates );

		return $best instanceof CoverArtCandidate ? $best->url : '';
	}

	/**
	 * Convert CAA images array to scored candidates.
	 *
	 * @param array<int, mixed> $images
	 * @return CoverArtCandidate[]
	 */
	private function candidates_from_caa_images( array $images, string $source ): array {
		$candidates = array();
		$images     = array_values(
			array_filter(
				$images,
				static function ( $image ): bool {
					return is_array( $image );
				}
			)
		);

		// Prefer front.
		usort(
			$images,
			static function ( array $left, array $right ): int {
				return (int) ! empty( $right['front'] ) <=> (int) ! empty( $left['front'] );
			}
		);

		foreach ( $images as $image ) {
			$is_front   = ! empty( $image['front'] );
			$thumbnails = isset( $image['thumbnails'] ) && is_array( $image['thumbnails'] ) ? $image['thumbnails'] : array();

			// Try to get dimensions if available (CAA sometimes provides width/height via image).
			// For scoring, we use known sizes.
			$original_url = isset( $image['image'] ) && is_string( $image['image'] ) ? self::secure_cover_url( $image['image'] ) : '';
			if ( '' !== $original_url ) {
				// Original could be >1200, give high width estimate.
				$candidates[] = new CoverArtCandidate( $original_url, 2000, 2000, $source, $is_front, false, false );
			}

			// Thumbnails: 1200, 500, large, 250, small.
			$size_map = array(
				'1200'  => 1200,
				'500'   => 500,
				'large' => 500,
				'250'   => 250,
				'small' => 250,
			);
			foreach ( $size_map as $key => $width ) {
				if ( ! empty( $thumbnails[ $key ] ) && is_string( $thumbnails[ $key ] ) ) {
					$candidates[] = new CoverArtCandidate(
						self::secure_cover_url( $thumbnails[ $key ] ),
						$width,
						$width,
						$source,
						$is_front,
						false,
						false
					);
				}
			}
		}

		return $candidates;
	}

	public function enrich( MetadataResult $result ): MetadataResult {
		if ( '' === $result->reference_id ) {
			return $result;
		}

		$url  = add_query_arg(
			array(
				'inc' => rawurlencode( 'artist-credits+labels+release-groups+genres+annotation' ),
				'fmt' => 'json',
			),
			trailingslashit( self::RELEASE_ENDPOINT ) . rawurlencode( $result->reference_id )
		);
		$data = $this->decode_json( $this->request( $url ) );
		if ( null === $data ) {
			return $result;
		}

		$artists = $this->artist_entities( isset( $data['artist-credit'] ) && is_array( $data['artist-credit'] ) ? $data['artist-credit'] : array() );
		$genres  = $this->named_entities( isset( $data['genres'] ) && is_array( $data['genres'] ) ? $data['genres'] : array() );
		if ( empty( $genres ) && isset( $data['release-group']['genres'] ) && is_array( $data['release-group']['genres'] ) ) {
			$genres = $this->named_entities( $data['release-group']['genres'] );
		}

		$labels = array();
		if ( isset( $data['label-info'] ) && is_array( $data['label-info'] ) ) {
			foreach ( $data['label-info'] as $label_info ) {
				$label = is_array( $label_info ) && isset( $label_info['label'] ) && is_array( $label_info['label'] ) ? $label_info['label'] : array();
				if ( empty( $label['name'] ) ) {
					continue;
				}
				$labels[] = array(
					'name'        => (string) $label['name'],
					'external_id' => isset( $label['id'] ) ? (string) $label['id'] : '',
				);
			}
		}

		// Phase 3: more fields — normalize country/language via MetadataResult later.
		$barcode = $result->barcode;
		if ( isset( $data['barcode'] ) && is_scalar( $data['barcode'] ) && '' !== trim( (string) $data['barcode'] ) ) {
			$barcode = (string) $data['barcode'];
		}
		$country = $result->country;
		if ( isset( $data['country'] ) && is_scalar( $data['country'] ) && '' !== trim( (string) $data['country'] ) ) {
			$country = (string) $data['country'];
		}
		$publisher = $result->publisher;
		if ( ! empty( $labels ) ) {
			$publisher = $labels[0]['name'];
		}
		$language = $result->language;
		if ( isset( $data['text-representation']['language'] ) && is_scalar( $data['text-representation']['language'] ) && '' !== trim( (string) $data['text-representation']['language'] ) ) {
			$language = (string) $data['text-representation']['language'];
		}
		$release_date = $result->release_date;
		if ( isset( $data['date'] ) && is_scalar( $data['date'] ) && '' !== trim( (string) $data['date'] ) ) {
			$release_date = (string) $data['date'];
		}

		return MetadataResult::from_array(
			array_merge(
				$result->to_array(),
				array(
					'description'  => isset( $data['annotation'] ) && is_scalar( $data['annotation'] ) ? (string) $data['annotation'] : $result->description,
					'artists'      => ! empty( $artists ) ? $artists : $result->artists,
					'genres'       => ! empty( $genres ) ? $genres : $result->genres,
					'labels'       => ! empty( $labels ) ? $labels : $result->labels,
					'barcode'      => $barcode,
					'country'      => $country,
					'publisher'    => '' !== $publisher ? $publisher : $result->publisher,
					'language'     => '' !== $language ? $language : $result->language,
					'release_date' => $release_date,
				)
			)
		);
	}

	private function lucene( MetadataQuery $query ): string {
		$parts = array();
		if ( '' !== $query->track ) {
			$title_term = 'recording:"' . self::escape_lucene( $query->track ) . '"';
			$parts[]    = '' !== $query->free_text && '' === $query->artist
				? '(' . $title_term . ' OR artist:"' . self::escape_lucene( $query->track ) . '")'
				: $title_term;
		}
		if ( '' !== $query->artist ) {
			$parts[] = 'artistname:"' . self::escape_lucene( $query->artist ) . '"';
		}
		if ( '' !== $query->album ) {
			$parts[] = 'release:"' . self::escape_lucene( $query->album ) . '"';
		}
		if ( '' !== $query->year ) {
			$parts[] = 'firstreleasedate:' . self::escape_lucene( $query->year ) . '*';
		}

		return implode( ' AND ', $parts );
	}

	private static function escape_lucene( string $value ): string {
		return (string) preg_replace( '/(["\\\\])/', '\\\\$1', $value );
	}

	/**
	 * @param array<string, mixed> $recording
	 */
	private function normalize_recording( array $recording ): MetadataResult {
		$artist  = '';
		$artists = array();
		if ( ! empty( $recording['artist-credit'] ) && is_array( $recording['artist-credit'] ) ) {
			$artists = $this->artist_entities( $recording['artist-credit'] );
			$artist  = implode( ', ', array_column( $artists, 'name' ) );
		}

		$album      = '';
		$date       = '';
		$release_id = '';
		if ( ! empty( $recording['releases'] ) && is_array( $recording['releases'] ) ) {
			$releases = array_values(
				array_filter(
					$recording['releases'],
					static function ( $release ): bool {
						return is_array( $release ) && ! empty( $release['id'] ) && ! empty( $release['title'] );
					}
				)
			);
			usort(
				$releases,
				static function ( array $left, array $right ): int {
					return self::release_score( $right ) <=> self::release_score( $left );
				}
			);
			if ( ! empty( $releases ) ) {
				$release    = $releases[0];
				$album      = (string) $release['title'];
				$release_id = (string) $release['id'];
				$date       = ! empty( $release['date'] ) ? (string) $release['date'] : '';
			}
		}

		$genres = $this->named_entities( isset( $recording['genres'] ) && is_array( $recording['genres'] ) ? $recording['genres'] : array() );
		$genre  = implode( ', ', array_column( $genres, 'name' ) );
		$moods  = array();
		$tags   = array();
		if ( ! empty( $recording['tags'] ) && is_array( $recording['tags'] ) ) {
			usort(
				$recording['tags'],
				static function ( $a, $b ): int {
					$ac = is_array( $a ) && isset( $a['count'] ) ? (int) $a['count'] : 0;
					$bc = is_array( $b ) && isset( $b['count'] ) ? (int) $b['count'] : 0;

					return $bc <=> $ac;
				}
			);
			$tags = $this->named_entities( array_slice( $recording['tags'], 0, 10 ) );
			foreach ( $tags as $tag ) {
				if ( $this->is_known_mood( (string) $tag['name'] ) ) {
					$moods[] = $tag;
				}
			}
		}

		$duration = 0;
		if ( ! empty( $recording['length'] ) && is_numeric( $recording['length'] ) ) {
			$duration = (int) round( ( (int) $recording['length'] ) / 1000 );
		}

		$isrc = '';
		if ( ! empty( $recording['isrcs'] ) && is_array( $recording['isrcs'] ) && ! empty( $recording['isrcs'][0] ) ) {
			$isrc = (string) $recording['isrcs'][0];
		}

		return MetadataResult::from_array(
			array(
				'provider'     => $this->name(),
				'entity_type'  => 'track',
				'reference_id' => $release_id,
				'title'        => isset( $recording['title'] ) ? (string) $recording['title'] : '',
				'artist'       => $artist,
				'album'        => $album,
				'release_date' => $date,
				'genre'        => $genre,
				'artists'      => $artists,
				'genres'       => $genres,
				'moods'        => $moods,
				'tags'         => $tags,
				'duration'     => $duration,
				'isrc'         => $isrc,
				'source_url'   => ! empty( $recording['id'] ) ? 'https://musicbrainz.org/recording/' . rawurlencode( (string) $recording['id'] ) : '',
			)
		);
	}

	private function is_known_mood( string $name ): bool {
		$key = strtolower( trim( $name ) );
		$key = (string) preg_replace( '/[\s_-]+/', ' ', $key );
		return in_array(
			$key,
			array(
				'aggressive',
				'angry',
				'atmospheric',
				'calm',
				'chill',
				'dark',
				'dreamy',
				'emotional',
				'energetic',
				'epic',
				'euphoric',
				'happy',
				'hopeful',
				'melancholic',
				'melancholy',
				'mellow',
				'peaceful',
				'relaxing',
				'romantic',
				'sad',
				'sentimental',
				'uplifting',
			),
			true
		);
	}

	/**
	 * @param array<string, mixed> $release
	 */
	private static function release_score( array $release ): int {
		$score     = 0;
		$cover_art = isset( $release['cover-art-archive'] ) && is_array( $release['cover-art-archive'] ) ? $release['cover-art-archive'] : array();
		if ( ! empty( $cover_art['front'] ) ) {
			$score += 1000;
		} elseif ( ! empty( $cover_art['artwork'] ) || ! empty( $cover_art['count'] ) ) {
			$score += 500;
		}
		if ( isset( $release['status'] ) && 'official' === strtolower( (string) $release['status'] ) ) {
			$score += 100;
		}
		if ( ! empty( $release['date'] ) ) {
			$score += 20;
		}

		return $score;
	}

	private static function secure_cover_url( string $url ): string {
		$url = (string) esc_url_raw( $url );
		if ( 0 === stripos( $url, 'http://' ) ) {
			$url = 'https://' . substr( $url, 7 );
		} elseif ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}

		return (string) esc_url_raw( $url, array( 'https' ) );
	}

	/**
	 * Shared HTTP helper using injected client.
	 */
	private function request( string $url ): \ManaCore\MusicWave\Core\Metadata\Http\HttpResponse {
		return $this->http->get(
			$url,
			array(
				'timeout'             => 10,
				'limit_response_size' => 1024 * 1024,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
	}

	/**
	 * @param array<int, mixed> $credits
	 * @return array<int, array<string, string>>
	 */
	private function artist_entities( array $credits ): array {
		$artists = array();
		foreach ( $credits as $credit ) {
			if ( ! is_array( $credit ) ) {
				continue;
			}
			$artist = isset( $credit['artist'] ) && is_array( $credit['artist'] ) ? $credit['artist'] : array();
			$name   = isset( $credit['name'] ) && is_scalar( $credit['name'] ) ? (string) $credit['name'] : ( isset( $artist['name'] ) ? (string) $artist['name'] : '' );
			if ( '' === $name ) {
				continue;
			}
			$artists[] = array(
				'name'        => $name,
				'external_id' => isset( $artist['id'] ) ? (string) $artist['id'] : '',
			);
		}

		return $artists;
	}

	/**
	 * @param array<int, mixed> $items
	 * @return array<int, array<string, string>>
	 */
	private function named_entities( array $items ): array {
		$entities = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['name'] ) ) {
				continue;
			}
			$entities[] = array(
				'name'        => (string) $item['name'],
				'external_id' => isset( $item['id'] ) ? (string) $item['id'] : '',
			);
		}

		return $entities;
	}
}
