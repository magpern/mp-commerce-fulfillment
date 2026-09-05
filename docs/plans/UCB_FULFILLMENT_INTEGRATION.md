# Third-party kit fulfillment integration (UCB parent-line skip)

**Status:** Frozen — accepted design, Product-Owner-authorized narrow Feature
Freeze exception (ADR-0008). Implementation tracked in a separate PR.
**Baseline:** `main` `v1.0.1`.
**Target release:** `v1.1.0`.
**Authoritative architecture:** `docs/ARCHITECTURE_PLAN.md`; this plan does
not introduce new architecture beyond ADR-0008.
**Feature Freeze exception:** `docs/PILOT_STABILIZATION_POLICY.md` remains
**ACTIVE**. The Product Owner has granted a **narrow exception**, scoped
exactly to ADR-0008's `_ucb_kit` parent-skip guard and its tests — nothing
else in the Pilot Feature Freeze is affected.
**Rollout gate:** kit products must not be enabled for sale until the
implementation from this plan is deployed (see "Rollout gate" below).

## Context

A third-party bundle plugin ("Architecture B") creates a priced **kit
parent** order line plus real, hidden, zero-priced **component child** order
lines; WooCommerce core owns component stock reservation, reduction, and
restoration. MPCF currently ingests every `line_item` unconditionally, so a
kit parent becomes a picking row it can never physically be, blocking
`picking → picked` via `AllItemsPickedGuard`. This plan integrates the two
without any dependency from MPCF onto the bundle plugin's code.

## Evidence table

All MPCF references are repository-relative, read at `main` `v1.0.1`
(`7b4a223`). All bundle-plugin references are repository-relative within that
plugin's own repository, read at its `main` branch (commit `c0c9dde`, "M1:
fixed-kits core (Architecture B)"). No absolute or deployment-specific path
appears anywhere in this document (invariant I13).

| # | Source (repo-relative) | Observed behavior |
|---|---|---|
| E1 | MPCF `src/Woo/WooOrderSource.php:193–214` | `line_items()` iterates `$order->get_items('line_item')`, skips non-`WC_Order_Item_Product`, emits an `OrderLineSnapshot` per line. No stock / virtual / price / `needs_shipping` / null-product filter. A deleted product yields an empty SKU; the line is still emitted. |
| E2 | MPCF `src/Woo/WooOrderSource.php:55` | `line_items()` is private; its sole call site is `find()`, feeding `OrderSnapshot::create()`. Every consumer reaches it via `OrderSnapshot::items()`. |
| E3 | MPCF `src/Application/IntakeService.php:170, 184–197` | `insert_all()` maps `$order->items()` 1:1 into `FulfillmentItem::intake()` — the only writer of picking rows. |
| E4 | MPCF `src/Application/IntakeService.php:154` | `item_count` = `count( $order->items() )` — same source as E3, stays consistent automatically. |
| E5 | MPCF `src/Woo/RefundObserver.php:258–266` | `flag_item_changes()` builds `$stored` from `mpcf_fulfillment_items` and `$live` from `$order->items()` — i.e. from the same `WooOrderSource::line_items()` (E2). |
| E6 | MPCF `src/Woo/RefundObserver.php:112–117` | Hooks: `woocommerce_order_status_cancelled`, `_fully_refunded`, `_partially_refunded`, `woocommerce_saved_order_items` (2 args). `handle_order_items_saved()` discards the posted `$items` and re-reads via `OrderSource`. |
| E7 | MPCF `src/Infrastructure/Database/Schema.php:265–284` | `mpcf_fulfillment_items`: `PRIMARY KEY (id)`, `KEY fulfillment_id`, `KEY sku_snapshot`. No unique key on `(fulfillment_id, order_item_id)`. Intake idempotency comes from the parent's `order_unique (order_id, order_source)`. |
| E8 | MPCF `src/Infrastructure/Database/WpdbFulfillmentItemRepository.php:40–86` | Items are write-once at intake; `save()` updates only `qty_picked`/`qty_packed`/`location_snapshot` by `id`. No re-sync path. `IntakeService::intake()` short-circuits when a fulfillment already exists, so items are never re-read. |
| E9 | MPCF, grep `order_item_id` across `src/` | Used as an array key in exactly two places, both in `RefundObserver::flag_item_changes()` (E5). Scanning/picking/packing key on `product_id`/`sku_snapshot`/`qty_picked`/`qty_packed` instead. |
| E10 | MPCF `src/Woo/IntakeHooks.php:66–70, 137` | Intake paths: `woocommerce_payment_complete`, `woocommerce_order_status_processing`, Action Scheduler `mpcf_process_intake` (group `mpcf`) — all funnel into `IntakeService::intake()`. |
| E11 | MPCF `src/Cli/BackfillCommand.php:122–134` | `wp mpcf intake backfill` → same `IntakeService::intake()`. No REST route creates a fulfillment. No `wp_schedule_event`/reconcile job exists anywhere. |
| E12 | MPCF, grep across `src/` | No stock-readiness or eligibility gate exists: zero hits for `needs_shipping`, `is_virtual`, `is_downloadable`, `get_stock_quantity`, `is_in_stock`, `managing_stock`. `backordered`/`waiting` are manual-only states. |
| E13 | Bundle plugin `src/Domain/MetaKeys.php`; `src/Woo/OrderConstruction.php:67–74` | Kit **parent** line gets `_ucb_kit` = JSON snapshot `{"v":1,"kit_id":…,"kit_sku":…,"kit_qty":…,"components":[…]}`, written at `woocommerce_checkout_create_order_line_item`. Also a transient `_ucb_temp_cart_key`, deleted in a second pass. |
| E14 | Bundle plugin `src/Woo/OrderConstruction.php:58–65` | **Child** component lines get `_ucb_component` = `'1'`, `_ucb_parent_item_id` (string), `_ucb_snapshot_version` = `'1'`, `_ucb_position`. Children carry no `_ucb_kit`. |
| E15 | Bundle plugin `src/Woo/OrderConstruction.php:77–116` | `_ucb_parent_item_id` is a cart-key hash at line-item-create time, backfilled to the real numeric `order_item_id` only after order creation. Not usable as a predicate. |
| E16 | Bundle plugin `src/Woo/CartConstruction.php:128–137` | Zero-pricing happens in the cart (`set_price('0')` on the child clone); the plugin never touches order-item subtotal/total. The parent carries the full kit price. |
| E17 | Bundle plugin `src/Woo/Presentation.php:28–57` | Children are hidden from display by `woocommerce_order_item_visible` and scoped filters. Raw `$order->get_items('line_item')` still returns real child rows — exactly what MPCF reads. |
| E18 | Bundle plugin `docs/adr/0004-fulfillment-plugin-expansion-and-compatibility-contract.md` | Published contract: *"No expansion. The fulfillment plugin needs exactly one guard: skip the non-pickable parent line… reads only persisted order-item meta — no dependency on this plugin's classes, autoloader, or constants."* Also: *"The change detector needs zero modification"*, and *"fails closed on the parent line."* |

## Design: one guard, in the single shared reader

Add the skip inside `WooOrderSource::line_items()`, immediately after the
existing `WC_Order_Item_Product` type guard:

```php
foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
    if ( ! $item instanceof WC_Order_Item_Product ) {
        continue;
    }

    // A bundle plugin's priced kit parent line is a container, not a
    // pickable thing; its real component child lines are separate line
    // items on the same order and are ingested normally. Persisted meta
    // only — never a class, hook, constant or activation check.
    if ( '' !== (string) $item->get_meta( '_ucb_kit', true ) ) {
        continue;
    }
    …
}
```

`WooOrderSource::line_items()` is the single reader behind every consumer
(E2): intake (E3), `item_count` (E4), and the `RefundObserver` diff (E5) all
share it. Filtering there keeps `$stored` and `$live` symmetric automatically
— no spurious `problem`. All three intake paths plus CLI backfill (E10, E11)
share `IntakeService::intake()`, which shares `OrderSource::find()`, so one
guard covers checkout, status transitions, Action Scheduler retry, and
backfill by construction.

Placing the guard in `IntakeService::line_items()` instead would be a
**defect**: `$live` would still contain the parent id while `$stored` would
not, flagging `problem` on every subsequent admin save or refund. This also
follows invariant I8 — only `src/Woo/` may name a WooCommerce symbol.

### The parent-skip predicate

**Marker:** order-item meta key `_ucb_kit`, present only on the kit parent
line.

**Predicate:** `'' !== (string) $item->get_meta( '_ucb_kit', true )`

- Persisted-data only — no bundle-plugin class, constant, hook, autoloader,
  or `is_plugin_active()` check.
- Version-agnostic / fails closed — skips on presence, not on the `v` field,
  so an unrecognized future snapshot still skips the parent.
- Narrow — a standalone component product carries `_ucb_component` at most,
  never `_ucb_kit` (E14), so it is ingested normally; component children
  inside a kit are ingested normally too.
- Not built on `_ucb_parent_item_id` (a cart hash before the plugin's
  post-save backfill pass, E15) or on `_ucb_component` (inverting the test
  would wrongly exclude every ordinary non-kit line).

### Component lines remain eligible with the bundle plugin absent

The guard reads persisted `woocommerce_order_itemmeta` through WooCommerce
CRUD. If the bundle plugin is deactivated, deleted, or fatals, a historical
kit order still carries `_ucb_kit` on its parent and real meta on its
component lines: **the parent is still skipped and the components are still
ingested and picked correctly, with no bundle-plugin code loaded at all.**

## Rollout gate

`mpcf_fulfillment_items` rows are write-once at intake with no re-sync path
(E8). A fulfillment created from a kit order *before* this guard is deployed
keeps an unrepairable phantom parent row; its first admin save then flags
`problem` (E5). Therefore:

> **Kit products must not be enabled for sale until this fulfillment release
> is deployed.** Sequence: (1) merge and release the MPCF guard, (2) deploy
> it, (3) only then make any kit product purchasable. Backfill/repair of
> fulfillments created before step 2 is explicitly out of scope.

## Migration and RefundObserver: both NOT required

**Schema migration: NOT required.** No column added, removed, or re-typed;
no unique key added or relied upon (E7); `Migrator::TARGET` stays at its
current value. MPCF writes nothing new — it reads a foreign, pre-existing
key. The read dependency is documented in `docs/COMPATIBILITY.md`, not
`docs/PERSISTED_DATA.md` (which inventories keys MPCF *writes*).

**`RefundObserver` change: NOT required.** E5 + E9: the observer's only
order-item keying is `$stored`/`$live` in `flag_item_changes()`, both derived
from the same filtered reader once the guard is in place. Each component
keeps its own stable `order_item_id`, so the existing diff is already
correct for kit orders — matching E18's prediction that "the change detector
needs zero modification." No parent-line expansion, no synthetic child rows,
no `(fulfillment_id, order_item_id)` uniqueness work, and no split-row model
are carried forward from any earlier, rejected design.

## Compatibility and failure-mode analysis

| Scenario | Outcome |
|---|---|
| Bundle plugin active, normal kit order | Parent skipped; N components → N picking rows |
| Bundle plugin deactivated/deleted before intake (AS retry fires later) | Identical — meta is persisted, guard still matches |
| Bundle plugin fatals mid-request | MPCF unaffected; guard is a meta read |
| Order predating the bundle plugin | No `_ucb_kit` anywhere; behavior byte-identical to today |
| Standalone purchase of a component product | No `_ucb_kit`; ingested normally |
| `kit_qty > 1` | Child line carries summed `qty = kit_qty × qty_per_kit` (known upstream limitation); MPCF picks the summed quantity correctly, with no per-kit-instance traceability |
| Admin order save on a kit fulfillment | `$stored`/`$live` symmetric ⇒ no diff ⇒ no `problem` |
| Admin refund on a kit order | Also fires `woocommerce_saved_order_items`; same symmetry holds |
| Full refund / cancellation | Untouched — `propose_cancel_or_flag()` never reads items |
| Partial refund | Untouched — unconditionally flags `problem` today, by existing design |
| A different bundle plugin using a different marker | Out of scope; this guard is deliberately specific and narrow |
| Kit sold before the guard is deployed | Phantom parent row, unrepairable in place — prevented by the rollout gate, not by code |
| Kit order where every line is a parent (impossible today) | Fulfillment with 0 items — already tolerated by intake (E12) |

**Stock-readiness prerequisite: none exists (E12).** There is nothing to
remove or relax in MPCF.

## Scoped implementation work package

**W1 — Guard.** Four lines in `src/Woo/WooOrderSource.php::line_items()` plus
a docblock paragraph documenting the persisted-data-only contract.

**W2 — Docs.** `docs/COMPATIBILITY.md`: new "Third-party bundle plugins"
section naming `_ucb_kit` as a read-only external contract and restating the
rollout gate. `docs/ARCHITECTURE_PLAN.md` §7 amendment clarifying that
fulfillment items are *pickable* order lines. This plan and ADR-0008's status
lines updated to reflect implementation state once the implementation PR
exists.

**W3 — Tests.** Extend `tests/unit/Application/IntakeServiceTest.php` with a
fixture proving `item_count` equals the number of inserted rows for a
filtered set. Add `tests/integration/Woo/WooOrderSourceKitLineTest.php` and
extend `RefundObserverTest.php` / `IntakeHooksTest.php`, all seeding
`_ucb_kit` / `_ucb_component` meta by hand — the suite never requires the
bundle plugin installed.

| AC | Assertion |
|---|---|
| AC1 | One kit + 3 components → exactly 3 `mpcf_fulfillment_items` rows, none with the parent's `order_item_id`; `item_count === 3` |
| AC2 | Two kits sharing a component → each kit's child line keeps its own `order_item_id` and quantity; rows are not merged |
| AC3 | Ordinary non-kit order → row set byte-identical to current `main` |
| AC4 | Historical kit order with no bundle plugin loaded (meta seeded directly) → parent skipped, components ingested |
| AC5 | `woocommerce_saved_order_items` on an unchanged kit fulfillment → no transition, no event, state stays `picking` |
| AC6 | `woocommerce_order_partially_refunded` and `woocommerce_order_status_cancelled` on a kit order → unchanged behavior vs a non-kit order |
| AC7 | Action Scheduler `mpcf_process_intake` retry path yields the identical row set as the synchronous path |
| AC8 | A standalone order line carrying `_ucb_component` but no `_ucb_kit` → is ingested (predicate narrowness) |
| AC9 | Parent line with an unknown `_ucb_kit` version (`{"v":99,…}`) → still skipped (fails closed) |
| AC10 | `AllItemsPickedGuard` allows `picking → picked` once the 3 component rows are picked |

**W4 — Validation environment.** Any execution runs in a disposable Docker
stack per the project's local (gitignored) tooling notes — no bind-mount of,
and no write to, any served environment.

## Non-goals (explicit)

Promotions; host MU-plugin guard deployment; any change to the bundle
plugin; custom stock orchestration (WooCommerce core owns component stock);
catalogue/kit setup; deployment to a served environment; support for bundle
plugins other than this one; backfill/repair of fulfillments created before
the guard; per-kit-instance traceability when `kit_qty > 1`.

## Verification of this plan's own PR

```bash
git diff --stat origin/main   # docs/ only

CHANGED=$(git diff --name-only origin/main)
grep -nEi 'biopentra|/opt/|/home/|[A-Za-z]:\\\\|https?://(dev|www)\.' $CHANGED && exit 1
echo "OK: no absolute or deployment-specific references"
```

Covers every changed file, including `docs/adr/README.md`.
