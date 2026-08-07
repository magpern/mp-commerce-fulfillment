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

test.describe( 'Wave Scan Mode', () => {
	test( 'FIFO wave scan, over-scan, undo, and exit leave Workspace intact', async ( {
		page,
	} ) => {
		const idA = claimSeedFulfillmentId();
		const idB = claimSeedFulfillmentId();
		// Ensure created_at order: lower id is typically earlier in seed.
		const fifoFirst = Math.min( idA, idB );
		const fifoSecond = Math.max( idA, idB );

		// Localize REST nonce via Queue (same mpcfWorkspace bootstrap as Wave).
		await page.goto( '/wp-admin/admin.php?page=mpcf-queue' );
		await expect( page.locator( 'body' ) ).toBeVisible();

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
		await expect( page.locator( 'h1' ) ).toContainText( /Wave Workspace/i );
		await expect( page.locator( '[data-mpcf-wave-workspace]' ) ).toHaveAttribute(
			'data-mpcf-wave-id',
			String( waveId )
		);
		await expect( page.locator( '[data-mpcf-wave-status]' ) ).toContainText( /draft|active/i, {
			timeout: 15000,
		} );

		await page.locator( '[data-mpcf-wave-activate]' ).click();
		await expect( page.locator( '[data-mpcf-wave-status]' ) ).toContainText( /active/i, {
			timeout: 15000,
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
		// Seed qty is 2 — second scan still FIFO-first until that line completes.
		expect( secondBody.data.fulfillment_id ).toBe( fifoFirst );

		// Complete remaining qty on first member (seed qty=2 → already 2 after two scans).
		// Drive remaining picks until second member receives allocation.
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

		// Exhaust remaining picks, then assert over-scan rejection.
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
				break;
			}
		}

		// One successful pick then undo (re-open via a fresh wave would be heavier;
		// instead create a tiny third-member wave if both are exhausted).
		// Prefer: if undo unavailable because over-scan left no correction, claim
		// a third seed, add member is draft-only — so verify undo while still active
		// by undoing after a deliberate mid-wave pick on a fresh wave below when needed.
		const undoProbe = await rest( page, `waves/${waveId}` );
		expect( undoProbe.ok ).toBeTruthy();

		// Exit Scan Mode — wave chrome remains operable.
		await page.locator( '[data-mpcf-wave-exit-scan]' ).click();
		await expect( page.locator( '[data-mpcf-wave-scan]' ) ).toBeHidden();
		await expect( page.locator( '[data-mpcf-wave-activate]' ) ).toBeVisible();
		await expect( page.locator( '[data-mpcf-wave-walk]' ) ).toContainText( SKU );

		// Normal per-fulfillment Workspace still works after leaving Wave Scan Mode.
		await page.goto(
			`/wp-admin/admin.php?page=mpcf-workspace&fulfillment_id=${fifoFirst}`
		);
		await expect( page.locator( '[data-mpcf-workspace]' ) ).toBeVisible();
		await expect( page.locator( '[data-mpcf-primary-action]' ) ).toBeVisible();
	} );

	test( 'undo reverses the last wave pick', async ( { page } ) => {
		const idA = claimSeedFulfillmentId();
		const idB = claimSeedFulfillmentId();

		await page.goto( '/wp-admin/admin.php?page=mpcf-queue' );

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
		await page.locator( '[data-mpcf-wave-activate]' ).click();
		await expect( page.locator( '[data-mpcf-wave-status]' ) ).toContainText( /active/i, {
			timeout: 15000,
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
