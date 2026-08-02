# Queue/Dashboard performance validation (Milestone 1, D21)

Falsifies Architecture Plan §III.5 acceptance criterion 3 and closes the
"verified against real Queue query shapes" provision from §III.2.2 (the
pre-specified `mpcf_fulfillments` indexes were accepted as a starting
point, not treated as final until this proof ran).

**Result: no unindexed scan, no N+1, no non-scaling query plan. No index
change is required — the schema stays at `mpcf_db_version = 3` as shipped
in D9/D15.**

## Method

`tests/integration/Performance/QueuePerformanceProofTest.php`, run against
the same Docker test environment as the rest of the integration suite, but
via its own `phpunit-performance.xml.dist` (not part of `composer
test:integration` or CI — see that file's header comment for why). Rerun
this file and update this document whenever the schema, an index, or one
of these query shapes changes.

- **Dataset:** 10,000 fulfillments seeded via direct bulk SQL (not through
  the Domain/Application layers — too slow at this volume for what is a
  query-shape proof, not a domain-correctness proof), with a realistic
  warehouse state distribution, ~25,000 line items, and ~58,000 audit
  events. No real PII — customer names are a fixed pool of obviously
  synthetic names (`Alex Warehouse`, `Blake Testcustomer`, …).
- **State distribution:** 7,000 `completed`, 500 `cancelled`, 2,500 spread
  across the open states (`queued` 700, `picking` 500, `picked` 400,
  `packing` 350, `packed` 300, `shipped` 150, `delivered` 60), 40 in an
  exception state (`problem` 20, `waiting` 15, `backordered` 5).
- **Age:** open-state fulfillments created 0–14 days ago (exception states
  1–20 days ago, reflecting that they've been stuck); closed fulfillments
  created 3–120 days ago — stresses the age filter and the "oldest open"
  ordering meaningfully instead of a uniform random spread.
- **Assignment:** ~65% of open-state fulfillments assigned to one of 20
  synthetic operator ids; the rest (and all closed ones split similarly)
  unassigned — enough of a mix to stress both the assignee and unassigned
  filters.
- **Items:** 1–5 per fulfillment, SKUs drawn from a `SKU-1000`–`SKU-9999`
  pool, deliberately reused across many fulfillments so a prefix search
  matches a realistic multi-row bucket, not exactly one row.
- **Audit events:** one `fulfillment.state_changed` event per step on each
  fulfillment's path to its current state (e.g. a `completed` fulfillment
  has 8: `queued→picking→…→completed`), spread across its own lifetime.
  ~150 of the `packed`/`shipped` transition events are explicitly dated
  "today" (not derived from natural date arithmetic) so the Dashboard's
  today-throughput counters have a realistic non-zero value to actually
  measure — stated here plainly as a synthetic construction, not a claim
  of full same-day-fulfillment narrative realism.
- **Timing:** each query shape run 20 times; first run recorded as "cold",
  the sorted remainder gives p50/p95. All 20 runs hit the same warmed
  MariaDB buffer pool (the test doesn't restart the container between
  runs) — "cold vs warm" here means first-execution vs steady-state on an
  already-connected process, not a full cache flush, which is not
  practical to do safely against a shared test container mid-suite.
- **EXPLAIN:** captured from `$wpdb->last_query` immediately after each
  real call into `QueueService`/`DashboardService`/`WpdbSearchQuery` — the
  actual query the production code issued, not a hand-copied
  approximation. For the two-query search path (`WpdbSearchQuery` lookup,
  then `FulfillmentRepository::query()`'s listing query), both are
  captured and asserted independently — `$wpdb->last_query` only ever
  shows the most recent query, so the lookup is checked first, on its own.
- **Target:** p95 < 200ms per query shape on the reference Docker
  container (`mpcf-test-runner` + `mariadb:11.4`, no other proof running
  concurrently) — a local-development-container budget, not a production
  SLA.

## Results

| Query shape | Index used | EXPLAIN type | Rows examined | Cold | p50 | p95 |
|---|---|---|---|---|---|---|
| Default open Queue (`state IN open`, `ORDER BY created_at DESC LIMIT 20`) | `created_at` | index | 80 | 2.31ms | 3.12ms | 4.08ms |
| State filter (`state = picking`) | `created_at` | index | 400 | 1.17ms | 2.00ms | 3.59ms |
| Assignee filter (`assignee_id = ` + state) | `created_at` | index | 80 | 6.18ms | 8.43ms | 14.28ms |
| Unassigned filter (`assignee_id IS NULL` + state) | `created_at` | index | 80 | 28.00ms | 33.49ms | 41.42ms |
| Age filter (`state_entered_at <=` + state) | `created_at` | index | 80 | 6.43ms | 8.13ms | 14.58ms |
| Numeric search — lookup (`id = ? OR order_id = ?`) | `order_unique`,`PRIMARY` | index_merge | 2 | — | — | — |
| Numeric search — end to end (lookup + listing) | `PRIMARY` | const | 1 | 0.52ms | 0.59ms | 0.74ms |
| SKU-prefix search — lookup (`sku_snapshot LIKE 'SKU-1%'`) | `sku_snapshot` | range | 3,354 | — | — | — |
| SKU-prefix search — end to end (lookup + listing) | `created_at` | index | 20 | 30.23ms | 39.72ms | 89.32ms |
| Customer-name-prefix search — lookup (`customer_name_snapshot LIKE 'Alex%'`) | `customer_name_snapshot` | range | 834 | — | — | — |
| Customer-name-prefix search — end to end (lookup + listing) | `created_at` | index | 239 | 9.71ms | 11.61ms | 20.65ms |
| Dashboard needs-attention (exception states, `ORDER BY state_entered_at`) | `state_warehouse` | range | 40 | 0.98ms | 1.33ms | 3.75ms |
| Dashboard oldest-open (open states, `ORDER BY created_at`) | `created_at` | index | 40 | 16.81ms | 23.35ms | 56.84ms |
| Dashboard unassigned (open states + `assignee_id IS NULL`) | `created_at` | index | 40 | 28.52ms | 36.35ms | 58.20ms |
| Dashboard `open_count` (`COUNT(*) WHERE state IN open`) | `state_warehouse` | range | 2,500 | 1.65ms | 1.92ms | 3.93ms |
| Dashboard `exception_count` (`COUNT(*) WHERE state IN exception`) | `state_warehouse` | range | 40 | 0.32ms | 0.40ms | 3.16ms |
| Dashboard `packed_today` (`event_type` + `created_at` range) | `created_at` | range | 975 | 5.17ms | 9.00ms | 31.71ms |
| Dashboard `shipped_today` (`event_type` + `created_at` range) | `created_at` | range | 975 | 4.80ms | 7.34ms | 12.38ms |

12 tests, 51 assertions, all green. Full raw run: `docker run --rm
--network mpcf-test-net -v "$PWD":/app -w /app -e WP_DB_HOST=mpcf-test-db
mpcf-test-runner:latest bash -c "vendor/bin/phpunit -c
phpunit-performance.xml.dist"`.

## Findings

- **No full table scan anywhere.** Every `EXPLAIN` row's `type` is
  `const`, `range`, `index_merge`, or `index` — never `ALL`. The Queue's
  own filter builder (`WpdbFulfillmentRepository::where_clause()`) only
  ever emits conditions against indexed columns, by construction (I7/§9.3's
  "no unindexed scan" rule) — this proof confirms that construction holds
  at 10k rows in practice, not just by code inspection.
- **No unindexed `LIKE '%…%'` scan.** Both prefix-search lookups
  (`sku_snapshot`, `customer_name_snapshot`) use their dedicated index in
  `range` mode — the step-3 migration index pays off exactly as intended.
  The SKU search's 3,354-row lookup is the intentional worst case in this
  proof (a single-character-after-prefix term matching roughly a ninth of
  all seeded items) and still resolves end to end in 89ms p95 — a typical
  merchant SKU search narrows on more characters and would scan far fewer
  rows.
- **Numeric search benefits from `index_merge`.** MariaDB combines the
  `PRIMARY` and `order_unique` indexes for the `id = ? OR order_id = ?`
  disjunction rather than falling back to a scan — no schema change
  needed for this shape.
- **No N+1.** Every shape here is exactly one or two queries total
  (the search lookup, then the listing query) — `FulfillmentRepository`
  never loads rows one at a time, and none of these shapes triggers
  a per-row follow-up query.
- **Dashboard's today-throughput counters chose `created_at` over
  `event_type`.** Both are indexed single-column keys (no composite
  `(event_type, created_at)` index exists); MariaDB's optimizer preferred
  `created_at` because, in this dataset, `event_type = 'fulfillment.state_changed'`
  is not selective (nearly every seeded event has that type — this
  plugin's only event type in Milestone 1), while the `created_at >= today`
  range is. This is expected, indexed, and fast (975 rows, p95 well under
  target) — not a scan, and not a case that needs a composite index at
  this milestone's event-type cardinality. Revisit if a future milestone
  adds enough additional event types to make `event_type` the more
  selective predicate for a busy shop.
- **Every p95 is comfortably under the 200ms reference-container target** —
  the slowest, the deliberately-broad SKU-prefix search, is 89ms; every
  Dashboard stat query is under 60ms.

## Conclusion

Acceptance criterion 3 is satisfied. **No migration amendment is
required** — the three-step schema (`mpcf_db_version = 3`) shipped in D9
(intake-idempotency unique index) and D15 (search indexes) already covers
every real query shape the Queue and Dashboard issue, verified here at
10,000 rows rather than assumed from the column-level design in
Architecture Plan §7.1.
