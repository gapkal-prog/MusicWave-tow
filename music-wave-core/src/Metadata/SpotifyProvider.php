<?php
/**
 * Optional keyed Spotify Web API provider.
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

final class SpotifyProvider extends AbstractMetadataProvider implements MetadataEnrichmentProvider {
	private const TOKEN_ENDPOINT  = 'https://accounts.spotify.com/api/token';
	private const SEARCH_ENDPOINT = 'https://api.spotify.com/v1/search';
	private const API_ENDPOINT    = 'https://api.spotify.com/v1/';
	private const TOKEN_TRANSIENT = 'mw_meta_spotify_token';

	/** @var string */
	private $client_id;

	/** @var string */
	private $client_secret;

	public function __construct( string $client_id = '', string $client_secret = '', ?HttpClientInterface $http = null ) {
		parent::__construct( $http );
		$this->client_id     = $client_id;
		$this->client_secret = $client_secret;
	}

	public function name(): string {
		return 'spotify';
	}

	public function is_enabled(): bool {
		return '' !== $this->client_id && '' !== $this->client_secret;
	}

	public function priority(): int {
		return 10;
	}

	public function search( MetadataQuery $query ): array {
		$token = $this->access_token();
		if ( '' === $token ) {
			throw new \RuntimeException( 'Spotify authentication failed.' );
		}

		$entity_type = $this->search_type( $query );
		$q           = $this->build_query( $query, $entity_type );
		if ( '' === $q ) {
			return array();
		}

		$url      = $this->build_url(
			self::SEARCH_ENDPOINT,
			array(
				'q'     => rawurlencode( $q ),
				'type'  => $entity_type,
				'limit' => 10,
			)
		);
		$response = $this->http->get(
			$url,
			array(
				'timeout'             => 10,
				'limit_response_size' => 1024 * 1024,
				'headers'             => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		$data = $this->decode_json( $response );
		if ( null === $data ) {
			throw new \RuntimeException( 'Spotify returned an invalid search payload.' );
		}

		$bucket = $entity_type . 's';
		if ( ! isset( $data[ $bucket ]['items'] ) || ! is_array( $data[ $bucket ]['items'] ) ) {
			throw new \RuntimeException( 'Spotify returned an invalid search payload.' );
		}
		$items = $data[ $bucket ]['items'];

		$results = array();
		foreach ( $items as $item ) {
			$result = $this->normalize_item( is_array( $item ) ? $item : array(), $entity_type );
			if ( $result->is_usable() ) {
				$results[] = $result;
			}
		}

		return $results;
	}

	public function cover_for( MetadataResult $result ): string {
		return $result->cover_url; // Spotify returns cover art inline.
	}

	public function enrich( MetadataResult $result ): MetadataResult {
		$token = $this->access_token();
		if ( '' === $token || '' === $result->reference_id ) {
			return $result;
		}

		$entity_type = in_array( $result->entity_type, array( 'track', 'album', 'playlist', 'show', 'episode' ), true ) ? $result->entity_type : 'track';
		$detail      = $this->get_json( self::API_ENDPOINT . $entity_type . 's/' . rawurlencode( $result->reference_id ), $token );
		if ( null === $detail ) {
			return $result;
		}
		if ( in_array( $entity_type, array( 'show', 'episode', 'playlist' ), true ) ) {
			return $this->enrich_spoken_or_playlist( $result, $detail, $entity_type );
		}

		$artists      = array();
		$genres       = array();
		$artist_items = isset( $detail['artists'] ) && is_array( $detail['artists'] ) ? array_slice( $detail['artists'], 0, 5 ) : array();
		foreach ( $artist_items as $artist ) {
			if ( ! is_array( $artist ) || empty( $artist['name'] ) ) {
				continue;
			}
			$artist_id = isset( $artist['id'] ) ? (string) $artist['id'] : '';
			$artists[] = array(
				'name'        => (string) $artist['name'],
				'external_id' => $artist_id,
			);
			if ( '' === $artist_id ) {
				continue;
			}
			$artist_detail = $this->get_json( self::API_ENDPOINT . 'artists/' . rawurlencode( $artist_id ), $token );
			if ( null !== $artist_detail && isset( $artist_detail['genres'] ) && is_array( $artist_detail['genres'] ) ) {
				foreach ( $artist_detail['genres'] as $genre ) {
					if ( is_scalar( $genre ) ) {
						$genres[] = array(
							'name'        => (string) $genre,
							'external_id' => '',
						);
					}
				}
			}
		}

		$labels       = array();
		$total_tracks = 0;
		$track_number = 0;
		$explicit     = false;
		$publisher    = '';
		$country      = '';
		$album_genres = array();

		$album_id = 'album' === $entity_type && isset( $detail['id'] ) ? (string) $detail['id'] : ( isset( $detail['album']['id'] ) ? (string) $detail['album']['id'] : '' );

		$country = $this->extract_country( $detail );

		// Track-specific fields.
		if ( 'track' === $entity_type ) {
			$track_number = isset( $detail['track_number'] ) ? (int) $detail['track_number'] : 0;
			$explicit     = ! empty( $detail['explicit'] );
			$total_tracks = isset( $detail['album']['total_tracks'] ) ? (int) $detail['album']['total_tracks'] : 0;
			$publisher    = isset( $detail['album']['label'] ) && is_scalar( $detail['album']['label'] ) ? (string) $detail['album']['label'] : '';
			if ( '' !== $album_id ) {
				$album = $this->get_json( self::API_ENDPOINT . 'albums/' . rawurlencode( $album_id ), $token );
				if ( null !== $album ) {
					if ( ! empty( $album['label'] ) && is_scalar( $album['label'] ) ) {
						$labels[]  = array(
							'name'        => (string) $album['label'],
							'external_id' => '',
						);
						$publisher = (string) $album['label'];
					}
					if ( isset( $album['total_tracks'] ) ) {
						$total_tracks = (int) $album['total_tracks'];
					}
					if ( isset( $album['genres'] ) && is_array( $album['genres'] ) ) {
						foreach ( $album['genres'] as $g ) {
							if ( is_scalar( $g ) && '' !== (string) $g ) {
								$album_genres[] = array(
									'name'        => (string) $g,
									'external_id' => '',
								);
							}
						}
					}
					if ( '' === $country ) {
						$country = $this->extract_country( $album );
					}
				}
			}
		} elseif ( 'album' === $entity_type ) {
			$total_tracks = isset( $detail['total_tracks'] ) ? (int) $detail['total_tracks'] : 0;
			$publisher    = isset( $detail['label'] ) && is_scalar( $detail['label'] ) ? (string) $detail['label'] : '';
			if ( ! empty( $detail['label'] ) && is_scalar( $detail['label'] ) ) {
				$labels[] = array(
					'name'        => (string) $detail['label'],
					'external_id' => '',
				);
			}
			if ( isset( $detail['genres'] ) && is_array( $detail['genres'] ) ) {
				foreach ( $detail['genres'] as $g ) {
					if ( is_scalar( $g ) && '' !== (string) $g ) {
						$album_genres[] = array(
							'name'        => (string) $g,
							'external_id' => '',
						);
					}
				}
			}
		}

		// Merge album genres into genres if present.
		$final_genres = ! empty( $genres ) ? $genres : $result->genres;
		if ( ! empty( $album_genres ) ) {
			$final_genres = array_merge( $final_genres, $album_genres );
		}

		return MetadataResult::from_array(
			array_merge(
				$result->to_array(),
				array(
					'artists'      => ! empty( $artists ) ? $artists : $result->artists,
					'genres'       => ! empty( $final_genres ) ? $final_genres : $result->genres,
					'labels'       => ! empty( $labels ) ? $labels : $result->labels,
					'total_tracks' => $total_tracks > 0 ? $total_tracks : $result->total_tracks,
					'track_number' => $track_number > 0 ? $track_number : $result->track_number,
					'explicit'     => $explicit || $result->explicit,
					'publisher'    => '' !== $publisher ? $publisher : $result->publisher,
					'country'      => '' !== $country ? $country : $result->country,
				)
			)
		);
	}

	private function search_type( MetadataQuery $query ): string {
		$types        = array(
			'album'           => 'album',
			'ep'              => 'album',
			'mix'             => 'album',
			'playlist'        => 'playlist',
			'podcast_show'    => 'show',
			'podcast_episode' => 'episode',
		);
		$release_type = $query->primary_release_type();
		return isset( $types[ $release_type ] ) ? $types[ $release_type ] : 'track';
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function normalize_item( array $item, string $entity_type ): MetadataResult {
		if ( 'track' === $entity_type ) {
			return $this->normalize_track( $item );
		}

		$images     = isset( $item['images'] ) && is_array( $item['images'] ) ? $item['images'] : array();
		$best_image = $this->best_image_candidate( $images );
		$cover_url  = $best_image instanceof CoverArtCandidate ? $best_image->url : $this->first_image( $images );
		$cover_w    = $best_image instanceof CoverArtCandidate ? $best_image->width : 0;
		$cover_h    = $best_image instanceof CoverArtCandidate ? $best_image->height : 0;
		$artists    = isset( $item['artists'] ) && is_array( $item['artists'] ) ? $item['artists'] : array();
		$owner      = '';
		if ( 'playlist' === $entity_type && isset( $item['owner']['display_name'] ) ) {
			$owner = (string) $item['owner']['display_name'];
		} elseif ( in_array( $entity_type, array( 'show', 'episode' ), true ) && isset( $item['publisher'] ) ) {
			$owner = (string) $item['publisher'];
		}
		if ( 'episode' === $entity_type && isset( $item['show']['publisher'] ) ) {
			$owner = (string) $item['show']['publisher'];
		}

		$total_tracks = 0;
		$publisher    = '';
		$genres       = array();
		if ( 'album' === $entity_type ) {
			$total_tracks = isset( $item['total_tracks'] ) ? (int) $item['total_tracks'] : 0;
			$publisher    = isset( $item['label'] ) && is_scalar( $item['label'] ) ? (string) $item['label'] : '';
			if ( isset( $item['genres'] ) && is_array( $item['genres'] ) ) {
				foreach ( $item['genres'] as $g ) {
					if ( is_scalar( $g ) && '' !== (string) $g ) {
						$genres[] = array(
							'name'        => (string) $g,
							'external_id' => '',
						);
					}
				}
			}
		}

		$country = $this->extract_country( $item );

		return MetadataResult::from_array(
			array(
				'provider'     => $this->name(),
				'entity_type'  => $entity_type,
				'reference_id' => isset( $item['id'] ) ? (string) $item['id'] : '',
				'title'        => isset( $item['name'] ) ? (string) $item['name'] : '',
				'artist'       => '' !== $owner ? $owner : implode( ', ', array_column( $artists, 'name' ) ),
				'artists'      => 'album' === $entity_type ? $artists : array(),
				'album'        => 'episode' === $entity_type && isset( $item['show']['name'] ) ? (string) $item['show']['name'] : ( isset( $item['name'] ) ? (string) $item['name'] : '' ),
				'release_date' => isset( $item['release_date'] ) ? (string) $item['release_date'] : '',
				'duration'     => isset( $item['duration_ms'] ) && is_numeric( $item['duration_ms'] ) ? (int) round( (int) $item['duration_ms'] / 1000 ) : 0,
				'cover_url'    => $cover_url,
				'cover_mime'   => '' !== $cover_url ? 'image/jpeg' : '',
				'cover_width'  => $cover_w,
				'cover_height' => $cover_h,
				'description'  => isset( $item['description'] ) ? (string) $item['description'] : '',
				'source_url'   => isset( $item['external_urls']['spotify'] ) ? (string) $item['external_urls']['spotify'] : '',
				'total_tracks' => $total_tracks,
				'publisher'    => $publisher,
				'genres'       => $genres,
				'labels'       => '' !== $publisher ? array(
					array(
						'name'        => $publisher,
						'external_id' => '',
					),
				) : array(),
				'country'      => $country,
			)
		);
	}

	/**
	 * @param array<string, mixed> $detail
	 */
	private function enrich_spoken_or_playlist( MetadataResult $result, array $detail, string $entity_type ): MetadataResult {
		$description = isset( $detail['html_description'] ) && is_scalar( $detail['html_description'] )
			? (string) $detail['html_description']
			: ( isset( $detail['description'] ) && is_scalar( $detail['description'] ) ? (string) $detail['description'] : $result->description );
		$publisher   = '';
		if ( isset( $detail['publisher'] ) ) {
			$publisher = (string) $detail['publisher'];
		} elseif ( isset( $detail['show']['publisher'] ) ) {
			$publisher = (string) $detail['show']['publisher'];
		} elseif ( isset( $detail['owner']['display_name'] ) ) {
			$publisher = (string) $detail['owner']['display_name'];
		}
		$artists = '' !== $publisher ? array(
			array(
				'name'        => $publisher,
				'external_id' => '',
			),
		) : $result->artists;

		$language = '';
		if ( isset( $detail['languages'] ) && is_array( $detail['languages'] ) && ! empty( $detail['languages'][0] ) ) {
			$language = (string) $detail['languages'][0];
		} elseif ( isset( $detail['language'] ) && is_scalar( $detail['language'] ) ) {
			$language = (string) $detail['language'];
		}

		$explicit = ! empty( $detail['explicit'] );
		$country  = $this->extract_country( $detail );

		$duration = 0;
		if ( isset( $detail['duration_ms'] ) && is_numeric( $detail['duration_ms'] ) ) {
			$duration = (int) round( (int) $detail['duration_ms'] / 1000 );
		}

		$release_date = '';
		if ( isset( $detail['release_date'] ) && is_scalar( $detail['release_date'] ) ) {
			$release_date = (string) $detail['release_date'];
		}

		return MetadataResult::from_array(
			array_merge(
				$result->to_array(),
				array(
					'entity_type'  => $entity_type,
					'description'  => $description,
					'artists'      => $artists,
					'publisher'    => '' !== $publisher ? $publisher : $result->publisher,
					'language'     => '' !== $language ? $language : $result->language,
					'explicit'     => $explicit || $result->explicit,
					'country'      => '' !== $country ? $country : $result->country,
					'duration'     => $duration > 0 ? $duration : $result->duration,
					'release_date' => '' !== $release_date ? $release_date : $result->release_date,
				)
			)
		);
	}

	/**
	 * Extract first available market as country (best-effort ISO2).
	 *
	 * @param array<string, mixed> $data
	 * @return string
	 */
	private function extract_country( array $data ): string {
		if ( isset( $data['available_markets'] ) && is_array( $data['available_markets'] ) && ! empty( $data['available_markets'][0] ) && is_scalar( $data['available_markets'][0] ) ) {
			return (string) $data['available_markets'][0];
		}
		if ( isset( $data['album']['available_markets'] ) && is_array( $data['album']['available_markets'] ) && ! empty( $data['album']['available_markets'][0] ) && is_scalar( $data['album']['available_markets'][0] ) ) {
			return (string) $data['album']['available_markets'][0];
		}
		return '';
	}

	/**
	 * Pick best image by width (largest first). Falls back to first.
	 *
	 * @param array<int, mixed> $images
	 */
	private function first_image( array $images ): string {
		return $this->best_image_url( $images );
	}

	/**
	 * @param array<int, mixed> $images
	 */
	private function best_image_url( array $images ): string {
		$best_url   = '';
		$best_width = -1;

		foreach ( $images as $image ) {
			if ( ! is_array( $image ) || empty( $image['url'] ) ) {
				continue;
			}
			$width = isset( $image['width'] ) && is_numeric( $image['width'] ) ? (int) $image['width'] : 0;
			if ( $width > $best_width ) {
				$best_width = $width;
				$best_url   = (string) $image['url'];
			}
			if ( '' === $best_url ) {
				$best_url = (string) $image['url'];
			}
		}

		// If no width info, fallback to first.
		if ( '' !== $best_url ) {
			return $best_url;
		}

		foreach ( $images as $image ) {
			if ( is_array( $image ) && ! empty( $image['url'] ) ) {
				return (string) $image['url'];
			}
		}

		return '';
	}

	/**
	 * Collect all candidates from Spotify images for scoring.
	 *
	 * @param array<int, mixed> $images
	 * @return CoverArtCandidate[]
	 */
	private function cover_candidates( array $images ): array {
		$candidates = array();
		foreach ( $images as $image ) {
			if ( ! is_array( $image ) || empty( $image['url'] ) ) {
				continue;
			}
			$candidates[] = new CoverArtCandidate(
				(string) $image['url'],
				isset( $image['width'] ) ? (int) $image['width'] : 0,
				isset( $image['height'] ) ? (int) $image['height'] : 0,
				$this->name(),
				true,
				false,
				false
			);
		}
		return $candidates;
	}

	/**
	 * @param array<int, mixed> $images
	 */
	private function best_image_candidate( array $images ): ?CoverArtCandidate {
		$candidates = $this->cover_candidates( $images );
		if ( empty( $candidates ) ) {
			return null;
		}
		$resolver = new CoverArtResolver();
		return $resolver->best( $candidates );
	}

	private function build_query( MetadataQuery $query, string $entity_type = 'track' ): string {
		$parts = array();
		if ( 'track' === $entity_type && '' !== $query->track ) {
			$parts[] = 'track:' . $query->track;
		} elseif ( 'album' === $entity_type && '' !== $query->track && '' === $query->album ) {
			$parts[] = 'album:' . $query->track;
		} elseif ( '' !== $query->track ) {
			$parts[] = $query->track;
		}
		if ( in_array( $entity_type, array( 'track', 'album' ), true ) && '' !== $query->artist ) {
			$parts[] = 'artist:' . $query->artist;
		} elseif ( '' !== $query->artist ) {
			$parts[] = $query->artist;
		}
		if ( in_array( $entity_type, array( 'track', 'album' ), true ) && '' !== $query->album ) {
			$parts[] = 'album:' . $query->album;
		} elseif ( '' !== $query->album ) {
			$parts[] = $query->album;
		}
		if ( in_array( $entity_type, array( 'track', 'album' ), true ) && '' !== $query->year ) {
			$parts[] = 'year:' . $query->year;
		}

		return trim( implode( ' ', $parts ) );
	}

	private function access_token(): string {
		if ( ! $this->is_enabled() ) {
			return '';
		}

		$token_key = self::TOKEN_TRANSIENT . '_' . md5( $this->client_id . ':' . $this->client_secret );
		$cached    = get_transient( $token_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = $this->http->post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout'             => 10,
				'limit_response_size' => 1024 * 1024,
				'headers'             => array(
					'Authorization'                => 'Basic ' . base64_encode( $this->client_id . ':' . $this->client_secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
									'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'                => array( 'grant_type' => 'client_credentials' ),
			)
		);

		if ( ! $response->is_success() ) {
			return '';
		}

		$data  = $response->json();
		$token = is_array( $data ) && ! empty( $data['access_token'] ) ? (string) $data['access_token'] : '';
		if ( '' !== $token ) {
			$ttl = is_array( $data ) && ! empty( $data['expires_in'] ) ? max( 60, (int) $data['expires_in'] - 60 ) : 3300;
			set_transient( $token_key, $token, $ttl );
		}

		return $token;
	}

	/**
	 * @param array<string, mixed> $track
	 */
	private function normalize_track( array $track ): MetadataResult {
		$artists = array();
		if ( ! empty( $track['artists'] ) && is_array( $track['artists'] ) ) {
			foreach ( $track['artists'] as $artist ) {
				if ( is_array( $artist ) && ! empty( $artist['name'] ) ) {
					$artists[] = (string) $artist['name'];
				}
			}
		}

		$best_image = null;
		$cover_url  = '';
		$cover_w    = 0;
		$cover_h    = 0;
		if ( ! empty( $track['album']['images'] ) && is_array( $track['album']['images'] ) ) {
			$best_image = $this->best_image_candidate( $track['album']['images'] );
			if ( $best_image instanceof CoverArtCandidate ) {
				$cover_url = $best_image->url;
				$cover_w   = $best_image->width;
				$cover_h   = $best_image->height;
			} else {
				$cover_url = $this->best_image_url( $track['album']['images'] );
			}
		}

		$country = $this->extract_country( $track );

		return MetadataResult::from_array(
			array(
				'provider'     => $this->name(),
				'entity_type'  => 'track',
				'reference_id' => isset( $track['id'] ) ? (string) $track['id'] : '',
				'title'        => isset( $track['name'] ) ? (string) $track['name'] : '',
				'artist'       => implode( ', ', $artists ),
				'artists'      => array_map(
					static function ( array $artist ): array {
						return array(
							'name'        => isset( $artist['name'] ) ? (string) $artist['name'] : '',
							'external_id' => isset( $artist['id'] ) ? (string) $artist['id'] : '',
						);
					},
					isset( $track['artists'] ) && is_array( $track['artists'] ) ? $track['artists'] : array()
				),
				'album'        => isset( $track['album']['name'] ) ? (string) $track['album']['name'] : '',
				'release_date' => isset( $track['album']['release_date'] ) ? (string) $track['album']['release_date'] : '',
				'duration'     => isset( $track['duration_ms'] ) && is_numeric( $track['duration_ms'] ) ? (int) round( (int) $track['duration_ms'] / 1000 ) : 0,
				'isrc'         => isset( $track['external_ids']['isrc'] ) ? (string) $track['external_ids']['isrc'] : '',
				'cover_url'    => $cover_url,
				'cover_mime'   => '' !== $cover_url ? 'image/jpeg' : '',
				'cover_width'  => $cover_w,
				'cover_height' => $cover_h,
				'source_url'   => isset( $track['external_urls']['spotify'] ) ? (string) $track['external_urls']['spotify'] : '',
				'track_number' => isset( $track['track_number'] ) ? (int) $track['track_number'] : 0,
				'total_tracks' => isset( $track['album']['total_tracks'] ) ? (int) $track['album']['total_tracks'] : 0,
				'explicit'     => ! empty( $track['explicit'] ),
				'publisher'    => isset( $track['album']['label'] ) && is_scalar( $track['album']['label'] ) ? (string) $track['album']['label'] : '',
				'country'      => $country,
			)
		);
	}

	/**
	 * @return array<string, mixed>|null
	 * @throws RateLimitException
	 */
	private function get_json( string $url, string $token ): ?array {
		$response = $this->http->get(
			$url,
			array(
				'timeout'             => 10,
				'limit_response_size' => 1024 * 1024,
				'headers'             => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( $response->is_error() ) {
			return null;
		}
		if ( $response->is_rate_limited() ) {
			throw new RateLimitException( 'Spotify rate limit reached.' );
		}
		if ( ! $response->is_success() ) {
			return null;
		}

		return $response->json();
	}
}
