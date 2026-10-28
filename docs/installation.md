# Installation

1. Install and activate WooCommerce when paid releases are used.
2. Install and activate `music-wave-core`, then activate the `musicwave` block theme.
3. For protected downloads, install and activate `music-wave-vip`.
4. Optionally define `MUSIC_WAVE_VIP_PROTECTED_ROOT` in `wp-config.php` as a readable directory outside the public web root, or configure it under Settings > MusicWave VIP. The constant takes precedence; when unset, VIP creates `musicwave-private` beside the WordPress web root.
5. Create releases, assign release-type and discovery taxonomies, configure access mode, and map WooCommerce products or membership levels.
6. In the **MusicWave release details** metabox, add every protected download quality by uploading a file, importing one from Media Library, browsing protected files, or entering a provider identifier. MusicWave stores local files as `local:relative/path/file.ext` inside `mw_download_assets`.
7. Open `MusicWave > Setup & diagnostics` and resolve the schema, permalink, WooCommerce, theme, and protected-delivery checks.
8. Re-save permalinks after updating to Core `0.5.0` or later; this enables the WooCommerce `My music library` endpoint.
9. Optionally use `MusicWave > Setup & diagnostics > Import demo catalog` to preview the release, album, and podcast experience. The importer is safe to run repeatedly and only removes records it created.

After activation, visit Settings > Permalinks once or save permalinks to refresh rewrite rules. Test public, purchaser, member, administrator, expired-token, replay-token, and invalid-asset cases before launch.
