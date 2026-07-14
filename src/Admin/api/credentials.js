import apiFetch from '@wordpress/api-fetch';

const accountBase = (code) =>
	`/affilicard/v1/accounts/${encodeURIComponent(code)}/credentials`;

export function fetchCredentials(accountCode) {
	return apiFetch({ path: accountBase(accountCode) });
}

export function updateCredentials(accountCode, values) {
	return apiFetch({
		path: accountBase(accountCode),
		method: 'PUT',
		data: values,
	});
}

export function deleteCredentials(accountCode) {
	return apiFetch({ path: accountBase(accountCode), method: 'DELETE' });
}

export function testConnection(providerCode, values) {
	return apiFetch({
		path: `/affilicard/v1/providers/${encodeURIComponent(
			providerCode
		)}/test-connection`,
		method: 'POST',
		data: values,
	});
}
