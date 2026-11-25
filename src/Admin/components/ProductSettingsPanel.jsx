import { useEffect, useState } from '@wordpress/element';
import { useEntityProp } from '@wordpress/core-data';
import {
	PanelBody,
	SelectControl,
	TextControl,
	CheckboxControl,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ListingsEditor } from './ListingsEditor';
import { ExtrasEditor } from './ExtrasEditor';
import { StockStatusSelect } from './StockStatusSelect';
import { fetchEditorSettings } from '../api/settings';
import { fetchPlatforms } from '../api/platforms';

const PRODUCT_TYPE_OPTIONS = [
	{ value: 'generic', label: __('汎用', 'affilicard') },
	{ value: 'ebook', label: __('電子書籍', 'affilicard') },
];

const asArray = (v) => (Array.isArray(v) ? v : []);

/**
 * ストアが成人向けと判定した platform code（どれかの購入リンクの adult が '1'）。
 * PHP の AdultDetection::detectedBy() と同じ決まり（無効な listing も数える）。
 *
 * @param {Array} listings 商品の listings。
 * @return {string[]} platform code の一覧（重複なし）。
 */
export function adultDetectedBy(listings) {
	const codes = [];
	asArray(listings).forEach((listing) => {
		const hit = asArray(listing?.offers).some(
			(offer) => '1' === String(offer?.adult ?? '')
		);
		if (hit && listing?.platform && !codes.includes(listing.platform)) {
			codes.push(listing.platform);
		}
	});
	return codes;
}

export function ProductSettingsPanel() {
	const [meta, setMeta] = useEntityProp(
		'postType',
		'affilicard_product',
		'meta'
	);
	const m = meta || {};

	// 「使用中」の印は GeneralSettings::fallbackOnTerminal()（サイト全体設定）に
	// 依存する。CardHtmlBuilder が描画側へ渡しているのと同じ値を、ここでは
	// REST 経由（fetchEditorSettings）で取得して ListingsEditor へ渡す。取得前/失敗時は
	// PHP 側の既定（OFF）に合わせて false のまま進める。
	const [generalSettings, setGeneralSettings] = useState(null);
	useEffect(() => {
		fetchEditorSettings()
			.then(setGeneralSettings)
			.catch(() => setGeneralSettings({}));
	}, []);
	const fallbackOnTerminal = Boolean(generalSettings?.fallback_on_terminal);

	// 成人向け判定の注意書きで、platform code の代わりに名前を出すため。
	const [platformNames, setPlatformNames] = useState({});
	useEffect(() => {
		fetchPlatforms()
			.then((list) =>
				setPlatformNames(
					Object.fromEntries(
						asArray(list).map((p) => [p.code, p.name || p.code])
					)
				)
			)
			.catch(() => setPlatformNames({}));
	}, []);

	const productType = m.affilicard_product_type || 'generic';
	const stockStatus = m.affilicard_stock_status || 'available';
	const releaseDate = m.affilicard_release_date || '';
	const maskBlur = !!m.affilicard_mask_blur;
	const maskR18 = !!m.affilicard_mask_r18;
	const maskLabel = m.affilicard_mask_label || '';
	const listings = asArray(m.affilicard_listings);
	const adultBy = adultDetectedBy(listings);
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
				{adultBy.length > 0 && (
					<Notice status="info" isDismissible={false}>
						{`${adultBy
							.map((code) => platformNames[code] || code)
							.join('・')}${__(
							' が成人向けと判定しています。設定 → 一般 の「ストアが成人向けと判定した商品を R18 表示にする」が ON（既定）なら、上のチェックが無くてもカードは R18 表示になります。外したいときはブロックの「ストアの成人向け判定」で「判定を無視する」を選んでください。',
							'affilicard'
						)}`}
					</Notice>
				)}
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
					fallbackOnTerminal={fallbackOnTerminal}
					onChange={(next) => patch({ affilicard_listings: next })}
				/>
			</PanelBody>
		</div>
	);
}
