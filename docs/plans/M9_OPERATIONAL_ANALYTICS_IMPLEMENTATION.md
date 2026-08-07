# M9 — Operational Analytics & Insights — Milestone Implementation Plan

**Status:** Planning complete — **PO approved Architecture Plan Part XI on 2026-08-07**. Runtime implementation **not started** (awaits explicit implementation GO).  
**Baseline:** `main` / `v0.8.0` (M8 closed).  
**Target release:** `v0.9.0`.  
**Authoritative architecture:** `docs/ARCHITECTURE_PLAN.md` **Part XI** (+ §15 / §20).  
**This file:** execution checklist and acceptance surface for implementers. It does not introduce architecture beyond Part XI.

---

## 1. Goals

1. Operational observability over M0–M8 outbound execution data (throughput, Stage Timeline, queue ageing, waves, scans, photos, documents, notifications, top failure reasons).
2. `AnalyticsService` → `AnalyticsEngine` (**LIVE** / **ROLLUP** / **REBUILD**) → Calculators → Repositories.
3. Persist closed-UTC-day rollups in **`mpcf_analytics_daily`** with **`rollup_version`** (`ROLLUP_VERSION = 1`).
4. Keep analytics **read-only** — no pick/pack/ship/wave workflow changes.
5. Honor ADR-0007: no inventory / receiving / purchasing / supplier / stock analytics.
6. Analytics owns analytics; Mission Control owns attention-now — no trend teasers, no Mission Control redesign.

## 2. Architecture summary

| Layer | M9 addition |
|---|---|
| Application | `AnalyticsService` (façade: caps, ranges, DTO shaping, CSV) |
| Engine | `AnalyticsEngine` with modes **LIVE**, **ROLLUP**, **REBUILD** |
| Calculators | `Engine\Analytics\*` — **Counters** and **Durations** separate; nearest-rank p50/p90 |
| Infrastructure | `mpcf_analytics_daily` (migrator target **8** indicative); repositories; nightly AS ROLLUP; CLI backfill/rebuild |
| API | Read-only `/mpcf/v1/analytics/…` (additive v1 only) |
| Admin | Analytics Dashboard, Reports (+ CSV), Diagnostics — under Analytics IA |
| Caps | `mpcf_view_analytics`; operator stats default off (`mpcf_view_operator_stats` / D17) |

**Calendar:** rollup keys = **UTC** days; UI may show merchant timezone; CLI rebuild = UTC.

**Immutability:** Historical rollups are immutable during normal operation. They change only through an explicit administrative rebuild.

## 3. Data ownership & boundaries

**MPCF owns:** analytics read models, rollup rows, Analytics UI/REST/CSV, rebuild CLI.

**MPCF does not own:** inventory, receiving, POs, suppliers, stock ledger, financial/ERP analytics, Mission Control redesign, Site Health / doctor / privacy exporter (M10).

**Source of truth:** existing `mpcf_events` and aggregates — **no duplicate business state**.

## 4. Metric families (implement/test separately)

### Counters

Fulfillments created/packed/shipped; waves completed/abandoned (+ size / items / lines); scans; photos; notifications; documents; exception tallies; top-N failure reasons.

### Durations (Stage Timeline)

Queued→Picking, Picking→Picked, Picked→Packing, Packing→Packed, Packed→Shipped, Queued→Shipped — avg + **deterministic nearest-rank** p50/p90.

### Queue ageing (code constants — not settings)

`0–1h` · `1–4h` · `4–24h` · `1–3d` · `>3d`

## 5. Phase breakdown

### M9-A — Domain & engine

- [ ] Part XI PO-approved (done 2026-08-07); implementation GO received
- [ ] Migrator: `mpcf_analytics_daily` + `rollup_version`
- [ ] `AnalyticsEngine` LIVE / ROLLUP / REBUILD
- [ ] Counter + duration calculators (nearest-rank fixtures)
- [ ] REST skeleton `/mpcf/v1/analytics/…`
- [ ] Unit + integration tests for modes / UTC keys / immutability guards
- [ ] `PERSISTED_DATA.md` / `HOOKS.md` / `API.md` stubs

**Exit:** Can LIVE-query today and ROLLUP a closed UTC day via CLI; no rich UI yet.

### M9-B — Analytics Dashboard

- [ ] Dashboard cards (throughput, waves, exceptions)
- [ ] Stage Timeline section
- [ ] Queue ageing buckets
- [ ] Clear LIVE vs historical labeling
- [ ] Browser smoke

**Exit:** Lead can read today’s LIVE metrics and yesterday’s rollup without REST tooling.

### M9-C — Reports & CSV

- [ ] Daily / weekly / monthly / custom ranges
- [ ] CSV UTF-8 from **same DTOs** as AnalyticsService (parity test)
- [ ] Nightly Action Scheduler ROLLUP job
- [ ] CLI backfill for historical gaps

**Exit:** Export and scheduled rollups work; no Excel path.

### M9-D — Diagnostics

- [ ] Stalled waves / slow fulfillments lists
- [ ] Top rejection / guard / scan / notification failure reasons
- [ ] Operator-stats fields gated (D17 + cap)
- [ ] Optional `analytics.rollup_completed` event

**Exit:** Operational diagnostics usable under Analytics IA.

### M9-E — Hardening & release

- [ ] Dogfood on `dev.biopentra.eu`
- [ ] REBUILD detects `rollup_version < ROLLUP_VERSION`
- [ ] Guards: inventory coupling, no workflow mutation, no Mission Control teasers
- [ ] Docs finalize; version `0.9.0`; ZIP + release-audit; PR
- [ ] PO GO → merge/tag/publish (no silent prod deploy)

## 6. Acceptance criteria

See Part XI.11. Minimum dogfood script:

1. Confirm LIVE today matches open fulfillments/events for current UTC day.
2. Run ROLLUP for yesterday; reload Analytics — values come from `mpcf_analytics_daily`.
3. Stage Timeline shows avg + p50/p90; nearest-rank matches unit fixture.
4. Ageing buckets only the five code constants.
5. Attempt UI-driven historical rewrite — must be refused; CLI rebuild succeeds.
6. CSV columns match DTO/REST field set.
7. Confirm no inventory plugin reads; Mission Control unchanged (no trend cards).

## 7. Validation & testing

| Tier | Focus |
|---|---|
| Unit | Counters; durations + nearest-rank; ageing buckets; UTC day keys |
| Integration | LIVE / ROLLUP / REBUILD; immutability; obsolete version; REST caps; CSV↔DTO |
| Browser | Analytics Dashboard smoke |
| Structural | ADR-0007 / no stock writes in Analytics packages |
| Release | PHPCS, POT, matrix CI, release-audit, version triad |

## 8. Release strategy

- Branch: `feature/m9-operational-analytics` from current `main` / after `v0.8.0`.
- Version bump only in M9-E.
- Tag `v0.9.0` only on explicit PO GO.
- M10 must not start until M9 closed.

## 9. Risks

| Risk | Mitigation |
|---|---|
| Event-table scans for LIVE today | Indexed queries only; keep LIVE window small |
| Historical dashboard drift | Immutability + explicit REBUILD only |
| Calculator/format change invalidates old rows | `ROLLUP_VERSION` + rebuild detection |
| Scope creep into BI / Excel / Mission Control | Explicit non-goals; stop conditions |
| DST / timezone confusion | UTC rollup keys; UI converts display only |

## 10. Out of scope reminder

BI platform, AI forecasting, inventory/receiving/purchasing/supplier/stock analytics, financial/ERP, Mission Control redesign, Excel/XLSX, merchant-configurable ageing, Site Health / doctor / privacy exporter (M10), workflow mutation, duplicate business state.
