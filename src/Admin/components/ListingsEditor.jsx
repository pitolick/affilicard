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

const EMPTY_LISTING = {
	platform: '',
	enabled: true,
	// 自動取得の可否はプラットフォームの Provider 側で決まるため、listing 側は
	// 既定で自動更新の対象にする（'manual' 固定だと追加した listing が永久に
	// 更新されない）。止めたい listing だけ「自動更新」トグルを OFF にする。
	update_mode: 'auto',
	auto_update: true,
	external_id: '',
	regular_url: '',
	affiliate_url: '',
	price: '',
	list_price: '',
	badge: '',
	image_url: '',
	button_label_override: '',
	last_fetched_at: '',
	fetch_error: null,
	platform_extras: [],
};

function isFallback(listing) {
	return (
		(listing.affiliate_url ?? '') === '' &&
		(listing.regular_url ?? '') !== ''
	);
}

function platformName(platforms, code) {
	const p = (platforms || []).find((x) => x.code === code);
	return p ? p.name : '';
}

function rowTitle(platforms, row) {
	const name = platformName(platforms, row.platform);
	const base = name || __('（プラットフォーム未選択）', 'affilicard');
	return isFallback(row) ? `⚠ ${base}` : base;
}

export function ListingsEditor({ listings, onChange }) {
	const [platforms, setPlatforms] = useState(null);

	useEffect(() => {
		fetchPlatforms()
			.then((list) => setPlatforms(Array.isArray(list) ? list : []))
			.catch(() => setPlatforms([]));
	}, []);

	const rows = Array.isArray(listings) ? listings : [];

	const updateRow = (idx, patch) => {
		const next = rows.map((r, i) => (i === idx ? { ...r, ...patch } : r));
		onChange(next);
	};

	const removeRow = (idx) => onChange(rows.filter((_, i) => i !== idx));

	const addRow = () => onChange([...rows, { ...EMPTY_LISTING }]);

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
						{isFallback(row) && (
							<Notice status="warning" isDismissible={false}>
								{__(
									'⚠ アフィリエイト URL 未設定、通常 URL にフォールバック中',
									'affilicard'
								)}
							</Notice>
						)}
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
							label={__('外部 ID', 'affilicard')}
							value={row.external_id}
							placeholder={__('ストアの商品 ID', 'affilicard')}
							onChange={(v) => updateRow(i, { external_id: v })}
						/>
						<TextControl
							label={__('通常 URL', 'affilicard')}
							value={row.regular_url}
							placeholder={__(
								'https://example.com/item/123',
								'affilicard'
							)}
							onChange={(v) => updateRow(i, { regular_url: v })}
						/>
						<TextControl
							label={__('アフィリエイト URL', 'affilicard')}
							value={row.affiliate_url}
							placeholder={__(
								'https://al.example.com/item/123',
								'affilicard'
							)}
							onChange={(v) => updateRow(i, { affiliate_url: v })}
						/>
						<TextControl
							label={__('価格', 'affilicard')}
							value={row.price}
							placeholder={__('例: 660', 'affilicard')}
							onChange={(v) => updateRow(i, { price: v })}
						/>
						<TextControl
							label={__('参考価格', 'affilicard')}
							value={row.list_price}
							placeholder={__('例: 880', 'affilicard')}
							onChange={(v) => updateRow(i, { list_price: v })}
						/>
						<TextControl
							label={__('バッジ', 'affilicard')}
							value={row.badge}
							placeholder={__('例: 40%OFF', 'affilicard')}
							onChange={(v) => updateRow(i, { badge: v })}
						/>
						<TextControl
							label={__('画像 URL', 'affilicard')}
							value={row.image_url}
							placeholder={__(
								'https://example.com/cover.jpg',
								'affilicard'
							)}
							onChange={(v) => updateRow(i, { image_url: v })}
						/>
						<TextControl
							label={__('ボタンラベル上書き', 'affilicard')}
							value={row.button_label_override}
							placeholder={__('例: ○○で読む', 'affilicard')}
							onChange={(v) =>
								updateRow(i, { button_label_override: v })
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
