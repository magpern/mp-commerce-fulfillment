// Regression tests for quantity control defects in the packing workspace.
// Covers: plus/minus buttons, direct input, REST persistence, completion
// detection, transition enablement, and idempotent server reconciliation.

const { test, expect } = require( '@playwright/test' );
const { openClaimedWorkspace } = require( './claim-seed' );

async function openPickingWorkspace( page ) {
	await openClaimedWorkspace( page );

	const primary = page.locator( '[data-mpcf-primary-action]' );
	await expect( primary ).toHaveText( /^Picking$/ );
	await page.keyboard.press( 'Control+Enter' );
	await expect( primary ).toHaveText( /^Picked$/ );

	const stepper = page.locator( '.mpcf-ui-quantity-stepper__value' ).first();
	await stepper.waitFor( { state: 'attached' } );

	return { primary, stepper };
}

test.describe( 'Packing Workspace — quantity controls', () => {
	test( 'plus button increments quantity and persists via REST', async ( { page } ) => {
		const { primary, stepper } = await openPickingWorkspace( page );
		const incrementButton = page.locator( '[data-mpcf-quantity-increment]' ).first();
		const checklistRow = page.locator( '.mpcf-ui-checklist__row' ).first();
		const quantityDisplay = page.locator( '.mpcf-workspace__quantity-processed' ).first();

		await expect( stepper ).toHaveValue( '0' );
		await expect( quantityDisplay ).toContainText( 'Picked: 0' );

		const itemsRequest = page.waitForResponse(
			response =>
				response.request().method() === 'PUT' &&
				/\/mpcf\/v1\/fulfillments\/\d+\/items$/.test( response.url() ) &&
				response.ok()
		);

		await incrementButton.click();
		await expect( stepper ).toHaveValue( '1' );
		await expect( quantityDisplay ).toContainText( 'Picked: 1' );

		const response = await itemsRequest;
		const body = await response.json();

		expect( body.items[ 0 ].qty_picked ).toBe( 1 );
		expect( body.version ).toBeGreaterThan( 1 );

		// Seed qty_ordered is 2 — a single increment must not mark complete.
		await expect( checklistRow ).not.toHaveClass( /mpcf-ui-checklist__row--complete/ );
		await expect( primary ).toBeDisabled();
	} );

	test( 'minus button decrements quantity and persists via REST', async ( { page } ) => {
		const { primary, stepper } = await openPickingWorkspace( page );
		const incrementButton = page.locator( '[data-mpcf-quantity-increment]' ).first();
		const decrementButton = page.locator( '[data-mpcf-quantity-decrement]' ).first();

		await incrementButton.click();
		await page.waitForResponse(
			response =>
				response.request().method() === 'PUT' &&
				/\/mpcf\/v1\/fulfillments\/\d+\/items$/.test( response.url() ) &&
				response.ok()
		);

		const decrementRequest = page.waitForResponse(
			response =>
				response.request().method() === 'PUT' &&
				/\/mpcf\/v1\/fulfillments\/\d+\/items$/.test( response.url() ) &&
				response.ok()
		);

		await decrementButton.click();
		await expect( stepper ).toHaveValue( '0' );

		const response = await decrementRequest;
		const body = await response.json();

		expect( body.items[ 0 ].qty_picked ).toBe( 0 );
		await expect( primary ).toBeDisabled();
	} );

	test( 'direct numeric input persists on blur', async ( { page } ) => {
		const { primary, stepper } = await openPickingWorkspace( page );
		const maxQty = await stepper.getAttribute( 'max' );

		const itemsRequest = page.waitForResponse(
			response =>
				response.request().method() === 'PUT' &&
				/\/mpcf\/v1\/fulfillments\/\d+\/items$/.test( response.url() ) &&
				response.ok()
		);

		await stepper.fill( String( maxQty ) );
		await stepper.blur();

		const response = await itemsRequest;
		const body = await response.json();

		expect( body.items[ 0 ].qty_picked ).toBe( Number( maxQty ) );
		await expect( stepper ).toHaveValue( String( maxQty ) );
		await expect( primary ).toBeEnabled();
	} );

	test( 'ordered quantity is visible in checklist', async ( { page } ) => {
		await openPickingWorkspace( page );

		const ordered = page.locator( '.mpcf-workspace__quantity-ordered' ).first();
		await expect( ordered ).toContainText( /^Ordered: \d+/ );

		const processed = page.locator( '.mpcf-workspace__quantity-processed' ).first();
		await expect( processed ).toContainText( /^Picked: \d+/ );

		const remaining = page.locator( '.mpcf-workspace__quantity-remaining' ).first();
		await expect( remaining ).toContainText( /^Remaining: \d+/ );
	} );

	test( 'row becomes complete when all ordered quantity is picked', async ( { page } ) => {
		const { stepper } = await openPickingWorkspace( page );
		const maxQty = await stepper.getAttribute( 'max' );
		const maxValue = parseInt( maxQty, 10 );
		const checklistRow = page.locator( '.mpcf-ui-checklist__row' ).first();
		const incrementButton = page.locator( '[data-mpcf-quantity-increment]' ).first();

		await expect( checklistRow ).not.toHaveClass( /mpcf-ui-checklist__row--complete/ );

		for ( let i = 0; i < maxValue; i++ ) {
			await incrementButton.click();
		}

		await page.waitForResponse(
			response =>
				response.request().method() === 'PUT' &&
				/\/mpcf\/v1\/fulfillments\/\d+\/items$/.test( response.url() ) &&
				response.ok()
		);

		await expect( stepper ).toHaveValue( String( maxValue ) );
		await expect( checklistRow ).toHaveClass( /mpcf-ui-checklist__row--complete/ );
	} );

	test( 'primary action becomes enabled when all items are picked', async ( { page } ) => {
		const { primary } = await openPickingWorkspace( page );

		await expect( primary ).toBeDisabled();
		await page.keyboard.press( 'Shift+A' );

		await page.waitForResponse(
			response =>
				response.request().method() === 'PUT' &&
				/\/mpcf\/v1\/fulfillments\/\d+\/items$/.test( response.url() ) &&
				response.ok()
		);

		await expect( primary ).toBeEnabled();
		await expect( primary ).toHaveText( /^Picked$/ );
	} );

	test( 'transition succeeds after all items are picked', async ( { page } ) => {
		const { primary } = await openPickingWorkspace( page );

		await page.keyboard.press( 'Shift+A' );
		await page.waitForResponse(
			response =>
				response.request().method() === 'PUT' &&
				/\/mpcf\/v1\/fulfillments\/\d+\/items$/.test( response.url() ) &&
				response.ok()
		);

		await page.keyboard.press( 'Control+Enter' );
		await expect( primary ).toHaveText( /^Packing$/ );
	} );
} );
