# Production deploy checklist — fresh install

Use this checklist when installing MPCF on a **new** WordPress + WooCommerce site (not an upgrade). Assumes release ZIP from GitHub Releases, not a git checkout.

## Prerequisites

| Requirement | Verify |
|---|---|
| PHP ≥ 8.1 | `php -v` |
| WordPress ≥ 6.5 | Site admin → Updates |
| WooCommerce ≥ 8.2 | Plugins → Installed |
| **HPOS enabled** | WooCommerce → Settings → Advanced → Features → **High-Performance order storage** = enabled |
| Action Scheduler | Ships with WooCommerce; required for intake fallback, photo retention, analytics rollup |
| WP-CLI (recommended) | `wp --info` on the host or via `docker compose run --rm wpcli` |

See `docs/COMPATIBILITY.md` for tested-up-to matrix.

## Install steps

1. **Upload and activate** the release ZIP (`Plugins → Add New → Upload`).
2. Confirm WooCommerce is active — the plugin is inert without it.
3. **Verify schema migration** — activation runs `Migrator::migrate()`; expect `mpcf_db_version = 8` and all `mpcf_*` tables (see `docs/PERSISTED_DATA.md`).
4. **Verify storage** — `uploads/mpcf/` root, `photos/`, `documents/` exist and are writable (activation/repair creates them).
5. **Run doctor** (read-only):
   ```bash
   wp mpcf doctor
   ```
   Exit **0** = pass or warn-only. Exit **1** = at least one `fail` — resolve before go-live.
6. **Grant capabilities** — activation creates roles `mpcf_warehouse_operator` and `mpcf_warehouse_lead` and grants all `mpcf_*` caps to `administrator` and `shop_manager`. Assign operator/lead roles to warehouse users; do not rely on `manage_options` alone for day-to-day ops.
7. **Configure settings** — Fulfillment → Settings: notification strategy, photo limits, wave limits, branding (documents), outbound bridge behavior.
8. **Smoke test intake** — place a test order through checkout; confirm one fulfillment appears in the Queue.
9. **Confirm schedules** — doctor should report Action Scheduler hooks `mpcf_purge_photo_retention` and `mpcf_analytics_daily_rollup` present. If missing: `wp mpcf repair schedules --yes`.
10. **Site Health** — Tools → Site Health → Status → **MP Commerce Fulfillment** test should be good or recommended (not critical).

## Post-deploy monitoring

- Schedule `wp mpcf doctor` in host cron or run after deploys (see `docs/ops/MONITORING.md`).
- Ensure real WP cron or system cron drives Action Scheduler (WooCommerce → Status → Scheduled Actions).

## Explicit non-goals at deploy

- No inventory/receiving plugin required (ADR-0007).
- No production schema edits — migrations are code-driven only.
- Do not enable `remove_data_on_uninstall` unless you intend full data wipe on uninstall.
