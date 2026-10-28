<?php
/**
 * Release post type registration.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleasePostType {
	public const KEY = 'mw_release';

	/** @return array<int, string> */
	public static function primitive_capabilities(): array {
		return array(
			'edit_mw_release',
			'read_mw_release',
			'delete_mw_release',
			'edit_mw_releases',
			'edit_others_mw_releases',
			'publish_mw_releases',
			'read_private_mw_releases',
			'delete_mw_releases',
			'delete_private_mw_releases',
			'delete_published_mw_releases',
			'delete_others_mw_releases',
			'edit_private_mw_releases',
			'edit_published_mw_releases',
		);
	}

	/**
	 * Register the canonical release post type.
	 *
	 * @return void
	 */
	public function register(): void {
		$labels = array(
			'name'                  => _x( 'انتشارها', 'post type general name', 'music-wave-core' ),
			'singular_name'         => _x( 'انتشار', 'post type singular name', 'music-wave-core' ),
			'add_new_item'          => __( 'افزودن انتشار جدید', 'music-wave-core' ),
			'edit_item'             => __( 'ویرایش انتشار', 'music-wave-core' ),
			'new_item'              => __( 'انتشار جدید', 'music-wave-core' ),
			'view_item'             => __( 'مشاهده انتشار', 'music-wave-core' ),
			'search_items'          => __( 'جست‌وجوی انتشار', 'music-wave-core' ),
			'not_found'             => __( 'هیچ انتشاری یافت نشد.', 'music-wave-core' ),
			'not_found_in_trash'    => __( 'هیچ انتشاری در سطل زباله یافت نشد.', 'music-wave-core' ),
			'all_items'             => __( 'همهٔ انتشارها', 'music-wave-core' ),
			'archives'              => __( 'انتشار آرشیو', 'music-wave-core' ),
			'featured_image'        => __( 'اثر هنری روی جلد', 'music-wave-core' ),
			'set_featured_image'    => __( 'ست آثار هنری روی جلد', 'music-wave-core' ),
			'remove_featured_image' => __( 'حذف آثار هنری روی جلد', 'music-wave-core' ),
			'menu_name'             => __( 'MusicWave', 'music-wave-core' ),
		);

		register_post_type(
			self::KEY,
			array(
				'labels'              => $labels,
				'public'              => true,
				'show_in_rest'        => true,
				'has_archive'         => true,
				'rewrite'             => array( 'slug' => 'music' ),
				'menu_icon'           => 'dashicons-album',
				'menu_position'       => 25,
				'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'custom-fields', 'comments' ),
				'template_lock'       => false,
				'show_in_nav_menus'   => true,
				'exclude_from_search' => false,
				'map_meta_cap'        => true,
				'capability_type'     => array( 'mw_release', 'mw_releases' ),
				'capabilities'        => array( 'create_posts' => 'edit_mw_releases' ),
			)
		);
	}
}
