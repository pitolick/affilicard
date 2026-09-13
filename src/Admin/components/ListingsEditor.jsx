import { useEffect, useRef, useState } from '@wordpress/element';
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
 * 元の配列での位置（index）を添えて返す。編集画面はこの並びで描画しつつ、
 * 行の身元（uid）・編集・削除は元の位置で引く必要があるため。
 *
 * @param {Array<Object>} offers
 * @return {Array<{offer: Object, index: number, order: number}>}
 */
function sortOffersWithIndex(offers) {
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
		.sort((a, b) => (a.order === b.order ? a.index - b.index : a.order - b.order));
}

/**
 * 上と同じ並べ替えの結果から offer だけを取り出す。
 *
 * @param {Array<Object>} offers
 * @return {Array<Object>}
 */
function sortOffersForSelection(offers) {
	return sortOffersWithIndex(offers).map((entry) => entry.offer);
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

function offerTestId(offer, index) {
	const id = (offer.external_id ?? '').trim();
	return id !== '' ? id : `pos-${index}`;
}

function OffersEditor({ offers, fallbackOnTerminal, onChange }) {
	const rows = Array.isArray(offers) ? offers : [];

	// 行の身元（React key ＝ PanelBody の開閉キー）は、中身（external_id 等）から
	// 導出してはいけない。導出すると、外部 ID を1文字打つたびに識別子が変わって
	// React が行を作り直し、openKeys が古いキーのままになって入力中のパネルが
	// 閉じ、フォーカスも失われる（1文字ごとに入力が止まる）。ここでは行が
	// 生成された瞬間に発行する uid を使い、以後は並べ替え・追加・削除のときだけ
	// この配列も同じ操作でなぞる。フィールドの編集では一切触らない。
	const nextUidRef = useRef(0);
	const makeUid = () => {
		nextUidRef.current += 1;
		return `offer-uid-${nextUidRef.current}`;
	};
	const [uids, setUids] = useState(() => rows.map(() => makeUid()));

	const [openKeys, setOpenKeys] = useState(() => new Set());
	const inUseOffer = selectInUseOffer(rows, fallbackOnTerminal);

	// 描画は配列の並びではなく PHP の OfferSelector と同じ並び（display_order 昇順・
	// 同値は出現順）で行う。配列順で描くと、外部パイプラインが書いた listing や
	// 過去 UI で並べ替えた listing で「PHP は 2 行目を使っているのに、画面では
	// 1 行目に使用中の印が付いている」という食い違いが起きる。
	const sortedEntries = sortOffersWithIndex(rows);

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

	// フィールドの編集は行の身元にもならない配列の並びにも触れない。
	const updateOffer = (idx, patch) => {
		onChange(rows.map((o, i) => (i === idx ? { ...o, ...patch } : o)));
	};

	// 削除では残りの display_order を詰めない。外部パイプライン等が明示的に
	// 振った番号かもしれず、詰めると別の書き手の意図を壊すため。
	const removeOffer = (idx) => {
		setUids((prev) => prev.filter((_, i) => i !== idx));
		onChange(rows.filter((_, i) => i !== idx));
	};

	const addOffer = () => {
		setUids((prev) => [...prev, makeUid()]);
		onChange([...rows, emptyOffer()]);
	};

	// 並べ替えは人の操作が明示的な意図なので、新しい並びに display_order を
	// 振り直す（同値が並んでいても確実に順序が変わるように）。削除時に詰めない
	// ルールとは別。身元（uids）もデータと同じ入れ替えでなぞり、開いていた行が
	// 新しい位置でも開いたままになるようにする。
	//
	// 受け取るのは配列の位置ではなく**描画されている並び**での位置。配列の位置で
	// 動かすと、配列の並びと display_order がずれている listing で ↑↓ が見えている
	// 並びと違う行を動かす。並べ替えたあとは配列の並びも描画順に揃える。
	const move = (position, direction) => {
		const target = position + direction;
		if (target < 0 || target >= sortedEntries.length) {
			return;
		}
		const entries = [...sortedEntries];
		[entries[position], entries[target]] = [
			entries[target],
			entries[position],
		];
		setUids((prev) =>
			entries.map((entry) => prev[entry.index] ?? `pos:${entry.index}`)
		);
		onChange(
			entries.map((entry, i) => ({
				...entry.offer,
				display_order: (i + 1) * 10,
			}))
		);
	};

	return (
		<div className="affilicard-offers-editor">
			<h4>{__('購入リンク', 'affilicard')}</h4>
			{rows.length === 0 && (
				<p className="description">
					{__('購入リンクがありません', 'affilicard')}
				</p>
			)}
			{sortedEntries.map(({ offer, index: i }, position) => {
				// uids は rows と同じ操作（追加・削除・並べ替え）でしか変わらないため、
				// 常に rows と同じ長さ・同じ並びのはず。念のため index にフォールバックする。
				// 引くのは描画位置ではなく元の配列での位置（i）。
				const key = uids[i] ?? `pos:${i}`;
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
								disabled={position === 0}
								onClick={() => move(position, -1)}
							/>
							<Button
								icon="arrow-down-alt2"
								size="small"
								label={__('下へ移動', 'affilicard')}
								disabled={position === sortedEntries.length - 1}
								onClick={() => move(position, 1)}
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
								label={__('通常 URL（必須）', 'affilicard')}
								required
								help={__(
									'空のまま保存すると、この購入リンクは保存時に破棄されます（生死を判定できないリンクを残さないため）。',
									'affilicard'
								)}
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
