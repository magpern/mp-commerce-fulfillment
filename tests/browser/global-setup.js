// Logs into wp-admin once and saves the session for every spec to reuse
// (playwright.config.js's `use.storageState`) — avoids a login flow at the
// top of every single test file. Credentials match what
// tests/bin/install-wp-site.sh's `wp core install` call creates.
const { chromium } = require( '@playwright/test' );

module.exports = async function globalSetup() {
	const baseURL = process.env.MPCF_BASE_URL || 'http://127.0.0.1:8888';
	const username = process.env.MPCF_ADMIN_USER || 'admin';
	const password = process.env.MPCF_ADMIN_PASSWORD || 'password';

	const browser = await chromium.launch();
	const page = await browser.newPage();

	await page.goto( `${ baseURL }/wp-login.php` );
	await page.fill( '#user_login', username );
	await page.fill( '#user_pass', password );
	await page.click( '#wp-submit' );
	await page.waitForURL( `${ baseURL }/wp-admin/**` );

	await page.context().storageState( { path: 'tests/browser/.auth/admin.json' } );
	await browser.close();
};
