/**
 * Playwright global-setup
 *
 * 1. wp-login.php でログインし storageState を保存する
 * 2. wp eval-file で seed.php を実行し SEED_JSON を artifacts/seed.json に書き出す
 *    （JSON をシェル引数に通さないのでクォート問題が発生しない）
 * 3. Task 16: REST への書き込み（offers 保存往復・複数値 meta ミラー）を検証する
 *    E2E は cookie 認証だと nonce が要るため、WP Application Password を作り
 *    Basic 認証ヘッダを artifacts/app-credentials.json に書き出す。
 *    `wp-env` はローカルホスト上の平文 HTTP だが、Application Passwords は
 *    `is_ssl() || host === 'localhost'` で許可されるため localhost では動く。
 *    `--all` で毎回作り直す（このユーザーは E2E 専用の管理者なので安全）。
 */

'use strict';

const { chromium } = require( '@playwright/test' );
const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );

/**
 * `wp-env run tests-cli <args...>` をシェルを介さずに実行する。
 *
 * **外から来る値をシェル文字列へ埋め込まない。** WP_USERNAME のような環境変数を
 * テンプレートリテラルでコマンド行に差し込むと、`;` や `$(...)` がシェルに
 * そのまま解釈される（コマンドインジェクション。CI の環境変数を触れる者なら
 * 誰でも任意のコマンドを走らせられる）。execFileSync に argv 配列で渡せば
 * シェル自体が介在せず、値は 1 引数のままコマンドへ届く。
 *
 * @param {string[]}             args               wp-cli 側の argv。
 * @param {Object}               [options]
 * @param {boolean}              [options.allowFailure] 非ゼロ終了を無視して空文字を返す。
 * @return {string} 標準出力。
 */
function wpEnvRun( args, { allowFailure = false } = {} ) {
	try {
		return execFileSync( 'npx', [ 'wp-env', 'run', 'tests-cli', ...args ], {
			encoding: 'utf8',
		} );
	} catch ( error ) {
		if ( ! allowFailure ) {
			throw error;
		}
		return '';
	}
}

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
	const out = wpEnvRun( [
		'wp',
		'eval-file',
		'--use-include',
		'wp-content/plugins/affilicard/tests/e2e/seed.php',
	] );
	const line = out.split( '\n' ).find( ( l ) => l.includes( 'SEED_JSON:' ) );
	if ( ! line ) {
		throw new Error( `seed.php did not output SEED_JSON. Output:\n${ out }` );
	}
	const json = line.slice( line.indexOf( 'SEED_JSON:' ) + 'SEED_JSON:'.length ).trim();
	fs.writeFileSync( 'artifacts/seed.json', json );

	// --- REST 用 Application Password（Basic 認証）を作り直す ---
	// 既存の Application Password が 1 つも無い（フレッシュな CI DB）と wp-cli が
	// 非ゼロ終了する場合がある。削除自体が目的で対象が無ければ何もする必要が
	// 無いため allowFailure で無視し、後続の create だけを失敗させたい
	// （シェルを介さないので `|| true` は使えない）。
	wpEnvRun(
		[ 'wp', 'user', 'application-password', 'delete', user, '--all' ],
		{ allowFailure: true }
	);
	const appPassOut = wpEnvRun( [
		'wp',
		'user',
		'application-password',
		'create',
		user,
		'affilicard-e2e',
		'--porcelain',
	] );
	// wp-env run は `ℹ Starting ...` / `✔ Ran ...` で実コマンドの出力を挟むため、
	// 生成されたパスワード本体（英数字のみ）だけを 1 行ずつ拾って抽出する。
	const appPassword = appPassOut
		.split( '\n' )
		.map( ( l ) => l.trim() )
		.find( ( l ) => /^[A-Za-z0-9]{20,}$/.test( l ) );
	if ( ! appPassword ) {
		throw new Error(
			`application-password create did not output a password. Output:\n${ appPassOut }`
		);
	}
	fs.writeFileSync(
		'artifacts/app-credentials.json',
		JSON.stringify( { username: user, password: appPassword } )
	);
};
