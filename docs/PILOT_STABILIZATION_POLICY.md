# Pilot Stabilization Policy

**Status: ACTIVE**  
**Effective:** 2026-08-07  
**Released artifact under Pilot:** `v1.0.0`  
**Architecture Freeze:** remains **ACTIVE** (`docs/ARCHITECTURE_FREEZE.md`)  
**Feature Freeze:** **ACTIVE** (this document)

This is **not** a new milestone and **not** feature work. It governs operational
validation of the published release before production deployment.

---

## Purpose

The purpose of the Pilot is to validate MP Commerce Fulfillment under real
operational usage on:

**https://dev.biopentra.eu**

using the published release artifact.

Development is complete.

The Pilot exists to discover defects before production deployment.

---

## Environment

`dev.biopentra.eu` is now the official **Pilot environment**.

It is no longer considered an active development environment while the Pilot
is running.

Production remains:

**https://www.biopentra.eu**

No production deployment is permitted until the Product Owner explicitly
authorizes it (Phase **P6**).

Pilot installs must use the published GitHub Release ZIP for the version under
test (starting with `v1.0.0`), not a development checkout or untagged build.

---

## Feature Freeze

Effective immediately: **Feature Freeze is ACTIVE**.

The following are prohibited during the Pilot:

- new functionality
- UX redesign
- architecture changes
- refactoring not required by an approved bug fix
- schema changes unless required by a release-blocking defect
- new public APIs
- speculative optimisation

**Architecture Freeze** remains **ACTIVE**.

---

## Finding Classification

Every Pilot finding shall be classified as exactly one of:

| Class | Definition |
|---|---|
| **Critical** | Data loss; security issue; corruption; warehouse cannot operate |
| **Major** | Important workflow broken; incorrect operational behaviour |
| **Minor** | Isolated functional defect; cosmetic issue; documentation issue |
| **Enhancement** | Usability improvement; convenience; future feature; workflow optimisation |

---

## Implementation Policy

Findings are **not** implemented immediately.

Flow:

1. Collect  
2. Triage (classification above)  
3. Product Owner approval  
4. Approved implementation batch  
5. Maintenance release  
6. Continue Pilot  

**Emergency Critical** defects are the only exception to deferred batching.

---

## Maintenance Releases

Pilot fixes are released as:

- `v1.0.1`
- `v1.0.2`
- …

Only approved Pilot fixes may enter the `v1.x` stabilization line.

Enhancements are deferred to **`v1.1`** unless explicitly approved as
production blockers.

---

## Branch Policy

The `v1.x` stabilization line accepts:

- approved bug fixes
- documentation corrections
- operational fixes

No feature development.

Future feature work belongs to **`v1.1`** or later.

---

## Pilot Exit Criteria

The Pilot ends when the Product Owner declares one of:

| Outcome | Meaning |
|---|---|
| **PASS** | Proceed to Production Deployment (P6). |
| **PATCH REQUIRED** | Release a maintenance update and continue the Pilot. |
| **FAIL** | Return to development. |

---

## Governance

Every Pilot fix requires:

- reproduced issue
- documented root cause
- Product Owner approval
- regression verification
- updated release notes

No undocumented changes are permitted.

---

## Related documents

- `docs/ARCHITECTURE_FREEZE.md` — Architecture Freeze inventory (**ACTIVE**)
- `docs/certification/P5_ACCEPTANCE_REPORT.md` — Pre-production acceptance
- `docs/V1_RELEASE_REPORT.md` — `v1.0.0` publication
- `docs/plans/V1_PRODUCTION_READINESS.md` — Program phases including P6
- `docs/ROADMAP.md` — Current phase marker
