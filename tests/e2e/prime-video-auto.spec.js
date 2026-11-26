/**
 * E2E spec: Prime Video の自動取得（v4.6.0・spec 2026-11-26-prime-video-creators-design.md）
 *
 * Prime Video を自動取得（amazon-creators）にした状態で、動画配信の商品のカードが
 * 1. ¥0 の出品から取った「見放題」を、価格なしの提供形態として出す
 * 2. 有料の最安値に「〜」（price_from）を付けて出す
 * 3. 価格を出さない行でも、API が返した「見放題」には Amazon の提供元の表記を付ける
 * 4. 確かめてから 24 時間を過ぎた「見放題」は出さない
 * 5. 管理画面の購入リンクに「最低価格として出す」の切り替えが出て、保存値を映す
 *
 * 実 API は叩かない（取得済みの値を REST で直接入れる）。`affilicard_platforms` は全 spec が
 * 共有するため、最後に必ず元へ戻す。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );

const ATTRIBUTION = 'アマゾンジャパン合同会社またはその関連会社により提供されたもの';

async function restClient( page ) {
	await page.goto( '/wp-admin/' );
	const nonce = (
		await (
			await page.request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' )
		).text()
	).trim();
	return async ( method, route, data ) => {
		const res = await page.request.fetch(
			`/?rest_route=${ encodeURIComponent( route ) }`,
			{
				method,
				headers: { 'X-WP-Nonce': nonce },
				data,
			}
		);
		if ( ! res.ok() ) {
			throw new Error(
				`${ method } ${ route } failed: ${ res.status() } ${ await res.text() }`
			);
		}
		return res.json();
	};
}

function hoursAgo( hours ) {
	return new Date( Date.now() - hours * 3600 * 1000 ).toISOString();
}

function pvOffer( asin, variant, fields ) {
	return {
		external_id: asin,
		regular_url: `https://www.amazon.co.jp/dp/${ asin }`,
		affiliate_url: `https://www.amazon.co.jp/dp/${ asin }?tag=example-22`,
		variant_label: variant,
		...fields,
	};
}

test.describe( 'Prime Video の自動取得', () => {
	let api;
	let originalPlatforms;

	test.beforeEach( async ( { page } ) => {
		api = await restClient( page );
		originalPlatforms = await api( 'GET', '/affilicard/v1/platforms' );
		const switched = originalPlatforms.map( ( p ) =>
			p.code === 'prime-video'
				? { ...p, provider: 'amazon-creators', eligibleProvider: 'amazon-creators' }
				: p
		);
		await api( 'PUT', '/affilicard/v1/platforms', { platforms: switched } );
	} );

	test.afterEach( async () => {
		if ( originalPlatforms ) {
			await api( 'PUT', '/affilicard/v1/platforms', {
				platforms: originalPlatforms,
			} );
		}
	} );

	test( '見放題と最低価格「〜」をバージョンごとに出し、古い見放題は出さない', async ( {
		page,
	} ) => {
		const product = await api( 'POST', '/wp/v2/affilicard_product', {
			title: 'E2E Prime Video 自動取得',
			status: 'publish',
			meta: {
				affilicard_product_type: 'vod',
				affilicard_listings: [
					{
						platform: 'prime-video',
						enabled: true,
						update_mode: 'auto',
						offers: [
							pvOffer( 'B0E2EPV001', '劇場公開版', {
								price: '400',
								price_from: '1',
								last_verified_at: hoursAgo( 1 ),
							} ),
							pvOffer( 'B0E2EPV002', 'エクステンデッド版', {
								plan_label: '見放題',
								last_verified_at: hoursAgo( 1 ),
							} ),
							pvOffer( 'B0E2EPV003', '4K 版', {
								plan_label: '見放題',
								last_verified_at: hoursAgo( 25 ),
							} ),
						],
					},
				],
			},
		} );
		const post = await api( 'POST', '/wp/v2/posts', {
			title: 'E2E Prime Video 自動取得の記事',
			status: 'publish',
			content: `<!-- wp:affilicard/product-card {"productId":${ product.id }} /-->`,
		} );

		await page.goto( `/?p=${ post.id }` );
		const card = page.locator( '.affilicard-card' ).first();
		await expect( card ).toBeVisible();

		const rows = card.locator( '.affilicard-card__row' );
		await expect( rows ).toHaveCount( 3 );
		await expect( rows.nth( 0 ).locator( '.affilicard-card__price' ) ).toHaveText(
			'¥400〜'
		);
		await expect( rows.nth( 0 ).locator( '.affilicard-card__plan' ) ).toHaveCount(
			0
		);
		await expect( rows.nth( 1 ).locator( '.affilicard-card__plan' ) ).toHaveText(
			'見放題'
		);
		await expect( rows.nth( 1 ).locator( '.affilicard-card__price' ) ).toHaveCount(
			0
		);
		// 25 時間前に確かめた「見放題」は出さない（ボタンは残る）。
		await expect( rows.nth( 2 ).locator( '.affilicard-card__plan' ) ).toHaveCount(
			0
		);
		await expect( rows.nth( 2 ).locator( '.affilicard-card__cta' ) ).toBeVisible();

		await expect( card.locator( '.affilicard-card__attribution' ) ).toContainText(
			ATTRIBUTION
		);

		// 管理画面: 最低価格の切り替えが保存値を映す。
		await page.goto( `/wp-admin/post.php?post=${ product.id }&action=edit` );
		await page.waitForFunction(
			() =>
				window.wp &&
				window.wp.data &&
				window.wp.data.dispatch( 'core/preferences' )
		);
		await page.evaluate( () => {
			window.wp.data
				.dispatch( 'core/preferences' )
				.set( 'core/edit-post', 'welcomeGuide', false );
		} );
		const listingsPanel = page.getByRole( 'button', {
			name: 'プラットフォーム listing',
		} );
		await expect( listingsPanel ).toBeVisible( { timeout: 15_000 } );
		if ( ( await listingsPanel.getAttribute( 'aria-expanded' ) ) === 'false' ) {
			await listingsPanel.click();
		}
		await page.getByRole( 'button', { name: /B0E2EPV001/ } ).first().click();
		await expect(
			page.getByLabel( '最低価格として出す（「〜」を付ける）' ).first()
		).toBeChecked();
	} );
} );
