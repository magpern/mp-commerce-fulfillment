# M8 Release Report — Wave & Batch Picking (`v0.8.0` RC)

**Baseline:** `v0.7.0` / branch start `b277086`  
**Schema:** settings **9**, migrator target **7** (`mpcf_waves`, `mpcf_wave_members`)  
**Status:** **RC on `feature/m8-wave-batch-picking`**. Not merged, not tagged,
not published, not deployed to production. **M9 not started.**

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

## Validation evidence (RC)

| Check | Result |
|---|---|
| Unit | **582** tests, **1857** assertions (4 skipped) — green |
| Integration | **267** tests, **940** assertions — green (`mpcf-test-net` / `wordpress_test`) |
| PHPCS | **0** errors / **377** files — green (`php:8.1-cli`) |
| POT | Regenerated (`languages/mp-commerce-fulfillment.pot`) |
| Release-audit | Passed inside `php:8.1-cli` (+ zip) after `composer install --no-dev` |
| Dogfood smoke | `wp eval-file …/bin/smoke-m8-wave.php` → migrator 7, settings 9, tables, doc type, `W`, `0.8.0` **OK** |
| CI / GitHub Actions | **Not run** on this RC (local Docker gates only; PR CI when opened) |
| Browser Playwright | Spec added (`tests/browser/wave-scan.spec.js`); full Playwright run not required for this RC gate |

## Performance notes

Walk model built in PHP from one members→items hydration (no N+1 in the
builder). Scan path is one `PackingService` absolute update. Cap via
`wave_max_members` (default 25, max 100).

## Release artifact

| Field | Value |
|---|---|
| ZIP | `dist/mp-commerce-fulfillment-0.8.0.zip` |
| SHA-256 | `871d16d2d9f8d343758a71a5f94c2c38174e4bcd48d178b22a9c9cc2edba414e` |

## Explicit confirmations

- Not merged to `main`
- Not tagged `v0.8.0`
- Not published to GitHub Releases
- Not deployed to production
- M9 not started

## Deferred

- Idle auto-pause (`wave_idle_pause_minutes` stored; policy optional)
- Multi-picker waves / lead override
- Operator chooser for multi-order SKU (post-M8 ADR if dogfood requires)
- Operation Context framework
- Mission Control redesign
- Full operator dogfood of 20–50-order waves on live packing floor
