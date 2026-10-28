<?php
/**
 * Title: کارت انتشار
 * Slug: musicwave/release-card
 * Categories: musicwave, musicwave-cards
 * Keywords: release, card, archive, query loop, preview, artwork
 * Block Types: core/post-template
 * Inserter: true
 * Viewport Width: 440
 *
 * Canonical MusicWave release card pairing square cover artwork with a
 * floating `.mw-hero-slide__play` circular accent play button, linked release
 * title, and compact release metadata — ready for Query Loops and grids.
 *
 * @package MusicWave
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
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
