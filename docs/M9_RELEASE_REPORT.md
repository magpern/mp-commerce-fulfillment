# M9 Release Report — Operational Analytics & Insights (`v0.9.0` RC)

**Baseline:** `v0.8.0` / branch `feature/m9-operational-analytics` from `b69ec6d`  
**Schema:** settings **9** (unchanged), migrator target **8** (`mpcf_analytics_daily`)  
**Status:** **Release candidate.** Not merged / tagged / published. Production not deployed. **M10 not started.**

## CI waiver (PO-approved)

GitHub Actions was unavailable at M9 start and at RC due to account
billing / spending-limit restrictions (jobs never started). This is not a
code/test failure. Last fully green `main` CI: M8 merge run
`31158595754`. Commits after that baseline on `main` before this branch
were documentation-only M9 planning. Local validation was mandatory for
this RC (unit, integration, PHPCS, POT, build, release-audit).

## Architecture delivered

| Area | Delivered |
|---|---|
| Engine | `AnalyticsEngine` modes **LIVE** / **ROLLUP** / **REBUILD**; `ROLLUP_VERSION = 1` |
| Calculators | Counters, durations (nearest-rank p50/p90), queue ageing (code constants), top-N reasons |
| Persistence | `mpcf_analytics_daily` (UTC day × warehouse); immutable historical rows in normal operation |
| Application | `AnalyticsService`, `AnalyticsRange`, `AnalyticsCsvExporter` |
| REST | `/mpcf/v1/analytics/…` read-only, `mpcf_view_analytics` |
| Admin | Analytics Overview / Reports / Diagnostics (`mpcf-analytics`); Mission Control unchanged |
| CLI | `wp mpcf analytics backfill`, `wp mpcf analytics rebuild` (UTC) |
| Scheduler | `mpcf_analytics_daily_rollup` (Action Scheduler group `mpcf`) |

## Decisions (binding)

| Topic | Decision |
|---|---|
| Scope | Fulfillment-specific observability — not BI |
| Calendar | UTC rollup days; UI may show merchant TZ |
| Immutability | Historical rollups change only via explicit REBUILD |
| CSV | UTF-8 from same DTOs as UI; no XLSX |
| Inventory | No inventory/receiving/stock coupling (ADR-0007) |

## Validation evidence (local)

| Check | Result |
|---|---|
| Unit | **593** tests green (4 skipped) |
| Integration | Migration/activation target **8**; Analytics rollup integration green |
| PHPCS | Analytics paths **0** errors |
| Inventory guard | `AnalyticsInventoryGuardTest` green |
| Version triad | `0.9.0` header / `MPCF_VERSION` / readme Stable tag |

## Packages

| Package | Outcome |
|---|---|
| M9-A | Schema 8, engine, calculators, repo, scheduler, CLI |
| M9-B | Analytics Overview + Stage Timeline + ageing |
| M9-C | Reports + CSV |
| M9-D | Diagnostics lists + top reasons |
| M9-E | Tests, docs, `0.9.0` RC ZIP/audit |

## Explicit non-goals (unchanged)

BI platform; Mission Control redesign; inventory analytics; Site Health /
`wp mpcf doctor` / privacy exporter (M10); Excel exports; workflow mutation.
