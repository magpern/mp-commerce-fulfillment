# Roadmap

Full milestone table and scope: `docs/ARCHITECTURE_PLAN.md` §20. One
approved milestone at a time (I14) — each milestone gets its own execution
plan appended to `ARCHITECTURE_PLAN.md`, reconciled against this document's
actual state before work starts.

0. **M0 — Bootstrap & MPDS extraction** — **released as `v0.0.1`** (tag
   pushed, Release workflow green, installable zip published). Plugin
   bootstrap, composition root, settings framework, `PersistedKeys`,
   capability framework, migration framework (framework only — no business
   tables), structural guard framework, MPDS vendoring proof, canonical
   documentation. Unit suite (44 tests) and integration suite (12 tests,
   HPOS forced on, real WordPress/WooCommerce/MariaDB) both green; phpcs
   clean. `mp-admin-design-system` extracted to its own repo and released
   as `v0.1.0`. The plugin activates, installs its migration framework,
   declares HPOS compatibility, and does nothing else.
1. **M1 — Fulfillment core (Warehouse MVP)** — **released as `v0.1.0`**
   (PO accepted the milestone and its release-candidate verification
   2026-08-02; tag pushed, Release workflow green, installable zip
   published). Execution plan is Part III of `ARCHITECTURE_PLAN.md`,
   actual outcomes recorded in §III.7, full evidence in
   `docs/M1_RELEASE_REPORT.md` (archived). Intake, workflow engine +
   standard workflow, Queue/Fulfillment Detail/Dashboard screens, audit
   hash chain, roles/capabilities + Operator Mode, status bridge, uninstall
   policy, and the 10k-row Queue performance proof (no full scan, no N+1,
   no migration amendment needed — see `docs/QUEUE_PERFORMANCE_VALIDATION.md`).
   `mp-admin-design-system` gained the six Milestone 1 components and was
   released as `v0.2.0`. Milestone formally closed 2026-08-02.
2. **M2 — Packing Workspace & REST** — **released as `v0.2.0`** (tag pushed
   2026-08-03, Release workflow succeeded, installable zip published). PO
   approved Part IV 2026-08-02, implementation complete and merged
   2026-08-03, acceptance verification passed 2026-08-03. MPDS `v0.3.0`
   released alongside. A prerequisite defect-patch release, `v0.1.1`, was
   shipped 2026-08-02 — see below. REST namespace `mpcf/v1`, the Packing
   Workspace, `Shipment`/`Package`, a minimal packing slip pulled forward
   from M3, timeline pagination, and MPDS `v0.3.0` (toast, stepper,
   workspace-layout, action-bar, checklist, quantity-stepper, unit-input,
   repeater, scan-input). Performance re-proof at 10k rows with M2's
   14-event distribution complete (F23). Full evidence in
   `docs/M2_RELEASE_REPORT.md`. Milestone formally closed 2026-08-03.
2a. **`v0.1.1` — M1 defect patch (prerequisite for M2)** — **released**
    2026-08-02 (tag pushed, Release workflow green, published artifact
    independently re-verified: 132 entries, zero dev files, zero runtime
    dependency, version parity across header/constant/readme, corrected
    `SOURCE_TAG`). Fixed a real M1 defect found during M2 reconciliation:
    the admin-side composition root wired a subscriber-less
    `EventDispatcher`, so admin-initiated transitions (Queue, Fulfillment
    Detail) never reached `Woo\StatusBridge` — only `RefundObserver`-driven
    transitions did. See `docs/ARCHITECTURE_PLAN.md` §IV.2 and
    `docs/M1_RELEASE_REPORT.md`'s addendum for full evidence.
3. **M3 — Ops UX (Workspace next-action + Orders)** — **released as `v0.3.0`**.
   Ships M3-D Workspace stage guidance / quantity disclosure / shipped
   success path, M3-E Orders read-only overview, and M3-F iterative
   dogfood + operator-feedback polish. Mission Control Dashboard/Queue
   redesign (M3-A/B/C) is deferred post-0.3.0. Permanent operational
   backlog: `docs/DOGFOOD_LESSONS.md`. Evidence: `docs/M3_RELEASE_REPORT.md`.
4. **M4 — Documents I** — **released as `v0.4.0`**. Typed packing slip +
   picking list; branding; protected HTML storage; Workspace document
   actions; Documents history + exact reprint; capped Queue bulk
   picking-list print. Evidence: `docs/M4_RELEASE_REPORT.md`.
5. **M5 — Tracking & notifications** — **release candidate `v0.5.0`** on
   `feature/m5-tracking-notifications` (not tagged/published pending PO
   approval). Carrier registry (M5-A), notification configuration (M5-B),
   notification pipeline + EmailChannel (M5-C), completed-order tracking
   extension + Workspace/REST notify (M5-D), dogfood + RC prep (M5-E).
   Evidence: `docs/M5_RELEASE_REPORT.md`.
6. **M6 — Package photography** — **release candidate `v0.6.0`** on
   `feature/m6-package-photography` (not tagged/published pending PO
   approval). M6-A–D: foundation, REST/guard, Workspace/settings, retention
   purge + CS Detail gallery. Evidence: `docs/M6_RELEASE_REPORT.md`,
   `docs/ARCHITECTURE_PLAN.md` Part VIII.10–VIII.13.
7. **M7 — Barcode & scan mode** — not started.
8. **M8 — Batch picking** — not started.
9. **M9 — Analytics I** — not started.
10. **M10 — Hardening & operational maturity** — not started.
11. **1.0 — Commercial release** — not started.

## Future capabilities (not scheduled)

Documented for architectural guidance only. Not in the active milestone
sequence above. Not required for current Biopentra operations.

### Partial fulfillment & split shipments

An operator may eventually need to ship available quantity while leaving
the remainder open on the same fulfillment, then create additional
shipments later. M2 intentionally requires all ordered quantity to be
picked and packed before shipment; this capability is a future evolution
of the existing shipment model, not a redesign. Full concept:
`docs/ARCHITECTURE_PLAN.md` §24.1.

Milestones beyond 1.0 (Returns & RMA, Multi-warehouse queues & location-sorted picking, Carrier
integrations, Automation & webhooks, Warehouse mobile mode) are listed in
`docs/ARCHITECTURE_PLAN.md` §20 and are not yet scheduled.

## Domain ownership

Inbound inventory (suppliers, purchase orders, goods receipts, receiving,
inventory movements, stock ledger, inventory position, landed cost, warehouse
location hierarchy, bins) is owned by **`wc-inventory-overview`**, not MPCF.
See ADR-0007 and `docs/ARCHITECTURE_PLAN.md` §2.6. MPCF owns outbound
warehouse execution only; M4 remains **Documents I**.
