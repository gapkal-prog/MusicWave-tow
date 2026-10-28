# Changelog

All notable changes to MusicWave (theme `musicwave/`, plugins `music-wave-core/` and `music-wave-vip/`) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to Semantic Versioning with WordPress compatibility in mind.

## [Unreleased]
### Added
- Theme: dedicated **هنرمندان** (`/artists/`, template `page-artists`) and **سبک‌ها** (`/genres/`, template `page-genres`) browse pages, provisioned with the other route pages and linked from every bundled header.
- Theme: Customizer controls for the bottom navigation bar (`musicwave_chrome_tabbar`: off / mobile / tablet+mobile), its labels, and the header width default.
- Core: **artists index pagination.** `music-wave/artists-shelf` gained `pagination` + `perPage` attributes; the pager is a real `<nav>` with «قبلی/بعدی», numbered pages, `aria-current="page"`, a «صفحهٔ N از M · K هنرمند» status line and `rel="prev"/"next"` links, and it pages the *all artists* source with a bounded `offset` query (current page clamps to the last page). Curated (`manual`) and shuffled (`rand`) shelves stay single-page by design. Shared `pagination.css` module + inspector toggle, page-size range and conditional visibility.
- Docs: `docs/phpstan-level5-prompt-fa.md` — local PHPStan level 5 runbook plus a ready-to-paste remediation prompt (the analyse gate cannot run in the cloud sandbox).
- Theme: **«جستجوی سربرگ MusicWave» is a fully configurable block.** The `music-wave/header-search` inspector is a grouped Site Editor panel: independent layout per breakpoint (`desktopMode`/`tabletMode`/`mobileMode` — inline field, compact, icon pop-up or slide-down bar), `maxWidth`, placeholder and button copy, suggestion limit, scope tabs, recent searches, quick tags, voice search, keyboard shortcut and footer hints, with conditional visibility so an option appears only when it can take effect.
- Core: **«عوامل انتشار» (`mw_credits`) is fillable from the release screen.** The release metabox renders an ordered repeater («افزودن عامل» plus a remove button per row) posting `mw_credits[<n>][name|role]`, so the pairs arrive at the same sanitizer the REST route uses. An emptied repeater posts an empty scalar and clears the meta instead of leaving the old value in place. Each row is a **نام** (required) and an optional **نقش**; rows without a name are dropped, and both values are stored as free text so Persian roles survive.
### Changed
- Theme: the bottom bar is a real `core/navigation` block inside **every** header template part, so it can be edited in the Site Editor for all five headers; turning it off removes it everywhere, collapses `--mw-rail-tabbar-height`, re-docks `mw-global-player` to the bottom edge, and releases the reserved page padding.
- Theme: the global header admin screen (`Appearance → سربرگ MusicWave`) is gone. Header selection lives in the Customizer, header composition in the Site Editor, and route pages provision themselves once per routes version.
- Theme: `music-wave/rail-toggle` is the single owner of the rail/drawer trigger (one instance per stream header, top-bar placement visible at every width), with a reworked inspector copy and `bar` as the block default.
- Theme: `collection-list` rows drop the redundant «افزودن به صف» / «افزودن به کتابخانه» buttons — a row keeps its play, quality select and download actions — so the action cell no longer crowds the quality selector or wraps badly at narrow widths.
### Fixed
- Core: `collection-list` play rows swap the `play-filled` vector for the pause vector while playing (`ActionButtonStyle::play_toggle_icon()` + CSS `aria-pressed` state); the legacy glyph swap no longer overwrites SVG icons.
- Theme: `collection-list` rows get a token-based surface/outline skin, wrapping action clusters, ellipsized labels, and a single container-query breakpoint (the duplicated `42rem` viewport block is gone).
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
