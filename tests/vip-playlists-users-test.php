<?php
/**
 * Integration tests for:
 * 1. VIP plan catalog, default seeder (`PlanProducts`), `.mw-membership__plan` cards (`MembershipPresenter`),
 *    and single product page details (`VipProductPagePresenter`).
 * 2. Public & unlisted playlist share links (`PlaylistRepository::public_url()` & `PlaylistBlocks`)
 *    for logged-out guests and private access enforcement.
 * 3. WordPress `users.php` & `user-edit.php` VIP management (`VipPlans` manual grants/expiry/audit log,
 *    `UserActivityInspector`, `UsersListIntegration`, and `UserProfilePanel`).
 *
 * @package ManaCore\MusicWave\Tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	require __DIR__ . '/run.php';
	return;
}

use ManaCore\MusicWave\Core\Access\AccessPolicyEngine;
use ManaCore\MusicWave\Core\Account\MembershipPresenter;
use ManaCore\MusicWave\Core\Blocks\PlaylistBlocks;
use ManaCore\MusicWave\Core\Commerce\PurchaseChecker;
use ManaCore\MusicWave\Core\Infrastructure\WordPressReleaseRepository;
use ManaCore\MusicWave\Core\Playlists\PlaylistFormHandler;
use ManaCore\MusicWave\Core\Schema\ReleaseMetaSchema;
use ManaCore\MusicWave\Core\Playlists\PlaylistLimits;
use ManaCore\MusicWave\Core\Playlists\PlaylistRepository;
use ManaCore\MusicWave\Vip\PlanProducts;
use ManaCore\MusicWave\Vip\Plans\VipPlanCatalog;
use ManaCore\MusicWave\Vip\Plans\VipProductPagePresenter;
use ManaCore\MusicWave\Vip\Users\UserActivityInspector;
use ManaCore\MusicWave\Vip\Users\UserProfilePanel;
use ManaCore\MusicWave\Vip\Users\UsersListIntegration;
use ManaCore\MusicWave\Vip\VipPlans;

// =========================================================================
// 1. VIP Plan Catalog, Default Seeder, `.mw-membership__plan` & Product Page
// =========================================================================

$mw_presets = VipPlanCatalog::presets();
mw_assert_same( array( 'vip-1m', 'vip-6m', 'vip-12m' ), array_column( $mw_presets, 'level' ), 'VipPlanCatalog must provide 1m, 6m, and 12m presets.' );

foreach ( $mw_presets as $mw_preset ) {
	mw_assert_same( true, count( $mw_preset['features'] ) >= 4, 'Preset ' . $mw_preset['level'] . ' must define rich feature bullets.' );
	mw_assert_same( true, '' !== $mw_preset['summary'], 'Preset ' . $mw_preset['level'] . ' must define a summary excerpt.' );
	mw_assert_same( true, false !== strpos( VipPlanCatalog::build_preset_html( $mw_preset ), '<ul>' ), 'Preset ' . $mw_preset['level'] . ' must build structured HTML product description.' );
	mw_assert_same( true, false !== strpos( VipPlanCatalog::icon_svg( $mw_preset['icon'] ), '<svg' ), 'VipPlanCatalog::icon_svg must return clean SVG markup.' );
}

$GLOBALS['mw_test_options']['music_wave_vip_settings'] = array(
	'module_enabled'     => 'enabled',
	'membership_sources' => array( 'woocommerce_plans' ),
	'plan_rows'          => '',
);

$mw_seed_result = PlanProducts::create_defaults();
mw_assert_same( 3, $mw_seed_result['created'], 'PlanProducts::create_defaults() must create all 3 default WooCommerce plan products.' );
mw_assert_same( 3, count( $mw_seed_result['levels'] ), 'PlanProducts::create_defaults() must map all 3 level:product pairs.' );

$mw_first_pair     = explode( ':', $mw_seed_result['levels'][0] );
$mw_first_plan_pid = (int) ( $mw_first_pair[1] ?? 0 );
$mw_seeded_product = get_post( $mw_first_plan_pid );
mw_assert_same( true, $mw_seeded_product instanceof WP_Post && false !== strpos( $mw_seeded_product->post_content, '<ul>' ), 'Default plan product must receive structured HTML capabilities in post_content.' );
mw_assert_same( true, $mw_seeded_product instanceof WP_Post && '' !== $mw_seeded_product->post_excerpt, 'Default plan product must receive short description in post_excerpt.' );

// Verify merchant custom copy is NEVER overwritten when create_defaults() runs again.
wp_update_post(
	array(
		'ID'           => $mw_first_plan_pid,
		'post_content' => '<p>متن اختصاصی فروشگاه برای اشتراک ویژه با امکانات کامل استودیویی.</p><ul><li>پشتیبانی اختصاصی ۲۴ ساعته</li><li>کیفیت مستر FLAC</li></ul>',
		'post_excerpt' => 'خلاصه اختصاصی مدیر فروشگاه برای طرح یک‌ماهه.',
	)
);
$mw_second_seed = PlanProducts::create_defaults();
mw_assert_same( 0, $mw_second_seed['created'], 'Re-running create_defaults() must reuse existing mapped products without duplicates.' );
mw_assert_same( 'خلاصه اختصاصی مدیر فروشگاه برای طرح یک‌ماهه.', get_post( $mw_first_plan_pid )->post_excerpt, 'Re-running create_defaults() must preserve custom merchant short description.' );
mw_assert_same( true, false !== strpos( get_post( $mw_first_plan_pid )->post_content, 'پشتیبانی اختصاصی ۲۴ ساعته' ), 'Re-running create_defaults() must preserve custom merchant product description.' );

// Connect `PlanProducts::products_data()` with `MembershipPresenter` `.mw-membership__plan` rendering.
$mw_prev_current_user            = $GLOBALS['mw_test_current_user'] ?? 0;
$mw_vip_test_user                = 88;
$GLOBALS['mw_test_current_user'] = $mw_vip_test_user;
$GLOBALS['mw_test_users'][ $mw_vip_test_user ] = (object) array(
	'ID'              => $mw_vip_test_user,
	'user_email'      => 'vip88@example.test',
	'user_login'      => 'vip88',
	'display_name'    => 'کاربر ویژه ۸۸',
	'user_registered' => '2026-01-10 12:00:00',
);
$mw_live_plans = VipPlans::from_settings();
$mw_live_plans->grant_manual( $mw_vip_test_user, 'vip-1m', 30, 1, 'تست فعال‌سازی اولیه' );

$mw_panel_payload = PlanProducts::products_data( $mw_vip_test_user );
mw_assert_same( true, $mw_panel_payload['module_enabled'], 'PlanProducts::products_data() must report active module.' );
mw_assert_same( 3, count( $mw_panel_payload['plans'] ), 'PlanProducts::products_data() must expose all 3 configured plan products.' );
mw_assert_same( 'متن اختصاصی فروشگاه برای اشتراک ویژه با امکانات کامل استودیویی.', $mw_panel_payload['plans'][0]['description'], 'Plan card description must directly reflect product page توضیحات محصول.' );
mw_assert_same( true, in_array( 'پشتیبانی اختصاصی ۲۴ ساعته', $mw_panel_payload['plans'][0]['features'], true ), 'Plan card features must extract <li> items directly from product description.' );
mw_assert_same( true, ! empty( $mw_panel_payload['plans'][0]['is_active'] ), 'Active user grant must mark matching plan card as is_active.' );

$GLOBALS['mw_test_filters']['music_wave_vip_membership_panel'] = $mw_panel_payload;
$mw_release_repo         = new WordPressReleaseRepository( new ReleaseMetaSchema() );
$mw_membership_presenter = new MembershipPresenter(
	new AccessPolicyEngine(
		$mw_release_repo,
		new PurchaseChecker( $mw_release_repo )
	)
);
$mw_rendered_panel       = $mw_membership_presenter->render_panel( $mw_vip_test_user );
mw_assert_same( true, false !== strpos( $mw_rendered_panel, 'mw-membership__plan--active' ), 'Membership panel must highlight active user plan with mw-membership__plan--active.' );
mw_assert_same( true, false !== strpos( $mw_rendered_panel, 'متن اختصاصی فروشگاه برای اشتراک ویژه با امکانات کامل استودیویی.' ), 'Membership plan card must display product description inside .mw-membership__plan.' );
mw_assert_same( true, false !== strpos( $mw_rendered_panel, 'پشتیبانی اختصاصی ۲۴ ساعته' ), 'Membership plan card must render extracted product capability items.' );
mw_assert_same( true, false !== strpos( $mw_rendered_panel, 'mw-membership__plan-icon' ) && false !== strpos( $mw_rendered_panel, '<svg' ), 'Membership plan card must display visual SVG tier icon.' );
mw_assert_same( true, false !== strpos( $mw_rendered_panel, 'mw-membership__plan-link' ), 'Membership plan card must link directly to the product page details.' );
unset( $GLOBALS['mw_test_filters']['music_wave_vip_membership_panel'] );

// Single product page VIP details integration (`VipProductPagePresenter`).
$GLOBALS['mw_test_current_user']  = $mw_vip_test_user;
$GLOBALS['mw_test_singular_type'] = 'product';
$GLOBALS['mw_test_current_post']  = $mw_first_plan_pid;
$mw_product_presenter             = new VipProductPagePresenter();
$mw_summary_enhanced              = $mw_product_presenter->enhance_product_blocks( '<div class="wp-block-woocommerce-product-summary">خلاصه</div>', array( 'blockName' => 'woocommerce/product-summary', 'attrs' => array() ) );
$mw_hero_enhanced                 = $mw_product_presenter->enhance_product_blocks( '<div class="mw-product-hero">هدر محصول</div>', array( 'blockName' => 'core/group', 'attrs' => array( 'className' => 'mw-product-hero' ) ) );
mw_assert_same( true, false !== strpos( $mw_summary_enhanced, 'mw-product-vip-meta' ), 'Single VIP product summary block must prepend VIP duration, icon, and active status bar.' );
mw_assert_same( true, false !== strpos( $mw_hero_enhanced, 'mw-product-details-panel' ) && false !== strpos( $mw_hero_enhanced, 'پشتیبانی اختصاصی ۲۴ ساعته' ), 'Single VIP product hero must append full توضیحات محصول panel and sibling plans.' );
unset( $GLOBALS['mw_test_singular_type'], $GLOBALS['mw_test_current_post'] );

// =========================================================================
// 2. Public Playlist Share Link & Guest Access (`PlaylistBlocks`)
// =========================================================================

$mw_playlist_store = new TestPlaylistStore();
$mw_playlist_repo  = new PlaylistRepository( $mw_playlist_store );
$mw_playlist_forms = new PlaylistFormHandler( $mw_playlist_repo );
$mw_playlist_ui    = new PlaylistBlocks( $mw_playlist_repo, $mw_playlist_forms );

$GLOBALS['mw_test_types'][1]    = 'mw_release';
$GLOBALS['mw_test_statuses'][1] = 'publish';
$GLOBALS['mw_test_titles'][1]   = 'قطعه آزمایشی موج موسیقی';

$GLOBALS['mw_test_current_user'] = $mw_vip_test_user;
$mw_pl_id                        = $mw_playlist_repo->create( $mw_vip_test_user, 'منتخب تابستان' );
$mw_playlist_repo->add_item( $mw_vip_test_user, $mw_pl_id, 1 );

// Private playlist: no public URL and no owner share box.
mw_assert_same( '', $mw_playlist_repo->public_url( $mw_pl_id, PlaylistRepository::VISIBILITY_PRIVATE ), 'Private playlist must return empty public share URL.' );

// Switch to PUBLIC (`عمومی`): owner gets `/playlists/?mw-playlist={id}` (NOT `/account/`) even without share token!
$mw_playlist_repo->update( $mw_vip_test_user, $mw_pl_id, array( 'visibility' => PlaylistRepository::VISIBILITY_PUBLIC ) );
$mw_updated_public = $mw_playlist_store->find( $mw_pl_id );
$mw_public_url     = $mw_playlist_repo->public_url( $mw_pl_id, PlaylistRepository::VISIBILITY_PUBLIC, (string) ( $mw_updated_public['share_token'] ?? '' ) );
mw_assert_same( true, false !== strpos( $mw_public_url, 'mw-playlist=' . $mw_pl_id ) && false === strpos( $mw_public_url, '/account/' ), 'Public playlist share link must target public playlists page rather than login-gated /account/.' );

$mw_public_view = $mw_playlist_repo->view( $mw_pl_id, $mw_vip_test_user );
mw_assert_same( $mw_public_url, $mw_public_view['share_url'] ?? '', 'PlaylistRepository::view() must include canonical share_url for live JS updates.' );

$mw_owner_manager_html = $mw_playlist_ui->render_manager( array() );
mw_assert_same( true, false !== strpos( $mw_owner_manager_html, 'data-mw-share-box="' . $mw_pl_id . '"' ) && false !== strpos( $mw_owner_manager_html, 'پیوند را به‌اشتراک بگذارید' ), 'Owner playlist manager must display share link box when playlist is public.' );

// Logged-out GUEST (`user_id = 0`) opens `?mw-playlist={id}` -> renders full `.mw-shared-playlist` view cleanly!
$GLOBALS['mw_test_current_user'] = 0;
$_GET['mw-playlist']             = (string) $mw_pl_id;
$mw_guest_public_html            = $mw_playlist_ui->render_public( array() );
mw_assert_same( true, false !== strpos( $mw_guest_public_html, 'mw-shared-playlist' ) && false === strpos( $mw_guest_public_html, 'mw-shared-playlist--denied' ), 'Logged-out guest must view public shared playlist without login wall.' );
mw_assert_same( true, false !== strpos( $mw_guest_public_html, 'منتخب تابستان' ) && false !== strpos( $mw_guest_public_html, 'قطعه آزمایشی موج موسیقی' ), 'Guest shared playlist page must display playlist title and release tracks.' );

// Switching playlist back to PRIVATE (`خصوصی`) immediately denies guest access cleanly.
$mw_playlist_repo->update( $mw_vip_test_user, $mw_pl_id, array( 'visibility' => PlaylistRepository::VISIBILITY_PRIVATE ) );
$mw_guest_private_html = $mw_playlist_ui->render_public( array() );
mw_assert_same( true, false !== strpos( $mw_guest_private_html, 'mw-shared-playlist--denied' ) && false === strpos( $mw_guest_private_html, 'قطعه آزمایشی موج موسیقی' ), 'Private playlist must deny guest access without leaking private tracks.' );

// Switching playlist to UNLISTED (`غیرفهرست‌شده`) denies invalid token and allows valid share token for guests.
$mw_playlist_repo->update( $mw_vip_test_user, $mw_pl_id, array( 'visibility' => PlaylistRepository::VISIBILITY_UNLISTED ) );
$mw_updated_unlisted   = $mw_playlist_store->find( $mw_pl_id );
$_GET['mw-share']      = 'wrong-token';
$mw_guest_bad_tok_html = $mw_playlist_ui->render_public( array() );
mw_assert_same( true, false !== strpos( $mw_guest_bad_tok_html, 'mw-shared-playlist--denied' ), 'Unlisted playlist must reject invalid share token.' );

$_GET['mw-share']       = (string) $mw_updated_unlisted['share_token'];
$mw_guest_good_tok_html = $mw_playlist_ui->render_public( array() );
mw_assert_same( true, false !== strpos( $mw_guest_good_tok_html, 'منتخب تابستان' ) && false === strpos( $mw_guest_good_tok_html, 'mw-shared-playlist--denied' ), 'Unlisted playlist with valid mw-share token must render cleanly for guests.' );
unset( $_GET['mw-playlist'], $_GET['mw-share'] );

// =========================================================================
// 3. WordPress `users.php` & `user-edit.php` Integration (`MusicWave VIP`)
// =========================================================================

$GLOBALS['mw_test_current_user'] = 1;
$GLOBALS['mw_test_capabilities'] = array( 'manage_options', 'list_users', 'edit_users', 'edit_user' );

$mw_inspector     = new UserActivityInspector( $mw_live_plans );
$mw_users_list    = new UsersListIntegration( $mw_live_plans, $mw_inspector );
$mw_profile_panel = new UserProfilePanel( $mw_live_plans, $mw_users_list, $mw_inspector );

// Manual extend + audit log + effective expiry index sync.
$mw_before_exp = (int) get_user_meta( $mw_vip_test_user, 'mw_vip_effective_until', true );
$mw_live_plans->extend_active_for_user( $mw_vip_test_user, 15, 1, 'تمدید دستی ۱۵ روزه' );
$mw_after_exp = (int) get_user_meta( $mw_vip_test_user, 'mw_vip_effective_until', true );
mw_assert_same( true, $mw_after_exp >= $mw_before_exp + ( 14 * DAY_IN_SECONDS ), 'Extending VIP grant must add duration onto existing active grant.' );
mw_assert_same( true, count( $mw_live_plans->user_history( $mw_vip_test_user ) ) >= 2, 'Manual VIP mutations must record per-user audit history entries.' );

// `users.php` columns, filter views, bulk actions, and zero nested `<form>` tags inside table cells.
$mw_registered_cols = $mw_users_list->register_columns( array( 'cb' => '<input type="checkbox">', 'username' => 'نام کاربری', 'posts' => 'نوشته‌ها' ) );
mw_assert_same( true, isset( $mw_registered_cols['mw_vip_status'], $mw_registered_cols['mw_vip_activity'] ), 'UsersListIntegration must register VIP status and user activity columns.' );

$mw_views = $mw_users_list->register_status_views( array( 'all' => '<a href="users.php">همه</a>' ) );
mw_assert_same( true, isset( $mw_views['vip_active'], $mw_views['vip_expiring'], $mw_views['vip_expired'], $mw_views['vip_none'] ), 'UsersListIntegration must register active, expiring, expired, and non-VIP filter views.' );

$mw_bulk_actions = $mw_users_list->register_bulk_actions( array() );
mw_assert_same( true, count( $mw_bulk_actions ) >= 4, 'UsersListIntegration must expose bulk grant and bulk revoke actions.' );

$mw_status_cell_html   = $mw_users_list->render_column( '', 'mw_vip_status', $mw_vip_test_user );
$mw_activity_cell_html = $mw_users_list->render_column( '', 'mw_vip_activity', $mw_vip_test_user );
mw_assert_same( true, false !== strpos( $mw_status_cell_html, 'data-mw-vip-user-cell="' . $mw_vip_test_user . '"' ), 'VIP status column must render inline quick-management container.' );
mw_assert_same( false, strpos( $mw_status_cell_html, '<form' ), 'VIP status cell must NEVER nest <form> tags inside WP_Users_List_Table.' );
mw_assert_same( true, false !== strpos( $mw_activity_cell_html, '_customer_user=' . $mw_vip_test_user ), 'User activity column must link directly to user WooCommerce orders/payments.' );

// `user-edit.php` panel render + POST save mutation (entry revocation + admin note -> expired index state).
ob_start();
$mw_profile_panel->render_panel( $GLOBALS['mw_test_users'][ $mw_vip_test_user ] );
$mw_profile_html = (string) ob_get_clean();
mw_assert_same( true, false !== strpos( $mw_profile_html, 'mw-vip-profile-panel' ) && false !== strpos( $mw_profile_html, '_customer_user=' . $mw_vip_test_user ), 'User profile panel must display VIP status, user activity, and WooCommerce order links.' );
mw_assert_same( false, strpos( $mw_profile_html, '<form' ), 'User profile panel must NEVER nest <form> tags inside #your-profile.' );

$mw_existing_grant_keys = array_keys( $mw_live_plans->all_grants_for_user( $mw_vip_test_user ) );
$_POST                  = array(
	UserProfilePanel::NONCE_NAME => wp_create_nonce( UserProfilePanel::NONCE_ACTION ),
	'mw_vip_admin_note'          => 'لغو آزمایشی توسط مدیر',
	'mw_vip_existing_keys'       => $mw_existing_grant_keys,
	'mw_vip_entry_revoke'        => array_fill_keys( $mw_existing_grant_keys, '1' ),
);
$mw_profile_panel->handle_save( $mw_vip_test_user );
$_POST = array();
mw_assert_same( array(), $mw_live_plans->active_levels( $mw_vip_test_user ), 'UserProfilePanel::handle_save() revocation must immediately clear active VIP levels.' );
mw_assert_same( 'لغو آزمایشی توسط مدیر', (string) get_user_meta( $mw_vip_test_user, VipPlans::ADMIN_NOTE_META, true ), 'UserProfilePanel::handle_save() must save internal admin VIP note.' );
mw_assert_same( '1', (string) get_user_meta( $mw_vip_test_user, 'mw_vip_ever_granted', true ), 'Revoked VIP user must retain mw_vip_ever_granted=1 for expired/former VIP filter views.' );

// =========================================================================
// 4. `MusicWave VIP` Settings Page Rendering, `fa_IR` Hook Suffixes & Tabs
// =========================================================================

$mw_vip_settings_page = new \ManaCore\MusicWave\Vip\SettingsPage();
mw_assert_same( true, \ManaCore\MusicWave\Vip\SettingsPage::is_vip_settings_screen( '%d8%a7%d9%86%d8%aa%d8%b4%d8%a7%d8%b1%d9%87%d8%a7_page_music-wave-vip' ), 'VIP settings screen detector must match Persian fa_IR translated parent menu hook suffix.' );
mw_assert_same( true, \ManaCore\MusicWave\Vip\SettingsPage::is_vip_settings_screen( 'mw_release_page_music-wave-vip' ), 'VIP settings screen detector must match English parent menu hook suffix.' );
mw_assert_same( true, \ManaCore\MusicWave\Vip\SettingsPage::is_vip_settings_screen( 'settings_page_music-wave-vip' ), 'VIP settings screen detector must match Settings fallback menu hook suffix.' );
mw_assert_same( false, \ManaCore\MusicWave\Vip\SettingsPage::is_vip_settings_screen( 'edit.php' ), 'VIP settings screen detector must reject unrelated admin screens.' );
mw_assert_same( true, \ManaCore\MusicWave\Core\Admin\AdminAssets::is_submenu_screen( 'music-wave-settings', '%d8%a7%d9%86%d8%aa%d8%b4%d8%a7%d8%b1%d9%87%d8%a7_page_music-wave-settings' ), 'Core AdminAssets::is_submenu_screen must match Persian fa_IR translated parent hook suffix.' );
mw_assert_same( false, \ManaCore\MusicWave\Core\Admin\AdminAssets::is_submenu_screen( 'music-wave-settings', 'index.php' ), 'Core AdminAssets::is_submenu_screen must reject unrelated screens.' );

if ( ! function_exists( 'wp_nonce_url' ) ) {
	function wp_nonce_url( string $actionurl, $action = -1, string $name = '_wpnonce' ): string {
		return add_query_arg( array( $name => wp_create_nonce( (string) $action ) ), $actionurl );
	}
}
if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( int $post_id = 0 ): string {
		return 'https://example.test/wp-admin/post.php?post=' . $post_id . '&action=edit';
	}
}
if ( ! function_exists( 'wp_is_writable' ) ) {
	function wp_is_writable( string $path ): bool {
		return is_writable( $path );
	}
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $target ): bool {
		return is_dir( $target ) || @mkdir( $target, 0755, true );
	}
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true, bool $display = true ): string {
		$res = (string) $checked === (string) $current ? ' checked="checked"' : '';
		if ( $display ) {
			echo $res; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return $res;
	}
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $selected, $current = true, bool $display = true ): string {
		$res = (string) $selected === (string) $current ? ' selected="selected"' : '';
		if ( $display ) {
			echo $res; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return $res;
	}
}
if ( ! function_exists( 'disabled' ) ) {
	function disabled( $disabled, $current = true, bool $display = true ): string {
		$res = (string) $disabled === (string) $current ? ' disabled="disabled"' : '';
		if ( $display ) {
			echo $res; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return $res;
	}
}
if ( ! function_exists( 'settings_errors' ) ) {
	function settings_errors( string $setting = '' ): void {
		unset( $setting );
	}
}
if ( ! function_exists( 'settings_fields' ) ) {
	function settings_fields( string $option_group ): void {
		echo '<input type="hidden" name="option_page" value="' . esc_attr( $option_group ) . '">';
	}
}
if ( ! function_exists( 'submit_button' ) ) {
	function submit_button( string $text = 'Save', string $type = 'primary', string $name = 'submit', bool $wrap = true ): void {
		unset( $type, $wrap );
		echo '<button type="submit" name="' . esc_attr( $name ) . '" class="button button-primary">' . esc_html( $text ) . '</button>';
	}
}

// Render full `SettingsPage::render()` output across all 4 tabs under PHP 8 strict_types=1.
ob_start();
$mw_vip_settings_page->render();
$mw_vip_settings_html = (string) ob_get_clean();
mw_assert_same( true, false !== strpos( $mw_vip_settings_html, 'class="mwvip-tabs"' ), 'SettingsPage::render() must render .mwvip-tabs navigation cleanly without TypeError.' );
mw_assert_same( true, false !== strpos( $mw_vip_settings_html, 'data-panel="general"' ) && false !== strpos( $mw_vip_settings_html, 'data-panel="delivery"' ) && false !== strpos( $mw_vip_settings_html, 'data-panel="membership"' ) && false !== strpos( $mw_vip_settings_html, 'data-panel="advanced"' ), 'SettingsPage::render() must render all 4 tab panels cleanly.' );
mw_assert_same( true, false !== strpos( $mw_vip_settings_html, 'mwvip-plan-icon' ), 'SettingsPage::render() must render plan card SVG icons cleanly with extra class.' );
mw_assert_same( false, strpos( $mw_vip_settings_html, 'onclick=' ), 'SettingsPage::render() must remain 100% CSP-clean with zero inline event handlers.' );

// =========================================================================
// 5. Shared Playlist UI Cleanup & Multi-Type Recursive Queue Expansion
// =========================================================================

// Verify `.mw-shared-playlist` does NOT show «همه فهرست‌های پخش عمومی» button.
$mw_playlist_repo->update( $mw_vip_test_user, $mw_pl_id, array( 'visibility' => PlaylistRepository::VISIBILITY_PUBLIC ) );
$GLOBALS['mw_test_current_user'] = 0;
$_GET['mw-playlist']             = (string) $mw_pl_id;
$mw_clean_shared_html            = $mw_playlist_ui->render_public( array() );
mw_assert_same( false, strpos( $mw_clean_shared_html, 'همه فهرست‌های پخش عمومی' ), '.mw-shared-playlist must NOT render the removed «همه فهرست‌های پخش عمومی» button.' );
mw_assert_same( true, false !== strpos( $mw_clean_shared_html, 'data-mw-playlist-play' ) && false !== strpos( $mw_clean_shared_html, 'mw-playlists__play-icon' ), '.mw-shared-playlist must render functional Play All button with icon toggle target.' );
mw_assert_same( true, false !== strpos( $mw_clean_shared_html, 'data-mw-playlist-id="' . $mw_pl_id . '"' ), '.mw-shared-playlist track play buttons must carry data-mw-playlist-id.' );
unset( $_GET['mw-playlist'] );

// Create multi-type catalog entries:
// - 501: Single public preview track
// - 502: Album containing child tracks 503 (preview) + 504 (VIP umbrella `mw_vip` full stream + preview fallback)
// - 505: Nested collection pointing to Album 502 AND itself (cycle test: 505 -> 502, 505 -> 505)
$GLOBALS['mw_test_types'][501]    = 'mw_release';
$GLOBALS['mw_test_statuses'][501] = 'publish';
$GLOBALS['mw_test_titles'][501]   = 'تک‌آهنگ آزاد ۵۰۱';
$mw_release_repo->update( 501, 'mw_access_mode', 'public' );
$mw_release_repo->update( 501, 'mw_preview_url', 'https://cdn.example.test/501.mp3' );

$GLOBALS['mw_test_types'][503]    = 'mw_release';
$GLOBALS['mw_test_statuses'][503] = 'publish';
$GLOBALS['mw_test_titles'][503]   = 'قطعه اول آلبوم ۵۰۳';
$mw_release_repo->update( 503, 'mw_access_mode', 'public' );
$mw_release_repo->update( 503, 'mw_preview_url', 'https://cdn.example.test/503.mp3' );

$GLOBALS['mw_test_types'][504]    = 'mw_release';
$GLOBALS['mw_test_statuses'][504] = 'publish';
$GLOBALS['mw_test_titles'][504]   = 'قطعه ویژه مشترکین ۵۰۴';
$mw_release_repo->update( 504, 'mw_access_mode', 'membership' );
$mw_release_repo->update( 504, 'mw_membership_levels', array( 'mw_vip' ) );
$mw_release_repo->update( 504, 'mw_preview_url', 'https://cdn.example.test/504-preview.mp3' );
$mw_release_repo->update(
	504,
	'mw_download_assets',
	array(
		array(
			'key'      => 'high',
			'label'    => 'FLAC Master',
			'asset_id' => 'protected/504-master.flac',
		),
	)
);

$GLOBALS['mw_test_types'][502]    = 'mw_release';
$GLOBALS['mw_test_statuses'][502] = 'publish';
$GLOBALS['mw_test_titles'][502]   = 'آلبوم چندقطعه‌ای ۵۰۲';
$mw_release_repo->update( 502, 'mw_collection_items', array(
	array( 'release_id' => 503, 'position' => 1, 'role' => 'track' ),
	array( 'release_id' => 504, 'position' => 2, 'role' => 'track' ),
) );

$GLOBALS['mw_test_types'][505]    = 'mw_release';
$GLOBALS['mw_test_statuses'][505] = 'publish';
$GLOBALS['mw_test_titles'][505]   = 'مجموعه تودرتو ۵۰۵';
$mw_release_repo->update( 505, 'mw_collection_items', array(
	array( 'release_id' => 502, 'position' => 1, 'role' => 'track' ),
	array( 'release_id' => 505, 'position' => 2, 'role' => 'track' ),
) );

$mw_playability = new \ManaCore\MusicWave\Core\Blocks\Concerns\ReleasePlayability( $mw_release_repo );
mw_assert_same( true, $mw_playability->is_playable( 502 ), 'Album with no own preview URL must still report is_playable=true via playable child tracks.' );
mw_assert_same( true, $mw_playability->is_playable( 505 ), 'Nested collection with self-reference cycle must safely report is_playable=true without recursion loop.' );

// Wire real `MembershipAdapter` + `VipPlans` (even with `promote_vip_role = '0'`) to test umbrella `mw_vip`!
$GLOBALS['mw_test_options']['music_wave_vip_settings']['promote_vip_role'] = '0';
$mw_umbrella_plans   = VipPlans::from_settings();
$mw_umbrella_plans->grant_manual( $mw_vip_test_user, 'vip-6m', 180, 1, 'اشتراک شش‌ماهه برای تست دسترسی عمومی VIP' );
mw_assert_same( true, $mw_umbrella_plans->user_has_access( $mw_vip_test_user, 'mw_vip' ), 'Subscriber with active vip-6m grant must satisfy umbrella mw_vip check even when promote_vip_role is disabled.' );
mw_assert_same( true, $mw_umbrella_plans->user_has_access( $mw_vip_test_user, 'vip-6m' ), 'Subscriber with active vip-6m grant must satisfy exact vip-6m tier check.' );
mw_assert_same( false, $mw_umbrella_plans->user_has_access( $mw_vip_test_user, 'vip-12m' ), 'Subscriber with only vip-6m grant must NOT satisfy stricter vip-12m tier check.' );

$mw_vip_provider = new \ManaCore\MusicWave\Vip\ConfigurableMembershipProvider(
	array( 'woocommerce_plans' ),
	$mw_umbrella_plans
);
$mw_full_policy = new AccessPolicyEngine(
	$mw_release_repo,
	new PurchaseChecker( $mw_release_repo ),
	$mw_vip_provider
);

// Multi-type playlist containing [501 (track), 505 (nested collection -> album 502 -> tracks 503, 504)].
$mw_mix_pl = $mw_playlist_repo->create( $mw_vip_test_user, 'پلی‌لیست ترکیبی همه انواع' );
$mw_playlist_repo->add_item( $mw_vip_test_user, $mw_mix_pl, 501 );
$mw_playlist_repo->add_item( $mw_vip_test_user, $mw_mix_pl, 505 );
$mw_playlist_repo->update( $mw_vip_test_user, $mw_mix_pl, array( 'visibility' => PlaylistRepository::VISIBILITY_PUBLIC ) );

$mw_pl_routes = new \ManaCore\MusicWave\Core\Playlists\PlaylistRoutes(
	$mw_playlist_repo,
	$mw_full_policy,
	$mw_release_repo
);

// Guest (`user_id = 0`) hits `/playlists/{id}/playback-queue` -> gets all 3 expanded playable leaf tracks (501, 503, 504 preview)!
$GLOBALS['mw_test_current_user'] = 0;
$mw_guest_req = new WP_REST_Request( array( 'id' => $mw_mix_pl ) );
$mw_guest_res  = $mw_pl_routes->playback_queue( $mw_guest_req );
$mw_guest_data = $mw_guest_res->get_data();
mw_assert_same( array( 501, 503, 504 ), array_column( $mw_guest_data['tracks'], 'releaseId' ), 'Playlist playback queue must recursively expand single tracks, nested collections, and albums in order without cycles.' );
mw_assert_same( array( 501, 505, 505 ), array_column( $mw_guest_data['tracks'], 'rootId' ), 'Expanded tracks must preserve top-level playlist entry release ID in rootId so row highlighting matches.' );
mw_assert_same( false, $mw_guest_data['tracks'][2]['full'], 'Guest must only receive preview stream on VIP track 504.' );

// Active `vip-6m` subscriber hits `/playlists/{id}/playback-queue` -> gets `full: true` + `quality: high` on track 504!
$GLOBALS['mw_test_current_user'] = $mw_vip_test_user;
$mw_vip_res  = $mw_pl_routes->playback_queue( $mw_guest_req );
$mw_vip_data = $mw_vip_res->get_data();
mw_assert_same( true, $mw_vip_data['tracks'][2]['full'], 'Active VIP subscriber must receive full protected stream access on track 504 inside playlist queue.' );
mw_assert_same( 'high', $mw_vip_data['tracks'][2]['quality'], 'Active VIP subscriber must receive high bitrate stream quality on protected asset track 504.' );

unset( $GLOBALS['mw_test_filters']['music_wave_membership_provider'] );

// =========================================================================
// 7. Persistent Background Navigation Default + Schema0150 Migration +
//    Upgraded Continue Listening (`ListeningRepository`, `ListeningRoutes`, `ListeningBlocks`)
// =========================================================================
delete_option( \ManaCore\MusicWave\Core\Support\Settings::OPTION );
mw_assert_same(
	'enabled',
	\ManaCore\MusicWave\Core\Support\Settings::get( 'persistent_navigation' ),
	'Persistent background navigation must be enabled by default.'
);

update_option(
	\ManaCore\MusicWave\Core\Support\Settings::OPTION,
	array_merge(
		\ManaCore\MusicWave\Core\Support\Settings::defaults(),
		array( 'persistent_navigation' => 'disabled' )
	)
);
( new \ManaCore\MusicWave\Core\Migrations\Schema0150() )->up();
mw_assert_same(
	'enabled',
	\ManaCore\MusicWave\Core\Support\Settings::get( 'persistent_navigation' ),
	'Schema0150 must upgrade legacy disabled persistent_navigation installs to enabled.'
);

global $wpdb;
if ( ! $wpdb instanceof wpdb ) {
	$wpdb = new wpdb();
}
$wpdb->mw_enable_activity_table = true;
$mw_preview_player_instance                          = new \ManaCore\MusicWave\Core\Blocks\PreviewPlayer( $mw_release_repo );
$GLOBALS['mw_test_filters']['music_wave_card_play_button'] = array( $mw_preview_player_instance, 'card_play_button' );
$mw_listening_repo                                   = new \ManaCore\MusicWave\Core\Listening\ListeningRepository();
$mw_listening_blocks                                 = new \ManaCore\MusicWave\Core\Blocks\ListeningBlocks( $mw_listening_repo );
$mw_listening_routes                                 = new \ManaCore\MusicWave\Core\Listening\ListeningRoutes( $mw_listening_repo );

// 7a. Guest state HTML renders clean local browser rail shell + clear button.
$GLOBALS['mw_test_current_user'] = 0;
$mw_listening_guest_html         = $mw_listening_blocks->render( array( 'limit' => 8 ) );
mw_assert_same( true, false !== strpos( $mw_listening_guest_html, 'mw-continue-listening--guest' ), 'Guest continue-listening block must carry mw-continue-listening--guest.' );
mw_assert_same( true, false !== strpos( $mw_listening_guest_html, 'data-mw-listening-guest-rail' ), 'Guest continue-listening block must render guest rail target.' );

// 7b. Logged-in pre-consent state HTML renders consent opt-in surface.
$mw_listen_user                  = 991;
$GLOBALS['mw_test_current_user'] = $mw_listen_user;
$mw_listening_consent_html       = $mw_listening_blocks->render( array( 'limit' => 8 ) );
mw_assert_same( true, false !== strpos( $mw_listening_consent_html, 'mw-continue-listening--consent' ), 'Pre-consent continue-listening block must carry mw-continue-listening--consent.' );
mw_assert_same( true, false !== strpos( $mw_listening_consent_html, 'data-mw-listening-consent' ), 'Pre-consent continue-listening block must render opt-in button.' );

// 7c. Consent REST route activates history + progress REST route records BOTH by `release_id` AND by `release_url`!
$mw_consent_res = $mw_listening_routes->consent( new WP_REST_Request( array( 'consent' => true ) ) );
mw_assert_same( true, $mw_consent_res->get_data()['consent'], 'Consent endpoint must persist opt-in flag.' );

// Record progress via `release_url` fallback (simulating `.mw-preview-button` which carries `data-preview-link` without `data-mw-release-id`)!
$mw_prog_url_res = $mw_listening_routes->progress(
	new WP_REST_Request(
		array(
			'release_id'  => 0,
			'release_url' => 'https://example.test/?p=501',
			'position'    => 142,
			'event'       => 'progress',
		)
	)
);
mw_assert_same( true, $mw_prog_url_res->get_data()['saved'], 'Progress endpoint must resolve release ID from release_url when release_id is 0.' );
mw_assert_same( 501, $mw_prog_url_res->get_data()['release_id'], 'Progress endpoint must resolve exact release ID 501 from release_url.' );

// Record progress on release 503 explicitly, then complete release 503 (`played`) -> stale `progress` row must graduate cleanly!
$mw_listening_routes->progress(
	new WP_REST_Request(
		array(
			'release_id' => 503,
			'position'   => 68,
			'event'      => 'progress',
		)
	)
);
$mw_listening_routes->progress(
	new WP_REST_Request(
		array(
			'release_id' => 503,
			'position'   => 0,
			'event'      => 'played',
		)
	)
);

// Record progress on a second release (501 + 504) so active continue shelf has 2 live in-progress tracks!
$mw_listening_routes->progress(
	new WP_REST_Request(
		array(
			'release_id' => 504,
			'position'   => 68,
			'event'      => 'progress',
		)
	)
);
mw_assert_same( 0, count( array_filter( $mw_listening_repo->recent( $mw_listen_user, \ManaCore\MusicWave\Core\Listening\ListeningRepository::EVENT_PROGRESS, 8 ), static function ( array $r ): bool { return 503 === (int) $r['release_id']; } ) ), 'Graduated played release 503 must be automatically purged from in-progress shelf.' );
mw_assert_same( 1, count( $mw_listening_repo->recent( $mw_listen_user, \ManaCore\MusicWave\Core\Listening\ListeningRepository::EVENT_PLAYED, 8 ) ), 'Completed release 503 must appear in played history.' );

$mw_continue_res  = $mw_listening_routes->continue_listening( new WP_REST_Request( array( 'per_page' => 8 ) ) );
$mw_continue_data = $mw_continue_res->get_data();
mw_assert_same( 2, count( $mw_continue_data['items'] ), 'Continue endpoint must return both active in-progress releases (501 & 504).' );
mw_assert_same( true, false !== strpos( $mw_continue_data['cards_html'], 'data-mw-resume-position="142"' ), 'Server-rendered continue cards_html must embed exact data-mw-resume-position.' );
mw_assert_same( true, false !== strpos( $mw_continue_data['cards_html'], 'data-mw-listening-remove="501"' ), 'Server-rendered continue cards_html must embed per-card remove button.' );

$mw_listening_active_html = $mw_listening_blocks->render( array( 'limit' => 8 ) );
mw_assert_same( true, false !== strpos( $mw_listening_active_html, 'data-mw-listening-items' ), 'Active continue-listening block must render live shelf items container.' );
mw_assert_same( true, false !== strpos( $mw_listening_active_html, 'data-mw-listening-clear' ), 'Active continue-listening block must render clear history button.' );
mw_assert_same( true, false !== strpos( $mw_listening_active_html, 'data-mw-listening-disable' ), 'Active continue-listening block must render turn-off history button.' );

// 7d. Single-item dismiss removes ONLY that release; clear_history removes all remaining items while keeping disable button accessible.
$mw_rm_res = $mw_listening_routes->remove_item( new WP_REST_Request( array( 'release_id' => 504 ) ) );
mw_assert_same( true, $mw_rm_res->get_data()['removed'], 'remove_item must remove rows for release 504.' );
mw_assert_same( 1, count( $mw_listening_repo->recent( $mw_listen_user, \ManaCore\MusicWave\Core\Listening\ListeningRepository::EVENT_PROGRESS, 8 ) ), 'Release 501 must remain untouched when 504 is removed.' );

$mw_clear_res = $mw_listening_routes->clear_history( new WP_REST_Request( array() ) );
mw_assert_same( true, $mw_clear_res->get_data()['cleared'], 'clear_history must clear all history rows.' );
mw_assert_same( 0, count( $mw_listening_repo->recent( $mw_listen_user, \ManaCore\MusicWave\Core\Listening\ListeningRepository::EVENT_PROGRESS, 8 ) ), 'History must be empty after clear_history.' );
$mw_listening_empty_after_clear = $mw_listening_blocks->render( array( 'limit' => 8 ) );
mw_assert_same( true, false !== strpos( $mw_listening_empty_after_clear, 'data-mw-listening-disable' ), 'Empty continue-listening block must retain turn-off history button.' );

// 7e. Turning OFF listening history per user (`consent: false`) AND sitewide (`listening_history = disabled`).
$mw_disable_res = $mw_listening_routes->consent( new WP_REST_Request( array( 'consent' => false ) ) );
mw_assert_same( false, $mw_disable_res->get_data()['consent'], 'Consent route must allow turning listening history OFF.' );
mw_assert_same( true, false !== strpos( $mw_listening_blocks->render( array( 'limit' => 8 ) ), 'mw-continue-listening--consent' ), 'Revoking consent must return block to opt-in state.' );

$mw_prev_settings = get_option( \ManaCore\MusicWave\Core\Support\Settings::OPTION, array() );
update_option( \ManaCore\MusicWave\Core\Support\Settings::OPTION, array_merge( is_array( $mw_prev_settings ) ? $mw_prev_settings : array(), array( 'listening_history' => 'disabled' ) ) );
mw_assert_same( false, \ManaCore\MusicWave\Core\Support\Settings::listening_enabled(), 'Settings::listening_enabled() must reflect disabled sitewide setting.' );
mw_assert_same( '', $mw_listening_blocks->render( array( 'limit' => 8 ) ), 'Disabled sitewide listening setting must suppress frontend continue-listening output.' );
$mw_blocked_optin = $mw_listening_routes->consent( new WP_REST_Request( array( 'consent' => true ) ) );
mw_assert_same( true, $mw_blocked_optin instanceof WP_Error && 'mw_listening_disabled' === $mw_blocked_optin->get_error_code(), 'Opt-in must be rejected while sitewide listening history is disabled.' );
update_option( \ManaCore\MusicWave\Core\Support\Settings::OPTION, $mw_prev_settings );
mw_assert_same( true, \ManaCore\MusicWave\Core\Support\Settings::listening_enabled(), 'Restoring settings must re-enable listening history.' );
unset( $GLOBALS['mw_test_filters']['music_wave_card_play_button'] );

// Verify public playlists browser (`/playlists/` without `?mw-playlist=`) never emits `.mw-shared-playlist` or `?mw-playlist=` links on «نمایش قطعه‌ها».
$GLOBALS['mw_test_current_user'] = 0;
unset( $_GET['mw-playlist'], $_GET['mw-share'] );
$mw_mix_public_browse_html = $mw_playlist_ui->render_public( array() );
mw_assert_same( false, strpos( $mw_mix_public_browse_html, 'mw-shared-playlist' ), 'Public playlists browse page without direct share query param must never render .mw-shared-playlist.' );
mw_assert_same( false, strpos( $mw_mix_public_browse_html, 'mw-playlist=' ), 'Public playlist cards and «نمایش قطعه‌ها» toggle must never link to ?mw-playlist=.' );
mw_assert_same( true, false !== strpos( $mw_mix_public_browse_html, '<button type="button" class="mw-public-playlists__toggle"' ), '«نمایش قطعه‌ها» must render as an inline disclosure <button type="button">.' );

// =========================================================================
// 8. Architectural Regression Suite: Pillars 1–5 (Dashboard Tabs, Volume,
//    Guest Playlist Disc Parity, Complete Release Details, Widget Seeding & Dedup)
// =========================================================================

// Pillar 1: AccountLibrary renders first tab active on server while preserving progressive enhancement.
if ( ! function_exists( 'wp_logout_url' ) ) {
	function wp_logout_url( $redirect = '' ) {
		return 'https://musicwave.test/wp-login.php?action=logout&redirect_to=' . rawurlencode( (string) $redirect );
	}
}
$GLOBALS['mw_test_current_user'] = $mw_listen_user;
$mw_pillar_policy                = new \ManaCore\MusicWave\Core\Access\AccessPolicyEngine(
	$mw_release_repo,
	new \ManaCore\MusicWave\Core\Commerce\PurchaseChecker( $mw_release_repo )
);
$mw_account_lib_service          = new \ManaCore\MusicWave\Core\Commerce\AccountLibrary(
	$mw_pillar_policy,
	$mw_release_repo,
	new \ManaCore\MusicWave\Core\Library\LibraryRepository()
);
$mw_dashboard_rendered_html      = $mw_account_lib_service->render_dashboard_block( array() );
mw_assert_same( 1, substr_count( $mw_dashboard_rendered_html, 'mw-user-dashboard__tab is-active' ), 'Account dashboard must mark the default first tab button active.' );
mw_assert_same( 1, substr_count( $mw_dashboard_rendered_html, 'aria-selected="true"' ), 'Account dashboard must set aria-selected="true" on exactly one initial tab.' );
mw_assert_same( true, false !== strpos( $mw_dashboard_rendered_html, 'data-mw-initial-tab="library"' ), 'Account dashboard must expose the resolved default initial tab key.' );

// Pillar 3: Guest `.mw-playlist-picker__trigger` circular icon disc parity with `LibraryButton`.
$GLOBALS['mw_test_current_user'] = 0;
$mw_guest_picker_html            = $mw_playlist_ui->render_picker( array( 'releaseId' => 501 ) );
$mw_guest_library_html           = \ManaCore\MusicWave\Core\Library\LibraryButton::markup(
	'release',
	501
);
mw_assert_same( true, false !== strpos( $mw_guest_picker_html, 'mw-playlist-picker__trigger mw-playlist-picker__trigger--guest' ), 'Guest add-to-playlist trigger must use .mw-playlist-picker__trigger--guest circular disc classes.' );
mw_assert_same( false, strpos( $mw_guest_picker_html, 'mw-playlist-picker__guest-cta' ), 'Legacy wide text-pill .mw-playlist-picker__guest-cta must never be rendered.' );
mw_assert_same( substr_count( $mw_guest_picker_html, '<span' ), substr_count( $mw_guest_picker_html, '</span>' ), 'Guest playlist picker HTML must have strictly balanced <span> tags with zero orphaned closing tags.' );
mw_assert_same( true, false !== strpos( $mw_guest_picker_html, '<svg' ) && false !== strpos( $mw_guest_picker_html, 'screen-reader-text' ), 'Guest playlist picker trigger must contain crisp vector icon + .screen-reader-text label matching LibraryButton.' );

$GLOBALS['mw_test_current_user'] = $mw_listen_user;
$mw_member_picker_html           = $mw_playlist_ui->render_picker( array( 'releaseId' => 501 ) );
mw_assert_same( substr_count( $mw_member_picker_html, '<span' ), substr_count( $mw_member_picker_html, '</span>' ), 'Authenticated playlist picker HTML must have strictly balanced <span> tags.' );

// Pillar 4: Complete «جزئیات انتشار MusicWave» inside `.mw-release-hero__specs` & removal of fake CSS badges.
$mw_release_page_css = (string) file_get_contents( dirname( __DIR__ ) . '/musicwave/assets/css/components/release-page.css' );
mw_assert_same( false, strpos( $mw_release_page_css, 'mw-release-hero__cover-stack::after' ), '.mw-release-hero__cover-stack::after must be completely removed from release-page.css.' );
mw_assert_same( false, strpos( $mw_release_page_css, 'content: "Lossless"' ), 'Hardcoded "Lossless" badge content must never exist in release-page.css.' );
mw_assert_same( false, strpos( $mw_release_page_css, 'content: "Hi-Res"' ), 'Hardcoded "Hi-Res" badge content must never exist in release-page.css.' );

$mw_single_release_tpl = (string) file_get_contents( dirname( __DIR__ ) . '/musicwave/templates/single-mw_release.html' );
mw_assert_same( false, strpos( $mw_single_release_tpl, 'wp:music-wave/access-panel' ), 'single-mw_release.html must delegate access-panel to WordPress sidebar widgets without hardcoded duplication.' );
mw_assert_same( 1, substr_count( $mw_single_release_tpl, 'wp:music-wave/release-meta' ), 'single-mw_release.html must render unified fullSpecs release-meta block inside .mw-release-hero__specs.' );

$mw_full_spec_post_id                             = 987;
$GLOBALS['mw_test_posts'][ $mw_full_spec_post_id ]    = (object) array( 'ID' => $mw_full_spec_post_id, 'post_type' => 'mw_release', 'post_status' => 'publish', 'post_title' => 'سمفونی شبانه' );
$GLOBALS['mw_test_types'][ $mw_full_spec_post_id ]    = 'mw_release';
$GLOBALS['mw_test_statuses'][ $mw_full_spec_post_id ] = 'publish';
$GLOBALS['mw_test_titles'][ $mw_full_spec_post_id ]   = 'سمفونی شبانه';
$GLOBALS['mw_test_meta'][ $mw_full_spec_post_id ]     = array(
	'mw_access_mode'     => 'public',
	'mw_catalog_number'  => 'MW-2026-99',
	'mw_album'           => 'آلبوم رؤیای شرقی',
	'mw_release_date'    => '2026-10-15',
	'mw_duration'        => 274,
	'mw_bpm'             => 118,
	'mw_musical_key'     => 'F#m',
	'mw_track_number'    => 3,
	'mw_total_tracks'    => 9,
	'mw_season_number'   => 2,
	'mw_episode_number'  => 7,
	'mw_isrc'            => 'IR-MW1-26-00099',
	'mw_language'        => 'فارسی',
	'mw_country'         => 'ایران',
	'mw_publisher'       => 'انتشارات موج موسیقی',
	'mw_barcode'         => '6260000098765',
	'mw_explicit'        => '1',
	'mw_download_assets' => array(
		array(
			'key'        => 'flac-24',
			'label'      => 'FLAC 24-bit',
			'asset_id'   => 'local:symphony/master.flac',
			'format'     => 'flac',
			'file_key'   => 'main-download',
			'file_label' => 'فایل اصلی',
		),
		array(
			'key'        => 'mp3-320',
			'label'      => 'MP3 320 kbps',
			'asset_id'   => 'local:symphony/master.mp3',
			'format'     => 'mp3',
			'file_key'   => 'main-download',
			'file_label' => 'فایل اصلی',
		),
	),
);

$mw_specs_blocks    = new \ManaCore\MusicWave\Core\Blocks\ReleaseBlocks( $mw_pillar_policy, $mw_release_repo );
$mw_full_specs_html = $mw_specs_blocks->render_meta(
	array(
		'releaseId'         => $mw_full_spec_post_id,
		'compact'           => true,
		'layout'            => 'grid',
		'showLabels'        => true,
		'showArtist'        => false,
		'showGenre'         => false,
		'showMood'          => false,
		'showLabel'         => false,
		'showReleaseType'   => false,
		'showTaxonomyChips' => false,
		'showLibraryButton' => false,
		'showActions'       => false,
		'fullSpecs'         => true,
	)
);
foreach ( array( 'MW-2026-99', 'آلبوم رؤیای شرقی', '2026-10-15', '4:34', '118', 'F#m', '3 / 9', 'فصل 2 · قسمت 7', 'IR-MW1-26-00099', 'فارسی', 'ایران', 'انتشارات موج موسیقی', '6260000098765', 'محتوای صریح' ) as $mw_expected_spec ) {
	mw_assert_same( true, false !== strpos( $mw_full_specs_html, $mw_expected_spec ), 'Full release specs block must display populated metadata field: ' . $mw_expected_spec );
	mw_assert_same( 1, substr_count( $mw_full_specs_html, $mw_expected_spec ), 'Full release specs field must render strictly once without duplication: ' . $mw_expected_spec );
}
// Audio quality was removed from the details block; files keep their labels in downloads only.
mw_assert_same( false, false !== strpos( $mw_full_specs_html, 'FLAC 24-bit' ), 'Release details block must not render audio quality labels.' );
mw_assert_same( false, false !== strpos( $mw_full_specs_html, 'MP3 320 kbps' ), 'Release details block must not render audio quality labels.' );

// Guard against duplicate `fullSpecs: true` blocks on the same release page:
$mw_full_specs_duplicate = $mw_specs_blocks->render_meta(
	array(
		'releaseId'         => $mw_full_spec_post_id,
		'compact'           => true,
		'layout'            => 'grid',
		'showLabels'        => true,
		'showTaxonomyChips' => false,
		'showLibraryButton' => false,
		'showActions'       => false,
		'fullSpecs'         => true,
	)
);
mw_assert_same( '', $mw_full_specs_duplicate, 'A second fullSpecs release-meta block on the same release page must return empty string.' );

// Verify `block.json` default for `fullSpecs` is `false` so archive cards and legacy single-fact
// blocks (`Man Delam Tange – Single` regression) NEVER repeat album/quality/barcode/publisher 4x!
$mw_meta_block_json = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/music-wave-core/blocks/release-meta/block.json' ), true );
mw_assert_same( false, $mw_meta_block_json['attributes']['fullSpecs']['default'] ?? null, 'release-meta block.json fullSpecs default must be false so cards and single-fact blocks stay compact.' );

$mw_single_date_only_html = ( new \ManaCore\MusicWave\Core\Blocks\ReleaseBlocks( $mw_pillar_policy, $mw_release_repo ) )->render_meta(
	array(
		'releaseId'         => $mw_full_spec_post_id,
		'showCatalogNumber' => false,
		'showReleaseDate'   => true,
		'showDuration'      => false,
		'showBpm'           => false,
		'showKey'           => false,
		'showArtist'        => false,
		'showGenre'         => false,
		'showMood'          => false,
		'showLabel'         => false,
		'showReleaseType'   => false,
		'showTaxonomyChips' => false,
		'showLibraryButton' => false,
		'showActions'       => false,
		'compact'           => true,
		'layout'            => 'inline',
		'showLabels'        => true,
		'fullSpecs'         => $mw_meta_block_json['attributes']['fullSpecs']['default'],
	)
);
mw_assert_same( 1, substr_count( $mw_single_date_only_html, '2026-10-15' ), 'Single-fact release date block must render the release date once.' );
mw_assert_same( false, strpos( $mw_single_date_only_html, 'آلبوم رؤیای شرقی' ), 'Single-fact release date block must never leak album/barcode/publisher fields.' );
mw_assert_same( false, strpos( $mw_single_date_only_html, '6260000098765' ), 'Single-fact release date block must never repeat barcode.' );

// Verify customized DB `wp_template` override for `single-mw_release` with legacy 4-block `.mw-release-hero__specs`
// AND legacy hardcoded `access-panel` in `.mw-release-stage__aside` upgrades automatically with zero duplication!
$mw_legacy_db_tpl = (object) array(
	'slug'    => 'single-mw_release',
	'source'  => 'custom',
	'content' => '<!-- wp:group {"metadata":{"name":"مشخصات انتشار"},"className":"mw-release-hero__specs","layout":{"type":"flex","flexWrap":"wrap"}} -->'
		. '<div class="wp-block-group mw-release-hero__specs">'
		. '<!-- wp:music-wave/release-meta {"showCatalogNumber":false,"showReleaseDate":true,"showDuration":false,"showBpm":false,"showKey":false,"compact":true,"layout":"inline","showLabels":true} /-->'
		. '<!-- wp:music-wave/release-meta {"showCatalogNumber":false,"showReleaseDate":false,"showDuration":true,"showBpm":false,"showKey":false,"compact":true,"layout":"inline","showLabels":true} /-->'
		. '<!-- wp:music-wave/release-meta {"showCatalogNumber":false,"showReleaseDate":false,"showDuration":false,"showBpm":true,"showKey":false,"compact":true,"layout":"inline","showLabels":true} /-->'
		. '<!-- wp:music-wave/release-meta {"showCatalogNumber":false,"showReleaseDate":false,"showDuration":false,"showBpm":false,"showKey":true,"compact":true,"layout":"inline","showLabels":true} /-->'
		. '</div><!-- /wp:group -->'
		. '<!-- wp:column {"width":"34%","className":"mw-release-stage__aside"} -->'
		. '<div class="wp-block-column mw-release-stage__aside" style="flex-basis:34%"><!-- wp:template-part {"slug":"sidebar","tagName":"aside"} /-->'
		. '<!-- wp:music-wave/release-credits {"layout":"list","showRole":true} /-->'
		. '<!-- wp:music-wave/access-panel /--></div><!-- /wp:column -->',
);
$mw_upgraded_tpl = ( new \ManaCore\MusicWave\Theme\Theme() )->templates()->normalize_block_template( $mw_legacy_db_tpl );
mw_assert_same( 1, substr_count( $mw_upgraded_tpl->content, 'wp:music-wave/release-meta' ), 'Upgraded custom DB single-mw_release template must collapse 4 legacy specs blocks into 1 unified block.' );
mw_assert_same( true, false !== strpos( $mw_upgraded_tpl->content, '"fullSpecs":true' ), 'Upgraded custom DB single-mw_release template must enable fullSpecs:true.' );
mw_assert_same( false, strpos( $mw_upgraded_tpl->content, 'wp:music-wave/access-panel' ), 'Upgraded custom DB single-mw_release template must strip hardcoded duplicate access-panel.' );
mw_assert_same( substr_count( $mw_upgraded_tpl->content, '<!-- wp:group' ), substr_count( $mw_upgraded_tpl->content, '<!-- /wp:group -->' ), 'Upgraded custom DB template must preserve strictly valid Gutenberg block comment pairs.' );

// Pillar 5: WordPress Widgets Area seeding (`WidgetAreaSeeder`), admin deletion respect,
// empty `<section class="widget ...">` shell cleanup, and single-instance deduplication.
delete_option( \ManaCore\MusicWave\Theme\Widgets\WidgetAreaSeeder::SEEDED_OPTION );
update_option( 'sidebars_widgets', array( 'wp_inactive_widgets' => array() ) );
update_option( 'widget_block', array( '_multiwidget' => 1 ) );

$mw_widget_seeder = new \ManaCore\MusicWave\Theme\Widgets\WidgetAreaSeeder();
$mw_widget_seeder->seed_once();

$mw_seeded_sidebars = get_option( 'sidebars_widgets', array() );
$mw_seeded_blocks   = get_option( 'widget_block', array() );
mw_assert_same( '1', get_option( \ManaCore\MusicWave\Theme\Widgets\WidgetAreaSeeder::SEEDED_OPTION ), 'WidgetAreaSeeder must persist one-time seeded option.' );
mw_assert_same( 2, count( $mw_seeded_sidebars['musicwave-sidebar'] ?? array() ), 'WidgetAreaSeeder must seed default continue-listening + access-panel widgets into musicwave-sidebar.' );

$mw_all_widget_contents = implode( "\n", array_column( array_filter( $mw_seeded_blocks, 'is_array' ), 'content' ) );
mw_assert_same( true, false !== strpos( $mw_all_widget_contents, 'wp:music-wave/continue-listening' ), 'musicwave-sidebar must contain seeded continue-listening block widget.' );
mw_assert_same( true, false !== strpos( $mw_all_widget_contents, 'wp:music-wave/access-panel' ), 'musicwave-sidebar must contain seeded access-panel block widget.' );

// Simulate admin deleting `access-panel` widget from `musicwave-sidebar` -> re-running `seed_once()` MUST NOT bring it back!
array_pop( $mw_seeded_sidebars['musicwave-sidebar'] );
update_option( 'sidebars_widgets', $mw_seeded_sidebars );
$mw_widget_seeder->seed_once();
$mw_after_delete_sidebars = get_option( 'sidebars_widgets', array() );
mw_assert_same( 1, count( $mw_after_delete_sidebars['musicwave-sidebar'] ), 'Deleted default widgets must NEVER be re-added automatically on subsequent requests.' );

// Single-instance deduplication across same-page renders of `access-panel` AND `continue-listening`.
$GLOBALS['mw_test_meta'][ $mw_full_spec_post_id ]['mw_access_mode'] = 'logged_in';
$mw_access_first                                                    = $mw_specs_blocks->render_access_panel( array( 'releaseId' => $mw_full_spec_post_id ) );
$mw_access_second                                                   = $mw_specs_blocks->render_access_panel( array( 'releaseId' => $mw_full_spec_post_id ) );
mw_assert_same( 1, substr_count( $mw_access_first . $mw_access_second, 'mw-access-panel__message' ), 'Granted .mw-access-panel__message must render strictly once per page even if block is placed in two areas.' );

$mw_dedup_listening = new \ManaCore\MusicWave\Core\Blocks\ListeningBlocks( $mw_listening_repo );
$mw_listen_first    = $mw_dedup_listening->render( array( 'itemsToShow' => 8 ) );
$mw_listen_second   = $mw_dedup_listening->render( array( 'itemsToShow' => 4 ) );
mw_assert_same( '', $mw_listen_second, 'Second same-page render of continue-listening must return empty string.' );
mw_assert_same( 1, substr_count( $mw_listen_first . $mw_listen_second, 'class="mw-continue-listening__panel"' ), '.mw-continue-listening__panel must render strictly once per page even with different itemsToShow settings.' );

// Empty `<section class="widget mw-widget ..."></section>` wrapper stripping in `TemplateManager::inject_sidebar_widgets()`.
if ( ! function_exists( 'is_active_sidebar' ) ) {
	function is_active_sidebar( $index ) {
		return isset( $GLOBALS['mw_test_sidebar_markup'][ (string) $index ] );
	}
}
if ( ! function_exists( 'dynamic_sidebar' ) ) {
	function dynamic_sidebar( $index = 1 ) {
		if ( ! isset( $GLOBALS['mw_test_sidebar_markup'][ (string) $index ] ) ) {
			return false;
		}
		echo $GLOBALS['mw_test_sidebar_markup'][ (string) $index ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return true;
	}
}
$mw_tpl_mgr                                              = ( new \ManaCore\MusicWave\Theme\Theme() )->templates();
$mw_sidebar_shell_markup                                 = '<aside class="wp-block-template-part mw-sidebar"><div class="mw-sidebar__widgets"></div></aside>';
$GLOBALS['mw_test_sidebar_markup']['musicwave-sidebar']  = '<section id="block-2" class="widget mw-widget widget_block"></section><section id="block-3" class="widget mw-widget widget_block">   </section>';
mw_assert_same( '', $mw_tpl_mgr->inject_sidebar_widgets( $mw_sidebar_shell_markup, array( 'attrs' => array( 'slug' => 'sidebar' ) ) ), 'Sidebar must collapse cleanly when active widgets render empty dynamic blocks.' );

$GLOBALS['mw_test_sidebar_markup']['musicwave-sidebar']  = '<section id="block-2" class="widget mw-widget widget_block"></section><section id="block-4" class="widget mw-widget widget_block"><p>ابزارک فعال</p></section>';
$mw_mixed_sidebar_output                                 = $mw_tpl_mgr->inject_sidebar_widgets( $mw_sidebar_shell_markup, array( 'attrs' => array( 'slug' => 'sidebar' ) ) );
mw_assert_same( false, strpos( $mw_mixed_sidebar_output, 'id="block-2"' ), 'Empty dynamic widget wrappers must be stripped even alongside non-empty widgets.' );
mw_assert_same( true, false !== strpos( $mw_mixed_sidebar_output, 'id="block-4"' ), 'Non-empty sidebar widgets must remain intact inside .mw-sidebar__widgets.' );
unset( $GLOBALS['mw_test_sidebar_markup'] );

unset(
	$GLOBALS['mw_test_posts'][ $mw_full_spec_post_id ],
	$GLOBALS['mw_test_types'][ $mw_full_spec_post_id ],
	$GLOBALS['mw_test_statuses'][ $mw_full_spec_post_id ],
	$GLOBALS['mw_test_titles'][ $mw_full_spec_post_id ],
	$GLOBALS['mw_test_meta'][ $mw_full_spec_post_id ]
);
$GLOBALS['mw_test_current_user'] = 0;

// Verify `FrontendRuntime` pure-JSON route state serialization & strict allowlist parsing.
$mw_parsed_loc = \ManaCore\MusicWave\Core\Support\FrontendRuntime::parse_localized_assignments(
	'var musicWaveLibrary = {"restUrl":"https://musicwave.test/wp-json/","restNonce":"loc-nonce-99"};' .
	"\n" .
	'var untrustedGlobalObject = {"evil":true};'
);
mw_assert_same( true, isset( $mw_parsed_loc['musicWaveLibrary'] ), 'FrontendRuntime must extract allowlisted localized JSON config objects.' );
mw_assert_same( 'loc-nonce-99', $mw_parsed_loc['musicWaveLibrary']['restNonce'], 'FrontendRuntime must decode localized JSON nonce cleanly.' );
mw_assert_same( false, isset( $mw_parsed_loc['untrustedGlobalObject'] ), 'FrontendRuntime must ignore non-allowlisted global variables.' );

$GLOBALS['mw_test_current_user'] = 42;
ob_start();
( new \ManaCore\MusicWave\Core\Support\FrontendRuntime() )->render_route_state();
$mw_route_state_html = (string) ob_get_clean();
$GLOBALS['mw_test_current_user'] = 0;
mw_assert_same( true, false !== strpos( $mw_route_state_html, '<script type="application/json" id="mw-route-state">' ), 'FrontendRuntime must output an inert application/json route-state script tag.' );

if ( ! empty( $GLOBALS['mw_dump_e2e_dom'] ) ) {
	$GLOBALS['mw_test_current_user'] = 0;
	$_GET['mw-playlist']             = (string) $mw_mix_pl;
	$mw_mix_shared_html              = $mw_playlist_ui->render_public( array() );
	unset( $_GET['mw-playlist'] );
	ob_start();
	( new \ManaCore\MusicWave\Core\Blocks\PreviewPlayer( $mw_release_repo ) )->render_global_player();
	$mw_global_player_html = (string) ob_get_clean();
	echo '__MW_JSON_START__' . json_encode(
		array(
			'vip'              => $mw_vip_settings_html,
			'publicBrowse'     => $mw_mix_public_browse_html,
			'shared'           => $mw_mix_shared_html,
			'player'           => $mw_global_player_html,
			'routeStateHtml'   => $mw_route_state_html,
			'pid'              => $mw_mix_pl,
			'listeningGuest'   => $mw_listening_guest_html,
			'listeningConsent' => $mw_listening_consent_html,
			'listeningActive'  => $mw_listening_active_html,
			'cardsHtml'        => $mw_continue_data['cards_html'],
			'dashboardHtml'    => $mw_dashboard_rendered_html,
			'guestPickerHtml'  => $mw_guest_picker_html,
			'guestLibraryHtml' => $mw_guest_library_html,
			'fullSpecsHtml'    => $mw_full_specs_html,
		)
	) . '__MW_JSON_END__';
}

$GLOBALS['mw_test_current_user'] = $mw_prev_current_user;
