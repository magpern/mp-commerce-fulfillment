# v1.0.0 Release Report — Architecture Freeze & Production Readiness (P4)

**Kind:** Administrative release — **no new functionality** vs certified `v0.10.0`.  
**Program:** `docs/plans/V1_PRODUCTION_READINESS.md`  
**Architecture Freeze:** **ACTIVE** (`docs/ARCHITECTURE_FREEZE.md`)  
**Schema:** Migrator **TARGET 8** (unchanged)  
**Status:** **Released.** P4 **COMPLETE**. Production **not** deployed. P5 **READY** (not started). Hypercare **not** started.

---

## Program archive

| Phase | Verdict / outcome | Evidence |
|---|---|---|
| **P1** Architecture Freeze | **COMPLETE** — Freeze **ACTIVE** | `docs/ARCHITECTURE_FREEZE.md` |
| **P2** Regression Certification | **PASS WITH DOCUMENTED NON-BLOCKING LIMITATIONS** | `docs/certification/P2_REGRESSION_CERTIFICATION_REPORT.md` |
| **P3** Operational Certification | **PASS WITH DOCUMENTED NON-BLOCKING LIMITATIONS** | `docs/certification/P3_OPERATIONAL_CERTIFICATION_REPORT.md` |
| **P4** v1.0 Release | **COMPLETE** — immutable ZIP published | This document |

---

## Final certification review (pre-merge / pre-tag)

| Check | Result |
|---|---|
| Release blockers | None |
| Frozen-contract conflicts | None |
| Open HIGH defects | None |
| Security blockers | None |
| Unresolved production blockers | None |

### Remaining documented non-blocking limitations

1. Browser harness flakes/retries (CI retry; acceptable)
2. PHP 8.5 not claimed supported (CI 8.1/8.4; local 8.5 Reflection noise)
3. Doctor `configuration.settings_option` warn until settings saved once
4. Customer-prefix search listing may EXPLAIN `ALL` on huge IN lists at ~50k (lookup indexed)
5. AS unschedule may be re-asserted by bootstrap before doctor observes missing
6. Rollback to `v0.9.0` lacks M10 CLI (`doctor`, etc.) by design
7. WP-CLI trusts shell identity (operational recommendation: restrict CLI on hosts)

---

## Release publication

| Field | Value |
|---|---|
| P3 PR | https://github.com/magpern/mp-commerce-fulfillment/pull/10 |
| Merge commit (P3 → main) | `8991408bbc496ba156fe2ae916e3fa660cfc3b5e` |
| Release commit (version triad `1.0.0`) | `38f0c42de7deeb8e24987e1595d915c6207c17ca` |
| Annotated tag | `v1.0.0` → `38f0c42de7deeb8e24987e1595d915c6207c17ca` |
| GitHub Release | https://github.com/magpern/mp-commerce-fulfillment/releases/tag/v1.0.0 |
| Release workflow | https://github.com/magpern/mp-commerce-fulfillment/actions/runs/31212421969 (**success**) |
| Published ZIP | `mp-commerce-fulfillment-1.0.0.zip` |
| Download | https://github.com/magpern/mp-commerce-fulfillment/releases/download/v1.0.0/mp-commerce-fulfillment-1.0.0.zip |
| Local SHA-256 | `016896d563e926a79d7e2ca884a0ad9c0e3bf8c8949d85c4c9439ce1815fbbed` |
| Published SHA-256 | `b236970280467149f2bd9ea16692afab46eeb25f5d5bdbc2186a54b95c41b9e4` |

**Canonical production artifact for P5:** the **published** ZIP (SHA-256 above). Do not deploy local builds or bind-mounts.

### Local vs published ZIP delta (expected)

- Empty directory `src/Admin/assets/` present in some local zippers, omitted in Actions zip (no files).
- Composer `vendor/composer/installed.php` / `vendor/bin` archive/install metadata may differ between local and Actions `--no-dev` builds.
- **No** runtime source differences excluding `vendor/` metadata; **no** tests, Playwright, `node_modules`, or certification harness in published ZIP.

---

## Verified in published ZIP

| Surface | Present |
|---|---|
| Version triad `1.0.0` | Yes |
| Migrator TARGET 8 | Yes |
| Doctor / validate / repair / audit CLI | Yes |
| Site Health registrar | Yes |
| Privacy exporter/eraser | Yes |
| Analytics | Yes |
| Wave picking | Yes |
| Scan mode assets | Yes |
| Photography assets | Yes |
| Notifications | Yes |
| Document templates | Yes |
| Prohibited artifacts (tests / node / Playwright) | Absent |

---

## Confirmations

- Architecture Freeze remains **ACTIVE**
- Production **not** deployed
- Hypercare **not** started
- P5 **not** started (READY)
- `v1.0.0` is the canonical production artifact
