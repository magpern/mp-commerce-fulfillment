# Queue/Dashboard/Workspace performance validation (D21)

Falsifies Architecture Plan §III.5 acceptance criterion 3 (Milestone 1) and
§IV.15's performance criteria (Milestone 2, F23). Originally run for M1's
`mpcf_db_version = 3`; re-run for M2 (F23) after the schema advanced to
`mpcf_db_version = 5` and the event table gained 13 new event types, per
§IV.10's own instruction to revisit the `event_type`-vs-`created_at` index
choice "if a future milestone adds enough additional event types" — M2 did.

**Result: no unindexed scan, no N+1, no non-scaling query plan, for every
M1 shape and for the three M2 additions (workspace load, tracking-number
lookup, and the same today-throughput shapes re-run against a far larger
and more varied event distribution). No index change is required — the
schema stays at `mpcf_db_version = 5` as shipped through F2.**

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
  warehouse state distribution, ~25,000 line items, ~110,000 audit events
  (up from ~58,000 in M1 — see "M2 event distribution" below), and, new for
  M2, ~7,510 shipments each with one package. No real PII — customer names
  are a fixed pool of obviously synthetic names (`Alex Warehouse`, `Blake
  Testcustomer`, …).
- **State distribution:** unchanged from M1 — 7,000 `completed`, 500
  `cancelled`, 2,500 spread across the open states (`queued` 700, `picking`
  500, `picked` 400, `packing` 350, `packed` 300, `shipped` 150, `delivered`
  60), 40 in an exception state (`problem` 20, `waiting` 15, `backordered`
  5).
- **Age:** open-state fulfillments created 0–14 days ago (exception states
  1–20 days ago, reflecting that they've been stuck); closed fulfillments
  created 3–120 days ago.
- **Assignment:** ~65% of open-state fulfillments assigned to one of 20
  synthetic operator ids; the rest (and all closed ones split similarly)
  unassigned.
- **Items:** 1–5 per fulfillment, SKUs drawn from a `SKU-1000`–`SKU-9999`
  pool, deliberately reused across many fulfillments so a prefix search
  matches a realistic multi-row bucket, not exactly one row.
- **Audit events — M1 shape (`fulfillment.state_changed`):** one event per
  step on each fulfillment's path to its current state, spread across its
  own lifetime, exactly as M1 seeded it.
- **Audit events — M2 event distribution (new for F23):** every fulfillment
  that reached a given milestone state also carries the M2 event types a
  real pack would have produced getting there: `items.picked` (packing state
  or later, 8,860 fulfillments), `items.packed` + `shipment.created` +
  `package.created` + `shipment.updated` + `document.rendered` (packed or
  later, 7,510 fulfillments each), `shipment.shipped` (shipped or later,
  7,210), `shipment.delivered` (delivered or later, 7,060). This is what
  turns "one event type in the table" (M1) into 14 event types with real,
  varied cardinality — the actual condition §IV.10 named for revisiting the
  `event_type`-vs-`created_at` index choice below.
- **Shipments/packages (new for F23):** one `mpcf_shipments` row per
  fulfillment that reached `packed` or later (7,510 rows), carrier
  `postnord`, a deterministic `tracking_number` (`TRACK-{order_id}`), and
  `status` reflecting whether it has actually shipped; one `mpcf_packages`
  row per shipment with a random weight/dimensions in realistic ranges.
  `mpcf_package_items` is not seeded — no query shape this proof measures
  reads it (a deliberate scope decision, not an oversight; see "Tracking-
  number search" below for the parallel decision on the search classifier).
- **Timing:** each query shape run 20 times; first run recorded as "cold",
  the sorted remainder gives p50/p95. All 20 runs hit the same warmed
  MariaDB buffer pool.
- **EXPLAIN:** captured from `$wpdb->last_query` immediately after each
  real call into the actual production service/repository — never a
  hand-copied approximation. Multi-query shapes (search, workspace load)
  have each of their queries captured and asserted independently.
- **Target:** p95 < 200ms per query shape on the reference Docker container
  (`mpcf-test-runner` + `mariadb:11.4`, no other proof running
  concurrently) — a local-development-container budget, not a production
  SLA — except workspace load, which Architecture Plan §IV.15 gives its own
  300ms budget (it is several joined reads bundled into one server render,
  not a single query shape).

## Results

| Query shape | Index used | EXPLAIN type | Rows examined | Cold | p50 | p95 |
|---|---|---|---|---|---|---|
| Default open Queue (`state IN open`, `ORDER BY created_at DESC LIMIT 20`) | `created_at` | index | 80 | 1.96ms | 3.60ms | 11.56ms |
| State filter (`state = picking`) | `created_at` | index | 402 | 1.50ms | 3.08ms | 8.50ms |
| Assignee filter (`assignee_id = ` + state) | `created_at` | index | 80 | 8.43ms | 10.16ms | 14.34ms |
| Unassigned filter (`assignee_id IS NULL` + state) | `created_at` | index | 80 | 23.92ms | 31.61ms | 36.84ms |
| Age filter (`state_entered_at <=` + state) | `created_at` | index | 80 | 7.63ms | 8.85ms | 10.25ms |
| Numeric search — lookup (`id = ? OR order_id = ?`) | `order_unique`,`PRIMARY` | index_merge | 2 | — | — | — |
| Numeric search — end to end (lookup + listing) | `PRIMARY` | const | 1 | 0.87ms | 1.74ms | 3.24ms |
| SKU-prefix search — lookup (`sku_snapshot LIKE 'SKU-1%'`) | `sku_snapshot` | range | 3,402 | — | — | — |
| SKU-prefix search — end to end (lookup + listing) | `created_at` | index | 20 | 34.13ms | 38.57ms | 48.07ms |
| Customer-name-prefix search — lookup (`customer_name_snapshot LIKE 'Alex%'`) | `customer_name_snapshot` | range | 834 | — | — | — |
| Customer-name-prefix search — end to end (lookup + listing) | `created_at` | index | 241 | 10.86ms | 18.13ms | 28.59ms |
| Dashboard needs-attention (exception states, `ORDER BY state_entered_at`) | `state_warehouse` | range | 40 | 1.35ms | 2.97ms | 5.90ms |
| Dashboard oldest-open (open states, `ORDER BY created_at`) | `created_at` | index | 40 | 17.96ms | 21.90ms | 27.92ms |
| Dashboard unassigned (open states + `assignee_id IS NULL`) | `created_at` | index | 40 | 27.16ms | 30.88ms | 39.69ms |
| Dashboard `open_count` (`COUNT(*) WHERE state IN open`) | `state_warehouse` | range | 2,500 | 1.80ms | 2.83ms | 4.02ms |
| Dashboard `exception_count` (`COUNT(*) WHERE state IN exception`) | `state_warehouse` | range | 40 | 0.32ms | 0.69ms | 2.14ms |
| Dashboard `packed_today` (`event_type` + `created_at` range) — **M2 distribution** | `created_at` | range | 1,067 | 5.46ms | 6.93ms | 8.26ms |
| Dashboard `shipped_today` (`event_type` + `created_at` range) — **M2 distribution** | `created_at` | range | 1,067 | 5.12ms | 6.19ms | 8.47ms |
| **Workspace load — fulfillment (PRIMARY)** | `PRIMARY` | const | 1 | — | — | — |
| **Workspace load — items (fulfillment_id)** | `fulfillment_id` | ref | 5 | — | — | — |
| **Workspace load — last-5 timeline (fulfillment_id, `ORDER BY id DESC LIMIT 5`)** | `fulfillment_id` | ref | 16 | — | — | — |
| **Workspace load — packages (shipment_id)** | `shipment_id` | ref | 1 | — | — | — |
| **Workspace load — notes (fulfillment_id)** | `fulfillment_id` | ref | 1 | — | — | — |
| **Workspace load — end to end** (all five reads above, bundled) | `fulfillment_id` | ref | 1 | 1.90ms | 4.27ms | 8.23ms |
| **Tracking-number lookup** (`mpcf_shipments.tracking_number`, exact match) | `tracking_number` | ref | 1 | 0.24ms | 0.32ms | 1.02ms |

14 tests, 64 assertions, all green. Full raw run: `docker run --rm
--network mpcf-test-net -v "$PWD":/app -w /app -e WP_DB_HOST=mpcf-test-db
mpcf-test-runner:latest bash -c "bash tests/bin/install-wp.sh &&
vendor/bin/phpunit -c phpunit-performance.xml.dist"`.

## Findings

- **No full table scan anywhere**, including on any of the three new M2
  shapes. Every `EXPLAIN` row's `type` is `const`, `range`, `index_merge`,
  `index`, or `ref` — never `ALL`.
- **The M1 findings all still hold** at the larger, M2-shaped dataset: no
  unindexed `LIKE '%…%'` scan (both prefix-search lookups still resolve via
  their dedicated index in `range` mode), numeric search still benefits
  from `index_merge`, no N+1 anywhere.
- **`event_type` vs `created_at`, re-measured and re-confirmed —
  `created_at` still wins, and the reasoning has changed.** M1's finding
  reasoned that `event_type = 'fulfillment.state_changed'` was the
  non-selective predicate "because M1 has only one event type." M2 gave
  the table 14 event types, `fulfillment.state_changed` is no longer
  anywhere near "nearly every row," and MariaDB's optimizer *still* chose
  `created_at` — because the real selectivity contest here was never
  really about how many distinct `event_type` values exist; it's `created_at
  >= <today>` narrowing a 10,000-fulfillment/multi-month table down to
  roughly 1,000 rows (~1%), a tighter filter than any single `event_type`
  value achieves even at 14-way cardinality. **No composite `(event_type,
  created_at)` index is added** — migration step 6 stays unimplemented,
  exactly as §IV.10 specified ("only if the re-measurement demands it —
  measured, not assumed"). Rows examined actually rose slightly (975 → 1,067)
  purely from the larger dataset, not from a worse plan; p95 improved
  (31.71ms/12.38ms → 8.26ms/8.47ms), well inside target either way.
- **Workspace load resolves in five small, independently-indexed reads,
  comfortably inside its own 300ms budget.** Each read (`fulfillment` by
  `PRIMARY`, `items`/`timeline`/`notes` by `fulfillment_id`, `packages` by
  `shipment_id`) touches a handful of rows regardless of how large the
  tables grow, because every one of them is an equality lookup on an
  indexed foreign key or the primary key — none scale with
  `TOTAL_FULFILLMENTS`. End-to-end p95 was 8.23ms, roughly 36× inside
  budget; the bundle is dominated by connection/PHP overhead, not by SQL.
- **The timeline pagination fix (this milestone, same F23) is what makes
  the workspace-load and REST `recent_events` shapes measurable at all as
  bounded reads** — before it, both fetched a fulfillment's entire event
  chain and sliced it down to 5 in PHP, which this proof's dataset (a
  handful of events per fulfillment) would not have exposed as slow, but a
  real, long-lived fulfillment with hundreds of events would have.
- **Tracking-number lookup is schema-ready, not yet wired to the Queue's
  search box.** `mpcf_shipments.tracking_number` resolves an exact match in
  `ref` mode off its own index — the property Architecture Plan §IV.6/D22
  requires ("`SearchQuery` must resolve a scanned tracking number... without
  an unindexed scan") holds at the schema level. Classifying a
  tracking-shaped search term and routing it to this table
  (`SearchTermClassifier`/`WpdbSearchQuery`) is **not** part of what M2's
  Packing Workspace UI needed — the workspace resolves a fulfillment's own
  shipment directly by fulfillment id, never by searching a tracking
  number — and is recorded here as a scope decision for a future milestone,
  not a silently-uncovered gap.
- **Every p95 is comfortably under its target** — the slowest general
  shape is the SKU-prefix search at 48ms (vs. 200ms); workspace load is
  8.23ms (vs. 300ms).

## Conclusion

Milestone 1's acceptance criterion 3 and Milestone 2's §IV.15 performance
criteria are both satisfied. **No migration amendment is required** — the
schema through `mpcf_db_version = 5` (F2's shipment/package/document
tables) already covers every real query shape the Queue, Dashboard, and
Packing Workspace issue, verified here at 10,000 fulfillments / ~110,000
events / ~7,500 shipments rather than assumed from the column-level design
in Architecture Plan §7.1. The one open item this proof deliberately does
not resolve — wiring tracking-number search into the Queue's own search
box — is a scope decision for a later milestone, not a defect in this one.
