# Using MusicWave blocks with Elementor (page-builder bridge)

MusicWave is a **block theme** (`musicwave`) with server-rendered dynamic blocks
(`music-wave/*`). Elementor is a separate document model. This page explains the
supported bridge, what it can and cannot do, and why a native Elementor widget
set is not part of Core.

## Short answer

| Goal | Supported? | How |
|------|------------|-----|
| Place a MusicWave block inside an Elementor page/section | ✅ Yes | Shortcode widget + `[musicwave_block]` |
| Configure block settings from an Elementor panel | ❌ No | Pass the block's `block.json` attributes as JSON |
| Edit the FSE templates (`templates/single-mw_release.html`, `archive.html`, …) visually in Elementor | ❌ No | Use the WordPress Site Editor for templates |
| Keep theme styles, player, and block scripts on an Elementor page | ✅ Yes | The active MusicWave theme enqueues them |

## The `[musicwave_block]` shortcode

Registered by MusicWave Core on every request. It renders any registered
`music-wave/*` block through WordPress's own `render_block()`, so the output is
identical to the block editor and Site Editor.

```
[musicwave_block name="music-wave/release-shelf" attributes='{"layout":"grid","itemsToShow":8,"columns":4}']
```

In Elementor:

1. Add a **Shortcode** widget (Elementor → Widgets → General → Shortcode).
2. Paste the shortcode, adjusting the JSON for the block you want.
3. Publish. The Elementor editor preview renders the shortcode server-side.

### Parameters

| Attribute | Required | Description |
|-----------|----------|-------------|
| `name` | Yes | Full block name, e.g. `music-wave/related-releases`. Only the `music-wave/` namespace is accepted. |
| `attributes` (alias `attrs`) | No | JSON object whose keys are the block's `block.json` attributes. Unknown keys are dropped before rendering. |

The shortcode refuses:

- any block outside `music-wave/*` (so it cannot be used to render core or third-party blocks),
- malformed JSON (an empty string is returned instead of partially-applied settings),
- keys not declared by the block's `block.json`.

### Common block names

| Block | Name | Useful attributes |
|-------|------|-------------------|
| Release shelf | `music-wave/release-shelf` | `layout`, `itemsToShow`, `showAll`, `columns`, `source` |
| Release slider | `music-wave/release-slider` | `layout`, `itemsToShow`, `showAll`, `columns`, `imageSize` |
| Related releases | `music-wave/related-releases` | `layout`, `sameArtistHeading`, `sameArtistIcon`, `similarIcon` |
| Artists shelf | `music-wave/artists-shelf` | `layout`, `itemsToShow`, `showAll`, `columns`, `showBio`, `pagination` |
| Taxonomy shelf | `music-wave/taxonomy-shelf` | `taxonomy`, `layout`, `itemsToShow`, `columns` |
| Tracklist | `music-wave/collection-list` | `showArtwork`, `showTotalDuration` |
| Preview player | `music-wave/preview-player` | `showIcon`, `variant`, `size` |
| Library button | `music-wave/library-button` | `buttonStyle`, `showCount` |
| Add to playlist | `music-wave/add-to-playlist` | `buttonStyle` |
| Download button | `music-wave/download-button` | `showHeading`, `showDescription`, `showQuality`, `showStream`, `compact` |

Attributes are identical to the ones in each `blocks/*/block.json`; the block
editor inspector writes the same keys. Values must be JSON types (strings in
double quotes, numbers/booleans bare).

### Context-dependent blocks

Some blocks read the current release or term that WordPress is rendering
(`collection-list`, `release-credits`, `related-releases`, `release-meta`, the
lyrics block). On a standalone Elementor page there is no current release, so
they render their empty/editorial state or nothing. Use them on a release
template (Site Editor), or pass the IDs/list attributes if the block supports
them.

## Why the FSE templates are not editable in Elementor

Elementor Pro's Theme Builder edits **classic** template files (`single.php`,
`archive.php`, …) and stores its own document tree. MusicWave ships WordPress
block templates (`templates/*.html`), which belong to the Site Editor's document
model. Elementor does not read or write those files, so it cannot present the
release template as an editable document. This is a boundary of the two
systems, not a MusicWave bug. Editing `templates/single-mw_release.html` remains
a Site Editor workflow.

## Why there is no native Elementor widget for each block

A native integration would require a **separate Elementor addon**:

- one class per block extending `\Elementor\Widget_Base`,
- an Elementor controls stack that mirrors every block attribute (duplicating
  the inspector and the attribute schema),
- the widget's own asset registration and render lifecycle,
- conditional loading on `did_action( 'elementor/loaded' )`.

Core deliberately does not hard-depend on Elementor. A widget addon is feasible
as an opt-in plugin that reuses the same `render_block()` path; the shortcode is
the dependency-free contract that makes that addon (or any other builder)
possible without touching block PHP.

## Related

- `docs/composability-architecture.md` — block composition and rendering lanes.
- `docs/extension-contracts.md` — stable provider and event contracts.
- `docs/user-guide.md` — authoring the block templates in the Site Editor.