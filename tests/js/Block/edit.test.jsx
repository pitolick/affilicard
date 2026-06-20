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
} );
