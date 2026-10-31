<?php
/**
 * Static Full Site Editing integrity gate for the MusicWave block theme.
 *
 * This intentionally has no WordPress runtime dependency. It catches broken
 * template/part/pattern references and block metadata drift in a clean
 * checkout before a ZIP is sent to a customer or marketplace.
 */

declare(strict_types=1);

$theme_directory = dirname(__DIR__) . '/musicwave';
$failures        = array();

$fail = static function (string $message) use (&$failures): void {
	$failures[] = $message;
};

$theme_json_file = $theme_directory . '/theme.json';
$theme_json      = is_file($theme_json_file) ? json_decode((string) file_get_contents($theme_json_file), true) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
if (! is_array($theme_json)) {
	$fail('theme.json is missing or contains invalid JSON.');
}

$parts = array();
if (is_array($theme_json) && isset($theme_json['templateParts']) && is_array($theme_json['templateParts'])) {
	foreach ($theme_json['templateParts'] as $part) {
		if (! is_array($part) || empty($part['name'])) {
			$fail('Every theme.json template part must declare a name.');
			continue;
		}
		$name        = (string) $part['name'];
		$parts[$name] = true;
		if (! is_file($theme_directory . '/parts/' . $name . '.html')) {
			$fail('Missing template part file: parts/' . $name . '.html');
		}
	}
}

// A part that ships but is not declared in theme.json never reaches the
// Site Editor template-part list, so a merchant can neither see nor edit it.
// This reverse check is what keeps every header variant (including
// header-stream-end) reachable from Appearance → Editor.
if (is_array($theme_json)) {
	foreach (glob($theme_directory . '/parts/*.html') ?: array() as $part_file) {
		$part_name = basename($part_file, '.html');
		if (! isset($parts[$part_name])) {
			$fail('Template part file parts/' . $part_name . '.html is not registered in theme.json templateParts and stays invisible in the Site Editor.');
		}
	}
}

$custom_templates = array();
if (is_array($theme_json) && isset($theme_json['customTemplates']) && is_array($theme_json['customTemplates'])) {
	foreach ($theme_json['customTemplates'] as $template) {
		if (! is_array($template) || empty($template['name'])) {
			$fail('Every custom template must declare a name.');
			continue;
		}
		$name                = (string) $template['name'];
		$custom_templates[]  = $name;
		if (! is_file($theme_directory . '/templates/' . $name . '.html')) {
			$fail('Missing custom template file: templates/' . $name . '.html');
		}
	}
}

$pattern_slugs = array();
foreach (glob($theme_directory . '/patterns/*.php') ?: array() as $pattern_file) {
	$content = (string) file_get_contents($pattern_file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if (preg_match('/^\s*\*\s+Slug:\s+([a-z0-9-]+\/[a-z0-9-]+)/m', $content, $match)) {
		$slug = $match[1];
		if (isset($pattern_slugs[$slug])) {
			$fail('Duplicate pattern slug: ' . $slug);
		}
		$pattern_slugs[$slug] = true;
	} else {
		$fail('Pattern is missing a valid Slug header: ' . basename($pattern_file));
	}
}

$block_files = array_merge(
	glob($theme_directory . '/templates/*.html') ?: array(),
	glob($theme_directory . '/parts/*.html') ?: array(),
	glob($theme_directory . '/patterns/*.php') ?: array()
);

foreach ($block_files as $block_file) {
	$label   = basename($block_file);
	$content = (string) file_get_contents($block_file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	// New bundled content must use the canonical namespace. The legacy
	// namespace remains valid only through the hidden PHP/JS compatibility alias.
	if (preg_match('/wp:musicwave\/(?:release-shelf|release-slider|theme-text|theme-toggle)\b/', $content)) {
		$fail($label . ' uses a legacy MusicWave block namespace; bundled content must use music-wave/*.');
	}

	if (false !== strpos($content, 'music-sidebar')) {
		$fail($label . ' references the retired music-sidebar part.');
	}
	if (false !== strpos($content, 'â') || false !== strpos($content, 'Ã')) {
		$fail($label . ' contains a mojibake sequence.');
	}

	preg_match_all('/<!--\s+wp:template-part\s+(\{.*?\})\s+\/-->/', $content, $part_matches);
	foreach ($part_matches[1] as $attributes_json) {
		$attributes = json_decode($attributes_json, true);
		$slug       = is_array($attributes) && isset($attributes['slug']) ? (string) $attributes['slug'] : '';
		if ('' === $slug || ! isset($parts[$slug]) || ! is_file($theme_directory . '/parts/' . $slug . '.html')) {
			$fail($label . ' references an unavailable template part: ' . ($slug ?: '[empty]'));
		}
	}

	preg_match_all('/<!--\s+wp:pattern\s+(\{.*?\})\s+\/-->/', $content, $pattern_matches);
	foreach ($pattern_matches[1] as $attributes_json) {
		$attributes = json_decode($attributes_json, true);
		$slug       = is_array($attributes) && isset($attributes['slug']) ? (string) $attributes['slug'] : '';
		$own        = false === strpos($slug, '/') || 0 === strpos($slug, 'musicwave/');
		if ($own && ! isset($pattern_slugs[$slug])) {
			$fail($label . ' references an unavailable bundled pattern: ' . ($slug ?: '[empty]'));
		}
	}

	// footer.html already composes footer-widgets. Including both from one
	// template renders the footer twice and is a common Site Editor regression.
	$has_footer         = 1 === preg_match('/wp:template-part\s+\{[^}]*"slug"\s*:\s*"footer"/', $content);
	$has_footer_widgets = 1 === preg_match('/wp:template-part\s+\{[^}]*"slug"\s*:\s*"footer-widgets"/', $content);
	if ($has_footer && $has_footer_widgets) {
		$fail($label . ' includes both footer and footer-widgets; footer already owns the widget area.');
	}

	// Dynamic MusicWave blocks must remain self-closing in templates and parts;
	// a fallback HTML body is not synchronized with server-side rendering.
	preg_match_all('/<!--\s+wp:((?:music-wave|musicwave)\/[a-z0-9-]+)(.*?)-->/', $content, $dynamic_matches, PREG_SET_ORDER);
	foreach ($dynamic_matches as $dynamic_match) {
		// section-head is the explicitly registered static container, not a PHP leaf.
		if ('music-wave/section-head' !== $dynamic_match[1] && '/' !== substr(rtrim($dynamic_match[2]), -1)) {
			$fail($label . ' stores dynamic MusicWave block ' . $dynamic_match[1] . ' with fallback HTML.');
		}
	}
}

$theme_block_files = glob($theme_directory . '/blocks/*/block.json') ?: array();
$theme_block_names = array();
foreach ($theme_block_files as $block_file) {
	$metadata = json_decode((string) file_get_contents($block_file), true); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$label    = basename(dirname($block_file));
	if (! is_array($metadata)) {
		$fail('Invalid theme block metadata: blocks/' . $label . '/block.json');
		continue;
	}
	$name = isset($metadata['name']) ? (string) $metadata['name'] : '';
	if ('' === $name || isset($theme_block_names[$name])) {
		$fail('Missing or duplicate theme block name in blocks/' . $label . '/block.json');
	}
	if (0 !== strpos($name, 'music-wave/')) {
		$fail($name . ' must use the canonical music-wave/* namespace.');
	}
	$theme_block_names[$name] = true;
	if (3 !== (int) ($metadata['apiVersion'] ?? 0)) {
		$fail($name . ' must use block API version 3.');
	}
	if (empty($metadata['$schema']) || 'https://schemas.wp.org/trunk/block.json' !== $metadata['$schema']) {
		$fail($name . ' must declare the WordPress block.json schema.');
	}
	if (! isset($metadata['keywords']) || ! is_array($metadata['keywords']) || empty($metadata['keywords'])) {
		$fail($name . ' must provide at least one localized keyword.');
	}
	if (empty($metadata['textdomain'])) {
		$fail($name . ' must declare a text domain.');
	}
	if ('music-wave' !== ($metadata['category'] ?? '')) {
		$fail($name . ' must use the shared music-wave inserter category.');
	}
	if (empty($metadata['title']) || empty($metadata['description']) || empty($metadata['icon'])) {
		$fail($name . ' must provide a title, description, and icon.');
	}
	if (! isset($metadata['attributes']) || ! is_array($metadata['attributes']) || ! isset($metadata['supports']) || ! is_array($metadata['supports'])) {
		$fail($name . ' must declare attributes and supports objects.');
	}
	if (! isset($metadata['example']) || ! is_array($metadata['example'])) {
		$fail($name . ' must provide an editor example.');
	}
}

$expected_theme_blocks = array(
	'music-wave/release-shelf',
	'music-wave/release-slider',
	'music-wave/theme-text',
	'music-wave/theme-toggle',
);

$core_block_names = array();
$core_block_files = glob(dirname($theme_directory) . '/music-wave-core/blocks/*/block.json') ?: array();
$core_rendering   = (string) file_get_contents(dirname($theme_directory) . '/music-wave-core/src/Modules/Rendering.php'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$core_editor      = (string) file_get_contents(dirname($theme_directory) . '/music-wave-core/assets/blocks.js'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
foreach ($core_block_files as $block_file) {
	$metadata = json_decode((string) file_get_contents($block_file), true); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$label    = 'music-wave-core/blocks/' . basename(dirname($block_file));
	if (! is_array($metadata)) {
		$fail('Invalid Core block metadata: ' . $label . '/block.json');
		continue;
	}
	$name = isset($metadata['name']) ? (string) $metadata['name'] : '';
	$core_block_names[] = $name;
	foreach (array('name', 'title', 'description', 'category', 'icon', 'textdomain', 'keywords', 'attributes', 'supports', 'example') as $required_key) {
		if (! array_key_exists($required_key, $metadata) || (is_string($metadata[$required_key]) && '' === $metadata[$required_key])) {
			$fail($label . '/block.json must declare ' . $required_key . '.');
		}
	}
	if (! isset($metadata['keywords']) || ! is_array($metadata['keywords']) || empty($metadata['keywords']) || ! isset($metadata['attributes']) || ! is_array($metadata['attributes']) || ! isset($metadata['supports']) || ! is_array($metadata['supports'])) {
		$fail($label . '/block.json must provide keyword, attributes, and supports arrays.');
	}
	if (3 !== (int) ($metadata['apiVersion'] ?? 0) || 'https://schemas.wp.org/trunk/block.json' !== ($metadata['$schema'] ?? '')) {
		$fail($label . '/block.json must use API v3 and the WordPress schema.');
	}
	if (0 !== strpos($name, 'music-wave/') || 'music-wave' !== ($metadata['category'] ?? '')) {
		$fail($name . ' must use the canonical namespace and shared category.');
	}
	if (false === strpos($core_rendering, $name) || false === strpos($core_editor, $name)) {
		$fail($name . ' must be present in both Core PHP and JavaScript editor registries.');
	}
}
// 41 canonical Core blocks: the 40 dynamic surfaces after consolidating
// `dashboard-edit` into `dashboard-account` (hidden PHP alias preserved), the
// three artists browsing-surface leaves of the composable artists index, and
// `music-wave/release-card` — the card a Query Loop repeats. The artists pager
// is not a block any more: the shelf renders WordPress' own pagination.
if (41 !== count($core_block_names) || count($core_block_names) !== count(array_unique($core_block_names))) {
	$fail('Core block metadata inventory must contain 41 unique blocks.');
}
foreach ($expected_theme_blocks as $expected_block) {
	if (! isset($theme_block_names[$expected_block])) {
		$fail('Missing canonical theme block metadata: ' . $expected_block);
	}
}

$theme_block_styles = is_array($theme_json) && isset($theme_json['styles']['blocks']) && is_array($theme_json['styles']['blocks'])
	? $theme_json['styles']['blocks']
	: array();
foreach ($expected_theme_blocks as $expected_block) {
	if (! isset($theme_block_styles[$expected_block]) && in_array($expected_block, array('music-wave/release-shelf', 'music-wave/release-slider'), true)) {
		$fail('theme.json must expose Site Editor styles for ' . $expected_block . '.');
	}
}
foreach (array('musicwave/release-shelf', 'musicwave/release-slider') as $legacy_style_block) {
	if (! isset($theme_block_styles[$legacy_style_block])) {
		$fail('theme.json must retain compatibility styles for ' . $legacy_style_block . '.');
	}
}
$theme_block_settings = is_array($theme_json) && isset($theme_json['settings']['blocks']) && is_array($theme_json['settings']['blocks'])
	? $theme_json['settings']['blocks']
	: array();
foreach (array('musicwave/release-shelf', 'musicwave/release-slider', 'musicwave/theme-text', 'musicwave/theme-toggle') as $legacy_settings_block) {
	if (! isset($theme_block_settings[$legacy_settings_block])) {
		$fail('theme.json must retain compatibility settings for ' . $legacy_settings_block . '.');
	}
}

$theme_functions = (string) file_get_contents($theme_directory . '/functions.php'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
foreach (array_merge(glob($theme_directory . '/src/*.php') ?: array(), glob($theme_directory . '/src/*/*.php') ?: array()) as $theme_src_file) {
	$theme_functions .= "\n" . (string) file_get_contents($theme_src_file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}
if (false === strpos($theme_functions, 'musicwave_register_legacy_presentation_block') || false === strpos($theme_functions, "'musicwave/' . \$dir")) {
	$fail('Theme registration must retain hidden musicwave/* compatibility aliases.');
}
foreach (array('theme-text', 'theme-toggle', 'release-slider', 'release-shelf') as $theme_block_dir) {
	if (false === strpos($theme_functions, "'dir'      => '{$theme_block_dir}'") || false === strpos($theme_functions, 'musicwave_render_' . str_replace('-', '_', $theme_block_dir))) {
		$fail('Theme PHP registration must map metadata and renderer for blocks/' . $theme_block_dir . '.');
	}
}
$navigation_css = (string) file_get_contents($theme_directory . '/assets/css/components/navigation.css'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
if (false === strpos($navigation_css, '.mw-site-header.is-position-sticky') || false === strpos($navigation_css, 'position: sticky')) {
	$fail('Header CSS must use the Site Editor sticky support class.');
}
if (preg_match('/\.mw-site-header\s*\{[^{}]*position:\s*sticky/', $navigation_css)) {
	$fail('Header CSS must not force sticky positioning when the Site Editor toggle is disabled.');
}
if (false === strpos($navigation_css, 'block-size: 100dvh') || false === strpos($navigation_css, '.wp-block-navigation__responsive-container.is-menu-open')) {
	$fail('Mobile navigation CSS must provide a full-screen open state.');
}
if (false === strpos($navigation_css, 'body.has-modal-open') || false === strpos($navigation_css, 'overflow: hidden')) {
	$fail('Mobile navigation CSS must lock the page scroll while open.');
}

if (is_array($theme_json)) {
	if (3 !== (int) ($theme_json['version'] ?? 0)) {
		$fail('theme.json must use schema version 3.');
	}
	if (empty($theme_json['settings']['layout']['allowEditing'])) {
		$fail('theme.json must expose layout editing controls to Site Editor users.');
	}
	if (true !== ($theme_json['settings']['position']['sticky'] ?? false) || true !== ($theme_json['settings']['blocks']['core/group']['position']['sticky'] ?? false)) {
		$fail('theme.json must expose sticky positioning for the header Group.');
	}
	foreach (array('page-account', 'page-playlists', 'page-cart', 'page-checkout') as $required_template) {
		if (! in_array($required_template, $custom_templates, true)) {
			$fail('theme.json must register the custom template: ' . $required_template);
		}
	}
}

if (! empty($failures)) {
	foreach ($failures as $failure) {
		echo 'FAIL ' . $failure . PHP_EOL;
	}
	echo count($failures) . ' Site Editor issue(s) found.' . PHP_EOL;
	exit(1);
}

echo 'SITE EDITOR INTEGRITY OK' . PHP_EOL;
