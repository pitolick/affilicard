/**
 * Tests for src/Admin/api/platforms.js
 */

jest.mock( '@wordpress/api-fetch' );

import apiFetch from '@wordpress/api-fetch';
import {
	fetchPlatforms,
	updatePlatforms,
} from '../../../src/Admin/api/platforms';

beforeEach( () => {
	apiFetch.mockClear();
	apiFetch.mockResolvedValue( [] );
} );

describe( 'api/platforms', () => {
	test( 'fetchPlatforms calls GET /affilicard/v1/platforms', async () => {
		await fetchPlatforms();
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/affilicard/v1/platforms',
		} );
	} );

	test( 'updatePlatforms calls PUT /affilicard/v1/platforms with platforms array wrapped', async () => {
		const platforms = [
			{ code: 'dmm', name: 'DMM', enabled: true },
			{ code: 'amazon', name: 'Amazon', enabled: false },
		];
		await updatePlatforms( platforms );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/affilicard/v1/platforms',
			method: 'PUT',
			data: { platforms },
		} );
	} );
} );
