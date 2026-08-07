# Disaster recovery

Recovery scope: **MPCF-owned** persistence only. WooCommerce orders, products, and customers restore via WC/backup strategy separately.

## What to backup

### Database — all `mpcf_*` tables

| Table | Priority |
|---|---|
| `mpcf_fulfillments` | Critical |
| `mpcf_fulfillment_items` | Critical |
| `mpcf_events` | Critical (audit chain) |
| `mpcf_notes` | High |
| `mpcf_shipments` | Critical |
| `mpcf_packages` | Critical |
| `mpcf_package_items` | Critical |
| `mpcf_documents` | High |
| `mpcf_media` | High (metadata; bytes in uploads) |
| `mpcf_waves` | Medium |
| `mpcf_wave_members` | Medium |
| `mpcf_analytics_daily` | Medium (rebuildable from events) |

### Options

| Option | Notes |
|---|---|
| `mpcf_settings` | Plugin configuration |
| `mpcf_db_version` | Schema version marker |

### Filesystem

```
wp-content/uploads/mpcf/
├── documents/   # Protected HTML renders
├── photos/      # Package photography bytes
└── .htaccess    # Deny stub (Apache)
```

### Not backed up by MPCF

- Action Scheduler rows (WooCommerce tables) — reschedule via `wp mpcf repair schedules --yes` after restore.
- WordPress users/roles — re-grant via plugin reactivation if needed.

## Backup commands (example)

```bash
# SQL — adjust prefix if not wp_
wp db export mpcf-backup.sql \
  --tables=wp_mpcf_fulfillments,wp_mpcf_fulfillment_items,wp_mpcf_events,wp_mpcf_notes,\
wp_mpcf_shipments,wp_mpcf_packages,wp_mpcf_package_items,wp_mpcf_documents,wp_mpcf_media,\
wp_mpcf_waves,wp_mpcf_wave_members,wp_mpcf_analytics_daily

wp option get mpcf_settings >> mpcf-options.json
wp option get mpcf_db_version >> mpcf-options.json

tar -czf mpcf-uploads.tgz -C wp-content/uploads mpcf
```

Prefer full-site backup that includes WC HPOS tables if order↔fulfillment linkage must survive.

## Restore order

1. **Restore WordPress + WooCommerce** base (files, config, WC orders).
2. **Restore `mpcf_*` tables** into the same DB (matching table prefix).
3. **Restore options** `mpcf_settings`, `mpcf_db_version`.
4. **Restore `uploads/mpcf/`** tree (documents/photos reference relative paths in DB).
5. **Deploy matching plugin version** (same or newer as backup; see `docs/ops/UPGRADE.md`).
6. **Reactivate plugin** if needed — capabilities re-granted on activation.
7. **Verify**:
   ```bash
   wp mpcf doctor
   wp mpcf validate schema
   wp mpcf audit verify --all --limit=100
   ```
8. **Repair schedules/storage** if doctor fails:
   ```bash
   wp mpcf repair schedules --yes
   wp mpcf repair storage-dirs --yes
   ```

## Partial restore

- **Single fulfillment** — not supported; restore full tables or use WC order re-intake (creates new fulfillment; does not merge history).
- **Analytics only** — restore `mpcf_analytics_daily` or rebuild: `wp mpcf analytics rebuild --from=… --to=…`.

## Integrity checks post-restore

- Hash chain: `wp mpcf audit verify <fulfillment_id>`.
- Orphan probes: `wp mpcf validate consistency`.
- Photo/document paths must exist under restored uploads tree.

## RPO / RTO

Set at infrastructure level. MPCF adds no replication. Minimum practical RPO = last backup frequency of DB + uploads.
