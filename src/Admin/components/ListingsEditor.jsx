import { useEffect, useState } from '@wordpress/element';
import {
	TextControl,
	ToggleControl,
	SelectControl,
	Button,
	Notice,
	Panel,
	PanelBody,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { fetchPlatforms } from '../api/platforms';

// Affilicard\Pricing\OfferSelector::DEFAULT_ORDER と揃える。
const DEFAULT_DISPLAY_ORDER = 100;

const EMPTY_LISTING = {
	platform: '',
	enabled: true,
	// 自動取得の可否はプラットフォームの Provider 側で決まるため、listing 側は
	// 既定で自動更新の対象にする（'manual' 固定だと追加した listing が永久に
	// 更新されない）。止めたい listing だけ「自動更新」トグルを OFF にする。
	update_mode: 'auto',
	auto_update: true,
	button_label_override: '',
	platform_extras: [],
	offers: [],
};

function emptyOffer() {
	return {
		display_order: DEFAULT_DISPLAY_ORDER,
		external_id: '',
		regular_url: '',
		affiliate_url: '',
		price: '',
		list_price: '',
		badge: '',
		image_url: '',
		search_key: '',
		fetch_status: '',
		last_fetched_at: '',
		last_verified_at: '',
	};
}

function platformName(platforms, code) {
	const p = (platforms || []).find((x) => x.code === code);
	return p ? p.name : '';
}

function rowTitle(platforms, row) {
	const name = platformName(platforms, row.platform);
	return name || __('（プラットフォーム未選択）', 'affilicard');
}

/**
 * `Affilicard\Pricing\OfferSelector::sorted()`（PHP）と同じ並べ替え。
 * display_order 昇順・同値は元の配列での出現順（安定ソート）。
 *
 * @param {Array<Object>} offers
 * @return {Array<Object>}
 */
function sortOffersForSelection(offers) {
	return offers
		.map((offer, index) => ({ offer, index }))
		.filter(({ offer }) => offer && typeof offer === 'object')
		.map(({ offer, index }) => {
			const raw = offer.display_order;
			const order =
				raw === undefined || raw === null || raw === ''
					? DEFAULT_DISPLAY_ORDER
					: Number(raw);
			return { offer, index, order };
		})
		.sort((a, b) => (a.order === b.order ? a.index - b.index : a.order - b.order))
		.map((entry) => entry.offer);
}

/**
 * どの購入リンク（offer）が「使用中」かを決める。
 *
 * `Affilicard\Pricing\OfferSelector::select()`（PHP、src/Pricing/OfferSelector.php）
 * をそのまま JS へ写したもの。カード描画も価格更新もこの PHP 側の規則だけを見るため、
 * 管理画面がここで別の規則を使うと「印は付いているのに実際に表示されるリンクは違う」
 * という食い違いが起きる。分岐を変えるときは両方直し、
 * tests/js/components/ListingsEditor.test.jsx の一致確認テストも一緒に直すこと。
 *
 * @param {Array<Object>} offers
 * @param {boolean}       fallbackOnTerminal
 * @return {Object|null} 使用中の offer（配列内の同一参照）。offers が空なら null。
 */
export function selectInUseOffer(offers, fallbackOnTerminal) {
	const rows = Array.isArray(offers) ? offers : [];
	const sorted = sortOffersForSelection(rows);
	if (sorted.length === 0) {
		return null;
	}
	if (!fallbackOnTerminal) {
		return sorted[0];
	}
	const survivor = sorted.find((offer) => offer.fetch_status !== 'terminal');
	// 全件 terminal。非破壊に倒し、先頭を返す（PHP 側と同じ）。
	return survivor ?? sorted[0];
}

const FETCH_STATUS_LABELS = {
	unsupported: __('自動取得の対象外です', 'affilicard'),
	transient: __('一時的に取得できませんでした', 'affilicard'),
	terminal: __('商品が見つかりません', 'affilicard'),
};

// `Affilicard\Pricing\FetchStatus::label()`（PHP）と同じ文言。
// unsupported と transient は再試行の分類こそ同じだが、人に見せる意味は別なので
// 文言も分ける（自動取得対象外 ≠ 一時的な失敗）。
function offerStatusLabel(status) {
	return FETCH_STATUS_LABELS[status] ?? '';
}

// アフィリエイト URL 未設定・通常 URL 設定済みなら、カード描画は通常 URL へ
// フォールバックする（CardRenderer と同じ判定）。
function isOfferFallback(offer) {
	return (offer.affiliate_url ?? '') === '' && (offer.regular_url ?? '') !== '';
}

function offerTitle(offer) {
	const id = (offer.external_id ?? '').trim();
	return id !== '' ? id : __('（外部 ID 未設定）', 'affilicard');
}

// PanelBody の開閉に使う識別子。行の位置（配列 index）に紐づけると、並べ替えで
// 別の購入リンクが同じ位置に来たときに開閉状態が入れ替わって見える
// （PanelBody の initialOpen は手動トグルまで毎レンダー読み直されるため）。
// external_id は外部（ストア）の商品 ID で購入リンクごとに決まるため、これを識別子にする。
// 未設定のうちは位置でしか区別できないため index にフォールバックする。
function offerKey(offer, index) {
	const id = (offer.external_id ?? '').trim();
	return id !== '' ? `id:${id}` : `pos:${index}`;
}

function offerTestId(offer, index) {
	const id = (offer.external_id ?? '').trim();
	return id !== '' ? id : `pos-${index}`;
}

function OffersEditor({ offers, fallbackOnTerminal, onChange }) {
	const rows = Array.isArray(offers) ? offers : [];
	const [openKeys, setOpenKeys] = useState(() => new Set());
	const inUseOffer = selectInUseOffer(rows, fallbackOnTerminal);

	const setOpen = (key, isOpen) => {
		setOpenKeys((prev) => {
			const next = new Set(prev);
			if (isOpen) {
				next.add(key);
			} else {
				next.delete(key);
			}
			return next;
		});
	};

	const updateOffer = (idx, patch) => {
		onChange(rows.map((o, i) => (i === idx ? { ...o, ...patch } : o)));
	};

	// 削除では残りの display_order を詰めない。外部パイプライン等が明示的に
	// 振った番号かもしれず、詰めると別の書き手の意図を壊すため。
	const removeOffer = (idx) => onChange(rows.filter((_, i) => i !== idx));

	const addOffer = () => onChange([...rows, emptyOffer()]);

	// 並べ替えは人の操作が明示的な意図なので、新しい並びに display_order を
	// 振り直す（同値が並んでいても確実に順序が変わるように）。削除時に詰めない
	// ルールとは別。
	const move = (idx, direction) => {
		const target = idx + direction;
		if (target < 0 || target >= rows.length) {
			return;
		}
		const next = [...rows];
		[next[idx], next[target]] = [next[target], next[idx]];
		const renumbered = next.map((o, i) => ({
			...o,
			display_order: (i + 1) * 10,
		}));
		onChange(renumbered);
	};

	return (
		<div className="affilicard-offers-editor">
			<h4>{__('購入リンク', 'affilicard')}</h4>
			{rows.length === 0 && (
				<p className="description">
					{__('購入リンクがありません', 'affilicard')}
				</p>
			)}
			{rows.map((offer, i) => {
				const key = offerKey(offer, i);
				const isInUse = inUseOffer === offer;
				const statusLabel = offerStatusLabel(offer.fetch_status);
				return (
					<div
						className="affilicard-offer-row"
						key={key}
						data-testid={`offer-${offerTestId(offer, i)}`}
					>
						<div className="affilicard-offer-row__header">
							<span className="affilicard-offer-row__order">
								{__('表示順', 'affilicard')}:{' '}
								{offer.display_order ?? DEFAULT_DISPLAY_ORDER}
							</span>
							<Button
								icon="arrow-up-alt2"
								size="small"
								label={__('上へ移動', 'affilicard')}
								disabled={i === 0}
								onClick={() => move(i, -1)}
							/>
							<Button
								icon="arrow-down-alt2"
								size="small"
								label={__('下へ移動', 'affilicard')}
								disabled={i === rows.length - 1}
								onClick={() => move(i, 1)}
							/>
							{isInUse && (
								<span className="affilicard-offer-row__badge">
									{__('使用中', 'affilicard')}
								</span>
							)}
							{statusLabel && (
								<span className="affilicard-offer-row__status">
									{statusLabel}
								</span>
							)}
							{isOfferFallback(offer) && (
								<Notice status="warning" isDismissible={false}>
									{__(
										'⚠ アフィリエイト URL 未設定、通常 URL にフォールバック中',
										'affilicard'
									)}
								</Notice>
							)}
						</div>
						<PanelBody
							title={offerTitle(offer)}
							opened={openKeys.has(key)}
							onToggle={(isOpen) => setOpen(key, isOpen)}
						>
							<TextControl
								label={__('外部 ID', 'affilicard')}
								value={offer.external_id ?? ''}
								placeholder={__('ストアの商品 ID', 'affilicard')}
								onChange={(v) =>
									updateOffer(i, { external_id: v })
								}
							/>
							<TextControl
								label={__('通常 URL', 'affilicard')}
								value={offer.regular_url ?? ''}
								placeholder={__(
									'https://example.com/item/123',
									'affilicard'
								)}
								onChange={(v) =>
									updateOffer(i, { regular_url: v })
								}
							/>
							<TextControl
								label={__('アフィリエイト URL', 'affilicard')}
								value={offer.affiliate_url ?? ''}
								placeholder={__(
									'https://al.example.com/item/123',
									'affilicard'
								)}
								onChange={(v) =>
									updateOffer(i, { affiliate_url: v })
								}
							/>
							<TextControl
								label={__('価格', 'affilicard')}
								value={offer.price ?? ''}
								placeholder={__('例: 660', 'affilicard')}
								onChange={(v) => updateOffer(i, { price: v })}
							/>
							<TextControl
								label={__('参考価格', 'affilicard')}
								value={offer.list_price ?? ''}
								placeholder={__('例: 880', 'affilicard')}
								onChange={(v) =>
									updateOffer(i, { list_price: v })
								}
							/>
							<TextControl
								label={__('バッジ', 'affilicard')}
								value={offer.badge ?? ''}
								placeholder={__('例: 40%OFF', 'affilicard')}
								onChange={(v) => updateOffer(i, { badge: v })}
							/>
							<TextControl
								label={__('画像 URL', 'affilicard')}
								value={offer.image_url ?? ''}
								placeholder={__(
									'https://example.com/cover.jpg',
									'affilicard'
								)}
								onChange={(v) =>
									updateOffer(i, { image_url: v })
								}
							/>
							<Button
								variant="link"
								isDestructive
								onClick={() => removeOffer(i)}
							>
								{__('購入リンクを削除', 'affilicard')}
							</Button>
						</PanelBody>
					</div>
				);
			})}
			<Button variant="secondary" onClick={addOffer}>
				{__('購入リンクを追加', 'affilicard')}
			</Button>
		</div>
	);
}

export function ListingsEditor({
	listings,
	platforms: platformsProp,
	onChange,
	fallbackOnTerminal = false,
}) {
	const [fetchedPlatforms, setFetchedPlatforms] = useState(null);

	useEffect(() => {
		// 呼び出し側が platforms を直接渡す場合（テスト等）は内部フェッチをしない。
		if (platformsProp) {
			return;
		}
		fetchPlatforms()
			.then((list) => setFetchedPlatforms(Array.isArray(list) ? list : []))
			.catch(() => setFetchedPlatforms([]));
	}, [platformsProp]);

	const rows = Array.isArray(listings) ? listings : [];

	const updateRow = (idx, patch) => {
		const next = rows.map((r, i) => (i === idx ? { ...r, ...patch } : r));
		onChange(next);
	};

	const removeRow = (idx) => onChange(rows.filter((_, i) => i !== idx));

	const addRow = () => onChange([...rows, { ...EMPTY_LISTING, offers: [] }]);

	const platforms = platformsProp ?? fetchedPlatforms;

	if (platforms === null) {
		return <p>{__('プラットフォーム読み込み中…', 'affilicard')}</p>;
	}

	const platformOptions = [
		{ value: '', label: __('— 選択 —', 'affilicard') },
		...platforms.map((p) => ({ value: p.code, label: p.name })),
	];

	return (
		<div className="affilicard-listings-editor">
			<h3>{__('プラットフォーム listing', 'affilicard')}</h3>
			{rows.length === 0 && (
				<p className="description">
					{__('listing がありません', 'affilicard')}
				</p>
			)}
			<Panel className="affilicard-listings-panel">
				{rows.map((row, i) => (
					<PanelBody
						key={i}
						title={rowTitle(platforms, row)}
						initialOpen={i === 0}
					>
						<SelectControl
							label={__('プラットフォーム', 'affilicard')}
							value={row.platform}
							options={platformOptions}
							onChange={(v) => updateRow(i, { platform: v })}
						/>
						<ToggleControl
							label={__('有効', 'affilicard')}
							checked={Boolean(row.enabled)}
							onChange={(v) => updateRow(i, { enabled: v })}
						/>
						<ToggleControl
							label={__('自動更新', 'affilicard')}
							checked={Boolean(row.auto_update)}
							onChange={(v) =>
								// update_mode は v3.3.0 でこのトグルに一本化した。旧 UI が
								// 書いた 'manual' が残っていると ON にしても PHP 側で弾かれ、
								// トグルが無言で効かないため、操作時に auto へ正規化する。
								// （'api' は PHP 側が auto の別表記として救済する）
								updateRow(i, {
									auto_update: v,
									update_mode: 'auto',
								})
							}
							help={__(
								'OFF にするとこの listing は定期実行の自動更新の対象から外れます。ただし設定 →「強制一括更新」を実行した場合は OFF の listing も更新されます。プラットフォームの Provider が手動入力の場合は ON でも自動取得されません。',
								'affilicard'
							)}
						/>
						<TextControl
							label={__('ボタンラベル上書き', 'affilicard')}
							value={row.button_label_override}
							placeholder={__('例: ○○で読む', 'affilicard')}
							onChange={(v) =>
								updateRow(i, { button_label_override: v })
							}
						/>
						<OffersEditor
							offers={row.offers}
							fallbackOnTerminal={fallbackOnTerminal}
							onChange={(nextOffers) =>
								updateRow(i, { offers: nextOffers })
							}
						/>
						<Button
							variant="link"
							isDestructive
							onClick={() => removeRow(i)}
						>
							{__('listing を削除', 'affilicard')}
						</Button>
					</PanelBody>
				))}
			</Panel>
			<Button variant="secondary" onClick={addRow}>
				{__('listing を追加', 'affilicard')}
			</Button>
		</div>
	);
}
