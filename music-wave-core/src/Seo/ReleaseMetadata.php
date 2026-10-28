<?php
/**
 * Search and social metadata fallback for public release pages.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Seo;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use WP_Post;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleaseMetadata {
	/** @var ReleaseRepository */
	private $releases;

	public function __construct( ReleaseRepository $releases ) {
		$this->releases = $releases;
	}

	public function register(): void {
		add_filter( 'document_title_parts', array( $this, 'filter_title' ) );
		add_action( 'wp_head', array( $this, 'render' ), 5 );
	}

	/**
	 * Add the primary artist to WordPress' native document title.
	 *
	 * @param array<string, string> $parts Document title parts.
	 * @return array<string, string>
	 */
	public function filter_title( array $parts ): array {
		$post = $this->current_release();
		if ( ! $post instanceof WP_Post || ! $this->is_enabled() ) {
			return $parts;
		}

		$artists = $this->artist_names( $post->ID );
		if ( ! empty( $artists ) ) {
			$parts['title'] = sprintf(
				/* translators: 1: release title, 2: artist names. */
				__( '%1$s توسط %2$s', 'music-wave-core' ),
				get_the_title( $post ),
				implode( ', ', $artists )
			);
		}

		return $parts;
	}

	/**
	 * Render Open Graph, Twitter Card and description metadata across release singles
	 * and non-release public routes when no external SEO plugin handles them.
	 */
	public function render(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		if (
			( function_exists( 'is_admin' ) && is_admin() )
			|| ( function_exists( 'is_feed' ) && is_feed() )
			|| ( function_exists( 'is_search' ) && is_search() )
			|| ( function_exists( 'is_404' ) && is_404() )
		) {
			return;
		}

		$post     = $this->current_release();
		$metadata = $post instanceof WP_Post
			? $this->metadata( $post->ID )
			: $this->route_fallback_metadata();
		if ( empty( $metadata ) ) {
			return;
		}

		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'fa_IR';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- meta() esc_attr()s every attribute, key, and value.
		echo $this->meta( 'name', 'description', (string) $metadata['description'] );
		echo $this->meta( 'property', 'og:locale', $locale );
		echo $this->meta( 'property', 'og:type', (string) $metadata['type'] );
		echo $this->meta( 'property', 'og:site_name', (string) get_bloginfo( 'name' ) );
		echo $this->meta( 'property', 'og:title', (string) $metadata['title'] );
		echo $this->meta( 'property', 'og:description', (string) $metadata['description'] );
		echo $this->meta( 'property', 'og:url', (string) $metadata['url'] );
		echo $this->meta( 'name', 'twitter:card', ( empty( $metadata['image'] ) || empty( $metadata['image_large'] ) ) ? 'summary' : 'summary_large_image' );
		echo $this->meta( 'name', 'twitter:title', (string) $metadata['title'] );
		echo $this->meta( 'name', 'twitter:description', (string) $metadata['description'] );

		if ( ! empty( $metadata['image'] ) ) {
			echo $this->meta( 'property', 'og:image', (string) $metadata['image'] );
			echo $this->meta( 'property', 'og:image:alt', (string) $metadata['title'] );
			echo $this->meta( 'name', 'twitter:image', (string) $metadata['image'] );
		}
		if ( ! empty( $metadata['release_date'] ) ) {
			$release_property = 'article' === $metadata['type'] ? 'article:published_time' : 'music:release_date';
			echo $this->meta( 'property', $release_property, (string) $metadata['release_date'] );
		}
		if ( ! empty( $metadata['artist_urls'] ) && is_array( $metadata['artist_urls'] ) && 'article' !== $metadata['type'] ) {
			foreach ( $metadata['artist_urls'] as $artist_url ) {
				echo $this->meta( 'property', 'music:musician', (string) $artist_url );
			}
		}
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Build a public-only metadata payload suitable for tests and extensions.
	 *
	 * @return array<string, mixed>
	 */
	public function metadata( int $release_id ): array {
		$link = get_permalink( $release_id );
		if ( ReleasePostType::KEY !== get_post_type( $release_id ) || ! is_string( $link ) || '' === $link ) {
			return array();
		}

		$title        = get_the_title( $release_id );
		$artists      = $this->artist_names( $release_id );
		$social_title = empty( $artists )
			? $title
			: sprintf(
				/* translators: 1: release title, 2: artist names. */
				__( '%1$s توسط %2$s', 'music-wave-core' ),
				$title,
				implode( ', ', $artists )
			);
		$description  = $this->description( $release_id, $title, $artists );
		$image        = get_the_post_thumbnail_url( $release_id, 'full' );
		$image_source = is_string( $image ) && '' !== $image ? 'featured' : '';
		if ( '' === $image_source ) {
			// SEO1 fallback chain: without a featured cover, the site icon still
			// gives shared cards a recognizable image instead of a blank tile.
			$site_icon = get_site_icon_url( 512 );
			if ( is_string( $site_icon ) && '' !== $site_icon ) {
				$image        = $site_icon;
				$image_source = 'site-icon';
			}
		}

		/**
		 * Filter the Open Graph/Twitter image URL for release social cards.
		 *
		 * Runs after the built-in featured-image then site-icon fallback chain,
		 * so extensions can supply a dynamic share banner for releases without
		 * any imagery (review finding SEO1). Return a non-empty URL to override.
		 *
		 * @param string $image        Resolved image URL ('' when none).
		 * @param int    $release_id   Release post ID.
		 * @param string $image_source 'featured', 'site-icon', or '' before the filter.
		 */
		$pre_filter_image = (string) $image;
		$image            = (string) apply_filters( 'music_wave_og_image', $pre_filter_image, $release_id, $image_source );
		if ( '' === $image ) {
			$image_source = '';
		} elseif ( $image !== $pre_filter_image || '' === $image_source ) {
			// A filter override is assumed to be a share-sized banner.
			$image_source = 'filtered';
		}
		$types       = wp_get_post_terms( $release_id, 'mw_release_type', array( 'fields' => 'slugs' ) );
		$types       = is_array( $types ) ? array_map( 'sanitize_key', $types ) : array();
		$artist_urls = array();
		$terms       = wp_get_post_terms( $release_id, 'mw_artist' );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$term_link = get_term_link( $term );
				if ( is_string( $term_link ) && '' !== $term_link ) {
					$artist_urls[] = esc_url_raw( $term_link );
				}
			}
		}

		return array(
			'title'        => $social_title,
			'description'  => $description,
			'url'          => esc_url_raw( $link ),
			'image'        => '' !== $image ? esc_url_raw( (string) $image ) : '',
			'image_large'  => 'featured' === $image_source || 'filtered' === $image_source,
			'type'         => $this->open_graph_type( $types ),
			'release_date' => sanitize_text_field( (string) $this->releases->get( $release_id, 'mw_release_date' ) ),
			'artist_urls'  => array_values( array_unique( array_filter( $artist_urls ) ) ),
		);
	}

	public function is_enabled(): bool {
		$enabled = ! SeoCompatibility::has_competing_plugin();

		/**
		 * Filter MusicWave's fallback title and social metadata output.
		 *
		 * @param bool $enabled Whether fallback metadata is enabled.
		 */
		return (bool) apply_filters( 'music_wave_public_metadata_enabled', $enabled );
	}

	/** @return WP_Post|null */
	private function current_release() {
		if ( is_admin() || is_feed() || ! is_singular( ReleasePostType::KEY ) ) {
			return null;
		}

		$post = get_post();

		return $post instanceof WP_Post && 'publish' === $post->post_status ? $post : null;
	}

	/** @return array<int, string> */
	private function artist_names( int $release_id ): array {
		$artists = wp_get_post_terms( $release_id, 'mw_artist', array( 'fields' => 'names' ) );

		return is_array( $artists ) ? array_values( array_filter( array_map( 'sanitize_text_field', $artists ) ) ) : array();
	}

	/** @param array<int, string> $artists */
	private function description( int $release_id, string $title, array $artists ): string {
		$excerpt = get_the_excerpt( $release_id );
		if ( is_string( $excerpt ) && '' !== trim( wp_strip_all_tags( $excerpt ) ) ) {
			return wp_trim_words( wp_strip_all_tags( $excerpt ), 35, '' );
		}

		if ( ! empty( $artists ) ) {
			return sprintf(
				/* translators: 1: release title, 2: artist names, 3: site name. */
				__( 'به %1$s توسط %2$s در %3$s گوش دهید.', 'music-wave-core' ),
				$title,
				implode( ', ', $artists ),
				get_bloginfo( 'name' )
			);
		}

		return sprintf(
			/* translators: 1: release title, 2: site name. */
			__( 'به %1$s در %2$s گوش دهید.', 'music-wave-core' ),
			$title,
			get_bloginfo( 'name' )
		);
	}

	private function meta( string $attribute, string $key, string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		return '<meta ' . esc_attr( $attribute ) . '="' . esc_attr( $key ) . '" content="' . esc_attr( $value ) . '">' . "\n";
	}

	/** @param array<int, string> $types Release type slugs. */
	private function open_graph_type( array $types ): string {
		if ( count( array_intersect( $types, array( 'podcast_show', 'podcast_episode' ) ) ) > 0 ) {
			return 'article';
		}
		if ( count( array_intersect( $types, array( 'mix', 'playlist' ) ) ) > 0 ) {
			return 'music.playlist';
		}
		if ( count( array_intersect( $types, array( 'album', 'ep' ) ) ) > 0 ) {
			return 'music.album';
		}

		return 'music.song';
	}

	/**
	 * Build fallback description & social metadata for front page, catalog archives,
	 * artist/genre/mood taxonomy archives, and standard public pages.
	 *
	 * @return array<string, mixed>
	 */
	private function route_fallback_metadata(): array {
		$site_name = trim( (string) get_bloginfo( 'name' ) );
		$tagline   = trim( (string) get_bloginfo( 'description' ) );
		$icon      = function_exists( 'get_site_icon_url' ) ? (string) get_site_icon_url( 512 ) : '';

		if ( function_exists( 'is_front_page' ) && is_front_page() ) {
			$desc = '' !== $tagline
				? $tagline
				: sprintf(
					/* translators: %s: site name. */
					__( 'پخش آنلاین، کاوش آثار هنرمندان و دانلود باکیفیت موسیقی در %s.', 'music-wave-core' ),
					$site_name
				);
			return array(
				'title'        => '' !== $tagline ? $site_name . ' — ' . $tagline : $site_name,
				'description'  => $desc,
				'url'          => esc_url_raw( home_url( '/' ) ),
				'image'        => '' !== $icon ? esc_url_raw( $icon ) : '',
				'image_large'  => false,
				'type'         => 'website',
				'release_date' => '',
				'artist_urls'  => array(),
			);
		}

		if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( ReleasePostType::KEY ) ) {
			$archive_url = get_post_type_archive_link( ReleasePostType::KEY );
			if ( ! is_string( $archive_url ) || '' === $archive_url ) {
				return array();
			}
			$title = sprintf(
				/* translators: %s: site name. */
				__( 'کاتالوگ انتشارهای موسیقی — %s', 'music-wave-core' ),
				$site_name
			);
			return array(
				'title'        => $title,
				'description'  => sprintf(
					/* translators: %s: site name. */
					__( 'مرور جدیدترین تک‌آهنگ‌ها، آلبوم‌ها و آثار هنرمندان در %s.', 'music-wave-core' ),
					$site_name
				),
				'url'          => esc_url_raw( $archive_url ),
				'image'        => '' !== $icon ? esc_url_raw( $icon ) : '',
				'image_large'  => false,
				'type'         => 'website',
				'release_date' => '',
				'artist_urls'  => array(),
			);
		}

		if ( function_exists( 'is_tax' ) && is_tax() && function_exists( 'get_queried_object' ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$term_link = get_term_link( $term );
				if ( ! is_string( $term_link ) || '' === $term_link ) {
					return array();
				}
				$term_desc = '' !== trim( wp_strip_all_tags( $term->description ) )
					? wp_trim_words( wp_strip_all_tags( $term->description ), 35, '' )
					: sprintf(
						/* translators: 1: term name, 2: site name. */
						__( 'آثار، آهنگ‌ها و آلبوم‌های مرتبط با %1$s در %2$s.', 'music-wave-core' ),
						$term->name,
						$site_name
					);
				$thumb_id  = function_exists( 'get_term_meta' ) ? (int) get_term_meta( $term->term_id, 'mw_avatar_id', true ) : 0;
				$term_img  = $thumb_id > 0 && function_exists( 'wp_get_attachment_image_url' ) ? (string) wp_get_attachment_image_url( $thumb_id, 'full' ) : '';
				$final_img = '' !== $term_img ? $term_img : $icon;

				return array(
					'title'        => $term->name . ' — ' . $site_name,
					'description'  => $term_desc,
					'url'          => esc_url_raw( $term_link ),
					'image'        => '' !== $final_img ? esc_url_raw( $final_img ) : '',
					'image_large'  => '' !== $term_img,
					'type'         => 'mw_artist' === $term->taxonomy ? 'profile' : 'website',
					'release_date' => '',
					'artist_urls'  => array(),
				);
			}
		}

		if ( function_exists( 'is_singular' ) && is_singular() && function_exists( 'get_post' ) ) {
			$page = get_post();
			if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
				$link = get_permalink( $page );
				if ( is_string( $link ) && '' !== $link ) {
					$raw_excerpt = get_the_excerpt( $page->ID );
					$page_desc   = is_string( $raw_excerpt ) && '' !== trim( wp_strip_all_tags( $raw_excerpt ) )
						? wp_trim_words( wp_strip_all_tags( $raw_excerpt ), 35, '' )
						: ( '' !== $tagline ? $tagline : get_the_title( $page ) . ' — ' . $site_name );
					$thumb       = get_the_post_thumbnail_url( $page->ID, 'full' );
					$final_img   = is_string( $thumb ) && '' !== $thumb ? $thumb : $icon;

					return array(
						'title'        => get_the_title( $page ) . ' — ' . $site_name,
						'description'  => $page_desc,
						'url'          => esc_url_raw( $link ),
						'image'        => '' !== $final_img ? esc_url_raw( $final_img ) : '',
						'image_large'  => is_string( $thumb ) && '' !== $thumb,
						'type'         => 'article',
						'release_date' => '',
						'artist_urls'  => array(),
					);
				}
			}
		}

		return array();
	}
}
