# Third-party code and asset provenance

Status: verified 2026-10-17 during Phase 4 (FIX-03, FIX-04 pending).

## Shipped runtime code

The distributable packages (`music-wave-core`, `music-wave-vip`, `musicwave`) contain **only
first-party code and assets plus self-hosted OFL fonts**. A grep audit of `music-wave-core/assets` and `musicwave/assets`
found no bundled third-party libraries, no vendored minified scripts, and no external license
headers. All JavaScript is hand-rolled against WordPress-provided `wp-*` script packages, which
are supplied by WordPress core at runtime and are not redistributed here.

Rules for future changes:

1. Any bundled third-party library, font, icon set, or image must be GPL-2.0-or-later
   compatible and must be recorded in this file with name, version, source URL, and license.
2. Protected master audio, demo audio, and demo artwork must never be committed to the
   repository or included in release archives.
3. Release packaging (Stage 7) must re-verify this inventory before producing archives.

## Web fonts (self-hosted)

### Vazirmatn Variable — Persian primary
- **Name:** Vazirmatn Variable
- **Version:** 33.003 (from font metadata, see OFL.txt)
- **Source:** https://github.com/rastikerdar/vazirmatn
- **License:** SIL Open Font License 1.1 (OFL-1.1) — GPL compatible
- **Path:** `musicwave/assets/fonts/Vazirmatn-Variable.woff2`, license `musicwave/assets/fonts/OFL.txt`
- **Usage:** `@font-face` in `assets/css/tokens.css`, `font-display: swap`, tokens `--mw-font-sans` / `--mw-font-display`
- **Redistribution notes:** OFL requires license file shipped alongside font — satisfied via OFL.txt in same directory and Credits in readme.txt
- **SPDX:** OFL-1.1

### Plus Jakarta Sans — Latin (FIX-04 pending self-host)
- **Name:** Plus Jakarta Sans Variable
- **Version:** to be recorded from upstream release (Google Fonts repo or fontsource)
- **Source:** https://github.com/google/fonts (o/plusjakartasans) or https://fontsource.org/fonts/plus-jakarta-sans
- **License:** OFL-1.1
- **Planned path:** `musicwave/assets/fonts/PlusJakartaSans-Variable.woff2` + `OFL-PlusJakartaSans.txt`
- **Current status:** still enqueued from `fonts.googleapis.com` in `functions.php:267` — must be self-hosted in FIX-04
- **Usage:** Latin fallback in `--mw-font-sans`, weights 400-800

### Syne — Latin display (FIX-04 pending self-host)
- **Name:** Syne Variable
- **Version:** to be recorded from upstream
- **Source:** https://github.com/google/fonts (o/syne) or https://fontsource.org/fonts/syne
- **License:** OFL-1.1
- **Planned path:** `musicwave/assets/fonts/Syne-Variable.woff2` + `OFL-Syne.txt`
- **Current status:** enqueued from Google Fonts, preconnect to fonts.gstatic.com in functions.php:518 — to be removed in FIX-04
- **Usage:** Latin display in `--mw-font-display`, weights 600-800

**Privacy note:** After FIX-04, front-end must produce zero requests to `fonts.googleapis.com` or `fonts.gstatic.com`. Until then, third-party request exists and must be disclosed in privacy policy (see FIX-08).

## Development-only dependencies (not distributed)

Composer (`composer.json`, dev):

| Package | License |
|---|---|
| dealerdirect/phpcodesniffer-composer-installer | MIT |
| php-stubs/wordpress-stubs | MIT |
| phpstan/phpstan | MIT |
| phpcompatibility/php-compatibility(-wp) | LGPL-3.0-or-later |
| squizlabs/php_codesniffer | BSD-3-Clause |
| wp-coding-standards/wpcs | MIT |

npm (`package.json`, dev):

| Package | License |
|---|---|
| @playwright/test | Apache-2.0 |
| @wordpress/e2e-test-utils-playwright | GPL-2.0-or-later |
| @wordpress/scripts | GPL-2.0-or-later |

These tools run only on developer and CI machines; none of their code enters the release
archives, so their licenses impose no distribution obligations on the shipped product.

## Product licensing

All three shipped packages and the repository root are licensed **GPL-2.0-or-later**
(see `LICENSE`). The former `proprietary` marker in the root `composer.json` was a
contradiction with the shipped readme headers and was corrected in Stage 0.

