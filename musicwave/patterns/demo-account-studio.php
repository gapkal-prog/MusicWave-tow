<?php
/**
 * Title: دموی استودیوی حساب کاربری
 * Slug: musicwave/demo-account-studio
 * Categories: musicwave, musicwave-demos
 * Keywords: demo, account, dashboard, library, queue, continue listening
 * Inserter: true
 * Viewport Width: 1440
 *
 * Demo pack: the listener-facing half of the account page.
 *
 * An account page does not have to be one tabbed dashboard. This pack shows the
 * personal surfaces as ordinary blocks — a welcome panel, listening stats, the
 * resume rail, the playback queue and the full library — so an editor can
 * reorder them, drop the ones the site does not need, or split them across
 * several pages. It deliberately does not use music-wave/account-dashboard, the
 * all-in-one block the bundled page-account template already composes.
 *
 * Every block here is dynamic: it renders the signed-in visitor's own data on
 * the frontend and shows a neutral placeholder to guests.
 *
 * @package MusicWave
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$musicwave_demo = new \ManaCore\MusicWave\Theme\Patterns\DemoComposer();

?>
<!-- wp:group {"align":"wide","className":"mw-demo","metadata":{"name":"دموی استودیوی حساب کاربری"},"style":{"spacing":{"blockGap":"var:preset|spacing|50"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide mw-demo"><?php echo $musicwave_demo->intro( __( 'حساب کاربری، چیدمان متفاوت', 'musicwave' ), __( 'استودیوی شخصی شما در یک نگاه', 'musicwave' ), __( 'خوشآمد، آمار شنیدن، ادامهٔ پخش، صف پخش و کتابخانهٔ شخصی؛ هر بخش یک بلوک مستقل که میتوانید جابهجا کنید یا حذف کنید.', 'musicwave' ) ); ?>
<!-- wp:group {"className":"mw-demo__grid","layout":{"type":"grid","columnCount":2}} -->
<div class="wp-block-group mw-demo__grid"><!-- wp:music-wave/dashboard-welcome /-->

<!-- wp:music-wave/dashboard-stats /--></div>
<!-- /wp:group -->

<!-- wp:music-wave/continue-listening {"heading":"به گوش دادن ادامه دهید","intro":"از همانجایی که رها کردید؛ پیشرفت شما ذخیره شده است.","layout":"scroll","itemsToShow":8,"columns":4,"showWhen":true,"showPreview":true} /-->

<!-- wp:music-wave/playback-queue {"heading":"صف پخش","showPosition":true,"showControls":true,"showPlayAll":true,"showShuffle":true,"showRepeat":true,"showClear":true} /-->

<?php echo $musicwave_demo->section_head( __( 'کتابخانه', 'musicwave' ), __( 'همهٔ آنچه ذخیره کردهاید', 'musicwave' ), __( 'فهرست شخصی با فیلتر، شمارش و امکان حذف؛ همان کتابخانهٔ حساب کاربری، اینبار بهصورت یک فهرست کامل.', 'musicwave' ) ); ?>
<!-- wp:music-wave/music-library {"layout":"list","itemsToShow":12,"columns":4,"showFilters":true,"showCounts":true,"showArtist":true,"showType":true,"showYear":true,"showRemove":true} /-->

<!-- wp:music-wave/playlists {"heading":"فهرستهای پخش من"} /--></div>
<!-- /wp:group -->