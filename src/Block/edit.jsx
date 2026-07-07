import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	CheckboxControl,
	ComboboxControl,
	PanelBody,
	BaseControl,
	ToolbarGroup,
	ToolbarButton,
	Spinner,
	TextControl,
} from '@wordpress/components';
import {
	InspectorControls,
	ColorPalette,
	BlockControls,
	useBlockProps,
} from '@wordpress/block-editor';
import { searchProducts, getProduct, getCardPreview } from '../Admin/api/products';

/**
 * コンボボックスのアイテム描画純粋関数。
 * option.item（raw データ）があればサムネ＋title＋platform＋price のリッチ div を返し、
 * 無ければ <span>{option.label}</span> を返す。
 *
 * @param {{ value: number, label: string, item?: object }} option
 * @return {JSX.Element}
 */
export function renderComboboxItem( option ) {
	const data = option?.item;
	if ( ! data ) {
		return <span>{ option?.label }</span>;
	}
	return (
		<div className="affilicard-combobox-item">
			{ data.thumbnail ? (
				<img src={ data.thumbnail } alt="" width="32" height="32" />
			) : null }
			<span className="affilicard-combobox-item__title">{ data.title }</span>
			{ data.platform ? (
				<span className="affilicard-combobox-item__platform">{ data.platform }</span>
			) : null }
			{ data.price ? (
				<span className="affilicard-combobox-item__price">
					{ '¥' }
					{ data.price }
				</span>
			) : null }
		</div>
	);
}

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
		onlyPlatforms = [],
		ctaLabelOverrides = {},
		ctaBgColor,
		ctaTextColor,
		cardBgColor,
		cardBorderColor,
		maskBlur,
		maskR18,
		maskLabel,
	} = attributes;
	const [options, setOptions] = useState([]);
	const [filter, setFilter] = useState('');

	// apiVersion 3 ブロックはルートに useBlockProps を適用しないと
	// 選択・クリック処理が配線されない。両ブランチのルートに spread する。
	const blockProps = useBlockProps();

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
				onlyPlatforms,
				ctaLabelOverrides,
				ctaBgColor,
				ctaTextColor,
				cardBgColor,
				cardBorderColor,
				maskBlur,
				maskR18,
				maskLabel,
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
		JSON.stringify(onlyPlatforms),
		// eslint-disable-next-line react-hooks/exhaustive-deps
		JSON.stringify(ctaLabelOverrides),
		ctaBgColor,
		ctaTextColor,
		cardBgColor,
		cardBorderColor,
		maskBlur,
		maskR18,
		maskLabel,
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
					title={__('表示プラットフォーム', 'affilicard')}
					initialOpen={false}
				>
					<p className="components-base-control__help">
						{__('未選択なら全プラットフォームを表示します。', 'affilicard')}
					</p>
					{listingPlatforms.map((code) => (
						<CheckboxControl
							key={code}
							label={code}
							checked={onlyPlatforms.includes(code)}
							onChange={(checked) =>
								setAttributes({
									onlyPlatforms: checked
										? [...onlyPlatforms, code]
										: onlyPlatforms.filter((c) => c !== code),
								})
							}
							__nextHasNoMarginBottom
						/>
					))}
				</PanelBody>
			)}
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
			<PanelBody
				title={__('表紙マスク', 'affilicard')}
				initialOpen={false}
			>
				<p className="components-base-control__help">
					{__('未設定なら商品側の設定を継承します。', 'affilicard')}
				</p>
				<CheckboxControl
					label={__('表紙にぼかしを掛ける', 'affilicard')}
					checked={!!maskBlur}
					onChange={(checked) => setAttributes({ maskBlur: checked })}
					__nextHasNoMarginBottom
				/>
				<CheckboxControl
					label={__('R18（18+ バッジ＋ぼかしを強制）', 'affilicard')}
					checked={!!maskR18}
					onChange={(checked) => setAttributes({ maskR18: checked })}
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={__('ラベルテキスト（任意）', 'affilicard')}
					value={maskLabel ?? ''}
					onChange={(value) => setAttributes({ maskLabel: value })}
					placeholder={__('未設定（継承）', 'affilicard')}
					__nextHasNoMarginBottom
				/>
			</PanelBody>
		</InspectorControls>
	);

	if (productId) {
		return (
			<div
				{...blockProps}
				className={`${blockProps.className ?? ''} affilicard-block-preview`.trim()}
			>
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
					// プレビューはエディタ上で非インタラクティブ（block-editor.css で
					// pointer-events: none）。クリックはブロック本体に通って選択され、
					// CTA リンクの誤遷移も防ぐ。エスケープ済みのサーバ生成 HTML を挿入。
					// eslint-disable-next-line react/no-danger
					<div
						className="affilicard-block-preview__rendered"
						dangerouslySetInnerHTML={{ __html: previewHtml }}
					/>
				)}
				{previewState === 'idle' && !previewHtml && (
					<p>{__('プレビューする内容がありません。', 'affilicard')}</p>
				)}
			</div>
		);
	}

	// ComboboxControl が関数として存在する場合のみ __experimentalRenderItem を使用する。
	// renderComboboxItem を呼ぶアダプタ: ComboboxControl が渡す引数は { item } の形（item = option object）。
	const renderItem =
		typeof ComboboxControl === 'function'
			? ( { item } ) => renderComboboxItem( item )
			: undefined;

	return (
		<div
			{...blockProps}
			className={`${blockProps.className ?? ''} affilicard-block-placeholder`.trim()}
		>
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
