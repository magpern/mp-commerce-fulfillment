# M6 Release Candidate Report — Package Photography (`v0.6.0`)

**Status:** Release candidate prepared on `feature/m6-package-photography`.  
**Not tagged / not published / not deployed** pending Product Owner approval.  
**Do not begin M7.**

## Implementation summary

| Slice | Outcome |
|---|---|
| M6-A | `mpcf_media`, PhotoRecord/PhotoKind, ProtectedPhotoStore, GD pipeline, PhotoService, soft-delete, `photo.captured`/`photo.deleted`, requirement semantics |
| M6-B | Photo REST (list/upload/meta/content/thumb/delete), optimistic concurrency, packing→packed `photo_required` guard |
| M6-C | Workspace capture/gallery/preview/delete, merchant photo settings (`SCHEMA_VERSION` 8), browser coverage |
| M6-D | Retention eligibility + `PhotoRetentionService`, daily Action Scheduler purge (`mpcf` group), `photo.purged`, CS Fulfillment Detail gallery, docs + `0.6.0` RC |

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
- Full unit suite green locally (540 tests at RC prep)

## Version / ZIP

- Version triad: `0.6.0` (plugin header, `MPCF_VERSION`, `readme.txt` Stable tag)
- Schema settings shape: still **8** (no new settings keys in M6-D)
- Migrator target: still **6** (`mpcf_media`)
- Build: `bash bin/build-zip.sh` → `dist/mp-commerce-fulfillment-0.6.0.zip`
- SHA-256: `7a7eb2725366b800d88808259878418f55b609ed6cf330244f4dd48a561fea4d`
- Install/upgrade/rollback: clean install of ZIP; upgrade from `0.5.0` keeps `mpcf_media` + files; rollback to `0.5.0` does not delete photo files/metadata (older code ignores retention schedule / CS gallery)

## Confirmation

- `v0.6.0` **not** tagged or published
- Production **not** deployed
- No M7 work
- No inventory/receiving/PO coupling
