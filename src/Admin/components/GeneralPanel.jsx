import { useEffect, useState } from '@wordpress/element';
import {
	TextControl,
	ToggleControl,
	SelectControl,
	Button,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { fetchSettings, updateSettings } from '../api/settings';
import { triggerRefresh } from '../api/refresh';
import { refreshIntervalOptions } from '../refreshIntervals';
import { CronHelpBox } from './CronHelpBox';

// 運用ドキュメント（WP-Cron のままで足りるかの判断基準／サーバー cron への移行手順）
// への導線。QueuePanel.jsx の警告 Notice と同じリンク先。
const OPERATIONS_DOC_URL =
	'https://github.com/pitolick/affilicard/blob/main/docs/operations-refresh-queue.md';

// 棚卸し期間 (日) の確定値を計算する（GeneralSettings::update() の max(1, ...) と同じ
// クランプをブラウザ側でも行う）。1 未満・非数値は 1 にフォールバックする。
// 呼び出すのは blur・保存などの「確定」タイミングのみ。編集中の入力欄には適用しない
// （即時クランプすると「全選択して消す→入力する」という通常の編集操作が壊れ、消した瞬間に
// 1 が入り込んで続く入力と連結されてしまう。CodeRabbit 指摘）。
function clampStocktakeDays(raw) {
	const days = parseInt(raw, 10);
	return Number.isFinite(days) && days >= 1 ? days : 1;
}

export function GeneralPanel() {
	const [settings, setSettings] = useState(null);
	const [saving, setSaving] = useState(false);
	const [notice, setNotice] = useState(null);
	// false | 'normal' | 'force' — どちらの一括更新ボタンが実行中かを追跡する。
	const [refreshing, setRefreshing] = useState(false);
	// 棚卸し期間入力欄の編集中ドラフト。null なら未編集（settings の値を表示）、
	// 文字列ならその生の入力値を表示する（空文字・1 未満も含め、確定までクランプしない）。
	const [stocktakeDaysDraft, setStocktakeDaysDraft] = useState(null);

	useEffect(() => {
		fetchSettings()
			.then(setSettings)
			.catch(() => setSettings({}));
	}, []);

	if (settings === null) {
		return <p>{__('読み込み中…', 'affilicard')}</p>;
	}

	const update = (patch) => setSettings({ ...settings, ...patch });

	// GeneralSettings::DEFAULTS（PHP 側）の既定値と揃える。fetchSettings が失敗すると
	// settings は {} になるため、`Boolean(settings.x)` のようなフォールバック無しの読み出しは
	// サーバー既定が true のトグルを誤って false 表示してしまう（CodeRabbit 指摘）。
	const cronEnabled = settings.cron_enabled ?? true;
	const stocktakeEnabled = settings.stocktake_enabled ?? true;

	const stocktakeDaysValue =
		stocktakeDaysDraft !== null
			? stocktakeDaysDraft
			: String(settings.stocktake_days ?? 180);

	const commitStocktakeDays = () => {
		if (stocktakeDaysDraft === null) {
			return;
		}
		update({ stocktake_days: clampStocktakeDays(stocktakeDaysDraft) });
		setStocktakeDaysDraft(null);
	};

	const onSave = async () => {
		setSaving(true);
		setNotice(null);
		// blur を経ずに保存された場合（ドラフトが残ったまま）も確定時クランプを適用する。
		// setStocktakeDaysDraft は非同期なので、送信するペイロードはここで直接組み立てる。
		const payload =
			stocktakeDaysDraft !== null
				? {
						...settings,
						stocktake_days: clampStocktakeDays(stocktakeDaysDraft),
					}
				: settings;
		try {
			const next = await updateSettings(payload);
			setSettings(next);
			setStocktakeDaysDraft(null);
			setNotice({
				type: 'success',
				message: __('保存しました', 'affilicard'),
			});
		} catch {
			setNotice({
				type: 'error',
				message: __('保存に失敗しました', 'affilicard'),
			});
		} finally {
			setSaving(false);
		}
	};

	const onBulkRefresh = async (force) => {
		setRefreshing(force ? 'force' : 'normal');
		setNotice(null);
		try {
			await triggerRefresh(null, force);
			setNotice({
				type: 'success',
				message: __(
					'価格更新を実行しました。反映結果は各商品の価格・「最終同期」でご確認ください。',
					'affilicard'
				),
			});
		} catch {
			setNotice({
				type: 'error',
				message: __('価格更新の実行に失敗しました。', 'affilicard'),
			});
		} finally {
			setRefreshing(false);
		}
	};

	return (
		<div className="affilicard-general-panel">
			<h2>{__('一般設定', 'affilicard')}</h2>
			{notice && (
				<Notice status={notice.type} onRemove={() => setNotice(null)}>
					{notice.message}
				</Notice>
			)}

			<div className="affilicard-general-panel__section">
				<TextControl
					label={__('キャッシュ TTL (秒)', 'affilicard')}
					type="number"
					value={String(settings.cache_ttl_seconds ?? 86400)}
					onChange={(v) =>
						update({ cache_ttl_seconds: parseInt(v, 10) || 0 })
					}
				/>

				<SelectControl
					label={__('デフォルト商品タイプ', 'affilicard')}
					value={settings.default_product_type ?? 'generic'}
					options={[
						{ label: '汎用', value: 'generic' },
						{ label: '電子書籍', value: 'ebook' },
					]}
					onChange={(v) => update({ default_product_type: v })}
				/>

				<ToggleControl
					label={__('自動更新を有効化 (WP-Cron)', 'affilicard')}
					checked={cronEnabled}
					onChange={(v) => update({ cron_enabled: v })}
					help={
						<>
							{__(
								'WP-Cron のままで足りるか、サーバー cron へ移行すべきかの判断基準は運用ドキュメントを参照してください。',
								'affilicard'
							)}{' '}
							<a
								href={OPERATIONS_DOC_URL}
								target="_blank"
								rel="noreferrer"
							>
								{__('運用ドキュメントを見る', 'affilicard')}
							</a>
						</>
					}
				/>

				<SelectControl
					label={__('更新間隔（時間毎）', 'affilicard')}
					value={String(settings.refresh_interval_hours ?? 3)}
					options={refreshIntervalOptions(
						settings.refresh_interval_hours ?? 3
					)}
					onChange={(v) =>
						update({ refresh_interval_hours: parseInt(v, 10) || 3 })
					}
					help={__(
						'価格の自動更新をこの間隔で行います（全プラットフォーム共通）。価格は取得から24時間で自動的に非表示になるため、24時間より短い間隔を推奨します。',
						'affilicard'
					)}
				/>

				{cronEnabled && <CronHelpBox />}

				<ToggleControl
					label={__('棚卸しを有効化', 'affilicard')}
					checked={stocktakeEnabled}
					onChange={(v) => update({ stocktake_enabled: v })}
					help={__(
						'記事に掲載されなくなって指定日数が過ぎた商品の自動更新を止めます。記事を更新すれば対象に戻ります。',
						'affilicard'
					)}
				/>

				<TextControl
					label={__('棚卸し期間 (日)', 'affilicard')}
					type="number"
					min="1"
					value={stocktakeDaysValue}
					onChange={(v) => setStocktakeDaysDraft(v)}
					onBlur={commitStocktakeDays}
					help={__(
						'記事に掲載されなくなってこの日数が過ぎた商品を、自動更新の対象から外します（既定 180 日・最小 1 日）。記事を更新すれば対象に戻ります。',
						'affilicard'
					)}
				/>

				<ToggleControl
					label={__('商品画像を表示しない', 'affilicard')}
					checked={Boolean(settings.hide_product_images)}
					onChange={(v) => update({ hide_product_images: v })}
					help={__(
						'すべてのカードから商品画像を描画しません。画像の枠ごと畳んで本文を全幅にします。',
						'affilicard'
					)}
				/>
			</div>

			<div className="affilicard-general-panel__actions">
				<Button
					variant="secondary"
					disabled={Boolean(refreshing)}
					onClick={() => onBulkRefresh(false)}
				>
					{refreshing === 'normal'
						? __('更新中…', 'affilicard')
						: __('一括更新', 'affilicard')}
				</Button>
				<Button
					variant="secondary"
					isDestructive
					disabled={Boolean(refreshing)}
					onClick={() => onBulkRefresh(true)}
				>
					{refreshing === 'force'
						? __('更新中…', 'affilicard')
						: __(
								'強制一括更新（自動更新 OFF も含む）',
								'affilicard'
						  )}
				</Button>

				<Button variant="primary" onClick={onSave} disabled={saving}>
					{saving
						? __('保存中…', 'affilicard')
						: __('保存', 'affilicard')}
				</Button>
			</div>
		</div>
	);
}
