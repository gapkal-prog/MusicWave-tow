# Secure delivery threat model

Status: Phase 2 Stage 2 (PROJECT_PLAN.md). Maps each attack case from the Stage 2 exit gate
to its control and its regression coverage. Cases marked *wp-env* still need verification on
a real WordPress fixture before Stage 7 sign-off.

| Attack case | Control | Coverage |
|---|---|---|
| Direct web access to masters | Protected root must resolve outside ABSPATH **and** `DOCUMENT_ROOT`; unsafe automatic default refused; deny files written as second layer | `preflight()` + settings notice; *wp-env* HTTP probe pending |
| Path/ID guessing | Opaque `vip:<key>` registry IDs (128-bit random) replace path-revealing `local:` IDs; malformed/unknown IDs fail closed; registry-backed assignment validation + dedicated capability | `tests/run.php` registry cases |
| Ticket guessing | Browser receives only a 160-bit random `mwt_` ticket; signed claims never leave the server | `tests/run.php` opaque ticket cases |
| Token replay | One-time replay markers in indexed `{prefix}mw_download_replays`; atomic `INSERT IGNORE`; daily cleanup keeps the store bounded | `tests/run.php` replay + ticket replay cases; cron in *wp-env* |
| Token tampering | HMAC-SHA256 over the full claim payload; nonce binding; expiry, purpose, user/release/asset match checks | `tests/run.php` token service cases |
| Cross-user transfer | Claims bind user ID + nonce; delivery re-runs the access decision for the presenting user | `tests/run.php` token binding cases |
| Mode/parameter tampering on remote URLs | Complete-payload signing `url|expires|mode|kid` | `tests/run.php` signature-differs cases |
| Unsafe redirect / SSRF on `direct:` URLs | HTTPS-only, `<=2048` chars, blocks credentials/fragments, `localhost`, internal TLDs, RFC 1918, CGNAT `100.64/10`, `198.18/15`, multicast, `::ffff:` IPv4-mapped IPv6, NAT64 `64:ff9b::/96`, numeric/hex/octal IPs, plus `direct_link_allowed_hosts` re-validated at both save and delivery time; `manage_mw_protected_assets` required on assignment | `tests/vip-access-delivery-suite.php` T7–T13 |
| Weak/rotated signing keys & autoload exposure | Fail-closed 32-char minimum; `MUSIC_WAVE_VIP_REMOTE_SECRET` constant priority; non-autoloaded `music_wave_vip_remote_keyring` (`active`/`retired` `kid` rotation + `hash_equals()`) | `tests/vip-access-delivery-suite.php` T18 |
| `vip:<hash>` leakage in remote URLs | `RemoteRedirectProvider` resolves `vip:<32hex>` via `ProtectedAssetRegistry` to `relative_path` and fails closed on unknown IDs | `tests/vip-access-delivery-suite.php` T15–T17 |
| Replaced/truncated master on disk | Registry size/SHA-256 fingerprint re-verified at delivery | `tests/protected-asset-storage.php` |
| Token-issue flooding | Per-user fixed-window rate limit (429) | `tests/run.php` + `tests/download-e2e.php` |
| Bulk exfiltration / cache-flush quota bypass | Optional per-user daily delivery quota (`music_wave_download_daily_quota`) persisted in non-autoloaded `mw_dl_quota_*` DB options with shutdown-safe counting and daily cleanup | `tests/vip-access-delivery-suite.php` T14 |
| PHP worker exhaustion on large files | X-Sendfile / X-Accel-Redirect offload modes | `tests/run.php` header builder cases |

## Load testing

Bounded-load verification (concurrent ticket issue + delivery + replay contention) is deferred
to Stage 7 qualification on the wp-env fixture; the atomic replay consume and rate limits above
are the controls under test.
