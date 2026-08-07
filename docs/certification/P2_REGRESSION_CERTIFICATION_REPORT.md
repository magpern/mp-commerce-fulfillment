# P2 — Regression Certification Report

**Program:** v1.0 Architecture Freeze & Production Readiness  
**Phase:** P2 — Regression Certification  
**Branch:** `certification/p2-regression`  
**Architecture Freeze:** **ACTIVE**  
**Baseline release under test:** `v0.10.0`  
**Starting HEAD:** `114c8d85bc077b77a11503d5c098a2f95c24a48d`  
**Tested commit (certification tip):** see PR / final HEAD on this branch  
**Status:** COMPLETE  
**Verdict:** **PASS WITH DOCUMENTED NON-BLOCKING LIMITATIONS**

## Verdict summary

All release-blocking certification gates passed. No FROZEN-contract conflicts. No feature work. No production deploy. P3 not started.

Non-blocking limitations are documented below (local PHP 8.5 deprecation noise; browser harness flakiness that recovers under CI retries; doctor settings-option warn; rollback CLI surface differences; 50k perf measurement still pending methodology).

## Environment

| Item | Value |
|---|---|
| Certification host | Biopentra Dev VPS (`/opt/biopentra`) |
| Starting commit | `114c8d8` (`docs(v1): activate architecture freeze`) |
| Published ZIP | `mp-commerce-fulfillment-0.10.0.zip` SHA-256 `1c44029935460553505cd8bf3d6d908a2cc3ab5d18c1a52b1ba4d15e2b97cd85` |
| Clean-install stack | Docker `wordpress:7.0.2-php8.4-apache` + `mariadb:11.4` (`compose_mpcf_rc`) |
| Unit runner | `composer:2` image (PHP 8.5 CLI locally) |
| Integration runner | `mpcf-test-runner:latest` + `mpcf-test-db` / `wordpress_test` |
| Browser site | `http://127.0.0.1:8888` (`wordpress_browser` on `mpcf-test-db`) |
| Browser runner | `mcr.microsoft.com/playwright:v1.62.1-jammy` (matches lockfile `@playwright/test@1.62.1`) |
| Main CI at P2 start | Green (`docs(v1): activate architecture freeze`, run `31192166192`) |

## Principles followed

- Frozen contracts authoritative; no features; no redesign; no opportunistic refactors.
- Runtime changes only for proven certification defects — **none required**.
- Evidence recorded; not fabricated.
- Architecture Freeze remains **ACTIVE**.

## Defect log

| ID | Class | Area | Summary | Resolution |
|---|---|---|---|---|
| P2-D1 | NON-BLOCKING | Unit / PHP 8.5 | `Reflection*::setAccessible()` deprecation prints mark 5 unit tests risky under local Composer PHP 8.5 | No product defect. CI matrix uses PHP 8.1/8.4 where suite is clean. |
| P2-D2 | NON-BLOCKING | Browser harness | Under parallel chromium+firefox workers, occasional flake (`Control+Enter` timing, photo upload timeout, firefox wave first-scan). Passes on CI retry or isolated re-run. | Documented. Final clean-suite run: **24 passed**, **2 flaky** (retry OK), **0 failed**. Firefox wave isolated re-run: **2/2 passed**. |
| P2-D3 | NON-BLOCKING | Doctor | `configuration.settings_option` warn when option never saved (defaults apply) | Expected; not release-blocking. |
| P2-D4 | DOCUMENTATION / LIMITATION | Rollback | `v0.10.0 → v0.9.0`: forward schema TARGET **8** retained; `wp mpcf doctor` absent on 0.9.0 (M10 surface) | Documented rollback limitation. Core 0.9 CLI (`intake`, `analytics`) remains. Data counts unchanged. |
| — | — | Frozen contracts | No freeze↔runtime drift requiring STOP | N/A |

**BLOCKER / IMPORTANT runtime defects found:** none.

## Matrix

| Area | Result | Evidence | Defects | Notes |
|---|---|---|---|---|
| P2-A Installation | **PASS** | `/tmp/mpcf-p2-cert/p2a-install.log` | P2-D3 | Published ZIP activate; WP 7.0.2 / PHP 8.4.23 / WC 10.9.4 / MPCF 0.10.0 / DB=8; 12 tables; doctor 45 pass / 1 warn / 0 fail; privacy exporter+eraser; Site Health `mpcf_operational` |
| P2-B Upgrade/Rollback | **PASS** | `/tmp/mpcf-p2-cert/logs/p2b-*-*.log` | P2-D4 | `0.8.0→0.10.0` (db 7→8); `0.9.0→0.10.0`; `0.5.0→0.10.0`; rollback `0.10→0.9` data retained (fulfillments=2, events=9); restored to 0.10.0 |
| P2-C M0–M3 core | **PASS** | Unit 610 + Integration 275 | — | Workflow/guards/concurrency covered by existing suites |
| P2-D M4 Documents | **PASS** | Integration + release-audit templates | — | Packing/picking/wave templates in ZIP |
| P2-E M5 Notifications | **PASS** | Unit (carriers/tracking) + integration | — | No carrier API calls in tests |
| P2-F M6 Photography | **PASS** | Integration + browser photos (axe path) | P2-D2 | Photos browser passed (retry once on chromium) |
| P2-G M7 Scan | **PASS** | Integration + browser scan-mode | P2-D2 | Chromium+firefox scan passed on final/retry |
| P2-H M8 Wave | **PASS** | Integration + browser wave-scan | P2-D2 | Chromium full; firefox isolated + final suite |
| P2-I M9 Analytics | **PASS** | Integration + CLI analytics present | — | Read-only analytics invariant in unit/integration |
| P2-J M10 Operations | **PASS** | doctor/validate/audit CLI smoke | P2-D3 | `validate schema` Success; `audit verify --all` ok=2 |
| Frozen contracts | **PASS** | `FrozenContractInventoryTest` (8 tests) | — | TARGET 8; caps; schema tables; settings; shipped hooks; deferred hooks absent; CLI classes; version triad 0.10.0 |
| Roles/caps | **PASS** | `/tmp/mpcf-p2-roles.log` | — | Operator lacks delete-photo/analytics/settings/cancel; Lead+Admin have full bundle |
| REST | **PASS** | Doctor REST family checks + integration REST suites | — | `/mpcf/v1/fulfillments`, analytics overview registered |
| CLI | **PASS** | help + doctor/validate/audit/analytics | P2-D4 | Frozen M10 commands present on 0.10.0 |
| Hooks/events | **PASS** | Freeze inventory test + audit verify | — | Append-only chain OK on sample |
| Browser | **PASS** | `/tmp/mpcf-p2-browser-final.log` | P2-D2 | 26 configured tests (chromium+firefox); final EXIT 0 |
| Accessibility | **PASS** | `accessibility.spec.js` chromium+firefox | — | axe: no serious/critical |
| i18n/POT | **PASS** | `composer make-pot:check` | — | Success |
| Security | **PASS** | Guard unit filter 18 tests; M10 review retained | — | Caps/privacy/storage/release-artifact/ADR-0007 guards |
| Performance | **PASS** (existing) | `docs/QUEUE_PERFORMANCE_VALIDATION.md` | — | 10k proof on record; 50k methodology pending (NON-BLOCKING) |
| ADR-0007 / no-inventory | **PASS** | `AnalyticsInventoryGuardTest` + related | — | No wc-inventory-overview table reads |
| PHPCS | **PASS** | 452 files clean | — | `/tmp/mpcf-p2-phpcs.log` |
| Build / release-audit | **PASS** | `bin/build-zip.sh` + `bin/release-audit.sh` | — | Release audit passed |
| Full CI (PR) | **PENDING→required** | GitHub Actions on PR | — | Must be green before merge |

## Role / capability matrix (runtime)

```
administrator:          view_queue=Y process=Y delete_photos=Y analytics=Y operator_stats=Y settings=Y cancel=Y capture=Y render=Y
mpcf_warehouse_lead:    view_queue=Y process=Y delete_photos=Y analytics=Y operator_stats=Y settings=Y cancel=Y capture=Y render=Y
mpcf_warehouse_operator:view_queue=Y process=Y delete_photos=N analytics=N operator_stats=N settings=N cancel=N capture=Y render=Y
```

## Upgrade / rollback detail

| Path | Result | Notes |
|---|---|---|
| Clean install published `0.10.0` | PASS | DB TARGET 8; all tables; schedules present |
| `0.8.0 → 0.10.0` | PASS | db 7→8; fulfillments/events unchanged |
| `0.9.0 → 0.10.0` | PASS | db stays/reaches 8; doctor green |
| `0.5.0 → 0.10.0` | PASS | db 5→8; doctor green |
| Rollback `0.10.0 → 0.9.0` | PASS w/ limitations | No fatal; schema 8 retained; data retained; `doctor` not in 0.9 CLI |
| Restore `0.10.0` | PASS | Post-rollback restore |

## Quality gate counts

| Gate | Result |
|---|---|
| Unit | OK — **610** tests, **1967** assertions, 4 skipped (5 risky under local PHP 8.5 only) |
| Integration | OK — **275** tests, **971** assertions |
| Browser (final) | OK — **24** passed, **2** flaky (retry passed), **0** failed |
| Accessibility | OK — chromium + firefox axe specs |
| FrozenContractInventoryTest | OK — **8** tests, **49** assertions |
| Security/guard filter | OK — **18** tests, **86** assertions |
| PHPCS | OK — 452 files |
| POT | OK |
| Build ZIP | OK — `dist/mp-commerce-fulfillment-0.10.0.zip` |
| Release audit | OK |
| Audit chain | OK — `Verified 2 fulfillments: ok=2 fail=0` |

## Commands log (representative)

```bash
# Unit
docker run --rm -v "$PWD":/app -w /app composer:2 bash -lc 'vendor/bin/phpunit -c phpunit.xml.dist'

# Integration
docker run --rm --network mpcf-test-net -v "$PWD":/app -w /app \
  -e WP_DB_HOST=mpcf-test-db -e WP_DB_NAME=wordpress_test -e WP_DB_USER=root -e WP_DB_PASS=root \
  mpcf-test-runner:latest vendor/bin/phpunit -c phpunit-integration.xml.dist

# Browser (after install-wp-site.sh against wordpress_browser)
docker run --rm --network host -v "$PWD:/app" -w /app \
  -e MPCF_BASE_URL=http://127.0.0.1:8888 -e CI=1 -u "$(id -u):$(id -g)" \
  mcr.microsoft.com/playwright:v1.62.1-jammy \
  bash -lc 'npx playwright test --reporter=list --workers=2'

# PHPCS / POT / build / audit
composer phpcs
composer make-pot:check
composer install --no-dev && bash bin/build-zip.sh && bash bin/release-audit.sh
```

## Frozen-contract audit

- Freeze status **ACTIVE** (`docs/ARCHITECTURE_FREEZE.md`).
- Runtime inventory test asserts TARGET 8, capability slugs, schema tables, settings keys, shipped filters, deferred hooks absent, CLI command classes, version triad `0.10.0`.
- **No STOP.** No documentation↔runtime conflict requiring PO ADR.

## Confirmation checklist

- [x] No features added
- [x] Architecture Freeze remains ACTIVE
- [x] P3 not started
- [x] Production not deployed
- [x] Certification commits only (test + docs)
- [ ] PR CI green (gate before merge)
- [ ] Do not merge until CI green + this report accepted

## Exit

P2 **COMPLETE** with verdict **PASS WITH DOCUMENTED NON-BLOCKING LIMITATIONS**.  
P3 marked **READY TO START** in plan/roadmap — **not begun**.
