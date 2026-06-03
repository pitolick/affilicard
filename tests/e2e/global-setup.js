/**
 * Playwright global-setup
 *
 * 1. wp-login.php でログインし storageState を保存する
 * 2. wp-cli で affilicard_product を 2 件シードし、ID を artifacts/seed.json に書き出す
 *
 * wp-cli JSON quoting 方針:
 *   execSync で `npx wp-env run tests-cli wp ...` を呼ぶとき、
 *   JSON 文字列内のダブルクォートがシェル展開で壊れないよう
 *   JSON をコンテナ内の /tmp ファイルに書き出してから
 *   `wp post meta update <id> key "$(cat /tmp/xxx.json)"` で渡す。
 */

'use strict';

const { chromium } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

const baseURL = process.env.WP_BASE_URL || 'http://localhost:8889';
const user = process.env.WP_USERNAME || 'admin';
const pass = process.env.WP_PASSWORD || 'password';

/**
 * wp-env の tests-cli コンテナでコマンドを実行する。
 * stdio: 'pipe' にして stdout/stderr をキャプチャし、エラー時にログを出す。
 *
 * @param {string} cmd  `wp ...` 以降のコマンド文字列
 * @returns {string}    stdout (trimmed)
 */
function wpCli( cmd ) {
	try {
		return execSync( `npx wp-env run tests-cli wp ${ cmd }`, {
			encoding: 'utf8',
			stdio: [ 'pipe', 'pipe', 'pipe' ],
		} ).trim();
	} catch ( err ) {
		process.stderr.write(
			`[global-setup] wp-cli failed: wp ${ cmd }\n${ err.stderr || '' }\n`
		);
		throw err;
	}
}

/**
 * JSON を tests-cli コンテナの /tmp/<name>.json に書き込む。
 * `wp-env run tests-cli` は stdin を経由できないため、ホスト側のtmpに
 * ファイルを書き、docker cp に相当する仕組みが無い。
 * 代わりに、JSON 文字列全体を bash -c 'printf ... > /tmp/file' で渡す。
 * ダブルクォートを \x22 にエスケープし単一引数として渡す。
 *
 * @param {string} name  ファイル名（拡張子なし）
 * @param {unknown} data  シリアライズするデータ
 */
function writeJsonToContainer( name, data ) {
	const json = JSON.stringify( data );
	// ダブルクォートを \x22 にエスケープして bash printf で出力
	const escaped = json.replace( /"/g, '\\x22' );
	execSync(
		`npx wp-env run tests-cli bash -c 'printf "${ escaped }" > /tmp/${ name }.json'`,
		{ encoding: 'utf8', stdio: [ 'pipe', 'pipe', 'pipe' ] }
	);
}

/**
 * affilicard_product を 1 件作成し、メタデータを設定して postId を返す。
 *
 * @param {string} title
 * @param {'available'|'out_of_stock'} stock
 * @param {string} affiliateUrl
 * @returns {number}
 */
function seedProduct( title, stock, affiliateUrl ) {
	// 投稿を作成して ID を取得
	const id = wpCli(
		`post create --post_type=affilicard_product --post_status=publish --post_title='${ title }' --porcelain`
	);
	const postId = parseInt( id, 10 );
	if ( ! postId || isNaN( postId ) ) {
		throw new Error( `Failed to create product, got id: ${ id }` );
	}

	// シンプルなメタデータ（値にダブルクォートを含まない）
	wpCli( `post meta update ${ postId } affilicard_stock_status '${ stock }'` );
	wpCli( `post meta update ${ postId } affilicard_product_type 'generic'` );
	wpCli( `post meta update ${ postId } affilicard_schema_version '1'` );
	wpCli( `post meta update ${ postId } affilicard_extid_dmm-books 'extid-${ postId }'` );

	// JSON メタデータはコンテナ内ファイル経由で設定（ダブルクォートのエスケープ問題を回避）
	const listings = [
		{
			platform: 'dmm-books',
			enabled: true,
			affiliate_url: affiliateUrl,
			regular_url: '',
			price: '600',
		},
	];
	const tmpName = `listings_${ postId }`;
	writeJsonToContainer( tmpName, listings );
	wpCli(
		`post meta update ${ postId } affilicard_listings "$(cat /tmp/${ tmpName }.json)" --format=json`
	);

	return postId;
}

module.exports = async () => {
	fs.mkdirSync( 'artifacts', { recursive: true } );

	// --- ログイン & storageState の保存 ---
	const browser = await chromium.launch();
	const page = await browser.newPage();
	try {
		await page.goto( `${ baseURL }/wp-login.php` );
		await page.fill( '#user_login', user );
		await page.fill( '#user_pass', pass );
		await page.click( '#wp-submit' );
		await page.waitForURL( '**/wp-admin/**', { timeout: 30_000 } );
		await page.context().storageState( { path: 'artifacts/storage-state.json' } );
	} finally {
		await browser.close();
	}

	// --- 商品シード ---
	const available = seedProduct(
		'E2E 在庫あり商品',
		'available',
		'https://example.com/aff-a'
	);
	const outOfStock = seedProduct(
		'E2E 在庫切れ商品',
		'out_of_stock',
		'https://example.com/aff-b'
	);

	fs.writeFileSync(
		'artifacts/seed.json',
		JSON.stringify( { available, outOfStock } )
	);

	process.stdout.write(
		`[global-setup] seed.json: available=${ available }, outOfStock=${ outOfStock }\n`
	);
};
