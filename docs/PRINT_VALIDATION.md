# Packing slip print-fidelity validation (Spike S1)

Architecture Plan §IV.7's reduced Spike S1, run at F14: does the bundled
print-only HTML (`templates/documents/packing-slip.php` +
`packing-slip.css`, §IV.8) produce an acceptable A4 packing slip from both
Chrome and Firefox — no clipped content, correct field positions — without
a PDF renderer? Falsification condition: if either engine fails, the PDF
renderer moves into Milestone 2 and the plan is amended (§IV.16, M2-R4).

**Result: pass on both engines. No PDF renderer is needed for M2.**

## Method

`Documents\HtmlRenderer` was used to render two fixtures from real
`DocumentModel` objects (the same class `DocumentService`/
`PackingSlipAssembler` produce in production) to standalone HTML files,
then printed to PDF two ways:

- **Chrome** (Chromium, `zenika/alpine-chrome`): headless
  `--print-to-pdf`, the same Blink print pipeline `window.print()` drives
  in a real browser.
- **Firefox** (`selenium/standalone-firefox`): the W3C WebDriver `Print`
  command (`POST /session/{id}/print`) — Firefox has no headless
  print-to-PDF CLI flag, but this endpoint drives the identical Gecko
  print pipeline `window.print()` uses; it is the standards-defined way to
  get a print rendering out of Firefox without a display.

Resulting PDFs were inspected with `pdfinfo` (page count, page size) and
`pdftotext -layout` (content presence and reading order) — not a manual
visual check, but an evidence trail anyone can reproduce and re-verify.

This exercised the renderer/template/CSS layer directly; it did not go
through the REST route or a real WooCommerce order, since Spike S1 is
about print fidelity specifically, not the pipeline already covered by
`DocumentServiceTest`/`DocumentsControllerTest`.

## Fixture 1 — typical single-package order

Two line items, one package, no colli tracking number — the common case.

| Engine | Pages | Page size | Result |
|---|---|---|---|
| Chrome | 1 | 594.96 × 841.92 pt (A4) | Store header, ship-to block, items table, package line, and barcode-payload footer all present; no clipping. |
| Firefox | 1 | 596 × 842 pt (A4) | Same. |

## Fixture 2 — worst-case order

14 line items with long product names (deliberately long enough to wrap
within a cell), 2 packages with colli tracking numbers, and a non-ASCII
ship-to address (`Björn Åström-Öberg`, `Långa Vägen 123, lgh 4tr`) — the
shapes most likely to overflow a fixed-size page or mis-render a
character encoding.

| Engine | Pages | Page size | Page break | Result |
|---|---|---|---|---|
| Chrome | 2 | 594.96 × 841.92 pt (A4) | After item 13 | Table header (`SKU`/`ITEM`/`QTY`) repeats correctly on page 2 (native `<thead>` browser page-break behavior); no row is split across pages; both packages and the barcode payload land on page 2, fully visible. Non-ASCII characters render correctly. |
| Firefox | 2 | 596 × 842 pt (A4) | After item 12 | Same outcome — a different exact break point than Chrome (expected: the two engines' text-layout metrics differ slightly), but the same property holds: never mid-row, header repeats, nothing clipped. |

Both engines chose a *different* page-break point for the same content —
expected, since Blink and Gecko measure text slightly differently — but
neither ever split a table row, clipped content, or lost the repeating
header. That is the property this spike is actually checking, not
pixel-identical output between browsers.

## One real, unrelated finding — not a defect in this template

Chrome's CLI print-to-PDF adds its own URL/date header-footer band by
default; passing `--print-to-pdf-no-header` did not suppress it in the
Chromium build used here. This is Chrome's own browser-level "headers and
footers" print option, present on any web page printed this way — it is
not something `@page`/CSS can control, and it is not specific to this
template. In real use, the operator's own browser print dialog (or its
saved print-settings profile for the packing-station printer) controls
this the same way it would for any other page; it requires no change to
`packing-slip.php`/`packing-slip.css`.

## Conclusion

Print-HTML meets the bar Architecture Plan §IV.7 set: a correctly laid
out A4 slip from both Chrome and Firefox, with no clipped content, even
under a deliberately adversarial fixture. The `PdfRendererPort` binding
stays deferred to the milestone that actually needs a stored file (§10) —
adding it now would cost the zero-runtime-dependency property this
plugin has held since Milestone 1 for no benefit this milestone needs.
