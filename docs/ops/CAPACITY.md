# Capacity guidance

Operational scale notes for MPCF warehouse data. Not hard limits — tuning depends on hardware, MariaDB/MySQL config, and open-queue size.

## Reference scales

| Scale | Fulfillments | Status |
|---|---|---|
| **Proven (query shapes)** | **10k** | `QueuePerformanceProofTest` + `docs/QUEUE_PERFORMANCE_VALIDATION.md` |
| **Baseline target** | **50k** | Methodology in `docs/ops/PERFORMANCE_BASELINE.md`; **live timings pending RC dogfood** |
| **Doctor warn threshold** | **50k** | `capacity.fulfillments_scale` warns — review this doc |

At **50k+** fulfillments, expect proportionally larger `mpcf_events` (audit append-only). Events are **not** joined in queue list queries by design.

## Growth drivers

| Store | Growth rate | Notes |
|---|---|---|
| `mpcf_fulfillments` | ~1 row per paid order | Indexed queue filters |
| `mpcf_events` | Multiple per lifecycle step | Largest table at scale; timeline paginates |
| `mpcf_analytics_daily` | 1 row × UTC day × warehouse | Bounded; rebuild if `rollup_version` obsolete |
| `uploads/mpcf/photos/` | Operator captures × retention | Purge when `photos_retention_months > 0` |
| `uploads/mpcf/documents/` | Renders accumulate | No automatic purge — plan disk |

## 10k guidance (current proof)

- Queue/Dashboard/Workspace query shapes stay indexed (M1/M2 proof).
- Re-run performance proof when schema or index changes.
- Open-queue size affects operator UX more than list query cost.

## 50k guidance (planned measurement)

Before declaring production-ready at 50k:

1. Run baseline harness (see `PERFORMANCE_BASELINE.md`).
2. Doctor should pass; capacity checker may warn — expected.
3. Analytics ROLLUP mode preferred for overview/report pages.
4. Schedule nightly rollup; avoid repeated LIVE rebuilds on full history.

## Archival policy (operator decision — not automated in M10)

MPCF **does not** ship automatic fulfillment/event archival in v0.10.0. Long-retention sites should plan:

| Data | Options |
|---|---|
| Closed fulfillments + events | Export/archive to cold storage; **no built-in prune** — requires ADR + tooling if added |
| Analytics daily rows | Immutable; explicit `wp mpcf analytics rebuild` only when rollup version changes |
| Photo bytes | Settings-driven retention purge (M6) |
| Documents | Manual cleanup or future ADR — rows have no delete API |

**Rollback note:** downgrading plugin version does not remove analytics or event history (see `docs/ops/ROLLBACK.md`).

## Open queue hygiene

Doctor reports `capacity.open_queue` and `capacity.oldest_open`. Stale open fulfillments increase operator noise — resolve `problem`/`waiting` states in normal ops, not via repair CLI.
