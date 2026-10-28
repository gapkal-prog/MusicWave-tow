# Performance benchmarks and query budgets

MusicWave's list surfaces grow with catalog and account size, so they are
budgeted in queries first and wall time second: query counts are where these
surfaces actually regress, while wall time on CI runners is noisy.
See `PROJECT_PLAN.md` Stage 5 deliverable 7.

## Running the benchmark

The harness runs inside a real WordPress install through WP-CLI. `tools/` is
mapped into wp-env at `wp-content/mw-tools`.

```bash
npx @wordpress/env start
npx @wordpress/env run cli wp musicwave migrate
npx @wordpress/env run cli wp eval-file wp-content/mw-tools/benchmark-catalog.php seed=500 cleanup=1
```

- `seed=<n>` creates `n` throwaway published releases with artist, genre, mood,
  and release-type terms, a 500-item library, and a 500-item playlist.
- `cleanup=1` deletes the seeded releases and the benchmark user afterwards.
- The script exits non-zero when any scenario exceeds its budget, so CI gates on
  it (`Quality → WordPress fixture smoke`).

## Budgets

| Scenario | Max queries | Max ms | What it covers |
|---|---:|---:|---|
| `catalog_archive` | 40 | 1500 | Paged `mw_release` archive with found-rows |
| `catalog_facets` | 25 | 2500 | Cold facet scan (bounded to 200 releases) |
| `catalog_facets_hot` | 2 | 200 | Cached facet payload |
| `catalog_suggest` | 20 | 1500 | Autocomplete for a common term |
| `library_page` | 40 | 1500 | Page 1 of a 500-item library |
| `playlist_view` | 40 | 1500 | Owner view of a 500-item playlist |
| `recommendations` | 40 | 1500 | Explainable recommendations |

## Why the budgets hold

- **Batched taxonomy resolution.** `ReleaseTermIndex` resolves a whole release
  set in one `wp_get_object_terms()` call and memoizes it for the request.
  Library summaries, library counts, facet counts, related-release ranking, and
  recommendation genre signals all use it, replacing one query per release per
  taxonomy. Regression tests assert the batched call count directly.
- **Batched release visibility.** `ReleaseVisibility::prime()` warms the post
  cache (and post meta for summary rendering) for a whole candidate set in one
  query, so library pages, playlist views, and facet scans evaluate their
  releases from cache instead of one `get_post_type()`/`get_post_status()` read
  per row. Callers that know their candidate IDs call it before the loop.
- **Bounded scans.** Facets scan at most 200 published releases and report
  `approximate: true` instead of widening the query. Autocomplete asks for at
  most `limit * 3` candidates (hard cap 50) and 4 term hits per taxonomy.
- **Indexed operational tables.** Listening activity, playlists, and playlist
  items live in indexed tables with the composite keys listed in
  `docs/data-dictionary.md`; playlist reads are keyed by `playlist_id` and
  ordered by `position`.
- **Caching.** Autocomplete and facet payloads cache for 300s
  (`music_wave_catalog_discovery_ttl`). Personalized responses are never cached
  in a shared cache (`no-store, private`).
- **Bounded user data.** Libraries cap at 500 items, playlists at 50 per user
  and 500 items each, the durable queue at 100 items, and listening history is
  pruned to the retention window.

## When to reach for an external search index

If `catalog_suggest` or `catalog_archive` misses its budget on a real catalog,
implement `CatalogSearchAdapter` and return it from
`music_wave_catalog_search_adapter` (see `docs/catalog-filters.md`). The adapter
proposes candidate IDs only; Core keeps enforcing release visibility, so a stale
index cannot leak unpublished releases.

## Recording results

Paste the harness table into the Stage 5 evidence block in `PROJECT_PLAN.md`
together with the WordPress/PHP versions and the dataset size. A budget change
requires a note explaining what moved and why.

### Recorded run — 2026-09-10 (CI, WordPress 6.8 / PHP 8.2, `seed=500`)

| Scenario | Queries | Budget | ms | Budget | Detail |
|---|---:|---:|---:|---:|---|
| `catalog_archive` | 5 | 40 | 3.3 | 1500 | 24 of 500 releases |
| `catalog_facets` | 9 | 25 | 10.1 | 2500 | 200 matched, approximate=yes |
| `catalog_facets_hot` | 0 | 2 | 0.0 | 200 | 200 matched (cached) |
| `catalog_suggest` | 18 | 20 | 7.0 | 1500 | 10 suggestions |
| `library_page` | 29 | 40 | 24.9 | 1500 | 24 items on page 1 |
| `playlist_view` | 5 | 40 | 4.0 | 1500 | 500 playlist items |
| `recommendations` | 1 | 40 | 0.5 | 1500 | 12 recommendations |

This run is the first fully green fixture job: before the visibility batching,
`playlist_view` measured 280/40, `library_page` 77/40, and `catalog_facets`
208/25 — one post read per candidate row on each of the three surfaces.

## Lighthouse budgets (FIX-36)

Frontend performance measured via `tools/run-audits.sh` which runs Lighthouse on:

- `/` (front)
- `/releases/` (catalog archive)
- `/playlists/` (public playlists)
- `/my-account/` (account hub - Phase 4)
- `/request-song/` (request form)

**Budgets (controlled demo, no third-party scripts):**

| Category | Minimum |
|----------|---------|
| Performance | >=90 |
| Accessibility | >=95 |
| Best Practices | >=95 |
| SEO | >=95 |

**Optimizations for budget:**

- Conditional CSS loading: only enqueue `catalog`, `shelf`, `artist`, `release-page`, etc when `has_block` or archive context matches (FIX-35 `musicwave_enqueue_conditional_styles`).
- JS deferred: `slider`, `hero-slider`, `release-hero`, `lyrics`, `header-search`, `rail` use `defer` via `script_loader_tag` filter.
- Critical CSS: tokens, hues, base, layout, navigation, rail, utilities, accessibility, block-styles, theme-toggle, header-search enqueued always.
- Images: lazy via `loading="lazy"` + shelf JS syncs to `eager` for first viewport.
- Fonts: self-hosted woff2, no Google (FIX-04), `font-display: swap`.

**axe:** 0 violations required on same pages.

**Reports:** `docs/audits/lighthouse-*.json` and `axe-*.json` generated by `tools/run-audits.sh --url=http://localhost:8888`.

**CI:** add job that starts wp-env and runs audits; fails if budgets not met.

*Updated for FIX-36*
