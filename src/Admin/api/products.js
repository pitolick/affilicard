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
