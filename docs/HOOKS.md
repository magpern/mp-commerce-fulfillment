# Hooks

Every hook this plugin registers, and everything it deliberately does not
hook. Synced against the code by `HooksDocumentationSyncTest`.

## Registered by this plugin

| Hook | File | Priority | Purpose |
|---|---|---|---|
| `before_woocommerce_init` | `mp-commerce-fulfillment.php` | default | Declares `custom_order_tables` and `cart_checkout_blocks` compatibility. Registered unconditionally, even if `MPCF\Plugin` never boots. |
| `plugins_loaded` | `mp-commerce-fulfillment.php` | default | Guards WooCommerce presence and boots `MPCF\Plugin::instance()->init()`. |
| `admin_init` | `MPCF\Plugin::init()` | default | Runs `Migrator::maybe_migrate()` — the drift check for bind-mount deployments that never fire the activation hook (see `docs/ARCHITECTURE_PLAN.md` §7). |
| `init` | `MPCF\Plugin::init()` | default | Loads the plugin text domain. |

`register_activation_hook()` is registered in the main file (calls
`MPCF\Plugin::activate()`, which runs `Migrator::migrate()` and grants
capabilities/roles). No deactivation hook is registered (invariant I12).

## Deliberately NOT hooked (Milestone 0)

- No `woocommerce_*` order, cart, checkout, payment, or product filter of
  any kind. Milestone 0 does not read or write an order.
- No admin menu, admin screen, or admin asset enqueue.
- No REST route.
- No Action Scheduler action (Milestone 1 introduces the intake hooks that
  need it).

## Public extension points

None yet. Milestone 1 onward introduces `mpcf_workflows`, `mpcf_carriers`,
`mpcf_document_types`, `mpcf_event` (+ per-type actions),
`mpcf_workspace_flags`, `mpcf_intake_should_create`, and template overrides
— see `docs/ARCHITECTURE_PLAN.md` §16.2.
