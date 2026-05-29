/**
 * Tests for src/Admin/api/products.js
 */

jest.mock( '@wordpress/api-fetch' );

import apiFetch from '@wordpress/api-fetch';
import {
	searchProducts,
	getProduct,
	saveProduct,
	updateProduct,
	deleteProduct,
} from '../../../src/Admin/api/products';

beforeEach( () => {
	apiFetch.mockClear();
	apiFetch.mockResolvedValue( [] );
} );

describe( 'api/products', () => {
	test( 'searchProducts builds query string with defaults', async () => {
		await searchProducts();
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/affilicard/v1/products?per_page=20&page=1',
		} );
	} );

	test( 'searchProducts includes search keyword when provided', async () => {
		await searchProducts( { search: 'manga', perPage: 5, page: 2 } );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/affilicard/v1/products?search=manga&per_page=5&page=2',
		} );
	} );

	test( 'getProduct uses /id path', async () => {
		await getProduct( 42 );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/affilicard/v1/products/42',
		} );
	} );

	test( 'saveProduct posts to base path', async () => {
		const data = { title: 'Test' };
		await saveProduct( data );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/affilicard/v1/products',
			method: 'POST',
			data,
		} );
	} );

	test( 'updateProduct patches at /id path', async () => {
		const data = { title: 'Updated' };
		await updateProduct( 7, data );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/affilicard/v1/products/7',
			method: 'PATCH',
			data,
		} );
	} );

	test( 'deleteProduct deletes at /id path', async () => {
		await deleteProduct( 9 );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/affilicard/v1/products/9',
			method: 'DELETE',
		} );
	} );
} );
