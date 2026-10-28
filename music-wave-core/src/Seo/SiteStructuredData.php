<?php
/**
 * Site-wide Schema.org `@graph` structured data (`WebSite` + `SearchAction`,
 * `Organization`, `BreadcrumbList`, `MusicGroup`, and `CollectionPage`).
 *
 * Designed for Google Rich Results Test, Bing Rich Cards, and AI search citation graphs.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Seo;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use WP_Post;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteStructuredData {

	public function register(): void {
		add_action( 'wp_head', array( $this, 'render' ), 29 );
	}

	public function render(): void {
		if (
			( function_exists( 'is_admin' ) && is_admin() )
			|| ( function_exists( 'is_feed' ) && is_feed() )
			|| SeoCompatibility::has_competing_plugin()
		) {
			return;
		}

		$graph = $this->graph();
		if ( empty( $graph ) ) {
			return;
		}

		$payload = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);

		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
		if ( ! is_string( $json ) || '' === $json ) {
			return;
		}

		echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Assemble the route-aware Schema.org `@graph` entities.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function graph(): array {
		$home_url  = home_url( '/' );
		$site_name = trim( (string) get_bloginfo( 'name' ) );
		if ( '' === $site_name ) {
			$site_name = 'MusicWave';
		}
		$tagline   = trim( (string) get_bloginfo( 'description' ) );
		$site_icon = function_exists( 'get_site_icon_url' ) ? (string) get_site_icon_url( 512 ) : '';
		$locale    = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'language' ) : 'fa-IR';

		$organization = array(
			'@type' => 'Organization',
			'@id'   => $home_url . '#organization',
			'name'  => $site_name,
			'url'   => $home_url,
		);
		if ( '' !== $site_icon ) {
			$organization['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => esc_url_raw( $site_icon ),
			);
		}

		$website = array(
			'@type'           => 'WebSite',
			'@id'             => $home_url . '#website',
			'url'             => $home_url,
			'name'            => $site_name,
			'inLanguage'      => '' !== $locale ? $locale : 'fa-IR',
			'publisher'       => array( '@id' => $home_url . '#organization' ),
			'potentialAction' => array(
				array(
					'@type'       => 'SearchAction',
					'target'      => array(
						'@type'       => 'EntryPoint',
						'urlTemplate' => add_query_arg( array( 's' => '{search_term_string}' ), $home_url ),
					),
					'query-input' => 'required name=search_term_string',
				),
			),
		);
		if ( '' !== $tagline ) {
			$website['description'] = $tagline;
		}

		$graph = array( $organization, $website );

		$breadcrumbs = $this->breadcrumb_node( $home_url, $site_name );
		if ( ! empty( $breadcrumbs ) ) {
			$graph[] = $breadcrumbs;
		}

		$entity = $this->route_entity_node( $home_url );
		if ( ! empty( $entity ) ) {
			$graph[] = $entity;
		}

		/**
		 * Filter the site-level `@graph` JSON-LD array.
		 *
		 * @param array<int, array<string, mixed>> $graph Schema graph nodes.
		 */
		$filtered = apply_filters( 'music_wave_site_json_ld_graph', $graph );

		return is_array( $filtered ) ? array_values( $filtered ) : $graph;
	}

	/**
	 * Build `BreadcrumbList` node with valid `ListItem` positions (`>= 2` items).
	 *
	 * @return array<string, mixed>
	 */
	private function breadcrumb_node( string $home_url, string $site_name ): array {
		unset( $home_url );
		$resolved = BreadcrumbTrail::resolve(
			array(
				'home_label'         => $site_name,
				'show_catalog_root'  => true,
				'hide_on_front_page' => true,
			)
		);
		if ( count( $resolved ) < 2 ) {
			return array();
		}

		$items    = array();
		$position = 1;
		foreach ( $resolved as $crumb ) {
			if ( empty( $crumb['url'] ) || empty( $crumb['name'] ) ) {
				continue;
			}
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position++,
				'name'     => (string) $crumb['name'],
				'item'     => (string) $crumb['url'],
			);
		}
		if ( count( $items ) < 2 ) {
			return array();
		}

		$last_url = (string) $items[ count( $items ) - 1 ]['item'];

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $last_url . '#breadcrumb',
			'itemListElement' => $items,
		);
	}

	/**
	 * Build `MusicGroup` for artist profiles or `CollectionPage` for catalog archives.
	 *
	 * @return array<string, mixed>
	 */
	private function route_entity_node( string $home_url ): array {
		if ( function_exists( 'is_tax' ) && is_tax( 'mw_artist' ) && function_exists( 'get_queried_object' ) ) {
			$artist = get_queried_object();
			if ( $artist instanceof WP_Term ) {
				$url = get_term_link( $artist );
				if ( is_string( $url ) && '' !== $url ) {
					$node = array(
						'@type' => 'MusicGroup',
						'@id'   => $url . '#artist',
						'name'  => $artist->name,
						'url'   => $url,
					);
					$desc = trim( wp_strip_all_tags( $artist->description ) );
					if ( '' !== $desc ) {
						$node['description'] = $desc;
					}
					$avatar_id = function_exists( 'get_term_meta' ) ? (int) get_term_meta( $artist->term_id, 'mw_avatar_id', true ) : 0;
					if ( $avatar_id > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
						$avatar_url = wp_get_attachment_image_url( $avatar_id, 'full' );
						if ( is_string( $avatar_url ) && '' !== $avatar_url ) {
							$node['image'] = esc_url_raw( $avatar_url );
						}
					}
					return $node;
				}
			}
		}

		if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( ReleasePostType::KEY ) ) {
			$archive_url = get_post_type_archive_link( ReleasePostType::KEY );
			if ( is_string( $archive_url ) && '' !== $archive_url ) {
				return array(
					'@type'    => 'CollectionPage',
					'@id'      => $archive_url . '#collection',
					'url'      => $archive_url,
					'name'     => __( 'انتشارهای موسیقی', 'music-wave-core' ),
					'isPartOf' => array( '@id' => $home_url . '#website' ),
				);
			}
		}

		return array();
	}
}
