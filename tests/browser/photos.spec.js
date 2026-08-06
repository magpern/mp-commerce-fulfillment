// M6-C: package photography capture / gallery / preview / delete in the
// Packing Workspace. Uses only M6-B REST routes (no getUserMedia).
const { test, expect } = require( '@playwright/test' );
const path = require( 'path' );
const { openClaimedWorkspaceFromQueue } = require( './claim-seed' );

const SAMPLE_JPG = path.join( __dirname, 'fixtures', 'sample.jpg' );

async function advanceToPackingWithPackage( page ) {
	await openClaimedWorkspaceFromQueue( page );
	await expect( page.locator( '[data-mpcf-workspace]' ) ).toBeVisible();

	const primary = page.locator( '[data-mpcf-primary-action]' );
	const stepper = page.locator( '.mpcf-ui-quantity-stepper__value' ).first();

	await expect( primary ).toHaveText( /^Picking$/ );
	await page.keyboard.press( 'Control+Enter' );

	await expect( primary ).toHaveText( /^Picked$/ );
	await stepper.waitFor( { state: 'attached' } );
	await page.keyboard.press( 'Shift+A' );
	await page.keyboard.press( 'Control+Enter' );

	await expect( primary ).toHaveText( /^Packing$/ );
	await page.keyboard.press( 'Control+Enter' );

	await expect( primary ).toHaveText( /^Packed$/ );
	await stepper.waitFor( { state: 'attached' } );
	await page.keyboard.press( 'Shift+A' );

	await page.locator( '[data-mpcf-tracking-number]' ).first().fill( 'TRACK-PHOTO-1' );
	await page.locator( '[data-mpcf-tracking-number]' ).first().blur();

	const weightField = page.locator( '[data-mpcf-package-field="weight_grams"]' ).first();
	await weightField.waitFor( { state: 'visible' } );
	await weightField.fill( '1.2' );
	await weightField.blur();

	await expect( page.locator( '[data-mpcf-photos]' ).first() ).toBeVisible();
}

test.describe( 'Packing Workspace — package photography', () => {
	test( 'uploads, previews, and deletes package photos', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=mpcf-settings' );
		await expect( page.locator( 'text=Package photography' ) ).toBeVisible();

		const required = page.locator( 'input[type="checkbox"][name="photos_required"]' );
		if ( ! ( await required.isChecked() ) ) {
			await required.check();
		}
		await page.locator( '[data-mpcf-sticky-save] button[type="submit"]' ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved' );

		await advanceToPackingWithPackage( page );

		const photos = page.locator( '[data-mpcf-photos]' ).first();
		await expect( photos ).toBeVisible();

		await photos.locator( '[data-mpcf-photo-kind]' ).selectOption( 'contents' );
		await photos.locator( '[data-mpcf-photo-input]' ).setInputFiles( SAMPLE_JPG );
		await expect( photos.locator( '[data-mpcf-photo-gallery] li' ) ).toHaveCount( 1, { timeout: 15000 } );

		await photos.locator( '[data-mpcf-photo-kind]' ).selectOption( 'package' );
		await photos.locator( '[data-mpcf-photo-input]' ).setInputFiles( SAMPLE_JPG );
		await expect( photos.locator( '[data-mpcf-photo-gallery] li' ) ).toHaveCount( 2, { timeout: 15000 } );
		await expect( page.locator( '[data-mpcf-photo-requirement-status]' ).first() ).toContainText(
			/satisfied/i
		);

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await photos.locator( '[data-mpcf-photo-gallery] li' ).first().locator( 'button', { hasText: 'Preview' } ).click();
		const lightbox = page.locator( '[data-mpcf-photo-lightbox]' );
		await expect( lightbox ).toBeVisible();
		await page.keyboard.press( 'Escape' );
		await expect( lightbox ).toHaveCount( 0 );

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await photos.locator( '[data-mpcf-photo-gallery] li' ).first().locator( 'button', { hasText: 'Delete' } ).click();
		await expect( photos.locator( '[data-mpcf-photo-gallery] li' ) ).toHaveCount( 1, { timeout: 15000 } );
	} );
} );
