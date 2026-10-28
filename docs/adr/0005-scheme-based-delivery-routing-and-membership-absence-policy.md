# ADR 0005: Scheme-based delivery routing, membership-absence policy, and remote keyring rotation

- Status: Accepted
- Date: 2026-10-28
- Milestone: Core `0.16.0` / VIP `0.5.2`

## Context

Prior to Core `0.16.0` and VIP `0.5.2`, MusicWave coupled two orthogonal concerns:

1. **Entitlement evaluation when no membership add-on is installed (`NullMembershipProvider`):**
   `membership_absent_behavior` offered only `allow` (opening `membership` while keeping
   paid `purchase_or_membership` locked behind `purchase_required`) and `deny` (locking
   membership gates). Operators deactivating VIP temporarily had no reversible setting to
   open both `membership` and `purchase_or_membership` releases for download without bulk-editing
   post metadata. In addition, `AccessPolicyEngine` did not read `Settings::get('membership_absent_behavior')`
   directly unless `Plugin::boot()`'s filter ran, and that filter rejected values outside `allow|deny`.
2. **Asset delivery routing:** Core delegated all delivery to a single `music_wave_download_provider`
   filter (`NullDownloadProvider` without VIP) and rejected URL-backed download assets at schema/REST
   boundaries, while VIP's `RemoteRedirectProvider` URL-encoded `vip:<32hex>` literally (`vip%3A...`)
   instead of resolving `relative_path` from `ProtectedAssetRegistry`, and stored its HMAC signing
   secret inside the autoloaded `music_wave_vip_settings` option without multi-key rotation.

## Decision

1. **`MembershipAbsencePolicy` (`allow` | `open_download` | `deny`):**
   - `allow` remains the backwards-compatible default (`membership` opens with reason
     `membership_provider_absent`; product-linked `purchase_or_membership` keeps `purchase_required`).
   - `open_download` opens both `membership` and non-purchased `purchase_or_membership`
     with explicit decision reason `membership_absent_open_download`, without mutating any
     `mw_release` post meta and **never** opening `purchase`, `restricted`, or guest `logged_in`.
   - `deny` fails closed (`membership_provider_absent` / `purchase_required`).
2. **Scheme-based `DeliveryRouter` + `DirectLinkProvider` in Core (zero DB schema change):**
   - `Plugin::boot()` preserves `apply_filters( 'music_wave_download_provider', new NullDownloadProvider() )`
     for external/VIP providers (`vip:<32hex>`, `local:<path>`) and wraps the result in
     `DeliveryRouter( $external, new DirectLinkProvider() )`.
   - Assets prefixed with `direct:https://...` are validated at both authoring time
     (`MetaDefinition`, `DownloadAssetRoutes`, `ReleaseMetaBox`, `assets/editor.js`) and
     delivery time (`DirectLinkUrlValidator`) against strict HTTPS, length (`<=2048`),
     credential/fragment rejection, SSRF/IP range blocking (RFC 1918, loopback, link-local,
     CGNAT `100.64/10`, benchmark `198.18/15`, multicast, `::ffff:` IPv4-mapped IPv6,
     NAT64 `64:ff9b::/96`, numeric/hex/octal IPs), and optional `direct_link_allowed_hosts`.
   - Creating or modifying `direct:` URLs requires `manage_mw_protected_assets`
     (`DirectLinkPermission`), preventing privilege escalation by lower-privileged editors.
   - `DirectLinkProvider` and VIP `RemoteRedirectProvider` share `RedirectResponse` (`302`,
     `Referrer-Policy: no-referrer`, `Cache-Control: private, no-store, max-age=0`,
     `X-Content-Type-Options: nosniff`).
3. **VIP `RemoteRedirectProvider` registry resolution & `RemoteSigningKeyring`:**
   - `ProviderFactory` injects `ProtectedAssetRegistry` into `RemoteRedirectProvider`,
     mapping `vip:<32hex>` to its registered `relative_path` (failing closed on unknown IDs)
     and stripping `local:` so `vip%3A` / `local%3A` never appear in signed CDN URLs.
   - `RemoteSigningKeyring` persists secrets in non-autoloaded option
     `music_wave_vip_remote_keyring` (`autoload=false`), prioritizes `MUSIC_WAVE_VIP_REMOTE_SECRET`
     in `wp-config.php`, and supports `active` + `retired` `kid` rotation verified with `hash_equals()`.

## Consequences

- Sites without MusicWave VIP can deliver controlled `direct:https://...` releases with full
  Core entitlement checks, one-time tickets, rate limits, and non-autoloaded DB daily quotas,
  while `vip:` / `local:` assets continue failing closed (`503`) unless a protected provider is active.
- Operators are explicitly warned in Admin UI and docs that `302` redirects disclose the final
  target URL to authorized browsers, so sensitive paid masters should use VIP local vault or
  short-lived HMAC remote signing.
- Schema version stays `0.15.0` (`MigrationRunner::LATEST_VERSION`); rollback or toggle changes
  require zero data migration.
