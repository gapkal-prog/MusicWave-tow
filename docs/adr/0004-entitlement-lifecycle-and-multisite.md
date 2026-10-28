# ADR 0004: WooCommerce entitlement lifecycle and multisite support

- Status: Accepted
- Date: 2026-08-19
- Milestone: Phase 2, Stage 3 (PROJECT_PLAN.md)

## Context

Entitlement decisions were implicitly delegated to `wc_customer_bought_product()` without a
documented policy, and multisite support had never been decided.

## Decision: entitlement lifecycle

1. **Grant:** a WooCommerce order in `processing` or `completed` state grants purchase
   entitlement to every release mapped to a purchased product.
2. **Revoke:** `refunded`, `cancelled`, and `failed` orders grant nothing. Because every
   token issuance **and** every delivery re-evaluates the live decision (no cached
   entitlements), a refund revokes access immediately — including tokens already issued,
   which fail at delivery time.
3. **Membership/manual grants:** evaluated live by their providers on the same
   deny-by-default engine; expiry therefore revokes on the next request.
4. **Extension point:** `music_wave_purchase_owns_release` may adjust the ownership result
   for custom order lifecycles (deposits, invoicing, gifting). The result remains subject to
   the access policy engine; integrations must not grant on unpaid states.
5. Real Woo order-transition tests (pending → processing → refunded) run on the wp-env
   fixture as part of the Stage 3 exit gate and Stage 7 qualification.

## Decision: multisite

Multisite is **not supported** for the production baseline. All storage is per-site
(options, post/user meta, and the per-site `{prefix}mw_download_replays` /
`{prefix}mw_vip_assets` tables), so nothing breaks structurally, but network activation,
cross-site catalogs, and network-wide provider configuration are untested and unclaimed.
Activation on multisite is tolerated per-site at the operator's own risk; readmes must not
claim multisite support until a dedicated test pass exists.

## Consequences

- No entitlement cache exists to invalidate; revocation correctness costs one policy
  evaluation per request (bounded by the Stage 2 rate limits).
- Marketplace listings and readme files must state single-site support only.
