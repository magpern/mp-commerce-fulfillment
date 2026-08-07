# Rollback guide

Rollback = **replace plugin PHP/assets with a prior release ZIP**. Database and uploads are **not** automatically reverted.

## Plugin ZIP rollback

1. **Backup current state** (even when rolling back):
   ```bash
   wp db export /tmp/pre-rollback.sql
   tar -czf /tmp/mpcf-uploads.tgz -C wp-content/uploads mpcf
   ```
2. Deactivate is **optional** — replacing files on an active plugin is supported; prefer maintenance window for operator UX.
3. Install prior ZIP (e.g. `v0.9.0` over `v0.10.0`) via Plugins → Upload or filesystem deploy.
4. Load wp-admin; confirm no PHP fatals.
5. Run smoke tests: Queue list, open one fulfillment, one analytics page.

**Do not** manually drop `mpcf_*` tables unless performing a full uninstall with `remove_data_on_uninstall` (see `docs/PERSISTED_DATA.md`).

## Forward-compatible tables

Newer versions may create tables or columns older code ignores safely:

| Artifact | Rollback behavior |
|---|---|
| `mpcf_analytics_daily` (M9, step 8) | v0.8.0 ignores table; fulfillment ops unaffected. **Lesson from M9 RC:** table and rollups **persist** after rollback — disk use remains; older plugin simply does not read them. |
| `mpcf_waves` / `mpcf_wave_members` (M8) | v0.7.0 ignores; wave data retained. |
| `mpcf_media` (M6) | Earlier versions ignore; photo bytes remain under `uploads/mpcf/photos/`. |
| `maintenance.*` events (M10) | v0.9.0 does not emit or display them; rows remain in `mpcf_events` without breaking hash chains. |
| Privacy anonymization (M10) | Irreversible content scrub — rollback cannot restore anonymized names/notes/photo bytes. |

**Rule:** rollback restores **code behavior**, not deleted/anonymized content.

## Analytics table retention lesson (M9)

Simulated upgrade `v0.8.0 → v0.9.0 → rollback v0.8.0` confirmed:

- `mpcf_analytics_daily` rows remain on disk.
- Fulfillment, shipment, and event data intact.
- v0.8.0 has no fatal errors; analytics UI simply absent.

Plan capacity accordingly — rollback does not reclaim analytics storage.

## When rollback is insufficient

- **Schema corruption** — `wp mpcf repair schema --yes` (dry-run first) on the **target** version, not after rollback to older code.
- **Consistency orphans** — investigate with `wp mpcf validate consistency`; no automatic repair (business interpretation).
- **Audit chain break** — `wp mpcf audit verify <id>`; restore from DB backup if chain is genuinely broken (rare; events are append-only).

## Re-upgrade after rollback

Follow `docs/ops/UPGRADE.md`. Migrator steps are idempotent — re-running step 8 does not duplicate analytics rows (unique `(utc_date, warehouse_id)`).
