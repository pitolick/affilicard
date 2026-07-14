/**
 * Tests for src/Admin/api/credentials.js
 */

jest.mock( '@wordpress/api-fetch' );

import apiFetch from '@wordpress/api-fetch';
import {
	fetchCredentials,
	updateCredentials,
	deleteCredentials,
	testConnection,
} from '../../../src/Admin/api/credentials';

beforeEach( () => {
	apiFetch.mockClear();
	apiFetch.mockResolvedValue( {} );
} );

describe( 'api/credentials', () => {
	test( 'fetchCredentials calls GET with encoded account code', async () => {
		await fetchCredentials( 'rakuten' );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/affilicard/v1/accounts/rakuten/credentials',
		} );
	} );

	test( 'updateCredentials calls PUT with values payload', async () => {
		const values = { access_key: 'abc' };
		await updateCredentials( 'rakuten', values );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/affilicard/v1/accounts/rakuten/credentials',
			method: 'PUT',
			data: values,
		} );
	} );

	test( 'deleteCredentials calls DELETE on the account credentials route', async () => {
		await deleteCredentials( 'rakuten' );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/affilicard/v1/accounts/rakuten/credentials',
			method: 'DELETE',
		} );
	} );

	test( 'testConnection calls POST /providers/{code}/test-connection with credentials', async () => {
		const values = { access_key: 'abc' };
		apiFetch.mockResolvedValueOnce( { ok: true, message: 'OK' } );
		const result = await testConnection( 'rakuten-kobo', values );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/affilicard/v1/providers/rakuten-kobo/test-connection',
			method: 'POST',
			data: values,
		} );
		expect( result ).toEqual( { ok: true, message: 'OK' } );
	} );

	test( 'fetchCredentials encodes special characters in account code', async () => {
		await fetchCredentials( 'my/code' );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/affilicard/v1/accounts/my%2Fcode/credentials',
		} );
	} );

	test( 'deleteCredentials encodes special characters in account code', async () => {
		await deleteCredentials( 'my/code' );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/affilicard/v1/accounts/my%2Fcode/credentials',
			method: 'DELETE',
		} );
	} );
} );
