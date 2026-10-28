<?php
/**
 * Title: دموی مرور و کشف کاتالوگ
 * Slug: musicwave/demo-discovery-browse
 * Categories: musicwave, musicwave-demos
 * Keywords: demo, browse, catalog, filters, genres, moods, pagination, discovery
 * Inserter: true
 * Viewport Width: 1440
 *
 * Demo pack: a catalogue page that can actually be filtered.
 *
 * The genre and mood rails, the filter bar, the result summary and the release
 * grid here are the same blocks the bundled browse and archive templates use —
 * but on a Page, so the whole arrangement is editable. The grid is a Query Loop
 * with numbered pagination at the bottom, which is what makes a long catalogue
 * navigable rather than merely long.
 *
 * @package MusicWave
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$musicwave_demo = new \ManaCore\MusicWave\Theme\Patterns\DemoComposer();

?>
<!-- wp:group {"align":"wide","className":"mw-demo","metadata":{"name":"دموی مرور و کشف کاتالوگ"},"style":{"spacing":{"blockGap":"var:preset|spacing|50"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide mw-demo"><?php echo $musicwave_demo->intro( __( 'مرور کاتالوگ', 'musicwave' ), __( 'کاتالوگ را مرور کنید، نه فقط تماشا', 'musicwave' ), __( 'ردیف سبکها و حالوهواها، نوار فیلتر، خلاصهٔ نتایج، شبکهٔ انتشارها با صفحهبندی و یک ردیف پیشنهادی — همه در یک برگه.', 'musicwave' ) ); ?>
<!-- wp:music-wave/taxonomy-shelf {"align":"wide","eyebrow":"مرور","heading":"کشف و مرور هر سبک","description":"مستقیماً به صدای موردنظر خود بروید.","taxonomy":"mw_genre","layout":"grid","itemsToShow":8,"cardStyle":"colorful"} /-->

<!-- wp:music-wave/taxonomy-shelf {"align":"wide","eyebrow":"حالوهواها","heading":"یک حالوهوا انتخاب کنید","description":"موسیقی متناسب با لحظه.","taxonomy":"mw_mood","orderBy":"name","layout":"scroll","itemsToShow":12,"cardStyle":"plain","showCount":false} /-->

<?php echo $musicwave_demo->section_head( __( 'فیلترها', 'musicwave' ), __( 'کاتالوگ را دقیق کنید', 'musicwave' ), __( 'جستوجو، هنرمند، سبک، حالوهوا، نوع و مرتبسازی؛ نتیجه بلافاصله در شبکهٔ پایین بهروز میشود.', 'musicwave' ) ); ?>
<!-- wp:music-wave/catalog-filters {"align":"wide","layout":"inline","showSearch":true,"showArtistFilter":true,"showGenreFilter":true,"showMoodFilter":true,"showTypeFilter":true,"showSort":true,"showReset":true} /-->

<!-- wp:music-wave/catalog-results {"align":"wide","showCount":true,"showChips":true} /-->

<!-- wp:query {"query":{"perPage":9,"pages":0,"offset":0,"postType":"mw_release","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false}} -->
<div class="wp-block-query"><!-- wp:post-template {"className":"mw-release-grid","layout":{"type":"grid","columnCount":3}} -->
<!-- wp:group {"className":"mw-release-card mw-surface","layout":{"type":"constrained"}} -->
<div class="wp-block-group mw-release-card mw-surface"><!-- wp:group {"className":"mw-release-card__media","layout":{"type":"default"}} -->
<div class="wp-block-group mw-release-card__media"><!-- wp:post-featured-image {"isLink":true,"aspectRatio":"1"} /-->
<!-- wp:music-wave/preview-player {"buttonStyle":"icon","variant":"accent","size":"md","className":"mw-hero-slide__play"} /--></div>
<!-- /wp:group -->
<!-- wp:group {"className":"mw-release-card__body","layout":{"type":"constrained"}} -->
<div class="wp-block-group mw-release-card__body"><!-- wp:post-title {"isLink":true,"fontSize":"medium"} /-->
<!-- wp:music-wave/release-meta /--></div>
<!-- /wp:group --></div>
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
<!-- /wp:query-no-results -->
<!-- wp:query-pagination {"className":"mw-catalog-pagination","layout":{"type":"flex","justifyContent":"space-between","flexWrap":"wrap"}} -->
<!-- wp:query-pagination-previous /-->
<!-- wp:query-pagination-numbers /-->
<!-- wp:query-pagination-next /-->
<!-- /wp:query-pagination --></div>
<!-- /wp:query -->

<?php echo $musicwave_demo->section_head( __( 'پیشنهاد', 'musicwave' ), __( 'از کجا شروع کنیم؟', 'musicwave' ), __( 'یک ردیف کوتاه از انتشارهای تصادفی برای کسانی که هنوز تصمیم نگرفتهاند.', 'musicwave' ) ); ?>
<!-- wp:music-wave/release-shelf {"align":"wide","orderBy":"rand","itemsToShow":8,"columns":4,"layout":"grid","imageShape":"square","showArtwork":true,"showPlayButton":true,"showArtist":true,"showAction":true} /--></div>
<!-- /wp:group -->