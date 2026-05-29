/**
 * Tests for src/Admin/api/settings.js
 */

jest.mock( '@wordpress/api-fetch' );

import apiFetch from '@wordpress/api-fetch';
import {
	fetchSettings,
	updateSettings,
} from '../../../src/Admin/api/settings';

beforeEach( () => {
	apiFetch.mockClear();
	apiFetch.mockResolvedValue( {} );
} );

describe( 'api/settings', () => {
	test( 'fetchSettings calls GET /affilicard/v1/settings', async () => {
		await fetchSettings();
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/affilicard/v1/settings',
		} );
	} );

	test( 'updateSettings calls PUT /affilicard/v1/settings with data', async () => {
		const data = {
			cache_ttl_seconds: 3600,
			default_product_type: 'ebook',
			cron_enabled: true,
		};
		await updateSettings( data );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/affilicard/v1/settings',
			method: 'PUT',
			data,
		} );
	} );
} );
