<?php
/**
 * Administration metadata auto-fill module.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Modules;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Contracts\Module;
use ManaCore\MusicWave\Core\Metadata\MetadataLookupRoutes;
use ManaCore\MusicWave\Core\Metadata\MetadataTaxonomyMapper;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Metadata implements Module {
	/** @var MetadataLookupRoutes */
	private $routes;

	/** @var MetadataTaxonomyMapper */
	private $taxonomy_mapper;

	public function __construct( MetadataLookupRoutes $routes, MetadataTaxonomyMapper $taxonomy_mapper ) {
		$this->routes          = $routes;
		$this->taxonomy_mapper = $taxonomy_mapper;
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this->routes, 'register' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		$this->taxonomy_mapper->register();
	}

	/**
	 * @return void
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'mw_metadata_lookup',
			__( 'تکمیل خودکار فراداده و هنر جلد', 'music-wave-core' ),
			array( $this, 'render_meta_box' ),
			ReleasePostType::KEY,
			'side',
			'high'
		);
	}

	/**
	 * Render the lookup panel container; the editor script enhances it.
	 *
	 * @param \WP_Post $post Current release post.
	 * @return void
	 */
	public function render_meta_box( \WP_Post $post ): void {
		$screen        = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$release_types = wp_get_post_terms( $post->ID, 'mw_release_type', array( 'fields' => 'slugs' ) );
		$release_types = is_wp_error( $release_types ) ? array() : array_values( array_map( 'sanitize_key', $release_types ) );
		echo '<div id="mw-metadata-lookup" class="mw-metadata-lookup" data-post-id="' . esc_attr( (string) $post->ID ) . '" data-release-types="' . esc_attr( wp_json_encode( $release_types ) ) . '">';
		echo '<p class="description">' . esc_html__( 'جست‌وجو بر اساس عنوان، هنرمند، آلبوم، یا پادکست. اعمال یک نتیجه می‌تواند توضیحات را پر کند و هنرمندان، ژانرها، حال‌وهواها و برچسب‌ها را با استفاده از شناسهٔ پایدار ارائه‌دهنده و نام‌های مستعار چند زبانه به یکدیگر متصل کند.', 'music-wave-core' ) . '</p>';
		echo '<p class="mw-metadata-lookup__loading">' . esc_html__( 'در حال بارگذاری ابزارهای جست‌وجو…', 'music-wave-core' ) . ' <span class="mw-metadata-lookup__spinner" aria-hidden="true"></span></p>';
		echo '</div>';
		unset( $screen );
	}

	/**
	 * Enqueue the lookup script on release editor screens only.
	 *
	 * @param string $hook_suffix Admin hook suffix.
	 * @return void
	 */
	/**
	 * @param string|null $hook_suffix Current admin screen hook suffix.
	 */
	public function enqueue_assets( $hook_suffix = '' ): void {
		$hook_suffix = is_string( $hook_suffix ) ? $hook_suffix : '';
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();
		if ( null === $screen || ReleasePostType::KEY !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'music-wave-metadata-lookup',
			MUSIC_WAVE_CORE_URL . 'assets/metadata-lookup.js',
			array( 'wp-api-fetch', 'wp-i18n' ),
			MUSIC_WAVE_CORE_VERSION,
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'music-wave-metadata-lookup', 'music-wave-core', MUSIC_WAVE_CORE_PATH . 'languages' );
		}

		wp_localize_script(
			'music-wave-metadata-lookup',
			'musicWaveMetadataLookup',
			array(
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'root'    => esc_url_raw( rest_url( MetadataLookupRoutes::NAMESPACE . MetadataLookupRoutes::ROUTE ) ),
				'strings' => array(
					'queryPlaceholder' => __( 'قطعه، هنرمند یا آلبوم…', 'music-wave-core' ),
					'search'           => __( 'جست‌وجو', 'music-wave-core' ),
					'searching'        => __( 'جست‌وجو…', 'music-wave-core' ),
					'apply'            => __( 'اعمال', 'music-wave-core' ),
					'applying'         => __( 'در حال اعمال…', 'music-wave-core' ),
					'noResults'        => __( 'هیچ منطبقی یافت نشد.', 'music-wave-core' ),
					'error'            => __( 'جست‌وجو انجام نشد. دوباره امتحان کنید.', 'music-wave-core' ),
					'applied'          => __( 'فراداده اعمال شد.', 'music-wave-core' ),
					'providedBy'       => __( 'منبع', 'music-wave-core' ),
					'coverAlt'         => __( 'پیش‌نمایش جلد آلبوم', 'music-wave-core' ),
					'hasCover'         => __( 'کاور', 'music-wave-core' ),
					'refineSearch'     => __( 'اصلاح جست‌وجو', 'music-wave-core' ),
					'artist'           => __( 'هنرمند', 'music-wave-core' ),
					'album'            => __( 'آلبوم', 'music-wave-core' ),
					'year'             => __( 'سال', 'music-wave-core' ),
				),
			)
		);

		wp_enqueue_style(
			'music-wave-metadata-lookup',
			MUSIC_WAVE_CORE_URL . 'assets/metadata-lookup.css',
			array(),
			MUSIC_WAVE_CORE_VERSION
		);
	}
}
