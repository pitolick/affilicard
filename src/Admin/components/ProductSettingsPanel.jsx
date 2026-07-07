import { useEntityProp } from '@wordpress/core-data';
import { PanelBody, SelectControl, TextControl, CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ListingsEditor } from './ListingsEditor';
import { ExtrasEditor } from './ExtrasEditor';
import { StockStatusSelect } from './StockStatusSelect';

const PRODUCT_TYPE_OPTIONS = [
	{ value: 'generic', label: __('汎用', 'affilicard') },
	{ value: 'ebook', label: __('電子書籍', 'affilicard') },
];

const asArray = (v) => (Array.isArray(v) ? v : []);

export function ProductSettingsPanel() {
	const [meta, setMeta] = useEntityProp(
		'postType',
		'affilicard_product',
		'meta'
	);
	const m = meta || {};

	const productType = m.affilicard_product_type || 'generic';
	const stockStatus = m.affilicard_stock_status || 'available';
	const releaseDate = m.affilicard_release_date || '';
	const maskBlur = !!m.affilicard_mask_blur;
	const maskR18 = !!m.affilicard_mask_r18;
	const maskLabel = m.affilicard_mask_label || '';
	const listings = asArray(m.affilicard_listings);
	const extras = asArray(m.affilicard_extras);

	// useEntityProp の setter は React の setState と異なり更新関数を受け付けない
	// （editEntityRecord に値をそのまま渡す）。毎レンダーで fresh な m を読み、
	// 各 editor は常に全要素を渡すため object 形式マージで安全。
	const patch = (next) => setMeta({ ...m, ...next });

	return (
		<div className="affilicard-product-settings">
			<PanelBody title={__('基本', 'affilicard')} initialOpen={true}>
				<SelectControl
					label={__('商品タイプ', 'affilicard')}
					value={productType}
					options={PRODUCT_TYPE_OPTIONS}
					onChange={(v) => patch({ affilicard_product_type: v })}
				/>
				<StockStatusSelect
					value={stockStatus}
					onChange={(v) => patch({ affilicard_stock_status: v })}
				/>
				<TextControl
					type="date"
					label={__('発売日（予約商品・任意）', 'affilicard')}
					help={__('未来の日付を入れると発売日まで予約カード表示になります', 'affilicard')}
					value={releaseDate}
					onChange={(v) => patch({ affilicard_release_date: v })}
				/>
			</PanelBody>
			<PanelBody title={__('表紙マスク', 'affilicard')} initialOpen={false}>
				<CheckboxControl
					label={__('表紙にぼかしを掛ける', 'affilicard')}
					checked={maskBlur}
					onChange={(v) => patch({ affilicard_mask_blur: v })}
					__nextHasNoMarginBottom
				/>
				<CheckboxControl
					label={__('R18（18+ バッジ＋ぼかしを強制）', 'affilicard')}
					checked={maskR18}
					onChange={(v) => patch({ affilicard_mask_r18: v })}
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={__('ラベルテキスト（任意）', 'affilicard')}
					help={__('ぼかし/R18 のとき表紙上に表示する注意文言', 'affilicard')}
					value={maskLabel}
					onChange={(v) => patch({ affilicard_mask_label: v })}
				/>
			</PanelBody>
			<PanelBody title={__('追加情報', 'affilicard')} initialOpen={false}>
				<ExtrasEditor
					productType={productType}
					extras={extras}
					onChange={(next) => patch({ affilicard_extras: next })}
				/>
			</PanelBody>
			<PanelBody
				title={__('プラットフォーム listing', 'affilicard')}
				initialOpen={false}
			>
				<ListingsEditor
					listings={listings}
					onChange={(next) => patch({ affilicard_listings: next })}
				/>
			</PanelBody>
		</div>
	);
}
