<?php
/**
 * Title: ویترین افقی با فیلتر
 * Slug: musicwave/home-showcase-rail
 * Categories: musicwave, featured, musicwave-shelves
 * Keywords: shelf, scroll, filter, tabs, latest, top
 * Inserter: true
 *
 * The home-page showcase rail: a horizontal scroll shelf whose filter tabs
 * switch the query between the newest releases, the most-viewed, albums,
 * and singles. Tabs are server-rendered links (musicwave_parse_filter_tabs()),
 * so switching needs no JavaScript and stays crawlable.
 *
 * @package MusicWave
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo '<!-- wp:music-wave/release-shelf ' . wp_json_encode(
	array(
		'align'            => 'wide',
		'className'        => 'mw-showcase-rail',
		'eyebrow'          => __( 'ویترین انتشارهای MusicWave', 'musicwave' ),
		'title'            => __( 'جدیدترین و برترین انتشارها', 'musicwave' ),
		'description'      => __( 'با تب‌ها بین تازه‌ترین اضافه‌شده‌ها و پرسنشنیده‌ترین‌ها جابه‌جا شوید.', 'musicwave' ),
		'itemsToShow'      => 12,
		'columns'          => 4,
		'layout'           => 'scroll',
		'showArrows'       => true,
		'showArtwork'      => true,
		'showArtist'       => true,
		'showAction'       => false,
		'filterTabs'       => __( 'جدیدترین', 'musicwave' ) . "\n" . __( 'برترین', 'musicwave' ) . '|orderby:views' . "\n" . __( 'آلبوم‌ها', 'musicwave' ) . '|taxonomy:mw_release_type:album' . "\n" . __( 'تک‌آهنگ‌ها', 'musicwave' ) . '|taxonomy:mw_release_type:single',
		'sectionUrl'       => '/browse/',
		'sectionLinkLabel' => __( 'مشاهده همه', 'musicwave' ),
	)
) . " /-->\n";
