<?php
/**
 * Public structured data for MusicWave catalog releases.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Seo;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Catalog\ReleaseVisibility;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use ManaCore\MusicWave\Core\Support\Settings;
use WP_Post;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleaseJsonLd {
	/** @var ReleaseRepository */
	private $releases;

	/** @var ReleaseVisibility */
	private $visibility;

	public function __construct( ReleaseRepository $releases, ?ReleaseVisibility $visibility = null ) {
		$this->releases   = $releases;
		$this->visibility = null !== $visibility ? $visibility : new ReleaseVisibility();
	}

	/**
	 * Register public JSON-LD output.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'render' ), 30 );
	}

	/**
	 * Render structured data only for public MusicWave release singles.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( is_admin() || is_feed() || ! is_singular( ReleasePostType::KEY ) || ! $this->is_enabled() ) {
			return;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post || ! $this->visibility->is_public( (int) $post->ID ) ) {
			return;
		}

		$schema = $this->schema( $post->ID );
		if ( empty( $schema ) ) {
			return;
		}

		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
		if ( false === $json ) {
			return;
		}

		// wp_json_encode() with JSON_HEX_TAG/JSON_HEX_AMP produces script-tag-safe output.
		echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Build the public schema graph for a release.
	 *
	 * @return array<string, mixed>
	 */
	public function schema( int $release_id ): array {
		$link = get_permalink( $release_id );
		if ( ! is_string( $link ) || '' === $link ) {
			return array();
		}

		$types  = $this->term_slugs( $release_id, 'mw_release_type' );
		$schema = array(
			'@context'         => 'https://schema.org',
			'@type'            => $this->schema_type( $types ),
			'@id'              => $link . '#musicwave-release',
			'url'              => $link,
			'name'             => get_the_title( $release_id ),
			'mainEntityOfPage' => array(
				'@type' => 'WebPage',
				'@id'   => $link,
			),
		);

		$modified = get_post_modified_time( 'c', true, $release_id );
		if ( is_string( $modified ) && '' !== $modified ) {
			$schema['dateModified'] = $modified;
		}

		$image = get_the_post_thumbnail_url( $release_id, 'full' );
		if ( is_string( $image ) && '' !== $image ) {
			$schema['image'] = esc_url_raw( $image );
		}

		$date = $this->releases->get( $release_id, 'mw_release_date' );
		if ( is_string( $date ) && '' !== $date ) {
			$schema['datePublished'] = $date;
		}

		$catalog_number = $this->releases->get( $release_id, 'mw_catalog_number' );
		if ( is_string( $catalog_number ) && '' !== $catalog_number ) {
			$schema['identifier'] = $catalog_number;
		}

		$description = $this->public_description( $release_id );
		if ( '' !== $description ) {
			$schema['description'] = $description;
		}

		$artists = $this->artists( $release_id );
		if ( ! empty( $artists ) ) {
			if ( in_array( 'podcast_show', $types, true ) || in_array( 'podcast_episode', $types, true ) ) {
				$schema['creator'] = $artists;
			} else {
				$schema['byArtist'] = $artists;
			}
		}

		$genres = $this->term_names( $release_id, 'mw_genre' );
		if ( ! empty( $genres ) ) {
			$schema['genre'] = implode( ', ', $genres );
		}

		$duration = (int) $this->releases->get( $release_id, 'mw_duration' );
		if ( $duration > 0 ) {
			$schema['duration'] = $this->iso_duration( $duration );
		}

		$preview = $this->releases->get( $release_id, 'mw_preview_url' );
		if ( is_string( $preview ) && 'https' === wp_parse_url( $preview, PHP_URL_SCHEME ) ) {
			$schema['audio'] = array(
				'@type'       => 'AudioObject',
				'contentUrl'  => $preview,
				'encodingUrl' => $preview,
			);
			if ( $duration > 0 ) {
				$schema['audio']['duration'] = $this->iso_duration( $duration );
			}
			$encoding_format = $this->audio_encoding_format( $preview );
			if ( '' !== $encoding_format ) {
				$schema['audio']['encodingFormat'] = $encoding_format;
			}
		}

		if ( in_array( 'album', $types, true ) || in_array( 'ep', $types, true ) || in_array( 'mix', $types, true ) || in_array( 'playlist', $types, true ) ) {
			$tracks = $this->tracks( $release_id );
			if ( ! empty( $tracks ) ) {
				$schema['track']     = $tracks;
				$schema['numTracks'] = count( $tracks );
			}
		}

		if ( in_array( 'podcast_show', $types, true ) ) {
			$schema['@type'] = 'PodcastSeries';
		} elseif ( in_array( 'podcast_episode', $types, true ) ) {
			$schema['@type'] = 'PodcastEpisode';
			$episode_number  = (int) $this->releases->get( $release_id, 'mw_episode_number' );
			$season_number   = (int) $this->releases->get( $release_id, 'mw_season_number' );
			if ( $episode_number > 0 ) {
				$schema['episodeNumber'] = $episode_number;
			}
			if ( $season_number > 0 ) {
				$schema['partOfSeason'] = array(
					'@type'        => 'PodcastSeason',
					'seasonNumber' => $season_number,
				);
			}
			$series = $this->parent_collection( $release_id, 'PodcastSeries' );
			if ( ! empty( $series ) ) {
				$schema['partOfSeries'] = $series;
			}
		}

		/**
		 * Filter public MusicWave JSON-LD before it is rendered.
		 *
		 * Integrations must not introduce private download assets, entitlement
		 * data, user data, or unverified external URLs.
		 *
		 * @param array<string, mixed> $schema     Public schema graph.
		 * @param int                  $release_id Release ID.
		 */
		$schema = apply_filters( 'music_wave_release_json_ld', $schema, $release_id );

		return is_array( $schema ) ? $schema : array();
	}

	/**
	 * Return whether Core is allowed to emit its public JSON-LD.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$mode    = (string) Settings::get( 'json_ld_mode' );
		$default = 'enabled' === $mode || ( 'auto' === $mode && ! SeoCompatibility::has_competing_plugin() );

		/**
		 * Filter MusicWave JSON-LD output.
		 *
		 * Enable this when the active SEO plugin is configured not to emit
		 * duplicate Music schema for MusicWave releases.
		 *
		 * @param bool $enabled Whether output is enabled.
		 */
		return (bool) apply_filters( 'music_wave_json_ld_enabled', $default );
	}

	/**
	 * @param array<int, string> $types Release type slugs.
	 */
	private function schema_type( array $types ): string {
		if ( in_array( 'playlist', $types, true ) ) {
			return 'MusicPlaylist';
		}

		if ( in_array( 'album', $types, true ) || in_array( 'ep', $types, true ) || in_array( 'mix', $types, true ) ) {
			return 'MusicAlbum';
		}

		return 'MusicRecording';
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function artists( int $release_id ): array {
		$terms = wp_get_post_terms( $release_id, 'mw_artist' );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$artists = array();
		foreach ( $terms as $term ) {
			$artist = array(
				'@type' => 'MusicGroup',
				'@id'   => '',
				'name'  => $term->name,
			);
			$link   = get_term_link( $term );
			if ( ! is_wp_error( $link ) && is_string( $link ) && '' !== $link ) {
				$artist['@id'] = $link . '#artist';
				$artist['url'] = $link;
			}
			if ( '' === $artist['@id'] ) {
				unset( $artist['@id'] );
			}
			$canonical = (string) get_term_meta( $term->term_id, 'mw_artist_canonical_url', true );
			if ( 'https' === wp_parse_url( $canonical, PHP_URL_SCHEME ) ) {
				$artist['sameAs'] = esc_url_raw( $canonical );
			}
			$artists[] = $artist;
		}

		return $artists;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function tracks( int $release_id ): array {
		if ( ! method_exists( $this->releases, 'collection_items' ) ) {
			return array();
		}

		$items  = $this->releases->collection_items( $release_id );
		$tracks = array();
		foreach ( $items as $item ) {
			$child_id = isset( $item['release_id'] ) ? absint( $item['release_id'] ) : 0;
			if ( $child_id < 1 || ! $this->visibility->is_public( $child_id ) ) {
				continue;
			}
			$link = get_permalink( $child_id );
			if ( ! is_string( $link ) || '' === $link ) {
				continue;
			}
			$track    = array(
				'@type'    => 'MusicRecording',
				'name'     => get_the_title( $child_id ),
				'url'      => $link,
				'position' => isset( $item['position'] ) ? (int) $item['position'] : count( $tracks ) + 1,
			);
			$duration = (int) $this->releases->get( $child_id, 'mw_duration' );
			if ( $duration > 0 ) {
				$track['duration'] = $this->iso_duration( $duration );
			}
			$artists = $this->artists( $child_id );
			if ( ! empty( $artists ) ) {
				$track['byArtist'] = $artists;
			}
			$tracks[] = $track;
		}

		return $tracks;
	}

	/**
	 * @return array<int, string>
	 */
	private function term_slugs( int $release_id, string $taxonomy ): array {
		$terms = wp_get_post_terms( $release_id, $taxonomy, array( 'fields' => 'slugs' ) );

		return is_array( $terms ) ? array_values( array_map( 'sanitize_key', $terms ) ) : array();
	}

	/**
	 * @return array<int, string>
	 */
	private function term_names( int $release_id, string $taxonomy ): array {
		$terms = wp_get_post_terms( $release_id, $taxonomy, array( 'fields' => 'names' ) );

		return is_array( $terms ) ? array_values( array_filter( array_map( 'sanitize_text_field', $terms ) ) ) : array();
	}

	private function public_description( int $release_id ): string {
		$excerpt = get_the_excerpt( $release_id );
		if ( ! is_string( $excerpt ) || '' === $excerpt ) {
			return '';
		}

		return wp_trim_words( wp_strip_all_tags( $excerpt ), 55, '' );
	}

	private function iso_duration( int $seconds ): string {
		$hours   = (int) floor( $seconds / HOUR_IN_SECONDS );
		$minutes = (int) floor( ( $seconds % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );
		$seconds = $seconds % MINUTE_IN_SECONDS;
		$parts   = 'PT';
		if ( $hours > 0 ) {
			$parts .= $hours . 'H';
		}
		if ( $minutes > 0 ) {
			$parts .= $minutes . 'M';
		}

		return $parts . $seconds . 'S';
	}

	private function audio_encoding_format( string $url ): string {
		$path      = wp_parse_url( $url, PHP_URL_PATH );
		$extension = is_string( $path ) ? strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) : '';
		$formats   = array(
			'mp3'  => 'audio/mpeg',
			'm4a'  => 'audio/mp4',
			'aac'  => 'audio/aac',
			'ogg'  => 'audio/ogg',
			'wav'  => 'audio/wav',
			'flac' => 'audio/flac',
		);

		return isset( $formats[ $extension ] ) ? $formats[ $extension ] : '';
	}

	/** @return array<string, string> */
	private function parent_collection( int $release_id, string $type ): array {
		if ( ! method_exists( $this->releases, 'collection_ids' ) ) {
			return array();
		}

		$collection_ids = $this->releases->collection_ids( $release_id );
		foreach ( $collection_ids as $collection_id ) {
			$link = get_permalink( $collection_id );
			if ( ! is_string( $link ) || '' === $link || ! $this->visibility->is_public( (int) $collection_id ) ) {
				continue;
			}

			return array(
				'@type' => $type,
				'@id'   => $link . '#musicwave-release',
				'name'  => get_the_title( $collection_id ),
				'url'   => $link,
			);
		}

		return array();
	}
}
