<?php
/**
 * Provider fallback chain with transient caching.
 *
 * Phase 1 refactor: delegates cover import to CoverImportService (SRP).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MetadataResolver {
	/** @var MetadataProvider[] */
	private $providers = array();

	/** @var CoverImportService */
	private $cover_importer;

	/** Circuit breaker: failures threshold before opening. */
	private const CIRCUIT_FAILURE_THRESHOLD = 3;

	/** Circuit breaker: how long to keep circuit open (seconds). */
	private const CIRCUIT_OPEN_TTL = 300; // 5 minutes.

	/** Circuit breaker: window to count failures. */
	private const CIRCUIT_WINDOW = 300;

	/**
	 * @param MetadataProvider[]      $providers      Registered providers.
	 * @param CoverImportService|null $cover_importer Dedicated cover importer.
	 * @param CoverArtResolver|null   $cover_resolver Optional legacy cover resolver.
	 */
	public function __construct( array $providers = array(), ?CoverImportService $cover_importer = null, ?CoverArtResolver $cover_resolver = null ) {
		unset( $cover_resolver );
		foreach ( $providers as $provider ) {
			if ( $provider instanceof MetadataProvider ) {
				$this->providers[] = $provider;
			}
		}
		$this->cover_importer = $cover_importer ?? new CoverImportService();
		$this->sort();
	}

	public function add( MetadataProvider $provider ): void {
		$this->providers[] = $provider;
		$this->sort();
	}

	/**
	 * Search down the priority chain.
	 *
	 * @return array<string, mixed>
	 */
	public function search( MetadataQuery $query, int $limit = 6 ): array {
		if ( ! $query->is_valid() ) {
			return array(
				'success' => false,
				'code'    => 'invalid_query',
				'message' => __( 'عنوان قطعه یا نام هنرمند را برای جست‌وجو وارد کنید.', 'music-wave-core' ),
				'results' => array(),
			);
		}

		$limit  = max( 1, min( 15, $limit ) );
		$cached = $this->cache_get( $query, $limit );
		if ( is_array( $cached ) ) {
			$cached['cached'] = true;
			return $cached;
		}

		$attempts = array();
		$errors   = array();
		foreach ( $this->enabled() as $provider ) {
			$name = $provider->name();
			if ( $this->is_circuit_open( $name ) ) {
				$errors[ $name ] = 'circuit_open';
				continue;
			}
			$attempts[] = $name;
			try {
				$results = $provider->search( $query );
				$this->record_success( $name );
			} catch ( RateLimitException $e ) {
				$errors[ $name ] = 'rate_limited';
				$this->record_failure( $name );
				continue;
			} catch ( \Throwable $e ) {
				$errors[ $name ] = 'unavailable';
				$this->record_failure( $name );
				continue;
			}

			if ( empty( $results ) ) {
				continue;
			}

			$results = array_slice( array_values( $results ), 0, $limit );

			$payload = array(
				'success'  => true,
				'cached'   => false,
				'provider' => $name,
				'label'    => $this->provider_label( $name ),
				'attempts' => $attempts,
				'errors'   => $errors,
				'results'  => array_map(
					static function ( MetadataResult $result ): array {
						return $result->to_array();
					},
					$results
				),
			);
			$this->cache_set( $query, $limit, $payload );

			return $payload;
		}

		if ( ! empty( $errors ) ) {
			return array(
				'success'  => false,
				'cached'   => false,
				'code'     => 'provider_unavailable',
				'attempts' => $attempts,
				'errors'   => $errors,
				'message'  => __( 'سرویس فراداده در دسترس نیست یا درخواست‌ها محدود شده‌اند. اتصال خروجی HTTPS و تنظیمات سرویس را بررسی کنید و کمی بعد دوباره امتحان کنید.', 'music-wave-core' ),
				'results'  => array(),
			);
		}

		return array(
			'success'  => false,
			'cached'   => false,
			'code'     => 'no_results',
			'attempts' => $attempts,
			'message'  => __( 'هیچ منطبقی یافت نشد. عنوان یا املای هنرمند دیگری را امتحان کنید.', 'music-wave-core' ),
			'results'  => array(),
		);
	}

	public function enrich( MetadataResult $result, array $release_types = array() ): MetadataResult {
		if ( '' === $result->provider || '' === $result->reference_id ) {
			return $this->with_factual_description( $result, $release_types );
		}

		$cache_key = 'mw_meta_detail_v3_' . md5( wp_json_encode( array( $result->to_array(), $release_types ) ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $this->with_factual_description( MetadataResult::from_array( array_merge( $result->to_array(), $cached ) ), $release_types );
		}

		foreach ( $this->enabled() as $provider ) {
			if ( $provider->name() !== $result->provider ) {
				continue;
			}

			// Circuit breaker check for enrich as well.
			if ( $this->is_circuit_open( $provider->name() ) ) {
				return $this->with_factual_description( $result, $release_types );
			}

			// Per requirement: cover from same source only, quality ignored. If source has cover, show it.
			if ( '' === $result->cover_url ) {
				try {
					$url = $provider->cover_for( $result );
					if ( '' !== $url ) {
						$result->cover_url = $url;
						if ( 0 === $result->cover_width ) {
							$result->cover_width = $this->infer_width_from_url( $url );
						}
					}
				} catch ( \Throwable $e ) {
					$result->cover_url = '';
				}
			}

			try {
				$enriched = $provider instanceof MetadataEnrichmentProvider ? $provider->enrich( $result ) : $result;

				// If enrich cleared cover, restore same-source cover.
				if ( '' === $enriched->cover_url && '' !== $result->cover_url ) {
					$enriched->cover_url    = $result->cover_url;
					$enriched->cover_width  = $result->cover_width;
					$enriched->cover_height = $result->cover_height;
				}

				$this->record_success( $provider->name() );
				set_transient( $cache_key, $enriched->to_array(), 12 * HOUR_IN_SECONDS );
				return $this->with_factual_description( $enriched, $release_types );
			} catch ( RateLimitException $e ) {
				$this->record_failure( $provider->name() );
				break;
			} catch ( \Throwable $e ) {
				$this->record_failure( $provider->name() );
				break;
			}
		}

		// No cross-provider fallback per requirement: cover must be from same source.

		return $this->with_factual_description( $result, $release_types );
	}

	private function infer_width_from_url( string $url ): int {
		if ( false !== strpos( $url, '-1200' ) ) {
			return 1200;
		}
		if ( false !== strpos( $url, '-500' ) || false !== strpos( $url, '-large' ) ) {
			return 500;
		}
		if ( false !== strpos( $url, 'i.scdn.co' ) ) {
			return 640;
		}
		if ( false !== strpos( $url, 'discogs.com' ) ) {
			return 600;
		}
		return 0;
	}

	/**
	 * Download a remote cover into the media library.
	 * Delegates to CoverImportService (Phase 1 SRP).
	 *
	 * @return array<string, int|string>|\WP_Error
	 */
	public function import_cover( string $url, string $title = '', int $post_id = 0 ) {
		return $this->cover_importer->import( $url, $title, $post_id );
	}

	/**
	 * @return MetadataProvider[]
	 */
	private function enabled(): array {
		$out = array();
		foreach ( $this->providers as $provider ) {
			if ( $provider->is_enabled() ) {
				$out[] = $provider;
			}
		}

		return $out;
	}

	private function sort(): void {
		usort(
			$this->providers,
			static function ( MetadataProvider $a, MetadataProvider $b ): int {
				return $a->priority() <=> $b->priority();
			}
		);
	}

	private function provider_label( string $name ): string {
		$labels = array(
			'spotify'     => __( 'Spotify', 'music-wave-core' ),
			'discogs'     => __( 'دیسکاگ', 'music-wave-core' ),
			'musicbrainz' => __( 'MusicBrainz + آرشیو Cover Art', 'music-wave-core' ),
			'itunes'      => __( 'iTunes', 'music-wave-core' ),
			'deezer'      => __( 'Deezer', 'music-wave-core' ),
			'lastfm'      => __( 'Last.fm', 'music-wave-core' ),
		);

		return isset( $labels[ $name ] ) ? $labels[ $name ] : $name;
	}

	/** @return array<string, mixed>|null */
	private function cache_get( MetadataQuery $query, int $limit ): ?array {
		$value = get_transient( $this->cache_key( $query, $limit ) );
		return is_array( $value ) ? $value : null;
	}

	/** @param array<string, mixed> $payload */
	private function cache_set( MetadataQuery $query, int $limit, array $payload ): void {
		set_transient( $this->cache_key( $query, $limit ), $payload, 30 * MINUTE_IN_SECONDS );
	}

	private function cache_key( MetadataQuery $query, int $limit ): string {
		return 'mw_meta_v5_' . md5(
			wp_json_encode(
				array(
					array_map(
						static function ( MetadataProvider $provider ): string {
							return $provider->name();
						},
						$this->enabled()
					),
					$query->free_text,
					$query->track,
					$query->artist,
					$query->album,
					$query->year,
					$query->release_types,
					$limit,
				)
			)
		);
	}

	private function with_factual_description( MetadataResult $result, array $release_types = array() ): MetadataResult {
		if ( '' !== trim( wp_strip_all_tags( $result->description ) ) ) {
			return $result;
		}

		$type      = isset( $release_types[0] ) ? sanitize_key( (string) $release_types[0] ) : 'track';
		$artist    = '' !== $result->artist ? $result->artist : __( 'هنرمند ناشناس', 'music-wave-core' );
		$templates = array(
			/* translators: %1$s: value 1, %2$s: value 2. */
			'track'           => __( '%1$s قطعه‌ای از %2$s است.', 'music-wave-core' ),
			/* translators: %1$s: value 1, %2$s: value 2. */
			'single'          => __( '%1$s تک آهنگی از %2$s است.', 'music-wave-core' ),
			/* translators: %1$s: value 1, %2$s: value 2. */
			'ep'              => __( '%1$s یک EP توسط %2$s است.', 'music-wave-core' ),
			/* translators: %1$s: value 1, %2$s: value 2. */
			'album'           => __( '%1$s آلبومی از %2$s است.', 'music-wave-core' ),
			/* translators: %1$s: value 1, %2$s: value 2. */
			'mix'             => __( '%1$s ترکیبی از %2$s است.', 'music-wave-core' ),
			/* translators: %1$s: value 1, %2$s: value 2. */
			'playlist'        => __( '%1$s یک فهرست پخش است که توسط %2$s تنظیم شده است.', 'music-wave-core' ),
			/* translators: %1$s: value 1, %2$s: value 2. */
			'podcast_show'    => __( '%1$s یک نمایش پادکست از %2$s است.', 'music-wave-core' ),
			/* translators: %1$s: value 1, %2$s: value 2. */
			'podcast_episode' => __( '%1$s یک قسمت پادکست از %2$s است.', 'music-wave-core' ),
		);
		$parts     = array( sprintf( isset( $templates[ $type ] ) ? $templates[ $type ] : $templates['track'], $result->title, $artist ) );
		if ( '' !== $result->album && $result->album !== $result->title ) {
			/* translators: %s: value 1. */
			$parts[] = sprintf( __( 'در آلبوم %s ظاهر می‌شود.', 'music-wave-core' ), $result->album );
		}
		if ( $result->track_number > 0 && $result->total_tracks > 0 ) {
			/* translators: %1$d: value 1, %2$d: value 2, %d: value 3. */
			$parts[] = sprintf( __( 'ترک %1$d از %2$d.', 'music-wave-core' ), $result->track_number, $result->total_tracks );
		} elseif ( $result->track_number > 0 ) {
			/* translators: %d: value 1. */
			$parts[] = sprintf( __( 'شماره ترک: %d.', 'music-wave-core' ), $result->track_number );
		}
		if ( '' !== $result->year ) {
			/* translators: %s: value 1. */
			$parts[] = sprintf( __( 'سال انتشار: %s.', 'music-wave-core' ), $result->year );
		}
		if ( '' !== $result->release_date && $result->release_date !== $result->year ) {
			/* translators: %s: value 1. */
			$parts[] = sprintf( __( 'تاریخ انتشار: %s.', 'music-wave-core' ), $result->release_date );
		}
		if ( '' !== $result->country ) {
			/* translators: %s: value 1. */
			$parts[] = sprintf( __( 'کشور: %s.', 'music-wave-core' ), $result->country );
		}
		if ( '' !== $result->publisher ) {
			/* translators: %s: value 1. */
			$parts[] = sprintf( __( 'ناشر: %s.', 'music-wave-core' ), $result->publisher );
		} elseif ( ! empty( $result->labels ) ) {
			/* translators: %s: value 1. */
			$parts[] = sprintf( __( 'برچسب‌ها: %s.', 'music-wave-core' ), implode( ', ', array_column( $result->labels, 'name' ) ) );
		}
		if ( '' !== $result->barcode ) {
			/* translators: %s: value 1. */
			$parts[] = sprintf( __( 'بارکد: %s.', 'music-wave-core' ), $result->barcode );
		}
		if ( ! empty( $result->genres ) ) {
			/* translators: %s: value 1. */
			$parts[] = sprintf( __( 'ژانرها: %s.', 'music-wave-core' ), implode( ', ', array_column( $result->genres, 'name' ) ) );
		}
		if ( $result->explicit ) {
			$parts[] = __( 'محتوای صریح دارد.', 'music-wave-core' );
		}
		if ( '' !== $result->language ) {
			/* translators: %s: value 1. */
			$parts[] = sprintf( __( 'زبان: %s.', 'music-wave-core' ), $result->language );
		}
		if ( ! empty( $result->moods ) ) {
			/* translators: %s: value 1. */
			$parts[] = sprintf( __( 'حال‌وهواها: %s.', 'music-wave-core' ), implode( ', ', array_column( $result->moods, 'name' ) ) );
		}

		return MetadataResult::from_array( array_merge( $result->to_array(), array( 'description' => implode( ' ', $parts ) ) ) );
	}

	/**
	 * Phase 4: Circuit breaker — check if provider is in open state.
	 */
	private function is_circuit_open( string $provider ): bool {
		$key  = 'mw_meta_circuit_' . $provider;
		$data = get_transient( $key );
		if ( ! is_array( $data ) || empty( $data['opened_at'] ) ) {
			return false;
		}
		$opened_at = (int) $data['opened_at'];
		if ( time() - $opened_at > self::CIRCUIT_OPEN_TTL ) {
			// Expired, clear.
			delete_transient( $key );
			return false;
		}
		return true;
	}

	/**
	 * Record a failure for circuit breaker.
	 */
	private function record_failure( string $provider ): void {
		$key      = 'mw_meta_circuit_' . $provider;
		$fail_key = 'mw_meta_circuit_fail_' . $provider;
		$raw      = get_transient( $fail_key );
		$fails    = is_array( $raw ) ? $raw : array();
		$now      = time();
		// Prune old failures outside window.
		$fails   = array_values(
			array_filter(
				$fails,
				static function ( $ts ) use ( $now ): bool {
					return is_int( $ts ) && ( $now - $ts ) <= self::CIRCUIT_WINDOW;
				}
			)
		);
		$fails[] = $now;
		set_transient( $fail_key, $fails, self::CIRCUIT_WINDOW * 2 );

		if ( count( $fails ) >= self::CIRCUIT_FAILURE_THRESHOLD ) {
			set_transient(
				$key,
				array(
					'opened_at' => $now,
					'failures'  => count( $fails ),
				),
				self::CIRCUIT_OPEN_TTL * 2
			);
			// Clear failure list after opening.
			delete_transient( $fail_key );
		}
	}

	/**
	 * Record success — clears failure counter.
	 */
	private function record_success( string $provider ): void {
		delete_transient( 'mw_meta_circuit_fail_' . $provider );
		delete_transient( 'mw_meta_circuit_' . $provider );
	}
}
