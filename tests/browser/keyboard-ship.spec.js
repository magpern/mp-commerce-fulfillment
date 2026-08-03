// Architecture Plan §IV.15 acceptance criterion 1: a Warehouse Operator
// processes a fulfillment queued -> shipped using only the keyboard, with
// no full-page reload at any point. The only pointer action in this spec
// is opening a Queue row — real keyboard-only Queue row navigation (j/k,
// Enter) is `data-table-keynav.js`'s own subject, not this workspace-
// focused suite's. Text entry uses `.fill()`, which sets a field's value
// without any pointer interaction — the same "no mouse" property
// `page.keyboard.press()` has, just not a literal per-keystroke simulation.
//
// Selects its row by `testInfo.parallelIndex` rather than always the
// first one: chromium and firefox run as separate projects, by default
// concurrently, and both mutating the *same* fulfillment would race each
// other's transitions non-deterministically — `tests/browser/seed.php`
// seeds one fulfillment per worker slot specifically so this is safe.
const { test, expect } = require( '@playwright/test' );

test.describe( 'Packing Workspace — keyboard-only queued to shipped', () => {
	test( 'ships a single-line order with no pointer interaction beyond opening it', async ( { page }, testInfo ) => {
		await page.goto( '/wp-admin/admin.php?page=mpcf-queue' );

		const row = page.locator( '[data-mpcf-row-open]' ).nth( testInfo.parallelIndex );
		await row.click();
		await page.waitForURL( /page=mpcf-workspace/ );

		await expect( page.locator( '[data-mpcf-workspace]' ) ).toBeVisible();

		const primary = page.locator( '[data-mpcf-primary-action]' );

		// The primary button's label is the target state's own label
		// (WorkflowService::available_transitions() — a state's label,
		// not a separate verb-phrase transition label), so this asserts
		// against the state names StandardWorkflow declares, not the
		// illustrative "Start picking"/"Mark packed" phrasing in the
		// Architecture Plan's UI diagram.

		const stepper = page.locator( '.mpcf-ui-quantity-stepper__value' ).first();

		// Playwright's own `+`-combo convention: 'Shift+A' produces
		// `event.key === 'A'`; the lowercase 'Shift+a' produces
		// `event.key === 'a'` with `shiftKey: true` instead (it presses
		// the literal named key "a" while holding Shift, rather than
		// asking the OS keyboard layout to compute the shifted
		// character the way a real hardware key-press would) — and
		// `shortcuts.js` deliberately has separate `'a'`/`'A'` cases
		// (complete the focused line vs. complete every line), so this
		// is not a cosmetic choice.

		// queued -> picking
		await expect( primary ).toHaveText( /^Picking$/ );
		await page.keyboard.press( 'Control+Enter' );

		// The action bar updates the instant the transition response
		// arrives; the checklist becomes live one beat later, via its own
		// separate GET request (workspace.js's refreshChecklistForState())
		// — real, but sub-100ms on a local install, and this wait is the
		// correct way to observe "the checklist becomes live" (§IV.5.8
		// step 2) rather than assuming it is simultaneous with the action
		// bar. Complete every picking line, then advance.
		await expect( primary ).toHaveText( /^Picked$/ );
		await stepper.waitFor( { state: 'attached' } );
		await page.keyboard.press( 'Shift+A' );
		await page.keyboard.press( 'Control+Enter' );

		await expect( primary ).toHaveText( /^Packing$/ );
		await page.keyboard.press( 'Control+Enter' );

		// Now packing: complete every line, then satisfy the shipment/
		// package-spec guards before "Packed" becomes approved.
		await expect( primary ).toHaveText( /^Packed$/ );
		await stepper.waitFor( { state: 'attached' } );
		await page.keyboard.press( 'Shift+A' );

		// The first edit to the shipment card creates it (and package 1) —
		// Architecture Plan §IV.5.8 step 6. No wait is needed between this
		// and the checklist completion above, nor before the weight edit
		// below: `workspace.js`'s `flushPendingWrites()` forces every
		// pending item/package write (debounced or already in flight) to
		// settle before it will ever submit a transition (§IV.10, risk
		// M2-R7), which is exactly the "fast keyboard operator" case this
		// spec is simulating.
		await page.locator( '[data-mpcf-tracking-number]' ).first().fill( 'TRACK-E2E-1' );
		await page.locator( '[data-mpcf-tracking-number]' ).first().blur();

		// Package 1's weight is what satisfies package_spec_present. The
		// field itself does not exist until the tracking-number edit's
		// shipment-creation chain finishes rendering it, so this wait is
		// real synchronisation, not a guess at timing.
		const weightField = page.locator( '[data-mpcf-package-field="weight_grams"]' ).first();
		await weightField.waitFor( { state: 'visible' } );
		await weightField.fill( '1.2' );
		await weightField.blur();

		await expect( primary ).toHaveText( /^Packed$/ );
		await page.keyboard.press( 'Control+Enter' );

		await expect( primary ).toHaveText( /^Shipped$/ );
		await page.keyboard.press( 'Control+Enter' );

		await expect( page.locator( '[data-mpcf-toast]' ).last() ).toContainText( 'Shipped' );
	} );
} );
