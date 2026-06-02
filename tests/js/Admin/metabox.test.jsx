/**
 * Tests for src/Admin/metabox.js
 *
 * Targets MetaboxApp; the DOMContentLoaded handler is out of scope.
 */

jest.mock( '../../../src/Admin/api/products' );
jest.mock( '../../../src/Admin/api/platforms' );

import { render, screen, waitFor } from '@testing-library/react';
import { MetaboxApp } from '../../../src/Admin/metabox';
import { getProduct } from '../../../src/Admin/api/products';
import { fetchPlatforms } from '../../../src/Admin/api/platforms';

beforeEach( () => {
	getProduct.mockReset();
	fetchPlatforms.mockReset();
	fetchPlatforms.mockResolvedValue( [] );
} );

describe( 'MetaboxApp', () => {
	test( 'exports MetaboxApp', () => {
		expect( typeof MetaboxApp ).toBe( 'function' );
	} );

	test( 'renders "保存後に編集できます" when postId is 0', () => {
		render( <MetaboxApp postId={ 0 } /> );
		expect(
			screen.getByText( '保存後に編集できます' )
		).toBeInTheDocument();
		expect( getProduct ).not.toHaveBeenCalled();
	} );

	test( 'with valid postId, calls getProduct on mount and renders editor on load', async () => {
		getProduct.mockResolvedValue( {
			product_type: 'ebook',
			stock_status: 'available',
			extras: [],
			listings: [],
		} );
		render( <MetaboxApp postId={ 42 } /> );
		await waitFor( () =>
			expect( getProduct ).toHaveBeenCalledWith( 42 )
		);
		// Editor surface should now be present.
		expect( screen.getByText( '商品タイプ' ) ).toBeInTheDocument();
		expect( screen.getByText( '在庫状況' ) ).toBeInTheDocument();
		expect( screen.getByText( '追加情報' ) ).toBeInTheDocument();
		// ListingsEditor mounts after platforms load.
		await waitFor( () =>
			expect(
				screen.getByText( 'プラットフォーム listing' )
			).toBeInTheDocument()
		);
	} );

	test( 'shows loading state while getProduct is pending', () => {
		getProduct.mockReturnValue( new Promise( () => {} ) );
		render( <MetaboxApp postId={ 42 } /> );
		expect( screen.getByText( '読み込み中…' ) ).toBeInTheDocument();
	} );

	test( 'hidden field affilicard_data is rendered with current state as JSON', async () => {
		const product = {
			product_type: 'ebook',
			stock_status: 'available',
			extras: [ { key: 'author', label: '著者', value: '山田太郎' } ],
			listings: [
				{
					platform: 'dmm-books',
					enabled: true,
					update_mode: 'manual',
					auto_update: false,
					external_id: '111',
					regular_url: '',
					affiliate_url: 'https://a',
					price: '500',
					list_price: '',
					badge: '',
					image_url: '',
					button_label_override: '',
				},
			],
		};
		getProduct.mockResolvedValue( product );
		render( <MetaboxApp postId={ 42 } /> );
		await waitFor( () =>
			expect( getProduct ).toHaveBeenCalledWith( 42 )
		);

		// The hidden textarea should exist and carry the JSON state.
		const field = document.querySelector(
			'textarea[name="affilicard_data"]'
		);
		expect( field ).not.toBeNull();
		const decoded = JSON.parse( field.value );
		expect( decoded.product_type ).toBe( 'ebook' );
		expect( decoded.stock_status ).toBe( 'available' );
		expect( decoded.extras ).toEqual( product.extras );
		expect( decoded.listings ).toEqual( product.listings );
	} );

	test( 'old REST save button is not rendered', async () => {
		getProduct.mockResolvedValue( {
			product_type: 'generic',
			stock_status: 'available',
			extras: [],
			listings: [],
		} );
		render( <MetaboxApp postId={ 42 } /> );
		await waitFor( () =>
			expect( screen.getByText( '商品タイプ' ) ).toBeInTheDocument()
		);
		// The button from the old REST-save path must be gone.
		expect(
			screen.queryByText( 'Affilicard データを保存' )
		).toBeNull();
		expect( screen.queryByText( '保存中…' ) ).toBeNull();
	} );

	test( 'shows hint text about saving with Publish/Update', async () => {
		getProduct.mockResolvedValue( {
			product_type: 'generic',
			stock_status: 'available',
			extras: [],
			listings: [],
		} );
		render( <MetaboxApp postId={ 42 } /> );
		await waitFor( () =>
			expect(
				screen.getByText(
					'『公開』『更新』を押すと商品設定も保存されます'
				)
			).toBeInTheDocument()
		);
	} );

	test( 'falls back to default product shape when getProduct rejects', async () => {
		getProduct.mockRejectedValue( new Error( 'fail' ) );
		render( <MetaboxApp postId={ 42 } /> );
		await waitFor( () =>
			expect( screen.getByText( '商品タイプ' ) ).toBeInTheDocument()
		);
		// 在庫状況 default = 販売中 (available) のセレクト box が描画される
		expect( screen.getByText( '在庫状況' ) ).toBeInTheDocument();
	} );
} );
