# MusicWave VIP integration

Activate MusicWave Core and MusicWave VIP together. Core delays its composition until `plugins_loaded`, allowing VIP to register its adapters regardless of plugin load order.

## Master switch and free mode

Settings > MusicWave VIP opens with a **General** tab holding the master switch. Turning **VIP membership enforcement** off opens every membership gate for everyone (guests included, decision reason `vip_module_disabled`) while secure delivery keeps running — the site behaves as if the paywall was lifted for a promotion. While the switch is off, the plan engine stops granting, revoking, expiring, and promoting roles; stored grants are preserved and everything resumes when it is switched back on. WooCommerce per-release purchases (the `purchase` access mode) are never affected by the switch.

The same fail-open behavior applies when the VIP plugin is deactivated or missing entirely: Core treats membership releases as usable while no membership module answers, controlled by the **Membership module behavior** setting (Releases > Settings > Access, filter `music_wave_membership_absent_behavior`, default `allow`, `deny` for strict deployments). Protected downloads cannot be served without VIP — the download buttons surface a clear "VIP module disabled" message instead of failing silently — so deactivate VIP only when releases no longer need protected files.

**Who may stream & download protected audio** (`delivery_access`) chooses between *registered users only* (default) and *everyone, including guests*. The `everyone` mode is the secure-delivery-only setup: registration and membership are optional, every visitor can stream and download, but links stay signed, short-lived, and entitlement re-checks remain on the delivery path. Combine it with the remote signed host for a "download host only" site where subscriptions are handled elsewhere.

## Plan management

The **Membership & Plans** tab includes a plan management table (level, linked product, duration, remove-mapping action) and a **Create default plans (1 / 6 / 12 months)** button. The button creates three virtual WooCommerce products (`vip-1m`/`vip-6m`/`vip-12m` levels, 30/180/365-day durations) and maps them automatically; it is idempotent, so pressing it again only adds anything missing. Products are created with a zero price — set the real price on each product screen in WooCommerce, and every gateway, coupon, tax, and checkout feature of WooCommerce applies to plans automatically.

## Membership surfaces

The membership view is shared by four surfaces:

- the **Membership** panel inside the `music-wave/account-dashboard` block (toggle it with the *Show Membership panel* attribute, rename it with *Membership panel heading*),
- the **Membership** item in the WooCommerce **my-account** menu (`/my-account/membership/`),
- the `[musicwave_membership]` shortcode (`heading`, `show_active`, `show_plans`, `show_buy`, `empty_text`), and
- the standalone `music-wave/membership-panel` block (`heading`, `showActive`, `showPlans`, `showBuyButtons`, `emptyText`) that can be placed anywhere in the Site Editor.

VIP feeds all of them through the `music_wave_vip_membership_panel` filter with `module_enabled`, `active[]` (level + human expiry), and `plans[]` (id, level, title, price, duration, url, cart_url). When the master switch is off, the surfaces show a friendly "all membership content is currently free" notice.


For the built-in protected-file provider, either define `MUSIC_WAVE_VIP_PROTECTED_ROOT` in `wp-config.php` as an absolute directory outside the public web root or configure it under Settings > MusicWave VIP. When neither is set, VIP automatically creates `musicwave-private` beside the WordPress web root. In the **MusicWave release details** metabox, add one or more protected download qualities by uploading a file, importing it from the Media Library, choosing an existing protected file, or entering a `local:relative/path/file.zip` provider identifier. MusicWave stores those variants in `mw_download_assets`. The provider rejects traversal, files outside that root, missing files, and any asset identifier other than `local:`.

For an S3-compatible bucket, CDN, download host, or custom signing endpoint, choose **Remote HTTPS host with HMAC redirect**. Configure an HTTPS base URL, optional path prefix, a random secret of at least 32 characters, the host's expiry/signature query-key names, and a 30–900 second lifetime. VIP signs `base/path/asset-id|expiry` with HMAC-SHA256 and redirects only after Core has rechecked entitlement. The remote host must validate the same signature and expiry; a remote URL must never be pasted into public release content. For providers with a different signing algorithm, use the `music_wave_vip_remote_download_url` filter and return a short-lived HTTPS URL.

Membership sources are selectable under the VIP settings. WordPress role slugs, WooCommerce plan products, and the existing `music_wave_vip_membership_levels_for_user` adapter are the beginner-friendly defaults. Optional WooCommerce Memberships checks use a plan slug (prefix with `plan-` when it could be ambiguous); WooCommerce Subscriptions checks a product ID or a `subscription-123` level. Missing Woo extensions fail closed. Custom systems can use the existing level filter or `music_wave_vip_membership_access` for a single level decision.

## WooCommerce VIP plans

A VIP plan is a **normal WooCommerce product** — no extra membership extension is required. The workflow:

1. Create a WooCommerce product for each plan (an optional `sale price` works; variation IDs are supported).
2. Enable the **WooCommerce plan products** membership source under Settings > MusicWave VIP.
3. Enter one plan per line: `level:product-id[,product-id][:days]`. `level` is the exact key used in the release **Membership levels** field; `days` is optional (1–3650) and a missing duration means a lifetime grant. Example: `vipgold:123` and `silver:45,46:365`.
4. Set protected releases to the **Membership** access mode with the matching level key.

When a WooCommerce order reaches a granting status (`processing`/`completed` by default, filterable via `music_wave_vip_granting_statuses`), the customer account is granted every matching level and — when **VIP role promotion** is enabled — additionally receives the automatically registered `mw_vip` role. That role carries only `read`; it exists so VIP customers are identifiable, while all access decisions stay in the Core deny-by-default policy engine.

Refunded, cancelled, or failed orders revoke the grants from that order immediately. Entitlement is never cached: the access policy re-reads the stored grants (`mw_vip_plan_grants` user meta, keyed by order ID with expiry timestamps) on every decision, so a refund stops access at once. Expired timed grants are pruned automatically the next time they are read — no background sweep is required for correctness. Guest checkouts fail closed: plan purchases require a linked customer account.

Events: `music_wave_vip_plan_granted` (`$user_id, $order_id, $levels`) and `music_wave_vip_plan_revoked` (`$user_id, $order_id`) fire after each transition and are the notification/integration points.

## Protected assets

Administrators (accounts with the dedicated `manage_mw_protected_assets` capability, mapped to `manage_options` by default) can browse or upload protected assets from the unified release metabox. They can also select an audio attachment from WordPress Media Library; MusicWave copies it into the configured protected root before using it for a secure download. Uploads and copied files are limited by `wp_max_upload_size()` plus the default extensions `mp3`, `m4a`, `aac`, `ogg`, `wav`, `flac`, and `zip`. Use the `music_wave_vip_allowed_asset_extensions` filter to adjust that allow-list, and the `music_wave_manage_asset_caps` filter to broaden or narrow who manages protected assets.
