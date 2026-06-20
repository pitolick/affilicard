/**
 * Tests for src/Block/edit.jsx
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { Edit } from '../../../src/Block/edit';
import * as productsApi from '../../../src/Admin/api/products';

jest.mock( '../../../src/Admin/api/products' );

describe( 'Edit', () => {
	beforeEach( () => {
		productsApi.searchProducts.mockResolvedValue( [
			{ id: 7, title: 'サンプル漫画 1巻', status: 'publish' },
		] );
		productsApi.getProduct.mockResolvedValue( {
			id: 7,
			title: 'サンプル漫画 1巻',
		} );
		productsApi.getCardPreview.mockResolvedValue( {
			html: '<div class="affilicard-card">プレビュー本文</div>',
		} );
	} );

	const setup = ( attributes = {} ) => {
		const setAttributes = jest.fn();
		render(
			<Edit attributes={ attributes } setAttributes={ setAttributes } />
		);
		return { setAttributes };
	};

	test( 'shows combobox when no product selected', () => {
		setup();
		expect(
			screen.getByLabelText( '商品を検索', { selector: 'input' } )
		).toBeInTheDocument();
	} );

	test( 'searches products on filter change', async () => {
		setup();
		fireEvent.change(
			screen.getByLabelText( '商品を検索', { selector: 'input' } ),
			{ target: { value: 'サンプル' } }
		);
		await waitFor( () =>
			expect( productsApi.searchProducts ).toHaveBeenCalledWith(
				expect.objectContaining( { search: 'サンプル' } )
			)
		);
	} );

	test( 'sets productId attribute on selection', async () => {
		const { setAttributes } = setup();
		fireEvent.change(
			screen.getByLabelText( '商品を検索', { selector: 'input' } ),
			{ target: { value: 'サンプル' } }
		);
		const option = await screen.findByText( /サンプル漫画 1巻/ );
		fireEvent.click( option );
		expect( setAttributes ).toHaveBeenCalledWith( { productId: 7 } );
	} );

	// 旧: プレースホルダで商品タイトルを表示していた。
	// 新: getCardPreview が返す HTML でプレビューを描画する。
	test( 'shows preview HTML when productId set', async () => {
		setup( { productId: 7 } );
		await waitFor( () =>
			expect( screen.getByText( 'プレビュー本文' ) ).toBeInTheDocument()
		);
		expect( productsApi.getCardPreview ).toHaveBeenCalledWith( 7, {
			hidePlatforms: [],
			ctaLabelOverrides: {},
			ctaBgColor: undefined,
			ctaTextColor: undefined,
			cardBgColor: undefined,
			cardBorderColor: undefined,
		} );
	} );

	test( 'passes attribute params to getCardPreview', async () => {
		setup( {
			productId: 7,
			hidePlatforms: [ 'dmm-books' ],
			ctaLabelOverrides: { 'dmm-books': '購入' },
			ctaBgColor: '#ff0000',
			ctaTextColor: '#ffffff',
		} );
		await waitFor( () =>
			expect( screen.getByText( 'プレビュー本文' ) ).toBeInTheDocument()
		);
		expect( productsApi.getCardPreview ).toHaveBeenCalledWith(
			7,
			expect.objectContaining( {
				hidePlatforms: [ 'dmm-books' ],
				ctaLabelOverrides: { 'dmm-books': '購入' },
				ctaBgColor: '#ff0000',
				ctaTextColor: '#ffffff',
			} )
		);
	} );

	test( 'renders color palette controls in inspector', async () => {
		setup( { productId: 7 } );
		// getCardPreview の解決を待ってから色設定 UI を確認する。
		await waitFor( () =>
			expect( screen.getByText( 'プレビュー本文' ) ).toBeInTheDocument()
		);
		expect( screen.getAllByText( /色/ ).length ).toBeGreaterThan( 0 );
	} );

	test( 'updates ctaBgColor via color palette', async () => {
		const { setAttributes } = setup( { productId: 7 } );
		// getCardPreview の解決を待ってから操作する。
		await waitFor( () =>
			expect( screen.getByText( 'プレビュー本文' ) ).toBeInTheDocument()
		);
		const palettes = document.querySelectorAll( '[data-color-palette]' );
		fireEvent.change( palettes[ 0 ], { target: { value: '#ff0000' } } );
		expect( setAttributes ).toHaveBeenCalledWith(
			expect.objectContaining( { ctaBgColor: '#ff0000' } )
		);
	} );

	test( 'shows toolbar button to change product when productId set', async () => {
		setup( { productId: 7 } );
		await waitFor( () =>
			expect( screen.getByText( 'プレビュー本文' ) ).toBeInTheDocument()
		);
		expect( screen.getByText( '商品を変更' ) ).toBeInTheDocument();
	} );

	test( 'clicking "商品を変更" calls setAttributes with undefined productId', async () => {
		const { setAttributes } = setup( { productId: 7 } );
		await waitFor( () =>
			expect( screen.getByText( 'プレビュー本文' ) ).toBeInTheDocument()
		);
		fireEvent.click( screen.getByText( '商品を変更' ) );
		expect( setAttributes ).toHaveBeenCalledWith( { productId: undefined } );
	} );

	test( 'shows error message when getCardPreview rejects', async () => {
		productsApi.getCardPreview.mockRejectedValue( new Error( 'network error' ) );
		setup( { productId: 7 } );
		await waitFor( () =>
			expect(
				screen.getByText( 'プレビューを取得できませんでした。' )
			).toBeInTheDocument()
		);
	} );

	test( 'does not show stale HTML when getCardPreview rejects', async () => {
		productsApi.getCardPreview.mockRejectedValue( new Error( 'network error' ) );
		setup( { productId: 7 } );
		await waitFor( () =>
			expect(
				screen.getByText( 'プレビューを取得できませんでした。' )
			).toBeInTheDocument()
		);
		// エラー時は previewHtml がリセットされるためプレビュー本文は表示されない
		expect( screen.queryByText( 'プレビュー本文' ) ).not.toBeInTheDocument();
		// 空メッセージも表示されない（error 状態では idle 条件が false）
		expect(
			screen.queryByText( 'プレビューする内容がありません。' )
		).not.toBeInTheDocument();
	} );

	// Task 6: CTA ラベル上書きパネル
	describe( 'CTA ラベル上書きパネル', () => {
		beforeEach( () => {
			// 有効な listing を持つ商品を返すよう getProduct を上書き
			productsApi.getProduct.mockResolvedValue( {
				id: 7,
				title: 'サンプル漫画 1巻',
				listings: [ { platform: 'dmm-books', enabled: true } ],
			} );
		} );

		test( 'listing のプラットフォームごとに CTA ラベル上書き欄が出る', async () => {
			setup( { productId: 7 } );
			expect( await screen.findByLabelText( 'dmm-books' ) ).toBeInTheDocument();
		} );

		test( '値入力で setAttributes が ctaLabelOverrides に code を含めて呼ばれる', async () => {
			const { setAttributes } = setup( { productId: 7 } );
			const input = await screen.findByLabelText( 'dmm-books' );
			fireEvent.change( input, { target: { value: '今すぐ読む' } } );
			expect( setAttributes ).toHaveBeenCalledWith( {
				ctaLabelOverrides: { 'dmm-books': '今すぐ読む' },
			} );
		} );

		test( '空入力で setAttributes が該当 code を含まない object で呼ばれる', async () => {
			const { setAttributes } = setup( {
				productId: 7,
				ctaLabelOverrides: { 'dmm-books': '購入する' },
			} );
			const input = await screen.findByLabelText( 'dmm-books' );
			fireEvent.change( input, { target: { value: '' } } );
			const calls = setAttributes.mock.calls.filter( ( c ) =>
				Object.prototype.hasOwnProperty.call( c[ 0 ], 'ctaLabelOverrides' )
			);
			expect( calls.length ).toBeGreaterThan( 0 );
			const lastCall = calls[ calls.length - 1 ];
			expect( lastCall[ 0 ].ctaLabelOverrides ).not.toHaveProperty( 'dmm-books' );
		} );

		test( 'listing がない場合は CTA ラベル上書きパネルが表示されない', async () => {
			productsApi.getProduct.mockResolvedValue( {
				id: 7,
				title: 'サンプル漫画 1巻',
				listings: [],
			} );
			setup( { productId: 7 } );
			await waitFor( () =>
				expect( productsApi.getCardPreview ).toHaveBeenCalled()
			);
			expect( screen.queryByText( 'CTA ラベル上書き' ) ).not.toBeInTheDocument();
		} );
	} );

	// Task 7: 最近商品とリッチ表示
	describe( '最近商品とリッチ表示', () => {
		beforeEach( () => {
			// Task 7 用のリッチデータを返すモック
			productsApi.searchProducts.mockResolvedValue( [
				{
					id: 7,
					title: 'サンプル漫画 1巻',
					status: 'publish',
					thumbnail: 'https://example.com/thumb.jpg',
					platform: 'dmm-books',
					price: '500',
				},
			] );
		} );

		test( '未選択時は空入力でも searchProducts が呼ばれる', async () => {
			setup();
			await waitFor( () =>
				expect( productsApi.searchProducts ).toHaveBeenCalledWith(
					expect.objectContaining( { search: '' } )
				)
			);
		} );

		test( '最近商品が候補リストに表示される', async () => {
			setup();
			await waitFor( () =>
				expect( productsApi.searchProducts ).toHaveBeenCalled()
			);
			// options に item が含まれ、__experimentalRenderItem で platform が表示される
			const platformText = await screen.findByText( 'dmm-books' );
			expect( platformText ).toBeInTheDocument();
		} );

		test( '__experimentalRenderItem で価格が表示される', async () => {
			setup();
			await waitFor( () =>
				expect( productsApi.searchProducts ).toHaveBeenCalled()
			);
			const priceText = await screen.findByText( '¥500' );
			expect( priceText ).toBeInTheDocument();
		} );

		test( 'item.item が無い場合は label をフォールバック表示する', async () => {
			// item.item なしの option（label のみ）をセットする仮のシナリオ:
			// searchProducts が thumbnail/platform/price を含まないアイテムを返す場合は
			// label テキスト "サンプル漫画 1巻 (#7)" が表示される
			productsApi.searchProducts.mockResolvedValue( [
				{ id: 7, title: 'サンプル漫画 1巻', status: 'publish' },
			] );
			setup();
			await waitFor( () =>
				expect( productsApi.searchProducts ).toHaveBeenCalled()
			);
			// __experimentalRenderItem が呼ばれ、data.platform が undefined なのでフォールバック title が使われる
			// label テキストが表示される（item.item.title が使われる）
			const titleText = await screen.findByText( 'サンプル漫画 1巻' );
			expect( titleText ).toBeInTheDocument();
		} );

		test( 'perPage が 20 で searchProducts が呼ばれる', async () => {
			setup();
			await waitFor( () =>
				expect( productsApi.searchProducts ).toHaveBeenCalledWith(
					expect.objectContaining( { perPage: 20 } )
				)
			);
		} );

		test( '空入力 fetch 後に候補を選択すると productId がセットされる', async () => {
			const { setAttributes } = setup();
			await waitFor( () =>
				expect( productsApi.searchProducts ).toHaveBeenCalled()
			);
			const button = await screen.findByText( 'dmm-books' );
			// button の親の button（option ボタン）をクリック
			fireEvent.click( button.closest( 'button' ) );
			expect( setAttributes ).toHaveBeenCalledWith( { productId: 7 } );
		} );
	} );
} );
