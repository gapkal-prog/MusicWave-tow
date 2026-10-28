<?php
/**
 * Title: دموی فهرست هنرمندان
 * Slug: musicwave/demo-artists-directory
 * Categories: musicwave, musicwave-demos
 * Keywords: demo, artists, directory, follow, spotlight, pagination
 * Inserter: true
 * Viewport Width: 1440
 *
 * Demo pack: an artist directory as an ordinary page.
 *
 * The bundled artist template is bound to a single artist term. This pack shows
 * the whole roster instead, in the three shapes the theme can render: a term
 * rail, a grid of artist cards with follow buttons, and a paged alphabetical
 * list. Any of the three can be kept on its own, which makes the pack a good
 * starting point for a "meet the artists" landing page as well as a directory.
 *
 * @package MusicWave
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$musicwave_demo = new \ManaCore\MusicWave\Theme\Patterns\DemoComposer();

?>
<!-- wp:group {"align":"wide","className":"mw-demo","metadata":{"name":"دموی فهرست هنرمندان"},"style":{"spacing":{"blockGap":"var:preset|spacing|50"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide mw-demo"><?php echo $musicwave_demo->intro( __( 'فهرست هنرمندان', 'musicwave' ), __( 'هنرمندان را پیدا کنید و دنبال کنید', 'musicwave' ), __( 'از یک ردیف سریع تا فهرست کامل صفحهبهصفحه؛ هر سه حالت از یک بلوک ساخته شدهاند و میتوانید هرکدام را نگه دارید.', 'musicwave' ) ); ?>
<!-- wp:music-wave/taxonomy-shelf {"taxonomy":"mw_artist","orderBy":"count","layout":"grid","itemsToShow":8,"columns":4,"cardStyle":"colorful","showCount":true} /-->

<!-- wp:music-wave/artists-shelf {"source":"all","orderBy":"count","order":"DESC","layout":"grid","columns":4,"imageShape":"circle","imageSize":"medium","itemsToShow":8,"showImage":true,"showName":true,"showBio":true,"bioLength":20,"showReleaseCount":true,"showFollowButton":true} /-->

<?php echo $musicwave_demo->section_head( __( 'فهرست الفبایی', 'musicwave' ), __( 'همهٔ هنرمندان', 'musicwave' ), __( 'همان فهرست، اینبار صفحهبهصفحه و با امکان دنبال کردن؛ برای کاتالوگهای بزرگ مناسبتر است.', 'musicwave' ) ); ?>
<!-- wp:music-wave/artists-shelf {"source":"all","orderBy":"name","order":"ASC","layout":"list","columns":4,"imageShape":"circle","imageSize":"thumbnail","pagination":true,"perPage":12,"itemsToShow":12,"showImage":true,"showName":true,"showBio":true,"bioLength":30,"showReleaseCount":true,"showFollowButton":true} /--></div>
<!-- /wp:group -->