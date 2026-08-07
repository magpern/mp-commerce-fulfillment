# M10 — Operational Hardening & Production Readiness — Milestone Implementation Plan

**Status:** Planning complete — documentation checkpoint **2026-08-07**. Runtime implementation **not started** (awaits Part XII PO approval + explicit implementation GO).  
**Baseline:** `main` / `v0.9.0` (M9 closed).  
**Target release:** `v0.10.0`.  
**Authoritative architecture:** `docs/ARCHITECTURE_PLAN.md` **Part XII** (+ §17 / §20).  
**This file:** execution checklist and acceptance surface for implementers. It does not introduce architecture beyond Part XII.

---

## 1. Goals

1. Make MPCF **diagnosable and supportable** in production via `wp mpcf doctor` and Site Health.
2. Provide **validation** (always safe) and **repair** (explicit, audited, dry-run first) tools.
3. Ship **privacy exporter/eraser** aligned with §17.
4. Publish **ops docs**: deploy, upgrade, rollback, monitoring, capacity, disaster recovery.
5. Record **performance baselines** (~50k fulfillments methodology) and a **security review**.
6. Draft **`ARCHITECTURE_FREEZE.md`** for the subsequent **1.0** milestone.
7. Do **not** add warehouse workflows, inventory, analytics redesign, or Mission Control redesign.

## 2. Architecture summary

| Layer | M10 addition |
|---|---|
| Application | `DoctorService` / `CheckerRegistry` + structured check results; `Repair*` services |
| Infrastructure | Site Health adapters; privacy exporter/eraser; filesystem/AS probes |
| CLI | `wp mpcf doctor`, `validate …`, `repair …` (plus existing audit/analytics/intake) |
| Admin | Site Health only (no new Mission Control surfaces) |
| Docs | `docs/ops/*`, `docs/SECURITY_REVIEW.md`, draft `ARCHITECTURE_FREEZE.md` |
| Schema | Prefer **no** new tables; migrator stays at target **8** unless ADR’d catch-up |

**Default posture:** read-only. **Mutations:** `--yes` + capability + audit event.

**Failure classes:** environment · configuration · permissions · schema · consistency · storage · schedule · integration · capacity.

## 3. Data ownership & boundaries

**MPCF owns:** diagnostics results, repair audit events, privacy anonymization of MPCF snapshots/media metadata, ops documentation.

**MPCF does not own:** inventory/receiving/stock; hosting/proxy/OS hardening beyond documenting WP/Woo prerequisites; carrier networks; BI.

**Source of truth:** existing tables, events, settings, Action Scheduler, filesystem paths — **no duplicate business state**.

## 4. Phase breakdown

### M10-A — Operational diagnostics

- [ ] Part XII PO-approved; implementation GO received
- [ ] CheckerRegistry + result DTO (`id`, `status`, `class`, `message`, `remediation`, `data`)
- [ ] Checkers: env, config, capabilities, schema version/tables, AS schedules (intake / photo retention / analytics rollup), storage writability/free space, analytics health (table, obsolete rollup_version, rollup freshness), notification health (recent fail rates)
- [ ] `wp mpcf doctor` (+ `--format=json|table`, non-zero exit on `fail`)
- [ ] Unit fixtures per failure class
- [ ] `HOOKS.md` / `API.md` notes if any REST diagnostics added (optional; CLI-first)

**Exit:** Doctor runs read-only on a clean install and reports structured results.

### M10-B — Repair & validation tools

- [ ] `wp mpcf validate queue|waves|schema|storage` (read-only)
- [ ] Consistency detectors with seeded integration fixtures (orphans, wave membership, shipped-without-shipment)
- [ ] `wp mpcf repair schedules|storage-dirs|schema` with dry-run default and `--yes`
- [ ] Repair audit/maintenance events
- [ ] Extend `wp mpcf audit verify` reporting as needed
- [ ] Doctor remediation strings point at exact repair commands

**Exit:** Dry-run never writes; `--yes` repairs are idempotent and audited.

### M10-C — Site Health & privacy

- [ ] Site Health tests delegate to CheckerRegistry (parity test vs doctor)
- [ ] Privacy exporter for customer-linked fulfillments/notes/photo metadata
- [ ] Privacy eraser anonymizes without breaking hash-chain integrity
- [ ] WC order anonymization sympathy hook
- [ ] `docs/ops/privacy.md` + SECURITY updates

**Exit:** Site Health shows MPCF tests; privacy tools round-trip on fixtures.

### M10-D — Production hardening docs & baselines

- [ ] `docs/ops/PRODUCTION_DEPLOY.md`
- [ ] `docs/ops/UPGRADE.md` (include `v0.9.0` → `v0.10.0`)
- [ ] `docs/ops/ROLLBACK.md` (forward-created tables; analytics retention lesson)
- [ ] `docs/ops/MONITORING.md`, `CAPACITY.md`, `DISASTER_RECOVERY.md`
- [ ] Performance baseline evidence (~50k or documented scaled method)
- [ ] `docs/SECURITY_REVIEW.md`
- [ ] Compatibility matrix refresh
- [ ] i18n gap pass (POT/strings)
- [ ] Draft `ARCHITECTURE_FREEZE.md` for 1.0

**Exit:** Ops pack complete; freeze draft reviewed; baselines recorded.

### M10-E — Release candidate

- [ ] Dogfood doctor on long-lived `dev.biopentra.eu`
- [ ] Upgrade/rollback simulation from published `v0.9.0` ZIP → `v0.10.0` RC
- [ ] Guards: no inventory coupling; no workflow feature creep
- [ ] Version triad `0.10.0`; ZIP + release-audit; PR
- [ ] PO GO → merge/tag/publish (no silent prod deploy)
- [ ] Confirm **1.0 / M11 not started**

## 5. Acceptance criteria

See Part XII.11. Minimum dogfood script:

1. Run `wp mpcf doctor` — all pass (or documented warns only).
2. Break one schedule registration in a disposable env — doctor `fail` + Site Health critical.
3. `wp mpcf repair schedules` without `--yes` → no change; with `--yes` → schedule restored + event.
4. Seed wave orphan membership → validate waves fails; repair path documented.
5. Privacy export for fixture customer includes fulfillment ids; erase anonymizes name snapshot.
6. Confirm Analytics UI and Mission Control unchanged aside from Site Health.
7. Walk PRODUCTION_DEPLOY + UPGRADE checklists against RC ZIP.

## 6. Validation & testing

| Tier | Required |
|---|---|
| Unit | Checkers, dry-run gates, anonymize helpers |
| Integration | Doctor, Site Health registration, repairs, privacy, audit verify |
| CLI | Exit codes, JSON schema stability |
| Browser | Optional Site Health smoke only |
| Prod simulation | Upgrade/rollback ZIP path from `v0.9.0` |
| Structural | ADR-0007; Domain purity; Cli → Application only |

## 7. Release strategy

- Branch: `feature/m10-operational-hardening` from `main` / `v0.9.0`
- Version: **`0.10.0`**
- One PR; tag only after PO GO
- No production deploy inside the milestone unless separately ordered
- **Do not start 1.0 or M11** until M10 closes

## 8. Stop conditions

Inventory required; new warehouse workflow; silent destructive repair; forked Site Health logic; schema rewrite without ADR; dirty tree / concurrent agent; conflating M10 with shipping `1.0.0`.

## 9. Explicit non-goals

New warehouse workflows; inventory/purchasing/stock; barcode redesign; analytics redesign; Mission Control redesign; customer storefront features; accounting/BI; carrier label APIs; returns; webhooks; tablet PWA; breaking REST v1.

## 10. Relationship to 1.0

M10 **prepares** the freeze (`ARCHITECTURE_FREEZE.md` draft) and proves operational readiness.  
**1.0** is a separate milestone that formally freezes public surfaces and ships `1.0.0`.
