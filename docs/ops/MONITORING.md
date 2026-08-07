# Monitoring guide

M10 adds **CLI-first** diagnostics; no dedicated REST monitoring endpoints.

## `wp mpcf doctor` exit codes

| Exit | Meaning | Action |
|---|---|---|
| **0** | All checks `pass`, or only `warn` | OK for cron/monitoring; review warns in output |
| **1** | One or more checks `fail` | Alert; read remediation lines; see `docs/ops/DOCTOR_AND_REPAIR.md` |

JSON for automation:

```bash
wp mpcf doctor --format=json
```

Cron example (daily, alert on non-zero):

```bash
0 6 * * * cd /path/to/wp && wp mpcf doctor --format=json || logger -t mpcf-doctor "FAIL"
```

### Check categories (summary)

| Checker id | Watches |
|---|---|
| `environment` | PHP/WP floors, required PHP extensions, commerce platform helpers (`wc_get_orders`) — **not** a full WC-version/HPOS assertion |
| `configuration` | Settings option shape, `mpcf_db_version` |
| `permissions` | Role/cap grants |
| `schema` | Tables, indexes, version match |
| `consistency` | Orphan rows, shipped-without-shipment |
| `storage` | `uploads/mpcf` dirs, deny file, disk free (<50MB → warn) |
| `schedule` | AS hooks: photo retention, analytics rollup |
| `integration` | REST route registration, `wp_mail` availability |
| `capacity` | Row counts; warn at ≥50k fulfillments; obsolete analytics rollups |

## Action Scheduler failures

| Signal | Where |
|---|---|
| Doctor `schedule.missing.*` | Recurring hook not scheduled → `wp mpcf repair schedules --yes` |
| Doctor `schedule.backlog.*` | >5 pending actions on a hook → inspect WooCommerce → Status → Scheduled Actions, group `mpcf` |
| Stuck intake | `mpcf_process_intake` async actions; check WC logs source `mpcf` |

Ensure site cron or system cron processes Action Scheduler (WooCommerce dependency).

## Disk space

- Doctor `storage.disk_free` warns below **50 MB** on uploads volume.
- Photo growth: `photos_retention_months` setting + daily `mpcf_purge_photo_retention`.
- Documents and photos live under `uploads/mpcf/` — monitor volume size independently.

See `docs/ops/CAPACITY.md`.

## Notification failures

Doctor checks `wp_mail()` availability (`integration.wp_mail`). It does **not** compute send failure rates.

Monitor separately:

- Analytics → Diagnostics (admin) — notification failed counts from rollups/events.
- Audit events `notification.failed` in fulfillment timelines.
- WooCommerce / SMTP plugin logs for delivery issues.

## Site Health

**Tools → Site Health → Status** includes **MP Commerce Fulfillment** (`mpcf_operational`):

| Site Health status | Maps from doctor |
|---|---|
| **Good** | All pass |
| **Recommended** | Warns only |
| **Critical** | Any fail |

Results cached **5 minutes** (`mpcf_site_health_ops` transient). Same `CheckerRegistry` as CLI — no forked logic.

After repairs or config changes, re-run doctor or wait for cache expiry.

## What M10 does not monitor

- External carrier APIs (no live tracking probes).
- Inventory/stock (ADR-0007 — out of scope).
- Host-level CPU/RAM (document at infrastructure layer).
