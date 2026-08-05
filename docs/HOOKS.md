# Hooks

Every hook this plugin registers, and everything it deliberately does not
hook. No automated sync test exists for this file (there is no
`HooksDocumentationSyncTest`) — it is kept current by hand at each
milestone's documentation-reconciliation step (D20 for Milestone 1, F24 for
Milestone 2).

## Registered by this plugin

| Hook | Type | File | Priority/args | Purpose |
|---|---|---|---|---|
| `before_woocommerce_init` | action | `mp-commerce-fulfillment.php` | default | Declares `custom_order_tables` (HPOS) and `cart_checkout_blocks` compatibility via `FeaturesUtil`. Registered unconditionally, even if `MPCF\Plugin` never boots. |
| `plugins_loaded` | action | `mp-commerce-fulfillment.php` | default | Guards WooCommerce presence (a second `admin_notices` if absent) and boots `MPCF\Plugin::instance()->init()`. |
| `init` | action | `MPCF\Plugin::init()` | default | Loads the plugin text domain. |
| `admin_init` | action | `MPCF\Plugin::init()` | default | Runs `Migrator::maybe_migrate()` — the drift check for bind-mount deployments that never fire the activation hook (§7). |
| `admin_menu` | action | `MPCF\Plugin::wire_admin()` | priority `20` | Registers `Fulfillment Detail` as a real submenu page (so its capability/URL resolve), then immediately calls `remove_submenu_page()` — reachable only from Queue/Dashboard, never a standalone nav item. |
| `admin_menu` | action | `Vendor\Mpds\PageShell\Menu::register()` | default | Registers the top-level "Fulfillment" menu (Dashboard + Queue), invoked via `Plugin::wire_admin()`. |
| `admin_enqueue_scripts` | action | `Admin\Assets::maybe_enqueue()` | default | Enqueues MPDS + plugin admin CSS/JS, gated to this plugin's own screens (`mpcf-dashboard`, `mpcf-queue`, `mpcf-orders`, `mpcf-settings`, `mpcf-fulfillment-detail`, `mpcf-workspace`). Script Modules API (`wp_enqueue_script_module`, WP 6.5+) enqueues five workspace ES modules from `assets/admin/js/`. |
| `admin_body_class` | filter | `Admin\Assets::maybe_add_body_class()` | default | Appends `mpcf-ui-scope mpcf-admin` on this plugin's own screens. |
| `admin_body_class` | filter | `Admin\OperatorMode::maybe_add_body_class()` | default | Appends `mpcf-operator-mode` for operator-tier users when the `operator_mode_enabled` setting is on and the user is not an admin/lead — CSS then hides the rest of wp-admin's nav. |
| `woocommerce_payment_complete` | action | `Woo\IntakeHooks::handle_order_paid()` | default | Synchronous order-to-fulfillment intake on payment completion (classic and Blocks checkout). |
| `woocommerce_order_status_processing` | action | `Woo\IntakeHooks::handle_order_paid()` | default | Same intake path — covers gateways/manual-order flows that go straight to `processing` without firing `payment_complete`. |
| `mpcf_process_intake` | action + Action Scheduler | `Woo\IntakeHooks::process_scheduled_intake()` | default | Action Scheduler fallback retry when a synchronous intake attempt fails; does not reschedule itself on further failure. Scheduled via `as_enqueue_async_action()` in group `mpcf` — see `docs/PERSISTED_DATA.md`. |
| `woocommerce_order_status_cancelled` | action | `Woo\RefundObserver::handle_order_cancelled()` | default | Store-order bridge: proposes cancel/flag-problem per `inbound_cancel_behavior` (not supplier receiving — see ADR-0007). |
| `woocommerce_order_fully_refunded` | action | `Woo\RefundObserver::handle_order_fully_refunded()` | default | Store-order bridge: proposes cancel/flag-problem per `inbound_refund_behavior` (not supplier receiving — see ADR-0007). |
| `woocommerce_order_partially_refunded` | action | `Woo\RefundObserver::handle_order_partially_refunded()` | default | Always flags the fulfillment `problem` — no automatic-cancel setting exists for a partial refund. |
| `woocommerce_saved_order_items` | action | `Woo\RefundObserver::handle_order_items_saved()` | `accepted_args=2` | Diffs live order items against the intake snapshot; flags `problem` with a minimal id/qty diff on any material post-intake edit. |

`register_activation_hook()` is registered in the main file (calls
`MPCF\Plugin::activate()`, which runs `Migrator::migrate()` and grants
capabilities/roles). No deactivation hook is registered (invariant I12).
Uninstall runs via the standard `uninstall.php` file convention, not
`register_uninstall_hook()`.

## REST — Milestone 2

| Route | Method | Capability | Purpose |
|---|---|---|---|
| `/mpcf/v1/fulfillments` | GET | `mpcf_view_queue` | Queue list. |
| `/mpcf/v1/fulfillments/{id}` | GET | `mpcf_view_queue` | Fulfillment detail. |
| `/mpcf/v1/fulfillments/{id}/transitions` | GET | `mpcf_view_queue` | Available transitions, returned in every mutation response. |
| `/mpcf/v1/fulfillments/{id}/transitions` | POST | per-edge, from workflow definition | Apply a transition. |
| `/mpcf/v1/fulfillments/{id}/items` | PUT | `mpcf_process_fulfillments` | Batch absolute quantities (picked/packed). |
| `/mpcf/v1/fulfillments/{id}/notes` | GET/POST | `mpcf_view_queue` / `mpcf_add_notes` | Fetch/add notes. |
| `/mpcf/v1/fulfillments/{id}/assignment` | PUT/DELETE | `mpcf_process_fulfillments` | Assign/unassign. |
| `/mpcf/v1/fulfillments/{id}/shipments` | GET/POST | `mpcf_view_queue` / `mpcf_manage_shipments` | Fetch/create shipments. |
| `/mpcf/v1/shipments/{id}` | PATCH/DELETE | `mpcf_manage_shipments` | Update/delete shipment. |
| `/mpcf/v1/shipments/{id}/ship` | POST | `mpcf_manage_shipments` | Ship (sets status to shipped, stamps shipped_at). |
| `/mpcf/v1/shipments/{id}/packages` | POST | `mpcf_manage_shipments` | Add package. |
| `/mpcf/v1/packages/{id}` | PATCH/DELETE | `mpcf_manage_shipments` | Update/delete package. |
| `/mpcf/v1/fulfillments/{id}/documents/render` | POST | `mpcf_render_documents` | Render and print a packing slip. |
| `/mpcf/v1/carriers` | GET | `mpcf_view_queue` | Bundled carrier registry. |

Full endpoint documentation: `docs/API.md`.

## Deliberately NOT hooked (Milestone 1)

- No product, cart, or checkout filter beyond the two HPOS/Blocks
  compatibility declarations above — this plugin never modifies an order's
  line items, prices, totals, customer data or products (I1).

## Public extension points added in M2

| Hook | Type | File | Purpose |
|---|---|---|---|
| `mpcf_workspace_flags` | filter | `src/Admin/WorkspacePage.php` | Returns a list of flag descriptors to render in the workspace's context column. Bundled: customer note present, high value, repeat problem customer. Integrators can add custom flags via this filter. |

## Public extension points added in M4-A (Documents I)

| Hook | Type | File | Purpose |
|---|---|---|---|
| `mpcf_document_types` | filter | `src/Documents/DocumentTypeRegistry.php` | Amend the small packing_slip / picking_list type map. Malformed entries are dropped. |
| `mpcf_document_template` | filter | `src/Documents/TemplateRegistry.php` | Override template path before theme/bundled resolution. Must be a readable `.php` file. |
| `mpcf_document_model` | filter | `src/Application/DocumentService.php` | Amend the assembled `DocumentModel` after core assemble and before render. Must return a `DocumentModel` with the same `doc_type`. |

M4-B–E did not add public hooks. Branding is settings-backed; storage,
history, and reprint are orchestrated by `DocumentService` /
`DocumentHistoryService`. Audit event types used by the documents
subsystem (not WordPress hooks): `document.rendered`,
`document.reprinted` (payload includes `source_document_id`).

## Public extension points added in M5-A (Carrier Registry Foundation)

| Hook | Type | File | Purpose |
|---|---|---|---|
| `mpcf_carriers` | filter | `src/Infrastructure/Carriers/BundledCarrierRegistry.php` | Amend the EU-skewed carrier map. Each entry: `id`, `label`, `tracking_url_template` (nullable), optional `tracking_number_pattern`, optional `phone_required`. |

**Validation (DocumentTypeRegistry resilience):** every contributed definition
is validated. Malformed entries are **rejected**, **logged** (`wc_get_logger`
source `mpcf-carriers`, or `error_log` fallback), and **skipped**. Remaining
valid carriers load normally. A non-array filter return reverts to the
bundled set. Duplicate ids: later definition wins (logged). `other` is
always restored if a filter removes it.

Definitions are **immutable after registration**. Runtime merchant
preferences (default carrier, notification strategy) belong in Settings
(later M5) — do not mutate registry definitions for that.

`TrackingUrlResolver` (default `TemplateTrackingUrlResolver`) expands
`{tracking}` templates; it is not a live carrier API and is not hooked.

All other v1.0 extension surfaces (`mpcf_workflows`, `mpcf_event` +
per-type actions, `mpcf_intake_should_create`) remain documented in
`docs/ARCHITECTURE_PLAN.md` §16.2 as future milestones.
