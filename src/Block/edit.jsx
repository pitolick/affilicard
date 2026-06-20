import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	ComboboxControl,
	PanelBody,
	BaseControl,
	ToolbarGroup,
	ToolbarButton,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { InspectorControls, ColorPalette, BlockControls } from '@wordpress/block-editor';
import { searchProducts, getProduct, getCardPreview } from '../Admin/api/products';

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

	// 選択商品の有効 listing プラットフォーム
	const [listingPlatforms, setListingPlatforms] = useState([]);

	useEffect(() => {
		let active = true;
		// 入力毎の REST 発火を避けるため簡易デバウンス（300ms）。
		// 空フィルタ時も最近商品（modified 降順）を取得する。
		const timer = setTimeout(() => {
			searchProducts({ search: filter, perPage: 20 })
				.then((items) => {
					if (!active) {
						return;
					}
					setOptions(
						(items || []).map((p) => ({
							value: p.id,
							label: `${p.title} (#${p.id})`,
							item: p,
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

	// productId 選択時に有効 listing の platform code を取得
	useEffect(() => {
		if (!productId) {
			setListingPlatforms([]);
			return;
		}
		let active = true;
		getProduct(productId)
			.then((p) => {
				if (!active) return;
				const codes = (p?.listings || [])
					.filter((l) => l && l.enabled !== false && l.platform)
					.map((l) => l.platform);
				setListingPlatforms([...new Set(codes)]);
			})
			.catch(() => active && setListingPlatforms([]));
		return () => {
			active = false;
		};
	}, [productId]);

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
				.catch(() => {
					if (!active) return;
					setPreviewHtml('');
					setPreviewState('error');
				});
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

	const setCtaOverride = (code, value) => {
		const next = { ...ctaLabelOverrides };
		if (value && value.trim()) {
			next[code] = value;
		} else {
			delete next[code];
		}
		setAttributes({ ctaLabelOverrides: next });
	};

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
			{listingPlatforms.length > 0 && (
				<PanelBody
					title={__('CTA ラベル上書き', 'affilicard')}
					initialOpen={false}
				>
					{listingPlatforms.map((code) => (
						<TextControl
							key={code}
							label={code}
							value={ctaLabelOverrides[code] || ''}
							onChange={(value) => setCtaOverride(code, value)}
							placeholder={__('未設定（プラットフォーム既定）', 'affilicard')}
							__nextHasNoMarginBottom
						/>
					))}
				</PanelBody>
			)}
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
				{previewHtml && (
					// eslint-disable-next-line react/no-danger
					<div dangerouslySetInnerHTML={{ __html: previewHtml }} />
				)}
				{previewState === 'idle' && !previewHtml && (
					<p>{__('プレビューする内容がありません。', 'affilicard')}</p>
				)}
			</div>
		);
	}

	const renderItem =
		typeof ComboboxControl === 'function'
			? ({ item }) => {
					const data = item?.item;
					if (!data) {
						return <span>{item?.label}</span>;
					}
					return (
						<div className="affilicard-combobox-item">
							{data.thumbnail ? (
								<img src={data.thumbnail} alt="" width="32" height="32" />
							) : null}
							<span className="affilicard-combobox-item__title">{data.title}</span>
							{data.platform ? (
								<span className="affilicard-combobox-item__platform">{data.platform}</span>
							) : null}
							{data.price ? (
								<span className="affilicard-combobox-item__price">
									{'¥'}
									{data.price}
								</span>
							) : null}
						</div>
					);
			  }
			: undefined;

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
				{...(renderItem ? { __experimentalRenderItem: renderItem } : {})}
			/>
		</div>
	);
}
