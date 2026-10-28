# MusicWave SEO and structured data

MusicWave Core emits one public JSON-LD graph on published `mw_release` single pages when no recognized SEO plugin is active. It uses only public catalog data:

- `MusicRecording` for tracks and singles.
- `MusicAlbum` for albums, EPs, mixes, and playlists, including public ordered track links.
- `PodcastSeries` and `PodcastEpisode` for the respective release types.
- artist identity and official canonical URL, genre, artwork, editorial excerpt, catalog number, release/modified dates, duration, and HTTPS preview URL when available.
- the canonical page entity, public child-track count, child-track artist attribution, and podcast series relationship when available.

When no recognized SEO plugin is active, Core also provides a conservative fallback for the native document title, meta description, Open Graph Music fields, and Twitter Card fields. Descriptions use the explicitly public excerpt and never derive text from gated release content. The block theme declares native `title-tag` support and WordPress remains responsible for the canonical link.

Protected asset identifiers, download URLs, product mappings, membership levels, entitlement decisions, and customer data are never emitted.

## SEO plugin compatibility

Core disables its JSON-LD and fallback social metadata by default if Yoast SEO, Rank Math, SEOPress, or All in One SEO is detected, preventing duplicate output. If another SEO plugin is active but does not create Music schema, a custom integration may explicitly enable MusicWave output:

```php
add_filter( 'music_wave_json_ld_enabled', '__return_true' );
```

Use that filter only after confirming the page has one Music schema graph. The `music_wave_release_json_ld` filter may extend public fields but must never add protected assets, entitlement data, user data, or unverified URLs.

Fallback title/social metadata can be controlled separately with `music_wave_public_metadata_enabled`. Keep it disabled when another plugin owns titles, descriptions, Open Graph, or Twitter Cards.
