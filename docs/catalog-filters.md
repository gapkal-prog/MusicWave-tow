# Catalog filters and sorting

The `music-wave/catalog-filters` block is used by the release archive template. It submits a standard GET request to the `mw_release` archive and uses the registered WordPress taxonomy query variables: `mw_artist`, `mw_genre`, `mw_mood`, and `mw_release_type`.

The optional `mw_sort` query argument is strictly allow-listed. Valid values are `latest`, `oldest`, `title_asc`, and `title_desc`; invalid or absent values safely fall back to `latest`. Sorting is applied only to the public primary `mw_release` archive, using native WordPress `date` or `title` ordering. It never exposes, sorts by, or queries private access metadata.

The companion `music-wave/catalog-results` block shows the archive result count and removable active-filter links. It preserves only known catalog query values when constructing a link, so tracking parameters or arbitrary query variables are not propagated.

The filter block reads at most 50 non-empty terms per taxonomy and does not run custom post queries. WordPress therefore owns taxonomy parsing, pagination, canonical URLs, and cache compatibility. Filters deliberately use term slugs rather than IDs or arbitrary meta values.

## Autocomplete and facet counts

Two public, cacheable REST routes back the discovery UI. Both read published catalog data only — never private metadata, entitlements, or user data.

| Route | Parameters | Behavior |
|---|---|---|
| `GET music-wave/v1/catalog/suggest` | `term`, `per_page` (max 10) | Terms shorter than 2 characters are refused with `mw_search_term_too_short`. Returns matching releases plus up to 4 term suggestions per taxonomy. |
| `GET music-wave/v1/catalog/facets` | `mw_artist`, `mw_genre`, `mw_mood`, `mw_release_type`, `per_taxonomy` (max 20) | Returns the sanitized filter set, per-taxonomy counts, the matched total, and an `approximate` flag. |

Guards:

- Filters accept term slugs only, at most 5 values per taxonomy, and unknown taxonomies are dropped.
- Facet counts come from a bounded scan (200 published releases). When the cap is reached the response sets `approximate: true` instead of running an unbounded query.
- Both routes cache their payload for 300 seconds (`music_wave_catalog_discovery_ttl`) and are rate limited per actor — user ID when signed in, otherwise a salted digest of the client address, never the raw address (`music_wave_discovery_rate_limit`, `music_wave_discovery_rate_window`, default 60 requests per minute). Exceeding the limit returns `mw_discovery_throttled` (429).
- Responses are `public, max-age=300`; no personalized data is included, so shared caches are safe.

## External search adapter boundary

If native WordPress search stops meeting the catalog's needs, implement `ManaCore\MusicWave\Core\Discovery\CatalogSearchAdapter` and return it from the `music_wave_catalog_search_adapter` filter. The adapter's `search( string $term, int $limit ): ?array` returns candidate release IDs, or `null` to fall back to native search.

The adapter only *proposes* candidates. Core still applies the shared release visibility policy and re-checks the public title, so a stale, poisoned, or misconfigured external index cannot surface unpublished or private releases. Suggestions can be curated further with the `music_wave_catalog_suggestions` filter, which must keep every entry public.
