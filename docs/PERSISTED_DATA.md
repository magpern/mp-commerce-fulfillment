# Persisted data inventory

Machine-readable source of truth: `src/PersistedKeys.php`. This document is
its human-readable mirror, kept in sync by `PersistedKeysInventoryTest`
(fails if this file and the class disagree) and bound to `uninstall.php` by
`UninstallPolicyGuardTest` (fails if uninstall does not remove exactly this
inventory when `remove_data_on_uninstall` is enabled).

Milestone 1 introduces the plugin's first business tables (Architecture
Plan §7.1). Milestone 0 persisted only framework state.

## Options

| Option | Owner | Notes |
|---|---|---|
| `mpcf_settings` | `MPCF\Settings` | Versioned settings array. M0's only key was `remove_data_on_uninstall` (default `false`). M1 raises the shape version to 2, adding `outbound_bridge_enabled` (default `true`) and `inbound_cancel_behavior`/`inbound_refund_behavior` (`cancel`\|`flag`, defaulting to `cancel` and `flag` respectively) for `Woo\StatusBridge`/`Woo\RefundObserver`. |
| `mpcf_db_version` | `MPCF\Infrastructure\Database\Migrator` | Applied schema version. M1 raises `TARGET` to `2`: step 1 creates the four tables below, step 2 adds the `order_unique` index on `mpcf_fulfillments (order_id, order_source)` that makes intake idempotency a database-enforced guarantee. |

## Tables

All four created by `Migrator` step 1, DDL in
`MPCF\Infrastructure\Database\Schema` (see `docs/ARCHITECTURE_PLAN.md` §7.1
for the frozen column/index specification):

| Table | Purpose |
|---|---|
| `mpcf_fulfillments` | Aggregate root: one row per fulfillment, its workflow state, assignee, and order snapshot fields. |
| `mpcf_fulfillment_items` | Line items per fulfillment, snapshotted (SKU, name) so picking lists and audit stay stable if a product is later renamed or deleted. |
| `mpcf_events` | Append-only (I5), hash-chained audit log — every state change, item tick, and bridge action. |
| `mpcf_notes` | Internal operator/lead notes per fulfillment. |

## Capabilities and roles

Granted on every activation. Removal follows the same all-or-nothing rule
as everything else (invariant I12): with `remove_data_on_uninstall`
disabled, uninstalling leaves every capability and role in place exactly
as reactivating would; enabling the flag removes them along with the
options and tables below.

| Capability | Purpose |
|---|---|
| `mpcf_view_queue` | View the fulfillment queue and detail pages. |
| `mpcf_process_fulfillments` | Pick, pack, and advance workflow state. |
| `mpcf_manage_shipments` | Create/edit shipments and tracking. |
| `mpcf_add_notes` | Add internal fulfillment notes. |
| `mpcf_capture_photos` | Upload package photos. |
| `mpcf_render_documents` | Render packing slips and other documents. |
| `mpcf_cancel_fulfillment` | Cancel a fulfillment. |
| `mpcf_view_audit` | View the fulfillment audit trail. |
| `mpcf_view_analytics` | View aggregate analytics. |
| `mpcf_view_operator_stats` | View per-operator analytics (off by default in settings — D17). |
| `mpcf_manage_settings` | Change plugin settings. |

Custom roles created on activation: `mpcf_warehouse_operator` (queue/
process/shipments/notes/photos/documents) and `mpcf_warehouse_lead` (every
`mpcf_*` capability). `administrator` and `shop_manager` also receive every
`mpcf_*` capability. No capability is ever named directly in a permission
check outside `MPCF\Capabilities`.

## Directories

None created in M0. Milestone 5 introduces the protected photo store under
`wp-content/uploads/mpcf/`.

## Uninstall policy

All-or-nothing (invariant I12), default **keep everything**. With
`remove_data_on_uninstall` disabled, `uninstall.php` is a no-op. Enabled, it
removes: `mpcf_settings`, `mpcf_db_version`, all four tables above, the
`mpcf_warehouse_operator` and `mpcf_warehouse_lead` roles, and every
`mpcf_*` capability from every role that holds it.
