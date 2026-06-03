// @ts-check
const { defineConfig, devices } = require( '@playwright/test' );

const baseURL = process.env.WP_BASE_URL || 'http://localhost:8889';

module.exports = defineConfig( {
	testDir: 'tests/e2e',
	timeout: 60_000,
	expect: { timeout: 10_000 },
	globalTimeout: 15 * 60_000,
	fullyParallel: false,
	workers: 1,
	retries: 0,
	reporter: [ [ 'list' ], [ 'html', { open: 'never' } ] ],
	globalSetup: require.resolve( './tests/e2e/global-setup.js' ),
	use: {
		baseURL,
		storageState: 'artifacts/storage-state.json',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},
	projects: [ { name: 'chromium', use: { ...devices[ 'Desktop Chrome' ] } } ],
} );
