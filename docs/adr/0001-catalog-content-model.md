# ADR 0001: Catalog content model

- Status: Accepted
- Date: 2026-08-06
- Milestone: M2

## Decision

MusicWave uses `mw_release` as the canonical music entity. A WooCommerce `product` is a commercial offer and is linked to one or more releases; it is never the catalog entity itself.

Artists, genres, moods, labels, and release types are taxonomies. This keeps filtering native to WordPress and avoids expensive meta queries. Artist is intentionally a non-hierarchical taxonomy in the MVP. Rich artist editorial pages may be introduced later without changing release identity.

Release metadata is registered from one schema dictionary. Public descriptive fields may be exposed through REST. Commerce, membership, external-provider, and protected-download identifiers remain private.

## Consequences

- The catalog remains available when WooCommerce is inactive.
- A release can be sold through multiple products, variations, bundles, or membership offers.
- Product deletion can remove a mapping without deleting the release.
- All metadata writes pass through the repository or the schema-driven admin handler.
- A future schema change requires a versioned migration.

## Rejected alternatives

- Using `product` as the music entity couples editorial content to one commerce plugin.
- Separate CPTs for track, album, and playlist create duplicated admin flows before their distinct behavior is proven.
- Storing genres and artists as post meta makes filtering and archive URLs harder to scale.
