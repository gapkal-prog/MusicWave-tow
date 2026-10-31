<?php
/**
 * Block-theme template and dynamic-block regression checks.
 */

declare(strict_types=1);

if ( ! function_exists( 'mw_assert_same' ) ) {
	/**
	 * Assert one static integrity expectation.
	 *
	 * The complete test suite defines this helper in tests/run.php. Keeping a
	 * guarded local fallback makes this focused integrity check safe to run on
	 * its own as well, without redeclaring the suite helper when included.
	 *
	 * @param mixed  $expected Expected value.
	 * @param mixed  $actual   Actual value.
	 * @param string $message  Failure message.
	 * @return void
	 */
	function mw_assert_same( $expected, $actual, string $message ): void {
		if ( $expected !== $actual ) {
			fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
			fwrite( STDERR, 'Expected: ' . var_export( $expected, true ) . PHP_EOL );
			fwrite( STDERR, 'Actual: ' . var_export( $actual, true ) . PHP_EOL );
			exit( 1 );
		}
	}
}

$theme_directory = dirname( __DIR__ ) . '/musicwave';
$theme_json      = json_decode( (string) file_get_contents( $theme_directory . '/theme.json' ), true );
mw_assert_same( true, is_array( $theme_json ), 'theme.json must contain valid JSON.' );

$registered_parts = array();
foreach ( isset( $theme_json['templateParts'] ) && is_array( $theme_json['templateParts'] ) ? $theme_json['templateParts'] : array() as $part ) {
	if ( ! is_array( $part ) || empty( $part['name'] ) ) {
		continue;
	}
	$registered_parts[] = (string) $part['name'];
	mw_assert_same(
		true,
		is_file( $theme_directory . '/parts/' . $part['name'] . '.html' ),
		'Every template part declared by theme.json must have a matching file.'
	);
}

$block_files = array_merge(
	glob( $theme_directory . '/templates/*.html' ) ?: array(),
	glob( $theme_directory . '/parts/*.html' ) ?: array(),
	glob( $theme_directory . '/patterns/*.php' ) ?: array()
);

$registered_patterns = array();
foreach ( glob( $theme_directory . '/patterns/*.php' ) ?: array() as $pattern_file ) {
	$pattern_content = (string) file_get_contents( $pattern_file );
	if ( preg_match( '/^\s*\*\s+Slug:\s+([a-z0-9-]+\/[a-z0-9-]+)/m', $pattern_content, $slug_match ) ) {
		$registered_patterns[] = $slug_match[1];
	}
}

$registered_custom_templates = array();
foreach ( isset( $theme_json['customTemplates'] ) && is_array( $theme_json['customTemplates'] ) ? $theme_json['customTemplates'] : array() as $custom_template ) {
	mw_assert_same( true, is_array( $custom_template ) && isset( $custom_template['name'] ), 'Each custom template must declare a name.' );
	$registered_custom_templates[] = (string) $custom_template['name'];
	mw_assert_same(
		true,
		is_file( $theme_directory . '/templates/' . $custom_template['name'] . '.html' ),
		'Every custom template declared by theme.json must have a matching templates/*.html file.'
	);
}
mw_assert_same(
	true,
	in_array( 'page-no-sidebar', $registered_custom_templates, true ),
	'theme.json must register the "page-no-sidebar" layout template so editors can remove the Music sidebar per page.'
);
mw_assert_same(
	true,
	in_array( 'page-account', $registered_custom_templates, true ),
	'theme.json must register the unified "page-account" template for the account pages.'
);
mw_assert_same(
	true,
	in_array( 'page-login', $registered_custom_templates, true ),
	'theme.json must register the bundled sign-in / sign-up template.'
);
$login_template = (string) file_get_contents( $theme_directory . '/templates/page-login.html' );
mw_assert_same(
	true,
	false !== strpos( $login_template, '[music_wave_auth]' ),
	'page-login.html must render the server-side auth panel shortcode.'
);
mw_assert_same(
	false,
	false !== strpos( $login_template, 'mw-app-shell' ),
	'page-login.html must stay a focused, sidebar-free authentication screen.'
);
mw_assert_same(
	true,
	in_array( 'page-playlists', $registered_custom_templates, true ),
	'theme.json must register the public playlists template so it is assignable in the Site Editor.'
);
mw_assert_same(
	true,
	in_array( 'page-cart', $registered_custom_templates, true ) && in_array( 'page-checkout', $registered_custom_templates, true ),
	'theme.json must register the commerce page templates for explicit Site Editor assignment.'
);

$layout_css = (string) file_get_contents( $theme_directory . '/assets/css/layout.css' );
$navigation_css = (string) file_get_contents( $theme_directory . '/assets/css/components/navigation.css' );
mw_assert_same(
	true,
	false !== strpos( $navigation_css, '.mw-site-header.is-position-sticky' ) && false !== strpos( $navigation_css, 'position: sticky' ),
	'The header must become sticky through the Site Editor position support class.'
);
mw_assert_same(
	false,
	1 === preg_match( '/\.mw-site-header\s*\{[^{}]*position:\s*sticky/', $navigation_css ),
	'The header must not be sticky when the Site Editor position toggle is disabled.'
);
mw_assert_same(
	true,
	false !== strpos( $navigation_css, 'block-size: 100dvh' ) && false !== strpos( $navigation_css, '.wp-block-navigation__responsive-container.is-menu-open' ),
	'The mobile navigation must provide a full-screen open state.'
);
mw_assert_same(
	true,
	false !== strpos( $navigation_css, 'body.has-modal-open' ) && false !== strpos( $navigation_css, 'overflow: hidden' ),
	'The mobile navigation must lock background scrolling while open.'
);
mw_assert_same(
	true,
	false !== strpos( $navigation_css, '--mw-header-width' )
		&& false !== strpos( $navigation_css, '.is-style-mw-header-full' )
		&& false !== strpos( $navigation_css, '--wp--custom--header--content-size' ),
	'The header must expose a Site Editor width mode (wide/full) driven by a shared CSS token.'
);
mw_assert_same(
	true,
	isset( $theme_json['settings']['position']['sticky'], $theme_json['settings']['blocks']['core/group']['position']['sticky'] ) && true === $theme_json['settings']['position']['sticky'] && true === $theme_json['settings']['blocks']['core/group']['position']['sticky'],
	'theme.json must expose sticky positioning for the header Group in Site Editor.'
);
mw_assert_same(
	true,
	false !== strpos( $navigation_css, 'inset-inline-end' ),
	'Header and mobile navigation positioning must use RTL-safe logical insets.'
);
mw_assert_same(
	true,
	false !== strpos( $navigation_css, 'prefers-reduced-motion' ),
	'Header and mobile navigation must respect reduced-motion preferences.'
);

mw_assert_same(
	true,
	false !== strpos( $layout_css, '.mw-app-shell:has(> .mw-sidebar)' ),
	'The app-shell grid must apply only when the Music sidebar template part is present.'
);
mw_assert_same(
	true,
	false !== strpos( $layout_css, '.mw-app-shell:not(:has(> .mw-sidebar))' ),
	'Pages without the Music sidebar must fall back to a single-column layout.'
);
mw_assert_same(
	false,
	1 === preg_match( '/\.mw-app-shell\s*\{[^{}]*grid-template-columns/', $layout_css ),
	'The unconditional .mw-app-shell grid rule is removed in favour of sidebar-aware selectors.'
);

$no_sidebar_template = (string) file_get_contents( $theme_directory . '/templates/page-no-sidebar.html' );
mw_assert_same(
	false,
	false !== strpos( $no_sidebar_template, 'music-sidebar' ),
	'The no-sidebar page template must not reference the Music sidebar template part.'
);

$page_with_sidebar_template = (string) file_get_contents( $theme_directory . '/templates/page-with-sidebar.html' );
mw_assert_same(
	false,
	false !== strpos( $page_with_sidebar_template, '"slug":"footer-widgets"' ),
	'page-with-sidebar.html must not render footer-widgets beside footer because footer.html already composes it.'
);
mw_assert_same(
	false,
	false !== strpos( $no_sidebar_template, 'mw-app-shell' ),
	'The no-sidebar page template must not use the app-shell layout class.'
);
mw_assert_same(
	true,
	false !== strpos( $page_with_sidebar_template, 'mw-sidebar-column' ),
	'page-with-sidebar.html must mark its sidebar column so the layout can collapse it when the sidebar is inactive.'
);
mw_assert_same(
	true,
	false !== strpos( $layout_css, '.mw-sidebar-column:not(:has(*))' ),
	'An inactive sidebar column must collapse so the content beside it runs full width.'
);

$functions_source = (string) file_get_contents( $theme_directory . '/functions.php' );
foreach ( array_merge( glob( $theme_directory . '/src/*.php' ) ?: array(), glob( $theme_directory . '/src/*/*.php' ) ?: array() ) as $mw_theme_src_file ) {
	$functions_source .= "\n" . (string) file_get_contents( $mw_theme_src_file );
}
mw_assert_same(
	true,
	false !== strpos( $functions_source, "'page-no-sidebar'" ),
	'functions.php must whitelist the no-sidebar template slug for template repair.'
);
mw_assert_same(
	true,
	false !== strpos( $functions_source, 'musicwave_sidebar_widget_areas' )
		&& false !== strpos( $functions_source, "'musicwave-sidebar'" )
		&& false !== strpos( $functions_source, "'musicwave-shop-sidebar'" ),
	'functions.php must map every sidebar template part to its MusicWave widget area.'
);
mw_assert_same(
	true,
	false !== strpos( $functions_source, 'wp_strip_all_tags' ),
	'The sidebar template part must deactivate itself when it holds neither widgets nor authored content.'
);
mw_assert_same(
	true,
	false !== strpos( $functions_source, 'musicwave_register_block_category' ),
	'The theme must register the shared MusicWave inserter category when Core is not active.'
);
mw_assert_same(
	true,
	false !== strpos( $functions_source, "'mw-header-wide'" )
		&& false !== strpos( $functions_source, "'mw-header-full'" )
		&& false !== strpos( $functions_source, "'core/template-part'" ),
	'The theme must register the header width block styles for the Site Editor Styles panel.'
);
$site_header_source = is_file( $theme_directory . '/inc/site-header.php' )
	? (string) file_get_contents( $theme_directory . '/inc/site-header.php' )
	: '';
if ( is_file( $theme_directory . '/src/Header/SiteHeaderManager.php' ) ) {
	$site_header_source .= "\n" . (string) file_get_contents( $theme_directory . '/src/Header/SiteHeaderManager.php' );
}
mw_assert_same(
	true,
	false !== strpos( $site_header_source, 'musicwave_site_header_slug' ),
	'The theme must expose a site-wide header chrome switch.'
);
mw_assert_same(
	true,
	false !== strpos( $site_header_source, "add_filter( 'get_block_template'," )
		&& false !== strpos( $site_header_source, "add_filter( 'get_block_templates'," )
		&& false !== strpos( $site_header_source, "add_filter( 'get_block_file_template'," )
		&& false !== strpos( $site_header_source, 'musicwave_rewrite_header_part_slugs' ),
	'The active header must be written into the template content so the Site Editor canvas and the front-end resolve the same header.'
);
mw_assert_same(
	true,
	false !== strpos( $site_header_source, 'musicwave_header_width_mode' )
		&& false !== strpos( $site_header_source, 'musicwave_apply_header_width_mode' )
		&& false !== strpos( $site_header_source, "add_filter( 'render_block', array( \$this, 'apply_header_width_mode' ), 15" ),
	'The theme must stamp one header width default on every rendered header part.'
);
mw_assert_same(
	true,
	false !== strpos( $site_header_source, "array( 'area' => 'header' )" ),
	'The header chooser must list the header template parts registered in the Site Editor.'
);
mw_assert_same(
	true,
	false !== strpos( (string) file_get_contents( $theme_directory . '/theme.json' ), '"name": "header-stream-end"' ),
	'theme.json must register every bundled header part, including header-stream-end.'
);

/*
 * Header chrome contract: exactly one rail-toggle block and exactly one bottom
 * bar per bundled header part, and the end-rail header puts its toggle next to
 * the rail it controls (after the action cluster) instead of on the inline
 * start. Two toggles shipped in the default parts, which is what made the
 * "open/close" button look duplicated on desktop and misplaced on phones.
 */
foreach ( glob( $theme_directory . '/parts/header*.html' ) ?: array() as $header_part_file ) {
	$header_part         = (string) file_get_contents( $header_part_file );
	$header_part_label   = basename( $header_part_file );
	$toggle_occurrences  = substr_count( $header_part, 'wp:music-wave/rail-toggle' );
	$tabbar_occurrences  = substr_count( $header_part, 'mw-rail__tabbar' );
	$is_stream_part      = false !== strpos( $header_part_label, 'header-stream' );

	mw_assert_same(
		true,
		$toggle_occurrences <= 1,
		$header_part_label . ' must ship at most one rail-toggle block.'
	);
	mw_assert_same(
		true,
		$tabbar_occurrences <= 1,
		$header_part_label . ' must not ship the bottom bar twice.'
	);
	mw_assert_same(
		$is_stream_part,
		1 === $toggle_occurrences,
		$header_part_label . ( $is_stream_part
			? ' must ship exactly one rail-toggle block.'
			: ' must not ship a rail toggle (it has no rail to control).' )
	);

	if ( 'header-stream-end.html' === $header_part_label ) {
		$actions_at = strpos( $header_part, 'mw-header-actions' );
		$toggle_at  = strpos( $header_part, 'wp:music-wave/rail-toggle' );
		mw_assert_same(
			true,
			false !== $actions_at && false !== $toggle_at && $toggle_at > $actions_at,
			'header-stream-end.html must place the rail toggle after the action cluster, next to the inline-end rail.'
		);
	}
}

$rail_css = (string) file_get_contents( $theme_directory . '/assets/css/components/rail.css' );
mw_assert_same(
	1,
	substr_count( $rail_css, "\n.mw-rail__tabbar .wp-block-navigation-item__content {" ),
	'rail.css must declare the bottom-bar item skin exactly once (the simple-header copy duplicated it).'
);
mw_assert_same(
	1,
	substr_count( $rail_css, "\n.mw-rail__tabbar .wp-block-navigation__container {" ),
	'rail.css must declare the bottom-bar list layout exactly once.'
);
mw_assert_same(
	false,
	false !== strpos( $rail_css, '.mw-site-header .mw-rail__tabbar {' ),
	'rail.css must not keep a second, parent-scoped copy of the bottom-bar skin.'
);
mw_assert_same(
	true,
	false !== strpos( $functions_source, 'musicwave_register_legacy_presentation_block' )
		&& false !== strpos( $functions_source, "'musicwave/' . \$dir" ),
	'Theme registration must retain hidden musicwave/* compatibility aliases after the namespace migration.'
);

$theme_block_files = glob( $theme_directory . '/blocks/*/block.json' ) ?: array();
$expected_theme_blocks = array(
	'music-wave/release-shelf',
	'music-wave/release-slider',
	'music-wave/theme-text',
	'music-wave/theme-toggle',
	'music-wave/synced-lyrics',
	'music-wave/section-head',
);
$theme_block_names = array();
foreach ( $theme_block_files as $theme_block_file ) {
	$metadata = json_decode( (string) file_get_contents( $theme_block_file ), true );
	$name     = is_array( $metadata ) && isset( $metadata['name'] ) ? (string) $metadata['name'] : '';
	$theme_block_names[] = $name;
	$label = basename( dirname( $theme_block_file ) ) . '/block.json';
	mw_assert_same( true, 0 === strpos( $name, 'music-wave/' ), $label . ' must use the canonical music-wave/* namespace.' );
	mw_assert_same( 'https://schemas.wp.org/trunk/block.json', is_array( $metadata ) && isset( $metadata['$schema'] ) ? $metadata['$schema'] : '', $label . ' must declare the WordPress block.json schema.' );
	mw_assert_same( true, is_array( $metadata ) && ! empty( $metadata['keywords'] ), $label . ' must provide localized keywords.' );
	mw_assert_same( true, is_array( $metadata ) && isset( $metadata['attributes'] ) && is_array( $metadata['attributes'] ) && isset( $metadata['supports'] ) && is_array( $metadata['supports'] ) && isset( $metadata['example'] ) && is_array( $metadata['example'] ), $label . ' must provide attributes, supports, and example objects.' );
}
foreach ( $expected_theme_blocks as $expected_theme_block ) {
	mw_assert_same( true, in_array( $expected_theme_block, $theme_block_names, true ), 'Theme metadata must include ' . $expected_theme_block . '.' );
}

$core_block_files = glob( dirname( $theme_directory ) . '/music-wave-core/blocks/*/block.json' ) ?: array();
$core_names       = array();
foreach ( $core_block_files as $core_block_file ) {
	$metadata = json_decode( (string) file_get_contents( $core_block_file ), true );
	$name     = is_array( $metadata ) && isset( $metadata['name'] ) ? (string) $metadata['name'] : '';
	$core_names[] = $name;
	$label = basename( dirname( $core_block_file ) ) . '/block.json';
	mw_assert_same( true, 0 === strpos( $name, 'music-wave/' ), $label . ' must use the canonical music-wave/* namespace.' );
	mw_assert_same( 'https://schemas.wp.org/trunk/block.json', is_array( $metadata ) && isset( $metadata['$schema'] ) ? $metadata['$schema'] : '', $label . ' must declare the WordPress block.json schema.' );
	mw_assert_same( true, is_array( $metadata ) && ! empty( $metadata['keywords'] ) && isset( $metadata['attributes'] ) && is_array( $metadata['attributes'] ) && isset( $metadata['supports'] ) && is_array( $metadata['supports'] ) && isset( $metadata['example'] ) && is_array( $metadata['example'] ), $label . ' must provide complete metadata objects.' );
}
mw_assert_same( 41, count( $core_names ), 'Core metadata inventory must contain 41 unified blocks.' );
foreach ( array( 'music-wave/artists-hero', 'music-wave/artists-toolbar', 'music-wave/artists-alphabet', 'music-wave/artists-pagination' ) as $artists_surface_block ) {
	mw_assert_same( true, in_array( $artists_surface_block, $core_names, true ), 'The artists browsing surface must ship the ' . $artists_surface_block . ' block.' );
}
mw_assert_same( count( $core_names ), count( array_unique( $core_names ) ), 'Core metadata names must be unique.' );

$theme_json_blocks = isset( $theme_json['settings']['blocks'] ) && is_array( $theme_json['settings']['blocks'] ) ? $theme_json['settings']['blocks'] : array();
foreach ( array( 'music-wave/release-shelf', 'music-wave/release-slider', 'music-wave/theme-text', 'music-wave/theme-toggle', 'music-wave/synced-lyrics', 'music-wave/section-head' ) as $theme_block_name ) {
	mw_assert_same( true, isset( $theme_json_blocks[ $theme_block_name ] ), 'theme.json settings must expose the canonical ' . $theme_block_name . ' block.' );
}
foreach ( array( 'musicwave/release-shelf', 'musicwave/release-slider', 'musicwave/theme-text', 'musicwave/theme-toggle' ) as $legacy_block_name ) {
	mw_assert_same( true, isset( $theme_json_blocks[ $legacy_block_name ] ), 'theme.json settings must retain the legacy compatibility block ' . $legacy_block_name . '.' );
}
mw_assert_same( true, isset( $theme_json['styles']['blocks']['music-wave/release-shelf'], $theme_json['styles']['blocks']['music-wave/release-slider'], $theme_json['styles']['blocks']['musicwave/release-shelf'], $theme_json['styles']['blocks']['musicwave/release-slider'] ), 'theme.json styles must include canonical and legacy shelf/slider keys.' );

$sidebar_templates = array(
	'archive-mw_release.html',
	'archive-product.html',
	'archive.html',
	'home.html',
	'index.html',
	'page-account.html',
	'page-artists.html',
	'page-browse.html',
	'page-genres.html',
	'page-music-home.html',
	'page.html',
	'search.html',
	'single-mw_release.html',
	'single-product.html',
	'single.html',
	'taxonomy-mw_artist.html',
	'taxonomy-mw_genre.html',
);
foreach ( $sidebar_templates as $template_file ) {
	$contents = (string) file_get_contents( $theme_directory . '/templates/' . $template_file );
	mw_assert_same(
		true,
		false !== strpos( $contents, 'className":"mw-app-shell"' ) || false !== strpos( $contents, 'mw-app-shell' ),
		$template_file . ' must use the shared app-shell layout wrapper.'
	);
	// The bundled templates ship without a sidebar; layout.css must still adapt
	// automatically when an administrator adds one to a saved template.
	if ( false !== strpos( $contents, '"slug":"music-sidebar"' ) ) {
		mw_assert_same(
			true,
			in_array( 'music-sidebar', $registered_parts, true ),
			$template_file . ' references a Music sidebar that theme.json must register.'
		);
	}
}

/**
 * Classify the MusicWave block comments stored in one piece of bundled content.
 *
 * Returns human-readable violations instead of asserting inline, so the very
 * same rules can be exercised over real templates *and* over synthetic fixtures
 * (a negative case cannot be asserted directly because mw_assert_same() exits).
 *
 * Which spelling is legitimate follows from how a block is registered, never
 * from a per-block exception list:
 *
 *  - A block registered with a PHP `render_callback` renders its own markup
 *    server-side, so stored inner HTML would be dead content shadowing the
 *    renderer. Those comments must be self-closing.
 *  - A block registered from block.json with no callback is a static container
 *    whose children are real blocks, so it must open a properly paired
 *    container; self-closing it would store no children at all.
 *
 * Nesting is still enforced by the balance walker further down this file, which
 * is deliberately untouched: a paired container cannot smuggle in an unmatched
 * delimiter.
 *
 * @param string             $content       Bundled template, part or pattern source.
 * @param array<int, string> $dynamic_names Block names that render in PHP.
 * @param array<int, string> $known_names   Every MusicWave block name the repository registers.
 * @return array<int, string>
 */
function mw_musicwave_comment_violations( string $content, array $dynamic_names, array $known_names ): array {
	$violations = array();

	preg_match_all( '/<!--\s+wp:((?:music-wave|musicwave)\/[a-z0-9-]+)(.*?)-->/', $content, $matches, PREG_SET_ORDER );
	foreach ( $matches as $match ) {
		$name         = (string) $match[1];
		$self_closing = '/' === substr( rtrim( (string) $match[2] ), -1 );

		if ( ! in_array( $name, $known_names, true ) ) {
			$violations[] = $name . ' is stored in bundled content but registered by neither Core nor the theme.';
			continue;
		}

		if ( in_array( $name, $dynamic_names, true ) ) {
			if ( ! $self_closing ) {
				$violations[] = 'the dynamic block ' . $name . ' must be stored as a self-closing comment without fallback HTML.';
			}
			continue;
		}

		if ( $self_closing ) {
			$violations[] = 'the child-bearing block ' . $name . ' must not be stored self-closing, or its inner blocks cannot be selected or edited.';
			continue;
		}

		$opens  = preg_match_all( '/<!--\s+wp:' . preg_quote( $name, '/' ) . '\s/', $content );
		$closes = preg_match_all( '/<!--\s+\/wp:' . preg_quote( $name, '/' ) . '\s*-->/', $content );
		if ( $opens !== $closes ) {
			$violations[] = 'the child-bearing block ' . $name . ' stores ' . $opens . ' opening and ' . $closes . ' closing comments.';
		}
	}

	return $violations;
}

/*
 * Stage D: derive the two lanes from the registration sites themselves.
 *
 * Every Core block is registered through BlockSupport::register_dynamic(), which
 * always merges a render_callback over block.json, so each Core metadata name is
 * dynamic. A theme dir is dynamic exactly when musicwave_register_presentation_blocks()
 * maps it with a 'dir' => ... entry beside a callback (plus its hidden
 * musicwave/* alias); every other bundled theme block.json is a static container.
 */
$mw_dynamic_theme_dirs = array();
preg_match_all( "/'dir'\s*=>\s*'([a-z0-9-]+)'/", $functions_source, $mw_dynamic_dir_matches );
$mw_dynamic_theme_dirs = array_values( array_unique( $mw_dynamic_dir_matches[1] ) );

$mw_dynamic_block_names = $core_names;
$mw_static_block_names  = array();
$mw_derived_static_dirs = array();
foreach ( $theme_block_files as $mw_theme_block_file ) {
	$mw_theme_dir      = basename( dirname( $mw_theme_block_file ) );
	$mw_theme_metadata = json_decode( (string) file_get_contents( $mw_theme_block_file ), true );
	$mw_theme_name     = is_array( $mw_theme_metadata ) && isset( $mw_theme_metadata['name'] ) ? (string) $mw_theme_metadata['name'] : '';
	if ( '' === $mw_theme_name ) {
		continue;
	}

	if ( in_array( $mw_theme_dir, $mw_dynamic_theme_dirs, true ) ) {
		$mw_dynamic_block_names[] = $mw_theme_name;
		$mw_dynamic_block_names[] = 'musicwave/' . $mw_theme_dir;
		continue;
	}

	$mw_static_block_names[]  = $mw_theme_name;
	$mw_derived_static_dirs[] = $mw_theme_dir;
}
$mw_dynamic_block_names = array_values( array_unique( $mw_dynamic_block_names ) );
$mw_known_block_names   = array_values( array_unique( array_merge( $mw_dynamic_block_names, $mw_static_block_names ) ) );

// The static registration list must cover exactly the dirs derived above: a new
// child-bearing block.json that nobody registers would otherwise ship silently
// unregistered, and a listed dir without metadata would fatal the editor.
preg_match( '/public function static_block_dirs\(\)\s*:\s*array\s*\{(.*?)\n\t\}/s', $functions_source, $mw_static_list_match );
mw_assert_same( true, isset( $mw_static_list_match[1] ), 'functions.php must declare musicwave_static_block_dirs() as the static-block registration list.' );
preg_match_all( "/'([a-z0-9-]+)'/", isset( $mw_static_list_match[1] ) ? (string) $mw_static_list_match[1] : '', $mw_static_list_matches );
$mw_registered_static_dirs = array_values( array_unique( $mw_static_list_matches[1] ) );
sort( $mw_registered_static_dirs );
sort( $mw_derived_static_dirs );
mw_assert_same(
	$mw_derived_static_dirs,
	$mw_registered_static_dirs,
	'musicwave_static_block_dirs() must list exactly the theme blocks that ship block.json without a render callback.'
);

// Fixtures: the same classifier, proven to accept a correctly paired static
// container and to reject each way the contract can be broken. The vinyl
// pattern now stores a paired section-head (Stage E); the fixtures still
// cover the negative cases bundled content cannot exercise.
$mw_paired_static = '<!-- wp:music-wave/section-head {"align":"wide"} -->' . "\n"
	. '<div class="wp-block-music-wave-section-head alignwide mw-section-head"><!-- wp:group {"className":"mw-section-head__text"} -->'
	. '<div class="wp-block-group mw-section-head__text"><!-- wp:heading {"level":2} -->'
	. '<h2 class="wp-block-heading">عنوان</h2>' . "\n" . '<!-- /wp:heading --></div>' . "\n" . '<!-- /wp:group --></div>' . "\n"
	. '<!-- /wp:music-wave/section-head -->';
mw_assert_same( array(), mw_musicwave_comment_violations( $mw_paired_static, $mw_dynamic_block_names, $mw_known_block_names ), 'A correctly paired static container must be accepted.' );
mw_assert_same(
	array(),
	mw_musicwave_comment_violations( '<!-- wp:music-wave/release-meta {"compact":true} /-->', $mw_dynamic_block_names, $mw_known_block_names ),
	'A self-closing dynamic leaf must still be accepted.'
);
mw_assert_same(
	1,
	count( mw_musicwave_comment_violations( '<!-- wp:music-wave/section-head /-->', $mw_dynamic_block_names, $mw_known_block_names ) ),
	'A self-closing static container must be rejected: it stores no editable children.'
);
mw_assert_same(
	1,
	count( mw_musicwave_comment_violations( '<!-- wp:music-wave/release-shelf {"layout":"grid"} --><div>fallback</div><!-- /wp:music-wave/release-shelf -->', $mw_dynamic_block_names, $mw_known_block_names ) ),
	'A dynamic block stored with fallback HTML must still be rejected.'
);
mw_assert_same(
	1,
	count( mw_musicwave_comment_violations( '<!-- wp:music-wave/section-head {"align":"wide"} -->', $mw_dynamic_block_names, $mw_known_block_names ) ),
	'An unclosed static container must be rejected.'
);
mw_assert_same(
	1,
	count( mw_musicwave_comment_violations( '<!-- wp:music-wave/not-a-block /-->', $mw_dynamic_block_names, $mw_known_block_names ) ),
	'A MusicWave block name nobody registers must be rejected.'
);

foreach ( $block_files as $block_file ) {
	$content = (string) file_get_contents( $block_file );
	$label   = basename( $block_file );

	mw_assert_same(
		false,
		1 === preg_match( '/wp:musicwave\/(?:release-shelf|release-slider|theme-text|theme-toggle)\b/', $content ),
		$label . ' must use canonical music-wave/* block comments in bundled content.'
	);

	preg_match_all( '/<!--\s+wp:template-part\s+(\{.*?\})\s+\/-->/', $content, $template_part_matches );
	foreach ( isset( $template_part_matches[1] ) ? $template_part_matches[1] : array() as $attributes_json ) {
		$attributes = json_decode( $attributes_json, true );
		$slug       = is_array( $attributes ) && isset( $attributes['slug'] ) ? (string) $attributes['slug'] : '';
		mw_assert_same( true, '' !== $slug, $label . ' must use a valid template-part slug.' );
		mw_assert_same( true, in_array( $slug, $registered_parts, true ), $label . ' must reference a template part registered in theme.json.' );
		mw_assert_same( true, is_file( $theme_directory . '/parts/' . $slug . '.html' ), $label . ' must reference an available template-part file.' );
	}

	preg_match_all( '/<!--\s+wp:pattern\s+(\{.*?\})\s+\/-->/', $content, $pattern_matches );
	foreach ( isset( $pattern_matches[1] ) ? $pattern_matches[1] : array() as $attributes_json ) {
		$attributes = json_decode( $attributes_json, true );
		$slug       = is_array( $attributes ) && isset( $attributes['slug'] ) ? (string) $attributes['slug'] : '';
		// Namespaced references such as woocommerce/cart-empty-message are
		// registered at runtime by plugins; only the theme's own namespace is
		// verifiable from the repository.
		$own_namespace = false === strpos( $slug, '/' ) || 0 === strpos( $slug, 'musicwave/' );
		if ( $own_namespace ) {
			mw_assert_same( true, in_array( $slug, $registered_patterns, true ), $label . ' must reference an available bundled pattern.' );
		}
	}

	mw_assert_same(
		array(),
		mw_musicwave_comment_violations( $content, $mw_dynamic_block_names, $mw_known_block_names ),
		$label . ' must store MusicWave blocks the way they are registered: PHP-rendered blocks self-closing with no fallback HTML, static containers correctly paired.'
	);

	mw_assert_same( false, false !== strpos( $content, 'â' ), $label . ' must not contain mojibake sequences.' );
	mw_assert_same( false, false !== strpos( $content, 'Ã' ), $label . ' must not contain mojibake sequences.' );

	preg_match_all( '/<!--\s*(\/?)wp:([a-z0-9-]+\/)?([a-z0-9-]+)(.*?)(\/?)-->/', $content, $comments, PREG_SET_ORDER );
	$stack = array();
	foreach ( $comments as $comment ) {
		$is_closing = '/' === $comment[1];
		$is_self    = '/' === $comment[5] || ( ! $is_closing && '/' === substr( rtrim( $comment[4] ), -1 ) );
		$block_name = (string) $comment[2] . $comment[3];
		if ( $is_self ) {
			continue;
		}
		if ( ! $is_closing ) {
			$stack[] = $block_name;
			continue;
		}
		$opened = array_pop( $stack );
		mw_assert_same( $block_name, $opened, $label . ' must close blocks in the same order they were opened.' );
	}
	mw_assert_same( array(), $stack, $label . ' must close every non-dynamic block comment.' );
}

$editor_script = (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/assets/blocks.js' );
mw_assert_same(
	true,
	false !== strpos( $editor_script, "'music-wave/release-meta': {" )
		&& false !== strpos( $editor_script, 'showCatalogNumber' ),
	'Release metadata must expose the release picker in the editor.'
);
mw_assert_same(
	false,
	false !== strpos( $editor_script, 'Nothing to show yet.' ),
	'Dynamic blocks must use actionable, block-specific empty states.'
);

$release_blocks_source = (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/src/Blocks/ReleaseBlocks.php' );
foreach ( (array) glob( dirname( __DIR__ ) . '/music-wave-core/src/Blocks/Release/*.php' ) as $mw_release_renderer_file ) {
	$release_blocks_source .= "\n" . (string) file_get_contents( $mw_release_renderer_file );
}
$rendering_module      = (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/src/Modules/Rendering.php' );

foreach ( array( 'showArtwork', 'showPreview', 'showAction', 'sameArtistSection', 'sameArtistIcon', 'similarSection', 'similarIcon', 'matchGenre', 'matchMood', 'matchType', 'orderBy', 'layout', 'columns', 'imageShape' ) as $related_attribute ) {
	mw_assert_same(
		true,
		false !== strpos( $release_blocks_source, '\'' . $related_attribute . '\'' ),
		'Related releases must register the ' . $related_attribute . ' block attribute.'
	);
	mw_assert_same(
		true,
		false !== strpos( $rendering_module, '\'' . $related_attribute . '\'' ),
		'The editor metadata must mirror the related-releases ' . $related_attribute . ' attribute.'
	);
}

foreach ( array( 'showWhenGranted', 'grantedMessage', 'purchaseMessage', 'purchaseCtaLabel', 'membershipMessage', 'membershipCtaLabel', 'membershipCtaUrl' ) as $panel_attribute ) {
	mw_assert_same(
		true,
		false !== strpos( $release_blocks_source, '\'' . $panel_attribute . '\'' ) && false !== strpos( $rendering_module, '\'' . $panel_attribute . '\'' ),
		'The access panel override ' . $panel_attribute . ' must be registered for the server and the editor.'
	);
}

foreach ( array( 'heading', 'showRole', 'groupByRole' ) as $credits_attribute ) {
	mw_assert_same(
		true,
		false !== strpos( $release_blocks_source, '\'' . $credits_attribute . '\'' ) && false !== strpos( $rendering_module, '\'' . $credits_attribute . '\'' ),
		'The credits option ' . $credits_attribute . ' must be registered for the server and the editor.'
	);
}

foreach ( array( 'showPosition', 'showArtwork', 'showDuration', 'showTotalDuration', 'showPreview', 'showDownload', 'groupByDisc' ) as $collection_attribute ) {
	mw_assert_same(
		true,
		false !== strpos( $release_blocks_source, '\'' . $collection_attribute . '\'' ) && false !== strpos( $rendering_module, '\'' . $collection_attribute . '\'' ),
		'The collection option ' . $collection_attribute . ' must be registered for the server and the editor.'
	);
}

foreach ( array( 'description', 'showQuality', 'showStream', 'downloadLabel', 'playLabel', 'loginLabel' ) as $download_attribute ) {
	mw_assert_same(
		true,
		false !== strpos( $release_blocks_source, '\'' . $download_attribute . '\'' ) && false !== strpos( $rendering_module, '\'' . $download_attribute . '\'' ),
		'The secure download option ' . $download_attribute . ' must be registered for the server and the editor.'
	);
}

foreach ( array( 'showLabels', 'showMood', 'showReleaseType', 'fullSpecs' ) as $meta_attribute ) {
	mw_assert_same(
		true,
		false !== strpos( $release_blocks_source, '\'' . $meta_attribute . '\'' ) && false !== strpos( $rendering_module, '\'' . $meta_attribute . '\'' ),
		'The release metadata option ' . $meta_attribute . ' must be registered for the server and the editor.'
	);
}

foreach ( array( 'showSearch', 'showArtistFilter', 'showGenreFilter', 'showMoodFilter', 'showTypeFilter', 'showSort', 'showReset', 'maxTerms', 'searchPlaceholder', 'submitLabel', 'resetLabel' ) as $filter_attribute ) {
	mw_assert_same(
		true,
		false !== strpos( $release_blocks_source, '\'' . $filter_attribute . '\'' ) && false !== strpos( $rendering_module, '\'' . $filter_attribute . '\'' ),
		'The catalog filter option ' . $filter_attribute . ' must be registered for the server and the editor.'
	);
}

foreach ( array( 'showCount', 'showChips' ) as $results_attribute ) {
	mw_assert_same(
		true,
		false !== strpos( $release_blocks_source, '\'' . $results_attribute . '\'' ) && false !== strpos( $rendering_module, '\'' . $results_attribute . '\'' ),
		'The catalog results option ' . $results_attribute . ' must be registered for the server and the editor.'
	);
}

$preview_player_source = (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/src/Blocks/PreviewPlayer.php' );
foreach ( array( 'style', 'showIcon', 'fullWidth' ) as $preview_attribute ) {
	mw_assert_same(
		true,
		false !== strpos( $preview_player_source, '\'' . $preview_attribute . '\'' ) && false !== strpos( $rendering_module, '\'' . $preview_attribute . '\'' ),
		'The preview button option ' . $preview_attribute . ' must be registered for the server and the editor.'
	);
}

$artist_profile_source = (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/src/Blocks/ArtistProfileBlock.php' );
foreach ( array( 'bioLength', 'showReleaseCount' ) as $artist_attribute ) {
	mw_assert_same(
		true,
		false !== strpos( $artist_profile_source, '\'' . $artist_attribute . '\'' ) && false !== strpos( $rendering_module, '\'' . $artist_attribute . '\'' ),
		'The artist profile option ' . $artist_attribute . ' must be registered for the server and the editor.'
	);
}

foreach ( array( 'sameArtistSection', 'matchGenre', 'groupByRole', 'groupByDisc', 'downloadLabel', 'showWhenGranted', 'imageShape' ) as $editor_control ) {
	mw_assert_same(
		true,
		false !== strpos( $editor_script, $editor_control ),
		'The editor must expose the ' . $editor_control . ' inspector control.'
	);
}

foreach ( array( 'showLabels', 'fullSpecs', 'showMood', 'showReleaseType', 'showSearch', 'showArtistFilter', 'maxTerms', 'showCount', 'showChips', 'fullWidth', 'bioLength', 'showReleaseCount' ) as $editor_control ) {
	mw_assert_same(
		true,
		false !== strpos( $editor_script, $editor_control ),
		'The editor must expose the ' . $editor_control . ' inspector control.'
	);
}

$related_css = (string) file_get_contents( $theme_directory . '/assets/css/components/related.css' );
mw_assert_same(
	true,
	false !== strpos( $related_css, '.mw-related-releases__section-header' ),
	'The theme must style the related-releases section header.'
);

// Related rails reuse the MusicWave release shelf layout system so they stay
// visually identical to the shelf block. The section chrome still lives in
// ReleaseBlocks; the card itself is emitted by the shared ReleaseCard
// renderer, so the card classes are asserted there instead.
foreach ( array( 'mw-release-shelf--', 'mw-release-shelf__items' ) as $shelf_marker ) {
	mw_assert_same(
		true,
		false !== strpos( $release_blocks_source, $shelf_marker ),
		'Related releases must render with the release-shelf layout class ' . $shelf_marker . '.'
	);
}

$release_card_source     = (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/src/Blocks/ReleaseCard.php' );
$listening_blocks_source = (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/src/Blocks/ListeningBlocks.php' );
foreach ( array( 'mw-release-shelf__item', 'mw-release-shelf__artwrap', 'mw-release-shelf__art--', 'mw-release-shelf__image', 'mw-release-shelf__placeholder', 'mw-release-shelf__artist', 'mw-release-shelf__body', 'mw-release-shelf__action' ) as $card_marker ) {
	mw_assert_same(
		true,
		false !== strpos( $release_card_source, $card_marker ),
		'The shared release card must render the shelf card class ' . $card_marker . '.'
	);
}

// The card skeleton has exactly one home. Related releases, continue listening
// and the theme shelf all delegate, so re-inlining the markup anywhere is a
// regression: tests/card-markup.php pins the rendered bytes and these
// assertions pin the delegation itself.
foreach ( array( 'ReleaseBlocks.php' => $release_blocks_source, 'ListeningBlocks.php' => $listening_blocks_source ) as $producer_name => $producer_source ) {
	mw_assert_same(
		false,
		false !== strpos( $producer_source, 'mw-release-shelf__artwrap' ),
		$producer_name . ' must not re-inline the release card artwork; it must delegate to ReleaseCard.'
	);
	mw_assert_same(
		true,
		false !== strpos( $producer_source, 'ReleaseCard::render(' ) && false !== strpos( $producer_source, 'ReleaseCard::presentation_data(' ),
		$producer_name . ' must build its cards through the shared ReleaseCard renderer.'
	);
}

mw_assert_same(
	true,
	false !== strpos( $functions_source, 'ReleaseCard::presentation_data(' ) && false !== strpos( $functions_source, 'musicwave_render_release_card(' ),
	'The theme release shelf must build its cards through the shared ReleaseCard renderer.'
);

// Stage A (docs/composability-architecture.md): one canonical release card.
// Every release archive surface composes the same __media / __overlay / __body
// structure so catalog.css styles them identically, and the preview button
// lives inside the overlay - that selector is what turns it into an artwork
// affordance instead of a loose button in the card flow.
$mw_card_pattern_source = (string) file_get_contents( $theme_directory . '/patterns/release-card.php' );
$mw_card_pattern_start  = strpos( $mw_card_pattern_source, '?>' );
mw_assert_same( true, false !== $mw_card_pattern_start, 'release-card.php must be a PHP pattern file.' );
$mw_canonical_card = trim( substr( $mw_card_pattern_source, (int) $mw_card_pattern_start + 2 ) );

$mw_search_source = (string) file_get_contents( $theme_directory . '/templates/search.html' );
$mw_search_card   = array();
preg_match( '/<!-- wp:post-template.*?-->\n(.*?)\n<!-- \/wp:post-template -->/s', $mw_search_source, $mw_search_card );
mw_assert_same(
	isset( $mw_search_card[1] ) ? (string) $mw_search_card[1] : '',
	$mw_canonical_card,
	'The bundled release-card pattern must stay byte-identical to the search archive card, so the inserter and the templates cannot drift apart.'
);

mw_assert_same(
	true,
	in_array( 'musicwave/release-card', $registered_patterns, true ) && in_array( 'musicwave/release-grid', $registered_patterns, true ),
	'The theme must register the canonical release-card and release-grid patterns.'
);

$mw_grid_pattern_source = (string) file_get_contents( $theme_directory . '/patterns/release-grid.php' );
mw_assert_same(
	true,
	false !== strpos( $mw_grid_pattern_source, $mw_canonical_card ),
	'The release-grid pattern must embed the same canonical card as the release-card pattern.'
);
mw_assert_same(
	true,
	false !== strpos( $mw_grid_pattern_source, '<!-- wp:query ' ) && false !== strpos( $mw_grid_pattern_source, '<!-- wp:post-template ' ) && false !== strpos( $mw_grid_pattern_source, '<!-- wp:query-no-results -->' ),
	'The release-grid pattern must compose a native Query Loop with an empty state.'
);

// The shared card prefix (root group + media + overlay) is derived from the
// canonical markup instead of being restated here, so this assertion cannot
// disagree with the pattern it guards.
$mw_body_marker   = '<!-- wp:group {"className":"mw-release-card__body"';
$mw_body_position = strpos( $mw_canonical_card, $mw_body_marker );
mw_assert_same( true, false !== $mw_body_position, 'The canonical card must have a body group.' );
$mw_canonical_media = substr( $mw_canonical_card, 0, (int) $mw_body_position );

foreach ( array( 'archive-mw_release.html', 'search.html', 'taxonomy-mw_artist.html', 'taxonomy-mw_genre.html' ) as $mw_release_template ) {
	$mw_template_source = (string) file_get_contents( $theme_directory . '/templates/' . $mw_release_template );
	$mw_template_card   = array();
	preg_match( '/<!-- wp:post-template.*?-->\n(.*?)\n<!-- \/wp:post-template -->/s', $mw_template_source, $mw_template_card );
	$mw_card_markup = isset( $mw_template_card[1] ) ? (string) $mw_template_card[1] : '';

	mw_assert_same(
		true,
		0 === strpos( $mw_card_markup, $mw_canonical_media ),
		$mw_release_template . ' must compose the canonical card media group and preview overlay.'
	);
	mw_assert_same(
		true,
		false !== strpos( $mw_card_markup, $mw_body_marker ),
		$mw_release_template . ' must compose the canonical card body group.'
	);
	mw_assert_same(
		1,
		substr_count( $mw_card_markup, 'wp:music-wave/preview-player' ),
		$mw_release_template . ' must place exactly one .mw-hero-slide__play preview trigger on the card media.'
	);
	mw_assert_same(
		true,
		false !== strpos( $mw_card_markup, 'wp:music-wave/release-meta' ),
		$mw_release_template . ' must keep the compact release metadata in the card body.'
	);
}

// archive.html is the generic archive: it inherits the main query and renders
// any post type, so release-only blocks stay out and there is no overlay - an
// empty overlay would still paint its hover gradient across the artwork.
$mw_generic_source = (string) file_get_contents( $theme_directory . '/templates/archive.html' );
$mw_generic_card   = array();
preg_match( '/<!-- wp:post-template.*?-->\n(.*?)\n<!-- \/wp:post-template -->/s', $mw_generic_source, $mw_generic_card );
$mw_generic_markup = isset( $mw_generic_card[1] ) ? (string) $mw_generic_card[1] : '';
mw_assert_same(
	true,
	false !== strpos( $mw_generic_markup, 'mw-release-card__media' ) && false !== strpos( $mw_generic_markup, $mw_body_marker ),
	'archive.html must compose the canonical card media and body groups.'
);
mw_assert_same(
	false,
	false !== strpos( $mw_generic_markup, 'mw-release-card__overlay' ),
	'archive.html renders any post type, so it must not carry a preview overlay it has nothing to put in.'
);
mw_assert_same(
	false,
	false !== strpos( $mw_generic_markup, 'wp:music-wave/' ),
	'archive.html renders any post type, so it must not embed release-only MusicWave blocks.'
);

$access_css = (string) file_get_contents( $theme_directory . '/assets/css/components/access.css' );
mw_assert_same(
	true,
	false !== strpos( $access_css, '.mw-access-panel--stack' ),
	'The theme must style the stacked access-panel layout.'
);

$catalog_css = (string) file_get_contents( $theme_directory . '/assets/css/components/catalog.css' );
foreach ( array( '.mw-release-meta--compact', '.mw-release-meta__facts--inline', '.mw-release-meta__facts--stack', '.mw-release-meta--panel' ) as $meta_selector ) {
	mw_assert_same(
		true,
		false !== strpos( $catalog_css, $meta_selector ),
		'The theme must style the release metadata variant ' . $meta_selector . '.'
	);
}

$player_css = (string) file_get_contents( $theme_directory . '/assets/css/components/global-player.css' );
foreach ( array( '.mw-preview-button--outline', '.mw-preview-button--ghost', '.mw-preview-button--block' ) as $preview_selector ) {
	mw_assert_same(
		true,
		false !== strpos( $player_css, $preview_selector ),
		'The theme must style the preview button variant ' . $preview_selector . '.'
	);
}

$catalog_filters_css = (string) file_get_contents( $theme_directory . '/assets/css/components/catalog-filters.css' );
mw_assert_same(
	true,
	false !== strpos( $catalog_filters_css, '.mw-catalog-filters--stacked' ),
	'The theme must style the stacked catalog-filters layout.'
);

$artist_css = (string) file_get_contents( $theme_directory . '/assets/css/components/artist.css' );
mw_assert_same(
	true,
	false !== strpos( $artist_css, '.mw-artist-profile__count' ),
	'The theme must style the artist profile release count badge.'
);

$collections_css = (string) file_get_contents( $theme_directory . '/assets/css/components/collections.css' );
foreach ( array( '.mw-release-credits__list--grid', '.mw-release-credits__list--inline', '.mw-collection-list__disc', '.mw-collection-list__artwork' ) as $collection_selector ) {
	mw_assert_same(
		true,
		false !== strpos( $collections_css, $collection_selector ),
		'The theme must style the collection/credits variant ' . $collection_selector . '.'
	);
}

$downloads_css = (string) file_get_contents( $theme_directory . '/assets/css/components/downloads.css' );
mw_assert_same(
	true,
	false !== strpos( $downloads_css, '.mw-download-file-row--no-quality' ),
	'The theme must style download rows without a quality selector.'
);

// page-account.html renders the tabbed account-dashboard directly instead of
// composing it through an inserter pattern, so the panels stay the real ones.
$account_template_contents = (string) file_get_contents( $theme_directory . '/templates/page-account.html' );
mw_assert_same(
	true,
	false !== strpos( $account_template_contents, 'wp:music-wave/account-dashboard' ),
	'page-account.html must render music-wave/account-dashboard directly.'
);
foreach ( array( 'page-library.html', 'page-dashboard.html', 'page-my-account.html' ) as $retired_template ) {
	mw_assert_same(
		false,
		is_file( $theme_directory . '/templates/' . $retired_template ),
		'The retired ' . $retired_template . ' template must be merged into page-account.html.'
	);
}
mw_assert_same(
	true,
	false !== strpos( $functions_source, 'musicwave_alias_account_template' )
		&& false !== strpos( $functions_source, "'page-account'" ),
	'functions.php must alias legacy account templates to the unified page-account template.'
);

// A second sign-in gate is the exact regression the unified dashboard removed,
// so no bundled markup may fall back to the raw WooCommerce account shortcode.
$mw_gate_markup_files = array_merge(
	glob( $theme_directory . '/templates/*.html' ) ?: array(),
	glob( $theme_directory . '/parts/*.html' ) ?: array(),
	glob( $theme_directory . '/patterns/*.php' ) ?: array()
);
foreach ( $mw_gate_markup_files as $mw_gate_file ) {
	mw_assert_same(
		false,
		false !== strpos( (string) file_get_contents( $mw_gate_file ), 'woocommerce_my_account' ),
		basename( $mw_gate_file ) . ' must not embed the raw WooCommerce account shortcode as a second sign-in gate.'
	);
}

$account_library_source = (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/src/Commerce/AccountLibrary.php' );
mw_assert_same(
	false,
	false !== strpos( $account_library_source, "data-mw-dashboard-panel=\"' . esc_attr( \$key ) . '\" hidden" ),
	'Dashboard panels must not be server-hidden; content has to stay visible when JavaScript fails (PROJECT_PLAN.md §14).'
);

$dashboard_script = (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/assets/dashboard.js' );
mw_assert_same(
	true,
	false !== strpos( $dashboard_script, 'closeAll();' ),
	'The dashboard controller must collapse panels on load so tab behavior only applies when JavaScript is available.'
);

$library_blocks_source = (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/src/Blocks/LibraryBlocks.php' );
foreach ( array( 'heading', 'showFilters', 'showCounts', 'columns', 'itemsToShow', 'showArtist', 'showType', 'showYear', 'showRemove', 'emptyMessage' ) as $library_attribute ) {
	mw_assert_same(
		true,
		false !== strpos( $library_blocks_source, '\'' . $library_attribute . '\'' ) && false !== strpos( $rendering_module, '\'' . $library_attribute . '\'' ),
		'The personal library option ' . $library_attribute . ' must be registered for the server and the editor.'
	);
}

mw_assert_same(
	true,
	false !== strpos( $editor_script, '\'music-wave/music-library\': {' )
		&& false !== strpos( $editor_script, '\'music-wave/library-button\': {' ),
	'The editor must expose the personal library and standalone library-button blocks.'
);

$account_library_css = (string) file_get_contents( $theme_directory . '/assets/css/components/account-library.css' );
foreach ( array( '.mw-music-library__tabs', '.mw-music-library__items--grid', '.mw-library-button', '.mw-user-dashboard__stats', '.mw-release-meta__actions', '.mw-user-dashboard__tabs', '.mw-user-dashboard__panel' ) as $library_selector ) {
	mw_assert_same(
		true,
		false !== strpos( $account_library_css, $library_selector ),
		'The theme must style the personal library component ' . $library_selector . '.'
	);
}

$account_library_source = (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/src/Commerce/AccountLibrary.php' );
foreach ( array( 'data-mw-dashboard-tab', 'data-mw-dashboard-panel', 'class_exists( \'WooCommerce\' )', 'VipModuleStatus::is_active()' ) as $dashboard_marker ) {
	mw_assert_same(
		true,
		false !== strpos( $account_library_source, $dashboard_marker ),
		'The dashboard must render tabbed sections with plugin-aware visibility (' . $dashboard_marker . ').'
	);
}
mw_assert_same(
	true,
	is_file( dirname( __DIR__ ) . '/music-wave-core/assets/dashboard.js' ),
	'The dashboard tab controller script must ship with the plugin.'
);

// block.json parity: every dynamic block ships marketplace-grade metadata,
// and its attributes stay synchronized with the editor localization layer.
$music_wave_block_metadata_dir = dirname( __DIR__ ) . '/music-wave-core/blocks';
$music_wave_expected_blocks    = array(
	'release-meta',
	'access-panel',
	'release-credits',
	'collection-list',
	'catalog-filters',
	'catalog-results',
	'search-unified',
	'preview-player',
	'download-button',
	'related-releases',
	'preview-button',
	'artist-profile',
	'account-dashboard',
	'dashboard-welcome',
	'dashboard-stats',
	'dashboard-subscription',
	'dashboard-account',
	'dashboard-addresses',
	'dashboard-payment',
	'dashboard-orders',
	'dashboard-downloads',
	'dashboard-requests',
	'membership-panel',
	'music-library',
	'library-button',
	'playlists',
	'add-to-playlist',
	'public-playlists',
	'continue-listening',
	'artists-shelf',
	'artists-hero',
	'artists-toolbar',
	'artists-alphabet',
	'artists-pagination',
	'taxonomy-shelf',
	'term-hero',
	'playback-queue',
	'add-to-queue',
	'share-button',
	'shuffle-button',
	'request-form',
);
foreach ( $music_wave_expected_blocks as $music_wave_block_slug ) {
	$music_wave_metadata_file = $music_wave_block_metadata_dir . '/' . $music_wave_block_slug . '/block.json';
	mw_assert_same( true, is_file( $music_wave_metadata_file ), 'Every MusicWave block must ship a block.json metadata file (' . $music_wave_block_slug . ').' );

	$music_wave_metadata = json_decode( (string) file_get_contents( $music_wave_metadata_file ), true );
	mw_assert_same( true, is_array( $music_wave_metadata ), $music_wave_block_slug . ' block.json must contain valid JSON.' );
	mw_assert_same( 'music-wave/' . $music_wave_block_slug, isset( $music_wave_metadata['name'] ) ? (string) $music_wave_metadata['name'] : '', $music_wave_block_slug . ' block.json must declare its canonical block name.' );
	mw_assert_same( 3, isset( $music_wave_metadata['apiVersion'] ) ? (int) $music_wave_metadata['apiVersion'] : 0, $music_wave_block_slug . ' must register as an API v3 block.' );
	mw_assert_same( 'music-wave', isset( $music_wave_metadata['category'] ) ? (string) $music_wave_metadata['category'] : '', $music_wave_block_slug . ' must live in the dedicated music-wave inserter category.' );
	mw_assert_same( 'music-wave-core', isset( $music_wave_metadata['textdomain'] ) ? (string) $music_wave_metadata['textdomain'] : '', $music_wave_block_slug . ' must declare the music-wave-core text domain.' );
	mw_assert_same(
		true,
		! empty( $music_wave_metadata['title'] ) && ! empty( $music_wave_metadata['description'] ) && ! empty( $music_wave_metadata['icon'] ),
		$music_wave_block_slug . ' block.json must declare a title, description, and icon.'
	);
	mw_assert_same(
		true,
		isset( $music_wave_metadata['keywords'] ) && is_array( $music_wave_metadata['keywords'] ) && count( $music_wave_metadata['keywords'] ) > 0,
		$music_wave_block_slug . ' block.json must declare inserter keywords.'
	);
	mw_assert_same(
		true,
		isset( $music_wave_metadata['example']['attributes'] ) && is_array( $music_wave_metadata['example']['attributes'] ),
		$music_wave_block_slug . ' block.json must provide an inserter example.'
	);
	mw_assert_same(
		true,
		isset( $music_wave_metadata['attributes'] ) && is_array( $music_wave_metadata['attributes'] ) && count( $music_wave_metadata['attributes'] ) > 0,
		$music_wave_block_slug . ' block.json must declare its attributes.'
	);

	foreach ( array_keys( isset( $music_wave_metadata['attributes'] ) && is_array( $music_wave_metadata['attributes'] ) ? $music_wave_metadata['attributes'] : array() ) as $music_wave_attribute_key ) {
		mw_assert_same(
			true,
			false !== strpos( $rendering_module, "'" . $music_wave_attribute_key . "'" ),
			'The ' . $music_wave_block_slug . ' attribute ' . $music_wave_attribute_key . ' from block.json must stay localized by Rendering.php for the editor.'
		);
	}
}
mw_assert_same(
	count( $music_wave_expected_blocks ),
	count( glob( $music_wave_block_metadata_dir . '/*/block.json' ) ?: array() ),
	'The blocks/ directory must hold exactly one block.json per MusicWave dynamic block.'
);

// Public playlists ship as a first-class Site Editor surface: page-playlists
// composes the section directly from blocks — the block itself renders the
// whole experience, so no wrapper template part or single-block pattern may
// duplicate it.
$page_playlists_template = (string) file_get_contents( $theme_directory . '/templates/page-playlists.html' );
mw_assert_same(
	true,
	false !== strpos( $page_playlists_template, 'wp:music-wave/public-playlists' ),
	'page-playlists.html must compose the public playlists section directly from the block.'
);
mw_assert_same(
	false,
	in_array( 'playlists', $registered_parts, true ) || is_file( $theme_directory . '/parts/playlists.html' ),
	'The public playlists experience must live in the block itself; a wrapper template part would duplicate it.'
);
mw_assert_same(
	false,
	in_array( 'musicwave/public-playlists-section', $registered_patterns, true ),
	'A pattern wrapping only the public playlists block would duplicate its inserter entry; keep composition in templates.'
);

foreach ( array( 'imageShape', 'showToggle', 'showPagination', 'sectionUrl', 'sectionLinkLabel' ) as $public_playlist_attribute ) {
	mw_assert_same(
		true,
		false !== strpos( $editor_script, $public_playlist_attribute ),
		'The editor must expose the public playlists ' . $public_playlist_attribute . ' control.'
	);
}

$public_playlists_css = (string) file_get_contents( $theme_directory . '/assets/css/components/playlists.css' );
foreach ( array( '.mw-public-playlists--minimal', '.mw-public-playlists__grid--scroll', '--mw-shelf-columns', '.mw-public-playlists__more', '.mw-public-playlists__art--circle' ) as $public_playlist_selector ) {
	mw_assert_same(
		true,
		false !== strpos( $public_playlists_css, $public_playlist_selector ),
		'The theme must style the public playlists variant ' . $public_playlist_selector . ' in coordination with the release shelf.'
	);
}

// The artists shelf and continue-listening rails reuse the shared shelf
// chrome, so the theme must ship their component styles and keep them
// registered as style modules.
$artists_shelf_css = (string) file_get_contents( $theme_directory . '/assets/css/components/artists-shelf.css' );
foreach ( array( '.mw-artists-shelf__avatar--circle', '.mw-artists-shelf__initial', '.mw-release-shelf--list .mw-artists-shelf__item', '.mw-artists-shelf__empty' ) as $artists_shelf_selector ) {
	mw_assert_same(
		true,
		false !== strpos( $artists_shelf_css, $artists_shelf_selector ),
		'The theme must style the artists shelf variant ' . $artists_shelf_selector . ' in coordination with the release shelf.'
	);
}
$listening_css = (string) file_get_contents( $theme_directory . '/assets/css/components/listening.css' );
foreach ( array( '.mw-continue-listening__panel', '.mw-continue-listening__header', '.mw-continue-listening__empty' ) as $listening_selector ) {
	mw_assert_same(
		true,
		false !== strpos( $listening_css, $listening_selector ),
		'The theme must style the continue-listening surface ' . $listening_selector . '.'
	);
}
mw_assert_same(
	true,
	false !== strpos( $functions_source, "'musicwave-artists-shelf'" ) && false !== strpos( $functions_source, "'musicwave-listening'" ),
	'functions.php must register the artists-shelf and listening style modules for frontend and editor parity.'
);
mw_assert_same(
	true,
	false !== strpos( $functions_source, "'musicwave-taxonomy-shelf'" ),
	'functions.php must register the taxonomy-shelf style module for frontend and editor parity.'
);
$mw_removed_patterns = array(
	'musicwave/artist-release-shelf',
	'musicwave/editorial-feature',
	'musicwave/featured-release-slider',
	'musicwave/browse-categories',
	'musicwave/horizontal-release-shelf',
	'musicwave/curated-release-shelf',
	'musicwave/new-releases-shelf',
	'musicwave/release-chart',
	'musicwave/popular-artists',
	'musicwave/vinyl-record-shelf',
	'musicwave/section-heading',
	'musicwave/request-cta',
	'musicwave/widget-rich-sidebar',
	'musicwave/sidebar-content',
	'musicwave/breadcrumb-bar',
	'musicwave/catalog-heading',
	'musicwave/cta-banner',
	'musicwave/hero-centered',
	'musicwave/footer-widget-columns',
	'musicwave/widget-search',
	'musicwave/newsletter-signup',
	'musicwave/account-hub',
	'musicwave/album-spec-sheet',
	'musicwave/music-home-layout',
	'musicwave/editorial-highlights',
	'musicwave/editorial-intro',
	'musicwave/editorial-notes',
	'musicwave/widget-panel',
);
foreach ( $mw_removed_patterns as $mw_removed_pattern ) {
	mw_assert_same(
		false,
		in_array( $mw_removed_pattern, $registered_patterns, true ),
		'Removed pattern ' . $mw_removed_pattern . ' must be completely deleted from the theme.'
	);
}
foreach ( array( 'musicwave/release-card', 'musicwave/release-grid', 'musicwave/home-hero', 'musicwave/home-showcase-rail', 'musicwave/music-service-callout' ) as $mw_upgraded_pattern ) {
	mw_assert_same(
		true,
		in_array( $mw_upgraded_pattern, $registered_patterns, true ),
		'Upgraded pattern ' . $mw_upgraded_pattern . ' must remain registered in the theme.'
	);
}

// The full-page demo packs were retired: editors now compose pages from the
// seven bundled sections instead. Their files, the shared chrome composer and
// the demo pattern category must all be gone so nothing re-registers them.
$mw_demo_patterns = array(
	'musicwave/demo-account-studio',
	'musicwave/demo-account-commerce',
	'musicwave/demo-release-showcase',
	'musicwave/demo-artists-directory',
	'musicwave/demo-discovery-browse',
	'musicwave/demo-community-requests',
);
foreach ( $mw_demo_patterns as $mw_demo_pattern ) {
	mw_assert_same(
		false,
		in_array( $mw_demo_pattern, $registered_patterns, true ),
		'Retired demo pattern ' . $mw_demo_pattern . ' must no longer be registered.'
	);
	mw_assert_same(
		false,
		file_exists( $theme_directory . '/patterns/' . substr( $mw_demo_pattern, strlen( 'musicwave/' ) ) . '.php' ),
		'Retired demo pattern must be deleted from disk: ' . $mw_demo_pattern
	);
}
mw_assert_same(
	false,
	file_exists( $theme_directory . '/src/Patterns/DemoComposer.php' ),
	'The shared demo chrome composer must be deleted together with the demo packs.'
);
$mw_block_registry_source = (string) file_get_contents( $theme_directory . '/src/Blocks/BlockRegistry.php' );
foreach ( array( 'musicwave-shelves', 'musicwave-cards' ) as $mw_live_category ) {
	mw_assert_same(
		true,
		false !== strpos( $mw_block_registry_source, "'" . $mw_live_category . "'" ),
		'Pattern category ' . $mw_live_category . ' must stay registered.'
	);
}
foreach ( array( 'musicwave-demos', 'musicwave-hero', 'musicwave-widgets' ) as $mw_dead_category ) {
	mw_assert_same(
		false,
		false !== strpos( $mw_block_registry_source, "'" . $mw_dead_category . "'" ),
		'Unused pattern category ' . $mw_dead_category . ' must not be re-registered.'
	);
}
$mw_editorial_css = (string) file_get_contents( $theme_directory . '/assets/css/components/editorial.css' );
foreach ( array( '.mw-demo__intro', '.mw-demo__badge', '.mw-demo__grid' ) as $mw_demo_selector ) {
	mw_assert_same(
		false,
		false !== strpos( $mw_editorial_css, $mw_demo_selector ),
		'editorial.css must drop the retired demo selector ' . $mw_demo_selector . '.'
	);
}
$mw_release_card_pattern = (string) file_get_contents( $theme_directory . '/patterns/release-card.php' );
mw_assert_same(
	true,
	false !== strpos( $mw_release_card_pattern, 'wp:post-featured-image' )
		&& false !== strpos( $mw_release_card_pattern, 'mw-hero-slide__play' ),
	'The release-card pattern must pair post-featured-image with a .mw-hero-slide__play playback button.'
);
$mw_page_account_template = (string) file_get_contents( $theme_directory . '/templates/page-account.html' );
mw_assert_same(
	true,
	1 === substr_count( $mw_page_account_template, 'wp:music-wave/account-dashboard' ),
	'page-account.html must compose the account dashboard once, without a second gated sign-in surface.'
);
$taxonomy_shelf_css = (string) file_get_contents( $theme_directory . '/assets/css/components/taxonomy-shelf.css' );
foreach ( array( '.mw-terms-shelf__tile--style-colorful.mw-terms-shelf__tile--hue-1', '.mw-terms-shelf__tile--style-plain', '.mw-release-shelf--list .mw-terms-shelf__tile', '.mw-terms-shelf__empty' ) as $taxonomy_shelf_selector ) {
	mw_assert_same(
		true,
		false !== strpos( $taxonomy_shelf_css, $taxonomy_shelf_selector ),
		'The theme must style the taxonomy shelf variant ' . $taxonomy_shelf_selector . ' in coordination with the release shelf.'
	);
}
mw_assert_same(
	true,
	in_array( 'musicwave/genre-mood-browse', $registered_patterns, true ),
	'The theme must ship the genre-mood-browse pattern so editors can drop browse tiles anywhere.'
);

// Archive pages must open with the shared term hero instead of hand-rolled
// page headings, so artists and genres get the same platform-grade landing.
$artist_archive_template = (string) file_get_contents( $theme_directory . '/templates/taxonomy-mw_artist.html' );
mw_assert_same(
	true,
	false !== strpos( $artist_archive_template, 'wp:music-wave/term-hero' ),
	'taxonomy-mw_artist.html must compose its header from the term hero block.'
);
mw_assert_same(
	false,
	false !== strpos( $artist_archive_template, 'music-wave/artist-profile' ) || false !== strpos( $artist_archive_template, 'wp:query-title' ),
	'the artist archive must not duplicate the hero with a profile card or generic archive title.'
);
$genre_archive_template = (string) file_get_contents( $theme_directory . '/templates/taxonomy-mw_genre.html' );
mw_assert_same(
	true,
	false !== strpos( $genre_archive_template, 'wp:music-wave/term-hero' ),
	'taxonomy-mw_genre.html must compose its header from the term hero block.'
);
$term_hero_css = (string) file_get_contents( $theme_directory . '/assets/css/components/term-hero.css' );
foreach ( array( '.mw-term-hero--banner', '.mw-term-hero__scrim', '.mw-term-hero--compact', '.mw-term-hero--hue-1 .mw-term-hero__media', '.mw-term-hero--size-tall' ) as $term_hero_selector ) {
	mw_assert_same(
		true,
		false !== strpos( $term_hero_css, $term_hero_selector ),
		'The theme must style the term hero variant ' . $term_hero_selector . '.'
	);
}
mw_assert_same(
	true,
	false !== strpos( $functions_source, "'musicwave-term-hero'" ),
	'functions.php must register the term-hero style module for frontend and editor parity.'
);
$queue_css = (string) file_get_contents( $theme_directory . '/assets/css/components/queue.css' );
foreach ( array( '.mw-playback-queue__item', '.mw-playback-queue__notice--error', '.mw-playback-queue__controls', '.mw-playback-queue__guest-text' ) as $queue_selector ) {
	mw_assert_same(
		true,
		false !== strpos( $queue_css, $queue_selector ),
		'The theme must style the playback queue surface ' . $queue_selector . '.'
	);
}
mw_assert_same(
	true,
	false !== strpos( $functions_source, "'musicwave-queue'" ),
	'functions.php must register the queue style module for frontend and editor parity.'
);
foreach ( array( '.mw-add-to-queue--guest', '.mw-add-to-queue__in' ) as $add_queue_selector ) {
	mw_assert_same(
		true,
		false !== strpos( $queue_css, $add_queue_selector ),
		'The theme must style the add-to-queue variant ' . $add_queue_selector . '.'
	);
}

$theme_editor_script = (string) file_get_contents( $theme_directory . '/assets/editor-blocks.js' );
foreach ( array( 'playlistOrderBy', 'playlistSearch', "'playlists'" ) as $shelf_playlist_control ) {
	mw_assert_same(
		true,
		false !== strpos( $theme_editor_script, $shelf_playlist_control ),
		'The release shelf editor must expose the public playlist source control (' . $shelf_playlist_control . ').'
	);
}

/*
 * Stage D — music-wave/section-head, the single deliberate InnerBlocks block.
 *
 * The contracts below hold the two rendering lanes apart: a block either renders
 * itself in PHP (leaf + ServerSideRender preview) or stores itself from JS
 * (static container + InnerBlocks), never both. Every assertion names the
 * failure mode it prevents rather than restating the implementation.
 */
$mw_section_head_file     = $theme_directory . '/blocks/section-head/block.json';
$mw_section_head_metadata = json_decode( (string) file_get_contents( $mw_section_head_file ), true );
mw_assert_same( true, is_array( $mw_section_head_metadata ), 'blocks/section-head/block.json must contain valid JSON.' );
mw_assert_same(
	'music-wave/section-head',
	isset( $mw_section_head_metadata['name'] ) ? (string) $mw_section_head_metadata['name'] : '',
	'The static section head must use the canonical music-wave/* namespace.'
);

// No PHP renderer and no second script: the block stores its markup from save().
foreach ( array( 'render', 'render_callback', 'script', 'editorScript', 'viewScript', 'usesContext' ) as $mw_forbidden_key ) {
	mw_assert_same(
		false,
		is_array( $mw_section_head_metadata ) && isset( $mw_section_head_metadata[ $mw_forbidden_key ] ),
		'section-head block.json must not declare ' . $mw_forbidden_key . ': a static container stores its own markup, so a renderer or extra script would open a second rendering path.'
	);
}
mw_assert_same(
	array(
		'sectionUrl'       => array(
			'type'    => 'string',
			'default' => '',
		),
		'sectionLinkLabel' => array(
			'type'    => 'string',
			'default' => '',
		),
	),
	is_array( $mw_section_head_metadata ) && isset( $mw_section_head_metadata['attributes'] ) ? $mw_section_head_metadata['attributes'] : null,
	'The section-head block exposes only optional section-link attributes while keeping content in real child blocks.'
);
mw_assert_same(
	false,
	is_array( $mw_section_head_metadata ) && isset( $mw_section_head_metadata['supports']['inserter'] ),
	'The section-head block must stay visible in the inserter alongside the section-heading pattern.'
);

$mw_section_supports = is_array( $mw_section_head_metadata ) && isset( $mw_section_head_metadata['supports'] ) ? (array) $mw_section_head_metadata['supports'] : array();
mw_assert_same( array( 'wide', 'full' ), isset( $mw_section_supports['align'] ) ? $mw_section_supports['align'] : null, 'The section head must support the wide and full alignments the editorial headers use.' );
mw_assert_same( true, isset( $mw_section_supports['anchor'] ) ? $mw_section_supports['anchor'] : null, 'The section head must expose an HTML anchor so sections can be linked.' );
mw_assert_same( false, isset( $mw_section_supports['html'] ) ? $mw_section_supports['html'] : null, 'A container whose children are real blocks must not offer "Edit as HTML".' );
mw_assert_same( true, isset( $mw_section_supports['color']['background'] ) ? $mw_section_supports['color']['background'] : null, 'The section head must accept a background so the inverted look has something to sit on.' );
mw_assert_same(
	false,
	isset( $mw_section_supports['color']['text'] ) ? $mw_section_supports['color']['text'] : null,
	'A root text colour must stay off: editorial.css sets .mw-section-head h2 and p colours, so the control would be dead. Children carry their own colour controls.'
);
mw_assert_same(
	false,
	isset( $mw_section_supports['spacing']['blockGap'] ) ? $mw_section_supports['spacing']['blockGap'] : null,
	'blockGap must stay off: .mw-section-head sets its own flex gap, so the control would be dead.'
);

// The seeded structure is the one editorial.css actually styles: the __text
// group stacks eyebrow/title/description, while the shell itself is a flex row
// that expects extra siblings such as __rule.
$mw_section_example = is_array( $mw_section_head_metadata ) && isset( $mw_section_head_metadata['example'] ) ? (array) $mw_section_head_metadata['example'] : array();
$mw_example_group   = isset( $mw_section_example['innerBlocks'][0] ) ? (array) $mw_section_example['innerBlocks'][0] : array();
mw_assert_same( 'core/group', isset( $mw_example_group['name'] ) ? (string) $mw_example_group['name'] : '', 'The section-head example must seed a real core/group child, not a wrapper the block owns.' );
mw_assert_same(
	'mw-section-head__text',
	isset( $mw_example_group['attributes']['className'] ) ? (string) $mw_example_group['attributes']['className'] : '',
	'The seeded text group must carry mw-section-head__text, the only element that stacks the header lines vertically.'
);
$mw_example_children = array();
foreach ( isset( $mw_example_group['innerBlocks'] ) && is_array( $mw_example_group['innerBlocks'] ) ? $mw_example_group['innerBlocks'] : array() as $mw_example_child ) {
	$mw_example_children[] = isset( $mw_example_child['name'] ) ? (string) $mw_example_child['name'] : '';
}
mw_assert_same(
	array( 'core/paragraph', 'core/heading', 'core/paragraph' ),
	$mw_example_children,
	'The section-head example must seed eyebrow, level-2 title and description, matching the PHP shelf header order.'
);
mw_assert_same(
	'mw-eyebrow',
	isset( $mw_example_group['innerBlocks'][0]['attributes']['className'] ) ? (string) $mw_example_group['innerBlocks'][0]['attributes']['className'] : '',
	'The seeded eyebrow must carry mw-eyebrow so it renders as the editorial kicker.'
);
mw_assert_same(
	2,
	isset( $mw_example_group['innerBlocks'][1]['attributes']['level'] ) ? (int) $mw_example_group['innerBlocks'][1]['attributes']['level'] : 0,
	'The seeded title must be a level-2 heading, the level .mw-section-head h2 styles.'
);

// Registration: static blocks are registered from block.json with no callback
// and no legacy alias, on the same init hook as the dynamic lane.
preg_match( '/public function register_static_blocks\(\)\s*:\s*void\s*\{(.*?)\n\t\}/s', $functions_source, $mw_static_register_match );
mw_assert_same( true, isset( $mw_static_register_match[1] ), 'functions.php must declare musicwave_register_static_blocks().' );
$mw_static_register_body = isset( $mw_static_register_match[1] ) ? (string) $mw_static_register_match[1] : '';
mw_assert_same( true, false !== strpos( $mw_static_register_body, 'register_block_type( $path )' ), 'Static blocks must be registered straight from their block.json directory.' );
mw_assert_same( false, false !== strpos( $mw_static_register_body, 'render_callback' ), 'The static lane must not attach a render callback.' );
mw_assert_same(
	false,
	false !== strpos( $mw_static_register_body, 'register_legacy_presentation_block' ),
	'The static lane must not register a legacy musicwave/* alias: no content was ever saved under another name.'
);
mw_assert_same(
	true,
	false !== strpos( $functions_source, "'init'" ) && false !== strpos( $functions_source, "'register_static_blocks'" ),
	'Static blocks must register on init beside the dynamic presentation blocks.'
);
mw_assert_same(
	true,
	substr_count( $functions_source, 'block_metadata_entry(' ) >= 3,
	'Both editor lanes must build their client metadata through the one block_metadata_entry() helper.'
);
mw_assert_same(
	true,
	false !== strpos( $functions_source, "'musicwaveStaticBlocks'" ) && false !== strpos( $theme_editor_script, 'window.musicwaveStaticBlocks' ),
	'The static lane must be localized to the editor script under its own key.'
);

// Editor JS: the lanes must not borrow each other's rendering strategy. The
// static lane ends at the IIFE invocation, whose argument list names every
// dependency regardless of which lane uses it.
$mw_static_lane_start = strpos( $theme_editor_script, 'var sectionHeadTemplate' );
mw_assert_same( true, false !== $mw_static_lane_start, 'editor-blocks.js must register the static child-bearing lane.' );
$mw_static_lane_end = strpos( $theme_editor_script, '} )(', (int) $mw_static_lane_start );
mw_assert_same( true, false !== $mw_static_lane_end, 'editor-blocks.js must keep its single IIFE invocation after the registration lanes.' );
$mw_dynamic_lane = substr( $theme_editor_script, 0, (int) $mw_static_lane_start );
$mw_static_lane  = substr( $theme_editor_script, (int) $mw_static_lane_start, (int) $mw_static_lane_end - (int) $mw_static_lane_start );
mw_assert_same(
	false,
	false !== strpos( $mw_dynamic_lane, 'blockEditor.InnerBlocks' ),
	'The PHP-rendered lane must stay free of InnerBlocks: those blocks build their chrome from attributes in PHP, so a JS container would duplicate it.'
);
mw_assert_same(
	false,
	false !== strpos( $mw_static_lane, 'createElement( serverSideRender' ),
	'The static lane must not use ServerSideRender: its children are real blocks, so a REST preview would add a second rendering path.'
);
foreach ( array( 'blockEditor.useBlockProps(', 'blockEditor.useBlockProps.save(', 'blockEditor.InnerBlocks.Content', 'templateLock: false', "className: 'mw-section-head'", "className: 'mw-section-head__text'", "className: 'mw-eyebrow'", 'level: 2' ) as $mw_static_hook ) {
	mw_assert_same(
		true,
		false !== strpos( $mw_static_lane, $mw_static_hook ),
		'The static section-head registration must use ' . $mw_static_hook . '.'
	);
}
mw_assert_same(
	true,
	substr_count( $theme_editor_script, 'translatedMetadata( block )' ) >= 2,
	'Both editor lanes must translate block.json metadata through the one translatedMetadata() helper.'
);

// Block styles ↔ stylesheet: the Styles panel writes is-style-<slug>, PHP headers
// keep the BEM modifier, and editorial.css must carry one declaration set for
// both spellings so a stored header and a rendered header cannot drift.
preg_match( '/public function section_head_styles\(\)\s*:\s*array\s*\{(.*?)\n\t\}/s', $functions_source, $mw_section_style_match );
mw_assert_same( true, isset( $mw_section_style_match[1] ), 'functions.php must declare musicwave_section_head_styles().' );
preg_match_all( "/'([a-z-]+)'\s*=>\s*__\(/", isset( $mw_section_style_match[1] ) ? (string) $mw_section_style_match[1] : '', $mw_section_style_slugs );
mw_assert_same(
	array( 'center', 'stack', 'invert' ),
	isset( $mw_section_style_slugs[1] ) ? $mw_section_style_slugs[1] : array(),
	'The section head must offer the three editorial modifiers editorial.css ships, each with a translatable label.'
);
mw_assert_same(
	true,
	( false !== strpos( $functions_source, 'foreach ( $this->section_head_styles() as $style_name => $style_label )' )
		|| false !== strpos( $functions_source, 'foreach ( musicwave_section_head_styles() as $style_name => $style_label )' ) )
		&& false !== strpos( $functions_source, "'music-wave/section-head'," ),
	'Every section-head look must be registered as a block style on music-wave/section-head.'
);

$mw_editorial_css = (string) file_get_contents( $theme_directory . '/assets/css/components/editorial.css' );
// Each needle is anchored to a rule start: an unanchored selector also matches
// the descendant rules (.mw-section-head--center .mw-section-head__rule {), so
// deleting a component rule would otherwise go unnoticed.
foreach ( array( '.mw-section-head {', '.mw-section-head__text {', '.mw-section-head__rule {', '.mw-section-head h2 {', '.mw-section-head p {' ) as $mw_head_selector ) {
	mw_assert_same(
		true,
		false !== strpos( $mw_editorial_css, "\n" . $mw_head_selector ),
		'editorial.css must keep the section-head declaration block ' . $mw_head_selector . '.'
	);
}
foreach ( $mw_section_style_slugs[1] as $mw_section_slug ) {
	/*
	 * Count both spellings rather than merely looking for them: every BEM
	 * modifier rule needs an is-style twin, otherwise the look a PHP-rendered
	 * header gets would silently differ from the look the Styles panel applies
	 * to a stored container.
	 */
	$mw_bem_rules   = substr_count( $mw_editorial_css, '.mw-section-head--' . $mw_section_slug );
	$mw_style_rules = substr_count( $mw_editorial_css, '.mw-section-head.is-style-' . $mw_section_slug );
	mw_assert_same( true, $mw_bem_rules > 0, 'editorial.css must keep the BEM modifier mw-section-head--' . $mw_section_slug . '.' );
	mw_assert_same(
		$mw_bem_rules,
		$mw_style_rules,
		'editorial.css must give every mw-section-head--' . $mw_section_slug . ' rule an is-style-' . $mw_section_slug . ' twin, so the Styles panel and PHP headers share one declaration set.'
	);
}

// Verify zero templates, template parts, or patterns reference any non-existent pattern slug.
$mw_block_markup_files = array_merge(
	glob( $theme_directory . '/templates/*.html' ) ?: array(),
	glob( $theme_directory . '/parts/*.html' ) ?: array(),
	glob( $theme_directory . '/patterns/*.php' ) ?: array()
);
foreach ( $mw_block_markup_files as $mw_markup_file ) {
	$mw_markup_content = (string) file_get_contents( $mw_markup_file );
	if ( preg_match_all( '/<!--\s*wp:pattern\s+\{[^}]*"slug"\s*:\s*"([^"]+)"/', $mw_markup_content, $mw_pat_matches ) ) {
		foreach ( $mw_pat_matches[1] as $mw_ref_slug ) {
			if ( 0 !== strpos( $mw_ref_slug, 'musicwave/' ) ) {
				continue;
			}
			mw_assert_same(
				true,
				in_array( $mw_ref_slug, $registered_patterns, true ),
				basename( $mw_markup_file ) . ' references missing/deleted pattern "' . $mw_ref_slug . '".'
			);
		}
	}
}
mw_assert_same(
	false,
	false !== strpos( $functions_source, 'mw-vinyl-shelf' ),
	'Do not reintroduce a vinyl wrapper class that is not in the live pattern.'
);

// Empty PHP headers: fill one-insert copy only when the editor already set
// eyebrow or title, so a canvas section-head can sit above without a second
// heading. The playlist more-link auto-URL is gated the same way.
mw_assert_same(
	true,
	substr_count( $functions_source, "'' !== \$eyebrow || '' !== \$title" ) >= 3,
	'Slider, playlist copy, and playlist auto-URL must all gate on eyebrow-or-title being set.'
);
mw_assert_same(
	true,
	false !== strpos( $release_blocks_source, "'' !== \$heading ? '<h2>'" )
		&& false !== strpos( $release_blocks_source, "'' !== \$heading_html ? '<div class=\"mw-related-releases__section-header\">'" ),
	'Related-releases must skip an empty h2 and omit the section-header wrapper when no heading is set.'
);

mw_assert_same(
	true,
	false !== strpos( $theme_editor_script, 'بالای اسلایدر قرار دهید' )
		&& false !== strpos( $theme_editor_script, 'بالای ویترین قرار دهید' ),
	'Shelf and slider heading fields must tell editors to leave them empty and use section-head on the canvas.'
);

mw_assert_same(
	true,
	false !== strpos( $editor_script, "'music-wave/release-meta' === blockName" )
		&& false !== strpos( $editor_script, "'layout' === attr" )
		&& false !== strpos( $editor_script, 'false !== props.attributes.compact' ),
	'Compact release-meta must hide the multi-item layout selector that has no effect in inline dot-separated compact mode.'
);

mw_assert_same(
	true,
	false !== strpos( $release_blocks_source, "'' !== \$same_artist_heading || '' !== \$similar_heading" )
		&& false !== strpos( $release_blocks_source, 'Fill one-insert copy only when' ),
	'Related-releases must fill default headings only when at least one heading attribute is already set.'
);
mw_assert_same(
	true,
	false !== strpos( $editor_script, 'هر دو عنوان را خالی بگذارید' )
		&& false !== strpos( $editor_script, 'خالی بماند تا سربرگ این بخش چاپ نشود' ),
	'Related-releases heading fields must tell editors to leave them empty and use section-head on the canvas.'
);
mw_assert_same(
	true,
	false !== strpos( $editor_script, "'music-wave/download-button' === blockName" )
		&& false !== strpos( $editor_script, "[ 'heading', 'description' ]" )
		&& false !== strpos( $editor_script, 'Compact download-button' ),
	'Compact download-button must hide heading and description controls that have no effect on the inline action group.'
);

// Request-form: pure form block (legacy layout/side/header/highlights panels
// deleted completely); editorial chrome is composed via native Gutenberg canvas
// blocks on the shared __* classes. POST/nonce/honeypot stay strictly in PHP,
// and editor canvas styles load `request-form.css` alongside `editor.css`.
$mw_request_form_php = (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/src/Blocks/RequestFormBlock.php' );
$mw_request_form_meta = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/blocks/request-form/block.json' ), true );
$mw_request_form_attrs = is_array( $mw_request_form_meta ) && isset( $mw_request_form_meta['attributes'] ) ? (array) $mw_request_form_meta['attributes'] : array();
$mw_request_form_css = (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/assets/request-form.css' );
$mw_editor_canvas_css = (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/assets/editor.css' );
$mw_rendering_src = (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/src/Modules/Rendering.php' );
$mw_req_editor_start  = strpos( $editor_script, "'music-wave/request-form': {" );
$mw_req_editor_end    = false !== $mw_req_editor_start ? strpos( $editor_script, "'music-wave/shuffle-button': {", $mw_req_editor_start ) : false;
$mw_req_editor_slice  = ( false !== $mw_req_editor_start && false !== $mw_req_editor_end )
	? substr( $editor_script, $mw_req_editor_start, $mw_req_editor_end - $mw_req_editor_start )
	: '';
mw_assert_same( true, '' !== $mw_req_editor_slice, 'Core blocks.js must register music-wave/request-form controls.' );
foreach ( array( 'music-wave-request-form', 'music-wave-library-style', 'music-wave-request-chat' ) as $mw_editor_style_handle ) {
	mw_assert_same(
		true,
		false !== strpos( $mw_rendering_src, "'" . $mw_editor_style_handle . "'" ),
		'Rendering::enqueue_editor_canvas_styles() must enqueue ' . $mw_editor_style_handle . ' in the editor canvas.'
	);
}
foreach ( array( 'layout', 'showHeading', 'showTypeChips', 'eyebrow', 'heading', 'intro', 'showHighlights', 'highlightsHeading', 'highlightsBody', 'showSteps' ) as $mw_deleted_req_attr ) {
	mw_assert_same(
		false,
		isset( $mw_request_form_attrs[ $mw_deleted_req_attr ] ),
		'request-form/block.json must permanently omit the legacy "' . $mw_deleted_req_attr . '" attribute.'
	);
	mw_assert_same(
		false,
		false !== strpos( $mw_req_editor_slice, "'" . $mw_deleted_req_attr . "'" ),
		'Core blocks.js must not expose legacy request-form control "' . $mw_deleted_req_attr . '".'
	);
}
foreach ( array( 'چیدمان و ستون کنار', 'متن خدمات' ) as $mw_deleted_req_panel ) {
	mw_assert_same(
		false,
		false !== strpos( $editor_script, $mw_deleted_req_panel ),
		'Core blocks.js must permanently omit legacy panel "' . $mw_deleted_req_panel . '".'
	);
}
mw_assert_same(
	false,
	false !== strpos( $mw_request_form_php, 'InnerBlocks' ),
	'RequestFormBlock must keep rendering the form in PHP; do not add InnerBlocks on the SSR form.'
);
mw_assert_same(
	true,
	false !== strpos( $mw_request_form_php, 'admin-post.php' )
		&& false !== strpos( $mw_request_form_php, 'HONEYPOT' )
		&& false !== strpos( $mw_request_form_php, 'wp_nonce_field' ),
	'Request form POST, nonce and honeypot must stay in the PHP renderer.'
);
mw_assert_same(
	true,
	false !== strpos( $mw_request_form_css, '.mw-request-form .mw-request-form {' )
		&& false !== strpos( $mw_request_form_css, 'ol.wp-block-list.mw-request-form__steps > li::before' ),
	'Request-form CSS must flatten a nested PHP form and number core/list steps cleanly.'
);
mw_assert_same(
	true,
	false !== strpos( $mw_editor_canvas_css, '.mw-block-editor-shell .mw-request-form__form' )
		&& false === strpos( $mw_editor_canvas_css, '.mw-block-editor-shell > .wp-block-music-wave-request-form .mw-request-form__form' ),
	'Canvas form lock must be a descendant selector so a composed wrapper still disables submit.'
);

$mw_request_templates = array(
	$theme_directory . '/templates/page-requests.html',
	$theme_directory . '/templates/page-request-song.html',
	$theme_directory . '/templates/page-request-collab.html',
);
foreach ( $mw_request_templates as $mw_request_template ) {
	$mw_request_html = (string) file_get_contents( $mw_request_template );
	mw_assert_same(
		true,
		false !== strpos( $mw_request_html, 'mw-request-form__heading' )
			&& false === strpos( $mw_request_html, '"showHeading"' )
			&& false !== strpos( $mw_request_html, '"slug":"breadcrumb"' )
			&& 1 === substr_count( $mw_request_html, 'wp:music-wave/request-form' ),
		basename( $mw_request_template ) . ' must pair canvas request chrome and breadcrumb with a pure self-closing PHP form.'
	);
}


mw_assert_same(
	true,
	is_array( $mw_section_head_metadata )
		&& false !== strpos( (string) $mw_section_head_metadata['description'], 'عنوان بخش' )
		&& false === strpos( (string) $mw_section_head_metadata['description'], 'الگوی «عنوان بخش»' ),
	'The section-head block description must remain clear without referencing the removed section-heading pattern.'
);

// No PHP/SSR product block may be converted to make this pilot work.
foreach ( array( 'ReleaseBlocks.php', 'PlaylistBlocks.php', 'ListeningBlocks.php' ) as $mw_ssr_block_file ) {
	$mw_ssr_source = (string) file_get_contents( dirname( $theme_directory ) . '/music-wave-core/src/Blocks/' . $mw_ssr_block_file );
	mw_assert_same(
		false,
		false !== strpos( $mw_ssr_source, 'InnerBlocks' ),
		$mw_ssr_block_file . ' must keep rendering its product blocks in PHP; the InnerBlocks pilot must not spread to them.'
	);
}
mw_assert_same(
	false,
	false !== strpos( (string) file_get_contents( dirname( $theme_directory ) . '/music-wave-core/assets/blocks.js' ), 'InnerBlocks' ),
	'Core editor JS must not gain an InnerBlocks path for its ServerSideRender blocks.'
);

// The release shelf hero slider ("اسلایدر هیرو") is the reference-style
// crossfading layout: a dedicated stylesheet, a self-initializing fade
// engine, and the render-time script enqueue wired in functions.php.
$hero_slider_css = (string) file_get_contents( $theme_directory . '/assets/css/components/hero-slider.css' );
foreach ( array( '.mw-hero-slide', '.mw-hero-slide.is-active', '.mw-hero-slide__scrim', '.mw-hero-slider__dot.is-active', 'prefers-reduced-motion' ) as $hero_slider_selector ) {
	mw_assert_same(
		true,
		false !== strpos( $hero_slider_css, $hero_slider_selector ),
		'The theme must style the hero slider variant ' . $hero_slider_selector . ' in coordination with the release shelf.'
	);
}

$hero_slider_script = (string) file_get_contents( $theme_directory . '/assets/hero-slider.js' );
foreach ( array( 'data-mw-hero-slider', 'data-mw-hero-previous', 'data-mw-hero-next', '[data-mw-hero-dot]', 'prefers-reduced-motion', 'mw-page-rendered' ) as $hero_slider_hook ) {
	mw_assert_same(
		true,
		false !== strpos( $hero_slider_script, $hero_slider_hook ),
		'The hero slider script must bind the ' . $hero_slider_hook . ' hook.'
	);
}

mw_assert_same(
	true,
	false !== strpos( $functions_source, 'musicwave_render_hero_slider' )
		&& false !== strpos( $functions_source, "'musicwave-hero-slider'" )
		&& false !== strpos( $functions_source, "'slider'" ),
	'functions.php must render the hero slider layout and register its style module and render-time script.'
);

$release_shelf_meta  = json_decode( (string) file_get_contents( $theme_directory . '/blocks/release-shelf/block.json' ), true );
$release_shelf_attrs = is_array( $release_shelf_meta ) && isset( $release_shelf_meta['attributes'] ) ? (array) $release_shelf_meta['attributes'] : array();
foreach ( array( 'layout', 'autoplay', 'showArrows', 'showDots', 'interval' ) as $hero_attribute ) {
	mw_assert_same(
		true,
		isset( $release_shelf_attrs[ $hero_attribute ] ),
		'The release shelf block.json must declare the ' . $hero_attribute . ' attribute for the hero slider layout.'
	);
}

mw_assert_same(
	true,
	false !== strpos( $theme_editor_script, "'slider'" ),
	'The release shelf editor must expose the hero slider layout option.'
);

/*
 * Style-variation contract.
 *
 * Every look offered in the block editor must exist three times: in the
 * registry (PHP), in the stylesheet (the modifier class the renderer emits),
 * and in the editor surface the admin picks it from. Parsing the two
 * registries keeps this assertion honest when a slug is added or renamed —
 * a label can never ship without the CSS behind it.
 */
$theme_variations = array();
$current_source    = '';
$current_variation = '';
$inside_registry   = false;
$registry_indent   = '';
foreach ( explode( "\n", $functions_source ) as $functions_line ) {
	if ( false !== strpos( $functions_line, 'public function presentation_style_variations()' ) ) {
		$inside_registry = true;
		continue;
	}
	if ( ! $inside_registry ) {
		continue;
	}
	if ( 0 === strpos( $functions_line, "\t}" ) || 0 === strpos( $functions_line, '}' ) ) {
		break;
	}
	/*
	 * The registry body uses one indentation level per nesting step. Reading
	 * that step from the `return array(` line keeps the parser working when
	 * the whole function is re-indented, instead of silently matching nothing.
	 */
	if ( '' === $registry_indent ) {
		if ( preg_match( '/^(\t+)return array\($/', $functions_line, $indent_match ) ) {
			$registry_indent = $indent_match[1];
		}
		continue;
	}
	if ( preg_match( '/^' . $registry_indent . "\t'([a-z0-9-]+)'\s*=>\s*array\(/", $functions_line, $source_match ) ) {
		$current_source                      = $source_match[1];
		$theme_variations[ $current_source ] = array();
		$current_variation                   = '';
		continue;
	}
	if ( '' !== $current_source && preg_match( '/^' . $registry_indent . "\t\t'([a-z0-9-]+)'\s*=>\s*array\(/", $functions_line, $variation_match ) ) {
		$current_variation = $variation_match[1];
		$theme_variations[ $current_source ][ $current_variation ] = '';
		continue;
	}
	/*
	 * Keep each variation's own body: the stylesheet assertion below needs to
	 * read the `is_default` marker off it, not just the slug.
	 */
	if ( '' !== $current_variation ) {
		$theme_variations[ $current_source ][ $current_variation ] .= $functions_line . "\n";
	}
}

mw_assert_same(
	array( 'release-shelf', 'release-slider', 'breadcrumb', 'artist-profile', 'term-hero', 'header-search' ),
	array_keys( $theme_variations ),
	'functions.php must expose style variations for all theme presentation blocks.'
);

$release_slider_meta  = json_decode( (string) file_get_contents( $theme_directory . '/blocks/release-slider/block.json' ), true );
$release_slider_attrs = is_array( $release_slider_meta ) && isset( $release_slider_meta['attributes'] ) ? (array) $release_slider_meta['attributes'] : array();
foreach ( array( 'imageShape', 'imageSize', 'filterTabs' ) as $mw_slider_upgrade_attr ) {
	mw_assert_same(
		true,
		isset( $release_slider_attrs[ $mw_slider_upgrade_attr ] ),
		'release-slider/block.json must expose the "' . $mw_slider_upgrade_attr . '" attribute.'
	);
}
foreach ( array( 'asideTitle', 'sideColumnWidth', 'overlay', 'heroTitleColor', 'heroTitleSize', 'heroTextColor', 'filterTabs' ) as $mw_shelf_upgrade_attr ) {
	mw_assert_same(
		true,
		isset( $release_shelf_attrs[ $mw_shelf_upgrade_attr ] ),
		'release-shelf/block.json must expose the "' . $mw_shelf_upgrade_attr . '" attribute.'
	);
}
foreach (
	array(
		'404.html',
		'archive-mw_release.html',
		'archive-product.html',
		'archive.html',
		'index.html',
		'page-account.html',
		'page-artists.html',
		'page-browse.html',
		'page-cart.html',
		'page-genres.html',
		'page-checkout.html',
		'page-no-sidebar.html',
		'page-playlists.html',
		'page-request-collab.html',
		'page-request-song.html',
		'page-requests.html',
		'page-shop.html',
		'page-wide.html',
		'page-with-sidebar.html',
		'page.html',
		'search.html',
		'single-mw_release.html',
		'single-product.html',
		'single.html',
		'taxonomy-mw_artist.html',
		'taxonomy-mw_genre.html',
	) as $mw_breadcrumb_template
) {
	$mw_breadcrumb_tpl_html = (string) file_get_contents( $theme_directory . '/templates/' . $mw_breadcrumb_template );
	mw_assert_same(
		true,
		false !== strpos( $mw_breadcrumb_tpl_html, '"slug":"breadcrumb"' ),
		$mw_breadcrumb_template . ' must include the modular breadcrumb template part.'
	);
}
mw_assert_same( true, count( $theme_variations['release-shelf'] ) >= 3, 'The release shelf must offer at least three clean surface looks (minimal, editorial, glass), while bento lives in layout.' );
mw_assert_same( false, isset( $theme_variations['release-shelf']['bento'] ), 'Bento must live in layout rather than conflicting with hero slider in style variations.' );
mw_assert_same( true, count( $theme_variations['release-slider'] ) >= 2, 'The release slider must offer at least two looks.' );

$component_css = '';
foreach ( (array) glob( $theme_directory . '/assets/css/components/*.css' ) as $component_stylesheet ) {
	$component_css .= (string) file_get_contents( $component_stylesheet );
}
/*
 * A registered look may also be a block's *default*: `artist-profile` offers
 * `editorial` as the framed card every other skin is measured against, so that
 * slug is deliberately absent from the stylesheet — the base `.mw-artist-profile`
 * box already draws it, and repeating those declarations would be a second copy
 * of the same look. Such a slug declares itself with `is_default` (the same
 * marker the core variation registry uses) and is excused from shipping a rule
 * of its own.
 */
foreach ( $theme_variations as $source => $variation_names ) {
	foreach ( $variation_names as $variation_name => $variation_body ) {
		if ( false !== strpos( $variation_body, "'is_default'" ) ) {
			continue;
		}
		mw_assert_same(
			true,
			false !== strpos( $component_css, 'mw-' . $source . '--' . $variation_name ),
			'The ' . $source . ' variation "' . $variation_name . '" must have a stylesheet rule for mw-' . $source . '--' . $variation_name . '.'
		);
	}
}

mw_assert_same(
	true,
	substr_count( $functions_source, 'style_variant_class(' ) >= 4,
	'All presentation renderers (slider, shelf, playlist shelf, and breadcrumb) must resolve the chosen variation into a modifier class.'
);
foreach ( array( 'musicwave-editorial', 'musicwave-vinyl' ) as $style_module ) {
	mw_assert_same(
		true,
		false !== strpos( $functions_source, "'" . $style_module . "'" ),
		'functions.php must register the ' . $style_module . ' style module.'
	);
}

// The two editor surfaces must both expose the picker: the Styles panel
// (register_block_style) and the select inside the block settings panel.
foreach ( array( 'musicwavePresentationVariations', 'is-style-', 'styleVariant' ) as $editor_marker ) {
	mw_assert_same(
		true,
		false !== strpos( $theme_editor_script, $editor_marker ),
		'The theme block editor must expose the appearance picker marker ' . $editor_marker . '.'
	);
}

$core_rendering_source = (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/src/Modules/Rendering.php' );
$core_editor_script    = (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/assets/blocks.js' );
foreach ( array( 'public static function style_variations()', "'musicWaveBlockStyles'", 'styleBackedAttributes' ) as $core_marker ) {
	mw_assert_same(
		true,
		false !== strpos( $core_rendering_source . $core_editor_script, $core_marker ),
		'The Core plugin must expose the shared style registry marker ' . $core_marker . '.'
	);
}
mw_assert_same(
	false,
	strpos( $core_editor_script, 'استایل و ظاهر' ),
	'Core blocks.js must not duplicate Gutenberg native Styles panel inside Settings.'
);
mw_assert_same(
	false,
	strpos( $theme_editor_script, 'استایل و ظاهر' ),
	'Theme editor-blocks.js must not duplicate Gutenberg native Styles panel inside Settings.'
);

/*
 * Block name => the component classes the renderer can turn a variation into.
 * Most blocks modify their own root; some (preview player, release meta)
 * delegate the look to a sub-component, so both candidates are accepted.
 */
$core_style_components = array(
	'music-wave/preview-player'    => array( 'mw-preview-button', 'mw-preview-player' ),
	'music-wave/account-dashboard' => array( 'mw-user-dashboard' ),
	'music-wave/preview-button'    => array( 'mw-preview-button' ),
	'music-wave/release-meta'      => array( 'mw-release-meta__facts', 'mw-release-meta' ),
	'music-wave/catalog-filters'   => array( 'mw-catalog-filters' ),
	'music-wave/collection-list'   => array( 'mw-collection-list' ),
	'music-wave/related-releases'  => array( 'mw-related-releases' ),
	'music-wave/catalog-results'   => array( 'mw-catalog-results' ),
	'music-wave/artist-profile'    => array( 'mw-artist-profile' ),
	'music-wave/artists-shelf'     => array( 'mw-artists-shelf' ),
	'music-wave/public-playlists'  => array( 'mw-public-playlists' ),
	'music-wave/term-hero'         => array( 'mw-term-hero' ),
);
$core_style_block   = '';
$core_style_chunk   = '';
$core_variation_map = array();
$inside_styles      = false;
foreach ( explode( "\n", $core_rendering_source ) as $rendering_line ) {
	if ( false !== strpos( $rendering_line, 'public static function style_variations(): array {' ) ) {
		$inside_styles = true;
		continue;
	}
	if ( ! $inside_styles ) {
		continue;
	}
	if ( preg_match( '/^\t\}\s*$/', $rendering_line ) ) {
		break;
	}
	if ( preg_match( "/^\s*'(music-wave\/[a-z0-9-]+)'\s*=>\s*array\(/", $rendering_line, $block_match ) ) {
		if ( '' !== $core_style_block ) {
			$core_variation_map[ $core_style_block ] = $core_style_chunk;
		}
		$core_style_block = $block_match[1];
		$core_style_chunk = '';
		continue;
	}
	$core_style_chunk .= $rendering_line . "\n";
}
if ( '' !== $core_style_block ) {
	$core_variation_map[ $core_style_block ] = $core_style_chunk;
}

$core_style_blocks = array_keys( $core_variation_map );
sort( $core_style_blocks );
$core_style_expected = array_keys( $core_style_components );
sort( $core_style_expected );
mw_assert_same(
	$core_style_expected,
	$core_style_blocks,
	'Rendering.php must register style variations for exactly the known dynamic blocks (the parser and the map must agree).'
);
foreach ( $core_variation_map as $core_style_block_name => $core_style_body ) {
	// Read one entry per 'name' key: everything up to the next one.
	preg_match_all( "/'name'\s*=>\s*'([a-z0-9-]+)'(.*?)(?='name'\s*=>|\z)/s", $core_style_body, $core_style_entries, PREG_SET_ORDER );
	mw_assert_same( true, ! empty( $core_style_entries ), $core_style_block_name . ' must declare at least one style variation.' );
	foreach ( $core_style_entries as $core_style_entry ) {
		$core_style_name = $core_style_entry[1];
		mw_assert_same(
			true,
			false !== strpos( $core_style_entry[2], "'label'" ),
			$core_style_block_name . ' style variation "' . $core_style_name . '" must carry a translatable label.'
		);
		if ( false !== strpos( $core_style_entry[2], "'is_default'" ) ) {
			// The default look is the component's own stylesheet.
			continue;
		}
		$core_style_candidates = array();
		foreach ( $core_style_components[ $core_style_block_name ] as $core_style_component ) {
			$core_style_candidates[] = $core_style_component . '--' . $core_style_name;
		}
		$core_style_found = false;
		foreach ( $core_style_candidates as $core_style_class ) {
			if ( false !== strpos( $component_css, $core_style_class ) ) {
				$core_style_found = true;
				break;
			}
		}
		mw_assert_same(
			true,
			$core_style_found,
			'The registered variation ' . $core_style_block_name . ' "' . $core_style_name . '" must ship one of these stylesheet rules: ' . implode( ', ', $core_style_candidates ) . '.'
		);
	}
}

/*
 * Editor preview fidelity, style-variation identity, and the secure download
 * label contract.
 *
 * Each of these is a regression a PHP-only test run cannot see: the preview
 * context lives in the editor bundle, the variations live in the stylesheets,
 * and the labels live in the rendered markup. The source contracts are
 * asserted directly so the fixes stay fixed.
 */

// Query Loop: every row must preview against its own post (while filtering non-numeric Site Editor template IDs).
mw_assert_same(
	true,
	false !== strpos( $core_editor_script, 'urlQueryArgs' )
		&& ( false !== strpos( $core_editor_script, 'queryArgs.post_id = numericPostId' )
			|| false !== strpos( $core_editor_script, 'post_id: contextPost.postId' )
			|| false !== strpos( $core_editor_script, 'queryArgs.post_id = contextPost.postId' ) ),
	'The editor preview must send the contextual post to the block renderer so a Query Loop row renders its own release.'
);
mw_assert_same(
	true,
	false === strpos( $core_editor_script, 'پیش‌نمایش انتشار' )
		&& false === strpos( $core_editor_script, 'useReleaseOptions' ),
	'The legacy release-preview dropdown and arbitrary first-post fallback must remain completely removed in favor of self-contained editor previews.'
);
mw_assert_same(
	true,
	false !== strpos( $core_editor_script, 'if ( existing && ! blocks.getBlockType( block.name ) ) {' ),
	'Re-registration must restore the server definition when the editor settings are rejected, so a block is never left unregistered inside a template.'
);

// Catalog filters: the stacked look must differ from the toolbar structurally.
$catalog_filters_stylesheet = (string) file_get_contents( $theme_directory . '/assets/css/components/catalog-filters.css' );
foreach ( array( 'grid-template-columns', '.mw-catalog-filters__actions', 'grid-column: 1 / -1' ) as $stacked_rule ) {
	mw_assert_same(
		true,
		false !== strpos( $catalog_filters_stylesheet, $stacked_rule ),
		'The stacked catalog-filters variation must ship the "' . $stacked_rule . '" rule that separates it from the inline toolbar.'
	);
}
mw_assert_same(
	true,
	false !== strpos( $release_blocks_source, 'mw-catalog-filters__actions' ),
	'The catalog filter renderer must group the submit and reset controls so both looks can place them as one unit.'
);

// Collection list: the tracklist look must be its own design, not a tweak.
$vinyl_stylesheet = (string) file_get_contents( $theme_directory . '/assets/css/components/vinyl.css' );
foreach ( array( '.mw-collection-list.mw-collection-list--tracklist {', 'border-block-end: 1px dotted' ) as $tracklist_rule ) {
	mw_assert_same(
		true,
		false !== strpos( $vinyl_stylesheet, $tracklist_rule ),
		'The tracklist variation must ship the "' . $tracklist_rule . '" rule.'
	);
}

// Secure download: the labels are visible text, not only an accessible name.
foreach ( array( 'mw-download-button__label', 'mw-secure-play-button__label' ) as $download_label_class ) {
	mw_assert_same(
		true,
		false !== strpos( $release_blocks_source, $download_label_class ),
		'The secure download renderer must print the ' . $download_label_class . ' as visible text.'
	);
	mw_assert_same(
		true,
		false !== strpos( $downloads_css, $download_label_class ),
		'The theme must style the ' . $download_label_class . '.'
	);
}
mw_assert_same(
	true,
	false !== strpos( $release_blocks_source, 'download_sign_in_markup' ),
	'The loginLabel attribute must drive a sign-in prompt instead of being declared and ignored.'
);

$download_runtime = (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/assets/download.js' );
mw_assert_same(
	true,
	false !== strpos( $download_runtime, '.mw-secure-play-button__label' ),
	'The download runtime must keep the visible play/pause label in step with the accessible name.'
);

// Release versions must agree across distributable headers and the manifest.
$mw_manifest = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/release-manifest.json' ), true );
foreach ( array( 'music-wave-core' => 'music-wave-core.php', 'music-wave-vip' => 'music-wave-vip.php', 'musicwave' => 'style.css' ) as $mw_package => $mw_entry ) {
	$mw_header = (string) file_get_contents( dirname( __DIR__ ) . '/' . $mw_package . '/' . $mw_entry );
	$mw_readme = (string) file_get_contents( dirname( __DIR__ ) . '/' . $mw_package . '/readme.txt' );
	preg_match( '/^\s*(?:\*\s*)?Version:\s*([0-9.]+)/m', $mw_header, $mw_version );
	preg_match( '/^Stable tag:\s*([0-9.]+)/m', $mw_readme, $mw_stable );
	mw_assert_same( $mw_manifest['packages'][ $mw_package ]['version'], $mw_version[1] ?? '', $mw_package . ' manifest version must match its runtime header.' );
	mw_assert_same( $mw_version[1] ?? '', $mw_stable[1] ?? '', $mw_package . ' readme stable tag must match its runtime header.' );
}

/*
 * render_block / render_block_data callbacks must be value filters.
 *
 * `apply_filters()` assigns every callback's return value back into
 * `$block_content`. A callback that forgets to return — or declares `: void`
 * to "only do work" — therefore replaces the markup with null. WordPress then
 * calls `trim( null )` (deprecated notice) and, for an interactive block,
 * `wp_interactivity_process_directives( null )` (fatal error). Both only
 * surface on a real render, so this is pinned statically.
 */
$mw_filter_project = dirname( __DIR__ );
$mw_filter_dirs    = array( 'musicwave', 'music-wave-core', 'music-wave-vip' );
$mw_filter_files   = array();
foreach ( $mw_filter_dirs as $mw_filter_dir ) {
	$mw_filter_iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $mw_filter_project . '/' . $mw_filter_dir ) );
	foreach ( $mw_filter_iterator as $mw_filter_file ) {
		if ( $mw_filter_file->isFile() && 'php' === strtolower( $mw_filter_file->getExtension() ) ) {
			$mw_filter_files[] = $mw_filter_file->getPathname();
		}
	}
}

$mw_value_filters = array( 'render_block', 'render_block_data', 'pre_render_block' );
$mw_checked       = 0;
foreach ( $mw_filter_files as $mw_filter_path ) {
	$mw_filter_source = (string) file_get_contents( $mw_filter_path );
	if ( ! preg_match_all( "/add_filter\(\s*'(render_block|render_block_data|pre_render_block)'[^,]*,\s*(?:'([a-z0-9_]+)'|array\(\s*\\\$this\s*,\s*'([a-z0-9_]+)'\s*\))/i", $mw_filter_source, $mw_filter_hits, PREG_SET_ORDER ) ) {
		continue;
	}
	foreach ( $mw_filter_hits as $mw_filter_hit ) {
		++$mw_checked;
		$mw_callback = ! empty( $mw_filter_hit[2] ) ? $mw_filter_hit[2] : $mw_filter_hit[3];
		$mw_label    = basename( $mw_filter_path ) . ' → ' . $mw_callback . '() on ' . $mw_filter_hit[1];

		mw_assert_same(
			true,
			(bool) preg_match( '/function\s+' . preg_quote( $mw_callback, '/' ) . '\s*\(/', $mw_filter_source ),
			$mw_label . ' must be defined in the file that registers it.'
		);

		$mw_start = strpos( $mw_filter_source, 'function ' . $mw_callback );
		$mw_body  = substr( $mw_filter_source, $mw_start );
		$mw_depth = 0;
		$mw_end   = 0;
		for ( $mw_i = strpos( $mw_body, '{' ); $mw_i < strlen( $mw_body ); $mw_i++ ) {
			if ( '{' === $mw_body[ $mw_i ] ) {
				++$mw_depth;
			} elseif ( '}' === $mw_body[ $mw_i ] ) {
				--$mw_depth;
				if ( 0 === $mw_depth ) {
					$mw_end = $mw_i;
					break;
				}
			}
		}
		$mw_function = substr( $mw_body, 0, $mw_end );

		mw_assert_same(
			false,
			(bool) preg_match( '/\)\s*:\s*void\b/', $mw_function ),
			$mw_label . ' must not declare a void return type; a filter callback returning nothing blanks the block.'
		);
		mw_assert_same(
			true,
			(bool) preg_match( '/\breturn\b/', $mw_function ),
			$mw_label . ' must return the (possibly unchanged) block content on every path.'
		);
	}
}
mw_assert_same(
	true,
	$mw_checked > 0,
	'The value-filter audit must find at least one render_block callback, otherwise it stopped matching.'
);
echo 'render_block value-filter audit OK (' . $mw_checked . " callbacks).\n";

/*
 * Every mobile chrome value the Customizer offers must be handled everywhere.
 *
 * The chrome slug is written to <html> and then matched in CSS and read in
 * rail.js. A value that the registry offers but the stylesheet never matches
 * silently falls back to "no bottom bar, no drawer" on a phone — a failure
 * that no PHP-level gate can see, so it is pinned here.
 */
$mw_chrome_choices = array( 'drawer', 'tabs', 'both' );
$mw_chrome_source  = $site_header_source;
foreach ( $mw_chrome_choices as $mw_chrome ) {
	mw_assert_same(
		true,
		(bool) preg_match( "/'" . $mw_chrome . "'\s*=>\s*__\(/", $mw_chrome_source ),
		'The "' . $mw_chrome . '" mobile chrome must be offered in the Customizer registry.'
	);
}

$mw_rail_css = (string) file_get_contents( $theme_directory . '/assets/css/components/rail.css' );
foreach ( $mw_chrome_choices as $mw_chrome ) {
	mw_assert_same(
		true,
		false !== strpos( $mw_rail_css, 'data-mw-mobile-chrome="' . $mw_chrome . '"' ),
		'rail.css must branch on the "' . $mw_chrome . '" mobile chrome.'
	);
}

/*
 * The brand lives in the top bar on phones, never in the bottom bar, and the
 * bottom bar is a full-width, borderless strip stacked on the player dock.
 */
mw_assert_same(
	true,
	false !== strpos( $mw_rail_css, '.mw-rail__tabbar' ),
	'The bottom bar must have its own stylesheet block.'
);
mw_assert_same(
	false,
	(bool) preg_match( '/\.mw-rail__tabbar[^}]*border-radius:\s*var\(--mw-radius-xl\)/s', $mw_rail_css ),
	'The bottom bar must stay flush with the screen edges (no floating card radius).'
);
mw_assert_same(
	true,
	(bool) preg_match( '/\.mw-stream-topbar__brand\s*\{\s*display:\s*flex/', $mw_rail_css ),
	'The top bar must keep the site logo and title on small screens.'
);

/*
 * Vertical order of the two fixed bottom bars. The navigation bar owns the
 * bottom edge and the player docks directly above it, so the bar's inset is
 * always 0 — offsetting it by `--mw-player-offset` (as it used to be) floated
 * the navigation on top of the player the moment a track loaded. Both the
 * inset and the stacking order are pinned here.
 */
$mw_player_css  = (string) file_get_contents( $theme_directory . '/assets/css/components/global-player.css' );
$mw_tabbar_rule = preg_match(
	'/html\[data-mw-mobile-chrome="tabs"\][^{}]*\.mw-rail__tabbar[^{}]*\{(?<rule>[^}]*)\}/s',
	$mw_rail_css,
	$mw_tabbar_match
) ? $mw_tabbar_match['rule'] : '';
$mw_player_rule = preg_match(
	'/\.mw-global-player\s*\{(?<rule>[^}]*)\}/s',
	$mw_player_css,
	$mw_player_match
) ? $mw_player_match['rule'] : '';

mw_assert_same(
	true,
	(bool) preg_match( '/inset-block-end:\s*0\s*;/', $mw_tabbar_rule ),
	'The bottom bar must stay docked to the bottom edge instead of being pushed up.'
);
mw_assert_same(
	false,
	false !== strpos( $mw_tabbar_rule, '--mw-player-offset' ),
	'The bottom bar must not be offset by the player footprint; the player is the element that moves.'
);
mw_assert_same(
	true,
	(bool) preg_match( '/html\[data-mw-mobile-chrome="tabs"\][^{}]*\.mw-global-player[^{}]*\{[^}]*inset-block-end:\s*var\(--mw-rail-tabbar-height\)/s', $mw_rail_css ),
	'The player must dock directly above the bottom bar in the "tabs" and "both" chrome.'
);

$mw_tabbar_z = preg_match( '/z-index:\s*(\d+)/', $mw_tabbar_rule, $mw_z_index ) ? (int) $mw_z_index[1] : -1;
$mw_player_z = preg_match( '/z-index:\s*(\d+)/', $mw_player_rule, $mw_z_index ) ? (int) $mw_z_index[1] : -1;
mw_assert_same(
	true,
	$mw_player_z > $mw_tabbar_z,
	'The player must paint above the bottom bar (z-index ' . $mw_player_z . ' > ' . $mw_tabbar_z . ').'
);

/*
 * The mobile drawer is an overlay. `position: fixed` is declared only inside
 * the `min-width: 64rem` desktop block, so on a phone the rail kept
 * `position: static` and its `block-size: 100dvh` pushed the whole header one
 * viewport down — the drawer ended up rendered *above* the header.
 */
foreach ( array( 'drawer', 'both' ) as $mw_drawer_chrome ) {
	mw_assert_same(
		true,
		// The lookahead keeps `.mw-rail__backdrop` from passing as `.mw-rail`.
		(bool) preg_match( '/html\[data-mw-mobile-chrome="' . $mw_drawer_chrome . '"\][^{}]*\.mw-rail(?![\w-])[^{}]*\{[^}]*position:\s*fixed/s', $mw_rail_css ),
		'The "' . $mw_drawer_chrome . '" chrome drawer must be position: fixed so it never occupies page flow.'
	);
}

/*
 * WordPress repeats the navigation block className on the inner
 * `wp-block-navigation__container`. Both elements match `.mw-rail__tabbar`, so
 * without an explicit reset the list becomes a second fixed bar offset from
 * the wrapper and the tabs render above the player.
 */
mw_assert_same(
	true,
	(bool) preg_match( '/\.mw-rail__tabbar\s+\.mw-rail__tabbar[^{}]*\{[^}]*position:\s*static/s', $mw_rail_css ),
	'A nested .mw-rail__tabbar (duplicated block className) must be reset out of fixed positioning.'
);

echo 'Mobile chrome contract OK (' . count( $mw_chrome_choices ) . " modes).\n";
