/**
 * E2E spec: プラットフォームのリンクテンプレート
 *
 * 1. 設定画面（VOD タブ）でリンクテンプレートと対象ドメインを入れて保存すると、
 *    REST（/affilicard/v1/platforms）に正規化された値で保存される
 * 2. 作品 URL（regular_url）だけを持つ VOD の購入リンクは、カードの CTA が
 *    テンプレートで組み立てたアフィリエイトリンクになる
 * 3. 対象ドメイン外の URL にはテンプレートを当てず、regular_url のまま出す
 *
 * `affilicard_platforms` は全 spec が共有するオプションのため、各テストの後で必ず元へ戻す。
 * テンプレートの値（rk）は架空。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );

const SETTINGS_URL =
	'/wp-admin/edit.php?post_type=affilicard_product&page=affilicard-settings';

const TEMPLATE = 'https://h.example.test/sp/cc?rk=E2E&url={url_enc}';

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
 * hulu の定義を差し替えた（無ければ足した）プラットフォーム一覧を返す。
 *
 * @param {Array<Object>} platforms 現在の一覧
 * @param {Object}        patch     hulu に当てる値
 * @return {Array<Object>} 新しい一覧
 */
function withHulu( platforms, patch ) {
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
		linkTemplate: '',
		linkHosts: [],
	};
	const found = platforms.find( ( p ) => p.code === 'hulu' );
	const others = platforms.filter( ( p ) => p.code !== 'hulu' );
	return [ ...others, { ...base, ...found, ...patch } ];
}

test.describe( 'プラットフォームのリンクテンプレート', () => {
	let api;
	let originalPlatforms;

	test.beforeEach( async ( { page } ) => {
		api = await restClient( page );
		originalPlatforms = await api( 'GET', '/affilicard/v1/platforms' );
	} );

	test.afterEach( async () => {
		await api( 'PUT', '/affilicard/v1/platforms', {
			platforms: originalPlatforms,
		} );
	} );

	test( '設定画面で入れたテンプレートと対象ドメインが正規化されて保存される', async ( {
		page,
	} ) => {
		// 設定画面の VOD タブに hulu が出るよう、まず定義を用意する（値は空）。
		await api( 'PUT', '/affilicard/v1/platforms', {
			platforms: withHulu( originalPlatforms, {
				linkTemplate: '',
				linkHosts: [],
			} ),
		} );

		await page.goto( SETTINGS_URL );
		await page.getByRole( 'tab', { name: 'プラットフォーム' } ).click();
		await page.getByRole( 'tab', { name: 'VOD' } ).click();
		await page.getByRole( 'button', { name: /^Hulu \(hulu\)/ } ).click();

		await page.getByLabel( 'リンクテンプレート' ).fill( TEMPLATE );
		await page.getByLabel( '対象ドメイン' ).fill( ' Hulu.JP , ' );
		await page.getByRole( 'button', { name: '保存' } ).click();
		await expect(
			page
				.locator( '.components-notice__content' )
				.getByText( '保存しました' )
		).toBeVisible();

		const saved = ( await api( 'GET', '/affilicard/v1/platforms' ) ).find(
			( p ) => p.code === 'hulu'
		);
		expect( saved.linkTemplate ).toBe( TEMPLATE );
		expect( saved.linkHosts ).toEqual( [ 'hulu.jp' ] );
	} );

	test( '作品 URL だけの購入リンクはテンプレートで組み立てたアフィリエイトリンクになり、対象外のドメインはそのまま出す', async ( {
		page,
	} ) => {
		await api( 'PUT', '/affilicard/v1/platforms', {
			platforms: withHulu( originalPlatforms, {
				enabled: true,
				linkTemplate: TEMPLATE,
				linkHosts: [ 'hulu.jp' ],
			} ),
		} );

		const createProduct = ( title, regularUrl, externalId ) =>
			api( 'POST', '/wp/v2/affilicard_product', {
				title,
				status: 'publish',
				meta: {
					affilicard_product_type: 'vod',
					affilicard_listings: [
						{
							platform: 'hulu',
							enabled: true,
							offers: [
								{
									external_id: externalId,
									regular_url: regularUrl,
									affiliate_url: '',
								},
							],
						},
					],
				},
			} );

		const matched = await createProduct(
			'E2E リンクテンプレート（対象ドメイン）',
			'https://www.hulu.jp/e2e-title',
			'link-template-e2e-1'
		);
		const unmatched = await createProduct(
			'E2E リンクテンプレート（対象外）',
			'https://example.test/not-hulu',
			'link-template-e2e-2'
		);
		const post = await api( 'POST', '/wp/v2/posts', {
			title: 'E2E リンクテンプレートの記事',
			status: 'publish',
			content:
				`<!-- wp:affilicard/product-card {"productId":${ matched.id }} /-->` +
				`<!-- wp:affilicard/product-card {"productId":${ unmatched.id }} /-->`,
		} );

		await page.goto( `/?p=${ post.id }` );
		const cards = page.locator( '.affilicard-card' );
		await expect( cards ).toHaveCount( 2 );

		await expect(
			cards.nth( 0 ).locator( 'a.affilicard-card__cta' )
		).toHaveAttribute(
			'href',
			'https://h.example.test/sp/cc?rk=E2E&url=https%3A%2F%2Fwww.hulu.jp%2Fe2e-title'
		);
		await expect(
			cards.nth( 1 ).locator( 'a.affilicard-card__cta' )
		).toHaveAttribute( 'href', 'https://example.test/not-hulu' );
	} );
} );
