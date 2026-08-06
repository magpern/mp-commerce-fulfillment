# REST API reference

`mpcf/v1` — introduced in Milestone 2 (Architecture Plan §IV.9, D6, I11,
§16.2). This is the same public surface the Packing Workspace itself runs
on; there is no privileged admin side-channel. Frozen additive-only from
the `v0.2.0` tag (§4 governance) — routes, response fields and error codes
listed here will never be removed or reshaped within the `1.x` line; new
routes and new optional fields may be added.

Every controller is a thin translation from the wire shape to an
`Application\*` service call — the identical objects the Queue, Fulfillment
Detail and (from Milestone 2) the Packing Workspace call. A transition
submitted through this API and one submitted from the Fulfillment Detail
screen produce identical database and audit outcomes (§IV.15 criterion 2).

## Authentication

- **Cookie + nonce** — the same session an admin screen uses. Send
  `X-WP-Nonce` (from `wp_create_nonce( 'wp_rest' )` or the value WordPress
  already localizes for admin JS via `wp_rest_settings`) on every request.
  WordPress itself rejects a cookie-authenticated request with a missing
  or stale nonce before any `mpcf/v1` code runs.
- **Application Passwords** — WordPress core's own mechanism
  (Users → Profile → Application Passwords). Authenticate with HTTP Basic
  auth using the generated credential; no `mpcf`-specific code is involved.
  Scoped API keys (a credential limited to specific `mpcf_*` capabilities)
  are a post-1.0 idea, not implemented.

Every route requires at least `mpcf_view_queue`; mutating routes require
the specific capability listed in the route table below. Capabilities are
checked with the acting user's real WordPress capabilities — an
Application Password authenticates as the underlying user, so the same
role-based access control applies either way.

## Conventions

- **Namespace.** Every route below is prefixed with `/wp-json/mpcf/v1`
  (omitted from the tables for brevity).
- **JSON in, JSON out.** Send `Content-Type: application/json` (or a
  standard form-encoded body — WordPress's REST server accepts either).
  Every response body is JSON.
- **Optimistic-lock `version`.** Every fulfillment-scoped mutation
  (`POST .../transitions`, `PUT .../items`, `POST .../photos`,
  `DELETE /photos/{id}`) requires the fulfillment's current `version` in
  the request body. A mismatch — someone else changed the fulfillment
  since you last read it — returns `409 mpcf_version_conflict` and
  changes nothing. Shipment/package routes
  (`PATCH/DELETE /shipments/{id}`, `PATCH/DELETE /packages/{id}`, …) and
  `PUT/DELETE /fulfillments/{id}/assignment` do not require a
  caller-supplied `version` — their own optimistic lock is enforced
  internally against whatever the server just read, the same guarantee a
  version check gives you, without asking the caller to track a second
  version number for shipments/packages, which carry none of their own
  (Architecture Plan §IV.6: shipment status is not fulfillment state).
- **Absolute quantities, not deltas.** `PUT .../items` sets each line's
  picked/packed quantity to the exact value you send — never "add 1". This
  makes a retried or double-submitted request idempotent by construction.
- **Fresh state, no follow-up round trip.** Every mutation response embeds
  the fresh list of candidate next transitions (the same shape
  `GET .../transitions` returns) and, where relevant, the fulfillment's new
  `version` — a client never needs a second request just to find out what
  it may do next.
- **Dates.** Every timestamp field is an ISO 8601 string (`DATE_ATOM`,
  e.g. `2026-08-02T10:00:00+00:00`).
- **Pagination.** `GET /fulfillments` takes `page`/`per_page`
  (1-indexed, default `1`/`20`) and returns `total`/`page`/`per_page`/
  `total_pages` alongside `items`.

## Errors

Every failure is a standard WordPress REST error body
(`{ "code": "...", "message": "...", "data": { "status": ... } }`) using
one of exactly five stable codes:

| Code | HTTP status | Meaning |
|---|---|---|
| `mpcf_forbidden` | 403 | The acting user lacks the capability this specific action needs. |
| `mpcf_not_found` | 404 | The fulfillment/shipment/package id does not exist. |
| `mpcf_invalid_payload` | 400 | The request body is malformed — a required field is missing, a line references an item that does not belong to the fulfillment, an empty batch. |
| `mpcf_version_conflict` | 409 | The caller's `version` no longer matches — reload and retry. |
| `mpcf_guard_rejected` | 422 | The request was well-formed but a business rule (a workflow guard, "a shipped shipment cannot be deleted") rejects it. `data.guard` names which one. |

A `422 mpcf_guard_rejected` body's `data.guard` is the guard identifier —
`all_items_picked`, `all_items_packed`, `package_spec_present`,
`has_shipment`, `has_tracking`, `photo_required`, or a structural code
(`no_such_edge`, `unknown_current_state`, `unknown_target_state`) — the
same vocabulary `GET .../transitions`' `rejection_code` field uses, so a
client can render one consistent message for a guard regardless of which
route surfaced it.

## Route reference

| Method | Route | Capability |
|---|---|---|
| GET | `/fulfillments` | `mpcf_view_queue` |
| GET | `/fulfillments/{id}` | `mpcf_view_queue` |
| GET | `/fulfillments/{id}/transitions` | `mpcf_view_queue` |
| POST | `/fulfillments/{id}/transitions` | per-edge (see below) |
| PUT | `/fulfillments/{id}/items` | `mpcf_process_fulfillments` |
| GET | `/fulfillments/{id}/notes` | `mpcf_view_queue` |
| POST | `/fulfillments/{id}/notes` | `mpcf_add_notes` |
| PUT | `/fulfillments/{id}/assignment` | `mpcf_process_fulfillments` |
| DELETE | `/fulfillments/{id}/assignment` | `mpcf_process_fulfillments` |
| GET | `/fulfillments/{id}/shipments` | `mpcf_view_queue` |
| POST | `/fulfillments/{id}/shipments` | `mpcf_manage_shipments` |
| PATCH | `/shipments/{id}` | `mpcf_manage_shipments` |
| DELETE | `/shipments/{id}` | `mpcf_manage_shipments` |
| POST | `/shipments/{id}/ship` | `mpcf_manage_shipments` |
| POST | `/shipments/{id}/packages` | `mpcf_manage_shipments` |
| PATCH | `/packages/{id}` | `mpcf_manage_shipments` |
| DELETE | `/packages/{id}` | `mpcf_manage_shipments` |
| POST | `/fulfillments/{id}/documents/render` | `mpcf_render_documents` |
| GET | `/documents` | `mpcf_render_documents` |
| GET | `/documents/{document_id}/content` | `mpcf_render_documents` |
| POST | `/documents/{document_id}/reprint` | `mpcf_render_documents` |
| GET | `/fulfillments/{id}/photos` | `mpcf_view_queue` |
| POST | `/fulfillments/{id}/photos` | `mpcf_capture_photos` |
| GET | `/photos/{photo_id}` | `mpcf_view_queue` |
| GET | `/photos/{photo_id}/content` | `mpcf_view_queue` |
| GET | `/photos/{photo_id}/thumb` | `mpcf_view_queue` |
| DELETE | `/photos/{photo_id}` | `mpcf_delete_photos` |
| GET | `/carriers` | `mpcf_view_queue` |
| POST | `/shipments/{id}/notify` | `mpcf_manage_shipments` |
| GET | `/shipments/{id}/notification-status` | `mpcf_view_queue` |

### `GET /fulfillments`

Query params: `state[]` (state keys, default any), `assignee` (a user id
or the string `unassigned`), `search` (free text, resolved the same way
the Queue's search box is — D22), `order_by` (`created_at` default),
`order` (`ASC`/`DESC`, default `DESC`), `page`, `per_page`.

```json
{
  "items": [ { "id": 42, "order_id": 1042, "state": "packing", "version": 3, "...": "..." } ],
  "total": 137,
  "page": 1,
  "per_page": 20,
  "total_pages": 7
}
```

### `GET /fulfillments/{id}`

```json
{
  "fulfillment": { "id": 42, "state": "packing", "version": 3, "...": "..." },
  "items": [ { "id": 501, "sku_snapshot": "SKU-1", "qty_ordered": 3, "qty_picked": 3, "qty_packed": 1, "...": "..." } ],
  "recent_events": [ { "event_type": "fulfillment.state_changed", "payload": { "from": "picked", "to": "packing" }, "...": "..." } ]
}
```

`recent_events` is the last 5 audit entries only, oldest first — the same
bound the Packing Workspace's outcome-column timeline uses (§IV.5.2). The
full, unbounded chain is a Fulfillment Detail screen concern until §IV.10's
timeline pagination lands (F23); it is not exposed here.

### `GET /fulfillments/{id}/transitions`

Every candidate next state for the fulfillment's current state, each
already evaluated against real data — never a client-side guess.

```json
{
  "transitions": [
    {
      "target": "packed",
      "label": "Packed",
      "approved": false,
      "rejection_code": "package_spec_present",
      "rejection_message": "Package dimensions and weight must be confirmed before packing is complete.",
      "requires_reason": false,
      "required_capability": "mpcf_process_fulfillments"
    }
  ]
}
```

A candidate the caller's own capabilities cannot reach is omitted
entirely, never returned as a disabled entry.

### `POST /fulfillments/{id}/transitions`

Body: `{ "target": "packed", "version": 3, "reason": "..." }` — `reason` is
required only for edges whose `requires_reason` is `true` (entering an
exception state, cancelling).

The capability required depends on which edge `target` names — most
forward edges need `mpcf_process_fulfillments`, `packed -> shipped` needs
`mpcf_manage_shipments`, cancellation needs `mpcf_cancel_fulfillment`. A
capability the caller lacks for the requested edge specifically is
`403 mpcf_forbidden`, even if the caller can view the fulfillment at all.

On success:

```json
{
  "fulfillment": { "id": 42, "state": "packed", "version": 4, "...": "..." },
  "transitions": [ { "target": "shipped", "approved": true, "...": "..." } ]
}
```

### `PUT /fulfillments/{id}/items`

Body: `{ "version": 4, "lines": [ { "item_id": 501, "qty_picked": 3 }, { "item_id": 502, "qty_packed": 1 } ] }`.
Each line sets `qty_picked` and/or `qty_packed` — at least one of the two,
absolute values clamped server-side to `0..qty_ordered`. One call is one
coalesced audit event per field touched (`items.picked`/`items.packed`),
regardless of how many lines it contains — the burst-aggregation the
Packing Workspace's checklist relies on (§IV.10).

```json
{
  "items": [ { "id": 501, "qty_picked": 3, "...": "..." } ],
  "version": 5,
  "transitions": [ "..." ]
}
```

### `POST /fulfillments/{id}/scan`

Capability: `mpcf_process_fulfillments`. Architecture Plan Part IX.11.

Body:

```json
{
  "action": "resolve|pick|pack|undo",
  "payload": "SKU-1 or MPCF:I:501",
  "version": 5,
  "active_package_id": 3
}
```

- `resolve` — parse/match only (no quantity mutation; `version` optional).
- `pick` / `pack` — +1 to `qty_picked` / `qty_packed` when stage matches;
  requires `version`. Packing never exceeds picked. Over-scan → 422.
- `undo` — server-authoritative decrement of the operator's last successful
  scan for this fulfillment (transient memory; no scan-session table).
- Stale `version` → `409 mpcf_version_conflict` (client must not auto-replay).
- Package barcodes (`MPCF:P:{id}`) switch active package context only —
  they do not rewrite `mpcf_package_items` allocation.

```json
{
  "result": "quantity_incremented",
  "message": "Quantity incremented.",
  "version": 6,
  "stage_complete": false,
  "item": { "id": 501, "qty_picked": 1, "...": "..." },
  "items": [ "..." ],
  "progress": { "ordered": 3, "processed": 1, "remaining": 2 },
  "transitions": [ "..." ]
}
```

### `GET /fulfillments/{id}/notes`

```json
{ "notes": [ { "id": 9, "author_id": 3, "body": "Customer called.", "is_pinned": true, "created_at": "..." } ] }
```

Pinned notes sort first.

### `POST /fulfillments/{id}/notes`

Body: `{ "body": "...", "is_pinned": false }`. `201` on success:
`{ "note": { "...": "..." } }`.

### `PUT /fulfillments/{id}/assignment`

Body: `{ "user_id": 7 }`. `200`: `{ "success": true }`.

### `DELETE /fulfillments/{id}/assignment`

No body. Clears the assignment. `200`: `{ "success": true }`.

### `GET /fulfillments/{id}/shipments`

```json
{
  "shipments": [
    {
      "id": 1, "status": "pending", "carrier_id": "postnord", "tracking_number": "ABC123",
      "packages": [ { "id": 1, "seq": 1, "weight_grams": 1200, "tracking_number": null, "...": "..." } ]
    }
  ]
}
```

### `POST /fulfillments/{id}/shipments`

No body. Creates a shipment and its package 1, auto-allocating every
currently-packed line quantity to it (§IV.5.8 step 6 — the same "first
edit to any shipment field" moment the workspace triggers this on). `201`:

```json
{
  "shipment": { "id": 1, "status": "pending", "...": "..." },
  "fulfillment": { "id": 42, "version": 5, "...": "..." },
  "transitions": [ "..." ]
}
```

### `PATCH /shipments/{id}`

Body (all optional): `{ "carrier_id": "postnord", "service": "MyPack", "tracking_number": "ABC123", "tracking_url": "https://..." }`.
An empty string clears a field. Response shape matches `POST .../shipments`.

### `DELETE /shipments/{id}`

Refused (`422 mpcf_guard_rejected`, `data.guard = "not_deletable"`) once
the shipment has shipped — a shipped shipment is corrected, never deleted
(§IV.6). Permitted while `pending`. `200`: `{ "shipment": null, "fulfillment": {...}, "transitions": [...] }`.

### `POST /shipments/{id}/ship`

No body. Marks one shipment `shipped` and stamps `shipped_at`. Response
shape matches `PATCH /shipments/{id}`.

### `POST /shipments/{id}/packages`

No body. Appends a new package at the next sequence number. `201`:
`{ "package": { "id": 2, "seq": 2, "...": "..." }, "fulfillment": {...}, "transitions": [...] }`.

### `PATCH /packages/{id}`

Body (all optional): `{ "weight_grams": 1200, "length_mm": 300, "width_mm": 200, "height_mm": 100, "tracking_number": "COLLI-1" }`.
Weights in grams, dimensions in millimetres, integers only (D15) — a
client converts to/from the store's display units itself.

```json
{
  "package": { "id": 1, "weight_grams": 1200, "...": "..." },
  "fulfillment": { "id": 42, "version": 5, "...": "..." },
  "transitions": [ "..." ]
}
```

### `DELETE /packages/{id}`

`200`: `{ "package": null, "fulfillment": {...}, "transitions": [...] }`.

### `POST /fulfillments/{id}/documents/render`

Optional body field `doc_type` (`packing_slip` default; `picking_list` also
bundled). Assembles, renders, stores canonical HTML, and records via
`DocumentService::render()`. Stage policy applies (`DocumentStagePolicy`).
`201`:

```json
{
  "html": "<!DOCTYPE html>...",
  "document_id": 7,
  "document_type": "packing_slip",
  "template_version": "2",
  "stored": true,
  "file_available": true,
  "fulfillment": { "id": 42, "version": 5, "...": "..." },
  "transitions": [ "..." ]
}
```

Load `html` into a same-origin hidden `<iframe>` (`iframe.srcdoc = html`)
and call `iframe.contentWindow.print()` (§IV.8); the stylesheet is inlined.

### `GET /documents`

Query: `doc_type`, `search` (order # / fulfillment id), `date_from`,
`date_to` (`Y-m-d`), `limit` (≤100), `offset`. Requires
`mpcf_render_documents`. Returns `{ "items": [...], "total": N }`.

### `GET /documents/{document_id}/content`

Streams the **exact** stored HTML artifact (no reprint audit). Capability
`mpcf_render_documents`. Path resolved only from trusted repository
metadata under the protected upload root; traversal rejected. Response is
raw `text/html; charset=UTF-8` (not a JSON envelope).

### `POST /documents/{document_id}/reprint`

Streams the exact stored HTML and appends `document.reprinted` (payload
includes `source_document_id`). Does **not** create a new document row.
`200`: `{ "html": "...", "document_id": 7, "source_document_id": 7, ... }`.

### Package photos (M6-B)

Protected operational evidence under `uploads/mpcf/photos/…` (ADR-0004).
Never WP Media Library. Metadata responses never include storage paths —
only relative REST stream routes (`content` / `thumbnail`).

Photo-specific error codes (in addition to the five stable codes above):

| Code | HTTP | Meaning |
|---|---|---|
| `mpcf_photo_not_found` | 404 | Missing or soft-deleted metadata GET |
| `mpcf_photo_deleted` | 404 | Soft-deleted content/thumb stream |
| `mpcf_photo_invalid_kind` | 400 | Kind not in `contents`\|`package` |
| `mpcf_photo_invalid_upload` | 400 | Bad/empty/unprocessable upload |
| `mpcf_photo_package_mismatch` | 400 | Package not on this fulfillment |
| `mpcf_photo_limit_reached` | 422 | Active photo cap reached |
| `mpcf_photo_content_missing` | 422 | Metadata exists but bytes unreadable |
| `mpcf_photo_purged` | 422 | Bytes removed by retention (`purged_at` set / paths cleared) |
| `mpcf_photo_storage_failed` | 500 | Persistence failure |

When `photos_required` is on, `packing → packed` returns
`422 mpcf_guard_rejected` with `data.guard = photo_required` until ≥1
**active** `kind=package` photo exists (contents photos do not satisfy).

#### `GET /fulfillments/{id}/photos`

Optional query: `package_id`, `kind`. Active photos only, sorted by
`package_id` then `sequence`. `200`: `{ "photos": [ … ] }`.

#### `POST /fulfillments/{id}/photos`

Multipart: `file` (required), `package_id`, `kind`, `version` (required).
Capability `mpcf_capture_photos`. `201`:

```json
{
  "photo": {
    "id": 3,
    "fulfillment_id": 42,
    "package_id": 1,
    "kind": "package",
    "mime": "image/jpeg",
    "bytes": 48210,
    "width": 1600,
    "height": 1200,
    "sha256": "…",
    "processing_version": 1,
    "sequence": 1,
    "captured_by": 7,
    "created_at": "2026-08-06T10:00:00+00:00",
    "purged": false,
    "has_bytes": true,
    "content": "/mpcf/v1/photos/3/content",
    "thumbnail": "/mpcf/v1/photos/3/thumb"
  },
  "version": 6,
  "photo_requirement_satisfied": true,
  "transitions": [ "…" ]
}
```

#### `GET /photos/{photo_id}`

Active metadata only. Soft-deleted or missing → `404 mpcf_photo_not_found`.

#### `GET /photos/{photo_id}/content` / `…/thumb`

Capability-gated raw JPEG streams (`Content-Type`, `Content-Disposition:
inline`, `X-Content-Type-Options: nosniff`). Soft-deleted →
`404 mpcf_photo_deleted`.

#### `DELETE /photos/{photo_id}`

Body/param `version` required. Capability `mpcf_delete_photos` (Lead+).
Soft-delete is idempotent (no second audit / no version bump when already
deleted). `200`: `{ "photo": {…}, "version": N, "photo_requirement_satisfied": bool, "transitions": […] }`.

### `GET /carriers`

```json
{
  "carriers": [
    {
      "id": "postnord",
      "label": "PostNord",
      "tracking_url_template": "https://tracking.postnord.com/en/?id={tracking}",
      "tracking_number_pattern": "^[A-Za-z0-9]{8,35}$",
      "phone_required": false
    },
    {
      "id": "other",
      "label": "Other",
      "tracking_url_template": null,
      "tracking_number_pattern": null,
      "phone_required": false
    }
  ]
}
```

Additive fields (`tracking_url_template`, `tracking_number_pattern`,
`phone_required`) were added in M5-A; `id` and `label` remain for
backward compatibility. Bundled set is EU-skewed (PostNord, DHL, Bring,
DPD, GLS, UPS, DB Schenker, Budbee, Instabox) plus `other`. `other`
always exists and accepts a free-text carrier label plus a manual
tracking URL — no merchant is blocked on an unbundled carrier (§11).
Integrators extend the set via the `mpcf_carriers` filter (see
`docs/HOOKS.md`). Tracking URLs are resolved by `TrackingUrlResolver`
(default: template expansion) — not a live carrier API.

### `POST /shipments/{id}/notify`

Sends the MPCF shipped-email notification for one shipment when the
merchant strategy includes `MPCF_SHIPPED` or `BOTH`. Body param `force`
(default `true` for this route) bypasses the automatic 120s dedup window
used by the `shipment.shipped` subscriber.

```json
{
  "status": "sent",
  "strategy": "MPCF_SHIPPED",
  "result": { "success": true, "channel": "email", "error_code": "" }
}
```

`status` values: `sent`, `failed`, `suppressed`, `skipped_strategy`,
`not_found`. Response never includes the customer email address.

### `GET /shipments/{id}/notification-status`

Last audited notification outcome for the shipment (from the fulfillment
event trail).

```json
{
  "notification": {
    "status": "sent",
    "occurred_at": "2026-08-05T12:00:00+00:00",
    "strategy": "MPCF_SHIPPED",
    "error_code": null
  }
}
```

## What is not exposed, and why

- **No packing-inside-wave routes** — Wave Scan Mode ends at `picked`; packing stays per-fulfillment Workspace (Part X).
- **No PDF download routes** — canonical stored format is HTML; PDF remains deferred.
- **No `mpcf_workflows` filter routes** — the workflow definition is still
  evolving; freezing an API surface around it now would be premature (§16.2).
- **No carrier API / label / live-tracking routes** — M13.
- **No SMS / push / webhook / Slack notification channels** — email only in M5;
  future channels are additive on `NotificationChannel`.
- **No notification history / campaign / resend-queue routes** — Workspace
  shows last status only; audit trail remains on the fulfillment timeline.
- **No scoped API keys** — an Application Password authenticates as its
  full user account today; a credential limited to specific capabilities
  is a post-1.0 idea (§IV.13).
- **No REST response caching** — a warehouse queue that shows stale data
  is worse than a slow one (§IV.10).
- **No document delete route** — documents are immutable historical artifacts.

## Waves (M8 / Part X)

Capability for lifecycle/scan: `mpcf_process_fulfillments`. Documents:
`mpcf_render_documents`. Wave `version` is required on mutating lifecycle
and scan actions (409 on conflict). Exclusive owner enforced server-side
(`wave_owned`).

| Method | Route | Purpose |
|---|---|---|
| `POST` | `/waves` | Create draft (`warehouse_id`, optional `fulfillment_ids`, `title`) |
| `GET` | `/waves` | List open waves (`mine`, `warehouse_id`) |
| `GET` | `/waves/{id}` | Wave + progress + walk |
| `POST` | `/waves/{id}/members` | Add fulfillments (`fulfillment_ids`, `version`) |
| `DELETE` | `/waves/{id}/members/{fulfillment_id}` | Remove member (`version`) |
| `POST` | `/waves/{id}/activate` | draft→active |
| `POST` | `/waves/{id}/pause` | active→paused |
| `POST` | `/waves/{id}/resume` | paused→active |
| `POST` | `/waves/{id}/complete` | complete (`force` optional) |
| `POST` | `/waves/{id}/abandon` | abandon (releases membership; does not cancel) |
| `GET` | `/waves/{id}/walk` | Combined walk model |
| `POST` | `/waves/{id}/scan` | Wave pick scan (`action=resolve\|pick\|undo`) |
| `POST` | `/waves/{id}/documents` | Render `wave_picking_list` HTML |