/**
 * Playwright global-setup
 *
 * 1. wp-login.php でログインし storageState を保存する
 * 2. wp eval-file で seed.php を実行し SEED_JSON を artifacts/seed.json に書き出す
 *    （JSON をシェル引数に通さないのでクォート問題が発生しない）
 */

'use strict';

const { chromium } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );

module.exports = async () => {
	const baseURL = process.env.WP_BASE_URL || 'http://localhost:8889';
	const user = process.env.WP_USERNAME || 'admin';
	const pass = process.env.WP_PASSWORD || 'password';
	fs.mkdirSync( 'artifacts', { recursive: true } );

	// --- login & persist storage state ---
	const browser = await chromium.launch();
	const page = await browser.newPage();
	await page.goto( `${ baseURL }/wp-login.php` );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pass );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );
	await page.context().storageState( { path: 'artifacts/storage-state.json' } );
	await browser.close();

	// --- seed data via a PHP file (no shell quoting of JSON) ---
	const out = execSync(
		'npx wp-env run tests-cli wp eval-file wp-content/plugins/affilicard/tests/e2e/seed.php',
		{ encoding: 'utf8' }
	);
	const line = out.split( '\n' ).find( ( l ) => l.includes( 'SEED_JSON:' ) );
	if ( ! line ) {
		throw new Error( `seed.php did not output SEED_JSON. Output:\n${ out }` );
	}
	const json = line.slice( line.indexOf( 'SEED_JSON:' ) + 'SEED_JSON:'.length ).trim();
	fs.writeFileSync( 'artifacts/seed.json', json );
};
