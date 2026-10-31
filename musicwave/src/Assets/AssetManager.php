<?php
declare(strict_types=1);

namespace ManaCore\MusicWave\Theme\Assets;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages modular CSS registration, script deferring, color-scheme detection,
 * local font preloading, and Core dependency notices.
 */
final class AssetManager {

	private const DEFERRED_THEME_SCRIPTS = array(
		'musicwave-theme-preference',
		'musicwave-rail',
		'musicwave-slider',
		'musicwave-hero-slider',
		'musicwave-lyrics',
		'musicwave-release-hero',
		'musicwave-header-search',
	);

	private const DEFERRED_CORE_SCRIPTS = array(
		'music-wave-runtime',
		'music-wave-playlists',
		'music-wave-preview-player',
		'music-wave-library',
		'music-wave-download',
		'music-wave-listening',
		'music-wave-queue',
		'music-wave-release-actions',
		'music-wave-request-chat',
		'music-wave-request-form',
		'music-wave-catalog-filters',
		'music-wave-catalog-suggest',
		'music-wave-view-counter',
	);

	/**
	 * Register WordPress asset hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render_core_admin_notice' ) );
		add_action( 'init', array( $this, 'register_assets' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_conditional_styles' ), 20 );
		add_filter( 'script_loader_tag', array( $this, 'defer_scripts' ), 10, 2 );
		add_filter( 'script_loader_tag', array( $this, 'filter_script_tag' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'preload_theme_preference' ), 0 );
		add_action( 'wp_head', array( $this, 'preload_local_font' ), 1 );
		add_filter( 'language_attributes', array( $this, 'language_attributes' ) );
		add_filter( 'wp_resource_hints', array( $this, 'resource_hints' ), 10, 2 );
		add_action( 'wp_default_scripts', array( $this, 'maybe_remove_jquery_migrate' ) );
	}

	/**
	 * Dashboard notice when MusicWave Core is missing or below the required version.
	 */
	public function render_core_admin_notice(): void {
		if ( ! is_admin() || ! current_user_can( 'switch_themes' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( null === $screen || 'themes' !== $screen->id ) {
			return;
		}
		if ( defined( 'MUSIC_WAVE_CORE_VERSION' ) && version_compare( (string) MUSIC_WAVE_CORE_VERSION, '0.9.0', '>=' ) ) {
			return;
		}
		if ( ! defined( 'MUSIC_WAVE_CORE_VERSION' ) ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'پوستهٔ MusicWave به افزونهٔ MusicWave Core نیاز دارد.', 'musicwave' ) . '</strong> ' . esc_html__( 'بدون افزونه، صفحات تک‌ریلیز و بلوک‌های کاتالوگ کار نمی‌کنند.', 'musicwave' ) . '</p></div>';
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>' . esc_html(
			sprintf(
				/* translators: 1: minimum Core version, 2: active Core version. */
				__( 'پوستهٔ MusicWave به MusicWave Core نسخهٔ %1$s یا بالاتر نیاز دارد؛ نسخهٔ نصب‌شده %2$s است.', 'musicwave' ),
				'0.9.0',
				(string) MUSIC_WAVE_CORE_VERSION
			)
		) . '</strong></p></div>';
	}

	/**
	 * Return ordered modular styles used in both the frontend and editor.
	 *
	 * @return array<string, array{file: string, dependencies: array<int, string>}>
	 */
	public function style_modules(): array {
		return array(
			'musicwave-tokens'          => array(
				'file'         => 'assets/css/tokens.css',
				'dependencies' => array(),
			),
			'musicwave-hues'            => array(
				'file'         => 'assets/css/hues.css',
				'dependencies' => array( 'musicwave-tokens' ),
			),
			'musicwave-base'            => array(
				'file'         => 'assets/css/base.css',
				'dependencies' => array( 'musicwave-tokens', 'musicwave-hues' ),
			),
			'musicwave-layout'          => array(
				'file'         => 'assets/css/layout.css',
				'dependencies' => array( 'musicwave-base' ),
			),
			'musicwave-navigation'      => array(
				'file'         => 'assets/css/components/navigation.css',
				'dependencies' => array( 'musicwave-layout' ),
			),
			'musicwave-breadcrumb'      => array(
				'file'         => 'assets/css/components/breadcrumb.css',
				'dependencies' => array( 'musicwave-layout' ),
			),
			'musicwave-rail'            => array(
				'file'         => 'assets/css/components/rail.css',
				'dependencies' => array( 'musicwave-navigation' ),
			),
			'musicwave-utilities'       => array(
				'file'         => 'assets/css/utilities.css',
				'dependencies' => array( 'musicwave-base' ),
			),
			'musicwave-theme-toggle'    => array(
				'file'         => 'assets/css/components/theme-toggle.css',
				'dependencies' => array( 'musicwave-navigation' ),
			),
			'musicwave-header-search'   => array(
				'file'         => 'assets/css/components/header-search.css',
				'dependencies' => array( 'musicwave-navigation', 'musicwave-rail', 'musicwave-catalog-suggest' ),
			),
			'musicwave-catalog-suggest' => array(
				'file'         => 'assets/css/components/catalog-suggest.css',
				'dependencies' => array( 'musicwave-utilities' ),
			),
			'musicwave-catalog'         => array(
				'file'         => 'assets/css/components/catalog.css',
				'dependencies' => array( 'musicwave-utilities' ),
			),
			'musicwave-catalog-filters' => array(
				'file'         => 'assets/css/components/catalog-filters.css',
				'dependencies' => array( 'musicwave-catalog', 'musicwave-catalog-suggest' ),
			),
			'musicwave-artist'          => array(
				'file'         => 'assets/css/components/artist.css',
				'dependencies' => array( 'musicwave-utilities' ),
			),
			'musicwave-pagination'      => array(
				'file'         => 'assets/css/components/pagination.css',
				'dependencies' => array( 'musicwave-utilities' ),
			),
			'musicwave-artists-shelf'   => array(
				'file'         => 'assets/css/components/artists-shelf.css',
				'dependencies' => array( 'musicwave-utilities', 'musicwave-shelf', 'musicwave-pagination' ),
			),
			'musicwave-artists-index'   => array(
				'file'         => 'assets/css/components/artists-index.css',
				'dependencies' => array( 'musicwave-utilities', 'musicwave-pagination' ),
			),
			'musicwave-taxonomy-shelf'  => array(
				'file'         => 'assets/css/components/taxonomy-shelf.css',
				'dependencies' => array( 'musicwave-utilities', 'musicwave-shelf' ),
			),
			'musicwave-term-hero'       => array(
				'file'         => 'assets/css/components/term-hero.css',
				'dependencies' => array( 'musicwave-utilities' ),
			),
			'musicwave-queue'           => array(
				'file'         => 'assets/css/components/queue.css',
				'dependencies' => array( 'musicwave-utilities' ),
			),
			'musicwave-auth'            => array(
				'file'         => 'assets/css/components/auth.css',
				'dependencies' => array( 'musicwave-utilities' ),
			),
			'musicwave-access'          => array(
				'file'         => 'assets/css/components/access.css',
				'dependencies' => array( 'musicwave-utilities' ),
			),
			'musicwave-downloads'       => array(
				'file'         => 'assets/css/components/downloads.css',
				'dependencies' => array( 'musicwave-access' ),
			),
			'musicwave-player'          => array(
				'file'         => 'assets/css/components/player.css',
				'dependencies' => array( 'musicwave-utilities' ),
			),
			'musicwave-global-player'   => array(
				'file'         => 'assets/css/components/global-player.css',
				'dependencies' => array( 'musicwave-utilities' ),
			),
			'musicwave-collections'     => array(
				'file'         => 'assets/css/components/collections.css',
				'dependencies' => array( 'musicwave-utilities' ),
			),
			'musicwave-comments'        => array(
				'file'         => 'assets/css/components/comments.css',
				'dependencies' => array( 'musicwave-utilities' ),
			),
			'musicwave-related'         => array(
				'file'         => 'assets/css/components/related.css',
				'dependencies' => array( 'musicwave-catalog', 'musicwave-player' ),
			),
			'musicwave-slider'          => array(
				'file'         => 'assets/css/components/slider.css',
				'dependencies' => array( 'musicwave-catalog' ),
			),
			'musicwave-hero-slider'     => array(
				'file'         => 'assets/css/components/hero-slider.css',
				'dependencies' => array( 'musicwave-shelf' ),
			),
			'musicwave-shelf'           => array(
				'file'         => 'assets/css/components/shelf.css',
				'dependencies' => array( 'musicwave-catalog' ),
			),
			'musicwave-commerce'        => array(
				'file'         => 'assets/css/components/commerce.css',
				'dependencies' => array( 'musicwave-utilities' ),
			),
			'musicwave-account-library' => array(
				'file'         => 'assets/css/components/account-library.css',
				'dependencies' => array( 'musicwave-utilities' ),
			),
			'musicwave-playlists'       => array(
				'file'         => 'assets/css/components/playlists.css',
				'dependencies' => array( 'musicwave-utilities' ),
			),
			'musicwave-listening'       => array(
				'file'         => 'assets/css/components/listening.css',
				'dependencies' => array( 'musicwave-utilities', 'musicwave-shelf' ),
			),
			'musicwave-block-styles'    => array(
				'file'         => 'assets/css/components/block-styles.css',
				'dependencies' => array( 'musicwave-utilities' ),
			),
			'musicwave-editorial'       => array(
				'file'         => 'assets/css/components/editorial.css',
				'dependencies' => array( 'musicwave-catalog', 'musicwave-shelf' ),
			),
			'musicwave-vinyl'           => array(
				'file'         => 'assets/css/components/vinyl.css',
				'dependencies' => array( 'musicwave-collections', 'musicwave-editorial', 'musicwave-shelf' ),
			),
			'musicwave-lyrics'          => array(
				'file'         => 'assets/css/components/lyrics.css',
				'dependencies' => array( 'musicwave-utilities' ),
			),
			'musicwave-release-page'    => array(
				'file'         => 'assets/css/components/release-page.css',
				'dependencies' => array( 'musicwave-catalog', 'musicwave-lyrics', 'musicwave-vinyl' ),
			),
			'musicwave-search-unified'  => array(
				'file'         => 'assets/css/components/search-unified.css',
				'dependencies' => array( 'musicwave-catalog', 'musicwave-header-search' ),
			),
			'musicwave-accessibility'   => array(
				'file'         => 'assets/css/accessibility.css',
				'dependencies' => array( 'musicwave-base' ),
			),
		);
	}

	/**
	 * Return stylesheet paths for WordPress editor parity.
	 *
	 * @return array<int, string>
	 */
	public function editor_style_files(): array {
		$files = array();
		foreach ( $this->style_modules() as $style ) {
			$files[] = $style['file'];
		}
		$files[] = 'assets/css/editor.css';

		return $files;
	}

	/**
	 * Register and enqueue public modular styles and interactive scripts.
	 */
	/**
	 * Register modular styles and interactive scripts early on `init` so FSE block
	 * templates evaluated during `get_the_block_template_html()` (prior to `wp_head`)
	 * can safely enqueue any presentation handle without dropping scripts.
	 */
	public function register_assets(): void {
		if ( ! function_exists( 'wp_register_style' ) || ! function_exists( 'wp_register_script' ) ) {
			return;
		}

		$theme   = wp_get_theme();
		$version = (string) $theme->get( 'Version' );
		$uri     = get_template_directory_uri();

		foreach ( $this->style_modules() as $handle => $style ) {
			if ( ! wp_style_is( $handle, 'registered' ) ) {
				wp_register_style(
					$handle,
					$uri . '/' . $style['file'],
					$style['dependencies'],
					$version
				);
			}
		}

		if ( ! wp_style_is( 'musicwave-fonts', 'registered' ) ) {
			wp_register_style(
				'musicwave-fonts',
				false,
				array( 'musicwave-tokens' ),
				$version
			);
		}

		$lazy_scripts = array(
			'musicwave-slider'          => 'assets/slider.js',
			'musicwave-release-hero'    => 'assets/release-hero.js',
			'musicwave-release-content' => 'assets/release-content.js',
			'musicwave-lyrics'          => 'assets/lyrics.js',
			'musicwave-hero-slider'     => 'assets/hero-slider.js',
			'musicwave-header-search'   => 'assets/header-search.js',
		);

		foreach ( $lazy_scripts as $handle => $relative_path ) {
			if ( ! wp_script_is( $handle, 'registered' ) ) {
				wp_register_script(
					$handle,
					$uri . '/' . $relative_path,
					array(),
					$version,
					true
				);
				$this->set_script_translations( $handle );
			}
		}

		wp_localize_script(
			'musicwave-slider',
			'musicwaveSlider',
			array(
				'previous'  => __( 'موارد قبلی', 'musicwave' ),
				'next'      => __( 'موارد بعدی', 'musicwave' ),
				/* translators: %d: slide group index. */
				'goToGroup' => __( 'رفتن به گروه اسلاید %d', 'musicwave' ),
				/* translators: 1: current slide group index, 2: total slide groups. */
				'status'    => __( 'گروه اسلاید %1$d از %2$d', 'musicwave' ),
			)
		);

		wp_localize_script(
			'musicwave-hero-slider',
			'musicwaveHeroSlider',
			array(
				'previous'  => __( 'اسلاید قبلی', 'musicwave' ),
				'next'      => __( 'اسلاید بعدی', 'musicwave' ),
				/* translators: %d: slide index. */
				'goToSlide' => __( 'رفتن به اسلاید %d', 'musicwave' ),
				/* translators: 1: current slide index, 2: total slides. */
				'status'    => __( 'اسلاید %1$d از %2$d', 'musicwave' ),
			)
		);

		wp_localize_script(
			'musicwave-release-content',
			'musicwaveReleaseContent',
			array(
				'more' => __( 'نمایش بیشتر محتوا', 'musicwave' ),
				'less' => __( 'نمایش کمتر', 'musicwave' ),
			)
		);

		wp_localize_script(
			'musicwave-lyrics',
			'musicwaveLyrics',
			array(
				'follow'    => __( 'دنبال کردن پخش', 'musicwave' ),
				'following' => __( 'همگام با پخش', 'musicwave' ),
			)
		);
	}

	/**
	 * Enqueue frontend assets.
	 */
	public function enqueue_assets(): void {
		$this->register_assets();

		$theme   = wp_get_theme();
		$version = (string) $theme->get( 'Version' );
		$uri     = get_template_directory_uri();

		wp_enqueue_style( 'musicwave-fonts' );

		wp_enqueue_style(
			'musicwave',
			get_stylesheet_uri(),
			array( 'musicwave-fonts' ),
			$version
		);

		foreach ( $this->style_modules() as $handle => $style ) {
			wp_enqueue_style( $handle );
		}

		wp_enqueue_script(
			'musicwave-theme-preference',
			$uri . '/assets/theme-preference.js',
			array(),
			$version,
			true
		);
		$this->set_script_translations( 'musicwave-theme-preference' );

		wp_enqueue_script(
			'musicwave-rail',
			$uri . '/assets/rail.js',
			array(),
			$version,
			true
		);
		wp_localize_script(
			'musicwave-rail',
			'musicwaveRail',
			array(
				'labels' => array(
					'collapse' => __( 'جمع‌کردن منو', 'musicwave' ),
					'expand'   => __( 'بازکردن منو', 'musicwave' ),
				),
			)
		);

		wp_localize_script(
			'musicwave-theme-preference',
			'musicwaveThemePreference',
			array(
				'labels' => array(
					'system' => __( 'استفاده از پوسته سیستم', 'musicwave' ),
					'light'  => __( 'استفاده از پوسته روشن', 'musicwave' ),
					'dark'   => __( 'استفاده از پوسته تیره', 'musicwave' ),
				),
			)
		);

		wp_enqueue_script( 'musicwave-slider' );
		wp_enqueue_script( 'musicwave-hero-slider' );
		wp_enqueue_script( 'musicwave-header-search' );

		if ( function_exists( 'is_singular' ) && is_singular( 'mw_release' ) ) {
			wp_enqueue_script( 'musicwave-release-hero' );
			wp_enqueue_script( 'musicwave-release-content' );
		}
	}

	/**
	 * Ensure all required component styles remain enqueued across block templates.
	 */
	public function enqueue_conditional_styles(): void {
		if ( is_admin() ) {
			return;
		}

		foreach ( $this->style_modules() as $handle => $style ) {
			if ( wp_style_is( $handle, 'registered' ) && ! wp_style_is( $handle, 'enqueued' ) ) {
				wp_enqueue_style( $handle );
			}
		}

		$map = apply_filters(
			'music_wave_conditional_assets',
			array(
				'music-wave/release-shelf'          => array( 'musicwave-shelf', 'musicwave-collections', 'musicwave-vinyl', 'musicwave-catalog' ),
				'music-wave/artists-shelf'          => array( 'musicwave-artists-shelf', 'musicwave-shelf', 'musicwave-catalog' ),
				'music-wave/artists-hero'           => array( 'musicwave-artists-index' ),
				'music-wave/artists-toolbar'        => array( 'musicwave-artists-index' ),
				'music-wave/artists-alphabet'       => array( 'musicwave-artists-index' ),
				'music-wave/taxonomy-shelf'         => array( 'musicwave-taxonomy-shelf', 'musicwave-shelf' ),
				'music-wave/release-card'           => array( 'musicwave-catalog', 'musicwave-shelf' ),
				'music-wave/hero-slider'            => array( 'musicwave-hero-slider', 'musicwave-slider' ),
				'music-wave/release-slider'         => array( 'musicwave-slider', 'musicwave-shelf' ),
				'music-wave/account-library'        => array( 'musicwave-account-library', 'musicwave-commerce' ),
				'music-wave/membership-panel'       => array( 'musicwave-account-library', 'musicwave-commerce' ),
				'music-wave/dashboard-welcome'      => array( 'musicwave-account-library' ),
				'music-wave/dashboard-stats'        => array( 'musicwave-account-library' ),
				'music-wave/dashboard-subscription' => array( 'musicwave-account-library' ),
				'music-wave/playlist'               => array( 'musicwave-playlists' ),
				'music-wave/public-playlists'       => array( 'musicwave-playlists' ),
				'music-wave/request-form'           => array( 'musicwave-commerce' ),
				'music-wave/term-hero'              => array( 'musicwave-term-hero' ),
				'music-wave/artist-profile'         => array( 'musicwave-artist' ),
				'music-wave/lyrics'                 => array( 'musicwave-lyrics', 'musicwave-release-page' ),
				'music-wave/release-meta'           => array( 'musicwave-release-page' ),
			)
		);

		$needed = array();
		global $post;
		if ( $post instanceof WP_Post ) {
			$content = (string) $post->post_content;
			foreach ( $map as $block => $handles ) {
				if ( false !== strpos( $content, $block ) ) {
					$needed = array_merge( $needed, $handles );
				}
			}
		}

		if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( 'mw_release' ) ) {
			$needed = array_merge( $needed, array( 'musicwave-catalog', 'musicwave-catalog-filters', 'musicwave-shelf' ) );
		}
		if ( function_exists( 'is_tax' ) && is_tax( array( 'mw_artist', 'mw_genre', 'mw_mood', 'mw_label', 'mw_release_type' ) ) ) {
			$needed = array_merge( $needed, array( 'musicwave-catalog', 'musicwave-artist', 'musicwave-term-hero', 'musicwave-taxonomy-shelf' ) );
		}
		if ( function_exists( 'is_singular' ) && is_singular( 'mw_release' ) ) {
			$needed = array_merge( $needed, array( 'musicwave-release-page', 'musicwave-lyrics', 'musicwave-vinyl', 'musicwave-editorial', 'musicwave-related', 'musicwave-access', 'musicwave-collections', 'musicwave-catalog', 'musicwave-player', 'musicwave-global-player' ) );
		}
		if ( function_exists( 'is_search' ) && is_search() ) {
			$needed = array_merge( $needed, array( 'musicwave-catalog', 'musicwave-catalog-filters' ) );
		}
		if ( function_exists( 'is_singular' ) && is_singular( 'product' ) ) {
			$needed = array_merge( $needed, array( 'musicwave-commerce', 'musicwave-account-library' ) );
		}
		if ( function_exists( 'is_page' ) && is_page() ) {
			$needed = array_merge( $needed, array( 'musicwave-commerce', 'musicwave-account-library' ) );
		}
		if ( function_exists( 'is_front_page' ) && is_front_page() ) {
			$needed = array_merge( $needed, array( 'musicwave-shelf', 'musicwave-catalog', 'musicwave-hero-slider', 'musicwave-slider', 'musicwave-collections', 'musicwave-vinyl', 'musicwave-editorial', 'musicwave-term-hero', 'musicwave-taxonomy-shelf', 'musicwave-artists-shelf' ) );
		}

		foreach ( array_unique( $needed ) as $handle ) {
			if ( wp_style_is( $handle, 'registered' ) && ! wp_style_is( $handle, 'enqueued' ) ) {
				wp_enqueue_style( $handle );
			}
		}
	}

	/**
	 * Defer non-critical theme script handles while keeping navigation immediate.
	 */
	public function defer_scripts( string $tag, string $handle ): string {
		if ( in_array( $handle, self::DEFERRED_THEME_SCRIPTS, true ) && false === strpos( $tag, ' defer' ) ) {
			return str_replace( '<script ', '<script defer ', $tag );
		}

		return $tag;
	}

	/**
	 * Defer non-critical Core script handles without duplicating WordPress strategy tags.
	 */
	public function filter_script_tag( string $tag, string $handle ): string {
		if ( in_array( $handle, self::DEFERRED_CORE_SCRIPTS, true ) && false === strpos( $tag, ' defer' ) && false === strpos( $tag, 'data-wp-strategy' ) ) {
			return str_replace( ' src', ' defer src', $tag );
		}

		return $tag;
	}

	/**
	 * Apply saved theme and rail state before first paint.
	 */
	public function preload_theme_preference(): void {
		echo "<script>(function(){var d=document.documentElement;d.setAttribute('data-mw-rail-js','1');try{var t=window.localStorage.getItem('musicwave-theme');if('light'===t||'dark'===t){d.setAttribute('data-mw-theme',t);}var r=window.localStorage.getItem('musicwave-rail');if('collapsed'===r||'expanded'===r){d.setAttribute('data-mw-rail',r);}}catch(e){}}());</script>\n";
	}

	/**
	 * Resolve the native color scheme of the active global styles palette.
	 */
	public function native_color_scheme(): string {
		$canvas = '';
		if ( function_exists( 'wp_get_global_settings' ) ) {
			$settings = wp_get_global_settings( array( 'color', 'palette' ) );
			$palettes = is_array( $settings ) ? $settings : array();
			foreach ( array( 'custom', 'theme' ) as $origin ) {
				if ( empty( $palettes[ $origin ] ) || ! is_array( $palettes[ $origin ] ) ) {
					continue;
				}
				foreach ( $palettes[ $origin ] as $color ) {
					if ( isset( $color['slug'], $color['color'] ) && 'canvas' === $color['slug'] && is_string( $color['color'] ) ) {
						$canvas = $color['color'];
						break 2;
					}
				}
			}
		}

		$scheme   = $this->is_light_color( $canvas ) ? 'light' : 'dark';
		$filtered = apply_filters( 'musicwave_native_color_scheme', $scheme, $canvas );

		return 'light' === $filtered ? 'light' : 'dark';
	}

	/**
	 * Decide whether a hex color reads as light (WCAG relative luminance > 0.5).
	 */
	public function is_light_color( string $hex ): bool {
		$hex = ltrim( trim( $hex ), '#' );
		if ( 3 === strlen( $hex ) || 4 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}/i', $hex ) ) {
			return false;
		}

		$channels = array();
		foreach ( array( 0, 2, 4 ) as $offset ) {
			$value      = hexdec( substr( $hex, $offset, 2 ) ) / 255;
			$channels[] = $value <= 0.03928 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
		}

		$luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

		return $luminance > 0.5;
	}

	/**
	 * Expose the native color scheme on <html> for tokens.css.
	 */
	public function language_attributes( string $output ): string {
		return trim( $output . ' data-mw-scheme="' . esc_attr( $this->native_color_scheme() ) . '"' );
	}

	/**
	 * Keep resource hints local-only (self-hosted fonts).
	 *
	 * @param array<int|string, mixed> $urls          Resource hint URLs.
	 * @param string                   $relation_type Relation type.
	 * @return array<int|string, mixed>
	 */
	public function resource_hints( array $urls, string $relation_type ): array {
		unset( $relation_type );
		return $urls;
	}

	/**
	 * Preload the primary self-hosted variable font to eliminate LCP font render delay.
	 */
	public function preload_local_font(): void {
		$preload = (bool) apply_filters( 'musicwave_preload_fonts', true );
		if ( ! $preload ) {
			return;
		}
		if ( function_exists( 'get_theme_file_path' ) ) {
			$file = get_theme_file_path( 'assets/fonts/Vazirmatn-Variable.woff2' );
		} elseif ( function_exists( 'get_template_directory' ) ) {
			$file = get_template_directory() . '/assets/fonts/Vazirmatn-Variable.woff2';
		} else {
			$file = dirname( __DIR__, 2 ) . '/assets/fonts/Vazirmatn-Variable.woff2';
		}
		if ( ! is_string( $file ) || ! file_exists( $file ) ) {
			return;
		}
		if ( function_exists( 'get_theme_file_uri' ) ) {
			$font_uri = get_theme_file_uri( 'assets/fonts/Vazirmatn-Variable.woff2' );
		} elseif ( function_exists( 'get_template_directory_uri' ) ) {
			$font_uri = get_template_directory_uri() . '/assets/fonts/Vazirmatn-Variable.woff2';
		} else {
			$font_uri = '/wp-content/themes/musicwave/assets/fonts/Vazirmatn-Variable.woff2';
		}
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
			esc_url( $font_uri )
		);
	}

	/**
	 * Optionally remove jQuery Migrate when requested via filter.
	 *
	 * @param mixed $scripts WordPress scripts registry.
	 */
	public function maybe_remove_jquery_migrate( $scripts ): void {
		if ( ! (bool) apply_filters( 'musicwave_remove_jquery_migrate', false ) ) {
			return;
		}
		if ( is_object( $scripts ) && ! empty( $scripts->registered['jquery'] ) ) {
			$deps                                 = $scripts->registered['jquery']->deps;
			$scripts->registered['jquery']->deps = array_diff( $deps, array( 'jquery-migrate' ) );
		}
	}

	private function set_script_translations( string $handle ): void {
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( $handle, 'musicwave', get_template_directory() . '/languages' );
		}
	}
}
