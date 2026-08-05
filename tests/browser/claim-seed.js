// Claims a unique seeded fulfillment id for one Playwright test.
// Seed ids are written by tests/browser/seed.php; claiming is atomic across
// chromium/firefox workers so mutable specs never share a row.
const fs = require( 'fs' );
const path = require( 'path' );

const AUTH_DIR = path.join( __dirname, '.auth' );
const IDS_FILE = path.join( AUTH_DIR, 'seed-fulfillments.json' );
const LOCK_FILE = path.join( AUTH_DIR, 'seed-claim.lock' );
const CURSOR_FILE = path.join( AUTH_DIR, 'seed-claim.cursor' );

function sleepSync( ms ) {
	const end = Date.now() + ms;
	while ( Date.now() < end ) {
		/* busy wait — claim is rare and sub-ms */
	}
}

function withLock( fn ) {
	const started = Date.now();
	for ( ;; ) {
		try {
			const fd = fs.openSync( LOCK_FILE, 'wx' );
			try {
				return fn();
			} finally {
				fs.closeSync( fd );
				fs.unlinkSync( LOCK_FILE );
			}
		} catch ( err ) {
			if ( 'EEXIST' !== err.code ) {
				throw err;
			}
			if ( Date.now() - started > 10000 ) {
				throw new Error( 'Timed out waiting for seed-claim lock' );
			}
			sleepSync( 5 );
		}
	}
}

/**
 * Returns the next unused fulfillment id from the seed pool.
 *
 * @return {number}
 */
function claimSeedFulfillmentId() {
	return withLock( () => {
		if ( ! fs.existsSync( IDS_FILE ) ) {
			throw new Error(
				'Missing ' + IDS_FILE + ' — run tests/bin/install-wp-site.sh (seed.php) before Playwright'
			);
		}

		const ids = JSON.parse( fs.readFileSync( IDS_FILE, 'utf8' ) );
		if ( ! Array.isArray( ids ) || 0 === ids.length ) {
			throw new Error( 'Seed fulfillment id list is empty' );
		}

		let cursor = 0;
		if ( fs.existsSync( CURSOR_FILE ) ) {
			cursor = parseInt( fs.readFileSync( CURSOR_FILE, 'utf8' ), 10 ) || 0;
		}

		if ( cursor >= ids.length ) {
			throw new Error(
				'Exhausted seeded fulfillments (' + ids.length + '). Increase MPCF_SEED_ORDER_COUNT in seed.php.'
			);
		}

		const id = Number( ids[ cursor ] );
		fs.writeFileSync( CURSOR_FILE, String( cursor + 1 ), 'utf8' );
		return id;
	} );
}

/**
 * Opens the workspace for a freshly claimed seeded fulfillment via Queue.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @return {Promise<number>} Claimed fulfillment id.
 */
async function openClaimedWorkspaceFromQueue( page ) {
	const fulfillmentId = claimSeedFulfillmentId();

	await page.goto( '/wp-admin/admin.php?page=mpcf-queue' );
	const row = page.locator(
		`a[data-mpcf-row-open][href*="fulfillment_id=${fulfillmentId}"]`
	);
	await row.click();
	await page.waitForURL( new RegExp( `page=mpcf-workspace.*fulfillment_id=${fulfillmentId}` ) );

	return fulfillmentId;
}

/**
 * Opens the workspace for a freshly claimed seeded fulfillment by URL.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @return {Promise<number>} Claimed fulfillment id.
 */
async function openClaimedWorkspace( page ) {
	const fulfillmentId = claimSeedFulfillmentId();
	await page.goto(
		`/wp-admin/admin.php?page=mpcf-workspace&fulfillment_id=${fulfillmentId}`
	);
	await page.waitForURL( /page=mpcf-workspace/ );
	return fulfillmentId;
}

module.exports = {
	claimSeedFulfillmentId,
	openClaimedWorkspace,
	openClaimedWorkspaceFromQueue
};
