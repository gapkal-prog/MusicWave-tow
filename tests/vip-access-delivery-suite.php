<?php
/**
 * Comprehensive End-to-End Integration & Regression Suite for all 9 MusicWave VIP,
 * Access Mode, WooCommerce Linking, Defaults, Bulk Access, Secure Downloads,
 * Uninstall Data Retention, and Protected Delivery subsystems.
 */

declare(strict_types=1);

use ManaCore\MusicWave\Core\Access\AccessCtaResolver;
use ManaCore\MusicWave\Core\Access\AccessDecision;
use ManaCore\MusicWave\Core\Access\AccessModePolicy;
use ManaCore\MusicWave\Core\Access\AccessPolicyEngine;
use ManaCore\MusicWave\Core\Access\AccessSubject;
use ManaCore\MusicWave\Core\Admin\BulkAccessManager;
use ManaCore\MusicWave\Core\Blocks\ReleaseBlocks;
use ManaCore\MusicWave\Core\Catalog\ReleaseDefaults;
use ManaCore\MusicWave\Core\Commerce\ProductMapper;
use ManaCore\MusicWave\Core\Commerce\PurchaseChecker;
use ManaCore\MusicWave\Core\Downloads\DownloadRateLimiter;
use ManaCore\MusicWave\Core\Downloads\DownloadResolver;
use ManaCore\MusicWave\Core\Downloads\DownloadRoutes;
use ManaCore\MusicWave\Core\Downloads\DownloadTokenClaims;
use ManaCore\MusicWave\Core\Downloads\DownloadTokenService;
use ManaCore\MusicWave\Core\Downloads\TransientReplayStore;
use ManaCore\MusicWave\Core\Infrastructure\WordPressReleaseRepository;
use ManaCore\MusicWave\Core\Schema\ReleaseMetaSchema;
use ManaCore\MusicWave\Core\Support\Settings as CoreSettings;
use ManaCore\MusicWave\Core\Support\SettingsFilters;
use ManaCore\MusicWave\Vip\ConfigurableMembershipProvider;
use ManaCore\MusicWave\Vip\Delivery\ProtectedDeliveryDiagnostics;
use ManaCore\MusicWave\Vip\ProtectedAssetRegistry;
use ManaCore\MusicWave\Vip\ProtectedAssetRoutes;
use ManaCore\MusicWave\Vip\ProtectedAssetStorage;
use ManaCore\MusicWave\Vip\ProtectedFileProvider;
use ManaCore\MusicWave\Vip\SettingsPage as VipSettingsPage;
use ManaCore\MusicWave\Vip\VipPlans;
use ManaCore\MusicWave\Vip\VipSettings;

// Register Core runtime settings filters for end-to-end verification.
( new SettingsFilters() )->register();

$mw_suite_schema = new ReleaseMetaSchema();
$mw_suite_repo   = new WordPressReleaseRepository( $mw_suite_schema );
$mw_suite_mapper = new ProductMapper( $mw_suite_schema );

// -----------------------------------------------------------------------------
// 1. Access Modes (`public`, `logged_in`, `purchase`, `membership`, `purchase_or_membership`, `restricted`) & Dual CTAs
// -----------------------------------------------------------------------------
mw_assert_same(
	array( 'public', 'logged_in', 'purchase', 'membership', 'purchase_or_membership', 'restricted' ),
	AccessModePolicy::keys(),
	'AccessModePolicy must expose all 6 canonical modes.'
);
$GLOBALS['mw_test_types'][9100] = 'mw_release';
$GLOBALS['mw_test_meta'][9100]  = array();
mw_assert_same(
	'purchase_or_membership',
	AccessModePolicy::resolve_for_release( $mw_suite_repo, 9100, 'purchase_or_membership' )['mode'],
	'AccessModePolicy::resolve_for_release must NEVER destructively rewrite purchase_or_membership when sides are initially unlinked.'
);
mw_assert_same(
	array( 'mw_vip' ),
	$mw_suite_repo->get( 9100, 'mw_membership_levels' ),
	'AccessModePolicy::resolve_for_release must auto-seed default VIP level when empty on purchase_or_membership.'
);
unset( $GLOBALS['mw_test_types'][9100], $GLOBALS['mw_test_meta'][9100] );

$mw_hybrid_release_id = 9101;
$mw_woo_product_id    = 9201;
$mw_vip_page_id       = 9301;

$GLOBALS['mw_test_posts'][ $mw_hybrid_release_id ]    = (object) array( 'ID' => $mw_hybrid_release_id, 'post_type' => 'mw_release', 'post_status' => 'publish', 'post_title' => 'قطعه ترکیبی ویژه' );
$GLOBALS['mw_test_types'][ $mw_hybrid_release_id ]    = 'mw_release';
$GLOBALS['mw_test_statuses'][ $mw_hybrid_release_id ] = 'publish';
$GLOBALS['mw_test_titles'][ $mw_hybrid_release_id ]   = 'قطعه ترکیبی ویژه';

$GLOBALS['mw_test_posts'][ $mw_woo_product_id ]    = (object) array( 'ID' => $mw_woo_product_id, 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'خرید تکی قطعه' );
$GLOBALS['mw_test_types'][ $mw_woo_product_id ]    = 'product';
$GLOBALS['mw_test_statuses'][ $mw_woo_product_id ] = 'publish';
$GLOBALS['mw_test_titles'][ $mw_woo_product_id ]   = 'خرید تکی قطعه';
$GLOBALS['mw_test_meta'][ $mw_woo_product_id ]     = array( '_price' => '75000', '_regular_price' => '75000' );

$GLOBALS['mw_test_meta'][ $mw_hybrid_release_id ] = array(
	'mw_access_mode'       => 'purchase_or_membership',
	'mw_product_ids'       => array( $mw_woo_product_id ),
	'mw_membership_levels' => array( 'vip_yearly' ),
);

// 1A: Without VIP provider installed, a paid `purchase_or_membership` release MUST NOT leak for free!
$mw_no_vip_engine = new AccessPolicyEngine( $mw_suite_repo, new PurchaseChecker( $mw_suite_repo ), null );
$mw_no_vip_guest  = $mw_no_vip_engine->decide( $mw_hybrid_release_id, new AccessSubject( 0 ) );
mw_assert_same( false, $mw_no_vip_guest->is_allowed(), 'Paid purchase_or_membership release must NEVER grant free access when VIP plugin is inactive.' );
mw_assert_same( 'purchase_required', $mw_no_vip_guest->reason(), 'Paid purchase_or_membership without VIP provider must request purchase.' );

// 1B: With VIP provider active, test all 4 subject combinations (Guest, Non-entitled User, Single Buyer, Active VIP Subscriber).
$mw_plans_engine = new VipPlans(
	array(
		'vip_yearly' => array(
			'product_ids'   => array( 9299 ),
			'duration_days' => 365,
		),
	),
	false
);
$mw_vip_user_id   = 501;
$mw_buyer_user_id = 502;
$mw_plain_user_id = 503;

$mw_plans_engine->grant_manual( $mw_vip_user_id, 'vip_yearly', 365, 1, 'Test annual VIP' );
$GLOBALS['mw_test_users'][ $mw_buyer_user_id ]                                       = (object) array(
	'ID'         => $mw_buyer_user_id,
	'user_email' => 'buyer502@example.test',
	'roles'      => array( 'customer' ),
);
$GLOBALS['mw_test_purchases'][ $mw_buyer_user_id . ':' . $mw_woo_product_id ] = true;

$mw_vip_provider  = new ConfigurableMembershipProvider( array( 'woocommerce_plans', 'role' ), $mw_plans_engine );
$mw_hybrid_engine = new AccessPolicyEngine( $mw_suite_repo, new PurchaseChecker( $mw_suite_repo ), $mw_vip_provider );

$dec_guest = $mw_hybrid_engine->decide( $mw_hybrid_release_id, new AccessSubject( 0 ) );
$dec_plain = $mw_hybrid_engine->decide( $mw_hybrid_release_id, new AccessSubject( $mw_plain_user_id ) );
$dec_buyer = $mw_hybrid_engine->decide( $mw_hybrid_release_id, new AccessSubject( $mw_buyer_user_id ) );
$dec_vip   = $mw_hybrid_engine->decide( $mw_hybrid_release_id, new AccessSubject( $mw_vip_user_id ) );

mw_assert_same( false, $dec_guest->is_allowed(), 'Guest must be denied on purchase_or_membership.' );
mw_assert_same( 'purchase_or_membership_required', $dec_guest->reason(), 'Guest deny reason on hybrid mode must be purchase_or_membership_required.' );
mw_assert_same( false, $dec_plain->is_allowed(), 'Unentitled registered user must be denied on purchase_or_membership.' );
mw_assert_same( true, $dec_buyer->is_allowed(), 'Single-release WooCommerce buyer must be allowed on purchase_or_membership without needing VIP.' );
mw_assert_same( 'purchase', $dec_buyer->reason(), 'Single buyer grant reason must be purchase.' );
mw_assert_same( true, $dec_vip->is_allowed(), 'Active VIP subscriber must be allowed on purchase_or_membership without buying single release.' );
mw_assert_same( 'membership', $dec_vip->reason(), 'Active VIP grant reason must be membership.' );

// 1C: `logged_in` mode check.
$GLOBALS['mw_test_meta'][ $mw_hybrid_release_id ]['mw_access_mode'] = 'logged_in';
mw_assert_same( 'login_required', $mw_hybrid_engine->decide( $mw_hybrid_release_id, new AccessSubject( 0 ) )->reason(), 'logged_in mode must deny guests with login_required.' );
mw_assert_same( true, $mw_hybrid_engine->decide( $mw_hybrid_release_id, new AccessSubject( $mw_plain_user_id ) )->is_allowed(), 'logged_in mode must allow any authenticated user.' );

// Restore `purchase_or_membership` and verify Dual CTA rendering (`Buy Single` + `Subscribe VIP`).
$GLOBALS['mw_test_meta'][ $mw_hybrid_release_id ]['mw_access_mode'] = 'purchase_or_membership';
$GLOBALS['mw_test_options'][ CoreSettings::OPTION ] = array(
	'membership_cta_url' => 'https://example.test/vip-plans/',
);
$mw_hybrid_blocks = new ReleaseBlocks( $mw_hybrid_engine, $mw_suite_repo );
$GLOBALS['mw_test_current_user_id'] = $mw_plain_user_id;
$mw_hybrid_panel_html = $mw_hybrid_blocks->render_access_panel( array( 'releaseId' => $mw_hybrid_release_id ) );
mw_assert_same( true, false !== strpos( $mw_hybrid_panel_html, 'mw-access-panel--purchase_or_membership_required' ), 'Denied panel must carry purchase_or_membership_required modifier class.' );
mw_assert_same( true, false !== strpos( $mw_hybrid_panel_html, 'mw-access-panel__cta--primary' ) && false !== strpos( $mw_hybrid_panel_html, 'mw-access-panel__cta--secondary' ), 'Hybrid panel must render BOTH primary Purchase button AND secondary VIP button simultaneously.' );
$GLOBALS['mw_test_current_user_id'] = 0;

// -----------------------------------------------------------------------------
// 2. VIP Master Switch (`module_enabled`) + Tab-Safe Settings Persistence + Source Aliases
// -----------------------------------------------------------------------------
$GLOBALS['mw_test_options'][ VipSettings::OPTION ] = array(
	'module_enabled'           => 'disabled',
	'membership_sources'       => array( 'woocommerce_plans', 'role' ),
	'promote_vip_role'         => 'enabled',
	'delete_data_on_uninstall' => 'enabled',
	'vip_plans'                => array(
		array(
			'level'         => 'vip_monthly',
			'product_ids'   => array( $mw_woo_product_id ),
			'duration_days' => 30,
		),
	),
);

// 2A: Saving the `delivery` tab must NEVER wipe `membership_sources`, `promote_vip_role`, `vip_plans`, or `delete_data_on_uninstall`!
$saved_from_delivery_tab = VipSettingsPage::sanitize_settings(
	array(
		'mwvip_full_form'   => '1',
		'mwvip_active_tab'  => 'delivery',
		'module_enabled'    => 'enabled',
		'delivery_provider' => 'local',
		'delivery_access'   => 'logged_in',
	)
);
mw_assert_same( array( 'woocommerce_plans', 'role' ), $saved_from_delivery_tab['membership_sources'], 'Saving the Protected Delivery tab must NEVER wipe membership_sources.' );
mw_assert_same( 'enabled', $saved_from_delivery_tab['promote_vip_role'], 'Saving the Protected Delivery tab must preserve promote_vip_role.' );
mw_assert_same( 'enabled', $saved_from_delivery_tab['delete_data_on_uninstall'], 'Saving the Protected Delivery tab must preserve delete_data_on_uninstall.' );
mw_assert_same( 1, count( $saved_from_delivery_tab['vip_plans'] ), 'Saving the Protected Delivery tab must preserve configured vip_plans.' );

// 2B: Re-enabling `module_enabled` in browser UI (`mwvip_controls_unlocked = 1`) updates sources on the VERY FIRST save!
$saved_browser_unlock = VipSettingsPage::sanitize_settings(
	array(
		'mwvip_full_form'         => '1',
		'mwvip_active_tab'        => 'plans',
		'mwvip_controls_unlocked' => '1',
		'module_enabled'          => 'enabled',
		'membership_sources'      => array( 'woocommerce_plans', 'filter' ),
		'promote_vip_role'        => 'disabled',
	)
);
mw_assert_same( array( 'woocommerce_plans', 'filter' ), $saved_browser_unlock['membership_sources'], 'Browser unlock save must persist newly selected sources on the very first save.' );
mw_assert_same( 'disabled', $saved_browser_unlock['promote_vip_role'], 'Browser unlock save must persist unchecked promote_vip_role on the very first save.' );

// -----------------------------------------------------------------------------
// 3. Bidirectional WooCommerce Product Linking + Quick Single-Release Product Creator
// -----------------------------------------------------------------------------
$mw_quick_rel_id = 9401;
$GLOBALS['mw_test_posts'][ $mw_quick_rel_id ]    = (object) array( 'ID' => $mw_quick_rel_id, 'post_type' => 'mw_release', 'post_status' => 'publish', 'post_title' => 'آلبوم طلوع' );
$GLOBALS['mw_test_types'][ $mw_quick_rel_id ]    = 'mw_release';
$GLOBALS['mw_test_statuses'][ $mw_quick_rel_id ] = 'publish';
$GLOBALS['mw_test_titles'][ $mw_quick_rel_id ]   = 'آلبوم طلوع';
$GLOBALS['mw_test_meta'][ $mw_quick_rel_id ]     = array();

$GLOBALS['mw_test_post_types']['product'] = true;
$created_product_id                       = $mw_suite_mapper->create_product_for_release( $mw_quick_rel_id, '129000', '99000' );
mw_assert_same( true, $created_product_id > 0, 'ProductMapper::create_product_for_release must create a valid WooCommerce product.' );
mw_assert_same( array( $created_product_id ), $mw_suite_repo->product_ids( $mw_quick_rel_id ), 'Created product must be linked on the release forward index.' );
mw_assert_same( array( $mw_quick_rel_id ), $mw_suite_mapper->release_ids( $created_product_id ), 'Created product must link back to the release on its reverse index.' );
mw_assert_same( '99000', (string) get_post_meta( $created_product_id, '_price', true ), 'Sale price lower than regular price must become active product _price.' );

// Syncing from the WooCommerce product edit screen to a second release updates both cleanly.
$mw_suite_mapper->sync_from_product( $created_product_id, array( $mw_quick_rel_id ), array( $mw_quick_rel_id, $mw_hybrid_release_id ), 'purchase_or_membership' );
mw_assert_same( true, in_array( $created_product_id, $mw_suite_repo->product_ids( $mw_hybrid_release_id ), true ), 'sync_from_product must add the product to newly linked releases.' );
$mw_suite_mapper->sync_from_product( $created_product_id, array( $mw_quick_rel_id, $mw_hybrid_release_id ), array( $mw_hybrid_release_id ) );
mw_assert_same( false, in_array( $created_product_id, $mw_suite_repo->product_ids( $mw_quick_rel_id ), true ), 'sync_from_product must remove the product from unlinked releases.' );

// -----------------------------------------------------------------------------
// 4. New Release Defaults (`default_access_mode`, `default_membership_levels`, `default_preview_duration`) & Bulk Access Manager
// -----------------------------------------------------------------------------
$GLOBALS['mw_test_options'][ CoreSettings::OPTION ] = array(
	'default_access_mode'       => 'purchase_or_membership',
	'default_membership_levels' => 'mw_vip, vip_yearly',
	'default_preview_duration'  => 45,
);
$mw_seeded_rel_id                               = 9501;
$mw_seeded_post                                 = new WP_Post( $mw_seeded_rel_id, 'mw_release', 'auto-draft' );
$GLOBALS['mw_test_posts'][ $mw_seeded_rel_id ]  = $mw_seeded_post;
$GLOBALS['mw_test_types'][ $mw_seeded_rel_id ]  = 'mw_release';
$GLOBALS['mw_test_meta'][ $mw_seeded_rel_id ]   = array();

( new ReleaseDefaults( $mw_suite_repo ) )->apply( $mw_seeded_rel_id, $mw_seeded_post, false );
mw_assert_same( 'purchase_or_membership', $GLOBALS['mw_test_meta'][ $mw_seeded_rel_id ]['mw_access_mode'] ?? '', 'ReleaseDefaults must seed purchase_or_membership on new release creation.' );
mw_assert_same( array( 'mw_vip', 'vip_yearly' ), $GLOBALS['mw_test_meta'][ $mw_seeded_rel_id ]['mw_membership_levels'] ?? array(), 'ReleaseDefaults must seed default_membership_levels on new release creation.' );
mw_assert_same( 45, $GLOBALS['mw_test_meta'][ $mw_seeded_rel_id ]['mw_preview_duration'] ?? 0, 'ReleaseDefaults must seed default_preview_duration on new release creation.' );

// -----------------------------------------------------------------------------
// 5. Secure Downloads TTLs, Guest Delivery Policy (`401` vs `200`), and Daily Quota (`429`)
// -----------------------------------------------------------------------------
$mw_dl_tmp_dir = sys_get_temp_dir() . '/mw-vip-suite-' . uniqid( '', true );
mkdir( $mw_dl_tmp_dir, 0775, true );
$mw_dl_track_file = $mw_dl_tmp_dir . '/master.mp3';
file_put_contents( $mw_dl_track_file, "ID3\x04\x00\x00\x00\x00\x00\x00" . str_repeat( 'A', 256 ) );

$GLOBALS['mw_test_options'][ VipSettings::OPTION ] = array(
	'module_enabled'        => 'enabled',
	'delivery_provider'     => 'local',
	'delivery_access'       => 'logged_in',
	'protected_root'        => $mw_dl_tmp_dir,
	'sendfile_mode'         => 'xaccel',
	'xaccel_prefix'         => '/protected-audio',
	'remote_base_url'       => 'https://cdn.musicwave.test',
	'remote_signing_secret' => '0123456789abcdef0123456789abcdef0123456789abcdef',
	'remote_ttl'            => 240,
);
$GLOBALS['mw_test_options'][ CoreSettings::OPTION ] = array(
	'download_token_ttl'   => 420,
	'stream_token_ttl'     => 1200,
	'download_daily_quota' => 1,
	'download_ip_binding'  => false,
);

$mw_suite_storage  = new ProtectedAssetStorage();
$mw_sync_result    = $mw_suite_storage->sync_inventory();
mw_assert_same( true, is_array( $mw_sync_result ) && 1 === $mw_sync_result['scanned'], 'ProtectedAssetStorage::sync_inventory must scan all audio files inside the protected root.' );
mw_assert_same( true, file_exists( $mw_dl_tmp_dir . '/.htaccess' ) && file_exists( $mw_dl_tmp_dir . '/web.config' ), 'ProtectedAssetStorage::sync_inventory must harden .htaccess and web.config.' );

$mw_listed_assets = $mw_suite_storage->list_assets();
$mw_vip_asset_id  = (string) ( $mw_listed_assets['items'][0]['id'] ?? '' );
mw_assert_same( true, '' !== $mw_vip_asset_id && ( 0 === strpos( $mw_vip_asset_id, 'vip:' ) || 0 === strpos( $mw_vip_asset_id, 'local:' ) ), 'Synced protected file must expose a valid private asset identifier.' );

$mw_diag_snapshot = ( new ProtectedDeliveryDiagnostics( $mw_suite_storage ) )->snapshot();
mw_assert_same( true, (bool) $mw_diag_snapshot['overall_ready'], 'ProtectedDeliveryDiagnostics must report overall_ready=true on hardened outside-web-root folder.' );
mw_assert_same( 1, $mw_diag_snapshot['local']['inventory_count'], 'ProtectedDeliveryDiagnostics must report accurate protected asset count even before SQL table migration.' );
mw_assert_same( true, '' !== (string) $mw_diag_snapshot['remote']['sample_download_url'], 'ProtectedDeliveryDiagnostics must generate a live sample signed HTTPS HMAC URL when configured.' );

$mw_dl_rel_id = 9601;
$GLOBALS['mw_test_posts'][ $mw_dl_rel_id ]    = (object) array( 'ID' => $mw_dl_rel_id, 'post_type' => 'mw_release', 'post_status' => 'publish' );
$GLOBALS['mw_test_types'][ $mw_dl_rel_id ]    = 'mw_release';
$GLOBALS['mw_test_statuses'][ $mw_dl_rel_id ] = 'publish';
$GLOBALS['mw_test_meta'][ $mw_dl_rel_id ]     = array(
	'mw_access_mode'     => 'public',
	'mw_download_assets' => array(
		array(
			'key'      => 'mp3',
			'label'    => 'MP3 320',
			'asset_id' => $mw_vip_asset_id,
			'format'   => 'mp3',
		),
	),
);

$mw_dl_provider = new ProtectedFileProvider( $mw_suite_storage );
$mw_dl_tokens   = new DownloadTokenService( '0123456789abcdef0123456789abcdef0123456789abcdef' );
$mw_dl_resolver = new DownloadResolver( $mw_suite_repo, $mw_hybrid_engine, $mw_dl_tokens, new TransientReplayStore(), $mw_dl_provider );
$mw_dl_routes   = new DownloadRoutes( $mw_dl_resolver, new DownloadRateLimiter( 60, 60 ) );

// Enforce guest lock (`delivery_access === 'logged_in'`) via filter.
$GLOBALS['mw_test_filters']['music_wave_guest_delivery_allowed'] = static function (): bool {
	return 'everyone' === (string) VipSettings::all()['delivery_access'];
};
$GLOBALS['mw_test_current_user'] = 0;
$mw_guest_req                    = new WP_REST_Request(
	array(
		'id'    => $mw_dl_rel_id,
		'asset' => 'mp3',
	)
);
$mw_guest_resp = $mw_dl_routes->issue( $mw_guest_req );
mw_assert_same( true, $mw_guest_resp instanceof WP_Error && 'mw_authentication_required' === $mw_guest_resp->get_error_code(), 'Guest download request must return HTTP 401 mw_authentication_required when delivery_access=logged_in.' );

// Logged-in user gets custom TTL (`420`) on first download, then hits HTTP `429` (`quota_exceeded`) on second!
$GLOBALS['mw_test_current_user'] = $mw_plain_user_id;
$mw_user_req1                       = new WP_REST_Request(
	array(
		'id'    => $mw_dl_rel_id,
		'asset' => 'mp3',
	)
);
$mw_user_resp1 = $mw_dl_routes->issue( $mw_user_req1 );
mw_assert_same( true, $mw_user_resp1 instanceof WP_REST_Response && 200 === $mw_user_resp1->get_status(), 'Authenticated user must receive 200 token response.' );
$mw_ticket1 = $mw_user_resp1->get_data();
mw_assert_same( 420, $mw_ticket1['expires_in'] ?? 0, 'Issued token response must reflect configured download_token_ttl=420.' );

$mw_deliv_req1  = new WP_REST_Request(
	array(
		'id'    => $mw_dl_rel_id,
		'token' => $mw_ticket1['token'],
		'nonce' => $mw_ticket1['nonce'],
	)
);
$mw_deliv_resp1 = $mw_dl_routes->download( $mw_deliv_req1 );
mw_assert_same( true, $mw_deliv_resp1 instanceof WP_REST_Response && 204 === $mw_deliv_resp1->get_status(), 'First delivery within daily quota=1 must succeed with 204 X-Accel-Redirect.' );
mw_assert_same( true, in_array( 'X-Accel-Redirect: /protected-audio/master.mp3', $mw_dl_provider->last_sent_headers(), true ), 'ProtectedFileProvider must emit X-Accel-Redirect header for protected delivery.' );

$mw_user_resp2  = $mw_dl_routes->issue( $mw_user_req1 );
$mw_ticket2     = $mw_user_resp2->get_data();
$mw_deliv_req2  = new WP_REST_Request(
	array(
		'id'    => $mw_dl_rel_id,
		'token' => $mw_ticket2['token'],
		'nonce' => $mw_ticket2['nonce'],
	)
);
$mw_deliv_resp2 = $mw_dl_routes->download( $mw_deliv_req2 );
mw_assert_same( true, $mw_deliv_resp2 instanceof WP_Error && 'mw_download_quota_exceeded' === $mw_deliv_resp2->get_error_code(), 'Exceeding download_daily_quota must return HTTP 429 mw_download_quota_exceeded.' );

// Asset deletion cleans up disk file cleanly.
$mw_delete_target = 0 === strpos( $mw_vip_asset_id, 'vip:' ) ? substr( $mw_vip_asset_id, 4 ) : $mw_vip_asset_id;
mw_assert_same( true, $mw_suite_storage->delete_asset( $mw_delete_target ), 'ProtectedAssetStorage::delete_asset must delete protected asset cleanly.' );
mw_assert_same( false, file_exists( $mw_dl_track_file ), 'Deleted protected asset file must be removed from private vault.' );

// -----------------------------------------------------------------------------
// 6. Uninstall Data Retention (`delete_data_on_uninstall = disabled` vs `enabled`)
// -----------------------------------------------------------------------------
global $wpdb;
$mw_prev_wpdb_enabled             = $wpdb->mw_enable_activity_table;
$wpdb->mw_enable_activity_table   = true;
$wpdb->options                    = 'wp_options';
$wpdb->postmeta                   = 'wp_postmeta';
$wpdb->usermeta                   = 'wp_usermeta';
$wpdb->query( 'CREATE TABLE IF NOT EXISTS wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
$wpdb->query( 'CREATE TABLE IF NOT EXISTS wp_usermeta (umeta_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, meta_key TEXT, meta_value TEXT)' );
$wpdb->query( 'CREATE TABLE IF NOT EXISTS wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT, option_value TEXT)' );
$wpdb->query( "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (7001, '_mw_vip_plan', 'yes'), (9100, 'mw_access_mode', 'purchase_or_membership')" );
$wpdb->query( "INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (502, '_mw_vip_grants', '[]'), (502, '_mw_favorites', '[9100]')" );
$wpdb->query( "INSERT INTO wp_options (option_name, option_value) VALUES ('_transient_mw_dl_quota_503', '1')" );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', true );
}
$GLOBALS['mw_test_options'][ VipSettings::OPTION ]      = array( 'delete_data_on_uninstall' => 'disabled' );
$GLOBALS['mw_test_options']['music_wave_vip_audit_log'] = array( array( 'event' => 'order_grant' ) );
require dirname( __DIR__ ) . '/music-wave-vip/uninstall.php';
mw_assert_same( true, isset( $GLOBALS['mw_test_options'][ VipSettings::OPTION ] ), 'VIP uninstall.php MUST preserve settings & customer VIP data when delete_data_on_uninstall=disabled.' );
mw_assert_same( true, isset( $GLOBALS['mw_test_options']['music_wave_vip_audit_log'] ), 'VIP uninstall.php MUST preserve VIP audit log when delete_data_on_uninstall=disabled.' );
mw_assert_same( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_usermeta WHERE meta_key = '_mw_vip_grants'" ), 'VIP uninstall.php MUST preserve _mw_vip_grants SQL rows when delete_data_on_uninstall=disabled.' );

$GLOBALS['mw_test_options'][ VipSettings::OPTION ] = array( 'delete_data_on_uninstall' => 'enabled' );
music_wave_vip_uninstall_site();
mw_assert_same( false, isset( $GLOBALS['mw_test_options'][ VipSettings::OPTION ] ), 'VIP uninstall.php MUST delete VIP options when delete_data_on_uninstall=enabled.' );
mw_assert_same( false, isset( $GLOBALS['mw_test_options']['music_wave_vip_audit_log'] ), 'VIP uninstall.php MUST delete VIP audit log when delete_data_on_uninstall=enabled.' );
mw_assert_same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_usermeta WHERE meta_key = '_mw_vip_grants'" ), 'VIP uninstall.php MUST purge _mw_vip_grants SQL rows when delete_data_on_uninstall=enabled.' );
mw_assert_same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '_mw_vip_plan'" ), 'VIP uninstall.php MUST purge _mw_vip_plan SQL rows when delete_data_on_uninstall=enabled.' );

$GLOBALS['mw_test_options'][ CoreSettings::OPTION ]       = array( 'delete_data_on_uninstall' => 'disabled' );
$GLOBALS['mw_test_options']['music_wave_download_secret'] = 'keep-secret';
require dirname( __DIR__ ) . '/music-wave-core/uninstall.php';
mw_assert_same( true, isset( $GLOBALS['mw_test_options'][ CoreSettings::OPTION ] ), 'Core uninstall.php MUST preserve data when delete_data_on_uninstall=disabled.' );
mw_assert_same( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = 'mw_access_mode'" ), 'Core uninstall.php MUST preserve release meta SQL rows when delete_data_on_uninstall=disabled.' );

$GLOBALS['mw_test_options'][ CoreSettings::OPTION ] = array( 'delete_data_on_uninstall' => 'enabled' );
music_wave_core_uninstall_site();
mw_assert_same( false, isset( $GLOBALS['mw_test_options'][ CoreSettings::OPTION ] ), 'Core uninstall.php MUST purge Core options & secrets when delete_data_on_uninstall=enabled.' );
mw_assert_same( false, isset( $GLOBALS['mw_test_options']['music_wave_download_secret'] ), 'Core uninstall.php MUST purge download signing secret when delete_data_on_uninstall=enabled.' );
mw_assert_same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = 'mw_access_mode'" ), 'Core uninstall.php MUST purge release meta SQL rows when delete_data_on_uninstall=enabled.' );
mw_assert_same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE '_transient_mw_%'" ), 'Core uninstall.php MUST purge transient SQL rows when delete_data_on_uninstall=enabled.' );

// Cleanup temporary test globals & directory.
unset(
	$GLOBALS['mw_test_filters']['music_wave_user_purchased_products'],
	$GLOBALS['mw_test_filters']['music_wave_guest_delivery_allowed']
);
$wpdb->mw_enable_activity_table                     = $mw_prev_wpdb_enabled;
$GLOBALS['mw_test_current_user']                    = 0;
$GLOBALS['mw_test_options'][ CoreSettings::OPTION ] = array();
$GLOBALS['mw_test_options'][ VipSettings::OPTION ]  = array();
foreach ( is_dir( $mw_dl_tmp_dir ) ? ( scandir( $mw_dl_tmp_dir ) ?: array() ) : array() as $entry ) {
	if ( '.' !== $entry && '..' !== $entry && is_file( $mw_dl_tmp_dir . '/' . $entry ) ) {
		@unlink( $mw_dl_tmp_dir . '/' . $entry );
	}
}
@rmdir( $mw_dl_tmp_dir );

echo "VIP, Access & Protected Delivery suite OK.\n";
