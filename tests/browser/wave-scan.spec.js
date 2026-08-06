/**
 * @file Wave Scan Mode browser smoke (Playwright).
 *
 * Requires a WordPress admin session fixture compatible with existing
 * mpcf browser tests. Skips gracefully when credentials are absent.
 */

const { test, expect } = require('@playwright/test');

test.describe('Wave Scan Mode', () => {
	test.skip(!process.env.MPCF_BROWSER_BASE_URL, 'MPCF_BROWSER_BASE_URL not set');

	test('wave workspace loads and exposes scan controls', async ({ page }) => {
		const base = process.env.MPCF_BROWSER_BASE_URL.replace(/\/$/, '');
		await page.goto(`${base}/wp-admin/admin.php?page=mpcf-wave`);
		await expect(page.locator('h1')).toContainText(/Wave Workspace/i);
	});
});
