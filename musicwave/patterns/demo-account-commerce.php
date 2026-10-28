<?php
/**
 * Title: دموی خرید و عضویت حساب کاربری
 * Slug: musicwave/demo-account-commerce
 * Categories: musicwave, musicwave-demos
 * Keywords: demo, account, membership, subscription, orders, downloads, payment
 * Inserter: true
 * Viewport Width: 1440
 *
 * Demo pack: the commerce-facing half of the account page.
 *
 * The counterpart to musicwave/demo-account-studio. Where that pack shows what
 * a listener has saved, this one shows what they have bought: the active
 * subscription, the membership plans, the order history, entitled downloads,
 * addresses and payment methods. Use it alone for a shop-style account page, or
 * pair the two packs to rebuild the full dashboard without the all-in-one block.
 *
 * @package MusicWave
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$musicwave_demo = new \ManaCore\MusicWave\Theme\Patterns\DemoComposer();

?>
<!-- wp:group {"align":"wide","className":"mw-demo","metadata":{"name":"دموی خرید و عضویت حساب کاربری"},"style":{"spacing":{"blockGap":"var:preset|spacing|50"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide mw-demo"><?php echo $musicwave_demo->intro( __( 'حساب کاربری، بخش خرید', 'musicwave' ), __( 'عضویت، سفارش و دانلود در یک برگه', 'musicwave' ), __( 'اشتراک فعال، طرحهای عضویت، تاریخچهٔ سفارش، دانلودهای مجاز، آدرس و روش پرداخت؛ هر بخش یک بلوک جداگانه و قابل ویرایش.', 'musicwave' ) ); ?>
<!-- wp:group {"className":"mw-demo__grid","layout":{"type":"grid","columnCount":2}} -->
<div class="wp-block-group mw-demo__grid"><!-- wp:music-wave/dashboard-stats {"compact":false} /-->

<!-- wp:music-wave/dashboard-subscription {"showActive":true,"showProgress":true,"showOrderLink":true,"showRenewal":true} /--></div>
<!-- /wp:group -->

<!-- wp:music-wave/membership-panel {"showActive":true,"showPlans":true,"showBuyButtons":true} /-->

<?php echo $musicwave_demo->section_head( __( 'پرداخت', 'musicwave' ), __( 'سفارشها و دانلودها', 'musicwave' ), __( 'پیگیری سفارش، فایلهای آمادهٔ دانلود و اطلاعات پرداخت، همه در یک قالب یکدست.', 'musicwave' ) ); ?>
<!-- wp:music-wave/dashboard-orders {"compact":false,"perPage":6} /-->

<!-- wp:music-wave/dashboard-downloads {"compact":false} /-->

<!-- wp:group {"className":"mw-demo__grid","layout":{"type":"grid","columnCount":2}} -->
<div class="wp-block-group mw-demo__grid"><!-- wp:music-wave/dashboard-addresses {"compact":false,"showBilling":true,"showShipping":true} /-->

<!-- wp:music-wave/dashboard-payment {"compact":false} /--></div>
<!-- /wp:group -->

<!-- wp:music-wave/dashboard-requests {"compact":false,"perPage":6,"showStatus":true,"showReference":true} /--></div>
<!-- /wp:group -->