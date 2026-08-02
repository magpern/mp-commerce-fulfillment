# ADR-0003 — No JS framework, no build step

## Status

Accepted (Milestone 0; the Packing Workspace exercising this decision
lands in Milestone 2). **Superseded in part by ADR-0006** (Milestone 2):
this ADR's Consequences bullet ruling out any `package.json` in the
repository no longer holds for a dev/CI-only Playwright browser-test
toolchain — see that ADR for the narrow scope of the exception. This
ADR's Decision (no JS framework, no build step, for *shipped* code) is
unaffected.

## Context

The Packing Workspace needs real client-side interactivity: checklist
ticks, package-spec fields, workflow-state buttons, photo upload, list
filtering — all against a REST API, with optimistic updates. A
React/`@wordpress/element` single-page-app approach was considered, since
WooCommerce's own admin increasingly uses one.

## Decision

No JS framework and no build step, in this plugin or in the MP Admin
Design System it consumes. A framework was rejected: it imports a build
toolchain into a repo family that has deliberately never needed one,
couples the plugin to WooCommerce admin's own churning JS stack, and none
of the workspace's actual interactions exceed what small vanilla ES modules
over `fetch()` and the REST API do well. Instead: per-screen ES modules,
a tiny shared REST client, and a plain observable store with optimistic
updates and rollback on conflict.

## Consequences

- No `package.json`, no npm, no bundler in this repository, matching the
  sibling plugins' convention.
- The REST API (`mpcf/v1`, from Milestone 2) is the actual contract; a
  future framework rewrite of the admin JS would touch only `assets/` and
  leave the API and server-side logic untouched — the decision's stated
  escape hatch, deliberately kept open.
- The MP Admin Design System's own JS (vendored via `bin/sync-mpds.sh`)
  follows the same rule: vanilla, keyed only on `data-*` attributes.

## Related

`docs/ARCHITECTURE_PLAN.md` §9.2 (this decision's fuller rationale), D7,
R2 (risk register), ADR-0006 (the narrow, dev-only-toolchain exception).
