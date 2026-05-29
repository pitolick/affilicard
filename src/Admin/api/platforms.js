import apiFetch from '@wordpress/api-fetch';

const BASE = '/affilicard/v1/platforms';

export function fetchPlatforms() {
	return apiFetch( { path: BASE } );
}

export function updatePlatforms( platforms ) {
	return apiFetch( { path: BASE, method: 'PUT', data: { platforms } } );
}
