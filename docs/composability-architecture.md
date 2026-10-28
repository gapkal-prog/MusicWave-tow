# MusicWave composability architecture (design proposal — not yet implemented)

Status: **Stages B, A and D are implemented** (`bb18048`, `6ed2b59`, and the Stage D commit that
follows them). Stage C stays deferred on its trigger criteria. Every claim below was re-verified
against the working tree, and §10 records what changed during implementation, including where the
plan was wrong or incomplete.

Goal: make the complex MusicWave blocks genuinely modular and Site Editor–friendly — a
Query-Loop-like editing experience — **without** breaking dynamic PHP rendering, shipped
templates, saved content, or the doctrine in `docs/block-upgrade-roadmap.md` §2.3 / §2.11.

---

## 1. What the code actually does today (verified)

### 1.1 The native composition layer already exists — and has drifted

Five templates already use the WordPress Query Loop model:

| Template | Card structure inside `core/post-template` |
|---|---|
| `archive-mw_release.html` | flat: `post-featured-image`, `post-title`, `release-meta`, `preview-button`, `post-excerpt` |
| `taxonomy-mw_genre.html` | flat: `post-featured-image`, `post-title`, `release-meta`, `preview-button` |
| `archive.html` | flat: `post-featured-image`, `post-title`, `post-excerpt` (no meta, no preview) |
| `search.html` | structured: `group.mw-release-card__media` (`post-featured-image` + `group.mw-release-card__overlay` > `preview-button`) + `group.mw-release-card__body` (`post-title`, `release-meta`, `post-excerpt`) |
| `taxonomy-mw_artist.html` | structured, same as `search.html` |

`catalog.css` ships the structured design (`.mw-release-card__media/__overlay/__body`, lines
861–1090), and the preview button is only positioned as an artwork overlay through
`.mw-release-card__overlay .mw-preview-button` (line 1070). So in the three *flat* templates the
`preview-button` renders as an ordinary button in the card flow instead of over the artwork.
That is a live visual inconsistency between archive, genre, search and artist screens — caused
precisely by the absence of one shared, canonical card composition.

### 1.2 The same card is implemented three times in PHP, plus once in templates

`mw-release-shelf__item` markup is emitted independently by:

1. `music-wave-core/src/Blocks/ReleaseBlocks.php` → `related_card()` (≈ line 2021)
2. `music-wave-core/src/Blocks/ListeningBlocks.php` → continue-listening card (≈ lines 244, 260)
3. `musicwave/functions.php` → release-shelf card (≈ lines 2257, 2268) and the playlist variant
   (≈ line 2120)

The artwork wrapper line is **byte-identical** in all three:

```php
'<div class="mw-release-shelf__artwrap"><a class="mw-release-shelf__art mw-release-shelf__art--' . esc_attr( $shape ) . '" href="…'
```

`ArtistShelfBlock.php` and `TaxonomyShelfBlock.php` reuse the `mw-release-shelf__items`
container with their own tile markup. Templates then use a **second, disjoint vocabulary**
(`mw-release-card__*`). Net result: two card vocabularies, four+ emitters, no shared renderer.

### 1.3 Attribute soup where composition belongs

Attribute counts per `block.json`: `release-shelf` **46**, `request-form` **27**,
`artists-shelf` **23**, `related-releases` **23**, `account-dashboard` **21**,
`public-playlists` **21**, `continue-listening` **18**, `taxonomy-shelf` **16**.

The section-header cluster (`eyebrow`, `title`, `description`, `sectionUrl`,
`sectionLinkLabel`) is re-declared in **8 blocks / 22 attribute entries**, and rendered by
duplicated helpers (`musicwave_render_shelf_header()`, `related_section()`, …) — even though the
theme already ships:

* `.mw-section-head` + `__text` / `__rule` / `--center` / `--stack` / `--invert` in
  `editorial.css` (lines 78–145);
* the `musicwave/section-heading` **pattern**, which builds the identical header from
  `core/group` + `core/paragraph` + `core/heading` + `core/paragraph`.

`release-shelf.filterTabs` is worse: a textarea with a private `label|orderBy:date` /
`label|taxonomy:mw_genre:slug` mini-DSL parsed in PHP. That is exactly the "parallel
mini-framework" the brief forbids.

### 1.4 Editor architecture

* No `InnerBlocks` and no `useBlockProps` anywhere: neither in
  `music-wave-core/assets/blocks.js` nor in `musicwave/assets/editor-blocks.js`.
* Every block previews through `ServerSideRender` inside a plain
  `div.mw-block-editor-shell` wrapper; PHP is the only renderer (§2.3.4).
* Client registration re-registers server-hydrated types, preserving server metadata.

### 1.5 Hard constraints found in the test suite and the roadmap

| Constraint | Where | Consequence for this design |
|---|---|---|
| Every `music-wave/*` / `musicwave/*` block comment in a bundled template **must be self-closing** ("without fallback HTML") | `tests/template-integrity.php` ≈ lines 302–309 | A MusicWave block used *with children* in a template fails this assertion. The open/close pairing walker at ≈315–331 already handles paired comments, so only this one assertion needs amending — and only if a child-bearing block enters a bundled template. |
| "The block itself renders the whole experience, so no wrapper template part or single-block pattern duplicates it (an integrity test guards against re-introducing that layer)" | roadmap §2.11.1; test ≈ lines 697–714 | Forbids wrapping a fat block in a new container block or a one-block pattern. Any container introduced must add capability, not duplicate an existing block's output. |
| All changes additive; modifier classes appended only when non-default; optional trailing params on shared methods | roadmap §2.3.6 | No renderer signature may change incompatibly; extraction must be delegation, not replacement. |
| 4-location parity contract (block.json ↔ server PHP ↔ `Rendering.php::editor_blocks()` ↔ `blocks.js` fieldConfig ↔ theme CSS), asserted by the integrity test | roadmap §2.3.3 | Every new attribute on a dynamic block costs four edits + a test. This is a strong argument for preferring **composition** over **new attributes**. |
| `register_block_type( $folder, $args )` merges `$args` **over** block.json — pass only `render_callback` | roadmap §2.10 | Any new dynamic block registers through `BlockSupport::register_dynamic()`. |
| Theme→Core delegation is already an established pattern | `functions.php:1400`, `1787`, `1996` (`class_exists('\ManaCore\MusicWave\Core\…')`), plus the `music_wave_card_play_button` filter | The shared card renderer can live in Core with the theme delegating. |
| **The theme's release surfaces are already unreachable without Core** | `mw_release` is registered *only* by `music-wave-core/src/Catalog/ReleasePostType.php:58`; `musicwave_render_release_shelf()` (functions.php:2173) and `musicwave_render_release_slider()` (1483) both bail on `! post_type_exists('mw_release')`, and `musicwave_render_playlist_shelf()` (1996) bails on a Core `class_exists()` | The theme's duplicate card markup is **not** a working standalone fallback — it is dead code when Core is absent. It can therefore be *replaced* by delegation instead of shadowed by a fallback copy, which is what makes Stage B a real dedupe rather than an extra indirection layer. |

---

## 2. The architectural crux: `InnerBlocks` cannot live inside `ServerSideRender`

This is the decisive technical fact, and it shapes everything else.

`WP_REST_Block_Renderer_Controller::get_item()` builds the preview block as
`new WP_Block( $block_type, $attributes, … )` — **without a parsed block**, so
`innerBlocks` is empty and the `render_callback` receives `$content === ''`.

Consequences:

1. A `ServerSideRender` preview can **never** show live inner blocks. The children region of any
   InnerBlocks-enabled block must be rendered by React in `edit`.
2. If the parent's own chrome is PHP-rendered attribute markup, giving it InnerBlocks forces a
   **second, JS implementation of that chrome** — duplicated markup, permanent drift, and a
   direct violation of §2.3.4/§2.3.5.
3. Therefore: **a fat, data-driven MusicWave block must not receive InnerBlocks.**

How WordPress itself resolves this is the model to copy:

| Core block | Shape | Inner blocks? |
|---|---|---|
| `core/query` | container: query attributes + children (`post-template`, `query-no-results`, `query-pagination`) | **yes** — edit is pure React, no SSR |
| `core/post-template` | repeater: clones its children per row inside `BlockContextProvider`, supplies `postId`/`postType` | **yes** — pure React |
| `core/group` | static container, layout support | **yes** — static `save`, no PHP at all |
| `core/latest-posts`, `core/archives`, `core/calendar`, `core/latest-comments` | data-driven leaf; PHP render + `ServerSideRender` preview | **no** |

MusicWave's fat blocks (`release-shelf`, `related-releases`, `public-playlists`,
`continue-listening`, `artists-shelf`, `taxonomy-shelf`, `music-library`, `account-dashboard`)
are all `core/latest-posts`-class blocks. Core never gives those InnerBlocks — and neither
should we. What MusicWave is missing is not inner blocks inside the fat blocks; it is the
**container + leaf composition layer around them**, which core already provides.

WooCommerce faced the identical question and ships both lanes side by side: the fat
`woocommerce/product-collection` block *and* hand-composed query loops, both built from the same
`woocommerce/product-*` child blocks. That is the target shape here.

**Decision:** compose with core containers (`core/query`, `core/post-template`, `core/group`),
extend with MusicWave **leaf** blocks (which fit the existing SSR architecture perfectly), and
introduce InnerBlocks only where a block is a **static container** — so there is no PHP chrome to
duplicate.

---

## 3. Proposed architecture

Four stages, each independently shippable and each additive. Stages A and B carry most of the
value and no editor-architecture risk.

```
Lane 1 — "one insert, zero config" (unchanged, marketplace product)
  music-wave/release-shelf · related-releases · public-playlists · continue-listening
  artists-shelf · taxonomy-shelf · music-library · account-dashboard
        │
        └── delegates to ──► [Stage B] Core\Blocks\ReleaseCard  (ONE card renderer)
        └── delegates to ──► [Stage B] Core\Blocks\SectionHead  (ONE header renderer)

Lane 2 — "native composition, individually editable" (Query-Loop-like)
  core/query ─► core/post-template ─► group.mw-release-card
                                        ├─ group.mw-release-card__media
                                        │    ├─ core/post-featured-image
                                        │    └─ group.mw-release-card__overlay ─► music-wave/preview-button
                                        └─ group.mw-release-card__body
                                             ├─ core/post-title
                                             ├─ music-wave/release-meta      (leaf, SSR)
                                             ├─ core/post-excerpt
                                             └─ music-wave/library-button | share-button | add-to-queue (leaves)
        ▲
        └── [Stage A] musicwave/release-card + musicwave/release-grid patterns make this discoverable
            and identical across all five templates

Lane 3 — static containers with InnerBlocks (new, zero PHP)
  music-wave/section-head  ── InnerBlocks, template, templateLock:false, block styles
                             (replaces the 8×5 attribute header cluster for NEW content)
```

### Stage A — Canonical card + grid patterns, template convergence (no new blocks)

**Affected:** `archive-mw_release.html`, `archive.html`, `search.html`,
`taxonomy-mw_artist.html`, `taxonomy-mw_genre.html`; new patterns.

* Create `musicwave/patterns/release-card.php` (slug `musicwave/release-card`,
  `Block Types: core/group, core/post-featured-image, core/post-title, core/post-excerpt`)
  encoding the **structured** card (`__media` + `__overlay` + `__body`) — the variant the CSS
  actually supports.
* Create `musicwave/patterns/release-grid.php`: `core/query` (`inherit:true`) →
  `core/post-template` (`mw-release-grid`, grid/3) → the card → `core/query-no-results` →
  `core/query-pagination`.
* Rewrite the five templates' card markup to the canonical structure, **inline-expanded** (not
  `<!-- wp:pattern -->` references). Rationale: a `core/pattern` block is a single opaque unit in
  the editor — referencing it would make the card *less* individually editable, which is the
  opposite of the goal. The pattern exists for the inserter; templates carry real blocks.
* Result: the preview button is overlaid on artwork on every archive surface; `release-meta`
  present everywhere it belongs; one card structure to style and test.

All `music-wave/*` usages stay self-closing, so the integrity assertion in §1.5 is untouched.

### Stage B — Extract the shared card and header renderers into Core (no editor change)

Scope was narrowed after the four emitters were read line by line:

* In scope: `ReleaseBlocks::related_card()`, `ListeningBlocks::history_card()`, the theme's
  release-shelf card loop, plus the two golden-covered headers
  (`musicwave_render_shelf_header()` and `ReleaseBlocks::related_section()`).
* **Out of scope: the playlist shelf card** (`functions.php:2116–2120`). It only borrows the
  shelf's BEM skeleton — its artwork is a 2×2 collage of member releases, its play control is
  `data-mw-playlist-play`, its meta line is a track count (`__count`) and its data source is
  `PlaylistRepository`. Forcing it through a release-shaped renderer would require overriding
  almost every part, which is the mini-framework smell this plan exists to avoid.
* **Out of scope: the `ListeningBlocks` header** (`mw-continue-listening__header`). It is built
  inline inside `render()`, which the parity harness does not drive; extracting it unverified
  would trade a proven-safe change for an unproven one. Deferred until the harness covers it.

**Create**

* `music-wave-core/src/Blocks/ReleaseCard.php` — `final class`, static API, no dependencies
  beyond WordPress functions:
  * `presentation_data( int $release_id ): array` — `id`/`link`/`title`/`artist`/`initial`; the
    logic of `musicwave_release_presentation_data()` moved into Core so both packages share it.
  * `artwork( array $data, array $options ): string` — the `__artwrap` / `__art` / `__image` /
    `__placeholder` unit. Options: `shape`, `image_attributes` (the extra `loading` /
    `fetchpriority` / `decoding` hints, merged after the fixed `class` + `alt`), `overlay`
    (caller-resolved HTML) and `open_label` (caller-resolved, so each product keeps its own
    textdomain).
  * `render( array $data, array $options ): string` — the full
    `<article class="mw-release-shelf__item">`. Options add `extra_item_classes`, `show_artist`,
    `show_excerpt`, `show_action`, `action_label`, `meta_html` (the `<time>` element — a release
    date for the shelf, a "played ago" stamp for continue-listening) and `body_html` (the in-body
    preview button, used only by related-releases).
  * The split rule: **the shared class owns the skeleton and the parts that are byte-identical
    today; each caller keeps its own policy** (overlay fallback, perf hints, meta line, in-body
    button). That is why the option surface stays at ~11 documented keys instead of growing into
    a configuration language.
* `music-wave-core/src/Blocks/SectionHeader.php` — `more_link( string $class, string $url, string
  $label ): string` (the `<a class="{root}__more">…<span aria-hidden="true">&rarr;</span></a>`
  fragment shared by both golden-covered headers) and `shelf_header( string $eyebrow, string
  $title, string $description, string $url, string $label ): string` matching the theme's current
  markup exactly, with the caller resolving its own default label.

**Modify (delegation only, additive per §2.3.6)**

* `ReleaseBlocks.php`: `related_card()` and `related_section()` delegate; `related_card_options()`
  keeps its exact shape and gains no attributes.
* `ListeningBlocks.php`: `history_card()` delegates, keeping `mw-continue-listening__item` via
  `extra_item_classes` and its "played ago" line via `meta_html`.
* `musicwave/functions.php`: the release-shelf card loop delegates to `ReleaseCard`,
  `musicwave_release_presentation_data()` delegates to `ReleaseCard::presentation_data()`, and
  `musicwave_render_shelf_header()` delegates to `SectionHeader`. The theme keeps
  `class_exists()` guards around each call, matching how it already guards `Settings` and
  `BlockSupport`; because these paths are unreachable without Core (§1.5), no duplicated fallback
  markup is retained.
* `ArtistShelfBlock.php` / `TaxonomyShelfBlock.php`: unchanged in Stage B — they reuse the
  `mw-release-shelf__items` *container* but render their own tiles, which are not release cards.

**Acceptance criterion for Stage B: zero markup change**, enforced by
`tests/card-markup.php` (see §7.3) — 30 recorded cases spanning all five producers, both thumbnail
branches, the `music_wave_card_play_button` filter seam, and the header variants. No CSS edits, no
attribute edits, no parity edits: that is what makes this stage safe.

### Stage C — One new leaf block, only if Stage A proves insufficient

Candidate: `music-wave/release-card-media` (artwork + overlay + play button as a single
context-driven leaf, `usesContext: [postId, postType]`).

**Recommendation: do not build it yet.** Stage A already delivers the same card from
`core/post-featured-image` + `core/group` + `music-wave/preview-button`, all individually
editable, with no new parity burden. Build it only if operator feedback shows editors keep
breaking the overlay group. Criteria for triggering it are listed in §8.

### Stage D — `music-wave/section-head`: the single, deliberate InnerBlocks block

**Why this one:** its chrome is trivial and already styled (`.mw-section-head` + modifiers); it
replaces a 22-attribute duplication; and — decisively — it can be a **static** block, so there is
no PHP renderer, no `ServerSideRender`, no REST preview problem and no 4-location parity cost.

* `musicwave/blocks/section-head/block.json`: `apiVersion: 3`, `category: music-wave`,
  `supports: { align, anchor, color, spacing, typography, __experimentalSelector }`,
  no `usesContext`, `example` with innerBlocks.
* `edit`: `useBlockProps()` + `InnerBlocks` with
  `template = [[core/paragraph eyebrow], [core/heading h2], [core/paragraph description]]`,
  `templateLock: false` (editors may add/remove/reorder — this is the point).
* `save`: `useBlockProps.save({ className: 'mw-section-head' })` + `<InnerBlocks.Content />`,
  with the `__text` / `__rule` structure produced by the template rather than by PHP.
* Block styles registered from the existing modifiers: `center` (`--center`), `stack` (`--stack`),
  `invert` (`--invert`) — reusing `register_block_style()` exactly as `Rendering::register_block_styles()`
  and `musicwave_register_block_styles()` already do.
* Registered in `musicwave/functions.php` beside the other theme blocks; client settings added to
  `musicwavePresentationBlocks` and handled in `editor-blocks.js` with the existing
  `if ( blocks.getBlockType( name ) ) return;` guard (the safe pattern already used there).

Because it is static, nothing in the dynamic-block pipeline is touched: no `editor_blocks()`
entry, no `fieldConfig`, no SSR.

### Explicitly rejected

| Option | Why rejected |
|---|---|
| Add InnerBlocks to `release-shelf` / `related-releases` / `public-playlists` / etc. | Their chrome is PHP attribute markup; InnerBlocks would force a duplicate JS chrome (§2). Also collides with §2.11.1 (wrapper duplicating the block's own experience). |
| Wrap fat blocks in a new `music-wave/shelf-container` | Directly forbidden by §2.11.1 and guarded by an integrity test. |
| Convert templates to `<!-- wp:pattern -->` references | `core/pattern` is opaque in the editor; it would *reduce* per-part editability. |
| Rewrite `release-shelf` as a container + card children | A from-scratch rewrite of a 46-attribute shipping block; violates the brief and §2.3.6. |
| Delete the `filterTabs` DSL now | Working functionality in saved content. Deferred to §8 with a transform-based path. |

---

## 4. Files to create / modify

**Create**

| File | Stage | Purpose |
|---|---|---|
| `musicwave/patterns/release-card.php` | A | Canonical card for the inserter |
| `musicwave/patterns/release-grid.php` | A | Query + post-template + card + pagination + no-results |
| `music-wave-core/src/Blocks/ReleaseCard.php` | B | Single release-card renderer (`presentation_data`, `artwork`, `render`) |
| `music-wave-core/src/Blocks/SectionHeader.php` | B | Shared `more_link()` + `shelf_header()` |
| `tests/card-markup.php` | B | **Done** — standalone parity gate for all five card/header producers |
| `tests/fixtures/card-markup.json` | B | **Done** — 30 recorded pre-refactor cases |
| `musicwave/blocks/section-head/block.json` | D | Static container metadata |
| `musicwave/assets/css/components/section-head.css` *(only if `.mw-section-head` needs block-style hooks)* | D | Reuse `editorial.css` first; add nothing unless required |

**Modify**

| File | Stage | Change |
|---|---|---|
| `musicwave/templates/{archive-mw_release,archive,search,taxonomy-mw_artist,taxonomy-mw_genre}.html` | A | Converge on the structured card |
| `music-wave-core/src/Blocks/ReleaseBlocks.php` | B | `related_card()` / `related_section()` delegate |
| `music-wave-core/src/Blocks/ListeningBlocks.php` | B | card delegates |
| `musicwave/functions.php` | B, D | shelf + playlist card delegate with fallback; `musicwave_render_shelf_header()` delegates; register `section-head` |
| `music-wave-core/src/Blocks/ArtistShelfBlock.php`, `TaxonomyShelfBlock.php` | B | header delegates |
| `musicwave/assets/editor-blocks.js` | D | `edit`/`save` for `section-head` (InnerBlocks + useBlockProps) |
| `tests/template-integrity.php` | A, B, D | New contracts (§7); amend the self-closing assertion **only if** `section-head` enters a bundled template |
| `docs/block-audit-fa.md`, `docs/block-upgrade-roadmap.md` | all | Record the two-lane architecture and the new DO-NOT-DEVIATE items |

Untouched by design: all 27 `music-wave-core/blocks/*/block.json`, `Rendering.php::editor_blocks()`,
`blocks.js` `fieldConfig`, `BlockSupport::register_dynamic()`, `theme.json`, and every renderer's
public behaviour.

---

## 5. Rendering strategy

| Layer | Editor | Frontend | Source of markup |
|---|---|---|---|
| Fat dynamic blocks (Lane 1) | `ServerSideRender` (unchanged) | `render_callback` (unchanged) | PHP only — now via `ReleaseCard` / `SectionHead` |
| Card composition (Lane 2) | Real blocks; each MusicWave leaf previews via its own SSR with `post_id` (already fixed) | `core/post-template` renders leaves per row | Core blocks + existing leaves; no new PHP |
| `music-wave/section-head` (Lane 3) | `useBlockProps()` + `InnerBlocks` (live React) | stored markup from `save()` | JS only — no PHP renderer, no REST round-trip |

The rule that keeps this coherent: **a block either renders itself in PHP (leaf, SSR preview) or
stores itself from JS (static container, InnerBlocks) — never both.** Mixing the two in one block
is what produces duplicated chrome.

---

## 6. Migration and backward compatibility

* **Saved content:** Stages A–B change no block schema, so no stored block is affected. Stage D
  adds a block; it cannot invalidate anything.
* **Templates:** bundled templates are only read from disk when the theme is (re)activated or a
  template has no DB customisation. User-customised templates in `wp_template` posts keep their
  existing card markup and continue to render — the CSS supports both the flat and structured
  forms (`.mw-release-card` at `catalog.css:574`, `__media`/`__body` at `catalog.css:871`/`918`).
  No forced migration, no `deprecated` entries needed.
* **Theme without the plugin:** `functions.php` keeps its inline card markup behind
  `class_exists()` guards, exactly as it already does for `Settings` and `BlockSupport`.
* **Plugin without the theme:** `ReleaseCard` / `SectionHead` live in Core, so Core-only installs
  gain the shared renderer; class names are unchanged, so theme CSS still applies when present.
* **Future decomposition of a fat block** (e.g. `filterTabs`): only ever as a **new** block plus a
  `transforms.from` entry converting old attributes into child blocks. The old block stays
  registered and rendering. This is the native migration mechanism and it is the only one we will
  use.
* **`is-style-*` and modifier classes:** unchanged; `BlockSupport::style_variation()` precedence
  over legacy attributes is preserved.

---

## 7. Testing requirements

**Static gates (must pass in CI; runnable without a browser)**

1. `php tests/run.php` (which owns `mw_assert_same`; never run `template-integrity.php` directly).
2. `composer check:syntax`, `composer check:phpcs` (exit 0), `composer check:phpstan`
   (`--memory-limit=2G`), `php tools/check-templates.php`, `npm run lint:js`,
   `composer check:script-translations`, `composer make-pot` if any string is added.
3. **Card/header markup parity gate — implemented:** `tests/card-markup.php`
   (fixtures in `tests/fixtures/card-markup.json`, wired into `composer test`, record with
   `composer test:record-cards`). It is a **standalone entry point** with its own WordPress
   doubles and must not be required from `tests/run.php`, whose `get_the_post_thumbnail()` stub
   always returns `''` and would leave the artwork branch uncovered. Design notes:
   * instances are built with `ReflectionClass::newInstanceWithoutConstructor()` and the private
     `repository` is injected, so the card methods are exercised without the
     `AccessPolicyEngine`/`PurchaseChecker`/membership dependency graph;
   * the thumbnail double **echoes back the attribute array it receives**, so the fixtures pin
     each caller's `loading`/`fetchpriority`/`decoding` hints — the one place the three card
     producers genuinely differ (`related_card` sends only `class|alt`, `history_card` sends
     `lazy/low/async`, the theme sends `eager/high` for its first two items and `lazy/low` after);
   * `musicwave/functions.php` is loaded for real, which requires defining `ABSPATH` — the theme
     and both `inc/` files open with `if ( ! defined( 'ABSPATH' ) ) { exit; }` and exit silently
     otherwise;
   * `<time datetime>` from `history_card()` is derived from the real clock, so that one attribute
     value is normalised to `{TIMESTAMP}`; everything around it, including the relative
     "played ago" text, stays byte-asserted;
   * 30 cases: `related:*` (8), `history:*` (4), `theme-shelf:{defaults,full,filtered}:item-*`
     (9), `header:shelf:*` (5), `related-section:*` (5). Verified to fail loudly on a deliberate
     one-class perturbation and to pass again after revert, and to be reproducible across runs.
4. **New integrity contracts:**
   * the five templates each contain `mw-release-card__media`, `mw-release-card__overlay` and
     `mw-release-card__body`, and `music-wave/preview-button` appears only inside the overlay
     group;
   * `musicwave/release-card` and `musicwave/release-grid` are registered patterns;
   * `mw-release-shelf__artwrap` is emitted from exactly one PHP file (`ReleaseCard.php`), with
     the theme fallback counted as a second, guarded occurrence;
   * `section-head` declares `template` and `templateLock` and has **no** `render_callback`
     (assert it is absent from `editor_blocks()` and from `BlockSupport::register_dynamic()` call
     sites), so the static/dynamic rule cannot silently regress.
5. Extend the existing variation↔stylesheet contract for any new `section-head` block style.

**Runtime gates (need WordPress; cannot run in this sandbox)**

6. Playwright (`tests/e2e/editor-blocks.spec.js`): add a scenario that inserts
   `music-wave/section-head`, edits a child heading, saves, and asserts both the stored markup and
   the frontend `.mw-section-head`.
7. Manual Site Editor pass: open each of the five templates, confirm the card renders identically
   on frontend and in the editor preview, confirm each card part is individually selectable and
   editable, confirm no console errors and no "cannot be previewed" boundary on the Query Loop.
8. Block-validation check on a post saved *before* Stage D and re-opened after (must not show the
   "block contains unexpected or invalid content" notice).
9. RTL + all four theme styles (`aurora`, `studio`,
   `sunrise`) at 360 px / 768 px / 1280 px.
10. Theme-only install (Core deactivated) and Core-only install (default theme) both render the
    shelf without fatals.

---

## 8. Risks and handling

| Risk | Likelihood | Impact | Handling |
|---|---|---|---|
| Stage B extraction subtly changes card HTML (attribute order, `esc_*` placement, whitespace) | Medium | High (visual regressions across every shelf) | Golden-output byte comparison (§7.3) is a merge gate; extraction is mechanical delegation with no re-formatting. |
| Template convergence changes a shipped look users rely on | Medium | Medium | Structured card is already the CSS-supported design and already live on search + artist archives; flat templates are the outlier. Verify per-template screenshots before/after. |
| `tests/template-integrity.php` self-closing assertion fails once a child-bearing block enters a template | Low | Blocks CI | Only Stage D could trigger it. Either keep `section-head` out of bundled templates, or amend the assertion to "self-closing **or** correctly paired" — its intent is "no fallback HTML for dynamic blocks", which does not apply to a static container. Decide before implementing; do not weaken it silently. |
| `section-head` competes with `core/group` + the existing `section-heading` pattern | Medium | Low (confusion, not breakage) | Ship it only with a clear description/keywords and retire the pattern **or** keep the pattern as the "no new block" option. Decide in review; do not ship both without stating the difference. |
| Editor JS for a static block is the first `useBlockProps`/`InnerBlocks` use in the codebase | Medium | Medium | It is confined to `editor-blocks.js`, which already uses the safe `if ( getBlockType() ) return;` registration guard; no change to `blocks.js`. Lint with `npm run lint:js`. |
| New strings desync `.po` / `.mo` | Medium | Low | The final quality pass regenerated `.pot` / `en_US.po` / `.mo` / JED JSON and kept stale extra msgids. Budget `php tools/generate-pot.php` + merge + `.mo` / script JSON in the same change as any new strings. |
| Scope creep into rewriting `release-shelf` (46 attributes) | High (temptation) | High | Explicitly out of scope (§3, rejected table). Any decomposition must be a new block + `transforms.from`, deferred until Stages A/B/D are green and operator feedback exists. |
| Cannot validate anything at runtime in this sandbox | Certain | Medium | Every stage is gated on the CI commands in §7.2 plus the runtime passes in §7.6–10 before it is called done. No stage is declared complete on static checks alone. |

**Trigger criteria for the deferred work** (Stage C leaf block, `filterTabs` decomposition):
operator reports of broken overlay groups (→ Stage C), or a second block needing tabbed filtering
(→ replace the DSL with a `music-wave/filter-tab` child block + `transforms.from`, never an
in-place rewrite).

---

## 9. Sequencing

1. **Stage B first** (shared renderers + golden test). It is invisible to users, removes the
   duplication that would otherwise be copied into every later change, and is the only stage with
   a hard byte-identical acceptance criterion.
2. **Stage A** (patterns + template convergence) once B is green — the templates then compose the
   same card the PHP shelves render.
3. **Stage D** (`section-head`) as the InnerBlocks pilot, after A/B are stable, with its own E2E
   scenario.
4. **Stage C** only on the trigger criteria above.

Each stage is a separate commit and separately revertable.

---

## 10. Implementation log

### 10.1 Stage B — done (`bb18048`)

`Core\Blocks\ReleaseCard` (`presentation_data`, `initial`, `artwork`, `render`) and
`Core\Blocks\SectionHeader` (`more_link`, `shelf_header`) now own the shared markup;
`ReleaseBlocks::related_card()`, `ReleaseBlocks::related_section()`,
`ListeningBlocks::history_card()`, the theme's release-shelf card loop,
`musicwave_render_shelf_header()` and `musicwave_release_presentation_data()` all delegate.

Two plan corrections discovered while implementing:

* **The theme needed no fallback copy.** `mw_release` is registered only by
  `music-wave-core/src/Catalog/ReleasePostType.php`, and every theme release renderer bails on
  `! post_type_exists( 'mw_release' )` (or on a Core `class_exists()` for playlists). The
  duplicated theme markup was therefore unreachable without Core, so it was *replaced* rather than
  shadowed by a fallback — which is what turned this from an extra indirection layer into a real
  removal of duplication.
* **Two producers were deliberately left alone.** The playlist shelf card
  (`functions.php:2116–2120`) is a different domain object — collage artwork, `data-mw-playlist-play`,
  a track-count meta line, `PlaylistRepository` as its source — and forcing it through a
  release-shaped renderer would have meant overriding nearly every part. The continue-listening
  *header* is built inline inside `render()`, which the parity harness does not drive; extracting it
  unverified would have traded a proven change for an unproven one.

Results: 30/30 parity cases byte-identical; **0 msgids gained or lost** in both catalogs; the four
Core files are phpcs-clean; `functions.php` unchanged at its pre-existing 5 errors / 18 warnings.
`template-integrity.php` now asserts the card classes live in `ReleaseCard.php` *and* that all three
producers delegate without re-inlining `mw-release-shelf__artwrap`.

### 10.2 Stage A — done

**Canonical card** (derived from `search.html`, the template `catalog.css` was written for, and
byte-checked against it by the generator before any file was written):

```
group.mw-release-card.mw-surface            (constrained)
├── group.mw-release-card__media            (constrained)
│   ├── core/post-featured-image            isLink, aspectRatio 1
│   └── group.mw-release-card__overlay      (flex, centred)
│       └── music-wave/preview-button
└── group.mw-release-card__body             (constrained)
    ├── core/post-title                     isLink, fontSize large
    ├── music-wave/release-meta             compact, showLibraryButton false
    └── core/post-excerpt                   (per surface — see below)
```

Templates converged: `search.html` (gained `mw-surface`), `archive-mw_release.html`,
`taxonomy-mw_genre.html` (both restructured flat → media/overlay/body), `archive.html`
(restructured to media/body). `taxonomy-mw_artist.html` was **already canonical and was not
touched**. `taxonomy-mw_genre.html` and `taxonomy-mw_artist.html` now hold byte-identical cards.

Patterns added: `musicwave/release-card` (the canonical card, `Block Types: core/post-template`,
category `musicwave-cards`) and `musicwave/release-grid` (native `core/query` over `mw_release` +
`core/post-template` + the same card + `core/query-no-results` + pagination). Both embed the
identical card markup, inline-expanded — never referenced from templates via `wp:pattern`, because
a pattern block is opaque in the editor and would have made card parts *less* selectable.

Decisions, with the evidence that settled them:

| Decision | Why |
|---|---|
| Card root is `mw-release-card mw-surface` on all five | `.mw-release-card` already sets background, border and radius; `.mw-surface` (`utilities.css:5`) adds only `box-shadow: var(--mw-shadow-card)`. Four of five templates carried it, so converging on it changes one surface (search gains the standard card elevation) instead of four. |
| `showLibraryButton:false` everywhere | `ReleaseBlocks.php:690` returns early in compact mode, so the library/actions buttons at lines 708–715 are **unreachable** — every one of these cards uses `compact:true`. The attribute had no effect anywhere; converging removes a dead attribute, not a control. The library action still lives on `single-mw_release.html`. |
| Each surface keeps its own `post-excerpt` comment verbatim | `moreText:"مشاهده انتشار"` means "view release", which would mislabel a regular post — that is why `archive.html` uses `moreText:""`. Excerpt presence and length are per-surface content decisions; changing them would be a redesign, which Stage A excludes. |
| `archive.html` gets `__media` + `__body` but **no `__overlay`** | It is the generic archive (`mw-generic-archive`, `query.inherit:true`) and renders any post type, so release-only blocks stay out. An empty overlay is not harmless: `.mw-release-card__overlay` (catalog.css:1049) paints a gradient on `__media:hover`, so an overlay with nothing in it would still darken the artwork. |
| Pagination left as-is | `search.html` uses `mw-catalog-pagination` + `flexWrap`; the other four use a bare pagination. That is real drift, but pagination is not the card and converging it would change visuals on four surfaces — outside Stage A. Recorded here as a follow-up. |

New integrity contracts in `tests/template-integrity.php`: the `release-card` pattern must stay
**byte-identical** to the `search.html` card (so inserter and templates cannot drift); `release-grid`
must embed that same card and compose a real Query Loop with an empty state; all four release
templates must start with the canonical media+overlay prefix — *derived from the pattern at
runtime*, not restated — and must place exactly one preview button and keep compact release meta;
`archive.html` must have media+body and must contain no overlay and no `music-wave/*` block.

Both new gates were negative-controlled: moving the preview button out of the overlay in
`taxonomy-mw_genre.html` fails with a precise message, and renaming `__body` in the pattern fails
the byte-identity assertion. Both pass again after revert.

### 10.3 Outstanding, deliberately not done in Stage A

* **9 new translatable strings** — 2 in `patterns/release-grid.php` (Stage A) and 7 from Stage D
  (the section-head title, description, one keyword and the three Styles-panel labels plus two
  template placeholders that were already catalogued). Measured by running `tools/generate-pot.php`
  over an archive of HEAD and over the working tree and diffing the msgid sets, so the count is
  exact rather than estimated. They are not yet in `musicwave.pot` because regenerating would also
  pull in the **pre-existing** staleness (67 msgids in `musicwave.pot`, 6 in `music-wave-core.pot`,
  missing at HEAD before any of this work). All of it belongs to one deferred pot/po/mo pass.
* **Pre-existing phpcs debt**: 18 errors / 37 warnings in `musicwave/functions.php` (5/18),
  `inc/nav-icons.php` (1/3) and `inc/site-header.php` (12/16). The `site-header.php` errors are real
  findings — `$_POST` reads without nonce verification and unsanitized. Present at HEAD; untouched
  here by instruction.
* **Stage C** (`music-wave/release-card-media` leaf) is not started; its trigger criteria are in §8.

### 10.4 Verification environment

The sandbox has no PHP binary, so the gates are run through a WebAssembly PHP 8.x
(`@php-wasm/node`) with a WPCS toolchain assembled from GitHub *source* archives (release assets are
network-blocked; `codeload` and npm are not). Consequences worth recording:

* **Exit codes do not propagate** through that wrapper — `exit(3)` reports 0 — so every gate is
  judged on its output text, never on `$?`.
* `tools/check-syntax.php` cannot run (it shells out to `php -l` via `exec()`); a `TOKEN_PARSE`
  sweep that throws real `ParseError`s is used instead — 219/219 files clean.
* **phpstan cannot run at all** here: its `.phar` is a blocked release asset and the source needs
  composer plus the `php-stubs/*` packages. `composer check:phpstan` still has to be run in CI.
* `npm run lint:js` cannot run here (`node_modules` absent). Stage D *does* touch JavaScript, so its
  runtime behaviour was verified with a dependency-free harness instead: `tests/editor-lanes.js`
  executes the real `musicwave/assets/editor-blocks.js` in a `vm` context against a stubbed `wp.*`,
  which needs no npm install. `npm run test:editor-lanes` is committed; wiring it into
  `.github/workflows/quality.yml` needs the `workflows` permission the push token does not have, so
  the exact step is recorded in docs/block-upgrade-roadmap.md §2.15 for a maintainer to apply.
* The theme's files open with `if ( ! defined( 'ABSPATH' ) ) { exit; }` using a **bare `exit;`**, so
  any harness loading them must define `ABSPATH` or it terminates silently with no error recorded.

### 10.5 Stage D — done

`music-wave/section-head` is now the project's single deliberate InnerBlocks block: a static
container whose children are real blocks, registered from `musicwave/blocks/section-head/block.json`
with **no** `render_callback`, **no** attributes and **no** second script.

```
music-wave/section-head                     save(): useBlockProps.save({ className: 'mw-section-head' })
└── core/group .mw-section-head__text        (template seed, templateLock: false)
    ├── core/paragraph .mw-eyebrow           eyebrow
    ├── core/heading (level 2)               title
    └── core/paragraph                       description
```

Styles panel looks are the existing editorial modifiers, registered with `register_block_style()`:
`center` / `stack` / `invert` → `is-style-*`. `editorial.css` lists both spellings on each rule
(`.mw-section-head--center` and `.mw-section-head.is-style-center`), so a stored header and a
PHP-rendered header share one declaration set and cannot drift. All rules already used logical
properties, so RTL and the responsive clamp()s carry over unchanged.

**One correction to the plan.** §7 sketched the template as three flat children
(`[paragraph eyebrow], [heading], [paragraph]`). Against the real stylesheet that would have
broken the layout: `.mw-section-head` is `display:flex; justify-content:space-between; align-items:end`
(editorial.css:78), so three flat children sit *side by side*; only `.mw-section-head__text`
(`display:grid; gap:.4rem`) stacks them. The seeded template therefore wraps the three lines in the
`__text` group — which is also exactly the structure `patterns/vinyl-record-shelf.php` already
composes by hand, so the block and that pattern render identically. §7's own wording ("the `__text` /
`__rule` structure produced by the template") intended this; the inline sketch was inconsistent with
it. The shell stays a flex row, so an editor can still add a `__rule` hairline or a button as a
sibling — that is what the `order: 3` rule is for.

**Supports were narrowed on evidence, not copied from the sketch.** §7 listed
`align, anchor, color, spacing, typography, __experimentalSelector`. Three of those would have been
*dead controls*, which is worse than no control: `color.text` and `typography.fontSize`/`lineHeight`
are overridden by `.mw-section-head h2 { font-family/font-size/line-height }` and `.mw-section-head p { color }`,
and `spacing.blockGap` writes `--wp--style--block-gap` while the component sets `gap: 1.25rem`
itself. Shipped: `align: [wide, full]`, `anchor`, `html: false`, `color: { background, gradient }`,
`spacing: { margin, padding }`. Children keep their own native colour and typography controls, which
do work. Each exclusion is asserted with its reason in `tests/template-integrity.php`, so it cannot
be "restored" by accident.

**Coexistence with `musicwave/section-heading`** — both ship, in different inserter surfaces and for
different jobs, and the difference is documented in both directions:

| | `musicwave/section-heading` (pattern) | `music-wave/section-head` (block) |
|---|---|---|
| Inserter surface | Patterns tab (`musicwave`, `featured`, `musicwave-demos`, `musicwave-shelves`, `musicwave-cards`) | Blocks tab, category `music-wave` |
| What it inserts | A wide `core/group` with eyebrow/title/muted paragraph, styled through Global Styles | The `.mw-section-head` component shell with a seeded `__text` stack |
| Component vocabulary | none — plain core blocks | `mw-section-head` + `__text` (+ optional `__rule`), `mw-eyebrow` |
| Looks | inline typography, `mw-muted` | Styles panel: center / stack / invert |
| Choose it when | a one-off text heading inside content | a section needs the editorial header and a composable shell |

The pattern's HTML was **not** touched; only its docblock gained the cross-reference. Its block.json
counterpart asserts it still composes `core/group` + `core/heading` + `core/paragraph` and still
contains no `wp:music-wave/section-head` comment, so it cannot be silently converted or retired.

**Lane separation is now enforced, not just documented.** `editor-blocks.js` gained a second
registration loop fed by its own localized list (`musicwaveStaticBlocks`) instead of branching the
ServerSideRender loop on a block name. Both lanes build their client metadata through one extracted
helper (`musicwave_block_metadata_entry()` in PHP, `translatedMetadata()` in JS) rather than copying
it. The five PHP-rendered blocks and their five hidden legacy aliases were re-verified as unchanged
after that extraction: same titles, same keyword counts, `save()` still returns `null`, `edit()` still
uses ServerSideRender, aliases still hidden.

**The template-integrity pairing rule was amended structurally.** The old assertion required *every*
`music-wave/*` comment in bundled content to be self-closing, which a child-bearing block cannot
satisfy. It now classifies each block by how it is *registered*, with no per-block exception list:

* dynamic (has a `render_callback`) → must be self-closing, so stored fallback HTML still fails. Core
  is dynamic by construction: every Core block goes through `BlockSupport::register_dynamic()`, which
  always merges a callback. A theme dir is dynamic exactly when
  `musicwave_register_presentation_blocks()` maps it with a `'dir' =>` entry beside a callback.
* static (registered from block.json with no callback) → must be a correctly paired open/close
  container; self-closing it fails, because that would store no editable children.
* any name registered by neither → fails outright.

The strict nesting walker below it is untouched, so a paired container still cannot smuggle in an
unmatched delimiter. The classifier returns violation strings instead of asserting inline, which
makes it exercisable over fixtures. Stage E stores a paired `music-wave/section-head` in the vinyl
pattern (not in templates); six fixture cases still prove the rule accepts a paired container and a
self-closing leaf, and rejects a self-closed container, a dynamic block with fallback HTML, an
unclosed container and an unregistered name. A seventh contract asserts
`musicwave_static_block_dirs()` lists exactly the theme block.json files that have no callback, so a
new static block cannot ship unregistered and a stale entry cannot survive its metadata.

**Verification.** 14 negative controls were run against the new gates and all 14 failed with precise
messages before reverting clean: 8 on the integrity contracts (locked template, `render_callback`
added to block.json, SSR smuggled into the static lane, dropped `is-style-invert` twin, pattern
converted to the block, static dir moved into the callback lane, deleted `__rule` and `__text`
declaration blocks) and 6 on `tests/editor-lanes.js` (locked template, renamed `__text`, `save()`
storing nothing, `save()` returning `null`, diverging edit/save shells, removed `getBlockType()`
guard). Two of those controls initially *passed* — the first because a deleted CSS selector survived
inside a sibling rule, the second because an unanchored selector matched a descendant rule — and both
assertions were tightened (count both spellings per slug; anchor to a rule start) until the control
failed. Recording that, because an assertion that survives its own negative control is worse than no
assertion.

### 10.6 Stage E — header pairing without converting fat SSR (this stage)

Stage E does **not** add InnerBlocks to shelf, slider, related-releases, or public-playlists.
Those stay Lane 1 (fat PHP / ServerSideRender). What changed is how a canvas `section-head` can
sit *above* them without a second PHP heading, plus one pattern that actually stores the Stage D
block.

| Surface | Lane | Why |
| --- | --- | --- |
| `music-wave/section-head` | A — static InnerBlocks | Chrome is CSS + children; no query/access logic. |
| `musicwave/section-heading` | D — pattern of core blocks | Coexists; not converted. |
| `musicwave/vinyl-record-shelf` | D — pattern | Paired `section-head` + self-closing vinyl `release-shelf` with empty `eyebrow`/`title`. Not a new parent block. |
| Query Loop archive cards | C — Core composition | Stage A; unchanged. |
| `release-shelf` / `release-slider` | E — fat dynamic | Query + card chrome stay in PHP. Empty eyebrow+title omit the PHP heading (slider no longer fills «برای شما» / «انتشارهای منتخب» unless one field is already set). Inspector help points at `section-head`. |
| Playlist shelf source | E — fat dynamic | Same empty-copy omit; auto `/playlists/` URL only when copy is already going to print. |
| `related-releases` | E — fat dynamic | Empty both headings omit PHP titles (one-insert defaults fill only when at least one heading is already set). Skip empty `<h2>`; omit `__section-header` when heading and more are both empty. Inspector help points at `section-head`. Query + `ReleaseCard` stay in PHP. |
| `release-meta` compact | E — fat dynamic | Compact `<dl>` never renders library/actions/chips; inspector hides those three toggles while compact is on. |
| `download-button` compact | E — fat dynamic | Compact inline group never prints panel heading/description; inspector hides those four controls while compact is on. Signed-token download stays in PHP. |
| `request-form` | D+E | Form stays fat PHP. Pattern/templates compose chrome as core blocks on `.mw-request-form__*`. Nested card flattened in CSS. |
| access-panel, credits, collection-list, catalog-filters/results, public-playlists, continue-listening, playlist UI | E — fat dynamic | Query, permission, download or playlist logic. Headings already have `showHeading` (credits, collection-list, download panel). Unchanged this pass except compact download. |
| Stage C `release-card-media` | deferred | Stage A overlay already composes featured-image + group + preview-button; no overlay-break trigger. |

Shipped templates (`home.html`, `page-stream.html`, `single-mw_release.html`, `section-heading.php`) were **not** converted: `.mw-release-shelf__header` is not `.mw-section-head`, so emptying shelf attrs would drop a heading without adding a canvas one. `single-mw_release.html` still stores `sameArtistHeading` on related-releases, so that rail keeps its PHP title.

**Backward compatible.** Saved blocks keep their attributes. Empty both related headings now omit chrome instead of filling Persian defaults; any saved related-releases that already has one heading still fills the missing sibling. Compact download inspector hide is editor-only.

**Tests.** `tests/template-integrity.php` asserts vinyl pairing, empty-header gates (including related heading fill), compact skip (meta + download), and inspector help. `tests/editor-lanes.js` stays at 11 (no new lane). No live Site Editor run in this environment.

### Remaining-block classification (audit inventory)

Lane legend: ✅ upgraded this programme · 🟡 pairing/empty-header available · 🔵 Core composition already · ⏸️ intentionally fat (query/access/download/playlist) · 🔴 deferred (Stage C only on overlay-break).

| Block | Lane | Why this lane |
| --- | --- | --- |
| `section-head` | ✅ A | Static InnerBlocks; chrome is CSS + children. |
| `section-heading` pattern | ✅ D | Coexists; not converted. |
| `vinyl-record-shelf` pattern | ✅ D | Paired section-head + empty vinyl shelf. |
| Query Loop archive cards | ✅ C | Stage A; overlay is featured-image + group + preview-button. |
| `release-shelf` / `release-slider` | 🟡 E | Fat query + `ReleaseCard`. Empty eyebrow+title omit PHP header; vinyl pairs. Not converted in shipped templates. |
| `related-releases` | 🟡 E | Two query rails + shared card. Empty both headings omit PHP titles. Do not InnerBlocks the cards. |
| `release-meta` | ⏸️ E | Policy + taxonomy + library/playlist actions. Compact hides dead toggles. |
| `download-button` | ⏸️ E | Signed-token download. Compact hides dead heading chrome. `showHeading` already exists for the panel. |
| `collection-list` | ⏸️ E | Collection query + per-row preview/download. `showHeading` already exists. Track rows must stay PHP. |
| `release-credits` | ⏸️ E | Credits from release meta. `showHeading` already exists. |
| `access-panel` | ⏸️ E | Access policy + purchase/membership CTA. Copy is settings-backed, not canvas prose. |
| `catalog-filters` / `catalog-results` | ⏸️ E | GET form + live archive query. Labels already inspector-controlled. Style variations exist. |
| `request-form` | ✅ D+E | Form/POST/nonce/honeypot stay fat PHP. `request-cta` + the three request page templates compose heading/highlights/steps as core blocks on `.mw-request-form__*` (not `.mw-section-head`). Nested PHP section is flattened in CSS. Inspector hides chrome copy while the matching `show*` toggle is off. |
| `public-playlists` / `continue-listening` / `artists-shelf` / `taxonomy-shelf` | ⏸️ E | Query shelves. Same empty-header contract as theme shelf where they share `SectionHeader`. |
| `playlists` / `playback-queue` / `music-library` / add-to-* | ⏸️ E | Auth + mutation. Not canvas composition. |
| `preview-button` / `preview-player` / `share-button` / `shuffle-button` / `library-button` | ⏸️ E | Leaf actions; already small. |
| `term-hero` / `artist-profile` | ⏸️ E | Term/query identity. Archives already compose them as leaves. |
| header / nav / stream-rail | 🔵 C | Template parts + core navigation; not fat product blocks. |
| Stage C `release-card-media` | 🔴 | No overlay-break trigger. |

**Not claimed complete.** Request-form chrome is canvas-editable via Lane D composition; the form renderer is still fat PHP. Next highest remaining Site Editor limitation: do not convert remaining query/access shelves merely because they are large. Prefer the next surface whose chrome is inspector-only *and* already has a `showHeading` (or empty-header) gate with a matching CSS vocabulary — `collection-list` on `single-mw_release.html` is a candidate only if pairing can keep `.mw-collection-list__heading` rather than restyling onto `.mw-section-head`. Stage C remains deferred.
