=== MusicWave ===
Contributors: manacore
Tags: block-theme, block-patterns, full-site-editing, music, woocommerce, rtl-language-support, accessibility-ready, e-commerce
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.9.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Block theme for music catalogs, stores and streaming-inspired experiences with Site Editor configurability and self-hosted fonts.

== Description ==

MusicWave is a block theme (FSE) for music catalogs and stores, built for MusicWave Core and MusicWave VIP.

* **Design**: dark, light and system scheme via `theme.json` tokens (`--mw-color-*`, `--mw-radius-*`, `--mw-rail-*`), 4 style variations (Default, Aurora, Studio, Sunrise), 7 editable patterns, modular CSS in `assets/css/components/` (account-library, commerce, rail, playlists, shelf, etc), logical properties RTL-first, self-hosted fonts (Vazirmatn Variable Persian + Plus Jakarta Sans latin 200-800 + Syne latin 400-800, swap, unicode-range) — zero Google requests.
* **Header system**: 5 template parts (default, centered, minimal, stream side rail, stream-end), width mode filterable via `musicwave_header_width_mode` and Site Editor setting, `--mw-header-width` token syncs all variants, rail toggle with `aria-expanded`, `aria-controls`, localStorage `musicwave-rail`, theme toggle button class `nbp-theme-toggle-fallback` in top header bar. The bottom tab bar follows the Customizer scope `musicwave_chrome_tabbar` (off / mobile / tablet+mobile): it is never painted at desktop widths, and switching it off removes it everywhere, collapses `--mw-rail-tabbar-height` and re-docks the global player to the bottom edge. `music-wave/header-search` is a first-class block with its own Site Editor panel — independent layout per breakpoint (`desktopMode`/`tabletMode`/`mobileMode`: inline field, compact, icon pop-up or slide-down bar) plus max width, placeholder/button copy, suggestion limit, scope tabs, recent searches, quick tags, voice search, keyboard shortcut and footer hints, with conditional visibility. It submits through one inside-field icon (`.mw-header-search-block__field-icon`); the core search button and its styling are gone.
* **Site layout**: global widths come from `Settings → Site Editor → Styles → Layout` and are the single source for the constrained content column and footer width, the header row (`min(100%, var(--mw-header-width))`) and fluid-type sizing. Defaults are "Content width" `760px` (the readable single-column measure) and "Wide width" `100%`, so an untouched install renders full-bleed — the width the stream header is composed for; `Allow custom content and wide width` stays on so admins can retype either value with its unit picker. Header template parts can override with the "wide"/"full" block styles to span the container or full width.
* **Templates**: Home, Release Archive, Single Release (comments, discussion, hero, tracklist, same-artist/similar), Artist/Genre/Label/Mood/Type archives, Browse, Account (composes `account-dashboard` with every panel enabled beside a `release-shelf` in a 68/32 columns split), Cart/Checkout (virtual-only detection `mw-checkout--virtual-only`), Page with/without sidebar, Request Song/Collab/Requests (Core 0.14.0+), Stream page with rail.
* **Blocks**: 10+ theme blocks, Core 32 dynamic blocks including `account-dashboard` (showWelcome toggle, rename library to “کتابخانه علاقه‌مندی‌ها ♥”, membership→“اشتراک VIP”, orders→“سفارش‌ها”), `dashboard-welcome` (showAvatar/VipBadge/SubscriptionMeta/Renewal/introText), `dashboard-stats` (showEntitled/Saved/Artists/compact), `dashboard-subscription` (showActive/Progress/OrderLink/Renewal/heading with progress bar gradient accent→gold), `release-shelf`, `artists-shelf`, `taxonomy-shelf` with accessible nav buttons, `stream-search` with mode selector (simple vs icon-popup) and max suggestions control, single suggestion container inside popup, `playlist`, `library`, `queue`, `continue-listening`, `request-form`, plus the theme's `header-search` (per-breakpoint layout, single inside-field submit, styled suggestion list with a designed "not found" state) and `artist-profile` (compact/list/card/spotlight layouts, `editorial`/`minimal`/`glass` styles, avatar frame rendered only when a real photo exists). `collection-list` rows keep their play, quality-select and download actions only — the per-row queue/library buttons were removed.
* **Commerce**: WooCommerce support, virtual-only checkout (no shipping/address, email required, phone optional hidden, company hidden), cart contains only virtual subscription detection `cart_contains_only_virtual_subscription()`, direct checkout from plans via `?add-to-cart=X&mw_direct_checkout=1` + redirect filter `woocommerce_add_to_cart_redirect`.
* **Account dashboard (Phase 4)**: modern minimal compact — 56px avatar, VIP badge gradient + border, subscription-meta flex wrap gap 0.375rem, renewal box background 10% #ffb700 border 30%, stats compact flex row gap 0.5rem li `flex 1 1 8rem` padding 0.75rem, tabs scrollable gap 0.375rem `::-webkit-scrollbar display:none`, tab padding 0.625rem 0.875rem border transparent + bottom 2px transparent, active background 10% accent + border-bottom accent, `focus-visible outline 2px accent`, responsive <40rem column, `prefers-reduced-motion` no animation. Data source filter `music_wave_vip_membership_panel` — real grants `detailed_active` with level/expiry/remaining/percent_used/is_lifetime/order_id, `renewal_needed` at 90% threshold filterable.
* **Accessibility**: skip link verified, focus-visible, shelf nav aria-label/aria-controls/keyboard arrows/aria-live, search combobox roles `combobox/listbox/option` + `aria-expanded/aria-activedescendant` + Esc/Enter/Arrow, form labels + `aria-describedby` + `role=alert`, bidi `dir=auto` + `unicode-bidi: plaintext`.
* **Performance**: conditional CSS/JS loading (has_block, is_post_type_archive), critical above-fold preload for main font face, `defer` + dependencies, view counter beacon `POST music-wave/v1/releases/{id}/view` with dwell 10 sec filterable `music_wave_view_dwell_seconds`, table `mw_release_views` + hourly cron sync to meta for sorting, no per-request meta write.
* **Onboarding**: template repair page Appearance → MusicWave template repair, Site Editor configurability for every part, pattern library MusicWave, legacy widget compatibility via Legacy Widget block and sidebars `musicwave-sidebar`, `musicwave-footer-widgets`.

Requires MusicWave Core 0.15.0+. Recommended MusicWave VIP 0.5.1+ and WooCommerce 8.x/9.x.

== Installation ==

**Requirements:** MusicWave Core 0.15.0+, WordPress 6.6+, PHP 7.4+.

1. Install and activate MusicWave Core 0.15.0+ (and optionally MusicWave VIP 0.5.1+ and WooCommerce).
2. Upload `musicwave` theme folder to `/wp-content/themes/` and activate via Appearance → Themes (tile shows `screenshot.png` 1200x900).
3. Save Settings → Permalinks.
4. Open Appearance → Editor → Styles and pick a style variation (Default, Aurora, Studio, Sunrise). Adjust Colors, Typography, Spacing, Borders, Shadows — all via tokens, no code.
5. Open Appearance → Editor → Templates and Template Parts to configure header (5 variants, width mode), footer, sidebar. Header active part selectable via Appearance → Customize → “سربرگ و پوسته استریم”.
6. Open Appearance → Editor → Patterns. The **MusicWave** category holds the composable sections (hero, horizontal showcase, browse tiles, release card, query grid, FAQ, service callout). Every one is a plain group of core blocks and live renderers, so it is editable block by block after inserting.
7. Run MusicWave → Setup & diagnostics and resolve checks.
8. For page with sidebar: in page editor Settings → Page → Template → Page with sidebar. Sidebar content via Template Parts → Sidebar. Footer widgets via Template Parts → Footer widgets (4 columns).
9. If templates broken after customization: Appearance → MusicWave template repair → Restore validated theme templates (posts/releases/menus/styles never deleted).

== Frequently Asked Questions ==

= How do I change colors, fonts, spacing without code? =
Open Appearance → Editor → Styles. Click pencil on Colors (Palette: Canvas/Surface/Text/Accent — sliders, shelves, player follow via tokens), Typography (System Sans / Editorial Serif / Studio Mono, Small→Hero), Spacing & Borders (X Small→XX Large, corner radius per block), Shadows & Gradients (Card/Floating, Accent gradients). Click Save. History undo available. All blocks use `theme.json` tokens `--mw-color-*`, `--mw-radius-*`.

= How does the header system work? =
5 template parts: Header, Header (centered), Header (minimal), Header (stream) side rail, Header (stream-end) mirrored. Width mode filterable `musicwave_header_width_mode` and Site Editor setting, token `--mw-header-width` syncs variants. Rail has brand + 4 destinations (خانه، پیشنهادی، جستجو، موسیقی من) as navigation blocks with mask icons, collapses to 72px icon strip with warm highlight, toggle stores choice in localStorage `musicwave-rail`, preloads before paint to avoid shift, survives persistent player soft navigation. <64rem docks as bottom tab bar above player. Theme toggle button class `nbp-theme-toggle-fallback` in top bar, syncs all instances.

= How do I make the dashboard editable in Site Editor? =
Template `page-account.html` composes `music-wave/account-dashboard` with every panel enabled in a 68% column beside a 32% `music-wave/release-shelf`, inside the `mw-dashboard-page` shell. Open Appearance → Editor → Templates → Account: the columns, the dashboard and the shelf are all ordinary blocks, so they can be moved or removed and InnerBlocks are supported. The Inspector toggles `account-dashboard` showWelcome/showStats/showMembershipPanel/showLibrary/showOrders/showDownloads/showAddresses/showPaymentMethods/showAccountDetails/showRequests/showSignOut plus the library title rename to “کتابخانه علاقه‌مندی‌ها ♥”. The dashboard is the only sign-in gate on the page — no template or pattern embeds a second `[woocommerce_my_account]` surface. Front stays the same if you reorder.

= How do fonts work without Google? =
Self-hosted variable woff2 in `assets/fonts/`: Vazirmatn-Variable.woff2 109KB Persian unicode-range, PlusJakartaSans-Variable.woff2 27KB latin 200-800, Syne-Variable.woff2 34KB latin 400-800, all `font-display: swap`. Enqueue `musicwave-fonts` now local `tokens.css`, no `fonts.googleapis.com` or `fonts.gstatic.com` requests, no preconnect. Licenses OFL.txt, OFL-PlusJakartaSans.txt, OFL-Syne.txt + README + `docs/third-party-notices.md`. Growth <250KB (actual ~61KB). Preload only main above-fold face.

= What is the account dashboard data source? =
Filter `music_wave_vip_membership_panel` is single source of truth. Returns `has_vip`, `active`, `detailed_active` (level/expires_label/remaining_days/percent_used/is_lifetime/order_id/cart_url), `renewal_needed`, `plans`. `AccountLibrary.php` `render_dashboard`, `render_welcome_block`, `render_stats`, `render_subscription_block` read only from it, no hardcode. VIP badge only when `has_vip` true, otherwise “کاربر عادی”. Renewal button only when `renewal_needed` (90% threshold filterable `music_wave_subscription_renewal_threshold`) with real `cart_url`. Progress bar width `percent_used%` gradient accent→gold, lifetime shows “مادام‌العمر” without progress. Order link to `view-order/{order_id}`.

= How does search work? =
Header search block `stream-search` has mode selector (simple vs icon-popup) and max suggestions control, single suggestion container inside popup (previously two). Icon-popup mode renders 44px button that expands on demand. Autocomplete uses Core REST `/catalog/suggest` with script-aware normalization (Persian/Arabic/Latin, ye/kaf, ZWNJ, digits, diacritics, accents). Roles `combobox/listbox/option`, `aria-expanded`, `aria-activedescendant`, Esc/Enter/Arrow handling, axe compliant.

= How is view counting done without performance hit? =
Old `template_redirect` meta read-modify-write removed, stub `musicwave_track_release_view()` kept deprecated. New Core module `ViewCounter` REST `POST music-wave/v1/releases/{id}/view` public, counts only if published+visible, rate limiter bucket 'views' allows, not counted in last 10 min (transient hash IP+release, never raw IP, helper `ClientIp`), UA not bot (filterable list), user not editor. Front script only on single `mw_release`, beacon after N sec visible (default 10 filter `music_wave_view_dwell_seconds`) via `sendBeacon` + fetch keepalive fallback, no cookie. Storage table `mw_release_views` (release_id PK, views BIGINT, updated_at) via migration seeded from `mw_views` meta, `INSERT ... ON DUPLICATE KEY UPDATE`. Meta snapshot read-only hourly cron (max 200 rows) for compatibility and sorting. Helper `music_wave_release_views($id)`.

= How do playlists and library work? =
Tables `mw_playlists`, `mw_playlist_items`, `mw_user_activity`, `mw_download_replays`, `mw_vip_assets`, future `mw_release_views`, `mw_library_index`. Public playlists `GET /music-wave/v1/playlists/public` returns covers (4 thumbnails) but no `author_id` (privacy). Owner endpoints keep author. Limits filterable, default 20 playlists/user, 100 items/playlist. Library types wishlist/pre-save with fulfillment when release ships. Public playlists noindex by default setting `music_wave_public_playlists_indexable`, meta robots, sitemap exclude.

= Is RTL and mixed Persian/Latin supported? =
Yes, RTL-first logical properties, `dir=\"auto\"` for title/artist inputs, `unicode-bidi: plaintext` for display, bidi isolation. Tested `fa_IR` and `en_US`. Source strings Persian by policy, `en_US` from catalogs, `fa_IR` without catalog works. Adding third language from English POT: `composer make-translations`.

= What about page cache and object cache? =
Conditional asset loading via `has_block`, `is_post_type_archive`, `defer`. Discovery cache TTL `discovery_cache_ttl` 30-86400 default 300 invalidated on save/delete/status. Personalized endpoints send private cache control. View counter avoids per-request DB write (Query Monitor shows no write on release page). Persistent navigation opt-in setting `persistent_navigation` default disabled (hijacking nav has UX cost).

= How do I set up protected downloads? =
Install VIP, set protected root outside public root, configure delivery provider local or remote HMAC, see Core FAQ and `docs/extension-contracts.md` for `music_wave_client_ip` filter example Cloudflare (only when origin only receives proxy traffic). Test via `tests/download-e2e.php`.

== Screenshots ==

1. Front page with hero, release shelves, taxonomy tiles, dark/light toggle.
2. Single release with hero, tracklist, same-artist and similar sections, comments.
3. Account dashboard modern minimal compact — welcome 56px avatar, VIP badge, subscription meta, renewal, stats, tabs.
4. Public playlists directory with cover-stack and instant search.
5. Header variants — default, centered, minimal, stream rail, stream-end, mobile bottom tab bar.
6. Site Editor — Styles, Templates, Patterns, Header/Footer/Sidebar parts.
7. Request form block with modes and management screen.
8. Cart/Checkout virtual-only — no shipping/address, email required, direct checkout from plan.

== Changelog ==

= 0.9.2 =
* Feature: header content width is now a Site Editor setting. The header row ships with two block styles (wide / full) for the header template part and its inner Group, a filterable site-wide default (`musicwave_header_width_mode`), and one shared `--mw-header-width` token, so all five header variants stay in sync without editing code.
* Design: taxonomy terms keep one curated colour across every surface (term hero, browse tiles, artist cards, playlist fallback art, release chips). Colour comes from the term ID via a single source (`TermHue`), the CSS layer gains the canonical `mw-term-hue-N` classes and the shared `.mw-term-media` shell, legacy surface classes stay mapped, and undefined hues fall back to the accent with WCAG-safe ink.
* Feature: five new section patterns for the Site Editor — «سرآغاز سرمقالهای بخش», «برجستهها و آمار بخش», «یادداشت سرمقالهای», «پرسشهای پرتکرار بخش» and «پنل ابزارک». Each is a plain group of core blocks whose header, divider and panel rhythm are declared as editable block attributes, and the FAQ pattern shares one `.mw-faq` shell with the request templates instead of the dead `mw-requests-page__faq` class.
* Cleanup: the `account-hub`, `album-spec-sheet` and `music-home-layout` patterns are removed. `page-account.html` now composes `music-wave/account-dashboard` beside `music-wave/release-shelf` directly, so the duplicate sign-in surface and the wrapper pattern are both gone, and the pattern categories with no consumers were dropped from the registry.

= 0.9.1 =
* Editor: independent release facts and native editable request-page headings, introductions and steps. Existing saved templates are not overwritten.
* Fix: empty shelf/slider headings stay empty for composition; restore the vinyl section header container and use actual release dates in the spec-sheet pattern.
* Security: validate header settings within the save boundary.

= 0.9.0 =
* Feature: the \"سربرگ (استریم)\" template part (`header-stream`) becomes a streaming-app shell: a vertical side rail at the inline-start edge (right in RTL) with the brand and four destinations (خانه، پیشنهادی، جستجو، موسیقی من) rendered as core navigation blocks with mask icons, plus a slim top bar with the \"جستجو در سانگ سرا\" search field, the theme toggle and a round account link. The rail collapses to a 72px icon strip with a warm highlight for the current destination; `assets/rail.js` adds the toggle (`aria-expanded`, `aria-controls`), stores the choice in `localStorage` (`musicwave-rail`), preloads it before paint to avoid layout shift, marks custom destinations (search, account) as current, and survives the persistent-player soft navigation. Without JavaScript the rail is simply expanded. Below 64rem the same markup docks as a bottom tab bar above the global player. New tokens `--mw-rail-width*`, `--mw-rail-active`, `--mw-rail-on-active`; new module `components/rail.css`; editor styles keep the rail as an ordinary row in the canvas.
* Feature: new page templates \"سفارش آهنگ اختصاصی\" (`page-request-song`) and \"همکاری\" (`page-request-collab`) that compose the Core request form in its dedicated modes (Core 0.14.0+) with a matching FAQ, and \"صفحهٔ استریم (نوار کناری)\" (`page-stream`) that uses the rail header and opens with an \"آلوم جدید\" release shelf (\"نمایش بیشتر\" link) followed by new singles and most-played shelves. All three are repaired automatically on existing installs.

= 0.8.2 =
* New page template \"درخواست آهنگ و همکاری\" (`page-requests`) composed of the Core request form block plus an FAQ. The template is repaired automatically on existing installs like the other custom templates.
* The request form needs MusicWave Core 0.13.0 or newer; without Core the block outputs nothing on the front end and the editor shows the standard missing-block notice.

= 0.8.1 =
* Feature: comments section on the single release template (listener discussion with threaded replies, pagination, and a styled form) via the new `comments.css` module; light and dark palettes covered.
* Improvement: single release page on phones and tablets — the hero no longer bleeds past the viewport, the action bar centers with comfortable tap targets, and the track list renders as a two-line card (title + artist, duration and actions on one row) with a tablet column layout in between.
* Fix: horizontal shelf arrows (`.mw-release-shelf__nav-button`) work in right-to-left layouts; the slider handler measures rows in direction-aware logical pixels, advances by whole visible cards, hides arrows on rows that do not overflow, keeps enabled arrows visible on touch devices, and mirrors SVG chevrons in RTL. Community playlist shelves get the same arrows.
* Design: playlist artwork (`.mw-playlists__art-grid`) is now a fanned cover stack — covers layered like record sleeves that spread on hover/focus — across the account list, the public playlists block (square, landscape, portrait, circle) and client-rendered cards.

= 0.8.0 =
* New: light mode now remaps the theme.json colour presets (dark text on light surfaces) instead of deriving colours; every style variation declares its light and dark palette in `settings.custom.scheme`.
* New: the header search is a 44px icon button that expands on demand (core `button-only` Search block).
* New: theme preference toggle uses three inline SVG icons and keeps every instance (header, footer, sidebar) in sync.
* Fix: one header breakpoint (64rem) for all four header parts; the hamburger overlay is complete, scrollable, admin-bar aware, and no longer disappears between 600px and 767px.
* Fix: sticky headers keep their z-index and admin-bar offset above core's position support; anchors respect `scroll-padding-top`.
* Fix: the global preview player is fully responsive (three-zone bar, docked layout on phones), uses SVG icons, shows elapsed/total time on every viewport, exposes `aria-valuetext`, gains a mute control, and reserves bottom space via `html.mw-has-player`.
* Fix: footer social icons follow the palette presets; corrected a malformed selector in playlists.css.
* Fix: the feature shelf's side-list view counts use the scheme-aware muted token instead of a fixed white, so they stay readable in light mode; the release hero tint re-runs after soft navigations.

= 0.7.4 =
* Fix: registered the public playlists, cart, and checkout templates in the Site Editor.
* Fix: removed duplicate footer widget composition from the sidebar page template.
* Fix: unified presentation block metadata and shared release-card rendering helpers.

= 0.7.2 =
* Fix: removed hardcoded root-relative links in the Footer widgets and Sidebar template parts that pointed to non-existent URLs and escaped the site root on subdirectory installs; starter links now use safe placeholders for editing in the Site Editor.

== Upgrade Notice ==

= 0.9.2 =
Header width now Site Editor setting. Update Core to 0.15.0+ for TermHue sync. Pattern library refreshed — 11 editable section patterns; the account page now composes the dashboard directly. Clear caches, save permalinks, verify header in Site Editor.

= 0.9.1 =
Independent release facts and editable request-page headings. Existing templates not overwritten. Save Editor.

= 0.9.0 =
New stream rail header and page templates. Existing installs auto-repair templates. Check Appearance → Customize → Header and Stream.

= 0.8.0 =
Light mode remapped, search icon-popup, theme toggle SVG, header breakpoint 64rem, player responsive. Clear caches and test light/dark.

== Credits ==

* Vazirmatn Variable font — Copyright 2015 The Vazirmatn Project Authors (https://github.com/rastikerdar/vazirmatn), licensed under SIL Open Font License 1.1 (OFL-1.1), file `assets/fonts/Vazirmatn-Variable.woff2` + `assets/fonts/OFL.txt`.
* Plus Jakarta Sans — Copyright 2020 Tokotype, OFL-1.1, self-hosted variable woff2 `assets/fonts/PlusJakartaSans-Variable.woff2` + `assets/fonts/OFL-PlusJakartaSans.txt`, source https://github.com/tokotype/PlusJakartaSans
* Syne — Copyright 2017 Bonjour Monde, OFL-1.1, self-hosted variable woff2 `assets/fonts/Syne-Variable.woff2` + `assets/fonts/OFL-Syne.txt`, source https://github.com/Bonjour-Monde/Syne
* Theme screenshot placeholder generated for review; final 1200x900 capture via `tools/capture-theme-screenshot.js` (Playwright).
* MusicWave Core & VIP teams — https://manacore.dev

== Privacy ==

This theme by itself does not collect personal data. When combined with MusicWave Core:
- Request form stores name, email, phone, message as private post type `mw_request`.
- Listening history stored only with consent, 180-day retention, in user meta.
- Playlists and music library stored in custom tables and user meta.
- Rate limiters use hashed IP in transients (short-lived, no raw IP stored).
- Public playlists show display name (author) publicly.
- Self-hosted fonts: no Google requests, no IP/UA sent externally. See `docs/third-party-notices.md`.
- WooCommerce purchase data handled by WooCommerce.

See MusicWave Core privacy policy content (Settings > Privacy > Policy Guide after FIX-08) and `docs/user-data-and-playlists.md` for full details.
