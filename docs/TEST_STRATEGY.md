# Test strategy

Three tiers, per `docs/ARCHITECTURE_PLAN.md` §19.

## Unit (`tests/unit/`, `composer test:unit`)

WordPress is not loaded (`tests/unit/bootstrap.php` is the autoloader plus a
handful of `function_exists()` stubs for the few WordPress functions the
composition root touches, matching the sibling plugins' convention). This
is where `Domain`, `Engine` and `Application` correctness will live from
Milestone 1 onward (invariant I6: those layers are WordPress-free and
unit-testable without a bootstrap). Milestone 0 has no such classes yet —
its unit suite covers `Settings`, `Capabilities`, `PersistedKeys`, the
`CompositionRootTest`, and every structural guard.

## Integration (`tests/integration/`, `composer test:integration`)

WordPress and WooCommerce are loaded (`tests/bin/install-wp.sh`, HPOS forced
on). Milestone 0's integration suite proves: activation creates zero
tables and writes `mpcf_db_version = 0`; the plugin is fully inert without
WooCommerce; deactivate/reactivate changes nothing; uninstall with the flag
off removes nothing and with it on removes exactly
`PersistedKeys::inventory()`; the migration framework resumes correctly
after a simulated interruption using an injected fake step map; the
`admin_init` drift-check fires against a stale recorded version.

`HposProofTest` mirrors the sibling plugins' pattern: it skips when HPOS is
off, so a green run with zero skips is itself the proof HPOS was active.

## Structural guards (mutation-verified)

Regex-over-`src/` and, where needed, live introspection. Each guard is
proven by injecting the violation it exists to catch and confirming the
test fails before confirming it passes clean — the evidence is recorded in
the PR, not just asserted.

| Guard | Enforces |
|---|---|
| `DomainPurityGuardTest` | No WordPress/WooCommerce symbol in `Domain`/`Engine`/`Application` (invariant I6). Passes vacuously in M0 (those namespaces are still empty) and starts doing real work the moment Milestone 1 adds a class to them. |
| `DbConfinementGuardTest` | `$wpdb` appears only in `src/Infrastructure/Database/` (I7). |
| `WooConfinementGuardTest` | No WooCommerce class/hook named outside `src/Woo/` (I8). |
| `LegacyOrderStorageGuardTest` | No `get_post()`/`get_post_meta()`/direct `wp_posts` or `wp_postmeta` access on an order, anywhere (I2). |
| `CompositionRootTest` | `MPCF\Plugin` is the only class that instantiates its peers; enumerates the wired object graph and fails if it contains anything beyond the Milestone 0 scope (no placeholder services). |
| `PersistedKeysInventoryTest` | `src/PersistedKeys.php` and `docs/PERSISTED_DATA.md` agree. |
| `UninstallPolicyGuardTest` | `uninstall.php` removes exactly `PersistedKeys::inventory()` when enabled, and nothing when disabled. |
| `MpdsVendorGuardTest` | The vendored `src/Vendor/Mpds/` copy matches its committed `MANIFEST` — a hand-edit fails this test. |
| `CiMatrixGuardTest` / `CompatibilityMatrixTest` | The CI workflow's version coordinates match `docs/COMPATIBILITY.md` and the plugin header. |
| `PluginVersionTest` | The plugin header `Version:`, `MPCF_VERSION`, and `readme.txt` Stable tag agree. |

## Docker-only tooling

The reference development host has no PHP, Composer, or Node installed —
everything runs through Docker. See the gitignored `CLAUDE.local.md` for the
exact commands; `.github/workflows/ci.yml` is the portable reference setup.
