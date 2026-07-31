# Roadmap

Full milestone table and scope: `docs/ARCHITECTURE_PLAN.md` §20. One
approved milestone at a time (I14) — each milestone gets its own execution
plan appended to `ARCHITECTURE_PLAN.md`, reconciled against this document's
actual state before work starts.

0. **M0 — Bootstrap & MPDS extraction** — **code-complete on `main`,
   awaiting the `v0.0.1` tag (house rule: tags are pushed only on explicit
   PO approval).** Plugin bootstrap, composition root, settings framework,
   `PersistedKeys`, capability framework, migration framework (framework
   only — no business tables), structural guard framework, MPDS vendoring
   proof, canonical documentation. Unit suite (44 tests) and integration
   suite (12 tests, HPOS forced on, real WordPress/WooCommerce/MariaDB)
   both green; phpcs clean. `mp-admin-design-system` extracted to its own
   repo and tagged `v0.1.0`. The plugin activates, installs its migration
   framework, declares HPOS compatibility, and does nothing else.
1. **M1 — Fulfillment core (Warehouse MVP)** — not started. Requires fresh
   PO approval before work begins (house rule).
2. **M2 — Packing Workspace & REST** — not started.
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
