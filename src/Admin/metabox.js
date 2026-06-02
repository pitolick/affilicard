import { useEffect, useState, createElement } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getProduct } from './api/products';
import { ListingsEditor } from './components/ListingsEditor';
import { ExtrasEditor } from './components/ExtrasEditor';
import { StockStatusSelect } from './components/StockStatusSelect';

const PRODUCT_TYPE_OPTIONS = [
	{ value: 'generic', label: __('汎用', 'affilicard') },
	{ value: 'ebook', label: __('電子書籍', 'affilicard') },
];

export function MetaboxApp({ postId }) {
	const [data, setData] = useState(null);

	useEffect(() => {
		if (!postId) {
			return;
		}
		getProduct(postId)
			.then(setData)
			.catch(() =>
				setData({
					product_type: 'generic',
					stock_status: 'available',
					extras: [],
					listings: [],
				})
			);
	}, [postId]);

	if (!postId) {
		return <p>{__('保存後に編集できます', 'affilicard')}</p>;
	}
	if (data === null) {
		return <p>{__('読み込み中…', 'affilicard')}</p>;
	}

	const update = (patch) => setData({ ...data, ...patch });

	const hiddenValue = JSON.stringify({
		product_type: data.product_type,
		stock_status: data.stock_status,
		extras: data.extras,
		listings: data.listings,
	});

	return (
		<div className="affilicard-metabox">
			{/* hidden field: 現在の state を WP 投稿フォームと同期する */}
			<textarea
				name="affilicard_data"
				hidden
				readOnly
				value={hiddenValue}
			/>
			<SelectControl
				label={__('商品タイプ', 'affilicard')}
				value={data.product_type ?? 'generic'}
				options={PRODUCT_TYPE_OPTIONS}
				onChange={(v) => update({ product_type: v })}
			/>
			<StockStatusSelect
				value={data.stock_status}
				onChange={(v) => update({ stock_status: v })}
			/>
			<ExtrasEditor
				productType={data.product_type ?? 'generic'}
				extras={data.extras ?? []}
				onChange={(extras) => update({ extras })}
			/>
			<ListingsEditor
				listings={data.listings ?? []}
				onChange={(listings) => update({ listings })}
			/>
			<p className="affilicard-metabox-hint">
				{__(
					'『公開』『更新』を押すと商品設定も保存されます',
					'affilicard'
				)}
			</p>
		</div>
	);
}

document.addEventListener('DOMContentLoaded', () => {
	const root = document.getElementById('affilicard-metabox-root');
	if (!root) {
		return;
	}
	const postId = parseInt(root.dataset.postId ?? '0', 10) || 0;
	if (typeof createRoot === 'function') {
		createRoot(root).render(createElement(MetaboxApp, { postId }));
	}
});
