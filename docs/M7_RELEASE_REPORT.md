# M7 Release Report — Barcode & Scan Mode (`v0.7.0` RC)

**Branch:** `feature/m7-barcode-scan`  
**Baseline:** `v0.6.0` / `main` @ `3e3266b`  
**Schema:** settings **8**, migrator target **6** (unchanged)  
**Status:** Release candidate — **not merged, tagged, published, or production-deployed**. M8 not started.

## Decisions (repository-grounded)

| Topic | Decision |
|---|---|
| Modes | Picking Scan / Packing Scan inside existing Workspace only |
| Payloads | `MPCF:F|I|P|PR|V:{id}` + plain SKU (`sku_snapshot`) |
| Documents | Code 128 SVG of `MPCF:F:{id}`; human order number beside it; picking-list lines include `MPCF:I:{id}` |
| Mutations | `ScanService` → `PackingService` absolute +1; pack ≤ picked |
| Undo | Transient per operator/fulfillment (no scan-session table) |
| Packages | `MPCF:P:` switches active package only — no live allocation redesign |
| Caps | Reuse `mpcf_process_fulfillments` |
| Camera | Not required (keyboard-wedge baseline) |
| Inventory | No `wc-inventory-overview` reads/writes (ADR-0007) |

## Dogfood classification (dev)

Exercised via unit/integration assertions and live bind-mount on
`dev.biopentra.eu` WordPress container (plugin path mounted).

| # | Scenario | Class |
|---|---|---|
| 1–4 | Pick qty 1 / multi / SKU / variation resolve | Covered by unit tests — Release OK |
| 5–8 | Unknown / wrong-order / ambiguous / over-scan | Covered — Release OK |
| 9 | Stale version → 409 | Covered — Release OK |
| 10–11 | Pack after pick / pack before pick | Covered — Release OK |
| 12 | Package switch ownership | Covered — Release OK |
| 13 | Manual controls outside Scan Mode | Unchanged packing.js path — OK |
| 14 | Audit events | `scan.item_*` + `items.*` — OK |
| 15–16 | Document barcodes | Assembler + SVG renderer tests — OK |
| 17 | Permissions | Same PROCESS_FULFILLMENTS — OK |
| 18 | No stock mutation | Structural — no inventory services called — OK |

**Release blockers fixed during M7:** ScanResolution method name collision;
autoload bootstrap require for correction-store double; pack-after-pick test
version after in-memory state save; Scan Mode panel visibility (enter buttons
always visible).

## Performance (dev dogfood)

Live `ScanService::scan_pick` on fulfillment `#6` (SKU `STERILE-BW-10ML`)
measured **~26 ms** wall time inside `wp eval` on the WordPress container
(2026-08-06). Target was “feel immediate / &lt;300 ms”; met on this VPS.

## Release artifact

| Artifact | Value |
|---|---|
| ZIP | `dist/mp-commerce-fulfillment-0.7.0.zip` |
| SHA-256 | `005e6ea92097adb8378d498e3eda4ad26dab56892ce694e302439d9fe64d019a` |
| Release audit | passed |
| Version triad | `0.7.0` |

## Explicit confirmations

- `v0.7.0` not merged/tagged/published
- Production not deployed
- M8 not started
- No inventory/receiving coupling

**Deferred (Important polish / Future):** mandatory camera scanning; EAN
column; live per-package packed qty; wedge timing tuning across scanner
models; Mission Control scannable queue deep-link UX polish beyond document
payload.

- `v0.7.0` not merged/tagged/published
- Production not deployed
- M8 not started
- No inventory/receiving coupling
