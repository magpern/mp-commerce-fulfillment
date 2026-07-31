# Commerce Fulfillment for WooCommerce — Architecture Specification

**Status:** **Architecture Freeze v1.0** — Architecture Plan Rev 2.1 and Milestone 0 Execution Plan Rev 1 approved by the Product Owner 2026-07-31 as the permanent architectural baseline for Commerce Fulfillment.
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

This section binds branding, conventions and independence rules — not the roadmap. Ecosystem products beyond Fulfillment and Promotions are vision, not commitments.

### 2.3 Ownership boundary

| Concern | Owner | Notes |
|---|---|---|
| Products, stock quantity, prices | WooCommerce | MPCF reads product data (SKU, image, weight, dims) for display and documents; never writes it |
| Checkout, payment, refunds | WooCommerce | MPCF *reacts* to refunds/cancellations (exception states); never initiates them in v1 |
| Customers, addresses | WooCommerce | MPCF reads shipping address; address *corrections* before shipping are a post-1.0 candidate, and would write through WC CRUD |
| The order record and its statuses | WooCommerce | MPCF may advance WC status through one narrow, configurable bridge (§6.6) |
| Warehouse workflow state | **Commerce Fulfillment** | `mpcf_fulfillments.state`, driven only by the workflow engine |
| Picking / packing progress | **Commerce Fulfillment** | per-line quantities in `mpcf_fulfillment_items` |
| Shipments, carriers, tracking | **Commerce Fulfillment** | multiple shipments per order from day one |
| Fulfillment documents | **Commerce Fulfillment** | packing slip, picking list, invoice, customs, return slip |
| Package photos | **Commerce Fulfillment** | protected storage, audit-fingerprinted |
| Fulfillment audit trail | **Commerce Fulfillment** | append-only `mpcf_events` |
| Internal warehouse notes | **Commerce Fulfillment** | separate from WC order notes (§14) |
| Fulfillment analytics | **Commerce Fulfillment** | derived from the event log |
| Returns / RMA | **Commerce Fulfillment** (post-1.0) | separate aggregate, same engine |

### 2.4 Personas

- **Warehouse operator** — picks and packs all day. Needs speed, big targets, keyboard/scanner flow, zero WordPress knowledge. Should never need to see the rest of wp-admin.
- **Warehouse lead / merchant** — configures workflow, carriers, documents; watches the queue and analytics; investigates problems via the audit trail.
- **Developer / integrator** — extends via documented hooks and the REST API; builds carrier adapters and automations.

### 2.5 Explicit non-goals (v1.x)

- No stock/inventory management (quantity on hand stays WooCommerce's).
- No rate shopping / checkout shipping-rate calculation (that is checkout territory; MPCF starts after payment).
- No purchase orders / inbound logistics.
- No customer-facing "track your order" portal pages in early milestones (customer touch = notification emails with tracking links; a portal is a Future Opportunity).
- No non-WooCommerce order sources in v1 — but the `OrderSource` port exists from M1 so the assumption is architectural, not structural.

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

- **Outbound (MPCF → WC), event-driven:** default mapping ships as: first fulfillment enters `shipped` **and** all fulfillments for the order are shipped → WC order `completed`. Merchant-configurable (e.g. map to a single custom `wc-shipped` status if the merchant already has one; or do nothing). Writes use `WC_Order::update_status()` with an `mpcf` note prefix.
- **Inbound (WC → MPCF), hook-driven:** order `cancelled` / fully `refunded` → open fulfillments proposed into `cancelled`/`problem` (setting: automatic vs. flagged-for-review; default automatic for cancel, flag for refund). Order edits after intake (items added/removed) → fulfillment flagged `problem` with a diff summary in the audit payload (§21 R3).
- **Loop guard:** an int depth counter (UMC's `OrderCurrencyLock` pattern) so bridge-initiated WC writes don't re-enter intake/observers.
- Authority rule, stated once and enforced by the mapping shape: **WC is authoritative for the money lifecycle; MPCF is authoritative for the warehouse lifecycle.** The bridge translates; it never lets one side drive the other's internal states directly.

---

## 7. Data model

All tables `ENGINE=InnoDB ROW_FORMAT=DYNAMIC`, explicit `CREATE TABLE` DDL in `Infrastructure\Database\Schema` (single source of truth for names), versioned idempotent steps in `Migrator` (`mpcf_db_version` option, updated after each step, runs from activation **and** an `admin_init` drift check — the bind-mount deployment lesson from AIM). No `dbDelta`, no SQL `ENUM` (states are `VARCHAR(32)` + PHP constants), UTC `DATETIME` everywhere, ids `BIGINT UNSIGNED AUTO_INCREMENT`.

### 7.1 Tables (M-numbers = milestone that introduces them)

**`mpcf_fulfillments`** (M1) — the aggregate root.
`id, order_id (indexed), order_source VARCHAR(32) DEFAULT 'woocommerce', warehouse_id BIGINT DEFAULT 1, workflow VARCHAR(64), state VARCHAR(32), previous_state VARCHAR(32), return_to_state VARCHAR(32) NULL, exception_reason VARCHAR(191) NULL, priority SMALLINT DEFAULT 0, assignee_type VARCHAR(16) NULL, assignee_id BIGINT NULL, version INT (optimistic lock), order_number_snapshot VARCHAR(64), customer_name_snapshot VARCHAR(191), item_count SMALLINT, created_at, state_entered_at, completed_at NULL`
Indexes: `(state, warehouse_id)`, `(order_id)`, `(assignee_type, assignee_id, state)`, `(created_at)`. The two snapshots exist so the Queue renders without N+1 order loads; they are display hints, never authority (the workspace always reads live order data through `OrderSource`). **Assignment is polymorphic (D20):** `assignee_type` is `'user'` everywhere in v1, but packing stations, teams and virtual queues become new type values plus registry data — never a migration. `warehouse_id` points at the warehouse-level node of the future `mpcf_locations` hierarchy (row 1 = the implicit default warehouse until that table exists).

**`mpcf_fulfillment_items`** (M1)
`id, fulfillment_id (indexed), order_item_id, product_id, variation_id, sku_snapshot VARCHAR(191), name_snapshot VARCHAR(255), qty_ordered, qty_picked, qty_packed, location_snapshot VARCHAR(191) NULL (M-locations)`
Snapshots make picking lists and audit stable even if the product is later renamed/deleted.

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

Post-1.0 (schema reserved, not created early): **`mpcf_locations`** — a single self-referential hierarchy table (`id, parent_id NULL, type VARCHAR(32), name, code VARCHAR(64), sort`) covering facility → warehouse → zone → shelf → bin with **types as data** (a flat warehouse list is just parentless rows; aisles or totes later are new type values, never an ALTER; separate `mpcf_warehouses`/`mpcf_bins` tables were rejected for exactly that reason) + `mpcf_item_locations`; `mpcf_batches` + `mpcf_batch_items` (M7); `mpcf_returns` + `mpcf_return_items`; `mpcf_stats_daily` (M8 rollups); `mpcf_search_index` (§9.3 — only if profiling demands it); `mpcf_webhooks`, `mpcf_api_keys`.

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
- **Multiple shipments per order and multiple packages per shipment** are native (§7.1): split shipments are additional `mpcf_shipments` rows; multi-parcel consignments are additional `mpcf_packages` rows, each with its own colli tracking number, dimensions, weight, photos and (later) label. Tracking display prefers package-level numbers when present, falling back to the consignment number.
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
| D13 | `warehouse_id` on fulfillments from day one (default 1); physical topology arrives post-1.0 as ONE self-referential `mpcf_locations` hierarchy (facility→warehouse→zone→shelf→bin, types as data) | Multi-warehouse is a column today, a feature later; new hierarchy levels never require an ALTER |
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

GitHub Actions, UMC's proven shape: `phpcs` (hard gate) · `pot` check · `unit` matrix (PHP 8.1 / current stable / next) · `integration` legs: **floor** (PHP 8.1 / WP 6.5 / WC 8.2.x), **current** (current stable PHP/WP/WC, pinned coordinates with a why-comment, guarded against drift), **ceiling** (`continue-on-error`) — per the PO's floor-plus-current-stable mandate · `build` (zip artifact) · `release-audit`. Release workflow: tag `vX.Y.Z` → header/tag parity check → build → GitHub release. Local dev/test tooling is Docker-only (dedicated `mpcf-test-runner` image + `mariadb:11.4` on an internal network, never published ports), documented in the gitignored `CLAUDE.local.md`, following the sibling plugins' template.

### 19.3 Per-milestone definition of done

`composer phpcs` clean; unit + integration + guards green; CI green including floor and current-stable legs; docs updated (`HOOKS.md`, `PERSISTED_DATA.md`, ADRs Accepted, `ROADMAP.md`); version bumped (four-place ritual); merged via PR; tagged only on explicit PO approval. One approved milestone at a time.

---

## 20. Milestone roadmap

Each milestone is a usable release, tagged, installable. Detailed execution plans (house format: reconciliation, scope table, sub-steps, sequenced commits, verification checklist) are written per milestone, one at a time, after PO approval.

| M | Version | Name | Delivers | New tables |
|---|---|---|---|---|
| **M0** | 0.0.x | Bootstrap & MPDS extraction | `mp-admin-design-system` v0.1.0 repo (tokens + the *existing* extracted component set + shell + behavior JS + contract tests; new §8.4 components land with the milestones that need them); plugin repo skeleton (main file, Plugin, Settings, Capabilities, PersistedKeys, migration framework, guard framework, CI, build/release tooling, canonical docs incl. this document); `bin/sync-mpds.sh` + vendor guard; activates inert | — (migration framework + `mpcf_db_version` only; schema v1 lands in M1) |
| **M1** | 0.1.0 | Fulfillment core — Warehouse MVP | Intake (paid → fulfillment, idempotent, CLI backfill); workflow engine + standard workflow; Queue (filters, search, bulk assign); fulfillment detail (timeline, notes, manual transitions); audit stream + hash chain; roles/capabilities + operator mode; status bridge v1; dashboard v1; uninstall policy | fulfillments, items, events, notes |
| **M2** | 0.2.0 | Packing Workspace & REST | `mpcf/v1` (fulfillments, transitions, items, notes, shipments); the workspace (checklist, packages + specs, manual carrier+tracking, sticky action bar, drawer from queue); optimistic-concurrency UX; Application Passwords documented | shipments, packages, package_items |
| **M3** | 0.3.0 | Documents I | Assembler/renderer/template architecture; packing slip + picking list (print-HTML, barcode payloads, branding settings, template overrides); render audit + reprint history | documents |
| **M4** | 0.4.0 | Tracking & notifications | Carrier registry (EU-skewed bundled set); tracking validation hints; multi-package UX polish; notification subsystem (policy/dispatcher/EmailChannel, §16.1) with shipped email per shipment + WC-email tracking block; bridge mapping settings UI | — |
| **M5** | 0.5.0 | Package photography | Capture slots, protected store + streamer, SHA-256 audit fingerprints, photo-required workflow guard, retention purge job | media |
| **M6** | 0.6.0 | Barcode & scan mode | Scan sink → pick/pack by SKU/EAN scan; scannable queue (slip barcode opens workspace); mismatch handling; kbd/scan-first workspace mode | — |
| **M7** | 0.7.0 | Batch picking | BatchBuilder engine; batch creation from queue; batch picking list document; batch → per-order packing handoff | batches, batch_items |
| **M8** | 0.8.0 | Analytics I | Daily rollups (Action Scheduler + backfill CLI); Analytics screen (throughput, durations p50/p90, carrier mix, exception rates); dashboard trends; operator stats behind D17 | stats_daily |
| **M9** | 0.9.0 → RC | Hardening & operational maturity | i18n complete, Site Health tests, `wp mpcf doctor`/`audit verify`, privacy exporter/eraser, performance baselines at 50k fulfillments, security review doc, `ARCHITECTURE_FREEZE.md`, compatibility matrix | — |
| **1.0** | 1.0.0 | Commercial release | Freeze public surface (hooks, REST v1, schema semantics, template contract) | — |
| M10 | 1.1.0 | Returns & RMA | Return aggregate + workflow, return slip doc, customer-initiated intake hook, refund handoff to WC | returns, return_items |
| M11 | 1.2.0 | Multi-warehouse & locations | Location hierarchy (facility/warehouse/zone/shelf/bin as data, §7.1), item-location assignment, location-sorted picking, warehouse routing rules, per-warehouse queues | locations, item_locations |
| M12 | 1.3.0 | Carrier integrations I | `CarrierPort` label purchase + tracking sync (first adapters chosen by PO — candidates: Sendcloud, nShift, EasyPost as an aggregator strategy); label documents; CN22/CN23 + commercial invoice (PDF renderer lands here) | carrier_accounts |
| M13 | 1.4.0 | Automation & webhooks | Outgoing HMAC webhooks, automation rules (event→condition→action), scoped API keys | webhooks, api_keys, rules |
| M14 | 1.5.0 | Warehouse mobile mode | PWA-style tablet frontend over `mpcf/v1` (scan-first), station login via API keys | — |

Sequencing notes: M3 before M4 because a printable slip is the single most-requested day-one artifact; M6 before M7 because batch picking without scanning is paper anyway; photography (M5) early because it is a headline differentiator and its guard integrates with the workflow engine; returns deliberately post-1.0 — it doubles the domain surface and deserves the stability of a frozen core.

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

Admin workflow builder UI (definitions are already data); customer-facing tracking portal page + branded tracking emails; inbound logistics/purchase orders; address validation/correction pre-ship; rate shopping at pack time (choose cheapest carrier for measured weight/dims); packing-material optimization (box suggestion from item dims); SLA rules & alerting (age thresholds → notifications) on the automation engine; multi-source orders (the `order_source` column + `OrderSource` port admit non-WooCommerce feeds); photo annotations; voice picking; hardware station integrations (scales via WebHID/WebSerial — reads feed the same `PackageSpec` REST field); marketplace of carrier adapters as separate paid add-on plugins hooking `mpcf_carriers`/`CarrierPort`; additional notification channels (SMS/push/Slack/Teams) as `NotificationChannel` implementations; audit investigation mode and Audit Explorer (§13); cross-plugin MP Commerce integrations (Inventory feeding backorder detection, Shipping providing negotiated rates at pack time) strictly through the public surfaces of §2.2.

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
| CI expectations | ✅ | §19.2 shape: phpcs · pot · unit (8.1/8.3/8.4) · integration legs floor (PHP 8.1/WP 6.5/WC 8.2.x) + current (pinned current stable, why-comment) + ceiling (`continue-on-error`) · build · release-audit. `CiMatrixGuardTest` + `CompatibilityMatrixTest` bind the matrix to `docs/COMPATIBILITY.md`. |
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

