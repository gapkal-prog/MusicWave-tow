# Release authoring workflow

1. Create a release under MusicWave → Add new release.
2. Add cover artwork, description, excerpt, and the appropriate artist, genre, mood, label, and release type terms.
3. Complete release and media metadata in the **MusicWave release details** meta box. Track/season/episode numbers, duration, preview duration, access, downloadable files, and each file’s private qualities stay together there.
4. Select an access mode.
5. For purchase access, map at least one existing WooCommerce product.
6. For membership access, enter provider-neutral membership keys separated by commas.
7. Publish or update the release.

Create a downloadable-file group for every song, episode, or bundle. For each group, add an opaque provider ID, upload a file into the protected directory, choose a Media Library audio file, or browse a file already stored there. Use the target-file selector to add another quality instead of creating a duplicate song. MusicWave detects duration, format, bitrate, and size from supported audio uploads when WordPress can read the file metadata. Review the detected values before publishing; direct public file URLs are intentionally rejected.

Albums, playlists, mixes, and podcast shows also show an in-place collection picker in this meta box. It searches compatible child releases only: Track/Single for music collections and Podcast Episode for a podcast show. Search matches both the release title and protected filenames already attached to that release. Each child keeps its own preview and secure download options when shown on the collection page.

The save handler verifies a nonce and the edit-post capability, sanitizes every field through the canonical schema, and normalizes incomplete access rules:

- Purchase without a product becomes restricted.
- Membership without a level becomes restricted.
- Purchase-or-membership with only one configured side becomes that specific mode.
- Purchase-or-membership with neither side becomes restricted.

Product mappings are edited on the release. The WooCommerce product screen shows a read-only reverse list so store managers can verify relationships without creating conflicting sources of truth.

## Product search

When WooCommerce is active, the product selector uses WooCommerce's authenticated AJAX search rather than loading the entire product catalog. Existing mappings remain intact if WooCommerce is temporarily inactive.

## Ownership behavior

## Artist profiles

The `Artists` taxonomy screen contains MusicWave fields for a sanitized public biography, a WordPress media-library image, and an optional HTTPS canonical URL. These values are term metadata rather than a second Artist post type, so filtering and existing artist archive URLs remain native to WordPress.

## Release readiness

Every release editor includes a non-blocking `Release readiness` checklist. It flags missing release type, cover artwork, release date, duration, collection items, paid-access mapping, membership levels, and protected assets where applicable. The Releases list also shows a compact readiness count plus the saved access mode.

The **Auto-fill metadata & cover art** panel adapts provider searches to the selected release type (track, single, EP, album, mix, playlist, podcast show, or podcast episode). It enriches only the selected result, fills empty release content/excerpt fields with an official provider annotation/description when available, and connects Artists, Genres, Moods, and Labels.

Confirmed provider genres and moods are created when missing. Unclassified community tags are never created automatically; they can only select an existing Mood whose name or alias matches. Taxonomy matching prioritizes provider IDs, saved aliases, and stable slugs before visible names. If a term is translated or renamed, its previous identity is retained automatically. For terms translated before this feature was installed, add the provider-facing name under **Metadata aliases** on the term edit screen to prevent duplicate terms.

The checklist is intentionally advisory: merchants can save drafts and complete assets or commerce configuration later. Before publishing a paid collection, all listed issues should be resolved and the customer download flow tested from a non-administrator account.

## Control center and global defaults

Administrators can use **MusicWave > Settings & overview** as the central control surface for the project. The page shows catalog and integration status, links to the native WordPress screens that own templates, widgets, navigation, media, users, taxonomies, and permalinks, and exposes settings that have real runtime behavior:

- Default access mode and preview duration persisted when a new release is created; changing the defaults never rewrites existing releases.
- Public archive page size and default sorting.
- Automatic, forced, or disabled MusicWave JSON-LD output.
- Escaped purchase, membership, restricted, and access-granted messages plus an optional HTTPS membership CTA.
- Integration cards and embedded settings registered by active helper plugins.

The **Access** tab can change matching releases in capability- and nonce-protected batches of 200. Filters are available for current access mode, post status, and release type. Purchase and membership modes remain deny-by-default: a release without the required product or membership mapping is skipped, while `purchase_or_membership` is safely normalized to the available configured method. The job is resumable for large catalogs and expires automatically if abandoned.

MusicWave delegates purchase history to the WooCommerce customer-bought-product API. WooCommerce controls which paid order states count as ownership. Anonymous visitors are never treated as owners. Guest checkout to later-account reconciliation is not part of this phase and requires an explicit privacy-reviewed workflow.

## Site Editor music sections

The **MusicWave release shelf** block powers the album rails used across the
music home, browse, library, and product templates. In its block settings an
administrator can control the heading, query order, taxonomy filter, amount,
desktop columns, grid/scroll/list layout, artwork shape, metadata visibility,
action label, and optional “See all” link.

For a handpicked rail, use the **Curated release shelf** pattern, then enter
published MusicWave release IDs as a comma-separated list in **Curated release
IDs**. This takes priority over the automatic query and preserves the exact
order entered. Use the **Artist release shelf** pattern when a rail should
follow one `mw_artist` term; set that term’s slug in the same inspector panel.

## Release detail blocks

The single-release template is assembled from five MusicWave blocks. Every
block exposes grouped inspector panels so each section can be customized per
template without touching code.

**Related releases** reuses the release shelf layout system. The sidebar
offers section visibility (default / always show / hide for the same-artist
and similar sections), heading overrides, an optional section link, query
controls (items per section, order by, order, and which genre / mood / type
signals power similarity), and the same presentation controls as the release
shelf: responsive grid, horizontal scroll, or compact list layout, grid
columns, artwork shape, and per-card metadata toggles (artwork, artist, date,
excerpt, preview button, action link).

**Release access panel** keeps the deny-by-default engine but lets a template
override every message and call to action (granted, restricted, purchase, and
membership), choose a banner or stacked layout, and hide the granted state
entirely. Empty overrides fall back to the global MusicWave messages.

**Release credits** supports list, grid, and inline layouts, optional role
grouping, and independent heading and role visibility.

**Collection track list** can show track numbers, artwork, per-track
duration, and a total running time, group multi-disc collections by disc, and
toggle the per-track preview and secure download actions.

**Secure download** resolves files only through signed tokens and never
exposes private asset URLs. Administrators can override the heading,
description, download, play, and sign-in labels, and can hide the quality
selector, the secure play buttons, or the panel header.

## Personal music library

Signed-in customers can save songs, albums, podcasts, and follow artists into
a personal library. The **Add to library** button is rendered inside the
**Release metadata** block on single release pages (toggle it with the *Show
add-to-library button* inspector control) and as a **Follow artist** button
inside the **Artist profile** block. Saved items are stored per user and are
removed automatically when the underlying release or artist is deleted.

The **Personal music library** block displays a visitor's saved collection
with filter tabs grouped by release type and followed artists. Its inspector
panels expose the heading, intro text, empty-state message, filter and count
visibility, list or grid layout with columns and item limits, and per-item
toggles for artist names, type badges, release years, and remove buttons. The
standalone **Add to library button** block can target the current release
(contextual, for templates and Query Loops), a fixed release, or a fixed
artist through the artist term ID, with solid, outline, or ghost styles and
custom labels.

Library changes are persisted through the authenticated
`music-wave/v1/library` REST routes; guests always receive a sign-in call to
action instead of a broken toggle. Sites can disable the feature with the
`music_wave_library_enabled` filter.

## Unified account hub

The Music Library, Music user dashboard, and My Account pages are merged
into a single bundled template: **Music account** (`page-account`). The
retired `page-library`, `page-dashboard`, and `page-my-account` templates
(and common page slugs such as `music-library` or `music-user-dashboard`)
resolve to it automatically, so administrators customize one layout in the
Site Editor and every account page updates together. Assign the **Music
account** template to any page from the page editor template picker.

The template renders the **Account hub** pattern, which combines the page
heading, the **User music dashboard** block, the **Personal music library**
block, the page content, the WooCommerce account area, and a discovery
shelf.

The dashboard block works as a tabbed hub: the quick links expand their
section in place below the header. **Music library** reveals the personal
library and the entitled secure downloads, **Orders** shows the WooCommerce
order history, **Account details** shows the profile card with an edit
shortcut, and **Membership** shows the VIP levels. The Orders and Account
details tabs render only while WooCommerce is active, and the Membership tab
only while the MusicWave VIP plugin is active. Panel links support
`#mw-library` style deep linking.
