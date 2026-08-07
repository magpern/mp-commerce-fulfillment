# Architecture freeze inventory

**Status: ACTIVE**  
**Approved:** Phase **P1 — Architecture Freeze Approval** (2026-08-07)  
**Baseline release:** `v0.10.0` contracts; canonical production artifact **`v1.0.0`** (P4, 2026-08-07) — administrative version bump only (see `docs/V1_RELEASE_REPORT.md`)  
**Program:** `docs/plans/V1_PRODUCTION_READINESS.md`

**P1 record:** Architecture Freeze approved during Phase P1. Every FROZEN surface below was verified to exist in released code. No runtime changes were required. Surfaces named in architecture prose but **not implemented** are listed under **Deferred (not frozen)** — they were **not invented**.

**Program note:** This is **not** M11 and **not** a feature milestone. Administrative `v1.0.0` (P4) is **published**. Pre-production acceptance (P5) on `dev.biopentra.eu` is **COMPLETE**. Production deploy of that exact ZIP to `www.biopentra.eu` is **P6** (not started; PO GO required). Freeze remains **ACTIVE** for the `1.x` line.

---

## Legend

| Class | Meaning |
|---|---|
| **FROZEN** | Semantics and shapes fixed for `1.x`; breaking change → `2.0` + Accepted ADR + PO approval |
| **MAY EVOLVE ADDITIVELY** | Append-only within `1.x` — new fields, routes, hooks, tables/columns via migrator, new checker ids |
| **INTERNAL** | Not a public contract; may change in any `1.x` patch/minor without major bump |
| **DEFERRED** | Documented intent only — **not shipped**; must not be treated as a public contract until implemented and reclassified |

---

## Versioning policy (FROZEN)

| Rule | Detail |
|---|---|
| SemVer | `MAJOR.MINOR.PATCH` in header / `MPCF_VERSION` / `readme.txt` Stable tag (triad parity) |
| `1.x` line | No breaking changes to FROZEN surfaces |
| Breaking change | Requires `2.0.0` + Accepted ADR (+ sibling ADRs if ADR-0007 boundary moves) + PO approval |
| Schema | Additive migrator steps only within `1.x`; never rewrite shipped column semantics |
| Tags | Annotated `v*` tags drive GitHub Release ZIP; never retag a published version |

---

## Backward compatibility policy (FROZEN)

| Rule | Detail |
|---|---|
| Upgrade | `0.10.0` → `1.0.0` and any `1.x` → later `1.y` activate without operational data loss |
| Rollback | ZIP rollback restores **code behavior**, not anonymized/deleted content; **no** automatic schema downgrade |
| Clients | REST / WP-CLI / shipped hook consumers of FROZEN shapes keep working across `1.x` |
| Defaults | New settings keys MUST ship with safe defaults |

---

## Deprecation policy (FROZEN)

| Rule | Detail |
|---|---|
| Within `1.x` | FROZEN surfaces are **not** removed or reshaped |
| Soft deprecation | Successors may be preferred in docs; old FROZEN path remains until `2.0` |
| Communication | Recorded in release notes + this inventory |
| Hard removal | Only in `2.0` with migration ADR |

---

## Governance when ACTIVE

Any change that modifies a **FROZEN** contract requires:

1. an Architecture Decision Record (**ADR**),
2. an explicit **compatibility assessment**, and
3. **Product Owner approval**.

All **`v1.x`** development must remain **backward compatible** unless a future **`v2.0`** roadmap explicitly supersedes this policy.

Additive evolution (**MAY EVOLVE ADDITIVELY**) still requires documentation updates to this inventory and relevant public docs (`API.md`, `HOOKS.md`, etc.) in the same release.

---

## Core principles (FROZEN)

| Principle | Reference |
|---|---|
| Operator-first (P0) | Architecture Plan §2.1 |
| WooCommerce as adapter (`src/Woo/` only) | I8 |
| Engine-first / deterministic domain | §4, I6 |
| Single fulfillment state writer (`WorkflowService`) | I4 |
| Append-only hash-chained audit | I5 |
| HPOS mandatory | I2 |
| No inventory/receiving ownership | **ADR-0007** |
| Admin UI + REST share Application services | I11 |
| Deactivation removes nothing; uninstall all-or-nothing | I12 |
| Zero runtime Composer/Node dependency in release ZIP | ADR-0006 |

---

## ADR-0007 boundary (FROZEN)

| Owner | Domain |
|---|---|
| **MPCF** | Outbound fulfillment, picking, packing, shipments, documents, photos, waves, ops analytics, audit, queue |
| **wc-inventory-overview** | Inbound inventory, stock ledger, locations, receiving |
| **WooCommerce** | Catalog, orders, checkout, customer record |

No cross-plugin table access. Integration via documented hooks/API only.

---

## PHP public APIs

| Surface | Class | Notes |
|---|---|---|
| Third-party PHP class API (`Application\*`, `Domain\*`, repositories) | **INTERNAL** | Not a supported integrator contract; use REST / shipped hooks / CLI |
| Plugin bootstrap constants (`MPCF_VERSION`, paths) | **FROZEN** meaning | Version triad parity required |
| `MPCF\Capabilities` slug strings | **FROZEN** | See Capabilities |
| `MPCF\Settings` option key meanings | **FROZEN** | New keys additive |

---

## REST `mpcf/v1` (FROZEN shapes)

Verified present in `src/Api/Rest/*` and `docs/API.md`. Stable error codes: `mpcf_forbidden`, `mpcf_not_found`, `mpcf_invalid_payload`, `mpcf_version_conflict`, `mpcf_guard_rejected`.

| Area | Routes (summary) | Class |
|---|---|---|
| Fulfillments | GET/POST transitions, items, notes, assignment | FROZEN |
| Scan | `POST /fulfillments/{id}/scan` | FROZEN |
| Shipments / packages | CRUD + ship + notify + notification-status | FROZEN |
| Documents | render, list, content, reprint | FROZEN |
| Photos | list/upload/get/content/thumb/delete | FROZEN |
| Carriers | `GET /carriers` | FROZEN |
| Waves | create/list/get/members/lifecycle/walk/scan/documents | FROZEN |
| Analytics | overview, timeline, queue-ageing, waves, diagnostics, reports, export | FROZEN |
| Diagnostics REST | **None** (CLI / Site Health only) | FROZEN **policy** |

**MAY EVOLVE ADDITIVELY:** new routes, optional response fields, new query params.

---

## CLI (verified)

| Command | Class |
|---|---|
| `wp mpcf doctor` | MAY EVOLVE ADDITIVELY |
| `wp mpcf validate <target>` | MAY EVOLVE ADDITIVELY (`schema\|storage\|schedules\|consistency\|fulfillments\|waves\|analytics`) |
| `wp mpcf repair <target> [--yes]` | MAY EVOLVE ADDITIVELY; **FROZEN policy:** dry-run default; targets only `schedules\|storage-dirs\|schema\|capabilities`; no “fix everything” |
| `wp mpcf audit verify` | MAY EVOLVE ADDITIVELY |
| `wp mpcf analytics backfill\|rebuild` | MAY EVOLVE ADDITIVELY |
| `wp mpcf intake backfill` | MAY EVOLVE ADDITIVELY |

**INTERNAL:** checker class structure; diagnostics SQL.

---

## Hooks & filters (shipped only)

| Hook | Exists in code? | Class |
|---|---|---|
| `mpcf_workspace_flags` | Yes | FROZEN |
| `mpcf_document_types` | Yes | FROZEN |
| `mpcf_document_template` | Yes | FROZEN |
| `mpcf_document_model` | Yes | FROZEN |
| `mpcf_carriers` | Yes | FROZEN |
| `wp_privacy_personal_data_exporters` / `erasers` (MPCF ids) | Yes | FROZEN behavior |
| `woocommerce_privacy_remove_order_personal_data` (sympathy) | Yes | FROZEN behavior |
| `site_status_tests` (`mpcf_operational`) | Yes | INTERNAL adapter |
| AS: `mpcf_process_intake`, `mpcf_purge_photo_retention`, `mpcf_analytics_daily_rollup` | Yes | FROZEN hook names / group `mpcf` |

**MAY EVOLVE ADDITIVELY:** new documented filters/actions in `HOOKS.md`.

---

## Deferred (not frozen) — do not invent

Named in architecture prose / older drafts but **not registered** in `v0.10.0` code (`docs/HOOKS.md` § M9 explicitly):

| Surface | Status |
|---|---|
| `mpcf_event` + per-type WP actions (`mpcf_fulfillment_state_changed`, …) | **DEFERRED** — in-process `Application\EventDispatcher` only; no WP `do_action` bridge shipped |
| `mpcf_workflows` filter | **DEFERRED** |
| `mpcf_intake_should_create` filter | **DEFERRED** |

Implementing any deferred surface in `1.x` is **additive** (new public hook) and requires inventory update + docs; it is **not** a silent FROZEN assumption today.

---

## Domain / audit events (`mpcf_events` table)

Table semantics and append-only hash chain: **FROZEN**.  
Privacy eraser must not rewrite `payload` / `hash` / `prev_hash`: **FROZEN**.

Shipped event-type families (payload contracts stable within `1.x`):

| Family | Examples (verified in Application) | Class |
|---|---|---|
| Workflow | `fulfillment.created`, `fulfillment.state_changed`, `fulfillment.assigned`, `fulfillment.unassigned`, `items.picked`, `items.packed` | FROZEN |
| Shipping | `shipment.*`, `package.created` / `updated` / `deleted` | FROZEN |
| Documents | `document.rendered`, `document.reprinted` | FROZEN |
| Notifications | `notification.sent` / `failed` / `suppressed` | FROZEN |
| Photos | `photo.captured` / `deleted` / `purged` | FROZEN |
| Scan | `scan.item_picked` / `item_packed` / `corrected` | FROZEN |
| Wave | `wave.created`, `wave.member_*`, `wave.activated` / `paused` / `resumed` / `completed` / `abandoned` | FROZEN |
| Maintenance | `maintenance.repair.*` (M10) | MAY EVOLVE ADDITIVELY |

**INTERNAL:** hash computation, canonicalization, `PayloadGuard` internals.

---

## Database / migrations

| Item | Class |
|---|---|
| Migrator **TARGET 8** table/column semantics (`mpcf_fulfillments`, items, events, notes, shipments, packages, package_items, documents, media, waves, wave_members, analytics_daily) | FROZEN |
| New migrator steps / tables / columns / indexes | MAY EVOLVE ADDITIVELY |
| Automatic schema downgrade on ZIP rollback | **Forbidden** |

---

## Capabilities (FROZEN slugs)

Verified in `MPCF\Capabilities`:  
`mpcf_view_queue`, `mpcf_process_fulfillments`, `mpcf_manage_shipments`, `mpcf_add_notes`, `mpcf_capture_photos`, `mpcf_delete_photos`, `mpcf_render_documents`, `mpcf_cancel_fulfillment`, `mpcf_view_audit`, `mpcf_view_analytics`, `mpcf_view_operator_stats`, `mpcf_manage_settings`.

Roles: `mpcf_warehouse_operator`, `mpcf_warehouse_lead` (+ admin/shop_manager full grants).

**MAY EVOLVE ADDITIVELY:** new capability slugs for new features.

---

## Settings (`mpcf_settings`)

| Item | Class |
|---|---|
| Meaning of shipped keys (bridge, operator mode, documents branding, notification strategy + copy, photo limits/retention, wave limits, uninstall flag, …) — schema_version **9** | FROZEN |
| New keys with defaults | MAY EVOLVE ADDITIVELY |

---

## Pipelines (verified)

| Pipeline | Public surface | Class |
|---|---|---|
| Documents | REST + `mpcf_document_*` + templates | FROZEN |
| Notifications | Strategy enum + WC email path + notify REST | FROZEN strategy |
| Package photography | REST + caps + retention AS hook | FROZEN |
| Scan mode | REST scan (+ wave scan) | FROZEN |
| Wave picking | REST `/waves…` + `wave_picking_list` | FROZEN |
| Analytics | REST read-only + CSV + CLI + AS rollup | FROZEN DTO semantics |

**INTERNAL:** template markup, rollup calculators, AS lock transients, protected-store internals (relative-path rule remains via ADR-0004).

---

## Aggregates & workflow (FROZEN semantics)

| Aggregate | Class |
|---|---|
| `Fulfillment` (standard workflow, optimistic `version`, single writer) | FROZEN |
| `Shipment` / `Package` (ADR-0005) | FROZEN |
| `Wave` (ends at `picked`) | FROZEN |
| Document / Media records | FROZEN |

**MAY EVOLVE ADDITIVELY:** new workflow definitions **only after** `mpcf_workflows` (or successor) is implemented and documented — until then Standard workflow remains the sole shipped definition.

---

## Extension policy (FROZEN)

1. Integrate via **shipped** documented hooks and REST — no private backdoors.
2. Duplicate business state in another plugin is prohibited.
3. Moving ownership across ADR-0007 requires Accepted ADR in every affected repo.
4. Breaking REST/hook/schema semantics → major version + migration ADR + PO approval.
5. Release ZIP remains installable without Node/Composer on the merchant host (ADR-0006).

---

## Privacy (FROZEN behavior)

| Tool | Behavior |
|---|---|
| Exporter `mpcf-fulfillment-data` | Email-keyed; metadata not binaries |
| Eraser | Anonymize snapshots/notes/photos; retain chain + order links |
| WC sympathy | Order anonymization → MPCF erase |

---

## Explicitly INTERNAL

- Admin screen markup/CSS (except documented hook outputs)
- `CheckerRegistry` / checker implementations
- SQL in `Infrastructure/Database/`
- In-process `EventDispatcher` subscriber wiring
- MPDS vendored paths
- Browser/Playwright harness (not shipped)
- Performance seed scripts
- Site Health HTML presentation

---

## P1 approval checklist (complete)

| Surface family | Verified in `v0.10.0` code | Classification complete |
|---|---|---|
| REST | Yes | Yes |
| CLI | Yes | Yes |
| Hooks (shipped) | Yes | Yes |
| Deferred hooks | Confirmed absent | Deferred — not frozen |
| Domain/audit events | Yes | Yes |
| Schema TARGET 8 | Yes | Yes |
| Capabilities | Yes | Yes |
| Settings | Yes | Yes |
| Pipelines | Yes | Yes |
| Governance policies | Yes | Yes |

**Exit:** Freeze **ACTIVE**. No runtime implementation performed in P1.
