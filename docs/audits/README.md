# Audit reports (generated output)

This directory is the **output location** for the optional live-environment
audits in `tools/run-audits.sh` (Lighthouse JSON, axe results, multisite smoke
notes). It intentionally ships empty: every report here is produced by running
the tooling against a real site, so nothing in this folder is a substitute for a
green local run.

## Authoritative gates

Run these from the repository root instead of trusting a checked-in summary:

| Gate | Command | Scope |
|------|---------|-------|
| PHP syntax | `composer check:syntax` | every `.php` file in the three packages, `tools/`, `tests/` |
| NUL bytes | `composer check:nul-bytes` | all shipped text files |
| Strict types / ABSPATH | `composer check:strict-types`, `composer check:abspath` | all PHP files and directories |
| Static analysis | `composer check:phpstan` | PHPStan L5 over `music-wave-core`, `music-wave-vip`, `musicwave` |
| Coding standards | `composer check:phpcs` | WordPress-Core/Extra/Docs/Security + PHPCompatibilityWP |
| Template + block contracts | `composer check:site-editor`, `php tools/check-templates.php` | `theme.json`, `templates/`, `parts/`, `block.json` inventory |
| i18n | `composer check:translations`, `composer check:script-translations` | POT/PO/MO + WP-JED JSON |
| Unit & runtime | `composer test` | `tests/run.php` and the six suites it composes |
| Release integrity | `sha256sum -c MANIFEST.sha256` inside `music-wave-core/` | distribution tree hashes |

## Optional live audits

```bash
./tools/run-audits.sh --url=http://localhost:8888
```

Reports land here (for example `lighthouse-front.json`, `axe-*.json`,
`multisite-smoke.md`). They are environment-specific artifacts: review them,
then delete them before committing — this folder should stay free of stale
results.
