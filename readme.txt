=== Commerce Fulfillment ===
Contributors: magpern
Tags: woocommerce, fulfillment, warehouse, shipping, picking
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.1
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
