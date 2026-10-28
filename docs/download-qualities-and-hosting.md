# Download qualities, collections, shared hosting, and memberships

## Download files and qualities

Create one `mw_release` for each track or podcast episode. The **MusicWave release details** meta box keeps track/episode/season numbers, preview duration, protected downloadable files, and their qualities together.

Create one downloadable-file group for every song, episode, bonus file, or bundle. Add as many quality rows as needed inside that group. This lets an album or podcast release offer multiple files while each file still has MP3, FLAC, or other variants. Every quality includes:

- a unique technical key used by secure download requests;
- a customer-facing label, such as `MP3 320 kbps` or `FLAC lossless`;
- an opaque provider asset ID, never a public URL;
- optional format, bitrate, duration, file size, and file-name metadata.

Editors can upload a protected file directly to the configured private directory, browse files already stored there, choose an audio file from WordPress Media Library, or add an opaque ID for another secure provider. Select the target downloadable file before uploading to add a quality to it; leave the target as new to create another file. A selected Media Library file is copied to the configured protected directory; its original public upload remains unchanged and should be removed if it must not remain public. For supported audio files, MusicWave VIP reads format, bitrate, duration, and file size, then creates a suggested quality label/key. Common quality presets can also fill the key, customer label, format, and bitrate with one selection. All detected values remain editable.

Existing `mw_download_asset_id` values appear as one `Standard download` row the first time they are edited, then move into `mw_download_assets`.

Albums, playlists, and podcast shows contain child Track/Episode releases through `mw_collection_items`. The collection picker searches compatible releases by title and by the safe stored filename of their protected assets. Add each child once, attach its download files and quality variants once, then reuse it in any album, compilation, playlist, or podcast show. This avoids copied files, inconsistent metadata, and separate entitlement logic.

## Shared hosting

The included VIP provider works on shared hosting when the host allows PHP to read a directory outside `public_html` and permits files large enough for your intended uploads:

1. Create a directory such as `/home/account/musicwave-private` outside `public_html`.
2. Set `MUSIC_WAVE_VIP_PROTECTED_ROOT` in `wp-config.php`, or configure the directory in Settings > MusicWave VIP. When neither is set, VIP creates `musicwave-private` beside the WordPress directory when that parent directory is writable.
3. Upload assets through the editor, browse existing files in the protected root, or transfer them by SFTP and select/use a `local:` identifier.
4. Set PHP `upload_max_filesize`, `post_max_size`, `memory_limit`, and execution limits high enough for the files you actually upload.

The provider streams the selected file through PHP after access and signed-token checks. This is practical for a small catalog and moderate traffic, but it consumes PHP workers and bandwidth. For many simultaneous FLAC/ZIP downloads, use object storage/CDN with a future provider that generates short-lived signed delivery URLs; Core's opaque provider-ID contract is designed for that migration.

VIP now includes that migration path as **Remote HTTPS host with HMAC redirect**. The remote service must verify `signature = base64url(HMAC-SHA256(canonical_url + "|" + expires, secret))` and reject expired timestamps. Keep the secret only in `wp_options`/server configuration, rotate it during a maintenance window, and use a short lifetime. A custom integration can implement `music_wave_vip_remote_download_url` when the host needs AWS SigV4, CloudFront, Bunny, Cloudflare R2, or another provider-specific signer.

## WooCommerce and VIP access

For one-time sales, map the release to one or more WooCommerce products and set access to `purchase`. A completed eligible WooCommerce order grants the release entitlement.

For subscriptions or members-only content, set access to `membership` or `purchase_or_membership`, then add neutral level keys such as `gold` or `vip`. MusicWave VIP maps those keys to WordPress roles by default. A membership plugin integration should return the active levels through the `music_wave_vip_membership_levels_for_user` filter; it should not write direct download URLs or bypass `AccessPolicyEngine`.

The settings screen explains the four membership sources: roles, the external filter, WooCommerce Memberships, and WooCommerce Subscriptions. Multiple sources may be enabled; access is granted when at least one source matches. This keeps WooCommerce order ownership and membership entitlement separate while allowing both through `purchase_or_membership`.

WooCommerce Subscriptions or Memberships can therefore be connected through a small adapter that maps an active plan to a level key. Core remains the single authority for current access, including expiry, refund, or cancellation changes.

## Preview duration

The preview player defaults to 30 seconds and supports a per-release `mw_preview_duration` value from 10 to 120 seconds. The editor can read the duration of a public HTTPS preview file as a best-effort convenience. Protected-file uploads are read on the server, so the browser never opens a protected master file. The upload integration currently fills safe format, bitrate, duration, and file-size metadata; waveform, codec detail, and loudness data can be added through a future media-processing integration.
