import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	ComboboxControl,
	PanelBody,
	BaseControl,
	ToolbarGroup,
	ToolbarButton,
	Spinner,
} from '@wordpress/components';
import { InspectorControls, ColorPalette, BlockControls } from '@wordpress/block-editor';
import { searchProducts, getCardPreview } from '../Admin/api/products';

const COLOR_FIELDS = [
	{ attr: 'ctaBgColor', label: __('ボタン背景色', 'affilicard') },
	{ attr: 'ctaTextColor', label: __('ボタン文字色', 'affilicard') },
	{ attr: 'cardBgColor', label: __('カード背景色', 'affilicard') },
	{ attr: 'cardBorderColor', label: __('カード枠線色', 'affilicard') },
];

export function Edit({ attributes, setAttributes }) {
	const {
		productId,
		hidePlatforms = [],
		ctaLabelOverrides = {},
		ctaBgColor,
		ctaTextColor,
		cardBgColor,
		cardBorderColor,
	} = attributes;
	const [options, setOptions] = useState([]);
	const [filter, setFilter] = useState('');

	// プレビュー用 state
	const [previewHtml, setPreviewHtml] = useState('');
	const [previewState, setPreviewState] = useState('idle'); // idle | loading | error

	useEffect(() => {
		if (!filter) {
			setOptions([]);
			return;
		}
		let active = true;
		// 入力毎の REST 発火を避けるため簡易デバウンス（300ms）。
		const timer = setTimeout(() => {
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
		}, 300);
		return () => {
			active = false;
			clearTimeout(timer);
		};
	}, [filter]);

	// productId 選択時のプレビュー fetch（属性変更デバウンス 300ms）
	useEffect(() => {
		if (!productId) {
			setPreviewHtml('');
			setPreviewState('idle');
			return;
		}
		let active = true;
		setPreviewState('loading');
		const timer = setTimeout(() => {
			getCardPreview(productId, {
				hidePlatforms,
				ctaLabelOverrides,
				ctaBgColor,
				ctaTextColor,
				cardBgColor,
				cardBorderColor,
			})
				.then((res) => {
					if (!active) return;
					setPreviewHtml(res?.html || '');
					setPreviewState('idle');
				})
				.catch(() => active && setPreviewState('error'));
		}, 300);
		return () => {
			active = false;
			clearTimeout(timer);
		};
	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [
		productId,
		// eslint-disable-next-line react-hooks/exhaustive-deps
		JSON.stringify(hidePlatforms),
		// eslint-disable-next-line react-hooks/exhaustive-deps
		JSON.stringify(ctaLabelOverrides),
		ctaBgColor,
		ctaTextColor,
		cardBgColor,
		cardBorderColor,
	]);

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
			<div className="affilicard-block-preview">
				{inspector}
				<BlockControls>
					<ToolbarGroup>
						<ToolbarButton
							onClick={() => setAttributes({ productId: undefined })}
						>
							{__('商品を変更', 'affilicard')}
						</ToolbarButton>
					</ToolbarGroup>
				</BlockControls>
				{previewState === 'loading' && <Spinner />}
				{previewState === 'error' && (
					<p>{__('プレビューを取得できませんでした。', 'affilicard')}</p>
				)}
				{previewState !== 'error' && previewHtml && (
					// eslint-disable-next-line react/no-danger
					<div dangerouslySetInnerHTML={{ __html: previewHtml }} />
				)}
				{previewState !== 'loading' &&
					previewState !== 'error' &&
					!previewHtml && (
						<p>{__('プレビューする内容がありません。', 'affilicard')}</p>
					)}
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
