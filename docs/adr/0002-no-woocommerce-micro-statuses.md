# ADR-0002 — No fulfillment micro-states as WooCommerce order statuses

## Status

Accepted (Milestone 0). Confirmed as implemented: Milestone 1 shipped the
data-defined `WorkflowEngine`/standard workflow and a narrow, re-entrancy
-guarded `Woo\StatusBridge` (outbound: all-shipped → WC `completed`;
inbound: WC cancel/refund/item-change → fulfillment `cancelled`/`problem`)
— no custom `wc-*` order status was registered.

## Context

The fulfillment workflow (queued, picking, picked, packing, packed,
shipped, delivered, completed, plus exception states) is considerably finer
grained than WooCommerce's order lifecycle. WooCommerce order statuses are
a flat, global vocabulary consumed by payment gateways, email triggers,
reports and every other plugin on the site.

## Decision

Registering eight-plus custom `wc-*` order statuses for warehouse
micro-states was considered and rejected. Instead, fulfillment state lives
entirely in `mpcf_fulfillments.state`, driven only by a generic,
data-defined workflow engine (`docs/ARCHITECTURE_PLAN.md` §6). A narrow,
configurable, re-entrancy-guarded `Woo\StatusBridge` translates between the
two systems in one direction each way (e.g. "all fulfillments shipped" →
WC order `completed`; WC order cancelled → fulfillment proposed into
`cancelled`), never a direct coupling of the two state machines.

## Consequences

- Third-party plugins that reason about WooCommerce order statuses are
  unaffected by this plugin's internal workflow.
- Custom, per-merchant workflows are just data (a `WorkflowDefinition`) —
  no ecosystem-wide status registration is ever required to add one.
- The bridge is the plugin's only WooCommerce order status writer, and it
  is deliberately narrow: it never treats WooCommerce as a place to encode
  fulfillment detail.

## Related

`docs/ARCHITECTURE_PLAN.md` §6.5 (this decision's fuller rationale), §6.6
(the status bridge), D2/D3.
