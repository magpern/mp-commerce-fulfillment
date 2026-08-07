# Privacy — export, erase, and retention

M10 registers WordPress **Tools → Export Personal Data** and **Erase Personal Data** handlers. WooCommerce order anonymization triggers sympathetic MPCF erase.

## Exporter

**Registry id:** `mpcf-fulfillment-data`  
**Group:** MP Commerce Fulfillment

Exports customer-linked data matched by **billing email** (via WooCommerce order lookup, limit 100 orders):

| Exported | Included |
|---|---|
| Fulfillment id, order id, state | Yes |
| Customer name snapshot | Yes |
| Created timestamp | Yes |
| Internal notes text | Yes |
| Photo metadata (paths, dimensions, hashes) | Yes — **not binary files** |

No shipment tracking numbers in default export unless present in note/snapshot fields.

## Eraser

**Registry id:** `mpcf-fulfillment-data`

| Field / asset | Erasure behavior |
|---|---|
| `customer_name_snapshot` | Replaced with `[anonymized]` |
| `mpcf_notes.body` | Replaced with `[note erased]` |
| Photo bytes | Files unlinked; `mpcf_media` marked purged |
| Event `actor_id` labels | Actor display anonymized to `[erased]` where user matches |
| Event payloads, hashes, `prev_hash` | **Retained** — hash-chain integrity |
| `order_id` links | **Retained** — operational/audit requirement |
| Fulfillment rows | **Not deleted** — warehouse history preserved |

Eraser messages explicitly state retained audit fields.

## WooCommerce sympathy

Hook: `woocommerce_privacy_remove_order_personal_data` (priority 20)  
Handler: `MPCF\Woo\PrivacyHooks` → `PrivacyEraser::erase_for_order_id()`

When WC anonymizes an order, MPCF scrubs linked fulfillment snapshots/notes/photos for that order id without waiting for a separate privacy request.

## Hash-chain integrity

Privacy anonymization **does not** UPDATE event payload JSON or break hash links. `WpdbEventPrivacyAnonymizer` scrubs actor display fields only where applicable.

Verify after bulk erase on test fixtures:

```bash
wp mpcf audit verify <fulfillment_id>
```

## GDPR operational notes

- Export/erase are **email-keyed** — guest checkout orders match via billing email on WC orders.
- Operators with internal notes mentioning PII should expect those strings in exports until erased.
- Photo retention purge (`photos_retention_months`) is separate from privacy erase — scheduled byte removal, not anonymization request.
- `remove_data_on_uninstall` wipes all MPCF tables when enabled — not a per-customer tool.

## Admin access

Privacy tools run under WordPress core privacy capabilities (`export_others_personal_data`, `erase_others_personal_data`). MPCF does not add separate privacy caps.

See `docs/SECURITY_REVIEW.md` for security posture of exporter/eraser callbacks.
