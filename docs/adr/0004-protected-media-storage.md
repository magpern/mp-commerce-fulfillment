# ADR-0004 — Package photos and documents outside the media library

## Status

Accepted (Milestone 0; photo capture lands in Milestone 5, document
storage in Milestone 3).

## Context

Package photos and rendered documents (packing slips, invoices, customs
forms) are fulfillment records, not media the merchant curates. Invariant
I9 requires both to be never publicly reachable.

## Decision

Photos and stored document renders are written to a protected directory
(`wp-content/uploads/mpcf/…`) with deny rules (`.htaccess` for Apache, a
documented nginx snippet), random-suffixed filenames, and served only
through a capability-checked streaming endpoint (`Api\FileEndpoint`). The
WordPress media library was rejected: it would make every package photo a
public-URL attachment, pollute the library with fulfillment records the
merchant never chose to upload, and hand attachment lifecycle (regeneration,
deletion, the Media modal) to a system that has no notion of "this file is
part of an audit trail and must not be casually deleted."

## Consequences

- The plugin owns its own file lifecycle: ingest, hash, store, stream,
  retention-purge, deletion — each step audited.
- `PersistedKeys` must inventory the protected directory itself (not
  individual files) so uninstall can remove it as a unit.
- A future admin UI that wants to *display* these files does so through
  the streaming endpoint, never a direct URL — this is enforced structurally
  (the files are outside the webroot's normally-served media path) rather
  than by convention alone.

## Related

`docs/ARCHITECTURE_PLAN.md` §12 (package photography), §10 (documents), I9.
