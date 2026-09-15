import apiFetch from '@wordpress/api-fetch';

const BASE = '/affilicard/v1/settings';

export function fetchSettings() {
	return apiFetch({ path: BASE });
}

/**
 * 商品編集画面が必要とする設定だけを読む（edit_posts で読める）。
 *
 * 一般設定（BASE）は manage_options 必須なので、Editor から読むと 403 になり
 * 呼び出し側が既定 false へ黙って倒れる。必要な 1 項目だけを返す別ルートを使う。
 */
export function fetchEditorSettings() {
	return apiFetch({ path: '/affilicard/v1/editor-settings' });
}

export function updateSettings(data) {
	return apiFetch({ path: BASE, method: 'PUT', data });
}
