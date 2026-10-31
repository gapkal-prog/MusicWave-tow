<?php
/**
 * End-to-end architectural, CSS cascade & Inspector contract regression suite.
 *
 * Registers every real block across `music-wave-core`, `music-wave-vip`, and
 * `musicwave` through `register_block_type()` + `do_action( 'init' )`, executes
 * their real `render_callback` closures, and verifies every CSS cascade rule,
 * consolidated block schema, and Inspector contract end-to-end.
 *
 * @package MusicWave\Tests
 */

declare(strict_types=1);

namespace {
	use ManaCore\MusicWave\Core\Blocks\Concerns\ActionButtonStyle;

	if ( ! defined( 'EP_PAGES' ) ) {
		define( 'EP_PAGES', 4096 );
	}
	if ( ! defined( 'EP_ROOT' ) ) {
		define( 'EP_ROOT', 65536 );
	}
	foreach (
		array(
			'load_plugin_textdomain',
			'load_theme_textdomain',
			'register_post_type',
			'register_taxonomy',
			'register_post_meta',
			'register_term_meta',
			'register_meta',
			'add_rewrite_rule',
			'add_rewrite_tag',
			'add_rewrite_endpoint',
			'add_permastruct',
			'wp_register_script',
			'wp_register_style',
			'wp_set_script_translations',
			'register_block_style',
			'register_block_pattern',
			'register_block_pattern_category',
			'term_exists',
			'wp_insert_term',
			'wp_next_scheduled',
			'wp_schedule_event',
			'add_feed',
		) as $mw_wp_stub_fn
	) {
		if ( ! function_exists( $mw_wp_stub_fn ) ) {
			eval( 'function ' . $mw_wp_stub_fn . '( ...$args ) { unset( $args ); return true; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test harness stub.
		}
	}
	if ( ! function_exists( 'get_avatar' ) ) {
		function get_avatar( $id_or_email, int $size = 56 ): string {
			unset( $id_or_email );
			return '<span class="mw-user-dashboard__avatar" data-size="' . (int) $size . '"></span>';
		}
	}
	if ( ! function_exists( 'plugin_basename' ) ) {
		function plugin_basename( string $file ): string {
			return basename( dirname( $file ) ) . '/' . basename( $file );
		}
	}

	if ( ! defined( 'MUSIC_WAVE_CORE_FILE' ) ) {
		require_once dirname( __DIR__ ) . '/music-wave-core/music-wave-core.php';
	}
	\ManaCore\MusicWave\Core\Plugin::instance()->boot();
	foreach ( $GLOBALS['mw_test_action_callbacks']['init'] ?? array() as $mw_init_cb ) {
		if ( is_callable( $mw_init_cb ) ) {
			call_user_func( $mw_init_cb );
		}
	}

$mw_root = dirname( __DIR__ );

/**
 * Invoke a real registered dynamic block callback identically to WordPress core.
 *
 * @param string               $block_name Block slug.
 * @param array<string, mixed> $attributes Block attributes.
 */
$mw_render_registered = static function ( string $block_name, array $attributes = array() ): string {
	mw_assert_same( true, isset( $GLOBALS['mw_test_registered_blocks'][ $block_name ]['render_callback'] ), 'Registered dynamic block must expose a callable render_callback: ' . $block_name );
	$entry    = $GLOBALS['mw_test_registered_blocks'][ $block_name ];
	$defaults = array();
	if ( isset( $entry['attributes'] ) && is_array( $entry['attributes'] ) ) {
		foreach ( $entry['attributes'] as $attr_key => $attr_schema ) {
			if ( is_array( $attr_schema ) && array_key_exists( 'default', $attr_schema ) ) {
				$defaults[ $attr_key ] = $attr_schema['default'];
			}
		}
	}
	$merged   = array_merge( $defaults, $attributes );
	$callback = $entry['render_callback'];
	return (string) call_user_func( $callback, $merged, '', null );
};

// 1. CSS Cascade & Orphan Audit Contracts across stylesheets.
$mw_slider_css = (string) file_get_contents( $mw_root . '/musicwave/assets/css/components/slider.css' );
$mw_base_art   = strpos( $mw_slider_css, '.mw-release-slider__art {' );
$mw_min_art    = strpos( $mw_slider_css, '.mw-release-slider.is-style-minimal .mw-release-slider__art' );
$mw_shape_art  = strpos( $mw_slider_css, '.mw-release-slider.mw-release-slider--shape-circle .mw-release-slider__art' );
mw_assert_same(
	true,
	false !== $mw_base_art && false !== $mw_min_art && false !== $mw_shape_art && $mw_shape_art > $mw_base_art && $mw_shape_art > $mw_min_art,
	'slider.css must declare authoritative .mw-release-slider--shape-* modifiers AFTER base and style-variation rules so imageShape always wins cascade.'
);

$mw_hero_slider_css = (string) file_get_contents( $mw_root . '/musicwave/assets/css/components/hero-slider.css' );
foreach ( array( 'circle', 'square', 'landscape', 'portrait' ) as $mw_shape ) {
	mw_assert_same( true, false !== strpos( $mw_hero_slider_css, '.mw-release-shelf--slider.mw-release-shelf--shape-' . $mw_shape ), 'hero-slider.css must style imageShape=' . $mw_shape . ' on hero slider slides.' );
}

$mw_artist_css = (string) file_get_contents( $mw_root . '/musicwave/assets/css/components/artist.css' );
mw_assert_same( false, strpos( $mw_artist_css, 'mw-artist-profile--slider' ), 'artist.css must not retain dead .mw-artist-profile--slider rules.' );

/* The card *style* (`editorial|minimal|glass`) is the skin axis and the layouts are
 * the geometry axis. Both write `background`/`border`/`border-radius`/`box-shadow`
 * at equal specificity, so the skins must be declared AFTER every layout rule: a
 * layout that brings its own surface (`--compact` pill, `--spotlight` banner) would
 * otherwise win the cascade and the picked style would look inert in that layout. */
$mw_layout_positions = array();
foreach ( array( 'compact', 'list', 'card', 'spotlight' ) as $mw_layout_slug ) {
	$mw_layout_positions[ $mw_layout_slug ] = strpos( $mw_artist_css, '.mw-artist-profile--' . $mw_layout_slug . ' {' );
}
mw_assert_same( false, in_array( false, $mw_layout_positions, true ), 'artist.css must keep an explicit rule for every profile layout slug (compact|list|card|spotlight).' );
$mw_skin_positions = array(
	'minimal' => strpos( $mw_artist_css, '.mw-artist-profile--minimal,' ),
	'glass'   => strpos( $mw_artist_css, '.mw-artist-profile--glass,' ),
);
mw_assert_same( false, in_array( false, $mw_skin_positions, true ), 'artist.css must style both non-default card styles (minimal, glass) through the --<style> class.' );
mw_assert_same(
	true,
	min( $mw_skin_positions ) > max( $mw_layout_positions ),
	'artist.css must declare the minimal|glass card styles AFTER all profile layout rules so the author style always wins the cascade.'
);

$mw_editorial_css = (string) file_get_contents( $mw_root . '/musicwave/assets/css/components/editorial.css' );
mw_assert_same( true, false !== strpos( $mw_editorial_css, '.mw-artist-profile--spotlight:not(.mw-artist-profile--minimal):not(.mw-artist-profile--glass)' ), 'editorial.css must stand down its spotlight tint while the minimal|glass card style is active.' );

$mw_release_page_css = (string) file_get_contents( $mw_root . '/musicwave/assets/css/components/release-page.css' );
mw_assert_same( false, strpos( $mw_release_page_css, 'mw-artist-profile__slider' ), 'release-page.css must not retain dead .mw-artist-profile__slider selector.' );
mw_assert_same( false, strpos( $mw_release_page_css, 'mw-release-meta__chips' ), 'release-page.css must not retain dead .mw-release-meta__chips selector.' );
mw_assert_same( true, false !== strpos( $mw_release_page_css, '"position title artist duration actions"' ), 'release-page.css must realign collection-list grid tracks when hiding quality column on single release stage.' );
mw_assert_same( true, false !== strpos( $mw_release_page_css, ':not(.mw-artist-profile--minimal):not(.mw-artist-profile--glass)' ), 'release-page.css hero artist strip must stand down its chip surface while the minimal|glass card style is active.' );

$mw_artists_shelf_css = (string) file_get_contents( $mw_root . '/musicwave/assets/css/components/artists-shelf.css' );
mw_assert_same( true, false !== strpos( $mw_artists_shelf_css, '.mw-artists-shelf.is-style-editorial .mw-artists-shelf__item' ), 'artists-shelf.css must style framed editorial cards around .mw-artists-shelf__item.' );
mw_assert_same( true, false !== strpos( $mw_artists_shelf_css, '.mw-artists-shelf.is-style-glass .mw-artists-shelf__item' ), 'artists-shelf.css must style glass cards around .mw-artists-shelf__item without overriding avatar shape.' );

// 2. Unified ActionButtonStyle & all 4 Action Buttons (`add-to-playlist`, `share-button`, `shuffle-button`, `library-button`).
mw_assert_same( 'icon', ActionButtonStyle::resolve( array( 'style' => 'icon' ) ), 'ActionButtonStyle must resolve explicit style=icon.' );
mw_assert_same( 'button', ActionButtonStyle::resolve( array( 'style' => 'button' ) ), 'ActionButtonStyle must resolve explicit style=button.' );
// Verify ActionButtonStyle handles BOTH new buttonStyle (even alongside WP native array `style`) AND legacy string `style`.
mw_assert_same( 'button', ActionButtonStyle::resolve( array( 'buttonStyle' => 'button', 'style' => array( 'spacing' => array( 'padding' => '8px' ) ) ) ), 'ActionButtonStyle must resolve buttonStyle=button cleanly alongside WP native array style object!' );
mw_assert_same( 'icon', ActionButtonStyle::resolve( array( 'buttonStyle' => 'icon', 'style' => array( 'color' => array( 'background' => '#111' ) ) ) ), 'ActionButtonStyle must resolve buttonStyle=icon cleanly alongside WP native array style object!' );
mw_assert_same( 'icon', ActionButtonStyle::resolve( array( 'className' => 'is-style-icon' ) ), 'ActionButtonStyle must honor is-style-icon className.' );

$mw_prev_rest_flag = isset( $_GET['context'] ) ? $_GET['context'] : null;
$_GET['context']   = 'edit';

$mw_share_icon = $mw_render_registered( 'music-wave/share-button', array( 'style' => 'icon', 'label' => 'اشتراک ویژه' ) );
$mw_share_full = $mw_render_registered( 'music-wave/share-button', array( 'style' => 'button', 'label' => 'اشتراک ویژه' ) );
mw_assert_same( true, false !== strpos( $mw_share_icon, 'mw-action-button--icon' ), 'share-button icon mode must emit mw-action-button--icon.' );
mw_assert_same( true, false !== strpos( $mw_share_full, 'mw-action-button--button' ) && false !== strpos( $mw_share_full, 'اشتراک ویژه' ), 'share-button full mode must emit mw-action-button--button and visible label.' );

$mw_shuffle_icon = $mw_render_registered( 'music-wave/shuffle-button', array( 'style' => 'icon', 'label' => 'پخش تصادفی آلبوم' ) );
$mw_shuffle_full = $mw_render_registered( 'music-wave/shuffle-button', array( 'style' => 'button', 'label' => 'پخش تصادفی آلبوم' ) );
mw_assert_same( true, false !== strpos( $mw_shuffle_icon, 'mw-action-button--icon' ), 'shuffle-button icon mode must emit unified mw-action-button--icon.' );
mw_assert_same( true, false !== strpos( $mw_shuffle_full, 'mw-action-button--button' ) && false !== strpos( $mw_shuffle_full, 'پخش تصادفی آلبوم' ), 'shuffle-button full mode must emit unified mw-action-button--button and label.' );

$mw_playlist_icon = $mw_render_registered( 'music-wave/add-to-playlist', array( 'style' => 'icon', 'label' => 'افزودن سریع' ) );
$mw_playlist_full = $mw_render_registered( 'music-wave/add-to-playlist', array( 'style' => 'button', 'label' => 'افزودن سریع' ) );
mw_assert_same( true, false !== strpos( $mw_playlist_icon, 'mw-action-button--icon' ), 'add-to-playlist icon mode must emit unified mw-action-button--icon.' );
mw_assert_same( true, false !== strpos( $mw_playlist_full, 'mw-action-button--button' ) && false !== strpos( $mw_playlist_full, 'افزودن سریع' ), 'add-to-playlist full mode must emit unified mw-action-button--button and label.' );

$mw_lib_heart  = $mw_render_registered( 'music-wave/library-button', array( 'style' => 'heart', 'itemType' => 'release' ) );
$mw_lib_artist = $mw_render_registered( 'music-wave/library-button', array( 'style' => 'button', 'itemType' => 'artist' ) );
mw_assert_same( true, false !== strpos( $mw_lib_heart, 'mw-library-button--heart' ), 'library-button heart mode must emit mw-library-button--heart.' );
mw_assert_same( true, false !== strpos( $mw_lib_artist, 'mw-library-button--button' ) && false !== strpos( $mw_lib_artist, 'دنبال‌کردن' ), 'library-button with itemType=artist must accurately preview artist follow state in Site Editor.' );

// 3. `release-meta` Default Compact Dot-Separated Inline Mode vs Structured Panel Mode & Consolidated Variations.
$mw_compact_meta  = $mw_render_registered( 'music-wave/release-meta', array() );
$mw_expanded_meta = $mw_render_registered( 'music-wave/release-meta', array( 'compact' => false, 'layout' => 'inline' ) );
mw_assert_same( true, false !== strpos( $mw_compact_meta, '<dl ' ) && false !== strpos( $mw_compact_meta, 'mw-release-meta--compact' ), 'release-meta default render must output borderless compact inline <dl>.' );
mw_assert_same( true, false !== strpos( $mw_expanded_meta, 'mw-release-meta--panel' ) && false !== strpos( $mw_expanded_meta, 'mw-release-meta__facts--inline' ), 'release-meta with compact=false must output structured panel layout.' );

$mw_meta_block_json = json_decode( (string) file_get_contents( $mw_root . '/music-wave-core/blocks/release-meta/block.json' ), true );
mw_assert_same( true, is_array( $mw_meta_block_json ) && ! isset( $mw_meta_block_json['variations'] ), 'release-meta block.json must consolidate single-fact variations into the unified block.' );
foreach ( array( 'showActions', 'showTaxonomyChips', 'showLibraryButton', 'linkTerms' ) as $mw_removed_attr ) {
	mw_assert_same( false, isset( $mw_meta_block_json['attributes'][ $mw_removed_attr ] ), 'release-meta block.json must not expose bloated attribute: ' . $mw_removed_attr );
}

// 4. `collection-list` Track Actions Alignment & Toggle Contract.
$mw_col_with_act    = $mw_render_registered( 'music-wave/collection-list', array( 'showPreview' => true, 'showDownload' => true ) );
$mw_col_without_act = $mw_render_registered( 'music-wave/collection-list', array( 'showPreview' => false, 'showDownload' => false ) );
mw_assert_same( true, false !== strpos( $mw_col_with_act, 'mw-collection-list__actions' ) && false === strpos( $mw_col_with_act, 'mw-collection-list--no-actions' ), 'collection-list with track actions enabled must render actions column and header.' );
mw_assert_same( true, false !== strpos( $mw_col_without_act, 'mw-collection-list--no-actions' ) && false === strpos( $mw_col_without_act, 'mw-collection-list__actions' ), 'collection-list with both preview and download disabled must add mw-collection-list--no-actions and omit action cells.' );

// 5. `artist-profile` Clean Layouts (`card`, `list`, `spotlight`) + Legacy `slider` Upgrade.
$mw_artist_migrated = $mw_render_registered( 'music-wave/artist-profile', array( 'layout' => 'slider', 'imageShape' => 'circle' ) );
mw_assert_same( true, false !== strpos( $mw_artist_migrated, 'mw-artist-profile--spotlight' ) && false === strpos( $mw_artist_migrated, 'mw-artist-profile--slider' ), 'artist-profile must seamlessly upgrade legacy slider layout to spotlight with zero --slider class output.' );

	// 6. Consolidated `dashboard-account` (`dashboard-edit` duplicate dir removed + hidden alias preserved) + Dynamic `account-dashboard` Ordering.
	mw_assert_same( false, is_dir( $mw_root . '/music-wave-core/blocks/dashboard-edit' ), 'Duplicate blocks/dashboard-edit directory must be removed cleanly.' );
	mw_assert_same( false, $GLOBALS['mw_test_registered_blocks']['music-wave/dashboard-edit']['supports']['inserter'] ?? true, 'Legacy music-wave/dashboard-edit alias must be hidden from the block inserter while remaining backward-compatible.' );
	mw_assert_same(
		$mw_render_registered( 'music-wave/dashboard-account', array( 'heading' => 'جزئیات من' ) ),
		$mw_render_registered( 'music-wave/dashboard-edit', array( 'heading' => 'جزئیات من' ) ),
		'music-wave/dashboard-account and legacy music-wave/dashboard-edit alias must render identically through one unified implementation.'
	);

$mw_dashboard_custom = $mw_render_registered(
	'music-wave/account-dashboard',
	array(
		'panelOrder'         => 'requests,membership,account,library',
		'showOrders'         => false,
		'showDownloads'      => false,
		'showAddresses'      => false,
		'showPaymentMethods' => false,
		'showPlaylists'      => true,
	)
);
	$mw_pos_req = strpos( $mw_dashboard_custom, 'data-mw-dashboard-tab="requests"' );
	$mw_pos_mem = strpos( $mw_dashboard_custom, 'data-mw-dashboard-tab="membership"' );
	$mw_pos_acc = strpos( $mw_dashboard_custom, 'data-mw-dashboard-tab="account"' );
	$mw_pos_lib = strpos( $mw_dashboard_custom, 'data-mw-dashboard-tab="library"' );
	mw_assert_same(
		true,
		false !== $mw_pos_req && false !== $mw_pos_mem && false !== $mw_pos_acc && false !== $mw_pos_lib
			&& $mw_pos_req < $mw_pos_mem && $mw_pos_mem < $mw_pos_acc && $mw_pos_acc < $mw_pos_lib
			&& false === strpos( $mw_dashboard_custom, 'data-mw-dashboard-tab="orders"' ),
		'account-dashboard must dynamically honor custom comma-separated panelOrder AND hide disabled sections in exact sequence.'
	);

// 7. `release-shelf` Bento & Hero Slider Shapes + JS Inspector Contracts.
$mw_bento_shelf = $mw_shelf_renderer->render_release_shelf( array( 'layout' => 'bento', 'styleVariant' => 'minimal', 'itemsToShow' => 4 ) );
$mw_hero_circle = $mw_shelf_renderer->render_release_shelf( array( 'layout' => 'slider', 'imageShape' => 'circle', 'itemsToShow' => 4 ) );
mw_assert_same( true, false !== strpos( $mw_bento_shelf, 'mw-release-shelf--bento' ) && false !== strpos( $mw_bento_shelf, 'mw-release-shelf--minimal' ), 'release-shelf must combine layout=bento with styleVariant=minimal cleanly.' );
mw_assert_same( true, false !== strpos( $mw_hero_circle, 'mw-hero-slide__art--circle' ), 'release-shelf hero slider must emit mw-hero-slide__art--circle when imageShape=circle.' );

	$mw_blocks_js = (string) file_get_contents( $mw_root . '/music-wave-core/assets/blocks.js' );
	$mw_theme_ed  = (string) file_get_contents( $mw_root . '/musicwave/assets/editor-blocks.js' );
$mw_req_json  = json_decode( (string) file_get_contents( $mw_root . '/music-wave-core/blocks/request-form/block.json' ), true );
mw_assert_same( false, strpos( $mw_blocks_js, 'دیده نمیشه' ), 'blocks.js must never contain broken colloquial UI notice copy.' );
mw_assert_same( false, isset( $mw_req_json['attributes']['showTypeChips'] ), 'request-form block.json must not contain redundant showTypeChips attribute.' );
mw_assert_same( true, false !== strpos( $mw_blocks_js, 'DASHBOARD_PANEL_META' ) && false !== strpos( $mw_blocks_js, 'movePanel(' ), 'blocks.js must provide interactive up/down panel ordering controls synced with AccountLibrary panel keys.' );
mw_assert_same( true, false !== strpos( $mw_theme_ed, "value: 'bento'" ), 'musicwave editor.js must expose bento cleanly inside layout options without conflicting style variations.' );

// 8. Custom Icons (`ActionButtonStyle::render_icon`), Smart Collection Playback (`preview-player`), Relational `collection-list`, and Zero-Empty Editor Previews.
$mw_css_icon_html = ActionButtonStyle::render_icon( array( 'icon' => 'dashicons dashicons-controls-play' ), 'play' );
mw_assert_same( true, false !== strpos( $mw_css_icon_html, 'dashicons-controls-play' ), 'ActionButtonStyle::render_icon must support safe CSS icon classes.' );
$mw_svg_icon_html = ActionButtonStyle::render_icon( array( 'icon' => '<svg viewBox="0 0 24 24" onload="alert(1)"><script>alert(1)</script><path d="M8 5v14l11-7z"/></svg>' ), 'play' );
mw_assert_same( true, false !== strpos( $mw_svg_icon_html, '<path d="M8 5v14l11-7z"' ) && false === strpos( $mw_svg_icon_html, 'onload' ) && false === strpos( $mw_svg_icon_html, '<script' ), 'ActionButtonStyle::render_icon must sanitize inline SVG and strip scripts/event handlers.' );

foreach ( array(
	'music-wave/preview-player',
	'music-wave/preview-button',
	'music-wave/download-button',
	'music-wave/collection-list',
	'music-wave/library-button',
	'music-wave/add-to-playlist',
	'music-wave/add-to-queue',
	'music-wave/share-button',
	'music-wave/shuffle-button',
	'music-wave/release-meta',
	'music-wave/release-credits',
	'music-wave/related-releases',
	'music-wave/artist-profile',
	'music-wave/term-hero',
	'music-wave/search-unified',
	'music-wave/request-form',
) as $mw_live_block_slug ) {
	$mw_live_html = $mw_render_registered( $mw_live_block_slug, array() );
	mw_assert_same(
		true,
		'' !== trim( $mw_live_html ),
		'Dynamic block ' . $mw_live_block_slug . ' must render real non-empty preview HTML in Editor context without empty placeholders.'
	);
}
mw_assert_same(
	true,
	'' !== trim( ( new \ManaCore\MusicWave\Theme\Blocks\ChromeBlocksRenderer() )->render_synced_lyrics( array() ) ),
	'Theme block music-wave/synced-lyrics must render real non-empty preview HTML in Editor context.'
);

if ( null === $mw_prev_rest_flag ) {
	unset( $_GET['context'] );
} else {
	$_GET['context'] = $mw_prev_rest_flag;
}

// -------------------------------------------------------------------------
// 6. Relational Multi-Disc Collection, VIP Tracklist Visibility & Smart Mode
// -------------------------------------------------------------------------
$mw_rel_album_id = wp_insert_post(
	array(
		'post_type'   => 'mw_release',
		'post_status' => 'publish',
		'post_title'  => 'آلبوم چنددیسکی آزمایشی',
	)
);
$mw_rel_track_d1 = wp_insert_post(
	array(
		'post_type'   => 'mw_release',
		'post_status' => 'publish',
		'post_parent' => 0,
		'post_title'  => 'قطعه اول دیسک یک',
	)
);
$mw_rel_track_d2 = wp_insert_post(
	array(
		'post_type'   => 'mw_release',
		'post_status' => 'publish',
		'post_parent' => 0,
		'post_title'  => 'قطعه اول دیسک دو',
	)
);
$GLOBALS['mw_test_release_types'][ $mw_rel_album_id ] = array( 'album' );
$GLOBALS['mw_test_release_types'][ $mw_rel_track_d1 ] = array( 'single' );
$GLOBALS['mw_test_release_types'][ $mw_rel_track_d2 ] = array( 'single' );
$relation_repository->update( $mw_rel_album_id, 'mw_access_mode', 'membership' );
$relation_repository->update( $mw_rel_track_d1, 'mw_access_mode', 'membership' );
$relation_repository->update( $mw_rel_track_d1, 'mw_preview_url', 'https://cdn.example.test/previews/d1.mp3' );
$relation_repository->update( $mw_rel_track_d1, 'mw_duration', 205 );
$relation_repository->update( $mw_rel_track_d2, 'mw_access_mode', 'membership' );
$relation_repository->update( $mw_rel_track_d2, 'mw_preview_url', 'https://cdn.example.test/previews/d2.mp3' );
$relation_repository->update( $mw_rel_track_d2, 'mw_duration', 198 );
$relation_repository->replace_collection_items(
	$mw_rel_album_id,
	array(
		array(
			'release_id' => $mw_rel_track_d1,
			'position'   => 1,
			'disc'       => 1,
			'role'       => 'track',
		),
		array(
			'release_id' => $mw_rel_track_d2,
			'position'   => 2,
			'disc'       => 2,
			'role'       => 'track',
		),
	)
);

$mw_vip_album_ui = $mw_render_registered(
	'music-wave/collection-list',
	array(
		'releaseId'   => $mw_rel_album_id,
		'groupByDisc' => true,
	)
);
mw_assert_same(
	true,
	false !== strpos( $mw_vip_album_ui, 'قطعه اول دیسک یک' )
		&& false !== strpos( $mw_vip_album_ui, 'قطعه اول دیسک دو' )
		&& substr_count( $mw_vip_album_ui, 'mw-collection-list__disc"' ) === 2
		&& false === strpos( $mw_vip_album_ui, 'data-download-key=' ),
	'VIP album with relational collection_items (post_parent=0) must display multi-disc tracks to visitors while keeping protected downloads unexposed.'
);

$mw_smart_album_play = $mw_render_registered(
	'music-wave/preview-player',
	array( 'releaseId' => $mw_rel_album_id )
);
mw_assert_same(
	true,
	false !== strpos( $mw_smart_album_play, 'data-mw-play-mode="all"' ),
	'Preview player must smartly auto-detect data-mw-play-mode="all" for collections with playable tracks.'
);

unset(
	$GLOBALS['mw_test_posts'][ $mw_rel_album_id ],
	$GLOBALS['mw_test_posts'][ $mw_rel_track_d1 ],
	$GLOBALS['mw_test_posts'][ $mw_rel_track_d2 ],
	$GLOBALS['mw_test_meta'][ $mw_rel_album_id ],
	$GLOBALS['mw_test_meta'][ $mw_rel_track_d1 ],
	$GLOBALS['mw_test_meta'][ $mw_rel_track_d2 ],
	$GLOBALS['mw_test_release_types'][ $mw_rel_album_id ],
	$GLOBALS['mw_test_release_types'][ $mw_rel_track_d1 ],
	$GLOBALS['mw_test_release_types'][ $mw_rel_track_d2 ]
);

// Verify the removed patterns are gone for good and the redesigned ones still ship.
foreach ( array(
	'artist-release-shelf.php',
	'editorial-feature.php',
	'featured-release-slider.php',
	'browse-categories.php',
	'horizontal-release-shelf.php',
	'curated-release-shelf.php',
	'new-releases-shelf.php',
	'release-chart.php',
	'popular-artists.php',
	'vinyl-record-shelf.php',
	'section-heading.php',
	'request-cta.php',
	'widget-rich-sidebar.php',
	'sidebar-content.php',
	'breadcrumb-bar.php',
	'catalog-heading.php',
	'cta-banner.php',
	'hero-centered.php',
	'footer-widget-columns.php',
	'widget-search.php',
	'newsletter-signup.php',
	'account-hub.php',
	'album-spec-sheet.php',
	'music-home-layout.php',
	'editorial-highlights.php',
	'editorial-intro.php',
	'editorial-notes.php',
	'widget-panel.php',
	'demo-account-studio.php',
	'demo-account-commerce.php',
	'demo-release-showcase.php',
	'demo-artists-directory.php',
	'demo-discovery-browse.php',
	'demo-community-requests.php',
) as $mw_deleted_pattern ) {
	mw_assert_same(
		false,
		file_exists( dirname( __DIR__ ) . '/musicwave/patterns/' . $mw_deleted_pattern ),
		'Deleted pattern must not exist on disk: ' . $mw_deleted_pattern
	);
}

$mw_release_card_pattern = (string) file_get_contents( dirname( __DIR__ ) . '/musicwave/patterns/release-card.php' );
mw_assert_same(
	true,
	false !== strpos( $mw_release_card_pattern, 'mw-hero-slide__play' )
		&& false !== strpos( $mw_release_card_pattern, 'post-featured-image' ),
	'Redesigned release-card.php pattern must render featured cover image with .mw-hero-slide__play play overlay.'
);

// 10. Five-Block Site Editor & Streaming Layout Regression Contracts.
// 10a. Showcase & Slider SSR attribute pass-through: `layout` must never be stripped.
mw_assert_same(
	false,
	1 === preg_match( '/WP_RESERVED_SUPPORT_ATTRS\s*=\s*\{[^}]*\blayout\s*:\s*true/s', $mw_blocks_js ),
	'Core blocks.js WP_RESERVED_SUPPORT_ATTRS must never strip scalar block attribute layout.'
);
mw_assert_same(
	false,
	1 === preg_match( '/reservedSupportKeys\s*=\s*\{[^}]*\blayout\s*:\s*true/s', $mw_theme_ed ),
	'Theme editor-blocks.js reservedSupportKeys must never strip scalar block attribute layout.'
);
mw_assert_same(
	true,
	false !== strpos( $mw_shelf_renderer->render_release_slider( array( 'styleVariant' => 'cinema' ) ), 'mw-release-slider--cinema' )
		&& false !== strpos( $mw_shelf_renderer->render_release_slider( array( 'styleVariant' => 'minimal' ) ), 'mw-release-slider--minimal' ),
	'release-slider must honor styleVariant across cinema and minimal modes.'
);

// 10b. `download-button` synchronized Play + Download + Quality controls & stacked compact multi-file rows.
$_GET['context'] = 'edit';
$mw_dl_parent_id = 91801;
$mw_dl_track_a   = 91802;
$mw_dl_track_b   = 91803;
foreach ( array(
	$mw_dl_parent_id => 'مجموعه آزمایشی دانلود',
	$mw_dl_track_a   => 'قطعه اول آزمایشی',
	$mw_dl_track_b   => 'قطعه دوم آزمایشی',
) as $mw_tmp_id => $mw_tmp_title ) {
	$GLOBALS['mw_test_posts'][ $mw_tmp_id ]    = (object) array(
		'ID'          => $mw_tmp_id,
		'post_type'   => 'mw_release',
		'post_status' => 'publish',
		'post_title'  => $mw_tmp_title,
		'post_parent' => $mw_tmp_id === $mw_dl_parent_id ? 0 : $mw_dl_parent_id,
		'menu_order'  => $mw_tmp_id - $mw_dl_parent_id,
	);
	$GLOBALS['mw_test_titles'][ $mw_tmp_id ]   = $mw_tmp_title;
	$GLOBALS['mw_test_types'][ $mw_tmp_id ]    = 'mw_release';
	$GLOBALS['mw_test_statuses'][ $mw_tmp_id ] = 'publish';
}
$GLOBALS['mw_test_meta'][ $mw_dl_track_a ] = array( 'mw_track_number' => 2 );
$GLOBALS['mw_test_meta'][ $mw_dl_track_b ] = array( 'mw_track_number' => 5 );
$GLOBALS['mw_test_meta'][ $mw_dl_parent_id ] = array(
	'mw_collection_items' => array(
		array( 'release_id' => $mw_dl_track_a, 'position' => 2, 'disc' => 1 ),
		array( 'release_id' => $mw_dl_track_b, 'position' => 5, 'disc' => 1 ),
	),
);

$mw_dl_synced = $mw_render_registered(
	'music-wave/download-button',
	array(
		'releaseId'   => $mw_dl_parent_id,
		'buttonStyle' => 'icon',
		'variant'     => 'outline',
		'size'        => 'lg',
		'shape'       => 'rounded',
	)
);
mw_assert_same(
	true,
	false !== strpos( $mw_dl_synced, 'mw-secure-play-button mw-action-button mw-action-button--icon mw-action-button--outline mw-action-button--lg mw-action-button--rounded' )
		&& false !== strpos( $mw_dl_synced, 'mw-download-button mw-action-button mw-action-button--icon mw-action-button--outline mw-action-button--lg mw-action-button--rounded' )
		&& false !== strpos( $mw_dl_synced, 'mw-download-quality--size-lg mw-download-quality--shape-rounded mw-download-quality--variant-outline' ),
	'download-button must synchronize buttonStyle, variant, size, and shape across Download button, Play button, and Quality selector.'
);

$mw_dl_compact_multi = $mw_render_registered(
	'music-wave/download-button',
	array(
		'releaseId'   => $mw_dl_parent_id,
		'compact'     => true,
		'showQuality' => false,
		'showStream'  => false,
	)
);
mw_assert_same( 2, substr_count( $mw_dl_compact_multi, 'mw-download-inline--stacked' ), 'Compact download-button on a multi-file release must render every stacked file row.' );

$mw_downloads_css = (string) file_get_contents( $mw_root . '/musicwave/assets/css/components/downloads.css' );
$mw_editor_css    = (string) file_get_contents( $mw_root . '/musicwave/assets/css/editor.css' );
mw_assert_same(
	true,
	1 === preg_match( '/\.mw-download-inline-list\s*\{\s*display:\s*grid\b/s', $mw_downloads_css )
		&& false === strpos( $mw_editor_css, '.mw-download-inline-list' ),
	'Compact multi-file list (.mw-download-inline-list) must use vertical CSS Grid without inline-flex overrides.'
);

// 10c. `collection-list` 1-based sequential track numbering & CSS subgrid alignment shell.
$mw_col_numbered = $mw_render_registered( 'music-wave/collection-list', array( 'releaseId' => $mw_dl_parent_id ) );
mw_assert_same(
	true,
	false !== strpos( $mw_col_numbered, '<span class="mw-collection-list__position">1</span>' )
		&& false !== strpos( $mw_col_numbered, '<span class="mw-collection-list__position">2</span>' )
		&& false === strpos( $mw_col_numbered, '<span class="mw-collection-list__position">5</span>' )
		&& false !== strpos( $mw_col_numbered, 'mw-collection-list__grid-shell' ),
	'collection-list must number tracks sequentially starting at 1 even when standalone child mw_track_number starts at 2.'
);

// 10d. Empty `synced-lyrics` and `release-credits` editor states render concise diagnostics with zero fake body rows.
$mw_empty_lyrics  = ( new \ManaCore\MusicWave\Theme\Blocks\ChromeBlocksRenderer() )->render_synced_lyrics( array( 'releaseId' => $mw_dl_track_a ) );
$mw_empty_credits = $mw_render_registered( 'music-wave/release-credits', array( 'releaseId' => $mw_dl_track_a ) );
mw_assert_same(
	true,
	false !== strpos( $mw_empty_lyrics, 'mw-editor-diagnostic' )
		&& false === strpos( $mw_empty_lyrics, 'mw-lyrics__line' )
		&& false !== strpos( $mw_empty_credits, 'mw-editor-diagnostic' )
		&& false === strpos( $mw_empty_credits, 'mw-release-credits__item' ),
	'Empty synced-lyrics and release-credits blocks in Editor context must render diagnostic notices without fake sample lines or fake names.'
);

// 10e. `artist-profile` schema parity (`compact`) & distinct layout rendering.
$mw_artist_reg_attrs = $GLOBALS['mw_test_registered_blocks']['music-wave/artist-profile']['attributes'] ?? array();
mw_assert_same( true, isset( $mw_artist_reg_attrs['compact'] ), 'Registered music-wave/artist-profile block must expose compact attribute from block.json.' );
mw_assert_same(
	true,
	false !== strpos( $mw_render_registered( 'music-wave/artist-profile', array( 'layout' => 'compact' ) ), 'mw-artist-profile--compact' )
		&& false !== strpos( $mw_render_registered( 'music-wave/artist-profile', array( 'layout' => 'list' ) ), 'mw-artist-profile--list' )
		&& false !== strpos( $mw_render_registered( 'music-wave/artist-profile', array( 'layout' => 'card' ) ), 'mw-artist-profile--card' )
		&& false !== strpos( $mw_render_registered( 'music-wave/artist-profile', array( 'layout' => 'spotlight' ) ), 'mw-artist-profile--spotlight' ),
	'artist-profile must render all 4 layouts (compact, list, card, spotlight) cleanly.'
);

unset(
	$GLOBALS['mw_test_posts'][ $mw_dl_parent_id ],
	$GLOBALS['mw_test_posts'][ $mw_dl_track_a ],
	$GLOBALS['mw_test_posts'][ $mw_dl_track_b ],
	$GLOBALS['mw_test_titles'][ $mw_dl_parent_id ],
	$GLOBALS['mw_test_titles'][ $mw_dl_track_a ],
	$GLOBALS['mw_test_titles'][ $mw_dl_track_b ],
	$GLOBALS['mw_test_meta'][ $mw_dl_parent_id ],
	$GLOBALS['mw_test_meta'][ $mw_dl_track_a ],
	$GLOBALS['mw_test_meta'][ $mw_dl_track_b ],
	$GLOBALS['mw_test_types'][ $mw_dl_parent_id ],
	$GLOBALS['mw_test_types'][ $mw_dl_track_a ],
	$GLOBALS['mw_test_types'][ $mw_dl_track_b ],
	$GLOBALS['mw_test_statuses'][ $mw_dl_parent_id ],
	$GLOBALS['mw_test_statuses'][ $mw_dl_track_a ],
	$GLOBALS['mw_test_statuses'][ $mw_dl_track_b ],
	$_GET['context']
);

echo 'Block architecture, CSS cascade & Inspector refactor suite OK (' . count( $GLOBALS['mw_test_registered_blocks'] ) . ' registered blocks verified).' . PHP_EOL;
}
