# Commerce Fulfillment for WooCommerce — Architecture Specification

**Status:** **Architecture Freeze v1.0** — Architecture Plan Rev 2.1 and Milestone 0 Execution Plan Rev 1 approved by the Product Owner 2026-07-31 as the permanent architectural baseline for Commerce Fulfillment. M0–M3 are closed (`v0.0.1`, `v0.1.0`/`v0.1.1`, `v0.2.0`, `v0.3.0`). Mission Control Dashboard/Queue redesign remains deferred. Documents I (M4) is a **release candidate** on `feature/m4-documents` targeting `v0.4.0` (Part VI) — not tagged pending PO approval.
**Working name:** Commerce Fulfillment (commercial name TBD — internal identifiers are rename-proof and never churn).
**Internal identity (fixed, PO-approved 2026-07-31):** namespace `MPCF\`, prefix `mpcf_`, tables `{$wpdb->prefix}mpcf_*`, text domain `mp-commerce-fulfillment`, constants `MPCF_*`, capability prefix `mpcf_`.
**Repo (to create):** private GitHub `magpern/mp-commerce-fulfillment`, plus sibling `magpern/mp-admin-design-system` (PO-approved 2026-07-31).
**Version floors (PO-approved 2026-07-31):** PHP 8.1 / WP 6.5 / WC 8.2 as *minimum supported*, not primary dev target. CI matrix must cover the floor **and** current stable PHP/WP/WC. HPOS compatibility mandatory from the first installable release; direct access to legacy order post storage is forbidden (I2).

---

## Architecture Freeze (v1.0)

This document is the **authoritative architectural specification** for Commerce Fulfillment. The frozen baseline is Part I (Architecture Plan, Rev 2.1) plus Part II (Milestone 0 Execution Plan, Rev 1).

- **Future milestone plans must conform to this document.** Each opens with a reconciliation against this baseline and against what the previous milestone actually shipped.
- **Architectural changes require an ADR.** Any change to the invariants (§3), the decisions D1–D22 (§18), the layer and dependency rules (§5), data-model semantics (§7), the state-engine contract (§6), or the public extension surface (§16) is an architecture decision: the ADR is written and Accepted first, then this document is amended, then code changes — in that order.
- **Implementation may improve code quality but may not alter architectural decisions without explicit approval.** Naming, internal structure within a layer, test depth and performance work are implementation freedom; ownership boundaries, dependency direction, persistence shape and public contracts are not.

## Version history

| Version | Date | Summary |
|---|---|---|
| Rev 1 | 2026-07-31 | Original draft: full architecture (vision, invariants, layers, state engine, data model, MPDS, warehouse UI, subsystems, security) + milestone roadmap M0–M14. |
| Rev 2 | 2026-07-31 | Scalability review: Product Philosophy (§2.1) and MP Commerce ecosystem (§2.2); `Package` first-class under `Shipment` (D19, ADR-0005); single self-referential location hierarchy (D13); polymorphic assignment (D20); channel-based notification subsystem (D21); `SearchQuery` port (D22); explicit five-stage document pipeline (§10); Dashboard as operational workspace (§9.3); audit investigation direction (§13); event payload versioning (§6.4). |
| Rev 2.1 | 2026-07-31 | M0 reconciliation: I2 retitled "WooCommerce CRUD-only order access; HPOS compatibility mandatory" (intent unchanged); M0 re-scoped to migration framework only (schema v1 lands in M1) with MPDS `v0.1.0` carrying the extracted existing component set; HPOS declaration confirmed in the main plugin file (§5.4 authoritative); Part II (M0 execution plan) appended. |
| **Architecture Freeze v1.0** | 2026-07-31 | PO approved Rev 2.1 + M0 Execution Plan Rev 1 as the permanent baseline. Freeze, version-history and governance sections added; final consistency pass (guard-test references corrected §19.3→§19.1, layer-map notification naming aligned with D21, one illustrative undefined option key removed, internal §16 subsection references precised). |
| M1 Execution Plan Rev 1 | 2026-08-01 | Part III appended: Milestone 1 (Fulfillment core — Warehouse MVP) execution plan, reconciled against M0's actual shipped state. Three open scope questions resolved by explicit PO decision (MPDS component work in-scope as an early M1 phase; Queue drawer ships in M1 and opens Fulfillment Detail; Dashboard's picking-list quick action omitted, not stubbed). PO approved for implementation 2026-08-01. Part I/II baseline unchanged — no architectural decision altered. |
| M1 doc reconciliation | 2026-08-02 | §III.7 appended: actual outcomes of the D1–D19 commit sequence (schema reached version 3 via two additive index steps found during D9/D15, not only at the D21 proof; the two §19.1 guard tests missing since D6 added in D20; PO decisions from III.1/III.2 confirmed shipped as decided). No architectural decision altered — a documentation-only pass, per this document's own governance rule that implementation may not change architecture without an ADR. |
| M1 performance proof | 2026-08-02 | §III.7's D21 outcome updated with the actual 10k-row proof result: no full scan, no N+1, no non-scaling plan, no migration amendment required (`docs/QUEUE_PERFORMANCE_VALIDATION.md`). No architectural decision altered. |
| M1 released | 2026-08-02 | PO accepted M1 and its release-candidate verification; `mp-admin-design-system` tagged `v0.2.0` and `mp-commerce-fulfillment` tagged `v0.1.0`, both published and independently re-verified against the downloaded release assets (`docs/M1_RELEASE_REPORT.md`). |
| M2 Execution Plan (Part IV) | 2026-08-02 | Part IV appended: Milestone 2 (Packing Workspace & REST) execution plan, reconciled against M1's actual shipped state — reconciliation found one real M1 defect (the admin-side composition root wires a subscriber-less `EventDispatcher`, so admin-initiated transitions never reach `Woo\StatusBridge`) and three related findings in how transition eligibility is derived, all resolved by a single fix (§IV.3.B). Four PO decisions captured at approval: the dispatcher defect ships as its own `v0.1.1` patch before M2 feature work starts; multi-package "add package" ships in M2 without line-quantity allocation (M4); a minimal packing slip is pulled forward from M3 into M2; a dev/CI-only Playwright toolchain is added under new **ADR-0006**, which narrows ADR-0003's *Consequences* (shipped code stays framework-free and build-free) without altering its Decision. Two roadmap-sequencing amendments (§20's M2/M3 rows, §7.1's `mpcf_documents` milestone number) and ADR-0006 are the only document changes; no invariant, D-decision, layer rule, data-model semantic, engine contract or public-surface rule is altered. PO approved for implementation 2026-08-02. |
| Partial fulfillment future capability | 2026-08-03 | §24.1 appended: partial fulfillment & split shipments documented as a future capability (operator dogfooding, M2). No architectural decision altered — a documentation-only pass. |
| M3 Ops UX / Part V | 2026-08-04 | Roadmap sequencing amendment: M3 becomes Ops UX (Workspace next-action + Orders + dogfood stabilization) for `v0.3.0`; Documents I moves to M4; later milestones +1. Mission Control A/B/C deferred. Part V appended (execution summary). No invariant, D-decision, layer rule, data-model semantic, engine contract, or public-surface rule altered. |
| ADR-0007 inbound/outbound ownership | 2026-08-04 | ADR-0007 Accepted: inbound inventory domain assigned to `wc-inventory-overview`; MPCF outbound-only reaffirmed; D13 amended (location hierarchy removed from MPCF); §2.6 ownership registry added; M12 rewritten; §6.6 store-order bridge naming clarified. Documentation-only — no invariant, engine contract, schema, or public-surface change. |
| M8 Wave & Batch Picking plan (Part X) | 2026-08-06 | Part X appended: definitive M8 execution plan (Wave aggregate, combined walk document, Wave Scan Mode extending M7, Workspace, concurrency/security/performance). Operation Context deferred (documented only). IX.21 updated to M7 closed/`v0.7.0`. Documentation-only — no runtime change; implementation requires PO approval of Part X. |

## Governance

- **ADR-gated change.** Deviations from this document require an Accepted ADR (`docs/adr/`, Nygard format, house numbering) before any conforming change to document or code.
- **Milestone plans derive from this document.** They add execution detail (scope tables, sub-steps, sequenced commits, verification) and their scope tables are binding; they never introduce architecture. One approved milestone at a time (I14).
- **Implementation follows architecture, not vice versa.** A real contradiction or technical blocker discovered during implementation pauses the work and produces an ADR plus a document amendment — never a silent divergence.
- **Public APIs freeze at their release milestones.** Hooks and template contracts freeze as they ship (tracked in `docs/HOOKS.md`); REST `mpcf/v1` freezes additive-only at M2 (§16.2); the complete public surface freezes at 1.0 via the repo's `ARCHITECTURE_FREEZE.md` (§4). Within a major version, all public surfaces are append-only.

---

## Context

WooCommerce ends its responsibility at "the customer paid." Everything after that — who picks the order, how it is packed, which box, what it weighed, which carrier took it, what documents accompanied it, what the package looked like when it left, and who did each of those things — lives today in people's heads, paper, and a scatter of single-purpose plugins (packing-slip printers, tracking-number meta boxes, status-changer buttons). None of them own the *workflow*.

Commerce Fulfillment is a warehouse system that treats WooCommerce as its order source. WooCommerce remains authoritative for products, checkout, payment, customers and the order record. Commerce Fulfillment becomes authoritative for everything between "paid" and "delivered" (and, post-1.0, "returned"): warehouse workflow, picking, packing, shipping, tracking, fulfillment documents, fulfillment audit, and fulfillment analytics.

This is the fourth plugin in the house line (after Universal Multicurrency, Universal Geo Context, AI Multilingual) and deliberately inherits their proven conventions: thin main file, hand-wired composition root, WordPress-free domain core, explicit-SQL migrations, structural guard tests, executable-contract documentation, Docker-only tooling, one approved milestone at a time. It is also the trigger for extracting the admin design system (currently two prefix-swapped copies, `umc-ui-*` and `ugc-ui-*`) into a shared, versioned **MP Admin Design System**.

This document is the permanent architecture specification. On repo bootstrap (M0) it is copied into the repo as `docs/ARCHITECTURE_PLAN.md`; milestone-level execution plans derive from it, one at a time.

---

## 1. Executive summary

1. **The fulfillment is its own aggregate, not a decoration on the WooCommerce order.** A paid order is *ingested* into the plugin's own tables as one or more `Fulfillment` records with line items, a workflow state, shipments, photos, notes, documents and an append-only event log. The WC order is referenced by ID and read through an `OrderSource` port — never mutated except through a narrow, configurable status bridge. This is the same architectural cut Shopify made (Order vs FulfillmentOrder) and it is what makes multi-shipment, multi-warehouse, returns, mobile and analytics natural instead of bolted on.

2. **A generic, data-defined workflow engine drives all state.** States and transitions are *data* (a `WorkflowDefinition`), the engine is generic and WordPress-free. The standard forward workflow (Queued → Picking → Picked → Packing → Packed → Shipped → Delivered → Completed, plus exception states) is just the default definition. Custom workflows, simplified two-step workflows, and the future Returns workflow all reuse the same engine. Every transition is validated, capability-checked, audit-recorded and timestamped — which is also what makes analytics free.

3. **Layered, ports-and-adapters architecture.** `Domain` → `Engine` → `Application` are pure PHP (unit-testable without WordPress). `Infrastructure`, `Woo`, `Admin`, `Api`, `Documents` are adapters at the edge. Dependency direction is inward only, enforced by structural guard tests exactly as in the sibling plugins.

4. **REST-first interactivity.** The Packing Workspace (the heart of the product) is a REST-backed screen from day one: admin JavaScript talks to `mpcf/v1`, which talks to the same application services the future mobile/tablet interface and public API will use. There is no privileged admin side-channel — that single decision is what makes "mobile later" a UI project, not a re-architecture.

5. **MP Admin Design System extracted now.** A separate `mp-admin-design-system` repo (tokens, components CSS, behavior JS, `ComponentRenderer`, standalone page shell, markup-contract tests), consumed at build time via a mechanical vendoring script with namespace rewriting. Commerce Fulfillment is its first consumer; UMC/UGC retrofit at their own pace.

6. **Trust artifacts are first-class.** Append-only, hash-chained audit events; SHA-256-fingerprinted package photos stored outside the media library behind a capability-checked endpoint; generated documents recorded with template versions. Together these answer the merchant's real question: *prove what left the warehouse, when, and who handled it.*

7. **Operator-first philosophy inside an MP Commerce ecosystem.** The product is designed for warehouse operators, not developers (§2.1 — speed, clarity, low cognitive load, auditability, minimal clicks, deterministic workflows; show exactly what the current step needs, never all of WooCommerce). It is the founding member of the MP Commerce plugin family (§2.2), sharing the design system and house conventions while remaining strictly runtime-independent.

8. **Roadmap:** M0 bootstrap + design-system extraction → M1 fulfillment core (usable queue + workflow) → M2 Packing Workspace + REST → M3 Documents I → M4 Tracking & notifications → M5 Package photography → M6 Barcode & scan mode → M7 Batch picking → M8 Analytics I → M9 hardening/RC → **1.0** → Returns, multi-warehouse, label-buying carrier integrations, automation/webhooks, mobile.

---

## 2. Product philosophy, vision and scope

### 2.1 Product philosophy (P0 — a permanent architectural principle)

Commerce Fulfillment is designed **first and foremost for warehouse operators, not developers** — and not primarily for store managers either. An operator processes hundreds of fulfillments a day, often standing, often with a scanner in one hand, frequently not a "WordPress person" at all. Every screen, endpoint and workflow decision therefore optimizes, in order, for:

1. **Speed** — fewest keystrokes/taps between "order paid" and "package on the pallet"; no full-page reloads inside a task; the primary action always one obvious button.
2. **Clarity** — the screen shows exactly the information and actions required for the *current fulfillment step*, nothing more. The goal is never to expose every WooCommerce capability; it is to hide everything that doesn't matter right now.
3. **Low cognitive load** — one visual language (MPDS), one interaction grammar (queue → workspace → advance), consistent placement of the same controls on every screen; progressive disclosure (leads get detail pages; operators get the workspace).
4. **Auditability** — doing the work *is* recording the work; operators never perform separate "logging" steps, and nothing they do is invisible.
5. **Minimal clicks** — a default-configured store packs a simple order with: open from queue → check items → enter tracking → one primary button. Every added click in a core path needs a reason written down.
6. **Deterministic workflows** — the same order type moves through the same states in the same sequence every time; predictability is a UX feature. Surprise is reserved for exception states, which are loud.

This philosophy is an architectural input, not marketing copy. When a future decision trades internal elegance, developer convenience, or feature completeness against operator speed and clarity, **the operator wins** — and design documents cite this section (P0) the way code cites invariants.

### 2.2 The MP Commerce ecosystem (architectural vision)

Commerce Fulfillment is not an isolated plugin: it is the founding member of a planned **MP Commerce** family of commercial WooCommerce plugins — MP Commerce Fulfillment (this document), MP Commerce Promotions (already in development as `mp-commerce-promotions`), and future candidates such as MP Commerce Inventory, MP Commerce Shipping, MP Commerce Returns, MP Commerce Analytics and MP Commerce CRM. What the family shares:

- **One visual language:** the MP Admin Design System (§8) — a merchant who learns one MP Commerce plugin's queue/workspace/timeline idioms has learned them all.
- **One architecture convention set:** thin main file, hand-wired composition root, WordPress-free core layers, ports-and-adapters edges, structural guard tests, explicit-SQL migrations, `PersistedKeys` inventories, executable-contract docs, per-plugin rename-proof prefixes.
- **One coding convention set:** the shared phpcs ruleset, naming patterns, VO/DTO idioms, and CI shape already proven across UMC/UGC/AIM.
- **Strict plugin independence:** every MP Commerce plugin installs, functions and uninstalls entirely alone. **No runtime dependency between siblings, ever.** Integration happens only through documented public surfaces (hooks, REST, domain-event actions), discovered defensively at runtime — the proven precedent is UMC consuming Universal Geo Context through an adapter that degrades gracefully when the sibling is absent.
- **Boundary hygiene over time:** where a future sibling overlaps this plugin's roadmap (e.g., a standalone MP Commerce Returns vs the M10 returns milestone, or MP Commerce Shipping vs the M12 carrier integrations), the split is decided then, by ADR, along the natural seam: Fulfillment owns the *warehouse-side* of any process; a sibling owns the customer-/carrier-facing side and integrates through the same public events and REST API available to any third party. No sibling ever gets a private backdoor — that rule is what keeps the ecosystem honest.

This section binds branding, conventions and independence rules — not the roadmap. Ecosystem products beyond Fulfillment and Promotions are vision, not commitments. In the Biopentra stack, the deployed inventory sibling is **`wc-inventory-overview`**; ADR-0007 assigns it the complete inbound domain per §2.6.

### 2.3 Ownership boundary

| Concern | Owner | Notes |
|---|---|---|
| Products, stock quantity, prices | WooCommerce | MPCF reads product data (SKU, image, weight, dims) for display and documents; never writes it. Inbound stock mutation is performed only by `wc-inventory-overview` (ADR-0007). |
| Checkout, payment, refunds | WooCommerce | MPCF *reacts* to refunds/cancellations (exception states); never initiates them in v1 |
| Customers, addresses | WooCommerce | MPCF reads shipping address; address *corrections* before shipping are a post-1.0 candidate, and would write through WC CRUD |
| The order record and its statuses | WooCommerce | MPCF may advance WC status through one narrow, configurable bridge (§6.6) |
| Suppliers, purchase orders, goods receipts, receiving | **wc-inventory-overview** | Complete inbound purchasing and receiving domain (ADR-0007) |
| Inventory movements, stock ledger, inventory position, landed cost | **wc-inventory-overview** | Including weighted-average cost writes on receive |
| Warehouse location hierarchy, bins, shelves, aisles, item-to-location assignment | **wc-inventory-overview** | Inventory topology master data (ADR-0007) |
| Warehouse workflow state (outbound) | **Commerce Fulfillment** | `mpcf_fulfillments.state`, driven only by the workflow engine |
| Picking / packing progress | **Commerce Fulfillment** | per-line quantities in `mpcf_fulfillment_items` |
| Shipments, carriers, tracking | **Commerce Fulfillment** | multiple shipments per order from day one |
| Fulfillment documents | **Commerce Fulfillment** | packing slip, picking list, invoice, customs, return slip |
| Package photos | **Commerce Fulfillment** | protected storage, audit-fingerprinted |
| Fulfillment audit trail | **Commerce Fulfillment** | append-only `mpcf_events` |
| Internal warehouse notes | **Commerce Fulfillment** | separate from WC order notes (§14) |
| Fulfillment analytics | **Commerce Fulfillment** | derived from the event log |
| Operator workflow UX (Queue, Workspace, Orders) | **Commerce Fulfillment** | outbound warehouse execution screens |
| Per-warehouse queue partition (`warehouse_id`) | **Commerce Fulfillment** | execution routing, not the location registry |
| Pick-path hint at intake (`location_snapshot`) | **Commerce Fulfillment** | immutable snapshot on fulfillment items; not inventory authority |
| Returns / RMA | **Commerce Fulfillment** (post-1.0) | separate aggregate, same engine |

### 2.4 Personas

- **Warehouse operator** — picks and packs all day. Needs speed, big targets, keyboard/scanner flow, zero WordPress knowledge. Should never need to see the rest of wp-admin.
- **Warehouse lead / merchant** — configures workflow, carriers, documents; watches the queue and analytics; investigates problems via the audit trail.
- **Developer / integrator** — extends via documented hooks and the REST API; builds carrier adapters and automations.

### 2.5 Explicit non-goals (v1.x)

MPCF owns **outbound warehouse execution** only: fulfillment, picking, packing,
shipments, packages, tracking, fulfillment documents, operator workflow, and
the fulfillment audit trail.

MPCF does **not** own (all owned by **`wc-inventory-overview`**, ADR-0007):

- Suppliers, purchase orders, goods receipts, or receiving
- Inventory movements, stock ledger, or inventory position
- Landed cost or inbound cost ledger writes
- Inventory topology: warehouse location hierarchy, bins, shelves, aisles, or
  item-to-location assignment
- Inbound stock mutation of any kind

Additional non-goals:

- No stock/inventory management (quantity on hand stays WooCommerce's record;
  inbound mutation is wc-inventory-overview's responsibility).
- No rate shopping / checkout shipping-rate calculation (that is checkout territory; MPCF starts after payment).
- No purchase orders / supplier inbound logistics (wc-inventory-overview).
- No customer-facing "track your order" portal pages in early milestones (customer touch = notification emails with tracking links; a portal is a Future Opportunity).
- No non-WooCommerce order sources in v1 — but the `OrderSource` port exists from M1 so the assumption is architectural, not structural.

Cross-plugin integration with the inventory owner uses documented hooks or a
versioned read contract only — never direct table access (ADR-0007).

### 2.6 Domain ownership registry

Every business concept has **exactly one canonical owner**. Duplicate ownership
is prohibited. Reassigning a concept requires an Accepted ADR in **every**
affected repository before implementation.

| Business concept | Owner |
|---|---|
| Product catalog | WooCommerce |
| Stock quantity (on hand) | WooCommerce |
| Product prices | WooCommerce |
| Customer | WooCommerce |
| Customer order | WooCommerce |
| Checkout and payment | WooCommerce |
| Refunds and cancellations (initiation) | WooCommerce |
| Supplier | wc-inventory-overview |
| Purchase order | wc-inventory-overview |
| PO line / incoming supply | wc-inventory-overview |
| Goods receipt | wc-inventory-overview |
| Receiving (supplier delivery) | wc-inventory-overview |
| Receiving discrepancy | wc-inventory-overview |
| Inventory movement | wc-inventory-overview |
| Stock ledger | wc-inventory-overview |
| Inventory position / incoming | wc-inventory-overview |
| Warehouse location (hierarchy) | wc-inventory-overview |
| Bin / shelf / aisle | wc-inventory-overview |
| Item-to-location assignment | wc-inventory-overview |
| Inventory cost / weighted average | wc-inventory-overview |
| Landed cost | wc-inventory-overview |
| Inbound stock mutation | wc-inventory-overview |
| Inventory reconciliation | wc-inventory-overview |
| Fulfillment (outbound) | MPCF |
| Warehouse workflow (outbound states) | MPCF |
| Picking progress | MPCF |
| Packing progress | MPCF |
| Shipment | MPCF |
| Package | MPCF |
| Tracking (outbound consignment) | MPCF |
| Packing slip | MPCF |
| Picking list (fulfillment document) | MPCF |
| Fulfillment audit trail | MPCF |
| Operator workflow UX | MPCF |
| Per-warehouse queue partition (`warehouse_id`) | MPCF |
| Pick-path hint at intake (`location_snapshot`) | MPCF |

If a concept is not listed, default to WooCommerce for commerce data,
wc-inventory-overview for inbound inventory, and MPCF for outbound execution —
and add a row here via ADR before building it.

---

## 3. Non-negotiable invariants

Numbered, restated in the repo `CLAUDE.md`, and enforced by named guard tests (§19.1). Breaking one requires an ADR first, not a code change.

| # | Invariant |
|---|---|
| I1 | **WooCommerce owns the order.** MPCF never modifies order line items, prices, totals, customer data or products. Its only writes to a WC order are: status transitions via the bridge (§6.6), order meta under `_mpcf_*`, and (only when explicitly enabled) mirrored order notes. |
| I2 | **WooCommerce CRUD-only order access; HPOS compatibility mandatory.** All order reads/writes go through WC CRUD (`wc_get_order()`, `WC_Order` getters/setters), which works identically under HPOS and legacy storage. Direct access to `wp_posts` / `wp_postmeta` for orders — including `get_post()`, `get_post_meta()` on order IDs and SQL against those tables — is forbidden everywhere. HPOS compatibility is declared from the first installable release. |
| I3 | **All fulfillment state lives in `mpcf_*` tables.** Never in order meta, options or transients. Order meta carries at most back-pointers under `_mpcf_*`. |
| I4 | **Single writer for state.** Every state mutation flows through `WorkflowEngine::transition()` and is audit-recorded. No code path writes `mpcf_fulfillments.state` directly. |
| I5 | **The audit log is append-only.** The events repository exposes no update or delete; pruning/anonymization (GDPR) is a distinct, itself-audited administrative operation (§13). |
| I6 | **`Domain`, `Engine` and `Application` are WordPress-free.** No WP/WC function, class or constant; unit-testable without a bootstrap. |
| I7 | **`$wpdb` is confined to `src/Infrastructure/Database/`.** |
| I8 | **Only `src/Woo/` may name a WooCommerce class or hook.** The rest of the plugin does not know WooCommerce exists. |
| I9 | **Package photos and generated documents are never publicly reachable.** Stored outside the media library, behind deny rules, served only through a capability-checked streaming endpoint. |
| I10 | **Fulfillment never breaks the shop.** Intake and bridge failures degrade to a logged `problem`/admin notice — never a customer-facing error, never a fatal in checkout, payment or order screens. |
| I11 | **Admin UI and REST API consume the same application services.** No business logic in admin handlers that the API cannot reach (mobile-readiness invariant). |
| I12 | **Deactivation removes nothing.** No deactivation hook is registered. Uninstall is all-or-nothing behind `remove_data_on_uninstall` (default: keep everything — this is warehouse history). |
| I13 | **Generic product.** No site, client, host or deployment names in committed code. `mpcf` is the stable internal prefix regardless of commercial name. |
| I14 | **One approved milestone at a time.** Every feature ships with tests; phpcs is a hard gate; tags pushed only on explicit PO approval. |

---

## 4. Design principles

- **Operator-first (P0).** §2.1 is the first tie-breaker in every design review: when internal elegance or developer convenience conflicts with operator speed, clarity or predictability, the operator wins.
- **Deterministic.** Same inputs → same outputs. The engine takes explicit `Clock` and identity inputs; nothing reads globals mid-computation. Transition outcomes are pure values (`TransitionResult`) executed by services.
- **Engine-first.** The workflow engine, document data assembly, and analytics calculators are built and tested as pure PHP before any UI exists. UI renders engine output; it never contains rules.
- **WooCommerce is an adapter.** One namespace (`Woo`) translates between WC and the application; everything else is portable PHP. This is the same discipline that made UMC's Store API milestone cheap.
- **Immutable domain values.** Value objects (states, package specs, tracking refs, events) follow the house VO pattern: `final`, private typed properties, validating constructors, getters named after the property, no setters, `default_array()` / `sanitize_raw()` / `from_array()` for anything persisted as an array. Entities that must evolve (`Fulfillment`) mutate only through intention-revealing methods that emit domain events.
- **Composition root, no container.** `MPCF\Plugin` hand-wires the object graph exactly like the siblings; services are `final`, constructor-injected, and register hooks only inside `register()`. Enforced by a `CompositionRootTest`.
- **Data over code for variability.** Workflows, carriers, document types and badge vocabularies are registries of data, filterable at documented extension points — adding one never requires touching the engine.
- **Boring persistence.** Explicit-SQL `Schema` + versioned idempotent `Migrator` (AIM ADR-0003 pattern); no `dbDelta`, no SQL `ENUM`, schema version in its own option.
- **Evidence discipline.** Every WC hook/priority claim in milestone plans carries a file:line citation against the installed WooCommerce version. "Verified" ≠ "expected".
- **Backward compatibility within a major.** Public surface (hooks, REST v1, DB schema semantics, document template contract) is append-only within 1.x; an `ARCHITECTURE_FREEZE.md` is written at 1.0 exactly as UGC did.

---

## 5. Architecture

### 5.1 Layer map

```
                    ┌────────────────────────────────────────────────────────┐
  edge (adapters)   │  Admin        Api           Woo            Cli         │
                    │  (screens,    (REST mpcf/v1, (intake,       (WP-CLI)    │
                    │  view models) webhooks)     status bridge,             │
                    │                             product/order reads)      │
                    ├────────────────────────────────────────────────────────┤
  application       │  Application (services: Intake, Workflow, Picking,    │
                    │  Packing, Shipping, Tracking, Documents, Photos,      │
                    │  Notes, Audit, Notifications, Analytics)              │
                    │  + Ports (interfaces the edge implements)             │
                    ├────────────────────────────────────────────────────────┤
  core              │  Engine  (WorkflowEngine, DocumentAssembler,          │
                    │           AnalyticsCalculators, BatchBuilder)         │
                    │  Domain  (Fulfillment, Shipment, WorkflowDefinition,  │
                    │           events, value objects, exceptions)          │
                    ├────────────────────────────────────────────────────────┤
  infrastructure    │  Infrastructure (Database repos, FileStore,           │
                    │  notification channels, Scheduler/ActionScheduler,    │
                    │  Http, Clock, Identity)                               │
                    └────────────────────────────────────────────────────────┘
```

### 5.2 `src/` layout

```
src/
├── Plugin.php                 composition root (singleton, idempotent init())
├── Settings.php               sole owner of mpcf_settings (versioned, pure defaults()/sanitize())
├── PersistedKeys.php          machine-readable inventory of every persisted key (copied from UMC)
├── Capabilities.php           capability + role definitions (single source of truth)
├── Domain/                    entities, value objects, domain events, exceptions   [WP-free]
│   ├── Fulfillment/           Fulfillment, FulfillmentItem, FulfillmentId, Priority
│   ├── Shipping/              Shipment (consignment), Package (physical box: spec, colli tracking),
│   │                          PackageSpec (weight/dims), TrackingReference, CarrierId
│   ├── Workflow/              WorkflowDefinition, State, StateType, Transition, TransitionGuard
│   ├── Event/                 DomainEvent + concrete events, Actor (user/system/api)
│   ├── Document/              DocumentType, DocumentModel (assembled data, render-agnostic)
│   ├── Media/                 PhotoRecord, ContentHash
│   ├── Note/                  Note
│   ├── Assignment/            Assignee VO (type + id: user now; station/team/queue reserved)
│   └── Location/              LocationId, LocationType (facility/warehouse/zone/shelf/bin — types are
│                              data; the full hierarchy arrives with M-multi-warehouse)
├── Engine/                    pure computation                                     [WP-free]
│   ├── WorkflowEngine.php     validates + executes transitions → TransitionResult
│   ├── DocumentAssembler/     per-type assemblers: (Fulfillment + OrderSnapshot) → DocumentModel
│   ├── Analytics/             pure calculators (throughput, time-in-state, percentiles)
│   └── Batch/                 BatchBuilder (M7)
├── Application/               orchestration + ports                                [WP-free]
│   ├── Ports/                 OrderSource, FulfillmentRepository, EventRepository,
│   │                          ShipmentRepository, NoteRepository, PhotoStorage, DocumentStore,
│   │                          NotificationChannel, SearchQuery, Scheduler, Clock, Identity,
│   │                          CarrierRegistry (+CarrierPort M-labels)
│   ├── IntakeService.php      order paid → fulfillment(s) created (idempotent)
│   ├── WorkflowService.php    the ONLY caller of WorkflowEngine + state persister (I4)
│   ├── PackingService.php     line check-off, package specs, workspace commands
│   ├── ShippingService.php    shipments + tracking lifecycle
│   ├── DocumentService.php    assemble → render → store → audit
│   ├── PhotoService.php       ingest → hash → store → audit
│   ├── NoteService.php, AuditRecorder.php, AnalyticsService.php
│   ├── Notifications/         NotificationPolicy + Dispatcher (event → policy → channels, §16.1)
│   └── Dto/                   command + query DTOs shared by Admin and Api (I11)
├── Infrastructure/
│   ├── Database/              Schema.php, Migrator.php, Wpdb*Repository (only $wpdb users, I7)
│   ├── Files/                 ProtectedFileStore (uploads/mpcf/, deny rules, streamer backend)
│   ├── Notifications/         EmailChannel (wraps the WC mailer); future channels implement the same port
│   ├── Scheduling/            ActionSchedulerAdapter (WC ships Action Scheduler; WC is required)
│   ├── Http/                  transport for future carrier APIs (UMC HttpTransport pattern)
│   └── SystemClock.php, WpIdentity.php
├── Woo/                       the ONLY namespace naming WooCommerce (I8)
│   ├── WooOrderSource.php     implements OrderSource via wc_get_order()/WC_Order CRUD (I2)
│   ├── IntakeHooks.php        woocommerce_payment_complete / status hooks → IntakeService
│   ├── StatusBridge.php       fulfillment events ↔ WC order status (re-entrancy guarded, §6.6)
│   ├── RefundObserver.php     refund/cancel → exception-state proposals
│   ├── EmailTrackingHooks.php tracking block into WC customer emails (M4)
│   └── (HPOS/blocks FeaturesUtil declarations live in the main plugin file, §5.4 — they must
│        register even when Plugin stays inert)
├── Admin/                     screens, shell integration, view models; talks to Application only
│   ├── Screens/               Dashboard, Queue, Workspace, FulfillmentDetail, Documents,
│   │                          Analytics, Settings (each implements the shared Page interface)
│   ├── ViewModel/             public-property DTOs + factories (house pattern)
│   ├── Menu.php, AdminAssets.php, AdminPageSlugs.php, AdminPageRegistry.php
│   └── Vendor bridge to MP Admin Design System renderer (§8)
├── Api/
│   ├── Rest/                  mpcf/v1 controllers (thin: permission → DTO → service → response)
│   ├── FileEndpoint.php       capability-checked photo/document streamer (I9)
│   └── Webhooks/              (M-automation)
├── Documents/
│   ├── TemplateRegistry.php   document type → template resolution + override chain
│   ├── HtmlRenderer.php       print-CSS HTML rendering (default)
│   └── PdfRendererPort binding arrives with the label/customs milestone if needed
├── Cli/                       mpcf CLI: intake backfill, doctor, export
└── Exceptions/                marker interface + typed exceptions (house pattern)
```

### 5.3 Dependency rules

| From ↓ may depend on → | Domain | Engine | Application | Infrastructure | Woo | Admin | Api | Documents |
|---|---|---|---|---|---|---|---|---|
| **Domain** | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ |
| **Engine** | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ |
| **Application** | ✔ | ✔ | ✔ (Ports) | ✖ | ✖ | ✖ | ✖ | ✖ |
| **Infrastructure** | ✔ | ✖ | ✔ (implements Ports) | ✔ | ✖ | ✖ | ✖ | ✖ |
| **Woo** | ✔ | ✖ | ✔ | ✖ | ✔ | ✖ | ✖ | ✖ |
| **Admin** | ✔ (read) | ✖ | ✔ (services/DTOs) | ✖ | ✖ | ✔ | ✖ | ✖ |
| **Api** | ✔ (read) | ✖ | ✔ (services/DTOs) | ✖ | ✖ | ✖ | ✔ | ✖ |
| **Documents** | ✔ | ✔ | ✔ (implements Ports) | ✖ | ✖ | ✖ | ✖ | ✔ |

Forbidden and guard-tested (§19.1): WP symbols in Domain/Engine/Application; `$wpdb` outside `Infrastructure\Database`; WooCommerce symbols outside `Woo`; `wp_posts`/`get_post*` on orders anywhere; Admin or Api referencing a `*Repository` interface directly (they go through services); anything except `WorkflowService` calling `FulfillmentRepository::save_state()`.

`Plugin.php` is the one file allowed to see everything — it is the composition root, exactly as in UGC (whose docblock states the invariant: *no internal service may instantiate a peer; everything is constructor-injected from Plugin*).

### 5.4 Boot flow

`mp-commerce-fulfillment.php` follows the sibling template byte-for-byte in shape: ABSPATH guard → `MPCF_VERSION`/`MPCF_PLUGIN_FILE` constants → PHP 8.1 guard with admin notice → guarded autoload require → `before_woocommerce_init` HPOS + blocks compatibility declaration → `register_activation_hook` (migrations + roles) → `plugins_loaded`: guard `class_exists('WooCommerce')` (admin notice + inert if absent — WooCommerce **is** required, unlike UGC/AIM) → `Plugin::instance()->init()`.

`Plugin::init()` gates registration by context: always (intake hooks, status bridge, REST routes on `rest_api_init`, migrations drift-check on `admin_init`); `is_admin()` (menu, screens, assets); `WP_CLI` (commands). Filters attach unconditionally and gate at call time where WC cache-key stability matters (UMC's documented invariant).

---

## 6. Fulfillment state engine

The most load-bearing component. It is **not** WooCommerce statuses, and it is not a hardcoded enum of screens — it is a generic engine executing data-defined workflows.

### 6.1 Concepts

- **State** — string identifier (`picking`), plus metadata: label, badge variant (design-system token), `StateType` (`initial | working | exception | terminal`), `counts_as_open` (queue/analytics flag), `expects_operator` (assignment semantics). No PHP `enum` (house convention; and third-party states must be registrable without core changes).
- **Transition** — `(from, to)` + required capability + ordered `TransitionGuard`s + declared domain events. Guards are pure predicates over `(Fulfillment, TransitionContext)` — e.g. `all_items_packed`, `has_shipment_with_tracking`, `photo_captured_if_required`.
- **WorkflowDefinition** — named, versioned set of states + transitions + initial state. Immutable VO built from array data (`from_array()` house trio), so definitions can be shipped as PHP data, filtered, or eventually edited in admin.
- **WorkflowEngine** (`Engine/`) — `transition(Fulfillment, targetState, TransitionContext): TransitionResult`. Pure: validates the edge exists, runs guards, and returns either a rejection (typed reason) or an approved result carrying the new state and the domain events to record. It persists nothing and calls no WP function.
- **WorkflowService** (`Application/`) — the single writer (I4): loads the fulfillment (optimistic-lock version), asks the engine, persists state + `state_entered_at`, appends audit events, dispatches events to subscribers (status bridge, notifications, webhooks later). Concurrency: `UPDATE … WHERE id = %d AND version = %d`; zero affected rows → typed conflict error surfaced to the UI as "someone else updated this fulfillment".

### 6.2 Standard workflow (the default definition)

```
                 ┌────────────────────────── exception band ─────────────────────────┐
                 │   problem      waiting      backordered                            │
                 │   (blocked)    (customer/   (stock)                                │
                 │                 info)                                              │
                 └──────▲──────────────▲──────────────▲───────────────────────────────┘
                        │ any working state may enter an exception state and return
                        │ to the state it left (engine records provenance)
                        │
 queued ──► picking ──► picked ──► packing ──► packed ──► shipped ──► delivered ──► completed
   │                                                        (terminal band: completed, cancelled)
   └──► cancelled (from any non-terminal state, capability-gated)
```

| State | Type | Enters when | Notable guards on exit |
|---|---|---|---|
| `queued` | initial | intake accepts a paid order | — |
| `picking` | working | operator starts picking (self-assign or lead-assign) | — |
| `picked` | working | every line `qty_picked == qty_ordered` (guard) or lead override (audited) | `all_items_picked` |
| `packing` | working | operator opens the Packing Workspace and starts | — |
| `packed` | working | packing checklist complete; package spec captured | `all_items_packed`, `package_spec_present`, optional `photo_required` |
| `shipped` | working | shipment handed to carrier | `has_shipment` (tracking optional per settings) |
| `delivered` | working | carrier confirmation (M-integrations) or manual | — |
| `completed` | terminal | auto after `shipped`/`delivered` per settings, or manual | — |
| `problem` | exception | anything blocked: damage, mismatch, address issue | requires reason (audited) |
| `waiting` | exception | waiting on customer/info | requires reason |
| `backordered` | exception | stock shortfall | requires reason |
| `cancelled` | terminal | order cancelled/refunded (via observer proposal) or manual | requires capability `mpcf_cancel_fulfillment` |

Exception states remember `return_to` (the state they interrupted) so "resolve" is one action. Skipping is explicit workflow data, not engine leniency: the default definition includes documented shortcut edges (`queued → packing` for stores that don't run a discrete picking phase; `packed → completed` for pickup orders), each still guard-checked and audited.

### 6.3 Custom workflows

- Registered via `WorkflowRegistry` (filter `mpcf_workflows`), keyed by slug; fulfillments store `workflow` alongside `state`, so definitions can evolve without migrating rows.
- Settings map contexts to workflows (v1: one default; later: per shipping class/warehouse/order tag).
- Third parties may add states, add transitions, or replace the definition entirely. The engine only requires: exactly one initial state, at least one terminal state, all transition endpoints defined, no orphan states — validated by `WorkflowDefinition::validate()` at registration, rejected loudly in admin, tolerated silently (fallback to standard) at runtime (I10).
- Renaming/removing a state that live rows occupy is the registrant's problem, but the engine degrades safely: unknown current state → fulfillment flagged `problem` with an audit event, never a fatal.
- Admin-editable workflow builder is a Future Opportunity (§24) — the data model already supports it; only the UI is deferred.

### 6.4 Domain events

Every approved transition (and every non-state mutation: tracking added, photo captured, note added, document rendered) yields immutable `DomainEvent` objects: `event_type` (namespaced string: `fulfillment.state_changed`, `shipment.tracking_added`, `photo.captured`, `document.rendered`, `note.added`, `item.picked`, …), `Actor` (user / system / api-key, resolved via `Identity` port), occurred-at (from `Clock`), aggregate ids, minimal payload. Every payload carries a small integer `v` (per-event-type schema version) and consumers tolerate unknown fields — events outlive the code that wrote them, and analytics/webhooks will read years-old rows.

Dispatch is synchronous and in-process through a tiny `EventDispatcher` in Application (ordered subscribers registered in the composition root). At the edge, `Woo\` bridges each event to `do_action('mpcf_event', DomainEvent)` plus per-type actions (`mpcf_fulfillment_state_changed`, …) for third parties. Async fan-out (webhooks, rollups, notification batching) goes through the `Scheduler` port → Action Scheduler (bundled with WooCommerce, which is required — so it is always available; WP pseudo-cron is never assumed, since real hosts frequently disable it).

The event stream is simultaneously: the audit log source (§13), the analytics source (§15), the notification trigger (§16), and the webhook feed (post-1.0). One write path, four consumers.

### 6.5 What fulfillment states are NOT

They are not WC order statuses. Registering eight custom `wc-*` statuses was considered and rejected: WC statuses are a flat global vocabulary consumed by payment gateways, email triggers, reports and every other plugin — polluting it with warehouse micro-states breaks third-party assumptions, caps workflow customization, and couples our engine to `wp_posts`-era semantics. (Rejected alternative recorded as ADR-0002.)

### 6.6 WooCommerce status bridge (`Woo\StatusBridge`)

A narrow, configurable, re-entrancy-guarded two-way mapping:

- **Outbound bridge (MPCF → WC), event-driven:** default mapping ships as: first fulfillment enters `shipped` **and** all fulfillments for the order are shipped → WC order `completed`. Merchant-configurable (e.g. map to a single custom `wc-shipped` status if the merchant already has one; or do nothing). Writes use `WC_Order::update_status()` with an `mpcf` note prefix.
- **Store-order bridge (WC → MPCF), hook-driven:** order `cancelled` / fully `refunded` → open fulfillments proposed into `cancelled`/`problem` (settings `inbound_cancel_behavior` / `inbound_refund_behavior`: automatic vs. flagged-for-review; default automatic for cancel, flag for refund). Order edits after intake (items added/removed) → fulfillment flagged `problem` with a diff summary in the audit payload (§21 R3).
- **Loop guard:** an int depth counter (UMC's `OrderCurrencyLock` pattern) so bridge-initiated WC writes don't re-enter intake/observers.
- Authority rule, stated once and enforced by the mapping shape: **WC is authoritative for the money lifecycle; MPCF is authoritative for the warehouse lifecycle.** The bridge translates; it never lets one side drive the other's internal states directly.

**Bridge direction naming (ADR-0007).** Settings keys retain the `inbound_*` prefix for backward compatibility, but they configure **store-order bridge** behaviour only — how WooCommerce order cancellations, full refunds, and post-intake edits propagate into MPCF fulfillment states. They have **nothing to do with supplier inbound logistics**, goods receipts, purchase orders, or receiving (owned by `wc-inventory-overview`, §2.6).

---

## 7. Data model

All tables `ENGINE=InnoDB ROW_FORMAT=DYNAMIC`, explicit `CREATE TABLE` DDL in `Infrastructure\Database\Schema` (single source of truth for names), versioned idempotent steps in `Migrator` (`mpcf_db_version` option, updated after each step, runs from activation **and** an `admin_init` drift check — the bind-mount deployment lesson from AIM). No `dbDelta`, no SQL `ENUM` (states are `VARCHAR(32)` + PHP constants), UTC `DATETIME` everywhere, ids `BIGINT UNSIGNED AUTO_INCREMENT`.

### 7.1 Tables (M-numbers = milestone that introduces them)

**`mpcf_fulfillments`** (M1) — the aggregate root.
`id, order_id (indexed), order_source VARCHAR(32) DEFAULT 'woocommerce', warehouse_id BIGINT DEFAULT 1, workflow VARCHAR(64), state VARCHAR(32), previous_state VARCHAR(32), return_to_state VARCHAR(32) NULL, exception_reason VARCHAR(191) NULL, priority SMALLINT DEFAULT 0, assignee_type VARCHAR(16) NULL, assignee_id BIGINT NULL, version INT (optimistic lock), order_number_snapshot VARCHAR(64), customer_name_snapshot VARCHAR(191), item_count SMALLINT, created_at, state_entered_at, completed_at NULL`
Indexes: `(state, warehouse_id)`, `(order_id)`, `(assignee_type, assignee_id, state)`, `(created_at)`. The two snapshots exist so the Queue renders without N+1 order loads; they are display hints, never authority (the workspace always reads live order data through `OrderSource`). **Assignment is polymorphic (D20):** `assignee_type` is `'user'` everywhere in v1, but packing stations, teams and virtual queues become new type values plus registry data — never a migration. `warehouse_id` partitions outbound fulfillments into per-warehouse queues (default `1` = single-warehouse install). It is an **execution routing dimension**, not the inventory location registry (owned by `wc-inventory-overview`, ADR-0007).

**`mpcf_fulfillment_items`** (M1)
`id, fulfillment_id (indexed), order_item_id, product_id, variation_id, sku_snapshot VARCHAR(191), name_snapshot VARCHAR(255), qty_ordered, qty_picked, qty_packed, location_snapshot VARCHAR(191) NULL`
`location_snapshot` is an **immutable intake snapshot** of a pick-path hint copied at fulfillment creation (e.g. aisle/shelf label from an external source). It supports picking lists and display only; it is **not** inventory position authority and is **not** written by receiving. Inventory topology lives in `wc-inventory-overview` (ADR-0007). Nullable until a future integration supplies the hint. Other snapshots make picking lists and audit stable even if the product is later renamed/deleted.

**`mpcf_shipments`** (M2) — the consignment (one carrier handover).
`id, fulfillment_id (indexed), carrier_id VARCHAR(64), service VARCHAR(128) NULL, tracking_number VARCHAR(191) (consignment-level), tracking_url TEXT NULL (explicit override; normally derived), status VARCHAR(32) DEFAULT 'pending', shipped_at NULL, delivered_at NULL, created_at`

**`mpcf_packages`** (M2) + **`mpcf_package_items`** (M2) — the physical boxes inside a shipment (ADR-0005, D19).
`id, shipment_id (indexed), seq SMALLINT, weight_grams INT NULL, length_mm/width_mm/height_mm INT NULL, tracking_number VARCHAR(191) NULL (per-package colli number when the carrier assigns one), label_path VARCHAR(255) NULL (M-integrations), created_at`; items: `(package_id, fulfillment_item_id, qty)`. One shipment contains one or more packages; creating a simple shipment auto-creates its single package, so the common case stays one form while multi-parcel consignments — each parcel with its own dimensions, weight, photos, label and colli number — are extra rows, never a schema change. Weight/dimensions stored in fixed integer base units (grams/mm); display conversion is a UI concern.

**`mpcf_events`** (M1) — append-only audit (§13).
`id, fulfillment_id (indexed, NULL for global events), event_type VARCHAR(64) (indexed), actor_type VARCHAR(16), actor_id BIGINT NULL, actor_label_snapshot VARCHAR(191), payload LONGTEXT (JSON), prev_hash CHAR(64) NULL, hash CHAR(64), created_at (indexed)`

**`mpcf_notes`** (M1) — internal notes (§14).
`id, fulfillment_id (indexed), author_id, body TEXT, is_pinned TINYINT, created_at`

**`mpcf_media`** (M5) — package photos (§12).
`id, fulfillment_id (indexed), package_id BIGINT NULL, kind VARCHAR(32) ('package'|'contents'|…, filterable), file_path VARCHAR(255) (relative to protected root), mime VARCHAR(64), bytes INT, sha256 CHAR(64), captured_by BIGINT, created_at`

**`mpcf_documents`** (M3) — generation record (§10).
`id, fulfillment_id (indexed), doc_type VARCHAR(64), template_version VARCHAR(32), file_path VARCHAR(255) NULL (NULL = rendered-to-print, not stored), rendered_by, created_at`

Post-1.0 (schema reserved, not created early): `mpcf_batches` + `mpcf_batch_items` (M7); `mpcf_returns` + `mpcf_return_items`; `mpcf_stats_daily` (M8 rollups); `mpcf_search_index` (§9.3 — only if profiling demands it); `mpcf_webhooks`, `mpcf_api_keys`.

Location hierarchy and item-to-location assignment are owned by **`wc-inventory-overview`** (ADR-0007, §2.6). MPCF does not create `mpcf_locations` or `mpcf_item_locations`. Future location-sorted picking consumes pick-path data from the inventory owner via a versioned contract; `location_snapshot` and `warehouse_id` remain MPCF-side execution hints and queue partitioning.

### 7.2 Why custom tables (ADR-0001)

Order meta was rejected: the Queue needs indexed multi-column queries (`state + warehouse + assignee + age`) at warehouse scale; audit needs append-only semantics meta can't express; shipments/items/photos are relational; HPOS migrates meta storage under our feet; and meta writes trigger order-object cache invalidations we don't want per pick-tick. A CPT was rejected for the same reasons plus `wp_posts` coupling (violates the spirit of I2). Fulfillment state in its own tables also survives order anonymization cleanly (§17.4).

### 7.3 Everything persisted (PersistedKeys discipline)

`src/PersistedKeys.php` (copied wholesale from UMC, adapted) inventories: options (`mpcf_settings`, `mpcf_db_version`, plus each option a later milestone adds), all `mpcf_*` tables, order meta (`_mpcf_has_fulfillment` back-pointer only if profiling shows the join needs it — default: none), user meta (workspace UI preferences `mpcf_ui_prefs`), the protected upload directory, capabilities/roles, Action Scheduler group `mpcf`, transients, cache groups. Bound to `docs/PERSISTED_DATA.md` and `uninstall.php` by inventory guard tests, exactly like UMC. Uninstall (all-or-nothing, default keep): drop tables, delete options/caps/roles, remove the protected directory, unschedule AS group.

---

## 8. MP Admin Design System (MPDS)

### 8.1 Current state (verified)

The design system already exists twice: `assets/admin/umc-settings.css` in Universal Multicurrency (the origin — tokens at lines 1–15 and 1485–1507, `umc-ui-*` BEM components at 1484–2386, `AdminComponentRenderer.php` with a markup-contract test) and `assets/css/admin.css` in Universal Geo Context (`ugc-ui-*` — the same stylesheet, prefix swapped, plus the **standalone-menu page shell** UMC lacks: `Page` interface, `AdminPageSlugs`, `AdminPageRegistry`, `Menu`, `AdminPageShell`). Commerce Fulfillment would be copy number three — the rule-of-three trigger. **PO decision 2026-07-31: extract now.**

### 8.2 Distribution: shared source vs runtime library (ADR-MPDS-0001)

**B) Shared runtime library — rejected.** WordPress has no dependency resolution: two plugins loading different versions of a shared runtime library is the classic WP failure mode (first `class_exists` wins; the older copy silently serves the newer plugin). A mu-plugin/library-plugin also breaks the house rule that each plugin repo is fully self-contained and independently installable, and complicates commercial distribution (customers install one zip).

**A) Shared source, single canonical repo, build-time vendoring — recommended and approved.** Precisely:

- New private repo **`magpern/mp-admin-design-system`**, semver-tagged (`v0.1.0` at M0 — the extracted existing component set; `v1.0.0` when the component API has stabilized through real consumer milestones, target around the plugin's own 1.0). Contents:
  - `css/tokens.css` — two layers: *brand tokens* (accent, surfaces, radius, spacing — the per-product skin) and *system tokens* (`--mpds-neutral-*`, shadows, transitions, badge palette). Fixes carried in on extraction: the four undefined variables (`--umc-radius-lg/-md`, `--umc-shadow-sm`), and the untokenized hardcoded accent-alpha `rgba(108,60,255,…)` values become `--mpds-accent-a20/a35`.
  - `css/components.css` — the `mpds-ui-*` component set (page-intro, feature-section, statistics grid/cards, settings cards, choice cards, toggle/field rows, status badges, provider cards, panels, empty states, quick actions, sticky save bar, pill nav, skeletons, timeline, data table, drawers + modals as new components §8.4), all scoped under a host body class, `prefers-reduced-motion` companions, breakpoints 1024/782/480/390.
  - `php/ComponentRenderer.php` + `php/PageShell/` (Page interface, Menu, AdminPageShell, SectionNavigation, view models — UGC's standalone-menu shell generalized; UMC's `WC_Settings_Page` shell stays behind in UMC).
  - `js/` — behavior modules keyed on `data-mpds-*` attributes only: dirty-state/sticky-save, disclosure, clipboard, drawer/modal focus management. Vanilla, no build step.
  - `tests/` — the markup-contract test suite (class names + structural landmarks per component), CSS-token lint (every `var()` reference must be defined), and a manifest generator.
  - `GALLERY.md` + a static gallery page for visual review.
- **Consumption:** each consumer pins a tag and runs `bin/sync-mpds.sh <tag>`, which copies the package into the plugin (`src/Vendor/Mpds/`, `assets/mpds/`), mechanically rewrites the PHP namespace `Mpds\` → `MPCF\Vendor\Mpds\`, the CSS var prefix `--mpds-` → `--mpcf-ds-`, class prefix `mpds-ui-` → `mpcf-ui-`, and `data-mpds-` → `data-mpcf-`, then writes `assets/mpds/MANIFEST` (source tag + content hashes). The rewrite is deterministic string substitution — the same operation done by hand for UGC, now scripted, versioned and one-way.
- **Why prefix-rewrite instead of sharing identifiers:** PHP classes *must* be rewritten (collision, above). CSS/data attributes are rewritten too so that a page can never accidentally pick up another active plugin's stylesheet version — each plugin's admin pages are self-sufficient at whatever MPDS version it shipped with. Upgrading MPDS in one plugin never risks another.
- **Drift protection:** a `MpdsVendorGuardTest` in each consumer recomputes the manifest hashes — hand-edits to the vendored copy fail CI; fixes must land upstream and be re-synced. This converts "copied source" from a drift liability into a pinned dependency with an audit trail.
- Vendored copy is committed (repo stays self-contained, CI needs no cross-repo access, zips build unchanged).
- **Retrofit:** UMC and UGC adopt MPDS on their own schedules (each under their own "one milestone at a time" rule); the extraction repo starts from UMC's stylesheet as the canonical source since it is the origin. Nothing in this plan blocks on their retrofit.

### 8.3 Design language (unchanged, now canonical)

Tokens: 8/16/24px spacing ladder, 14–16px radii + pill radius, the neutral-50…900 ramp, badge palette (active/warning/error/disabled/recommended + new fulfillment vocabulary §8.4), `0.18s cubic-bezier(0.4,0,0.2,1)` transitions, shadow sm/md/lg, readable width 860px, rem type scale with 650-weight headings and uppercase-tracked labels. Accessibility floor (already baked in, now written down as MPDS rules): visually-hidden native inputs, `aria-current`/`aria-live`/`aria-expanded` conventions, decorative elements `aria-hidden`, ≥44px touch targets on action controls, `:focus-within` on interactive cards, reduced-motion companions mandatory for every animation.

### 8.4 New components MPDS gains for (and with) this plugin

Fulfillment is the first *operational* (not settings) UI, so MPDS v1.x grows: **data table** with sortable headers/sticky header/bulk-select (generalizing UGC's `ugc-ui-data-table`), **filter bar** (search input + filter pills + saved views), **drawer** (right-hand slide-over for order detail from the queue), **modal** (confirm/problem-reason dialogs), **timeline** (generalizing UGC's `TimelineRenderer`) with actor avatars and event icons, **stepper/segmented progress** (workflow position indicator), **kbd hints** (keyboard-shortcut badges), **stat trend** (delta arrows on statistics cards), **toast** (async save feedback), **scan input** (large-focus barcode field, M6). Each lands in the MPDS repo with contract tests, then syncs down — the design system evolves through its consumers but lives upstream.

---

## 9. Warehouse UI

### 9.1 Placement and shell

A **top-level admin menu "Fulfillment"** (dashicon `dashicons-archive`), not a WooCommerce settings tab — operators live here all day; it is a workspace, not configuration. Screens implement the MPDS `Page` interface and register through `Menu`/`AdminPageRegistry` (UGC pattern): Dashboard, Queue, Packing Workspace (contextual, reached from the queue), Fulfillment detail, Documents, Analytics, Settings. Assets are gated to plugin screens only; body class `mpcf-admin` is the token scope root. An optional **Operator Mode** setting hides the rest of wp-admin chrome for the operator role (admin-bar and menu reduced to Fulfillment screens) — deliberate: operators are not WordPress users culturally, and every visible menu is a support ticket.

### 9.2 JavaScript strategy (ADR-0003)

House discipline is no build step, no framework — and it stays. The Packing Workspace needs real interactivity, so the decision deserves an honest paragraph: a React/`@wordpress/element` SPA was considered (rich ecosystem, WC admin uses it) and **rejected** — it imports a build toolchain into a repo family that has none, couples us to WC admin's churning JS stack, and none of the workspace interactions (checklist ticks, field edits, state buttons, photo upload, list filtering) exceed what small vanilla ES modules over `fetch` + the REST API do well. Architecture: per-screen ES modules under `assets/admin/js/` (`workspace.js`, `queue.js`), a tiny shared `api.js` (REST client with nonce handling) and `store.js` (plain observable state, optimistic updates with rollback on 409). No jQuery in new code. Server renders the initial state into the page (fast first paint, no skeleton flash); JS enhances. If the workspace ever genuinely outgrows this, that is a new ADR — with the REST API unchanged, a framework rewrite would touch only `assets/`.

### 9.3 Screens

**Dashboard** — an **operational workspace, not an analytics page** (P0): it answers *"what should we do next?"* before *"what happened?"*. Top band: next actions — the needs-attention list (problem/waiting, oldest first), oldest open fulfillments, unassigned work, and (post-1.0) carrier cut-off countdowns — each a one-click jump into the queue with filters pre-applied or straight into a workspace. Second band: today's operational stats (open, in exception, packed today, shipped today) as MPDS statistics cards, plus quick actions (print today's picking lists). Historical trends and charts live on the Analytics screen (M8), never here. The acceptance rule for any future dashboard widget: if it doesn't change what an operator or lead does in the next hour, it belongs on Analytics instead.

**Fulfillment Queue** — the operational hub. MPDS data table: order number, customer, item count, state badge, age (time in current state, highlighted past thresholds), priority, assignee, warehouse (hidden until multi-warehouse). Filter bar: state (default "open"), assignee, age, search (order #, customer, SKU, tracking). Bulk actions: assign, print picking lists, advance state (guard-checked per row, partial-failure reported per row). Row click → drawer preview with "Open workspace" primary action. Keyboard: `j/k` row navigation, `Enter` opens, `/` focuses search. Saved filter views per user (`mpcf_ui_prefs`). Pagination server-side, indexed queries only — the queue must stay fast at 50k open rows (§21 R6).

*Search architecture (D22).* The design target is "scan or paste anything": order number, SKU, customer name, tracking number, carrier, shipment, phone, email, note text. Search sits behind a `SearchQuery` port from M1 so the queue UI never changes when the backend evolves. The v1 implementation classifies the term (tracking-shaped → shipments/packages, SKU-shaped → items, numeric → order/fulfillment id, otherwise → name snapshot) and unions targeted indexed lookups — never `LIKE '%…%'` over unindexed columns. Fields whose authority is WooCommerce (phone, email) resolve through `OrderSource` lookups rather than duplicating PII into `mpcf_*` tables. Note-text search and cross-field ranking arrive with the reserved `mpcf_search_index` projection (`fulfillment_id, field, normalized_value`, maintained from domain events) only if profiling shows the union approach failing at scale — the port makes that swap invisible.

**Packing Workspace** — §9.4.

**Fulfillment detail** — read-oriented sibling of the workspace for leads/support: full timeline (audit), notes, shipments, documents, photos, raw order link. The workspace is for doing; the detail page is for understanding.

**Documents** — per-fulfillment print actions live in the workspace; this screen is batch-oriented (M3+): render picking lists for a selection, reprint history (from `mpcf_documents`), template settings link.

**Analytics** (M8) — §15.

**Settings** — MPDS settings cards: workflow (mapping + toggles for optional guards like photo-required), status bridge mapping, carriers, documents (store branding block, template options), photos (required/optional, retention), notifications, permissions/operator mode, advanced (uninstall policy). Sticky save bar with dirty-state tracking (MPDS module).

### 9.4 Packing Workspace — the heart

One fulfillment, one screen, optimized for hands-on-keyboard/scanner speed. Layout (three columns ≥1024px, stacked with sticky action bar below):

- **Left — context:** customer + shipping address (formatted, copy button), order essentials (number, date, payment method badge, order link for leads), flags (customer note present, high value, repeat problem customer — filterable badge slot `mpcf_workspace_flags`).
- **Center — the work:** item checklist. Each line: product image, name, SKU, **large quantity progress (2 / 3)**, location (when M-locations lands), tap/click target the full row height; check-off increments `qty_picked`/`qty_packed` per workspace mode. A single always-focused hidden **scan sink** input captures keyboard-wedge scanner input from M6 (the DOM/focus architecture for it exists from M2 so M6 is additive). Mismatch (unknown SKU scanned, over-scan) → prominent inline error + optional problem shortcut.
- **Right — the outcome:** shipment panel (carrier select from registry, service, tracking number with per-carrier format validation hint; per-package weight/dims with unit-aware inputs and an "add package" action for multi-parcel consignments — the single-box case stays a single form because the first package is implicit, §7.1), photo capture slots (M5: package/contents, camera or file), documents (print packing slip — renders immediately), internal notes (pinned first), compact timeline (last 5 events, link to full).
- **Sticky action bar:** one context-sensitive primary button computed from the workflow definition (`Start picking` → `Mark picked` → `Start packing` → `Mark packed` → `Ship`), secondary `Problem…` (modal: reason + note, capability-checked), overflow menu for rarer transitions. Buttons render *only* transitions the engine allows for this user — the UI never knows the rules, it asks (`GET /fulfillments/{id}/transitions`).

Speed principles: zero full-page reloads during a pack; every mutation optimistic with rollback; all controls reachable by keyboard; primary action ≥44px and triggerable via `Ctrl+Enter`; autofocus follows the task (checklist → tracking → primary button); 409 conflicts (another operator) surface as a toast + refresh, never silent overwrite.

### 9.5 Mobile/tablet future

Not implemented now; made cheap by three standing decisions: (1) I11 — all workspace behavior is REST commands; (2) MPDS is responsive with touch-size floors, and the workspace's stacked layout is designed (not just allowed) at 480px; (3) authentication for a dedicated tablet app is Application Passwords over the same REST namespace, plus scoped `mpcf_api_keys` post-1.0. A future "warehouse mode" PWA is a new frontend over `mpcf/v1` — no server work beyond endpoint completeness, which is guarded by the rule that every workspace capability lands as REST first.

---

## 10. Documents

Every document flows through one explicit five-stage pipeline; each stage is a separate seam:

```
Assembler (pure data) → Template (layout) → Renderer (HTML-print / PDF port) → Storage (protected store; optional) → Audit (always)
```

Stages are independently replaceable: third parties override templates without touching assembly; a PDF renderer swaps in without touching templates; storage is skipped for render-to-print but audit never is. `DocumentService` is the only orchestrator of the pipeline — no code path renders a document outside it (guard-tested), which is what makes "documents printed" a reliable audit fact.

- **`DocumentType` registry** (filterable `mpcf_document_types`): `packing_slip` (M3), `picking_list` (M3), `batch_picking_list` (M7), `commercial_invoice`, `return_slip`, `cn22`, `cn23` (M-customs), `shipping_label` (reserved for carrier integrations — labels come from carrier APIs as files, not our renderer). Each type declares: assembler, template, paper size, per-fulfillment vs batch, capability.
- **Assembly ≠ rendering.** `Engine\DocumentAssembler\*` produces a pure `DocumentModel` (store block, addresses, line items with snapshots, totals policy, barcode payloads, customs fields) from `(Fulfillment, OrderSnapshot, Settings)` — fully unit-tested, no HTML. Renderers consume models.
- **HTML-first rendering.** Default output is print-optimized HTML (`@page` CSS, `@media print`) opened for the browser's print dialog — zero heavy dependencies, perfect for packing slips/picking lists at a packing station. A `PdfRenderer` binding (likely dompdf) arrives with customs/labels where a stored file is contractually needed; the port exists from M3 so it is a swap, not a refactor. Stored renders go into the protected file store and `mpcf_documents`.
- **Templates** are plain PHP templates receiving only the `DocumentModel` + an escaper; override chain: filter → theme directory (`mp-commerce-fulfillment/documents/…`) → bundled. Templates are versioned (`template_version` recorded per render) so audit can say exactly what a reprinted slip looked like.
- **Every render is audited** (`document.rendered`, who/what/when/template version) — "documents printed" is an explicit audit requirement.
- Barcode payloads (Code 128 of fulfillment id / order number) are part of `DocumentModel` from M3 so slips are scannable the day M6 lands.

---

## 11. Tracking

- **Carrier registry, data-driven** (`mpcf_carriers` filter + bundled definitions): id, label, tracking-URL template (`https://…?id={tracking}`), tracking-number format hint (regex, warn-only), phone-required flag. Bundled set at M4 skews EU (PostNord, DHL, Bring, DPD, GLS, UPS, DB Schenker, Budbee, Instabox) + generic "Other" with manual URL. Data, not code — merchants and third parties add carriers without PHP in a later settings iteration.
- **Multiple shipments per order and multiple packages per shipment** are native (§7.1): split shipments are additional `mpcf_shipments` rows; multi-parcel consignments are additional `mpcf_packages` rows, each with its own colli tracking number, dimensions, weight, photos and (later) label. Tracking display prefers package-level numbers when present, falling back to the consignment number. Partial-quantity shipping within one fulfillment is a future capability — see §24.1.
- **Customer notification** (M4): "your order has shipped" email per shipment with tracking link(s) — delivered through the notification subsystem (§16.1) via its `EmailChannel` (wrapping the WC mailer for styling consistency); the dispatcher's dedup window means multi-package edits within a grace period send once. Plus `Woo\EmailTrackingHooks`: inject a tracking block into WC's own Completed-order email when the bridge maps shipped→completed (so merchants who keep WC emails get tracking there instead of a second email — setting chooses which).
- **Shipment status** field (`pending/shipped/delivered/exception`) is manual/bridge-driven in v1; **live carrier tracking sync** (poll or webhook via `CarrierPort`) is the M-integrations milestone — the schema (`delivered_at`, `status`) and the Scheduler port are already shaped for it.
- Tracking changes (add/edit/remove) are all audited with before/after payloads.

---

## 12. Package photography

First-class trust feature: *prove what left the warehouse.*

- **Capture:** workspace photo slots — `<input type="file" accept="image/*" capture="environment">` (works on tablets/phones today, no getUserMedia complexity in v1; a live-camera widget is a spike, §22 S4). Kinds: `package` (final, sealed), `contents` (open box), extensible.
- **Storage (ADR-0004):** NOT the media library — library pollution, public URLs (violates I9), attachment lifecycle owned by others. Instead `wp-content/uploads/mpcf/photos/{yyyy}/{mm}/{fulfillment_id}/…` with `.htaccess` deny + `index.html` (nginx users get a documented deny-rule snippet), random-suffixed filenames, served only via `Api\FileEndpoint` (capability check → `X-Sendfile`-less PHP streaming with correct headers; range support not needed for images).
- **Audit integrity:** on ingest, `PhotoService` computes SHA-256, strips EXIF GPS (privacy) while keeping timestamp EXIF, records `photo.captured` with the hash. The stored file is immutable; deletion is a distinct audited (and capability-gated) act. Future annotations are separate overlay records referencing the original — originals never change.
- **Constraints:** max dimensions/size enforced server-side with re-encode (defensive against 50MB phone photos), configurable per-fulfillment photo requirement as a workflow guard (`photo_required` on `packed → shipped`), retention policy setting (auto-purge after N months, purge itself audited) — storage growth and GDPR both demand it (§17.4, §21 R7).

---

## 13. Audit system

- **One stream:** `mpcf_events` (§7.1) is the single audit substrate — state changes, item ticks (aggregated per session to avoid row explosions: one `items.picked` event per checklist burst, itemized payload), tracking changes, photo events, document renders, note add/edit, settings changes affecting fulfillment behavior, bridge actions, API/webhook actions. Who / what / when / from-where (actor type: user, system, api).
- **Append-only (I5):** `WpdbEventRepository` exposes `append()` and readers only; guard test asserts no UPDATE/DELETE statement exists in the class and no other class touches the table.
- **Tamper-evidence:** per-fulfillment hash chain — each event stores `prev_hash` (previous event's `hash` for that fulfillment) and `hash = sha256(prev_hash . canonical_payload)`. Cheap (one select + one concat per append), and gives disputes real teeth: a `wp mpcf audit verify <fulfillment>` CLI walks the chain. Not cryptographic non-repudiation (server admin can rewrite history with effort) — documented honestly in `docs/SECURITY.md`; the goal is detecting casual tampering and DB mishaps, not defeating a hostile root.
- **Actor snapshots:** `actor_label_snapshot` (display name at event time) so history stays legible after user deletion; `actor_id` nullable for erasure (§17.4).
- **Rendering:** the Timeline (workspace, detail page) is a view over events with icon/label mapping per `event_type`; unknown types render generically (third-party events appear automatically).
- **Investigation workflows (future direction, architecture-ready):** the event stream is designed to be *queried*, not merely displayed. Post-1.0, an Audit Explorer adds: global filtering (event type, actor, date range, warehouse), cross-fulfillment actor timelines ("everything operator X touched on Tuesday"), payload search, and audited export (CSV/JSON behind `mpcf_view_audit`, the export itself logged). An **investigation mode** builds on the same substrate: a lead pins a working set of fulfillments/events and attaches annotations — stored as new `investigation.*` events, because the audit log annotates itself and nothing is ever edited (I5 holds even for investigators). Schema cost today is nil; one additional index `(actor_type, actor_id, created_at)` lands with the explorer.
- **Growth:** events are the largest table by far. Strategy: indexes only on real access paths (`fulfillment_id`, `event_type`, `created_at`); analytics reads move to rollups at M8; archival policy (export + prune fulfillments completed > N years) is a documented post-1.0 operation, not silent deletion.

---

## 14. Internal notes

Fulfillment notes are **not** WooCommerce order notes. WC order notes are customer-service-facing, mixed with gateway/system noise, stored in `wp_comments`, and visible to anyone with order access. Warehouse notes ("customer wants logo stickers", "fragile — double box", "left pallet 3") belong to the warehouse:

- Own table (`mpcf_notes`), own capability (`mpcf_add_notes` to write; workspace read comes with `mpcf_view_queue`), pinnable (pinned notes render prominently in the workspace before packing starts — that is their job).
- Never mirrored to WC order notes by default; an opt-in setting mirrors *specific* notes via an explicit "share to order" action (audited), not blanket sync.
- Notes are editable by their author within a grace window, then append-only like everything else (edits audited with diffs). Deletion: lead capability, audited.
- Post-1.0: order-level standing notes (customer flags) and note templates/canned notes.

---

## 15. Analytics

- **Source of truth is the event stream.** No separate instrumentation: state timestamps + events already encode queue depth, throughput, time-in-state, operator activity, carrier mix, exception rates. Analytics is a *read model*.
- **Two tiers:** live queries for small windows (dashboard "today" cards — indexed queries on `mpcf_fulfillments`/`mpcf_events`), and `mpcf_stats_daily` rollups (M8; Action Scheduler nightly job + backfill CLI) for trends: per-day per-warehouse counters (created, packed, shipped, exceptions), duration percentiles (p50/p90 queued→shipped, packing duration), per-operator counters, per-carrier counters. `Engine\Analytics\*` calculators are pure (arrays in, metrics out) and unit-tested against synthetic event fixtures.
- **Metrics roadmap:** M8 ships queue/throughput/speed/carrier basics; packing-error rate (problem-state reasons categorized), employee productivity views, and warehouse-throughput forecasting are Analytics II (post-1.0).
- **Privacy stance (EU reality):** per-operator analytics is **off by default** and separately toggleable with a capability of its own (`mpcf_view_operator_stats`) — per-employee performance monitoring has works-council/GDPR implications in the EU; the plugin makes it a deliberate, documented choice, and the docs say so plainly. Aggregate metrics never require it.

---

## 16. API, notifications, webhooks, automation

### 16.1 Notification subsystem

Notifications are a subsystem, not scattered `wp_mail()` calls, because the channel list will grow (email today; SMS, push, Slack, Teams, in-admin notices tomorrow) while the triggering logic must not:

```
DomainEvent → NotificationPolicy (which events notify whom — data, from settings)
           → Dispatcher (dedup / grace windows / async fan-out via Scheduler)
           → NotificationChannel port (EmailChannel in v1; others register via mpcf_notification_channels)
```

- **Everything originates from domain events** — there is no code path that notifies without one (guard-tested). Policies are data (settings-driven: customer shipped-mail on `shipment.shipped`, lead alert on `fulfillment.state_changed → problem`, digests later); channels are dumb transports (message in, delivery result out); message templates belong to the channel type.
- Deliveries are themselves audited (`notification.sent` / `notification.failed`) and fan out asynchronously through the Scheduler port with retry — a mail-server hiccup never blocks a pack (I10).
- Third-party channels (SMS gateways, Slack) implement one interface and register via filter; MP Commerce siblings or paid add-ons can ship channels without core changes (D21).

### 16.2 REST API, webhooks, automation

- **REST namespace `mpcf/v1`** exists from M2 because the workspace runs on it (I11). Resources: `fulfillments` (list with queue filters, get, `POST …/transitions`, item quantities), `shipments`, `notes`, `photos` (multipart), `documents` (`POST …/render`), later `carriers`, `workflows`, `stats`. Controllers are thin: `permission_callback` (capability + nonce for cookie auth) → DTO → application service → response shaped by the same view-model factories admin uses. Errors: typed application exceptions map to stable `mpcf_*` error codes.
- **Auth:** cookie+nonce (admin JS), Application Passwords (integrations/tablets) from day one because it is free with WP REST; scoped `mpcf_api_keys` (per-station keys, per-key capabilities) post-1.0.
- **Versioning:** additive-only within v1; breaking shape changes → `mpcf/v2` side-by-side. Documented in `docs/API.md` (UGC discipline: complete enough that an integrator needs no other document).
- **Webhooks (post-1.0):** subscription registry (`mpcf_webhooks`), fan-out via Action Scheduler with retry/backoff, HMAC-signed payloads, event-type filters — a straight consumer of the event dispatcher (§6.4).
- **Automation rules (post-1.0):** event → conditions → actions (assign, prioritize, set workflow, notify, webhook), stored as data, executed by a rule runner subscribed to the dispatcher. The registry-and-data pattern (§4) means this needs no engine changes.
- **Extensibility hooks policy:** every hook documented in `docs/HOOKS.md` (per-file tables, priorities justified) with a "deliberately NOT hooked" section, sync-tested like UMC. Extension points at v1: `mpcf_workflows`, `mpcf_carriers`, `mpcf_document_types`, `mpcf_event` (+ per-type actions), `mpcf_workspace_flags`, `mpcf_intake_should_create` (filter to skip/split fulfillments), template overrides. Internal class structure is NOT API; hooks and REST are.

---

## 17. Security, privacy, GDPR

### 17.1 Capabilities and roles

Custom capabilities (defined in `Capabilities.php`, granted on activation, removed on uninstall): `mpcf_view_queue`, `mpcf_process_fulfillments` (pick/pack/state transitions), `mpcf_manage_shipments`, `mpcf_add_notes`, `mpcf_capture_photos`, `mpcf_render_documents`, `mpcf_cancel_fulfillment`, `mpcf_view_audit`, `mpcf_view_analytics`, `mpcf_view_operator_stats`, `mpcf_manage_settings`. Roles: **Warehouse Operator** (queue/process/ship/notes/photos/documents — no WC order admin, no settings) and **Warehouse Lead** (adds cancel/audit/analytics/assignment). Administrators and shop managers get everything. Every REST route and admin action checks a specific capability — never `manage_options` shortcuts, never role names in code.

### 17.2 Request security

Nonces on all cookie-authenticated mutations; capability + ownership checks server-side (assignment rules enforced in services, not UI); all output escaped at the edge (MPDS renderer escapes internally, house phpcs rules enforce the rest); uploads validated by content sniffing not extension, re-encoded (§12); file streamer validates path against the DB record (no user-supplied paths — IDOR-proof by construction); SQL only via `$wpdb->prepare` inside repositories (phpcs + guard).

### 17.3 Audit integrity

§13: append-only + hash chain + honest threat-model documentation. Settings changes that alter workflow guards or the bridge are themselves audited (who loosened "photo required" matters in disputes).

### 17.4 Privacy / GDPR

- **Data inventory** (in `docs/PRIVACY.md`, UGC pattern): fulfillment rows hold name snapshot + order reference; notes may hold free-text PII; photos may show addresses on labels; events hold actor ids + minimal payloads (rule: **payloads reference IDs, never copy addresses/emails** — guard-tested regex on event constructors is a spike, S6).
- **WP privacy integration:** exporter (fulfillment history + notes + photo list for a customer's orders) and eraser (anonymize name snapshots, null `actor_id` where the subject is the actor, delete/blur photos on request) registered with WordPress's privacy tools; WC order anonymization observed → fulfillment snapshots anonymized in sympathy.
- **Retention:** photo retention setting (§12); documented archival guidance for events; employee-analytics stance (§15).
- **Storage locality:** everything on the merchant's server — no phone-home, no external services in core (carrier APIs post-1.0 are explicit merchant opt-ins with credentials in their own settings section, never logged).

---

## 18. Key decisions (D-table)

| # | Decision | Rationale (short) |
|---|---|---|
| D1 | Fulfillment is a separate aggregate in custom tables, not order meta/CPT | Queue queries, relational shape, append-only audit, HPOS independence (ADR-0001) |
| D2 | Generic data-defined workflow engine; states are data | Custom workflows, returns reuse, no engine churn per feature (ADR-0002 records the rejected WC-status alternative) |
| D3 | No fulfillment micro-states as WC order statuses; narrow configurable bridge instead | WC status vocabulary is a global ecosystem contract |
| D4 | Ports-and-adapters; Domain/Engine/Application WordPress-free | House pattern, proven testability |
| D5 | Hand-wired composition root, no DI container | House pattern; containers add indirection without benefit at this scale |
| D6 | REST-first workspace (`mpcf/v1` from M2); admin and API share services (I11) | Mobile/integrations become UI projects |
| D7 | No JS framework, no build step; vanilla ES modules over REST | House discipline; interactions don't justify a toolchain (ADR-0003) |
| D8 | MPDS extracted to its own repo; build-time vendoring with mechanical prefix/namespace rewrite + manifest guard | Rule of three; runtime library rejected (ADR-MPDS-0001) |
| D9 | Photos/documents in a protected upload directory, capability-checked streamer, never the media library | I9, GDPR (ADR-0004) |
| D10 | Append-only audit with per-fulfillment hash chain | Trust product; cheap tamper-evidence |
| D11 | Explicit-SQL Schema/Migrator, no dbDelta, no SQL ENUM, own version option | AIM ADR-0003, proven |
| D12 | Async via Action Scheduler (WC required ⇒ always present); never assume WP pseudo-cron | Reliability on real hosts |
| D13 | `warehouse_id` on fulfillments from day one (default 1) for per-warehouse queue partitioning; location hierarchy and item-to-location assignment belong to `wc-inventory-overview` (ADR-0007). M12 adds location-sorted picking by consuming inventory-owner data via a future contract — not MPCF location tables | Multi-warehouse queues are a column today; inventory topology is a sibling concern |
| D14 | Returns are a separate aggregate + workflow definition, not extra forward-flow states | Different lifecycle, same engine |
| D15 | Weight/dims in integer base units (g/mm); display conversion in UI | Determinism, no float drift |
| D16 | Documents: pure assembler → model → renderer; HTML-print first, PDF port later | Testability; no heavy deps until customs needs them |
| D17 | Per-operator analytics off by default behind its own capability | EU employment-privacy reality |
| D18 | Floors PHP 8.1/WP 6.5/WC 8.2 (minimums, not dev target); CI covers floor + current stable; HPOS mandatory, legacy order post storage forbidden | PO decision 2026-07-31 |
| D19 | `Package` is first-class from M2: Shipment (consignment) ⊃ Packages (dims/weight/colli tracking/photos/labels); simple shipments auto-create one package | Multi-parcel is the norm at real carriers; retrofitting packages under shipments later is the expensive migration (ADR-0005) |
| D20 | Assignment is polymorphic (`assignee_type` + `assignee_id`); v1 supports only `user` | Packing stations, teams and virtual queues become data, not migrations |
| D21 | Notifications: events → policy → dispatcher → `NotificationChannel` port; v1 ships email only | SMS/push/Slack/Teams are channel implementations, not refactors (§16.1) |
| D22 | Queue search behind a `SearchQuery` port; v1 = classified indexed lookups; denormalized `mpcf_search_index` projection reserved | The most-used screen must never grow unindexed LIKE scans (§9.3) |

## 19. Testing & CI strategy

### 19.1 Tiers (house standard)

1. **Unit (no WordPress):** everything in Domain/Engine/Application against hand-written fakes for ports (`tests/unit/Doubles/`). The workflow engine, guards, document assemblers and analytics calculators get exhaustive table-driven tests here — this is where the product's correctness lives.
2. **Integration (wp-phpunit + WooCommerce, HPOS forced on):** intake against real paid orders, bridge round-trips with loop-guard proof, REST routes via `rest_do_request` (applying UMC's four documented Store-API test gotchas), migrations idempotency, file streamer auth, uninstall. Real orders, real routes, no stubs. A `HposProofTest` mirrors UMC's pattern: a test that skips when HPOS is off, so a 0-skip run proves HPOS was active.
3. **Structural guards (mutation-verified — inject the violation, watch it fail):** `DomainPurityGuardTest` (no WP symbols in core layers), `DbConfinementGuardTest`, `WooConfinementGuardTest`, `LegacyOrderStorageGuardTest` (no `get_post`/`get_post_meta`/`wp_posts` on orders anywhere — I2), `SingleStateWriterGuardTest` (I4), `AuditAppendOnlyGuardTest` (I5), `AdminBoundaryGuardTest`, `CompositionRootTest`, `MpdsVendorGuardTest` (§8.2), `PersistedKeysInventoryTest` + `UninstallPolicyGuardTest`, MPDS markup-contract tests, `CiMatrixGuardTest`/`CompatibilityMatrixTest`, version-parity tests.

### 19.2 CI

GitHub Actions, UMC's proven shape: `phpcs` (hard gate) · `pot` check · `unit` matrix (PHP 8.1 / tested-up-to) · `integration` legs: **floor** (PHP 8.1 / WP 6.5 / WC 8.2.x), **current** (current stable PHP/WP/WC, pinned coordinates with a why-comment, guarded against drift), **ceiling** (`continue-on-error`) — per the PO's floor-plus-current-stable mandate · `build` (zip artifact) · `release-audit`. Release workflow: tag `vX.Y.Z` → header/tag parity check → build → GitHub release. Local dev/test tooling is Docker-only (dedicated `mpcf-test-runner` image + `mariadb:11.4` on an internal network, never published ports), documented in the gitignored `CLAUDE.local.md`, following the sibling plugins' template.

### 19.3 Per-milestone definition of done

`composer phpcs` clean; unit + integration + guards green; CI green including floor and current-stable legs; docs updated (`HOOKS.md`, `PERSISTED_DATA.md`, ADRs Accepted, `ROADMAP.md`); version bumped (four-place ritual); merged via PR; tagged only on explicit PO approval. One approved milestone at a time.

---

## 20. Milestone roadmap

Each milestone is a usable release, tagged, installable. Detailed execution plans (house format: reconciliation, scope table, sub-steps, sequenced commits, verification checklist) are written per milestone, one at a time, after PO approval.

| M | Version | Name | Delivers | New tables |
|---|---|---|---|---|
| **M0** | 0.0.x | Bootstrap & MPDS extraction | `mp-admin-design-system` v0.1.0 repo (tokens + the *existing* extracted component set + shell + behavior JS + contract tests; new §8.4 components land with the milestones that need them); plugin repo skeleton (main file, Plugin, Settings, Capabilities, PersistedKeys, migration framework, guard framework, CI, build/release tooling, canonical docs incl. this document); `bin/sync-mpds.sh` + vendor guard; activates inert | — (migration framework + `mpcf_db_version` only; schema v1 lands in M1) |
| **M1** | 0.1.0 | Fulfillment core — Warehouse MVP | Intake (paid → fulfillment, idempotent, CLI backfill); workflow engine + standard workflow; Queue (filters, search, bulk assign); fulfillment detail (timeline, notes, manual transitions); audit stream + hash chain; roles/capabilities + operator mode; status bridge v1; dashboard v1; uninstall policy | fulfillments, items, events, notes |
| **M2** | 0.2.0 | Packing Workspace & REST | `mpcf/v1` (fulfillments, transitions, items, notes, shipments); the workspace (checklist, packages + specs, manual carrier+tracking, sticky action bar, drawer from queue); optimistic-concurrency UX; Application Passwords documented; minimal packing slip pulled forward | shipments, packages, package_items, documents |
| **M3** | 0.3.0 | Ops UX (Workspace + Orders) | Workspace stage guidance / next-action clarity, quantity disclosure, shipped success path (M3-D); Orders read-only overview (M3-E); release stabilization & dogfood polish (M3-F). Mission Control Dashboard/Queue redesign (A/B/C) deferred post-0.3.0 | — |
| **M4** | 0.4.0 | Documents I | Assembler/renderer/template architecture beyond M2 packing slip; picking list (print-HTML, barcode payloads, branding settings, template overrides); render audit + reprint history; PDF port | (documents already from M2; additive columns/indexes as needed) |
| **M5** | 0.5.0 | Tracking & notifications | Carrier registry (EU-skewed bundled set); tracking validation hints; multi-package UX polish; notification subsystem (policy/dispatcher/EmailChannel, §16.1) with shipped email per shipment + WC-email tracking block; bridge mapping settings UI | — |
| **M6** | 0.6.0 | Package photography | Capture slots, protected store + streamer, SHA-256 audit fingerprints, photo-required workflow guard, retention purge job | media |
| **M7** | 0.7.0 | Barcode & scan mode | Scan sink → pick/pack by SKU/EAN scan; scannable queue (slip barcode opens workspace); mismatch handling; kbd/scan-first workspace mode | — |
| **M8** | 0.8.0 | Wave & batch picking | Wave aggregate (`mpcf_waves` / `mpcf_wave_members`); combined walk document; Wave Scan Mode (extends M7); wave → per-order packing handoff at `picked` | waves, wave_members |
| **M9** | 0.9.0 | Analytics I | Daily rollups (Action Scheduler + backfill CLI); Analytics screen (throughput, durations p50/p90, carrier mix, exception rates); dashboard trends; operator stats behind D17 | stats_daily |
| **M10** | 0.9.x → RC | Hardening & operational maturity | i18n complete, Site Health tests, `wp mpcf doctor`/`audit verify`, privacy exporter/eraser, performance baselines at 50k fulfillments, security review doc, `ARCHITECTURE_FREEZE.md`, compatibility matrix | — |
| **1.0** | 1.0.0 | Commercial release | Freeze public surface (hooks, REST v1, schema semantics, template contract) | — |
| M11 | 1.1.0 | Returns & RMA | Return aggregate + workflow, return slip doc, customer-initiated intake hook, refund handoff to WC | returns, return_items |
| M12 | 1.2.0 | Multi-warehouse queues & location-sorted picking | Per-warehouse queue UX and filters (existing `warehouse_id`); warehouse routing rules for outbound assignment; location-sorted pick path in Workspace and picking list **consuming** location data from `wc-inventory-overview` via a versioned contract; immutable `location_snapshot` at intake unchanged | — |
| M13 | 1.3.0 | Carrier integrations I | `CarrierPort` label purchase + tracking sync (first adapters chosen by PO — candidates: Sendcloud, nShift, EasyPost as an aggregator strategy); label documents; CN22/CN23 + commercial invoice (PDF renderer lands here) | carrier_accounts |
| M14 | 1.4.0 | Automation & webhooks | Outgoing HMAC webhooks, automation rules (event→condition→action), scoped API keys | webhooks, api_keys, rules |
| M15 | 1.5.0 | Warehouse mobile mode | PWA-style tablet frontend over `mpcf/v1` (scan-first), station login via API keys | — |

Sequencing notes: M3 Ops UX before M4 Documents because warehouse next-action clarity and Orders overview were required to make the M2 workspace usable day-to-day; Documents I (pick list / stored renders / PDF) follows once Ops UX is stable. M7 before M8 because batch picking without scanning is paper anyway; photography (M6) early because it is a headline differentiator and its guard integrates with the workflow engine; returns deliberately post-1.0 — it doubles the domain surface and deserves the stability of a frozen core.

### 20.2 Future capabilities (not scheduled)

These are documented for architectural guidance only. They are **not** in the milestone table above and are **not** required for current Biopentra warehouse operations.

| Capability | Document |
|---|---|
| Partial fulfillment & split shipments | §24.1 |

### 20.1 M1 acceptance criteria (falsifiable)

1. Paying a WooCommerce order (classic and Blocks checkout, HPOS on) creates exactly one `queued` fulfillment within the same request or the next AS tick; paying it twice creates no duplicate.
2. `wp mpcf intake backfill --status=processing` ingests existing orders idempotently.
3. The Queue lists/filters/searches 10k seeded fulfillments with indexed queries (no full scans in `EXPLAIN`) and p95 page render under target on the reference container.
4. Every transition in the standard workflow is executable exactly per §6.2 — guard-blocked transitions render disabled with the guard's reason; forbidden edges are absent from UI and rejected by the service (tested at the service layer, not the UI).
5. A Warehouse Operator account can process a fulfillment end to end but cannot see WC orders admin, settings, or cancel; a Lead can cancel (audited).
6. Order cancellation in WC moves open fulfillments to `cancelled` (audited, loop-guard proven by a test asserting no recursive bridge writes).
7. `wp mpcf audit verify <id>` passes on a processed fulfillment; manually corrupting an event row makes it fail.
8. Deactivate/reactivate loses nothing; uninstall with the flag off removes nothing; with the flag on removes everything in `PersistedKeys::inventory()`.
9. All guard tests of §19.1 exist, pass, and each fails when its violation is injected (mutation check recorded in the PR).
10. `docs/HOOKS.md`, `docs/PERSISTED_DATA.md`, ADRs 0001–0005, and `ROADMAP.md` are current; CI floor + current-stable legs green.

---

## 21. Risk register

| # | Risk | L | I | Mitigation | Verified by |
|---|---|---|---|---|---|
| R1 | Workflow engine over-engineered for real warehouses (operators want 3 clicks, we built a state calculus) | M | H | Default workflow ships tuned for the 90% case with shortcut edges; engine generality is invisible until someone customizes; M1 dogfooding on a real store before M2 | PO acceptance walkthrough per milestone |
| R2 | Packing Workspace complexity outgrows no-build JS | M | M | ADR-0003 escape hatch: REST unchanged ⇒ frontend swap is contained; watch `assets/` size/complexity at each milestone review | Milestone reviews |
| R3 | Order edited/refunded after intake → fulfillment desync | H | M | Refund/edit observers flag `problem` with diff payload; workspace always reads live order via `OrderSource`; snapshots are display-only | Integration tests simulating post-intake edits |
| R4 | Another fulfillment/status plugin fights the bridge (auto-complete plugins, custom statuses) | M | M | Bridge is configurable to passive; re-entrancy guard; Diagnostics-style passive conflict detection is a post-1.0 candidate | Conflict integration fixtures |
| R5 | `mpcf_events` growth degrades queue/timeline | M | M | Events never join queue queries; timeline paginates; rollups at M8; archival guidance | Performance baselines (M9) at 50k/500k rows |
| R6 | Queue slow on large stores | M | H | Indexed-only access paths designed up front (§7.1); seeded perf tests from M1, baseline doc like UMC's `PERFORMANCE_BASELINES.md` | `--group performance` CI leg |
| R7 | Photo storage growth / server disk exhaustion | M | M | Server-side re-encode + size caps + retention purge + Site Health disk warning | M5 tests; Site Health (M9) |
| R8 | MPDS vendoring drift or sync-script namespace-rewrite bugs | L | M | Manifest hash guard in every consumer; rewrite is deterministic and tested in the MPDS repo itself | `MpdsVendorGuardTest` |
| R9 | HPOS/legacy divergence on customer sites (floor WC 8.2 still allows legacy storage) | M | H | I2: CRUD-only access works identically under both backends; integration suite runs HPOS-on with proof-test; a legacy-storage leg in the floor CI matrix | `HposProofTest` + floor leg |
| R10 | GDPR exposure: photos/notes hold PII; per-operator stats | M | H | §17.4 inventory, exporter/eraser, retention, EXIF-GPS strip, D17 default-off operator stats | Privacy integration tests (M9) |
| R11 | Scanner hardware variance (keyboard-wedge quirks, suffix chars, layouts) | M | M | Spike S2 before M6; scan sink normalizes terminators; manual entry always available | S2 falsification test |
| R12 | Scope gravity: "fulfillment platform" absorbs inventory/shipping-rate features | M | H | §2.5 non-goals are binding; ecosystem boundary rule (§2.2) routes overlap to sibling products; candidate-area evaluation table (UGC device) in every milestone plan | PO gate per milestone |

## 22. Spike list

| ID | Spike | Blocks | Falsification test |
|---|---|---|---|
| S1 | Print-CSS fidelity: packing slip + CN22 layout across Chrome/Firefox print dialogs and common label/A4 printers | M3 | A CN22 prints with correct field positions on A6 and A4 from both engines; if not achievable, PDF renderer moves up to M3 |
| S2 | Keyboard-wedge scanner behavior in wp-admin (focus stealing, Enter/Tab suffixes, layout dependence, prefix codes) | M6 | Three scanner models tick a checklist row in <150ms without focus loss across 30-minute sessions |
| S3 | Protected-file streamer throughput (photos in workspace grid) through PHP on typical hosting | M5 | 12-photo grid loads <1s on the reference container; else pre-sized thumbnails (stored derivatives) are added to the design |
| S4 | `capture="environment"` UX on iPad/Android tablets vs getUserMedia widget | M5 | Operator captures package photo in ≤3 taps on both platforms |
| S5 | Action Scheduler latency/throughput for intake fallback and notification fan-out on constrained hosts | M1 | Seeded 200-order burst ingests within 5 min with default AS tuning |
| S6 | Guarding "no PII in event payloads" mechanically (static analysis over event constructors vs runtime redaction) | M1 | The chosen guard catches a deliberately-planted address copy in a payload |

## 23. Open decisions for the Product Owner

| # | Decision | Recommendation | Blocks? |
|---|---|---|---|
| P1 | Commercial name | Keep "Commerce Fulfillment" working title; decide before 1.0 marketing; `mpcf` never changes | No |
| P2 | Default outbound bridge mapping (`all shipped` → WC `completed`?) | Yes as default, prominently configurable, off = never touch WC status | No (M1 settings default) |
| P3 | Operator Mode default (hide wp-admin chrome for operators) | Off by default in v0.x, revisit for 1.0 | No |
| P4 | Photo default policy (optional vs required guard) | Optional by default; required is one toggle | No |
| P5 | Bundled carrier list final composition (EU skew per §11) | Confirm at M4 planning | No |
| P6 | First label-integration targets (Sendcloud vs nShift vs EasyPost-style aggregator) | Decide at M11/M12 planning with market input | No (post-1.0) |
| P7 | Pricing/licensing/update-delivery mechanism for the commercial product | Out of architecture scope; affects only an update-client adapter at the edge — decide before 1.0 | No |
| P8 | Dogfood store for M1 acceptance | Yes — a real WooCommerce dev site, same acceptance-script discipline as AIM M1 | Needed at M1 acceptance |

## 24. Future opportunities (explicitly deferred, architecture-compatible)

Admin workflow builder UI (definitions are already data); customer-facing tracking portal page + branded tracking emails; address validation/correction pre-ship; rate shopping at pack time (choose cheapest carrier for measured weight/dims); packing-material optimization (box suggestion from item dims); SLA rules & alerting (age thresholds → notifications) on the automation engine; multi-source orders (the `order_source` column + `OrderSource` port admit non-WooCommerce feeds); photo annotations; voice picking; hardware station integrations (scales via WebHID/WebSerial — reads feed the same `PackageSpec` REST field); marketplace of carrier adapters as separate paid add-on plugins hooking `mpcf_carriers`/`CarrierPort`; additional notification channels (SMS/push/Slack/Teams) as `NotificationChannel` implementations; audit investigation mode and Audit Explorer (§13); cross-plugin MP Commerce integrations (`wc-inventory-overview` feeding backorder/incoming signals, Shipping providing negotiated rates at pack time) strictly through the public surfaces of §2.2. Inbound logistics, purchase orders, and receiving are **not** MPCF future opportunities — they belong to `wc-inventory-overview` (ADR-0007, §2.6).

### 24.1 Partial fulfillment & split shipments (future capability)

**Future capability** — not in the active milestone schedule (§20). **Not required for current Biopentra warehouse operations.**

#### Business scenario

A typical case: a customer ordered five units of a line item, but only four are available in the warehouse — one is damaged, missing, or otherwise unavailable. An operator may eventually need to:

- ship the available quantity now,
- leave the remaining quantity open on the same fulfillment,
- create an additional shipment later when the remainder becomes available or is otherwise resolved.

This is distinct from multi-parcel consignments (several boxes in one handover) and from batch picking. It is about **partial quantity resolution** across one or more shipment events over time.

#### Fulfillment vs shipment

- **One fulfillment, multiple shipments.** The data model already admits N `mpcf_shipments` rows per fulfillment (§7.1, §11). A future partial-fulfillment workflow uses that shape: each carrier handover is its own shipment record.
- **A shipment may complete while the fulfillment stays open.** An individual shipment may reach `shipped` (with tracking, audit, and customer notification) without the fulfillment aggregate reaching a terminal working-state exit. The fulfillment remains in a working state until every ordered quantity on every line is accounted for.
- **Multiple shipments belong to one fulfillment.** Package-level allocation (`mpcf_package_items.qty`) is the existing hook for associating line quantities with physical consignments; line-level allocation UI is deferred (M4), but the persistence layer is already present.

#### WooCommerce order completion

The outbound status bridge (`§6.6 Woo\StatusBridge`) must not mark the WooCommerce order complete merely because the first shipment left the warehouse. **WooCommerce order completion must wait until every required quantity is either shipped, cancelled, refunded, or otherwise resolved** — evaluated across all fulfillments for that order. Today's default mapping (all fulfillments for the order have shipped → WC `completed`) is correct for the all-or-nothing M2 workflow; a partial-fulfillment future extends the completion predicate, not the bridge's event-driven shape.

#### M2 intentional non-support

Milestone 2 **deliberately does not** support partial fulfillment. Current operator behaviour remains:

- **all ordered quantity must be picked** before the fulfillment advances past picking (`all_items_picked` guard);
- **all items must be packed** before the fulfillment reaches `packed` (`all_items_packed` guard);
- **shipment only after complete picking and packing** — the fulfillment transitions to `shipped` as a whole once guards pass and the operator ships.

There is no partial-quantity advance, no "ship what we have" shortcut, and no per-line shipped-quantity tracking beyond `qty_picked` / `qty_packed` in M2.

#### M2 interim path (unchanged)

Until partial fulfillment ships, "ship what we have" is handled through mechanisms M2 already provides:

- move the fulfillment into the **exception band** (e.g. `backordered`) with a reason and ship nothing until resolved; or
- adjust the order in WooCommerce (reduce quantity, cancel line, refund) — `RefundObserver` flags the fulfillment and the workspace reads live order data through `OrderSource`.

See also §IV.5 (Partial shipment) in the M2 execution plan.

#### Architectural hooks already present (evolution, not redesign)

Future implementers should extend what M2 shipped rather than replace it:

| Hook | Role in a partial-fulfillment future |
|---|---|
| `mpcf_shipments` (N per fulfillment) | Each partial handover is an additional shipment row |
| `mpcf_package_items.qty` | Associates specific line quantities with packages |
| Data-defined `WorkflowDefinition` + guards | New partial-quantity guards and transitions without a second engine |
| Event-driven `StatusBridge` | Completion criteria can require "all quantities resolved" instead of "first shipment shipped" |
| Append-only audit (`mpcf_events`) | Each partial pick/pack/ship event remains auditable |

High-level areas likely touched in a future milestone (no schema or API design here): partial-quantity workflow guards; per-line resolution / shipped tracking; fulfillment terminal conditions; bridge completion criteria. Those details belong in a future execution plan and, where they alter invariants or public contracts, in an ADR.

#### Distinction from true split fulfillment

Two concepts must not be conflated:

1. **Partial fulfillment within one fulfillment** (this section) — ship available quantity now, remainder open, additional shipment(s) later, **one fulfillment aggregate per order** (`order_unique` unchanged).
2. **True split fulfillment** — one order → **several independently-shipping fulfillment aggregates**, requiring relaxing the `order_unique` index on `mpcf_fulfillments (order_id, order_source)`. That is a separate post-1.0 track and requires its own ADR (see §IV.13).

Documenting partial fulfillment now prevents today's all-or-nothing M2 decisions from being mistaken for permanent limitations of the shipment model — while keeping Biopentra's current workflow unchanged.

## 25. Final architecture recommendation

Build it as specified: an operator-first product (P0), a fulfillment aggregate of its own, a generic data-defined workflow engine at the center, ports-and-adapters layering with the WordPress-free core the house already knows how to test and guard, REST-first interactivity so mobile and integrations are frontends rather than rewrites, trust artifacts (append-only hash-chained audit, fingerprinted photos, versioned documents) as the commercial differentiator, and the MP Admin Design System extracted now so this is the last plugin that pays the copy-and-prefix tax — and the first member of the MP Commerce family to be born into its conventions.

Rev 2 hardened the decade-horizon seams: polymorphic assignment, package-level shipping under consignments, a single self-referential location hierarchy, channel-based notifications, ported search, and versioned event payloads. Each was chosen by the same test — *retrofitting it later is a schema migration or a refactor across consumers, while carrying it now costs a column, a nullable field, or an interface.* Ideas that failed that test (a workflow builder UI, a search index table, package-level tracking sync) stayed data-ready but unbuilt, which is where they belong.

The two places this plan deliberately spends extra early effort — the workflow engine's generality and the REST-first workspace — are justified by the roadmap's back half: returns, batches, automation, webhooks and mobile all become consumers of machinery that M1–M2 already shipped, instead of re-architectures. The two places it deliberately *avoids* effort — no JS framework, no PDF engine until customs demands one — keep the repo inside the house's no-build, low-dependency discipline that has kept three sibling plugins maintainable.

Execution follows the house ritual: this document is the frozen architecture reference; each milestone gets its own execution plan (reconciled against what actually shipped), explicit PO approval, and an independently green, tagged, installable release. First concrete step on approval: **M0 — create the two repos, extract MPDS v0.1.0 from the UMC stylesheet, and stand up the plugin skeleton whose only observable behavior is that it activates, installs its migration framework, declares HPOS compatibility, and does nothing.** The full M0 execution plan is Part II below.

---
---

# Part II — Milestone 0 Execution Plan (Rev 1 — approved, part of Architecture Freeze v1.0)

**Scope:** Bootstrap & MPDS extraction → plugin `v0.0.1`, MPDS `v0.1.0`. **Approved by the PO 2026-07-31.** Architecture (Part I) is frozen; implementation guidance from the PO (2026-07-31): MPDS must not become the critical path; M0 stays minimal; no placeholder services.

## II.1 Reconciliation record

Every checkpoint verified against Part I; discrepancies found and their resolutions (all applied as Rev 2.1 — none required a design change):

| Checkpoint | Verdict | Notes |
|---|---|---|
| Milestone roadmap | ✅ after amendment | M0 row over-scoped MPDS ("v1.0.0 … + gallery") and said "schema v1 created empty" while §7.1/M1 assign the first tables to M1. Amended: MPDS `v0.1.0` (extracted existing set), migration framework only in M0. |
| Repository layout (§5.2) | ✅ | M0 builds the strict subset listed in II.3; no placeholder services. One inconsistency fixed: `Woo/HposDeclaration.php` in the tree vs main-file declaration in §5.4 — §5.4 wins (declarations must register even when `Plugin` stays inert; sibling-proven pattern). |
| Dependency rules (§5.3) | ✅ | Enforceable from day one: guards run over mostly-empty namespaces and are mutation-verified via injected fixture violations, so they are real before the code they police exists. |
| Composition root strategy | ✅ | `Plugin::init()` in M0 registers only: textdomain, `admin_init` migration drift-check. Nothing else exists to register — which is exactly the "no placeholder services" rule. |
| DB migration strategy | ✅ **Option A recommended** (see II.2) | Framework + version tracking in M0; first business tables in M1. |
| HPOS requirements | ✅ | `FeaturesUtil` declarations (`custom_order_tables`, `cart_checkout_blocks`) from the main file at M0; `LegacyOrderStorageGuardTest` active from M0; I2 reworded (below). |
| MPDS extraction | ✅ after re-scoping | M0 = extract what exists in UMC today (tokens with the four dead-var/alpha fixes, current component set, UGC's standalone shell generalized, sticky-save/disclosure/clipboard JS, contract tests, manifest). Deferred out of M0: **all** §8.4 new components (data table, filter bar, drawer, modal, timeline, stepper, toast, kbd, scan input) — they land with M1/M2 which need them — and the visual gallery page (nice-to-have, not critical path). |
| Build tooling | ✅ | `bin/build-zip.sh`, `bin/make-pot.sh` adapted from UMC; `bin/sync-mpds.sh` new; minimal `bin/release-audit.sh` (version parity + zip content + docs presence) so the CI job exists from day one. |
| CI expectations | ✅ | §19.2 shape: phpcs · pot · unit (8.1/8.4) · integration legs floor (PHP 8.1/WP 6.5/WC 8.2.x) + current (pinned current stable, why-comment) + ceiling (`continue-on-error`) · build · release-audit. `CiMatrixGuardTest` + `CompatibilityMatrixTest` bind the matrix to `docs/COMPATIBILITY.md`. |
| PersistedKeys strategy | ✅ | M0 inventory is small and *true*: options (`mpcf_settings`, `mpcf_db_version`), capabilities + the two roles, uninstall policy. Guard tests bind it to `docs/PERSISTED_DATA.md` and `uninstall.php` from the first commit, so the discipline exists before the first table does. |
| Documentation obligations | ✅ | M0 ships: this document as `docs/ARCHITECTURE_PLAN.md`, `ROADMAP.md`, `COMPATIBILITY.md`, `PERSISTED_DATA.md`, `HOOKS.md` (truthfully near-empty, with the "deliberately NOT hooked" section), `TEST_STRATEGY.md`, `docs/adr/0001–0005` + `README.md` index, repo `CLAUDE.md` (invariants + code rules + workflow) and gitignored `CLAUDE.local.md` (Docker-only tooling, from AIM's template). Deferred to their milestones: `API.md` (M2), `SECURITY.md`/`PRIVACY.md` (M5/M9), `PERFORMANCE_BASELINES.md` (M9). |

**I2 wording review (PO request):** **Recommend the change — applied.** "HPOS-only access" implied the plugin requires HPOS to be *enabled*, which is false and contradicts R9 (the WC 8.2 floor allows legacy storage; CRUD works under both backends). New title: **"WooCommerce CRUD-only order access; HPOS compatibility mandatory."** Mechanism and requirement are now both explicit; the forbidden list (no `wp_posts`, no `wp_postmeta`, no `get_post*` on orders) is unchanged; "declared from v0.1.0" became "from the first installable release" since M0 already ships an installable zip.

## II.2 Migration strategy decision

**Recommended and adopted: M0 installs only the migration infrastructure and version tracking; M1 creates the first business tables.** Reasons:

1. The roadmap already says so — §20 lists `fulfillments, items, events, notes` as **M1's** new tables; creating them in M0 would contradict the frozen roadmap.
2. The M1 execution plan (house ritual: reconciliation → scope → sub-steps) is where the final DDL gets its scrutiny — hash-chain canonicalization, index verification against real queue queries, snapshot column sizes. Freezing DDL in M0, before that plan exists, buys nothing and risks shipping schema v1 twice.
3. Empty business tables in the wild are a liability, not an asset: v0.0.x installs would carry tables whose shape M1 might still refine, forcing ALTER steps for zero-row tables.
4. The *framework* is still fully proven in M0 without business DDL: `Migrator::TARGET = 0`, activation and the `admin_init` drift-check write `mpcf_db_version = 0`, and an integration test injects a **test-only step map** to prove per-step version persistence, idempotency, and resume-after-interruption — the AIM lessons, demonstrated on the real machinery.
5. Uninstall stays honest: M0's `uninstall.php` removes exactly what M0 creates.

## II.3 M0 scope — exhaustive

**Repo A: `magpern/mp-admin-design-system`** — `css/tokens.css` (brand + system layers; the four undefined vars defined, accent alphas tokenized as `--mpds-accent-a20/a35`), `css/components.css` (existing `umc-ui-*` set renamed `mpds-ui-*`, dual `umc-display-*` classes dropped), `php/ComponentRenderer.php`, `php/PageShell/` (Page interface, Menu, AdminPageShell, SectionNavigation, view models — generalized from UGC, zero plugin-specific strings), `js/` (sticky-save/dirty-state, disclosure, clipboard — keyed on `data-mpds-*` only), `tests/` (markup-contract per component, CSS token lint: every `var()` defined, JS-hook lint: JS never keys on `mpds-ui-*` classes), `bin/make-manifest.sh`, `docs/CONSUMING.md` (the sync contract + rewrite rules), CI (phpcs + tests), tag `v0.1.0`.

**Repo B: `magpern/mp-commerce-fulfillment`** — exactly the PO's M0 list, nothing more:

| Item | Files |
|---|---|
| Plugin bootstrap | `mp-commerce-fulfillment.php` (header incl. `Requires Plugins: woocommerce`, ABSPATH/PHP-8.1/autoload guards, `MPCF_VERSION`/`MPCF_PLUGIN_FILE`, `before_woocommerce_init` FeaturesUtil declarations, activation hook, `plugins_loaded` boot with WooCommerce guard) |
| Composition root | `src/Plugin.php` (singleton, idempotent `init()`, `activate()`; registers textdomain + drift-check only) |
| Settings framework | `src/Settings.php` (`mpcf_settings`, `SCHEMA_VERSION = 1`, pure `defaults()`/`sanitize()`; sole key in M0: `remove_data_on_uninstall = false`) |
| PersistedKeys | `src/PersistedKeys.php` + `uninstall.php` (all-or-nothing behind the flag) |
| Capability framework | `src/Capabilities.php` (§17.1 definitions; grant/revoke on activation/uninstall; roles Warehouse Operator + Warehouse Lead — defined now so the lifecycle is tested, even though no screen consumes them until M1) |
| Migration framework | `src/Infrastructure/Database/Schema.php` (names + `charset_collate()`; no DDL yet), `Migrator.php` (`mpcf_db_version`, `TARGET = 0`, empty step map, `migrate()`/`maybe_migrate()`, per-step version writes) |
| WooCommerce guard | admin notice + full inertness when WC absent |
| Structural guard framework | `tests/Support/SourceGuardTrait.php` + `DomainPurityGuardTest`, `DbConfinementGuardTest`, `WooConfinementGuardTest`, `LegacyOrderStorageGuardTest`, `CompositionRootTest`, `PersistedKeysInventoryTest`, `UninstallPolicyGuardTest`, `CiMatrixGuardTest`, `CompatibilityMatrixTest`, `PluginVersionTest` — each mutation-verified |
| MPDS vendoring proof | `bin/sync-mpds.sh` (deterministic copy + `Mpds\`→`MPCF\Vendor\Mpds\`, `--mpds-`→`--mpcf-ds-`, `mpds-ui-`→`mpcf-ui-`, `data-mpds-`→`data-mpcf-`, writes `assets/mpds/MANIFEST`), vendored copy committed, `MpdsVendorGuardTest` (hash check) + a renderer smoke test asserting rewritten output |
| CI | `.github/workflows/ci.yml` (§19.2 jobs) + `release.yml` (tag↔header parity, build, GitHub release) |
| Release tooling | `bin/build-zip.sh`, `bin/make-pot.sh`, minimal `bin/release-audit.sh`; committed POT |
| Canonical docs | listed in II.1; ADRs 0001 (custom tables), 0002 (no WC micro-statuses), 0003 (no-build JS), 0004 (protected media storage), 0005 (packages under shipments) marked Accepted; ADR-MPDS-0001 lives in the MPDS repo |

Explicitly **not** in M0: any `Domain/Engine/Application` production class (the namespaces exist only as guard-watched directories), any admin screen or menu, any REST route, any WC hook beyond the declarations/guards above, business tables, the §8.4 MPDS components, the gallery page.

Local test environment (not committed): `mpcf-test-runner` Docker image + `mariadb:11.4` on an internal-only network, per house rules; documented in `CLAUDE.local.md`.

## II.4 Commit sequence (each independently green)

**MPDS repo (A1–A6):**
A1 scaffold (composer, phpcs, CI, README, CONSUMING.md skeleton) → A2 `tokens.css` + token-lint test → A3 `components.css` + markup fixtures → A4 `ComponentRenderer` + contract tests → A5 PageShell + contract tests → A6 JS modules + JS-hook lint + `make-manifest.sh` + finalize CONSUMING.md → **tag `v0.1.0`**.

**Plugin repo (B1–B9):**
B1 scaffold: main file, composer, phpcs, gitignore, CLAUDE.md/CLAUDE.local.md, README/readme.txt → B2 canonical docs (ARCHITECTURE_PLAN, ROADMAP, COMPATIBILITY, PERSISTED_DATA, HOOKS, TEST_STRATEGY, ADRs) → B3 `Plugin` + `Settings` + unit bootstrap + `PluginVersionTest`/`SettingsTest`/`CompositionRootTest` → B4 `Capabilities` + `PersistedKeys` + `uninstall.php` + inventory/uninstall guards → B5 `Schema` + `Migrator` + integration suite bootstrap + lifecycle tests (activation, drift-check, fake-step resume, idempotency) → B6 remaining structural guards + mutation-verification evidence → B7 MPDS vendoring (`sync-mpds.sh`, vendored copy pinned to `v0.1.0`, `MpdsVendorGuardTest`, renderer smoke test) → B8 CI + release workflows + build/pot/release-audit scripts + `CiMatrixGuardTest` → B9 version-finalize `0.0.1` (four-place ritual), ROADMAP status update → PR → CI green → merge → **tag `v0.0.1` only on explicit PO approval**.

Dependency note: B7 needs the MPDS tag, so A1–A6 complete first; everything else in B is independent of A — if MPDS extraction stalls, B1–B6/B8 proceed and B7 slots in last (MPDS off the critical path, as directed).

## II.5 M0 acceptance criteria (falsifiable)

1. The built zip installs on WC 8.2.x (HPOS on) **and** current-stable WC: activates with no notice/fatal, creates **zero** tables, writes `mpcf_db_version = 0`, grants the §17.1 capabilities/roles; storefront and checkout behavior byte-identical before/after activation (no frontend hooks registered).
2. With WooCommerce absent: admin notice shown, plugin fully inert, no fatals; with PHP < 8.1: version notice, no autoload.
3. Deactivate/reactivate changes nothing; uninstall with flag off removes nothing; with flag on removes exactly `PersistedKeys::inventory()` — integration-tested both ways.
4. Migration framework proven on real machinery: fake-step test demonstrates per-step version persistence, resume after interruption, and idempotent re-run; `admin_init` drift-check fires on a stale version.
5. Every guard test in II.3 passes, and each **fails when its violation is injected** (mutation evidence recorded in the PR description).
6. MPDS: repo CI green (contract tests + token lint + JS-hook lint), tagged `v0.1.0`; `bin/sync-mpds.sh` run twice produces byte-identical output; hand-editing one vendored file fails `MpdsVendorGuardTest`; the rewritten `MPCF\Vendor\Mpds\ComponentRenderer` smoke test asserts `mpcf-ui-*` classes and zero `mpds-` remnants.
7. Plugin CI fully green including floor and current-stable integration legs; build job uploads the zip artifact; release workflow's tag↔header parity check verified.
8. `composer phpcs` clean in both repos; version parity (header / `MPCF_VERSION` / readme stable tag) test green.
9. Docs truthful: `HOOKS.md` lists exactly the hooks that exist (the declarations + drift-check) plus the deliberately-not-hooked section; `PERSISTED_DATA.md` matches the inventory (sync-tested); `docs/ARCHITECTURE_PLAN.md` is this document.
10. `src/` contains nothing beyond the II.3 list — no placeholder services (asserted by `CompositionRootTest` enumerating the wired graph).

## II.6 GO / NO-GO

**GO.** No architectural blockers found. The four discrepancies discovered were wording/scoping drift, all resolved in Rev 2.1 without touching any design decision (D1–D22 unchanged, invariants unchanged in intent). Pre-conditions to start: PO approves this Part II (house rule I14); GitHub repos created; `mpcf-test-runner` image built locally. Recommended start: MPDS commits A1–A6 and plugin commits B1–B6 in parallel tracks, B7 after the MPDS tag.

---
---

# Part III — Milestone 1 Execution Plan (Rev 1 — approved for implementation)

**Scope:** Fulfillment core — Warehouse MVP → plugin `v0.1.0`, MPDS `v0.2.0`. **Approved by the PO 2026-08-01.** Architecture (Part I) is frozen; M0 (Part II) is closed — `v0.0.1` released, `v0.1.0` (MPDS) released, both CI-green. Three scope questions the frozen architecture left open for this milestone were resolved by explicit PO decision at approval time (recorded in III.1) rather than left implicit.

## III.1 Reconciliation record

| Checkpoint | Verdict | Notes |
|---|---|---|
| M0 actually shipped what `ROADMAP.md` claimed | ✅ after wording fix | `v0.0.1` is tagged, pushed, and its Release workflow already ran green and published the zip; `mp-admin-design-system` `v0.1.0` likewise tagged, pushed, CI green. `ROADMAP.md`'s M0 entry previously read as "awaiting the tag" — corrected in this milestone's doc pass to state the release as fact, not pending. |
| M1's roadmap-table scope (§20) matches what this plan builds | ✅ | Intake, workflow engine + standard workflow, Queue, fulfillment detail, audit stream + hash chain, roles/capabilities + operator mode, status bridge v1, dashboard v1, uninstall policy, tables `fulfillments/items/events/notes` — all covered below. |
| §20.1 draft acceptance criteria still hold | ✅, adopted as-is | The ten criteria written at architecture time need no amendment; reused verbatim in III.5. |
| No `Domain/`, `Engine/`, `Application/`, `Woo/`, `Admin/`, `Cli/` code exists yet | ✅ confirmed by inspection | M0 built exactly `Plugin.php`, `Capabilities.php`, `PersistedKeys.php`, `Settings.php`, `Infrastructure/Database/{Migrator,Schema}.php`, and the vendored `Vendor/Mpds/`. Zero WooCommerce symbols anywhere in `src/` outside `Vendor/`. This milestone is genuinely greenfield for every layer below the bootstrap. |
| MPDS v0.1.0 has the components M1's UI needs | ❌ — the material gap this plan closes first | Confirmed by inspection: zero occurrences of data table, filter bar, drawer, modal, timeline, stepper, toast, kbd, or scan-input anywhere in MPDS `css/`, `js/`, `php/`. Every §8.4 component M0 deferred is still unbuilt. M1's Queue and Fulfillment Detail screens cannot be built without a data table, filter bar, drawer, timeline, and a reason-capture modal existing upstream first — hence the MPDS sub-track opens this milestone's commit sequence (III.4). |
| `docs/PERSISTED_DATA.md` already anticipates M1 | ✅ | Already states M1 introduces `mpcf_fulfillments`, `mpcf_fulfillment_items`, `mpcf_events` and `mpcf_notes` — filled in with real content, not invented, as part of this milestone. |
| Existing test/guard-test scaffolding is ready to receive real M1 code | ✅ | `DomainPurityGuardTest`, `DbConfinementGuardTest`, `WooConfinementGuardTest` already exist and pass vacuously (self-tested via planted fixtures per `docs/TEST_STRATEGY.md`) — M1 is where they start doing real work. New guard tests (single-writer, audit-append-only, admin/REST-parity) follow the identical scan-then-self-test convention. |

**PO decisions captured at approval (binding for this milestone):**

1. MPDS component work is in-scope as an early phase of M1, not a separate blocking milestone — mirrors how M0 itself handled the v0.1.0 extraction.
2. The Queue's drawer ships in M1; its primary action opens M1's own Fulfillment Detail page (not "the Workspace," which doesn't exist until M2). M2 repoints the same action later — no rework.
3. Dashboard's "print today's picking lists" quick action is omitted entirely from M1 (no disabled placeholder) — document rendering doesn't exist until M3. If no other quick action survives, the quick-actions panel is omitted entirely rather than shipped empty.

## III.2 Strategy decisions this plan makes

Flagged at M0 time (II.2) as destined for this plan — "hash-chain canonicalization, index verification against real queue queries, snapshot column sizes" — plus the PO decisions above:

1. **Hash-chain canonicalization.** `hash = sha256(prev_hash . canonical_payload)` (§13) is specified without saying what "canonical" means. Decision: `payload` is JSON-encoded with `JSON_UNESCAPED_SLASHES` and **sorted keys at every nesting level** before hashing, via a fixed `Domain\Event\Canonicalizer` pure function (WordPress-free, I6) — the same logical event always hashes identically regardless of PHP array-insertion order or locale. Unit-tested by feeding the same logical payload in two key orders and asserting an identical hash.
2. **Index verification against real query shapes.** §7.1's pre-specified indexes on `mpcf_fulfillments` — `(state, warehouse_id)`, `(order_id)`, `(assignee_type, assignee_id, state)`, `(created_at)` — are accepted as the starting migration, but not treated as final until the 10k-row `EXPLAIN` proof (acceptance criterion 3) runs against the Queue's actual filter/search query shapes. If that proof finds a missing composite index, the migration is amended before the `v0.1.0` tag, not patched afterward.
3. **Snapshot column sizing.** `customer_name_snapshot VARCHAR(191)`, `order_number_snapshot VARCHAR(64)`, etc. are accepted as specified — `191` is the house convention ceiling for indexable `utf8mb4` columns.
4. **MPDS sequencing.** MPDS component work is the first block of this milestone's commit sequence, released as `mp-admin-design-system v0.2.0`, vendored into the plugin before any Admin screen work starts (III.4).
5. **Queue drawer target.** Drawer's "Open workspace" action links to `FulfillmentDetail` in M1; the route/label is designed so M2 can repoint it at the Workspace without a drawer-component change.
6. **Optimistic-lock conflict UI.** `WorkflowService`'s typed conflict error needs UI surfacing, but `toast` is explicitly M2-scoped (workspace async save feedback). Decision: M1 surfaces conflicts as a standard WP admin notice on page reload ("someone else updated this fulfillment") — no new MPDS component required.

## III.3 M1 scope — exhaustive

**Repositories:** `mp-admin-design-system` (new minor release `v0.2.0`: data table, filter bar, drawer, timeline, modal + reason-capture variant, kbd-hint components, each with contract tests — no breaking change to v0.1.0's existing components) and `mp-commerce-fulfillment` (primary deliverable, vendors MPDS `v0.2.0` partway through the sequence). No other repository is touched by this milestone.

**Domain model additions** — four tables, `ENGINE=InnoDB ROW_FORMAT=DYNAMIC`, `BIGINT UNSIGNED AUTO_INCREMENT` ids, UTC `DATETIME`, no SQL `ENUM`, no `FOREIGN KEY` constraints (app-level integrity + indexes), DDL in `Infrastructure\Database\Schema`, applied via `Migrator` step 1 (`mpcf_db_version` 0 → 1):

- **`mpcf_fulfillments`** (aggregate root): `id, order_id, order_source, warehouse_id, workflow, state, previous_state, return_to_state, exception_reason, priority, assignee_type, assignee_id, version, order_number_snapshot, customer_name_snapshot, item_count, created_at, state_entered_at, completed_at`. Indexes: `(state, warehouse_id)`, `(order_id)`, `(assignee_type, assignee_id, state)`, `(created_at)`.
- **`mpcf_fulfillment_items`**: `id, fulfillment_id, order_item_id, product_id, variation_id, sku_snapshot, name_snapshot, qty_ordered, qty_picked, qty_packed, location_snapshot`. Indexed on `fulfillment_id`.
- **`mpcf_events`** (append-only, hash-chained): `id, fulfillment_id, event_type, actor_type, actor_id, actor_label_snapshot, payload, prev_hash, hash, created_at`. Indexed on `fulfillment_id`, `event_type`, `created_at`.
- **`mpcf_notes`**: `id, fulfillment_id, author_id, body, is_pinned, created_at`. Indexed on `fulfillment_id`.

Order meta writes remain limited to `_mpcf_*` back-pointers only (I3).

**Services, by layer:**

- *Domain* (WordPress-free, I6): `Fulfillment`/`FulfillmentItem`/`Note`, `State`/`Transition`/`WorkflowDefinition` VOs, the standard workflow's data-defined definition, `TransitionGuard` implementations (`all_items_picked`, `all_items_packed`, `package_spec_present`, `photo_required`, `has_shipment`), `DomainEvent` + concrete events, `Actor`, `Clock` port, `Event\Canonicalizer`, repository interfaces, `OrderSource` port, `SearchQuery` port.
- *Engine*: `WorkflowEngine::transition()` — pure, validates the edge, runs guards, returns rejection or approved result + events.
- *Application*: `WorkflowService` (sole state writer, optimistic lock), `IntakeService`, `EventDispatcher`, `SearchQuery` v1 implementation.
- *Infrastructure/Database* ($wpdb confined here, I7): `WpdbFulfillmentRepository`, `WpdbItemRepository`, `WpdbNoteRepository`, `WpdbEventRepository` (append-only), `Schema`/`Migrator` additions.
- *Woo* (only namespace allowed to name a WC symbol, I8): `WooOrderSource`, `IntakeHooks`, `StatusBridge` (outbound + inbound, loop-guarded), `RefundObserver`.
- *Cli*: `wp mpcf intake backfill`, `wp mpcf audit verify`.
- *Admin* (consumes the same Application services the future REST layer will use, I11 — no logic duplication despite no REST controller existing yet): `Screens/Queue`, `Screens/FulfillmentDetail`, `Screens/Dashboard`. All admin actions are nonce-based form POSTs calling `WorkflowService`/`IntakeService` directly.
- *Settings*: bridge-mapping config, schema_version bump.
- *Capabilities/roles*: no new capability strings (all eleven already exist from M0); M1 is where they are first consumed by real screens. Operator Mode ships as an M1 setting, off by default.

**Migrations:** single `Migrator` step 1, `TARGET` raised `0 → 1`, creates the four tables above. Idempotent/resumable per the existing contract — no framework change, only a new step entry.

**Tests:** unit coverage for `WorkflowEngine` per edge/guard, `WorkflowDefinition::validate()`, `Event\Canonicalizer` determinism, hash-chain computation, `SearchQuery` classification, and new guard tests (`SingleStateWriterGuardTest` I4, `AuditAppendOnlyGuardTest` I5, `AdminBoundaryGuardTest` I11) plus real (no-longer-vacuous) `DomainPurityGuardTest`/`DbConfinementGuardTest`/`WooConfinementGuardTest` and a PII-payload guard test (spike S6). Integration coverage (real WP+WC+MariaDB, HPOS on): classic+Blocks intake idempotency, CLI backfill idempotency, migration correctness, optimistic-lock conflict, `StatusBridge` outbound/inbound + loop-guard proof, `RefundObserver` post-intake-edit handling, Queue performance at 10k rows with `EXPLAIN`, capability/role screen-access enforcement, `wp mpcf audit verify` pass/fail (including deliberate corruption), uninstall/deactivate-reactivate extended to the four new tables, and an Action Scheduler 200-order burst test (spike S5). MPDS side: contract tests per new component, vendor-guard re-verification after sync.

**CI impact:** no new workflow files; the existing five-leg integration matrix is unchanged in shape (no floor move anticipated); integration-suite runtime grows materially from the new payment-simulation and AS-burst tests, worth watching but not a blocker; `release-audit.sh`'s doc-presence check is unaffected by the same doc set, which must be current by tag time (criterion 10).

## III.4 Commit sequence (each independently green)

**MPDS repo (C1–C7):** C1 data table + contract tests → C2 filter bar + contract tests → C3 drawer + contract tests → C4 timeline + contract tests → C5 modal + reason-capture variant + contract tests → C6 kbd-hint + contract tests → C7 `docs/CONSUMING.md` update + MANIFEST regeneration → release candidate ready for **tag `v0.2.0` on explicit PO approval**.

**Plugin repo (D1–D22):** D1 vendor MPDS v0.2.0 candidate via `bin/sync-mpds.sh`, `MpdsVendorGuardTest` green → D2 `Schema`+`Migrator` step 1 (four tables), `docs/PERSISTED_DATA.md` filled in → D3 Domain (aggregates, VOs, standard workflow data, `Event\Canonicalizer`), `DomainPurityGuardTest` now real → D4 Engine (`WorkflowEngine` + guards) → D5 Infrastructure repositories, `DbConfinementGuardTest` now real → D6 Application (`WorkflowService`, `EventDispatcher`, hash-chain append), `SingleStateWriterGuardTest`+`AuditAppendOnlyGuardTest` new and real → D7 spike S6 (PII-payload guard) → D8 `WooOrderSource`, `WooConfinementGuardTest` now real → D9 `IntakeHooks`+`IntakeService` (sync + AS fallback) → D10 spike S5 (AS burst test) → D11 `wp mpcf intake backfill` → D12 `StatusBridge` (outbound + loop guard) → D13 `RefundObserver` (inbound) → D14 Settings (bridge mapping, schema bump) → D15 `Screens/Queue` (data table + filter bar + drawer + bulk actions, `SearchQuery` v1) → D16 `Screens/FulfillmentDetail` (timeline, notes, manual transitions + reason modal) → D17 `Screens/Dashboard` (next-actions band + stat cards, no picking-list action) → D18 capability/role wiring (`AdminBoundaryGuardTest`), Operator Mode setting → D19 uninstall policy extended to the four tables → D20 docs (`HOOKS.md`, this document's outcomes, `ROADMAP.md` M1 row) → D21 Queue performance validation at 10k rows, amend indexes here if needed (III.2.2) → D22 full III.5 acceptance pass, all CI legs green, `release-audit.sh` green, release candidate ready for **tag `v0.1.0` on explicit PO approval**.

Dependency note: D1 needs the MPDS tag/candidate, so C1–C7 complete first; everything else in D depends on D1–D6 in the order listed — commit boundaries may shift where dependency order requires it, documented as a deviation if so.

## III.5 M1 acceptance criteria (falsifiable)

1. Paying a WooCommerce order (classic and Blocks checkout, HPOS on) creates exactly one `queued` fulfillment within the same request or the next AS tick; paying it twice creates no duplicate.
2. `wp mpcf intake backfill --status=processing` ingests existing orders idempotently.
3. The Queue lists/filters/searches 10k seeded fulfillments with indexed queries (no full scans in `EXPLAIN`) and p95 page render under target on the reference container.
4. Every transition in the standard workflow is executable exactly per §6.2 — guard-blocked transitions render disabled with the guard's reason; forbidden edges are absent from UI and rejected by the service (tested at the service layer, not the UI).
5. A Warehouse Operator account can process a fulfillment end to end but cannot see WC orders admin, settings, or cancel; a Lead can cancel (audited).
6. Order cancellation in WC moves open fulfillments to `cancelled` (audited, loop-guard proven by a test asserting no recursive bridge writes).
7. `wp mpcf audit verify <id>` passes on a processed fulfillment; manually corrupting an event row makes it fail.
8. Deactivate/reactivate loses nothing; uninstall with the flag off removes nothing; with the flag on removes everything in `PersistedKeys::inventory()`.
9. All guard tests of §19.1 exist, pass, and each fails when its violation is injected (mutation check recorded in the PR).
10. `docs/HOOKS.md`, `docs/PERSISTED_DATA.md`, ADRs 0001–0005, and `ROADMAP.md` are current; CI floor + current-stable legs green.

## III.6 GO / NO-GO

**GO for implementation.** Every scope item is traceable to a specific section of the frozen Architecture Freeze v1.0, reconciled against the actual (inspected, not assumed) state of both repos, with the three open scope questions resolved by explicit PO decision (III.1) rather than left implicit. Spikes S5 and S6 are real open risks, sequenced early (D7, D10) to fail fast if they don't pan out. Pre-conditions to start: PO approves this Part III (house rule I14) — granted 2026-08-01. Tags (`v0.2.0` MPDS, `v0.1.0` plugin) are cut only on a separate, explicit PO go-ahead after this milestone's acceptance criteria are met — not automatically at the end of the commit sequence.

## III.7 Actual outcomes (recorded at D20, against the D1–D22 commit sequence actually run)

This section records what the commit sequence in III.4 actually produced, not what it planned to produce — written after D1–D19 were implemented, as documentation reconciliation (D20) requires ("documentation must describe reality, not intention"). Nothing in this section changes an architectural decision; where implementation diverged from III.2/III.3, the divergence is additive or a defect fix, recorded here rather than folded silently into the plan text above.

**Schema ended up at version 3, not 1.** III.2.2 anticipated the pre-specified `mpcf_fulfillments` indexes might need amendment only after the 10k-row `EXPLAIN` proof (D21). In practice two additive index needs surfaced earlier, each as a separate idempotent `Migrator` step rather than a rewrite of step 1's DDL (the same mechanism III.2.2 itself named):
- **Step 2** (`order_unique`, D9 part 1) — a unique key on `mpcf_fulfillments (order_id, order_source)`, added when `IntakeService`'s idempotency requirement made a database-enforced guarantee (not just check-then-insert) concrete.
- **Step 3** (`customer_name_snapshot` on `mpcf_fulfillments`, `sku_snapshot` on `mpcf_fulfillment_items`, D15 part 1) — added when `SearchQuery` v1's customer-name and SKU prefix lookups needed to stay indexed rather than fall back to a full scan.

Both steps are additive-only (`ALTER TABLE ... ADD KEY`/`ADD UNIQUE KEY`), idempotent (guarded by `SHOW INDEX` checks), and covered by migration tests. D21's 10k-row proof (III.5 criterion 3) ran against the real Queue/Dashboard query shapes as originally planned — this reordered when two of the needed indexes were discovered, it did not skip the proof itself. Result: no full table scan, no N+1, no non-scaling plan at 10,000 seeded fulfillments; every p95 comfortably under target. **No further migration amendment was required** — full evidence in `docs/QUEUE_PERFORMANCE_VALIDATION.md`.

**Two guard tests named in §19.1 were missing until D20.** `SingleStateWriterGuardTest` (I4) and `AuditAppendOnlyGuardTest` (I5) were never written during D6, though the behavior they exist to guard (`WorkflowService` as the sole caller of `Fulfillment::apply_transition()`; `WpdbEventRepository` as the only class naming `Schema::EVENTS`, exposing no `UPDATE`/`DELETE`) was built correctly. Documentation reconciliation surfaced the gap against acceptance criterion 9 ("all guard tests of §19.1 exist"); both were added in D20 following the existing scan-then-self-test convention. `Admin\FulfillmentDetailPage::apply_transition()` was renamed to `submit_transition()` first — it coincidentally shared a name with the Domain mutator, which made a literal call-token scan for I4 ambiguous; no behavior changed.

**PO decisions from III.1/III.2 shipped exactly as decided:** the Queue drawer's primary action opens `FulfillmentDetail` (D15); Dashboard's quick-actions panel is omitted entirely rather than shipped with a disabled picking-list placeholder, per `Admin\DashboardPage`'s own docblock (D17); optimistic-lock conflicts surface as a standard WP admin notice on redirect, not a toast (D16) — `toast` remains unbuilt, correctly deferred to M2.

**Hash-chain canonicalization (III.2.1) shipped as specified** — `Domain\Event\Canonicalizer` sorts keys at every nesting level before hashing, unit-tested by feeding the same logical payload in two key orders.

**No REST route, no `do_action`/`apply_filters` extension point exists in M1** — confirmed by a full scan of `src/`. §16.2's "extension points at v1" (`mpcf_workflows`, `mpcf_carriers`, `mpcf_document_types`, `mpcf_event`, `mpcf_workspace_flags`, `mpcf_intake_should_create`) name the eventual v1.0 commercial-release surface, not an M1 commitment — III.3's own scope list never included them. The superseded `docs/HOOKS.md` draft paraphrased this as "Milestone 1 onward introduces...", which read as an M1 promise it was never architecturally obligated to keep; D20 corrects that paraphrase so the document stops implying these hooks exist today. This is a documentation-wording fix, not a scope cut — M2's REST layer remains the next place a public extension surface is actually planned.

**Post-release addendum — `v0.1.1` (2026-08-02).** Milestone 2 planning reconciliation (§IV.3) found one real defect this section's original pass missed: `Plugin::wire_admin()` built its own `WorkflowService` against a fresh, subscriber-less `EventDispatcher`, instead of the one `wire_services()` built and subscribed `Woo\StatusBridge` to. Consequence: a transition submitted from the Fulfillment Queue or Fulfillment Detail screen dispatched `fulfillment.state_changed` to no subscriber at all — the outbound bridge ("all fulfillments shipped → WC order `completed`") only ever fired for `RefundObserver`-driven transitions, never an operator-initiated one. Acceptance criterion 6's evidence (`Woo\StatusBridgeTest`/`Woo\RefundObserverTest`) did not catch this because, like every `FulfillmentDetailPage`/`QueuePage` integration test, it hand-builds its own `WorkflowService`/`EventDispatcher` pair to exercise the class under test in isolation — a genuine test-design blind spot (the real composition root was never touched), not a flaky assertion or an implementation defect the acceptance pass should have caught differently. Fixed in `v0.1.1` (§IV.2's P1): `Plugin::init()` now constructs the repositories, `EventDispatcher`, `Clock`, `Settings` and the one `WorkflowService` exactly once and hands the same instances to both `wire_services()` and `wire_admin()`; neither is stored as a property, so `CompositionRootTest`'s bookkeeping-only-properties guard is unaffected. A new `AdminStatusBridgeWiringTest` boots the real `Plugin::instance()->init()` object graph (recovering the real `FulfillmentDetailPage` instance from the real `admin_menu` registration, not a hand-wired equivalent) and is mutation-verified: reintroducing the old defect makes it fail with the exact original symptom. Full evidence: `docs/M1_RELEASE_REPORT.md`'s addendum. No architectural decision altered — this is a defect fix within M1's already-approved scope, not a design change.

---
---

# Part IV — Milestone 2 Execution Plan (Rev 1 — approved for implementation)

**Scope:** Packing Workspace & REST → plugin `v0.2.0`, MPDS `v0.3.0`; preceded by a
prerequisite defect-patch release `v0.1.1`. **Approved by the PO 2026-08-02.** Architecture
(Part I) is frozen; M1 (Part III) is closed — `v0.1.0` released, `v0.2.0` (MPDS) released,
both CI-green, both independently re-verified against their published release assets
(`docs/M1_RELEASE_REPORT.md`). Four scope questions were put to the PO before this plan was
written and resolved by explicit decision at approval time (recorded in IV.0) rather than left
implicit — the same house discipline III.1 applied to M1's three open questions.

**Context.** Milestone 1 shipped and closed on 2026-08-02 (`mp-commerce-fulfillment v0.1.0`,
`mp-admin-design-system v0.2.0`). The plugin can now ingest paid orders, run them through a
data-defined workflow, and show a lead a Queue, a Detail page and an operational Dashboard. What it
cannot do is **pack anything**. There is no screen where an operator checks items off, records a box
weight, enters a tracking number and hands the parcel to a carrier — every state change today is a
form POST on a read-oriented page built for understanding, not for doing.

M2 closes that gap. It is the milestone the whole architecture was shaped around: the REST-first
Packing Workspace (D6, I11, §9.4), the shipment/package data model (D19, ADR-0005), and the first
public API surface. After M2 a warehouse operator processes an order end to end without leaving one
screen, without a page reload, and — if they want — without touching the mouse.

This document was appended to `docs/ARCHITECTURE_PLAN.md` as **Part IV** on PO approval
(2026-08-02), exactly as D0 appended Part III for M1 — the same house ritual (house rule I14).

---

## Deliverable shape

| Artifact | Version | Repo |
|---|---|---|
| Patch release (M1 defect) | `v0.1.1` | `mp-commerce-fulfillment` |
| Design system | `v0.3.0` | `mp-admin-design-system` |
| Milestone 2 | `v0.2.0` | `mp-commerce-fulfillment` |

Files that change most: [src/Plugin.php](src/Plugin.php),
[src/Infrastructure/Database/Schema.php](src/Infrastructure/Database/Schema.php),
[src/Infrastructure/Database/Migrator.php](src/Infrastructure/Database/Migrator.php),
[src/Application/WorkflowService.php](src/Application/WorkflowService.php),
[src/Admin/FulfillmentDetailPage.php](src/Admin/FulfillmentDetailPage.php),
[src/Admin/QueuePage.php](src/Admin/QueuePage.php), [src/Admin/Assets.php](src/Admin/Assets.php),
[src/Settings.php](src/Settings.php), [src/PersistedKeys.php](src/PersistedKeys.php),
plus new `src/Api/`, `src/Documents/`, `src/Domain/Shipping/`, `assets/admin/js/`, `tests/browser/`.

---

## IV.0 Product Owner decisions captured at planning (binding)

Four scope questions were put to the PO before this plan was written. All four are answered and
binding for this milestone:

1. **The M1 event-dispatcher defect ships as its own patch release `v0.1.1` before M2 implementation
   starts** — not folded into M2. Rationale: the M1 line stays honest for anyone already running
   `0.1.0`. Scope in IV.2.
2. **Multi-package: "add package" ships in M2** with per-package weight, dimensions and colli
   tracking number. Allocating individual line quantities across packages stays M4 ("multi-package
   UX polish"); M2 auto-allocates every packed line to package 1.
3. **A minimal packing slip is pulled forward from M3 into M2.** A packing station with nothing to
   put in the box is not a finished workflow. Scope is deliberately narrow (IV.7) — print-HTML only,
   one document type, no storage, no PDF, no template override chain.
4. **Playwright joins the repo as a dev-and-CI-only browser test toolchain.** Constraints set by the
   PO, binding: Node artifacts never ship; `package.json`, Playwright config and browser tests are
   development artifacts; the release audit must actively prove no Node artifact reaches the zip;
   PHPUnit remains the primary correctness tier; Playwright complements it for real browser
   behaviour, accessibility, keyboard workflows and JS interaction. Requires **ADR-0006** (IV.4).

---

## IV.1 Objectives

1. An operator processes a fulfillment from `queued` to `shipped` on one screen, with no full-page
   reload, no mouse required, and a slip printed for the box.
2. `mpcf/v1` exists and is the only path the workspace uses — no privileged admin side-channel
   (I11). Every workspace capability is an API capability, which is what makes M14's tablet mode a
   frontend project.
3. `Shipment` and `Package` become real: multiple shipments per fulfillment, multiple packages per
   shipment, tracking at either level, carrier recorded through a port.
4. Guard evaluation stops being caller-asserted and starts being data-derived (IV.3, finding B).
5. The public surface that freezes additive-only at this milestone (§4 governance) is designed
   deliberately, documented completely in `docs/API.md`, and reviewed before it is written.

Non-objectives, stated so they cannot creep: no carrier API calls, no label purchase, no rate
shopping, no photos, no batch picking, no analytics, no scanning semantics beyond the focus
architecture, no returns.

---

## IV.2 Pre-milestone patch: `v0.1.1`

Not part of M2's scope table; a separate, tiny release that must be tagged before F1 begins.

**The defect.** [src/Plugin.php:208](src/Plugin.php#L208) constructs `wire_admin()`'s
`WorkflowService` with `new EventDispatcher()` — a *second*, empty dispatcher. The one carrying the
`StatusBridge` subscription is built in `wire_services()` at
[src/Plugin.php:146](src/Plugin.php#L146)/[:177](src/Plugin.php#L177). Consequence: **every
transition initiated from the Queue or the Fulfillment Detail screen dispatches to nobody.** The
outbound bridge ("all fulfillments shipped → WC order `completed`") only ever fires for transitions
driven by `RefundObserver`. M1's `StatusBridgeTest` passes because it exercises the service graph
directly, never the admin graph — a genuine coverage blind spot, not a flaky test.

| # | Commit | Content |
|---|---|---|
| P1 | Composition-root fix | One `EventDispatcher` and one `WorkflowService`, built once and shared by `wire_services()`/`wire_admin()`. Repositories, `Clock` and `Settings` likewise constructed once. `CompositionRootTest`'s allowlist is unchanged (no new class). New integration test: an admin-initiated `packed → shipped` on the last open fulfillment for an order moves the WC order to `completed` — asserted through the real admin code path, not the service graph. |
| P2 | Vendor stamp correction | `assets/mpds/SOURCE_TAG` still reads `v0.2.0-rc (pending PO tag approval…)` — accurate when vendored during D1, stale since the tag was published. Re-run `bin/sync-mpds.sh v0.2.0`; `MpdsVendorGuardTest` green. Nominated for a patch release by `docs/M1_RELEASE_REPORT.md` itself. |
| P3 | Release ritual | Version four-place bump to `0.1.1`; a short post-release addendum to `docs/M1_RELEASE_REPORT.md` and to `ARCHITECTURE_PLAN.md` §III.7 recording the defect and its fix (the milestone-history-stays-truthful rule); `ROADMAP.md` M1 entry updated. CI green → tag `v0.1.1` **on explicit PO approval**. |

`v0.1.1` is a prerequisite for F1, not a gate on planning approval.

---

## IV.3 Reconciliation record

Every M1 assumption M2 depends on, verified by inspection of the shipped code — not assumed.

| # | Checkpoint | Verdict | Notes |
|---|---|---|---|
| 1 | Architecture Freeze v1.0 still authoritative | ✅ | No invariant, D-decision, layer rule, data-model semantic, engine contract or public-surface rule is changed by this plan. Two roadmap-sequencing amendments (IV.4) and one ADR (IV.4) are the only document changes. |
| 2 | M1 shipped what ROADMAP/§III.7 claim | ✅ with one defect | Everything in the M1 release report is present in the tree. The one thing neither the report nor §III.7 records is the dispatcher defect above — found by this plan's inspection, fixed by `v0.1.1`. |
| 3 | Application services are genuinely reusable by REST (I11) | ✅ | `QueueService`, `FulfillmentDetailService`, `NoteService`, `AssignmentService`, `WorkflowService`, `DashboardService` are all WordPress-free and take no `$_POST`. `AdminBoundaryGuardTest` already proves Admin never bypasses them. M2's REST controllers call the same objects — this is the single biggest reason M2 is cheap. |
| 4 | `WorkflowService::transition()` guard flags are caller-asserted | ❌ **finding B — must change** | `$package_spec_present`/`$has_shipment`/`$photo_requirement_satisfied` are booleans supplied by the caller. `FulfillmentDetailPage::submit_transition()` passes `true, true` unconditionally ([FulfillmentDetailPage.php:471](src/Admin/FulfillmentDetailPage.php#L471)); `QueuePage::apply_advance()` passes the defaults `false, false` ([QueuePage.php:283](src/Admin/QueuePage.php#L283)). The same edge therefore behaves differently depending on which screen you are on. Correct for M1 (no shipment model existed); indefensible in M2 (one does). Resolution in IV.3.B. |
| 5 | Display-path guard evaluation is honest | ❌ **finding C — must change** | `FulfillmentDetailPage::render_transitions()` builds `new TransitionContext( array(), true, true, true )` — an **empty item list**, so `all_items_picked`/`all_items_packed` pass vacuously and the button renders enabled even when nothing is picked. Same fix as finding B. |
| 6 | Admin does not instantiate Engine classes | ❌ **finding D — must change** | `FulfillmentDetailPage::__construct()` does `new WorkflowEngine( GuardRegistry::standard() )` ([FulfillmentDetailPage.php:129](src/Admin/FulfillmentDetailPage.php#L129)) — a peer construction outside the composition root, and duplicated rule knowledge at the edge. §9.4 already specifies the correct shape: the UI *asks* (`GET /fulfillments/{id}/transitions`). Resolution in IV.3.B. |
| 7 | I4 (single state writer) survives M2's new writers | ✅ | `SingleStateWriterGuardTest` scans for callers of `Fulfillment::apply_transition()`. `PackingService` and `ShippingService` never call it — they mutate items, shipments and packages. **Shipment status is explicitly not fulfillment state** (IV.6); I4 governs `mpcf_fulfillments.state` only, and this plan states that boundary in the document so a future reader does not mistake `mpcf_shipments.status` for a second state machine that escaped the engine. |
| 8 | I5 (append-only audit) survives | ✅ | Every new mutation appends to `mpcf_events` through the same `EventRepository::append()`. No update, no delete. |
| 9 | `PayloadGuard` accepts M2's payload shapes | ⚠️ constraint | The key denylist forbids `address`, `street`, `city`, `zip`, `postal`, `phone`, `email` at any nesting level ([PayloadGuard.php](src/Domain/Event/PayloadGuard.php)). Shipment payloads must therefore use `carrier_id`, `service`, `tracking_number`, `weight_grams`, `length_mm` — never a recipient-address copy. This is the guard working as designed; every new event type gets an explicit PayloadGuard test. |
| 10 | `order_unique` UNIQUE (order_id, order_source) blocks nothing M2 needs | ✅ | Partial shipment in M2 is *N shipments under one fulfillment*, never *N fulfillments per order*. The constraint stands; relaxing it remains a future ADR (IV.13). |
| 11 | `FulfillmentItem::record_picked()/record_packed()` exist and are tested but unused | ✅ | Confirmed: only tests call them today. `FulfillmentItemRepository::save()` likewise. M2's `PackingService` is their first production caller — the domain mutators need no change. |
| 12 | Optimistic lock is usable as the workspace's concurrency token | ✅ with an addition | `WpdbFulfillmentRepository::save()` always advances `version` in its own `SET`, so a matched row reports an affected row even when nothing else changed — deliberate, documented, and exactly what M2 needs. M2 adds `FulfillmentRepository::touch()` (bump `version` only, conditioned on the current value) so item/shipment writes can advance the aggregate's token without a non-workflow path rewriting state columns. |
| 13 | Schema is at `mpcf_db_version = 3`; migration framework proven | ✅ | Steps are idempotent and resumable, guarded by `SHOW TABLES`/`SHOW INDEX`. M2 appends steps 4 and 5; no framework change. |
| 14 | `Settings::SCHEMA_VERSION = 3`, purely additive bumps | ✅ | M2 → 4. `sanitize()` always rebuilds from `defaults()`, so no destructive migration step exists to write. |
| 15 | Capabilities cover M2 | ✅ | All eleven exist since M0. `mpcf_manage_shipments` already gates the `packed → shipped` edge in `StandardWorkflow`; `mpcf_render_documents` exists for the slip. **No new capability string.** |
| 16 | MPDS v0.2.0 has the components the workspace needs | ❌ — the gap this plan closes first | Verified against `css/components.css`: no toast, stepper, checklist, quantity control, unit input, repeater, action bar, workspace layout or scan input. Nine components are missing. The MPDS sub-track (E1–E9) opens the commit sequence, exactly as C1–C7 opened M1's. |
| 17 | `timeline_for_fulfillment()` is unbounded | ⚠️ | Returns the whole chain. Fine at M1's ~8 events/fulfillment; M2 roughly doubles that and §13 mandates burst-aggregated item events specifically to avoid row explosions. M2 paginates the timeline and implements burst aggregation (IV.10). |
| 18 | Perf proof's index conclusions still hold | ⚠️ re-measure | `docs/QUEUE_PERFORMANCE_VALIDATION.md` explicitly says the Dashboard's today-counters preferred `created_at` over `event_type` *because M1 has only one event type*, and to "revisit if a future milestone adds enough additional event types". M2 adds eight. The proof is re-run (F23). |
| 19 | ADR-0003 permits a dev-only Node toolchain | ❌ — needs ADR-0006 | Its Consequences clause says "No `package.json`, no npm, no bundler in this repository". The PO's Playwright decision contradicts that sentence literally. Governance requires the ADR first, then the document, then the code. ADR-0006 is commit F0. |
| 20 | ADR-0005 vs §9.4 on multi-package UI | ⚠️ resolved by PO | ADR-0005 says the M2 UI "only exercises the single-package path"; §9.4 describes an "add package" action. Resolved by PO decision 2: add-package ships, line allocation does not. ADR-0005's Consequences gain a one-line note that its M2-UI remark was about schema economics, not a UI ceiling. |

### IV.3.B Resolution for findings B, C and D (one change, three symptoms)

All three are the same root cause: **transition eligibility is assembled by the caller instead of by
the application layer.** M2 fixes it once:

- New `Application\TransitionContextFactory` builds a `TransitionContext` from real data —
  `FulfillmentItemRepository` for line quantities, `ShipmentRepository`/`PackageRepository` for
  `package_spec_present`/`has_shipment`/`has_tracking`, `Settings` for the photo requirement (still
  trivially satisfied until M5).
- `WorkflowService::transition()` drops its three boolean parameters and uses the factory. Signature
  becomes `transition( int $id, string $target, Actor $actor, ?string $reason = null )`. Internal
  class structure is not public API (§16.2), so no ADR.
- New `WorkflowService::available_transitions( int $id, callable $can ): list<AvailableTransition>`
  returns, per candidate target: target key, label, whether approved, guard rejection code and
  message, whether a reason is required, and the required capability. `$can` is the injected
  capability predicate, keeping the service WordPress-free (I6).
- `FulfillmentDetailPage` consumes `available_transitions()` and **deletes its private
  `WorkflowEngine`**. `GET /mpcf/v1/fulfillments/{id}/transitions` returns the same list. One rule
  source, three consumers.

**Upgrade consequence, which must be tested.** Once `has_shipment` is derived from real data,
fulfillments already sitting in `packed` on a `0.1.x` install can no longer be shipped until a
shipment exists. That is correct behaviour, but it is a visible behaviour change on upgrade. It gets
its own integration test and a line in the release notes.

---

## IV.4 Documents to amend, and the one new ADR

| Change | Kind | Why it is not an architecture change |
|---|---|---|
| **ADR-0006 — Dev-only browser test toolchain** (new, Accepted at F0) | ADR | Narrows ADR-0003's *Consequences*, not its Decision. Shipped code stays framework-free and build-free; the runtime path is untouched; Node exists only in `devDependencies` and CI. ADR-0003's Status gains "Superseded in part by ADR-0006". |
| §20 roadmap: M2 row gains `mpcf_shipments/packages/package_items/documents` + packing slip; M3 row loses the packing slip and becomes "Documents I — pick list, stored renders, PDF port, template overrides, branding, reprint history" | Roadmap sequencing | §20 is not in the ADR-gated list (§3 invariants, §18 D-decisions, §5 layer rules, §7 data-model *semantics*, §6 engine contract, §16 public surface). Moving *when* a specified feature lands is the PO's call; nothing about the five-stage pipeline (§10) or the table's shape (§7.1) changes. |
| §7.1: `mpcf_documents` annotated (M2) instead of (M3) | Roadmap sequencing | Same reasoning. Same DDL. |
| ADR-0005 Consequences: one-line clarification on the M2-UI remark | Editorial | Records PO decision 2. |
| `docs/API.md` (new) | Documentation | Mandated by §16.2 for M2. |
| `docs/PRINT_VALIDATION.md` (new) | Documentation | Spike S1 evidence record. |

---

## IV.5 The Packing Workspace

The heart of the milestone. Everything below derives from P0 (§2.1) — speed, clarity, low cognitive
load, auditability, minimal clicks, deterministic workflows — and §9.4.

### IV.5.1 Placement, entry and exit

- Slug `mpcf-workspace`, URL `admin.php?page=mpcf-workspace&fulfillment_id=N`. Registered as a real
  submenu page then immediately `remove_submenu_page()`d, exactly as `FulfillmentDetailPage` is
  ([Plugin.php:234-248](src/Plugin.php#L234-L248)) — reachable, capability-checked, never a nav item.
- Entry points: Queue row `Enter`, Queue drawer's primary action (repointed from Fulfillment Detail
  — the repoint §III.2.5 promised, requiring no drawer change), Dashboard next-actions rows, and a
  direct URL.
- **Entry points are real `<a href>` anchors, never JS-only buttons.** Middle-click and
  `Shift`+click must open a second monitor's worth of workspace. This is a hard requirement, not a
  nicety (IV.5.6).
- Exit: `Esc` from a clean workspace returns to the Queue with filters preserved; with unsent
  changes it warns first.
- Capability to view: `mpcf_view_queue`. Every action re-checks its own capability server-side.

### IV.5.2 Layout

Three regions, named in the MPDS `workspace-layout` primitive so the same grammar serves M14's
tablet mode.

```
┌─ context ────────┬─ the work ───────────────────┬─ the outcome ────────┐
│ Order #1042      │  ●━━━━●━━━━○━━━━○━━━━○       │ SHIPMENT             │
│ 2026-08-02       │  queued picking picked …     │  Carrier  [PostNord] │
│ Card · Blocks    │                              │  Service  [MyPack]   │
│                  │  ┌──────────────────────┐    │  Tracking [________] │
│ SHIP TO      [⧉] │  │[img] Blue Widget     │    │                      │
│ Anna Andersson   │  │      SKU-1042        │    │ PACKAGES             │
│ Storgatan 1      │  │      − [ 2 / 3 ] +   │    │  #1  1200 g          │
│ 111 22 Stockholm │  └──────────────────────┘    │      30×20×10 cm     │
│ SE               │  ┌──────────────────────┐    │  [+ Add package]     │
│                  │  │[img] Red Gadget   ✓  │    │                      │
│ ⚑ Customer note  │  │      SKU-2001        │    │ DOCUMENTS            │
│ ⚑ High value     │  │      − [ 1 / 1 ] +   │    │  [Print packing slip]│
│                  │  └──────────────────────┘    │                      │
│ 📌 Fragile —     │                              │ NOTES  [+]           │
│    double box    │  [ Complete all ]            │ TIMELINE (last 5)    │
│                  │  ⌨ scanner ready             │                      │
├──────────────────┴──────────────────────────────┴──────────────────────┤
│ #1042 · Packing        [ Problem… ] [ ⋯ ]     [   Mark packed  ⌃⏎   ]  │
└─────────────────────────────────────────────────────────────────────────┘
```

**Breakpoints.**

| Width | Layout |
|---|---|
| ≥1280px | Three columns, 22% / 48% / 30%. Action bar spans full width, sticky bottom. |
| 1024–1279px | Two columns: context collapses into a header strip above the work column (address behind a disclosure, flags and pinned notes always visible). Outcome column keeps its width. |
| 768–1023px (tablet portrait) | Single column, stacked: context strip → work → outcome. Action bar stays sticky bottom. All targets ≥56px. |
| <768px | Same single column; the outcome column's package repeater collapses to one card at a time. Not a supported target in M2, but must not break. |

**Region contents.**

*Context (left).* Order number, order date, payment-method badge, channel; ship-to address block
formatted per the store's country format, with a copy button (MPDS `clipboard.js` already exists);
`View order` link for `manage_woocommerce` holders only (M1's existing rule); flag row — customer
note present, high value, repeat problem customer — rendered from the `mpcf_workspace_flags` filter
(§9.4's named extension slot, shipping in M2 with three bundled flags); pinned notes rendered
prominently *before* packing starts, which is their entire job (§14).

*The work (centre).* The MPDS `stepper` showing the fulfillment's position in its workflow
definition (not a hardcoded list — states come from `WorkflowDefinition`, so a custom workflow gets
a correct stepper for free). Then the item checklist:

- One MPDS `checklist-row` per `mpcf_fulfillment_items` row, minimum 64px tall.
- Product thumbnail 48px (read live through `OrderSource`, never a stored copy), name, SKU in a
  monospace, user-selectable span, location placeholder (empty until M11).
- MPDS `quantity-stepper`: `−  2 / 3  +`, both buttons ≥56px, `aria-valuenow/min/max` on the group,
  clamped `0..qty_ordered` client-side and server-side.
- **The whole row is the increment target.** Clicking or tapping anywhere that is not the `−` button
  increments by one. That is the single biggest click-count win available and it is why the row is
  64px, not 40px.
- Row renders complete (checkmark, muted, reduced contrast weight) at `qty == qty_ordered`.
- `Complete all` button sets every line to its ordered quantity in one batch call.
- `Collapse completed` toggle for long orders, so a 30-line order stays a one-screen job.
- **Scan sink**: a single always-focused, visually-hidden-but-focusable input (MPDS `scan-input`)
  that captures keyboard-wedge output. In M2 it *captures and displays* the scanned string and
  echoes an inline "scanning is not yet wired to items — M6" hint; it does not decode. Its purpose
  in M2 is the focus architecture (§9.4: "the DOM/focus architecture for it exists from M2 so M6 is
  additive"). A visible `⌨ scanner ready / paused` indicator tells the operator whether keystrokes
  will land there.

*The outcome (right).* Shipment panel: carrier `<select>` from the `CarrierRegistry` port, service
free text, consignment tracking number, tracking-URL override behind a disclosure. Packages
repeater: one card per package with weight and L×W×H, each an MPDS `unit-input` whose suffix comes
from the store's own configured units; `+ Add package` appends a card; remove is available while the
shipment is `pending`. Documents: `Print packing slip`. Notes: add field + list, pinned first.
Timeline: last five events with a link to Fulfillment Detail for the full chain.

*Action bar (sticky bottom, full width).* Left: fulfillment identity, current state badge, and a
conflict/pending-writes indicator. Right: `Problem…` (opens the reason modal), an overflow `⋯` menu
for rarer approved transitions, and exactly **one primary button** whose label and target come from
`available_transitions()` — `Start picking` → `Mark picked` → `Start packing` → `Mark packed` →
`Ship`. Primary is ≥56px tall, ≥200px wide, and sits in the bottom-right corner (Fitts's law: a
screen corner is an infinitely large target). When the engine rejects the next forward edge, the
button is disabled and the guard's own message renders directly beneath it — never a generic error,
never a silent no-op.

### IV.5.3 Keyboard-first operation

The complete map. It is rendered by `?` into a shortcut sheet composed from the existing MPDS
`modal` + `kbd-hints` components (no new component needed).

| Key | Action |
|---|---|
| `Ctrl/Cmd + Enter` | Primary action |
| `j` / `k` | Move item focus down / up |
| `Space` or `Enter` | Increment focused line by 1 |
| `Shift + Space` | Decrement focused line by 1 |
| `a` | Complete focused line |
| `Shift + A` | Complete all lines |
| `c` | Toggle collapse-completed |
| `/` | Focus the scan sink |
| `t` | Focus tracking number |
| `w` | Focus package 1 weight |
| `n` | Focus new-note field |
| `p` | Open the Problem modal |
| `P` (shift) | Print packing slip |
| `[` / `]` | Previous / next fulfillment in the queue slice |
| `?` | Shortcut sheet |
| `Esc` | Close modal/drawer → return focus to the scan sink; from a clean workspace, back to Queue |

Suppression rules, matching the existing `data-table-keynav.js` convention: every single-letter
binding is ignored while focus is inside a form field and while any modifier is held, so normal
typing and browser shortcuts are never intercepted.

**The queue cursor** (`[` / `]`) is the milestone's second-biggest click saver. The workspace
receives the queue's current filter/sort slice as an opaque cursor in the URL; `]` navigates to the
next fulfillment in that slice without a trip back through the Queue. After a successful `Ship`, a
toast offers `Next order →` with focus already on it; `]` or `Enter` takes it. Auto-advance is a
setting (`auto_advance_after_ship`), **default off** — surprise is reserved for exception states
(P0, principle 6).

### IV.5.4 Focus discipline

One `FocusManager` owns focus for the whole screen. Three rules, and they are testable:

1. **Resting focus is the scan sink.** Whenever no field is deliberately focused, focus returns
   there. This is what makes a scan gun work without the operator clicking anything.
2. **A network response never steals focus.** Optimistic rendering means the DOM updates while the
   operator is still typing; the manager records the active element and selection before a re-render
   and restores both after.
3. **Modals trap focus and return it.** MPDS `modal.js` already autofocuses
   `[data-mpds-modal-autofocus]`; M2 adds return-focus-to-opener on close.

### IV.5.5 Barcode / scanner preparation

M2 builds the architecture, M6 builds the semantics. Concretely, M2 delivers:

- The always-focused sink and its ready/paused indicator.
- Terminator normalisation: `Enter`, `Tab` and CR/LF suffixes are all treated as "scan complete", so
  M6 inherits a normalised string regardless of scanner configuration (spike S2's known variance).
- A dispatch seam: the sink emits a `mpcf:scan` custom event carrying the raw string. M6 subscribes
  a decoder; M2 subscribes only the debug echo.
- Timing tolerance: a scan arrives as a burst of keystrokes in <100ms. The sink buffers on a
  50ms-quiet-period boundary, so partial bursts are never dispatched.
- Everything the sink does is also achievable by hand — manual entry is never removed (R11).

### IV.5.6 Ergonomics: the warehouse-floor checklist

| Constraint | How the design answers it |
|---|---|
| **Minimal clicks** | Default-configured simple order: open from queue (1) → `Shift+A` complete all (1) → type tracking (typing) → `Ctrl+Enter` ×2 (`Mark packed`, `Ship`) = **4 interactions**, zero mouse. §2.1's stated target is met and beaten. |
| **Minimal scrolling** | Everything for a ≤6-line order fits above the fold at 1280×800. `Collapse completed` keeps long orders one-screen. The action bar is always visible without scrolling — it is sticky, not at the page end. |
| **Minimal mouse travel** | Primary action in the bottom-right corner; the checklist is the largest continuous target region; nothing critical lives in the top-right (the classic wp-admin trap). |
| **Large touch targets** | MPDS floor is 44px; the workspace raises the operational controls (quantity ±, primary action, add-package, print) to **56px**, with ≥8px separation between adjacent targets. |
| **Warehouse gloves** | No hover-only affordances anywhere. No drag-and-drop. No double-click. No right-click menus. No targets smaller than 44px, including the remove-package control. |
| **Fast repetitive work** | The queue cursor, `Shift+A`, and a primary button whose position never moves between states. Muscle memory is a feature: the primary button is in the same pixel for every fulfillment in every state. |
| **Multiple monitors** | Real anchors everywhere (middle-click opens a tab); a bookmarkable, shareable URL; the Queue and the workspace are independent screens that do not need each other in the same window. |
| **Keyboard-only** | Complete map in IV.5.3, no action reachable only by pointer, visible focus ring on every interactive element (the M1 `mpcf-admin.css` focus work extends to the workspace). |
| **Scan gun** | IV.5.5. |
| **Future tablet** | The 768–1023px single-column layout is *designed*, not merely tolerated; every target is already ≥44px; the REST API is the same one a PWA would use (I11, §9.5). |

### IV.5.7 State transitions, validation and error recovery

**Transitions.** The client never decides what is allowed. On load, and in the response body of
every mutation, the server returns the current `available_transitions()` list. The action bar
re-renders from it. There is no polling.

**Validation, in three layers, each of which alone is sufficient for correctness:**

1. Client: clamps, required-field hints, disabled states. A hint, never an enforcement.
2. REST controller: capability + nonce + payload schema (`args` with `validate_callback`/
   `sanitize_callback`) + optimistic-lock version.
3. Application/Engine: guards, workflow edges, domain VO constructors.

**Concurrency.** Every mutating request carries the fulfillment's `version`. A mismatch returns
**409 `mpcf_version_conflict`** with the current server state in the body. The client then: reverts
the optimistic change, raises a persistent (non-auto-dismissing) toast "Someone else updated this
fulfillment", and offers `Reload`. **Never a silent overwrite** — this is the §9.4 requirement.
Item and package writes advance the same `version` via `FulfillmentRepository::touch()`, so one
token covers the whole aggregate.

**Soft claim, not a hard lock.** Opening the workspace on an unassigned fulfillment self-assigns it
(audited). Opening one assigned to somebody else shows a non-blocking banner naming them and a
`Take over` action (audited). No hard lock: abandoned locks in a warehouse are worse than
collisions, and the optimistic version already prevents lost updates.

**Error recovery.**

| Failure | Behaviour |
|---|---|
| Guard rejection (422 `mpcf_guard_rejected`) | Guard's own message renders under the primary button. Nothing is lost. |
| Version conflict (409) | Above. |
| Forbidden (403) | Control disables itself with the reason; the page does not navigate away. |
| Network failure / 5xx | The store queues the mutation and retries with backoff (3 attempts). A persistent banner shows "Working offline — N changes pending". Item quantities are **absolute, not deltas**, so every retry is idempotent and a double-submit cannot double-count. |
| Tab closed with pending writes | `beforeunload` warning, plus a best-effort `navigator.sendBeacon` flush. |
| Scan mismatch (M6 groundwork) | Inline error region with `aria-live="assertive"`; the sink keeps focus; a `Report problem` shortcut is one keystroke away. |
| Unknown workflow state on the row | Already handled by the engine (`unknown_current_state` rejection); the workspace renders the state read-only with a link to Detail. |

### IV.5.8 Packing completion and shipment creation — the happy path, precisely

1. Operator opens the workspace. Fulfillment is `queued`. Primary reads `Start picking`.
2. `Ctrl+Enter` → `picking`. The checklist becomes live; focus moves to the scan sink.
3. Operator ticks lines (row click, `Space`, or `Shift+A`). Each burst flushes as one batch write
   (IV.10) → one `items.picked` audit event with an itemized payload.
4. All lines complete → the `all_items_picked` guard is satisfied → primary becomes `Mark picked`,
   enabled. `Ctrl+Enter` → `picked`. Primary becomes `Start packing`. `Ctrl+Enter` → `packing`.
5. Packing repeats the checklist against `qty_packed`. On entering `packing`, a shipment row is
   **not** created yet — an operator who abandons a pack must not leave orphan shipments.
6. Operator opens the shipment panel (or presses `t`/`w`). The **first** edit to any shipment field
   creates the shipment (`status = pending`) *and* its package seq 1, and allocates every packed
   line quantity to package 1 in `mpcf_package_items`. Audited as `shipment.created` +
   `package.created`.
7. `+ Add package` appends package seq 2… Each package carries its own weight, dimensions and
   optional colli tracking number. Line allocation stays on package 1 (PO decision 2; M4 adds the
   split UI).
8. All lines packed + at least one package has a weight → `all_items_packed` and
   `package_spec_present` are satisfied → primary becomes `Mark packed`. `Ctrl+Enter` → `packed`.
9. `Print packing slip` renders and opens the print dialog (IV.7). Recorded in `mpcf_documents` +
   `document.rendered`.
10. Operator enters the tracking number. Primary becomes `Ship` (guard `has_shipment`, plus
    `has_tracking` when `require_tracking_before_ship` is on). Requires `mpcf_manage_shipments`.
11. `Ctrl+Enter` → `shipped`. `ShippingService` sets every `pending` shipment on the fulfillment to
    `shipped` and stamps `shipped_at`, audited. The `fulfillment.state_changed` event reaches
    `StatusBridge` — which, after `v0.1.1`, actually fires from an admin/REST path — and the WC
    order moves to `completed` if every fulfillment for it has shipped.
12. Success toast with `Next order →`. `]` or `Enter` moves on.

**Partial shipment.** M2's answer is deliberate and simple: a fulfillment cannot reach `packed` with
unpacked lines (`all_items_packed`), so "ship what we have" is expressed through the exception band —
move to `backordered` with a reason, ship nothing, and resolve later; or pack and ship the available
lines after the lead reduces the order in WooCommerce (which `RefundObserver` already flags). The
data model supports N shipments per fulfillment today, so a second carrier handover after an
exception resolves needs no schema change. **True split fulfillment** (one order → several
independently-shipping fulfillments) requires relaxing the `order_unique` index and is a post-1.0
ADR (IV.13). Stating this now prevents a well-meaning future implementer from quietly dropping the
index. Full future-capability guidance: §24.1.

---

## IV.6 Shipment model

### Tables (Architecture Plan §7.1, verbatim shapes — migration step 4)

`mpcf_shipments` — `id, fulfillment_id (idx), carrier_id VARCHAR(64), service VARCHAR(128) NULL,
tracking_number VARCHAR(191) NULL, tracking_url TEXT NULL, status VARCHAR(32) DEFAULT 'pending',
shipped_at NULL, delivered_at NULL, created_at`. Additional index `(status)` for the future
tracking-sync sweep; `(tracking_number)` because `SearchQuery` must resolve a scanned tracking
number (D22's stated design target) without an unindexed scan.

`mpcf_packages` — `id, shipment_id (idx), seq SMALLINT, weight_grams INT NULL, length_mm/width_mm/
height_mm INT NULL, tracking_number VARCHAR(191) NULL, label_path VARCHAR(255) NULL, created_at`.
`label_path` is created and left NULL — M12 fills it; creating the column now costs nothing and
avoids an ALTER on a table that will be large.

`mpcf_package_items` — `id, package_id (idx), fulfillment_item_id (idx), qty`.

Weights in grams, dimensions in millimetres, integers only (D15). Display conversion is a UI
concern, sourced from the store's own unit settings through a `Woo\StoreUnits` port — **no new
setting and no new user meta**, because WooCommerce already owns that preference and duplicating it
would be a second source of truth.

### Lifecycle

`pending` → `shipped` → `delivered`, with `exception` reachable from `shipped`. Owned entirely by
`Application\ShippingService`.

**This is not a second workflow engine, and the plan says so explicitly.** I4 governs
`mpcf_fulfillments.state`. Shipment status is a four-value field with three legal moves, mutated
only by `ShippingService`, always audited. Routing it through `WorkflowEngine` would require a
second `WorkflowDefinition`, a second guard vocabulary and a second optimistic lock for no benefit.
If a future milestone needs carrier-driven shipment state (M12's tracking sync), that is the moment
to reconsider — by ADR.

- `pending` → `shipped`: set when the fulfillment transitions to `shipped`, or explicitly via
  `POST /shipments/{id}/ship`. Stamps `shipped_at`.
- `shipped` → `delivered`: manual in v1; carrier-driven in M12. Stamps `delivered_at`.
- `shipped` → `exception`: manual, reason-required, audited. A shipped shipment is **corrected, never
  deleted**.
- Deletion is allowed only while `pending`, requires `mpcf_manage_shipments`, and is audited.

### Tracking

Consignment-level `mpcf_shipments.tracking_number` is the common case. Per-package
`mpcf_packages.tracking_number` (colli) is supported from M2 and takes display precedence when
present. `tracking_url` is normally derived from the carrier's URL template and stored only when the
operator overrides it. Every add/edit/remove is audited with before/after values.

### Carrier abstraction

`Application\Ports\CarrierRegistry` (the port §5.2 already names) with
`Infrastructure\Carriers\BundledCarrierRegistry` in M2. Each carrier: `id`, `label`,
`tracking_url_template`. The bundled set is **deliberately minimal** and includes `other`, which
accepts a free-text carrier label and a manual tracking URL — so no merchant is blocked on a carrier
we did not bundle, and no `mpcf_carriers` filter needs to be frozen before M4 has designed the real
registry shape (format-validation hints, phone-required flags, the EU-skewed set).

### Label printing integration points — defined, not built

No `CarrierPort`, no HTTP, no credentials, no label rendering in M2. Three seams exist so M12 is
additive: `mpcf_packages.label_path` (created, NULL); `ShippingService::attach_tracking()` and
`ShippingService::attach_label()` are shaped to be callable by a future adapter without touching the
workspace; and `shipment.*` domain events are dispatched, so an adapter subscribes rather than being
wired into the packing path.

---

## IV.7 Documents in M2

Per PO decision 3, one document type is pulled forward. Everything else stays M3.

**In M2:**

- `Domain\Document\DocumentType` registry (filter `mpcf_document_types`, one bundled entry).
- `Domain\Document\DocumentModel` — pure assembled data: store block, ship-to and bill-to blocks,
  line items from the fulfillment's own snapshots (stable even if the product was renamed —
  precisely why the snapshots exist), package summary, order number, fulfillment id, and the
  **barcode payload** (Code 128 payload of the order number). §10 requires the payload in the model
  from the milestone the slip ships, so slips are scannable the day M6 lands.
- `Engine\DocumentAssembler\PackingSlipAssembler` — `(Fulfillment, OrderSnapshot, items, packages,
  Settings) → DocumentModel`. Pure, WordPress-free, exhaustively unit-tested. No HTML.
- `Documents\TemplateRegistry` — bundled resolution only. The filter → theme-directory → bundled
  override chain is M3.
- `Documents\HtmlRenderer` + `templates/documents/packing-slip.php` + a dedicated print stylesheet
  (`@page`, `@media print`, A4 default).
- `Application\DocumentService` — the only orchestrator of assemble → render → record → audit.
  Guard-tested: `DocumentPipelineGuardTest` asserts no class outside `DocumentService` calls a
  renderer, which is what makes "documents printed" a reliable audit fact (§10).
- `mpcf_documents` row per render with `file_path = NULL` (rendered-to-print, not stored) and
  `template_version`, plus a `document.rendered` audit event.
- `POST /mpcf/v1/fulfillments/{id}/documents/render` returning the print URL.
- Capability `mpcf_render_documents` (already exists).

**The barcode is a payload, not an image, in M2.** Rendering a scannable Code 128 needs either a
runtime dependency (the zip has exactly zero, a property confirmed at M1 release and worth keeping)
or ~150 lines of pure-PHP SVG encoding. Neither belongs in a pulled-forward slice. The slip prints
the payload as human-readable text; M6 renders it as SVG when there is a scanner to read it.

**Explicitly M3+:** pick list, batch picking list, stored renders in the protected file store, the
PDF renderer port, the template override chain, the store-branding settings block, the reprint
history screen, commercial invoice, CN22/CN23, return slip, shipping label (M12 — labels come from
carrier APIs as files, never from our renderer).

**Invoice links:** none added. The existing `View order` link on Fulfillment Detail
([FulfillmentDetailPage.php:227-230](src/Admin/FulfillmentDetailPage.php#L227-L230)) is carried into
the workspace's context column under the same `manage_woocommerce` gate. WooCommerce owns invoicing.

**Spike S1 (reduced), commit F14.** Pulling the slip forward pulls its spike forward. Scope is
narrowed to what M2 actually ships: an A4 packing slip must print with correct field positions and
no clipped content from both Chrome and Firefox print dialogs. Falsification: if either engine
cannot produce an acceptable slip from print-HTML, the PDF renderer moves into M2 and the milestone
grows — which is exactly what a spike is for. Evidence recorded in `docs/PRINT_VALIDATION.md`.

### IV.7 Actual outcomes (M2 shipped)

**Deviations from the plan's forward-looking text:**

1. **`mpcf_document_types` filter does not exist.** Line 1470 says it ships; it does not. The
   `DocumentType` registry exists as a design artifact (`src/Domain/Document/DocumentType.php`), but
   the filterable mechanism itself (the call site applying the filter, the bundled entry, and the
   override chain) is deferred to Milestone 3. `src/Documents/TemplateRegistry.php:12-16` explicitly
   states this: *"the filter → theme-directory → bundled override chain a real registry needs is
   Milestone 3's job."* The impact on integrators is zero — the feature does not exist in v0.2.0 to
   extend. This is not a mid-milestone scope cut; it was a forward-looking design comment (§IV.7
   was written as part of the architecture spec before implementation) that did not materialize
   during the M2 implementation because the one bundled template (packing slip) requires no override
   mechanism yet.

2. **`POST /mpcf/v1/fulfillments/{id}/documents/render` returns HTML, not a URL.** Line 1487 says
   it returns "the print URL"; the actual implementation returns inline HTML (print-rendered
   content). The rendering is to browser (`window.print()` in an iframe), not to a file. This is
   correct per the spike's purpose (print-HTML fidelity) and aligns with "rendered-to-print, not
   stored" (line 1485). No URL exists because there is no stored file — exactly the deferred-to-M3
   case. The API documentation (`docs/API.md:300-314`) is correct; the plan's forward-looking
   language in §IV.7 was aspirational.

---

## IV.8 Printing

| Question | M2 answer | Why |
|---|---|---|
| Automatic printing | **No** | Browsers cannot print silently. Doing it needs a print server, a kiosk-mode browser flag, or a native helper — all of which are merchant-infrastructure decisions, not plugin decisions. |
| Manual printing | **Yes** | `Print packing slip` button and `Shift+P`. Renders into a hidden same-origin iframe and calls `window.print()` — no new tab to close, no lost focus, and the workspace state survives. Focus returns to the scan sink after the dialog closes. |
| Print queue | **No** | A queue only earns its complexity once there is batch work to queue (M7). One slip per pack is not a queue. |
| Browser print | **Yes, the only mechanism in M2** | Print-optimised HTML with `@page`/`@media print`, zero dependencies, correct for a packing station with an A4 printer. |
| PDF generation | **No** | The `PdfRendererPort` binding arrives with customs/labels where a stored file is contractually required (§10). Adding dompdf now buys nothing and costs the zero-dependency property. |
| Print server | **No** | Post-1.0. The seam is `DocumentService` — a future `PrintTarget` port sits behind it without touching assemblers or templates. |

---

## IV.9 REST — decision and surface

### The decision

**M2 introduces `mpcf/v1`. Traditional wp-admin form POSTs continue for the M1 screens.** Both
halves are deliberate.

*Why REST is not optional here.* It is already decided and frozen — D6 ("REST-first workspace
`mpcf/v1` from M2"), I11 ("Admin UI and REST API consume the same application services"), §16.2
("REST namespace `mpcf/v1` exists from M2 because the workspace runs on it"), and §9.5 (mobile is a
UI project *because* of this). This plan does not get to relitigate it, and would not want to: the
workspace's requirements — no full-page reload inside a pack, optimistic updates with rollback,
409-conflict surfacing, per-tick persistence — are exactly what a form-POST screen cannot do. The
supporting evidence is that M1 was built for this: `AdminBoundaryGuardTest` already proves Admin
holds no business logic, so the controllers are genuinely thin.

*Why the M1 screens are not rewritten.* Queue, Detail and Dashboard are server-rendered,
POST-redirect-GET screens that work, are tested, and are read-oriented. Rewriting them onto REST
would be churn with no operator benefit, would put three working screens at risk, and would enlarge
this milestone by a third. They keep their form POSTs; the REST routes exist alongside for
integrators and for M14. The one change they get is the Queue drawer's primary action repointing at
the Workspace (§III.2.5's promised, zero-rework repoint).

*What this costs.* Two entry paths into the same services. That is exactly what I11 was written to
make safe, and a new `RestBoundaryGuardTest` keeps the controllers as thin as the screens.

### The surface

Frozen additive-only from the `v0.2.0` tag (§4 governance). **This raises the review bar: the shape
below is reviewed and agreed before F8 is written, not discovered during it.**

| Method | Route | Capability |
|---|---|---|
| GET | `/mpcf/v1/fulfillments` | `mpcf_view_queue` |
| GET | `/mpcf/v1/fulfillments/{id}` | `mpcf_view_queue` |
| GET | `/mpcf/v1/fulfillments/{id}/transitions` | `mpcf_view_queue` |
| POST | `/mpcf/v1/fulfillments/{id}/transitions` | per-edge, from the workflow definition |
| PUT | `/mpcf/v1/fulfillments/{id}/items` | `mpcf_process_fulfillments` |
| GET/POST | `/mpcf/v1/fulfillments/{id}/notes` | `mpcf_view_queue` / `mpcf_add_notes` |
| PUT/DELETE | `/mpcf/v1/fulfillments/{id}/assignment` | `mpcf_process_fulfillments` |
| GET/POST | `/mpcf/v1/fulfillments/{id}/shipments` | `mpcf_view_queue` / `mpcf_manage_shipments` |
| PATCH/DELETE | `/mpcf/v1/shipments/{id}` | `mpcf_manage_shipments` |
| POST | `/mpcf/v1/shipments/{id}/ship` | `mpcf_manage_shipments` |
| POST | `/mpcf/v1/shipments/{id}/packages` | `mpcf_manage_shipments` |
| PATCH/DELETE | `/mpcf/v1/packages/{id}` | `mpcf_manage_shipments` |
| POST | `/mpcf/v1/fulfillments/{id}/documents/render` | `mpcf_render_documents` |
| GET | `/mpcf/v1/carriers` | `mpcf_view_queue` |

**Conventions.**

- Every mutating request carries `version`; mismatch → 409.
- `PUT /items` takes **absolute** quantities: `{ version, lines: [ { item_id, qty_picked?, qty_packed? } ] }`.
  Absolute, never deltas — retries, double-submits and offline replays become idempotent by
  construction. One call = one coalesced audit event (§13's burst rule).
- Every mutation response returns the fresh `available_transitions()` list and the new `version`, so
  the client never needs a follow-up round trip.
- Auth: cookie + `X-WP-Nonce` for admin JS; Application Passwords work for integrations and are
  documented, not specially implemented. Scoped `mpcf_api_keys` stays post-1.0.
- Errors map typed application failures to stable codes: `mpcf_version_conflict` (409),
  `mpcf_guard_rejected` (422, body carries the guard id and message), `mpcf_forbidden` (403),
  `mpcf_not_found` (404), `mpcf_invalid_payload` (400).
- Controllers are thin: `permission_callback` → DTO → Application service → response shaped by the
  same view-model factories the admin screens use.

### Other public surface added in M2

`mpcf_event` action + per-type actions (`mpcf_fulfillment_state_changed`, `mpcf_shipment_created`,
…) bridged in `Woo\EventBridge` per §6.4; `mpcf_workspace_flags` (§9.4's named slot);
`mpcf_document_types`. **Not** added: `mpcf_workflows`, `mpcf_carriers`,
`mpcf_intake_should_create`, template overrides — each belongs to the milestone that designs its
data shape, and each is cheaper to add later than to un-freeze.

---

## IV.10 Performance

**Throughput model.** A sustained operator packs 30–60 orders/hour (60–120s per order including
physical work). A busy small-to-mid warehouse runs 5–8 concurrent operators → 300–500 orders/hour at
peak.

**Per-order request budget.** 1 workspace load + 1–2 item batches (picking, packing) + 1–3 shipment/
package writes + 1–2 transitions + 1 document render ≈ **8–12 requests**. At 500 orders/hour that is
~6,000 req/hour ≈ **1.7 req/s**, of which ~0.7/s are writes. This is not a load problem; it is a
correctness-under-concurrency problem, which is why the effort goes into the optimistic lock rather
than into caching.

**Burst aggregation (architecture-mandated, §13).** Ticking 5 lines individually would be 5 requests
and 5 audit rows. Instead the client debounces line changes (≤750ms, plus a forced flush on blur,
on state transition, and on `visibilitychange`/`beforeunload` via `sendBeacon`) and sends one batch.
One request, one `items.picked`/`items.packed` event with an itemized payload. **~5× fewer writes
and ~5× fewer audit rows**, and the operator sees no latency because the UI is optimistic.

**Queue sizes.** Design target remains 50k open rows (R6); M1 proved 10k with every p95 under 89ms.
M2 does not change the Queue's query shapes. It does add joined reads on the workspace, which are
per-fulfillment and bounded (1–2 shipments, 1–3 packages, N package_items).

**Database impact.**

| Table | Rows per fulfillment | Growth note |
|---|---|---|
| `mpcf_shipments` | 1 (rarely 2) | Negligible |
| `mpcf_packages` | 1–3 | Negligible |
| `mpcf_package_items` | = line count | Bounded by order size |
| `mpcf_documents` | 1 per render, reprints included | Small |
| `mpcf_events` | **+6 to +10** (was ~8) | The one that matters |

At 1,000 orders/day, `mpcf_events` grows from ~2.9M to ~6M rows/year. Mitigations already in the
architecture and enforced here: events never join Queue queries; the timeline paginates (M2 fixes
the currently-unbounded `timeline_for_fulfillment()`); rollups arrive at M8; archival guidance is a
documented post-1.0 operation.

**Locking.** Optimistic only. No `SELECT … FOR UPDATE`, no transaction spanning a request boundary,
no advisory locks, no application-level row locks. An item batch is one `UPDATE` per changed row
plus one version-bumping `touch()`; InnoDB row locks are held for microseconds and there are no
range predicates, so no gap locks. Two operators on the same fulfillment collide at the version
check, not in the database.

**Index re-measurement (F23).** The M1 proof concluded that the Dashboard's today-counters correctly
prefer the `created_at` index over `event_type` *because M1 has exactly one event type* — and
explicitly flagged that conclusion for revisiting when more types exist. M2 adds eight. F23 re-runs
`QueuePerformanceProofTest` with an M2-shaped event distribution, adds a workspace-load query shape
and a tracking-number search shape, and adds a composite `(event_type, created_at)` index as
migration step 6 **only if the re-measurement demands it** — measured, not assumed.

**Future scaling concerns, recorded now:** `mpcf_events` partitioning or archival at ~50M rows;
`mpcf_search_index` (the reserved D22 projection) if tracking/SKU search degrades; per-warehouse
sharding of the Queue at M11; REST response caching is deliberately *not* on this list — a warehouse
queue that shows stale data is worse than a slow one.

---

## IV.11 MPDS — generic versus plugin-specific

The separation rule, applied consistently: **generic if a second MP Commerce plugin's operational
screen would use it unchanged; plugin-specific if it encodes fulfillment vocabulary.**

### Generic → `mp-admin-design-system v0.3.0`

| # | Component | Notes |
|---|---|---|
| 1 | `toast` + `toast.js` | §8.4's named async-save feedback, deferred from M1 (§III.2.6 correctly used an admin notice instead). `aria-live="polite"`, optional action slot, auto-dismiss with pause-on-hover/focus, persistent variant for conflicts, `prefers-reduced-motion` companion. |
| 2 | `stepper` | §8.4's segmented workflow-position indicator. Ordered steps with complete/current/upcoming, `aria-current="step"`. |
| 3 | `workspace-layout` | Three/two/one-column responsive grid primitive with named regions. The layout grammar every future operational screen inherits. |
| 4 | `action-bar` + `action-bar.js` | Sticky bottom operational bar — the operational sibling of the existing `sticky-save`. The JS binds `Ctrl/Cmd+Enter` to `[data-mpds-primary-action]`. |
| 5 | `checklist` / `checklist-row` | Large-target row: leading control slot, media slot, primary/secondary text, trailing control, complete state. |
| 6 | `quantity-stepper` | `− n / m +` with ≥56px targets, `aria-valuenow/valuemin/valuemax`, `ArrowUp`/`ArrowDown` support. |
| 7 | `unit-input` | Numeric input with a unit-suffix affordance. |
| 8 | `repeater` | Add/remove item-group scaffold with a stable add-button contract. |
| 9 | `scan-input` + `scan-sink.js` | Focus-retaining capture field with a ready/paused indicator and terminator normalisation. §8.4 pencilled this in at M6, but §9.4 requires the focus architecture at M2 and §8.4 itself says components "land with the milestones that need them" — decoding stays with the consumer, and M6 adds it. |

**Deliberately reused rather than rebuilt:** the mismatch/error banner reuses `panel--warning`/
`panel--error`; the shortcut sheet composes `modal` + the existing `kbd-hints`; the address block is
plain markup. Three components not built is a result, not an omission.

**Not in v0.3.0:** `stat trend` (§8.4, needed at M8).

Every component ships with markup-contract tests, passes the CSS token lint (every `var()` defined)
and the JS-hook lint (JS never keys on `mpds-ui-*` classes), and is added to `MANIFEST`.

### Plugin-specific → `mp-commerce-fulfillment`

`assets/admin/js/api.js` (REST client, nonce, error mapping), `store.js` (observable state,
optimistic apply/rollback, retry queue, debounce/flush), `workspace.js` (bootstrap, focus manager,
queue cursor), `packing.js` (checklist and quantity semantics), `shipment.js` (shipment/package
panel, carrier, units), `documents.js` (print iframe), `shortcuts.js` (the key map and the shortcut
sheet); `assets/admin/css/mpcf-workspace.css`; `templates/documents/packing-slip.php` and its print
CSS; the carrier list, the flag definitions, and the `event_type` → icon/label map.

---

## IV.12 Commit sequence

Each commit independently green: `composer phpcs`, unit, integration, guards.

### MPDS repo — E1–E9 → `mp-admin-design-system v0.3.0`

E1 toast + `toast.js` + contract tests → E2 stepper → E3 workspace-layout → E4 action-bar +
`action-bar.js` → E5 checklist/checklist-row → E6 quantity-stepper + unit-input → E7 repeater →
E8 scan-input + `scan-sink.js` + JS-hook lint pass → E9 `README.md`/`docs/CONSUMING.md` update +
`MANIFEST` regeneration → **tag `v0.3.0` on explicit PO approval.**

### Plugin repo — F0–F25 → `mp-commerce-fulfillment v0.2.0`

| # | Commit |
|---|---|
| F0 | **ADR-0006** (dev-only browser test toolchain) Accepted; ADR-0003 Status gains "Superseded in part"; ADR README index updated. Governance order: ADR first, then document, then code. |
| F1 | Vendor MPDS `v0.3.0` via `bin/sync-mpds.sh`; `MpdsVendorGuardTest` green; `SOURCE_TAG` correct. |
| F2 | `Schema` + `Migrator` step 4 (`mpcf_shipments`, `mpcf_packages`, `mpcf_package_items`) and step 5 (`mpcf_documents`); `TARGET` 3 → 5; `PersistedKeys` + `docs/PERSISTED_DATA.md` + `uninstall.php`; migration lifecycle tests 3→5 including resume-after-interruption. |
| F3 | Domain: `Shipping/{Shipment,Package,PackageSpec,TrackingReference,CarrierId}`, `Document/{DocumentType,DocumentModel}`, repository interfaces, `CarrierRegistry` port. `DomainPurityGuardTest` still green. |
| F4 | Infrastructure: `WpdbShipmentRepository`, `WpdbPackageRepository`, `WpdbPackageItemRepository`, `BundledCarrierRegistry`; `FulfillmentRepository::touch()`; integration tests. |
| F5 | Application: `ShippingService` (create/update/delete shipment and package, attach tracking, ship, mark delivered/exception), all audited; the eight new event types; `Woo\EventBridge` (`mpcf_event` + per-type actions); PayloadGuard tests per payload shape. |
| F6 | Application: `PackingService` — batch absolute quantities, coalesced audit event per burst, `touch()`-based version advance. |
| F7 | **Findings B/C/D**: `TransitionContextFactory`, `WorkflowService::available_transitions()`, boolean params removed from `transition()`, `FulfillmentDetailPage` rewired and its private `WorkflowEngine` deleted; new `has_tracking` guard; upgrade test for legacy `packed` rows with no shipment. |
| F8 | `Api\Rest`: controller base (permission callback, nonce, version handling, error-code map) + `FulfillmentsController` (list, get, transitions GET/POST). |
| F9 | `Api\Rest`: `ItemsController` (batch), `NotesController`, `AssignmentController`. |
| F10 | `Api\Rest`: `ShipmentsController`, `PackagesController`, `CarriersController`. |
| F11 | `RestBoundaryGuardTest` (no `$wpdb`, no repository, no WooCommerce symbol, every route has a capability-checked `permission_callback`) + `docs/API.md` complete enough that an integrator needs no other document. |
| F12 | Documents: `PackingSlipAssembler` (pure, exhaustively unit-tested), `TemplateRegistry` (bundled only), `HtmlRenderer`, bundled template + print CSS. |
| F13 | `DocumentService` + `mpcf_documents` write + `document.rendered` audit + `DocumentsController` + `DocumentPipelineGuardTest`. |
| F14 | **Spike S1 (reduced)**: A4 packing-slip print fidelity, Chrome + Firefox; `docs/PRINT_VALIDATION.md`. Fails → PDF renderer enters M2 and the plan is amended. |
| F15 | `Admin\WorkspacePage`: server-rendered initial state (fast first paint, no skeleton flash), three regions, hidden-submenu registration, `Assets::SCREEN_SLUGS` extended. |
| F16 | Workspace JS core: `api.js`, `store.js` (optimistic, rollback, retry queue, debounce/flush), `workspace.js` bootstrap, focus manager. |
| F17 | Workspace JS: checklist, quantity semantics, scan sink, full key map, shortcut sheet. |
| F18 | Workspace JS: shipment/package panel, carrier select, tracking, `Woo\StoreUnits` port for display units. |
| F19 | Workspace JS: action bar, transitions, reason modal, toast, 409/422/offline recovery, queue cursor and `Next order`. |
| F20 | Queue/Dashboard integration: drawer primary action repointed to the Workspace, row `Enter` opens it, real anchors for new-tab/second-monitor use. |
| F21 | Settings: `auto_advance_after_ship` (default off), `default_carrier_id`, `require_tracking_before_ship` (default off); `SCHEMA_VERSION` 3 → 4. |
| F22 | **Playwright harness**: dev-only `package.json` + `playwright.config.js` + `tests/browser/`, Docker runner (`mcr.microsoft.com/playwright`), CI job serving WP via WP-CLI install + PHP built-in server, `wp eval-file` seed script, `@axe-core/playwright` checks; `bin/build-zip.sh` and `bin/release-audit.sh` extended to **fail** on any Node artifact; `ReleaseArtifactGuardTest`; `.gitignore` for `node_modules`/`test-results`/`playwright-report`. |
| F23 | Performance re-proof at 10k rows: existing shapes plus workspace load, tracking search, and the M2 event distribution; timeline pagination; migration step 6 only if measured to be needed; `docs/QUEUE_PERFORMANCE_VALIDATION.md` rewritten. |
| F24 | Documentation reconciliation: `HOOKS.md` (REST + the three filters + `mpcf_event` — the first real public extension surface), `API.md`, `PERSISTED_DATA.md`, `TEST_STRATEGY.md`, `COMPATIBILITY.md`, `ROADMAP.md`, ADR-0005 clarification, `ARCHITECTURE_PLAN.md` §IV.7 "actual outcomes". |
| F25 | Full IV.14 acceptance pass; version four-place bump to `0.2.0`; POT regenerated; every CI leg green; `release-audit` green → **tag `v0.2.0` on explicit PO approval.** |

**Dependency notes.** F1 needs the MPDS tag/candidate, so E1–E9 complete first. F0 has no
dependencies and must precede F22. F2–F7 are strictly ordered. F8–F11 need F5–F7. F15–F19 need F8–F10
and F1. F22 needs F15–F19 to have something to drive. Commit boundaries may shift where dependency
order requires it, documented as a deviation if so — the same rule M1 used.

---

## IV.13 Out of scope — belongs to M3+

Stated explicitly so scope gravity (R12) has nothing to grab.

- **M3:** pick list; batch picking list; stored document renders in the protected file store; PDF
  renderer port; template override chain (filter → theme → bundled); store-branding settings block;
  reprint-history screen; document-type registry beyond the one bundled entry.
- **M4:** the real carrier registry (EU-skewed bundled set, tracking-number format hints,
  phone-required flags, the `mpcf_carriers` filter); the notification subsystem (policy, dispatcher,
  `EmailChannel`); shipped-email per shipment; the WC-email tracking block; bridge-mapping settings
  UI; multi-package **line allocation** UI.
- **M5:** all photography — capture slots, protected store, `Api\FileEndpoint`, SHA-256
  fingerprints, EXIF-GPS stripping, the real `photo_required` guard, retention purge.
- **M6:** scan **semantics** — decoding, pick/pack by SKU/EAN, mismatch and over-scan handling,
  scannable-slip → workspace, SVG barcode rendering, scan-first workspace mode.
- **M7:** batch picking, `BatchBuilder`, batch tables, print queue.
- **M8:** all analytics, rollups, trends, operator stats.
- **M9:** Site Health, `wp mpcf doctor`, privacy exporter/eraser, 50k-row baselines,
  `ARCHITECTURE_FREEZE.md`, security review document.
- **Post-1.0:** returns; the location hierarchy and location-sorted picking; `CarrierPort` label
  purchase and live tracking sync; webhooks and automation rules; scoped API keys; the tablet PWA;
  true split fulfillment (needs an ADR to relax `order_unique` — see §24.1 for distinction from
  within-fulfillment partial shipping); partial fulfillment & split shipments (§24.1); the admin
  workflow builder; the `mpcf_search_index` projection; audit investigation mode.

---

## IV.14 Testing

| Tier | Scope for M2 |
|---|---|
| **Unit** (`tests/unit/`) | `PackingSlipAssembler` against fixture fulfillments; every new Domain VO's validating constructor; `ShippingService`/`PackingService`/`DocumentService` against hand-written port fakes; `TransitionContextFactory` truth table; `available_transitions()` per state; the new `has_tracking` guard; `BundledCarrierRegistry`; REST error-code mapping as a pure map; `PayloadGuard` compliance for all eight new payload shapes. |
| **Integration** (`tests/integration/`, real WP+WC+MariaDB, HPOS forced on) | Every REST route via `rest_do_request`, applying UMC's four documented Store-API test gotchas; the full capability matrix per route (operator vs lead vs shop_manager vs subscriber); nonce-failure and Application-Password paths; 409 conflict via two concurrent `version` holders; 422 guard rejection carrying the guard id; shipment/package lifecycle including delete-while-pending and refuse-delete-while-shipped; `mpcf_package_items` auto-allocation; document render writes exactly one `mpcf_documents` row and one `document.rendered` event; `Woo\EventBridge` fires `mpcf_event`; uninstall extended to all four new tables. |
| **Browser** (`tests/browser/`, Playwright, dev/CI only) | Full keyboard-only `queued → shipped` with zero pointer events; scanner emulation (`keyboard.type` at wedge speed with an `Enter` suffix) proving focus retention across a 30-action session; 409 conflict driven from two independent browser contexts; offline/retry via `page.route()` interception; focus-restoration after every optimistic re-render; print path under `emulateMedia({ media: 'print' })` asserting slip DOM and page-break behaviour; queue-cursor navigation; the three breakpoints. |
| **Accessibility** | `@axe-core/playwright` on the workspace at 1440/1024/800px with zero serious/critical violations; MPDS markup-contract tests for all nine new components; a manual screen-reader pass on the action bar and the checklist recorded in the acceptance script; every interactive element has a visible focus ring. |
| **Performance** (`phpunit-performance.xml.dist`) | The M1 shapes re-run against an M2-shaped event distribution; new shapes for workspace load, tracking-number search, and shipment/package reads; assert no `EXPLAIN type = ALL` anywhere and p95 < 200ms on the reference container. |
| **HPOS** | `HposProofTest` extended to cover the workspace's live `OrderSource` read and the document assembler's order read — both must be CRUD-only (I2). Zero skips is the proof. |
| **Workflow** | Table-driven over every edge in `StandardWorkflow`, now with **real** item/shipment/package data instead of caller-asserted booleans — this is the test that finding B makes meaningful for the first time. |
| **Migration** | `mpcf_db_version` 3 → 5 on a populated database; idempotent re-run; resume after simulated interruption; and the behavioural upgrade case — a fulfillment sitting in `packed` from `0.1.x` cannot ship until a shipment exists, and can once one does. |
| **Structural guards** | All 14 existing guards stay green and mutation-verified. New: `RestBoundaryGuardTest`, `DocumentPipelineGuardTest`, `ReleaseArtifactGuardTest`. `CompositionRootTest`'s allowlist is extended deliberately, commit by commit — never in bulk. |
| **Acceptance** | The ten falsifiable criteria in IV.15, each with named evidence, in the house `M2_RELEASE_REPORT.md` format. |

---

## IV.15 Validation

### Acceptance criteria (falsifiable)

1. A Warehouse Operator processes a fulfillment `queued → shipped` in the workspace using **only the
   keyboard**, with no full-page reload at any point, in ≤6 interactions for a single-line order.
2. Every workspace mutation is available as a `mpcf/v1` route with an identical result, proven by an
   integration test that performs the same pack twice — once through the REST routes, once through
   the same Application services — and asserts identical database and audit outcomes (I11).
3. Two browser sessions editing the same fulfillment produce a 409 on the second write; nothing is
   silently overwritten; the losing session recovers by reloading without losing unrelated work.
4. Creating a shipment auto-creates package 1 and allocates every packed line to it; adding a second
   package records its own weight, dimensions and colli number; deleting a `pending` shipment is
   permitted and audited, deleting a `shipped` one is refused.
5. `packed → shipped` is blocked by the engine when no shipment exists — including for a fulfillment
   that reached `packed` under `0.1.x` — and permitted once one does. Guard rejections render the
   guard's own message, never a generic error.
6. Printing a packing slip produces a correctly laid-out A4 page in Chrome and Firefox, writes
   exactly one `mpcf_documents` row and one `document.rendered` audit event with the template
   version, and returns focus to the scan sink.
7. Shipping the last open fulfillment for an order moves the WC order to `completed` **from the
   workspace** (the path `v0.1.1` unblocked), with the loop guard proven by a test asserting no
   recursive bridge write.
8. The Playwright suite passes in CI, including the axe pass with zero serious/critical violations;
   the built zip contains **no** `package.json`, `node_modules`, `playwright.config.js`,
   `tests/browser/`, or any other Node artifact — asserted by `release-audit.sh`, which fails the
   build if any is present.
9. All 17 structural guards exist, pass, and each fails when its violation is injected (mutation
   evidence recorded in the PR).
10. `docs/API.md`, `HOOKS.md`, `PERSISTED_DATA.md`, `TEST_STRATEGY.md`, `PRINT_VALIDATION.md`,
    `QUEUE_PERFORMANCE_VALIDATION.md`, `ROADMAP.md`, ADR-0006 and the ADR index are all current; CI
    floor and current-stable legs green.

### Performance criteria

- Workspace initial server render p95 < 300ms at 10k fulfillments on the reference container.
- Every REST mutation p95 < 150ms server-side.
- No `EXPLAIN type = ALL` on any query shape the workspace, Queue or Dashboard issues.
- Optimistic UI feedback < 50ms from keypress to visible change (measured in the browser suite).
- A five-line pack produces **≤ 12 REST requests** and **≤ 10 audit rows** end to end — burst
  aggregation is measured, not assumed.

### Release criteria

`composer phpcs` clean · unit + integration + guards green · browser suite green · performance proof
re-run and documented · CI green on all five integration legs plus the new browser job · POT
regenerated · four-place version bump · `release-audit` green including the Node-artifact check ·
every document in IV.15.10 current · merged via PR · **tagged only on explicit PO approval** (I14).

### Release audit additions (F22)

Beyond M1's checks (version parity, six required docs, zip builds, three required files present, no
`vendor/phpunit`, no `tests/`): fail on `package.json`, `package-lock.json`, `node_modules/`,
`playwright.config.*`, `tests/browser/`, `.playwright/`, `playwright-report/` or `test-results/`
anywhere in the archive; assert `docs/API.md` is present in the repo; assert
`vendor/composer/installed.json` still reports **zero runtime packages** — the zero-dependency
property M1 earned must survive a milestone that added a Node toolchain.

---

## IV.16 Risks

| # | Risk | L | I | Mitigation | Verified by |
|---|---|---|---|---|---|
| M2-R1 | **REST surface freezes wrong.** `mpcf/v1` is additive-only from the `v0.2.0` tag (§4); a bad resource shape is permanent within 1.x. | M | H | The IV.9 table is reviewed and agreed before F8 is written; `docs/API.md` is drafted at F11 against the implemented routes and re-read as a contract, not a description; anything uncertain is simply not exposed (a route added later is free; a route reshaped later is not). | PO review of IV.9 before F8; F11 doc review |
| M2-R2 | **Workspace complexity outgrows no-build JS** (R2 realised). Seven ES modules with an observable store, optimistic rollback and a retry queue is the largest JS this family has written. | M | M | ADR-0003's escape hatch is intact — the REST API is the contract, so a framework rewrite would touch only `assets/`. Hard budget: if `assets/admin/js/` exceeds ~1,500 lines or any single module exceeds ~400, that is a milestone-review flag, not a silent slide. Playwright coverage means a future rewrite has a safety net. | Milestone review; F25 line count recorded |
| M2-R3 | **Pulled-forward packing slip drags M3's architecture in with it.** "Just one document" quietly becomes the template override chain, branding settings and a PDF renderer. | M | M | IV.7's in/out list is binding. `TemplateRegistry` resolves bundled templates only — the override chain is physically absent, not merely unused. `DocumentPipelineGuardTest` prevents ad-hoc rendering. | F13 guard; F24 scope review |
| M2-R4 | **Spike S1 fails**: print-HTML cannot produce an acceptable A4 slip on both engines. | L | H | S1 runs at F14, before the workspace JS is written, so a PDF-renderer pivot costs a plan amendment rather than a rewrite. | F14 falsification test |
| M2-R5 | **Playwright destabilises CI.** Browser tests are the classic flake source; a red CI that everyone learns to ignore is worse than no browser tests. | H | M | No arbitrary sleeps — Playwright auto-waiting and explicit response waits only. Deterministic seed data via `wp eval-file`. The browser job runs **after** the PHPUnit legs and is the only job permitted a single automatic retry. Any test that flakes twice in a week is quarantined with an issue, not left red. | CI history reviewed at F25 |
| M2-R6 | **Node toolchain leaks into the release artifact.** | L | H | Three independent defences: `bin/build-zip.sh` allowlist, `bin/release-audit.sh` denylist, and `ReleaseArtifactGuardTest`. The PO named this explicitly; it gets belt, braces and a third belt. | Criterion 8 |
| M2-R7 | **Optimistic UI diverges from server truth** — the operator sees 3/3 packed while the database says 2/3, and ships a short box. | M | H | Every mutation response returns the authoritative state and version; the store reconciles from the response, never from its own optimistic value. Pending-write count is always visible. The `packed` transition forces a flush before it is attempted, so no transition ever runs against unflushed local state. | Browser suite: flush-before-transition test |
| M2-R8 | **Upgrade surprise**: `0.1.x` fulfillments in `packed` cannot ship until a shipment exists. | H | L | Correct behaviour, but it must not be a surprise. Covered by a migration test, called out in the release notes and `readme.txt` upgrade notice, and the guard's rejection message names the fix ("Add a shipment before shipping"). | F7 upgrade test |
| M2-R9 | **Scan sink fights wp-admin for focus** (R11 arriving early). Admin notices, the WordPress heartbeat and third-party admin scripts all steal focus. | M | M | The sink re-claims focus on `focusout` only when focus lands on `body` — never when the operator deliberately focused something. A visible ready/paused indicator means the operator always knows. Manual entry always works. M6's spike S2 covers real hardware. | Browser suite: 30-action focus-retention test |
| M2-R10 | **Shipment status becomes a shadow state machine.** A future contributor adds `shipment.status` transitions with their own guards and the plugin has two engines. | M | M | IV.6 states the boundary in the architecture document itself; `ShippingService` is the only writer; the four values and three legal moves are enumerated in code as constants with the rationale in the docblock. Escalating it is an ADR. | Code review; IV.6 in Part IV |
| M2-R11 | **Event-table growth accelerates** past what M8's rollups assume. | M | M | Burst aggregation caps the worst case (per-tick events were the real threat and are designed out); F23 re-measures index selectivity; timeline pagination lands in M2 rather than being discovered at M8. | F23 |
| M2-R12 | **Warehouse workflow mismatch**: the linear pick→pack model does not fit a store that picks and packs in one motion at the bench. | M | M | The `queued → packing` shortcut edge already exists in `StandardWorkflow` and the workspace renders whatever the definition offers — a bench-packing store gets a two-step workspace with no code change. Dogfooding on a real store before M3 (P8) is where this is falsified. | PO acceptance walkthrough |
| M2-R13 | **M2 alone still does not complete the physical job** if the slip pull-forward is later reversed, or if pick lists turn out to be the artifact operators actually need. | L | M | The slip is in scope (PO decision 3). If dogfooding shows the pick list matters more, that is an M3 sequencing input, recorded — not an M2 amendment. | Dogfood feedback at F25 |

---

## IV.17 GO / NO-GO

**GO, pending PO approval of this Part IV and the prior release of `v0.1.1`.**

Every scope item traces to a specific section of Architecture Freeze v1.0. The plan was reconciled
against the *inspected* state of both repositories, not their documentation — which is how the
event-dispatcher defect, the caller-asserted guard flags, the vacuous display-path context and the
Admin-instantiated `WorkflowEngine` were found. One new ADR (0006) and two roadmap-sequencing
amendments are the only changes to the frozen document set; no invariant, D-decision, layer rule,
data-model semantic, engine contract or public-surface rule is altered.

Two real open risks are sequenced early to fail fast: spike S1 at F14 (before any workspace JS
exists) and the Playwright harness at F22 (after the feature work it validates, so a harness problem
never blocks feature progress).

**Pre-conditions to start:** PO approves this Part IV (I14); `v0.1.1` is tagged and released; the
IV.9 REST surface is reviewed and agreed; `mpcf-test-runner` and a Playwright container image are
available on the dev host.

**First concrete step on approval:** append this document to `docs/ARCHITECTURE_PLAN.md` as Part IV
and record the approval in the version-history table — the same D0 ritual that opened M1 — then
begin the `v0.1.1` patch track (P1–P3), then MPDS E1–E9.

---

# Part V — Milestone 3 Execution Summary (Ops UX → v0.3.0)

**Status:** Release candidate documentation (2026-08-04). Architecture Freeze v1.0 remains authoritative. Tag `v0.3.0` requires separate PO acceptance.

## V.1 Scope that shipped

| Package | Delivered |
|---|---|
| M3-D | Workspace stage guidance, next-action clarity, quantity disclosure, packing/shipping emphasis, shipped success path |
| M3-E | Orders read-only overview (Woo status + optional fulfillment; Open destinations) |
| M3-F | Iterative operator dogfood, approved polish only, docs reconciliation, release validation |

## V.2 Explicitly deferred (not in v0.3.0)

- **M3-A** Mission Control Dashboard bands / CTAs
- **M3-B** Shared band/next-action extraction for Dashboard/Queue
- **M3-C** Queue next-action column & Mission Control presets
- Shell/Settings polish, Documents I (→ **M4**), partial fulfillment, analytics, barcode semantics

## V.3 Operational success metric

The Product Owner must complete every required warehouse scenario without stopping because the next action is unclear. Automated tests support this metric; they do not replace it.

## V.4 Observation backlog

All dogfood findings live only in [`docs/DOGFOOD_LESSONS.md`](DOGFOOD_LESSONS.md). Release reports summarize counts and outcomes; they do not duplicate lesson entries.

## V.5 Validation philosophy

Primary confidence: PHPUnit, integration tests, and manual operator dogfooding. Playwright verifies browser-specific behaviour only; full Playwright regression is not a standard release gate.

## V.6 Roadmap amendment

§20 table updated so M3 = Ops UX at 0.3.0 and Documents I = M4. Post-1.0 milestones renumbered M11–M15. No ADR required (sequencing only; no invariant or D-decision change).

---

# Part VI — Milestone 4 Documents I (release candidate)

**Status:** M4-A through **M4-E** complete on `feature/m4-documents`. Release candidate prepared as `v0.4.0` — **not tagged/published pending PO approval**. Architecture Freeze v1.0 §10 / D16 / ADR-0004 / ADR-0007 remain authoritative.

## VI.1 Reconciliation (vs v0.3.0)

M2 shipped a minimal packing-slip pipeline (`DocumentService` → `PackingSlipAssembler` → `HtmlRenderer` → `mpcf_documents` + `document.rendered`). M3 did not touch documents. M4 **extends** that pipeline end-to-end (typed render, protected storage, workspace/history/bulk, dogfood). It does not replace `DocumentService`.

## VI.2 M4-A delivered

| Concern | Implementation |
|---|---|
| Simple type registry | `Documents\DocumentTypeRegistry` — bundled `packing_slip` + `picking_list`; filter `mpcf_document_types`; malformed entries dropped |
| Stage policy | `Domain\Document\DocumentStagePolicy` — packing_slip: packing…completed; picking_list: queued…picked; cancelled always denied; exceptions use `return_to_state` |
| Generalized orchestrator | `DocumentService::render(id, doc_type, options)`; `render_packing_slip()` delegates |
| Renderer contract | `Documents\DocumentRendererInterface` (`render` / `format` / `mime_type`); `HtmlRenderer` implements it (canonical HTML) |
| Template chain | filter `mpcf_document_template` → theme `mp-commerce-fulfillment/documents/` → bundled; path validated |
| DocumentModel contract | Render-time snapshots: fulfillment state, template version, branding, rendered_at/by, customer instructions, renderer format via `with_render_meta()` |
| Repository reads | `get`, `list_for_fulfillment`, `latest_for_fulfillment_and_type` — no schema change |
| Hooks | `mpcf_document_types`, `mpcf_document_template`, `mpcf_document_model` |
| Template version | Explicit on type definition; theme/filter overrides use `override-{sha256-prefix}` (never mtime) |

## VI.3 M4-B delivered

| Concern | Implementation |
|---|---|
| Branding settings | `Settings` keys + accessors; Settings admin page (Documents branding card); sanitized; capability-gated writes |
| Branding snapshot | `Documents\BrandingSnapshot` — store name / address / footer / optional logo data-URI (≤256 KiB image); frozen into model + HTML at render |
| Packing slip enhance | Branding block, identity meta, packed qty, packages/tracking, customer instructions, template v2 |
| Picking list | `PickingListAssembler` + `picking-list.php` / `.css`; qty ordered/to-pick/picked/remaining; location_snapshot display; fulfillment-line order |
| Storage policy | Both types `storage_policy=store` |
| Protected HTML | `Infrastructure\Files\ProtectedDocumentStore` under `uploads/mpcf/documents/{yyyy}/{mm}/{fid}/{type}-{token}.html`; `.htaccess` deny; atomic write; integrity verify |
| Metadata / integrity | Relative `file_path` on `mpcf_documents`; `mime` / `bytes` / `sha256` / `stored` in `document.rendered` event — **no schema bump** |
| Compensation | Storage fail → no row/event; DB fail after write → delete orphan file; event after successful file+row |
| Logo historical guarantee | Data-URI embed when practical; otherwise omit (never mutable public URL alone) |

## VI.4 M4-C delivered (Workspace Integration)

| Concern | Implementation |
|---|---|
| State-aware actions | `Documents\DocumentPrintContext` — sole consumer of `DocumentStagePolicy` for button enablement + Shift+P primary type |
| Workspace UI | Bounded Documents action group; picking list + packing slip; disabled reasons; last-printed status per type |
| REST typed render | `POST .../documents/render` accepts optional `doc_type` (default `packing_slip`); structured meta (`document_type`, `template_version`, `stored`, `file_available`) |
| Client | Existing `documents.js` / `api.js` / `shortcuts.js` — in-flight guard; primary Shift+P |
| Timeline labels | `DocumentEventLabels` for `document.rendered` / `document.reprinted` |

## VI.5 M4-D delivered (History and Print Workflow)

| Concern | Implementation |
|---|---|
| History screen | `Admin\DocumentsPage` — filters by type/date/order; Workspace link; Reprint (no edit/delete) |
| Exact reprint | `DocumentHistoryService::reprint` streams stored HTML bytes; appends `document.reprinted` with `source_document_id` in **event payload only** (no schema column) |
| Content stream | `GET /documents/{id}/content` — capability-checked; path from repository metadata only; traversal rejected; raw HTML MIME via `rest_pre_serve_request` |
| List | `GET /documents` — thin wrapper over repository search |
| Queue bulk | Cap **25**; picking_list only; per-row stage/capability; partial success; combined page-break HTML for browser print; one stored document + audit per success |

## VI.6 M4-E delivered (Dogfood / RC prep)

Zero release blockers from dogfood round 1 on `dev.biopentra.eu` (WP-CLI service-level scenarios + Chrome/Firefox A4 print S2). Documentation reconciled; version bumped to `0.4.0` RC artifacts built. **Tag/publish gated on PO approval.**

## VI.7 Final decisions / deviations

| Topic | Decision |
|---|---|
| Canonical format | HTML only (no mandatory PDF) |
| Reprint lineage | Event payload `source_document_id` — **no** `mpcf_documents` schema bump |
| Bulk print UX | Combined page-separated HTML response (no print server / background queue) |
| Composite index | Deferred — history queries acceptable at current volume without migration |
| Ownership | Outbound only; no carrier APIs; no inventory/receiving/PO (`wc-inventory-overview`); ADR-0007 unchanged |

## VI.8 Explicitly not in M4 (→ later)

PDF renderer implementation; Mission Control redesign; M5 tracking/notifications; silent/print-server printing; M8 batch-picking engine.

---

# Part VII — M5 Tracking & notifications (Customer Communication)

**Milestone purpose:** Customer Communication — complete the outbound
communication layer after ship. Tracking **capture** already exists from
M2; M5 does not invent shipping. Labels, customs, rates, and live carrier
APIs remain **M13**.

## VII.1 M5-A delivered (Carrier Registry Foundation)

| Concern | Implementation |
|---|---|
| Immutable carrier VO | `Domain\Shipping\Carrier` — private ctor, `define()` / `is_valid()` / `to_array()`, no setters |
| Bundled EU set | PostNord, DHL, Bring, DPD, GLS, UPS, DB Schenker, Budbee, Instabox + `other` |
| Public filter | `mpcf_carriers` on `Infrastructure\Carriers\BundledCarrierRegistry` |
| Validation | Reject + log + continue (DocumentTypeRegistry resilience); `other` always restored |
| URL resolution | `Domain\TrackingUrlResolver` + `TemplateTrackingUrlResolver` (template expand only) |
| REST | `GET /mpcf/v1/carriers` additive metadata (`tracking_url_template`, `tracking_number_pattern`, `phone_required`) |
| Immutability | Registry definitions never mutated at runtime; Settings deferred to later M5 |
| Schema | No new tables |

## VII.2 Explicitly not in M5-A (→ later M5 / M13)

Notification / NotificationFactory / Dispatcher / NotificationStrategy;
TrackingEmailExtension; Settings Shipping & notifications card; customer
emails; Workspace warn-only UX polish; carrier APIs / labels / PDF /
receiving / inventory / Mission Control.

## VII.3 M5-B delivered (Notification Configuration)

| Concern | Implementation |
|---|---|
| NotificationStrategy | `Domain\Notification\NotificationStrategy` — `COMPLETED_EMAIL` / `MPCF_SHIPPED` / `BOTH` / `DISABLED` immutable enum (`COMPLETED_EMAIL` is the approved completed-order email strategy; Domain avoids confined store-platform tokens) |
| Configuration | `Application\Notifications\NotificationConfiguration` (immutable) + `NotificationConfigurationService` |
| Settings keys | schema v6: strategy, sender, reply-to, tracking footer, subject, introduction, signature; reuses `default_carrier_id` |
| Carrier default | Registry-validated via configuration service; empty/unknown → `other` (Settings stays pure — no registry dependency) |
| Admin UI | Settings **Notifications** card (MPDS); sticky save bar; capability `mpcf_manage_settings` |
| Public API for M5-C | `NotificationConfigurationService::get()` / `strategy()` / `default_carrier_id()` |
| Hooks | None added (avoid speculative hooks) |
| Schema | No new tables |

## VII.4 Explicitly not in M5-B (→ M5-C+)

Notification / NotificationFactory / Dispatcher / EmailChannel /
TrackingEmailExtension; shipment event handlers; customer emails;
notification REST endpoints; carrier APIs; preview/test-send UI.

## VII.5 M5-C delivered (Notification Engine)

| Concern | Implementation |
|---|---|
| Notification | Immutable `Domain\Notification\Notification` (recipient, subject, bodies, tracking, shipment snapshot, metadata) |
| Factory | `Application\Notifications\NotificationFactory` — shipment → Notification |
| Orchestration | `NotificationService` — strategy gate, dedup (120s), audit, status |
| Event bridge | `NotificationDispatcher` subscribes to `shipment.shipped` |
| Channel port | `Domain\Notification\NotificationChannel` + `NotificationResult` |
| Email transport | `Infrastructure\Notifications\EmailChannel` (`wp_mail` only) |
| Recipient port | `Domain\CustomerEmailLookup` → `Woo\WooCustomerEmailLookup` |
| Schema | No new tables — audit via existing fulfillment events |

## VII.6 M5-D delivered (WooCommerce Email Integration)

| Concern | Implementation |
|---|---|
| Completed-order extension | `Woo\TrackingEmailExtension` on `woocommerce_email_after_order_table` |
| Strategy | `COMPLETED_EMAIL` / `MPCF_SHIPPED` / `BOTH` / `DISABLED` via configuration service |
| No duplicate templates | Extension appends tracking block; MPCF shipped email uses factory HTML |
| Workspace | Send button + last status/time on shipped shipment cards |
| REST | `POST /shipments/{id}/notify`, `GET /shipments/{id}/notification-status` |

## VII.7 M5-E delivered (Stabilization & RC)

Focused unit + integration coverage; dogfood classification; docs
reconciled; `v0.5.0` release candidate prepared (not tagged/published
without PO approval). Explicitly not in M5: SMS/push, carrier APIs,
labels, inventory/receiving, Mission Control, M6 photography.

---


# Part VIII — M6 Package photography (operational evidence)

**Milestone purpose:** Prove what left the warehouse. Package photography is
**operational evidence**, not a DAM, media library, product photography
system, or inbound/receiving feature (ADR-0007).

**Baseline:** `v0.5.0` on green `main`. Target release: `v0.6.0`.

**Product Owner decisions (binding):**

| Decision | Choice |
|---|---|
| Owning entity | **Package** owns each photo; **Fulfillment** owns the audit stream |
| `package_id` | **Required** (NOT NULL) on every M6 capture |
| Storage | Protected `uploads/mpcf/photos/…` only (ADR-0004 / I9) — **never** WP Media Library |
| Canonical bytes | Deterministic server-side processing; **no** raw unprocessed original kept |
| Integrity | `sha256` covers canonical stored image bytes; `processing_version` identifies the pipeline that produced them |
| Immutability | Capture creates an immutable record; replace = new capture |
| Soft-delete | Sets `deleted_at`; **bytes remain** until retention purge |
| Retention purge (M6-D) | Removes canonical + thumbnail **files only**; preserves metadata, audit events, fulfillment history |
| Capture vs delete | Operators: `mpcf_capture_photos`. Soft-delete: **`mpcf_delete_photos` (Lead+ only)** |
| `photo_required` | When enabled, ≥1 **active** photo with `kind = package` before `packing → packed`. **Contents photos do not satisfy** |
| Guard edge | Wire on **`packing → packed`** (shipped workflow), not `packed → shipped` |
| Streamer | REST content routes (M4 documents pattern); ADR-0004 behavior preserved |

## VIII.1 Aggregate ownership

```
Fulfillment (audit root)
  └── Shipment
        └── Package  ← PhotoRecord (mpcf_media) attaches here
```

Capture requires an existing package that belongs to the fulfillment
(via its shipment). No fulfillment-only orphan photos in M6.

## VIII.2 Domain model

- `Domain\Media\PhotoRecord` — immutable evidence row
- `Domain\Media\PhotoKind` — allow-list: `contents` | `package`
- Port `Domain\Repository\MediaRepository` — create / get / list / soft_delete / counts / next_sequence; **no** hard-delete or arbitrary update
- Port `Domain\Media\PhotoStorage` + `Domain\Media\ImageProcessor`
- Application `PhotoService` — sole mutation entry point

## VIII.3 Storage (ADR-0004)

```
wp-content/uploads/mpcf/photos/{yyyy}/{mm}/{fulfillment_id}/{token}.{ext}
wp-content/uploads/mpcf/photos/{yyyy}/{mm}/{fulfillment_id}/{token}-thumb.jpg
```

Deny rules + empty `index.html` under the protected root (same model as
M4 documents). Relative paths only in DB. Random non-guessable filenames.
Atomic writes. No public URLs. No Media Library registration.

## VIII.4 Processing pipeline v1 (`processing_version = 1`)

1. Accept JPEG / PNG / WebP source bytes only (validated MIME + decode).
2. Decode; reject malformed / zero-dimension / decompression-bomb estimates.
3. Normalize EXIF orientation into pixels.
4. Resize so longest edge ≤ configured max edge px.
5. Strip EXIF GPS and unnecessary metadata.
6. Encode canonical JPEG (quality fixed for v1) as the evidence artifact.
7. Generate one gallery JPEG thumbnail.
8. SHA-256 the **final canonical stored bytes**.
9. Persist `processing_version = 1` with dimensions, MIME, byte size.

Future pipeline changes **must** increment `processing_version`. Historical
hashes remain unambiguous because each row records which pipeline produced
its bytes.

## VIII.5 Soft-delete and retention semantics

| Action | Row | Canonical + thumb bytes | Audit chain |
|---|---|---|---|
| Capture | Insert | Written | `photo.captured` |
| Soft-delete | `deleted_at` set | **Preserved** | `photo.deleted` |
| Retention purge (M6-D) | Metadata kept; paths cleared; `purged_at` | **Removed** | `photo.purged` |

The audit chain is permanent. Purge records intentional file removal under
retention policy — it does not erase history.

## VIII.6 Photo requirement rule

`requirement_satisfied(fulfillment_id)` ↔ at least one **non-deleted**
photo with `kind = package` for that fulfillment.

Contents-only evidence never satisfies the guard. M6-B wires
`TransitionContextFactory`; M6-A only exposes the query.

## VIII.7 Capability boundary

| Cap | Roles | Purpose |
|---|---|---|
| `mpcf_capture_photos` | Operator + Lead + Admin | Capture / upload |
| `mpcf_delete_photos` | **Lead + Admin only** | Soft-delete |

No new workflow states. Authorization is capability-only. Controllers
(M6-B) enforce delete cap; `PhotoService` remains Admin/REST-free.

## VIII.8 Milestone packages

| Package | Delivers | Does not |
|---|---|---|
| **M6-A** | Part VIII docs; `mpcf_media` migration; domain; store; processor; `PhotoService`; audit events; foundation tests | REST, Workspace UI, settings UI, purge job, browser, version bump |
| **M6-B** | REST + stream; guard wiring; delete-cap on DELETE | Workspace gallery UI |
| **M6-C** | Workspace capture/gallery; settings | Retention scheduler |
| **M6-D** | Retention purge; Detail CS gallery; docs reconcile; `v0.6.0` RC | — |

## VIII.9 Explicit non-goals (ADR-0007 and beyond)

OCR; AI image analysis; barcode recognition; video; customer uploads;
carrier labels; returns photography; inventory / supplier / PO / receiving
photos (`wc-inventory-overview`); Mission Control; live `getUserMedia`
widget; photo annotations; signed CDN URLs; Media Library; keeping raw
unprocessed originals; inbound domain work.

## VIII.10 M6-A delivery record

**Status:** foundation complete on `feature/m6-package-photography` (not merged; no `v0.6.0`).

**Shipped in M6-A:**

| Area | Outcome |
|---|---|
| Schema | Migrator target **6**; `mpcf_media` with `package_id NOT NULL`, `sha256 CHAR(64)`, `processing_version`, soft-delete `deleted_at`, retention marker `purged_at`, indexes `fulfillment_deleted` / `package_deleted` / `fulfillment_seq` |
| Domain | Immutable `PhotoRecord`, allow-listed `PhotoKind` (`contents` \| `package`), `ProcessedImage`, ports `MediaRepository` / `PhotoStorage` / `ImageProcessor` |
| Storage | `ProtectedPhotoStore` under `uploads/mpcf/photos/{yyyy}/{mm}/{fid}/{token}.jpg` (+ `-thumb.jpg`); deny rules; atomic write; no Media Library; no public URL |
| Processing | `GdImageProcessor` **processing_version = 1**: decode JPEG/PNG/WebP → EXIF orientation into pixels → longest-edge resize → re-encode JPEG (metadata stripped) → thumb → SHA-256 of canonical bytes |
| Service | `PhotoService` sole mutator: `capture`, `get`, lists, `soft_delete` (idempotent; bytes retained), `requirement_satisfied` (≥1 active `kind=package`) |
| Audit | `photo.captured` / `photo.deleted` on fulfillment chain; payloads per VIII.6 (no `photo.purged` yet) |
| Caps | `mpcf_capture_photos` (operators+); `mpcf_delete_photos` (lead+ / full-access only) — controller enforcement deferred to M6-B |
| Config | Internal `PhotoConfig` defaults only (no settings UI) |
| Tests | Domain/unit/service/store/processor/guards + media schema integration; CI unit+integration install `gd` |

**Explicitly not shipped in M6-A (delivered in M6-B+):** REST/stream, Workspace/Detail UI, settings UI, `TransitionContextFactory` / packing guard wiring, retention purge job, browser tests, version bump, release.

**Algorithm note (v1):** changing decode/resize/quality/thumb parameters without bumping `PROCESSING_VERSION` is forbidden; reprocess-in-place is forbidden (replace = new capture).

## VIII.11 M6-B delivery record

**Status:** REST + packing-guard wiring complete on `feature/m6-package-photography` (not merged; no `v0.6.0`).

**Shipped in M6-B:**

| Area | Outcome |
|---|---|
| Settings | `SCHEMA_VERSION` **7**; `photos_required` (default `false`); accessor `Settings::photos_required()` |
| Service | Versioned `capture_with_version` / `soft_delete_with_version` (+ `PhotoMutationResult`); `list_active`, `get_active`, `read_bytes` (no paths in responses) |
| REST | `PhotosController`: list/capture/metadata/content/thumb/soft-delete; stream via `rest_pre_serve_request`; delete capped to `mpcf_delete_photos` |
| Guard | `TransitionContextFactory` reads `photos_required` + `PhotoService::requirement_satisfied`; Engine `PhotoRequiredGuard` remains context-flag pure |
| Composition | `Plugin` builds one `PhotoService` and passes it to factory + REST |
| Tests | Unit (resource/factory/settings/service/guards) + integration REST/concurrency/guard matrix |

**Explicitly not shipped (M6-C+):** Workspace capture/gallery UI, settings UI, CS Detail gallery, retention purge job, browser tests, version bump, release.

## VIII.12 M6-C delivery record

**Status:** Workspace capture/gallery + settings UI complete on `feature/m6-package-photography` (not merged; no `v0.6.0`).

**Shipped in M6-C:**

| Area | Outcome |
|---|---|
| Settings | `SCHEMA_VERSION` **8**; `photos_max_per_fulfillment` / `photos_max_upload_bytes` / `photos_max_edge_px` / `photos_retention_months` (+ existing `photos_required`); Settings UI card; accessors; `PhotoConfig::from_settings()` wired in `Plugin` with `GdImageProcessor(max_edge)` |
| Workspace | Per-package photo section (`data-mpcf-photos`), requirement banner, gallery + file/dropzone upload (no getUserMedia), lightbox preview, soft-delete for Lead+, `PhotoEventLabels` timeline copy, `photo_required` guard messaging |
| Assets | `mpcf-photos` script module; `window.mpcfWorkspace.photos` config; `api.js` list/upload/delete/stream helpers |
| Retention | Months setting stored only — **no purge execution** |
| Tests | Settings/PhotoConfig/PhotoEventLabels unit; Settings/Workspace/Assets integration; `tests/browser/photos.spec.js` |

**Explicitly not shipped (M6-D):** Retention purge job, CS Fulfillment Detail gallery, docs reconcile for release, version bump, `v0.6.0` RC.

## VIII.13 M6-D delivery record

**Status:** Retention purge + CS Detail gallery + `v0.6.0` RC on
`feature/m6-package-photography` (draft PR; **not tagged/published**).

**Shipped in M6-D:**

| Area | Outcome |
|---|---|
| Eligibility | `PhotoRetentionEligibility` (UTC; retention `0` = never; inclusive cutoff) |
| Purge | `PhotoRetentionService::purge_batch()` — remove bytes, clear paths, set `purged_at`, append `photo.purged`; idempotent missing-file recovery; FS failure skips `purged_at` |
| Schedule | `PhotoRetentionScheduler` — Action Scheduler group `mpcf`, daily, batch 50, transient overlap lock |
| CS gallery | Fulfillment Detail package-grouped read-only gallery; purged metadata copy; protected preview via REST |
| API resource | `purged` + `has_bytes` on photo JSON (still no paths) |
| Settings | `photos_retention_months` allows `0` (indefinite) |
| Release | Version triad `0.6.0`; `docs/M6_RELEASE_REPORT.md` |

**Residual risk:** filesystem delete and DB/audit are not one atomic transaction; retries reconcile missing files then mark purged.

**Explicitly not shipped:** M7 barcode/scan; returns photography; purge-now UI; Site Health disk warning (M9); production deploy/tag.



---


# Part IX — M7 Barcode & Scan Mode (definitive execution plan)

**Milestone purpose:** Let an operator complete picking and packing accurately
using a USB/Bluetooth keyboard-wedge barcode scanner, integrated into the
existing Workspace — not a separate application. Manual controls remain
available. ADR-0007 ownership is unchanged: no inventory/receiving/stock
coupling; location snapshots remain immutable pick hints only.

**Baseline:** `v0.6.0` on green `main` (schema settings **8**, migrator target
**6**). Target release: `v0.7.0`. **No new tables.** Schema bump only if a
genuine M7 persistence need appears (none expected).

**Product Owner decisions (binding for M7):**

| # | Decision | Choice |
|---|---|---|
| 1 | Scan modes | **Picking Scan Mode** and **Packing Scan Mode** only, inside Workspace |
| 2 | Mutation entry | Sole Application entry: `ScanService` composing `PackingService` |
| 3 | Primary input | Keyboard-wedge → existing MPDS scan sink (`data-mpcf-scan`) + focused field |
| 4 | Camera | Progressive enhancement only (`BarcodeDetector` if present); **not** required |
| 5 | Quantities | +1 per successful item scan; absolute write via `PackingService` |
| 6 | Package scans | Identification / active-package switch only — **no** live per-package qty allocation redesign |
| 7 | Correction | Server-authoritative **undo last scan** via short-lived per-operator transient (no scan-session table) |
| 8 | Capabilities | Reuse `mpcf_process_fulfillments` — no new caps |
| 9 | Barcode render | Pure-PHP Code 128 SVG — **zero** runtime Composer deps |
| 10 | Document payload | System barcodes encode `MPCF:F:{fulfillment_id}`; human-readable order number remains visible |

## IX.1 Identifiers that may be scanned

| Kind | Example | Effect |
|---|---|---|
| MPCF fulfillment | `MPCF:F:{id}` | Identify / open context; not a qty mutation by itself |
| MPCF item | `MPCF:I:{fulfillment_item_id}` | Resolve exactly one line on this fulfillment |
| MPCF package | `MPCF:P:{package_id}` | Switch Workspace active package when package belongs to fulfillment |
| MPCF product | `MPCF:PR:{product_id}` | Resolve among this fulfillment's lines (exact product_id; reject if >1 or variation required) |
| MPCF variation | `MPCF:V:{variation_id}` | Resolve among this fulfillment's lines (exact variation_id) |
| Plain SKU | merchant label text | Exact case-sensitive match on `sku_snapshot` among current fulfillment lines |
| Order number (legacy text) | `#1001` / `1001` | Resolve as fulfillment identity only when scanning **to open** from documents/queue helpers; **not** used as item qty payload |

Plain product SKU scans remain first-class so merchants need not re-label stock.
There is **no** EAN/GTIN column on `FulfillmentItem` today — M7 does not add one
and does **not** read external inventory plugins for barcode master data.

## IX.2 Barcode payload format (versionable)

```
MPCF:<TYPE>:<VALUE>
```

- Prefix `MPCF` (literal, uppercase).
- `<TYPE>` one of: `F` | `I` | `P` | `PR` | `V`.
- `<VALUE>` positive decimal integer, no leading zeros required, no table names,
  no secrets, no PII.
- Optional future: `MPCF:1:F:{id}` — if a leading numeric segment appears after
  `MPCF`, treat it as format version; M7 parser accepts versionless form as v1.
- Max payload length enforced at the API boundary (256 chars after trim).
- Human-readable fallback: always display the encoded string (and order number
  beside fulfillment barcodes on documents).

Parser rejects: wrong prefix, unknown type, non-integer value, empty value,
embedded whitespace inside the triple, values ≤ 0.

## IX.3 Scan resolution order (deterministic)

Against **only** the current fulfillment's items (never global product search):

1. Parse as namespaced `MPCF:I:{item_id}` → exact item id on this fulfillment.
2. Else parse as `MPCF:V:{variation_id}` → exact `variation_id` match(es).
3. Else parse as `MPCF:PR:{product_id}` → exact `product_id` where `variation_id = 0`
   preferred for simple lines; if any matching line has `variation_id > 0` and
   another also matches product_id alone, reject as **ambiguous / variation required**.
4. Else exact `sku_snapshot` match (trimmed payload, case-sensitive).
5. Else parse as `MPCF:F:` / `MPCF:P:` → identity/navigation outcomes (not item resolve).
6. Else **no match**.

Reject (operator-readable codes, no silent choice):

| Code | When |
|---|---|
| `unknown_barcode` | No match |
| `ambiguous_sku` | >1 line shares the SKU / product key |
| `item_not_on_fulfillment` | Valid `MPCF:I` / `V` / `PR` id not on this fulfillment |
| `variation_required` | Parent/simple product key when variation lines are the only matches |
| `malformed_payload` | Bad `MPCF:…` shape |
| `wrong_fulfillment` | `MPCF:F` id ≠ current Workspace fulfillment |

## IX.4 Picking Scan Mode

**Eligible states:** `picking` only (qty field `qty_picked`). Terminal /
exception / packing / shipped / etc. → `wrong_stage`.

**Flow:** Enter mode → focus scan sink → scan item → `ScanService::scan_pick`
→ `picked += 1` via absolute `PackingService::update_quantities` → feedback.

**Over-scan:** if `qty_picked >= qty_ordered`, **no mutation**, code `over_scan`.

**Stage complete signal:** response flag when every line `qty_picked === qty_ordered`
(does not auto-transition; operator still uses existing primary action).

## IX.5 Packing Scan Mode

**Eligible states:** `packing` only (qty field `qty_packed`).

**Rules per scan:**

- `qty_packed += 1`
- Reject `over_scan` if next value would exceed `qty_ordered`
- Reject `not_yet_picked` if next value would exceed `qty_picked` (stricter than
  domain clamp — Scan Mode enforces pick-before-pack even though
  `FulfillmentItem::record_packed` historically clamps only to ordered)

**Package association:** Client may send `active_package_id`. Server validates
ownership (package → shipment → fulfillment). Package barcode (`MPCF:P:`)
returns `package_switched` without qty change. Item scans do **not** write
`mpcf_package_items` — allocation remains shipment-create snapshot (M2). Document
this boundary in API.md / release report.

## IX.6 Duplicate-scan / unexpected-item / quantity

- Duplicate physical units = repeated successful +1 scans (intentional; **not** HTTP-idempotent).
- Unexpected = `unknown_barcode` / `item_not_on_fulfillment` / `ambiguous_sku`.
- Manual qty entry remains outside Scan Mode via existing checklist controls.
- Client holds a request lock so one completed wedge scan → one HTTP POST.

## IX.7 Optimistic concurrency

Same fulfillment `version` token as `PUT …/items`. Stale → **409**
`mpcf_version_conflict`. Client refreshes authoritative state and does **not**
auto-replay the scan. Operator sees “not recorded”.

## IX.8 Scanner input handling

Reuse MPDS `scan-sink.js` (50 ms quiet period, Enter/Tab terminators,
`data-mpcf-scan` event). M7 Workspace module:

- Subscribes only while Scan Mode is active.
- Strips surrounding whitespace.
- Suppresses Workspace letter shortcuts while Scan Mode active (fixes Part IV
  letter-vs-wedge collision for the active mode).
- Escape exits Scan Mode safely (no mutation).
- Explicit focused sink remains the reliable fallback.

No hardware SDKs.

## IX.9 Mobile camera (optional)

If `window.BarcodeDetector` exists, Workspace may offer a small “Use camera”
control that feeds the same resolve/pick/pack path. Absence must not block M7.
No large JS barcode libraries.

## IX.10 ScanService operations

| Op | Behavior |
|---|---|
| `resolve_scan` | Parse + resolve; no mutation; returns match metadata |
| `scan_pick` | Stage check → resolve → over-scan check → PackingService +1 picked → audit |
| `scan_pack` | Stage check → package ownership if provided → resolve → pick/pack ceilings → +1 packed → audit |
| `undo_last_scan` | Load transient last successful scan for `(user_id, fulfillment_id)`; decrement by 1 if still eligible; clear/advance transient |
| `get_scan_state` | Optional: progress summary (remaining, recent from client; server returns items + version) |

Undo transient TTL ≈ 30 minutes; stores `{mode, item_id, resulting_qty, version_after, package_id?}`.
If undo target no longer matches current qty (concurrent edit), reject `undo_unavailable`
and clear transient — operator uses manual minus.

## IX.11 REST contract (smallest coherent)

```
POST /mpcf/v1/fulfillments/{id}/scan
```

Body:

```json
{
  "action": "resolve|pick|pack|undo",
  "payload": "string",
  "version": 12,
  "active_package_id": 3
}
```

- Cap: `mpcf_process_fulfillments`
- `undo` ignores payload (uses transient)
- Success envelope: `result` (status code string), `message`, `item`?, `items`?,
  `version`, `transitions`, `stage_complete`?, `active_package_id`?, `progress`?
- Failures: existing `failure_error` mapping (`version_conflict` → 409, etc.)

Thin `ScanController` → `ScanService` only.

## IX.12 Audit events

Prefer scan-specific events (bounded, high ops value):

| Event | When |
|---|---|
| `scan.item_picked` | Successful pick scan |
| `scan.item_packed` | Successful pack scan |
| `scan.corrected` | Successful undo |

Payload: `item_id`, `product_id`, `variation_id`, `mode`, `qty_picked` or
`qty_packed` (resulting), `package_id`?, `source` (`mpcf_payload`|`sku`),
`actor` via chain. **No** raw rejection spam events (`scan.rejected` deferred —
toasts suffice). Quantity still also flows through `items.picked` /
`items.packed` via PackingService (one coalesced absolute batch per scan).

Timeline labels via `ScanEventLabels`.

## IX.13 Workspace UX states

Scan Mode panel (bounded, not a Workspace redesign):

- Enter Picking / Enter Packing (enabled by current state)
- Status: Ready / Success / Warning / Error (text + non-color attribute)
- Current result line, progress (picked/packed vs ordered), remaining
- Recent scans (client ring buffer)
- Undo last scan
- Exit / Escape
- Optional sound: local, toggleable, never sole feedback

## IX.14 Barcode generation (documents)

- `Documents\Barcode\Code128Encoder` → SVG path/bars (Code 128B), deterministic
- Helper renders SVG + human-readable text
- Picking list + packing slip: encode `MPCF:F:{fulfillment_id}`; show order
  number as secondary text
- Optional per-line `MPCF:I:{item_id}` micro-barcode on picking list when
  item id present (SKU remains human text)
- No remote API; documents remain HTML; no PDF requirement; no tracking pixels

Assembler change: `barcode_payload()` becomes `MPCF:F:{id}` (tests updated).
Order number remains `order_number()` on the model for headers.

## IX.15 Performance targets (measure in dogfood; do not invent claims)

- Resolve restricted to in-memory fulfillment item list (no N+1 product lookup)
- No full-page reload
- Dev target: typical scan HTTP < 300 ms wall time on this VPS (record actuals)

## IX.16 Security / structural guards

Capability; fulfillment access; workflow stage; payload length/format;
SKU resolution scoped to fulfillment; version token; package ownership;
no stock mutation; no `wc-inventory-overview` tables; no raw SQL in
controllers; quantity never trusted from client (server computes +1);
`RestBoundaryGuard` / inventory coupling guards unchanged or extended.

## IX.17 Explicit non-goals

Inventory/receiving/stock/cycle-count scanning; warehouse location master
data; carrier-label scanning; photography scanning; OCR; RFID; hardware
SDKs; offline mode; mobile app; M8 batch picking; Mission Control redesign;
live per-package packed-qty allocation UI; EAN master-data table; mandatory
camera scanning.

## IX.18 Milestone packages

| Package | Delivers | Does not |
|---|---|---|
| **M7-A** | Part IX; payload VO/parser; resolver pure rules; Code128 SVG; document template integration; unit tests | REST mutations, Workspace Scan Mode panel |
| **M7-B** | `ScanService`; REST; concurrency; audit; undo transient; unit/integration | Workspace UI |
| **M7-C** | Scan Mode panel; wedge wiring; feedback; package switch UX; browser tests | Version bump |
| **M7-D** | Dogfood; blocker fixes; docs; `0.7.0` RC ZIP/audit; draft PR | Merge/tag/publish/deploy; M8 |

## IX.19 Stop conditions (runtime)

Stop and report if: inventory/receiving data required; stock mutation needed;
package allocation redesign required; large barcode dependency required;
camera becomes mandatory; scan-session DB table required; M8/Mission Control
becomes necessary; another agent dirties the tree; CI needs broad unrelated
fixes.

## IX.20 Numbering reconciliation note

Part IV prose historically labeled barcode semantics as “M6”. Authoritative
sequence is ROADMAP + §20 + Parts VIII/IX: photography = M6, barcode = M7.

## IX.21 M7 delivery record

**Status:** M7 **closed** — merged to `main`, tagged and published as `v0.7.0`
(evidence: `docs/M7_RELEASE_REPORT.md`). Schema unchanged (settings **8**,
migrator target **6**). No inventory/receiving coupling.

| Package | Outcome |
|---|---|
| M7-A | Part IX; `BarcodePayload` / `ScanResolver`; Code 128 SVG; documents encode `MPCF:F:{id}` (+ item `MPCF:I:{id}` on picking list) |
| M7-B | `ScanService` + transient undo store; `POST …/scan`; audits `scan.item_picked` / `scan.item_packed` / `scan.corrected` |
| M7-C | Workspace Scan Mode panel + `scan.js` keyboard-wedge; shortcut suppression; browser `scan-mode.spec.js` |
| M7-D | Docs reconcile; version triad `0.7.0`; ZIP + release audit; published release |

---

# Part X — M8 Wave & Batch Picking (definitive execution plan)

**Milestone purpose:** Maximize warehouse walking throughput by letting one
operator pick **many fulfillments in a single warehouse walk** (a **Wave**),
then hand each fulfillment to the existing per-order packing workflow.
Batch picking **ends at `picked`**. Packing, photography, shipping, and
notifications remain per-fulfillment as shipped through M7.

**Baseline:** green `main` @ `v0.7.0` (settings schema **8**, migrator target
**6**). Target release: `v0.8.0`. Expected schema: migrator target **7**
(`mpcf_waves`, `mpcf_wave_members`); settings may rise to **9** for wave
caps only.

**ADR-0007 remains authoritative.** Waves use immutable `location_snapshot`
hints only. No inventory, receiving, stock, location-master, or
`wc-inventory-overview` coupling.

**Numbering:** §20 row “M8 — Batch picking” is this milestone. Historical
prose that called batch picking “M7” (pre–barcode renumber) is obsolete —
Part IX.20 + ROADMAP + this Part are authoritative. Domain language is
**Wave**; “batch” remains a synonym in older §20 wording and is not a
second aggregate.

**Product Owner decisions (binding for M8 — proposed; require PO approval
before implementation):**

| # | Decision | Choice |
|---|---|---|
| 1 | Aggregate name | **Wave** (tables `mpcf_waves` / `mpcf_wave_members`) |
| 2 | Wave ends at | **`picked`** per member fulfillment; packing never runs inside a wave |
| 3 | Scan surface | **Extend M7 Scan Mode** — Wave Picking Scan Mode; no separate scanner app |
| 4 | Cross-order same SKU | **Deterministic FIFO** by fulfillment `created_at ASC`, then item id — **no operator chooser** for multi-order matches |
| 5 | Same-fulfillment ambiguity | Keep M7 rules (`ambiguous_sku`, `variation_required`) |
| 6 | Location sort | Sort walk by `location_snapshot` (NULLS LAST), then SKU — **hint only**, not inventory authority |
| 7 | Mutation entry | `WaveScanService` (or `ScanService` wave overload) → existing `PackingService` absolute +1 pick |
| 8 | Ownership | Exclusive **owner user** on active/paused wave; resume by owner (lead override later) |
| 9 | Capabilities | Reuse `mpcf_process_fulfillments` for M8; no new caps unless dogfood proves lead-only create is required |
| 10 | Operation Context | **Document now; do not introduce a full Operation Context framework in M8** (see X.8) |
| 11 | Documents | New doc type `wave_picking_list` (combined walk); per-order picking list remains |
| 12 | Auto-group | Queue filters + warehouse_id + optional SKU-overlap heuristic; manual add/remove always available |

## X.1 Goals and non-goals

### Goals

1. One warehouse walk → many orders picked.
2. Combined picking list grouped for walking, not per-order paper stacks.
3. Keyboard-wedge Batch/Wave Scan Mode reusing M7 sink + feedback patterns.
4. Clean handoff: each member reaches `picked`, then individual packing Workspace.
5. Survive disconnect: pause/resume without losing progress.
6. Stay outbound-only (ADR-0007).

### Explicit non-goals (M8)

Inventory ownership; receiving; cycle counting; warehouse master data /
bins as authority; route-optimization AI; RF terminals; mobile/PWA app;
mandatory camera scanning; carrier batching; packing batching; Mission
Control redesign; live per-package allocation redesign; EAN master table;
stock mutation; reading `wc-inventory-overview` tables.

## X.2 Wave lifecycle (M8-A)

### States

| State | Meaning |
|---|---|
| `draft` | Being composed; not yet walked |
| `active` | Operator is walking / scanning |
| `paused` | Owned, interrupted; progress retained |
| `completed` | All members `picked` (or force-completed with recorded exceptions) |
| `abandoned` | Cancelled; members released back to queue/picking without inventing stock moves |

Transitions: `draft → active | abandoned`; `active → paused | completed | abandoned`;
`paused → active | abandoned`. No revive from `completed`/`abandoned`.

### Aggregate

**Wave** (root):

- `id`, `warehouse_id`, `owner_user_id`, `state`, `version` (optimistic concurrency)
- `title` / optional label
- `created_at`, `activated_at`, `completed_at`, `abandoned_at`
- `settings_snapshot` JSON (max members, grouping criteria used) — auditability

**WaveMember**:

- `wave_id`, `fulfillment_id` (unique membership: a fulfillment is in at most
  **one** non-terminal wave)
- `position` (stable walk / display order)
- `joined_at`, `picked_at` (when that fulfillment reached `picked` under the wave)

**Why persist (not transient-only):** resume after browser crash, multi-hour
walks, printed wave list + later scan, audit of who walked which set, and
conflict detection across operators. Transients remain acceptable for
**undo-last-scan** (M7 pattern), not for wave membership.

### Operations

| Op | Behavior |
|---|---|
| Create | `draft` wave for current `warehouse_id` + owner |
| Add fulfillments | Only `queued` or `picking`; same `warehouse_id`; not in another open wave; optional auto `queued→picking` + assign owner on activate |
| Remove | Only while `draft` or `paused` (not mid-active scan burst without pause); member not yet `picked` |
| Auto-group | From Queue selection or filter (state=`queued`/`picking`, warehouse, age, optional shared-SKU affinity). Cap `wave_max_members` (settings, default e.g. 25, hard ceiling e.g. 100) |
| Manual group | Explicit add/remove IDs |
| Activate | `draft→active`; claim ownership; ensure members are `picking` + assignee = owner |
| Pause / Resume | `active↔paused`; scan rejected while paused |
| Complete | All members `picked` → `completed`; or lead force-complete with exception notes on unfinished members |
| Abandon | Release membership lock; leave fulfillments in their current workflow state (no silent cancel) |

### Data ownership

Waves are **MPCF outbound execution** structure. They do not store stock,
bins, or supplier data. Member progress is derived from existing
`qty_picked` / fulfillment `state` — wave tables do not duplicate quantities.

## X.3 Combined picking list / warehouse walk (M8-B)

### Walk model (document + Workspace progress)

Build a pure **WaveWalkModel** from member items:

```
location_snapshot (NULL → "∅")
  → product key (variation_id > 0 ? V:{id} : PR:{id} / sku_snapshot)
    → required_qty (sum outstanding: qty_ordered − qty_picked across members)
    → allocations[] { fulfillment_id, item_id, outstanding }
    → done_qty / complete flag
```

**Sort:** `location_snapshot` ascending (empty last), then `sku_snapshot`,
then `product_id`/`variation_id`. This is a **warehouse walk hint**, not a
path optimizer and not inventory topology.

**Duplicate SKUs across orders:** one walk row with aggregated `required_qty`
and per-fulfillment allocation list (FIFO order).

**Variations:** never collapse variation lines into parent SKU rows.

**Document:** `wave_picking_list` assembler + template; barcode for wave
identity optional (`MPCF:W:{wave_id}` — new payload type **W**, additive to
Part IX parser); human wave id + member order numbers visible. Per-line
still may show compact member ticks, not full separate lists.

**M4 picking list** stays fulfillment-scoped and unordered by location
(existing Assembler comment). Wave list is the first document allowed to
sort by `location_snapshot` because the sort key is already an MPCF
immutable snapshot — still not inventory ownership.

## X.4 Batch / Wave Scan Mode (M8-C)

### Mode

Extend `ScanMode` with `WAVE_PICKING` (or nest under picking with
`wave_id` context). Eligible only when wave `state=active` and owner matches.

**Do not** build a separate scanner. Reuse MPDS scan-sink, `scan.js`
feedback (status/result/recent/sound), shortcut suppression, undo transient
scoped to `(user, wave_id)`.

### Resolve algorithm (deterministic)

Against **union of outstanding items** in the wave (items with
`qty_picked < qty_ordered` on members still in `picking`):

1. Apply M7 parse order (`MPCF:I` / `V` / `PR` / SKU / `F` / `P` / new `W`).
2. If `MPCF:I` → must belong to a wave member; else reject.
3. If SKU / PR / V matches multiple **fulfillments**: choose outstanding
   allocation with earliest fulfillment `created_at`, then lowest `item_id`.
4. If multiple lines **on the same fulfillment** match → M7 `ambiguous_sku`
   / `variation_required` (operator must scan `MPCF:I` or variation code).
5. If no outstanding match → `unknown_barcode` or `over_scan` (wave-level).
6. `MPCF:F` / `MPCF:W` → identity/progress feedback only (no qty).
7. Packing scans **rejected** in wave mode (`wrong_mode`).

**Operator never manually chooses among multi-order SKU matches** in M8.
If product reality needs chooser UX, that is a post-M8 ADR.

### Mutation

`+1` via existing absolute quantity update on the chosen item; bump that
fulfillment’s version; when that fulfillment’s lines all complete →
workflow transition to `picked` (same guards as today); mark member
`picked_at`; refresh wave progress. Undo last wave scan restores prior qty
via M7-style correction store keyed by wave.

### Feedback

Show: SKU, fulfillment/order identity, new qty, remaining for that SKU on
wave, members complete / remaining. Errors stay operator-readable codes.

## X.5 Workspace (M8-D)

Keep M7 Workspace architecture. Add **Wave Workspace** (or Wave panel in
Mission Control deferred area — prefer a dedicated Wave screen + deep link
from Queue “Create wave from selection”):

- Wave dashboard: state, owner, progress bars (lines / fulfillments)
- Resume / Pause
- Remaining walk rows / remaining fulfillments / completed fulfillments
- Missing / exception list (members stuck, ambiguous, problem-state)
- Completion summary
- Enter Wave Scan Mode (extends M7 panel patterns)
- Print `wave_picking_list`
- “Open fulfillment” for exception handling (exits or pauses wave)

Single-fulfillment Packing Scan Mode unchanged. Switching into a wave
pauses single-fulfillment scan mode and vice versa.

## X.6 REST surface (smallest coherent)

| Method | Route | Purpose |
|---|---|---|
| POST | `/mpcf/v1/waves` | Create draft |
| GET | `/mpcf/v1/waves/{id}` | Wave + progress |
| GET | `/mpcf/v1/waves` | List mine / open (paginated) |
| POST | `/mpcf/v1/waves/{id}/members` | Add fulfillments |
| DELETE | `/mpcf/v1/waves/{id}/members/{fulfillment_id}` | Remove |
| POST | `/mpcf/v1/waves/{id}/activate` | draft→active |
| POST | `/mpcf/v1/waves/{id}/pause` | active→paused |
| POST | `/mpcf/v1/waves/{id}/resume` | paused→active |
| POST | `/mpcf/v1/waves/{id}/complete` | complete |
| POST | `/mpcf/v1/waves/{id}/abandon` | abandon |
| GET | `/mpcf/v1/waves/{id}/walk` | Combined walk model |
| POST | `/mpcf/v1/waves/{id}/scan` | Wave pick scan (+ undo action) |
| POST | `/mpcf/v1/waves/{id}/documents` | Render wave picking list |

All routes: `mpcf_process_fulfillments`; owner checks on mutating routes;
wave `version` on mutations; per-fulfillment `version` still enforced inside
scan.

Optional: `MPCF:W:{id}` on documents — parser additive.

## X.7 Audit events

Append-only, hash-chained as today:

- `wave.created` / `wave.activated` / `wave.paused` / `wave.resumed` /
  `wave.completed` / `wave.abandoned`
- `wave.member_added` / `wave.member_removed` / `wave.member_picked`
- Reuse `scan.item_picked` / `scan.corrected` with payload fields
  `wave_id`, `allocation_fulfillment_id`

No silent stock or inventory events.

## X.8 Operation Context (decision)

**Choice B — document now; do not implement a general Operation Context
framework in M8.**

Justification:

- M8 needs only one new ambient context: `wave_id` (+ owner + mode).
- Single-fulfillment Workspace already threads `fulfillment_id` via
  `data-mpcf-*` and the store module; inventing a generic Operation Context
  now risks speculative abstraction ahead of Mission Control / mobile.
- M8 introduces `WaveContext` (Application DTO / request attribute) passed
  into wave services — a **local** pattern, not a platform kernel.
- Revisit a shared Operation Context when a third concurrent ambient
  context appears (e.g. station + wave + fulfillment) or at M15 mobile.

Documented future shape (non-binding until ADR):

```
OperationContext { type: fulfillment|wave|…, id, mode?, version? }
```

## X.9 Performance expectations

| Scale | Expectation |
|---|---|
| 20 orders / ≤200 lines | Instant walk build; scan feels <300 ms (M7 dogfood class) |
| 50 orders / ≤500 lines | Walk build <500 ms server-side on VPS-class hardware; paginate walk UI if >100 rows |
| 100 orders | Allowed only if under `wave_max_members` ceiling; warn in UI; prefer multiple waves |
| 500 lines | Walk model built in one query set (members → items); no N+1; indexes on `(wave_id)`, `(fulfillment_id)` unique open-wave |

Loading: hydrate wave + members + items in bounded queries. Grouping: pure
PHP on arrays (unit-tested). Scan throughput: one item mutation path
(existing PackingService). Memory: cap members. Resume: reload wave by id.
Locking: wave `version` + owner; fulfillment `version` on qty writes.

## X.10 Concurrency

- One **owner** per active/paused wave; other operators get `wave_owned`.
- Fulfillment may belong to at most one non-terminal wave (DB unique /
  application guard).
- Stale wave version → 409, no mutation.
- Stale fulfillment version inside scan → 409, no replay (M7).
- Disconnect → wave stays `active` until pause timeout policy (optional
  M8-E: auto-pause after N minutes idle) or manual pause.
- Two scanners on one wave: rejected (single owner); no multi-picker wave
  in M8.

## X.11 Security

- Capability: `mpcf_process_fulfillments` (M8 default).
- REST: nonce / Application Passwords as today; no cookie CSRF holes.
- Ownership enforced server-side.
- Payload length/format limits from M7.
- No inventory table reads/writes; structural guard extension:
  forbid `wc-inventory-overview` and stock APIs in new Wave code paths.
- Documents: same protected storage + capability as M4.

## X.12 Schema (planned)

Migrator step → target **7**:

- `mpcf_waves` — columns per X.2; indexes `(state, warehouse_id, owner_user_id)`, `(updated_at)`
- `mpcf_wave_members` — `(wave_id, fulfillment_id)` PK/unique; index `(fulfillment_id)`; partial uniqueness for open waves enforced in Application if MySQL partial indexes unavailable

Settings schema **9** (if needed): `wave_max_members`, `wave_idle_pause_minutes` (0=off).

`PersistedKeys` + uninstall policy updated. No `mpcf_locations`.

## X.13 Milestone packages

| Package | Delivers | Does not |
|---|---|---|
| **M8-A** | Part X approved; Wave domain + repos + migrator 7; REST create/members/lifecycle; unit/integration | Scan mode, documents, rich UI |
| **M8-B** | WaveWalkModel; `wave_picking_list` document; `MPCF:W` parser additive; print from wave | Scan mutations |
| **M8-C** | Wave Scan Mode + `/waves/{id}/scan`; FIFO allocation; undo; browser tests | Mission Control redesign |
| **M8-D** | Wave Workspace UI (progress, pause/resume, exceptions, enter scan); Queue “add to wave” | Analytics |
| **M8-E** | Dogfood; hardening (idle pause optional); docs (`API`/`HOOKS`/`PERSISTED_DATA`); `0.8.0` RC ZIP/audit; PR | Production deploy without PO; M9 |

## X.14 Acceptance criteria (falsifiable)

1. Operator creates a wave of ≥5 queued fulfillments, activates, completes a
   walk using Wave Scan Mode only, and every member ends in `picked`.
2. Combined picking list groups duplicate SKUs and sorts by `location_snapshot`
   without reading inventory plugins.
3. Scanning a shared SKU allocates FIFO across members; never prompts a chooser.
4. Over-scan / unknown / wrong-stage / non-owner / stale version reject without
   mutation.
5. Pause → browser refresh → resume continues with same progress.
6. Abandon releases membership; fulfillments remain valid workflow citizens.
7. Packing a wave member uses existing single-fulfillment Workspace (no wave
   packing).
8. PHPCS, unit, integration, browser, POT, release-audit green; ADR-0007
   guards pass; version triad `0.8.0`.

## X.15 Validation & testing

- Unit: walk grouping/sort/FIFO allocator; wave state machine; parser `W`.
- Integration: REST lifecycle; scan across members; concurrency 409;
  membership exclusivity; document render.
- Browser: create/activate/scan/pause/resume happy path (Playwright).
- Structural: no inventory coupling in `Application/Wave`, `Domain/Wave`,
  wave REST controllers.
- Dogfood: 20-order wave on `dev.biopentra.eu`; measure scan latency.

## X.16 Release strategy

Branch `feature/m8-wave-batch-picking` from `v0.7.0` / main. One PR. Version
`0.8.0`. Tag `v0.8.0` only after PO GO. No production deploy in-milestone
unless separately ordered. M9 (Analytics I) must not start until M8 closes.

## X.17 Stop conditions (runtime)

Stop and report if: inventory/receiving data required; stock mutation
needed; operator chooser required for normal multi-order SKUs; packing
batching required; Mission Control redesign required; mobile/RF required;
another agent dirties the tree; schema needs location master tables.

## X.18 Prepare for M9

Waves emit structured audit events and completion timestamps suitable for
later throughput analytics (orders picked per wave, walk duration). M8 does
**not** build Analytics UI or `mpcf_stats_daily` (M9).
