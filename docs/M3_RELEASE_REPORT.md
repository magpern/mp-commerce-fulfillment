# Milestone 3 Release Report

**Version target:** 0.3.0  
**Status:** Release candidate — awaiting PO acceptance before tag  
**Date:** 2026-08-04

Living operational detail: [`docs/DOGFOOD_LESSONS.md`](DOGFOOD_LESSONS.md).  
This report summarizes outcomes only; it does not duplicate lesson entries.

---

## Executive summary

M3 for `v0.3.0` is **Ops UX + stabilization**, not Documents I:

- **M3-D** Workspace next-action / stage guidance / quantity disclosure / shipped success
- **M3-E** Orders read-only overview
- **M3-F** Iterative dogfood, approved polish, docs reconciliation

**Mission Control (M3-A/B/C) is deferred** and is not claimed as shipped.

Primary release decision criterion: PO can complete every required warehouse
scenario without stopping because the next action is unclear.

---

## Scenarios executed

Pending payment · On Hold · Processing · Queue · Picking · Packing · Packed ·
Shipped · Cancelled · Customer order note · Returning customer · Multiple line
items · Multiple quantities · Orders screen · Dashboard (usable; Mission Control
deferred) · Workspace · Search (Orders `s=`) · Exception (Problem)

Two dogfood rounds completed. Round 2 re-verified approved polish fixes.

---

## Issues found (summary)

| Class | Count | Notes |
|---|---|---|
| Release blocker | 0 | None remaining after Round 2 |
| M3 polish (fixed in RC) | 3 | DL-003, DL-004, DL-005 |
| Future enhancement | 5 | DL-001, DL-002, DL-006, DL-007, DL-008 |
| Out of scope | 0 | — |

See `DOGFOOD_LESSONS.md` for observation / decision / milestone target.

---

## Issues fixed in this RC

- Empty customer snapshot fallback (`No customer name`)
- Orders Filter submit button (parity with Queue)
- Packing stage copy + `package_spec_present` operator guard (PHP + JS)

---

## Issues deferred

Mission Control Dashboard/Queue redesign (A/B/C), returning-customer badge,
WP submenu shell cleanup, Documents I → **M4**.

---

## Validation evidence (engineering)

- Full unit suite green (364 tests) — including CompositionRoot /
  DomainPurity / WooConfinement / MpdsVendorGuard fixes for Orders surface
- Focused PHPCS clean on M3-F touched Admin files
- Manual browser dogfood Round 1 + Round 2 on https://dev.biopentra.eu
- Version remains **0.2.2** until F6 bump after PO GO
- `bin/release-audit.sh` reserved for F6 after version bump

---

## Release decision

**Recommended:** GO for `v0.3.0` pending explicit PO acceptance (F5).

**Do not tag or push** until PO confirms the operational success metric.
