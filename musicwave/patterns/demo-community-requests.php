<?php
/**
 * Title: دموی جامعه و درخواستها
 * Slug: musicwave/demo-community-requests
 * Categories: musicwave, musicwave-demos
 * Keywords: demo, community, public playlists, requests, forms, faq
 * Inserter: true
 * Viewport Width: 1440
 *
 * Demo pack: the community side of the site.
 *
 * Public playlists are the one collection surface that belongs to listeners
 * rather than editors, and the request form is the site's intake for new work.
 * Putting both on one page turns the community section into a single visit
 * instead of two, and the FAQ block is pulled in as a pattern reference so the
 * answers stay in one place instead of being copied here.
 *
 * @package MusicWave
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$musicwave_demo = new \ManaCore\MusicWave\Theme\Patterns\DemoComposer();

?>
<!-- wp:group {"align":"wide","className":"mw-demo","metadata":{"name":"دموی جامعه و درخواستها"},"style":{"spacing":{"blockGap":"var:preset|spacing|50"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide mw-demo"><?php echo $musicwave_demo->intro( __( 'جامعه', 'musicwave' ), __( 'فهرستهای پخش عمومی و درخواستهای سفارش', 'musicwave' ), __( 'فهرستهایی که شنوندگان ساختهاند، و فرمی که کار تازه را به سایت میآورد؛ هر دو در یک برگه و هر دو قابل ویرایش.', 'musicwave' ) ); ?>
<!-- wp:music-wave/public-playlists {"align":"wide","eyebrow":"فهرست شنوندگان","heading":"فهرستهای پخش انجمن","intro":"فهرستهایی که کاربران سایت ساخته و منتشر کردهاند.","layout":"grid","columns":3,"itemsToShow":9,"orderby":"updated_at","imageShape":"square","showSearch":true,"showCount":true,"showArt":true,"showAuthor":true,"showUpdated":true,"showPlayButton":true,"showToggle":true,"showPagination":true} /-->

<?php echo $musicwave_demo->section_head( __( 'سفارش', 'musicwave' ), __( 'درخواست موسیقی تازه', 'musicwave' ), __( 'موسیقی اختصاصی، همکاری روی پروژه یا درخواست قطعه؛ فرم زیر درخواست را مستقیم به تیم میرساند.', 'musicwave' ) ); ?>
<!-- wp:music-wave/request-form {"mode":"both","showRoles":true,"showBudget":true,"showDeadline":true,"showPhone":true,"showLinks":true} /-->

<!-- wp:pattern {"slug":"musicwave/section-faq"} /--></div>
<!-- /wp:group -->