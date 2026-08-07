# v1.0 — Architecture Freeze & Production Readiness Program

**Status:** Planning complete — **implementation not started**.  
**Kind:** Certification / evidence program — **not M11**, **not a feature milestone**.  
**Baseline:** `main` / released **`v0.10.0`** (M0–M10 closed). Production **not** yet deployed.  
**Freeze inventory:** `docs/ARCHITECTURE_FREEZE.md` (**DRAFT**, structure finalized for approval).  
**Target administrative release:** **`v1.0.0`** (no new functionality).

This plan designs the final certification program before MPCF is declared production-ready and the architecture freeze becomes **ACTIVE**.

---

## 1. Purpose

Prove, with recorded evidence:

| Proof | Meaning |
|---|---|
| Architectural stability | Public contracts classified; freeze inventory approval-ready |
| Operational stability | Doctor, schedules, storage, caps, Site Health behave under load and failure |
| Upgrade safety | Representative `0.x` → `1.0.0` (and `0.10.0` → candidate) paths retain data |
| Rollback safety | ZIP rollback to prior release does not fatal or corrupt audit chains |
| Security | Capability, REST, storage, privacy, CLI mutation posture reviewed |
| Maintainability | Ops docs, support procedures, monitoring runbooks usable by operators |
| Production readiness | Publish immutable `v1.0.0` ZIP, deploy that artifact, close hypercare |

**Method:** evidence gathering and verification only. Prefer disposable environments and published release ZIPs over bind-mounts for formal gates.

---

## 2. Non-goals (explicit)

| Forbidden | Reason |
|---|---|
| Feature work / new warehouse workflows | Not a feature milestone |
| Mission Control redesign | Deferred post-1.0 |
| Analytics redesign / BI | Out of scope |
| Inventory / purchasing / receiving | ADR-0007 |
| New public APIs (REST routes, hooks) except freeze-doc clarification | Freeze first |
| Schema / migrator bumps unless PO-approved catch-up ADR | Prefer TARGET 8 |
| Runtime PHP / JS / CSS / REST / migration implementation in planning | Docs only until GO |
| Calling this program “M11” | Reserved naming; this is **v1.0 certification** |

---

## 3. Preconditions (entry)

Already verified at planning start (`2026-08-07`):

- [x] `main` == `origin/main`, working tree clean
- [x] `v0.10.0` published; M10 closed
- [x] `docs/ARCHITECTURE_FREEZE.md` exists
- [x] No open PRs implementing new functionality
- [x] CI green on `main`

Re-verify these at the start of **each** phase.

---

## 4. Program phases

| Phase | Name | Outcome |
|---|---|---|
| **P1** | Architecture Freeze | Freeze inventory approved for activation; all public contracts classified |
| **P2** | Regression Certification | M0–M10 behavioral verification matrix green (no features) |
| **P3** | Operational Certification | Perf / security / a11y / privacy / DR / monitoring evidence recorded |
| **P4** | v1.0 Release Candidate Approval & Release | Tag **`v1.0.0`**; freeze → **ACTIVE**; immutable GitHub Release ZIP |
| **P5** | Production Deployment & Hypercare | Deploy **that exact** published artifact; monitor; close hypercare |

**Binding order: P1 → P2 → P3 → P4 → P5.**

Lifecycle (mature release process):

1. Certify the code (P1–P3).
2. Tag **`v1.0.0`** and publish the release artifact (P4).
3. Deploy that exact artifact to production (P5).
4. Monitor production (hypercare) and declare production complete (P5 exit).

Do **not** tag after production has already been running an untagged or differently built tree. The GitHub Release ZIP is the immutable deployable.

---

## 5. Phase P1 — Architecture Freeze

### 5.1 Goal

Finalize and PO-approve `docs/ARCHITECTURE_FREEZE.md` so every public contract is **FROZEN**, **MAY EVOLVE ADDITIVELY**, or **INTERNAL**.

### 5.2 Work (documentation / review only)

1. Walk `docs/API.md`, `docs/HOOKS.md`, `docs/PERSISTED_DATA.md`, CLI help, capabilities, settings, pipelines.
2. Resolve any ambiguous surfaces (document decisions in freeze inventory).
3. Confirm versioning, backward-compatibility, and deprecation policies (already structured in freeze doc).
4. Confirm ADR-0007 boundary and extension policy.
5. Produce a **P1 Freeze Approval** checklist sign-off (PO + tech lead).

### 5.3 Classification checklist (must be complete)

| Surface | FROZEN / INTERNAL / ADDITIVE | Evidence doc |
|---|---|---|
| REST `mpcf/v1` | | `API.md` |
| CLI | | Freeze + ops |
| Hooks / filters | | `HOOKS.md` |
| Domain events | | `PERSISTED_DATA.md` / events |
| Database / migrator | | `PERSISTED_DATA.md` |
| Capabilities | | Capabilities registry |
| Settings | | Settings keys |
| Document pipeline | | Documents docs / API |
| Notification pipeline | | M5 report / settings |
| Photo pipeline | | M6 / ADR-0004 |
| Wave pipeline | | M8 |
| Analytics | | M9 |
| Extension points | | Freeze § Extension |
| Versioning / BC / deprecation | | Freeze policy sections |

### 5.4 Exit criteria

- Freeze inventory has **no unclassified public surfaces**.
- PO written approval: “Freeze structure approved; remains DRAFT until `v1.0.0`.”
- No runtime code changes required for P1 exit (docs-only).

### 5.5 Artifacts

- Updated `docs/ARCHITECTURE_FREEZE.md` (still DRAFT)
- Short `docs/plans/` or release-notes appendix: P1 sign-off date + approvers

---

## 6. Phase P2 — Regression Certification

### 6.1 Goal

Re-verify **M0–M10** shipped behavior on **`v0.10.0`** (and the eventual `v1.0.0` candidate ZIP — identical code expected) without adding features.

### 6.2 Matrix (verification only)

| Milestone | Focus | Primary evidence |
|---|---|---|
| M0 | Activate, migrator framework, HPOS declare, uninstall policy | Clean install |
| M1 | Intake, workflow, queue/detail, audit chain, caps | Integration + smoke |
| M2 | REST, packing workspace, shipment/package | API + browser smoke |
| M3 | Workspace guidance, Orders overview | Browser / dogfood |
| M4 | Documents render/reprint/bulk | Documents flows |
| M5 | Tracking + notifications | Notify + events |
| M6 | Photos upload/list/delete/retention schedule | Photos + AS |
| M7 | Scan mode + barcodes | Scan REST / workspace |
| M8 | Waves create/walk/scan to picked | Wave flows |
| M9 | Analytics LIVE/ROLLUP/CLI/scheduler | Analytics + CLI |
| M10 | Doctor / validate / repair / privacy / Site Health | Ops CLI |

### 6.3 Formal gates (minimum)

1. **Full CI matrix green** on the certification commit / tag candidate.
2. **Clean-install** of published ZIP (not bind-mount).
3. **Upgrade** from representative `v0.9.0` and/or `v0.10.0` seed data → candidate.
4. **Rollback** ZIP to prior release; no fatals; data retained; document expected M10-surface absences if rolling before 1.0.
5. **Audit chain** verify on seeded fulfillments.
6. **Read-only invariant:** doctor / validate / Site Health do not mutate business rows.

### 6.4 Exit criteria

- Regression matrix recorded with pass/fail + environment versions (WP/PHP/WC).
- Zero release-blocking defects; any waivers explicitly PO-approved.
- Confirmation: **no feature commits** landed during P2.

### 6.5 Artifacts

- `docs/V1_REGRESSION_CERT.md` (create during execution) or section in eventual `docs/V1_RELEASE_REPORT.md`

---

## 7. Phase P3 — Operational Certification

### 7.1 Goal

Prove the plugin is operable, securable, and recoverable at production-relevant scale.

### 7.2 Workstreams

| Stream | Plan | Evidence target |
|---|---|---|
| **Performance** | Execute ~50k methodology in `docs/ops/PERFORMANCE_BASELINE.md`; record p50/p95 | Updated baseline doc with real timings |
| **Security** | Extend `docs/SECURITY_REVIEW.md` for freeze surfaces; confirm caps/REST/storage/privacy/CLI `--yes` posture | Security review addendum |
| **Accessibility** | Operator-critical screens smoke (Queue, Workspace, Wave, Analytics, Settings) against WCAG-oriented checklist | A11y notes (no redesign) |
| **Privacy** | Export + erase + `audit verify`; WC sympathy path | Ops privacy checklist signed |
| **Disaster recovery** | Follow `docs/ops/DISASTER_RECOVERY.md` dry-run | DR drill log |
| **Backup / restore** | DB + `uploads/mpcf` backup/restore rehearsal | Restore success log |
| **Monitoring** | `docs/ops/MONITORING.md` — doctor cron, AS health, Site Health | Monitoring runbook exercised |
| **Long-running stability** | Multi-day dogfood or soak (AS retention + analytics rollup) | Soak notes |
| **Large dataset** | Perf stream + orphan/consistency validate at scale | Validate output |
| **Ops documentation** | Walk deploy/upgrade/rollback/doctor docs for gaps | Doc errata list (docs-only fixes OK) |
| **Support procedures** | Define L1/L2: doctor first, validate, bounded repair, escalate | Support one-pager in ops or freeze appendix |

### 7.3 Exit criteria

- Each stream has recorded evidence or an explicit PO deferral with risk acceptance.
- No open critical security findings.
- Support one-pager published under `docs/ops/`.

### 7.4 Artifacts

- Updated performance baseline, security addendum, DR/backup logs, support one-pager

---

## 8. Phase P4 — v1.0 Release Candidate Approval & Release

### 8.1 Goal

PO-approve the certified codebase as the **v1.0.0 release candidate**, publish the **immutable** GitHub Release artifact, and activate the architecture freeze. **No new functionality.**

### 8.2 Preconditions

- P1–P3 exit criteria met and PO-signed.
- Working tree / RC commit identified; full CI green.
- Staging (or disposable) verification already used the same commit that will be tagged.

### 8.3 Work (administrative release only)

1. Confirm version triad → `1.0.0` (header / `MPCF_VERSION` / Stable tag) — **version bump only**.
2. Set `ARCHITECTURE_FREEZE.md` status **DRAFT → ACTIVE** (same release commit).
3. Full CI green; local build ZIP; release-audit.
4. Merge if needed; create annotated tag **`v1.0.0`** on the release commit.
5. Push tag; wait for GitHub Release workflow.
6. Download published `mp-commerce-fulfillment-1.0.0.zip`; verify version parity, freeze-relevant surfaces, no prohibited artifacts; record local vs published SHA-256.
7. Archive `docs/V1_RELEASE_REPORT.md`; update `docs/ROADMAP.md` (release published; production deploy = P5).

### 8.4 Explicitly forbidden in P4

- Features, opportunistic refactors, new REST routes, schema bumps, UI redesigns.
- Deploying to production before the published ZIP exists (that is **P5**).

### 8.5 Exit criteria

- GitHub Release **`v1.0.0`** live with installable ZIP
- Freeze document **ACTIVE**
- Published asset SHA recorded; artifact is the **only** allowed production install source for P5
- Roadmap notes release published; hypercare/production still open until P5 closes

---

## 9. Phase P5 — Production Deployment & Hypercare

### 9.1 Goal

Deploy the **exact** published `v1.0.0` ZIP to production, verify operations, run hypercare, and declare production fully operational.

### 9.2 Deployment checklist (summary)

Use and extend `docs/ops/PRODUCTION_DEPLOY.md` + `UPGRADE.md`. Source of truth: GitHub Release asset for `v1.0.0` (not a bind-mount, not an untagged build).

1. Pre-change backup (DB + `uploads/mpcf`).
2. Confirm HPOS, PHP/WP/WC floors, Action Scheduler.
3. Install/upgrade **published** `mp-commerce-fulfillment-1.0.0.zip`; confirm `mpcf_db_version` and tables.
4. `wp mpcf doctor` → exit 0 (warn-only OK if PO-accepted).
5. Site Health `mpcf_operational` not critical.
6. Smoke: intake → pick/pack/ship; document; notification as applicable.
7. Confirm AS schedules present.
8. Record deployed ZIP SHA-256 vs published release SHA.

### 9.3 Immediate verification checklist

| Check | Pass condition |
|---|---|
| Schema / migration | TARGET expected; all `mpcf_*` tables |
| Doctor | No release-blocking fails |
| Site Health | Not critical for MPCF |
| Queue | Lists open work |
| Audit | `wp mpcf audit verify` sample OK |
| Storage | photos/documents writable; deny stub present |
| Privacy tools | Exporter/eraser registered |

### 9.4 Rollback criteria (abort)

Trigger ZIP rollback (`docs/ops/ROLLBACK.md`) if:

- Activation/upgrade fatals
- Doctor critical fails that cannot be repaired safely within the window
- Audit chain breakage attributable to the deploy
- Material order/fulfillment processing stoppage caused by MPCF

**Do not** schema-downgrade. Restore from backup only if data corruption is confirmed.

### 9.5 Hypercare checklist

Observation window: **24–72 hours** (PO sets exact duration before cutover).

| # | Item | Done |
|---|---|---|
| 1 | Production deployment completed (published `v1.0.0` ZIP) | ☐ |
| 2 | Database migration verified (`mpcf_db_version` + tables) | ☐ |
| 3 | Site Health green / not critical for MPCF | ☐ |
| 4 | `wp mpcf doctor` clean (pass or accepted warn-only) | ☐ |
| 5 | Action Scheduler healthy (no stuck `mpcf` failures) | ☐ |
| 6 | Fulfillment queue operational | ☐ |
| 7 | Document generation verified | ☐ |
| 8 | Notifications verified | ☐ |
| 9 | Package photography verified | ☐ |
| 10 | Scan Mode verified | ☐ |
| 11 | Wave picking verified | ☐ |
| 12 | Analytics verified | ☐ |
| 13 | No unexpected PHP errors attributable to MPCF | ☐ |
| 14 | No unexpected audit failures (`audit verify` samples) | ☐ |
| 15 | Monitor for agreed 24–72 hour window | ☐ |
| 16 | Close hypercare (PO sign-off) | ☐ |
| 17 | Mark production as fully operational | ☐ |

Also continue: doctor on schedule (`MONITORING.md`), AS failed-action watch, capacity signals (intake rate / exception depth).

### 9.6 Success criteria

- Hypercare checklist complete with PO signature.
- No data-loss incidents; audit chains intact on sampled fulfillments.
- Production declared **fully operational**.

### 9.7 Exit criteria

- Deployed artifact SHA matches published `v1.0.0` release.
- Hypercare closed; program success definition met (see §12).
- Optional: staging rollback drill completed before prod (recommended mandatory).

---

## 10. Governance

| Role | Responsibility |
|---|---|
| Product Owner | Phase GO/NO-GO; freeze approval; production acceptance; waiver authority |
| Tech lead | Evidence quality; classification correctness; release mechanics |
| Operators | Dogfood, soak, support procedure validation |

**One approved phase at a time.** No parallel feature milestones during this program.

---

## 11. Relationship to roadmap numbering

Historical roadmap item “**11. 1.0 — Commercial release**” is fulfilled by **this program (P1–P5)**, not by an “M11 features” milestone. Do not create M11 for freeze/certification work.

Post-1.0 capabilities remain in Architecture Plan §20 / roadmap “Future capabilities” only.

---

## 12. Success definition (program complete)

MPCF is **production-ready and program-complete** when:

1. P1–P3 certification evidence is recorded and PO-approved.
2. **`v1.0.0` is tagged and published** (P4); freeze inventory is **ACTIVE**.
3. Production runs that **exact** published ZIP (P5); hypercare checklist closed.
4. No feature delta beyond version/docs freeze activation landed for `v1.0.0`.

---

## 13. Planning checkpoint

| Item | Value |
|---|---|
| Planning completed | 2026-08-07 |
| Runtime implementation | **Not started** |
| Next action | PO review of this plan + freeze inventory structure; then **P1 Freeze Approval** GO |
