# ADR-0006 — Dev-only browser test toolchain

## Status

Accepted (Milestone 2). Supersedes ADR-0003's Consequences in one narrow
respect only — see below; ADR-0003's Decision is unchanged.

## Context

Milestone 2 introduces the Packing Workspace: the first screen in this
plugin with substantial client-side interactivity — a checklist with
row-wide click targets and keyboard shortcuts, a focus-retaining scan
sink, optimistic mutations with 409/422 recovery, a shipment/package
repeater, and a print flow. PHPUnit integration tests exercise the REST
routes and Application services underneath all of this (I11 makes that
possible), but none of them can observe what a real browser actually does:
whether the keyboard-only path truly reaches `queued → shipped` with zero
pointer events, whether focus survives an optimistic DOM re-render,
whether a scanner-emulated keystroke burst lands in the sink without
stealing focus from elsewhere, whether two browser tabs racing a 409
resolve the way the design says, or whether the printed packing slip's
`@media print` layout is actually free of clipped fields in Chrome and
Firefox. The Milestone 2 Execution Plan (Architecture Plan §IV, PO
decision 4) calls for closing that gap with Playwright.

ADR-0003's Consequences state plainly: "No `package.json`, no npm, no
bundler in this repository." Adding Playwright means adding exactly that
— `package.json`, a lockfile, `node_modules`, and browser binaries — which
contradicts that sentence literally, even though nothing about ADR-0003's
actual *Decision* (no JS framework, no build step for shipped code) is
in tension with it.

## Decision

Playwright is added as a **development- and CI-only** toolchain, never a
runtime one:

- `package.json`, its lockfile, `playwright.config.*` and `tests/browser/`
  are committed to the repository (so CI and any contributor can run the
  suite) but are **never shipped**: `bin/build-zip.sh`'s file allowlist and
  `bin/release-audit.sh`'s denylist both explicitly exclude them, and a new
  `ReleaseArtifactGuardTest` fails the build if any Node artifact — or a
  non-empty `vendor/composer/installed.json` runtime-package list — reaches
  the zip.
- Shipped PHP/CSS/JS is completely unaffected: `assets/admin/js/*.js`
  remain hand-written vanilla ES modules, exactly as ADR-0003 specifies, no
  bundler touches them, and the browser tests drive the *built* admin
  screens through a real browser — they are a consumer of this plugin's
  output, not a build step that produces it.
- PHPUnit remains the primary correctness tier (I6's WordPress-free core
  layers, REST route contracts, guard tests). Playwright is deliberately
  narrower in scope: real-browser behavior PHPUnit structurally cannot
  observe — keyboard flows, focus management, accessibility, print
  rendering, and genuine two-browser-context race conditions.

## Consequences

- ADR-0003's Consequences bullet "No `package.json`, no npm, no bundler in
  this repository" is **superseded in this one respect**: a dev/CI-only
  `package.json` now exists. Its Decision — no framework, no build step,
  for *shipped* code — is untouched; ADR-0003's Status line is updated to
  note this ADR.
- Three independent defenses (allowlist, denylist, guard test) exist
  specifically because a single missed exclusion would silently reintroduce
  a Node runtime dependency this plugin has never had — the zero-dependency
  property (`vendor/composer/installed.json` reporting no packages) that
  every M1 release already verified and this milestone must keep proving.
- A future contributor adding a new Node dev-dependency must update all
  three exclusion points in the same change, not just add it to
  `package.json` — there is no single source of truth enforcing this
  beyond the guard test actually running in CI on every push.
- The MP Admin Design System's own JS (vendored via `bin/sync-mpds.sh`)
  is unaffected — it has no test runner of its own beyond its PHPUnit-based
  markup-contract/lint suite, and this ADR does not change that.

## Related

`docs/ARCHITECTURE_PLAN.md` §IV.0 (PO decision 4), §IV.12 (commit F22),
§IV.15 (release-audit additions), ADR-0003 (the decision this one
narrowly supersedes).
