# Performance baseline methodology (~50k fulfillments)

**Status:** Methodology defined; **live 50k timings pending measurement on RC dogfood**.

## Purpose

Extend the M1/M2 **10k** queue proof (`docs/QUEUE_PERFORMANCE_VALIDATION.md`) to **~50k fulfillments** so capacity guidance and doctor warn thresholds reflect realistic Biopentra scale.

## Current evidence

| Scale | Evidence | Result |
|---|---|---|
| 10k fulfillments | `tests/integration/Performance/QueuePerformanceProofTest.php` | Documented in `QUEUE_PERFORMANCE_VALIDATION.md` — no unindexed scans |
| 50k fulfillments | **Not yet run on reference hardware** | Pending |

Do **not** cite invented millisecond timings until recorded here from a reproducible run.

## How to run (when ready)

1. Use integration harness pattern from `QueuePerformanceProofTest` — bulk SQL seed (not domain-layer intake) for volume.
2. Target distribution: mirror 10k proof (state mix, assignment, event types M2+) scaled 5×.
3. Run via dedicated PHPUnit config (exclude from default CI — same rationale as 10k proof):
   ```bash
   # Example — exact command in test file docblock when 50k variant exists
   vendor/bin/phpunit -c phpunit-performance.xml.dist \
     tests/integration/Performance/QueuePerformanceProofTest.php
   ```
4. Alternatively: disposable env + `wp eval-file` seed script (future) matching integration fixtures.
5. Capture **server-side** timings only (PHP/MySQL); exclude browser paint.

## Metrics to capture (RC dogfood)

Record date, host spec, PHP/MySQL versions, and p50/p95 where applicable:

| Surface | Operation | Metric |
|---|---|---|
| Queue | First page list (default filters) | Wall time + query count |
| Queue | Search by customer name prefix | Wall time |
| Dashboard | Today throughput widgets | Wall time |
| Workspace | Open one `packed` fulfillment (shipments + items) | Wall time |
| Waves | List open waves + one wave detail | Wall time |
| Analytics | Overview ROLLUP mode (30-day range) | Wall time |
| Analytics | LIVE mode same range (compare) | Wall time |
| Doctor | Full run | Wall time + exit code |
| Validate | `consistency` + `schema` | Wall time |

Also record: `mpcf_events` row count, `uploads/mpcf/` disk bytes, doctor `capacity.*` output.

## Acceptance criteria (draft)

- Queue list and workspace open remain **indexed** (EXPLAIN / no full table scan on fulfillments for list shapes).
- Doctor completes in **operator-tolerable** time on dogfood host (threshold TBD after first run).
- No N+1 regression vs 10k proof patterns.

## When to re-run

- Any migrator step or index change on `mpcf_fulfillments`, `mpcf_events`, or queue query shapes.
- Major WooCommerce or MariaDB version bump on production-like hardware.

## Placeholder results

```
Date:       pending RC dogfood
Environment: pending
50k seed:   pending
Timings:    pending measurement
```

Update this section after first successful 50k run.
