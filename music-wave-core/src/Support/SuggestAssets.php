<?php
/**
 * Shared catalog-autocomplete asset.
 *
 * `assets/catalog-suggest.js` upgrades any `.mw-catalog-suggest` field into an
 * ARIA 1.2 combobox backed by `music-wave/v1/catalog/suggest`. Two surfaces
 * need it — the catalog filters block and the theme's header search — so the
 * handle, the endpoint and the translated default labels live here once instead
 * of being duplicated per caller.
 *
 * Every rendered field also carries its own `data-mw-suggest` overrides, so the
 * header can ask for three suggestions while a catalog page keeps its wider
 * list without the two fighting over one global object.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Support;

use ManaCore\MusicWave\Core\Discovery\CatalogSearch;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SuggestAssets {
	/** Script handle every caller enqueues. */
	public const HANDLE = 'music-wave-catalog-suggest';

	/** Global configuration object printed with the script. */
	public const CONFIG_OBJECT = 'musicWaveCatalogSuggest';

	/** Default suggestion count; callers may lower it per field. */
	public const DEFAULT_LIMIT = 8;

	/**
	 * Register the handle early enough for any surface to enqueue it.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_script' ), 5 );
	}

	/**
	 * Register the script so a theme or companion plugin can enqueue the same
	 * file instead of shipping a second copy of the autocomplete.
	 */
	public static function register_script(): void {
		if ( ! function_exists( 'wp_register_script' ) ) {
			return;
		}

		wp_register_script(
			self::HANDLE,
			MUSIC_WAVE_CORE_URL . 'assets/catalog-suggest.js',
			array(),
			MUSIC_WAVE_CORE_VERSION,
			true
		);
	}

	/**
	 * Translated default configuration.
	 *
	 * The endpoint is an absolute REST URL because the script is served from a
	 * static file; `rest_url()` is safe to print here (no request data).
	 *
	 * @return array<string, mixed>
	 */
	public static function config(): array {
		return array(
			'endpoint'     => function_exists( 'rest_url' ) ? esc_url_raw( rest_url( 'music-wave/v1/catalog/suggest' ) ) : '',
			'minLength'    => CatalogSearch::MIN_TERM_LENGTH,
			'noResults'    => __( 'هیچ منطبق با کاتالوگ یافت نشد.', 'music-wave-core' ),
			/* translators: %d: number of autocomplete suggestions. */
			'resultsCount' => __( 'پیشنهادها کاتالوگ %d در دسترس است.', 'music-wave-core' ),
			'typeLabels'   => array(
				'release'      => __( 'انتشار', 'music-wave-core' ),
				'artist'       => __( 'هنرمند', 'music-wave-core' ),
				'genre'        => __( 'سبک', 'music-wave-core' ),
				'mood'         => __( 'حال‌وهوا', 'music-wave-core' ),
				'release_type' => __( 'نوع انتشار', 'music-wave-core' ),
				'product'      => __( 'محصول', 'music-wave-core' ),
			),
		);
	}

	/**
	 * Enqueue the shared script for the current request.
	 *
	 * Safe to call from several renderers: WordPress prints one script tag per
	 * handle. Callers outside this plugin may use the same method after
	 * checking `class_exists()`.
	 */
	public static function enqueue(): void {
		if ( ! function_exists( 'wp_enqueue_script' ) ) {
			return;
		}

		if (
			function_exists( 'wp_script_is' )
			&& function_exists( 'wp_register_script' )
			&& ! wp_script_is( self::HANDLE, 'registered' )
		) {
			self::register_script();
		}

		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Enqueue the script together with the localized defaults.
	 */
	public static function enqueue_with_defaults(): void {
		self::enqueue();

		if ( function_exists( 'wp_localize_script' ) ) {
			wp_localize_script( self::HANDLE, self::CONFIG_OBJECT, self::config() );
		}
	}

	/**
	 * Configuration for one rendered field, ready to print in an attribute.
	 *
	 * @param int                  $limit    Maximum suggestions for this field.
	 * @param array<string, mixed> $overrides Extra keys merged over the defaults.
	 */
	public static function field_config( int $limit = self::DEFAULT_LIMIT, array $overrides = array() ): string {
		$config          = self::config();
		$config['limit'] = max( 1, min( 10, $limit ) );
		$config          = array_merge( $config, $overrides );

		if ( ! function_exists( 'wp_json_encode' ) ) {
			return '';
		}

		$encoded = wp_json_encode( $config );

		return is_string( $encoded ) ? $encoded : '';
	}
}
