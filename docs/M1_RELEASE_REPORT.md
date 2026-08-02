# Milestone 1 Release Report — Fulfillment Core (Warehouse MVP)

**Status: implementation and documentation reconciliation complete (D1–D20);
D21 (10k-row Queue performance proof) and D22 (full acceptance pass +
release candidate) are the remaining steps before this milestone is ready
to tag.** This report is updated in place as those steps complete — it
describes what actually shipped and what evidence backs each acceptance
criterion, not what was planned. No `v0.1.0` tag or GitHub release exists
at the time of writing.

Execution plan: `docs/ARCHITECTURE_PLAN.md` Part III. Actual outcomes
against that plan: §III.7. This report is the falsifiable evidence record
for §III.5's ten acceptance criteria; §III.7 is the narrative of what
happened and why.

## 1. What shipped

- **Intake:** `Woo\IntakeHooks` creates one `queued` fulfillment per paid
  order (classic and Blocks checkout, via `woocommerce_payment_complete`
  and `woocommerce_order_status_processing`), idempotent at both the
  application layer and the database (`order_unique` index on
  `mpcf_fulfillments (order_id, order_source)`). A failed synchronous
  attempt retries once via an Action Scheduler action in group `mpcf`.
  `wp mpcf intake backfill --status=processing` ingests existing orders
  the same way.
- **Workflow engine:** data-defined `WorkflowDefinition`/`State`/
  `Transition`, a pure `WorkflowEngine::transition()`, and the standard
  pick → pack → ship → deliver → complete workflow with its exception band
  (`problem`/`waiting`/`backordered`) and two shortcut edges. Five
  transition guards (`all_items_picked`, `all_items_packed`,
  `package_spec_present`, `photo_required`, `has_shipment`).
- **Single state writer + audit:** `Application\WorkflowService` is the
  only caller of `Domain\Fulfillment::apply_transition()` (I4), with an
  optimistic lock (`version` column) and one hash-chained `mpcf_events` row
  per transition (I5). `wp mpcf audit verify <id>` walks the chain.
- **Admin UI:** Fulfillment Queue (data table, filter bar, drawer, bulk
  actions, `SearchQuery` v1 for numeric/SKU-prefix/customer-name-prefix
  search), Fulfillment Detail (timeline, notes, manual transitions with a
  reason-capture modal where required), Dashboard (next-actions band, stat
  cards, no picking-list quick action — deliberately omitted, not stubbed).
  All built on MP Admin Design System `v0.2.0`-candidate components.
- **Roles/capabilities/Operator Mode:** `mpcf_warehouse_operator` and
  `mpcf_warehouse_lead`, enforced by `AdminBoundaryGuardTest` at the
  screen level; an optional `operator_mode_enabled` setting hides the rest
  of wp-admin's nav for the operator role.
- **WooCommerce status bridge:** outbound (all-shipped → WC `completed`,
  configurable) and inbound (WC cancel/refund/item-change → fulfillment
  `cancelled`/`problem`), both re-entrancy-guarded against bridge loops.
- **Uninstall policy:** all-or-nothing behind `remove_data_on_uninstall`,
  extended to the four new tables, the two roles, every `mpcf_*`
  capability, the `mpcf` Action Scheduler group, and (currently empty)
  user-meta. See `docs/PERSISTED_DATA.md`.

## 2. Schema

`mpcf_db_version` target raised 0 → 3 (§III.7 records why steps 2 and 3
were added as separate additive migrations rather than folded into step
1's DDL):

| Step | Change |
|---|---|
| 1 | Creates `mpcf_fulfillments`, `mpcf_fulfillment_items`, `mpcf_events`, `mpcf_notes` per Architecture Plan §7.1. |
| 2 | `order_unique` — `UNIQUE KEY (order_id, order_source)` on `mpcf_fulfillments`, making intake idempotency database-enforced. |
| 3 | `customer_name_snapshot` index on `mpcf_fulfillments`, `sku_snapshot` index on `mpcf_fulfillment_items`, for `SearchQuery` v1's prefix lookups. |

D21's 10k-row `EXPLAIN` proof against the real Queue query shapes — the
final check on whether this index set holds at scale — has not run yet;
this section will be updated with its findings, and the schema amended
before tagging if it finds a gap.

## 3. Test evidence (as of the D20 commit sequence)

| Suite | Count | Result |
|---|---|---|
| Unit (`tests/unit/`, 50 files) | 233 tests, 670 assertions | Green |
| Integration (`tests/integration/`, 25 files, real WP+WooCommerce+MariaDB, HPOS forced on) | 109 tests, 310 assertions | Green |
| phpcs | 147 files | Clean |
| `bin/make-pot.sh --check` | — | Clean (regenerated in D20 part 2 — stale since M0, first caught by this milestone's first CI run) |

Every structural guard named in Architecture Plan §19.1 now exists and is
mutation-verified (planted-violation self-test passes and fails
correctly): `DomainPurityGuardTest`, `DbConfinementGuardTest`,
`WooConfinementGuardTest`, `LegacyOrderStorageGuardTest`,
`SingleStateWriterGuardTest`, `AuditAppendOnlyGuardTest`,
`AdminBoundaryGuardTest`, `CompositionRootTest`, `MpdsVendorGuardTest`,
`PersistedKeysInventoryTest`, `UninstallPolicyGuardTest`,
`CiMatrixGuardTest`, `CompatibilityMatrixTest`, `PluginVersionTest`. The
first two were missing until D20 — see §III.7 for how the gap was found
and closed.

CI (GitHub Actions, `magpern/mp-commerce-fulfillment`): the D1–D19 commit
sequence had never been pushed to `origin` before D20 (M0's `v0.0.1`
release was the last thing CI ran against `main`), so D20 is this
milestone's first real CI signal. First push (`da15c00`) failed on
`make-pot:check` only — every other job (`phpcs`, `release-audit`,
`build`, `unit` ×3 PHP versions, `integration` ×5 legs including floor and
current-stable) was green on the first try. Fixed in the same D20 pass
(`1afc01d`); this report is updated once that push's CI result is
confirmed.

## 4. Deviations from the plan (see §III.7 for full narrative)

1. Schema reached version 3 via two additive steps discovered during D9
   and D15, ahead of the formal D21 10k-row proof — additive, idempotent,
   already covered by migration tests.
2. `SingleStateWriterGuardTest`/`AuditAppendOnlyGuardTest` were missing
   since D6; added in D20 once documentation reconciliation checked
   acceptance criterion 9 against the actual test tree.
   `Admin\FulfillmentDetailPage::apply_transition()` renamed to
   `submit_transition()` to remove the name collision that made the I4
   guard's call-token scan ambiguous — no behavior change.
3. `languages/mp-commerce-fulfillment.pot` had not been regenerated since
   M0; regenerated in D20 once CI's `make-pot:check` caught it.

No deviation changed an architectural decision, a data-model shape beyond
the two additive indexes above, or a public contract.

## 5. Outstanding before this milestone can tag

- **D21:** seed ≥10,000 fulfillments with a realistic state/assignment/age
  distribution; capture `EXPLAIN`/timing evidence (p50/p95, cold/warm) for
  every required Queue/Dashboard query shape; confirm no unindexed scan and
  no N+1; amend the migration if the proof demands it.
- **D22:** full acceptance-criteria pass (§III.5, all ten), `release-audit.sh`
  green, both CI legs (floor + current-stable) confirmed green on the
  fixed push, release-candidate artifacts built and verified (version
  sync, POT/manifest regeneration, MPDS vendoring, SHA-256 checksums, no
  dev files, self-contained zip).
- Explicit PO acceptance review and go-ahead before the `v0.1.0` tag is
  created and pushed — not automatic at the end of the commit sequence.
