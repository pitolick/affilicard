/**
 * E2E spec: affilicard/product-card ブロック描画テスト
 *
 * wp-cli でブロックマークアップ付きの投稿を直接作成し、
 * フロントエンドで PhP サーバサイドレンダを確認する。
 * ブロックエディタ UI を操作しないため、エディタ UI の
 * フレキシビリティに左右されない安定したテストになる。
 *
 * シード商品は global-setup.js が作成した artifacts/seed.json から取得する。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );

/** @type {{ available: number; outOfStock: number }} */
const seed = JSON.parse( fs.readFileSync( 'artifacts/seed.json', 'utf8' ) );

/**
 * wp-env の tests-cli コンテナでコマンドを実行する。
 *
 * @param {string} cmd  `wp ...` 以降のコマンド文字列
 * @returns {string}    stdout (trimmed)
 */
function wpCli( cmd ) {
	return execSync( `npx wp-env run tests-cli wp ${ cmd }`, {
		encoding: 'utf8',
		stdio: [ 'pipe', 'pipe', 'pipe' ],
	} ).trim();
}

/**
 * ブロックマークアップ付きの投稿を wp-cli で作成し、投稿 ID を返す。
 * post_content はシングルクォートで囲むため、属性 JSON 内に
 * シングルクォートが入らないよう attrs は数値・boolean のみ使う。
 *
 * @param {{ productId: number; ctaBgColor?: string }} attrs
 * @returns {number}
 */
function createPostWithBlock( attrs ) {
	// JSON をシングルクォート安全にするため、値が文字列のカラーは
	// シェルの変数展開を避けるためすべて ASCII printable に限定されている
	const json = JSON.stringify( attrs );
	const content = `<!-- wp:affilicard/product-card ${ json } /-->`;
	// post_content はシングルクォートで囲む。JSON 内にシングルクォートは入らない
	const id = wpCli(
		`post create --post_status=publish --post_title='E2E ブロック投稿' --post_content='${ content }' --porcelain`
	);
	return parseInt( id, 10 );
}

test( '在庫ありの商品は CTA ボタンと色が描画される', async ( { page } ) => {
	const postId = createPostWithBlock( {
		productId: seed.available,
		ctaBgColor: '#123456',
	} );

	await page.goto( `/?p=${ postId }` );

	const card = page.locator( '.affilicard-card' );
	await expect( card ).toBeVisible();

	// CTA リンクが正しい href と rel を持つ
	const cta = page.locator( 'a.affilicard-card__cta' );
	await expect( cta ).toHaveAttribute( 'href', 'https://example.com/aff-a' );
	await expect( cta ).toHaveAttribute( 'rel', 'nofollow sponsored noopener' );

	// インライン style に CSS 変数が注入されている
	await expect( card ).toHaveAttribute( 'style', /--affilicard-cta-bg:#123456/ );
} );

test( '在庫切れの商品は CTA 非表示でバッジが出る', async ( { page } ) => {
	const postId = createPostWithBlock( { productId: seed.outOfStock } );

	await page.goto( `/?p=${ postId }` );

	await expect(
		page.locator( '.affilicard-card__badge--out_of_stock' )
	).toBeVisible();

	await expect( page.locator( 'a.affilicard-card__cta' ) ).toHaveCount( 0 );
} );
