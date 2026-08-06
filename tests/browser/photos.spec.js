// M6-C: package photography capture / gallery / preview / delete in the
// Packing Workspace. Uses only M6-B REST routes (no getUserMedia).
// Restores photos_required after the test so other browser specs stay green.
const { test, expect } = require( '@playwright/test' );
const path = require( 'path' );
const { openClaimedWorkspaceFromQueue } = require( './claim-seed' );

const SAMPLE_JPG = path.join( __dirname, 'fixtures', 'sample.jpg' );

async function setPhotosRequired( page, enabled ) {
	await page.goto( '/wp-admin/admin.php?page=mpcf-settings' );
	await expect( page.locator( 'text=Package photography' ) ).toBeVisible();

	const required = page.locator( 'input[type="checkbox"][name="photos_required"]' );
	const isChecked = await required.isChecked();

	if ( enabled && ! isChecked ) {
		await required.check();
	} else if ( ! enabled && isChecked ) {
		await required.uncheck();
	} else {
		return;
	}

	await page.locator( '[data-mpcf-sticky-save] button[type="submit"]' ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved' );
}

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

	// Now packing: primary offers Packed (may be disabled once photo_required applies).
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
	test.afterEach( async ( { page } ) => {
		await setPhotosRequired( page, false );
	} );

	test( 'uploads, satisfies requirement, previews, and deletes package photos', async ( { page } ) => {
		await setPhotosRequired( page, true );
		await advanceToPackingWithPackage( page );

		const primary = page.locator( '[data-mpcf-primary-action]' );
		const photos = page.locator( '[data-mpcf-photos]' ).first();
		await expect( photos ).toBeVisible();

		// Package weight + packed lines are done; sealed-package photo still blocks.
		await expect( primary ).toBeDisabled();
		await expect( page.locator( '[data-mpcf-guard-message]' ) ).toContainText( /sealed-package photo/i );
		await expect( page.locator( '[data-mpcf-photo-requirement-status]' ).first() ).toContainText(
			/sealed-package photo still required/i
		);

		await photos.locator( '[data-mpcf-photo-kind]' ).selectOption( 'contents' );
		await photos.locator( '[data-mpcf-photo-input]' ).setInputFiles( SAMPLE_JPG );
		await expect( photos.locator( '[data-mpcf-photo-gallery] li' ) ).toHaveCount( 1, { timeout: 15000 } );
		await expect( page.locator( '[data-mpcf-photo-requirement-status]' ).first() ).toContainText(
			/sealed-package photo still required/i
		);
		await expect( primary ).toBeDisabled();

		await photos.locator( '[data-mpcf-photo-kind]' ).selectOption( 'package' );
		await photos.locator( '[data-mpcf-photo-input]' ).setInputFiles( SAMPLE_JPG );
		await expect( photos.locator( '[data-mpcf-photo-gallery] li' ) ).toHaveCount( 2, { timeout: 15000 } );
		await expect( page.locator( '[data-mpcf-photo-requirement-status]' ).first() ).toContainText(
			/satisfied/i
		);
		await expect( primary ).toBeEnabled();
		await expect( primary ).toHaveText( /^Packed$/ );

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await photos.locator( '[data-mpcf-photo-gallery] li' ).filter( { hasText: 'Sealed package' } ).locator( 'button', { hasText: 'Preview' } ).click();
		const lightbox = page.locator( '[data-mpcf-photo-lightbox]' );
		await expect( lightbox ).toBeVisible();
		await page.keyboard.press( 'Escape' );
		await expect( lightbox ).toHaveCount( 0 );

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await photos.locator( '[data-mpcf-photo-gallery] li' ).filter( { hasText: 'Sealed package' } ).locator( 'button', { hasText: 'Delete' } ).click();
		await expect( photos.locator( '[data-mpcf-photo-gallery] li' ) ).toHaveCount( 1, { timeout: 15000 } );
		await expect( page.locator( '[data-mpcf-photo-requirement-status]' ).first() ).toContainText(
			/sealed-package photo still required/i
		);
		await expect( primary ).toBeDisabled();
	} );
} );
