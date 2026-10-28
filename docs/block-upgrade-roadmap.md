# MusicWave — راهنمای ارتقاء حرفه‌ای بلوک‌ها | Professional Block Upgrade Roadmap

> این سند دو بخش دارد: بخش اول **فارسی** برای مالک پروژه، بخش دوم **انگلیسی** برای عامل هوشمند (Agent) تا در جلسه‌های بعدی دقیقاً از همین نقطه ادامه دهد.
> This document has two parts: Part 1 (Persian) for the project owner, Part 2 (English) as an actionable continuation spec for any AI agent working on this repository.

- تاریخ آخرین به‌روزرسانی / Last updated: 2026-08-25
- وضعیت فعلی / Current status: `tests/run.php` ✅ PASS — `PHPStan level 3 (2G)` ✅ 0 errors — `PHPCS` ✅ exit 0 — `ESLint` ✅ 0 errors — فاز ۰ تا ۲ کامل + ارتقای Public Playlists (Template Part، ویرایش جزئی بلوک، هماهنگی با Release Shelf) کامل شد. ادامه: فاز ۳

---

# بخش ۱ — فارسی (برای شما)

## ۱. آیا هنوز می‌شود حرفه‌ای‌تر کرد؟

**بله، زیاد.** بلوک‌ها از نظر «تنظیمات اینسپکتور» در جلسه قبل کامل شدند، اما برای رسیدن به سطح **قالب‌ها و افزونه‌های تجاری مارکت‌هایی مثل ThemeForest / WooCommerce Marketplace / WordPress.org** هنوز این فاصله‌ها وجود دارد:

| # | وضعیت فعلی | استاندارد محصولات جهانی |
|---|------------|--------------------------|
| ۱ | ثبت بلوک فقط با PHP (`register_block_type` آرایه‌ای) | متادیتای `block.json` برای هر بلوک (استاندارد وردپرس ۶+ و الزامی برای دایرکتوری رسمی و اکثر مارکت‌ها) |
| ۲ | جاوااسکریپت ادیتور دست‌نویس با `createElement` بدون ابزار build | استفاده از `@wordpress/scripts` (wp-scripts) یا حداقل فرایند build و فایل‌های کامپایل‌شده |
| ۳ | بدون Style Variations و Block Examples در Inserter | `styles`، `example`، `variations` برای پیش‌نمایش هنگام درج بلوک |
| ۴ | PHPCS روی کل مخزن خطا می‌دهد (خطاهای قدیمی نام‌گذاری فایل و داک‌بلاک) | قوانین دقیق‌شده در `phpcs.xml` + عبور کامل CI |
| ۵ | بدون تست E2E / Playwright | حداقل چند سناریوی E2E برای ادیتور |
| ۶ | i18n فقط `.pot` سمت سرور | ترجمه اسکریپت‌های JS با `wp_set_script_translations` |
| ۷ | بدون Lazy loading هوشمند دارایی‌ها | `should_load_separate_core_block_assets`, defer کردن اسکریپت پلیر |

## ۲. نقشه راه (به ترتیب اولویت)

### فاز ۰ — پاک‌سازی بدهی کیفیت 
هدف: تمام دروازه‌های کیفیت (Quality Gates) بدون خطا شوند.
1. **اصلاح `phpcs.xml`**: اسنیف‌های «نام فایل با حروف کوچک» و «پیشوند class-» با معماری PSR-4 پروژه در تضاد ذاتی هستند؛ باید با `<exclude-pattern>` یا `<exclude name="WordPress.Files.FileName"/>` فقط برای دایرکتوری `src/` غیرفعال شوند (نه حذف کلی استاندارد).
2. **خط ۱۱۷۰ در `ReleaseBlocks.php`**: خواندن `$_GET[mw_sort]` بدون sanitize از نظر PHPCS — باید با `sanitize_key()` یا همان `normalize_sort()` قبل از استفاده پوشش داده شود و کامنت ` translators:` برای رشته‌های دارای placeholder اضافه شود.
3. **اسکریپت composer**: اضافه کردن `--memory-limit=1G` به `check:phpstan` (PHPStan با 512M کرش می‌کند).
4. اجرای `composer make-pot` برای رشته‌های جدید جلسه قبل.

### فاز ۱ — مدرن‌سازی بلوک‌های ادیتور 
1. **`block.json` برای هر ۱۴ بلوک**: فایل `music-wave-core/blocks/<name>/block.json` شامل `name, title, category, icon, description, keywords, attributes, supports, example`. ثبت از مسیر فایل: `register_block_type( __DIR__ . '/blocks/release-meta' )`. مزیت: شناسایی خودکار توسط وردپرس، سازگاری با ابزارهای آینده، استاندارد مارکت.
2. **Block Examples**: خصوصیت `example` در block.json تا هنگام هاور در Inserter پیش‌نمایش واقعی دیده شود.
3. **Style Variations**: ثبت `register_block_style` برای دکمه پیش‌نمایش (solid/outline/ghost به‌جای attribute سفارشی — یا نگهداری attribute برای سازگاری عقب‌گرد و افزودن style به‌عنوان جایگزین مدرن).
4. **دسته‌بندی اختصاصی بلوک**: ثبت دسته `music-wave` در Inserter با آیکون اختصاصی.
5. **پنل‌های اینسپکتور پیشرفته‌تر**: `__experimentalBorder`، پشتیبانی از Gradient در `BlockSupport::appearance_tools()`، و `InspectorControls` گروه‌بندی‌شده با `PanelBody` (الگوی `fieldConfig` فعلی حفظ شود — فقط گسترش یابد).
6. ** هماهنگی با تنظیمات سراسری**: هر بلوک باید حالت «Inherit from settings» داشته باشد (الگوی `visibility_attribute` موجود است و باید به همه بلوک‌ها تعمیم یابد).

### فاز ۲ — ابزارسازی و Build 
1. افزودن `package.json` با `@wordpress/scripts` و اسکریپت‌های `build`/`start`/`lint:js`.
2. انتقال `blocks.js` به ساختار `src/` یا نگهداری نسخه دست‌نویس + مستندسازی دلیل آن (تصمیم معماری — هر دو قابل دفاع هستند؛ برای مارکت رسمی، build ترجیح داده می‌شود).
3. افزودن Playwright با `@wordpress/e2e-test-utils` برای سناریوهای: درج بلوک، تغییر تنظیمات، ذخیره، رندر فرانت.

### فاز ۳ — بین‌المللی‌سازی و دسترسی‌پذیری 
1. ترجمه JS با `wp_set_script_translations()` و تولید فایل‌های `languages/music-wave-core-<locale>-<handle>.json`.
2. بازبینی a11y: کنتراست رنگ‌ها (WCAG AA)، `aria-live` برای نتایج فیلتر کاتالوگ، مدیریت فوکوس در پلیر پیش‌نمایش.
3. پشتیبانی کامل RTL (قالب باید در حالت RTL سایت‌های فارسی/عربی بی‌نقص باشد — تست دستی الزامی).

### فاز ۴ — بسته‌بندی تجاری 
1. فایل `LICENSE` (هنوز وجود ندارد — برای فروش الزامی است).
2. یکسان‌سازی نسخه در `readme.txt` هر سه محصول + هدر پلاگین‌ها + یک Constant مرکزی.
3. مستندسازی هر بلوک در `docs/` به‌صورت «Block Reference» (مشخصات، attributeها، مثال).
4. اعتبارسنجی خروجی `tools/package-release.php` با ابزار رسمی مارکت هدف.


## ۳. معیارهای پذیرش «سطح جهانی»

- [ ] `composer check:syntax && composer test && composer check:phpcs && composer check:phpstan` همه سبز
- [ ] هر بلوک دارای `block.json` + آیکون + کلمات کلیدی + example
- [ ] هر رشته ترجمه‌پذیر در `.pot` موجود باشد (تولید خودکار)
- [ ] هر attribute جدید در ۴ جا باشد: PHP سرور + `Rendering.php` + `blocks.js` + CSS (قانون Parity که تست `template-integrity.php` آن را تضمین می‌کند)
- [ ] قالب در RTL و LTR و هر ۳ Style Variation (studio/aurora/sunrise) بی‌نقص رندر شود
- [ ] مستندات `docs/` به‌روز باشد

---

# Part 2 — English (Agent Continuation Spec)

> **Purpose**: Any agent continuing this project must read this section first, then follow the phases in order. Do NOT deviate from the established architectural patterns listed in §2.3.

## 2.1 Verified Current State (2026-08-16, post Phase 0 + Phase 1)

- `php tests/run.php` → "MusicWave domain smoke tests passed." ✅
- `phpstan analyse --configuration=phpstan.neon --memory-limit=2G` → 0 errors ✅ (NOTE: PHPStan now needs **2G**; 1G crashes the parallel worker. The composer script `check:phpstan` carries the flag.)
- `phpcs --standard=phpcs.xml music-wave-core musicwave` → ✅ exit 0, zero errors and warnings. PSR-4 `src/` trees are excluded from `WordPress.Files.FileName`; WordPress docblock-layout sniffs are excluded (PHPDoc-style annotations are the project standard); every security sniff remains active with point fixes or justified inline ignores.
- `DemoContentImporter::$result` is typed with the array shape `array{created: int, existing: int, skipped: int, removed: int, messages: array<int, string>}` on all by-ref params (fixes PHPStan `paramOut.type` widening).
- `ReleaseRepository` interface now declares `replace_collection_items()`; the `method_exists()` guard in `DemoContentImporter::sync_collection()` was intentionally removed (owner edit). Keep it removed.
- Every dynamic block registers through `BlockSupport::register_dynamic()`: the bundled `blocks/<name>/block.json` is the metadata source of truth; the PHP attribute arrays remain ONLY as a fallback for installs missing `blocks/` and as the parity anchor. `DownloadTokenService` keeps `json_encode()` on purpose (WP-stub domain tests) with a justified ignore.

## 2.2 Block Inventory (14 dynamic blocks)

| Block name | Registered in | Renderer |
|---|---|---|
| `music-wave/release-meta` | `ReleaseBlocks.php` | `render_meta` |
| `music-wave/access-panel` | `ReleaseBlocks.php` | `render_access_panel` |
| `music-wave/release-credits` | `ReleaseBlocks.php` | `render_credits` |
| `music-wave/collection-list` | `ReleaseBlocks.php` | `render_collection_list` |
| `music-wave/catalog-filters` | `ReleaseBlocks.php` | `render_catalog_filters` |
| `music-wave/catalog-results` | `ReleaseBlocks.php` | `render_catalog_results` |
| `music-wave/preview-player` | `ReleaseBlocks.php` | `render_preview_player` |
| `music-wave/download-button` | `ReleaseBlocks.php` | `render_download_button` |
| `music-wave/related-releases` | `ReleaseBlocks.php` | `render_related_releases` |
| `music-wave/preview-button` | `PreviewPlayer.php` | via `button_markup` |
| `music-wave/artist-profile` | `ArtistProfileBlock.php` | `render` |
| `music-wave/music-library` | `LibraryBlocks.php` | — |
| `music-wave/library-button` | `LibraryBlocks.php` | — |
| account library block | `Commerce/AccountLibrary.php` | — |

## 2.3 Architecture Patterns — DO NOT DEVIATE

1. **Server registration**: `ReleaseBlocks::register_dynamic_block( $name, $attributes, $callback )` — API v3, `uses_context: [postId, postType]`, `supports: BlockSupport::appearance_tools()`.
2. **Attribute helpers** (in `ReleaseBlocks.php`): `bool_attribute`, `text_attribute`, `key_attribute`, `range_attribute`, `message_attribute` (block attr → global `Settings::get()` → fallback), `visibility_attribute` (tri-state inherit/enabled/disabled), `url_attribute`. Always add new attributes through these helpers.
3. **Editor parity contract**: every attribute MUST exist in (a) the server block PHP, (b) `Modules/Rendering.php::editor_blocks()` localization of `musicWaveDynamicBlocks`, (c) `assets/blocks.js` inspector config, (d) theme CSS. `tests/template-integrity.php` asserts this — extend it for every new attribute.
4. **Editor JS**: hand-rolled `wp.element.createElement` + declarative `fieldConfig` descriptors `['text'|'intText'|'toggle'|'select'|'range', attr, label, ...]` with `groups` arrays of PanelBody sections; `ServerSideRender` for previews. Preserve test-required strings `'music-wave/release-meta': {` and `showCatalogNumber`.
5. **CSS**: BEM-style `mw-*` classes with `--modifier` variants, theme vars (`--mw-color-accent`, `--wp--preset--spacing--*`, `color-mix()`). Reuse shared shelf/layout classes across blocks instead of new ones.
6. **Backward compatibility**: all changes additive; modifier classes appended only when non-default; optional trailing params on shared methods.
7. **Settings harmony**: default sort / labels resolve through `Settings::get()` (option `music_wave_settings`, e.g. `archive_default_sort` normalized via `ReleaseArchiveQuery::normalize_sort()`).
8. **Shared card markup**: the release shelf card lives in `Core\Blocks\ReleaseCard` and the shelf
   header in `Core\Blocks\SectionHeader` — render through them, never re-inline `mw-release-shelf__artwrap`.
   The block-architecture release card is the `mw-release-card mw-surface` / `__media` / `__overlay` /
   `__body` structure: keep `music-wave/preview-button` inside `__overlay` (that selector is what makes it
   an artwork affordance) and `music-wave/release-meta` a direct child of `__body` (`.mw-release-meta--compact`
   is pinned with `margin:auto 0 0`). `patterns/release-card.php` must stay byte-identical to the card in
   `templates/search.html`; `tests/template-integrity.php` asserts all of this.
9. **Two rendering lanes, never mixed**: a block either renders itself in PHP (leaf block,
   `render_callback`, ServerSideRender preview) or stores itself from JavaScript (static container,
   `save()` + InnerBlocks). Dynamic blocks register through `musicwave_register_presentation_blocks()`
   with a `'dir' =>` + callback entry and a hidden `musicwave/*` alias; static blocks register through
   `musicwave_register_static_blocks()` from block.json with no callback and no alias. Stored content
   must keep dynamic blocks self-closing and static containers correctly paired — classified from the
   registration site, never from an exception list. `tests/editor-lanes.js` (runtime) and
   `tests/template-integrity.php` (structural) enforce both lanes.

## 2.4 Phase 0 — Quality Debt Cleanup (DONE 2026-08-16)

- [x] `phpcs.xml`: PSR-4 `src/` trees excluded from `WordPress.Files.FileName`; WordPress docblock-layout sniffs excluded; `.staging/`, `tests/`, `tools/` excluded from the default file set; `WordPress.WP.Capabilities` learned the custom `edit_mw_releases` capability via `custom_capabilities`.
- [x] All real findings fixed: `translators:` comments added, `$_GET`/`$_POST` reads sanitized at the point of read (`sanitize_key()`/`sanitize_title()`/`absint()`), short ternaries expanded in `AccessPolicyEngine.php` and `musicwave/functions.php`, reserved-keyword params renamed (`$class` → `$class_name`, `$default` → `$default_value`), escape-output findings escaped or justified, 486 formatting violations fixed by `phpcbf`.
- [x] `composer.json` scripts: `check:phpstan` carries `--memory-limit=2G`.
- [x] `composer make-pot` regenerated for all three products.
- [x] Verified: `composer check:syntax`, `composer test`, `composer check:phpcs` (exit 0), `composer check:phpstan` all green.

## 2.5 Phase 1 — Editor Block Modernization (DONE 2026-08-16)

1. [x] **block.json migration**: all 14 blocks ship `music-wave-core/blocks/<name>/block.json` with `$schema`, `apiVersion: 3`, `name`, `title`, `category: music-wave`, `description`, `keywords`, `icon`, `attributes` (exact copies of the PHP maps), `supports` (mirrors `BlockSupport::appearance_tools()`), `usesContext`, `example`, and `textdomain`. Registration goes through `BlockSupport::register_dynamic()` which loads the metadata folder and attaches only the PHP `render_callback`; PHP attribute arrays remain as fallback + parity anchor. `Rendering.php::editor_blocks()` enriches the hand-rolled JS registry with `category`/`example` from block.json at runtime (single source of truth). `tests/template-integrity.php` now asserts block.json existence, required metadata fields, and attribute parity with `Rendering.php` for all 14 blocks.
2. [x] **Inserter experience**: `example` in every block.json; dedicated `music-wave` block category registered via `block_categories_all` (Rendering::register_block_category); `register_block_style()` variations registered for `preview-player` + `preview-button` (outline/ghost), `release-meta` (inline/stack), `catalog-filters` (stacked). Renderers translate the chosen `is-style-*` class into the existing modifier classes via `BlockSupport::style_variation()` — the variation takes precedence over the legacy attribute when selected.
3. [x] **Inspector upgrades in `blocks.js`**: declarative `fieldConfig` extended — `text`/`select` descriptors now support per-control help; help texts added for catalog-results, account-dashboard, preview style/icon, and layout selects (with Styles-panel cross-references); label help documents the inherit-from-MusicWave-defaults behavior. Client registration now uses category `music-wave` and safely replaces a server-hydrated bare block type (unregister + re-register, preserving server `styles`/`example`).
4. [x] **Accessibility**: hidden labels stay `screen-reader-text`; new toggles that hide visible text keep SR-only counterparts; help copy documents the a11y behavior.

Next continuation point: Phase 3 (i18n JS translations, a11y/RTL audit), then Phase 4. On a machine with npm registry access, finish the Phase 2 operator follow-up listed in §2.6.

## 2.6 Phase 2 — Build Tooling (DONE 2026-08-16)

- [x] `package.json` added with `@wordpress/scripts`, `@playwright/test`, and `@wordpress/e2e-test-utils-playwright`; scripts: `lint:js`, `lint:js:fix`, `build` (lint gate alias), `test:e2e`, `test:e2e:headed`.
- [x] **Decision (option b)**: the dependency-free hand-rolled editor scripts are KEPT; `@wordpress/scripts` serves as a lint gate, not a compiler. Rationale documented in `DEVELOPMENT.md` (marketplace zip stays artifact-free, parity test strings stay verifiable, zero supply-chain exposure). ESLint profile in `.eslintrc.json` extends `plugin:@wordpress/eslint-plugin/recommended` with ES5/script-mode relaxations.
- [x] Playwright E2E added: `tests/e2e/playwright.config.js` + `tests/e2e/editor-blocks.spec.js` — data-driven scenario per block: insert on a page, change one inspector setting, assert the saved attribute fragment, publish, and assert front-end markup (`.mw-catalog-filters--stacked`, `.mw-music-library`, dashboard/catalog-results wrappers) or a clean no-PHP-fatal load for context-bound blocks. Targets the staging install from `docs/staging.md` (`WP_BASE_URL`/`WP_USERNAME`/`WP_PASSWORD` overridable).
- [x] Soft composer gate `check:js` (`tools/check-js.php`) runs `npm run lint:js` when `node_modules` exists and skips cleanly otherwise; all committed JS is `node --check` valid.
- Operator follow-up on a machine with npm registry access: `npm install`, `npm run lint:js` (tighten `.eslintrc.json` rules if warnings appear), `npx playwright install chromium`, start staging, `npm run test:e2e`.

## 2.7 Phase 3 — i18n / a11y / RTL

- `wp_set_script_translations( 'music-wave-blocks', 'music-wave-core', plugin_dir_path(...) . 'languages' )` for the editor script; generate JSON translation files.
- RTL audit of all `mw-*` component CSS (logical properties `margin-inline-start` etc. where directional hardcoding exists).
- WCAG AA contrast check for badge/pill colors (`--outline`, `--ghost`, `__count` use `color-mix()` — verify on all three style variations studio/aurora/sunrise).

## 2.8 Phase 4 — Marketplace Packaging

- Add `LICENSE` at repo root and reference it in both plugin headers + theme `style.css`.
- Single source of truth for version: define once, inject into `readme.txt` Stable tag and file headers via `tools/package-release.php` (verify the script already does this; if not, add).
- Write `docs/block-reference.md`: one section per block — attributes table (name/type/default/description), supports, screenshot path, since-version.

## 2.9 Verification Checklist (run before declaring any phase done)

```powershell
php tests\run.php
php vendor\bin\phpstan analyse --configuration=phpstan.neon --no-progress --memory-limit=1G
php vendor\bin\phpcs --standard=phpcs.xml music-wave-core musicwave
php tools\check-templates.php
```

Plus for any block touched: confirm the four parity locations (§2.3.3) and a manual render on staging (`.staging/` smoke flow, `tests/staging-smoke.php`).

## 2.10 Known Gotchas (learned the hard way)

- Never run `php tests/template-integrity.php` directly — `mw_assert_same()` is defined by `tests/run.php`.
- `@var` annotations do NOT override PHPStan tracking of by-reference params; use precise array-shape `@param` types instead (see `DemoContentImporter`).
- PHPStan needs `--memory-limit=2G` here (1G now crashes the parallel worker).
- WPCS doc-comment alignment sniffs require exact column alignment of `$param` names AND descriptions; iterate with phpcs output ("Expected N spaces...").
- `get_block_wrapper_attributes()` + custom classes: append modifier classes conditionally, never replace the default block class.
- PowerShell only: use `;` not `&&`.
- `register_block_type( $folder, $args )` merges `$args` OVER block.json — pass only `render_callback` there, or PHP arrays silently shadow the metadata file.
- `tests/run.php` stubs a minimal WP environment: do not swap `json_encode()` for `wp_json_encode()` in code paths the domain tests exercise (DownloadTokenService).
- When editing attribute lists, keep all parity locations in sync: block.json + PHP fallback arrays + `Rendering.php` + `blocks.js` fieldConfig + theme CSS; the integrity test now validates block.json ↔ Rendering.php automatically.

## 2.11 Public Playlists Site Editor Upgrade (DONE 2026-08-25, revised 2026-08-26)

The Public playlists surface is now a first-class, modular Site Editor experience coordinated with the MusicWave release shelf:

1. **Direct block composition**: `templates/page-playlists.html` composes the archive section by section from real blocks — header/footer template parts, the app-shell group, the `<main>` landmark, `music-wave/public-playlists`, and a helper paragraph. The block itself renders the whole experience, so no wrapper template part or single-block pattern duplicates it (an integrity test guards against re-introducing that layer).
2. **Block granularity** (`music-wave/public-playlists`): new attributes — `layout` gains the `scroll` horizontal rail, `columns` extends 2–6 (shelf parity), plus `imageShape` (`square|circle|landscape|portrait`), `showToggle`, `showPagination`, `sectionUrl` + `sectionLinkLabel` (release-shelf-style "See all" header link). `supports.typography` adds `fontWeight`/`textTransform`. All four parity locations were updated together (block.json, `Rendering.php::editor_blocks()`, `blocks.js` fieldConfig groups, theme CSS) — the integrity test enforces block.json ↔ Rendering.php automatically.
3. **Style variations**: `register_block_style` variants `cards` (default, unchanged rendering) and `minimal` (borderless editorial surface). The renderer resolves them through `BlockSupport::style_variation()` and appends `mw-public-playlists--minimal` only when non-default.
4. **Shared layout system**: `.mw-public-playlists__grid` consumes the same `--mw-shelf-columns` token and container-query breakpoints as `.mw-release-shelf` (52rem caps at 3 columns, 34rem collapses to 2), plus a scroll rail mirroring `.mw-release-shelf--scroll` (scroll-snap + auto columns). Art shape modifiers mirror the shelf art variants.
5. **Client parity**: `playlists.js` reads `layout`, `columns`, `imageShape`, `showToggle`, and `showPagination` from `data-mw-public-options` so instant-search re-renders respect every inspector setting.
6. **Theme shelf coordination**: `musicwave/assets/editor-blocks.js` gained a "Content source" control for `music-wave/release-shelf` (releases ↔ public playlists; the old `musicwave/release-shelf` name remains a hidden compatibility alias) with a playlist query panel (`playlistOrderBy`, `playlistSearch`), replacing the previous releases-only inspector for that mode.

Verification: `php tests/run.php` (includes extended template-integrity assertions), `composer check:syntax`, `composer check:phpcs` (exit 0), `composer check:phpstan` (0 errors), `npm run lint:js` (0 errors), `php tools/check-templates.php`, `composer make-pot`.

## 2.12 Definition of Done (marketplace-grade)

The project is "marketplace-ready" when: all four quality gates are green, every block ships a `block.json` with icon/keywords/example, the `.pot` + JS translation pipeline is wired, `LICENSE` exists, `docs/block-reference.md` covers all 14 blocks, and RTL + 3 style variations render without visual regressions.

## 2.13 Style variations — editorial × vinyl layer (DONE 2026-09-10)

Two design layers sit next to SonicStream and are wired through one registry per
package:

- `musicwave/assets/css/components/editorial.css` — magazine vocabulary:
  `.mw-eyebrow`, `.mw-section-head`, `.mw-chip` / `.mw-chip-rail` (token-driven
  variants, works on `core/post-terms`), `.mw-metabar`, `.mw-spec-strip`,
  `.mw-liner-notes`, the `.mw-album-facts` pattern wrapper, plus the
  `--editorial` / `--minimal` shelf and `--cinema` / `--editorial` slider looks
  and the Core-block variations (`--stamp`, `--editorial` results, related
  shelves, artist `--spotlight`).
- `musicwave/assets/css/components/vinyl.css` — physical media: the sleeve
  treatment that turns the single-release cover into a record, and the `--vinyl`
  / `--bento` shelf looks, `--tracklist` collection list, `--vinyl` preview
  button, and public-playlist cards.

Rules for anything added on top of this layer:

1. **One registry per package.** Theme:
   `musicwave_presentation_style_variations()` in `functions.php` (slug →
   label + hint). Core: `Rendering::style_variations()` (block → variations),
   consumed by `register_block_style()` *and* localized as
   `musicWaveBlockStyles` for the editor panel.
2. **`className` is the single source of truth.** Both editing surfaces — the
   native Styles panel and the "استایل و ظاهر" select in the block's own
   settings panel — read and write `is-style-<slug>`. `styleVariant` stays a
   read-only preset so patterns can ship a look; the className wins.
3. **A variation must resolve to a modifier class.** Theme renderers use
   `musicwave_style_variant_class()`; Core renderers use
   `BlockSupport::style_variation()`. Never add a slug without updating the
   renderer's allow-list.
4. **A variation must ship CSS.** `tests/template-integrity.php` parses both
   registries and fails when a non-default slug has no stylesheet rule, and it
   asserts the Core registry covers exactly the known dynamic blocks.
5. **No orphan chrome.** Every class in these two files is emitted by a
   renderer, template, or bundled pattern; the shared "now playing" equalizer
   stays in `global-player.css` (`.mw-equalizer`) rather than being duplicated.
6. **Layout coverage.** Style variations must also be visible in the block's
   non-grid layouts (`feature`, `slider`, `list`); the feature hero and shelf
   slider both carry variant rules.

Bundled patterns that demonstrated the layer at the time of this round were `vinyl-record-shelf.php`
(section head + `is-style-vinyl`), `album-spec-sheet.php` (chip rail, metabar,
spec strip, liner notes — composed from `core/post-terms`, `core/post-date`,
and `core/post-excerpt`, so every value is the real release data). The single
release template renders the fact sheet under the hero and ships the
`is-style-tracklist` collection list. **Superseded in 0.9.2:** both of those patterns were removed. The chip rail, metabar and spec strip vocabulary now lives in the live renderers (`release-meta`, `collection-list`, `release-credits`) and is composed by the full-page demo packs in `patterns/demo-*.php` and by `patterns/release-grid.php`.

Verification for this round: `php tests/run.php` (domain smoke tests +
template integrity + the style-variation contract), `php
tools/check-templates.php`, `php tools/check-site-editor.php`, `php
tools/check-script-translations.php`, `php tools/check-syntax.php`,
`npm run lint:js`, plus regenerated `.pot` / `en_US.po` / `.mo` / JED JSON
catalogs.

## 2.14 Composability — Stage B + Stage A (DONE 2026-09-14)

Full design, evidence and decisions: `docs/composability-architecture.md` §7–§10.

**Stage B (`bb18048`)** — extracted the two duplicated renderers into
`Core\Blocks\ReleaseCard` (`presentation_data` / `initial` / `artwork` / `render`) and
`Core\Blocks\SectionHeader` (`more_link` / `shelf_header`). All three PHP card producers
(`ReleaseBlocks::related_card`, `ListeningBlocks::history_card`, the theme release-shelf loop) and
both section-header producers now delegate; theme delegation is `class_exists`-guarded. The
duplicated markup was *removed*, not shadowed: `mw_release` is registered only by
`music-wave-core`, so the theme copy was unreachable without Core. `tests/card-markup.php` (30
cases) proves byte-parity and has two negative controls proving it fails on drift.

**Stage A** — one canonical release card on all five archive surfaces
(`search`, `taxonomy-mw_artist`, `archive-mw_release`, `taxonomy-mw_genre`, `archive`), plus two new
patterns: `musicwave/release-card` and `musicwave/release-grid` (native `core/query` composition).
Patterns are inline-expanded into templates, never referenced via `wp:pattern`, so every card part
stays selectable in the Site Editor. `taxonomy-mw_artist.html` was already canonical and untouched.

Two findings that future work must not undo:

* `showLibraryButton` is **inert on these cards**: `ReleaseBlocks.php:690` returns early in compact
  mode, before the library/actions buttons at :708–715. All five cards use `compact:true`, so they
  now all state `showLibraryButton:false` explicitly. The library action still lives on
  `single-mw_release.html`. If compact mode ever grows actions, revisit this.
* `archive.html` is the **generic** archive (`mw-generic-archive`, `query.inherit:true`, renders any
  post type): it composes `__media` + `__body` only. Do not add an overlay there — an empty
  `.mw-release-card__overlay` still paints its hover gradient (catalog.css:1049) — and do not add
  `music-wave/release-meta` or `moreText:"مشاهده انتشار"` ("view release"), which would mislabel
  regular posts.

Deferred follow-ups (known, deliberate, not regressions): pagination drift (`search` uses
`mw-catalog-pagination` + `flexWrap`, the other four a bare pagination). The pot/po/mo pass and the
phpcs/security work on `functions.php` / `inc/nav-icons.php` / `inc/site-header.php` landed in the
final quality pass (see §2.15). Stage C is not started.

Environment note: the verification sandbox has no PHP binary. Gates are run through WebAssembly PHP
with a from-source WPCS toolchain, which **swallows exit codes** — judge every gate on its output
text, never on `$?`. `tools/check-syntax.php` (needs `exec`) and phpstan (blocked release asset)
cannot run there; `composer check:phpstan` must still be run in CI. See
`docs/composability-architecture.md` §10.4.

## 2.15 Composability — Stage D (DONE 2026-09-14)

`music-wave/section-head` shipped as the project's only static InnerBlocks block. Design, evidence
and the correction to the plan's flat-template sketch: `docs/composability-architecture.md` §10.5.

Files: `musicwave/blocks/section-head/block.json` (no attributes, no render/editor script),
`musicwave/functions.php` (`musicwave_static_block_dirs()`, `musicwave_register_static_blocks()`,
`musicwave_section_head_styles()`, `musicwave_block_metadata_entry()`, the `musicwaveStaticBlocks`
localization), `musicwave/assets/editor-blocks.js` (second registration lane + `translatedMetadata()`),
`musicwave/assets/css/components/editorial.css` (`is-style-*` twins for the three modifiers),
`musicwave/theme.json` (per-block settings), `musicwave/patterns/section-heading.php` (docblock
cross-reference only), `tests/editor-lanes.js` + `tests/e2e/section-head.spec.js`, and the amended
pairing rule in `tests/template-integrity.php`.

Things future work must not undo:

* The seeded InnerBlocks template wraps its three lines in a `core/group` with
  `className: mw-section-head__text`. That group is load-bearing: `.mw-section-head` is a flex row,
  so flat children would sit side by side. `patterns/vinyl-record-shelf.php` composes the same
  structure by hand and must keep matching it.
* `templateLock: false` is the feature. Locking it would turn the block back into a fixed header.
* Supports deliberately exclude `color.text`, `typography` and `spacing.blockGap`: each would be a
  dead control because `editorial.css` sets those properties on `h2`/`p` and on the shell's own
  `gap`. Children carry their own working controls. The exclusions are asserted with reasons.
* The static lane must never gain a ServerSideRender preview and the dynamic lane must never gain
  InnerBlocks. Both directions are asserted; a second JS rendering path for PHP blocks is exactly the
  duplication this architecture exists to prevent.
* `musicwave/section-heading` (the pattern) stays. It is a different tool: plain core blocks for a
  one-off text heading, no component shell. Do not "consolidate" the two without a product decision.

New gates: `npm run test:editor-lanes` (runs the real editor script against a stubbed `wp.*`) and
`tests/e2e/section-head.spec.js` (needs the staging install: seeded
structure, child editability and move controls, a Styles-panel look reaching the frontend, block
validation on reload, inserter coexistence).

**One push-blocked change to apply by hand.** The coding agent's GitHub App token cannot write
`.github/workflows/*` (`refusing to allow a GitHub App to create or update workflow ... without
workflows permission`), so the npm script shipped but its CI step did not. Add this to the
`javascript` job in `.github/workflows/quality.yml`, directly after `- run: npm run lint:js`:

```yaml
      # Executes the real musicwave/assets/editor-blocks.js against a stubbed
      # wp.* and asserts what each registration lane does at runtime: the
      # PHP-rendered blocks keep their ServerSideRender preview and store
      # nothing, the static container keeps an unlocked InnerBlocks template and
      # stores its own markup. Dependency-free, so no WordPress needed.
      - run: npm run test:editor-lanes
```

Optionally rename that job to `JavaScript lint + editor lanes`. Until it lands, the gate still runs
locally with `npm run test:editor-lanes`; nothing else depends on the workflow edit.

Deferred, unchanged: Stage C and its trigger criteria (§8 of the composability plan). The final
quality pass regenerated `.pot` / `en_US.po` / `.mo` / JED JSON (Stage A + D strings included;
stale extra msgids kept), ran `npm run lint:js` to zero, and brought `functions.php`,
`inc/nav-icons.php`, and `inc/site-header.php` to 0 PHPCS errors under `phpcs.xml` without sniff
suppression. Site Editor E2E and PHPStan remain environment-limited where Docker / phpstan.phar
are absent.
