=== MusicWave VIP Integration ===
Contributors: manacore
Tags: music, membership, downloads, protected-files, woocommerce
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.5.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Local protected files, HMAC remote redirects, WooCommerce plan memberships and role adapter for MusicWave Core.

== Description ==

MusicWave VIP extends MusicWave Core with secure delivery and membership.

* **Protected delivery**:
  - Local mode: private directory outside public root (`/var/private/musicwave`), PHP streaming or accelerated via `X-Sendfile` / `X-Accel-Redirect` (`sendfile_mode`, `xaccel_prefix`), `.htaccess` deny files defense-in-depth.
  - Remote mode: HTTPS base URL + path prefix + HMAC-SHA256 signed short-lived URLs (`remote_base_url`, `remote_path_prefix`, `remote_signing_secret` ≥32 chars, `remote_signature_param`, `remote_expires_param`, TTL 30-900 sec, `remote_key_id`, `remote_allowed_hosts`). Referrer-Policy `no-referrer`, HMAC covers path+expiry+mode+kid.
  - Provider contract: filter `music_wave_download_provider`, `music_wave_vip_remote_download_url`, storage `ProtectedAssetStorage.php`, registry table `mw_vip_assets` with SHA-256 checksum verification at delivery.
* **Membership adapters** (selectable sources):
  - `role` – WordPress role slug equals level.
  - `filter` – developer filter `music_wave_vip_membership_access`.
  - `woocommerce_plans` – recommended: standard WooCommerce product → plan line `level:product_id[:days]` (e.g. `vipgold:123`, `silver:45,46:365`). Lifetime if no days, 1-3650 days otherwise. Paid orders grant level + `mw_vip` role instantly, refunds/cancellations/failures revoke live via compare-and-set with retry. Meta `_mw_vip_granted_levels`, `_mw_vip_granted_expiries`, `_mw_vip_granted_at` + order note.
  - `woocommerce_memberships` – WooCommerce Memberships (prefix `plan-` if needed).
  - `woocommerce_subscriptions` – WooCommerce Subscriptions (`subscription-123` or product ID).
* **Dashboard integration**: filter `music_wave_vip_membership_panel` returns `has_vip`, `active`, `detailed_active` (level/expires_label/remaining_days/percent_used/is_lifetime/order_id), `renewal_needed` (threshold filter `music_wave_subscription_renewal_threshold` default 90%), `plans[].cart_url`. Used by Core `AccountLibrary.php` blocks `dashboard-welcome`, `dashboard-stats`, `dashboard-subscription` for real VIP badge, subscription meta, renewal button, progress bar.
* **Expiry**: hourly cron + login hook, 15-min cooldown on admin/login, reverts to standard when expired, order view shows subscription details.
* **Uninstall**: opt-in setting `delete_data_on_uninstall` default OFF in Advanced tab, self-contained `uninstall.php` with `WP_UNINSTALL_PLUGIN` guard, drops `mw_vip_assets`, deletes options `music_wave_vip_*`, transients, user meta `mw_vip_plan_grants`, cron, role `mw_vip`, caps. Never deletes releases/terms/media/protected files. Multisite aware via `get_sites` + `switch_to_blog`.

Requires MusicWave Core 0.11.0+ and MusicWave theme. Works without WooCommerce (role/filter sources only).

== Installation ==

1. Activate MusicWave Core 0.11.0+ before this plugin.
2. Activate MusicWave VIP.
3. Set `MUSIC_WAVE_VIP_PROTECTED_ROOT` in `wp-config.php` (constant overrides UI) or configure Settings → MusicWave VIP → Delivery.
4. Ensure protected directory exists, readable/writable, outside public WordPress root. For subdirectory installs, auto-detected sibling folder is rejected if still web-accessible — use absolute path like `/var/private/musicwave`.
5. Upload or select assets from the MusicWave release sidebar (asset registry stores `local:` opaque IDs).
6. For WooCommerce plans: create normal WooCommerce products, enable source `woocommerce_plans`, add lines `level:product_id[:days]` in Membership & Plans tab, set release Access Policy to Membership with matching level.
7. Save permalinks and test delivery in Setup & diagnostics.

== Frequently Asked Questions ==

= Where should protected files live? =
Outside `public_html` / `htdocs` / `ABSPATH`. Example `/var/private/musicwave` or `/home/user/musicwave-private`. Must exist, readable/writable by web server, outside web root per `ProtectedAssetStorage::preflight()` checks (root_resolved, directory_exists, readable, writable, outside_web_root, deny_files). `.htaccess` with `Require all denied` is added automatically as defense-in-depth. Nginx needs `location /musicwave-protected/ { internal; alias /var/private/musicwave/; }` and `xaccel_prefix` setting.

= How do I sell VIP memberships? =
Create a standard WooCommerce product (simple, price). Note its ID (hover in Products → ID). In VIP Settings → Membership & Plans → VIP plan mapping, add line `vipgold:123` (lifetime) or `silver:102,103:365` (1 year, two product IDs). Set protected releases → Access → Membership → Membership levels = `vipgold`. Customer buying product 123 instantly gets level, `mw_vip` role, playback and protected downloads. Refund/revoke removes it live. Use button “Create default plans (1,6,12 months)” to auto-create 3 virtual products.

= What happens when a VIP plan expires? =
Grant stored with `granted_at`, `duration_days`, `expires_at = granted_at + duration*DAY`. `detailed_active_grants()` sorts expiry-first, calculates `percent_used` and `remaining_days`. When expired, access check `decide()` returns false, dashboard shows standard. Hourly cron `music_wave_vip_expiry_check` + login hook prunes. Lifetime grants show “مادام‌العمر” without progress. Renewal button appears when `percent_used ≥ 90%` (filter `music_wave_subscription_renewal_threshold`) with real `cart_url` from matching plan.

= Can I use S3/R2/CDN instead of local files? =
Yes, remote redirect mode. Set base URL HTTPS, prefix optional, secret ≥32 chars (`openssl rand -hex 32`), signature/expires params, TTL 30-900 (120-300 recommended), optional key ID for rotation, allowed hosts for mirrors. HMAC-SHA256 covers path+expiry+mode+kid. Secret never sent to browser, only short-lived URL via 302 with `Referrer-Policy: no-referrer`. Ensure origin only accepts traffic from WordPress if you trust `X-Forwarded-For` via filter `music_wave_client_ip`.

= Is download quota enforced? =
Core settings `download_rate_limit`, `download_rate_window`, `download_daily_quota` (0 = unlimited) enforced in `DownloadRateLimiter.php` and `DownloadResolver.php` with hashed IP buckets, filter `music_wave_download_quota`. Site Health warns when near quota. VIP respects same limits; token is one-time and consumed after quota check.

= Does it work without WooCommerce? =
Yes, use sources `role` and `filter`. Role source: create WP role with slug equal to level (e.g. `vipgold`) or assign existing role. Filter source: add `add_filter('music_wave_vip_membership_access', ...)` returning levels for user ID. Without WooCommerce, plan auto-creation button is disabled and product table shows warning.

= How do I disable author enumeration? =
Core optional setting (FIX-22 planned) `music_wave_disable_author_enumeration` filter. VIP itself does not expose `author_id` in public playlist payloads (FIX-14) — only display name and author URL. Public playlists default noindex via setting `music_wave_public_playlists_indexable` (FIX-27).

= What data does VIP store? =
Table `mw_vip_assets` (asset_id, release_id, file_path, size, sha256, created_at). Options `music_wave_vip_settings`, legacy `music_wave_vip_protected_root`. User meta `mw_vip_plan_grants` (level→order/duration/expiry). Transients for plan cache. No raw IP storage. Uninstall setting default OFF keeps data; when ON, drops table, deletes options/transients/user meta/cron/role. Never deletes releases/terms/media/protected files.

= How do I harden Apache/Nginx for protected files? =
Apache: keep outside DocumentRoot; `.htaccess` with `Require all denied` added automatically. Nginx: `location /musicwave-protected/ { internal; alias /var/private/musicwave/; }` + set X-Accel prefix `/musicwave-protected`. For X-Sendfile on Apache enable `mod_xsendfile` and set `X-Sendfile-Type`. For remote mode, ensure CDN validates expiry and signature server-side, not just client.

= How do I test protected delivery? =
Use `tests/download-e2e.php` (planned FIX-42): guest → 403, subscriber without purchase → 403, with purchase → 200 + token, editor → 200, rate limit → 429, X-Accel header when enabled. Manual: create release with protected asset, set access to membership `vipgold`, buy plan product as test customer, try download from incognito (should 403), login as customer (should 200), check order note `_mw_vip_granted_levels`.

== Screenshots ==

1. VIP Settings — General tab with master switch and delivery access.
2. VIP Settings — Delivery tab with local path and remote HMAC fields, preflight warnings.
3. VIP Settings — Membership & Plans with product reference table and mapping textarea.
4. Account dashboard — VIP badge, subscription meta, progress bar, renewal button, order link.
5. My Orders column “اشتراک” with badge and view-order subscription details box.

== Changelog ==

= 0.5.1 =
* Compatibility: the protected-asset uploader detects MIME types through a single helper that no longer calls `finfo_close()` (deprecated in PHP 8.5); behaviour is unchanged on PHP 7.4–8.4.
* Maintenance: database access in the asset registry and plan sweeps now type-checks the global `$wpdb` object instead of probing individual methods, so static analysis covers every query. No functional change.

= 0.5.0 =
* Fix: saving the MusicWave Core integrations tab no longer resets the VIP module. The settings page marks its own full form, so unchecked-checkbox semantics only apply when the VIP form itself is submitted.
* Fix: protected assets are verified against their stored SHA-256 checksum at delivery time; legacy rows without a checksum keep the size check.
* Hardening: an empty membership-source selection now fails closed instead of silently restoring the defaults.
* Hardening: the membership expiry sweep is throttled to a 15-minute cooldown on admin and login hooks; the scheduled cron sweep is unchanged.

= 0.4.0 =
* New: WooCommerce plan products as a membership source (`VipPlans`). A plan is a standard Woo product: paid orders grant the configured level (and the `mw_vip` role), refunds/cancellations/failures revoke it, lifetime or day-bounded durations are supported.
* New: `promote_vip_role` setting and auto-registered read-only `mw_vip` role so VIP accounts are identifiable without granting elevated capabilities.
* New: plan level visibility in the account dashboard through the existing `music_wave_vip_membership_levels_for_user` filter.
* Hardening: grant and revocation meta writes use compare-and-set with retry, so two concurrent WooCommerce order webhooks for one customer can never clobber each other's membership levels.
* Hardening: defensive role access for user objects lacking a roles array.

= 0.3.3 =
* Protected delivery hardening per MusicWave Stage 2.

= 0.3.2 =
* Initial marketplace release with local protected directory and role adapter.

== Upgrade Notice ==

= 0.5.1 =
MIME helper no longer calls `finfo_close()` (PHP 8.5 compat). No action needed.

= 0.5.0 =
Fix for Core integrations tab resetting VIP module. Verify your membership sources after update. Empty selection now fails closed (deny).

= 0.4.0 =
New WooCommerce plan products source. If you use role/filter only, no change. To sell plans, create WooCommerce products and add mapping lines.

== Privacy ==

VIP stores `mw_vip_assets` table and user meta `mw_vip_plan_grants`. No raw IP. Rate limiting via hashed IP transients. Protected files never leave private directory except via signed short-lived URLs. See Core privacy guide and `docs/third-party-notices.md`.

== Credits ==

* MusicWave VIP team — https://manacore.dev
* Uses WordPress core file functions and WooCommerce hooks when available.
