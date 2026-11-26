import { __, sprintf } from '@wordpress/i18n';

// 商品タイプの定義は PHP（ProductTypeRegistry）を単一情報源とし、
// window.affilicardProductTypes へ注入される（Plugin::enqueueSettings / ProductMetaBox）。
// 形: [{ code, label, extrasSchema: [{ key, label, placeholder }], offerFields: [] }]
//
// モジュール読込時ではなく呼び出しのたびに読む（テストで差し替えられるようにするため）。
// 注入が無い（読み込み順の事故など）ときは「汎用」だけにする。タイプをここに
// 直書きすると、PHP にタイプを足したときに管理画面だけ取り残されるため。
const FALLBACK = [
	{
		code: 'generic',
		label: __('汎用', 'affilicard'),
		extrasSchema: [],
		offerFields: [],
	},
];

export const productTypes = () => {
	const injected =
		typeof window !== 'undefined' &&
		Array.isArray(window.affilicardProductTypes)
			? window.affilicardProductTypes
			: [];
	return injected.length > 0 ? injected : FALLBACK;
};

export const findProductType = (code) =>
	productTypes().find((t) => t.code === code) ?? null;

// 未登録の code はそのまま返す。
export const productTypeLabel = (code) => findProductType(code)?.label ?? code;

/**
 * タイプの SelectControl 用の選択肢。
 * 保存済みの値が登録に無い（プラグインから消えたタイプ等）ときは、
 * 先頭の選択肢に勝手に見えてしまわないよう「未登録のタイプ」として残す。
 *
 * @param {string} current 現在の値。
 * @return {{label: string, value: string}[]} 選択肢。
 */
export const productTypeOptions = (current = '') => {
	const options = productTypes().map((t) => ({
		label: t.label,
		value: t.code,
	}));
	if (current && !findProductType(current)) {
		options.push({
			/* translators: %s: 商品タイプのコード */
			label: sprintf(__('未登録のタイプ（%s）', 'affilicard'), current),
			value: current,
		});
	}
	return options;
};

export const extrasSchemaFor = (code) => {
	const schema = findProductType(code)?.extrasSchema;
	return Array.isArray(schema) ? schema : [];
};

// タイプ固有の購入リンクの項目（例: 動画配信の plan_label）を、そのタイプが使うか。
export const typeAllowsOfferField = (code, field) => {
	const fields = findProductType(code)?.offerFields;
	return Array.isArray(fields) && fields.includes(field);
};
