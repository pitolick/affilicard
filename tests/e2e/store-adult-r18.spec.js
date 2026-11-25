/**
 * E2E spec: ストアの成人向け判定による R18 表示
 *
 * - どれかのストアが成人向けと判定した商品は、商品の R18 設定が無くても R18 表示（18+ バッジ）になる
 * - ブロックの storeR18=false でそのカードだけ外せる
 * - サイト設定（store_adult_r18）を OFF にすると全カードで外れる。ブロックの storeR18=true は従う
 *
 * 実 API は叩かない（判定済みの値を REST で直接入れる）。サイト設定は最後に必ず元へ戻す。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );

async function restClient( page ) {
	await page.goto( '/wp-admin/' );
	const nonce = ( await ( await page.request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
	return async ( method, route, data ) => {
		const res = await page.request.fetch( `/?rest_route=${ encodeURIComponent( route ) }`, {
			method,
			headers: { 'X-WP-Nonce': nonce },
			data,
		} );
		if ( ! res.ok() ) {
			throw new Error( `${ method } ${ route } failed: ${ res.status() } ${ await res.text() }` );
		}
		return res.json();
	};
}

const BADGE = '.affilicard-card__cover-badge';

test.describe( 'ストアの成人向け判定による R18 表示', () => {
	let api;
	let originalSetting;
	let postId;

	test.beforeEach( async ( { page } ) => {
		api = await restClient( page );
		originalSetting = ( await api( 'GET', '/affilicard/v1/settings' ) ).store_adult_r18;

		const product = await api( 'POST', '/wp/v2/affilicard_product', {
			title: 'E2E 成人向け判定',
			status: 'publish',
			meta: {
				affilicard_listings: [
					{
						platform: 'dmm-books',
						enabled: true,
						offers: [
							{
								external_id: 'adult-e2e',
								affiliate_url: 'https://example.test/adult-e2e',
								// 表紙マスク（18+ バッジ）は書影があるときだけ描画される。
								image_url: 'https://example.test/cover.jpg',
								adult: '1',
							},
						],
					},
				],
			},
		} );
		const post = await api( 'POST', '/wp/v2/posts', {
			title: 'E2E 成人向け判定の記事',
			status: 'publish',
			content:
				`<!-- wp:affilicard/product-card {"productId":${ product.id }} /-->` +
				`<!-- wp:affilicard/product-card {"productId":${ product.id },"storeR18":false} /-->` +
				`<!-- wp:affilicard/product-card {"productId":${ product.id },"storeR18":true} /-->`,
		} );
		postId = post.id;
	} );

	test.afterEach( async () => {
		await api( 'PUT', '/affilicard/v1/settings', { store_adult_r18: originalSetting !== false } );
	} );

	test( '既定ではストアの判定で R18 表示になり、ブロックで外せる', async ( { page } ) => {
		await api( 'PUT', '/affilicard/v1/settings', { store_adult_r18: true } );
		await page.goto( `/?p=${ postId }` );
		const cards = page.locator( '.affilicard-card' );
		await expect( cards ).toHaveCount( 3 );

		await expect( cards.nth( 0 ).locator( BADGE ) ).toHaveCount( 1 );
		await expect( cards.nth( 1 ).locator( BADGE ) ).toHaveCount( 0 );
		await expect( cards.nth( 2 ).locator( BADGE ) ).toHaveCount( 1 );
	} );

	test( 'サイト設定を OFF にすると外れ、ブロックで従うと指定したカードだけ R18 表示になる', async ( { page } ) => {
		await api( 'PUT', '/affilicard/v1/settings', { store_adult_r18: false } );
		await page.goto( `/?p=${ postId }` );
		const cards = page.locator( '.affilicard-card' );
		await expect( cards ).toHaveCount( 3 );

		await expect( cards.nth( 0 ).locator( BADGE ) ).toHaveCount( 0 );
		await expect( cards.nth( 1 ).locator( BADGE ) ).toHaveCount( 0 );
		await expect( cards.nth( 2 ).locator( BADGE ) ).toHaveCount( 1 );
	} );
} );
