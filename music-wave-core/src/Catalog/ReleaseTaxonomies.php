<?php
/**
 * Release taxonomy registration.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleaseTaxonomies {
	private const RELEASE_TYPES_VERSION = '1';

	/**
	 * Taxonomy definitions keyed by taxonomy slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function definitions(): array {
		return array(
			'mw_artist'       => array(
				'singular'     => __( 'هنرمند', 'music-wave-core' ),
				'plural'       => __( 'هنرمندان', 'music-wave-core' ),
				'hierarchical' => false,
				'slug'         => 'artist',
			),
			'mw_genre'        => array(
				'singular'     => __( 'سبک', 'music-wave-core' ),
				'plural'       => __( 'سبک‌ها', 'music-wave-core' ),
				'hierarchical' => true,
				'slug'         => 'genre',
			),
			'mw_mood'         => array(
				'singular'     => __( 'حال‌وهوا', 'music-wave-core' ),
				'plural'       => __( 'حال‌وهواها', 'music-wave-core' ),
				'hierarchical' => false,
				'slug'         => 'mood',
			),
			'mw_label'        => array(
				'singular'     => __( 'برچسب', 'music-wave-core' ),
				'plural'       => __( 'برچسب‌ها', 'music-wave-core' ),
				'hierarchical' => false,
				'slug'         => 'label',
			),
			'mw_release_type' => array(
				'singular'     => __( 'نوع انتشار', 'music-wave-core' ),
				'plural'       => __( 'انواع انتشار', 'music-wave-core' ),
				'hierarchical' => true,
				'slug'         => 'release-type',
			),
		);
	}

	/**
	 * Release taxonomies the public catalog exposes as filters, in display
	 * order.
	 *
	 * The archive query policy reuses this list, so filtering, sorting and
	 * paging stay identical across the release archive and every release
	 * taxonomy archive.
	 *
	 * @return array<int, string>
	 */
	public static function catalog_taxonomies(): array {
		return array( 'mw_artist', 'mw_genre', 'mw_mood', 'mw_release_type' );
	}

	/**
	 * Translated singular labels for the catalog filter taxonomies, keyed by
	 * taxonomy slug, so the filter form, its active-filter chips and the
	 * registered taxonomy labels can never drift apart.
	 *
	 * @return array<string, string>
	 */
	public static function catalog_labels(): array {
		$definitions = ( new self() )->definitions();
		$labels      = array();

		foreach ( self::catalog_taxonomies() as $taxonomy ) {
			if ( isset( $definitions[ $taxonomy ]['singular'] ) ) {
				$labels[ $taxonomy ] = (string) $definitions[ $taxonomy ]['singular'];
			}
		}

		return $labels;
	}

	/**
	 * Register all catalog taxonomies.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( $this->definitions() as $key => $definition ) {
			register_taxonomy(
				$key,
				ReleasePostType::KEY,
				array(
					'labels'            => array(
						'name'          => $definition['plural'],
						'singular_name' => $definition['singular'],
						'search_items'  => sprintf( /* translators: %s: taxonomy plural name. */ __( '%s را جست‌وجو', 'music-wave-core' ), $definition['plural'] ),
						'all_items'     => sprintf( /* translators: %s: taxonomy plural name. */ __( 'همه %s', 'music-wave-core' ), $definition['plural'] ),
						'edit_item'     => sprintf( /* translators: %s: taxonomy singular name. */ __( 'ویرایش %s', 'music-wave-core' ), $definition['singular'] ),
						'add_new_item'  => sprintf( /* translators: %s: taxonomy singular name. */ __( 'اضافه کردن %s جدید', 'music-wave-core' ), $definition['singular'] ),
					),
					'public'            => true,
					'hierarchical'      => $definition['hierarchical'],
					'show_in_rest'      => true,
					'show_admin_column' => true,
					'rewrite'           => array( 'slug' => $definition['slug'] ),
				)
			);
		}

		$this->ensure_default_release_types();
	}

	/**
	 * Seed the canonical release classifications once without overwriting
	 * merchant-managed terms.
	 *
	 * @return void
	 */
	private function ensure_default_release_types(): void {
		if ( self::RELEASE_TYPES_VERSION === (string) get_option( 'music_wave_release_types_version', '' ) ) {
			return;
		}

		$types = array(
			'track'           => __( 'قطعه', 'music-wave-core' ),
			'single'          => __( 'مجرد', 'music-wave-core' ),
			'ep'              => __( 'EP', 'music-wave-core' ),
			'album'           => __( 'آلبوم', 'music-wave-core' ),
			'mix'             => __( 'میکس', 'music-wave-core' ),
			'playlist'        => __( 'فهرست پخش', 'music-wave-core' ),
			'podcast_show'    => __( 'نمایش پادکست', 'music-wave-core' ),
			'podcast_episode' => __( 'قسمت پادکست', 'music-wave-core' ),
		);

		foreach ( $types as $slug => $name ) {
			if ( null === term_exists( $slug, 'mw_release_type' ) ) {
				wp_insert_term( $name, 'mw_release_type', array( 'slug' => $slug ) );
			}
		}

		update_option( 'music_wave_release_types_version', self::RELEASE_TYPES_VERSION, false );
	}
}
