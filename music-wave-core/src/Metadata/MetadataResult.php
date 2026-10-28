<?php
/**
 * One normalized music metadata suggestion.
 *
 * Centralizes sanitization and ISO normalization for country, language, date, barcode.
 * Designed for low memory footprint: const maps, early returns, bounded entity lists.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MetadataResult {
	/** @var string */
	public $provider = '';
	/** @var string */
	public $reference_id = '';
	/** @var string Provider entity kind (track, album, playlist, show, or episode). */
	public $entity_type = '';
	/** @var string */
	public $title = '';
	/** @var string */
	public $artist = '';
	/** @var string */
	public $album = '';
	/** @var string 4-digit release year. */
	public $year = '';
	/** @var string ISO release date (YYYY-MM-DD, YYYY-MM, or YYYY). */
	public $release_date = '';
	/** @var string */
	public $genre = '';
	/** @var int Duration in seconds (0 when unknown). */
	public $duration = 0;
	/** @var string International Standard Recording Code when known. */
	public $isrc = '';
	/** @var string */
	public $cover_url = '';
	/** @var string */
	public $cover_mime = '';
	/** @var int Cover width in pixels (0 when unknown). */
	public $cover_width = 0;
	/** @var int Cover height in pixels (0 when unknown). */
	public $cover_height = 0;
	/** @var string */
	public $source_url = '';
	/** @var string Sanitized release description or factual annotation. */
	public $description = '';
	/** @var int Track number. */
	public $track_number = 0;
	/** @var int Total tracks in album. */
	public $total_tracks = 0;
	/** @var string Country code (ISO-3166-1 alpha-2). */
	public $country = '';
	/** @var string Barcode / EAN / UPC. */
	public $barcode = '';
	/** @var bool Explicit flag. */
	public $explicit = false;
	/** @var string Publisher / record company. */
	public $publisher = '';
	/** @var string Release language (ISO-639-1). */
	public $language = '';

	/** @var array<int, array<string, mixed>> Structured artist identities. */
	public $artists = array();
	/** @var array<int, array<string, mixed>> Structured genre identities. */
	public $genres = array();
	/** @var array<int, array<string, mixed>> Structured label identities. */
	public $labels = array();
	/** @var array<int, array<string, mixed>> Explicit or confidently classified moods. */
	public $moods = array();
	/** @var array<int, array<string, mixed>> Unclassified provider tags; never auto-created as terms. */
	public $tags = array();

	/** ISO3 → ISO2 mapping (common). */
	private const COUNTRY_ISO3_MAP = array(
		'USA' => 'US',
		'GBR' => 'GB',
		'DEU' => 'DE',
		'FRA' => 'FR',
		'JPN' => 'JP',
		'CAN' => 'CA',
		'AUS' => 'AU',
		'ITA' => 'IT',
		'ESP' => 'ES',
		'NLD' => 'NL',
		'SWE' => 'SE',
		'NOR' => 'NO',
		'DNK' => 'DK',
		'FIN' => 'FI',
		'POL' => 'PL',
		'BRA' => 'BR',
		'KOR' => 'KR',
		'CHN' => 'CN',
		'IND' => 'IN',
		'RUS' => 'RU',
		'IRN' => 'IR',
	);

	/** Full country name → ISO2 (lowercase keys). */
	private const COUNTRY_NAME_MAP = array(
		'united states'            => 'US',
		'united states of america' => 'US',
		'usa'                      => 'US',
		'us'                       => 'US',
		'united kingdom'           => 'GB',
		'uk'                       => 'GB',
		'england'                  => 'GB',
		'great britain'            => 'GB',
		'germany'                  => 'DE',
		'deutschland'              => 'DE',
		'france'                   => 'FR',
		'japan'                    => 'JP',
		'canada'                   => 'CA',
		'australia'                => 'AU',
		'italy'                    => 'IT',
		'italia'                   => 'IT',
		'spain'                    => 'ES',
		'espana'                   => 'ES',
		'españa'                   => 'ES',
		'netherlands'              => 'NL',
		'holland'                  => 'NL',
		'sweden'                   => 'SE',
		'norway'                   => 'NO',
		'denmark'                  => 'DK',
		'finland'                  => 'FI',
		'poland'                   => 'PL',
		'brazil'                   => 'BR',
		'brasil'                   => 'BR',
		'south korea'              => 'KR',
		'korea'                    => 'KR',
		'china'                    => 'CN',
		'india'                    => 'IN',
		'russia'                   => 'RU',
		'iran'                     => 'IR',
		'ir'                       => 'IR',
		'turkey'                   => 'TR',
		'mexico'                   => 'MX',
		'argentina'                => 'AR',
		'belgium'                  => 'BE',
		'switzerland'              => 'CH',
		'austria'                  => 'AT',
		'ireland'                  => 'IE',
		'new zealand'              => 'NZ',
		'portugal'                 => 'PT',
		'greece'                   => 'GR',
		'czech republic'           => 'CZ',
		'czechia'                  => 'CZ',
		'hungary'                  => 'HU',
		'romania'                  => 'RO',
		'ukraine'                  => 'UA',
		'south africa'             => 'ZA',
		'israel'                   => 'IL',
		'saudi arabia'             => 'SA',
		'uae'                      => 'AE',
		'united arab emirates'     => 'AE',
		'europe'                   => 'EU',
		'worldwide'                => '',
		'world'                    => '',
	);

	/** ISO639-2/3 → ISO639-1 mapping. */
	private const LANGUAGE_ISO_MAP = array(
		'eng' => 'en',
		'spa' => 'es',
		'fre' => 'fr',
		'fra' => 'fr',
		'ger' => 'de',
		'deu' => 'de',
		'jpn' => 'ja',
		'ita' => 'it',
		'por' => 'pt',
		'rus' => 'ru',
		'ara' => 'ar',
		'fas' => 'fa',
		'per' => 'fa',
		'kor' => 'ko',
		'chi' => 'zh',
		'zho' => 'zh',
		'hin' => 'hi',
		'nld' => 'nl',
		'dut' => 'nl',
		'swe' => 'sv',
		'nor' => 'no',
		'dan' => 'da',
		'fin' => 'fi',
		'pol' => 'pl',
		'tur' => 'tr',
		'gre' => 'el',
		'ell' => 'el',
		'heb' => 'he',
		'cze' => 'cs',
		'ces' => 'cs',
		'hun' => 'hu',
		'rum' => 'ro',
		'ron' => 'ro',
		'ukr' => 'uk',
		'vie' => 'vi',
		'tha' => 'th',
		'ind' => 'id',
		'msa' => 'ms',
		'may' => 'ms',
	);

	/** Full language name → ISO639-1. */
	private const LANGUAGE_NAME_MAP = array(
		'english'    => 'en',
		'spanish'    => 'es',
		'french'     => 'fr',
		'german'     => 'de',
		'japanese'   => 'ja',
		'italian'    => 'it',
		'portuguese' => 'pt',
		'russian'    => 'ru',
		'arabic'     => 'ar',
		'persian'    => 'fa',
		'farsi'      => 'fa',
		'korean'     => 'ko',
		'chinese'    => 'zh',
		'hindi'      => 'hi',
		'dutch'      => 'nl',
		'swedish'    => 'sv',
		'norwegian'  => 'no',
		'danish'     => 'da',
		'finnish'    => 'fi',
		'polish'     => 'pl',
		'turkish'    => 'tr',
		'greek'      => 'el',
		'hebrew'     => 'he',
		'czech'      => 'cs',
		'hungarian'  => 'hu',
		'romanian'   => 'ro',
		'ukrainian'  => 'uk',
		'vietnamese' => 'vi',
		'thai'       => 'th',
		'indonesian' => 'id',
		'malay'      => 'ms',
	);

	/**
	 * Normalize provider-specific payloads into one result.
	 *
	 * @param array<string, mixed> $data Provider payload.
	 */
	public static function from_array( array $data ): self {
		$result               = new self();
		$result->provider     = isset( $data['provider'] ) ? sanitize_key( (string) $data['provider'] ) : '';
		$result->reference_id = isset( $data['reference_id'] ) ? sanitize_text_field( (string) $data['reference_id'] ) : '';
		$result->entity_type  = isset( $data['entity_type'] ) ? sanitize_key( (string) $data['entity_type'] ) : '';
		$result->title        = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';
		$result->artist       = isset( $data['artist'] ) ? sanitize_text_field( (string) $data['artist'] ) : '';
		$result->album        = isset( $data['album'] ) ? sanitize_text_field( (string) $data['album'] ) : '';
		$raw_release_date     = isset( $data['release_date'] ) ? (string) $data['release_date'] : '';
		$result->release_date = self::normalize_date( $raw_release_date );
		$result->genre        = isset( $data['genre'] ) ? sanitize_text_field( (string) $data['genre'] ) : '';
		$result->duration     = isset( $data['duration'] ) ? self::normalize_duration( $data['duration'] ) : 0;
		$result->isrc         = isset( $data['isrc'] ) ? strtoupper( self::clean_alnum( (string) $data['isrc'] ) ) : '';
		$result->cover_url    = isset( $data['cover_url'] ) ? self::normalize_cover_url( (string) $data['cover_url'] ) : '';
		$result->cover_mime   = isset( $data['cover_mime'] ) ? sanitize_text_field( (string) $data['cover_mime'] ) : '';
		$result->cover_width  = isset( $data['cover_width'] ) ? max( 0, (int) $data['cover_width'] ) : 0;
		$result->cover_height = isset( $data['cover_height'] ) ? max( 0, (int) $data['cover_height'] ) : 0;
		$result->source_url   = isset( $data['source_url'] ) ? (string) esc_url_raw( (string) $data['source_url'] ) : '';
		$result->description  = isset( $data['description'] ) ? self::normalize_description( $data['description'] ) : '';
		$result->track_number = isset( $data['track_number'] ) ? max( 0, (int) $data['track_number'] ) : 0;
		$result->total_tracks = isset( $data['total_tracks'] ) ? max( 0, (int) $data['total_tracks'] ) : 0;
		$result->country      = isset( $data['country'] ) ? self::normalize_country( (string) $data['country'] ) : '';
		$result->barcode      = isset( $data['barcode'] ) ? self::normalize_barcode( (string) $data['barcode'] ) : '';
		$result->explicit     = isset( $data['explicit'] ) ? (bool) $data['explicit'] : false;
		$result->publisher    = isset( $data['publisher'] ) ? sanitize_text_field( (string) $data['publisher'] ) : '';
		$result->language     = isset( $data['language'] ) ? self::normalize_language( (string) $data['language'] ) : '';
		$result->artists      = isset( $data['artists'] ) ? self::normalize_entities( $data['artists'] ) : array();
		$result->genres       = isset( $data['genres'] ) ? self::normalize_entities( $data['genres'] ) : array();
		$result->labels       = isset( $data['labels'] ) ? self::normalize_entities( $data['labels'] ) : array();
		$result->moods        = isset( $data['moods'] ) ? self::normalize_entities( $data['moods'] ) : array();
		$result->tags         = isset( $data['tags'] ) ? self::normalize_entities( $data['tags'] ) : array();

		if ( empty( $result->artists ) && '' !== $result->artist ) {
			$result->artists = self::entities_from_names( $result->artist );
		}
		if ( empty( $result->genres ) && '' !== $result->genre ) {
			$result->genres = self::entities_from_names( $result->genre );
		}
		if ( '' === $result->artist && ! empty( $result->artists ) ) {
			$result->artist = implode( ', ', array_column( $result->artists, 'name' ) );
		}
		if ( '' === $result->genre && ! empty( $result->genres ) ) {
			$result->genre = implode( ', ', array_column( $result->genres, 'name' ) );
		}

		$year         = isset( $data['year'] ) ? self::normalize_year( (string) $data['year'] ) : '';
		$result->year = '' !== $year ? $year : self::normalize_year( $raw_release_date );

		return $result;
	}

	public function is_usable(): bool {
		return '' !== $this->title && ( '' !== $this->artist || '' !== $this->album );
	}

	public function date_for_meta(): string {
		return $this->release_date;
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'provider'     => $this->provider,
			'reference_id' => $this->reference_id,
			'entity_type'  => $this->entity_type,
			'title'        => $this->title,
			'artist'       => $this->artist,
			'album'        => $this->album,
			'year'         => $this->year,
			'release_date' => $this->release_date,
			'genre'        => $this->genre,
			'duration'     => $this->duration,
			'isrc'         => $this->isrc,
			'cover_url'    => $this->cover_url,
			'cover_mime'   => $this->cover_mime,
			'cover_width'  => $this->cover_width,
			'cover_height' => $this->cover_height,
			'source_url'   => $this->source_url,
			'description'  => $this->description,
			'track_number' => $this->track_number,
			'total_tracks' => $this->total_tracks,
			'country'      => $this->country,
			'barcode'      => $this->barcode,
			'explicit'     => $this->explicit,
			'publisher'    => $this->publisher,
			'language'     => $this->language,
			'artists'      => $this->artists,
			'genres'       => $this->genres,
			'labels'       => $this->labels,
			'moods'        => $this->moods,
			'tags'         => $this->tags,
		);
	}

	private static function normalize_year( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/(\d{4})/', $value, $m ) ) {
			$y = (int) $m[1];
			if ( $y >= 1000 && $y <= 9999 ) {
				return (string) $y;
			}
		}
		return '';
	}

	private static function normalize_date( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/(\d{4})-(\d{2})-(\d{2})/', $value, $m ) ) {
			$y  = (int) $m[1];
			$mo = (int) $m[2];
			$d  = (int) $m[3];
			if ( $y >= 1000 && $y <= 9999 && checkdate( $mo, $d, $y ) ) {
				return sprintf( '%04d-%02d-%02d', $y, $mo, $d );
			}
		}
		return '';
	}

	private static function normalize_country( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$upper = strtoupper( $value );
		if ( 1 === preg_match( '/^[A-Z]{2}$/', $upper ) ) {
			return 'UK' === $upper ? 'GB' : $upper;
		}
		if ( isset( self::COUNTRY_ISO3_MAP[ $upper ] ) ) {
			return self::COUNTRY_ISO3_MAP[ $upper ];
		}
		if ( 1 === preg_match( '/^[A-Z]{3}$/', $upper ) ) {
			return $upper; // Keep unknown ISO3 to avoid data loss.
		}
		$lower = strtolower( $value );
		if ( isset( self::COUNTRY_NAME_MAP[ $lower ] ) ) {
			return self::COUNTRY_NAME_MAP[ $lower ];
		}
		if ( false !== strpos( $lower, ',' ) ) {
			$first = trim( explode( ',', $lower )[0] );
			if ( isset( self::COUNTRY_NAME_MAP[ $first ] ) ) {
				return self::COUNTRY_NAME_MAP[ $first ];
			}
		}
		// Fallback: keep uppercased up to 20 chars, avoids storing huge free-text.
		return strlen( $upper ) <= 20 ? $upper : substr( $upper, 0, 20 );
	}

	private static function normalize_language( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$lower = strtolower( $value );
		if ( 1 === preg_match( '/^[a-z]{2}$/', $lower ) ) {
			return $lower;
		}
		if ( isset( self::LANGUAGE_ISO_MAP[ $lower ] ) ) {
			return self::LANGUAGE_ISO_MAP[ $lower ];
		}
		if ( 1 === preg_match( '/^[a-z]{3}$/', $lower ) ) {
			return $lower; // Keep unknown 3-letter to avoid loss.
		}
		if ( isset( self::LANGUAGE_NAME_MAP[ $lower ] ) ) {
			return self::LANGUAGE_NAME_MAP[ $lower ];
		}
		if ( false !== strpos( $lower, '-' ) ) {
			$first = explode( '-', $lower )[0];
			if ( 1 === preg_match( '/^[a-z]{2}$/', $first ) ) {
				return $first;
			}
			if ( isset( self::LANGUAGE_ISO_MAP[ $first ] ) ) {
				return self::LANGUAGE_ISO_MAP[ $first ];
			}
		}
		if ( false !== strpos( $lower, '_' ) ) {
			$first = explode( '_', $lower )[0];
			if ( 1 === preg_match( '/^[a-z]{2}$/', $first ) ) {
				return $first;
			}
		}
		return sanitize_text_field( $value );
	}

	private static function normalize_barcode( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$clean = preg_replace( '/[^0-9A-Za-z]/', '', $value );
		return $clean ? sanitize_text_field( $clean ) : sanitize_text_field( $value );
	}

	private static function normalize_duration( $value ): int {
		if ( ! is_scalar( $value ) || '' === (string) $value ) {
			return 0;
		}
		return max( 0, (int) $value );
	}

	private static function clean_alnum( string $value ): string {
		return (string) preg_replace( '/[^A-Za-z0-9]/', '', $value );
	}

	private static function normalize_description( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return trim( substr( wp_kses_post( (string) $value ), 0, 12000 ) );
	}

	/** @param mixed $value @return array<int, array<string, mixed>> */
	private static function normalize_entities( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$entities = array();
		$seen     = array();
		foreach ( array_slice( $value, 0, 20 ) as $entity ) {
			if ( is_scalar( $entity ) ) {
				$entity = array( 'name' => (string) $entity );
			}
			if ( ! is_array( $entity ) || empty( $entity['name'] ) || ! is_scalar( $entity['name'] ) ) {
				continue;
			}
			$name = sanitize_text_field( (string) $entity['name'] );
			if ( '' === $name ) {
				continue;
			}
			$key = strtolower( $name );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$aliases      = isset( $entity['aliases'] ) && is_array( $entity['aliases'] )
				? array_values( array_filter( array_map( 'sanitize_text_field', $entity['aliases'] ) ) )
				: array();
			$entities[]   = array(
				'name'        => $name,
				'external_id' => isset( $entity['external_id'] ) && is_scalar( $entity['external_id'] ) ? sanitize_text_field( (string) $entity['external_id'] ) : '',
				'aliases'     => array_values( array_unique( $aliases ) ),
			);
			$seen[ $key ] = true;
		}
		return $entities;
	}

	/** @return array<int, array<string, mixed>> */
	private static function entities_from_names( string $value ): array {
		$names = preg_split( '/\s*,\s*/', $value );
		return self::normalize_entities( is_array( $names ) ? $names : array( $value ) );
	}

	private static function normalize_cover_url( string $value ): string {
		$url = (string) esc_url_raw( $value );
		if ( 0 === stripos( $url, 'http://' ) ) {
			$url = 'https://' . substr( $url, 7 );
		} elseif ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		return (string) esc_url_raw( $url, array( 'https' ) );
	}
}
