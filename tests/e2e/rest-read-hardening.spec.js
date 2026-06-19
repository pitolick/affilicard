/**
 * E2E spec: show_in_rest=true による wp/v2/affilicard_product の
 * 未認証読み取りが拒否される（非 viewable CPT）ことを固定する。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );

const seed = JSON.parse( fs.readFileSync( 'artifacts/seed.json', 'utf8' ) );

test.describe( 'wp/v2/affilicard_product read hardening', () => {
	test( '未認証ではコレクションが拒否される', async ( { playwright } ) => {
		const anon = await playwright.request.newContext();
		const res = await anon.get( '/wp-json/wp/v2/affilicard_product' );
		expect( [ 401, 403, 404 ] ).toContain( res.status() );
		await anon.dispose();
	} );

	test( '未認証では単一アイテム(publish)が拒否され affiliate_url を返さない', async ( {
		playwright,
	} ) => {
		const anon = await playwright.request.newContext();
		const res = await anon.get(
			`/wp-json/wp/v2/affilicard_product/${ seed.availableProductId }`
		);
		expect( [ 401, 403, 404 ] ).toContain( res.status() );
		const text = await res.text();
		expect( text ).not.toContain( 'example.com/aff-a' );
		expect( text ).not.toContain( 'affiliate_url' );
		await anon.dispose();
	} );

	test( '未認証では下書き(draft)商品が拒否され露出しない', async ( {
		playwright,
	} ) => {
		const anon = await playwright.request.newContext();
		const res = await anon.get(
			`/wp-json/wp/v2/affilicard_product/${ seed.draftProductId }`
		);
		expect( [ 401, 403, 404 ] ).toContain( res.status() );
		const text = await res.text();
		expect( text ).not.toContain( 'example.com/aff-draft' );
		await anon.dispose();
	} );

	// 注: subscriber/contributor など低権限ユーザーでの read 検証は本 PR スコープ外
	// （現 E2E は admin/未認証の 2 段階）。低権限ロールのテストは別 PR で追加する。
} );
