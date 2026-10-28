<?php
/**
 * REST routes powering the release metadata auto-fill UI.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Metadata;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;

final class MetadataLookupRoutes {
	public const NAMESPACE = 'music-wave/v1';
	public const ROUTE     = '/metadata-lookup';

	/** Per-user fixed-window bound for provider lookups (each fans out to external APIs with the site's keys). */
	public const LOOKUP_LIMIT  = 10;
	public const LOOKUP_WINDOW = 60;

	/** @var MetadataResolver */
	private $resolver;

	/** @var MetadataTaxonomyMapper */
	private $taxonomy_mapper;

	public function __construct( MetadataResolver $resolver, MetadataTaxonomyMapper $taxonomy_mapper ) {
		$this->resolver        = $resolver;
		$this->taxonomy_mapper = $taxonomy_mapper;
	}

	/**
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => array(
					'q'             => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'track'         => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'artist'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'album'         => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'year'          => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'limit'         => array(
						'type'              => 'integer',
						'default'           => 8,
						'sanitize_callback' => 'absint',
					),
					'release_types' => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array( 'type' => 'string' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/apply',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => array(
					'post_id'      => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'title'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'artist'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'album'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'year'         => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'release_date' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'genre'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'duration'     => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
					'isrc'         => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'cover_url'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
						'default'           => '',
					),
					'cover_width'  => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
					'cover_height' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
					'provider'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => '',
					),
					'source_url'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
						'default'           => '',
					),
					'reference_id' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'entity_type'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => '',
					),
					'description'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'wp_kses_post',
						'default'           => '',
					),
					'artists'      => array(
						'type'    => 'array',
						'default' => array(),
					),
					'genres'       => array(
						'type'    => 'array',
						'default' => array(),
					),
					'labels'       => array(
						'type'    => 'array',
						'default' => array(),
					),
					'moods'        => array(
						'type'    => 'array',
						'default' => array(),
					),
					'tags'         => array(
						'type'    => 'array',
						'default' => array(),
					),
					'track_number' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
					'total_tracks' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
					'country'      => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'barcode'      => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'explicit'     => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'publisher'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'language'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'fill_content' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'fill_excerpt' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * Both endpoints require the release-editing capability, so low-privilege
	 * accounts (Contributors hold only `edit_posts`) cannot drive outbound
	 * provider calls with the site's API credentials.
	 *
	 * @return bool
	 */
	public function can_edit(): bool {
		return current_user_can( 'edit_mw_releases' );
	}

	/**
	 * Whether the current user exhausted the per-user lookup budget.
	 *
	 * Phase 4: sliding window with atomic-like increment — stores array of timestamps,
	 * prunes old entries, counts within LOOKUP_WINDOW. Prevents boundary burst.
	 */
	private function rate_limited(): bool {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return true;
		}

		$key  = 'mw_meta_lookup_sw_' . $user_id;
		$now  = time();
		$raw  = get_transient( $key );
		$hits = is_array( $raw ) ? $raw : array();

		// Prune entries older than window.
		$window_start = $now - self::LOOKUP_WINDOW;
		$hits         = array_values(
			array_filter(
				$hits,
				static function ( $ts ) use ( $window_start ): bool {
					return is_int( $ts ) && $ts > $window_start;
				}
			)
		);

		if ( count( $hits ) >= self::LOOKUP_LIMIT ) {
			// Still store pruned list to keep window sliding.
			set_transient( $key, $hits, self::LOOKUP_WINDOW * 2 );
			return true;
		}

		$hits[] = $now;
		set_transient( $key, $hits, self::LOOKUP_WINDOW * 2 );

		return false;
	}

	/**
	 * Search providers and return normalized suggestions (resolver payload).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function search( \WP_REST_Request $request ) {
		if ( $this->rate_limited() ) {
			return new \WP_Error( 'mw_metadata_rate_limited', __( 'سرعت جست‌وجوی فراداده محدود است. یک دقیقه دیگر دوباره امتحان کنید.', 'music-wave-core' ), array( 'status' => 429 ) );
		}

		$query = MetadataQuery::from_strings(
			(string) $request->get_param( 'q' ),
			(string) $request->get_param( 'track' ),
			(string) $request->get_param( 'artist' ),
			(string) $request->get_param( 'album' ),
			(string) $request->get_param( 'year' ),
			is_array( $request->get_param( 'release_types' ) ) ? $request->get_param( 'release_types' ) : array()
		);

		$limit   = (int) $request->get_param( 'limit' );
		$payload = $this->resolver->search( $query, $limit > 0 ? $limit : 8 );

		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * Apply a chosen suggestion to the release post (meta + optional cover).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function apply( \WP_REST_Request $request ) {
		if ( $this->rate_limited() ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'سرعت جست‌وجوی فراداده محدود است. یک دقیقه دیگر دوباره امتحان کنید.', 'music-wave-core' ),
				),
				429
			);
		}

		$post_id = (int) $request->get_param( 'post_id' );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || ReleasePostType::KEY !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'شما نمی‌توانید این انتشار را ویرایش کنید.', 'music-wave-core' ),
				),
				403
			);
		}

		$result        = MetadataResult::from_array(
			array(
				'provider'     => (string) $request->get_param( 'provider' ),
				'reference_id' => (string) $request->get_param( 'reference_id' ),
				'entity_type'  => (string) $request->get_param( 'entity_type' ),
				'title'        => (string) $request->get_param( 'title' ),
				'artist'       => (string) $request->get_param( 'artist' ),
				'album'        => (string) $request->get_param( 'album' ),
				'year'         => (string) $request->get_param( 'year' ),
				'release_date' => (string) $request->get_param( 'release_date' ),
				'genre'        => (string) $request->get_param( 'genre' ),
				'duration'     => (int) $request->get_param( 'duration' ),
				'isrc'         => (string) $request->get_param( 'isrc' ),
				'cover_url'    => (string) $request->get_param( 'cover_url' ),
				'cover_width'  => (int) $request->get_param( 'cover_width' ),
				'cover_height' => (int) $request->get_param( 'cover_height' ),
				'source_url'   => (string) $request->get_param( 'source_url' ),
				'description'  => (string) $request->get_param( 'description' ),
				'artists'      => is_array( $request->get_param( 'artists' ) ) ? $request->get_param( 'artists' ) : array(),
				'genres'       => is_array( $request->get_param( 'genres' ) ) ? $request->get_param( 'genres' ) : array(),
				'labels'       => is_array( $request->get_param( 'labels' ) ) ? $request->get_param( 'labels' ) : array(),
				'moods'        => is_array( $request->get_param( 'moods' ) ) ? $request->get_param( 'moods' ) : array(),
				'tags'         => is_array( $request->get_param( 'tags' ) ) ? $request->get_param( 'tags' ) : array(),
				'track_number' => (int) $request->get_param( 'track_number' ),
				'total_tracks' => (int) $request->get_param( 'total_tracks' ),
				'country'      => (string) $request->get_param( 'country' ),
				'barcode'      => (string) $request->get_param( 'barcode' ),
				'explicit'     => (bool) $request->get_param( 'explicit' ),
				'publisher'    => (string) $request->get_param( 'publisher' ),
				'language'     => (string) $request->get_param( 'language' ),
			)
		);
		$release_types = wp_get_post_terms( $post_id, 'mw_release_type', array( 'fields' => 'slugs' ) );
		$release_types = is_wp_error( $release_types ) ? array() : array_values( array_map( 'sanitize_key', $release_types ) );
		$result        = $this->resolver->enrich( $result, $release_types );

		$warnings    = array();
		$post_update = array( 'ID' => $post_id );
		if ( '' !== $result->title ) {
			$post_update['post_title'] = sanitize_text_field( $result->title );
		}
		$content_applied = false;
		$excerpt_applied = false;
		if ( '' !== $result->description ) {
			if ( (bool) $request->get_param( 'fill_content' ) && empty( $post->post_content ) ) {
				$post_update['post_content'] = wp_kses_post( $result->description );
				$content_applied             = true;
			}
			if ( (bool) $request->get_param( 'fill_excerpt' ) && empty( $post->post_excerpt ) ) {
				$post_update['post_excerpt'] = wp_trim_words( wp_strip_all_tags( $result->description ), 40, '…' );
				$excerpt_applied             = true;
			}
		}
		if ( count( $post_update ) > 1 ) {
			$updated = wp_update_post(
				$post_update,
				true
			);
			if ( is_wp_error( $updated ) ) {
				$warnings[]      = __( 'عنوان انتشار یا توضیحات به‌روزرسانی نشد.', 'music-wave-core' );
				$content_applied = false;
				$excerpt_applied = false;
			}
		}

		$artist_term_ids = $this->taxonomy_mapper->assign( $post_id, 'mw_artist', $result->artists, $result->provider );
		$genre_term_ids  = $this->taxonomy_mapper->assign( $post_id, 'mw_genre', $result->genres, $result->provider );
		$label_term_ids  = $this->taxonomy_mapper->assign( $post_id, 'mw_label', $result->labels, $result->provider );
		$mood_term_ids   = array_values(
			array_unique(
				array_merge(
					$this->taxonomy_mapper->resolve( 'mw_mood', $result->moods, $result->provider, true ),
					$this->taxonomy_mapper->resolve( 'mw_mood', $result->tags, $result->provider, false )
				)
			)
		);
		if ( ! empty( $mood_term_ids ) ) {
			$assigned_moods = wp_set_object_terms( $post_id, $mood_term_ids, 'mw_mood', false );
			if ( is_wp_error( $assigned_moods ) ) {
				$mood_term_ids = array();
			}
		}
		if ( ! empty( $result->artists ) && empty( $artist_term_ids ) ) {
			$warnings[] = __( 'هنرمندان را نمی‌توان به این انتشار متصل کرد.', 'music-wave-core' );
		}
		if ( ! empty( $result->genres ) && empty( $genre_term_ids ) ) {
			$warnings[] = __( 'ژانرها را نمی‌توان به این انتشار متصل کرد.', 'music-wave-core' );
		}
		if ( ! empty( $result->labels ) && empty( $label_term_ids ) ) {
			$warnings[] = __( 'برچسب‌ها را نمی‌توان به این انتشار وصل کرد.', 'music-wave-core' );
		}
		if ( ! empty( $result->moods ) && empty( $mood_term_ids ) ) {
			$warnings[] = __( 'حال‌وهواها را نمی‌توان به این انتشار متصل کرد.', 'music-wave-core' );
		}

		// Phase 4: smart filling based on release type.
		$smart_meta = MetadataFieldMapper::map_to_meta( $result, $release_types );
		foreach ( $smart_meta as $meta_key => $meta_value ) {
			if ( is_string( $meta_value ) ) {
				// Sanitize per key type.
				if ( false !== strpos( $meta_key, '_url' ) || false !== strpos( $meta_key, '_source' ) ) {
					$meta_value = (string) esc_url_raw( $meta_value );
				} elseif ( 'mw_metadata_provider' === $meta_key ) {
					$meta_value = sanitize_key( $meta_value );
				} else {
					$meta_value = sanitize_text_field( $meta_value );
				}
				if ( '' === $meta_value ) {
					continue;
				}
			}
			if ( is_int( $meta_value ) && $meta_value <= 0 ) {
				// Skip zero/negative for all numeric meta except explicit flag.
				if ( 'mw_explicit' !== $meta_key ) {
					continue;
				}
			}
			update_post_meta( $post_id, $meta_key, $meta_value );
		}
		$date = $result->date_for_meta();

		$attachment = null;
		if ( '' !== $result->cover_url && ! current_user_can( 'upload_files' ) ) {
			$warnings[] = __( 'فراداده ذخیره شد، اما حساب شما مجوز بارگذاری تصویر جلد ندارد.', 'music-wave-core' );
		} elseif ( '' !== $result->cover_url ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$imported = $this->resolver->import_cover( $result->cover_url, $result->title, $post_id );
			if ( ! is_wp_error( $imported ) && ! empty( $imported['id'] ) ) {
				$attachment_id = (int) $imported['id'];
				set_post_thumbnail( $post_id, $attachment_id );
				$attachment = array(
					'id'  => $attachment_id,
					'url' => isset( $imported['url'] ) ? (string) $imported['url'] : '',
				);
			} else {
				$warnings[] = is_wp_error( $imported )
					? sprintf(
						/* translators: %s: cover import error */
						__( 'فراداده ذخیره شد، اما وارد کردن جلد ممکن نبود: %s', 'music-wave-core' ),
						$imported->get_error_message()
					)
					: __( 'فراداده ذخیره شد، اما وارد کردن جلد ممکن نبود.', 'music-wave-core' );
			}
		}

		$message = empty( $warnings )
			? __( 'فراداده برای این انتشار اعمال شد.', 'music-wave-core' )
			: __( 'فراداده با برخی هشدارها اعمال شد.', 'music-wave-core' );

		return new \WP_REST_Response(
			array(
				'success'    => true,
				'message'    => $message,
				'warnings'   => $warnings,
				'fields'     => array(
					'title'           => $result->title,
					'artist'          => $result->artist,
					'album'           => $result->album,
					'year'            => $result->year,
					'date'            => $date,
					'genre'           => $result->genre,
					'duration'        => $result->duration,
					'isrc'            => $result->isrc,
					'description'     => $result->description,
					'content_applied' => $content_applied,
					'excerpt_applied' => $excerpt_applied,
					'track_number'    => $result->track_number,
					'total_tracks'    => $result->total_tracks,
					'country'         => $result->country,
					'barcode'         => $result->barcode,
					'publisher'       => $result->publisher,
					'language'        => $result->language,
					'explicit'        => $result->explicit,
				),
				'terms'      => array(
					'mw_artist' => $artist_term_ids,
					'mw_genre'  => $genre_term_ids,
					'mw_label'  => $label_term_ids,
					'mw_mood'   => $mood_term_ids,
				),
				'term_names' => array(
					'mw_artist' => $this->term_names( $artist_term_ids, 'mw_artist' ),
					'mw_genre'  => $this->term_names( $genre_term_ids, 'mw_genre' ),
					'mw_label'  => $this->term_names( $label_term_ids, 'mw_label' ),
					'mw_mood'   => $this->term_names( $mood_term_ids, 'mw_mood' ),
				),
				'attachment' => $attachment,
			),
			200
		);
	}

	/**
	 * Return localized visible names for assigned term IDs.
	 *
	 * @param array<int, int> $term_ids Term IDs.
	 * @param string          $taxonomy Taxonomy key.
	 * @return array<int, string>
	 */
	private function term_names( array $term_ids, string $taxonomy ): array {
		if ( empty( $term_ids ) ) {
			return array();
		}
		$names = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'include'    => $term_ids,
				'hide_empty' => false,
				'fields'     => 'names',
			)
		);

		return is_wp_error( $names ) || ! is_array( $names ) ? array() : array_values( array_map( 'strval', $names ) );
	}
}
