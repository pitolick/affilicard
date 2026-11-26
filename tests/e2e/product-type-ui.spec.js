/**
 * E2E spec: 商品タイプを PHP の定義から出す（4.5.0）
 *
 * 1. 設定の「デフォルト商品タイプ」に動画配信が出る。動画配信にすると、商品編集画面で
 *    新しく作る商品が動画配信で始まる（Plugin::onInsertProduct()）
 * 2. 提供形態（plan_label）の入力欄は動画配信の商品でだけ出る。電子書籍へ切り替えると
 *    消える
 * 3. ストアの選択肢は、商品タイプ向けのストアが先、ほかは「（◯◯ 向け）」付きで後ろ
 *
 * `affilicard_general` は全 spec が共有するオプションのため、テストの後で必ず元へ戻す。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );

const SETTINGS_URL =
	'/wp-admin/edit.php?post_type=affilicard_product&page=affilicard-settings';

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

async function openNewProduct( page ) {
	await page.goto( '/wp-admin/post-new.php?post_type=affilicard_product' );
	await page.waitForFunction(
		() =>
			window.wp &&
			window.wp.data &&
			window.wp.data.select( 'core/edit-post' ) &&
			window.wp.data.dispatch( 'core/preferences' )
	);
	await page.evaluate( () => {
		window.wp.data
			.dispatch( 'core/preferences' )
			.set( 'core/edit-post', 'welcomeGuide', false );
	} );
}

async function expandSection( page, name ) {
	const btn = page.getByRole( 'button', { name } );
	await expect( btn ).toBeVisible( { timeout: 15_000 } );
	if ( ( await btn.getAttribute( 'aria-expanded' ) ) === 'false' ) {
		await btn.click();
	}
}

test.describe( '商品タイプ（PHP の定義から出す）', () => {
	let api;
	let originalSettings;

	test.beforeEach( async ( { page } ) => {
		api = await restClient( page );
		originalSettings = await api( 'GET', '/affilicard/v1/settings' );
	} );

	test.afterEach( async () => {
		await api( 'PUT', '/affilicard/v1/settings', originalSettings );
	} );

	test( '設定のデフォルト商品タイプに動画配信が出る', async ( { page } ) => {
		await page.goto( SETTINGS_URL );
		const select = page.getByLabel( 'デフォルト商品タイプ' );
		await expect( select ).toBeVisible();
		await expect( select.locator( 'option' ) ).toHaveText( [
			'汎用',
			'電子書籍',
			'動画配信',
		] );
	} );

	test( '既定が動画配信なら新しい商品は動画配信で始まり、提供形態の欄が出る', async ( {
		page,
	} ) => {
		await api( 'PUT', '/affilicard/v1/settings', {
			...originalSettings,
			default_product_type: 'vod',
		} );

		await openNewProduct( page );
		await expandSection( page, 'Affilicard 商品設定' );
		const typeSelect = page.getByLabel( '商品タイプ' );
		await expect( typeSelect ).toHaveValue( 'vod' );

		// 追加情報は動画配信の推奨項目。
		await expandSection( page, '追加情報' );
		await expect( page.getByLabel( '監督' ) ).toBeVisible();

		await expandSection( page, 'プラットフォーム listing' );
		await page.getByRole( 'button', { name: 'listing を追加' } ).click();

		// 動画配信向けのストアが先、電子書籍向けは「向け」付きで後ろ。
		const platformSelect = page.getByLabel( 'プラットフォーム' ).last();
		const labels = await platformSelect.locator( 'option' ).allTextContents();
		const firstEbook = labels.findIndex( ( l ) => l.includes( '電子書籍 向け' ) );
		const lastVod = labels.findLastIndex( ( l ) =>
			[ 'U-NEXT', 'Hulu', 'Prime Video' ].includes( l )
		);
		expect( firstEbook ).toBeGreaterThan( -1 );
		expect( lastVod ).toBeGreaterThan( -1 );
		expect( lastVod ).toBeLessThan( firstEbook );

		await platformSelect.selectOption( 'hulu' );
		await page.getByRole( 'button', { name: '購入リンクを追加' } ).click();
		await expandSection( page, '（外部 ID 未設定）' );
		await expect( page.getByLabel( '提供形態' ) ).toBeVisible();

		// 電子書籍に切り替えると、提供形態の欄は消え、ストアに注意が出る。
		await typeSelect.selectOption( 'ebook' );
		await expect( page.getByLabel( '提供形態' ) ).toHaveCount( 0 );
		// Notice は読み上げ用の領域にも同じ文言を出すので、Notice 本体に絞る。
		await expect(
			page
				.locator( '.components-notice' )
				.filter( { hasText: /この商品タイプ向けのストアではありません/ } )
		).toBeVisible();
	} );

	test( '既定が汎用なら新しい商品は汎用で始まる', async ( { page } ) => {
		await api( 'PUT', '/affilicard/v1/settings', {
			...originalSettings,
			default_product_type: 'generic',
		} );

		await openNewProduct( page );
		await expandSection( page, 'Affilicard 商品設定' );
		await expect( page.getByLabel( '商品タイプ' ) ).toHaveValue( 'generic' );
	} );
} );
