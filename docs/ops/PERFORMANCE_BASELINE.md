# Performance baseline methodology (~50k fulfillments)

**Status:** Methodology defined; **50k timings recorded (P3 Operational Certification, 2026-08-07)**.

## Purpose

Extend the M1/M2 **10k** queue proof (`docs/QUEUE_PERFORMANCE_VALIDATION.md`) to **~50k fulfillments** so capacity guidance and doctor warn thresholds reflect realistic Biopentra scale.

## Current evidence

| Scale | Evidence | Result |
|---|---|---|
| 10k fulfillments | `tests/integration/Performance/QueuePerformanceProofTest.php` | Documented in `QUEUE_PERFORMANCE_VALIDATION.md` — no unindexed scans |
| 50k fulfillments | `tests/integration/Performance/OperationalScale50kCertificationTest.php` + `phpunit-performance-50k.xml.dist` | Recorded in `docs/certification/p3-perf-50k-timings.log` and `docs/certification/P3_OPERATIONAL_CERTIFICATION_REPORT.md` |

Do **not** cite invented millisecond timings — use the certification log.

## How to run

```bash
docker run --rm --network mpcf-test-net -v "$PWD":/app -w /app \
  -e WP_DB_HOST=mpcf-test-db -e WP_DB_NAME=wordpress_test \
  -e WP_DB_USER=root -e WP_DB_PASS=root \
  mpcf-test-runner:latest \
  vendor/bin/phpunit -c phpunit-performance-50k.xml.dist
```

## P3 results (2026-08-07)

| Item | Value |
|---|---|
| Host | Biopentra Dev VPS — 6 vCPU, 11 GiB RAM |
| DB | MariaDB 11.4.12 |
| Dataset | **50,000** fulfillments; **619,650** events |
| Queue initial p95 | ~10 ms (indexed) |
| Workspace open p95 | ~6 ms (indexed) |
| Doctor p95 | ~469 ms |
| Consistency validate p95 | ~303 ms |
| Peak PHP memory | ~1.33 GiB |

**Note:** End-to-end customer-prefix search listing may EXPLAIN as `ALL` when the IN-list is very large; the search **lookup** remains indexed. Recorded as non-blocking operational note.

## When to re-run

- Any migrator step or index change on `mpcf_fulfillments`, `mpcf_events`, or queue query shapes.
- Major WooCommerce or MariaDB version bump on production-like hardware.
