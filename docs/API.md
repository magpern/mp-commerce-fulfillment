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
  (`POST .../transitions`, `PUT .../items`) requires the fulfillment's
  current `version` in the request body. A mismatch — someone else changed
  the fulfillment since you last read it — returns `409
  mpcf_version_conflict` and changes nothing. Shipment/package routes
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
| GET | `/carriers` | `mpcf_view_queue` |

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

### `GET /carriers`

```json
{ "carriers": [ { "id": "postnord", "label": "PostNord" }, { "id": "other", "label": "Other" } ] }
```

`other` always exists and accepts a free-text carrier label plus a manual
tracking URL — no merchant is blocked on a carrier this bundled set does
not recognize (§IV.6). The real EU-skewed registry (format hints,
phone-required flags, an `mpcf_carriers` filter) is Milestone 4's job.

## What is not exposed, and why

- **`POST /fulfillments/{id}/documents/render`** (the packing slip) ships
  later in this same milestone (Phase E) and will be documented here once
  implemented — this reference reflects the surface built through Phase D.
- **No pick list or batch picking routes** (Milestone 3+/7).
- **No `mpcf_workflows`/`mpcf_carriers` filter routes** — the workflow
  definition and the real carrier registry shape are still evolving;
  freezing an API surface around them now would be premature (§16.2).
- **No scoped API keys** — an Application Password authenticates as its
  full user account today; a credential limited to specific capabilities
  is a post-1.0 idea (§IV.13).
- **No REST response caching** — a warehouse queue that shows stale data
  is worse than a slow one (§IV.10).
