<?php
/**
 * Title: شبکهٔ انتشارها (حلقهٔ پرس‌وجو)
 * Slug: musicwave/release-grid
 * Categories: musicwave, musicwave-shelves
 * Keywords: releases, grid, query loop, archive, catalog, pagination
 * Inserter: true
 * Viewport Width: 1440
 *
 * A native Query Loop composition: core/query over the mw_release post type,
 * core/post-template as a three-column grid of canonical release cards, an
 * empty state, and pagination. Unlike the release-shelf block, which renders
 * its cards in PHP from inspector settings, every card part here is a real
 * block an editor can select, restyle or remove.
 *
 * Use this when the grid itself should be editable; use music-wave/release-shelf
 * when a single insert with query controls is wanted. Both render the same card
 * vocabulary, so they can sit on the same site without visual drift.
 *
 * @package MusicWave
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<!-- wp:query {"query":{"perPage":12,"pages":0,"offset":0,"postType":"mw_release","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false}} -->
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
