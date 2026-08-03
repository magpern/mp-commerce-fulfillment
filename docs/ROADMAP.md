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
2. **M2 — Packing Workspace & REST** — **shipped as `v0.2.0`** (PO approved
   Part IV 2026-08-02, implementation complete and merged 2026-08-03; MPDS
   `v0.3.0` shipped alongside). A prerequisite defect-patch release,
   `v0.1.1`, was shipped 2026-08-02 — see below. REST namespace `mpcf/v1`,
   the Packing Workspace, `Shipment`/`Package`, a minimal packing slip
   pulled forward from M3, timeline pagination, and MPDS `v0.3.0` (toast,
   stepper, workspace-layout, action-bar, checklist, quantity-stepper,
   unit-input, repeater, scan-input). Performance re-proof at 10k rows with
   M2's 14-event distribution complete (F23). Full evidence in
   `docs/M2_RELEASE_REPORT.md` (pending, scheduled with F25 acceptance).
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
3. **M3 — Documents I** — not started.
4. **M4 — Tracking & notifications** — not started.
5. **M5 — Package photography** — not started.
6. **M6 — Barcode & scan mode** — not started.
7. **M7 — Batch picking** — not started.
8. **M8 — Analytics I** — not started.
9. **M9 — Hardening & operational maturity** — not started.
10. **1.0 — Commercial release** — not started.

Milestones beyond 1.0 (Returns & RMA, Multi-warehouse & locations, Carrier
integrations, Automation & webhooks, Warehouse mobile mode) are listed in
`docs/ARCHITECTURE_PLAN.md` §20 and are not yet scheduled.
