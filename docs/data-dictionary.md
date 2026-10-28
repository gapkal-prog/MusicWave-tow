# MusicWave data dictionary

Schema version: `0.11.0`

## Post type

| Key | Purpose | REST | Public |
|---|---|---:|---:|
| `mw_release` | Canonical track, single, EP, album, mix, playlist, or podcast release | Yes | Yes |

### Permalinks (Core 0.12.0)

Single release URLs follow the primary `mw_release_type` term (ancestors are
consulted for child terms). The post type's own `/music/%mw_release%`
permastruct stays registered as the fallback and as the archive base
(`/music/`); every mapped base registers its own permastruct, so pagination,
comment pages, feeds, embeds and rewrite endpoints work under all of them.
Stale bases (for example an old `/music/<slug>/` link, or an album whose type
changed) are redirected with a permanent redirect to the canonical URL by
`ReleasePermalinks`, preserving sub-routes and query strings; feeds, embeds,
previews and non-GET requests are left alone.

| Release type | URL base |
|---|---|
| `album` | `/album/<slug>/` |
| `ep` | `/ep/<slug>/` |
| `mix` | `/mix/<slug>/` |
| `playlist` | `/playlist/<slug>/` |
| `single`, `track` | `/track/<slug>/` |
| `podcast_show` | `/podcast/<slug>/` |
| `podcast_episode` | `/episode/<slug>/` |
| no mapped type | `/music/<slug>/` |

When a release carries several mapped types the first match in the order
above wins (collections beat their tracks). Sites can adjust the map through
the `music_wave_release_permalink_bases` filter; bases are sanitized to
lowercase slugs and invalid entries are dropped. `ReleasePermalinks` stores a
fingerprint of the active map in the `music_wave_release_permalink_rules`
option and soft-flushes the rewrite rules on `wp_loaded` whenever the
fingerprint changes, so existing installs (and sites that change the map
through the filter) pick the new structures up on the next request without a
manual permalink re-save. No database schema change is involved.

## Taxonomies

| Key | Hierarchical | REST | Purpose |
|---|---:|---:|---|
| `mw_artist` | No | Yes | Primary and featured artists |
| `mw_genre` | Yes | Yes | Genre families and subgenres |
| `mw_mood` | No | Yes | Discovery by mood |
| `mw_label` | No | Yes | Record label or publisher |
| `mw_release_type` | Yes | Yes | Track, single, EP, album, mix, playlist, podcast show, or podcast episode classification |

## Artist term metadata

| Key | Type | REST | Admin | Notes |
|---|---|---:|---:|---|
| `mw_artist_biography` | string | Yes | Yes | Sanitized editorial biography for the public artist archive |
| `mw_artist_image_id` | integer | Yes | Yes | WordPress media attachment ID; image attachments only |
| `mw_artist_canonical_url` | string | Yes | Yes | Optional HTTPS official artist or label URL |
| `_mw_metadata_aliases` | array | No | Yes | Provider-facing and previous names used to match a translated or renamed Artist, Genre, or Label |
| `_mw_metadata_{provider}_id` | string | No | No | Stable provider identity used before visible-name matching |

## Release metadata

| Key | Type | REST | Admin | Notes |
|---|---|---:|---:|---|
| `mw_catalog_number` | string | Yes | Yes | Human-facing catalog identifier |
| `mw_album` | string | Yes | Yes | Album or collection title returned by metadata lookup |
| `mw_release_year` | integer | Yes | Yes | Four-digit year when a provider does not supply a complete date |
| `mw_release_date` | string | Yes | Yes | ISO `YYYY-MM-DD` date |
| `mw_isrc` | string | Yes | Yes | Canonical 12-character International Standard Recording Code |
| `mw_duration` | integer | Yes | Yes | Total duration in seconds |
| `mw_preview_duration` | integer | Yes | Yes | Preview cutoff in seconds; defaults to 30 and accepts 10–120 |
| `mw_bpm` | integer | Yes | Yes | 0 or 20–300 |
| `mw_musical_key` | string | Yes | Yes | Short musical key label |
| `mw_explicit` | boolean | Yes | Yes | Explicit-content marker |
| `mw_preview_url` | string | Yes | Yes | HTTPS preview URL; never a protected master file |
| `mw_track_number` | integer | Yes | Yes | Track/single ordinal; `0` when not applicable |
| `mw_episode_number` | integer | Yes | Yes | Podcast episode ordinal; `0` when not applicable |
| `mw_season_number` | integer | Yes | Yes | Podcast season ordinal; `0` when not applicable |
| `mw_credits` | array | Yes | No | Structured credit objects; editor UI follows after MVP authoring baseline |
| `mw_collection_items` | array | Yes (ordered IDs) | No | Validated child releases with `release_id`, `position`, optional `disc`, and `role` (`track` or `episode`) |
| `mw_access_mode` | string | Yes | Yes | `public`, `purchase`, `membership`, `purchase_or_membership`, or fail-safe `restricted` |
| `mw_product_ids` | array | No | Yes | Canonical WooCommerce product mapping |
| `mw_membership_levels` | array | No | Yes | Provider-neutral membership identifiers |
| `mw_external_id` | string | No | No | Provider-side resource identifier |
| `mw_metadata_source` | string | No | No | Private HTTPS source URL used for the most recent metadata application |
| `mw_metadata_provider` | string | No | No | Private normalized provider key used for the most recent metadata application |
| `mw_download_asset_id` | string | No | No | Legacy single protected asset reference; automatically represented as a `standard` quality when edited |
| `mw_download_assets` | array | No | Yes | Ordered quality variants with a key, customer-facing label, opaque provider asset identifier, and optional private file metadata |

## Product metadata

| Key | Type | REST | Purpose |
|---|---|---:|---|
| `_mw_release_ids` | array | No | Reverse index maintained by the product mapper |

## Operational tables

High-cardinality per-user data lives in indexed tables rather than serialized
meta. See `docs/user-data-and-playlists.md` for the rules and REST surface.

| Table | Schema | Purpose | Key indexes |
|---|---|---|---|
| `{prefix}mw_download_replays` | 0.9.0 | One-time delivery token replay records | unique `token_hash`, `expires_at` |
| `{prefix}mw_user_activity` | 0.10.0 | Consented listening progress and plays | unique `user+release+event`, `user+updated_at` |
| `{prefix}mw_playlists` | 0.11.0 | User playlists (title, visibility, share token) | unique `share_token`, `user+updated_at` |
| `{prefix}mw_playlist_items` | 0.11.0 | Ordered playlist entries | unique `playlist+release`, `playlist+position`, `release_id` |

## Song requests (Core 0.13.0)

Custom-song requests and collaboration proposals are a **private post type**
`mw_request` (no public query, REST, rewrite, search, or standard edit UI).
The lifecycle is the post status; everything else is post meta. No table is
added, so the schema version stays `0.11.0`.

| Status | Meaning |
|---|---|
| `mw_req_new` | Just submitted; counted in the menu badge |
| `mw_req_review` | A manager is working on it |
| `mw_req_replied` | A reply was emailed (set automatically by the first reply) |
| `mw_req_accepted` | Collaboration accepted |
| `mw_req_declined` | Declined |
| `mw_req_archived` | Kept out of the inbox without deleting |

| Key | Type | Purpose |
|---|---|---|
| `post_title` | string | Request subject |
| `mw_request_name` / `mw_request_email` / `mw_request_phone` | string | Requester contact (email lower-cased; phone digits folded to ASCII) |
| `mw_request_role` | key | `singer`, `producer`, `band`, `advertiser`, `business`, `fan`, `other` |
| `mw_request_type` | key | `song`, `collab`, `advertising`, `event`, `other` |
| `mw_request_message` | text | Free-text brief (20–4000 characters) |
| `mw_request_budget` | key | `''`, `starter`, `standard`, `premium`, `enterprise` |
| `mw_request_deadline` | `Y-m-d` | Requested date, never in the past at submission |
| `mw_request_links` | array | ≤5 validated http(s) URLs |
| `mw_request_priority` | key | `normal` or `high` (manager flag) |
| `mw_request_source` | key | `form` (public) or `manual` (entered by a manager) |
| `mw_request_user_id` | int | Logged-in submitter, `0` for guests |
| `mw_request_reference` | string | Human reference `MW-YYYY-000123` |
| `mw_request_log` | array | ≤200 entries `{kind: reply|note|event, body, actor, time, sent?}` |
| `mw_request_token` | string | Unguessable per-request secret for requester-facing links |
| `mw_request_consent_at` | int | Unix time the requester consented to storage |

Option `music_wave_requests` (autoloaded, Settings API group `music_wave_requests`):
`form_enabled`, `send_receipts` (`enabled|disabled`), `recipients` (validated, ≤10),
`reply_to`, `receipt_intro`, `signature`, `success_notice` (≤1000 chars each),
`rate_limit` (1–100, default 3), `rate_window` (60–86400 s, default 3600),
`enabled_types` (subset of the type keys). Transients `mw_request_form_<token>` hold
validation errors and values for one redirect round-trip (10 minutes); rate buckets use
the discovery limiter under `requests`. The privacy exporter/eraser covers every request
that matches the requester email.

## Invariants

- Product IDs must refer to existing `product` posts.
- Release IDs must refer to existing `mw_release` posts.
- `purchase` access requires at least one product mapping.
- `membership` access requires at least one membership level.
- Incomplete paid access configuration is normalized to `restricted`, never to public.
- Artist, genre, mood, and label terms may store private `_mw_metadata_aliases` plus provider-specific IDs. Auto-fill resolves these stable identities before visible names, so translated or renamed terms are reused instead of duplicated.
- Protected asset and external provider identifiers are never public REST fields.
- A protected asset identifier is opaque. Core does not infer a public URL from it.
- A release may contain any number of unique download qualities. Each quality has a unique technical key; quality keys and provider IDs are never exposed in public HTML or REST.
- A quality may store private editor metadata: `file_name`, `format`, `bitrate` (kbps), `duration` (seconds), and `file_size` (bytes). These values are never emitted in public HTML or REST.
- Albums and podcast shows contain tracks or episodes by relation. Add quality variants to each child release once; collections reuse those children without duplicating files.
- Deleting a product removes it from every release mapping without deleting catalog content.
- Deleting a release removes it from every personal library, wishlist, pre-save, and playlist without leaving orphaned rows or gaps in playlist positions.
- Playlist share tokens are capabilities: minted per share, rotated on re-share, never returned to a non-owner, and never resolvable for a private playlist.
- Non-owner playlist views contain published releases only.
- Requests are never public: `mw_request` posts are excluded from search, REST, feeds and the front end; the only readers are the manager screen (capability `manage_mw_requests`) and the privacy tools.

## Relationship invariants

- Only collection-shaped release types (`album`, `ep`, `mix`, `playlist`, and `podcast_show`) may own children.
- Track roles accept track/single releases; episode roles accept `podcast_episode` releases and only belong to podcast shows.
- A collection cannot contain itself, a duplicate child, or duplicate positions. Reverse lookups are maintained in private `_mw_collection_ids` metadata.
- REST reads expose the ordered relation for public catalog rendering; writes require `edit_post` on the collection and every referenced release.
