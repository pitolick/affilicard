import apiFetch from '@wordpress/api-fetch';

const base = (code) =>
	`/affilicard/v1/platforms/${encodeURIComponent(code)}/credentials`;

export function fetchCredentials(code) {
	return apiFetch({ path: base(code) });
}

export function updateCredentials(code, values) {
	return apiFetch({ path: base(code), method: 'PUT', data: values });
}

export function testConnection(code, values) {
	return apiFetch({
		path: `/affilicard/v1/platforms/${encodeURIComponent(code)}/test-connection`,
		method: 'POST',
		data: values,
	});
}
