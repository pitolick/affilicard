import apiFetch from '@wordpress/api-fetch';

const BASE = '/affilicard/v1/settings';

export function fetchSettings() {
	return apiFetch( { path: BASE } );
}

export function updateSettings( data ) {
	return apiFetch( { path: BASE, method: 'PUT', data } );
}
