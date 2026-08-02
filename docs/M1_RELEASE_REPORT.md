# Milestone 1 Release Report — Fulfillment Core (Warehouse MVP)

**ARCHIVED — Milestone 1 is formally closed.** The Product Owner accepted
the milestone and its release-candidate verification on 2026-08-02 and
authorized tagging and release. Both repos are now tagged and published:

- `mp-admin-design-system` — [`v0.2.0`](https://github.com/magpern/mp-admin-design-system/releases/tag/v0.2.0), commit `c19871670fbfb5906a0299641741058144d74cba`
- `mp-commerce-fulfillment` — [`v0.1.0`](https://github.com/magpern/mp-commerce-fulfillment/releases/tag/v0.1.0), commit `bed7df1697386f32b05153eeb1ddde56e0c99486`

The published `mp-commerce-fulfillment-0.1.0.zip` release asset was
independently re-downloaded and re-verified after publication (contents,
version parity, no dev files, no runtime dependency on
`mp-admin-design-system` — same checks as the release-candidate pass
below, repeated against the actual tagged build). One cosmetic,
non-functional finding: `assets/mpds/SOURCE_TAG` inside the shipped zip
still reads "pending PO tag approval" for the MPDS source commit, since
that file was vendored during D1 before either tag existed — accurate at
vendor time, stale in wording only, no behavioral effect. Not corrected
here since the `v0.1.0` tag is already published; a candidate fix for a
future patch release, not a reason to reopen this one.

This report remains the historical evidence record for the milestone,
below, unchanged from the acceptance pass that earned the PO's approval.

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

D21's 10k-row `EXPLAIN` proof against the real Queue query shapes ran and
confirmed this index set holds at scale — no full table scan, no N+1, no
non-scaling plan, every p95 under the 200ms reference-container target.
**No migration amendment was required.** Full evidence:
`docs/QUEUE_PERFORMANCE_VALIDATION.md`.

## 3. Test evidence (as of the D21 commit sequence)

| Suite | Count | Result |
|---|---|---|
| Unit (`tests/unit/`, 50 files) | 233 tests, 670 assertions | Green |
| Integration (`tests/integration/`, 25 files, real WP+WooCommerce+MariaDB, HPOS forced on) | 109 tests, 310 assertions | Green |
| Performance (`tests/integration/Performance/`, 1 file, 10k-row seeded dataset, run separately via `phpunit-performance.xml.dist`) | 12 tests, 51 assertions | Green |
| phpcs | 148 files | Clean |
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
release was the last thing CI ran against `main`), so D20 was this
milestone's first real CI signal. First push (`da15c00`) failed on
`make-pot:check` only — every other job (`phpcs`, `release-audit`,
`build`, `unit` ×3 PHP versions, `integration` ×5 legs including floor and
current-stable) was green on the first try. Fixed in the same D20 pass
(`1afc01d`), confirmed green (`c668082`), and green again on D21's push
(`f6e5ce6`) — the Performance suite is excluded from CI by design (see
§3's table), so CI's own `integration` legs stay unaffected by it.

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

## 5. D22 — full acceptance pass

Every §III.5 criterion, checked against real evidence:

| # | Criterion | Evidence |
|---|---|---|
| 1 | Paid order (classic + Blocks) → exactly one `queued` fulfillment, no duplicate on repeat | `Woo\IntakeHooks` integration tests: "Paying an order creates exactly one fulfillment", "A second payment notification for the same order creates no duplicate" |
| 2 | `wp mpcf intake backfill` idempotent | `Cli\BackfillCommand` integration tests: "Repeated backfills create no duplicates" |
| 3 | Queue indexed at 10k rows, p95 under target | `docs/QUEUE_PERFORMANCE_VALIDATION.md` — no full scan, no N+1, p95 ≤ 89ms worst case |
| 4 | Every standard-workflow transition executable exactly per §6.2; forbidden edges rejected | Unit: `WorkflowEngineTest` + per-guard tests, table-driven over every edge |
| 5 | Operator: full pick-to-ship, no WC orders/settings/cancel; Lead: can cancel (audited) | `MenuVisibilityTest`, `FulfillmentDetailPageTest` ("capability forbidden transition is rejected for an operator" / "succeeds for a lead"), `QueuePageTest` bulk-action capability tests |
| 6 | WC cancel → open fulfillments `cancelled` (audited), no bridge loop | `RefundObserver`/`StatusBridge` integration tests: "Cancellation moves a queued fulfillment to cancelled", "does not recursively trigger another bridge write" |
| 7 | `wp mpcf audit verify` passes/fails correctly | Unit hash-chain tests + `WpdbEventRepositoryTest` |
| 8 | Deactivate/reactivate loses nothing; uninstall keep/remove exact | `UninstallPolicyIntegrationTest`, full 7-test suite including the D19 Action Scheduler additions |
| 9 | Every §19.1 guard test exists, passes, and fails on injected violation | All 14 guards present (2 added in D20); each has its own planted-violation self-test, all green |
| 10 | Docs + ADRs + ROADMAP current; CI floor + current-stable green | D20/D21 doc reconciliation; CI run `30761537682` — `phpcs`, `pot`, `unit`×3, `integration`×5 (floor, current, mixed-php-floor, mixed-wp-floor, ceiling), `release-audit`, `build` all green |

Beyond the ten criteria, this pass also directly confirmed (all in the
same green integration run, `109 tests, 310 assertions`): the Action
Scheduler 200-order burst test, the PII-payload guard (unit), the
optimistic-lock conflict path (`WpdbFulfillmentRepositoryTest` +
`FulfillmentDetailPageTest`), `HposProofTest` (ran, zero skips — HPOS was
active), Operator Mode's full behavior matrix, and every
cancellation/refund/partial-refund/item-change path including the
diff-summary payload's own "contains no customer data" assertion.

No implementation defect was found beyond the two already resolved in
D20 (missing guards, stale POT) — D22 itself surfaced nothing new
requiring a fix.

## 6. Release candidates

**`mp-admin-design-system` v0.2.0-rc:** commit `c19871670fbfb5906a0299641741058144d74cba`
on `origin/main`, CI green (`phpcs`, `manifest`, `test`×3 PHP versions —
42 tests, 168 assertions). No zip artifact — this repo is a source
library distributed by git tag and vendored via `bin/sync-mpds.sh`, not
an installable package; its commit hash is its content-addressed
identity. `MANIFEST` already current (`bin/make-manifest.sh` produces no
diff). **Tagged and released as `v0.2.0` on 2026-08-02.**

**`mp-commerce-fulfillment` v0.1.0-rc:** commit `cc4a4e0` on `origin/main`
— version header/constant/readme Stable tag bumped to `0.1.0` together,
POT regenerated. Built by CI run `30761537682`'s `build` job (a clean
`--no-dev` install, not a local reproduction) and downloaded as
`mp-commerce-fulfillment-0.1.0.zip`:

- SHA-256: `fb0c9430c438cf16cce46276ea349a56f5b09267c6e925689ec202a10a5ebacf`
- 132 entries; contains `mp-commerce-fulfillment.php`, `uninstall.php`,
  `vendor/autoload.php`, `readme.txt`, `languages/`, `src/`, `assets/`
- `vendor/composer/installed.json` reports zero packages (`"dev": false`)
  — no composer runtime dependency of any kind, confirming this plugin
  has none, in production or otherwise
- `assets/mpds/` and `src/Vendor/Mpds/` contain the vendored MPDS files
  directly (CSS/JS/PHP) — no reference to the `mp-admin-design-system`
  package anywhere in the zip; `assets/mpds/SOURCE_TAG` records
  `v0.2.0-rc (pending PO tag approval; source: mp-admin-design-system@c19871670fbfb5906a0299641741058144d74cba)`
- No dev-only files: no `tests/`, no `vendor/phpunit`, no
  `vendor/dealerdirect`, no `.git`, no `.github`, no `composer.lock`
- CI's own `release-audit` job independently confirmed: version parity,
  all six required docs present, zip builds, contains the three required
  files, contains no dev-only files — "Release audit passed."

**Tagged and released as `v0.1.0` on 2026-08-02** (tag → `bed7df1`,
Release workflow green,
[GitHub Release](https://github.com/magpern/mp-commerce-fulfillment/releases/tag/v0.1.0)
published with `mp-commerce-fulfillment-0.1.0.zip` attached). The
published asset's SHA-256 differs from the one recorded above — expected,
not a defect: a zip's per-entry timestamps and composer's own
`pretty_version`/`version` provenance stamp (`dev-main` when built from a
branch push vs. `v0.1.0`/`0.1.0.0` when built from the tag itself, as the
Release workflow does) both legitimately change the outer archive's hash
between builds even when every real source file is byte-identical —
verified file-by-file at re-download. See the archive note at the top of
this document.
