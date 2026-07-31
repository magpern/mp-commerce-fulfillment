# ADR-0005 — `Package` is first-class under `Shipment` from the start

## Status

Accepted (Milestone 0; the `mpcf_shipments`/`mpcf_packages` tables land in
Milestone 2).

## Context

A single carrier handover (a "shipment"/consignment) very often consists of
more than one physical box in real-world fulfillment, each with its own
weight, dimensions, colli tracking number, photos and eventually a label. A
simpler model — one row per shipment carrying a single weight/dimensions
tuple — was considered, since Milestone 2 only needs to support the common
single-box case.

## Decision

`Shipment` (the consignment) and `Package` (a physical box within it) are
modeled as two tables from the first milestone that introduces either,
even though the Milestone 2 UI only exercises the single-package path (a
simple shipment auto-creates its one package, so the common case still
looks like one form). Retrofitting a `Package` concept under an
already-shipped single-box `Shipment` schema later was judged the more
expensive migration — existing `weight_grams`/dimension columns on
`mpcf_shipments` would need to move to a new child table with data
migration, while every future consumer (photos, labels, tracking display)
would need reworking to attach to the right level.

## Consequences

- `mpcf_packages` carries per-package weight, dimensions, colli tracking
  number, and (from a later milestone) a label file path; `mpcf_package_items`
  carries per-package line quantities for split packing.
- Photos (`mpcf_media`) attach to a package, not only to a fulfillment,
  from the day photography ships.
- The Packing Workspace's "add package" action for multi-parcel
  consignments is additive UI on top of a schema that already supports it,
  not a later schema change.

## Related

`docs/ARCHITECTURE_PLAN.md` §7.1 (`mpcf_shipments`/`mpcf_packages`), D19.
