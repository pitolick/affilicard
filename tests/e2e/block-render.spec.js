/**
 * E2E spec: affilicard/product-card ブロック描画テスト
 *
 * 1. REST で affilicard_product を作成（在庫あり・listing あり）
 * 2. 通常の投稿にブロックを挿入し、ctaBgColor 属性を指定してパブリッシュ
 * 3. フロントエンドでカード描画を確認:
 *    - .affilicard-card__title が商品タイトルを表示
 *    - a.affilicard-card__cta がアフィリエイト URL で rel 属性が正しい
 *    - ルート要素の inline style に --affilicard-cta-bg が注入されている
 * 4. 在庫切れ商品では CTA が表示されず、バッジが表示されることを確認
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/** テスト用の色（小文字 hex）— WordPress の sanitize_hex_color が正規化する */
const CTA_BG_COLOR = '#1a2b3c';

test.describe( 'affilicard/product-card ブロック描画', () => {
	let availableProductId;
	let outOfStockProductId;

	test.beforeAll( async ( { requestUtils } ) => {
		// 在庫あり商品（dmm-books listing 付き）
		const available = await requestUtils.rest( {
			path: '/affilicard/v1/products',
			method: 'POST',
			data: {
				title: 'E2E Block Render — Available',
				status: 'publish',
				product_type: 'ebook',
				stock_status: 'available',
				listings: [
					{
						platform: 'dmm-books',
						enabled: true,
						update_mode: 'manual',
						affiliate_url: 'https://example.com/cta-available',
						regular_url: '',
						price: '',
					},
				],
			},
		} );
		availableProductId = available.id;
		expect( availableProductId ).toBeGreaterThan( 0 );

		// 在庫切れ商品
		const outOfStock = await requestUtils.rest( {
			path: '/affilicard/v1/products',
			method: 'POST',
			data: {
				title: 'E2E Block Render — Out of Stock',
				status: 'publish',
				product_type: 'ebook',
				stock_status: 'out_of_stock',
				listings: [
					{
						platform: 'dmm-books',
						enabled: true,
						update_mode: 'manual',
						affiliate_url: 'https://example.com/cta-oos',
						regular_url: '',
						price: '',
					},
				],
			},
		} );
		outOfStockProductId = outOfStock.id;
		expect( outOfStockProductId ).toBeGreaterThan( 0 );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		for ( const id of [ availableProductId, outOfStockProductId ] ) {
			if ( id ) {
				await requestUtils
					.rest( {
						path: `/affilicard/v1/products/${ id }`,
						method: 'DELETE',
					} )
					.catch( () => {} );
			}
		}
	} );

	test( '在庫あり商品: CTA リンク・タイトル・カラー CSS 変数が描画される', async ( {
		page,
		admin,
		editor,
	} ) => {
		// 1. 通常投稿を新規作成（ブロックエディタ）
		await admin.createNewPost( { title: 'E2E ブロック描画テスト（在庫あり）' } );

		// 2. affilicard/product-card ブロックを挿入（productId と色属性を直接指定）
		await editor.insertBlock( {
			name: 'affilicard/product-card',
			attributes: {
				productId: availableProductId,
				ctaBgColor: CTA_BG_COLOR,
			},
		} );

		// ブロックがエディタに表示されるのを確認（ブロックプレースホルダ）
		await expect(
			page.locator( '.affilicard-block-placeholder' )
		).toBeVisible( { timeout: 10000 } );

		// 3. 投稿をパブリッシュ
		await editor.publishPost();

		// パブリッシュ後にフロントの URL を取得
		// editor.publishPost() が完了した後、view post リンクを探す
		const viewPostLink = page
			.getByRole( 'region', { name: 'Editor publish' } )
			.getByRole( 'link', { name: /view post/i } )
			.or(
				page
					.getByRole( 'link', { name: /投稿を表示/i } )
			);

		// フロントエンドにアクセス
		// リンクが見つからない場合は URL パラメータから投稿 URL を構築
		let frontUrl;
		try {
			frontUrl = await viewPostLink.getAttribute( 'href', {
				timeout: 5000,
			} );
		} catch {
			// フォールバック: 投稿リストから最新の投稿 URL を取得
			const postId = new URL( page.url() ).searchParams.get( 'post' );
			if ( postId ) {
				frontUrl = `/?p=${ postId }`;
			}
		}
		expect( frontUrl ).toBeTruthy();

		await page.goto( frontUrl );
		await page.waitForLoadState( 'domcontentloaded' );

		// 4. カードが描画されていることを確認
		const card = page.locator( '.affilicard-card' ).first();
		await expect( card ).toBeVisible( { timeout: 10000 } );

		// 4a. タイトル
		await expect(
			card.locator( '.affilicard-card__title' )
		).toContainText( 'E2E Block Render — Available' );

		// 4b. CTA リンク（アフィリエイト URL + rel 属性）
		const cta = card.locator( 'a.affilicard-card__cta' ).first();
		await expect( cta ).toBeVisible();
		await expect( cta ).toHaveAttribute(
			'href',
			'https://example.com/cta-available'
		);
		await expect( cta ).toHaveAttribute( 'rel', /nofollow/ );
		await expect( cta ).toHaveAttribute( 'rel', /sponsored/ );
		await expect( cta ).toHaveAttribute( 'rel', /noopener/ );

		// 4c. カラー CSS 変数が inline style に注入されている
		// sanitize_hex_color は小文字に正規化するため小文字で比較
		const rootStyle = await card.getAttribute( 'style' );
		expect( rootStyle ).toBeTruthy();
		expect( rootStyle?.toLowerCase() ).toContain(
			`--affilicard-cta-bg:${ CTA_BG_COLOR.toLowerCase() }`
		);
	} );

	test( '在庫切れ商品: CTA が表示されず out_of_stock バッジが表示される', async ( {
		page,
		admin,
		editor,
	} ) => {
		// 1. 通常投稿を新規作成（ブロックエディタ）
		await admin.createNewPost( { title: 'E2E ブロック描画テスト（在庫切れ）' } );

		// 2. 在庫切れ商品のブロックを挿入
		await editor.insertBlock( {
			name: 'affilicard/product-card',
			attributes: {
				productId: outOfStockProductId,
			},
		} );

		await expect(
			page.locator( '.affilicard-block-placeholder' )
		).toBeVisible( { timeout: 10000 } );

		// 3. パブリッシュ
		await editor.publishPost();

		// フロント URL を取得
		const viewPostLink = page
			.getByRole( 'region', { name: 'Editor publish' } )
			.getByRole( 'link', { name: /view post/i } )
			.or(
				page
					.getByRole( 'link', { name: /投稿を表示/i } )
			);

		let frontUrl;
		try {
			frontUrl = await viewPostLink.getAttribute( 'href', {
				timeout: 5000,
			} );
		} catch {
			const postId = new URL( page.url() ).searchParams.get( 'post' );
			if ( postId ) {
				frontUrl = `/?p=${ postId }`;
			}
		}
		expect( frontUrl ).toBeTruthy();

		await page.goto( frontUrl );
		await page.waitForLoadState( 'domcontentloaded' );

		// 4. 在庫切れカードの確認
		const card = page.locator( '.affilicard-card' ).first();
		await expect( card ).toBeVisible( { timeout: 10000 } );

		// 4a. タイトル
		await expect(
			card.locator( '.affilicard-card__title' )
		).toContainText( 'E2E Block Render — Out of Stock' );

		// 4b. CTA は表示されない（在庫切れ時はリスティングを描画しない）
		await expect( card.locator( 'a.affilicard-card__cta' ) ).toHaveCount( 0 );

		// 4c. out_of_stock バッジが表示される
		await expect(
			card.locator( '.affilicard-card__badge--out_of_stock' )
		).toBeVisible();
	} );

	test( 'ComboboxControl で商品を検索して選択できる（ブロックエディタ UI）', async ( {
		page,
		admin,
		editor,
	} ) => {
		// このテストは ComboboxControl の UI を実際に操作するため、
		// REST API から商品が検索結果に返ってくることが前提
		await admin.createNewPost( { title: 'E2E ComboboxControl テスト' } );

		// 空の affilicard/product-card ブロックを挿入（productId 未指定）
		await editor.insertBlock( {
			name: 'affilicard/product-card',
			attributes: {},
		} );

		// ComboboxControl の入力フィールド（label="商品を検索"）
		const searchInput = page.getByLabel( '商品を検索' );
		await expect( searchInput ).toBeVisible( { timeout: 10000 } );

		// 商品タイトルの一部を入力して検索
		await searchInput.fill( 'E2E Block Render — Available' );

		// 検索結果の候補が表示されるまで待機
		// ComboboxControl は listbox role で候補を表示する
		const listbox = page.getByRole( 'listbox' );
		await expect( listbox ).toBeVisible( { timeout: 10000 } );

		// 候補から該当商品を選択
		const option = listbox
			.getByRole( 'option' )
			.filter( { hasText: 'E2E Block Render — Available' } )
			.first();
		await expect( option ).toBeVisible( { timeout: 5000 } );
		await option.click();

		// 選択後はブロックプレースホルダに商品名が表示される
		await expect(
			page.locator( '.affilicard-block-placeholder' )
		).toContainText( 'E2E Block Render — Available', { timeout: 10000 } );
	} );
} );
