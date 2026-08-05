# Milestone 4 Release Report — Documents I

**Released:** 2026-08-05  
**Version:** 0.4.0  
**Status:** ✅ Production Ready (plugin artifact published; production deploy not part of this release)

**Tag:** `v0.4.0`  
**Merged commit:** `ed7c3b9`  
**PR:** https://github.com/magpern/mp-commerce-fulfillment/pull/1 (rebase-merged)

---

## Executive summary

M4 delivers outbound packing slips and picking lists end-to-end: typed
`DocumentService` orchestration, branding, protected immutable HTML storage,
Workspace actions, Documents history with exact reprint, capped Queue bulk
picking-list print, dogfood, and published release packaging.

Binding constraints held: no carrier APIs, no inventory/receiving/PO, no
mandatory PDF, no silent printing, no Mission Control redesign, no M5+.

PO approved GO 2026-08-05 after the M4 integration-test regression gate
passed (test defect, not production defect).

---

## Implementation (by phase)

| Phase | Scope | Outcome |
|---|---|---|
| M4-A | Type registry, stage policy, generalized render, template chain | Landed |
| M4-B | Branding, picking list, protected store, integrity + compensation | Landed |
| M4-C | Workspace actions, Shift+P, typed REST render, timeline labels | Landed |
| M4-D | History UI, exact reprint, content stream, Queue bulk cap 25 | Landed |
| M4-E | Dogfood, docs, RC prep, publish | Landed / closed |

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
| Release ZIP omitted `templates/documents/` | `bin/build-zip.sh` copies `templates/`; audit requires both template PHP files |
| CI `DocumentsControllerTest` `Undefined array key "items"` | Integration test used `?doc_type=` in the route path; fixed with `set_query_params` (`9e61f18` / `ed7c3b9`) |

## Deferred / polish (not fixed)

| Finding | Class |
|---|---|
| Manual browser click-through of Workspace Shift+P / History Reprint buttons | Important polish |
| Operator/Lead role matrix exercised via capability unit/integration tests, not full UI login matrix | Important polish |
| Composite DB index for history | Future enhancement |
| Baseline CI: picking `409` + WorkspaceFlags `trim()` | Pre-existing on `main`; not M4 regressions |

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
- Integration: DocumentsController list/reprint/auth green after query-param fix.
- CI vs `main`: identical baseline failures only (picking `409` ×5; WorkspaceFlags `trim()` ×3).

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

## Release artifacts

| Property | Value |
|---|---|
| **Tag** | `v0.4.0` |
| **Commit** | `ed7c3b9` |
| **Released** | 2026-08-05 |
| **GitHub Release** | https://github.com/magpern/mp-commerce-fulfillment/releases/tag/v0.4.0 |
| **Release workflow** | https://github.com/magpern/mp-commerce-fulfillment/actions/runs/31025832130 (**success**) |
| **Installable ZIP** | `mp-commerce-fulfillment-0.4.0.zip` |
| **Version parity** | header / `MPCF_VERSION` / Stable tag = `0.4.0` |
| **Local Build SHA-256** | `996cb581534ffb1cd68fd96cc4ac7fb367b5811dcd4d1e3e338698842c4fbce3` |
| **Published ZIP SHA-256** | `75383506f0eb42f1ae30b30146554fe5e553f9ea5120a6a29d3db792177309a1` |
| **Templates present** | `packing-slip.php`, `picking-list.php` |
| **Protected storage present** | `ProtectedDocumentStore.php` |
| **Prohibited artifacts** | none (no phpunit/tests/node_modules/playwright) |

**Note:** Local vs published ZIP SHA-256 differ. File-level comparison shows
identical plugin sources; only Composer autoload metadata differs
(`autoload_classmap.php`, `autoload_static.php`, `installed.php`) because
the Release workflow runs `composer install --optimize-autoloader`. Archive
timestamps also differ. Content verification confirms correctness.

---

## Milestone status

**M4 — Documents I is closed.** Tag `v0.4.0` published. M5 has not started.
