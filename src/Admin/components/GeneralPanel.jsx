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

export function GeneralPanel() {
	const [settings, setSettings] = useState(null);
	const [saving, setSaving] = useState(false);
	const [notice, setNotice] = useState(null);
	// false | 'normal' | 'force' — どちらの一括更新ボタンが実行中かを追跡する。
	const [refreshing, setRefreshing] = useState(false);

	useEffect(() => {
		fetchSettings()
			.then(setSettings)
			.catch(() => setSettings({}));
	}, []);

	if (settings === null) {
		return <p>{__('読み込み中…', 'affilicard')}</p>;
	}

	const update = (patch) => setSettings({ ...settings, ...patch });

	const onSave = async () => {
		setSaving(true);
		setNotice(null);
		try {
			const next = await updateSettings(settings);
			setSettings(next);
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
					checked={Boolean(settings.cron_enabled)}
					onChange={(v) => update({ cron_enabled: v })}
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

				{settings.cron_enabled && <CronHelpBox />}

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
