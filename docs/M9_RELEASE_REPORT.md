# M9 Release Report — Operational Analytics & Insights (`v0.9.0`)

**Baseline:** `v0.8.0` / branch `feature/m9-operational-analytics` from `b69ec6d`  
**Schema:** settings **9** (unchanged), migrator target **8** (`mpcf_analytics_daily`)  
**Status:** **Released.** M9 closed. **M10 not started.** Production not deployed.

## Architecture delivered

| Area | Delivered |
|---|---|
| Engine | `AnalyticsEngine` modes **LIVE** / **ROLLUP** / **REBUILD**; `RollupVersion::CURRENT = 1` |
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

## Validation evidence

| Check | Result |
|---|---|
| Unit | **593** tests, **1887** assertions (4 skipped) — green |
| Integration | **270** tests, **954** assertions — green |
| PHPCS | **0** errors — green |
| POT | Regenerated — green |
| Release-audit | Passed (+ zip) |
| Browser Playwright | Existing suite green on PR CI (no dedicated analytics browser specs) |
| CI / GitHub Actions | PR **#7** full matrix green at `b5d43af` (run `31171182819`); matched green main baseline `31158595754` |
| Clean install | ZIP activate → migrator **8**, table + indexes, REST/CLI/scheduler OK |
| Upgrade from `v0.8.0` | Migration 8; LIVE/ROLLUP/REBUILD/CLI OK; operational data retained |
| Rollback to `v0.8.0` | No fatal; analytics table retained and ignored; fulfillment data intact |
| Analytics correctness | LIVE counts, ageing buckets, timeline avg, CSV↔DTO parity on dev |
| Read-only invariant | Operational tables / WC stock unchanged; only analytics rows wrote |

## Release publication

| Field | Value |
|---|---|
| Merge commit | `f8542c455e19acc5e01de36a3e6e66f9c4ae14a2` |
| Tag | `v0.9.0` → `f8542c4` |
| GitHub Release | https://github.com/magpern/mp-commerce-fulfillment/releases/tag/v0.9.0 |
| Release workflow | https://github.com/magpern/mp-commerce-fulfillment/actions/runs/31172193956 — **success** |
| Published asset | `mp-commerce-fulfillment-0.9.0.zip` (336 files) |
| Local SHA-256 (pre-publish rebuild @ `b5d43af`) | `376b89a95ff7d77804c3dbdd13a6da86d097a1c7920cb0806243b8493a56c9ad` |
| Published SHA-256 | `7af2a367c8abfe7fc291cd4a9ae5b968e79c5347b45ce7757b072343a075ad85` |
| SHA delta | Expected archive/Composer metadata differences only; version parity and M9 runtime paths verified in published ZIP |

## Explicit confirmations

- Merged to `main` via PR #7 (merge commit)
- Tagged `v0.9.0` on merge commit
- Published to GitHub Releases
- Temporary Actions billing waiver **closed** (superseded by green CI)
- **Not** deployed to production
- **M9 closed**
- **M10 not started**

## Explicit non-goals (unchanged)

BI platform; Mission Control redesign; inventory analytics; Site Health /
`wp mpcf doctor` / privacy exporter (M10); Excel exports; workflow mutation.

## Packages

| Package | Outcome |
|---|---|
| M9-A | Schema 8, engine, calculators, repo, scheduler, CLI |
| M9-B | Analytics Overview + Stage Timeline + ageing |
| M9-C | Reports + CSV |
| M9-D | Diagnostics lists + top reasons |
| M9-E | Tests, docs, `0.9.0` release |

## CI note during RC

Early PR runs failed to start under account billing/spending limits. Final
feature HEAD `b5d43af` ran green after Actions recovered. One PHPCS fix
(`WpdbAnalyticsDailyRepository` ignore placement) landed before merge.
