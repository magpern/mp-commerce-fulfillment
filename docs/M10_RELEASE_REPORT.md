# M10 Release Candidate Report — Operational Hardening (`v0.10.0`)

**Status:** Release Candidate — awaiting Product Owner approval.  
**Branch:** `feature/m10-operational-hardening`  
**Not done:** merge, tag, publish, production deploy, v1.0 rollout.

## Version / schema

| Item | Value |
|---|---|
| Version triad | `0.10.0` (header / `MPCF_VERSION` / Stable tag) |
| Migrator `TARGET` | **8** (unchanged; no schema bump) |
| ADR-0007 | Unchanged — no inventory/receiving coupling |

## Delivered packages

| Package | Outcome |
|---|---|
| M10-A | CheckerRegistry + DoctorService + `wp mpcf doctor` + `wp mpcf audit verify` |
| M10-B | `wp mpcf validate …`, `wp mpcf repair …` (schedules, storage-dirs, schema, capabilities), dry-run/`--yes`, `maintenance.*` audit |
| M10-C | Site Health adapter over shared registry; privacy exporter/eraser; WC sympathy via `Woo\PrivacyHooks` |
| M10-D | `docs/ops/*`, `docs/SECURITY_REVIEW.md`, draft `docs/ARCHITECTURE_FREEZE.md` |
| M10-E | Dogfood on long-lived WP; upgrade/rollback simulation path documented; RC packaging |

## Dogfood (dev.biopentra.eu bind-mount)

1. `wp mpcf doctor` initially: fail on missing `uploads/mpcf/photos` + missing `mpcf_delete_photos` on Lead; warn on unset settings option.
2. `wp mpcf repair storage-dirs --yes` restored photo root.
3. `wp mpcf repair capabilities --yes` granted `mpcf_delete_photos` to Lead.
4. Re-run doctor: **pass=45 warn=1 fail=0** (settings option warn only).
5. `wp mpcf validate schema` — passed.
6. `wp mpcf repair schedules` without `--yes` — no change when healthy.
7. `wp mpcf audit verify --all --limit=20` — ok=20 fail=0.
8. `mpcf_db_version` remains **8**.

## Tests

- Unit: 602 tests green (4 skipped).
- Integration: OperationalHardeningTest green; full suite re-run for RC.
- PHPCS: clean on M10 paths.
- Architecture guards: Domain purity / `$wpdb` / Woo / audit-append-only (GDPR anonymizer allowlisted).

## Performance baseline

See `docs/ops/PERFORMANCE_BASELINE.md` — methodology recorded; full 50k timings pending optional follow-up measurement (not invented). Existing Queue performance harness remains available.

## Architecture freeze

`docs/ARCHITECTURE_FREEZE.md` status: **DRAFT** — becomes binding at v1.0 approval only.

## Stop confirmations

- v0.10.0 not merged / tagged / published
- Production not deployed
- v1.0 rollout not started
- No schema bump beyond TARGET 8
- No inventory/receiving coupling
