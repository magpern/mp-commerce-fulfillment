# Doctor, validate, repair, and audit verify

M10 operational CLI reference. All commands require WP-CLI and an bootstrapped WordPress install.

## `wp mpcf doctor`

Read-only aggregate health check. Uses shared `CheckerRegistry` (same logic as Site Health).

```bash
wp mpcf doctor [--check=<id>] [--format=table|json]
```

| Flag | Purpose |
|---|---|
| `--check=<id>` | Single checker: `environment`, `configuration`, `permissions`, `schema`, `consistency`, `storage`, `schedule`, `integration`, `capacity` |
| `--format=json` | Machine-readable full report |

**Exit codes:** `0` = pass or warn-only; `1` = any `fail`.

Remediation lines print after the summary table for non-pass checks.

## `wp mpcf validate <target>`

Read-only focused validation. Never mutates.

```bash
wp mpcf validate <target> [--format=table|json]
```

| Target | Scope |
|---|---|
| `schema` | Tables, version, indexes |
| `storage` | Upload dirs, writability |
| `schedules` | Action Scheduler recurring hooks |
| `consistency` | All consistency probes |
| `fulfillments` | Consistency minus wave-specific |
| `waves` | Wave membership orphans only |
| `analytics` | Analytics rollup health |

**Exit codes:** `0` = pass; `1` = any `fail`.

## `wp mpcf repair <target>`

Bounded repairs. **Dry-run by default** — no writes without `--yes`.

```bash
wp mpcf repair <schedules|storage-dirs|schema> [--yes] [--format=table|json]
```

| Target | What it does |
|---|---|
| `schedules` | Re-register missing AS recurring hooks (`mpcf_purge_photo_retention`, `mpcf_analytics_daily_rollup`) |
| `storage-dirs` | Create missing `uploads/mpcf` dirs; write deny `.htaccess` where supported |
| `schema` | Run migrator catch-up to `TARGET` (8) |

Applied repairs emit `maintenance.repair.*` global audit events (no fulfillment id).

### `--yes` policy

| Rule | Detail |
|---|---|
| Default | **Dry-run** — prints planned changes, exits without mutation |
| `--yes` required | Any filesystem, schedule, or schema write |
| Idempotent | Safe to re-run; already-fixed state → no-op |
| Never auto | Consistency/orphan business issues — **no repair CLI** |
| Shell trust | WP-CLI assumes operator with filesystem/DB access; no separate web UI for repairs in M10 |
| Audit | Every applied repair logged via `MaintenanceAuditor` |

**Workflow:**

```bash
wp mpcf repair schedules          # inspect
wp mpcf repair schedules --yes      # apply
wp mpcf doctor                      # confirm
```

## `wp mpcf audit verify`

Hash-chain integrity for append-only audit.

```bash
wp mpcf audit verify <fulfillment_id>
wp mpcf audit verify --all [--limit=500]
```

| Mode | Exit |
|---|---|
| Single fulfillment OK | 0 |
| Single failure | error message, non-zero |
| `--all` with any failure | 1 |

Does not repair broken chains — restore from backup or investigate manual DB tampering (events table is append-only by design; `AuditAppendOnlyGuardTest` enforces).

## Site Health parity

Tools → Site Health uses the **same** checkers as doctor (5-minute cache). If doctor and Site Health disagree, wait for cache expiry or compare timestamps — logic is shared, not duplicated.

## What repair will never do (M10)

- Force workflow transitions or ship orders
- Delete fulfillment rows or truncate events
- Adjust inventory/stock (ADR-0007)
- Mass-cancel unrelated Action Scheduler jobs

See `docs/ops/MONITORING.md` for scheduling doctor in cron.
