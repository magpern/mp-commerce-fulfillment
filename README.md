# Commerce Fulfillment

WooCommerce ends its responsibility at "the customer paid." Commerce
Fulfillment is a warehouse system that starts there: it owns picking,
packing, shipping, tracking, fulfillment documents, audit and analytics —
everything between "paid" and "delivered" — while WooCommerce stays
authoritative for products, checkout, payment, customers and the order
record.

**Current release candidate:** 0.6.0 (Milestone 6 — Package photography).
Draft PR on `feature/m6-package-photography`; not tagged/published pending
Product Owner approval. See `docs/M6_RELEASE_REPORT.md` and
`docs/ARCHITECTURE_PLAN.md` Part VIII.

**Current release:** 0.0.1 (Milestone 0 — bootstrap). **Milestone 1**
(fulfillment core — intake, workflow engine, Queue/Detail/Dashboard
screens, audit trail, WooCommerce status bridge) is implemented and
pending release as `0.1.0`; no tag exists yet. See `docs/ROADMAP.md` and
`docs/ARCHITECTURE_PLAN.md` §III.7 for its actual outcomes.

## Invariants

See `docs/ARCHITECTURE_PLAN.md` §3 for the authoritative, numbered list
(I1–I14). In short: WooCommerce owns the order; all order access goes
through WC CRUD (HPOS-safe, never `wp_posts`/`wp_postmeta`); all fulfillment
state lives in this plugin's own tables; every state change flows through
one workflow engine and is append-only audited; `Domain`/`Engine`/
`Application` are WordPress-free; deactivation removes nothing, uninstall is
all-or-nothing.

## Install

```
composer install --no-dev --optimize-autoloader
bash bin/build-zip.sh
```

The zip contains the plugin file, `uninstall.php`, `readme.txt`, `src/`,
`vendor/`, `languages/` and the vendored MP Admin Design System assets.

## Development

```
composer install
composer phpcs
composer test:unit
composer test:integration   # needs MySQL — see .github/workflows/ci.yml
composer sync-mpds -- <tag> # re-vendor the MP Admin Design System
```

## Compatibility

PHP 8.1+, WordPress 6.5+, WooCommerce 8.2+ (minimums; CI also covers current
stable PHP/WordPress/WooCommerce). HPOS-compatible from the first release;
direct access to legacy order post storage is forbidden. See
`docs/COMPATIBILITY.md`.

## Migration and uninstall

Schema is versioned in its own `mpcf_db_version` option, applied by an
explicit-SQL `Migrator` on activation and re-checked on `admin_init` (bind-
mount deployments never fire the activation hook). Deactivating the plugin
removes nothing. Uninstalling removes nothing by default; enabling
"Remove data on uninstall" in Settings removes everything this plugin
persisted — see `docs/PERSISTED_DATA.md` for the exact inventory.

## Documentation

| Document | Contents |
|---|---|
| `docs/ARCHITECTURE_PLAN.md` | The frozen architecture specification (Architecture Freeze v1.0) and the Milestone 0 execution plan. |
| `docs/ROADMAP.md` | Milestone status. |
| `docs/COMPATIBILITY.md` | Minimum and tested PHP/WordPress/WooCommerce versions. |
| `docs/PERSISTED_DATA.md` | Every option, table, capability and directory this plugin persists. |
| `docs/HOOKS.md` | Every hook this plugin registers or provides, and what is deliberately not hooked. |
| `docs/TEST_STRATEGY.md` | Unit/integration/structural-guard test strategy. |
| `docs/adr/` | Architecture decision records. |

## Changelog

### 0.1.0 — Milestone 1: fulfillment core (pending release)

Intake (`Woo\IntakeHooks`, classic and Blocks checkout, idempotent),
a data-defined workflow engine driving the standard pick/pack/ship
workflow, an append-only hash-chained audit trail, Fulfillment
Queue/Detail/Dashboard admin screens, the Warehouse Operator/Lead roles
and capabilities, an optional Operator Mode, and a configurable WooCommerce
status bridge (outbound completion, inbound cancel/refund/item-change
handling). Still no REST route and no public `do_action`/`apply_filters`
hook — see `docs/HOOKS.md`. Full outcome record:
`docs/ARCHITECTURE_PLAN.md` §III.7.

### 0.0.1 — Milestone 0: bootstrap

Plugin bootstrap, composition root, settings framework, capability
framework, migration framework (no business tables yet), MPDS vendoring.
Activates inert; declares HPOS compatibility; no admin screens, no REST
routes, no WooCommerce hooks beyond the compatibility declarations.

## License

GPL-2.0-or-later.
