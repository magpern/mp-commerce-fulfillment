// Keyboard-wedge Scan Mode: enter picking mode, scan SKU, assert REST pick.

const { test, expect } = require( '@playwright/test' );
const { openClaimedWorkspace } = require( './claim-seed' );

test.describe( 'Workspace Scan Mode', () => {
	test( 'picking scan increments quantity via scan endpoint', async ( { page } ) => {
		await openClaimedWorkspace( page );

		const primary = page.locator( '[data-mpcf-primary-action]' );
		await expect( primary ).toHaveText( /^Picking$/ );
		await page.keyboard.press( 'Control+Enter' );
		await expect( primary ).toHaveText( /^Picked$/ );

		await page.locator( '[data-mpcf-scan-mode-enter="picking"]' ).click();
		await expect( page.locator( '[data-mpcf-scan-mode-live]' ) ).toBeVisible();

		const scanRequest = page.waitForResponse(
			( response ) =>
				response.request().method() === 'POST' &&
				/\/mpcf\/v1\/fulfillments\/\d+\/scan$/.test( response.url() ) &&
				response.ok()
		);

		const sink = page.locator( '[data-mpcf-scan-sink]' );
		await sink.focus();
		await page.keyboard.type( 'BROWSER-TEST-WIDGET', { delay: 5 } );
		await page.keyboard.press( 'Enter' );

		const response = await scanRequest;
		const body = await response.json();

		expect( body.result ).toMatch( /quantity_incremented|item_complete|stage_complete/ );
		expect( body.item.qty_picked ).toBeGreaterThanOrEqual( 1 );
		await expect( page.locator( '[data-mpcf-scan-mode-status]' ) ).toHaveAttribute(
			'data-mpcf-scan-mode-status-state',
			/success|ready/
		);
	} );
} );
