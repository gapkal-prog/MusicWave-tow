<?php
/**
 * Title: دموی برگهٔ کامل انتشار
 * Slug: musicwave/demo-release-showcase
 * Categories: musicwave, musicwave-demos
 * Keywords: demo, release, single, tracklist, download, lyrics, credits, related
 * Inserter: true
 * Viewport Width: 1440
 *
 * Demo pack: the whole single-release experience on one editable page.
 *
 * The bundled single template is a fixed arrangement. This pack instead opens a
 * Query Loop over the release post type and walks through every release-level
 * capability the catalogue ships: the artwork and title, the full specification
 * sheet, the access panel, preview playback, the queue and playlist actions,
 * sharing, the tracklist, the download panel, synced lyrics, credits and
 * related releases.
 *
 * The loop requests a single random release, so the page is never empty and
 * never the same twice; replace it with a chosen release by pinning the loop to
 * one post, and drop whichever sections this site does not use.
 *
 * @package MusicWave
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$musicwave_demo = new \ManaCore\MusicWave\Theme\Patterns\DemoComposer();

?>
<!-- wp:group {"align":"wide","className":"mw-demo","metadata":{"name":"دموی برگهٔ کامل انتشار"},"style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide mw-demo"><?php echo $musicwave_demo->intro( __( 'برگهٔ انتشار، همهٔ امکانات', 'musicwave' ), __( 'یک انتشار، با تمام قابلیتهایش', 'musicwave' ), __( 'مشخصات فنی، دسترسی، پخش پیشنمایش، صف پخش، فهرست قطعه، دانلود، متن همگام، اعتبارات و انتشارهای مرتبط؛ همه روی یک برگه و همه قابل ویرایش.', 'musicwave' ) ); ?>
<!-- wp:query {"query":{"perPage":1,"pages":0,"offset":0,"postType":"mw_release","order":"desc","orderBy":"rand","author":"","search":"","exclude":[],"sticky":"","inherit":false}} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:group {"className":"mw-demo__release mw-surface","layout":{"type":"constrained"}} -->
<div class="wp-block-group mw-demo__release mw-surface"><!-- wp:post-featured-image {"aspectRatio":"16/9","align":"wide"} /-->

<!-- wp:post-title {"level":2} /-->

<!-- wp:music-wave/release-meta {"compact":false,"layout":"grid","showLabels":true,"fullSpecs":true,"showCatalogNumber":true,"showReleaseDate":true,"showDuration":true,"showBpm":true,"showKey":true,"showArtist":true,"showGenre":true} /-->

<!-- wp:music-wave/access-panel {"layout":"banner"} /-->

<!-- wp:group {"className":"mw-demo__actions","layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group mw-demo__actions"><!-- wp:music-wave/preview-player {"buttonStyle":"button","size":"lg"} /-->

<!-- wp:music-wave/add-to-queue {"position":"next"} /-->

<!-- wp:music-wave/add-to-playlist /-->

<!-- wp:music-wave/library-button {"itemType":"release","buttonStyle":"heart"} /-->

<!-- wp:music-wave/share-button {"buttonStyle":"button","label":"همرسانی"} /--></div>
<!-- /wp:group -->

<!-- wp:music-wave/collection-list {"heading":"لیست قطعه","density":"comfortable","actionStyle":"icon","showTotalDuration":true,"showQuality":true,"showDownload":true,"groupByDisc":false} /-->

<!-- wp:music-wave/download-button {"compact":false,"showHeading":true,"showDescription":true,"showQuality":true,"showStream":true} /-->

<!-- wp:music-wave/synced-lyrics /-->

<!-- wp:music-wave/release-credits {"layout":"list","showRole":true,"groupByRole":false} /-->

<!-- wp:music-wave/related-releases {"layout":"grid","columns":4,"itemsToShow":4,"showPreview":true,"showDate":false} /--></div>
<!-- /wp:group -->
<!-- /wp:post-template -->

<!-- wp:query-no-results -->
<!-- wp:group {"className":"mw-catalog-empty","layout":{"type":"constrained"}} -->
<div class="wp-block-group mw-catalog-empty"><!-- wp:paragraph {"align":"center","fontSize":"large"} -->
<p class="has-text-align-center has-large-font-size"><?php echo esc_html__( 'انتشاری پیدا نشد', 'musicwave' ); ?></p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"align":"center","textColor":"muted"} -->
<p class="has-text-align-center has-muted-color has-text-color"><?php echo esc_html__( 'فیلترها را تغییر دهید یا کاتالوگ کامل را مرور کنید.', 'musicwave' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
<!-- /wp:query-no-results --></div>
<!-- /wp:query --></div>
<!-- /wp:group -->