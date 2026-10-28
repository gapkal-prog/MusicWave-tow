<?php
/**
 * Title: معرفی کشف موسیقی
 * Slug: musicwave/home-hero
 * Categories: musicwave, featured
 * Keywords: hero, discovery, search, home, gradient
 * Inserter: true
 *
 * @package MusicWave
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<!-- wp:group {"align":"wide","className":"mw-home-hero mw-surface","metadata":{"name":"معرفی کشف موسیقی"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|40","bottom":"var:preset|spacing|50","left":"var:preset|spacing|40"},"blockGap":"var:preset|spacing|30"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide mw-home-hero mw-surface" style="padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--40)"><!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"center","justifyContent":"space-between"}} -->
<div class="wp-block-group alignwide"><!-- wp:group {"className":"mw-home-hero__copy","metadata":{"name":"متن و دکمه‌های معرفی"},"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group mw-home-hero__copy"><!-- wp:paragraph {"className":"mw-eyebrow mw-eyebrow--accent","fontSize":"small"} -->
<p class="mw-eyebrow mw-eyebrow--accent has-small-font-size"><?php echo esc_html__( 'صدای شما، به‌زیبایی سازمان‌یافته', 'musicwave' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1,"style":{"typography":{"fontWeight":"800","letterSpacing":"-0.03em","lineHeight":"1.05"}},"fontSize":"xx-large"} -->
<h1 class="wp-block-heading has-xx-large-font-size" style="font-weight:800;letter-spacing:-0.03em;line-height:1.05"><?php echo esc_html__( 'قطعه بعدی موردعلاقه‌تان را پیدا کنید.', 'musicwave' ); ?></h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"mw-muted","style":{"typography":{"lineHeight":"1.7"}},"fontSize":"large"} -->
<p class="mw-muted has-large-font-size" style="line-height:1.7"><?php echo esc_html__( 'انتشارها، هنرمندان، آلبوم‌ها و دانلودهای اختصاصی را در تجربه‌ای یکپارچه کشف کنید.', 'musicwave' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"style":{"spacing":{"blockGap":"var:preset|spacing|20","margin":{"top":"var:preset|spacing|20"}}},"layout":{"type":"flex"}} -->
<div class="wp-block-buttons" style="margin-top:var(--wp--preset--spacing--20)"><!-- wp:button {"className":"is-style-mw-pill","style":{"border":{"radius":"999px"}}} -->
<div class="wp-block-button is-style-mw-pill"><a class="wp-block-button__link wp-element-button" href="#latest" style="border-radius:999px"><?php echo esc_html__( 'کشف موسیقی', 'musicwave' ); ?></a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-outline is-style-mw-pill","style":{"border":{"radius":"999px"}}} -->
<div class="wp-block-button is-style-outline is-style-mw-pill"><a class="wp-block-button__link wp-element-button" href="/browse/" style="border-radius:999px"><?php echo esc_html__( 'مرور کاتالوگ', 'musicwave' ); ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->

<!-- wp:group {"className":"mw-hero-trust","metadata":{"name":"نشان‌های اعتماد"},"style":{"spacing":{"blockGap":"var:preset|spacing|20","margin":{"top":"var:preset|spacing|30"}}},"layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group mw-hero-trust" style="margin-top:var(--wp--preset--spacing--30)"><!-- wp:paragraph {"className":"mw-hero-trust__item"} -->
<p class="mw-hero-trust__item"><?php echo esc_html__( 'پخش آنی و بدون نصب', 'musicwave' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"className":"mw-hero-trust__item"} -->
<p class="mw-hero-trust__item"><?php echo esc_html__( 'دانلود امن با کیفیت اصلی', 'musicwave' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"className":"mw-hero-trust__item"} -->
<p class="mw-hero-trust__item"><?php echo esc_html__( 'پشتیبانی از هنرمندان مستقل', 'musicwave' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->

<!-- wp:group {"className":"mw-hero-search-panel","metadata":{"name":"باکس جست‌وجوی فوری"},"layout":{"type":"constrained"}} -->
<div class="wp-block-group mw-hero-search-panel"><!-- wp:paragraph {"className":"mw-hero-search-panel__label","fontSize":"small"} -->
<p class="mw-hero-search-panel__label has-small-font-size"><?php echo esc_html__( 'دنبال قطعه، هنرمند یا آلبوم بگردید', 'musicwave' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:search {"label":"<?php echo esc_attr__( 'جست‌وجو', 'musicwave' ); ?>","showLabel":false,"placeholder":"<?php echo esc_attr__( 'نام قطعه، هنرمند یا آلبوم…', 'musicwave' ); ?>","buttonText":"<?php echo esc_attr__( 'جست‌وجو', 'musicwave' ); ?>","buttonUseIcon":true,"className":"mw-hero-search"} /-->

<!-- wp:paragraph {"textColor":"muted","className":"mw-hero-search-panel__hint","style":{"typography":{"textAlign":"center"}},"fontSize":"x-small"} -->
<p class="has-text-align-center mw-hero-search-panel__hint has-muted-color has-text-color has-x-small-font-size"><?php echo esc_html__( 'جست‌وجوی فوری در کل کاتالوگ', 'musicwave' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
