# ADR-0008 — Skip third-party kit-parent order lines by persisted meta only

## Status

Accepted — v1.1 feature, under a narrow Pilot Stabilization Policy exception
scoped to this ADR only (see Consequences). Implemented in a separate PR;
see `docs/plans/UCB_FULFILLMENT_INTEGRATION.md` for the frozen plan.

## Context

A third-party bundle plugin ("Architecture B") can add a **kit parent** order
line (priced, not physically pickable) alongside real, hidden, zero-priced
**component child** order lines that WooCommerce core stocks, reserves, and
reduces normally.

MPCF's `WooOrderSource::line_items()` currently ingests every `line_item` on
an order unconditionally (`src/Woo/WooOrderSource.php:193–214`). Against such
an order, the kit parent becomes a picking row it can never be: an operator
cannot pick a container, and `Engine\Guard\AllItemsPickedGuard` would block
`picking → picked` forever.

Because `line_items()` is the single reader behind intake
(`src/Application/IntakeService.php:170,184–197`), `item_count`
(`src/Application/IntakeService.php:154`), and the `RefundObserver` item-diff
`$live` map (`src/Woo/RefundObserver.php:258–266`), any fix belongs there — not
duplicated per caller.

Full evidence: `docs/plans/UCB_FULFILLMENT_INTEGRATION.md` evidence table
(E1–E18).

## Decision

1. In `WooOrderSource::line_items()`, immediately after the existing
   `WC_Order_Item_Product` type guard, skip a line item whose persisted
   `_ucb_kit` order-item meta is non-empty:

   ```php
   if ( '' !== (string) $item->get_meta( '_ucb_kit', true ) ) {
       continue;
   }
   ```

2. **Persisted-data only.** `_ucb_kit` is used as a literal string. No class,
   constant, hook, autoloader, or activation check from the bundle plugin is
   referenced, per invariant I8 (only `src/Woo/` may name a WooCommerce
   symbol — `get_meta()` is one, the literal key is not).

3. **Presence, not content, is the predicate.** The guard does not parse the
   JSON snapshot or inspect a version field, so an unrecognized future
   snapshot shape still fails closed (the parent is still skipped).

4. **No other change.** No schema migration, no new unique key, no expansion
   or synthetic-row model, no stock-orchestration logic, and no
   `RefundObserver` code change — evidence (E5, E7–E9) shows the existing
   `order_item_id`-keyed diff and the existing schema already produce correct
   behavior once the shared reader is filtered.

5. **Rollout gate.** Because `mpcf_fulfillment_items` rows are write-once at
   intake with no re-sync path (`src/Infrastructure/Database/WpdbFulfillmentItemRepository.php:40–86`),
   a fulfillment created from a kit order *before* this guard is deployed
   keeps an unrepairable phantom parent row and will later be flagged
   `problem` on its first admin save. **No kit product may be enabled for
   sale until this release is deployed.** Sequence: merge → release → deploy
   → only then make a kit product purchasable.

## Rejected alternatives

- **Filtering in `IntakeService::line_items()` instead of `WooOrderSource`.**
  `RefundObserver`'s `$live` map is built from the *same* `OrderSource`, so
  filtering downstream of it would leave the parent id in `$live` but not in
  `$stored`, permanently flagging `problem` on every admin save or refund.
  Rejected as a defect, not a design choice.
- **Architecture A — expand the kit parent into synthetic component rows at
  intake.** Requires a uniqueness/reconciliation model this codebase does not
  have and duplicates ownership of data the bundle plugin already persists on
  real order lines. Rejected; not carried forward into this ADR.
- **Gate on `_ucb_component` instead of `_ucb_kit`.** Inverting the test
  ("only ingest lines carrying `_ucb_component`") would silently exclude
  every ordinary non-kit order line. Rejected for lack of narrowness.
- **Gate on `_ucb_parent_item_id`.** This value is a cart-key hash until the
  bundle plugin's post-save backfill pass runs, so it is not a stable
  predicate at the point line items are read. Rejected.

## Consequences

- Kit parent lines never become picking rows; real component lines are
  ingested, picked, and packed exactly like any other order line.
- Component lines remain fully eligible for fulfillment even when the bundle
  plugin is deactivated, removed, or has fatalled — the marker is WooCommerce
  order-item meta, independent of the plugin that wrote it.
- A standalone purchase of a component product (no kit involved) is
  unaffected — it carries no `_ucb_kit` meta.
- **Feature Freeze exception:** `docs/PILOT_STABILIZATION_POLICY.md` remains
  ACTIVE for all other work. This ADR is the one, explicitly Product-Owner-
  authorized exception, limited to the `_ucb_kit` parent-skip guard and its
  tests. It does not reopen the Pilot to other feature work.
- **Binding rollout gate:** kit products must not be sold until the
  implementation from this ADR is deployed (see Decision §5). This is an
  operational launch dependency, not merely documentation.
- `kit_qty > 1` still sums per-component quantity onto one order line with no
  per-kit-instance traceability — a known limitation of the upstream bundle
  plugin's contract, not something this ADR changes.

## Related

`docs/plans/UCB_FULFILLMENT_INTEGRATION.md` (frozen plan, full evidence and
acceptance criteria); `docs/ARCHITECTURE_PLAN.md` §7 (fulfillment items are
pickable order lines); `docs/COMPATIBILITY.md` (third-party bundle plugin
contract); `docs/PILOT_STABILIZATION_POLICY.md`.
