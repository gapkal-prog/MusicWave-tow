# ADR 0003: Runtime support policy (PHP / WordPress / WooCommerce)

- Status: Accepted
- Date: 2026-08-19
- Milestone: Phase 2, Stage 0 (PROJECT_PLAN.md)

## Context

The packages declare PHP 7.4+ and WordPress 6.6+ (tested through 6.8), but the tooling lock file
uses WordPress stubs newer than that claim and CI never proved the floor. PROJECT_PLAN.md
Stage 0 requires an explicit, testable support policy, and recommends PHP 8.2+ as the
next-major baseline.

## Decision

1. **Current release line (0.x → 1.0):** keep the declared floor of **PHP 7.4** and
   **WordPress 6.6**, because the shipped code is written to that floor and the commercial
   positioning targets ordinary hosting. The floor is only a valid claim while CI actually
   executes the quality gates on PHP 7.4 — the `quality.yml` matrix must keep a 7.4 job until
   this line ships. PHPCompatibilityWP (`testVersion 7.4-`) stays enabled in PHPCS.
2. **Next major (2.0):** raise the baseline to **PHP 8.2+** and **WordPress 6.7+**. New
   post-1.0 feature work may use PHP 8 syntax only after the 2.0 branch opens.
3. **WooCommerce:** supported as an **optional** integration at **9.x or newer**. Core must
   activate, publish, and render the catalog with WooCommerce absent; commerce surfaces must
   degrade to informative, non-fatal states.
4. **Testing claims:** "Tested up to" statements in readme files may only advance when the
   fixture (`.wp-env.json`) or recorded manual evidence covers that WordPress version.
   Compatibility ranges live in `release-manifest.json` and must not fork per-file.

## Consequences

- CI keeps a PHP 7.4 + PHP 8.2 matrix for every gate that can run without WordPress.
- The WordPress stubs used by PHPStan may exceed the declared floor (stubs describe newer
  APIs); the floor is enforced by PHPCompatibilityWP and the 7.4 CI job, not by stub choice.
- Dropping PHP 7.4 before 2.0 requires updating this ADR, `release-manifest.json`, all
  readme/style headers, `composer.json`, and the CI matrix in one change.

## Rejected alternatives

- **PHP 8.0/8.1 floor now:** breaks the shipped compatibility promise mid-line for no
  functional gain; the codebase is already 7.4-clean.
- **Tracking WooCommerce as a required dependency:** contradicts the product boundary that
  the catalog is independent of any commerce plugin (ADR 0001, PROJECT_PLAN.md §3).
