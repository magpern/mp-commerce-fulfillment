=== Commerce Fulfillment ===
Contributors: magpern
Tags: woocommerce, fulfillment, warehouse, shipping, picking
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.0.1
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

= 0.1.0 =
(Milestone 1 — pending release, not yet tagged.)
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
