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

## Performance (`tests/integration/Performance/`, `phpunit-performance.xml.dist`)

A fourth, deliberately separate tier: `QueuePerformanceProofTest` seeds
10,000 fulfillments and proves every real Queue/Dashboard query shape
stays indexed at that scale (Architecture Plan §III.2.2/acceptance
criterion 3). Not part of `composer test:integration` or CI — excluded
explicitly in `phpunit-integration.xml.dist` — because seeding 10k rows on
every run would slow the whole suite down for no ongoing benefit once
this proof has run once per schema change. Run explicitly (see that test
file's own docblock for the command); findings are recorded in
`docs/QUEUE_PERFORMANCE_VALIDATION.md` and must be rerun and updated
whenever the schema, an index, or one of these query shapes changes.

## Browser (`tests/browser/`, dev/CI only, `npx playwright test`)

Milestone 2 (F22, ADR-0006) adds a fourth tier for real browser behavior
PHPUnit structurally cannot observe: keyboard-only operation, focus
management, accessibility, and print rendering. Never part of
`composer test:unit`/`test:integration` — a separate `browser` CI job,
running after both PHPUnit tiers, provisions a real running WordPress +
WooCommerce site (`tests/bin/install-wp-site.sh`, distinct from
`tests/bin/install-wp.sh`'s file-only PHPUnit fixture), seeds one paid
order via `wp eval-file tests/browser/seed.php` (the plugin's real intake
hook, not a direct table insert), and serves it with PHP's built-in
server. `package.json`, `playwright.config.js` and `tests/browser/` are
committed but never shipped — enforced by three independent defenses
(`bin/build-zip.sh`'s post-copy assertion, `bin/release-audit.sh`'s zip
denylist, `ReleaseArtifactGuardTest`), per ADR-0006's own requirement that
a single missed exclusion must not be enough to reintroduce a Node
runtime dependency. This first pass covers the keyboard-only
queued-to-shipped path (acceptance criterion 1) and an `@axe-core/playwright`
accessibility scan of the workspace (criterion 8); the two-browser-context
409 conflict, offline/retry interception, the three responsive
breakpoints, and queue-cursor navigation are deliberately not yet covered
— a scope decision recorded here rather than silently left uncovered,
and available to a follow-up commit without any change to the harness.

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
| `ReleaseArtifactGuardTest` | No Node/Playwright artifact and no runtime Composer dependency ever reaches a release build (ADR-0006) — `bin/build-zip.sh`, `bin/release-audit.sh`, `.gitignore`, `composer.json`, and `package.json` all carry the required guard. |

## Docker-only tooling

The reference development host has no PHP, Composer, or Node installed —
everything runs through Docker. See the gitignored `CLAUDE.local.md` for the
exact commands; `.github/workflows/ci.yml` is the portable reference setup.
