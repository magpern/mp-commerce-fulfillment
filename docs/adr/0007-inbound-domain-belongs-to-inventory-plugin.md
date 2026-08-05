# ADR-0007 — Inbound domain belongs to wc-inventory-overview

## Status

Accepted (documentation-only boundary ratification, pre-M4).

## Context

Commerce Fulfillment (MPCF) is the outbound warehouse execution plugin:
fulfillment workflow, picking, packing, shipments, packages, documents, and
operator UX for customer orders.

The Biopentra stack also ships **wc-inventory-overview** (wp-admin “Inventory &
Profit” / “Purchasing”), which already owns suppliers, inventory movements,
weighted-average costing, and the sole production path that mutates WooCommerce
stock for inbound receiving (Batch Intake / Quick Restock today; Goods Receipt
after that plugin’s receiving milestones).

MPCF §2.5 already excluded stock/inventory management and purchase orders /
inbound logistics. However, §7.1, D13, and M12 still assigned **location
hierarchy**, **item-location assignment**, and **inventory topology** to MPCF
(`mpcf_locations`, `mpcf_item_locations`). That duplicated ownership of
concepts that belong in the inbound inventory domain.

A separate planning pass also showed that “inbound” in bridge settings
(`inbound_cancel_behavior`, `inbound_refund_behavior`) and §6.6 means
**store-order → MPCF** (cancel/refund hooks), not supplier receiving — a
naming collision that invited a mis-planned receiving milestone inside MPCF.

## Decision

1. **Inbound inventory domain owner:** `wc-inventory-overview` owns suppliers,
   purchase orders, goods receipts, receiving, inventory movements, the stock
   ledger, inventory position, landed/actual cost, inventory reconciliation,
   warehouse location hierarchy, bins, shelves, aisles, and **all inbound stock
   mutation**.

2. **Outbound warehouse execution owner:** MPCF owns fulfillment aggregate and
   workflow state, picking and packing progress, shipments, packages, tracking,
   fulfillment documents, operator workflow UX, the fulfillment audit trail
   (`mpcf_events`), and per-warehouse **queue partitioning** (`warehouse_id`).

3. **WooCommerce** remains authoritative for the product catalog, product data,
   stock-on-hand as the commerce record, customer orders, checkout, and the
   payment/refund lifecycle.

4. **Exactly one owner per business concept.** The canonical registry lives in
   `docs/ARCHITECTURE_PLAN.md` §2.6. Duplicate ownership is prohibited. Moving
   a concept between owners requires an **Accepted ADR in every affected
   repository** before code or schema changes.

5. **No cross-plugin database access.** Neither plugin may read or write the
   other’s tables. Integration, when needed, uses a narrow versioned WordPress
   hook or documented read API on the publisher side only.

6. **M12 reassignment:** MPCF drops `mpcf_locations` and `mpcf_item_locations`.
   M12 becomes multi-warehouse **queues**, warehouse routing, and
   location-sorted picking that **consumes** location data from
   wc-inventory-overview via a future contract — not MPCF location master data.

7. **`location_snapshot` stays in MPCF** on `mpcf_fulfillment_items` as an
   **immutable intake snapshot** — a pick-path hint copied at fulfillment
   creation. It is not inventory position authority (same pattern as
   `sku_snapshot` / `name_snapshot`).

8. **`warehouse_id` stays in MPCF** — an outbound execution routing dimension
   (which queue a fulfillment belongs to), not the inventory location registry.

9. **Setting key names unchanged.** `inbound_*` settings are not renamed (that
   would alter persisted settings shape). Documentation clarifies they mean
   **store-order bridge** behaviour only, never supplier inbound logistics.

## Rejected alternatives

- **Purchase orders inside MPCF** — duplicates wc-inventory-overview’s planned
  purchasing domain; violates single-owner rule.
- **Receiving / goods receipts inside MPCF** — would introduce a second inbound
  stock mutation path and split operator UX.
- **MPCF as a second stock writer** — two writers to WooCommerce stock and
  competing cost ledgers; breaks determinism and auditability.
- **Cross-plugin database access** — couples plugins at the storage layer;
  forbidden by MP Commerce independence rules (§2.2).

## Consequences

- D13 is **superseded in part** (location hierarchy and item-location tables
  removed from MPCF); the `warehouse_id` column decision stands.
- §7.1 post-1.0 reserved tables no longer include `mpcf_locations` or
  `mpcf_item_locations`.
- §20 M12 is rewritten; §2.6 adds the permanent ownership registry.
- §6.6 and settings documentation disambiguate store-order bridge from supplier
  receiving.
- Future location-sorted picking in MPCF depends on a wc-inventory-overview
  contract (not defined in this ADR).
- wc-inventory-overview should mirror this boundary in its own architecture
  docs when that repo’s roadmap recovery completes.

## Related

`docs/ARCHITECTURE_PLAN.md` §2.3, §2.5, §2.6, §6.6, §7.1, §18 D13, §20 M12,
§24; `docs/ROADMAP.md` domain ownership note.
