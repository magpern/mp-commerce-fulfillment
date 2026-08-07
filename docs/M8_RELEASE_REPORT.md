# M8 Release Report — Wave & Batch Picking (`v0.8.0`)

**Baseline:** `v0.7.0` / branch start `b277086`  
**Schema:** settings **9**, migrator target **7** (`mpcf_waves`, `mpcf_wave_members`)  
**Status:** **Released.** M8 closed. **M9 not started.** Production not deployed.

## Architecture delivered

| Area | Delivered |
|---|---|
| Domain | `Wave`, `WaveMember`, `WaveState`, `WaveWalkBuilder` |
| Application | `WaveService`, `WaveScanService` → `PackingService` absolute +1 |
| Infrastructure | Migrator step 7; `WpdbWaveRepository` optimistic version |
| REST | `/mpcf/v1/waves…` lifecycle + `/walk` + `/scan` + `/documents` |
| Documents | `wave_picking_list` + `MPCF:W` barcode type |
| Admin | Wave Workspace (`mpcf-wave`) + Queue create-wave |
| Caps | Reuse `mpcf_process_fulfillments` / `mpcf_render_documents` |
| Operation Context | Documented only — not implemented as a framework |

## Decisions (binding)

| Topic | Decision |
|---|---|
| Ends at | `picked` per member; packing stays per-fulfillment |
| Multi-order SKU | Deterministic FIFO (`created_at ASC`, then `item_id`) — no chooser |
| Location | `location_snapshot` sort hint only (NULLS LAST) |
| Ownership | Exclusive owner on active/paused waves |
| Inventory | No inventory/receiving/stock/`wc-inventory-overview` coupling (ADR-0007) |

## Validation evidence

| Check | Result |
|---|---|
| Unit | **582** tests, **1857** assertions (4 skipped) — green |
| Integration | Matrix green on PR CI (floor/current/mixed/ceiling) |
| PHPCS | **0** errors — green |
| POT | Regenerated — green |
| Release-audit | Passed (+ zip) |
| Browser Playwright | `tests/browser/wave-scan.spec.js` green on chromium + firefox |
| CI / GitHub Actions | PR **#6** full matrix green at `f4f1803` (final feature HEAD); Release workflow success |

## Bounded dev-wave dogfood

Script: `bin/dogfood-m8-wave.php` on bind-mounted dev WP (`wp eval-file`).

| Step | Result |
|---|---|
| Create wave with **5** fulfillments, shared SKU, qty **2** each | `wave_id=2`, `members=5` |
| Activate | `state=active` |
| Combined walk | `walk_rows=1` (shared SKU aggregated) |
| FIFO scans | first two picks → fulfillment **18**; third → **19** |
| Pause / resume | `paused` → `active` |
| Undo | `result=corrected` |
| Complete remaining picks | all five members `picked`; over-scan rejected |
| Wave complete | `state=completed` |
| Packing per fulfillment | sample transitions include `packing` |
| Woo stock after orders vs after wave | **490 → 490** (no mutation during wave ops) |

`DOGFOOD_OK`

## Performance notes

Walk model built in PHP from one members→items hydration. Scan path is one
`PackingService` absolute update. Cap via `wave_max_members` (default 25,
max 100). Wave UI serializes scans with a request lock and applies response
versions before background reload.

## Release publication

| Field | Value |
|---|---|
| Merge commit | `961ccf964f3660b8d7ca2423c0b8c9cd4cec5e98` |
| Tag | `v0.8.0` → `961ccf9` |
| GitHub Release | https://github.com/magpern/mp-commerce-fulfillment/releases/tag/v0.8.0 |
| Release workflow | https://github.com/magpern/mp-commerce-fulfillment/actions/runs/31158613444 — **success** |
| Published asset | `mp-commerce-fulfillment-0.8.0.zip` |
| Local SHA-256 (pre-publish rebuild) | `58fa9070f67a9df1e4f6b7820c83ee6afba96bcabcedbede4b195e5ebea46c4d` |
| Published SHA-256 | `4782851fe7fd727af74f92c309f8a882c75745ac17c4dab1a3de34813b588a14` |
| SHA delta | Expected archive/Composer metadata differences only; version parity and M8 runtime paths verified in published ZIP |

## Explicit confirmations

- Merged to `main` via PR #6 (merge commit)
- Tagged `v0.8.0` on merge commit
- Published to GitHub Releases
- **Not** deployed to production
- **M8 closed**
- **M9 not started**

## Deferred

- Idle auto-pause (`wave_idle_pause_minutes` stored; policy optional)
- Multi-picker waves / lead override
- Operator chooser for multi-order SKU (post-M8 ADR if dogfood requires)
- Operation Context framework
- Mission Control redesign
- Full operator dogfood of 20–50-order waves on live packing floor

## CI fixes during Conditional GO

| Class | Fix |
|---|---|
| M8 production defect | WavePage called nonexistent `render_footer()` → shell open/close like Workspace |
| M8 production defect | Wrong scan event + missing scan-sink; callable `api()`; scan request lock |
| M8 test defect | wave-scan.spec skipped env / click interception / over-scan waits |
| Release hygiene | POT refresh after WavePage string changes |
