<?php
/**
 * End-to-end smoke tests for a local WordPress + WooCommerce staging install.
 *
 * Run with:
 * php .staging/bin/wp-cli.phar eval-file tests/staging-smoke.php --path=.staging/wordpress
 */

use ManaCore\MusicWave\Core\Admin\ReleaseMetaBox;
use ManaCore\MusicWave\Core\Access\AccessPolicyEngine;
use ManaCore\MusicWave\Core\Access\AccessSubject;
use ManaCore\MusicWave\Core\Blocks\ReleaseBlocks;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Commerce\ProductMapper;
use ManaCore\MusicWave\Core\Commerce\PurchaseChecker;
use ManaCore\MusicWave\Core\Infrastructure\WordPressReleaseRepository;
use ManaCore\MusicWave\Core\Schema\ReleaseMetaSchema;

function mw_staging_assert($condition, string $message): void {
	if (! $condition) {
		throw new RuntimeException($message);
	}
}

function mw_staging_meta_box(): ReleaseMetaBox {
	$schema = new ReleaseMetaSchema();

	return new ReleaseMetaBox($schema, new WordPressReleaseRepository($schema), new ProductMapper());
}

function mw_staging_save_release(int $release_id, array $fields): void {
	$_POST = array_merge(
		array(
			'music_wave_release_nonce' => wp_create_nonce('music_wave_save_release'),
		),
		$fields
	);
	mw_staging_meta_box()->save($release_id, get_post($release_id));
	$_POST = array();
}

function mw_staging_product(string $name): int {
	$product = new WC_Product_Simple();
	$product->set_name($name);
	$product->set_regular_price('12.00');
	$product->set_status('publish');
	$product->set_catalog_visibility('visible');

	return $product->save();
}

wp_set_current_user(1);

mw_staging_assert(in_array('music-wave-core/music-wave-core.php', get_option('active_plugins'), true), 'M1: Core plugin must be active.');
mw_staging_assert('musicwave' === get_option('stylesheet'), 'M1: MusicWave theme must be active.');
mw_staging_assert(post_type_exists(ReleasePostType::KEY), 'M1: mw_release post type must be registered.');
foreach (array('mw_artist', 'mw_genre', 'mw_mood', 'mw_label', 'mw_release_type') as $taxonomy) {
	mw_staging_assert(taxonomy_exists($taxonomy), 'M1: Missing taxonomy ' . $taxonomy . '.');
	mw_staging_assert(is_object_in_taxonomy(ReleasePostType::KEY, $taxonomy), 'M1: Taxonomy is not attached to mw_release: ' . $taxonomy . '.');
}
mw_staging_assert(current_user_can('edit_mw_releases'), 'M1: Administrator must retain MusicWave authoring capability.');

$release_id = wp_insert_post(
	array(
		'post_type' => ReleasePostType::KEY,
		'post_status' => 'publish',
		'post_title' => 'Staging Smoke Release',
	)
);
mw_staging_assert(is_int($release_id) && $release_id > 0, 'M2: Could not create a MusicWave release.');
wp_set_object_terms($release_id, 'Smoke Artist', 'mw_artist');
wp_set_object_terms($release_id, 'Electronic', 'mw_genre');
mw_staging_assert(! is_wp_error(wp_get_object_terms($release_id, 'mw_artist')), 'M2: Artist taxonomy assignment failed.');

$product_id = mw_staging_product('Staging Smoke Product');
mw_staging_assert($product_id > 0, 'M5: Could not create WooCommerce product.');

mw_staging_save_release(
	$release_id,
	array(
		'mw_catalog_number' => 'MW-STAGE-001',
		'mw_release_date' => '2026-08-06',
		'mw_duration' => '245',
		'mw_bpm' => '128',
		'mw_musical_key' => 'A minor',
		'mw_explicit' => '1',
		'mw_preview_url' => 'https://example.test/preview.mp3',
		'mw_access_mode' => 'purchase',
		'mw_product_ids' => array('0', (string) $product_id, (string) $product_id),
		'mw_membership_levels' => 'Gold, gold, invalid level!',
	)
);

mw_staging_assert('MW-STAGE-001' === get_post_meta($release_id, 'mw_catalog_number', true), 'M2: Release metadata was not persisted.');
mw_staging_assert(128 === (int) get_post_meta($release_id, 'mw_bpm', true), 'M2: Numeric metadata was not sanitized and persisted.');
mw_staging_assert(array($product_id) === get_post_meta($release_id, 'mw_product_ids', true), 'M3/M5: Product mapping was not normalized.');
mw_staging_assert(array($release_id) === get_post_meta($product_id, ProductMapper::REVERSE_META_KEY, true), 'M5: Product reverse mapping was not created.');

$rest_response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/mw_release/' . $release_id));
mw_staging_assert(200 === $rest_response->get_status(), 'M2: Published release is not available through the REST API.');
$rest_data = $rest_response->get_data();
mw_staging_assert(isset($rest_data['meta']['mw_catalog_number']), 'M2: Public metadata is absent from REST.');
mw_staging_assert(! isset($rest_data['meta']['mw_product_ids']), 'M2/M5: Private product mapping leaked through REST.');

$repository = new WordPressReleaseRepository(new ReleaseMetaSchema());
$policy_engine = new AccessPolicyEngine($repository, new PurchaseChecker($repository));
mw_staging_assert(WP_Block_Type_Registry::get_instance()->is_registered('music-wave/release-meta'), 'M4: Release metadata block must be registered.');
global $post;
$post = get_post($release_id);
wp_set_current_user(0);
$rendering = new ReleaseBlocks(
	$policy_engine,
	$repository
);
$guest_meta = $rendering->render_meta(array());
mw_staging_assert('' === $guest_meta, 'M4: Gated release metadata must render nothing instead of a duplicate gate.');
$guest_body = $rendering->filter_content('Private editorial body');
mw_staging_assert(false === strpos($guest_body, 'Private editorial body'), 'M4: Restricted release content must not leak.');
mw_staging_assert(false !== strpos($guest_body, 'mw-access-panel'), 'M4: The canonical access panel must replace restricted body content.');
mw_staging_assert('' === $rendering->render_access_panel(array()), 'M4: The canonical access gate must render only once per release request.');
mw_staging_assert(! $policy_engine->decide($release_id, new AccessSubject())->is_allowed(), 'M6: Anonymous visitor must be denied purchase access.');
wp_set_current_user(1);
$public_release_id = wp_insert_post(array('post_type' => ReleasePostType::KEY, 'post_status' => 'publish', 'post_title' => 'Public Rendering Release'));
update_post_meta($public_release_id, 'mw_access_mode', 'public');
update_post_meta($public_release_id, 'mw_catalog_number', 'MW-PUBLIC-001');
$post = get_post($public_release_id);
$public_meta = $rendering->render_meta(array());
mw_staging_assert(false !== strpos($public_meta, 'mw-release-meta'), 'M4: Public release metadata block must render.');
update_post_meta($public_release_id, 'mw_access_mode', 'unknown');
mw_staging_assert(! $policy_engine->decide($public_release_id, new AccessSubject())->is_allowed(), 'M6: Unknown mode must deny by default in WordPress.');
update_post_meta($public_release_id, 'mw_access_mode', 'public');

mw_staging_save_release(
	$release_id,
	array(
		'mw_access_mode' => 'purchase',
		'mw_product_ids' => array('0'),
	)
);
mw_staging_assert('restricted' === get_post_meta($release_id, 'mw_access_mode', true), 'M3: Purchase access without a product must fail closed.');

mw_staging_save_release(
	$release_id,
	array(
		'mw_access_mode' => 'purchase',
		'mw_product_ids' => array((string) $product_id),
	)
);
mw_staging_assert('purchase' === get_post_meta($release_id, 'mw_access_mode', true), 'M3: Valid purchase access was not retained.');

$buyer_id = username_exists('musicwave-smoke-buyer');
if (! $buyer_id) {
	$buyer_id = wp_insert_user(
		array(
			'user_login' => 'musicwave-smoke-buyer',
			'user_email' => 'musicwave-smoke-buyer@example.test',
			'user_pass' => wp_generate_password(),
			'role' => 'customer',
		)
	);
}
mw_staging_assert(! is_wp_error($buyer_id) && $buyer_id > 0, 'M5: Could not create WooCommerce customer.');
$order = wc_create_order(array('customer_id' => $buyer_id));
$order->add_product(wc_get_product($product_id), 1);
$order->calculate_totals();
$order->update_status('completed');

$ownership = new PurchaseChecker($repository);
mw_staging_assert($ownership->user_owns_release($buyer_id, $release_id), 'M5: Completed WooCommerce order must grant ownership.');
mw_staging_assert(! $ownership->user_owns_release(0, $release_id), 'M5: Anonymous visitor must not gain ownership.');
mw_staging_assert($policy_engine->decide($release_id, new AccessSubject($buyer_id))->is_allowed(), 'M6: Purchase policy must grant a completed-order owner.');

wp_delete_post($product_id, true);
mw_staging_assert(array() === get_post_meta($release_id, 'mw_product_ids', true), 'M5: Deleting product must remove release mapping.');

// M7/M8: signed download issuance and provider composition.
wp_set_current_user(1);
update_post_meta($public_release_id, 'mw_download_asset_id', 'local:smoke/release.zip');
$quality_write = new WP_REST_Request( 'POST', '/music-wave/v1/releases/' . $public_release_id . '/download-assets' );
$quality_write->set_param(
	'assets',
	array(
		array( 'key' => 'mp3-320', 'label' => 'MP3 320 kbps', 'asset_id' => 'local:smoke/release-320.mp3', 'format' => 'mp3', 'file_key' => 'main', 'file_label' => 'Main download' ),
		array( 'key' => 'flac', 'label' => 'FLAC lossless', 'asset_id' => 'local:smoke/release.flac', 'format' => 'flac', 'file_key' => 'main', 'file_label' => 'Main download' ),
	)
);
$quality_write_response = rest_do_request( $quality_write );
mw_staging_assert( 200 === $quality_write_response->get_status(), 'M7: Authorized download quality update failed.' );
mw_staging_assert( 2 === count( $quality_write_response->get_data()['items'] ), 'M7: Download quality update did not return stored variants.' );
wp_set_current_user( 0 );
$anonymous_token_response = rest_do_request( new WP_REST_Request( 'POST', '/music-wave/v1/releases/' . $public_release_id . '/download-token' ) );
mw_staging_assert( 401 === $anonymous_token_response->get_status() && 'mw_authentication_required' === $anonymous_token_response->as_error()->get_error_code(), 'M7: Anonymous token requests must return the actionable authentication error.' );
wp_set_current_user( 1 );
$token_response = rest_do_request(new WP_REST_Request('POST', '/music-wave/v1/releases/' . $public_release_id . '/download-token'));
mw_staging_assert(200 === $token_response->get_status(), 'M7: Authorized token issuance failed.');
$token_data = $token_response->get_data();
mw_staging_assert(! empty($token_data['token']) && ! empty($token_data['nonce']), 'M7: Token response is incomplete.');
$quality_token_request = new WP_REST_Request( 'POST', '/music-wave/v1/releases/' . $public_release_id . '/download-token' );
$quality_token_request->set_param( 'quality', 'flac' );
$quality_token_response = rest_do_request( $quality_token_request );
mw_staging_assert( 200 === $quality_token_response->get_status(), 'M7: Authorized quality token issuance failed.' );
$quality_token_data = $quality_token_response->get_data();
$quality_claims = ( new ManaCore\MusicWave\Core\Downloads\DownloadTokenService( wp_salt( 'auth' ) ) )->verify( $quality_token_data['token'], $quality_token_data['nonce'] );
mw_staging_assert( null !== $quality_claims && 'flac' === $quality_claims->asset_key(), 'M7: Download tokens must bind the selected quality.' );
$download_provider = apply_filters('music_wave_download_provider', new ManaCore\MusicWave\Core\Downloads\NullDownloadProvider());
mw_staging_assert($download_provider instanceof ManaCore\MusicWave\Vip\ProtectedFileProvider, 'M8: VIP download provider was not composed.');
$membership_provider = ManaCore\MusicWave\Vip\ProviderFactory::membership_provider();
mw_staging_assert($membership_provider->has_access(1, array('administrator')), 'M8: WordPress role membership adapter must grant a configured role level.');
$vip_settings_before = get_option(ManaCore\MusicWave\Vip\VipSettings::OPTION, false);
update_option(ManaCore\MusicWave\Vip\VipSettings::OPTION, array('delivery_provider' => 'remote_redirect', 'remote_base_url' => 'https://downloads.example.test', 'remote_signing_secret' => str_repeat('x', 40)));
$remote_provider = ManaCore\MusicWave\Vip\ProviderFactory::download_provider(new ManaCore\MusicWave\Vip\ProtectedAssetStorage());
mw_staging_assert($remote_provider instanceof ManaCore\MusicWave\Vip\RemoteRedirectProvider, 'M8: Remote redirect provider must be selectable without replacing Core contracts.');
$remote_url = $remote_provider->create_url('album/track name.mp3', new ManaCore\MusicWave\Core\Downloads\DownloadTokenClaims(1, 1, time() + 60, 'token-id-1234567890', 'binding', 'mp3-320', 'download'), false);
echo 'REMOTE_URL=' . $remote_url . PHP_EOL;
mw_staging_assert(0 === strpos($remote_url, 'https://downloads.example.test/files/music/album/track%20name.mp3?') && false !== strpos($remote_url, 'sig=') && false !== strpos($remote_url, 'exp='), 'M8: Remote provider must encode the opaque asset path and generate short-lived HMAC parameters.');
if ( false === $vip_settings_before ) {
	delete_option(ManaCore\MusicWave\Vip\VipSettings::OPTION);
} else {
	update_option(ManaCore\MusicWave\Vip\VipSettings::OPTION, $vip_settings_before);
}

// Editorial collection relationship and reverse index.
$child_release_id = wp_insert_post(array('post_type' => ReleasePostType::KEY, 'post_status' => 'publish', 'post_title' => 'Staging Child Track'));
wp_set_object_terms($public_release_id, 'album', 'mw_release_type');
wp_set_object_terms($child_release_id, 'track', 'mw_release_type');
mw_staging_assert($repository->replace_collection_items($public_release_id, array(array('release_id' => $child_release_id, 'position' => 1, 'role' => 'track'))), 'Editorial: Collection relation could not be persisted.');
mw_staging_assert(array($public_release_id) === $repository->collection_ids($child_release_id), 'Editorial: Collection reverse index is invalid.');

// Editorial quality: incomplete releases receive non-blocking readiness issues.
$readiness = new ManaCore\MusicWave\Core\Admin\ReleaseReadiness($repository);
$readiness_issues = $readiness->issues($child_release_id);
mw_staging_assert(! empty($readiness_issues), 'Editorial: Incomplete releases must surface readiness issues.');

// SEO: JSON-LD uses public catalog fields and never exposes private asset identifiers.
$json_ld = new ManaCore\MusicWave\Core\Seo\ReleaseJsonLd($repository);
$release_schema = $json_ld->schema($public_release_id);
mw_staging_assert('MusicAlbum' === $release_schema['@type'], 'SEO: Album releases must produce MusicAlbum schema.');
mw_staging_assert(! isset($release_schema['mw_download_asset_id']), 'SEO: Protected asset identifiers must never appear in public schema.');
mw_staging_assert(! empty($release_schema['track']), 'SEO: Album schema must include public collection tracks.');
mw_staging_assert(1 === $release_schema['numTracks'] && ! empty($release_schema['mainEntityOfPage']), 'SEO: Album schema must expose public track count and main page identity.');
$social_metadata = ( new ManaCore\MusicWave\Core\Seo\ReleaseMetadata( $repository ) )->metadata( $public_release_id );
mw_staging_assert('music.album' === $social_metadata['type'] && ! empty($social_metadata['description']), 'SEO: Release pages must provide an Open Graph music fallback.');
mw_staging_assert(false === strpos(wp_json_encode($social_metadata), 'local:smoke'), 'SEO: Social metadata must never expose private provider identifiers.');

// M9/M10: every custom block referenced by the theme is registered with API v3.
foreach (array('music-wave/release-meta', 'music-wave/access-panel', 'music-wave/preview-player', 'music-wave/preview-button', 'music-wave/download-button', 'music-wave/collection-list', 'music-wave/release-credits', 'music-wave/catalog-filters', 'music-wave/catalog-results', 'music-wave/related-releases', 'music-wave/artist-profile') as $block_name) {
	$block_type = WP_Block_Type_Registry::get_instance()->get_registered($block_name);
	mw_staging_assert(false !== $block_type, 'M9/M10: Missing block ' . $block_name . '.');
	mw_staging_assert(3 === $block_type->api_version, 'M9/M10: Block must use API v3: ' . $block_name . '.');
}

echo "M1-M10 WordPress/WooCommerce/VIP staging smoke tests passed.\n";
