# Changelog

All notable changes to MusicWave (theme `musicwave/`, plugins `music-wave-core/` and `music-wave-vip/`) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to Semantic Versioning with WordPress compatibility in mind.

## [Unreleased]
### Added
- VIP: settings ship two companion guides — `docs/vip-buyer-guide-fa.md` (buyer-facing setup walkthrough: where files live, how to connect a download server/CDN, where the file link comes from) and `docs/vip-settings-testing-guide-fa.md` (a per-section QA checklist with expected results and failure signs).
- VIP: the delivery screen now exposes the SFTP host-key fingerprint and the WebDAV username, so every remote-upload adapter is fully configurable from the panel.
- VIP: the protected-delivery screen groups every setting into **bounded section cards** (edge/CDN, sessions, HLS, remote upload, smart local), each with a header icon, a live «ضروری/اختیاری» badge and a live «در حالت فعلی فعال/بی‌اثر» badge that update as the delivery mode changes, plus a one-glance matrix of which mode needs which sections and what is mandatory.
- VIP: every secret field gained an «ایجاد کلید ۶۴ نویسه‌ای» (CSPRNG hex) button and a reveal toggle, so no required credential has to be typed by hand.
- VIP: the delivery screen exposes every ADR-0006 control — session IP/UA binding, sliding renewal and store; HLS segment length, packaging timing and segment routing; edge extra allowed hosts; smart-local sendfile auto-detect; and `remote_upload_insecure_tls` — each with a plain-Persian description and a hover/keyboard tooltip.
- VIP: the delivery tools are wired to their REST routes — «آزمایش اتصال به لبه/CDN», «تست اتصال آپلود», «همگام‌سازی کل کتابخانه» and «وضعیت صف» report inline results (no browser alerts).
- VIP: a dynamic «بی‌اثر در حالت فعلی» note per group (sessions / HLS / upload) appears only when the selected delivery mode does not use it, together with a live server-based sendfile suggestion.
- Core: **secure playback sessions** (`src/Playback/`): `StreamSession`, the `StreamSessionStore` contract with `DbStreamSessionStore` (dedicated `{prefix}mw_stream_sessions` table) and `StreamSessionCookie` (HttpOnly + Secure + SameSite=Lax, host-only), plus the `session` / `heartbeat` / `close` REST routes — a delivery is bound to one user + IP (and UA when present) for a short TTL with a concurrency lease, so a copied link stops working elsewhere.
- Core: `Downloads\SegmentSigner` (session-bound HMAC URL signing) and `Support\SecretBoxCipher` (sodium `secretbox` + HKDF, base64url transport) — one shared primitive for wrapping edge/upload/stream secrets; `wrap()` fails closed on a rejected key length.
- Core: `StreamFingerprint` (shared IP/UA hashing) and `StreamSessionStore::revoke_for_user()`.
- VIP: **three new opt-in delivery modes** wired through `ProviderFactory` (`local`, `remote_redirect`, `edge_signed`, `proxy`, `edge_hls`): `EdgeProvider` (signed edge redirect), `proxy` (protected file served through the site domain via X-Sendfile / X-Accel), and `edge_hls`. Edge modes **fail closed** onto local protected delivery when the secret or base URL is missing — an unsigned public URL is never emitted.
- VIP: `Delivery\EdgeConnectionManager` («تست اتصال») — a HEAD/GET probe with the hidden origin header, used by the settings screen.
- VIP: **AES-128 encrypted HLS** (`src/Hls/`): `HlsPackager` (ffmpeg/ffprobe quality ladder + `#EXT-X-KEY`), `HlsRenditions`, `AssetKeyStore` (`{prefix}mw_vip_asset_keys`), `HlsRoutes`, `StreamKeyEndpoint` (session-gated key), `StreamUrl` (single source of truth for HLS route URLs), `SessionGate` and `HlsDiagnostics`.
- VIP: **automatic upload to a remote download server** (`src/Delivery/`): one `RemoteUploadAdapter` contract with `WebhookUploadAdapter`, `S3CompatibleUploadAdapter` (local SigV4, no AWS SDK), `WebdavUploadAdapter` and `SftpUploadAdapter`, behind a `RemoteUploadQueue` (`{prefix}mw_vip_upload_queue`), a background `RemoteUploadManager`/cron worker, `RemoteUploadRoutes` («همگام‌سازی کل کتابخانه») and `RemoteUploadDiagnostics`. Idempotent, checksum-deduped, auto-retrying; credentials wrapped with `SecretBoxCipher` and never logged.
- VIP: `Delivery\LocalDeliveryDiagnostics` + `ProtectedDeliveryDiagnostics` — auto-detects the best offload method, **self-heals the protected root** (`.htaccess` / `web.config` deny files) and **auto-refreshes stale checksums**; `local_selfheal_enabled` / `local_checksum_autorefresh` default on.
- VIP: settings groups for the edge (`edge_public_host`, `edge_base_url`, hidden origin header), sessions (`session_enabled`, `session_ttl`, `session_max_concurrent`, `signed_url_ttl`), HLS/encryption (`hls_enabled`, `stream_encryption`), remote upload (`remote_upload_enabled`, `remote_upload_mode`, `remote_upload_endpoint`, credentials, `remote_upload_concurrency`) and local delivery — every option configurable from the panel, with plain-Persian help, no code required.
- Docs: `docs/vip-delivery-settings-guide-fa.md` — a complete plain-Persian reference for every option (required vs optional, per-mode needs, a jargon glossary, and ready-made profiles).
- Core: `MembershipAbsencePolicy` — three-mode `membership_absent_behavior` (`deny` / `allow` / `open_download`) with single source of truth; `open_download` opens membership + hybrid releases when no VIP provider is active.
- Core: scheme-aware delivery (`music-wave-core/src/Downloads/`): `DirectLinkAsset`, `DirectLinkUrlValidator`, `DirectLinkPermission`, `RedirectResponse`, `DirectLinkProvider`, `DeliveryRouter`, `DeliveryCapabilities` — `direct:<https-url>` support with multi-layer validation, defense-in-depth re-validation at delivery, and shared 302 security headers.
- Core: `DownloadResolver` uses `DeliveryCapabilities::supports()` instead of hard `instanceof NullDownloadProvider`; `ReleaseDownloadCatalog::is_asset_streamable()` derives format from `direct:` URL path extension.
- Core: editor gains «افزودن فایل با لینک مستقیم (CDN/میزبان خارجی)»; VIP-only provider asset button renamed; `validQualities()` accepts `direct:` and rejects raw `http(s)://`.
- VIP: `RemoteRedirectProvider` resolves `vip:<hash>` → registered `relative_path` (fixes H7 `vip%3A…` bug); shared `RedirectResponse`; path hardening.
- VIP: multi-key signing (`remote_signing_keys` with `kid`/`status: active|retired`); `MUSIC_WAVE_VIP_REMOTE_SECRET` wp-config constant priority; key rotation (`VipSettings::rotate_signing_key()`); fail-closed minimum 32-char secret.
- Core: admin settings show live «اثر فعلی» line under membership-absence policy, delivery-state banner, and dynamic warnings.
- VIP: **reserved plan terms.** `VipPlans` keeps one membership timeline per customer: a plan bought while another is active starts where the current term ends instead of running alongside it, so «۶ ماه» followed by «۱ ماه» yields an active 6-month term and a 1-month term reserved for day 181. `detailed_active_grants()` reports `is_reserved`, `starts_at`, `starts_label` and `reserved_days`, `active_levels()`/`user_has_access()` stay false until the start date, the umbrella `mw_vip` level follows the live term, and order meta gains `_mw_vip_granted_starts`. Refunding or manually removing the blocking term re-lays the chain (`rebuild_reserved_chain()`), so the paid shorter plan starts immediately instead of waiting for a date that no longer exists.
- Core: `LibraryButton` gains an opt-in «نمایش تعداد کاربران کتابخانه» toggle (`showCount`). The badge renders an aggregate only — `LibraryRepository::count_for_item()` returns a count and is cached for 5 minutes — and is printed as an empty, `hidden` slot at zero so `library.js` can keep it in sync with the visitor's own toggle.
- Core: section-titled blocks (`related-releases`, `artists-shelf`, `taxonomy-shelf`, `continue-listening`, `public-playlists`, theme `release-shelf`/`release-slider`) gain an optional `icon` attribute rendered under the title through `SectionHeader::title()` + `ActionButtonStyle::optional_icon()`; the default is **no icon**.
- Theme: `assets/release-content.js` adds «نمایش بیشتر محتوا» / «نمایش کمتر» to the single release description. The content stays fully visible without JS — the clamp class is only added after the script measures it.
- Core: **page-builder bridge.** `Support\BlockShortcode` registers `[musicwave_block name="music-wave/…" attributes='{…}']`, rendering any project block through `render_block()` for Elementor and other builders' Shortcode widgets. Only the `music-wave/` namespace, declared attribute keys and valid JSON are accepted; see `docs/elementor-compatibility.md`.
- Core: `artists-shelf` gains `showAll` — «همهٔ هنرمندان» lifts the cap (bounded by `ArtistIndex::MAX_TERMS`) and suppresses the paging controls.
- Theme: `release-shelf` and `release-slider` gain `showAll` («بارگذاری همهٔ انتشارها»); `release-slider` also gains the shared `columns` card-size attribute.

### Changed
- VIP: the delivery-mode chooser and the «راهنمای سریع» matrix are now built from one shared data source (`mode_labels()` / `mode_guides()` / `mode_order()`), so the picker, the live hint and the matrix can never describe a mode differently; the matrix highlights the selected mode and shows each mode's required sections as chips.
- VIP: the eight delivery sections dropped their numeric prefixes and read in a fixed order — vault, local, edge/CDN, remote redirect, sessions, HLS, automatic upload, smart local — each a bordered card with an icon, a required/optional badge, an «applies in the current mode» badge and its mode list; a section that does not apply collapses while its heading stays.
- VIP: the delivery-panel intro now states the guarantee in one sentence and shows three benefit chips (signed short-lived links, the key never on the CDN, fail-closed fallback) instead of a technical paragraph.
- VIP: the private-vault and health cards are now one bounded, mode-gated section — it stays open for `local`, `proxy`, `edge_signed` and `edge_hls`, and collapses with an explanation for `remote_redirect` unless «آپلود خودکار» is on (where the local vault is only staging).
- VIP: the «حذف داده‌ها هنگام حذف افزونه» description now names every table it covers (vault, HLS keys, upload queue, playback sessions, audit log).
- VIP: secret fields are no longer uniform — the CSPRNG «ایجاد» button appears only for secrets MusicWave owns (edge/remote signing secrets, origin-header token, webhook token), while S3 keys and WebDAV/SFTP passwords show a «where to get it» note and no generator.
- VIP: delivery-provider labels state where files actually live, and a live hint under the chooser names exactly which numbered sections the selected mode requires.
- VIP: every delivery group is a bounded section in a fixed 1–7 order (local, edge/CDN, remote redirect, sessions, HLS, automatic upload, smart local) with an icon, required/optional badge, an «applies in the current mode» badge and the section's modes; a section that does not apply collapses (its header stays visible) instead of hiding rows piecemeal.
- VIP: local delivery and remote-redirect delivery are now full sections with step-by-step connection guidance — including what the download server must verify and why a static CDN should use an edge mode instead — and the signed-URL preview moved into the remote section.
- VIP: the quick-guide matrix now names the numbered sections required by each mode.
- VIP: settings wording de-jargoned — «لبه» explained as the public CDN domain, «راز امضای لبه» → «کلید مخفی امضای لینک», «راز امضای راهدور» → «کلید مخفی امضای لینک‌های راهدور», «میزبان نشانی پایه» → «دامنهٔ همین نشانی پایه», «هدر احراز مبدأ» → «هدر مخفی احراز هویت مبدأ», «شتابدهی سرور» → «روش ارسال فایل» — each with a plain description and a hover/keyboard tooltip.
- VIP: `HlsPackager` takes the segment length from `hls_segment_seconds`; the four upload adapters honour `remote_upload_insecure_tls`; the settings JS gained an accessible, styled help tooltip (`dashicons-editor-help`, hover/focus bubble) and a `list_field` input for host allowlists.
- VIP: fixed five malformed `style="` attributes in the remote-upload group (unterminated attribute on the SFTP port, path-prefix, size-cap, keep-local and retry rows).
- VIP: `Plugin::MIN_CORE_VERSION` replaces the former bootstrap `MUSIC_WAVE_VIP_MIN_CORE_VERSION` constant — one source of truth for the compatibility gate.
- VIP: deactivation unschedules every cron event (`music_wave_vip_upload_worker`, `music_wave_vip_daily_maintenance` and the VIP plan expiry checks); activation (re)schedules them, and a registered `music_wave_every_minute` interval backs the upload worker.
- Core: admin concerns (`DiagnosticsChecks`, `SettingsFormTrait`) carry the ADR-0006 delivery / session / HLS / upload checks and fields.
- Core: `music-wave-core` version `0.15.0` → `0.16.0`; `MUSIC_WAVE_CORE_VERSION` updated.
- VIP: version `0.5.1` → `0.5.2`; `MUSIC_WAVE_VIP_MIN_CORE_VERSION=0.16.0`.
- Core: all four `has_filter('music_wave_download_provider')` status checks replaced by `DeliveryCapabilities::probe()->is_available()`.
- Core: `Settings::sanitize()` accepts `open_download` for `membership_absent_behavior`; admin UI renders all three modes.
- Core: `Plugin.php` composition root wires `DeliveryRouter` + `DeliveryCapabilities` behind the unchanged `music_wave_download_provider` filter contract.
- Docs: ADR `0005-membership-absence-policy-and-direct-delivery.md`; `docs/vip-integration.md` signing drift fixed.
- VIP: a subscription cart holds exactly one VIP plan — `_sold_individually` on plan products, replace-on-add validation, and a `woocommerce_add_to_cart` prune + enforce pair; every «خرید و فعالسازی» click lands on checkout.
- VIP: subscription-only checkout drops billing/shipping sections and the order-summary product list across both classic and block checkout; the `order-pay` retry screen joins the thank-you screen on the shared `body.mw-pay--subscription` status card.
- Theme: global player cover art now resolves for tracklist playback; the shuffle trigger only renders when a playable tracklist exists.
- Theme: `.mw-account-library__item` action cluster no longer inherits `.mw-download-action` panel geometry (grid margins and inline-size containment); the download control is a compact accent pill.
- Theme: the bottom bar is a real `core/navigation` block inside **every** header template part, so it can be edited in the Site Editor for all five headers; turning it off removes it everywhere, collapses `--mw-rail-tabbar-height`, re-docks `mw-global-player` to the bottom edge, and releases the reserved page padding.
- Theme: the global header admin screen (`Appearance → سربرگ MusicWave`) is gone. Header selection lives in the Customizer, header composition in the Site Editor, and route pages provision themselves once per routes version.
- Theme: `music-wave/rail-toggle` is the single owner of the rail/drawer trigger (one instance per stream header, top-bar placement visible at every width), with a reworked inspector copy and `bar` as the block default.
- Theme: `collection-list` rows drop the redundant «افزودن به صف» / «افزودن به کتابخانه» buttons — a row keeps its play, quality select and download actions — so the action cell no longer crowds the quality selector or wraps badly at narrow widths.
- Theme: **`music-wave/theme-toggle` and `music-wave/theme-text` meet the WordPress Settings tab halfway.** The toggle wrapper *is* the button core prints support styles on, so it now paints through `--mw-toggle-bg` / `--mw-toggle-border` / `--mw-toggle-color`: colour, border, radius and shadow chosen in the inspector's Styles/Settings panels win over the component defaults instead of being overridden by them, and hover/focus flips the same properties. `theme-text` selects a real theme string (`— انتخاب کنید —`, latest releases, «موسیقیای پیدا نشد.», not-found title/message, cart/checkout/account titles, «قدرتگرفته از MusicWave») and its tag control offers the tags the renderer actually accepts (`p`, `span`, `h2`, `h3`), so an inspector choice can no longer produce markup the front end ignores.
- Core: `related-releases` restores its shelf geometry **inside** «سبک نمایش»: «چیدمان» (`layout` — grid / scroll / list), «شکل تصویر» (`imageShape` — square / landscape / portrait / circle) and «ستونهای دسکتاپ» (`columns`, 2–6) sit beside the display-style variation instead of in parallel panels. The defaults stay `grid` / `square` / `4`, so saved blocks and pinned card markup are unchanged.
- Theme: every top-level section title reads `--mw-section-title-size`, bound by default to `--wp--preset--font-size--large`, so Site Editor global styles own the size instead of per-block values.
- Core: secure play buttons, collection play rows and preview-player buttons render the canonical `play-filled`/`pause` vector pair through `ActionButtonStyle::play_toggle_icon()`; CSS swaps the two halves on `aria-pressed`, and the legacy JS glyph writes no longer overwrite SVG markup.
- Core: release details no longer lists «کیفیت صدا» — audio quality is a per-file download concern, not release metadata.
- Core: the five card-size controls (`related-releases`, `artists-shelf`, `taxonomy-shelf`, `release-shelf`, `release-slider`) are numeric steppers labelled «ستونهای دسکتاپ»; the selected count drives both the grid and the horizontal-scroll lane.
- Core: `related-releases` titles take per-section icons (`sameArtistIcon`, `similarIcon`); «نمایش پیوند بخش» and its label are gone.
- Core: `artists-shelf` paging is a single «حالت صفحه‌بندی» select (`none` / `internal` / `external`); a saved `browseIndex` still opens the external index.
- Theme: `release-slider` `imageSize` is now image quality only; card width follows `columns`.
- Theme: the single-release description is a padded prose panel with a standfirst and accent rule; the show-more control is centred with a chevron and `aria-controls`.
- i18n: the `en_US` catalogues were regenerated from the current source and completed — every Persian source string (including all new delivery/session/HLS/upload settings and route messages) now has an English translation, verified by `composer check:translations` (3432 strings).

### Fixed
- VIP: uninstall now drops `mw_vip_asset_keys` and `mw_vip_upload_queue` and clears all three VIP cron hooks (`music_wave_vip_expiry_check`, `music_wave_vip_upload_worker`, `music_wave_vip_daily_maintenance`), not just the assets table and one hook.
- Core: uninstall now drops the `mw_stream_sessions` table.
- VIP: the upload worker no longer claims queue rows when the pipeline is off or the mode needs no remote bytes — previously each claimed row was skipped by `sync()` and could stay stuck in `uploading` until the stale-reclaim window.
- VIP: `edge_hls` downloads now point at the signed original file (`/s/…`) instead of an HLS-manifest path; playback keeps using the site-side HLS routes.
- VIP: choosing `edge_hls` now enforces `hls_enabled`, so the player can never receive dead manifest URLs after a mode switch.
- VIP: secret keep/clear handling now lives only in `VipSettings::sanitize()`; the duplicate flag map in `SettingsPage` (which could drift) is gone.
- VIP: hidden mode-specific credential rows no longer submit empty duplicate values for shared keys (`remote_upload_endpoint`, `remote_upload_auth_token`, `remote_upload_access_key`) — previously a token typed into the visible row could be silently discarded on save.
- VIP: the upload queue only claims a row it actually transitioned (no double processing), recovers rows stuck in `uploading`, re-enqueues `failed` assets on explicit retry, and no longer double-counts attempts; freshly registered assets and freshly packaged HLS assets are auto-enqueued through `music_wave_vip_asset_registered` / `music_wave_vip_hls_ready`.
- VIP: the upload REST routes use the real `manage_mw_protected_assets`/`manage_options` capabilities (the invalid `edit_post` meta-capability is gone); library-wide sync and connection tests are administrator-only.
- VIP/Core: `session_sliding_renewal=disabled` is honoured by the heartbeat; `stream_encryption` defaults to `aes128`; `hls_proxy_segments` and `session_bind_*` are no longer stored-but-unused.
- Core: `ProtectedFileProvider` returns `206 Partial Content` for an explicit `bytes=0-` range instead of `200`.
- Core/VIP (deep-hardening pass): the HLS packager deletes the raw AES key temp file on every exit path (it was previously left on disk, defeating the wrapped-key design); playback sessions are now enforced against the recorded client IP/UA (`session_bind_ip`/`session_bind_ua` actually apply); sessions are revoked on logout, password reset, and user deletion; the HLS key endpoint pins the key version named in the playlist so a repackage mid-playback cannot swap the key; the key response is `private, no-store` + `Vary: Cookie`.
- VIP: remote-upload endpoint validation resolves DNS and rejects private/loopback/metadata addresses; upload HTTP requests no longer follow redirects and enable WordPress' URL guard; the S3 SigV4 canonical URI now matches the requested path (empty-key and percent-encoded keys no longer 403); SFTP can pin the server host-key fingerprint (`sftp_host_fingerprint`).
- Core: the direct-link validator rejects IPv4 shorthand/hex/decimal/octal and IPv6-mapped private literals; the playback stream route is rate-limited; the reported token TTL matches the 900-second token ceiling.
- Core: `trim(): null given` crash in `HlsPackager::binary_available()` (shell_exec returns null when the binary or function is unavailable) — now guarded, with a `where`/`command -v` probe and a stderr redirect.
- Core: `EdgeConnectionManager` undefined `$error_probe`; the stale 3-arg `mark_synced()` call; and `WP_REST_Request::get_url()` (which does not exist) meant HLS manifest/variant/segment/key signature verification could never pass — replaced with the new `StreamUrl`.
- VIP: `AssetKeyStore::has_any_key()` guards on a read-only `table_exists()` probe so diagnostics render before the schema has ever been migrated.
- Docs: ADR `0006-edge-signed-delivery-and-encrypted-hls.md`; ADR `0005` cleaned of garbled text.
- Core/H7: `vip:<hash>` remote redirect now emits real asset path instead of URL-encoded `vip%3A<hash>`.
- Core/H6: raw `http(s)://` asset IDs rejected with `mw_raw_url_not_allowed` (400) in REST + schema sanitizer.
- Theme: dedicated **هنرمندان** (`/artists/`, template `page-artists`) and **سبک‌ها** (`/genres/`, template `page-genres`) browse pages, provisioned with the other route pages and linked from every bundled header.
- Theme: Customizer controls for the bottom navigation bar (`musicwave_chrome_tabbar`: off / mobile / tablet+mobile), its labels, and the header width default.
- Core: **artists index pagination.** `music-wave/artists-shelf` gained `pagination` + `perPage` attributes; the pager is a real `<nav>` with «قبلی/بعدی», numbered pages, `aria-current="page"`, a «صفحهٔ N از M · K هنرمند» status line and `rel="prev"/"next"` links, and it pages the *all artists* source with a bounded `offset` query (current page clamps to the last page). Curated (`manual`) and shuffled (`rand`) shelves stay single-page by design. Shared `pagination.css` module + inspector toggle, page-size range and conditional visibility.
- Docs: `docs/phpstan-level5-prompt-fa.md` — local PHPStan level 5 runbook plus a ready-to-paste remediation prompt (the analyse gate cannot run in the cloud sandbox).
- Theme: **«جستجوی سربرگ MusicWave» is a fully configurable block.** The `music-wave/header-search` inspector is a grouped Site Editor panel: independent layout per breakpoint (`desktopMode`/`tabletMode`/`mobileMode` — inline field, compact, icon pop-up or slide-down bar), placeholder and button copy, suggestion limit, scope tabs, recent searches, quick tags, voice search, keyboard shortcut and footer hints, with conditional visibility so an option appears only when it can take effect. The retired `maxWidth` pixel control and the editor device-preview switch are gone: the field now sizes itself from the block's own layout (Styles → Layout / the surrounding group), so the editor tab, Styles and the front end cannot disagree.
- Core: **the artists index is five composable blocks instead of one page-shaped shelf.** `music-wave/artists-hero` (eyebrow, `heading` + `level`, intro, catalogue stats), `music-wave/artists-toolbar` (GET search form, «پیکارتین»/«الفبایی» sorting tabs), `music-wave/artists-alphabet` (Persian letter row, `showAll`/`allLabel`/`showCounts`, inert chips for letters with no artists), `music-wave/artists-pagination` (`perPage`, `showStatus`) and the extended `music-wave/artists-shelf` (`browseIndex`, `showCrowns`, `showResultCount`, `noResultsMessage`) are each independently movable, restylable and removable in the Site Editor, and each carries the full WordPress `supports` set (anchor, align, border, colour, spacing, typography, shadow).
- Core: `Catalog\ArtistIndex` — one browsing-state service (`mw_artists_page`, `mw_artists_search`, `mw_artists_letter`, `mw_artists_sort`) that every artists block reads and links through, so search, letter filter, ordering, page arithmetic and generated URLs can never drift between the hero, toolbar, alphabet, shelf and pager (shared `Concerns\ArtistsPager` renders both the shelf pager and the standalone block). The letter filter normalises Arabic variants (`ي ى ئ → ی`, `ك → ک`, `ة ۀ → ه`, `ؤ → و`, `أ إ ٱ → ا`), search matches names and slugs, and canonical defaults (page 1, popular order) stay out of the URL.
- Core: artist cards gained the 1-2-3 popularity crown badges (`showCrowns`, only on the count-ordered first page) and the «نمایش X از Y خواننده» result line (`showResultCount`); release counts read «۱۰ اثر».
- Theme: **`page-artists` is composed from those blocks** — hero, toolbar, alphabet, shelf, pagination and the genre shelf are separate top-level sections, and the hero `h1` is the page title, so the whole banner is editable text rather than a template heading.
- Core: **«عوامل انتشار» (`mw_credits`) is fillable from the release screen.** The release metabox renders an ordered repeater («افزودن عامل» plus a remove button per row) posting `mw_credits[<n>][name|role]`, so the pairs arrive at the same sanitizer the REST route uses. An emptied repeater posts an empty scalar and clears the meta instead of leaving the old value in place. Each row is a **نام** (required) and an optional **نقش**; rows without a name are dropped, and both values are stored as free text so Persian roles survive.
- Core: `collection-list` play rows swap the `play-filled` vector for the pause vector while playing (`ActionButtonStyle::play_toggle_icon()` + CSS `aria-pressed` state); the legacy glyph swap no longer overwrites SVG icons.
- Theme: `collection-list` rows get a token-based surface/outline skin, wrapping action clusters, ellipsized labels, and a single container-query breakpoint (the duplicated `42rem` viewport block is gone).
- Theme: **`music-wave/public-playlists` «چیدمان» now changes what the editor and the front end show.** The column modifier is printed for the real grid layout only — on `--scroll` and `--list` it won the cascade against their own `grid-template-columns`, so the artwork rail flattened into columns and the compact list broke into a grid — and the editor preview builds its class list through the same rule the PHP renderer applies. The circular artwork radius moved below the shared plate rules, where `art--circle .art-grid` and `art .art-grid` have equal specificity and source order decides, so «شکل اثر هنری» is no longer swallowed. The list layout also gained its row frame (plate beside the copy, stacking again on narrow cards).
- Theme: **PHP warnings from `wp-includes/blocks.php` when the bottom bar is disabled.** Stripping the bar from a header part removed it from `innerBlocks` only, so `innerContent` kept a placeholder for a block that no longer existed; core then rendered a desynchronised tree ("Undefined array key 2/3", "Trying to access array offset on null", "foreach() argument must be of type array|object, null given", 'Undefined array key "blockName"'). Both lists are now rewritten in lockstep (`SiteHeaderManager::strip_tabbar_block()`), so the shaped template part stays a valid block tree.
- Theme: `header-stream-end` no longer opens its top bar with the rail toggle — the toggle now closes the row next to the inline-end rail, the brand starts the row, and the search keeps the middle, so the site title is no longer pushed aside.
- Theme: the site logo + title stay visible on phones; the `≤40rem` rule that hid `.wp-block-site-title` is gone, and the editor canvas no longer hides either brand row.
- Theme: both rail headers preview like the front end in the Site Editor — the bottom bar owns its own grid row under the rail + top bar, the mobile canvas shows the rail as the open drawer instead of a horizontal strip, and the rail-toggle block is selectable instead of `display:none`.
- Theme: `.mw-playlist-picker__panel` is viewport-bounded at every breakpoint and becomes a docked bottom sheet below `40rem` (above the mobile bar / safe area) instead of overflowing the row.
- Theme: quality-selector rows in `collection-list` stack their action cluster as one wrapping flex line (the 2-column grid pushed play/queue/library into the selector's cell).
- Theme: the header search submits through **one** affordance. The core `wp-block-search__button has-icon wp-element-button` button and all of its styling are gone, leaving `.mw-header-search-block__field-icon` as the single inside-field submit; the `.mw-catalog-suggest__list` panel, including its empty ("not found") state — icon, title, description and action buttons — was rebuilt on shared tokens so the field, the pop-up and its 404 state read as one component at every breakpoint.
- Theme: **«دانلود این انتشار»** file rows reflow to one column and full-width actions below `30rem`, keep the `--no-quality` variant aligned, and wrap long titles instead of clipping them.
- Theme: `imageShape` is now a two-token contract (`--mw-art-ratio` / `--mw-art-radius`) shared by shelf, slider, hero-slider and artist-shelf artwork; covers always `object-fit: cover` inside the shaped frame, inherit its radius, and the circular shelf cover centres its play trigger inside the disc.
- Theme: **`artist-profile` no longer fakes a portrait.** The monogram stand-in (`mw-artist-profile__placeholder`), its dead `mw-artist-profile__media--*` modifier and the root `--placeholder` class are gone: the avatar frame is emitted only for artists that really have a photo, and the count label lost its identical singular/plural `_n()` call. `layout` is now the single source of truth — `resolve_layout()` owns the four «الگوی چیدمان پروفایل» options (`compact`/`list`/`card`/`spotlight`), the legacy `compact` boolean is demoted to an alias that can no longer override an explicit choice, the retired `slider` slug maps to `spotlight`, and the rule is mirrored 1:1 between PHP and the inspector (`blocks.js`). The card avatar shrinks at `38rem`/`30rem`, actions stack, names wrap, and reduced-motion is honoured; `library.js` also stops querying the non-existent `mw-artist-profile__library` container.
- Theme: removed the duplicated bottom-bar skin in `rail.css`, the orphaned `.mw-rail__header`/`.mw-rail__foot`/`.mw-stream-account__bell` rules, the dead `.mw-collection-list__download-group`, `.mw-brand__text` and `.mw-preview-button-wrap` selectors, and the JS-synthesised toggle that reappeared when the block was deleted.
- Theme: **the default layout width is full-bleed, and Styles → Layout still owns it.** `settings.layout.wideSize` is back to `100%` so an untouched install renders edge to edge — the width the stream header is composed for — instead of a centred `1440px` column; `contentSize` stays `760px` for the readable single-column measure, and both fields remain the built-in "Content width" / "Wide width" controls with a unit picker, so an admin can retype either one. `allowEditing` and `allowCustomContentAndWideSize` stay on, and `settings.custom.header.contentSize` now falls back to `100%` rather than the retired `1440px`. The header row keeps tracking it (`max-inline-size: min(100%, var(--mw-header-width))`), with `.is-style-mw-header-full` and `.alignfull` opting out of the cap. `styles.blocks.core/group.layout` support stays removed because it is not schema-valid.
- Theme: **theme presets win over core's defaults.** `settings.typography.defaultFontSizes: false` and `settings.spacing.defaultSpacingSizes: false` stop core's default font-size and spacing slugs from shadowing the theme's fluid `clamp()` scales, so `--wp--preset--font-size--*` and `--wp--preset--spacing--*` resolve to MusicWave tokens instead of core's fixed `1.75rem`-style steps.
- Theme: **`collection-list` end-of-row actions honour «سبک نمایش».** The `icon-with-text-label` style was ignored by the trailing action cluster (play, quality, download); it now prints the label beside each icon there too, and the inspector only offers a style the row can actually render.
- Theme: **`artist-profile` card skins reach every layout pattern.** «سبک ظاهری کارت» (`editorial`/`minimal`/`glass`) only dressed the banner portrait; «شاخص هنرمند» (`spotlight`) now renders the same skin, so the choice no longer silently no-ops on a layout that supports it.
- Theme: the stream top bar stops repeating the brand at `≥64rem` (the rail already carries it). Core's `.wp-block-group.is-layout-flex` re-showed it, so the hide rule carries three classes instead of one.
- Theme: `music-wave/rail-toggle` PHP fallback now defaults to the `bar` variant, matching `block.json` instead of contradicting it.
- Theme: the single-release description gets its own prose scale (measure, rhythm, headings, lists, quotes, figures, code) and a token-based reveal control instead of inheriting raw block defaults.
- Core: preview-player «مینیمال بی زمینه» (`ghost`) now renders when chosen in Styles — the block-style variation outranks the `variant` default.
- Core: `artists-shelf` compact list honours «اندازه تصویر» and shows the biography excerpt by falling back to the artist description.
- Theme: show-more is added only when the clamp really hides content (measured overflow, re-run after fonts/images load) instead of a 380 px guess.

### Security
- VIP: remote-upload credentials (`remote_upload_auth_token`, `remote_upload_secret_key`, `sftp_password`) are stored **wrapped** at rest (`sodium_crypto_secretbox` + HKDF, `enc:v1:` prefix, distinct context from the HLS key wrap) and unwrapped in memory for the adapters; legacy plaintext passes through and is wrapped on the next save. The previously decorative «پاکسازی مقدار ذخیره‌شده» checkbox now actually clears the stored secret.
- VIP: `ProtectedFileProvider` refuses to emit an `X-Sendfile` header when the resolved path contains control characters (falls back to PHP streaming); `local_stream_chunk_kb` now drives the real PHP range-stream chunk size.
- VIP: the AES key never reaches the CDN — issued only from the site and only for a valid session (`StreamKeyEndpoint` + `SessionGate`); HLS segments are worthless without it.
- Core/VIP: edge/upload/stream secrets are stored wrapped (`sodium_crypto_secretbox` + HKDF) and **never logged**; edge modes fail closed on a weak or empty secret; playback cookies are HttpOnly + Secure + SameSite=Lax; upload endpoints are validated as HTTPS by `DirectLinkUrlValidator` (private/loopback/metadata IPs and userinfo rejected).
- Core: direct-link URLs reject IP literals, localhost, single-label hosts, `.local/.internal/.localhost`, userinfo, non-443 ports, control characters, spaces/backslashes; host allowlist with prefix-wildcard support.
- VIP: `vip:<hash>` never leaks into remote URL path; registry row required (fail-closed); HMAC-SHA256 signs `url|expires|mode|kid`; `kid` in URL for key rotation.

### Removed
- Theme: six full-page demo patterns (`demo-account-studio`, `demo-account-commerce`, `demo-release-showcase`, `demo-artists-directory`, `demo-discovery-browse`, `demo-community-requests`) with their shared `src/Patterns/DemoComposer.php`, the `musicwave-demos` pattern category and the now-dead `.mw-demo__*` rules in `editorial.css`; the bundled pattern library is the seven composable sections.
- Core: unused `ManaCore\MusicWave\Vip\Plugin::is_active()` — `Support\VipModuleStatus::is_active()` stays the single Core-side probe for the VIP bootstrap constant.
- Core: `related-releases` `showSectionLink` / `sectionLinkLabel` and the dead `.mw-related-releases__more` renderer/CSS path.

## [0.15.0] - 2026-10-17 - Core
### Changed
- Design: term colours unified around `TermHue` source of truth, `mw-term-hue-N` modifier, legacy aliases.
### Security
- Hardening around metadata providers, playlist escaping, lyrics meta auth (planned FIX-16/17/18).
### Added
- Uninstall: self-contained `uninstall.php` for Core and VIP with opt-in `delete_data_on_uninstall` default OFF, multisite aware, never deletes customer content.

## [0.9.2] - 2026-10-17 - Theme
### Added
- Header content width Site Editor setting, filter `musicwave_header_width_mode`, token `--mw-header-width`.
- Self-hosted fonts: Plus Jakarta Sans Variable 27KB latin + Syne Variable 34KB latin, swap, unicode-range, zero Google requests.
### Changed
- `style.css` Description English (FIX-09), version bump 0.9.1 → 0.9.2.
- `screenshot.png` placeholder 1200x900, capture script `tools/capture-theme-screenshot.js`.

## [0.5.1] - 2026-10-16 - VIP
### Fixed
- MIME helper no longer calls `finfo_close()` (PHP 8.5 compat).
- DB access type-checks `$wpdb` object.

## [0.14.1] - 2026-10-10 - Core
### Fixed
- Provider failures vs empty searches, encode external API queries, plain-permalink REST URLs.
### Performance
- Cover art only for selected result, isolate caches by track and Spotify tokens.
### Security
- Cap cover downloads, clean temp files, require upload permission.

## [0.14.0] - 2026-10-05 - Core
### Added
- Request form block modes `both|song|collab`, editable eyebrow/heading/intro/labels, type/role pickers.
### Security
- Kinds bound into nonce, intersect with enabled kinds, refuse other kinds.

## [0.9.1] - 2026-10-08 - Theme
### Added
- Independent release facts, editable request-page headings.
### Fixed
- Empty shelf headings, vinyl header container, release dates in spec-sheet.
### Security
- Validate header settings within save boundary.

## [0.5.0] - 2026-10-08 - VIP
### Fixed
- Core integrations tab no longer resets VIP module (full-form marker).
- Protected assets verified against SHA-256 at delivery.
### Security
- Empty membership-source fails closed, expiry sweep throttled 15-min cooldown.

## [0.13.0] - 2026-09-30 - Core
### Added
- Script-aware search (Persian/Arabic/Latin normalization), filters `music_wave_script_aware_search`, query vars.
- Request & collaboration module: block `music-wave/request-form`, management screen, private `mw_request` post type, receipt `MW-YYYY-000000`.

## [0.12.0] - 2026-09-20 - Core
### Added
- Type-based release permalinks `/album/`, `/track/`, `/ep/`, `/mix/`, `/playlist/`, `/podcast/`, `/episode/`, filterable `music_wave_release_permalink_bases`, 301 legacy.
- Comments on single release template.

## [0.11.3] - 2026-09-15 - Core & Theme
### Changed
- Persistent preview player SVG controls, duration, mute, state attributes, seek bar `aria-valuetext`.
### Fixed
- Persistent navigation keeps header alive across soft swaps, merges block-support stylesheets, syncs `lang/dir/data-mw-scheme`.

## [0.4.0] - 2026-09-10 - VIP
### Added
- WooCommerce plan products source `VipPlans`, `mw_vip` role, grant/revoke via compare-and-set, expiry.

## [0.11.0] - 2026-09-01 - Core
### Added
- Playlist tables schema 0.11.0, public directory, continue-listening block, atomic rate limiting, guest quota, one-time tokens, follow notifications batches.

## [0.10.0] - 2026-08-15 - Core
### Added
- Listening activity table schema 0.10.0, consent controls, queue, progress REST.

## [0.9.0] - 2026-08-10 - Theme Phase 4 prerequisites
### Added
- Stream rail header `header-stream`, templates `page-request-song`, `page-request-collab`, `page-stream`, tokens `--mw-rail-*`, module `rail.css`, account dashboard Phase 4 blocks `dashboard-welcome`, `dashboard-stats`, `dashboard-subscription`, modern minimal compact CSS, real VIP data via filter `music_wave_vip_membership_panel`, renewal at 90% threshold.

[0.15.0]: https://manacore.dev/musicwave/releases/0.15.0
[0.9.2]: https://manacore.dev/musicwave/releases/0.9.2
[0.5.1]: https://manacore.dev/musicwave/releases/0.5.1
