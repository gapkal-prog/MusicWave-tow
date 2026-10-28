# Translations and RTL release process

## Source language policy (FIX-09)

**Source strings are Persian by design.** All PHP and JS `__()`, `_e()`, `_x()` calls use Persian as msgid. English catalogs `en_US` provide English product copy via WordPress localization. This is intentional for the primary market (fa_IR) and allows `fa_IR` sites to work without any catalog (fallback to Persian source).

* Theme headers (`style.css` Description, Theme Name) and plugin headers (`Plugin Name`, `Description` in `music-wave-core.php` and `music-wave-vip.php`) are English so Appearance → Themes and Plugins screens show English on an English site (FIX-09). Block titles/descriptions in `block.json` remain Persian for now; in an English site the inserter shows Persian titles until catalogs are loaded via `wp_set_script_translations` and PHP `load_textdomain`. This is documented as known and will be addressed in FIX-50 plan (source migration to English) if owner decides.
* CI check `check:non-latin-headers` (to be added) asserts `style.css` Description contains only Latin characters, ensuring English headers stay English. Currently verified manually via `grep -P '[^\x00-\x7F]' style.css` should not match Description line.
* Buyer-facing note: you can edit any visible text in Site Editor without code (templates, patterns, blocks). To add a third language, translate from English POT: run `composer make-translations`, copy `en_US.po` to `xx_XX.po`, fill `msgstr`, run `composer make-mo` and `make-script-json`. Do not translate slugs, asset IDs, membership keys, tokens, REST namespaces.

**Block titles verification (FIX-09):** In `wp-env` with `en_US` locale, 27 `block.json` files still have Persian titles/descriptions/keywords (e.g. `music-wave/release-card`, `music-wave/release-shelf`, `music-wave/account-dashboard`, `music-wave/dashboard-welcome`, `music-wave/dashboard-stats`, `music-wave/dashboard-subscription`, `music-wave/search`, `music-wave/playlist`, etc). This is expected until FIX-50. The English catalog translates them at runtime via script translations, so inserter shows English after assets load, but initial HTML may show Persian for a frame. Documented here, not fixed in this task per guide.

## Buyer-facing language note (reuse for user-guide.md)

MusicWave's source language is Persian. English is provided via `en_US` catalogs shipped in `languages/`. If your site language is English, you see English. If Persian (`fa_IR`), you see Persian without needing a catalog (source fallback). To add French, German, etc: duplicate `en_US.po` to `fr_FR.po`, translate `msgstr`, compile MO and JSON via `composer make-translations`. Never translate taxonomy slugs (`mw_artist`, `mw_release_type`), release-type slugs, opaque asset IDs (`local:`), membership level keys, download tokens, REST namespace `music-wave/v1`, or query vars `mw_sort`, `mw_filter`.

## Components

MusicWave ships three independent text domains:

| Component | Text domain | POT output |
|---|---|---|
| Theme | `musicwave` | `musicwave/languages/musicwave.pot` |
| Core | `music-wave-core` | `music-wave-core/languages/music-wave-core.pot` |
| VIP | `music-wave-vip` | `music-wave-vip/languages/music-wave-vip.pot` |

## Generate templates

Run the whole chain after any change to a translatable string:

```bash
composer make-translations
```

That runs four steps in order, each of which can also be run alone:

| Step | Script | What it does |
|---|---|---|
| `make-pot` | `tools/generate-pot.php` | Re-extracts every POT from the current PHP and JavaScript source. |
| `sync-po` | `tools/sync-po.php` | Merges each POT into its `en_US` PO: keeps existing English, appends new msgids with an empty `msgstr`, drops entries whose source string is gone. This is the in-repo stand-in for `msgmerge`. |
| `make-mo` | `tools/generate-mo.php` | Compiles each PO to the binary MO that WordPress actually loads. |
| `make-script-json` | `tools/generate-script-json.php` | Converts the `en_US` PO into JED 1.x JSON files named with the MD5 of each script path relative to the theme/plugin root. |

None of these need gettext binaries, so they run the same on CI and on a Windows dev box.

After `sync-po` adds an entry, fill its `msgstr` by hand, then re-run `make-mo` and `make-script-json` — `load_theme_textdomain()` reads the MO, never the PO, so an edited PO that was not recompiled changes nothing at runtime and looks exactly like a missing translation.

Review and commit changed POT/PO/MO/JSON files with the matching code change. Translation files are distribution assets and are included in release archives.

## Verify

```bash
composer check:translations
composer check:script-translations
```

`check:translations` (`tools/check-translation-coverage.php`) asserts, per component, that every POT msgid exists in the PO, that no PO entry is stale, and that no entry is empty or fuzzy — counting both plural forms. Because Persian is the source language, gettext gives no warning when English is missing: an untranslated msgid legitimately falls back to itself, so an English visitor silently sees Persian. This gate is the only thing that catches that.

A handful of msgids are Latin script at the source and correctly translate to themselves — product names (`MusicWave`, `MusicWave VIP`), format labels (`MP3 320 kbps`, `AAC 256 kbps`), technical tokens (`ISRC`, `BPM`, `LRC`), block keywords used for inserter search (`karaoke`, `lyrics`, `shuffle`), and pure placeholders (`%1$s: %2$s`). The gate accepts a translation identical to its msgid for exactly this reason; it still rejects an empty one.

## Glossary

Keep English wording consistent with the shipped catalogs:

| Persian | English |
|---|---|
| سربرگ | Header |
| نوار کناری / ریل | Sidebar / side rail |
| منوی کشویی | Drawer |
| نوار پایین | Bottom bar |
| نوار بالا | Top bar |
| پوسته (light/dark) | theme |
| ویترین | Shelf |
| شبکه | Grid |
| اسلایدر | Slider |
| عنوان بخش | Section heading |
| سرصفحهٔ بخش | Section head |
| سبک / ژانر | Genre |
| مدت زمان | Duration |
| انتشار | Release |
| متن هم‌زمان | Synced lyrics |

MusicWave uses Persian as the source language. The source strings in the Theme are Persian; `musicwave-en_US.po/.mo` and the hash-addressed JSON files provide the English product copy through WordPress localization. Core and VIP follow the same independent-domain workflow; their source conversion and English catalogs are tracked in [`translation-inventory-fa.md`](translation-inventory-fa.md).

Theme default copy is rendered through presentation-only `music-wave/theme-text` and `music-wave/theme-toggle` blocks (with hidden `musicwave/*` compatibility aliases), so default template headings, empty states, and accessibility labels can be translated without placing domain logic in templates. Merchant-edited template content remains ordinary WordPress content and is intentionally not overwritten.

## Persian and RTL QA

Before a Persian release, install the translated MO files, set the site language to Persian, and check:

1. Theme toggle, default homepage heading, 404 page, cart, checkout, account, and footer strings.
2. Release filters, sort labels, active-filter removal labels, result counts, access states, and download errors.
3. Artist metadata, collection ordering, preview player controls, account library, and WooCommerce checkout.
4. Keyboard focus order, logical CSS spacing, mixed Latin catalog numbers, dates, and audio durations.
5. The same pages in LTR after changing the site language back to English.

Do not translate taxonomy slugs, release-type slugs, opaque asset identifiers, membership keys, download tokens, REST namespaces, or query variables such as `mw_sort`.

## Bidi and dir=auto (FIX-32)

- Inputs that accept mixed Persian/Latin (title, artist, subject, message, search) use `dir="auto"` so browser chooses direction per first strong char.
- Display: `.mw-release-shelf__body h3`, artist, time, p and any `[dir="auto"]` use `unicode-bidi: plaintext` to isolate mixed-direction paragraphs (CSS logical properties).
- `RequestFormBlock.php` subject/message/name inputs have `dir="auto"`.
- `ReleaseCard.php` title/artist/excerpt have `dir="auto"`.
- `functions.php` header-search input has `dir="auto"`.
- Verify with Persian title + English artist name — should not flip punctuation.

Do not translate taxonomy slugs, release-type slugs, opaque asset identifiers, membership keys, download tokens, REST namespaces, or query variables such as `mw_sort`.
