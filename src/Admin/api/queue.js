import apiFetch from '@wordpress/api-fetch';

const BASE = '/affilicard/v1/refresh-queue';

export function fetchQueueStats() {
	return apiFetch({ path: BASE });
}

export function setPaused(paused) {
	return apiFetch({
		path: `${BASE}/pause`,
		method: 'POST',
		data: { paused },
	});
}

export function clearQueue() {
	return apiFetch({ path: BASE, method: 'DELETE' });
}

export function deleteFailed() {
	return apiFetch({ path: `${BASE}/failed`, method: 'DELETE' });
}

export function retryFailed() {
	return apiFetch({ path: `${BASE}/retry-failed`, method: 'POST' });
}

export function cancelPending() {
	return apiFetch({ path: `${BASE}/cancel-pending`, method: 'POST' });
}
