// Dev/CI-only Playwright config (ADR-0006). Never shipped — see
// bin/build-zip.sh, bin/release-audit.sh, tests/unit/ReleaseArtifactGuardTest.php.
//
// Runs against a real, running WordPress+WooCommerce site
// (tests/bin/install-wp-site.sh provisions and seeds it), never a mock —
// PHPUnit already covers everything mockable; this tier exists for real
// browser behavior (keyboard flows, focus, accessibility, print) PHPUnit
// structurally cannot observe.
const { defineConfig, devices } = require( '@playwright/test' );

const baseURL = process.env.MPCF_BASE_URL || 'http://127.0.0.1:8888';

module.exports = defineConfig( {
	testDir: './tests/browser',
	fullyParallel: true,
	forbidOnly: !! process.env.CI,
	// One automatic retry, CI only (Architecture Plan §IV.16, risk M2-R5:
	// "the browser job... is the only job permitted a single automatic
	// retry"). Playwright's own retry reruns just the failed test with a
	// trace, which is the right grain for that requirement — no separate
	// GitHub Actions retry wrapper needed.
	retries: process.env.CI ? 1 : 0,
	workers: process.env.CI ? 2 : undefined,
	reporter: process.env.CI ? [ [ 'html', { open: 'never' } ], [ 'list' ] ] : 'list',
	use: {
		baseURL,
		trace: 'retain-on-failure',
		storageState: 'tests/browser/.auth/admin.json'
	},
	globalSetup: require.resolve( './tests/browser/global-setup.js' ),
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] }
		},
		{
			name: 'firefox',
			use: { ...devices[ 'Desktop Firefox' ] }
		}
	]
} );
