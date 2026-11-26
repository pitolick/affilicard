/**
 * E2E spec: 購入リンクのバージョン（variant_label）
 *
 * 1. 商品編集画面の購入リンクに「バージョン」を入れて保存すると、listings meta の offer に
 *    variant_label として保存される
 * 2. バージョンの違う購入リンクは、カードで同じストアの中に 1 行ずつ並ぶ
 *    （2 行目以降はストア名を目に見える形では繰り返さない。CTA にバージョン名の計測属性が付く）
 * 3. 同じバージョンの購入リンクは「代わりのリンク」のまま、表示順が先の 1 件だけが出る
 *
 * hulu を例に使う（既定のプラットフォーム。テンプレートは空のまま regular_url を CTA に出す）。
 * `affilicard_platforms` は全 spec が共有するオプションのため、各テストの後で必ず元へ戻す。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );

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

/**
 * hulu を有効・テンプレート無しにした一覧を返す（無ければ足す）。
 *
 * @param {Array<Object>} platforms 現在の一覧
 * @return {Array<Object>} 新しい一覧
 */
function withPlainHulu( platforms ) {
	const base = {
		code: 'hulu',
		name: 'Hulu',
		provider: 'manual',
		displayOrder: 6,
		enabled: true,
		applicableTypes: [ 'vod' ],
		buttonLabel: 'Huluで見る',
		brandColor: '#1ce783',
		buttonTextColor: '#000000',
	};
	const found = platforms.find( ( p ) => p.code === 'hulu' );
	const others = platforms.filter( ( p ) => p.code !== 'hulu' );
	return [
		...others,
		{ ...base, ...found, enabled: true, linkTemplate: '', linkHosts: [] },
	];
}

test.describe( '購入リンクのバージョン', () => {
	let api;
	let originalPlatforms;

	test.beforeEach( async ( { page } ) => {
		api = await restClient( page );
		originalPlatforms = await api( 'GET', '/affilicard/v1/platforms' );
		await api( 'PUT', '/affilicard/v1/platforms', {
			platforms: withPlainHulu( originalPlatforms ),
		} );
	} );

	test.afterEach( async () => {
		await api( 'PUT', '/affilicard/v1/platforms', {
			platforms: originalPlatforms,
		} );
	} );

	test( 'バージョンの違う購入リンクは同じストアの中で 1 行ずつ並び、同じバージョンは 1 件だけ出る', async ( {
		page,
	} ) => {
		const product = await api( 'POST', '/wp/v2/affilicard_product', {
			title: 'E2E バージョン違い',
			status: 'publish',
			meta: {
				affilicard_product_type: 'vod',
				affilicard_listings: [
					{
						platform: 'hulu',
						enabled: true,
						offers: [
							{
								display_order: 20,
								external_id: 'variant-e2e-dub',
								regular_url: 'https://www.hulu.jp/e2e-dub',
								variant_label: '吹替版',
							},
							{
								display_order: 10,
								external_id: 'variant-e2e-sub',
								regular_url: 'https://www.hulu.jp/e2e-sub',
								variant_label: '字幕版',
								plan_label: '見放題',
							},
							{
								display_order: 30,
								external_id: 'variant-e2e-sub-alt',
								regular_url: 'https://www.hulu.jp/e2e-sub-alt',
								variant_label: '字幕版',
							},
						],
					},
				],
			},
		} );
		const post = await api( 'POST', '/wp/v2/posts', {
			title: 'E2E バージョン違いの記事',
			status: 'publish',
			content: `<!-- wp:affilicard/product-card {"productId":${ product.id }} /-->`,
		} );

		await page.goto( `/?p=${ post.id }` );
		const rows = page.locator( '.affilicard-card .affilicard-card__row' );
		await expect( rows ).toHaveCount( 2 );

		await expect( rows.nth( 0 ).locator( '.affilicard-card__variant' ) ).toHaveText( '字幕版' );
		await expect( rows.nth( 0 ).locator( '.affilicard-card__plan' ) ).toHaveText( '見放題' );
		await expect( rows.nth( 0 ).locator( '.affilicard-card__platform' ) ).toBeVisible();
		await expect( rows.nth( 0 ).locator( 'a.affilicard-card__cta' ) ).toHaveAttribute(
			'href',
			'https://www.hulu.jp/e2e-sub'
		);
		await expect( rows.nth( 0 ).locator( 'a.affilicard-card__cta' ) ).toHaveAttribute(
			'data-affilicard-variant',
			'字幕版'
		);

		await expect( rows.nth( 1 ).locator( '.affilicard-card__variant' ) ).toHaveText( '吹替版' );
		await expect( rows.nth( 1 ).locator( 'a.affilicard-card__cta' ) ).toHaveAttribute(
			'href',
			'https://www.hulu.jp/e2e-dub'
		);
		// 2 行目はストア名を見せない（スクリーンリーダー向けの文字だけ残す）。
		await expect( rows.nth( 1 ).locator( '.affilicard-card__sr-only' ) ).toHaveText( 'Hulu' );
		await expect( rows.nth( 1 ).getByText( 'Hulu', { exact: true } ) ).not.toBeInViewport();

		// 同じバージョン（字幕版）の代わりのリンクは出ない。
		await expect( page.locator( 'a[href="https://www.hulu.jp/e2e-sub-alt"]' ) ).toHaveCount( 0 );
	} );

	test( '商品編集画面でバージョンを入れて保存すると variant_label として保存される', async ( {
		page,
	} ) => {
		const product = await api( 'POST', '/wp/v2/affilicard_product', {
			title: 'E2E バージョン入力',
			status: 'publish',
			meta: {
				affilicard_product_type: 'vod',
				affilicard_listings: [
					{
						platform: 'hulu',
						enabled: true,
						offers: [
							{
								external_id: 'variant-e2e-edit',
								regular_url: 'https://www.hulu.jp/e2e-edit',
							},
						],
					},
				],
			},
		} );

		await page.goto( `/wp-admin/post.php?post=${ product.id }&action=edit` );
		await page.waitForFunction(
			() =>
				window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() &&
				window.wp.data.dispatch( 'core/preferences' )
		);
		await page.evaluate( () => {
			window.wp.data
				.dispatch( 'core/preferences' )
				.set( 'core/edit-post', 'welcomeGuide', false );
		} );

		const expandSection = async ( name ) => {
			const btn = page.getByRole( 'button', { name } );
			await expect( btn ).toBeVisible( { timeout: 15_000 } );
			if ( ( await btn.getAttribute( 'aria-expanded' ) ) === 'false' ) {
				await btn.click();
			}
		};
		await expandSection( 'Affilicard 商品設定' );
		await expandSection( 'プラットフォーム listing' );
		await expandSection( 'variant-e2e-edit' );
		await page.getByLabel( 'バージョン' ).last().fill( 'ディレクターズカット版' );

		// 保存ボタンの文言は WP のバージョンで変わる（Update / Save）ため、エディタの保存を直接呼ぶ。
		await page.evaluate( () =>
			window.wp.data.dispatch( 'core/editor' ).savePost()
		);
		await page.waitForFunction( () => {
			const editor = window.wp?.data?.select( 'core/editor' );
			return !! editor && ! editor.isSavingPost() && ! editor.isEditedPostDirty();
		} );

		await expect
			.poll( async () => {
				const got = await api( 'GET', `/affilicard/v1/products/${ product.id }` );
				return got.listings?.[ 0 ]?.offers?.[ 0 ]?.variant_label ?? '';
			} )
			.toBe( 'ディレクターズカット版' );
	} );
} );
