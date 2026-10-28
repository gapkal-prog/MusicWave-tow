<?php
/**
 * Title: فراخوان سرویس موسیقی
 * Slug: musicwave/music-service-callout
 * Categories: musicwave, call-to-action
 * Keywords: cta, membership, subscribe, banner
 * Inserter: true
 *
 * @package MusicWave
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<!-- wp:group {"align":"wide","className":"mw-service-callout mw-surface","metadata":{"name":"فراخوان سرویس موسیقی"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|30"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide mw-service-callout mw-surface" style="padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)"><!-- wp:paragraph {"className":"mw-eyebrow mw-eyebrow--accent","fontSize":"small"} -->
<p class="mw-eyebrow mw-eyebrow--accent has-small-font-size"><?php echo esc_html__( 'شنیدن حرفه‌ای', 'musicwave' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
<div class="wp-block-group alignwide"><!-- wp:group {"className":"mw-service-callout__copy","style":{"spacing":{"blockGap":"0.75rem"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group mw-service-callout__copy"><!-- wp:heading {"level":2,"style":{"typography":{"letterSpacing":"-0.02em","lineHeight":"1.15"}},"fontSize":"x-large"} -->
<h2 class="wp-block-heading has-x-large-font-size" style="letter-spacing:-0.02em;line-height:1.15"><?php echo esc_html__( 'کتابخانه‌ای مطابق سلیقه خود بسازید.', 'musicwave' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"mw-muted","fontSize":"medium"} -->
<p class="mw-muted has-medium-font-size"><?php echo esc_html__( 'عضویت‌ها، انتشارهای اختصاصی، دانلودها یا یک فهرست پخش منتخب را با این فراخوان قابل ویرایش برجسته کنید.', 'musicwave' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:buttons {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex"}} -->
<div class="wp-block-buttons"><!-- wp:button {"className":"is-style-mw-pill","style":{"border":{"radius":"999px"}}} -->
<div class="wp-block-button is-style-mw-pill"><a class="wp-block-button__link wp-element-button" href="/browse/" style="border-radius:999px"><?php echo esc_html__( 'شروع شنیدن', 'musicwave' ); ?></a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-outline is-style-mw-pill","style":{"border":{"radius":"999px"}}} -->
<div class="wp-block-button is-style-outline is-style-mw-pill"><a class="wp-block-button__link wp-element-button" href="/vip/" style="border-radius:999px"><?php echo esc_html__( 'مشاهده عضویت‌ها', 'musicwave' ); ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->

<!-- wp:paragraph {"className":"mw-callout-note","fontSize":"small"} -->
<p class="mw-callout-note has-small-font-size"><?php echo esc_html__( 'بدون نیاز به نصب؛ در هر دستگاهی با حساب کاربری خود ادامه دهید.', 'musicwave' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
