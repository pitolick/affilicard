/**
 * E2E spec: affilicard/product-card ブロック描画テスト
 *
 * global-setup が seed.php 経由で作成したブロック投稿のフロントエンドを検証する。
 * wp-cli / ブロックエディタ UI は一切使用しない。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );

const seed = JSON.parse( fs.readFileSync( 'artifacts/seed.json', 'utf8' ) );

test( '在庫ありの商品ブロックは CTA と色が描画される', async ( { page } ) => {
	await page.goto( `/?p=${ seed.availablePostId }` );
	const card = page.locator( '.affilicard-card' ).first();
	await expect( card ).toBeVisible();
	const cta = page.locator( 'a.affilicard-card__cta' ).first();
	await expect( cta ).toHaveAttribute( 'href', 'https://example.com/aff-a' );
	await expect( cta ).toHaveAttribute( 'rel', 'nofollow sponsored noopener' );
	await expect( card ).toHaveAttribute( 'style', /--affilicard-cta-bg:#123456/ );
} );

test( '在庫切れの商品ブロックは CTA 非表示でバッジが出る', async ( { page } ) => {
	await page.goto( `/?p=${ seed.outOfStockPostId }` );
	await expect( page.locator( '.affilicard-card__badge--out_of_stock' ).first() ).toBeVisible();
	await expect( page.locator( 'a.affilicard-card__cta' ) ).toHaveCount( 0 );
} );
