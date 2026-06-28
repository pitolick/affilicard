import apiFetch from '@wordpress/api-fetch';

const BASE = '/affilicard/v1/products';

export function searchProducts({ search = '', perPage = 20, page = 1 } = {}) {
	const query = new URLSearchParams();
	if (search) {
		query.set('search', search);
	}
	query.set('per_page', String(perPage));
	query.set('page', String(page));
	return apiFetch({ path: `${BASE}?${query.toString()}` });
}

export function getProduct(id) {
	return apiFetch({ path: `${BASE}/${id}` });
}

export function saveProduct(data) {
	return apiFetch({ path: BASE, method: 'POST', data });
}

export function updateProduct(id, data) {
	return apiFetch({ path: `${BASE}/${id}`, method: 'PATCH', data });
}

export function deleteProduct(id) {
	return apiFetch({ path: `${BASE}/${id}`, method: 'DELETE' });
}

export function getCardPreview(id, params = {}) {
	const query = new URLSearchParams();
	(params.hidePlatforms || []).forEach((code) =>
		query.append('hidePlatforms[]', code)
	);
	(params.onlyPlatforms || []).forEach((code) =>
		query.append('onlyPlatforms[]', code)
	);
	Object.entries(params.ctaLabelOverrides || {}).forEach(([code, label]) =>
		query.set(`ctaLabelOverrides[${code}]`, label)
	);
	['ctaBgColor', 'ctaTextColor', 'cardBgColor', 'cardBorderColor'].forEach(
		(key) => {
			if (params[key]) {
				query.set(key, params[key]);
			}
		}
	);
	const qs = query.toString();
	return apiFetch({
		path: `${BASE}/${id}/card-preview${qs ? `?${qs}` : ''}`,
	});
}
