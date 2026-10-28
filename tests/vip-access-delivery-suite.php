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

// =============================================================================
// 7. Upgrade Matrix (T1–T24): MembershipAbsencePolicy, Scheme-Based DirectLink
//    Delivery, SSRF/Redirect Hardening, Streamability, Keyring Rotation,
//    Smart Admin UX, Persistent DB Quotas, SQL Mode Counts & Benchmarks
// =============================================================================

// --- T1–T6 & B-NEW-7/8: MembershipAbsencePolicy (`allow` / `deny` / `open_download`) ---
unset( $GLOBALS['mw_test_filters']['music_wave_membership_absent_behavior'] );
$mw_t_absent_rel_mem    = 9601;
$mw_t_absent_rel_hybrid = 9602;
$mw_t_absent_rel_paid   = 9603;
$mw_t_absent_rel_rest   = 9604;
$mw_t_absent_rel_login  = 9605;
foreach ( array( $mw_t_absent_rel_mem, $mw_t_absent_rel_hybrid, $mw_t_absent_rel_paid, $mw_t_absent_rel_rest, $mw_t_absent_rel_login ) as $mw_tid ) {
	$GLOBALS['mw_test_types'][ $mw_tid ]    = 'mw_release';
	$GLOBALS['mw_test_statuses'][ $mw_tid ] = 'publish';
	$GLOBALS['mw_test_posts'][ $mw_tid ]    = (object) array( 'ID' => $mw_tid, 'post_type' => 'mw_release', 'post_status' => 'publish', 'post_title' => 'Release ' . $mw_tid );
}
$GLOBALS['mw_test_meta'][ $mw_t_absent_rel_mem ]    = array( 'mw_access_mode' => 'membership', 'mw_membership_levels' => array( 'mw_vip' ) );
$GLOBALS['mw_test_meta'][ $mw_t_absent_rel_hybrid ] = array( 'mw_access_mode' => 'purchase_or_membership', 'mw_product_ids' => array( 8801 ), 'mw_membership_levels' => array( 'mw_vip' ) );
$GLOBALS['mw_test_meta'][ $mw_t_absent_rel_paid ]   = array( 'mw_access_mode' => 'purchase', 'mw_product_ids' => array( 8801 ) );
$GLOBALS['mw_test_meta'][ $mw_t_absent_rel_rest ]   = array( 'mw_access_mode' => 'restricted' );
$GLOBALS['mw_test_meta'][ $mw_t_absent_rel_login ]  = array( 'mw_access_mode' => 'logged_in' );

// B-NEW-7: MetaDefinition must preserve `logged_in` mode during schema sanitization.
mw_assert_same( 'logged_in', $mw_suite_schema->get( 'mw_access_mode' )->sanitize( 'logged_in' ), 'B-NEW-7: MetaDefinition must preserve logged_in access mode.' );

$mw_t_absent_engine = new AccessPolicyEngine( $mw_suite_repo, new PurchaseChecker( $mw_suite_repo ), null );
$mw_t_guest         = new AccessSubject( 0 );

// T1 & T4: Default `allow` stored in settings (without external filter hook — B-NEW-8 verification).
$GLOBALS['mw_test_options'][ CoreSettings::OPTION ] = array( 'membership_absent_behavior' => 'allow' );
$mw_dec_t1 = $mw_t_absent_engine->decide( $mw_t_absent_rel_mem, $mw_t_guest );
$mw_dec_t4 = $mw_t_absent_engine->decide( $mw_t_absent_rel_hybrid, $mw_t_guest );
mw_assert_same( true, $mw_dec_t1->is_allowed() && 'membership_provider_absent' === $mw_dec_t1->reason(), 'T1: membership + allow must allow with reason membership_provider_absent.' );
mw_assert_same( false, $mw_dec_t4->is_allowed(), 'T4: purchase_or_membership with product under allow must preserve purchase gate.' );
mw_assert_same( 'purchase_required', $mw_dec_t4->reason(), 'T4: purchase_or_membership with product under allow must return purchase_required.' );

// T2: `deny` stored directly in CoreSettings::OPTION (B-NEW-8: no external filter registered!).
$GLOBALS['mw_test_options'][ CoreSettings::OPTION ] = array( 'membership_absent_behavior' => 'deny' );
$mw_dec_t2 = $mw_t_absent_engine->decide( $mw_t_absent_rel_mem, $mw_t_guest );
mw_assert_same( false, $mw_dec_t2->is_allowed(), 'T2 & B-NEW-8: membership + stored deny setting must deny without needing manual filter.' );
mw_assert_same( 'membership_provider_absent', $mw_dec_t2->reason(), 'T2: membership + deny must return membership_provider_absent.' );

// T3, T5, T6: `open_download` stored directly in CoreSettings::OPTION.
$GLOBALS['mw_test_options'][ CoreSettings::OPTION ] = CoreSettings::sanitize( array( 'membership_absent_behavior' => 'open_download' ) );
mw_assert_same( 'open_download', $GLOBALS['mw_test_options'][ CoreSettings::OPTION ]['membership_absent_behavior'], 'Settings::sanitize must accept open_download.' );
$mw_dec_t3 = $mw_t_absent_engine->decide( $mw_t_absent_rel_mem, $mw_t_guest );
$mw_dec_t5 = $mw_t_absent_engine->decide( $mw_t_absent_rel_hybrid, $mw_t_guest );
mw_assert_same( true, $mw_dec_t3->is_allowed() && 'membership_absent_open_download' === $mw_dec_t3->reason(), 'T3: membership + open_download must allow with membership_absent_open_download.' );
mw_assert_same( true, $mw_dec_t5->is_allowed() && 'membership_absent_open_download' === $mw_dec_t5->reason(), 'T5: purchase_or_membership + open_download must open download even when product is linked.' );
mw_assert_same( false, $mw_t_absent_engine->decide( $mw_t_absent_rel_paid, $mw_t_guest )->is_allowed(), 'T6a: open_download must NEVER open purchase-only releases.' );
mw_assert_same( false, $mw_t_absent_engine->decide( $mw_t_absent_rel_rest, $mw_t_guest )->is_allowed(), 'T6b: open_download must NEVER open restricted releases.' );
mw_assert_same( false, $mw_t_absent_engine->decide( $mw_t_absent_rel_login, $mw_t_guest )->is_allowed(), 'T6c: open_download must NEVER bypass logged_in requirement for guests.' );

// --- T7–T14: DirectLinkUrlValidator, DeliveryRouter, DirectLinkProvider, Streamability & Quotas ---
$GLOBALS['mw_test_options'][ CoreSettings::OPTION ] = array(
	'membership_absent_behavior' => 'open_download',
	'download_token_ttl'         => 300,
	'stream_token_ttl'           => 900,
	'download_daily_quota'       => 2,
	'direct_link_allowed_hosts'  => "cdn.example.com\n*.r2.dev",
);

// T10 & T11: Validator SSRF, scheme, credentials, fragments, IPv6-mapped, NAT64, CGNAT, numeric IP & allowlist checks.
$mw_bad_urls = array(
	'http://cdn.example.com/song.mp3',
	'javascript:alert(1)',
	'file:///etc/passwd',
	'https://user:pass@cdn.example.com/song.mp3',
	'https://cdn.example.com/song.mp3#frag',
	'https://localhost/song.mp3',
	'https://127.0.0.1/song.mp3',
	'https://169.254.169.254/latest/meta-data',
	'https://100.64.0.1/song.mp3',
	'https://[::ffff:127.0.0.1]/song.mp3',
	'https://[64:ff9b::7f00:1]/song.mp3',
	'https://0x7f000001/song.mp3',
	'https://2130706433/song.mp3',
	'https://evil.example.org/song.mp3',
	'https://r2.dev/song.mp3',
);
foreach ( $mw_bad_urls as $mw_bad_url ) {
	mw_assert_same( false, \ManaCore\MusicWave\Core\Downloads\DirectLinkUrlValidator::validate( $mw_bad_url )['valid'], 'T10/T11: Malicious or unallowed direct URL must be rejected: ' . $mw_bad_url );
}
mw_assert_same( '', $mw_suite_schema->get( 'mw_download_asset_id' )->sanitize( 'DIRECT:http://cdn.example.com/song.mp3' ), 'T10: Uppercase DIRECT:http:// bypass must be rejected by MetaDefinition.' );
mw_assert_same(
	'direct:https://media.r2.dev/releases/track-01.flac',
	\ManaCore\MusicWave\Core\Downloads\DirectLinkAsset::sanitize_asset_id( 'https://media.r2.dev/releases/track-01.flac' ),
	'T11: Wildcard allowlist *.r2.dev must accept subdomain media.r2.dev.'
);

// T7, T8, T9, T12, T14: Mixed release (`direct:` + `vip:`) routed through DeliveryRouter WITHOUT VIP active (`NullDownloadProvider`).
$mw_t_direct_rel = 9610;
$GLOBALS['mw_test_types'][ $mw_t_direct_rel ]    = 'mw_release';
$GLOBALS['mw_test_statuses'][ $mw_t_direct_rel ] = 'publish';
$GLOBALS['mw_test_posts'][ $mw_t_direct_rel ]    = (object) array( 'ID' => $mw_t_direct_rel, 'post_type' => 'mw_release', 'post_status' => 'publish', 'post_title' => 'تک‌آهنگ مستقیم' );
$GLOBALS['mw_test_meta'][ $mw_t_direct_rel ]     = array(
	'mw_access_mode'     => 'membership',
	'mw_download_assets' => array(
		array(
			'key'       => 'direct-lossless',
			'label'     => 'FLAC Direct',
			'asset_id'  => 'direct:https://cdn.example.com/vault/stream-endpoint?id=77',
			'format'    => 'flac',
			'file_name' => 'track-77.flac',
		),
		array(
			'key'      => 'vip-only',
			'label'    => 'VIP Vault WAV',
			'asset_id' => 'vip:0123456789abcdef0123456789abcdef',
			'format'   => 'wav',
		),
	),
);

mw_assert_same(
	true,
	\ManaCore\MusicWave\Core\Downloads\ReleaseDownloadCatalog::is_asset_streamable( $GLOBALS['mw_test_meta'][ $mw_t_direct_rel ]['mw_download_assets'][0] ),
	'T12: Extensionless direct: URL with explicit streamable format=flac must be recognized as streamable.'
);

$mw_router_no_vip   = new \ManaCore\MusicWave\Core\Downloads\DeliveryRouter(
	new \ManaCore\MusicWave\Core\Downloads\NullDownloadProvider(),
	new \ManaCore\MusicWave\Core\Downloads\DirectLinkProvider()
);
$mw_direct_tokens   = new DownloadTokenService( '0123456789abcdef0123456789abcdef0123456789abcdef' );
$mw_direct_resolver = new DownloadResolver(
	$mw_suite_repo,
	$mw_t_absent_engine,
	$mw_direct_tokens,
	new TransientReplayStore(),
	$mw_router_no_vip
);
$mw_direct_routes   = new DownloadRoutes(
	$mw_direct_resolver,
	new DownloadRateLimiter( 50, 60 )
);

$GLOBALS['mw_test_current_user'] = 701;
\ManaCore\MusicWave\Core\Downloads\RedirectResponse::reset_last_redirect();

// T7: Issue & deliver `direct-lossless` without VIP active -> 302 redirect via RedirectResponse!
$mw_d_issue_resp = $mw_direct_routes->issue( new WP_REST_Request( array( 'id' => $mw_t_direct_rel, 'quality' => 'direct-lossless' ) ) );
mw_assert_same( true, $mw_d_issue_resp instanceof WP_REST_Response && 200 === $mw_d_issue_resp->get_status(), 'T7: Direct asset must issue 200 ticket even when VIP provider is absent and membership_absent_behavior=open_download.' );
$mw_d_ticket     = $mw_d_issue_resp->get_data();
$mw_d_deliv_resp = $mw_direct_routes->download( new WP_REST_Request( array( 'id' => $mw_t_direct_rel, 'token' => $mw_d_ticket['token'], 'nonce' => $mw_d_ticket['nonce'] ) ) );
mw_assert_same( true, $mw_d_deliv_resp instanceof WP_REST_Response && 204 === $mw_d_deliv_resp->get_status(), 'T7: Direct asset delivery in test harness must succeed.' );
$mw_last_redir   = \ManaCore\MusicWave\Core\Downloads\RedirectResponse::last_redirect();
mw_assert_same( 'https://cdn.example.com/vault/stream-endpoint?id=77', is_array( $mw_last_redir ) ? $mw_last_redir['url'] : '', 'T7: DirectLinkProvider must redirect to exact validated HTTPS URL.' );
mw_assert_same( 'no-referrer', is_array( $mw_last_redir ) ? ( $mw_last_redir['headers']['Referrer-Policy'] ?? '' ) : '', 'T7: RedirectResponse must send Referrer-Policy: no-referrer.' );
mw_assert_same( 'nosniff', is_array( $mw_last_redir ) ? ( $mw_last_redir['headers']['X-Content-Type-Options'] ?? '' ) : '', 'T7b: RedirectResponse must send X-Content-Type-Options: nosniff.' );

// T8: Replay of the same one-time ticket must be denied (`403`).
$mw_d_replay_resp = $mw_direct_routes->download( new WP_REST_Request( array( 'id' => $mw_t_direct_rel, 'token' => $mw_d_ticket['token'], 'nonce' => $mw_d_ticket['nonce'] ) ) );
mw_assert_same( true, $mw_d_replay_resp instanceof WP_Error && 403 === ( $mw_d_replay_resp->get_error_data()['status'] ?? 0 ), 'T8: Replaying a consumed direct-link ticket must fail with 403.' );

// T9: Sibling `vip:` asset on the SAME release without VIP active must return 503 `provider_unavailable` at BOTH issue and download time.
$mw_vip_issue_resp = $mw_direct_routes->issue( new WP_REST_Request( array( 'id' => $mw_t_direct_rel, 'quality' => 'vip-only' ) ) );
mw_assert_same( true, $mw_vip_issue_resp instanceof WP_Error && 503 === ( $mw_vip_issue_resp->get_error_data()['status'] ?? 0 ), 'T9a: Protected vip: asset without VIP provider must fail closed with HTTP 503 at issue time.' );
$mw_vip_dl_nonce   = wp_create_nonce( 'music_wave_download_' . $mw_t_direct_rel );
$mw_pre_issued_vip = $mw_direct_tokens->issue( $mw_t_direct_rel, 701, 300, $mw_vip_dl_nonce, 'vip-only', 'download' );
$mw_vip_deliv_resp = $mw_direct_routes->download( new WP_REST_Request( array( 'id' => $mw_t_direct_rel, 'token' => $mw_pre_issued_vip, 'nonce' => $mw_vip_dl_nonce ) ) );
mw_assert_same( true, $mw_vip_deliv_resp instanceof WP_Error && 503 === ( $mw_vip_deliv_resp->get_error_data()['status'] ?? 0 ), 'T9b: Protected vip: asset without VIP provider must also fail closed with HTTP 503 at download time.' );

// T12: Stream issuance & delivery on extensionless `direct:` URL with `format=flac` must succeed.
\ManaCore\MusicWave\Core\Downloads\RedirectResponse::reset_last_redirect();
$mw_s_issue_resp = $mw_direct_routes->issue( new WP_REST_Request( array( 'id' => $mw_t_direct_rel, 'quality' => 'direct-lossless', 'purpose' => 'stream' ) ) );
mw_assert_same( true, $mw_s_issue_resp instanceof WP_REST_Response && 200 === $mw_s_issue_resp->get_status(), 'T12: Stream ticket issuance must succeed for direct: asset with format=flac.' );
$mw_s_ticket     = $mw_s_issue_resp->get_data();
$mw_s_deliv_resp = $mw_direct_routes->stream( new WP_REST_Request( array( 'id' => $mw_t_direct_rel, 'token' => $mw_s_ticket['token'], 'nonce' => $mw_s_ticket['nonce'] ) ) );
mw_assert_same( true, $mw_s_deliv_resp instanceof WP_REST_Response && 204 === $mw_s_deliv_resp->get_status(), 'T12: Stream delivery must redirect cleanly via DirectLinkProvider.' );

// T14: Persistent DB daily quota survives transient eviction (`download_daily_quota = 2`).
$mw_d2_issue  = $mw_direct_routes->issue( new WP_REST_Request( array( 'id' => $mw_t_direct_rel, 'quality' => 'direct-lossless' ) ) );
$mw_d2_ticket = $mw_d2_issue->get_data();
$mw_d2_deliv  = $mw_direct_routes->download( new WP_REST_Request( array( 'id' => $mw_t_direct_rel, 'token' => $mw_d2_ticket['token'], 'nonce' => $mw_d2_ticket['nonce'] ) ) );
mw_assert_same( true, $mw_d2_deliv instanceof WP_REST_Response && 204 === $mw_d2_deliv->get_status(), 'T14: Second download within daily quota=2 must succeed.' );

// Flush all transients to simulate object-cache eviction; DB option `mw_dl_quota_*` must still enforce quota=2!
$GLOBALS['mw_test_transients'] = array();
$mw_d3_issue                   = $mw_direct_routes->issue( new WP_REST_Request( array( 'id' => $mw_t_direct_rel, 'quality' => 'direct-lossless' ) ) );
$mw_d3_ticket                  = $mw_d3_issue->get_data();
$mw_d3_deliv                   = $mw_direct_routes->download( new WP_REST_Request( array( 'id' => $mw_t_direct_rel, 'token' => $mw_d3_ticket['token'], 'nonce' => $mw_d3_ticket['nonce'] ) ) );
mw_assert_same( true, $mw_d3_deliv instanceof WP_Error && 'mw_download_quota_exceeded' === $mw_d3_deliv->get_error_code(), 'T14: Persistent DB quota must block 3rd download even after transient cache flush.' );

// T11b: Runtime allowlist revocation blocks already-saved `direct:` URL at BOTH issue and delivery time (`403`).
$GLOBALS['mw_test_options'][ CoreSettings::OPTION ]['download_daily_quota'] = 0;
$mw_pre_rev_issue  = $mw_direct_routes->issue( new WP_REST_Request( array( 'id' => $mw_t_direct_rel, 'quality' => 'direct-lossless' ) ) );
$mw_pre_rev_ticket = $mw_pre_rev_issue->get_data();
$GLOBALS['mw_test_options'][ CoreSettings::OPTION ]['direct_link_allowed_hosts'] = 'other-cdn.example.com';
$mw_rev_issue      = $mw_direct_routes->issue( new WP_REST_Request( array( 'id' => $mw_t_direct_rel, 'quality' => 'direct-lossless' ) ) );
mw_assert_same( true, $mw_rev_issue instanceof WP_Error && 403 === ( $mw_rev_issue->get_error_data()['status'] ?? 0 ), 'T11b-issue: Revoking host from direct_link_allowed_hosts must block new ticket issuance with 403.' );
$mw_rev_deliv      = $mw_direct_routes->download( new WP_REST_Request( array( 'id' => $mw_t_direct_rel, 'token' => $mw_pre_rev_ticket['token'], 'nonce' => $mw_pre_rev_ticket['nonce'] ) ) );
mw_assert_same( true, $mw_rev_deliv instanceof WP_Error && 403 === ( $mw_rev_deliv->get_error_data()['status'] ?? 0 ), 'T11b-deliver: Revoking host from direct_link_allowed_hosts must also block pre-issued tickets with 403.' );

// T13: DirectLinkPermission & DownloadAssetRoutes authoring gates (`403` unauthorized, `400` bad URL, `200` unchanged relabel).
$GLOBALS['mw_test_options'][ CoreSettings::OPTION ]['direct_link_allowed_hosts'] = 'cdn.example.com';
$mw_asset_routes = new \ManaCore\MusicWave\Core\Downloads\DownloadAssetRoutes( $mw_suite_repo );
$mw_prev_test_caps                   = $GLOBALS['mw_test_capabilities'] ?? array();
$GLOBALS['mw_test_current_user']     = 702;
$GLOBALS['mw_test_capabilities']     = array( 'edit_posts', 'edit_post' );
$mw_unauth_direct_save               = $mw_asset_routes->update(
	new WP_REST_Request(
		array(
			'id'     => $mw_t_direct_rel,
			'assets' => array(
				array(
					'key'      => 'new-direct',
					'label'    => 'MP3 320',
					'asset_id' => 'direct:https://cdn.example.com/new-song.mp3',
				),
			),
		)
	)
);
mw_assert_same( true, $mw_unauth_direct_save instanceof WP_Error && 403 === ( $mw_unauth_direct_save->get_error_data()['status'] ?? 0 ), 'T13a: User without manage_mw_protected_assets must get 403 when assigning a new direct: URL.' );

$mw_relabel_unchanged = $mw_asset_routes->update(
	new WP_REST_Request(
		array(
			'id'     => $mw_t_direct_rel,
			'assets' => array(
				array(
					'key'       => 'direct-lossless',
					'label'     => 'FLAC 24-bit Master',
					'asset_id'  => 'direct:https://cdn.example.com/vault/stream-endpoint?id=77',
					'format'    => 'flac',
					'file_name' => 'track-77.flac',
				),
			),
		)
	)
);
mw_assert_same( true, $mw_relabel_unchanged instanceof WP_REST_Response && 200 === $mw_relabel_unchanged->get_status(), 'T13b: Editor relabeling an unchanged existing direct: asset must succeed with 200.' );

$GLOBALS['mw_test_capabilities'] = array( 'edit_posts', 'edit_post', 'manage_mw_protected_assets' );
$mw_bad_direct_save              = $mw_asset_routes->update(
	new WP_REST_Request(
		array(
			'id'     => $mw_t_direct_rel,
			'assets' => array(
				array(
					'key'      => 'bad-http',
					'label'    => 'Insecure HTTP',
					'asset_id' => 'direct:http://cdn.example.com/plain.mp3',
				),
			),
		)
	)
);
mw_assert_same( true, $mw_bad_direct_save instanceof WP_Error && 400 === ( $mw_bad_direct_save->get_error_data()['status'] ?? 0 ), 'T13c: Authorized admin submitting invalid direct:http:// URL must receive 400 validation error.' );

// --- T15–T19: RemoteRedirectProvider `vip:<hash>` / `local:` Resolution & Keyring Rotation ---
$wpdb->mw_enable_activity_table = true;
$wpdb->query(
	'CREATE TABLE IF NOT EXISTS wp_mw_vip_assets (
		asset_key TEXT PRIMARY KEY,
		path_hash TEXT UNIQUE NOT NULL,
		relative_path TEXT NOT NULL,
		file_size INTEGER NOT NULL DEFAULT 0,
		checksum TEXT NOT NULL DEFAULT "",
		mime TEXT NOT NULL DEFAULT "",
		registered_at INTEGER NOT NULL DEFAULT 0
	)'
);
$mw_reg_tmp                     = sys_get_temp_dir() . '/mw-vip-remote-reg-' . uniqid();
@mkdir( $mw_reg_tmp, 0755, true );
file_put_contents( $mw_reg_tmp . '/song name.flac', 'FLACDATA' );
$mw_remote_storage = new ProtectedAssetStorage();
$mw_reg_vip_id     = (string) $mw_remote_storage->registry()->register( 'albums/2026/song name.flac', $mw_reg_tmp . '/song name.flac' );
mw_assert_same( true, 0 === strpos( $mw_reg_vip_id, 'vip:' ), 'ProtectedAssetRegistry::register must return opaque vip:<32hex> identifier.' );

// T18: Multi-key rotation in RemoteSigningKeyring (`active` + `retired`, `autoload=false`, `hash_equals`).
\ManaCore\MusicWave\Vip\Security\RemoteSigningKeyring::clear_all();
$mw_secret_v1 = str_repeat( 'a1', 20 ); // 40 chars
$mw_secret_v2 = str_repeat( 'b2', 20 ); // 40 chars
$mw_saved_k1  = VipSettingsPage::sanitize_settings(
	array(
		'delivery_provider'     => 'remote_redirect',
		'remote_base_url'       => 'https://cdn.example.com/protected',
		'remote_path_prefix'    => 'audio',
		'remote_signing_secret' => $mw_secret_v1,
		'remote_key_id'         => 'k2026v1',
	)
);
mw_assert_same( '', $mw_saved_k1['remote_signing_secret'], 'T18a: Autoloaded music_wave_vip_settings option must NOT store raw remote_signing_secret once saved into keyring.' );
$GLOBALS['mw_test_options'][ VipSettings::OPTION ] = $mw_saved_k1;
mw_assert_same( $mw_secret_v1, VipSettings::all()['remote_signing_secret'], 'T18b: VipSettings::all() must transparently resolve active secret from non-autoloaded keyring.' );

$mw_remote_v1 = \ManaCore\MusicWave\Vip\ProviderFactory::download_provider( $mw_remote_storage );
mw_assert_same( true, $mw_remote_v1 instanceof \ManaCore\MusicWave\Vip\RemoteRedirectProvider, 'ProviderFactory must construct RemoteRedirectProvider with registry.' );
$mw_sample_claims = new DownloadTokenClaims( $mw_t_direct_rel, 701, time() + 120, 'tok-rem-1', 'nonce-rem-1', 'vip-only', 'download' );

// T15: Registered `vip:<32hex>` resolves to `albums/2026/song%20name.flac` with ZERO `vip%3A`!
$mw_url_v1 = $mw_remote_v1->create_url( $mw_reg_vip_id, $mw_sample_claims, false );
mw_assert_same( true, 0 === strpos( $mw_url_v1, 'https://cdn.example.com/protected/audio/albums/2026/song%20name.flac?' ), 'T15: RemoteRedirectProvider must map vip:<hash> to registered relative_path.' );
mw_assert_same( false, strpos( $mw_url_v1, 'vip%3A' ), 'T15: RemoteRedirectProvider must NEVER emit vip%3A in signed URLs.' );

// T16 & T17: Unknown `vip:` fails closed (`''`), `local:` strips prefix cleanly.
mw_assert_same( '', $mw_remote_v1->create_url( 'vip:ffffffffffffffffffffffffffffffff', $mw_sample_claims, false ), 'T16: Unregistered vip:<hash> must fail closed with empty URL.' );
mw_assert_same( '', $mw_remote_v1->create_url( 'direct:https://cdn.example.com/a.mp3', $mw_sample_claims, false ), 'T16b: direct: scheme passed directly to RemoteRedirectProvider must fail closed.' );
$mw_local_stripped_url = $mw_remote_v1->create_url( 'local:singles/track-02.mp3', $mw_sample_claims, false );
mw_assert_same( true, 0 === strpos( $mw_local_stripped_url, 'https://cdn.example.com/protected/audio/singles/track-02.mp3?' ) && false === strpos( $mw_local_stripped_url, 'local%3A' ), 'T17: RemoteRedirectProvider must strip local: prefix cleanly.' );

// T18c: Rotate to v2 key (`k2026v2`) while keeping `k2026v1` retired; BOTH v1 and v2 URLs must verify until retired keys are purged!
$mw_saved_k2 = VipSettingsPage::sanitize_settings(
	array(
		'delivery_provider'     => 'remote_redirect',
		'remote_base_url'       => 'https://cdn.example.com/protected',
		'remote_path_prefix'    => 'audio',
		'remote_signing_secret' => $mw_secret_v2,
		'remote_key_id'         => 'k2026v2',
	)
);
$GLOBALS['mw_test_options'][ VipSettings::OPTION ] = $mw_saved_k2;
$mw_remote_v2 = new \ManaCore\MusicWave\Vip\RemoteRedirectProvider( VipSettings::all(), $mw_remote_storage->registry() );
$mw_url_v2    = $mw_remote_v2->create_url( $mw_reg_vip_id, $mw_sample_claims, false );

$mw_parse_signed = static function ( string $signed ): array {
	$parts = explode( '?', $signed, 2 );
	$query = array();
	parse_str( $parts[1] ?? '', $query );
	return array(
		'unsigned'  => $parts[0],
		'expires'   => (int) ( $query['expires'] ?? 0 ),
		'signature' => (string) ( $query['signature'] ?? '' ),
		'mode'      => (string) ( $query['mode'] ?? '' ),
		'kid'       => (string) ( $query['kid'] ?? '' ),
	);
};
$mw_p1 = $mw_parse_signed( $mw_url_v1 );
$mw_p2 = $mw_parse_signed( $mw_url_v2 );
mw_assert_same( true, \ManaCore\MusicWave\Vip\Security\RemoteSigningKeyring::verify_signature( $mw_p2['unsigned'], $mw_p2['expires'], $mw_p2['mode'], $mw_p2['signature'], $mw_p2['kid'] ), 'T18c: Active rotated key k2026v2 signature must verify.' );
mw_assert_same( true, \ManaCore\MusicWave\Vip\Security\RemoteSigningKeyring::verify_signature( $mw_p1['unsigned'], $mw_p1['expires'], $mw_p1['mode'], $mw_p1['signature'], $mw_p1['kid'] ), 'T18d: Retired key k2026v1 signature must remain verifiable during rotation grace period.' );
mw_assert_same( false, \ManaCore\MusicWave\Vip\Security\RemoteSigningKeyring::verify_signature( $mw_p2['unsigned'], $mw_p2['expires'], 'stream', $mw_p2['signature'], $mw_p2['kid'] ), 'T18e: Tampering with mode parameter must fail HMAC verification.' );
\ManaCore\MusicWave\Vip\Security\RemoteSigningKeyring::purge_retired_keys();
mw_assert_same( false, \ManaCore\MusicWave\Vip\Security\RemoteSigningKeyring::verify_signature( $mw_p1['unsigned'], $mw_p1['expires'], $mw_p1['mode'], $mw_p1['signature'], $mw_p1['kid'] ), 'T18f: Purging retired keys must immediately revoke old key k2026v1.' );

@unlink( $mw_reg_tmp . '/song name.flac' );
@rmdir( $mw_reg_tmp );

// --- T21–T24: DeliveryCapabilities, Admin Link Integrity, SQL Mode Counts & Benchmark ---
unset( $GLOBALS['mw_test_filters']['music_wave_download_provider'] );
$mw_caps_direct = \ManaCore\MusicWave\Core\Support\DeliveryCapabilities::describe( new \ManaCore\MusicWave\Core\Downloads\NullDownloadProvider() );
mw_assert_same( 'direct_only', $mw_caps_direct['mode'], 'T21a: DeliveryCapabilities must report direct_only when no protected provider is active.' );
mw_assert_same( false, $mw_caps_direct['has_protected_provider'], 'T21b: has_protected_provider must be false in direct_only mode.' );
mw_assert_same( true, $mw_caps_direct['supports_direct_links'], 'T21c: supports_direct_links must always be true in Core.' );

$mw_vip_settings_src = (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-vip/src/SettingsPage.php' );
mw_assert_same( false, strpos( $mw_vip_settings_src, 'page=music-wave-bulk-access' ), 'T22: Dead music-wave-bulk-access link must be completely removed from VIP SettingsPage.' );
mw_assert_same( true, false !== strpos( $mw_vip_settings_src, 'page=music-wave-settings&tab=access' ), 'T22b: VIP SettingsPage must point bulk access button to page=music-wave-settings&tab=access.' );

// T23: DatabaseReplayStore cleanup batching + BulkAccessManager cached mode_counts().
$wpdb->mw_enable_activity_table = true;
$wpdb->posts                    = 'wp_posts';
$wpdb->postmeta                 = 'wp_postmeta';
$wpdb->options                  = 'wp_options';
$wpdb->query( 'CREATE TABLE IF NOT EXISTS wp_posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT)' );
$wpdb->query( 'DELETE FROM wp_posts' );
$wpdb->query( 'DELETE FROM wp_postmeta' );
$wpdb->query( "INSERT INTO wp_posts (ID, post_type, post_status) VALUES (1, 'mw_release', 'publish'), (2, 'mw_release', 'publish'), (3, 'mw_release', 'draft')" );
$wpdb->query( "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (1, 'mw_access_mode', 'membership'), (2, 'mw_access_mode', 'purchase_or_membership')" );
BulkAccessManager::invalidate_counts_cache();
$mw_bulk_mgr    = new BulkAccessManager( $mw_suite_repo, $mw_suite_mapper );
$mw_sql_counts  = $mw_bulk_mgr->mode_counts();
mw_assert_same( 1, $mw_sql_counts['membership'], 'T23a: SQL mode_counts() must count membership release accurately.' );
mw_assert_same( 1, $mw_sql_counts['purchase_or_membership'], 'T23b: SQL mode_counts() must count purchase_or_membership release accurately.' );
mw_assert_same( 1, $mw_sql_counts['public'], 'T23c: SQL mode_counts() must default release without mw_access_mode row to public.' );
mw_assert_same( true, is_array( get_transient( BulkAccessManager::COUNTS_TRANSIENT ) ), 'T23d: mode_counts() must cache result in transient.' );
BulkAccessManager::invalidate_counts_cache();
mw_assert_same( false, get_transient( BulkAccessManager::COUNTS_TRANSIENT ), 'T23e: invalidate_counts_cache() must clear cached mode counts.' );

$wpdb->query( 'DELETE FROM wp_options' );
$wpdb->query( "INSERT INTO wp_options (option_name, option_value) VALUES ('mw_dl_quota_old_20200101', '3'), ('mw_dl_quota_keep_" . gmdate( 'Ymd' ) . "', '1')" );
$GLOBALS['mw_test_options']['mw_dl_quota_old_20200101']               = 3;
$GLOBALS['mw_test_options'][ 'mw_dl_quota_keep_' . gmdate( 'Ymd' ) ] = 1;
( new \ManaCore\MusicWave\Core\Downloads\DatabaseReplayStore() )->cleanup();
mw_assert_same( false, isset( $GLOBALS['mw_test_options']['mw_dl_quota_old_20200101'] ), 'T14b: DatabaseReplayStore::cleanup() must purge stale daily quota options from previous days.' );
mw_assert_same( true, isset( $GLOBALS['mw_test_options'][ 'mw_dl_quota_keep_' . gmdate( 'Ymd' ) ] ), 'T14c: DatabaseReplayStore::cleanup() must preserve today quota option.' );

// T24: Performance micro-benchmark (1,000 iterations of validator + access policy + router capability check).
$mw_bench_start = microtime( true );
for ( $mw_i = 0; $mw_i < 1000; ++$mw_i ) {
	\ManaCore\MusicWave\Core\Downloads\DirectLinkUrlValidator::validate( 'https://cdn.example.com/audio/track-' . $mw_i . '.flac', array( 'cdn.example.com', '*.r2.dev' ) );
	$mw_t_absent_engine->decide( $mw_t_absent_rel_hybrid, $mw_t_guest );
	$mw_router_no_vip->can_deliver_asset( 'direct:https://cdn.example.com/audio/track-' . $mw_i . '.flac' );
}
$mw_bench_ms = ( microtime( true ) - $mw_bench_start ) * 1000.0;
mw_assert_same( true, $mw_bench_ms < 2000.0, 'T24: 1,000 combined validator + policy + router iterations must complete well under 2,000ms (actual: ' . round( $mw_bench_ms, 2 ) . 'ms).' );

// Final cleanup of Section 7 test state.
\ManaCore\MusicWave\Vip\Security\RemoteSigningKeyring::clear_all();
\ManaCore\MusicWave\Core\Downloads\RedirectResponse::reset_last_redirect();
$wpdb->query( 'DROP TABLE IF EXISTS wp_posts' );
$wpdb->query(
	'CREATE TABLE IF NOT EXISTS wp_mw_user_activity (
		user_id INTEGER NOT NULL,
		release_id INTEGER NOT NULL,
		event TEXT NOT NULL,
		position INTEGER NOT NULL DEFAULT 0,
		updated_at INTEGER NOT NULL DEFAULT 0,
		PRIMARY KEY (user_id, release_id, event)
	)'
);
unset( $wpdb->posts );
$wpdb->mw_enable_activity_table                     = $mw_prev_wpdb_enabled;
$GLOBALS['mw_test_current_user']                    = 0;
$GLOBALS['mw_test_capabilities']                    = $mw_prev_test_caps;
$GLOBALS['mw_test_options'][ CoreSettings::OPTION ] = array();
$GLOBALS['mw_test_options'][ VipSettings::OPTION ]  = array();
unset(
	$GLOBALS['mw_test_posts'][ $mw_t_absent_rel_mem ],
	$GLOBALS['mw_test_posts'][ $mw_t_absent_rel_hybrid ],
	$GLOBALS['mw_test_posts'][ $mw_t_absent_rel_paid ],
	$GLOBALS['mw_test_posts'][ $mw_t_absent_rel_rest ],
	$GLOBALS['mw_test_posts'][ $mw_t_absent_rel_login ],
	$GLOBALS['mw_test_posts'][ $mw_t_direct_rel ],
	$GLOBALS['mw_test_types'][ $mw_t_absent_rel_mem ],
	$GLOBALS['mw_test_types'][ $mw_t_absent_rel_hybrid ],
	$GLOBALS['mw_test_types'][ $mw_t_absent_rel_paid ],
	$GLOBALS['mw_test_types'][ $mw_t_absent_rel_rest ],
	$GLOBALS['mw_test_types'][ $mw_t_absent_rel_login ],
	$GLOBALS['mw_test_types'][ $mw_t_direct_rel ]
);

echo "VIP, Access & Protected Delivery suite OK.\n";
