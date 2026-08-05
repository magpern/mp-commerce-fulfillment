# Milestone 5 Release Report — Tracking & notifications

**Version:** 0.5.0  
**Status:** Release candidate (not tagged / not published — awaiting PO approval)

**Branch:** `feature/m5-tracking-notifications`  
**Baseline:** `v0.4.0` on `main`

---

## Executive summary

M5 completes customer shipment communication after ship: immutable carrier
registry, merchant notification configuration, notification pipeline
(`ShipmentShipped → Factory → Notification → Service/Dispatcher → EmailChannel`),
WooCommerce completed-order tracking extension, and minimal Workspace/REST
operator controls.

Binding constraints held: no carrier APIs, no SMS/push, no inventory/receiving,
no Mission Control redesign, no M6+.

---

## Implementation (by phase)

| Phase | Scope | Outcome |
|---|---|---|
| M5-A | Carrier VO, BundledCarrierRegistry, TrackingUrlResolver, REST carriers | Landed |
| M5-B | NotificationStrategy, configuration service, Settings schema v6 UI | Landed |
| M5-C | Notification, Factory, Service, Dispatcher, EmailChannel, audit events | Landed |
| M5-D | TrackingEmailExtension, Workspace notify panel, REST notify/status | Landed |
| M5-E | Dogfood classification, docs, RC prep | Landed / RC gate |

---

## Dogfood scenarios

Executed via focused unit suite + `NotificationsControllerTest` integration
against the real composition root (MariaDB + Woo). Classification:

| Scenario | Class | Notes |
|---|---|---|
| Successful MPCF shipped send + audit | OK | `notification.sent` |
| Missing customer email | OK | `notification.failed` / `missing_recipient` |
| DISABLED / COMPLETED_EMAIL skip MPCF channel | OK | `skipped_strategy` |
| MPCF_SHIPPED / BOTH send | OK | channel invoked |
| Dedup within 120s (auto) | OK | `suppressed` |
| Force bypasses dedup | OK | manual Workspace/REST |
| Carrier template tracking URL | OK | PostNord `{tracking}` expand |
| Invalid configuration fallback | OK | M5-B service (carrier→`other`, strategy default) |
| Pending shipment omitted from completed-order block | OK | TrackingEmailExtension |
| Notify without `mpcf_manage_shipments` | OK | HTTP 403 |
| Status without `mpcf_view_queue` | OK | HTTP 403 |
| `wp_mail` false in some hosts | Important polish | Surfaces as `failed` / `wp_mail_failed`; operators can resend |

### Release blockers fixed

| Blocker | Fix |
|---|---|
| (none remaining) | — |

### Deferred / polish

| Finding | Class |
|---|---|
| Live browser click-through of Workspace notify on production-like host | Important polish |
| Operator-facing i18n of JS status strings (`Notification status: …`) | Important polish |
| Public `mpcf_notification_channels` filter | Future enhancement |
| SMS / push / webhooks | Future enhancement (additive channels) |
| Baseline CI: picking `409` + WorkspaceFlags `trim()` | Pre-existing on `main`; not M5 regressions |

---

## Tests

- Unit: full suite green (488 tests)
- Integration (focused): `NotificationsControllerTest`, `CarriersControllerTest`
- PHPCS: clean on M5 touch set
- POT: regenerated for new strings

---

## Release candidate packaging

Prepared on branch (see commit `chore(release): prepare v0.5.0 release candidate`):

- Version bump to `0.5.0` (plugin header, `MPCF_VERSION`, `readme.txt`)
- ZIP via `bin/build-zip.sh`
- `composer release-audit`
- SHA-256 of dist ZIP

**Not done (PO gate):** merge to `main`, tag `v0.5.0`, GitHub Release publish.

---

## Explicit non-goals confirmation

- No M6 work started
- No carrier APIs
- No SMS/push architecture
- No inventory/receiving coupling
- `v0.5.0` not tagged or published
