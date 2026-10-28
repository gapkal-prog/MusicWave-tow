# MusicWave development guide

MusicWave is ManaCore's commercial WordPress music platform: a block theme for presentation and a companion plugin for catalog, player, commerce, access, and integration logic.

## Repository layout

- `musicwave/`: block theme templates, patterns, global styles, and presentation.
- `music-wave-core/`: catalog, player, application services, public APIs, and integration contracts.
- `music-wave-vip/`: role-based membership and protected-download integrations.
- `MusicWave-Backlog.md`: product scope, architecture decisions, milestones, and acceptance criteria.

## Requirements

- PHP 7.4 or newer; PHP 8.2 is the primary local development runtime.
- WordPress 6.6 or newer.
- WooCommerce 9.x or newer for commerce features.
- Composer 2 for development quality tools.
- Node.js 20+ / npm for JavaScript linting and the Playwright E2E suite (`npm install`).

## JavaScript tooling decision (Phase 2)

MusicWave ships **dependency-free, hand-rolled editor scripts** (`wp.element.createElement` + a declarative `fieldConfig` registry) instead of a compiled JSX build. Rationale:

- The runtime depends only on WordPress-provided `wp-*` script packages, so there is no build artifact to ship, version, or audit in the marketplace zip.
- `tests/template-integrity.php` asserts exact parity strings inside `music-wave-core/assets/blocks.js`; keeping the source hand-written keeps that contract directly verifiable.
- Supply-chain exposure stays at zero for a commercial product.

`@wordpress/scripts` is therefore used as a **quality gate, not a compiler**:

```bash
npm install
npm run lint:js        # ESLint over plugin + theme JS assets
npm run lint:js:fix
npm run build          # CI alias that runs the lint gate
composer check:js      # runs the same gate, skipping cleanly when node_modules is absent
```

The ESLint profile (`.eslintrc.json`) extends `plugin:@wordpress/eslint-plugin/recommended` with ES5/script-mode relaxations suited to the hand-rolled runtime files.

## Editor E2E tests (Playwright)

The suite in `tests/e2e/` covers every MusicWave dynamic block: insert on a page, change one inspector setting, publish, and assert the saved attributes plus the front-end markup where the block renders without release context.

```bash
npx playwright install chromium   # once
# start the staging install (see docs/staging.md), then:
npm run test:e2e
```

Connection settings default to `docs/staging.md` values and can be overridden via `WP_BASE_URL`, `WP_USERNAME`, `WP_PASSWORD`.

A step-by-step Persian walkthrough for checking out a branch, running wp-env or a LocalWP install, and working through the manual UI checklist lives in `docs/local-testing-fa.md`.

> Note: the committed JS sources are validated by `node --check`-compatible syntax at all times; `npm install` requires registry access, so the ESLint/Playwright gates activate on machines that can reach the npm registry.

## Current status

MusicWave Core `0.9.0` (schema `0.8.0`) provides the canonical release catalog, WooCommerce product mapping, access decisions, multi-quality protected downloads, collection relationships, global public preview player, and type-aware release authoring. The block theme provides single and archive experiences, including native taxonomy search, safe archive sorting, result counts, and removable active filters.

The release archive deliberately relies on WordPress-native archive queries. It allow-lists only `latest`, `oldest`, `title_asc`, and `title_desc` for the `mw_sort` query argument, and never queries or exposes protected access metadata. The translation pipeline and RTL QA guidance are now available. Full compatibility QA and the final license/asset audit remain before a public release.

### SonicStream presentation pass (UI/UX polish)

The theme and plugin front-end are aligned with the SonicStream design reference (`MusicWave-SonicStream/design-reference.html`), token-driven so all three style variations (Aurora, Studio, Sunrise) and the light theme keep working:

- Global player: in-row seek bar with green-to-green gradient (`--mw-progress` synced from `preview-player.js`), white hover thumb, accent-glow play toggle, and a `data-mw-state` now-playing hook.
- Track rows: `data-mw-playing` flag from the player JS lights a CSS-only equalizer in the position column and tints the active title.
- Horizontal shelves: floating prev/next arrows (hover-reveal, edge-aware disabling) driven by `slider.js` `initializeShelves()`; the `scroll` layout enqueues `musicwave-slider` at render time.
- Genre/browse tiles: reference gradient spectrum (nth-child defaults, editor backgrounds win) plus lift-on-hover.
- Catalog filters: RTL-aware shimmer sweep while `[data-mw-busy]` is set (reduced-motion safe).
- Membership/commerce: upsell gradient frame, plan-card lift, pill-shaped WC form fields with accent primary buttons.
- Secure download actions: two structural variants — the standalone panel card and a new `--inline` group embedded in collection track rows. The inline group is smaller markup rather than CSS-hidden content (no screen-reader clipping): the quality selector carries an `aria-label`, and its live status surfaces as a floating notice anchored to the group.

All motion respects `prefers-reduced-motion`; RTL uses logical properties throughout.

## Local checks

Run `composer install` once, then:

```bash
composer validate --strict
composer check:syntax    # recursive php -l over all three packages, tools, and tests
composer check:site-editor # static Template/Part/Pattern/Block metadata integrity gate
composer test            # dependency-free domain + security regression smoke tests
composer check:phpcs     # WordPress standards for music-wave-core, music-wave-vip, musicwave
composer check:phpstan   # static analysis for all three packages
composer make-pot
npm ci && npm run lint:js  # JS gate; CI enforces this non-skipping
```

All of these gates run in CI (`.github/workflows/quality.yml`) on PHP 7.4 and 8.2 and must
pass from a clean checkout. A gate that cannot run fails CI; it never silently skips.

No production Composer dependency is required by the current runtime.

## Local WordPress fixture

`.wp-env.json` boots WordPress 6.8 on PHP 8.2 with both plugins and the theme mounted:

```bash
npm ci
npx @wordpress/env start   # requires Docker
npx @wordpress/env run cli wp plugin activate music-wave-core music-wave-vip
npx @wordpress/env run cli wp theme activate musicwave
```

WooCommerce is optional; install it inside the fixture with
`npx @wordpress/env run cli wp plugin install woocommerce --activate` when testing commerce paths.

See `docs/site-editor-audit-fa.md` for the current FSE audit, architecture rules, and staged Site Editor upgrade plan.
See `docs/translations.md` for the text-domain contract, dependency-free POT generation, and Persian/RTL release checklist.
See `docs/download-qualities-and-hosting.md` for the protected multi-quality workflow, shared-hosting boundaries, and WooCommerce membership adapter contract.

## Architecture rules

- Business logic and persistent data never belong in the theme.
- WordPress callbacks remain thin and delegate to modules/services.
- Input is sanitized and validated at boundaries; dynamic output is escaped at render time.
- Restricted resources use deny-by-default policies and never expose a direct private-file URL.
- Public code remains PHP 7.4 compatible until the version policy changes in the backlog.

## Versioning and compatibility

`release-manifest.json` is the single source of truth for package versions, the data schema
version, and compatibility ranges. The runtime support policy (PHP 7.4 floor for the current
line, PHP 8.2+ at the next major) is recorded in `docs/adr/0003-runtime-support-policy.md`.

## Distribution

All packages are licensed GPL-2.0-or-later (see `LICENSE`); the third-party inventory lives in
`docs/third-party-notices.md`. Release packaging still requires the Stage 7 qualification gates
in `PROJECT_PLAN.md` before any archive is distributed.
