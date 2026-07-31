# CLAUDE.md

Naming: namespace `MPCF\`, prefix `mpcf_`/`MPCF`, tables `{$wpdb->prefix}mpcf_*`,
text domain `mp-commerce-fulfillment`, constants `MPCF_*`, capability prefix
`mpcf_`. These are fixed and rename-proof — the commercial product name may
still change; these identifiers never do.

The permanent architectural specification lives at
`docs/ARCHITECTURE_PLAN.md` (Architecture Freeze v1.0). Read it before
proposing any structural change. Architectural changes require an ADR
(`docs/adr/`) before the document or the code changes — see that document's
own Governance section.

## Core invariants

See `docs/ARCHITECTURE_PLAN.md` §3 for the authoritative table (I1–I14).
Summary, most relevant to day-to-day work:

1.  WooCommerce owns the order — this plugin never modifies order line
    items, prices, totals, customer data or products.
2.  WooCommerce CRUD-only order access; HPOS compatibility is mandatory.
    Never `wp_posts`/`wp_postmeta`/`get_post()`/`get_post_meta()` on an
    order — always `wc_get_order()` and `WC_Order` getters/setters.
3.  All fulfillment state lives in `mpcf_*` tables, never in order meta,
    options or transients.
4.  Single writer for state: every transition flows through
    `WorkflowEngine::transition()` via `WorkflowService`, audit-recorded.
5.  The audit log (`mpcf_events`) is append-only. No update, no delete.
6.  `Domain`, `Engine` and `Application` are WordPress-free — no WP/WC
    symbol, unit-testable without a bootstrap.
7.  `$wpdb` is confined to `src/Infrastructure/Database/`.
8.  Only `src/Woo/` may name a WooCommerce class or hook.
9.  Package photos and generated documents are never publicly reachable.
10. Fulfillment never breaks the shop — failures degrade to a logged
    problem state or admin notice, never a customer-facing error.
11. Admin UI and the REST API consume the same application services.
12. Deactivation removes nothing (no deactivation hook is registered).
    Uninstall is all-or-nothing behind `remove_data_on_uninstall`
    (default: keep everything).
13. Generic product — no site, client, host or deployment names in
    committed code.
14. One approved milestone at a time.

## Code rules

- **Generic product only.** No site names, client names, hosting domains, or
  any deployment-specific branding in committed files — code, comments, docs,
  tests, workflows, composer metadata, commit content. Check before every
  commit.
- **Fully self-contained repo.** This directory is its own git repository
  (GitHub: `magpern/mp-commerce-fulfillment`), independent of whatever tree
  it happens to be checked out in.
- Composition root: `MPCF\Plugin` is a hand-wired singleton, no DI
  container. Services are `final`, constructor-injected, and register hooks
  only inside their own `register()` method.
- Custom tables via explicit-SQL `Schema`/`Migrator`, never `dbDelta`, never
  a SQL `ENUM` (states are `VARCHAR` + PHP constants).
- `src/Vendor/Mpds/` is vendored from `magpern/mp-admin-design-system` by
  `bin/sync-mpds.sh` — never hand-edit it; fixes land upstream and get
  re-synced (`MpdsVendorGuardTest` enforces this).
- No secrets in this repo, ever.

## Workflow

- Checks: `composer phpcs`, `composer test:unit`, `composer test:integration`
  (integration needs MySQL and `tests/bin/install-wp.sh`; see
  `.github/workflows/ci.yml` for the reference setup).
- Machine-specific dev-environment notes belong in `CLAUDE.local.md`
  (gitignored) — never in this file.
- Release: bump the `Version:` plugin header, `MPCF_VERSION`, and
  `readme.txt` Stable tag together; tag `vX.Y.Z` matching the header, push
  the tag only when explicitly approved. The Release workflow builds and
  publishes the installable zip.
- Milestone execution plans live under `docs/ARCHITECTURE_PLAN.md` Part II
  onward (one per milestone, appended after PO approval) and each opens
  with a reconciliation against the frozen architecture and the previous
  milestone's actual shipped state.
