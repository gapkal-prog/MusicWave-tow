<?php
/**
 * Title: پرسشهای پرتکرار بخش
 * Slug: musicwave/section-faq
 * Categories: musicwave
 * Keywords: faq, questions, accordion, details, support
 * Inserter: true
 * Viewport Width: 1440
 *
 * A disclosure list built on core/details, not on a custom block: every
 * question is an editable `summary` and every answer is a real paragraph, so
 * rows can be added, reordered or deleted like any other block. `.mw-faq` is
 * the shared vocabulary for this list — the request pages use the same class,
 * which is why unstyled copies of this markup no longer need a private name.
 *
 * @package MusicWave
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<!-- wp:group {"align":"wide","metadata":{"name":"پرسشهای پرتکرار بخش"},"style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide"><!-- wp:music-wave/section-head {"align":"wide"} -->
<div class="wp-block-music-wave-section-head alignwide mw-section-head"><!-- wp:group {"className":"mw-section-head__text","layout":{"type":"default"}} -->
<div class="wp-block-group mw-section-head__text"><!-- wp:paragraph {"className":"mw-eyebrow"} -->
<p class="mw-eyebrow"><?php echo esc_html__( 'پیش از شروع', 'musicwave' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading"><?php echo esc_html__( 'پرسشهای پرتکرار', 'musicwave' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php echo esc_html__( 'کوتاهترین پاسخها به پرسشهایی که بیشتر از همه پرسیده میشود.', 'musicwave' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div>
<!-- /wp:music-wave/section-head -->

<!-- wp:group {"className":"mw-faq","layout":{"type":"default"}} -->
<div class="wp-block-group mw-faq"><!-- wp:details -->
<details class="wp-block-details"><summary><?php echo esc_html__( 'چگونه یک انتشار را پیشنهاد بدهم؟', 'musicwave' ); ?></summary><!-- wp:paragraph {"textColor":"muted"} -->
<p class="has-muted-color has-text-color"><?php echo esc_html__( 'از برگهٔ درخواستها استفاده کنید؛ هر پیشنهاد پس از بررسی در کاتالوگ قرار میگیرد.', 'musicwave' ); ?></p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->

<!-- wp:details -->
<details class="wp-block-details"><summary><?php echo esc_html__( 'کیفیت فایلها چه اندازه است؟', 'musicwave' ); ?></summary><!-- wp:paragraph {"textColor":"muted"} -->
<p class="has-muted-color has-text-color"><?php echo esc_html__( 'همهٔ انتشارها در دو نسخهٔ ۲۴ بیت ارائه میشوند: ۴۸ و ۹۶ کیلوهرتز.', 'musicwave' ); ?></p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->

<!-- wp:details -->
<details class="wp-block-details"><summary><?php echo esc_html__( 'آیا شنیدن بدون حساب کاربری ممکن است؟', 'musicwave' ); ?></summary><!-- wp:paragraph {"textColor":"muted"} -->
<p class="has-muted-color has-text-color"><?php echo esc_html__( 'بخش بزرگی از کاتالوگ آزاد است؛ فهرست پخش شخصی و دانلود به حساب کاربری نیاز دارد.', 'musicwave' ); ?></p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->

<!-- wp:details -->
<details class="wp-block-details"><summary><?php echo esc_html__( 'عضویت را چطور لغو کنم؟', 'musicwave' ); ?></summary><!-- wp:paragraph {"textColor":"muted"} -->
<p class="has-muted-color has-text-color"><?php echo esc_html__( 'از صفحهٔ حساب خود، هر زمان و بدون پرسش اضافه.', 'musicwave' ); ?></p>
<!-- /wp:paragraph --></details>
<!-- /wp:details --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
