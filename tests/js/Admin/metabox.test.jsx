/**
 * Tests for src/Admin/metabox.js
 *
 * Targets MetaboxApp; the DOMContentLoaded handler is out of scope.
 */

jest.mock( '../../../src/Admin/api/products' );
jest.mock( '../../../src/Admin/api/platforms' );

import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MetaboxApp } from '../../../src/Admin/metabox';
import {
	getProduct,
	updateProduct,
} from '../../../src/Admin/api/products';
import { fetchPlatforms } from '../../../src/Admin/api/platforms';

beforeEach( () => {
	getProduct.mockReset();
	updateProduct.mockReset();
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

	test( 'clicking save calls updateProduct with the right data shape', async () => {
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
		updateProduct.mockResolvedValue( product );
		render( <MetaboxApp postId={ 42 } /> );
		await waitFor( () =>
			expect( getProduct ).toHaveBeenCalledWith( 42 )
		);
		const saveBtn = screen.getByRole( 'button', {
			name: 'Affilicard データを保存',
		} );
		fireEvent.click( saveBtn );
		await waitFor( () =>
			expect( updateProduct ).toHaveBeenCalledWith( 42, {
				product_type: 'ebook',
				stock_status: 'available',
				extras: product.extras,
				listings: product.listings,
			} )
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

	test( 'shows success notice after successful save', async () => {
		const product = {
			product_type: 'generic',
			stock_status: 'available',
			extras: [],
			listings: [],
		};
		getProduct.mockResolvedValue( product );
		updateProduct.mockResolvedValue( product );
		render( <MetaboxApp postId={ 42 } /> );
		await waitFor( () =>
			expect( getProduct ).toHaveBeenCalledWith( 42 )
		);
		const saveBtn = screen.getByRole( 'button', {
			name: 'Affilicard データを保存',
		} );
		fireEvent.click( saveBtn );
		await waitFor( () =>
			expect( screen.getByText( '保存しました' ) ).toBeInTheDocument()
		);
	} );

	test( 'shows error notice when save fails', async () => {
		const product = {
			product_type: 'generic',
			stock_status: 'available',
			extras: [],
			listings: [],
		};
		getProduct.mockResolvedValue( product );
		updateProduct.mockRejectedValue( new Error( 'fail' ) );
		render( <MetaboxApp postId={ 42 } /> );
		await waitFor( () =>
			expect( getProduct ).toHaveBeenCalledWith( 42 )
		);
		const saveBtn = screen.getByRole( 'button', {
			name: 'Affilicard データを保存',
		} );
		fireEvent.click( saveBtn );
		await waitFor( () =>
			expect(
				screen.getByText( '保存に失敗しました' )
			).toBeInTheDocument()
		);
	} );
} );
