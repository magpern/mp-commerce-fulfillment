# Persisted data inventory

Machine-readable source of truth: `src/PersistedKeys.php`. This document is
its human-readable mirror, kept in sync by `PersistedKeysInventoryTest`
(fails if this file and the class disagree) and bound to `uninstall.php` by
`UninstallPolicyGuardTest` (fails if uninstall does not remove exactly this
inventory when `remove_data_on_uninstall` is enabled).

Milestone 1 introduced the plugin's first business tables (Architecture
Plan §7.1); Milestone 0 persisted only framework state. Milestone 2 adds
four more tables (the shipping model, D19/ADR-0005, and the document
generation record, §10).

## Options

| Option | Owner | Notes |
|---|---|---|
| `mpcf_settings` | `MPCF\Settings` | Versioned settings array. M0's only key was `remove_data_on_uninstall` (default `false`). M1 raised the shape version to 3: v2 added `outbound_bridge_enabled` (default `true`) and `inbound_cancel_behavior`/`inbound_refund_behavior` (`cancel`\|`flag`, defaulting to `cancel` and `flag` respectively) for the **store-order bridge** (`Woo\StatusBridge`/`Woo\RefundObserver` — not supplier receiving; ADR-0007); v3 added `operator_mode_enabled` (default `false`) for `Admin\OperatorMode`. M2 raises the shape version to 4 (F21): `auto_advance_after_ship` (default `false`) and `default_carrier_id` (default `''`, no whitelist — any string is a valid carrier id) for the Packing Workspace, and `require_tracking_before_ship` (default `false`) for `Engine\Guard\HasTrackingGuard` via `Application\TransitionContextFactory`. |
| `mpcf_db_version` | `MPCF\Infrastructure\Database\Migrator` | Applied schema version. M1 raised `TARGET` to `3`: step 1 creates the first four tables below, step 2 adds the `order_unique` index on `mpcf_fulfillments (order_id, order_source)` that makes intake idempotency a database-enforced guarantee, step 3 adds `customer_name_snapshot` (on `mpcf_fulfillments`) and `sku_snapshot` (on `mpcf_fulfillment_items`) indexes that `SearchQuery` v1 (D15) needs to keep its Queue-search lookups indexed. M2 raises `TARGET` to `5`: step 4 creates the three shipping tables, step 5 creates `mpcf_documents`. |

## Tables

DDL in `MPCF\Infrastructure\Database\Schema` (see `docs/ARCHITECTURE_PLAN.md`
§7.1 for the frozen column/index specification):

| Table | Migrator step | Purpose |
|---|---|---|
| `mpcf_fulfillments` | 1 | Aggregate root: one row per fulfillment, its workflow state, assignee, and order snapshot fields. |
| `mpcf_fulfillment_items` | 1 | Line items per fulfillment, snapshotted (SKU, name) so picking lists and audit stay stable if a product is later renamed or deleted. |
| `mpcf_events` | 1 | Append-only (I5), hash-chained audit log — every state change, item tick, and bridge action. |
| `mpcf_notes` | 1 | Internal operator/lead notes per fulfillment. |
| `mpcf_shipments` | 4 | The consignment (one carrier handover) — carrier, tracking, status, timestamps. |
| `mpcf_packages` | 4 | Physical boxes within a shipment (ADR-0005/D19) — weight, dimensions, colli tracking, a reserved `label_path` (NULL until M12). |
| `mpcf_package_items` | 4 | Per-package line-quantity allocations. Milestone 2 always allocates every packed line to package 1 (PO decision, §IV.0.2); the shape already supports M4's line-allocation split. |
| `mpcf_documents` | 5 | Document generation record (§10) — one row per fresh render. M4-B stores relative `file_path` under the protected upload root for packing_slip and picking_list; integrity (`mime`, `bytes`, `sha256`) lives in the `document.rendered` event payload (no schema bump). Indexes: `fulfillment_id`, `doc_type`. Composite `(fulfillment_id, doc_type, created_at)` and `source_document_id` deferred to M4-D. |

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

## Scheduled actions

| Action Scheduler group | Actions filed under it | Owner |
|---|---|---|
| `mpcf` | `mpcf_process_intake` (the Action Scheduler fallback path `Woo\IntakeHooks` uses when synchronous intake can't complete inline) | `Woo\IntakeHooks` |

Action Scheduler's own tables belong to the required order platform and are
never dropped or altered by this plugin — only the rows this plugin filed
under the `mpcf` group are ever touched, and only on uninstall with
`remove_data_on_uninstall` enabled (see below).

## User meta

None in Milestone 1. The architecture reserves `mpcf_ui_prefs` for saved
Queue filter views (Architecture Plan §9.3), but that feature was never
built — D15 shipped the Queue without it, deliberately, to keep the
milestone minimal. `PersistedKeys::user_meta_keys()` returns an empty array
for exactly this reason; the moment a future milestone writes real
user-meta, extending that list is the only change `uninstall.php` needs.

## Directories

Protected upload root under `wp-content/uploads/mpcf/` (ADR-0004). M4-B
stores canonical document HTML under `mpcf/documents/{yyyy}/{mm}/{fulfillment_id}/`.
Inventoried as `PersistedKeys::upload_directories()` → `mpcf`. Removed on
uninstall when `remove_data_on_uninstall` is enabled.

## Uninstall policy

All-or-nothing (invariant I12), default **keep everything**. With
`remove_data_on_uninstall` disabled, `uninstall.php` is a no-op: every
option, table, role, capability, scheduled action and user preference
survives. Enabled, it removes, in this order: every scheduled action under
the `mpcf` Action Scheduler group (via `as_unschedule_all_actions()`, a
safe no-op if WooCommerce/Action Scheduler is no longer active — invariant
I10), the `mpcf_warehouse_operator` and `mpcf_warehouse_lead` roles and
every `mpcf_*` capability from every role that holds it, every table
above, `mpcf_settings` and `mpcf_db_version`, and any user-meta key in
`PersistedKeys::user_meta_keys()` (currently none — see above). Every step
is safe to run more than once: `DROP TABLE IF EXISTS`, `delete_option()` on
an already-missing option, and unscheduling an already-empty group are all
no-ops the second time.
