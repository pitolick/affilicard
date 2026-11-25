/**
 * E2E spec: 割引率のバッジと付与ポイントの並び方
 *
 * - 片方だけのときは、販売価格の横に並ぶ
 * - 両方あるときは、広い幅（PC）では販売価格の下の段にまとまって並ぶ
 * - 狭い幅（スマホ）では、両方あっても販売価格の横に並ぶ
 *
 * 実 API は叩かない（取得済みの値を REST で直接入れる）。認証は cookie ＋ REST nonce。
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

function offer( id, extra ) {
	return {
		external_id: id,
		regular_url: `https://example.test/${ id }`,
		affiliate_url: `https://example.test/${ id }?aff=1`,
		price: '781',
		last_verified_at: new Date( Date.now() - 3600 * 1000 ).toISOString(),
		...extra,
	};
}

/**
 * 販売価格に対して、まとまり（割引・ポイント）が同じ行にあるか下の段にあるか。
 *
 * @param {import('@playwright/test').Locator} row 店舗行。
 * @return {Promise<'same'|'below'|'none'>} 並び方。
 */
async function perksPlacement( row ) {
	return row.evaluate( ( el ) => {
		const price = el.querySelector( '.affilicard-card__price' ).getBoundingClientRect();
		const perks = el.querySelector( '.affilicard-card__perks' );
		if ( ! perks ) {
			return 'none';
		}
		return perks.getBoundingClientRect().top >= price.bottom - 1 ? 'below' : 'same';
	} );
}

test.describe( '割引率のバッジと付与ポイントの並び方', () => {
	let postId;

	test.beforeAll( async ( { browser } ) => {
		const page = await browser.newPage();
		const api = await restClient( page );
		const create = async ( title, listings ) =>
			(
				await api( 'POST', '/wp/v2/affilicard_product', {
					title,
					status: 'publish',
					meta: { affilicard_listings: listings },
				} )
			).id;

		// 1 枚目: DMM は割引とポイントの両方、楽天は割引だけ。2 枚目: 楽天はポイントだけ、DMM はどちらも無し。
		const first = await create( 'E2E まとまり 1', [
			{ platform: 'dmm-books', enabled: true, offers: [ offer( 'perks-both', { badge: '44%OFF', points: '28' } ) ] },
			{ platform: 'rakuten-kobo', enabled: true, offers: [ offer( 'perks-discount', { badge: '10%OFF' } ) ] },
		] );
		const second = await create( 'E2E まとまり 2', [
			{ platform: 'dmm-books', enabled: true, offers: [ offer( 'perks-none', {} ) ] },
			{ platform: 'rakuten-kobo', enabled: true, offers: [ offer( 'perks-points', { points: '7' } ) ] },
		] );
		const post = await api( 'POST', '/wp/v2/posts', {
			title: 'E2E まとまりの並び方',
			status: 'publish',
			content:
				`<!-- wp:affilicard/product-card {"productId":${ first }} /-->` +
				`<!-- wp:affilicard/product-card {"productId":${ second }} /-->`,
		} );
		postId = post.id;
		await page.close();
	} );

	const rows = ( page ) => {
		const cards = page.locator( '.affilicard-card' );
		const row = ( card, name ) =>
			cards.nth( card ).locator( 'li.affilicard-card__row', { hasText: name } );
		return {
			both: row( 0, 'DMM' ),
			discount: row( 0, '楽天' ),
			none: row( 1, 'DMM' ),
			points: row( 1, '楽天' ),
		};
	};

	test( 'PC 幅: 片方だけなら価格の横、両方あれば下の段にまとめる', async ( { page } ) => {
		await page.setViewportSize( { width: 1280, height: 900 } );
		await page.goto( `/?p=${ postId }` );
		const r = rows( page );

		await expect( r.both.locator( '.affilicard-card__perks--both' ) ).toHaveCount( 1 );
		expect( await perksPlacement( r.both ) ).toBe( 'below' );
		expect( await perksPlacement( r.discount ) ).toBe( 'same' );
		expect( await perksPlacement( r.points ) ).toBe( 'same' );
		expect( await perksPlacement( r.none ) ).toBe( 'none' );

		// 両方あるときも、割引とポイントは同じ段に並ぶ（ポイントだけが離れない）。
		const sameLine = await r.both.locator( '.affilicard-card__perks' ).evaluate( ( el ) => {
			const [ a, b ] = [ ...el.children ].map( ( c ) => c.getBoundingClientRect() );
			return Math.abs( ( a.top + a.bottom ) / 2 - ( b.top + b.bottom ) / 2 ) < 4;
		} );
		expect( sameLine ).toBe( true );
	} );

	test( 'スマホ幅: 両方あっても価格の横に並べる', async ( { page } ) => {
		await page.setViewportSize( { width: 360, height: 800 } );
		await page.goto( `/?p=${ postId }` );
		const r = rows( page );

		expect( await perksPlacement( r.both ) ).toBe( 'same' );
		expect( await perksPlacement( r.discount ) ).toBe( 'same' );
		expect( await perksPlacement( r.points ) ).toBe( 'same' );
		expect( await perksPlacement( r.none ) ).toBe( 'none' );
	} );
} );
