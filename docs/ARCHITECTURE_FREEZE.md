# Architecture freeze inventory

**Status: DRAFT — structure finalized for v1.0 approval**  
Prepared during M10 (`v0.10.0`); structure completed under the **v1.0 Architecture Freeze & Production Readiness** program (`docs/plans/V1_PRODUCTION_READINESS.md`).

**Becomes ACTIVE at tag `v1.0.0` (program phase P4 — Release).** Until then this document is the approval inventory: classify every public contract, gather evidence, and gate production. Additive `0.x` changes require Product Owner approval and an update to this inventory before freeze activation. Production deploy of the published ZIP is program phase **P5**.

**Program note:** This is **not** M11 and **not** a feature milestone. Runtime implementation for freeze activation is limited to the administrative `v1.0.0` release after certification evidence (P1–P3) is complete.

---

## Legend

| Class | Meaning at / after 1.0 |
|---|---|
| **FROZEN** | Semantics and shapes fixed for `1.x`; breaking change → `2.0` + Accepted ADR |
| **MAY EVOLVE ADDITIVELY** | Append-only within `1.x` — new fields, routes, hooks, tables/columns via migrator, new checker ids |
| **INTERNAL** | Not a public contract; may change in any `1.x` patch/minor without major bump |

At freeze activation, every public surface below must have an explicit **FROZEN** or **INTERNAL** (or additive) classification. Ambiguity is a P1 exit blocker.

---

## Versioning policy (FROZEN at 1.0)

| Rule | Detail |
|---|---|
| SemVer for the plugin | `MAJOR.MINOR.PATCH` in header / `MPCF_VERSION` / `readme.txt` Stable tag (triad parity) |
| `1.x` line | No breaking changes to FROZEN surfaces |
| Breaking change | Requires `2.0.0` + Accepted ADR in this repo (and every affected sibling if ADR-0007 boundary moves) |
| Schema | Additive migrator steps only within `1.x`; never rewrite history of shipped columns' semantics |
| Tags | Annotated `v*` tags drive GitHub Release ZIP; never retag a published version |

---

## Backward compatibility policy (FROZEN at 1.0)

| Rule | Detail |
|---|---|
| Upgrade path | `0.10.0` → `1.0.0` and any `1.x` → later `1.y` must activate without data loss for operational tables |
| Rollback | Plugin ZIP rollback restores **code behavior**, not anonymized/deleted content; schema is never downgraded automatically (`docs/ops/ROLLBACK.md`) |
| Clients | REST clients, WP-CLI scripts, and hook consumers relying on FROZEN shapes must keep working across `1.x` |
| Defaults | New settings keys MUST ship with safe defaults so upgrades do not require immediate operator action |

---

## Deprecation policy (FROZEN at 1.0)

| Rule | Detail |
|---|---|
| Within `1.x` | FROZEN surfaces are **not** removed or reshaped |
| Soft deprecation | MAY mark additive successors as preferred in docs; old FROZEN path remains until `2.0` |
| Communication | Deprecations recorded in `CHANGELOG` / release notes and this inventory |
| Hard removal | Only in `2.0` with migration ADR and upgrade notes |

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
| **MPCF** | Outbound fulfillment, picking, packing, shipments, documents, photos, waves, analytics of fulfillment ops, audit, queue |
| **wc-inventory-overview** | Inbound inventory, stock ledger, locations, receiving |
| **WooCommerce** | Catalog, orders, checkout, customer record |

No cross-plugin table access. Integration via documented hooks/API only.

---

## Aggregates & workflow (FROZEN semantics)

| Aggregate | Notes | Class |
|---|---|---|
| `Fulfillment` | Standard workflow states; optimistic `version`; single writer | FROZEN |
| `Shipment` / `Package` | ADR-0005; shipment status ≠ fulfillment state | FROZEN |
| `Wave` | M8 lifecycle; ends at `picked` | FROZEN |
| `Document` record | Append render rows; reprint lineage via events | FROZEN |
| `Media` (photos) | Soft-delete + retention purge | FROZEN |

**MAY EVOLVE ADDITIVELY:** new workflow definitions via `mpcf_workflows`; new guards as data.

---

## REST `mpcf/v1` (FROZEN shapes)

Additive-only from the `v0.2.0` tag. Stable error codes: `mpcf_forbidden`, `mpcf_not_found`, `mpcf_invalid_payload`, `mpcf_version_conflict`, `mpcf_guard_rejected`.

| Area | Surface | Class |
|---|---|---|
| Fulfillments | Queue/detail/transitions/items/scan/notes/assignment | FROZEN |
| Shipments / packages | CRUD, ship | FROZEN |
| Documents | render, content, reprint | FROZEN |
| Photos | upload, list, delete | FROZEN |
| Waves | Wave API | FROZEN |
| Analytics | Read-only overview/reports/diagnostics | FROZEN |
| Carriers | GET registry | FROZEN |
| Diagnostics | **No** REST diagnostics routes (CLI / Site Health only) | FROZEN policy |

**MAY EVOLVE ADDITIVELY:** new routes, optional response fields, new query params.  
**Authoritative list:** `docs/API.md`.

---

## CLI (classification)

| Command family | Class |
|---|---|
| `wp mpcf doctor` | MAY EVOLVE ADDITIVELY (flags, checker ids, JSON fields) |
| `wp mpcf validate <target>` | MAY EVOLVE ADDITIVELY (new targets additive) |
| `wp mpcf repair <target> [--yes]` | MAY EVOLVE ADDITIVELY; **FROZEN policy:** dry-run default, bounded targets, no “fix everything” |
| `wp mpcf audit verify` | MAY EVOLVE ADDITIVELY |
| `wp mpcf analytics …`, intake | MAY EVOLVE ADDITIVELY |

**INTERNAL:** checker implementation classes; SQL in diagnostics readers.

---

## Hooks & filters (see `docs/HOOKS.md`)

| Hook / filter | Class |
|---|---|
| `mpcf_event` + per-type actions | FROZEN |
| `mpcf_workspace_flags` | FROZEN |
| `mpcf_document_types`, `mpcf_document_template`, `mpcf_document_model` | FROZEN |
| `mpcf_carriers` | FROZEN |
| `mpcf_intake_should_create`, `mpcf_workflows` | FROZEN (when registered) |
| WP privacy exporter/eraser registration behavior | FROZEN |
| `site_status_tests` (`mpcf_operational`) | INTERNAL adapter (WordPress core surface) |

**MAY EVOLVE ADDITIVELY:** new documented filters/actions in `HOOKS.md`.

---

## Domain events (`mpcf_events`)

Append-only hash-chained log. Payload contracts for shipped types are stable within `1.x`.

| Family | Examples | Class |
|---|---|---|
| Workflow | `fulfillment.state_changed`, `items.picked` / `packed` | FROZEN |
| Shipping | `shipment.*`, `package.created` | FROZEN |
| Documents | `document.rendered`, `document.reprinted` | FROZEN |
| Notifications | `notification.sent` / `failed` / `suppressed` | FROZEN |
| Scan | `scan.item_picked` / `packed` / `corrected` | FROZEN |
| Wave | `wave.*` | FROZEN |
| Maintenance | `maintenance.repair.*` | MAY EVOLVE ADDITIVELY |

**INTERNAL:** hash computation, canonicalization helpers, `PayloadGuard` implementation details.

**FROZEN behavior:** privacy eraser must not rewrite `payload` / `hash` / `prev_hash`.

---

## Database / schema (`mpcf_db_version`)

| Item | Class |
|---|---|
| Semantics of columns in tables shipped through migrator TARGET **8** | FROZEN |
| New tables/columns/indexes via new migrator steps | MAY EVOLVE ADDITIVELY |
| Automatic schema downgrade on plugin rollback | **Forbidden** (ops policy) |

Baseline at freeze planning: **TARGET 8** (`v0.10.0`). Any TARGET bump before `v1.0.0` requires PO approval and inventory update.

Authoritative DDL: `docs/PERSISTED_DATA.md` + `Schema` / `Migrator`.

---

## Capabilities (`mpcf_*`)

| Item | Class |
|---|---|
| Existing capability slugs and role bundles (`operator` / `lead` / admin grants) | FROZEN |
| New capabilities for new `1.x` features | MAY EVOLVE ADDITIVELY |

All checks via `MPCF\Capabilities` — never hardcoded role names in business logic.

---

## Settings (`mpcf_settings`)

| Item | Class |
|---|---|
| Meaning of existing keys (bridge, notification strategy, photo limits, wave limits, branding, …) | FROZEN |
| New keys with defaults; settings shape version bumps | MAY EVOLVE ADDITIVELY |

---

## Pipelines (public contracts)

| Pipeline | Public surface | Class |
|---|---|---|
| **Documents** | REST + templates + `mpcf_document_*` hooks | FROZEN |
| **Notifications** | Settings strategy enum + WC email extension hook (WC-owned delivery) | FROZEN strategy |
| **Photos** | REST + caps + retention AS hook `mpcf_purge_photo_retention` | FROZEN |
| **Wave** | REST `/waves…` + walk document type | FROZEN |
| **Analytics** | REST read-only + CSV + CLI backfill/rebuild + AS `mpcf_analytics_daily_rollup` | FROZEN DTO semantics |
| **Scan** | REST `POST …/scan` | FROZEN |

**INTERNAL:** template PHP markup, rollup calculators, AS lock transients, protected-store path layout internals (relative-path rule remains FROZEN via ADR-0004).

---

## Extension points (FROZEN policy)

1. Integrate via documented hooks and REST — **no private backdoors**.
2. Duplicate business state in another plugin is prohibited.
3. Moving ownership across ADR-0007 requires Accepted ADR in **every** affected repo.
4. Breaking REST/hook/schema semantics → major version + migration ADR.
5. Release ZIP must remain installable without Node/Composer on the merchant host (ADR-0006).

---

## Privacy (FROZEN behavior)

| Tool | Behavior |
|---|---|
| Exporter `mpcf-fulfillment-data` | Email-keyed; metadata not binaries |
| Eraser | Anonymize snapshots/notes/photos; retain chain + order links |
| WC sympathy | Order anonymization → MPCF erase |

See `docs/ops/privacy.md`.

---

## Explicitly INTERNAL (may change any `1.x` release)

- Admin screen markup/CSS (except documented hook outputs)
- `CheckerRegistry` / checker class structure
- SQL in `Infrastructure/Database/`
- MPDS vendored copy paths
- Browser/Playwright harness (not shipped)
- Performance seed scripts
- Site Health HTML presentation (check semantics feed from shared registry)

---

## Freeze activation gate (`v1.0.0`)

At **`v1.0.0` tag** (program phase **P4** — Release Candidate Approval & Release):

1. This document status changes **DRAFT → ACTIVE**.
2. Certification evidence from `docs/plans/V1_PRODUCTION_READINESS.md` phases **P1–P3** is complete and PO-approved.
3. No open FROZEN-surface ambiguities remain.
4. Production deploy (**P5**) uses **only** the published GitHub Release ZIP for `v1.0.0`.

**M10 delivered the draft.** The v1.0 program finalizes classification and evidence. **`v1.0.0` is an administrative release** — no feature work.

---

## Governance when ACTIVE

Once this document is **ACTIVE**:

Any change that modifies a **FROZEN** contract requires:

1. an Architecture Decision Record (**ADR**),
2. an explicit **compatibility assessment**, and
3. **Product Owner approval**.

All **`v1.x`** development must remain **backward compatible** unless a future **`v2.0`** roadmap explicitly supersedes this policy.

Additive evolution (**MAY EVOLVE ADDITIVELY**) still requires documentation updates to this inventory and the relevant public docs (`API.md`, `HOOKS.md`, etc.) in the same release.
