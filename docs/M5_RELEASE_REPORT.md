# Milestone 5 Release Report — Tracking & notifications

**Version:** 0.5.0  
**Status:** ✅ Released (plugin artifact published; production deploy not part of this release)

**Tag:** `v0.5.0`  
**Merged commit:** `5e06556d084255b9728897f896e0d6022907d5fa`  
**Tag target:** `5e06556d084255b9728897f896e0d6022907d5fa`  
**PR:** https://github.com/magpern/mp-commerce-fulfillment/pull/2  
**GitHub Release:** https://github.com/magpern/mp-commerce-fulfillment/releases/tag/v0.5.0  
**Published asset:** `mp-commerce-fulfillment-0.5.0.zip`  
**Release workflow:** run `31041053923` — **success**

**Local SHA-256 (post-merge rebuild):**  
`26fcddc965d7f1836963e86300bb33c0a9b4bcffb5c739f2cbacdcd2c9b4a112`

**Published SHA-256:**  
`a3d2de9fa9b4f14d2269c928b08a56d35a9656e0d818f5e381abda15281203b7`

Path-list difference vs local ZIP limited to expected archive/Composer
metadata (`vendor/bin/` present locally only). Version parity `0.5.0`
verified in the published ZIP.

**Baseline:** `v0.4.0` on `main`  
**Milestone closed:** M5 — Tracking & Notifications  
**M6 started:** no

---

## Executive summary

M5 completes customer shipment communication after ship: immutable carrier
registry, merchant notification configuration, notification pipeline
(`ShipmentShipped → Factory → Notification → Service/Dispatcher → EmailChannel`),
WooCommerce completed-order tracking extension, and minimal Workspace/REST
operator controls.

Binding constraints held: no carrier APIs, no SMS/push, no inventory/receiving,
no Mission Control redesign, no M6+.

PO approved GO 2026-08-05 subject to baseline-CI verification. Feature CI
failures matched `main` (PickingWorkflow `409`, WorkspaceFlags `trim()`,
DocumentsController PHPCS). A notification-only integration flake
(Settings cache + merged `rest_api_init` routes) was fixed before merge
(`0140e77`).

---

## Implementation (by phase)

| Phase | Scope | Outcome |
|---|---|---|
| M5-A | Carrier VO, BundledCarrierRegistry, TrackingUrlResolver, REST carriers | Landed |
| M5-B | NotificationStrategy, configuration service, Settings schema v6 UI | Landed |
| M5-C | Notification, Factory, Service, Dispatcher, EmailChannel, audit events | Landed |
| M5-D | TrackingEmailExtension, Workspace notify panel, REST notify/status | Landed |
| M5-E | Dogfood, docs, RC, merge, tag, publish | Landed / closed |

---

## Dogfood / smoke

Unit + `NotificationsControllerTest` integration, plus WP-CLI smoke on
dev.biopentra.eu bind-mount (shipment id 2, `pre_wp_mail` short-circuit):

| Scenario | Class | Notes |
|---|---|---|
| MPCF_SHIPPED sends one mail | OK | status `sent`, mails=1 |
| COMPLETED_EMAIL skips MPCF channel; tracking blocks present | OK | `skipped_strategy`; blocks=1 |
| BOTH enables both paths | OK | sent + blocks=1 |
| DISABLED neither path | OK | skip; `includes_completed=false` |
| Dedup suppresses repeat within window | OK | `suppressed` |
| Manual force resend | OK | `sent` |
| Last status/time updates | OK | audited `occurred_at` |
| Missing customer email | OK | `failed` / `missing_recipient` |
| Carrier fallback + tracking URL | OK | unknown→`other`; PostNord URL |
| No inventory/receiving classes | OK | |

### Release blockers fixed

| Blocker | Fix |
|---|---|
| CI `NotificationsControllerTest` strategy assertion (`COMPLETED_EMAIL` vs `MPCF_SHIPPED`) | Persist strategy before boot; `remove_all_actions('rest_api_init')` before re-init (`1f7e480`, `0140e77`) |

### Deferred / polish

| Finding | Class |
|---|---|
| Live browser click-through of Workspace notify button | Important polish |
| Operator-facing i18n of JS status strings | Important polish |
| Public `mpcf_notification_channels` filter | Future enhancement |
| SMS / push / webhooks | Future enhancement |
| Baseline CI: picking `409` + WorkspaceFlags `trim()` + DocumentsController PHPCS | Pre-existing on `main`; not M5 regressions |

---

## Tests

- Unit: full suite green (488 tests)
- Integration (focused): `NotificationsControllerTest`, `CarriersControllerTest`
- PHPCS: clean on M5 touch set (repo-wide PHPCS still fails baseline DocumentsController)
- POT: regenerated; CI pot job green
- Release workflow: green

---

## Explicit non-goals confirmation

- No M6 work started
- No carrier APIs
- No SMS/push architecture
- No inventory/receiving coupling
- Production was not deployed as part of this release
- Tag `v0.5.0` published on merged `main` commit `5e06556`
