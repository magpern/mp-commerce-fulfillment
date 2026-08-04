# Dogfood Lessons

Permanent warehouse UX backlog for MP Commerce Fulfillment.

Every operational observation is recorded here first, classified, and either
marked **Fixed in v0.3.0** or given a **milestone target**. This file is the
single operational backlog — release reports summarize outcomes and must not
duplicate these entries.

Entry fields: date, scenario, observation, classification, decision, target milestone.

---

## 2026-08-04 — M3-F Round 1

### DL-001

- **date:** 2026-08-04
- **scenario:** Dashboard
- **observation:** "Oldest open" lists shipped fulfillments (e.g. #6031, #6322) alongside truly open work, so Mission Control does not clearly answer "what can I work on / ready to ship".
- **classification:** Future enhancement
- **decision:** Deferred — does not prevent normal warehouse operation via Queue/Orders/Workspace. Mission Control Dashboard redesign stays post-0.3.0.
- **target milestone:** M3-A / post-0.3.0 Mission Control

### DL-002

- **date:** 2026-08-04
- **scenario:** Queue
- **observation:** Default "Open" Queue filter includes shipped fulfillments; no next-action column (M3-C deferred).
- **classification:** Future enhancement
- **decision:** Deferred — operators can still open Workspace from the row. Queue next-action / open-filter semantics belong with deferred M3-C.
- **target milestone:** M3-C / post-0.3.0

### DL-003

- **date:** 2026-08-04
- **scenario:** Dashboard / Orders
- **observation:** Some rows show empty customer names (`6023 —`, shipped #6322 blank customer), which weakens at-a-glance recognition.
- **classification:** M3 polish
- **decision:** Show a clear fallback label when the customer snapshot is empty (`CustomerNameDisplay::label()` on Dashboard, Queue, Orders, Detail).
- **target milestone:** Fixed in v0.3.0

### DL-004

- **date:** 2026-08-04
- **scenario:** Search / Orders screen
- **observation:** Orders search works via GET `s=` (SKU/order/customer), but unlike Queue there is no explicit Filter submit button — Enter-only submission is easy to miss.
- **classification:** M3 polish
- **decision:** Add a Filter submit button on Orders for parity with Queue.
- **target milestone:** Fixed in v0.3.0

### DL-005

- **date:** 2026-08-04
- **scenario:** Packing / Workspace
- **observation:** Marking Packed is blocked until package weight/dimensions are confirmed (`package_spec_present`), but packing stage instruction only says "complete the shipment details" and the JS guard map lacked that code — operators may pause until they find the package fields.
- **classification:** M3 polish
- **decision:** Clarify packing instruction and map `package_spec_present` in operator guard copy (PHP + workspace.js).
- **target milestone:** Fixed in v0.3.0

### DL-006

- **date:** 2026-08-04
- **scenario:** Returning customer
- **observation:** Returning customer is identifiable by name/email history in Orders, but there is no explicit "returning customer" badge or prior-order hint.
- **classification:** Future enhancement
- **decision:** Not required to complete existing workflows. Defer convenience badge.
- **target milestone:** post-0.3.0 Orders / CRM convenience

### DL-007

- **date:** 2026-08-04
- **scenario:** Dashboard
- **observation:** Dashboard still lacks Mission Control bands/CTAs (Needs attention / Ready to ship / In progress as first-viewport product experience).
- **classification:** Future enhancement
- **decision:** Explicitly deferred M3-A; not a release blocker unless daily work is blocked (it is not — Queue/Orders suffice).
- **target milestone:** M3-A

### DL-008

- **date:** 2026-08-04
- **scenario:** Workspace / Shell
- **observation:** WP admin submenu still exposes "Fulfillment Detail" and "Packing Workspace" as separate items alongside Dashboard/Queue/Orders.
- **classification:** Future enhancement
- **decision:** Shell cleanup / Settings screen work is out of M3-F feature lock.
- **target milestone:** post-0.3.0 shell polish

### Scenario outcomes (Round 1)

| Scenario | Result |
|---|---|
| Pending payment | Pass — Orders Open order; no fulfillment |
| On Hold | Pass — Open order; awaiting confirmation wording |
| Processing | Pass — path into Queue/Workspace |
| Queue | Pass — open work → Workspace |
| Picking | Pass — quantities + Complete all + Picked |
| Packing | Pass — guidance + package/shipment panel (after polish for package_spec) |
| Packed | Pass — #6326 Ready to ship / Open Workspace |
| Shipped | Pass — #6322 Completed / success path known |
| Cancelled | Pass — No action / Open order |
| Customer order note | Pass — visible Customer instructions |
| Returning customer | Pass — Magnus Pernemark recognizable |
| Multiple line items | Pass — two lines on #6328 |
| Multiple quantities | Pass — Ordered/Picked/Remaining |
| Orders screen | Pass — filters + Open destinations |
| Dashboard | Pass for daily work; Mission Control deferred (DL-001/007) |
| Workspace | Pass — stage banner next action clear after load/transition |
| Search | Pass — SKU search via Orders `s=` |
| Exception | Pass — Problem state; Resolve / return to Picking |

**Release blockers remaining after Round 1 classification:** none.

---

## 2026-08-04 — M3-F Round 2 (post-polish)

Verified on latest build:

| Fix | Evidence |
|---|---|
| DL-003 empty customer fallback | Dashboard shows `6023 — No customer name`, `6322 — No customer name` |
| DL-004 Orders Filter button | Orders filter bar exposes `Filter` submit control |
| DL-005 packing package_spec guidance | Packing stage: “Pack every picked item, then enter package weight and dimensions before marking packed.”; Packed remains disabled until package fields complete |

No new Release blockers. Future enhancements DL-001/002/006/007/008 unchanged.

**Release blockers remaining after Round 2:** none.
