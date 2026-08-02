# Test strategy

Three tiers, per `docs/ARCHITECTURE_PLAN.md` §19.

## Unit (`tests/unit/`, `composer test:unit`)

WordPress is not loaded (`tests/unit/bootstrap.php` is the autoloader plus a
handful of `function_exists()` stubs for the few WordPress functions the
composition root touches, matching the sibling plugins' convention). This is
where `Domain`, `Engine` and `Application` correctness lives (invariant I6:
those layers are WordPress-free and unit-testable without a bootstrap).
Milestone 1 filled these layers in for the first time: `WorkflowEngine`
transition/guard tables, `WorkflowDefinition::validate()`, `Event\Canonicalizer`
determinism, hash-chain computation, `SearchTermClassifier` classification,
`WorkflowService`/`EventDispatcher` behavior against hand-written repository
fakes, plus every structural guard. Milestone 0's own coverage (`Settings`,
`Capabilities`, `PersistedKeys`, `CompositionRootTest`) is unchanged.

## Integration (`tests/integration/`, `composer test:integration`)

WordPress and WooCommerce are loaded (`tests/bin/install-wp.sh`, HPOS forced
on). Milestone 1 exercises this tier for real for the first time: classic
and Blocks-checkout intake idempotency (`woocommerce_payment_complete` /
`woocommerce_order_status_processing`, pay-twice creates no duplicate);
`wp mpcf intake backfill` idempotency; migration to `mpcf_db_version = 3`
creates exactly the specified tables/indexes; `WorkflowService`'s
optimistic-lock conflict (concurrent `version` mismatch, zero rows
affected); `Woo\StatusBridge` outbound (shipped → WC `completed`) and
`Woo\RefundObserver` inbound (WC cancel/refund/item-change → `cancelled`/
`problem`), both loop-guard-proven; the Admin screens' real capability/role
enforcement (`Admin\MenuVisibilityTest` fires the real `admin_menu` action
and inspects WordPress's own `$menu`/`$submenu` globals); an Action
Scheduler intake-fallback burst test (spike S5); and the full uninstall
keep/remove lifecycle including the `mpcf` Action Scheduler group.

Milestone 0's own coverage (activation creates zero tables and writes
`mpcf_db_version = 0` when run in isolation; the plugin is fully inert
without WooCommerce; deactivate/reactivate changes nothing; the migration
framework resumes correctly after a simulated interruption using an
injected fake step map; the `admin_init` drift-check fires against a stale
recorded version) is unchanged, updated only where the schema version
itself moved (0 → 3).

`HposProofTest` mirrors the sibling plugins' pattern: it skips when HPOS is
off, so a green run with zero skips is itself the proof HPOS was active.

## Structural guards (mutation-verified)

Regex-over-`src/` and, where needed, live introspection. Each guard is
proven by injecting the violation it exists to catch and confirming the
test fails before confirming it passes clean — the evidence is recorded in
the PR, not just asserted.

| Guard | Enforces |
|---|---|
| `DomainPurityGuardTest` | No WordPress/WooCommerce symbol in `Domain`/`Engine`/`Application` (invariant I6). Real from Milestone 1 onward — those namespaces hold the workflow engine, aggregates, and application services. |
| `DbConfinementGuardTest` | `$wpdb` appears only in `src/Infrastructure/Database/` (I7). |
| `WooConfinementGuardTest` | No WooCommerce class/hook named outside `src/Woo/` (I8). |
| `LegacyOrderStorageGuardTest` | No `get_post()`/`get_post_meta()`/direct `wp_posts` or `wp_postmeta` access on an order, anywhere (I2). |
| `SingleStateWriterGuardTest` | Only `Application\WorkflowService` calls `Domain\Fulfillment::apply_transition()` — the single writer of fulfillment state (I4). |
| `AuditAppendOnlyGuardTest` | Only `Infrastructure\Database\WpdbEventRepository` names `mpcf_events`, and that class contains no `UPDATE`/`DELETE` against it (I5). |
| `AdminBoundaryGuardTest` | Admin screens call Application-layer services only, never a repository or `$wpdb` directly (I11) — the same services a future REST layer will call. |
| `CompositionRootTest` | `MPCF\Plugin` is the only class that instantiates its peers; enumerates the wired object graph against an explicit allowlist and fails on any service constructed ahead of the milestone that deliberately added it. |
| `PersistedKeysInventoryTest` | `src/PersistedKeys.php` and `docs/PERSISTED_DATA.md` agree. |
| `UninstallPolicyGuardTest` | `uninstall.php` removes exactly `PersistedKeys::inventory()` when enabled, and nothing when disabled. |
| `MpdsVendorGuardTest` | The vendored `src/Vendor/Mpds/` copy matches its committed `MANIFEST` — a hand-edit fails this test. |
| `CiMatrixGuardTest` / `CompatibilityMatrixTest` | The CI workflow's version coordinates match `docs/COMPATIBILITY.md` and the plugin header. |
| `PluginVersionTest` | The plugin header `Version:`, `MPCF_VERSION`, and `readme.txt` Stable tag agree. |

## Docker-only tooling

The reference development host has no PHP, Composer, or Node installed —
everything runs through Docker. See the gitignored `CLAUDE.local.md` for the
exact commands; `.github/workflows/ci.yml` is the portable reference setup.
