# Compatibility

## Minimum supported versions (the floor)

| | Version |
|---|---|
| PHP | 8.1 |
| WordPress | 6.5 |
| WooCommerce | 8.2 |

These are minimums, not the primary development target (PO decision,
2026-07-31 — see `docs/ARCHITECTURE_PLAN.md` D18). CI's `floor` integration
leg pins exactly these coordinates. WordPress 6.5 is now also load-bearing for
Milestone 2's Script Modules API (`wp_enqueue_script_module`, `print_enqueued_script_modules`) —
the floor is both a PO decision and a technical requirement, not either alone.

## Tested up to (current stable)

| | Version |
|---|---|
| PHP | 8.4 |
| WordPress | 6.7 |
| WooCommerce | 10.9 |

CI's `current` integration leg pins the current stable WooCommerce release
exactly (not `latest` — see the CI workflow's own comment on why floating
tags are unsuitable there); a `ceiling` leg floats on `latest` as an early
warning, `continue-on-error`.

## HPOS

Mandatory from the first release (invariant I2). `custom_order_tables` and
`cart_checkout_blocks` compatibility are declared unconditionally in
`mp-commerce-fulfillment.php` on `before_woocommerce_init`, independent of
whether `MPCF\Plugin::init()` ever runs. Direct access to legacy order post
storage (`wp_posts`/`wp_postmeta` for orders, `get_post()`/`get_post_meta()`
on an order ID) is forbidden everywhere in this codebase, guard-tested by
`LegacyOrderStorageGuardTest`.

## Third-party bundle plugins

A bundle/kit plugin implementing "Architecture B" — a priced kit-parent
order line plus real, hidden, zero-priced component child order lines that
WooCommerce core stocks, reserves, and reduces normally — is supported via
one read-only external contract (ADR-0008):

- **Marker:** order-item meta key `_ucb_kit`, non-empty on a kit-parent line
  only. `WooOrderSource::line_items()` excludes any line carrying it.
- **Nothing else is read.** No class, hook, constant, autoloader, or
  activation check from that plugin — the literal meta key is hardcoded
  here, so MPCF has no runtime dependency on it. A historical kit order is
  handled correctly even with that plugin fully absent.
- **No migration, no `RefundObserver` change.** See
  `docs/plans/UCB_FULFILLMENT_INTEGRATION.md` for the evidence.

**Rollout gate:** kit products must not be enabled for sale until the
ADR-0008 implementation is deployed. `mpcf_fulfillment_items` rows are
write-once at intake with no re-sync path, so a fulfillment created from a
kit order before this guard exists keeps an unrepairable phantom
kit-parent picking row.

## Bump ritual

When the floor or tested-up-to versions change, update this file, the
`Requires at least` / `Requires PHP` / `WC requires at least` / `WC tested
up to` plugin headers, and the CI matrix in the same commit. `CiMatrixGuardTest`
and `CompatibilityMatrixTest` bind this file to the CI workflow and the
plugin header so they cannot drift apart silently.
