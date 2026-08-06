# M8 — Wave & Batch Picking — Milestone Implementation Plan

**Status:** Planning complete — **awaiting PO approval of Architecture Plan Part X** before any implementation branch work.  
**Baseline:** `main` / `v0.7.0` (M7 closed).  
**Target release:** `v0.8.0`.  
**Authoritative architecture:** `docs/ARCHITECTURE_PLAN.md` **Part X**.  
**This file:** execution checklist and acceptance surface for implementers. It does not introduce architecture beyond Part X.

---

## 1. Goals

1. One operator walks the warehouse **once** and picks **many** fulfillments (a **Wave**).
2. Combined picking list / walk model grouped by immutable `location_snapshot` hints + SKU.
3. Extend M7 Scan Mode for wave picking (keyboard-wedge); no separate scanner product.
4. Wave ends when members are **`picked`**; packing remains per-fulfillment (M2–M7 Workspace).
5. Honor ADR-0007: no inventory / receiving / stock / location-master ownership.

## 2. Architecture summary

| Layer | M8 addition |
|---|---|
| Domain | `Wave`, `WaveMember`, `WaveState`, walk/allocation VOs |
| Application | `WaveService` (lifecycle), `WaveWalkBuilder`, `WaveScanService` → `PackingService` |
| Infrastructure | `mpcf_waves`, `mpcf_wave_members` (migrator target **7**); repositories |
| API | `/mpcf/v1/waves…` (+ `/scan`, `/walk`, documents) |
| Documents | `wave_picking_list` assembler/template; optional `MPCF:W:{id}` |
| Admin | Wave Workspace + Queue “create/add to wave”; Wave Scan Mode UI extending `scan.js` |

**Operation Context:** documented in Part X.8 — **not** implemented as a framework in M8. Pass `wave_id` explicitly.

## 3. Data ownership & boundaries

**MPCF owns:** wave lifecycle, membership, walk presentation, wave scan mutations of `qty_picked`, workflow transitions to `picked`, wave documents, audit.

**MPCF does not own:** suppliers, POs, receiving, stock ledger, bins/shelves as master data, cycle count, carrier batching, packing batching.

**Hints only:** `location_snapshot`, `warehouse_id`, existing SKU/product/variation snapshots.

## 4. Phase breakdown

### M8-A — Wave domain

- [ ] Part X PO-approved
- [ ] Migrator target 7: `mpcf_waves`, `mpcf_wave_members`
- [ ] Domain state machine + exclusivity rules
- [ ] `WaveService` create / members / activate / pause / resume / complete / abandon
- [ ] REST lifecycle + list/get
- [ ] Unit + integration tests
- [ ] `PERSISTED_DATA.md` / `HOOKS.md` / `API.md` stubs

**Exit:** Can create and activate a wave of real fulfillments via REST; no scan UI yet.

### M8-B — Combined picking documents

- [ ] `WaveWalkBuilder` (group/sort/allocate model)
- [ ] `wave_picking_list` document pipeline (M4 patterns)
- [ ] Additive barcode type `W` in parser (if printing wave id)
- [ ] Unit tests for grouping, NULL location sort, variation non-collapse

**Exit:** Printable combined walk for an active wave.

### M8-C — Batch / Wave Scan Mode

- [ ] `WaveScanService` + `POST /waves/{id}/scan`
- [ ] FIFO multi-order SKU allocation; M7 ambiguity rules within one fulfillment
- [ ] Undo scoped to wave; 409 stale wave/fulfillment versions
- [ ] Extend Workspace scan UI for wave mode (reuse sink)
- [ ] Browser tests

**Exit:** Full walk completable by scan alone on a ≥5-member wave.

### M8-D — Workspace

- [ ] Wave dashboard (progress, remaining, completed, exceptions)
- [ ] Pause / resume / abandon UX
- [ ] Queue selection → create wave
- [ ] Deep link open member fulfillment for exceptions
- [ ] Completion summary → handoff messaging (“continue packing per order”)

**Exit:** Operator-complete UX without raw REST.

### M8-E — Hardening & release

- [ ] Dogfood 20–50 order waves on dev
- [ ] Optional idle auto-pause
- [ ] Guards: inventory coupling, domain purity
- [ ] Docs finalize; version `0.8.0`; ZIP + release-audit; PR
- [ ] PO GO → merge/tag/publish (no silent prod deploy)

## 5. Acceptance criteria

See Part X.14. Minimum dogfood script:

1. Select 5+ queued orders → create wave → activate.
2. Print wave picking list; confirm location sort + SKU aggregation.
3. Wave Scan Mode: pick shared SKU across two orders → FIFO allocation.
4. Over-scan and unknown reject; pause/resume survives reload.
5. All members `picked`; pack one member in normal Workspace.
6. Confirm WooCommerce stock unchanged by scan path; no inventory plugin reads.

## 6. Validation & testing

| Tier | Focus |
|---|---|
| Unit | State machine, walk builder, FIFO allocator, parser `W` |
| Integration | REST, membership exclusivity, scan + workflow to `picked`, documents |
| Browser | Wave scan happy path + pause/resume |
| Structural | ADR-0007 / no stock writes in wave packages |
| Release | PHPCS, POT, matrix CI, release-audit, version triad |

## 7. Release strategy

- Branch: `feature/m8-wave-batch-picking` from current `main` / after `v0.7.0`.
- Version bump only in M8-E.
- Tag `v0.8.0` only on explicit PO GO.
- M9 must not start until M8 closed.

## 8. Risks

| Risk | Mitigation |
|---|---|
| Operators want chooser for multi-order SKU | Part X forbids in M8; measure dogfood; ADR if needed |
| Huge waves degrade UX | `wave_max_members` + warn at 50+ |
| Partial unique “one open wave per fulfillment” on MySQL | Application guard + integration test |
| Scope creep into Mission Control | Explicit non-goal; Queue entry points only |

## 9. Out of scope reminder

Inventory, receiving, RF/mobile, camera-mandatory, packing batching, carrier batching, Mission Control redesign, Analytics (M9), location master data.
