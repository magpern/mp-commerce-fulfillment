# M10 Release Report — Operational Hardening & Production Readiness (`v0.10.0`)

**Baseline:** `v0.9.0` / branch `feature/m10-operational-hardening` from `907bbca`  
**Schema:** migrator target **8** (unchanged; no schema bump)  
**Status:** **Released.** M10 closed. **v1.0 rollout has not started.** Production not deployed.  
**Architecture freeze:** `docs/ARCHITECTURE_FREEZE.md` remains **DRAFT** until the v1.0 approval process.

## Architecture delivered

| Area | Delivered |
|---|---|
| Diagnostics | `CheckerRegistry` + `DoctorService` + `wp mpcf doctor` |
| Validation | `wp mpcf validate` (`schema\|storage\|schedules\|consistency\|fulfillments\|waves\|analytics`) |
| Repair | `wp mpcf repair` (`schedules\|storage-dirs\|schema\|capabilities`) dry-run / `--yes`; `maintenance.*` audit |
| Audit | `wp mpcf audit verify` (+ `--all`) |
| Site Health | `mpcf_operational` over shared checker registry |
| Privacy | WP exporter/eraser; WC sympathy via `Woo\PrivacyHooks`; hash chain preserved |
| Ops docs | `docs/ops/*`, `docs/SECURITY_REVIEW.md`, draft `docs/ARCHITECTURE_FREEZE.md` |

## Decisions (binding)

| Topic | Decision |
|---|---|
| Version | **`v0.10.0`** (not 1.0.0) |
| Schema | Migrator **TARGET remains 8** |
| Inventory | No inventory/receiving/stock coupling (ADR-0007) |
| Repair | Bounded targets only — no “fix everything” |
| Diagnostics | Doctor / Site Health / validate are read-only for business state |
| Freeze | Architecture freeze DRAFT until v1.0 approval |

## Validation evidence

| Check | Result |
|---|---|
| Unit / integration / PHPCS / POT / browser | Full PR **#8** matrix green at RC HEAD `907bbca` |
| Clean install (RC ZIP) | WP **7.0.2** / PHP **8.4.23** / WC **10.9.4** — activate → migrator **8**, tables, AS, REST, Site Health, privacy, CLI OK |
| Upgrade `v0.9.0` → RC | Data/hashes/payloads retained; TARGET 8; doctor/validate/privacy/audit verify OK |
| Rollback RC → `v0.9.0` | No fatal; operational data retained; DB stays at 8; M10 CLI/Site Health/privacy absent (expected) |
| Doctor / repair | Missing schedule (same-request), storage dirs, capabilities, schema option; dry-run no mutate; `--yes` bounded + maintenance audit |
| Privacy / audit | Export + erase anonymize PII; hashes/payloads/`prev_hash` unchanged; `audit verify` OK |
| Read-only invariant | Doctor/validate/Site Health do not mutate fulfillments/qty/shipments/waves/hashes |

## Release publication

| Field | Value |
|---|---|
| PR | https://github.com/magpern/mp-commerce-fulfillment/pull/8 |
| RC HEAD (pre-merge) | `907bbca907cdad29f9202156bec4036037524e6d` |
| Merge commit | `65c9a21c46abd4c41f26b3a3ca453b4065dc5eb0` |
| Tag | `v0.10.0` → `65c9a21c46abd4c41f26b3a3ca453b4065dc5eb0` |
| GitHub Release | https://github.com/magpern/mp-commerce-fulfillment/releases/tag/v0.10.0 |
| Release workflow | https://github.com/magpern/mp-commerce-fulfillment/actions/runs/31181099814 — **success** |
| Published asset | `mp-commerce-fulfillment-0.10.0.zip` |
| Local SHA-256 (merged main rebuild) | `189f9e534faacbe7e25fb8507e8ed3425d4ce67012df0df2208539be55e7b400` |
| Published SHA-256 | `1c44029935460553505cd8bf3d6d908a2cc3ab5d18c1a52b1ba4d15e2b97cd85` |
| SHA delta | Expected archive/Composer metadata differences only; version parity and M10 runtime paths verified in published ZIP |

## Explicit confirmations

- Merged to `main` via PR #8 (merge commit)
- Tagged `v0.10.0` on merge commit (not the pre-merge feature tip alone)
- Published to GitHub Releases
- **Not** deployed to production
- **M10 closed**
- **v1.0 rollout has not started**
- **`docs/ARCHITECTURE_FREEZE.md` remains DRAFT** until v1.0 approval

## Explicit non-goals (unchanged)

Production deploy; v1.0 commercial packaging; inventory/receiving coupling; schema bump; “fix everything” repairs; rewriting audit payloads/hashes for privacy.

## Packages

| Package | Outcome |
|---|---|
| M10-A | CheckerRegistry + DoctorService + `wp mpcf doctor` + `wp mpcf audit verify` |
| M10-B | validate + repair CLI, dry-run/`--yes`, maintenance audit |
| M10-C | Site Health + privacy exporter/eraser + WC sympathy |
| M10-D | ops docs, security review, architecture freeze draft |
| M10-E | Dogfood, upgrade/rollback verification, `v0.10.0` release |
