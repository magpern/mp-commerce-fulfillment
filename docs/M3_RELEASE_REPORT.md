# Milestone 3 Release Report

**Released:** 2026-08-04  
**Version:** 0.3.0  
**Status:** ✅ Production Ready

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
scenario without stopping because the next action is unclear — **met**; PO
approved GO 2026-08-04.

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
| M3 polish (fixed in v0.3.0) | 3 | DL-003, DL-004, DL-005 |
| Future enhancement | 5 | DL-001, DL-002, DL-006, DL-007, DL-008 |
| Out of scope | 0 | — |

See `DOGFOOD_LESSONS.md` for observation / decision / milestone target.

---

## Issues fixed in this release

- Empty customer snapshot fallback (`No customer name`)
- Orders Filter submit button (parity with Queue)
- Packing stage copy + `package_spec_present` operator guard (PHP + JS)
- Structural guard allowlists / I6–I8 prose for Orders surface; Mpds MANIFEST hash
- `bin/release-audit.sh` pipefail false-negative on early zip entries

---

## Issues deferred

Mission Control Dashboard/Queue redesign (A/B/C), returning-customer badge,
WP submenu shell cleanup, Documents I → **M4**.

---

## Release artifacts

| Property | Value |
|---|---|
| **Tag** | `v0.3.0` |
| **Commit** | _(set after tag)_ |
| **Version parity** | header / `MPCF_VERSION` / Stable tag = `0.3.0` |
| **Installable ZIP** | `mp-commerce-fulfillment-0.3.0.zip` |
| **ZIP size** | 329426 bytes |
| **ZIP entries** | 206 files |
| **Local Build SHA-256** | `8400c1631277b36df0c819a98a22bc26189a9ad200c6017d5c6025adb60445ba` |
| **Published ZIP SHA-256** | _(set after GitHub Release)_ |
| **GitHub Release** | _(set after publish)_ |

**Note:** GitHub may re-archive the ZIP with different timestamps, producing a different published SHA-256. File-level content verification confirms correctness.

---

## Validation evidence (engineering)

- Full unit suite green (364 tests)
- Focused integration (Orders/Workspace/Menu): 39 OK
- Focused PHPCS clean on M3-F Admin files
- `bin/make-pot.sh` regenerated for new strings
- `bin/release-audit.sh` **passed** (version parity, docs, zero runtime deps, zip contents, no Node/dev artifacts)
- Clean-install extract: parity 0.3.0, `php -l` clean, autoload OK, no forbidden paths

---

## Release decision

**GO.** PO accepted 2026-08-04. Release audit passed with zero blockers.
