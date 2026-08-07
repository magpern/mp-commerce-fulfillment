# Security review — M10 (Operational Hardening)

**Scope:** M10 additions on branch `feature/m10-operational-hardening` targeting `v0.10.0`.  
**Method:** Code review of diagnostics, privacy, Site Health, repair CLI; structural guard suite; REST/capability patterns inherited from M2–M9.  
**Date:** 2026-08-07  
**Status:** No release blockers identified in automated structural review or targeted M10 code inspection.

This document records **reviewed areas and outcomes**. It is not a penetration test certificate.

---

## Reviewed areas

| Area | What was reviewed | Outcome |
|---|---|---|
| **Capabilities** | `MPCF\Capabilities` central registry; `PermissionsChecker`; no `manage_options` shortcuts in permission checks outside documented operator-mode exception | Pass — caps granted on activation; doctor verifies admin has `mpcf_manage_settings` |
| **REST API** | Existing `mpcf/v1` routes unchanged in M10; per-route capability callbacks | Pass — no new REST surface in M10; prior matrix unchanged (`docs/API.md`) |
| **Storage** | Protected `uploads/mpcf/` (ADR-0004); deny stub; content served via capability-checked REST only | Pass — `StorageChecker` probes writability; repair creates dirs, not world-readable URLs |
| **CLI `--yes` policy** | `RepairCommand` dry-run default; `--yes` gates writes; repairs bounded to schedules/storage-dirs/schema | Pass with **operational note** — WP-CLI trusts shell identity; no in-command capability check (standard WP-CLI pattern). Restrict CLI access on production hosts. |
| **Privacy exporter/eraser** | `PrivacyRegistrar`; email-keyed lookup; no binary file export; eraser retains hash chain | Pass — no mass delete of fulfillments; audit integrity preserved |
| **WC privacy sympathy** | `Woo\PrivacyHooks` confined to `src/Woo/` (I8) | Pass — order anonymization triggers bounded erase |
| **Site Health** | `SiteHealthRegistrar` delegates to `CheckerRegistry`; read-only; 5-min cache | Pass — no mutation paths |
| **Maintenance audit** | `MaintenanceAuditor` global events; payload must not contain secrets (docblock contract) | Pass — repair services pass structured non-secret payloads |
| **Diagnostics SQL** | `WpdbDiagnosticsReader` read-only probes | Pass — confined to `Infrastructure/Database/` (I7) |
| **Inventory coupling** | ADR-0007; no stock mutation; bridge hooks are store-order only | Pass — M10 adds no inventory tables or hooks |
| **Append-only audit** | `AuditAppendOnlyGuardTest`; privacy anonymizer avoids payload/hash updates | Pass |
| **Domain purity** | `DomainPurityGuardTest`, `WooConfinementGuardTest` | Pass — green in CI |

## M10-specific surfaces

| Surface | Risk class | Mitigation |
|---|---|---|
| `wp mpcf doctor` | Information disclosure (config/version counts) | CLI-only; same data visible to admins in Site Health summary |
| `wp mpcf repair schema` | Schema mutation | Dry-run default; migrator idempotent steps only |
| `wp mpcf validate consistency` | Information disclosure (orphan counts) | CLI-only; read-only |
| Privacy export | PII export to authorized WP privacy officers | Core WP privacy tool auth |

## Not reviewed in this pass

- Third-party WooCommerce extensions interaction matrix (see `docs/COMPATIBILITY.md`).
- Host/OS hardening, TLS, firewall (out of plugin scope — see ops deploy docs).
- Dedicated notification-failure rate checker (not implemented — monitor via analytics/events).

## Findings

**Release blockers:** none identified from structural guards + M10 code review.

**Recommendations (non-blocking):**

1. Run `wp mpcf doctor` after every production deploy (documented in ops checklists).
2. Limit WP-CLI and Application Password access on production.
3. ~~Complete 50k performance baseline on RC~~ **Done in P3** — see `docs/ops/PERFORMANCE_BASELINE.md` and `docs/certification/P3_OPERATIONAL_CERTIFICATION_REPORT.md`.

## P3 Operational Certification addendum (2026-08-07)

**Method:** Re-ran structural security unit filter (40 OK), `OperationalHardening` + capability/privacy integration (11 OK), privacy exporter/eraser hook registration, CLI `--yes` dry-run defaults unchanged. No new public contracts. No FROZEN surface changes.

**Outcome:** No release-blocking security or privacy defects. Prior recommendations 1–2 remain operational guidance. Recommendation 3 closed by P3-A.

## Regression gates

Continue enforcing: phpcs, unit + integration suites, `OperationalHardeningTest`, structural guards, release-audit ZIP denylist (ADR-0006).
