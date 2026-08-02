# Architecture decision records

Format (Nygard):

```
# ADR-NNNN — Title
## Status
## Context
## Decision
## Consequences
```

Filenames: `NNNN-kebab-case-title.md`, zero-padded 4 digits.

| ADR | Title | Milestone | Status |
|---|---|---|---|
| [0001](0001-custom-tables-not-order-meta.md) | Custom tables, not order meta or a CPT | M0 | Accepted |
| [0002](0002-no-woocommerce-micro-statuses.md) | No fulfillment micro-states as WooCommerce order statuses | M0 | Accepted |
| [0003](0003-no-build-vanilla-js.md) | No JS framework, no build step | M0 | Accepted (superseded in part by 0006) |
| [0004](0004-protected-media-storage.md) | Package photos and documents outside the media library | M0 | Accepted |
| [0005](0005-packages-under-shipments.md) | `Package` is first-class under `Shipment` from the start | M0 | Accepted |
| [0006](0006-dev-only-browser-test-toolchain.md) | Dev-only browser test toolchain (Playwright) | M2 | Accepted |
