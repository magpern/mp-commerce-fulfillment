# Persisted data inventory

Machine-readable source of truth: `src/PersistedKeys.php`. This document is
its human-readable mirror, kept in sync by `PersistedKeysInventoryTest`
(fails if this file and the class disagree) and bound to `uninstall.php` by
`UninstallPolicyGuardTest` (fails if uninstall does not remove exactly this
inventory when `remove_data_on_uninstall` is enabled).

Milestone 0 persists only framework state — no fulfillment data exists yet.

## Options

| Option | Owner | Notes |
|---|---|---|
| `mpcf_settings` | `MPCF\Settings` | Versioned settings array; sole key in M0 is `remove_data_on_uninstall` (default `false`). |
| `mpcf_db_version` | `MPCF\Infrastructure\Database\Migrator` | Applied schema version. M0's `TARGET` is `0` (no business tables yet). |

## Tables

None yet. `MPCF\Infrastructure\Database\Schema::all_tables()` returns an
empty array in M0; Milestone 1 introduces `mpcf_fulfillments`,
`mpcf_fulfillment_items`, `mpcf_events` and `mpcf_notes` (see
`docs/ARCHITECTURE_PLAN.md` §7).

## Capabilities and roles

Granted on activation, removed on uninstall (both branches — capabilities
are not "data" in the retention sense; removing a capability this plugin
added is not a data-loss risk, so it happens regardless of
`remove_data_on_uninstall`, matching the sibling plugins' convention for
capabilities they introduced):

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
removes: `mpcf_settings`, `mpcf_db_version`, every table in
`Schema::all_tables()` (none yet), the `mpcf_warehouse_operator` and
`mpcf_warehouse_lead` roles, and every `mpcf_*` capability from every role
that holds it.
