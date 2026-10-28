<?php
/**
 * Last.fm provider — optional keyed, provides artist biography and tags.
 *
 * Phase 5: enriches description from Last.fm artist.getInfo.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Metadata;

use ManaCore\MusicWave\Core\Metadata\Http\HttpClientInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LastFmProvider extends AbstractMetadataProvider implements MetadataEnrichmentProvider {
	private const ENDPOINT = 'https://ws.audioscrobbler.com/2.0/';

	/** @var string */
	private $api_key;

	public function __construct( string $api_key = '', ?HttpClientInterface $http = null ) {
		parent::__construct( $http );
		$this->api_key = $api_key;
	}

	public function name(): string {
		return 'lastfm';
	}

	public function is_enabled(): bool {
		return '' !== $this->api_key;
	}

	public function priority(): int {
		return 50;
	}

	public function search( MetadataQuery $query ): array {
		if ( ! $this->is_enabled() || ! $query->is_valid() ) {
			return array();
		}

		// Last.fm search is track or artist based. Use track.search.
		$term = trim( $query->track . ' ' . $query->artist );
		if ( '' === $term ) {
			$term = $query->free_text;
		}
		if ( '' === $term ) {
			return array();
		}

		$url = $this->build_url(
			self::ENDPOINT,
			array(
				'method'  => 'track.search',
				'track'   => $term,
				'api_key' => $this->api_key,
				'format'  => 'json',
				'limit'   => 10,
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
		if ( null === $data || ! isset( $data['results']['trackmatches']['track'] ) || ! is_array( $data['results']['trackmatches']['track'] ) ) {
			return array();
		}

		$out = array();
		foreach ( $data['results']['trackmatches']['track'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$result = $this->normalize_track( $item );
			if ( $result->is_usable() ) {
				$out[] = $result;
			}
		}

		return $out;
	}

	public function cover_for( MetadataResult $result ): string {
		return $result->cover_url; // Last.fm images are low quality, but keep if present.
	}

	public function enrich( MetadataResult $result ): MetadataResult {
		if ( ! $this->is_enabled() ) {
			return $result;
		}
		if ( '' === $result->artist ) {
			return $result;
		}

		// Fetch artist info for biography.
		$url = $this->build_url(
			self::ENDPOINT,
			array(
				'method'      => 'artist.getinfo',
				'artist'      => $result->artist,
				'api_key'     => $this->api_key,
				'format'      => 'json',
				'autocorrect' => 1,
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
		if ( ! is_array( $data ) || ! isset( $data['artist'] ) || ! is_array( $data['artist'] ) ) {
			return $result;
		}

		$artist_data = $data['artist'];
		$bio         = '';
		if ( isset( $artist_data['bio']['content'] ) && is_scalar( $artist_data['bio']['content'] ) ) {
			$bio = (string) $artist_data['bio']['content'];
			// Clean Last.fm attribution.
			$bio = trim( preg_replace( '/<a href=\"https:\/\/www\.last\.fm.*$/s', '', $bio ) );
		} elseif ( isset( $artist_data['bio']['summary'] ) && is_scalar( $artist_data['bio']['summary'] ) ) {
			$bio = (string) $artist_data['bio']['summary'];
		}

		$tags = array();
		if ( isset( $artist_data['tags']['tag'] ) && is_array( $artist_data['tags']['tag'] ) ) {
			foreach ( $artist_data['tags']['tag'] as $t ) {
				if ( is_array( $t ) && ! empty( $t['name'] ) ) {
					$tags[] = array(
						'name'        => (string) $t['name'],
						'external_id' => '',
					);
				}
			}
		}

		// If result already has description, keep it; else use bio as description for artist context.
		$description = $result->description;
		if ( '' === trim( wp_strip_all_tags( $description ) ) && '' !== $bio ) {
			$description = $bio;
		}

		return MetadataResult::from_array(
			array_merge(
				$result->to_array(),
				array(
					'description' => $description,
					'tags'        => ! empty( $tags ) ? array_merge( $result->tags, $tags ) : $result->tags,
				)
			)
		);
	}

	/**
	 * @param array<string, mixed> $track
	 */
	private function normalize_track( array $track ): MetadataResult {
		$title  = isset( $track['name'] ) ? (string) $track['name'] : '';
		$artist = isset( $track['artist'] ) ? (string) $track['artist'] : '';

		$cover = '';
		if ( isset( $track['image'] ) && is_array( $track['image'] ) ) {
			// Last.fm images array with size extralarge etc.
			foreach ( array_reverse( $track['image'] ) as $img ) {
				if ( is_array( $img ) && ! empty( $img['#text'] ) && is_scalar( $img['#text'] ) ) {
					$cover = (string) $img['#text'];
					break;
				}
			}
		}

		return MetadataResult::from_array(
			array(
				'provider'     => $this->name(),
				'entity_type'  => 'track',
				'reference_id' => isset( $track['mbid'] ) && '' !== $track['mbid'] ? (string) $track['mbid'] : $title . ' - ' . $artist,
				'title'        => $title,
				'artist'       => $artist,
				'album'        => '',
				'cover_url'    => $cover,
				'cover_mime'   => '' !== $cover ? 'image/jpeg' : '',
				'source_url'   => isset( $track['url'] ) ? (string) $track['url'] : '',
			)
		);
	}
}
