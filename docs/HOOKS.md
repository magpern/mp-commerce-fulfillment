# Hooks

Every hook this plugin registers, and everything it deliberately does not
hook. No automated sync test exists for this file (there is no
`HooksDocumentationSyncTest`) — it is kept current by hand at each
milestone's documentation-reconciliation step (D20 for Milestone 1).

## Registered by this plugin

| Hook | Type | File | Priority/args | Purpose |
|---|---|---|---|---|
| `before_woocommerce_init` | action | `mp-commerce-fulfillment.php` | default | Declares `custom_order_tables` (HPOS) and `cart_checkout_blocks` compatibility via `FeaturesUtil`. Registered unconditionally, even if `MPCF\Plugin` never boots. |
| `plugins_loaded` | action | `mp-commerce-fulfillment.php` | default | Guards WooCommerce presence (a second `admin_notices` if absent) and boots `MPCF\Plugin::instance()->init()`. |
| `init` | action | `MPCF\Plugin::init()` | default | Loads the plugin text domain. |
| `admin_init` | action | `MPCF\Plugin::init()` | default | Runs `Migrator::maybe_migrate()` — the drift check for bind-mount deployments that never fire the activation hook (§7). |
| `admin_menu` | action | `MPCF\Plugin::wire_admin()` | priority `20` | Registers `Fulfillment Detail` as a real submenu page (so its capability/URL resolve), then immediately calls `remove_submenu_page()` — reachable only from Queue/Dashboard, never a standalone nav item. |
| `admin_menu` | action | `Vendor\Mpds\PageShell\Menu::register()` | default | Registers the top-level "Fulfillment" menu (Dashboard + Queue), invoked via `Plugin::wire_admin()`. |
| `admin_enqueue_scripts` | action | `Admin\Assets::maybe_enqueue()` | default | Enqueues MPDS + plugin admin CSS/JS, gated to this plugin's own screens (`mpcf-dashboard`, `mpcf-queue`, `mpcf-fulfillment-detail`). |
| `admin_body_class` | filter | `Admin\Assets::maybe_add_body_class()` | default | Appends `mpcf-ui-scope mpcf-admin` on this plugin's own screens. |
| `admin_body_class` | filter | `Admin\OperatorMode::maybe_add_body_class()` | default | Appends `mpcf-operator-mode` for operator-tier users when the `operator_mode_enabled` setting is on and the user is not an admin/lead — CSS then hides the rest of wp-admin's nav. |
| `woocommerce_payment_complete` | action | `Woo\IntakeHooks::handle_order_paid()` | default | Synchronous order-to-fulfillment intake on payment completion (classic and Blocks checkout). |
| `woocommerce_order_status_processing` | action | `Woo\IntakeHooks::handle_order_paid()` | default | Same intake path — covers gateways/manual-order flows that go straight to `processing` without firing `payment_complete`. |
| `mpcf_process_intake` | action + Action Scheduler | `Woo\IntakeHooks::process_scheduled_intake()` | default | Action Scheduler fallback retry when a synchronous intake attempt fails; does not reschedule itself on further failure. Scheduled via `as_enqueue_async_action()` in group `mpcf` — see `docs/PERSISTED_DATA.md`. |
| `woocommerce_order_status_cancelled` | action | `Woo\RefundObserver::handle_order_cancelled()` | default | Proposes cancel/flag-problem per the `inbound_cancel_behavior` setting. |
| `woocommerce_order_fully_refunded` | action | `Woo\RefundObserver::handle_order_fully_refunded()` | default | Proposes cancel/flag-problem per the `inbound_refund_behavior` setting. |
| `woocommerce_order_partially_refunded` | action | `Woo\RefundObserver::handle_order_partially_refunded()` | default | Always flags the fulfillment `problem` — no automatic-cancel setting exists for a partial refund. |
| `woocommerce_saved_order_items` | action | `Woo\RefundObserver::handle_order_items_saved()` | `accepted_args=2` | Diffs live order items against the intake snapshot; flags `problem` with a minimal id/qty diff on any material post-intake edit. |

`register_activation_hook()` is registered in the main file (calls
`MPCF\Plugin::activate()`, which runs `Migrator::migrate()` and grants
capabilities/roles). No deactivation hook is registered (invariant I12).
Uninstall runs via the standard `uninstall.php` file convention, not
`register_uninstall_hook()`.

## Deliberately NOT hooked (Milestone 1)

- No REST route (`mpcf/v1` is M2, per §16.2 — the workspace is what needs it).
- No `do_action()`/`apply_filters()` anywhere in `src/` — this plugin fires
  no custom action or filter yet. The `fulfillment.state_changed` event
  `Woo\StatusBridge` subscribes to travels through the plugin's own
  in-process `Application\EventDispatcher`, not a WordPress hook, and is not
  third-party-extensible today.
- No product, cart, or checkout filter beyond the two HPOS/Blocks
  compatibility declarations above — this plugin never modifies an order's
  line items, prices, totals, customer data or products (I1).

## Public extension points

None yet. `docs/ARCHITECTURE_PLAN.md` §16.2 names the eventual v1.0
extension surface (`mpcf_workflows`, `mpcf_carriers`, `mpcf_document_types`,
`mpcf_event` + per-type actions, `mpcf_workspace_flags`,
`mpcf_intake_should_create`, template overrides) — none of these exist in
the code today; they are a roadmap reference, not a hook available to
integrate against. M2's REST layer is the next place a public extension
surface is actually planned.
