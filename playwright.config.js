// @ts-check
const { defineConfig, devices } = require( '@playwright/test' );
const path = require( 'path' );

const STORAGE_STATE_PATH = path.join(
	__dirname,
	'artifacts/storage-states/admin.json'
);

/**
 * Playwright configuration for affilicard E2E tests.
 *
 * WordPress environment is provided by @wordpress/env (wp-env).
 * - Development instance: http://localhost:8888
 * - Test instance:        http://localhost:8889  ← specs use this
 *
 * Authentication is handled by @wordpress/e2e-test-utils-playwright's
 * RequestUtils.setup(), which logs in via REST API and caches the cookie
 * state in artifacts/storage-states/admin.json (worker-scoped fixture).
 */
module.exports = defineConfig( {
	testDir: 'tests/e2e',
	/**
	 * Run each spec file sequentially inside a worker to avoid
	 * concurrent writes to the same WP instance.
	 */
	fullyParallel: false,
	workers: 1,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: [
		[ 'list' ],
		[ 'html', { outputFolder: 'playwright-report', open: 'never' } ],
	],
	outputDir: 'test-results',

	use: {
		baseURL:
			process.env.WP_BASE_URL ||
			'http://localhost:8889',
		storageState: STORAGE_STATE_PATH,
		headless: true,
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
		video: 'off',
	},

	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
