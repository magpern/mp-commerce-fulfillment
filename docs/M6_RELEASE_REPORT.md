# M6 Release Report — Package Photography (`v0.6.0`)

**Status:** **M6 closed.** Tagged and published as GitHub Release `v0.6.0`.  
**Production was not deployed.**  
**M7 has not started.**

## Publication record

| Field | Value |
|---|---|
| Feature RC HEAD | `d84b374` |
| Merged PR | [#4](https://github.com/magpern/mp-commerce-fulfillment/pull/4) |
| Merge commit (main) | `994182c08b6e02074204629e96a038a23f851852` |
| Annotated tag | `v0.6.0` → `994182c` |
| GitHub Release | https://github.com/magpern/mp-commerce-fulfillment/releases/tag/v0.6.0 |
| Published asset | `mp-commerce-fulfillment-0.6.0.zip` |
| Release workflow | [31116462017](https://github.com/magpern/mp-commerce-fulfillment/actions/runs/31116462017) — **success** |
| Local ZIP SHA-256 (pre-tag rebuild at `d84b374`) | `39814391322124db4ebe2edf8454ba79b076ccf354942377de47a5a5c3960810` |
| Published ZIP SHA-256 | `0be4e8e1a49210e505d395c28d5de51a6888b40c3174325540ebda0a970c1c3c` |

Local vs published: same member set (272 entries); SHA differs only by expected archive/Composer packaging metadata from the Release workflow rebuild.

## Implementation summary

| Slice | Outcome |
|---|---|
| M6-A | `mpcf_media`, PhotoRecord/PhotoKind, ProtectedPhotoStore, GD pipeline, PhotoService, soft-delete, `photo.captured`/`photo.deleted`, requirement semantics |
| M6-B | Photo REST (list/upload/meta/content/thumb/delete), optimistic concurrency, packing→packed `photo_required` guard |
| M6-C | Workspace capture/gallery/preview/delete, merchant photo settings (`SCHEMA_VERSION` 8), browser coverage |
| M6-D | Retention eligibility + `PhotoRetentionService`, daily Action Scheduler purge (`mpcf` group), `photo.purged`, CS Fulfillment Detail gallery, docs + `0.6.0` release |

## Retention policy

- Setting: `photos_retention_months` (0–120). **0 = retain indefinitely.**
- Eligibility (UTC): `purged_at IS NULL` AND `created_at <= now − N months`.
- Soft-deleted **and** active photos are eligible when aged.
- Purge removes canonical + thumbnail **bytes**, clears relative paths, sets `purged_at`.
- Preserves: row, SHA-256, processing_version, dimensions, MIME, capture metadata, `deleted_at`, audit chain, fulfillment history.
- Does **not** hard-delete metadata or audit events.
- Residual risk (documented): filesystem delete and DB/audit are not one atomic transaction; missing files are recovered idempotently on retry.

## Scheduling

- Hook: `mpcf_purge_photo_retention`
- Transport: Action Scheduler group `mpcf` (architecture D12; mirrors intake)
- Cadence: daily; batch size 50; transient overlap lock (15 min)
- No merchant “Purge now” UI

## CS gallery

- Read-only section on existing Fulfillment Detail (`mpcf-fulfillment-detail`)
- Grouped by package; soft-deleted hidden; purged shown as metadata-only copy
- Thumbnails/preview via protected REST; no paths/SHA in UI; no upload/delete

## Security validation

- Structural guards updated (`MediaFoundationGuardTest`)
- Purge candidates only from repository metadata; path containment before delete
- No Media Library registration; no public URLs; cron not externally triggerable
- CS gallery capability: existing `mpcf_view_queue` Detail access

## Dogfood findings

Walkthrough executed against unit/integration/browser evidence on this branch:

| Step | Result | Class |
|---|---|---|
| photos_required + contents then package | Guard blocks until sealed package photo (browser `photos.spec.js`) | Pass |
| Preview / Escape | Native dialog lightbox | Pass |
| Lead soft-delete re-blocks | Browser + unit | Pass |
| CS Detail gallery | Integration `FulfillmentDetailPhotosTest` | Pass |
| Artificial age + purge_batch | Unit `PhotoRetentionServiceTest` | Pass |
| Bytes removed / metadata kept / `photo.purged` | Unit | Pass |
| No stock/inventory/receiving coupling | Guard regex | Pass |
| Direct public URL to uploads | ADR-0004 deny rules + no public URL emission | Pass |

**Release blockers fixed during M6-D:** none beyond implementing the planned surfaces.  
**Important polish (deferred):** live multi-minute phone-image timing on production-like hardware.  
**Future enhancement:** Site Health disk warning (M9), privacy exporter/eraser (M9).  
**Out of scope:** M7 barcode, returns photography, OCR/AI, customer portal.

## Performance / storage

Invented numbers are forbidden. Measured in this RC:

- Unit purge of in-memory fixtures: sub-second per batch of 2–3 photos (PHPUnit wall clock).
- Representative phone JPEG upload/processing timing and gallery render with many packages were **not** instrumented on a dedicated load host in this RC; defer to post-merge dogfood on the PO’s staging site with real camera captures.
- After successful purge, FakePhotoStorage/ProtectedPhotoStore no longer hold bytes for purged relatives; metadata row remains.

## Tests

- Unit: eligibility, retention service, settings retention=0, labels, resource `purged`/`has_bytes`, foundation guards
- Integration: CS Detail gallery active/deleted/purged rendering
- Browser: existing M6-C `photos.spec.js` (packing capture/requirement/delete)
- Full configured CI green on PR #4 before merge

## Version / ZIP

- Version triad: `0.6.0` (plugin header, `MPCF_VERSION`, `readme.txt` Stable tag)
- Schema settings shape: **8**
- Migrator target: **6** (`mpcf_media`)
- Install/upgrade/rollback: clean install of ZIP; upgrade from `0.5.0` keeps `mpcf_media` + files; rollback to `0.5.0` does not delete photo files/metadata (older code ignores retention schedule / CS gallery)

## Confirmation

- M6 **closed** (merged + tagged + GitHub Release published)
- Production **not** deployed
- M7 **not** started
- No inventory/receiving/PO coupling
