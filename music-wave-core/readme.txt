=== MusicWave Core ===
Contributors: manacore
Tags: music, catalog, releases, woocommerce, downloads, playlists, rtl
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.16.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Catalog, access control, secure downloads, playlists, listening history and diagnostics for the MusicWave block theme.

== Description ==

MusicWave Core is the data and access layer for the MusicWave block theme.

* **Catalog**: custom post type `mw_release` with taxonomies `mw_artist`, `mw_genre`, `mw_mood`, `mw_label`, `mw_release_type`, meta schema, term color source of truth, release relationships (same artist / similar), archive query with safe sorting, type-based permalinks (`/album/`, `/track/`, `/ep/` etc filterable via `music_wave_release_permalink_bases`).
* **Access**: 5 access modes (public, purchase, membership, purchase_or_membership, restricted), bulk access manager (200 per batch), membership absent behavior setting, customer messages.
* **Commerce**: WooCommerce product mapping, library (wishlist / pre-save with fulfillment), account dashboard (`AccountLibrary.php`) with real VIP data via filter `music_wave_vip_membership_panel`, blocks `account-dashboard`, `dashboard-welcome`, `dashboard-stats`, `dashboard-subscription` editable in Site Editor.
* **Secure delivery**: token issuer, rate limiters (download / discovery), guest quota, provider contract `music_wave_download_provider`, `ClientIp` helper with filter `music_wave_client_ip` (default `REMOTE_ADDR`), signed one-time tokens.
* **Playlists & Library**: tables `mw_playlists`, `mw_playlist_items`, `mw_user_activity` (listening), `mw_download_replays`, public playlist directory with covers, limits filterable, `mw_library_index` planned for performance.
* **Discovery**: REST `music-wave/v1` routes `/catalog/suggest`, `/catalog/facets`, `/recommendations` (rate limited), `/playlists/public`, script-aware search (Persian/Arabic/Latin normalization, filters `music_wave_script_aware_search`).
* **Request module**: private post type `mw_request` (non-public, capability gated), block `music-wave/request-form` with modes both/song/collab, honeypot + timing + rate limit, PRG token, management screen under MusicWave → Requests.
* **Metadata providers**: MusicBrainz + Cover Art Archive (keyless), optional Spotify / Discogs (keys in settings), `wp_safe_remote_*` for external calls.
* **Onboarding & Diagnostics**: Setup & diagnostics page, Site Health checks, help tabs, `music_wave_schema_version` migration runner, uninstall cleanup with opt-in setting `delete_data_on_uninstall` (default OFF, never deletes `mw_release`/`mw_request`/terms/media/protected files).
* **Privacy**: exporter/eraser for listening history, playlists, requests; `PolicyContent.php` adds Privacy Guide content; rate limiter uses hashed IP in transients, no raw IP storage.

Requires MusicWave theme `musicwave/` for front-end templates. Works without WooCommerce (purchase modes disabled) and without MusicWave VIP (membership modes allow/deny via setting).

== Installation ==

1. Upload `music-wave-core` to `/wp-content/plugins/` and activate.
2. Activate the MusicWave block theme (`musicwave/`).
3. Go to Settings → Permalinks and click Save to flush rewrite rules (type-based release permalinks).
4. Open MusicWave → Setup & diagnostics and resolve required checks (schema version, WooCommerce, VIP, theme).
5. Configure MusicWave → Settings & overview: content defaults, archive, slider, access messages, delivery limits, integrations.
6. If using protected files, install and configure MusicWave VIP before assigning private assets.
7. Optional: enable WooCommerce for purchase flows; configure VIP plans in VIP settings.

== Frequently Asked Questions ==

= Does it work without WooCommerce? =
Yes. Catalog, search, playlists, listening history and request forms work without WooCommerce. Purchase-related access modes (`purchase`, `purchase_or_membership`) will deny unless WooCommerce and product mapping exist. Filters `music_wave_*` allow custom purchase providers.

= Does it work without MusicWave VIP? =
Yes. Membership modes use setting `membership_absent_behavior` (allow/deny) when no membership module is active. Secure delivery falls back to deny when VIP is missing. Install VIP for local protected directory or remote HMAC redirect.

= How do I set up protected downloads (local)? =
In VIP settings set a path outside public root, e.g. `/var/private/musicwave` or `/home/user/musicwave-private` (not inside `public_html`). Must exist, readable/writable, outside web root. The plugin creates `.htaccess` deny files as defense-in-depth. For Nginx use `location /musicwave-protected/ { internal; alias /var/private/musicwave/; }` and set X-Accel prefix. See `docs/third-party-notices.md` and `extension-contracts.md`.

= How do I use remote HMAC storage (S3/R2/CDN)? =
Set delivery provider to `remote_redirect`, base URL must be HTTPS (e.g. `https://cdn.example.com/files`), path prefix optional (`vip-assets`), signing secret ≥32 chars (`openssl rand -hex 32`), signature/expires params, TTL 30-900 sec (recommended 120-300). Optional `remote_key_id` for rotation, `remote_allowed_hosts` for mirrors. HMAC covers path+expiry+mode+kid, Referrer-Policy `no-referrer`. Never expose secret to browser.

= What are the playlist limits? =
Default: 20 playlists per user, 100 items per playlist, filterable via `music_wave_playlist_limits` and settings `music_wave_settings[playlist_*]` (if added). Public playlist endpoint `GET /music-wave/v1/playlists/public` returns `covers` (up to 4 thumbnails) but not `author_id` (privacy, FIX-14). Owner endpoints keep author context.

= How does listening history consent work? =
Playback progress and continue-listening use table `mw_user_activity`. Stored only after explicit opt-in via REST `POST music-wave/v1/listening/consent`. Retention setting `listening_retention_days` default 180 days (filterable), daily cron prunes old rows. Users can delete own history from account dashboard. Exporter/eraser covers it.

= How does search handle Persian/Arabic/Latin? =
Script-aware search expands each word into equivalent spellings (Arabic vs Persian ye/kaf, ZWNJ vs space, Persian/Arabic/Latin digits, diacritics/kashida, Latin accents café/cafe). Taxonomies `mw_artist/genre/mood/label/type` and meta `album, catalog number, ISRC, credits` matched via bounded sub-queries. Filters: `music_wave_script_aware_search`, `music_wave_search_taxonomies`, `music_wave_search_meta_keys`; query vars `mw_script_search`.

= Are translations and RTL supported? =
Source strings are Persian by policy; `en_US` catalogs shipped. Theme uses CSS logical properties and `dir=\"auto\"` for mixed content. Text domains `music-wave-core`, `music-wave-vip`, `musicwave`. Add new language from English POT: `composer make-translations`. RTL tested with `fa_IR` and `en_US`.

= Is page cache / object cache compatible? =
Yes. Archive and discovery results use transients with TTL `discovery_cache_ttl` (30-86400 sec, default 300) invalidated on `mw_release` save/delete/status transition. View counter uses beacon + table `mw_release_views` (hourly sync to `mw_views` meta for sorting) to avoid per-request writes. Personalized endpoints (`playlists`, `library`, `listening`) send `Cache-Control: private` and vary by user.

= What personal data is stored and for how long? =
Request form: `mw_request` private post type stores name/email/phone/message (no expiry, admin deletes). Listening: `mw_user_activity` with consent, 180 days default. Playlists/library: custom tables + user meta. Rate limiters: hashed IP in transients with short expiry (rate window 10-3600 sec). Public playlists show display name only. See Settings → Privacy → Policy Guide and `docs/user-data-and-playlists.md`.

= What are the audio upload limits? =
Cover art upload uses WordPress media library limits (`upload_max_filesize`, `post_max_size`). Protected audio files are not uploaded via media library but via VIP uploader to private directory; MIME validated via `finfo`, size capped, temp files cleaned, capability `upload_files` required (see `MetadataResolver::import_cover` hardening).

= How do release permalinks work? =
Map filterable: album `/album/`, track/single `/track/`, EP `/ep/`, mix `/mix/`, playlist `/playlist/`, podcast `/podcast/`, episode `/episode/`, fallback `/music/`. Bases register full permastruct (pagination, feeds, embeds). Legacy `/music/<slug>/` 301 to canonical. Rules regenerate once per map via option `music_wave_release_permalink_rules`.

= How is spam prevented on request form? =
Block `music-wave/request-form` includes honeypot, timing check, per-actor rate limit (IP hash + user), field-level validation, PRG with random token (no personal data in URL), receipt email with `MW-YYYY-000000` reference. Settings: recipients, Reply-To, receipts, signature, rate limit, enabled kinds.

== Screenshots ==

1. Catalog archive with taxonomy filters and safe sorting.
2. Single release with hero, tracklist, same-artist and similar sections.
3. Account dashboard — welcome with VIP badge, subscription meta, renewal, stats compact.
4. Public playlists directory with cover-stack and instant search.
5. Request form block with mode selector and management screen.
6. Setup & diagnostics with schema version, delivery, SEO checks.

== Changelog ==

= 0.16.0 =
* Feature: `MembershipAbsencePolicy` introduces three explicit modes when no membership module is active: `allow` (backwards-compatible default), `open_download` (opens `membership` and `purchase_or_membership` without editing releases, while keeping `purchase`, `restricted`, and guest `logged_in` closed), and `deny` (fail-closed).
* Feature: scheme-based `DeliveryRouter`, `DirectLinkProvider`, `DirectLinkAsset`, `DirectLinkUrlValidator`, `DirectLinkPermission`, `RedirectResponse`, and `DeliveryCapabilities` add Core-native controlled `direct:<https-url>` delivery and streaming alongside VIP/external `vip:` and `local:` protected providers — no database migration required.
* Security: strict SSRF/redirect hardening (HTTPS-only, `<=2048` chars, blocks credentials/fragments, `localhost`, internal TLDs, CGNAT `100.64/10`, `198.18/15`, multicast, `::ffff:` IPv4-mapped IPv6, NAT64 `64:ff9b::/96`, octal/hex/decimal IPs, plus optional `direct_link_allowed_hosts` enforced at both save and delivery time), capability gating (`manage_mw_protected_assets`), `MAX_TTL = 7200` ceiling, shutdown-safe delivery counting/auditing, and non-autoloaded DB daily quota persistence (`mw_dl_quota_*`).
* Performance: `BulkAccessManager::mode_counts()` uses a single aggregated SQL `GROUP BY` query with a 5-minute transient cache invalidated on release mutations; `DatabaseReplayStore::cleanup()` prunes expired replay and stale quota rows in bounded primary-key batches.

= 0.15.0 =
* Design: term colours are unified around one source of truth (`ManaCore\MusicWave\Core\Catalog\TermHue`). The term hero, taxonomy shelf tiles, artist cards, playlist fallback art, and release taxonomy chips all derive their hue from the term's numeric ID and emit the canonical `mw-term-hue-N` modifier, so a genre keeps its colour everywhere and never repaints when lists re-order; legacy surface classes remain supported through CSS aliases.

= 0.14.1 =
* Fix: distinguish provider failures from empty searches; encode external API queries and support plain-permalink REST URLs.
* Performance: retrieve cover art only for the selected result; isolate detail caches by track and Spotify tokens by credentials.
* Security: cap cover downloads during streaming, clean temporary files and require upload permission.
* Editor: independently insertable release facts, native block props and conditional inspector controls.
* SEO: playlists use MusicPlaylist structured data.

= 0.14.0 =
* Feature: the `music-wave/request-form` block gains a `mode` (`both` | `song` | `collab`) so one site can run a combined page or two dedicated pages (\"custom song\" / \"collaboration\"). Each mode ships its own translated eyebrow, heading, intro, submit label, service highlights and process steps; editors can override every line (`highlight1Title` … `step3`, `privacyNote`), preset or hide the kind picker (`showTypeChips`, `defaultType`) and the role picker (`showRoles`, `defaultRole`). Existing blocks keep their behaviour (`mode` defaults to `both`).
* Security: the kinds a form offers travel with the submission (`mw_request_kinds`) and are bound into the nonce action; the handler intersects them with the kinds enabled in Settings and `RequestSubmission::validate()` refuses any other kind, so a crafted POST cannot file a collaboration through a song-only page. A dedicated page whose kind the manager disabled shows the closed notice instead of an unsendable form.
* Fix: the block preview inside the Site Editor and post editor canvas is now styled. The stylesheet is registered on `init` and referenced from block.json (`style`/`editorStyle`), which is the only route into the iframed canvas; the shared preview shell stylesheet moves to `enqueue_block_assets`. Preview forms are inert (no submit, no focus steal).
* Improvement: custom inserter icon for the request form (studio microphone), delivered as path data and rendered as an inline SVG so it follows the editor colour.

= 0.13.0 =
* Feature: site search now finds music content in Persian, Arabic, and Latin scripts regardless of the keyboard used. Every search word is expanded into its equivalent spellings (Arabic vs Persian ye/kaf, zero-width non-joiner vs space, Persian/Arabic/Latin digits, Arabic diacritics and kashida, common Latin accents such as café/cafe, heh variants) at the SQL layer; artist/genre/mood/label/type term names and descriptive release metadata (album, catalog number, ISRC, credits) are matched through bounded sub-queries for every language; and the header autocomplete applies the same normalization. Pure-ASCII words keep a single spelling. Filters: `music_wave_script_aware_search`, `music_wave_search_taxonomies`, `music_wave_search_meta_keys`; query vars `mw_script_search`, `mw_search_taxonomies`, `mw_search_meta_keys`.
* Feature: custom song request and collaboration module. New `music-wave/request-form` block (progressive, works without JavaScript, honeypot + timing + per-actor rate limit, field-level validation, post/redirect/get with a random token instead of personal data in the URL, receipt email with a `MW-YYYY-000000` reference) and a full management screen under MusicWave → Requests & collaboration (pinned above MusicWave VIP): unread badge, statistics, status tabs, type/role/priority/search filters, bulk actions, detail view with email replies and canned templates, internal notes, accept/decline/archive, field editing, manual entry, settings (recipients, Reply-To, receipts, signature, rate limit, enabled request types, custom success notice), contextual help, privacy exporter/eraser. Requests are a private `mw_request` post type with registered statuses; no database schema change (schema stays 0.11.0). Filters: `music_wave_manage_request_caps`, `music_wave_request_message`, `music_wave_request_reply_templates`; actions `music_wave_request_created`, `music_wave_request_status_changed`.
* Note: request data is kept when the plugin is uninstalled (no uninstall routine exists); use the privacy eraser or delete requests from the management screen before removing the plugin if required.

= 0.12.0 =
* Feature: release permalinks now follow the release type. Albums live under `/album/`, tracks and singles under `/track/`, EPs under `/ep/`, mixes under `/mix/`, playlists under `/playlist/`, podcast shows under `/podcast/` and episodes under `/episode/`; releases without a mapped type keep `/music/`. The map is filterable through `music_wave_release_permalink_bases`, every base registers a full WordPress permastruct (pagination, comment pages, feeds, embeds, endpoints), and legacy or stale `/music/<slug>/` links are redirected permanently to the canonical URL.
* Rewrite rules regenerate themselves once per permalink base map (`music_wave_release_permalink_rules` option), so existing installs resolve the new URLs on the first request after the update without re-saving permalinks. No database schema change (schema stays 0.11.0).
* Feature: releases support comments; the theme's single release template renders a styled discussion section (threaded replies, pagination, comment form).
* Improvement: `GET /music-wave/v1/playlists/public` returns `covers` (up to four published cover thumbnails) so client-rendered community playlist cards show real artwork; the client-side card builder now emits the same cover-stack markup as the server.

= 0.11.3 =
* Improvement: the persistent preview player renders inline SVG controls, a separate duration read-out, a mute button, and state attributes (`data-state`, `data-mw-volume`) so the theme can style playing, paused, loading and muted states without text glyph swaps.
* Improvement: the seek bar announces \"elapsed / total\" through `aria-valuetext`; buffering shows a spinner while the toggle stays usable; `html.mw-has-player` flags an active player for layout offsets.
* Fix: persistent navigation now keeps the already-hydrated site header (hamburger overlay, expanding search) alive across soft page swaps and only refreshes its current-item markers, so the mobile menu keeps working after the first in-place navigation; pages with other Interactivity API regions (lightbox images, enhanced query pagination) fall back to a native load instead of arriving inert.
* Fix: soft navigations merge the incoming page's block-support and per-block stylesheets before painting, execute newly required scripts in dependency order before `mw-page-rendered`, sync `lang`/`dir`/`data-mw-scheme` on `<html>`, and tag history entries with the Interactivity API session id so browser back/forward no longer forces a reload.
* Maintenance: the JavaScript lint gate (`npm run lint:js`) passes again across both packages.

= 0.11.2 =
* Maintenance: normalized the editor asset formatting so the JavaScript quality gate passes cleanly.

= 0.11.1 =
* Fix: URLs generated from the current request no longer double the install folder on subdirectory installs (playlist toggles, sign-in redirects, queue redirects, and History API updates now resolve correctly).
* Fix: instant catalog filtering re-dispatches `mw-page-rendered` after swapping results, so the search suggest combobox and other re-entrant enhancements rebind to the fresh markup.

= 0.11.0 =
* New: dedicated playlist tables (schema 0.11.0) with a per-user playlist manager, public playlist directory with instant search and pagination, add-to-playlist picker, and shareable playlists with share tokens.
* New: continue-listening block — server-rendered guest, consent, and history states with a one-click REST opt-in.
* Hardening: atomic download rate limiting, per-identity guest quota buckets, one-time download tokens are consumed only after the quota check passes, and expired opaque tickets are pruned daily.
* Hardening: follow notifications fan out in deferred batches, pre-save fulfillment is deferred off the publish request, and metadata lookup is capability-gated and rate-limited.
* Fixes: share tokens rotate only when playlist visibility changes, playlist item swaps are transactional, library and playlist cleanup is post-type-guarded, and download asset lists are capped and validated before persistence.

= 0.10.0 =
* New: indexed listening-activity table (schema 0.10.0) with explicit consent controls, a durable playback queue with no-JavaScript reorder/remove/shuffle/repeat forms, and progress plus continue-listening REST endpoints.
* New: wishlist and pre-save library types with automatic fulfillment when a pre-saved release ships.

== Upgrade Notice ==

= 0.15.0 =
Term colour system unified to `TermHue`. Update theme to 0.9.2+ for matching CSS. No data loss. Clear caches after update.

= 0.14.0 =
Request form block gains modes. Existing blocks default to `both`. Review Settings → MusicWave → Requests for enabled kinds and rate limits.

= 0.13.0 =
Script-aware search enabled. No migration. If you customized `music_wave_search_taxonomies` or meta keys, verify filters still apply.

= 0.12.0 =
Release permalinks now type-based (`/album/`, `/track/` etc). Flush permalinks by saving Settings → Permalinks. Legacy `/music/` URLs 301 redirect.

= 0.11.0 =
New playlist tables (schema 0.11.0). Migration runs on activation. Verify playlists after update. Uninstall setting default OFF keeps data.

== Privacy ==

Core does not persist download audit events by default. Integrations that store `music_wave_download_event` data must provide data retention plus exporter and eraser behavior. See full policy in Settings → Privacy → Policy Guide after activating.

== Credits ==

* MusicWave Core team — https://manacore.dev
* Term color algorithm inspired by WordPress core hue distribution.
