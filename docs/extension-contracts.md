# Extension and event contracts

Status: Phase 2 Stage 3 (PROJECT_PLAN.md deliverable 7). This file is the authoritative
inventory of public extension points, their stability level, and the deprecation policy.

## Deprecation policy

- **Stable** hooks keep signature compatibility within a major version. New parameters are
  appended only (additive).
- Removing or changing a stable hook requires: a deprecation notice in the changelog and
  readme, a compatibility shim for **one minor release cycle**, and a `_deprecated_hook()`
  runtime notice where WordPress is available.
- **Experimental** hooks may change in any release; they are marked below.

## Provider composition (stable)

| Hook | Type | Contract |
|---|---|---|
| `music_wave_membership_provider` | filter | Return a `MembershipProvider`; invalid values fall back to the null provider (deny). |
| `music_wave_manual_access_provider` | filter | Return a `ManualAccessProvider`; same fallback. |
| `music_wave_download_provider` | filter | Return a `DownloadProvider` (optionally `StreamableDownloadProvider`). Deny-by-default when absent. |
| `music_wave_core_modules` | filter | Append `Module` implementations composed at boot. |

## Entitlement and delivery (stable)

| Hook | Type | Contract |
|---|---|---|
| `music_wave_purchase_owns_release` | filter | `(bool $owns, int $user_id, int $release_id)` — adjust purchase ownership (ADR 0004). |
| `music_wave_can_assign_download_asset` | filter | `(?bool $authorized, string $asset_id, int $release_id, int $user_id)` — provider-side assignment authorization; `null` defers to the dedicated capability. |
| `music_wave_manage_asset_caps` | filter | Primitive capabilities mapped to `manage_mw_protected_assets` (default `manage_options`). |
| `music_wave_download_event` | action | `(string $event, int $release_id, int $user_id, array $context)` — structured, PII-free audit stream. Context added in 0.9.x (additive). Persisting integrations own retention/export/erasure. |
| `music_wave_download_rate_limit` / `music_wave_download_rate_window` | filter | Token-issue rate limit tuning. |
| `music_wave_download_daily_quota` | filter | Per-user daily delivery quota; `0` disables (default). |

## VIP delivery (stable)

| Hook | Type | Contract |
|---|---|---|
| `music_wave_vip_download_provider` / `music_wave_vip_membership_provider` | filter | Replace composed VIP adapters. |
| `music_wave_vip_remote_download_url` | filter | Return a complete signed URL; the result must pass the HTTPS host allowlist or delivery fails closed. |
| `music_wave_vip_membership_levels_for_user` | filter | Map user roles/sources to membership level slugs. VIP adds active plan levels at priority 20. |
| `music_wave_vip_granting_statuses` | filter | `array<int, string>` WooCommerce order statuses that grant VIP plans (default `processing`, `completed`; ADR 0004 parity). |
| `music_wave_vip_plan_granted` | action | `(int $user_id, int $order_id, array $levels)` after a paid order grants plan levels. |
| `music_wave_vip_plan_revoked` | action | `(int $user_id, int $order_id)` after a voided order revokes its plan grants. |
| `music_wave_vip_grants_write_failed` | action | `(string $event, int $user_id, int $order_id)` when a grant meta write exhausts its compare-and-set retries under webhook contention. |
| `music_wave_client_ip` | filter | `(string $ip, string $raw_ip)` — override client IP used for rate limiting and guest quota. Default `REMOTE_ADDR` only. **WARNING**: Only trust proxy headers (e.g. `HTTP_CF_CONNECTING_IP`, `X-Forwarded-For`) when origin only receives traffic from that proxy; otherwise spoofed headers bypass rate limits. Example Cloudflare: `add_filter('music_wave_client_ip', fn($ip)=> !empty($_SERVER['HTTP_CF_CONNECTING_IP']) ? filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP) ?: $ip : $ip);` |
| `music_wave_view_dwell_seconds` | filter | `(int $seconds)` — how long a release must be visible before beacon counts (default 10). |
| `music_wave_subscription_renewal_threshold` | filter | `(float $threshold)` — percent used (0-1) after which renewal button appears (default 0.9 = 90%). |
| `music_wave_bot_ua_patterns` | filter | `(array $patterns)` — regex patterns to exclude bots from view counter (default includes bot/crawler/spider). |
| `music_wave_max_playlists_per_user` | filter | `(int $limit)` — max playlists per user (default 20). |
| `music_wave_max_playlist_items` | filter | `(int $limit)` — max items per playlist (default 100). |
| `music_wave_conditional_assets` | filter | `(array $map)` — block name => style handles for conditional CSS loading (FIX-35). Allows themes/plugins to extend. |
| `music_wave_lighthouse_budgets` | filter | `(array $budgets)` — Lighthouse performance budgets per page type (FIX-36). |

## Dashboard and account (stable)

| Hook | Type | Contract |
|---|---|---|
| `music_wave_vip_membership_panel` | filter | `(array $data, int $user_id)` — single source of truth for dashboard: `has_vip`, `active`, `detailed_active` (level/expires_label/remaining_days/percent_used/is_lifetime/order_id/cart_url), `renewal_needed`, `plans`. Used by `AccountLibrary.php` blocks. |

## Catalog, presentation, and privacy (stable)

| Hook | Type | Contract |
|---|---|---|
| `music_wave_library_items` | filter | Adjust normalized personal-library items for display paths. |
| `music_wave_library_item_added` / `music_wave_library_item_removed` | action | `$type, $item_id, $user_id` after a library mutation (`release`, `artist`, `wishlist`, `presave`). |
| `music_wave_presave_fulfilled` | action | `$release_id, $user_id` when a pre-saved release becomes available; the notification integration point. |
| `music_wave_listening_retention_days` | filter | Listening-history retention window in days (default 180). |
| `music_wave_recommendations` | filter | Curate recommendation items; each item must keep a machine `reason` and a translated `explanation`. |
| `music_wave_catalog_search_adapter` | filter | Return a `CatalogSearchAdapter` for external search. It proposes candidate release IDs only; Core still enforces release visibility. |
| `music_wave_catalog_suggestions` | filter | Curate autocomplete suggestions; entries must stay public (published releases and public terms). |
| `music_wave_catalog_discovery_ttl` | filter | Cache lifetime for autocomplete/facet payloads (default 300s). |
| `music_wave_discovery_rate_limit` / `music_wave_discovery_rate_window` | filter | Public discovery rate limit per actor and window (default 60/60s). |
| `music_wave_dashboard_panels` | filter | Add/remove account dashboard panels (key => icon/label/description/content). |
| `music_wave_release_permalink_bases` | filter | Map of release-type slug => URL base used for single release permalinks (defaults: album, ep, mix, playlist, podcast_show=>podcast, podcast_episode=>episode, single/track=>track). Order defines priority for multi-type releases; bases are sanitized to slugs, invalid entries dropped, and a base may be shared by several types. Changing the map requires a rewrite flush (re-save permalinks). |
| `music_wave_release_json_ld` | filter | Adjust public JSON-LD; must not add private assets, entitlements, or user data. |
| `music_wave_json_ld_enabled` | filter | Toggle Core JSON-LD output. |
| `music_wave_cover_import_budgets` | filter | Cover import timeout/byte/pixel budgets. |
| `music_wave_provider_health` | filter | Report provider health: slug => `{status: ok|misconfigured|unreachable|rate_limited|failed, summary}`; surfaced in Site Health. |

## Search (stable)

Site search rewrites the search `WHERE` clause so stored content matches regardless of
script and keyboard: Persian/Arabic spelling variants (ي/ی, ك/ک, zero-width non-joiner vs
space, Persian and Arabic digits, diacritics, kashida) and common Latin accents
(café/cafe) fold to one canonical form. Every eligible query also matches catalog term
names (artist, genre, mood, label, release type) and descriptive release meta (album,
catalogue number, ISRC, credits) through bounded sub-queries. Pure-ASCII words keep a
single spelling, so the rewrite adds only the bounded term/meta sub-queries for Latin
phrases.

| Hook | Type | Contract |
|---|---|---|
| `music_wave_script_aware_search` | filter | `(bool $enabled, WP_Query $query)` — whether one query receives the rewrite. Enabled by default for the main query, release-scoped queries, and queries opting in with the `mw_script_search` query var; return `false` to leave a query to core's clause. |
| `music_wave_search_taxonomies` | filter | `array<int, string>` taxonomy keys whose **term names** are matched per search word (default `mw_artist`, `mw_genre`, `mw_mood`, `mw_label`, `mw_release_type`). Keys are sanitized; matching runs through a bounded `ID IN (SELECT …)` sub-query, never an outer join. |
| `music_wave_search_meta_keys` | filter | `array<int, string>` post-meta keys whose values are matched per search word (default `mw_album`, `mw_catalog_number`, `mw_isrc`, `mw_credits`). Only add public, descriptive keys — never access, product, or asset fields. |

Query vars (set on a secondary `WP_Query` / `get_posts()` with `suppress_filters => false`):

| Query var | Contract |
|---|---|
| `mw_script_search` | `true` opts the query into the rewrite (subject to the filter above). |
| `mw_search_taxonomies` | `array` replaces the taxonomy list for this query only; an empty array skips the term sub-query. |
| `mw_search_meta_keys` | `array` replaces the meta-key list for this query only and forces the rewrite for that query (core cannot search meta at all). |

Guarantees: words remain AND-combined, `-word` exclusions, `exact`, `search_columns` /
`post_search_columns`, and `wp_query_search_exclusion_prefix` are honored; the anonymous
`post_password = ''` clause is kept; every value passes through `$wpdb->prepare()` and
`$wpdb->esc_like()`; at most 8 words × 6 spellings are expanded per query.

## Term colours and header width (theme)

| Hook | Type | Contract |
|---|---|---|
| `musicwave_header_width_mode` | filter | `(string $mode)` — site-wide header content width mode: `wide` (row inside `settings.custom.header.contentSize` from theme.json) or `full` (viewport width). The theme stamps the resolved mode on every rendered header part, so all header variants stay in sync; a per-part block style chosen in the Site Editor overrides it. |

Core exposes `ManaCore\MusicWave\Core\Catalog\TermHue` as the single source of
term colours: `for_id(int)`, `for_term(WP_Term)`, and `modifier(int)` return the
term-ID–derived hue step (`1..8`) and the canonical `mw-term-hue-N` class. All
term surfaces (term hero, taxonomy shelf tiles, artist cards, playlist fallback
art, release taxonomy chips) emit the same modifier and read the same `:root`
tokens and the shared `.mw-term-media` shell from `musicwave/assets/css/hues.css`
(`--mw-term-hue`, `--mw-term-hue-strong`, `--mw-term-hue-ink`), so one term
keeps one colour across cards, headers, banners, and archives regardless of list
order; undefined hues fall back to the theme accent.

## Song requests and collaboration (stable)

| Hook | Type | Contract |
|---|---|---|
| `music_wave_manage_request_caps` | filter | `array<int, string>` primitive capabilities that grant the `manage_mw_requests` meta capability (default `manage_options`). Return e.g. `array( 'edit_others_posts' )` to let editors handle requests. |
| `music_wave_request_message` | filter | `(array|false $message, string $kind, array $request)` — one outgoing email; `$message` is `{to, subject, body, headers}` and `$kind` is `receipt` (to the requester), `alert` (to managers) or `reply` (manager → requester). Return `false` to suppress delivery, or replace the parts to route through another transport. |
| `music_wave_request_reply_templates` | filter | `array<string, string>` label => body of the canned replies offered on the request screen; receives the normalized request as the second argument. |
| `music_wave_request_created` | action | `(int $request_id, array $request)` after a public submission was stored and its notifications sent. Manual admin entries do not fire it. |
| `music_wave_request_status_changed` | action | `(int $request_id, string $status, string $previous)` after a lifecycle change (`mw_req_new`, `mw_req_review`, `mw_req_replied`, `mw_req_accepted`, `mw_req_declined`, `mw_req_archived`). |

The public endpoint is `admin-post.php` action `music_wave_submit_request` (nonce `music_wave_request`);
the admin endpoint is `music_wave_manage_request` and requires `manage_mw_requests`. Both return through
post/redirect/get; field errors travel in a 10-minute transient keyed by a random token, never in the URL.

## Page builders (stable)

| Surface | Contract |
|---|---|
| `[musicwave_block]` shortcode | `name` (required, `music-wave/*` only) and `attributes` / `attrs` (JSON object of the block's declared attributes). Rendered through `render_block()`, so output matches the block and Site Editors. Unknown namespaces, malformed JSON and undeclared attribute keys are rejected. |

See `docs/elementor-compatibility.md` for Elementor usage, context-dependent blocks and the
reason the FSE templates themselves stay in the Site Editor.

## Experimental

| Hook | Type | Notes |
|---|---|---|
| `music_wave_admin_integrations` / `music_wave_admin_integration_settings` | filter/action | Admin integration cards; UI may change. |

## Events lifecycle

`music_wave_core_loaded` (action) fires once after module composition; integrations must
register providers via the filters above **before** it fires (normal plugin load order).
