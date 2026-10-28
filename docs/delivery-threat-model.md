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
| Unsafe redirect | HTTPS + explicit host allowlist enforced after all filters | `tests/run.php` allowlist cases |
| Weak/rotated signing keys | Fail-closed 32-char minimum; `kid` rotation parameter; settings warning | `tests/run.php` weak-key case |
| Replaced/truncated master on disk | Registry size fingerprint re-verified at delivery | code path in `resolve_registered()`; *wp-env* |
| Token-issue flooding | Per-user fixed-window rate limit (429) | `tests/run.php` rate-limit case |
| Bulk exfiltration via compromised account | Optional per-user daily delivery quota (`music_wave_download_daily_quota`) | code path; disabled by default |
| PHP worker exhaustion on large files | X-Sendfile / X-Accel-Redirect offload modes | `tests/run.php` header builder cases |

## Load testing

Bounded-load verification (concurrent ticket issue + delivery + replay contention) is deferred
to Stage 7 qualification on the wp-env fixture; the atomic replay consume and rate limits above
are the controls under test.
