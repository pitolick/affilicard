/**
 * E2E spec: Amazon のライセンスが求める表示（設計 2026-11-24 §5）
 *
 * Amazon Kindle を自動取得（amazon-creators）にした状態で、記事に置いたカードが
 * 1. 価格の時点表示の後ろに「詳しくはこちら」を出し、開くと Amazon の免責文言が出る
 * 2. カードの下に提供元（アマゾンジャパン合同会社）の表記を出す
 * 3. 参考価格をラベル（過去価格:）付きで出す
 * 4. 取得から 24 時間を過ぎた Amazon の書影は出さず、次のストアの書影に切り替える
 *
 * 実 API は叩かない（取得済みの値を REST で直接入れる）。`affilicard_platforms` は全 spec が
 * 共有するため、最後に必ず元へ戻す。認証は cookie ＋ REST nonce（wp-env / Playground のどちらでも動く）。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );

const DISCLAIMER = '本商品の購入においては、購入の時点でAmazon.co.jpに表示されている価格';
const ATTRIBUTION = 'アマゾンジャパン合同会社またはその関連会社により提供されたもの';

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

function hoursAgo( hours ) {
	return new Date( Date.now() - hours * 3600 * 1000 ).toISOString();
}

async function publishCard( api, title, listings ) {
	const product = await api( 'POST', '/wp/v2/affilicard_product', {
		title,
		status: 'publish',
		meta: { affilicard_listings: listings },
	} );
	const post = await api( 'POST', '/wp/v2/posts', {
		title: `${ title } の記事`,
		status: 'publish',
		content: `<!-- wp:affilicard/product-card {"productId":${ product.id }} /-->`,
	} );
	return post.id;
}

test.describe( 'Amazon のライセンスが求める表示', () => {
	let api;
	let originalPlatforms;

	test.beforeEach( async ( { page } ) => {
		api = await restClient( page );
		originalPlatforms = await api( 'GET', '/affilicard/v1/platforms' );
		const switched = originalPlatforms.map( ( p ) =>
			p.code === 'amazon-kindle' ? { ...p, provider: 'amazon-creators' } : p
		);
		await api( 'PUT', '/affilicard/v1/platforms', { platforms: switched } );
	} );

	test.afterEach( async () => {
		if ( originalPlatforms ) {
			await api( 'PUT', '/affilicard/v1/platforms', { platforms: originalPlatforms } );
		}
	} );

	test( '免責文言・提供元の表記・参考価格のラベルを出す', async ( { page } ) => {
		const postId = await publishCard( api, 'E2E Amazon 表示', [
			{
				platform: 'amazon-kindle',
				enabled: true,
				offers: [
					{
						external_id: 'B008ODF84I',
						regular_url: 'https://www.amazon.co.jp/dp/B008ODF84I',
						affiliate_url: 'https://www.amazon.co.jp/dp/B008ODF84I?tag=example-22',
						price: '550',
						list_price: '990',
						list_price_label: '過去価格:',
						badge: '44%OFF',
						image_url: 'https://m.media-amazon.com/images/I/51f7n+2hquL._SL500_.jpg',
						last_verified_at: hoursAgo( 1 ),
					},
				],
			},
		] );

		await page.goto( `/?p=${ postId }` );
		const card = page.locator( '.affilicard-card' ).first();
		await expect( card ).toBeVisible();

		await expect( card.locator( '.affilicard-card__list-price-label' ) ).toHaveText( '過去価格:' );
		await expect( card.locator( '.affilicard-card__list-price' ) ).toHaveText( '¥990' );
		await expect( card.locator( '.affilicard-card__discount' ) ).toHaveText( '44%OFF' );

		const disclaimer = card.locator( '.affilicard-card__price-disclaimer' );
		await expect( disclaimer.getByText( DISCLAIMER ) ).toBeHidden();
		await disclaimer.getByText( '詳しくはこちら' ).click();
		await expect( disclaimer.getByText( DISCLAIMER ) ).toBeVisible();

		await expect( card.locator( '.affilicard-card__attribution' ) ).toContainText( ATTRIBUTION );

		// 注釈は 1 つの枠に箇条書きでまとまり、「※」は ::before で描く。スマホ幅で折り返しても、
		// 2 行目以降は「※」の下に回り込まず本文の頭に揃う（ぶら下げ字下げ）。
		await expect( card.locator( '.affilicard-card__notes li.affilicard-card__note' ) ).toHaveCount( 2 );
		await page.setViewportSize( { width: 360, height: 800 } );
		const layout = await card.locator( '.affilicard-card__attribution' ).evaluate( ( li ) => {
			const marker = getComputedStyle( li, '::before' );
			const range = document.createRange();
			range.selectNodeContents( li );
			const lefts = [ ...range.getClientRects() ].map( ( r ) => Math.round( r.left ) );
			return {
				marker: marker.content,
				liLeft: Math.round( li.getBoundingClientRect().left ),
				lefts,
				text: li.textContent,
			};
		} );
		expect( layout.marker ).toBe( '"※"' );
		expect( layout.text.startsWith( '※' ) ).toBe( false );
		expect( layout.lefts.length ).toBeGreaterThan( 1 );
		expect( new Set( layout.lefts ).size ).toBe( 1 );
		expect( layout.lefts[ 0 ] ).toBeGreaterThan( layout.liLeft );

		await expect( card.locator( 'img.affilicard-card__media-image' ) ).toHaveAttribute(
			'src',
			/m\.media-amazon\.com/
		);
	} );

	test( '24 時間を過ぎた Amazon の書影は出さず、次のストアの書影にする', async ( { page } ) => {
		const postId = await publishCard( api, 'E2E Amazon 古い書影', [
			{
				platform: 'amazon-kindle',
				enabled: true,
				offers: [
					{
						external_id: 'B00932MYP0',
						affiliate_url: 'https://www.amazon.co.jp/dp/B00932MYP0?tag=example-22',
						price: '535',
						image_url: 'https://m.media-amazon.com/images/I/old.jpg',
						last_verified_at: hoursAgo( 25 ),
					},
				],
			},
			{
				platform: 'rakuten-kobo',
				enabled: true,
				offers: [
					{
						external_id: 'rk-e2e',
						affiliate_url: 'https://books.rakuten.co.jp/rk/e2e',
						image_url: 'https://thumbnail.image.rakuten.co.jp/e2e.jpg',
					},
				],
			},
		] );

		await page.goto( `/?p=${ postId }` );
		const card = page.locator( '.affilicard-card' ).first();
		await expect( card ).toBeVisible();

		await expect( card.locator( 'img.affilicard-card__media-image' ) ).toHaveAttribute(
			'src',
			'https://thumbnail.image.rakuten.co.jp/e2e.jpg'
		);
		// Amazon の価格（期限切れ）も書影も出していないので、提供元の表記も出さない。
		await expect( card.locator( '.affilicard-card__attribution' ) ).toHaveCount( 0 );
	} );
} );
