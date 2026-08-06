# M7 Release Report — Barcode & Scan Mode (`v0.7.0`)

**Baseline:** `v0.6.0` / `main` @ `3e3266b`  
**Schema:** settings **8**, migrator target **6** (unchanged)  
**Status:** **Published** on GitHub. Production **not** deployed. **M7 closed.** **M8 not started.**

## Publication evidence

| Item | Value |
|---|---|
| Final merged commit | `e16e32d` (`Merge pull request #5 from magpern/feature/m7-barcode-scan`) |
| Tag | `v0.7.0` → `e16e32d` |
| GitHub Release | https://github.com/magpern/mp-commerce-fulfillment/releases/tag/v0.7.0 |
| Published asset | `mp-commerce-fulfillment-0.7.0.zip` |
| Local ZIP SHA-256 | `143abf7a40bc94df94ebbb8abbff1cf07e6675e762a94e26833f1a8a6037d503` |
| Published ZIP SHA-256 | `143abf7a40bc94df94ebbb8abbff1cf07e6675e762a94e26833f1a8a6037d503` |
| Release workflow | **Not run** — GitHub Actions major outage ([incident qcvjkzcs7j74](https://www.githubstatus.com/incidents/qcvjkzcs7j74)); release created manually via `gh release create` with release-audited local ZIP |
| PR CI gate | **Waived** for the same Actions outage (PO authorization 2026-08-06); local PHPCS/unit/integration/POT/release-audit + bounded live smoke green |

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
always visible); Scan Mode enter-button sync after AJAX stage change; scan-sink
keystroke buffer accumulation (`buffer +=`).

## Final live smoke (2026-08-06)

Bounded REST smoke on isolated fixture fulfillments: pick ×1 / repeated qty /
over-scan 422 / unknown 422 / stale 409 no replay / pack after pick /
pack-before-pick 422 / undo / `MPCF:I:` resolve / `MPCF:F:` parse / WC stock
unchanged during scan ops. `MPCF_VERSION=0.7.0`, `mpcf_db_version=6`.

## Performance (dev dogfood)

Live `ScanService::scan_pick` on fulfillment `#6` (SKU `STERILE-BW-10ML`)
measured **~26 ms** wall time inside `wp eval` on the WordPress container
(2026-08-06). Target was “feel immediate / &lt;300 ms”; met on this VPS.

## Release artifact

| Artifact | Value |
|---|---|
| ZIP | `mp-commerce-fulfillment-0.7.0.zip` |
| SHA-256 (local = published) | `143abf7a40bc94df94ebbb8abbff1cf07e6675e762a94e26833f1a8a6037d503` |
| Release audit | passed |
| Version triad | `0.7.0` |

## Explicit confirmations

- M7 **closed**; `v0.7.0` merged, tagged, and published on GitHub
- Production **not** deployed
- M8 **not** started
- No inventory/receiving coupling

**Deferred (Important polish / Future):** mandatory camera scanning; EAN
column; live per-package packed qty; wedge timing tuning across scanner
models; Mission Control scannable queue deep-link UX polish beyond document
payload.
