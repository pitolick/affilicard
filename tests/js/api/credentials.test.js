/**
 * Tests for src/Admin/api/credentials.js
 */

jest.mock( '@wordpress/api-fetch' );

import apiFetch from '@wordpress/api-fetch';
import {
	fetchCredentials,
	updateCredentials,
	testConnection,
} from '../../../src/Admin/api/credentials';

beforeEach( () => {
	apiFetch.mockClear();
	apiFetch.mockResolvedValue( {} );
} );

describe( 'api/credentials', () => {
	test( 'fetchCredentials calls GET with encoded platform code', async () => {
		await fetchCredentials( 'dmm-ebook' );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/affilicard/v1/platforms/dmm-ebook/credentials',
		} );
	} );

	test( 'updateCredentials calls PUT with values payload', async () => {
		const values = { api_id: 'abc', affiliate_id: 'def' };
		await updateCredentials( 'dmm-ebook', values );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/affilicard/v1/platforms/dmm-ebook/credentials',
			method: 'PUT',
			data: values,
		} );
	} );

	test( 'testConnection calls POST /test-connection with credentials', async () => {
		const values = { api_id: 'abc', affiliate_id: 'def' };
		apiFetch.mockResolvedValueOnce( { ok: true, message: 'OK' } );
		const result = await testConnection( 'dmm-ebook', values );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/affilicard/v1/platforms/dmm-ebook/test-connection',
			method: 'POST',
			data: values,
		} );
		expect( result ).toEqual( { ok: true, message: 'OK' } );
	} );

	test( 'fetchCredentials encodes special characters in platform code', async () => {
		await fetchCredentials( 'my/code' );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/affilicard/v1/platforms/my%2Fcode/credentials',
		} );
	} );
} );
