# Upgrade guide

## General upgrade flow

1. **Backup** — DB (`mpcf_*` tables + options) and `uploads/mpcf/` (see `docs/ops/DISASTER_RECOVERY.md`).
2. **Pre-upgrade doctor**:
   ```bash
   wp mpcf doctor --format=json > /tmp/mpcf-doctor-pre.json
   ```
   Resolve any `fail` results before proceeding (or document accepted warns).
3. **Replace plugin files** — upload new ZIP or deploy bind-mount; do **not** deactivate first (schema drift check runs on `admin_init`).
4. **Load wp-admin once** — triggers `Migrator::maybe_migrate()` for bind-mount deployments that skip activation.
5. **Post-upgrade doctor**:
   ```bash
   wp mpcf doctor
   wp mpcf validate schema
   ```
6. **Verify operational surfaces** — Queue, Workspace, Analytics, Site Health.

Rollback procedure: `docs/ops/ROLLBACK.md`.

---

## v0.9.0 → v0.10.0 (M10 — Operational Hardening)

**Branch:** `feature/m10-operational-hardening` → **`v0.10.0`** tag when released.  
**Schema:** migrator **TARGET 8 unchanged** — no new tables in M10.  
**Settings shape:** unchanged from v0.9.0 (settings version **9**).

### What changes

| Area | Change |
|---|---|
| CLI | `wp mpcf doctor`, `wp mpcf validate …`, `wp mpcf repair …`, extended `wp mpcf audit verify` |
| Admin | Site Health aggregate test (Tools → Site Health) |
| Privacy | WP personal data exporter/eraser; WC order anonymization sympathy |
| Maintenance audit | `maintenance.*` global events for repairs |
| Docs | `docs/ops/*`, `docs/SECURITY_REVIEW.md`, draft `ARCHITECTURE_FREEZE.md` |

No new REST routes. No Mission Control redesign. No workflow changes.

### Upgrade steps (v0.9.0 → v0.10.0)

1. Run pre-upgrade doctor (above). On v0.9.0 this command does not exist — skip or note baseline manually.
2. Deploy `v0.10.0` ZIP over `v0.9.0` files.
3. Visit wp-admin or run `wp plugin list` to confirm active.
4. Confirm schema still at target **8**:
   ```bash
   wp option get mpcf_db_version
   # Expected: 8
   ```
5. Run post-upgrade doctor — all checks should pass on a healthy site.
6. Register privacy tools automatically — no config step; verify under Tools → Export Personal Data / Erase Personal Data.
7. Optional validation pass:
   ```bash
   wp mpcf validate consistency
   wp mpcf validate analytics
   wp mpcf audit verify --all --limit=50
   ```
8. If schedules were missing: `wp mpcf repair schedules --yes` (dry-run first without `--yes`).

### Migrator note

M10 does **not** add migrator step 9. `Migrator::TARGET` remains **8** (`mpcf_analytics_daily` from M9). Downgrade to v0.9.0 leaves any forward-created repair audit events in `mpcf_events` but does not break v0.9.0 runtime.

### Acceptance after upgrade

- Analytics UI and data unchanged (rollups retained).
- Fulfillment queue and workspace behavior unchanged.
- Site Health shows MPCF test.
- Doctor exit 0 (or warn-only documented).
