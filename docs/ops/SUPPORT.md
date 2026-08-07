# Support one-pager (L1 / L2)

**Audience:** warehouse leads, site admins, L1/L2 support.  
**Plugin:** MP Commerce Fulfillment (`v0.10.0` / v1.0 line).  
**Principle:** diagnose with read-only tools first; mutate only with bounded repair.

## First response (always)

1. Confirm WooCommerce + MPCF active; HPOS enabled.
2. Run:

```bash
wp mpcf doctor
wp mpcf validate schema
wp mpcf validate schedules
wp mpcf validate storage
```

3. Open **Tools → Site Health** → MPCF operational test (same checkers; 5-minute cache).
4. Check WooCommerce → Status → Scheduled Actions, group `mpcf`.
5. Check PHP / WC logs for `mpcf` fatals.

Exit doctor **1** ⇒ treat as alert. Exit **0** with warns ⇒ review remediations.

## Common cases

| Symptom | Likely cause | Next tools | Next action |
|---|---|---|---|
| Order not in Queue | Not paid/processing; intake not run; wrong warehouse filter | WC order status; `wp mpcf intake backfill` (idempotent); Queue filters | Fix order status or backfill; do not invent fulfillments |
| Cannot transition state | Guard (items incomplete, photo required, concurrency) | Workspace banner; audit timeline; REST 422 `mpcf_guard_rejected` | Complete required work; refresh version |
| Document missing | Never rendered; protected file lost | Document history; `uploads/mpcf/documents`; doctor storage | Re-render if allowed; restore uploads backup if file gone |
| Photo missing | Soft-deleted / purged / file orphan | Gallery; media row; retention setting | Lead-only delete; restore backup; retention is intentional |
| Notification failed | Strategy/disabled; missing email; mail transport | Audit `notification.failed`; Analytics diagnostics; SMTP logs | Fix recipient/strategy; resend manual if available |
| Scanner unknown SKU | SKU mismatch; wrong stage; wave over-scan | Scan result message; item SKU snapshot | Fix product SKU / scan expected barcode; exit Scan Mode |
| Wave stuck | Members not joinable; pause/abandon; ownership | Wave Workspace status; doctor consistency | Resume/abandon per policy; packing remains per fulfillment |
| Analytics stale | Rollup schedule missing/failed; LIVE vs ROLLUP mode | Doctor schedule; `wp mpcf analytics backfill\|rebuild` | Repair schedules; explicit rebuild — do not invent history |
| Scheduled job missing | AS unscheduled after restore | Doctor `schedule.missing.*` | `wp mpcf repair schedules --yes` |
| Capability / menu missing | Role not granted | Doctor permissions; Users → roles | Re-activate plugin or `wp mpcf repair capabilities --yes` |

## Escalation

| Level | Allowed |
|---|---|
| L1 | Doctor, validate, Site Health, UI inspection, logs |
| L2 | Bounded `wp mpcf repair … --yes` (schedules, storage-dirs, schema, capabilities); audit verify; backup restore |
| Engineering | Consistency orphans (no auto-repair), audit-chain breaks, frozen-contract changes |

Never use repair to fabricate business history. Never “fix everything”.

## References

- `docs/ops/DOCTOR_AND_REPAIR.md`
- `docs/ops/MONITORING.md`
- `docs/ops/DISASTER_RECOVERY.md`
- `docs/ops/privacy.md`
- `docs/ARCHITECTURE_FREEZE.md` (ACTIVE)
