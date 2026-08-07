# Architecture freeze inventory

**Status: DRAFT** — prepared during M10 (`v0.10.0`). Becomes **binding at `v1.0.0`**. Until then, treat as intent documentation; additive changes may still ship in `0.x` with PO approval.

**Purpose:** Declare what public surfaces freeze at 1.0 vs what may evolve additively vs what remains internal.

---

## Legend

| Class | Meaning at 1.0 |
|---|---|
| **FROZEN AT 1.0** | Semantics and shapes fixed for `1.x`; breaking change → `2.0` + ADR |
| **MAY EVOLVE ADDITIVELY** | Append-only within `1.x` — new fields, routes, hooks, tables/columns via migrator |
| **INTERNAL** | Not a public contract; may change any release |

---

## Core principles (FROZEN AT 1.0)

| Principle | Reference |
|---|---|
| Operator-first (P0) | Architecture Plan §2.1 |
| WooCommerce as adapter (`src/Woo/` only) | I8, ADR pattern |
| Engine-first / deterministic domain | §4, I6 |
| Single fulfillment state writer (`WorkflowService`) | I4 |
| Append-only hash-chained audit | I5 |
| HPOS mandatory | I2 |
| No inventory/receiving ownership | **ADR-0007** |
| Admin UI + REST share Application services | I11 |
| Deactivation removes nothing; uninstall all-or-nothing | I12 |

---

## ADR-0007 boundary (FROZEN AT 1.0)

| Owner | Domain |
|---|---|
| **MPCF** | Outbound fulfillment, picking, packing, shipments, documents, audit, queue partitioning |
| **wc-inventory-overview** | Inbound inventory, stock ledger, locations, receiving |
| **WooCommerce** | Catalog, orders, checkout, customer record |

No cross-plugin table access. Integration via documented hooks/API only.

---

## Aggregates & workflow (FROZEN AT 1.0 semantics)

| Aggregate | States / notes |
|---|---|
| `Fulfillment` | Standard workflow states; optimistic `version`; single writer |
| `Shipment` / `Package` | ADR-0005; shipment status ≠ fulfillment state |
| `Wave` | M8 lifecycle; ends at `picked` |
| `Document` record | Append render rows; reprint lineage via events |
| `Media` | Package photos; soft-delete + retention purge |

**MAY EVOLVE ADDITIVELY:** new workflow definitions via `mpcf_workflows` filter (post-1.0 registration pattern documented §16.2); new guards as data.

---

## REST `mpcf/v1` (FROZEN AT 1.0 shapes)

Additive-only from `v0.2.0` tag. Stable error codes: `mpcf_forbidden`, `mpcf_not_found`, `mpcf_invalid_payload`, `mpcf_version_conflict`, `mpcf_guard_rejected`.

| Area | Routes (representative) | Class |
|---|---|---|
| Fulfillments | CRUD-ish queue/detail/transitions/items/scan/notes/assignment | FROZEN shapes |
| Shipments/Packages | CRUD, ship | FROZEN shapes |
| Documents | render, content, reprint | FROZEN shapes |
| Photos | upload, list, delete | FROZEN shapes |
| Waves | M8 wave API | FROZEN shapes |
| Analytics | read-only overview/reports/diagnostics | FROZEN shapes (M9) |
| Carriers | GET registry | FROZEN shapes |

**M10:** no new REST diagnostics — CLI-first (`docs/API.md` note).

**MAY EVOLVE ADDITIVELY:** new routes, optional response fields, new query params.

---

## Events (FROZEN AT 1.0 type semantics)

Append-only `mpcf_events`. Payload contracts for shipped types are stable within `1.x`.

| Family | Examples | Class |
|---|---|---|
| Workflow | `fulfillment.state_changed`, `items.picked/packed` | FROZEN |
| Shipping | `shipment.*`, `package.created` | FROZEN |
| Documents | `document.rendered`, `document.reprinted` | FROZEN |
| Notifications | `notification.sent/failed/suppressed` | FROZEN |
| Scan | `scan.item_picked/packed/corrected` | FROZEN |
| Wave | `wave.*`, global lifecycle | FROZEN |
| Maintenance | `maintenance.repair.*` (M10) | MAY EVOLVE ADDITIVELY |

**INTERNAL:** hash computation, canonicalization helpers.

---

## Hooks (see `docs/HOOKS.md`)

| Hook | Class |
|---|---|
| `mpcf_event` + per-type actions | FROZEN AT 1.0 |
| `mpcf_workspace_flags` | FROZEN AT 1.0 |
| `mpcf_document_types`, `mpcf_document_template`, `mpcf_document_model` | FROZEN AT 1.0 |
| `mpcf_carriers` | FROZEN AT 1.0 |
| `mpcf_intake_should_create`, `mpcf_workflows` | FROZEN AT 1.0 (when registered) |
| `wp_privacy_personal_data_*` (MPCF registrars) | FROZEN AT 1.0 behavior |
| `site_status_tests` (`mpcf_operational`) | INTERNAL adapter |

**MAY EVOLVE ADDITIVELY:** new filters/actions documented in `HOOKS.md`.

---

## Documents / photos / notifications / scans / waves / analytics

| Subsystem | Public surface | Class |
|---|---|---|
| Documents | REST + templates + `mpcf_document_*` hooks | FROZEN |
| Photos | REST + settings caps + retention scheduler | FROZEN |
| Notifications | Settings + WC email extension hook (WC-owned) | FROZEN strategy enum |
| Scan mode | REST `POST …/scan` | FROZEN |
| Waves | REST `/waves…` | FROZEN |
| Analytics | REST read-only + CSV + CLI backfill/rebuild | FROZEN DTO semantics |

**INTERNAL:** template PHP paths, rollup calculators, scheduler locks.

---

## Settings (`mpcf_settings`)

| Class | Detail |
|---|---|
| MAY EVOLVE ADDITIVELY | New keys with defaults; shape version bumps |
| FROZEN AT 1.0 | Meaning of existing keys (bridge behaviors, notification strategy enum, photo limits) |

---

## Capabilities (`mpcf_*`)

| Class | Detail |
|---|---|
| FROZEN AT 1.0 | Existing capability slugs and role bundles |
| MAY EVOLVE ADDITIVELY | New caps for new features (1.x) |

All checks via `MPCF\Capabilities` — never hardcoded role names in business code.

---

## Privacy (FROZEN AT 1.0 behavior)

| Tool | Behavior |
|---|---|
| Exporter `mpcf-fulfillment-data` | Email-keyed; metadata not binaries |
| Eraser | Anonymize snapshots/notes/photos; retain chain + order links |
| WC sympathy | Order anonymization → MPCF erase |

See `docs/ops/privacy.md`.

---

## Schema (`mpcf_db_version`)

| Class | Detail |
|---|---|
| FROZEN AT 1.0 | Semantics of columns in shipped tables (§7.1) |
| MAY EVOLVE ADDITIVELY | New tables/columns/indexes via migrator steps; idempotent |

**M10:** TARGET **8** unchanged. No step 9 in v0.10.0.

---

## CLI (MAY EVOLVE ADDITIVELY)

| Commands | Class |
|---|---|
| `wp mpcf doctor`, `validate`, `repair`, `audit verify` | Additive flags/subcommands OK |
| `wp mpcf analytics …`, intake, existing ops | Additive |

**INTERNAL:** checker id strings may gain new ids; JSON report shape additive.

---

## Extension policy (FROZEN AT 1.0)

1. Integrate via documented hooks and REST — **no private backdoors**.
2. Duplicate business state in another plugin is prohibited.
3. Moving ownership across ADR-0007 boundary requires Accepted ADR in **every** affected repo.
4. Breaking REST/hook/schema semantics → major version + migration ADR.

---

## Explicitly INTERNAL (may change any release)

- Admin screen markup/CSS (except documented hook outputs)
- CheckerRegistry implementation details
- SQL in `Infrastructure/Database/`
- MPDS vendored copy paths
- Browser/Playwright harness (ADR-0006, not shipped)
- Performance seed scripts

---

## 1.0 milestone gate

At **`1.0.0` tag**, this document status changes from **DRAFT** to **ACTIVE**. Any item marked FROZEN AT 1.0 requires ADR + major bump to alter semantics.

M10 delivers this draft; **does not** ship `1.0.0`.
