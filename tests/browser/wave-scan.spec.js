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
	await expect( sink ).toBeVisible();
	await sink.focus();
	await page.keyboard.type( sku, { delay: 5 } );
	await page.keyboard.press( 'Enter' );
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {number} waveId
 * @param {string} sku
 */
async function uiPick( page, waveId, sku ) {
	const pending = page.waitForResponse(
		( response ) =>
			response.request().method() === 'POST' &&
			new RegExp( `/mpcf/v1/waves/${waveId}/scan$` ).test( response.url() )
	);
	await typeScan( page, sku );
	const resp = await pending;
	const body = await resp.json().catch( () => ( {} ) );
	return { resp, body };
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
		const state =
			probe.data &&
			( probe.data.state || ( probe.data.fulfillment && probe.data.fulfillment.state ) );
		if ( probe.ok && ( state === 'queued' || state === 'picking' ) ) {
			ids.push( id );
		}
	}
	if ( ids.length < count ) {
		throw new Error( 'Could not claim ' + count + ' joinable seeded fulfillments' );
	}
	return ids;
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {number} waveId
 */
async function openActiveWave( page, waveId ) {
	await page.goto( `/wp-admin/admin.php?page=mpcf-wave&wave_id=${waveId}` );
	await expect( page.locator( 'body' ) ).not.toContainText( /critical error on this website/i );
	await expect( page.locator( 'h1' ) ).toContainText( /Wave Workspace/i );
	await page.waitForFunction( () => !!( window.mpcfWorkspace && window.mpcfWorkspace.restUrl ) );
	await expect( page.locator( '[data-mpcf-wave-status]' ) ).toContainText( /draft|active/i, {
		timeout: 20000,
	} );
	if ( !( await page.locator( '[data-mpcf-wave-status]' ).innerText() ).match( /active/i ) ) {
		await page.locator( '[data-mpcf-wave-activate]' ).click();
		await expect( page.locator( '[data-mpcf-wave-status]' ) ).toContainText( /active/i, {
			timeout: 20000,
		} );
	}
}

test.describe( 'Wave Scan Mode', () => {
	test.describe.configure( { mode: 'serial' } );

	test( 'FIFO wave scan, over-scan, and exit leave Workspace intact', async ( { page } ) => {
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

		await openActiveWave( page, waveId );
		await expect( page.locator( '[data-mpcf-wave-walk]' ) ).toContainText( SKU );

		await page.locator( '[data-mpcf-wave-enter-scan]' ).click();
		await expect( page.locator( '[data-mpcf-wave-scan]' ) ).toBeVisible();

		const first = await uiPick( page, waveId, SKU );
		expect( first.resp.ok(), JSON.stringify( first.body ) ).toBeTruthy();
		expect( first.body.result ).toMatch( /quantity_incremented|member_complete/ );
		expect( first.body.data.fulfillment_id ).toBe( fifoFirst );
		await expect( page.locator( '[data-mpcf-wave-scan-result]' ) ).toContainText(
			`F#${fifoFirst}`
		);
		await expect( page.locator( '[data-mpcf-wave-scan-progress]' ) ).toContainText(
			/Remaining/
		);

		const second = await uiPick( page, waveId, SKU );
		expect( second.resp.ok(), JSON.stringify( second.body ) ).toBeTruthy();
		expect( second.body.data.fulfillment_id ).toBe( fifoFirst );

		// Remaining picks via REST (deterministic), then one UI over-scan.
		let version = second.body.version;
		for ( let i = 0; i < 12; i++ ) {
			const pick = await rest( page, `waves/${waveId}/scan`, {
				method: 'POST',
				body: { action: 'pick', payload: SKU, version },
			} );
			if ( ! pick.ok ) {
				break;
			}
			version = pick.data.version;
			if ( pick.data.data && pick.data.data.fulfillment_id === fifoSecond ) {
				// Continue until exhausted.
			}
		}

		const over = await uiPick( page, waveId, SKU );
		expect( over.resp.ok() ).toBeFalsy();
		expect( String( over.body.message || over.body.code || '' ) ).toMatch(
			/already fully picked|No outstanding|over.?scan|guard|modified by someone else/i
		);
		await expect( page.locator( '[data-mpcf-wave-scan-result]' ) ).not.toHaveText( '' );

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

		await openActiveWave( page, waveId );
		await page.locator( '[data-mpcf-wave-enter-scan]' ).click();
		await expect( page.locator( '[data-mpcf-scan-sink]' ) ).toBeVisible();

		const pick = await uiPick( page, waveId, SKU );
		expect( pick.resp.ok(), JSON.stringify( pick.body ) ).toBeTruthy();
		expect( pick.body.data.qty_picked ).toBeGreaterThanOrEqual( 1 );
		await expect( page.locator( '[data-mpcf-wave-scan-result]' ) ).toContainText( /F#/ );

		const undoPending = page.waitForResponse(
			( response ) =>
				response.request().method() === 'POST' &&
				new RegExp( `/mpcf/v1/waves/${waveId}/scan$` ).test( response.url() )
		);
		await page.locator( '[data-mpcf-wave-scan-undo]' ).click();
		const undoResp = await undoPending;
		expect( undoResp.ok(), await undoResp.text() ).toBeTruthy();
		const undoBody = await undoResp.json();
		expect( undoBody.result ).toBe( 'corrected' );
		await expect( page.locator( '[data-mpcf-wave-scan-result]' ) ).toContainText( /Undone|undone/i );

		await page.locator( '[data-mpcf-wave-exit-scan]' ).click();
		await expect( page.locator( '[data-mpcf-wave-scan]' ) ).toBeHidden();
	} );
} );
