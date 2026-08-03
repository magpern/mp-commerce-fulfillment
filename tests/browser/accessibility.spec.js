// Architecture Plan §IV.14/§IV.15 criterion 8: zero serious/critical axe
// violations on the workspace. PHPUnit cannot run an accessibility engine
// against rendered, styled, JS-enhanced markup — this is exactly the
// real-browser-only observation ADR-0006 exists for.
const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

test.describe( 'Packing Workspace — accessibility', () => {
	test( 'has no serious or critical axe violations', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=mpcf-queue' );
		await page.locator( '[data-mpcf-row-open]' ).first().click();
		await page.waitForURL( /page=mpcf-workspace/ );
		await expect( page.locator( '[data-mpcf-workspace]' ) ).toBeVisible();

		const results = await new AxeBuilder( { page } )
			.include( '[data-mpcf-workspace]' )
			.analyze();

		const seriousOrCritical = results.violations.filter( ( violation ) =>
			'serious' === violation.impact || 'critical' === violation.impact
		);

		expect( seriousOrCritical, JSON.stringify( seriousOrCritical, null, 2 ) ).toEqual( [] );
	} );
} );
