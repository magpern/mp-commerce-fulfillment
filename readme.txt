=== Commerce Fulfillment ===
Contributors: magpern
Tags: woocommerce, fulfillment, warehouse, shipping, picking
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Warehouse fulfillment platform for WooCommerce: picking, packing, shipping, tracking, documents and audit — everything from paid to delivered.

== Description ==

WooCommerce ends its responsibility at "the customer paid." Commerce
Fulfillment is a warehouse system that starts there: it owns picking,
packing, shipping, tracking, fulfillment documents, audit and analytics,
while WooCommerce stays authoritative for products, checkout, payment,
customers and the order record.

Requires WooCommerce 8.2 or newer, and is fully compatible with High-
Performance Order Storage (HPOS).

== Installation ==

1. Upload the plugin zip via Plugins > Add New > Upload Plugin.
2. Activate.
3. WooCommerce 8.2+ must be installed and active.

== Changelog ==

= 0.4.0 =
* Feat: Documents I — packing slip + picking list with branding and protected HTML storage (M4).
* Feat: Workspace state-aware document actions and typed REST render (`doc_type`).
* Feat: Documents history, exact historical reprint, and capped Queue bulk picking-list print (25).
* Docs: Part VI outcomes, API/history routes, print S2 evidence; Mission Control still deferred.

= 0.3.0 =
* Feat: Ops UX — Workspace stage guidance and next-action clarity (M3-D).
* Feat: Orders read-only overview with Open destinations (M3-E).
* Fix: Orders Filter submit button; packing package weight/dimensions guidance;
  empty customer name fallback in admin lists (M3-F dogfood polish).
* Docs: ROADMAP/Part V — M3 = Ops UX; Documents I moves to M4; dogfood lessons
  backlog. Mission Control Dashboard/Queue redesign deferred.

= 0.2.2 =
* Fix: Packing Workspace quantity controls — +/− buttons, direct number input,
  and the "Picked" / "Packed" counter now stay in sync and persist correctly.
  Stepper buttons are wired directly so the sticky action bar cannot swallow
  clicks; typing or using the native spinner commits on input/blur; unchanged
  quantity resubmits no longer write duplicate audit events.
* Fix: Checkout customer note text is shown in the workspace under
  "Customer instructions" (not just a presence flag).
* Tests: unit, integration, and browser coverage for the picking quantity
  workflow and customer-note rendering.

= 0.2.1 =
* Fix: WorkspaceFlags fatal when opening a fulfillment for a customer order
  (not a guest checkout). The repeat-customer detection called a nonexistent
  WooCommerce helper function `wc_get_customer_order_ids()`. Replaced with the
  correct HPOS-compatible `wc_get_orders()` API. Regression tests added to
  prevent similar issues; detection fails gracefully when a lookup is
  unavailable.

= 0.1.1 =
* Fix: an admin-initiated transition (Fulfillment Queue or Fulfillment
  Detail) now reaches the WooCommerce status bridge. Previously the
  composition root wired the admin screens' workflow service to a separate,
  unsubscribed event dispatcher, so only order-driven transitions (via
  cancellation/refund observation) advanced a WooCommerce order to
  "completed" — an operator manually shipping the last package for an
  order did not. No data migration; no settings change.
* Housekeeping: corrected a stale vendored-source label left over from
  release-candidate testing (`assets/mpds/SOURCE_TAG`), no functional
  effect.

= 0.1.0 =
* Fulfillment core: paying a WooCommerce order (classic or Blocks checkout)
  creates a fulfillment automatically and idempotently; `wp mpcf intake
  backfill` ingests existing orders the same way.
* A data-defined workflow engine drives the standard pick/pack/ship
  workflow, with an append-only, hash-chained audit trail per fulfillment
  (`wp mpcf audit verify` checks the chain).
* Fulfillment Queue, Fulfillment Detail and Dashboard admin screens, built
  on the MP Admin Design System.
* Two roles (Warehouse Operator, Warehouse Lead) with their own
  capabilities; an optional Operator Mode setting hides the rest of
  wp-admin's navigation for the operator role.
* A configurable WooCommerce status bridge: fulfillment progress can mark
  an order completed; order cancellation, refunds and post-payment item
  edits are reflected back onto the fulfillment.
* Uninstall policy extended to the new tables, roles, capabilities and
  scheduled actions — still all-or-nothing behind "Remove data on
  uninstall", still keeping everything by default.

= 0.0.1 =
* Milestone 0: bootstrap, composition root, settings framework, capability
  framework, migration framework. No business features yet.
