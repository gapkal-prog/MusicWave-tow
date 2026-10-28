<?php
/**
 * Optional keyed Discogs provider.
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

final class DiscogsProvider extends AbstractMetadataProvider implements MetadataEnrichmentProvider {
	private const SEARCH_ENDPOINT  = 'https://api.discogs.com/database/search';
	private const RELEASE_ENDPOINT = 'https://api.discogs.com/releases/';

	/** @var string */
	private $token;

	public function __construct( string $token = '', ?HttpClientInterface $http = null ) {
		parent::__construct( $http );
		$this->token = $token;
	}

	public function name(): string {
		return 'discogs';
	}

	public function is_enabled(): bool {
		return '' !== $this->token;
	}

	public function priority(): int {
		return 20;
	}

	public function search( MetadataQuery $query ): array {
		if ( ! $this->is_enabled() || ! $query->is_valid() || $query->is_podcast() || 'playlist' === $query->primary_release_type() ) {
			return array();
		}

		$args = array(
			'type'     => 'release',
			'per_page' => 8,
			'q'        => trim( implode( ' ', array_filter( array( $query->track, $query->artist, $query->album ) ) ) ),
		);
		if ( '' !== $query->artist ) {
			$args['artist'] = $query->artist;
		}
		if ( '' !== $query->track ) {
			$args['release_title'] = $query->track;
		}
		if ( '' !== $query->year ) {
			$args['year'] = $query->year;
		}
		$format = $this->format_for_release_type( $query->primary_release_type() );
		if ( '' !== $format ) {
			$args['format'] = $format;
		}

		$url      = $this->build_url( self::SEARCH_ENDPOINT, array_map( 'strval', $args ) );
		$response = $this->http->get(
			$url,
			array(
				'timeout'             => 10,
				'limit_response_size' => 1024 * 1024,
				'headers'             => array(
					'Authorization' => 'Discogs token=' . $this->token,
				),
			)
		);

		$data = $this->decode_json( $response );
		if ( null === $data || ! isset( $data['results'] ) || ! is_array( $data['results'] ) ) {
			throw new \RuntimeException( 'Discogs returned an invalid search payload.' );
		}

		$out = array();
		foreach ( $data['results'] as $result ) {
			$item = $this->normalize_result( is_array( $result ) ? $result : array() );
			if ( $item->is_usable() ) {
				$out[] = $item;
			}
		}

		return $out;
	}

	private function format_for_release_type( string $release_type ): string {
		$formats = array(
			'single' => 'Single',
			'ep'     => 'EP',
			'album'  => 'Album',
			'mix'    => 'Mixed',
		);
		return isset( $formats[ $release_type ] ) ? $formats[ $release_type ] : '';
	}

	public function cover_for( MetadataResult $result ): string {
		return $result->cover_url;
	}

	/**
	 * @throws RateLimitException
	 */
	public function enrich( MetadataResult $result ): MetadataResult {
		if ( '' === $result->reference_id ) {
			return $result;
		}

		$url      = trailingslashit( self::RELEASE_ENDPOINT ) . rawurlencode( $result->reference_id );
		$response = $this->http->get(
			$url,
			array(
				'timeout'             => 10,
				'limit_response_size' => 1024 * 1024,
				'headers'             => array(
					'Authorization' => 'Discogs token=' . $this->token,
				),
			)
		);

		if ( $response->is_error() ) {
			return $result;
		}
		if ( $response->is_rate_limited() ) {
			throw new RateLimitException( 'Discogs rate limit reached.' );
		}
		if ( ! $response->is_success() ) {
			return $result;
		}

		$data = $response->json();
		if ( ! is_array( $data ) ) {
			return $result;
		}

		$artists = $this->entities( isset( $data['artists'] ) && is_array( $data['artists'] ) ? $data['artists'] : array() );
		$labels  = $this->entities( isset( $data['labels'] ) && is_array( $data['labels'] ) ? $data['labels'] : array() );
		$genres  = array_merge(
			$this->entities( isset( $data['genres'] ) && is_array( $data['genres'] ) ? $data['genres'] : array() ),
			$this->entities( isset( $data['styles'] ) && is_array( $data['styles'] ) ? $data['styles'] : array() )
		);

		// Phase 2: improve cover — Discogs release has images[] with primary type and full-res uri.
		$best_candidate = $this->best_cover_candidate_from_images( isset( $data['images'] ) && is_array( $data['images'] ) ? $data['images'] : array() );
		$cover_url      = $best_candidate instanceof CoverArtCandidate ? $best_candidate->url : $result->cover_url;
		$cover_w        = $best_candidate instanceof CoverArtCandidate ? $best_candidate->width : $result->cover_width;
		$cover_h        = $best_candidate instanceof CoverArtCandidate ? $best_candidate->height : $result->cover_height;
		if ( '' === $cover_url ) {
			$cover_url = $result->cover_url;
		}

		// Phase 3: more complete info — country, barcode, publisher, release date.
		$country = $result->country;
		if ( isset( $data['country'] ) && is_scalar( $data['country'] ) && '' !== trim( (string) $data['country'] ) ) {
			$country = (string) $data['country'];
		}
		$publisher = '';
		if ( isset( $data['labels'][0]['name'] ) && is_scalar( $data['labels'][0]['name'] ) ) {
			$publisher = (string) $data['labels'][0]['name'];
		}
		$barcode = $result->barcode;
		if ( isset( $data['identifiers'] ) && is_array( $data['identifiers'] ) ) {
			foreach ( $data['identifiers'] as $idf ) {
				if ( ! is_array( $idf ) ) {
					continue;
				}
				$type = isset( $idf['type'] ) ? strtolower( (string) $idf['type'] ) : '';
				if ( in_array( $type, array( 'barcode', 'upc', 'ean' ), true ) && ! empty( $idf['value'] ) ) {
					$barcode = (string) $idf['value'];
					break;
				}
			}
		}
		$release_date = $result->release_date;
		if ( isset( $data['released'] ) && is_scalar( $data['released'] ) && '' !== trim( (string) $data['released'] ) ) {
			// Discogs released can be YYYY-MM-DD, YYYY-MM, or YYYY.
			$release_date = (string) $data['released'];
		} elseif ( isset( $data['year'] ) && '' !== trim( (string) $data['year'] ) && '' === $release_date ) {
			// Fallback to year if released missing.
			$release_date = (string) $data['year'];
		}
		$year = $result->year;
		if ( '' === $year && isset( $data['year'] ) ) {
			$year = (string) $data['year'];
		}
		// Also try to get barcode from format? Discogs sometimes has barcode in identifiers already handled.
		// Ensure we keep country even if it's full name — normalization happens in MetadataResult.

		return MetadataResult::from_array(
			array_merge(
				$result->to_array(),
				array(
					'description'  => isset( $data['notes'] ) && is_scalar( $data['notes'] ) ? $this->clean_notes( (string) $data['notes'] ) : $result->description,
					'artists'      => ! empty( $artists ) ? $artists : $result->artists,
					'genres'       => ! empty( $genres ) ? $genres : $result->genres,
					'labels'       => ! empty( $labels ) ? $labels : $result->labels,
					'cover_url'    => $cover_url,
					'cover_mime'   => '' !== $cover_url ? 'image/jpeg' : $result->cover_mime,
					'cover_width'  => $cover_w,
					'cover_height' => $cover_h,
					'country'      => $country,
					'barcode'      => $barcode,
					'publisher'    => '' !== $publisher ? $publisher : $result->publisher,
					'release_date' => $release_date,
					'year'         => $year,
				)
			)
		);
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private function normalize_result( array $result ): MetadataResult {
		$title  = isset( $result['title'] ) ? (string) $result['title'] : '';
		$artist = '';
		$album  = $title;
		if ( false !== strpos( $title, ' - ' ) ) {
			list( $artist, $album ) = array_map( 'trim', explode( ' - ', $title, 2 ) );
		}

		$cover   = '';
		$cover_w = 0;
		$cover_h = 0;
		if ( ! empty( $result['cover_image'] ) ) {
			$cover   = (string) $result['cover_image'];
			$cover_w = 600;
			$cover_h = 600;
		} elseif ( ! empty( $result['thumb'] ) ) {
			$cover   = (string) $result['thumb'];
			$cover_w = 150;
			$cover_h = 150;
		}
		$source_url = isset( $result['uri'] ) ? (string) $result['uri'] : '';
		if ( 0 === strpos( $source_url, '/' ) ) {
			$source_url = 'https://www.discogs.com' . $source_url;
		}

		return MetadataResult::from_array(
			array(
				'provider'     => $this->name(),
				'reference_id' => isset( $result['id'] ) ? (string) $result['id'] : '',
				'title'        => $album,
				'artist'       => $artist,
				'album'        => $album,
				'year'         => isset( $result['year'] ) ? (string) $result['year'] : '',
				'genres'       => array_merge(
					$this->entities( isset( $result['genre'] ) && is_array( $result['genre'] ) ? $result['genre'] : array() ),
					$this->entities( isset( $result['style'] ) && is_array( $result['style'] ) ? $result['style'] : array() )
				),
				'labels'       => $this->entities( isset( $result['label'] ) && is_array( $result['label'] ) ? $result['label'] : array() ),
				'cover_url'    => $cover,
				'cover_mime'   => '' !== $cover ? 'image/jpeg' : '',
				'cover_width'  => $cover_w,
				'cover_height' => $cover_h,
				'source_url'   => $source_url,
			)
		);
	}

	/**
	 * @param array<int, mixed> $items
	 * @return array<int, array<string, string>>
	 */
	private function entities( array $items ): array {
		$entities = array();
		foreach ( $items as $item ) {
			if ( is_scalar( $item ) ) {
				$item = array( 'name' => (string) $item );
			}
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

	private function clean_notes( string $notes ): string {
		$notes = (string) preg_replace( '/\[url=[^\]]+\](.*?)\[\/url\]/is', '$1', $notes );
		$notes = (string) preg_replace( '/\[(?:a|l|r)=([^\]]+)\]/i', '$1', $notes );

		return (string) preg_replace( '/\[\/?[a-z][^\]]*\]/i', '', $notes );
	}

	/**
	 * @param array<int, mixed> $images
	 */
	private function best_cover_candidate_from_images( array $images ): ?CoverArtCandidate {
		if ( empty( $images ) ) {
			return null;
		}

		$candidates = array();
		foreach ( $images as $img ) {
			if ( ! is_array( $img ) ) {
				continue;
			}
			$uri = isset( $img['uri'] ) && is_string( $img['uri'] ) && '' !== $img['uri'] ? $img['uri'] : ( isset( $img['resource_url'] ) ? (string) $img['resource_url'] : '' );
			if ( '' === $uri ) {
				continue;
			}
			$type         = isset( $img['type'] ) ? strtolower( (string) $img['type'] ) : '';
			$width        = isset( $img['width'] ) ? (int) $img['width'] : 0;
			$candidates[] = new CoverArtCandidate(
				$uri,
				$width,
				isset( $img['height'] ) ? (int) $img['height'] : 0,
				$this->name(),
				true,
				'primary' === $type,
				false
			);
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		$resolver = new CoverArtResolver();
		return $resolver->best( $candidates );
	}
}
