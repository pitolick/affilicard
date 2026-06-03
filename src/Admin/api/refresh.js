import apiFetch from '@wordpress/api-fetch';

export function triggerRefresh(platform, force = false) {
	const data = {};
	if (platform) {
		data.platform = platform;
	}
	if (force) {
		data.force = true;
	}
	return apiFetch({
		path: '/affilicard/v1/refresh',
		method: 'POST',
		data,
	});
}
