import { useEntityProp } from '@wordpress/core-data';
import { PanelBody, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ListingsEditor } from './ListingsEditor';
import { ExtrasEditor } from './ExtrasEditor';
import { StockStatusSelect } from './StockStatusSelect';

const PRODUCT_TYPE_OPTIONS = [
	{ value: 'generic', label: __('汎用', 'affilicard') },
	{ value: 'ebook', label: __('電子書籍', 'affilicard') },
];

const parseJsonArray = (v) => {
	if (typeof v !== 'string' || v === '') {
		return [];
	}
	try {
		const parsed = JSON.parse(v);
		return Array.isArray(parsed) ? parsed : [];
	} catch (e) {
		return [];
	}
};

export function ProductSettingsPanel() {
	const [meta, setMeta] = useEntityProp(
		'postType',
		'affilicard_product',
		'meta'
	);
	const m = meta || {};

	const productType = m.affilicard_product_type || 'generic';
	const stockStatus = m.affilicard_stock_status || 'available';
	const listings = parseJsonArray(m.affilicard_listings);
	const extras = parseJsonArray(m.affilicard_extras);

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
			</PanelBody>
			<PanelBody title={__('追加情報', 'affilicard')} initialOpen={false}>
				<ExtrasEditor
					productType={productType}
					extras={extras}
					onChange={(next) =>
						patch({ affilicard_extras: JSON.stringify(next) })
					}
				/>
			</PanelBody>
			<PanelBody
				title={__('プラットフォーム listing', 'affilicard')}
				initialOpen={false}
			>
				<ListingsEditor
					listings={listings}
					onChange={(next) =>
						patch({ affilicard_listings: JSON.stringify(next) })
					}
				/>
			</PanelBody>
		</div>
	);
}
