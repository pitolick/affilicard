import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	ComboboxControl,
	PanelBody,
	BaseControl,
	Button,
} from '@wordpress/components';
import { InspectorControls, ColorPalette } from '@wordpress/block-editor';
import { searchProducts, getProduct } from '../Admin/api/products';

const COLOR_FIELDS = [
	{ attr: 'ctaBgColor', label: __('ボタン背景色', 'affilicard') },
	{ attr: 'ctaTextColor', label: __('ボタン文字色', 'affilicard') },
	{ attr: 'cardBgColor', label: __('カード背景色', 'affilicard') },
	{ attr: 'cardBorderColor', label: __('カード枠線色', 'affilicard') },
];

export function Edit({ attributes, setAttributes }) {
	const { productId } = attributes;
	const [options, setOptions] = useState([]);
	const [filter, setFilter] = useState('');
	const [selectedTitle, setSelectedTitle] = useState('');

	useEffect(() => {
		if (!filter) {
			setOptions([]);
			return;
		}
		let active = true;
		searchProducts({ search: filter, perPage: 10 })
			.then((items) => {
				if (!active) {
					return;
				}
				setOptions(
					(items || []).map((p) => ({
						value: p.id,
						label: `${p.title} (#${p.id})`,
					}))
				);
			})
			.catch(() => active && setOptions([]));
		return () => {
			active = false;
		};
	}, [filter]);

	useEffect(() => {
		if (!productId) {
			setSelectedTitle('');
			return;
		}
		let active = true;
		getProduct(productId)
			.then((p) => active && setSelectedTitle(p?.title ?? ''))
			.catch(() => active && setSelectedTitle(''));
		return () => {
			active = false;
		};
	}, [productId]);

	const inspector = (
		<InspectorControls>
			<PanelBody title={__('色設定', 'affilicard')}>
				{COLOR_FIELDS.map((field) => (
					<BaseControl key={field.attr} label={field.label}>
						<ColorPalette
							value={attributes[field.attr]}
							onChange={(color) =>
								setAttributes({ [field.attr]: color })
							}
						/>
					</BaseControl>
				))}
			</PanelBody>
		</InspectorControls>
	);

	if (productId) {
		return (
			<div className="affilicard-block-placeholder">
				{inspector}
				<p>
					{__('選択中の商品: ', 'affilicard')}
					<strong>{selectedTitle || `#${productId}`}</strong>
				</p>
				<Button
					variant="secondary"
					onClick={() => setAttributes({ productId: undefined })}
				>
					{__('商品を変更', 'affilicard')}
				</Button>
			</div>
		);
	}

	return (
		<div className="affilicard-block-placeholder">
			{inspector}
			<ComboboxControl
				label={__('商品を検索', 'affilicard')}
				value={null}
				options={options}
				onFilterValueChange={setFilter}
				onChange={(value) =>
					setAttributes({
						productId: parseInt(value, 10) || undefined,
					})
				}
			/>
		</div>
	);
}
