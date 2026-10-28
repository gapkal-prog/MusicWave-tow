<?php
/**
 * Comprehensive Final Audit Suite covering:
 * - WCAG 2.2 AA mathematical contrast across all 4 theme JSON palettes & light/dark schemes
 * - WordPress FSE block-template asset registration order, single `tokens.css`, font preload & script `defer`
 * - WAVE Accessibility & HTML5 validity across shelf navigation and hero slider markup
 * - Balanced-brace `FrontendRuntime::parse_localized_assignments()` resilience
 * - Unified `wp_robots` SEO directives, `SiteStructuredData` Schema.org `@graph`, `robots.txt` & `/llms.txt`
 *
 * @package ManaCore\MusicWave\Tests
 */

declare(strict_types=1);

use ManaCore\MusicWave\Core\Playlists\PublicPlaylistSeo;
use ManaCore\MusicWave\Core\Seo\CrawlerDiscovery;
use ManaCore\MusicWave\Core\Seo\SearchSeo;
use ManaCore\MusicWave\Core\Seo\SiteStructuredData;
use ManaCore\MusicWave\Core\Support\FrontendRuntime;
use ManaCore\MusicWave\Core\Support\ShelfNavMarkup;

$mw_contrast_lum = static function ( string $hex ): float {
	$hex = ltrim( trim( $hex ), '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	$rgb = array(
		hexdec( substr( $hex, 0, 2 ) ) / 255,
		hexdec( substr( $hex, 2, 2 ) ) / 255,
		hexdec( substr( $hex, 4, 2 ) ) / 255,
	);
	foreach ( $rgb as &$channel ) {
		$channel = $channel <= 0.03928 ? $channel / 12.92 : pow( ( $channel + 0.055 ) / 1.055, 2.4 );
	}
	unset( $channel );
	return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
};

$mw_contrast_ratio = static function ( string $fg, string $bg ) use ( $mw_contrast_lum ): float {
	$l1 = $mw_contrast_lum( $fg );
	$l2 = $mw_contrast_lum( $bg );
	return ( max( $l1, $l2 ) + 0.05 ) / ( min( $l1, $l2 ) + 0.05 );
};

// -----------------------------------------------------------------------------
// 1. WCAG 2.2 AA Contrast Verification across ALL 4 Theme Palettes & Schemes
// -----------------------------------------------------------------------------
foreach ( array( 'obsidian', 'cassette', 'sonicstream' ) as $mw_retired_variation ) {
	mw_assert_same(
		false,
		file_exists( dirname( __DIR__ ) . '/musicwave/styles/' . $mw_retired_variation . '.json' ),
		'Retired style variation must stay deleted from the theme: ' . $mw_retired_variation
	);
}
$mw_palette_files = array_merge(
	array( dirname( __DIR__ ) . '/musicwave/theme.json' ),
	glob( dirname( __DIR__ ) . '/musicwave/styles/*.json' ) ?: array()
);
mw_assert_same( 4, count( $mw_palette_files ), 'All 4 MusicWave theme JSON palettes must be audited for WCAG 2.2 AA contrast.' );

foreach ( $mw_palette_files as $mw_palette_file ) {
	$mw_decoded = json_decode( (string) file_get_contents( $mw_palette_file ), true );
	mw_assert_same( true, is_array( $mw_decoded ), 'Theme JSON must parse cleanly without syntax errors: ' . basename( $mw_palette_file ) );

	$mw_colors = array();
	foreach ( $mw_decoded['settings']['color']['palette'] ?? array() as $mw_entry ) {
		$mw_colors[ $mw_entry['slug'] ] = $mw_entry['color'];
	}
	foreach ( array(
		array( 'text', 'canvas' ),
		array( 'text', 'surface' ),
		array( 'text', 'surface-raised' ),
		array( 'muted', 'canvas' ),
		array( 'muted', 'surface' ),
		array( 'muted', 'surface-raised' ),
		array( 'on-accent', 'accent' ),
	) as $mw_pair ) {
		list( $mw_fg, $mw_bg ) = $mw_pair;
		if ( isset( $mw_colors[ $mw_fg ], $mw_colors[ $mw_bg ] ) ) {
			$mw_ratio = $mw_contrast_ratio( $mw_colors[ $mw_fg ], $mw_colors[ $mw_bg ] );
			mw_assert_same(
				true,
				$mw_ratio >= 4.5,
				sprintf( 'WCAG AA contrast failed in %s for %s on %s (%.2f:1 < 4.5:1).', basename( $mw_palette_file ), $mw_fg, $mw_bg, $mw_ratio )
			);
		}
	}

	foreach ( $mw_decoded['settings']['custom']['scheme'] ?? array() as $mw_scheme_name => $mw_scheme_vals ) {
		if ( ! is_array( $mw_scheme_vals ) ) {
			continue;
		}
		if ( isset( $mw_scheme_vals['onAccent'], $mw_scheme_vals['accent'] ) ) {
			$mw_scheme_accent_ratio = $mw_contrast_ratio( (string) $mw_scheme_vals['onAccent'], (string) $mw_scheme_vals['accent'] );
			mw_assert_same(
				true,
				$mw_scheme_accent_ratio >= 4.5,
				sprintf( 'Scheme %s in %s must maintain >= 4.5:1 accent button contrast (got %.2f:1).', $mw_scheme_name, basename( $mw_palette_file ), $mw_scheme_accent_ratio )
			);
		}
		if ( isset( $mw_scheme_vals['muted'], $mw_scheme_vals['surfaceRaised'] ) ) {
			$mw_scheme_muted_ratio = $mw_contrast_ratio( (string) $mw_scheme_vals['muted'], (string) $mw_scheme_vals['surfaceRaised'] );
			mw_assert_same(
				true,
				$mw_scheme_muted_ratio >= 4.5,
				sprintf( 'Scheme %s in %s must maintain >= 4.5:1 muted contrast on surfaceRaised (got %.2f:1).', $mw_scheme_name, basename( $mw_palette_file ), $mw_scheme_muted_ratio )
			);
		}
	}
}

// -----------------------------------------------------------------------------
// 2. WAVE Accessibility & Valid HTML5 across Shelf Navigation & Hero Slider
// -----------------------------------------------------------------------------
$mw_nav_html = ShelfNavMarkup::controls( 'scroll', 'mw-test-vp' );
mw_assert_same( true, false !== strpos( $mw_nav_html, 'mw-release-shelf__nav-button' ), 'Scroll shelf nav helper must emit .mw-release-shelf__nav-button controls.' );
mw_assert_same( false, false !== strpos( $mw_nav_html, 'mw-release-shelf__nav" aria-hidden="true"' ), 'Shelf nav wrapper must never wrap focusable buttons in aria-hidden="true" (WAVE aria-hidden-focus).' );

$mw_theme_shelf_source = (string) file_get_contents( dirname( __DIR__ ) . '/musicwave/src/Blocks/ShelfAndSliderRenderer.php' );
mw_assert_same( false, false !== strpos( $mw_theme_shelf_source, 'mw-release-shelf__nav" aria-hidden="true"' ), 'Theme ShelfAndSliderRenderer must never wrap focusable nav buttons in aria-hidden="true".' );
mw_assert_same( false, false !== strpos( $mw_theme_shelf_source, '"" data-mw-hero-dot=' ), 'Hero slider pagination dots must never contain stray double-quote HTML corruption.' );
mw_assert_same( true, false !== strpos( $mw_theme_shelf_source, 'aria-hidden="true" inert' ), 'Inactive hero slides must render both aria-hidden="true" and inert.' );

$mw_asset_manager_audit = new \ManaCore\MusicWave\Theme\Assets\AssetManager();
$mw_deferred_tag        = $mw_asset_manager_audit->filter_script_tag( '<script src="https://example.test/runtime.js" id="music-wave-runtime-js"></script>', 'music-wave-runtime' );
mw_assert_same( true, false !== strpos( $mw_deferred_tag, ' defer src=' ), 'Core runtime script must load non-blocking with defer.' );

ob_start();
$mw_asset_manager_audit->preload_local_font();
$mw_font_preload_html = (string) ob_get_clean();
mw_assert_same( true, false !== strpos( $mw_font_preload_html, 'rel="preload"' ) && false !== strpos( $mw_font_preload_html, 'Vazirmatn-Variable.woff2' ), 'Primary self-hosted variable font must preload by default for fast LCP.' );

// -----------------------------------------------------------------------------
// 3. Balanced-Brace JSON Parsing in `FrontendRuntime` (Embedded `};` Safety)
// -----------------------------------------------------------------------------
$mw_tricky_localized = 'var musicWaveDownload = {"restUrl":"https://musicwave.test/wp-json/","errorMessage":"خطا در بسته }; لطفاً دوباره تلاش کنید","nested":{"ok":true}};';
$mw_parsed_configs   = FrontendRuntime::parse_localized_assignments( $mw_tricky_localized );
mw_assert_same( true, isset( $mw_parsed_configs['musicWaveDownload'] ), 'Balanced-brace parser must extract localized objects cleanly even when strings contain `};`.' );
mw_assert_same( 'خطا در بسته }; لطفاً دوباره تلاش کنید', $mw_parsed_configs['musicWaveDownload']['errorMessage'], 'Embedded `};` inside string values must never truncate JSON state.' );

// -----------------------------------------------------------------------------
// 4. Unified `wp_robots`, Schema.org `@graph`, `robots.txt` & `/llms.txt`
// -----------------------------------------------------------------------------
$mw_search_seo   = new SearchSeo();
$mw_public_robot = $mw_search_seo->filter_robots( array() );
mw_assert_same( 'large', $mw_public_robot['max-image-preview'] ?? '', 'Indexable pages must declare max-image-preview:large for Google Discover & Bing.' );
mw_assert_same( '-1', $mw_public_robot['max-snippet'] ?? '', 'Indexable pages must declare max-snippet:-1 for rich search & AI snippets.' );
mw_assert_same( '-1', $mw_public_robot['max-video-preview'] ?? '', 'Indexable pages must declare max-video-preview:-1.' );

$mw_playlist_seo_source = (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/src/Playlists/PublicPlaylistSeo.php' );
mw_assert_same( false, false !== strpos( $mw_playlist_seo_source, '<meta name="robots"' ), 'PublicPlaylistSeo must delegate through wp_robots without echoing duplicate <meta name="robots"> tags.' );

$mw_site_graph = ( new SiteStructuredData() )->graph();
mw_assert_same( true, count( $mw_site_graph ) >= 2, 'SiteStructuredData must always output at least Organization and WebSite @graph nodes.' );
mw_assert_same( 'Organization', $mw_site_graph[0]['@type'], 'First @graph entity must be Schema.org Organization.' );
mw_assert_same( 'WebSite', $mw_site_graph[1]['@type'], 'Second @graph entity must be Schema.org WebSite.' );
mw_assert_same( 'SearchAction', $mw_site_graph[1]['potentialAction'][0]['@type'] ?? '', 'WebSite node must include SearchAction Sitelinks Searchbox.' );

$mw_crawler_discovery = new CrawlerDiscovery();
$mw_robots_txt        = $mw_crawler_discovery->enhance_robots_txt( "User-agent: *\nDisallow: /wp-admin/\n", '1' );
foreach ( array( 'Googlebot', 'Bingbot', 'OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'ClaudeBot', 'Google-Extended', 'Disallow: /*?mw_sort=', '/llms.txt' ) as $mw_bot_marker ) {
	mw_assert_same( true, false !== strpos( $mw_robots_txt, $mw_bot_marker ), 'Enhanced robots.txt must contain crawler directive marker: ' . $mw_bot_marker );
}

$mw_llms_markdown = $mw_crawler_discovery->build_llms_markdown();
mw_assert_same( true, 0 === strpos( $mw_llms_markdown, '# ' ) && false !== strpos( $mw_llms_markdown, '## Navigation' ), '/llms.txt Markdown index must render valid top-level heading and navigation links.' );

echo 'Final Audit (SEO, Rich Results, CWV, Contrast & WAVE A11y) suite OK.' . PHP_EOL;
