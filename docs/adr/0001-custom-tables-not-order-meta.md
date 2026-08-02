# ADR-0001 — Custom tables, not order meta or a CPT

## Status

Accepted (Milestone 0). Confirmed as implemented: Milestone 1 shipped the
four tables exactly as decided (`mpcf_fulfillments`, `mpcf_fulfillment_items`,
`mpcf_events`, `mpcf_notes` — see `docs/PERSISTED_DATA.md`), read/written
only through `Infrastructure\Database` repositories, with the order itself
still referenced by ID and read exclusively through `Woo\WooOrderSource`.

## Context

A fulfillment needs its own state, priority, assignment, snapshots and
optimistic-lock version; its line items need per-line pick/pack quantities;
shipments, packages, photos, documents and notes are all naturally
relational to a fulfillment; and every one of those mutations needs an
append-only audit trail. The Queue screen needs indexed, multi-column
queries (`state + warehouse + assignee + age`) at warehouse scale.

## Decision

All fulfillment data lives in this plugin's own `mpcf_*` tables (explicit
SQL DDL, versioned migrations — see `docs/ARCHITECTURE_PLAN.md` §7), never
in WooCommerce order meta and never as a custom post type. The WooCommerce
order is referenced by ID and read through an `OrderSource` port; it is
never the fulfillment's storage.

Order meta was rejected: it cannot express append-only audit semantics,
cannot be indexed the way the Queue needs, migrates storage location under
HPOS, and each write triggers order-object cache invalidation the plugin
does not want on every pick tick. A custom post type was rejected for the
same reasons plus `wp_posts` coupling, which would sit uncomfortably next
to invariant I2's HPOS-only order access rule even though fulfillment rows
are not order rows.

## Consequences

- The plugin owns its own schema lifecycle (`Schema`, `Migrator`,
  `uninstall.php`) independent of WooCommerce's order storage migrations.
- Fulfillment data survives WooCommerce order anonymization cleanly — the
  two are separate tables with separate retention rules.
- The plugin must maintain its own indexes and query patterns rather than
  relying on `WP_Query`/`WC_Order_Query` — accepted, since the Queue's
  access patterns (state/warehouse/assignee/age) do not match either.

## Related

`docs/ARCHITECTURE_PLAN.md` §7 (data model), §7.2 (this decision's fuller
rationale), I2/I3 (invariants).
