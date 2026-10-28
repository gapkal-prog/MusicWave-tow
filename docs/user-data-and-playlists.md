# User data: library, wishlist, pre-saves, listening, playlists

Reference for the customer-owned datasets MusicWave Core stores, the rules that
guard them, and the REST surface that exposes them. See
`PROJECT_PLAN.md` Stage 5 for scope and acceptance criteria.

## Datasets

| Dataset | Storage | Bound | Privacy |
|---|---|---|---|
| Personal library (releases, artists, wishlist, pre-saves) | `mw_music_library` user meta | 500 items | Exported and erased by the WordPress privacy tools |
| Listening activity (progress, plays) | `{prefix}mw_user_activity` table | Retention window, default 180 days | Explicit opt-in; withdrawal erases immediately |
| Durable playback queue | `mw_playback_queue` user meta | 100 items | Erased with listening consent withdrawal |
| Playlists | `{prefix}mw_playlists` + `{prefix}mw_playlist_items` tables | 50 playlists, 500 items each | Owner-only by default; exported and erased by the privacy tools |

Schema `0.10.0` creates the activity table; schema `0.11.0` creates the two
playlist tables. Every repository degrades to an empty result until its
migration has run, so an interrupted upgrade never produces fatal errors.

## Library item types

| Type | Target | Accepted when |
|---|---|---|
| `release` | `mw_release` post | The actor may read the release |
| `artist` | `mw_artist` term | The term exists |
| `wishlist` | `mw_release` post | The actor may read the release |
| `presave` | `mw_release` post | The actor may read the release **and** `mw_release_date` is still in the future |

Account surfaces group `wishlist` under the **Wishlist** tab and `presave`
under **Coming soon**; plain `release` items keep grouping by release-type
slug.

### Pre-save lifecycle

1. A customer pre-saves an upcoming release. Core schedules a single
   `music_wave_presave_release` event for the stored release date.
2. When the date arrives — or the release is published earlier — Core converts
   the pre-save into a saved `release` item and fires
   `music_wave_presave_fulfilled` for each customer.
3. Fulfillment is idempotent, targeted at one release, and never releases
   early: if the release date moves forward, the promise stays pending and the
   event is rescheduled.

Pre-saves never expose unpublished catalog data. The release page must already
be readable; only its availability date lies ahead.

## Playlist privacy and sharing

| Visibility | Who can read | Share token |
|---|---|---|
| `private` (default) | Owner only | None; revoked immediately on downgrade |
| `unlisted` | Owner, plus anyone presenting the exact share token | Minted on share, rotated on every re-share |
| `public` | Anyone | Minted, but never returned to non-owners |

- Every mutation requires authentication **and** ownership.
- Non-owner views only ever list published releases, so drafts, scheduled, and
  private releases cannot leak through a shared link.
- Share tokens are capabilities: only the owner receives one in a response.
- Revoking sharing rotates the token, so an old link cannot be resurrected.
- Denied reads return the same `mw_playlist_not_found` error as a missing
  playlist, so playlist IDs cannot be enumerated.

## REST surface (`music-wave/v1`)

| Route | Method | Auth | Notes |
|---|---|---|---|
| `/library` | GET | Required | Summaries, counts, active filter |
| `/library/items` | POST, DELETE | Required | `type` (release/artist/wishlist/presave) + `id` |
| `/listening/consent` | POST | Required | `consent` opt-in/opt-out; opting out erases history and queue |
| `/listening/progress` | POST | Required | Requires consent; unreadable releases are refused |
| `/listening/continue` | GET | Required | Continue-listening items |
| `/listening/queue` | GET, POST | Required | Durable queue with shuffle/repeat |
| `/recommendations` | GET | Public | Editorial for anonymous callers; personalized responses are `no-store` |
| `/playlists` | GET, POST | Required | Own playlists; create with `title`, `visibility` |
| `/playlists/public` | GET | Public | Paginated public playlists (`page`, `per_page`, `orderby`); each item carries `covers` — up to four `{title, image}` pairs from published releases (thumbnail URL or empty when the release has no cover) for the theme's fanned cover stack |
| `/playlists/<id>` | GET | Public | Filtered by playlist privacy; accepts `share` token |
| `/playlists/<id>` | POST, DELETE | Required | Owner-only rename/visibility/delete |
| `/playlists/<id>/items` | POST, DELETE | Required | Owner-only add/remove by `release_id` |
| `/playlists/<id>/order` | POST | Required | Owner-only `release_ids` order; unknown IDs ignored, omitted items kept |

## Hooks

| Hook | Type | Contract |
|---|---|---|
| `music_wave_library_item_added` / `music_wave_library_item_removed` | action | `$type, $item_id, $user_id` after a library mutation. |
| `music_wave_presave_fulfilled` | action | `$release_id, $user_id` when a pre-save becomes available. Notification integrations attach here. |
| `music_wave_listening_retention_days` | filter | Listening-history retention window in days (default 180). |
| `music_wave_recommendations` | filter | Adjust recommendation items; every item must keep a machine `reason` and a translated `explanation`. |
| `music_wave_library_items` | filter | Adjust normalized library items for display paths. |

## Interface surfaces

| Surface | Where | Behavior without JavaScript |
|---|---|---|
| `music-wave/playlists` block | Account dashboard panel **Playlists**, or placed anywhere | Fully functional: create, rename, change visibility, delete, reorder (move up/down), and remove tracks are plain form posts to `admin-post.php` |
| `music-wave/add-to-playlist` block | Single release template | A labeled select plus submit; with no playlists yet it becomes a "create playlist" form |
| `music-wave/library-button` block (`itemType`) | Release pages | Server-rendered toggle for `release`, `wishlist`, or `presave`; signed-out visitors get a sign-in link |
| Catalog autocomplete | Catalog filter search field | A plain search input that submits the normal archive GET request |

Accessibility and privacy notes:

- Playlist mutations use post/redirect/get. The redirect carries a notice code
  that renders inside a `role="status"` live region, so screen readers hear the
  result without a JavaScript round trip.
- Reordering uses per-item **Move up** / **Move down** submit buttons with
  screen-reader names that include the track title, instead of drag-and-drop.
- The track list toggle exposes `aria-expanded`/`aria-controls`, and the expanded
  playlist is addressable through the `mw-playlist` query argument.
- Every form carries a nonce; ownership is re-checked in the repository, so a
  replayed or forged post cannot touch another listener's playlist.
- The share link input is rendered only for the owner of a shared playlist.
- Autocomplete upgrades the search field to an ARIA 1.2 combobox
  (`role="combobox"`, `aria-expanded`, `aria-activedescendant`, arrow-key and
  Escape handling) and announces result counts politely.
- Playlists appear inside the single account dashboard shell as a panel rather
  than as a second account surface.
