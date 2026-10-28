# Secure downloads

MusicWave Core never exposes `mw_download_asset_id` or a quality variant identifier as a URL. An authenticated user first requests a short-lived (five-minute) signed token from `POST /wp-json/music-wave/v1/releases/{id}/download-token?quality=mp3-320`. The token is bound to the release, current user, selected quality, and a WordPress download nonce returned with the token.

The frontend sends the WordPress REST nonce explicitly in `X-WP-Nonce` when requesting a token and includes a fresh REST nonce in the protected delivery URL. Expired sessions return the actionable `mw_authentication_required` response rather than WordPress's generic `rest_forbidden` message.

`GET /wp-json/music-wave/v1/downloads/{id}?token=...&nonce=...` verifies the HMAC signature, expiry, user/release/quality/nonce binding, current `AccessPolicyEngine` decision, and one-time replay marker before delegating delivery. A token therefore cannot be reused, transferred to another account, switched to a higher quality, or remain valid after a purchase/membership entitlement changes.

Core routes delivery by asset scheme through `DeliveryRouter` (ADR 0005):

- **Opaque protected schemes (`vip:<32hex>`, `local:<relative-path>`)**: deny-by-default (`503`) unless an integration supplies a `DownloadProvider` through `music_wave_download_provider` (such as MusicWave VIP).
- **Controlled direct HTTPS scheme (`direct:https://...`)**: handled natively in Core by `DirectLinkProvider` + `DirectLinkUrlValidator` + `RedirectResponse` (`302` with `Referrer-Policy: no-referrer`, `Cache-Control: private, no-store, max-age=0`, `X-Content-Type-Options: nosniff`). Assigning or editing `direct:` URLs requires `manage_mw_protected_assets`, enforces strict SSRF/IP/scheme validation plus optional `direct_link_allowed_hosts` at both save and delivery time, and supports streaming when URL extension, `format`, or `file_name` indicates a browser-playable audio codec. Because a `302` redirect reveals the final target URL to authorized browsers, sensitive paid masters should use VIP local vault or short-lived HMAC remote signing.

Download tokens are single-use (`MAX_TTL = 7200`). Stream tokens remain reusable during their short lifetime so browser byte-range requests can seek correctly; entitlement is re-evaluated for every request. Daily delivery quotas (`download_daily_quota`) persist in non-autoloaded `mw_dl_quota_*` options so object-cache flushes cannot reset user quotas, and shutdown guards ensure delivery counting and `music_wave_download_event` auditing execute even when HTTP providers terminate via `exit`.

Core emits `music_wave_download_event` with an event name, release ID, and user ID for `token_issued`, `token_denied`, `download_delivered`, and `download_denied`. Integrations that persist these events must define retention and personal-data export/erasure behavior.

MusicWave VIP provides an editor-only protected-asset route at `GET`/`POST /wp-json/music-wave/v1/protected-assets`. It lists at most 50 identifiers and accepts only explicitly allowed audio/archive extensions into the configured directory outside the public web root. The selected value remains an opaque `local:` identifier. The local provider supports single HTTP byte ranges for resumable downloads and audio clients; it does not reveal a master URL. Responses use private/no-store caching, no-referrer, content sniffing protection, RFC-compatible filenames, and buffering controls suitable for protected media.

For WooCommerce customers, Core adds a `My music library` My Account endpoint. It shows only releases that pass the current access decision and have at least one protected asset. When multiple quality variants exist, the customer selects a label such as `MP3 320 kbps` or `FLAC lossless`; its buttons reuse the same token issuance route, so a refunded order, expired membership, or revoked manual grant cannot continue to download merely because an item was once shown in the library.

## Stage 2 hardening (schema 0.9.0)

**Replay protection.** One-time replay markers moved from unbounded `wp_options` rows to the
dedicated indexed table `{prefix}mw_download_replays` (migration `0.9.0`). Consumption is a
single atomic `INSERT IGNORE`; a daily `music_wave_replay_cleanup` event prunes expired rows
and purges any legacy option-based markers. Sites where the migration has not run yet fall
back to the previous behavior automatically.

**Rate limiting.** Token issuance is limited per user with a fixed window (default 30 requests
per 60 seconds; `music_wave_download_rate_limit` / `music_wave_download_rate_window` filters).
Exceeding the limit returns `mw_download_rate_limited` (HTTP 429).

**Structured audit.** `music_wave_download_event` now receives a fourth `$context` array
(`timestamp`, plus `reason` on denials and `asset_key`/`purpose` on issuance). The previous
three-argument signature keeps working.

**Remote redirect contract (breaking for remote verifiers).** The HMAC now covers the complete
payload `url|expires|mode|key_id` instead of `url|expires`, so the delivery mode can no longer
be tampered with independently. Signing fails closed when the shared secret is shorter than
32 characters. An optional `remote_key_id` setting is appended as `kid` for secret rotation.
Redirects are refused unless the final URL host is the configured remote host or listed in
`remote_allowed_hosts` — this now also constrains URLs returned by the
`music_wave_vip_remote_download_url` filter. Remote verifiers must be updated to the new
payload before upgrading.

**Private-root preflight.** VIP no longer silently provisions `musicwave-private` beside
WordPress when that location is still inside a publicly served tree (subdirectory installs);
the settings screen shows structured preflight results (exists / readable / writable /
outside web root / deny files) and provisioning writes `.htaccess`, `web.config`, and
`index.html` deny files as defense in depth.

**Opaque asset inventory.** Protected files are now inventoried in the indexed
`{prefix}mw_vip_assets` table with random `vip:<key>` identifiers replacing path-revealing
`local:<relative-path>` IDs. Registration records size, SHA-256 checksum, and MIME type;
delivery re-verifies the stored size fingerprint and fails closed on replaced or truncated
files. Files are registered automatically on first inventory listing or upload, and legacy
`local:` identifiers keep resolving during the migration period (deprecated — new assignments
should use `vip:` IDs only). Assignment validation accepts a `vip:` identifier only when it
exists in the inventory and the acting user holds `manage_mw_protected_assets`.

**Server-offloaded transfers.** The local provider can hand transfers to the web server via
`X-Sendfile` (Apache/LiteSpeed) or `X-Accel-Redirect` (nginx internal location) through the
new "Server acceleration" setting, keeping PHP workers free for large masters. The nginx
internal location must be marked `internal;` and aliased to the protected directory; range
handling is then performed by the server.

## Block labels

`music-wave/download-button` exposes three label attributes and all three now render as visible
text, not only as an accessible name:

- `downloadLabel` prints inside `.mw-download-button__label` next to the download glyph, in both
  the panel rows (`download_file_rows()`) and the compact inline group
  (`download_inline_markup()`). An empty value falls back to the translated "Secure download".
- `playLabel` prints inside `.mw-secure-play-button__label`. `assets/download.js` swaps the
  visible text and `aria-label` together while playback runs, so the accessible name and the
  on-screen text can never disagree. An empty value falls back to the translated "Play".
- `loginLabel` drives an **opt-in** sign-in prompt (`download_sign_in_markup()`). It renders only
  when the visitor is signed out, the access decision denies the release, the label is non-empty,
  and the release actually has downloadable assets. The default is empty, so
  `single-mw_release.html` — which renders `music-wave/access-panel` beside this block — keeps
  showing exactly one sign-in message. The prompt reuses the block's own `heading` attribute and
  `wp_login_url( BlockSupport::current_url() )`, the same guest-CTA shape `PlaylistBlocks`,
  `QueueBlock`, and `ListeningBlocks` already use, and adds no new translatable string.

Both buttons are built by the shared `secure_play_button()` / `secure_download_button()` helpers,
so the panel and the inline group cannot drift apart again.
