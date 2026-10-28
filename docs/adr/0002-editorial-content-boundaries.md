# ADR 0002: Editorial content boundaries and relationships

- Status: Accepted for the next schema migration
- Date: 2026-08-06
- Milestones: M7 preparation, M8/M9 editorial UX

## Context

MusicWave needs albums, tracks, playlists, podcasts, artists, ordered child items, cover art, previews, and protected downloads. Creating a new post type for every noun would duplicate permissions, REST registration, metadata sanitization, templates, and access handling. Keeping every relationship in arbitrary post meta would make ordering, validation, and future migrations opaque.

## Decision

### 1. Keep one canonical audio post type

`mw_release` remains the canonical public audio entity. `mw_release_type` classifies it as `track`, `single`, `ep`, `album`, `mix`, `playlist`, `podcast_show`, or `podcast_episode`. Shared title, editorial body, artwork, duration, credits, access, and preview behavior therefore have one API and one policy boundary.

Albums and podcast shows are collection-shaped releases; tracks and podcast episodes are item-shaped releases. The renderer chooses fields from the type term instead of multiplying CPTs.

### 2. Use taxonomies for filtering, relationships for structure

`mw_artist`, `mw_genre`, `mw_mood`, `mw_label`, and `mw_release_type` stay taxonomies. Artist remains a taxonomy in the current product phase because it is primarily a filter and facet. Term meta can add biography, avatar, social links, and SEO fields without changing release identity. An Artist CPT is justified only when artist editorial permissions, revisions, or long-form content become a real workflow; that is a later migration, not a parallel MVP model.

Collections use a schema-validated ordered relation rather than a second CPT. The next schema migration will introduce a private `mw_collection_items` value on collection releases. Each item is an object with `release_id`, `position`, optional `disc`, and a constrained `role` (`track` or `episode`). The collection is the canonical owner of ordering; reverse lookups are derived and cached. This supports tracks appearing in more than one album or compilation without pretending there is a single parent.

### 3. Keep protected assets opaque

`mw_download_asset_id` remains a private opaque reference. It may identify a local attachment or a remote provider object, but it is never rendered as a URL, exposed in public REST, or stored in a theme. M7 will resolve it only after `AccessPolicyEngine` grants access and a signed, expiring delivery token is validated.

### 4. Use one editor with type-aware panels

The primary authoring surface remains the `mw_release` block editor. A schema-driven editor panel will show common fields for every release and conditional relationship panels for collection/item types:

- Album/playlist/show: searchable child-release selector with keyboard reordering, duplicate prevention, and validation that selected items are compatible.
- Track/episode: optional collection references, episode/track number, preview asset, and credits.
- All types: taxonomy terms, artwork, access policy, and publish validation.

The panel uses WordPress REST metadata and `@wordpress/data`; it does not bypass the post lifecycle or write directly to the database. The existing server-side metabox remains the PHP fallback during the migration.

## Alternatives rejected

- Separate `mw_album`, `mw_track`, and `mw_playlist` CPTs: duplicated editorial flows and ambiguous cross-type relationships before distinct permissions or lifecycle are proven.
- Artist CPT immediately: a premature migration from a taxonomy would make filtering and existing URLs harder without a demonstrated editorial need.
- A custom relationship table in the MVP: it adds installation, migration, and query complexity before catalog scale requires it. The relation value is isolated behind a repository so a table can replace it without changing the editor or renderer.
- Storing direct audio/download URLs: creates bypass, cache, and privacy risks and blocks provider-neutral delivery.

## Consequences

- One capability, REST controller, archive hierarchy, and access policy cover the catalog.
- Relationship validation and ordering become explicit migration-owned contracts.
- A future relationship table is an implementation swap behind the repository, not a content-model rewrite.
- The next work item is a schema migration plus relation repository; M7 secure download must wait for that contract and the existing M6 policy engine.
