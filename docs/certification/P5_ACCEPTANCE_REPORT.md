# P5 — Pre-Production Acceptance Report

**Program:** v1.0 Production Readiness  
**Phase:** P5 — Pre-Production Acceptance Testing  
**Architecture Freeze:** **ACTIVE**  
**Tested release:** `v1.0.0`  
**Artifact:** `mp-commerce-fulfillment-1.0.0.zip`  
**Published SHA-256:** `b236970280467149f2bd9ea16692afab46eeb25f5d5bdbc2186a54b95c41b9e4`  
**Acceptance environment:** https://dev.biopentra.eu  
**Production:** https://www.biopentra.eu — **NOT touched**  
**Date:** 2026-08-07  
**Verdict:** **PASS WITH MINOR DEFECTS**

---

## Preconditions

| Check | Result |
|---|---|
| GitHub Release `v1.0.0` exists | Pass |
| Downloaded ZIP SHA-256 matches published | Pass |
| Architecture Freeze ACTIVE | Pass |
| `dev.biopentra.eu` reachable | Pass |
| Acceptance uses released ZIP (not git checkout / not repo bind-mount) | Pass — mounted from `/opt/biopentra/dev/mpcf-acceptance/mp-commerce-fulfillment` (ZIP extract; no `.git`, no `tests/`) |
| Production deployment | Not performed |

Pre-acceptance backup: `/tmp/mpcf-p5-accept/backups/` (`db-*.sql.gz`, `uploads-mpcf-*.tgz`, doctor JSON).

---

## P5-A — Install acceptance build

| Check | Result |
|---|---|
| Plugin version | `1.0.0` active |
| `mpcf_db_version` | `8` |
| Doctor | Exit 0 — **45 pass / 1 warn / 0 fail** (`configuration.settings_option`) |
| Validate schema/storage/schedules/consistency/fulfillments/waves/analytics | All Success |
| Audit verify `--all --limit=50` | ok (sampled chains OK) |
| Privacy exporter/eraser hooks | Registered |
| Site Health `mpcf_operational` | Registered; status **recommended** (settings warn) |
| Analytics / waves / scan / photos / notifications / documents REST | Exercised in warehouse harness |

---

## P5-B — Warehouse acceptance (E2E)

Harness: `/tmp/mpcf-p5-accept/p5-warehouse-acceptance.php` via WP-CLI as administrator against live REST + intake.

| Workflow | Result |
|---|---|
| Intake (WC order → processing → fulfillment) | Pass |
| Queue list | Pass |
| Workspace detail | Pass |
| Picking / pick qty / picked | Pass |
| Packing / pack qty | Pass |
| Shipment + package dims + tracking | Pass |
| Packed → ship | Pass |
| Document render (packing slip) | Pass |
| Scan Mode (bad SKU rejected; good SKU pick) | Pass |
| Wave list + create | Pass |
| Analytics overview | Pass |
| Notification status | Pass |
| Photos list | Pass |
| No inventory mutation | Pass |

Final harness: **31 checks, 0 fail** (`warehouse-run3.log`).

---

## P5-C — Negative testing

| Case | Result |
|---|---|
| Invalid transition (e.g. back to `queued`) | Rejected (HTTP 409) |
| Stale version | Rejected (HTTP 409) |
| Unauthenticated queue | Rejected (HTTP 403) |
| Bad scan payload | Rejected (HTTP 422) |
| Package-spec guard before dims confirmed | Enforced (422) — correct product behavior |
| Repair schedules/storage-dirs without `--yes` | Safe / already present; Success |
| Rollback package | `v0.10.0` available on GitHub Releases (not executed — acceptance passed) |

---

## P5-D — Usability review (no implementation)

| Observation | Class |
|---|---|
| Operator happy-path (intake→ship→document) is coherent once package dims are set via `PATCH /packages/{id}` after create | — |
| Creating a package via `POST .../packages` does **not** accept dimensions in the create body; requires a follow-up PATCH — discoverable but easy to miss in API-only clients | **ENHANCEMENT** |
| Doctor/settings warn until settings saved once — operators should save Settings once after first install | Documented (P2/P3) |
| Site Health “recommended” mirrors the settings warn — not critical | Expected |

Enhancement backlog (do **not** implement in acceptance):

1. Allow optional dimensions on package create REST body (or clearer 201 response coaching).
2. One-click “save defaults” from doctor warn for `configuration.settings_option`.

---

## P5-E — Defect triage

| ID | Severity | Summary | Blocks production approval? |
|---|---|---|---|
| P5-M1 | **MINOR** | Doctor/Site Health warn: settings option never saved (defaults apply) | No |
| P5-E1 | **ENHANCEMENT** | Package create REST requires separate PATCH for dims | No |

**BLOCKER:** none  
**MAJOR:** none

---

## Verdict

**PASS WITH MINOR DEFECTS**

Released artifact `v1.0.0` (SHA above) is accepted for pre-production on `dev.biopentra.eu`. Production deploy remains a separate **P6** phase requiring explicit Product Owner GO.

---

## Environment note (acceptance mount)

For this acceptance run, `apps/wordpress/compose.yml` temporarily mounts the ZIP extract instead of the git checkout:

`/opt/biopentra/dev/mpcf-acceptance/mp-commerce-fulfillment`

Restore development bind-mount after P6 planning if desired:

`/opt/biopentra/dev/mp-commerce-fulfillment`

---

## Confirmation

- Production (`www.biopentra.eu`) untouched  
- Architecture Freeze remains **ACTIVE**  
- P6 not started  
- No feature/runtime changes for acceptance defects (none blocking)
