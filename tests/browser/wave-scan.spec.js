// Wave Scan Mode: create a 2-member wave of shared-SKU seeds, activate,
// enter scan mode, assert FIFO allocation, over-scan rejection, undo,
// progress updates, and that exiting Scan Mode leaves Workspace intact.
//
// Claims unique seeded fulfillments (tests/browser/claim-seed.js) so
// chromium/firefox workers never mutate the same rows.

const { test, expect } = require( '@playwright/test' );
const { claimSeedFulfillmentId } = require( './claim-seed' );

const SKU = 'BROWSER-TEST-WIDGET';

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} path
 * @param {{ method?: string, body?: object }=} options
 */
async function rest( page, path, options = {} ) {
	return page.evaluate(
		async ( { path: restPath, method, body } ) => {
			const settings = window.mpcfWorkspace || {};
			const response = await fetch( ( settings.restUrl || '' ) + restPath, {
				method: method || 'GET',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': settings.nonce || '',
				},
				body: undefined === body ? undefined : JSON.stringify( body ),
			} );
			const data = await response.json().catch( () => ( {} ) );
			return { ok: response.ok, status: response.status, data };
		},
		{ path, method: options.method || 'GET', body: options.body }
	);
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} sku
 */
async function typeScan( page, sku ) {
	const sink = page.locator( '[data-mpcf-scan-sink]' );
	await sink.focus();
	await page.keyboard.type( sku, { delay: 5 } );
	await page.keyboard.press( 'Enter' );
}

/**
 * Claim seed ids that are still joinable (queued/picking).
 *
 * @param {import('@playwright/test').Page} page
 * @param {number} count
 * @return {Promise<number[]>}
 */
async function claimJoinableIds( page, count ) {
	const ids = [];
	for ( let attempt = 0; attempt < 40 && ids.length < count; attempt++ ) {
		const id = claimSeedFulfillmentId();
		const probe = await rest( page, `fulfillments/${id}` );
		const state = probe.data && ( probe.data.state || ( probe.data.fulfillment && probe.data.fulfillment.state ) );
		if ( probe.ok && ( state === 'queued' || state === 'picking' ) ) {
			ids.push( id );
		}
	}
	if ( ids.length < count ) {
		throw new Error( 'Could not claim ' + count + ' joinable seeded fulfillments' );
	}
	return ids;
}

test.describe( 'Wave Scan Mode', () => {
	test.describe.configure( { mode: 'serial' } );

	test( 'FIFO wave scan, over-scan, undo, and exit leave Workspace intact', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/admin.php?page=mpcf-queue' );
		await expect( page.locator( 'body' ) ).toBeVisible();
		await page.waitForFunction( () => !!( window.mpcfWorkspace && window.mpcfWorkspace.restUrl ) );

		const claimed = await claimJoinableIds( page, 2 );
		const fifoFirst = Math.min( claimed[ 0 ], claimed[ 1 ] );
		const fifoSecond = Math.max( claimed[ 0 ], claimed[ 1 ] );

		const created = await rest( page, 'waves', {
			method: 'POST',
			body: {
				warehouse_id: 1,
				fulfillment_ids: [ fifoFirst, fifoSecond ],
				title: 'Browser wave scan',
			},
		} );
		expect( created.ok, JSON.stringify( created.data ) ).toBeTruthy();
		const waveId = created.data.id;
		expect( waveId ).toBeGreaterThan( 0 );

		await page.goto( `/wp-admin/admin.php?page=mpcf-wave&wave_id=${waveId}` );
		await expect( page.locator( 'body' ) ).not.toContainText( /critical error on this website/i );
		await expect( page.locator( 'h1' ) ).toContainText( /Wave Workspace/i );
		await expect( page.locator( '[data-mpcf-wave-workspace]' ) ).toHaveAttribute(
			'data-mpcf-wave-id',
			String( waveId )
		);
		await page.waitForFunction( () => !!( window.mpcfWorkspace && window.mpcfWorkspace.restUrl ) );
		await expect( page.locator( '[data-mpcf-wave-status]' ) ).toContainText( /draft|active/i, {
			timeout: 20000,
		} );

		await page.locator( '[data-mpcf-wave-activate]' ).click();
		await expect( page.locator( '[data-mpcf-wave-status]' ) ).toContainText( /active/i, {
			timeout: 20000,
		} );
		await expect( page.locator( '[data-mpcf-wave-walk]' ) ).toContainText( SKU );

		await page.locator( '[data-mpcf-wave-enter-scan]' ).click();
		await expect( page.locator( '[data-mpcf-wave-scan]' ) ).toBeVisible();
		await expect( page.locator( '[data-mpcf-scan-sink]' ) ).toBeVisible();

		const firstPick = page.waitForResponse(
			( response ) =>
				response.request().method() === 'POST' &&
				new RegExp( `/mpcf/v1/waves/${waveId}/scan$` ).test( response.url() )
		);
		await typeScan( page, SKU );
		const firstBody = await ( await firstPick ).json();
		expect( firstBody.result ).toMatch( /quantity_incremented|member_complete/ );
		expect( firstBody.data.fulfillment_id ).toBe( fifoFirst );
		await expect( page.locator( '[data-mpcf-wave-scan-result]' ) ).toContainText(
			`F#${fifoFirst}`
		);
		await expect( page.locator( '[data-mpcf-wave-scan-progress]' ) ).toContainText(
			/Remaining/
		);

		const secondPick = page.waitForResponse(
			( response ) =>
				response.request().method() === 'POST' &&
				new RegExp( `/mpcf/v1/waves/${waveId}/scan$` ).test( response.url() ) &&
				response.ok()
		);
		await typeScan( page, SKU );
		const secondBody = await ( await secondPick ).json();
		expect( secondBody.data.fulfillment_id ).toBe( fifoFirst );

		let sawSecond = false;
		for ( let i = 0; i < 6; i++ ) {
			const pending = page.waitForResponse(
				( response ) =>
					response.request().method() === 'POST' &&
					new RegExp( `/mpcf/v1/waves/${waveId}/scan$` ).test( response.url() )
			);
			await typeScan( page, SKU );
			const resp = await pending;
			const body = await resp.json();
			if ( resp.ok() && body.data && body.data.fulfillment_id === fifoSecond ) {
				sawSecond = true;
				break;
			}
			if ( ! resp.ok() ) {
				break;
			}
		}
		expect( sawSecond ).toBeTruthy();

		let sawOverScan = false;
		for ( let i = 0; i < 8; i++ ) {
			const pending = page.waitForResponse(
				( response ) =>
					response.request().method() === 'POST' &&
					new RegExp( `/mpcf/v1/waves/${waveId}/scan$` ).test( response.url() )
			);
			await typeScan( page, SKU );
			const resp = await pending;
			if ( ! resp.ok() ) {
				const err = await resp.json();
				expect( String( err.message || err.code || '' ) ).toMatch(
					/already fully picked|No outstanding|over.?scan|guard/i
				);
				await expect( page.locator( '[data-mpcf-wave-scan-result]' ) ).toContainText(
					/already fully picked|No outstanding|Scan failed|guard/i
				);
				sawOverScan = true;
				break;
			}
		}
		expect( sawOverScan ).toBeTruthy();

		await page.locator( '[data-mpcf-wave-exit-scan]' ).click();
		await expect( page.locator( '[data-mpcf-wave-scan]' ) ).toBeHidden();
		await expect( page.locator( '[data-mpcf-wave-activate]' ) ).toBeVisible();
		await expect( page.locator( '[data-mpcf-wave-walk]' ) ).toContainText( SKU );

		await page.goto(
			`/wp-admin/admin.php?page=mpcf-workspace&fulfillment_id=${fifoFirst}`
		);
		await expect( page.locator( '[data-mpcf-workspace]' ) ).toBeVisible();
		await expect( page.locator( '[data-mpcf-primary-action]' ) ).toBeVisible();
	} );

	test( 'undo reverses the last wave pick', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=mpcf-queue' );
		await page.waitForFunction( () => !!( window.mpcfWorkspace && window.mpcfWorkspace.restUrl ) );

		const [ idA, idB ] = await claimJoinableIds( page, 2 );

		const created = await rest( page, 'waves', {
			method: 'POST',
			body: {
				warehouse_id: 1,
				fulfillment_ids: [ idA, idB ],
				title: 'Browser wave undo',
			},
		} );
		expect( created.ok, JSON.stringify( created.data ) ).toBeTruthy();
		const waveId = created.data.id;

		await page.goto( `/wp-admin/admin.php?page=mpcf-wave&wave_id=${waveId}` );
		await expect( page.locator( 'body' ) ).not.toContainText( /critical error on this website/i );
		await expect( page.locator( '[data-mpcf-wave-status]' ) ).toContainText( /draft|active/i, {
			timeout: 20000,
		} );

		await page.locator( '[data-mpcf-wave-activate]' ).click();
		await expect( page.locator( '[data-mpcf-wave-status]' ) ).toContainText( /active/i, {
			timeout: 20000,
		} );

		await page.locator( '[data-mpcf-wave-enter-scan]' ).click();

		const pick = page.waitForResponse(
			( response ) =>
				response.request().method() === 'POST' &&
				new RegExp( `/mpcf/v1/waves/${waveId}/scan$` ).test( response.url() ) &&
				response.ok()
		);
		await typeScan( page, SKU );
		const pickBody = await ( await pick ).json();
		expect( pickBody.data.qty_picked ).toBeGreaterThanOrEqual( 1 );

		const undo = page.waitForResponse(
			( response ) =>
				response.request().method() === 'POST' &&
				new RegExp( `/mpcf/v1/waves/${waveId}/scan$` ).test( response.url() ) &&
				response.ok()
		);
		await page.locator( '[data-mpcf-wave-scan-undo]' ).click();
		const undoBody = await ( await undo ).json();
		expect( undoBody.result ).toBe( 'corrected' );
		expect( undoBody.message ).toMatch( /undone/i );
		await expect( page.locator( '[data-mpcf-wave-scan-result]' ) ).toContainText( /Undone|undone/i );

		await page.locator( '[data-mpcf-wave-exit-scan]' ).click();
		await expect( page.locator( '[data-mpcf-wave-scan]' ) ).toBeHidden();
	} );
} );
