# P3 — Operational Certification Report

**Program:** v1.0 Architecture Freeze & Production Readiness  
**Phase:** P3 — Operational Certification  
**Branch:** `certification/p3-operational`  
**Architecture Freeze:** **ACTIVE**  
**Baseline release:** `v0.10.0`  
**Starting HEAD:** `1af6791a2e92a36c4103b71ce1a0a68e9ad107f9`  
**Status:** COMPLETE  
**Verdict:** **PASS WITH DOCUMENTED NON-BLOCKING LIMITATIONS**

## Verdict summary

Operational certification completed with zero production blockers. Realistic ~50k scale measured; backup/restore and DR drills proven on disposable stack; security/privacy/a11y/ops docs/supportability reviewed. Documentation defects corrected. No FROZEN-contract changes. No features. P4 not started. Production not deployed.

## Environment

| Item | Value |
|---|---|
| Host | Biopentra Dev VPS `/opt/biopentra` |
| CPU | 6 vCPU |
| RAM | 11 GiB |
| Storage | ~96G root volume |
| MariaDB (perf) | `11.4.12` (`mpcf-test-db`) |
| Perf runner | `mpcf-test-runner:latest` |
| Disposable ops stack | `compose_mpcf_rc` — WP **7.0.2**, PHP **8.4.23**, WC **10.9.4**, MPCF **0.10.0**, DB TARGET **8** |
| Browser | Playwright 1.62.1 |

## Production acceptance matrix

| Area | Result | Evidence | Limitation | Production blocker? |
|---|---|---|---|---|
| Performance | **PASS** | `phpunit-performance-50k.xml.dist`; `docs/certification/p3-perf-50k-timings.log` | Large customer-prefix listing may EXPLAIN `ALL` on huge `IN` lists; lookup remains indexed | No |
| Soak | **PASS** | `/tmp/mpcf-p3-cert/soak.log` (10 rounds) | Short bounded soak on small disposable dataset | No |
| Backup/restore | **PASS** | `/tmp/mpcf-p3-cert/restore.log` + backup artifacts | — | No |
| DR | **PASS** | storage fail→repair; schedule probe; matrix below | AS unschedule may be re-asserted by bootstrap before doctor observes missing | No |
| Monitoring | **PASS** | `docs/ops/MONITORING.md` corrected + exercised via doctor | Doctor does not assert WC version/HPOS | No |
| Security | **PASS** | Unit 40 / Integration OperationalHardening 11; `docs/SECURITY_REVIEW.md` | WP-CLI trusts shell identity (documented) | No |
| Privacy | **PASS** | privacy hooks yes/yes; eraser/chain tests | — | No |
| Accessibility | **PASS** | axe + keyboard specs (local and/or CI) | Browser harness flakes (P2) | No |
| Operations docs | **PASS** | Walk + corrections | — | No |
| Supportability | **PASS** | `docs/ops/SUPPORT.md` | — | No |
| Production environment | **PASS** | Compat floors met on intended stack | Confirm HPOS + real cron on deploy (P5) | No |
| P2 limitations | **CLOSED / RETAINED** | See § P2 limitation closure | See below | No |

## P3-A — Performance (~50k)

**Dataset:** 50,000 fulfillments; 619,650 events; scale 5× M2 10k distribution.  
**Harness:** `tests/integration/Performance/OperationalScale50kCertificationTest.php`  
**Command:** `vendor/bin/phpunit -c phpunit-performance-50k.xml.dist` via `mpcf-test-runner`  
**Result:** OK (8 tests, 21 assertions)

| Surface | cold | p50 | p95 | EXPLAIN |
|---|---|---|---|---|
| Queue initial load | 7.98ms | 9.04ms | 10.03ms | `created_at` index |
| Queue search Alex (e2e) | 53.07ms | 56.06ms | 59.87ms | listing may `ALL` (large IN); lookup indexed |
| Workspace open (packed) | 2.09ms | 3.32ms | 5.59ms | `fulfillment_id` ref |
| Dashboard open_count | 5.49ms | 6.24ms | 7.49ms | `state_warehouse` range |
| Dashboard packed_today | 27.29ms | 38.63ms | 42.47ms | `created_at` range |
| Doctor full run | 395.61ms | 451.26ms | 468.73ms | acceptable on ref host |
| Validate schema | 3.45ms | 7.20ms | 7.56ms | — |
| Validate consistency | 255.86ms | 279.98ms | 303.33ms | `state_warehouse` ref |
| Queue numeric search | 0.79ms | 1.37ms | 3.01ms | PRIMARY const |

Peak memory during run: ~1.33 GiB.  
Compared to M8–M10 10k proof: no index regression on default queue/workspace shapes; doctor remains sub-second on this host at 50k.

## P3-B — Soak

10 rounds of doctor + validate schedules + row counts on disposable stack (~2 fulfillments). No doctor fail, no schedule loss, counts stable. Duration ~minutes (bounded, honest).

## P3-C — Backup / restore

**Backup:** DB SQL + `uploads/mpcf` tarball + settings/db_version (`tests/bin/p3-certify-ops.sh backup`).  
**Destroy:** TRUNCATE all `wp_mpcf_*`; wipe uploads/mpcf.  
**Restore:** import SQL; extract tarball.  
**Verify:** version 0.10.0; TARGET 8; doctor 45 pass / 1 warn; validate schema Success; audit verify ok=2.

## P3-D — Disaster recovery matrix

| Failure | Detection | Recovery | Data loss | Manual action |
|---|---|---|---|---|
| Missing document storage dir | doctor `storage.documents` fail | `wp mpcf repair storage-dirs --yes` | none if files intact | Re-run doctor |
| Missing AS schedules | doctor `schedule.missing.*` (when observed) | `wp mpcf repair schedules --yes` | none | Ensure cron processes AS |
| DB restored, files missing | storage/content errors; protected stream fails | restore uploads backup | files until restore | Restore `uploads/mpcf` |
| Files restored, metadata missing | missing rows / orphans | restore DB backup | metadata until restore | Full table restore |
| Forward schema on rollback | TARGET stays 8 on older ZIP | documented tolerance | none | Use matching code for M10 CLI |
| Analytics stale/missing | capacity/obsolete or empty rollups | `wp mpcf analytics backfill\|rebuild` | none (rebuild) | Explicit rebuild |
| Consistency orphans | `validate consistency` | **no auto-repair** | N/A | Investigate / restore |
| Audit chain break | `audit verify` fail | restore DB | possible if forged | Escalate |

## P3-E — Monitoring

Signals verified/documented: doctor exit codes; schedule presence; storage fail; capacity ≥50k warn; Site Health mapping; AS backlog guidance. Cron example retained in MONITORING.md.

## P3-F / P3-G — Security & privacy

Structural + OperationalHardening suites green. Privacy exporter/eraser registered. No release blockers. Security review recommendations unchanged (CLI host trust; doctor after deploy).

## P3-H — Accessibility

Operator-critical axe + keyboard coverage from configured browser suite (P2 certified; re-checked in P3/CI). No UI redesign.

## P3-I — Operations documentation

| Doc | Status |
|---|---|
| PRODUCTION_DEPLOY | VERIFIED |
| UPGRADE | VERIFIED (freeze ACTIVE wording fixed) |
| ROLLBACK | VERIFIED |
| DISASTER_RECOVERY | VERIFIED |
| DOCTOR_AND_REPAIR | VERIFIED (`capabilities` repair target added) |
| MONITORING | VERIFIED (environment checker wording corrected) |
| CAPACITY | VERIFIED |
| PERFORMANCE_BASELINE | VERIFIED (updated with 50k timings) |
| privacy | VERIFIED |
| SUPPORT | VERIFIED (new L1/L2 one-pager) |

## P3-J — Supportability

`docs/ops/SUPPORT.md` maps common symptoms → doctor/validate/audit/UI/docs. Sufficient for L1/L2 without source archaeology for listed cases.

## P3-K — Production environment compatibility

| Requirement | Certified floor | Intended Biopentra stack | Gap |
|---|---|---|---|
| PHP | ≥8.1 (tested ≤8.4) | PHP **8.4.23** (`apps/wordpress`) | OK |
| WordPress | ≥6.5 | **7.0.3** | OK |
| WooCommerce | ≥8.2 (tested ≤10.9) | **10.9.4** active | OK |
| Schema TARGET | 8 | Not deployed yet | P5 installs published ZIP |
| HPOS | Ops policy enabled | Confirm at P5 | Checklist item |
| Real cron / AS | Required | Host cron exists for WP | Confirm AS processing |
| Storage `uploads/mpcf` | Required | Standard uploads path | OK pattern |

No unresolved blocker for proceeding to P4 administrative release.

## P2 limitation closure

| Limitation | Status |
|---|---|
| Browser harness flakes/retries | **Acceptable** — CI retries; not production UX |
| PHP 8.5 risk/noise | **Not claimed supported** — CI 8.1/8.4; local 8.5 deprecations NON-BLOCKING |
| Doctor settings warning | **Expected** until settings saved once |
| 50k performance methodology | **Closed** — measured in P3-A |
| v0.9 rollback lacks M10 CLI | **Retained** — expected backward-version limitation |

## Defect log

| ID | Class | Area | Summary | Resolution |
|---|---|---|---|---|
| P3-D1 | DOCUMENTATION | ops | Repair doc omitted `capabilities` | Fixed in DOCTOR_AND_REPAIR.md |
| P3-D2 | DOCUMENTATION | ops | MONITORING overclaimed WC/HPOS in environment checker | Corrected |
| P3-D3 | DOCUMENTATION | ops | UPGRADE called freeze “draft” | Corrected to ACTIVE |
| P3-D4 | NON-BLOCKING | perf | Large IN listing EXPLAIN ALL on customer prefix at 50k | Documented; lookup indexed; no product change |
| P3-D5 | NON-BLOCKING | DR | AS unschedule may not remain visible after bootstrap | Documented; storage DR proven |

**BLOCKER defects:** none. **Frozen-contract conflicts:** none.

## Quality gates

| Gate | Result |
|---|---|
| Unit | **OK** — 610 tests, 1981 assertions |
| Integration | **OK** — 275 tests, 971 assertions |
| Browser (Chromium+Firefox, CI retries) | **OK** — 25 passed, 1 flaky (retry green) after reseed |
| Accessibility (axe + keyboard subset) | **OK** — EXIT 0 |
| PHPCS | **OK** — full suite green |
| POT (`make-pot:check`) | **OK** |
| Build ZIP + release-audit (`--no-dev`) | **OK** |
| 50k performance cert | **OK** — 8 tests, 21 assertions |
| Full GitHub Actions matrix | Required green on PR (do not merge until green) |

## Confirmation

- No features added
- Architecture Freeze remains **ACTIVE**
- P4 not started
- Production not deployed

## Tip (filled at push)

| Field | Value |
|---|---|
| Final HEAD | `62c4bed` |
| PR | https://github.com/magpern/mp-commerce-fulfillment/pull/10 |
| CI run | *(pending — require full matrix green before merge)* |
