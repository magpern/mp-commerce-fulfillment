# Milestone 4 Release Report — Documents I

**Version:** 0.4.0 (release candidate)  
**Branch:** `feature/m4-documents`  
**PR:** https://github.com/magpern/mp-commerce-fulfillment/pull/1 (draft)  
**Status:** Release candidate — **not tagged / not published** pending Product Owner approval.

---

## Executive summary

M4 delivers outbound packing slips and picking lists end-to-end: typed
`DocumentService` orchestration, branding, protected immutable HTML storage,
Workspace actions, Documents history with exact reprint, capped Queue bulk
picking-list print, dogfood, and RC packaging.

Binding constraints held: no carrier APIs, no inventory/receiving/PO, no
mandatory PDF, no silent printing, no Mission Control redesign, no M5+.

---

## Implementation (by phase)

| Phase | Scope | Outcome |
|---|---|---|
| M4-A | Type registry, stage policy, generalized render, template chain | Landed |
| M4-B | Branding, picking list, protected store, integrity + compensation | Landed |
| M4-C | Workspace actions, Shift+P, typed REST render, timeline labels | Landed |
| M4-D | History UI, exact reprint, content stream, Queue bulk cap 25 | Landed |
| M4-E | Dogfood, docs, RC prep | Landed (this report) |

---

## Dogfood round 1 (dev.biopentra.eu)

Service-level scenarios via WP-CLI against live fulfillments (#6 queued,
#4 packed, #3 shipped). **Release blockers: 0.**

| Scenario | Class | Notes |
|---|---|---|
| Queued → print picking list | OK | document stored; latency ~178 ms |
| Artifact readable | OK | 3480 bytes |
| Exact reprint | OK | sha256 match; `document.reprinted` |
| Packed → packing slip | OK | ~174 ms; title present |
| Shipped → reprint | OK | exact HTML match |
| Fresh print preserves old | OK | new id; old file hash stable |
| History list / order search | OK | total≥7; search `6326` hits |
| Bulk cap + partial | OK | succeeded=1; skipped_cap=3; packed ineligible |
| `.htaccess` deny | OK | present under uploads/mpcf |
| Traversal rejection | OK | |
| Ineligible state message | OK | picking list denied in packed |
| Unauthorized | OK | `forbidden` |

### Release blockers fixed

| Blocker | Fix |
|---|---|
| Release ZIP omitted `templates/documents/` (bundled packing-slip + picking-list) | `bin/build-zip.sh` copies `templates/`; `bin/release-audit.sh` requires both template PHP files |

## Deferred / polish (not fixed)

| Finding | Class |
|---|---|
| Manual browser click-through of Workspace Shift+P / History Reprint buttons | Important polish (service path covered; UI paths covered by unit/integration + existing JS) |
| Operator/Lead role matrix exercised via capability unit/integration tests, not full UI login matrix | Important polish |
| Composite DB index for history | Future enhancement |

---

## Print validation (S2)

See `docs/PRINT_VALIDATION.md` M4 Spike S2. Chrome + Firefox A4: packing
slip and picking list — **pass**.

---

## Performance / storage (measured)

| Measurement | Value |
|---|---|
| Picking list render (live) | ~178 ms |
| Packing slip render (live) | ~174 ms |
| History list (limit 50) | ~4 ms |
| Content stream read | ok; bytes=3480 |
| Bulk max | 25 (enforced; overflow skipped) |
| Orphan compensation | covered by unit test (`DocumentServiceStorageCompensationTest`) |

---

## Tests

- Unit suite: **431** OK (local Docker PHP 8.3).
- Focused M4 history/reprint/bulk unit tests added.
- Integration: DocumentsController list/reprint/auth extended; QueuePage ctor wired.
- CI on PR: continues to fail known **baseline** integration issues (picking `409` version conflicts; WorkspaceFlags `trim()` TypeError) — not introduced by M4 document commits; compare against prior `feature/m4-documents` runs.

---

## Security

- Protected store deny-all `.htaccess`.
- Content/reprint require `mpcf_render_documents`.
- Path traversal rejected by `ProtectedDocumentStore::absolute_path`.
- No public direct file URL; no document delete API.

---

## Documentation updated

`ARCHITECTURE_PLAN.md` Part VI · `API.md` · `HOOKS.md` · `PERSISTED_DATA.md` ·
`PRINT_VALIDATION.md` · `ROADMAP.md` · this report · `readme.txt`.

---

## Release candidate packaging

ZIP: `dist/mp-commerce-fulfillment-0.4.0.zip`  
SHA-256: `d2dc8fdbe3a638a76015c4e71e612f0309e9f5b39a1e277da5551c7bb2b511ba`  
Release audit: **passed**.  
Live upgrade (bind-mounted feature branch): plugin reports `0.4.0` active; existing stored documents remain readable (`reprint_survives=yes`). Clean-install: ZIP contains bootstrap, autoload, both templates, `DocumentsPage`, `DocumentHistoryService`, `ProtectedDocumentStore` (audit + zip listing).

**Explicit:** `v0.4.0` is **not** tagged and **not** published without PO approval.
