import { useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Notice, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	fetchQueueStats,
	setPaused,
	clearQueue,
	deleteFailed,
	retryFailed,
	cancelPending,
} from '../api/queue';
import { fetchSettings, updateSettings } from '../api/settings';
import { providerLabel } from '../providers';

const SCHEDULED_ACTIONS_URL = 'tools.php?page=action-scheduler&s=affilicard';

export function QueuePanel() {
	const [stats, setStats] = useState(null);
	const [settings, setSettings] = useState(null);
	const [saving, setSaving] = useState(false);
	const [busy, setBusy] = useState(false);
	const [notice, setNotice] = useState(null);

	const loadStats = useCallback(
		() =>
			fetchQueueStats()
				.then(setStats)
				.catch(() => setStats({ summary: {}, depth: 0, paused: false })),
		[]
	);

	useEffect(() => {
		loadStats();
		fetchSettings()
			.then(setSettings)
			.catch(() => setSettings({}));
	}, [loadStats]);

	if (stats === null || settings === null) {
		return <p>{__('読み込み中…', 'affilicard')}</p>;
	}

	const providerCodes = Object.keys(stats.summary ?? {});

	const runAction = async (action, successMessage) => {
		setBusy(true);
		setNotice(null);
		try {
			await action();
			await loadStats();
			setNotice({ type: 'success', message: successMessage });
		} catch {
			setNotice({
				type: 'error',
				message: __('操作に失敗しました', 'affilicard'),
			});
		} finally {
			setBusy(false);
		}
	};

	const onTogglePause = async (checked) => {
		setBusy(true);
		setNotice(null);
		try {
			const result = await setPaused(checked);
			setStats({ ...stats, paused: Boolean(result?.paused) });
		} catch {
			setNotice({
				type: 'error',
				message: __('一時停止の切り替えに失敗しました', 'affilicard'),
			});
		} finally {
			setBusy(false);
		}
	};

	const onClearAll = () =>
		runAction(async () => {
			// REST の DELETE /refresh-queue は pending action のみを取り消すため、
			// 「全削除」を成立させるには failed action も別途 deleteFailed() で削除する。
			await clearQueue();
			await deleteFailed();
		}, __('キューを全て削除しました', 'affilicard'));

	const onDeleteFailed = () =>
		runAction(deleteFailed, __('失敗分を削除しました', 'affilicard'));

	const onRetryFailed = () =>
		runAction(retryFailed, __('失敗分を再試行しました', 'affilicard'));

	const onCancelPending = () =>
		runAction(cancelPending, __('未処理分をキャンセルしました', 'affilicard'));

	const updateSetting = (patch) => setSettings({ ...settings, ...patch });

	const updateThrottleOverride = (code, value) => {
		updateSetting({
			throttle_overrides: {
				...(settings.throttle_overrides ?? {}),
				[code]: parseInt(value, 10) || 0,
			},
		});
	};

	const onSaveSettings = async () => {
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

	return (
		<div className="affilicard-queue-panel">
			<h2>{__('更新キュー管理', 'affilicard')}</h2>
			{notice && (
				<Notice status={notice.type} onRemove={() => setNotice(null)}>
					{notice.message}
				</Notice>
			)}

			<div className="affilicard-queue-panel__section">
				<ToggleControl
					label={__('キューを一時停止する', 'affilicard')}
					checked={Boolean(stats.paused)}
					disabled={busy}
					onChange={onTogglePause}
				/>
				<p>{__('キューの深さ', 'affilicard')}: {stats.depth ?? 0}</p>

				{providerCodes.length === 0 && (
					<p className="description">
						{__(
							'自動更新中のプラットフォームはありません。',
							'affilicard'
						)}
					</p>
				)}

				{providerCodes.map((code) => {
					const row = stats.summary[code] ?? {};
					return (
						<div
							className="affilicard-queue-panel__provider-row"
							key={code}
						>
							<strong>{providerLabel(code)}</strong>
							<span>{__('未処理', 'affilicard')}: {row.pending ?? 0}</span>
							<span>{__('処理中', 'affilicard')}: {row.in_progress ?? 0}</span>
							<span>{__('失敗', 'affilicard')}: {row.failed ?? 0}</span>
							<TextControl
								label={`${providerLabel(code)} ${__(
									'throttle上書き (ms)',
									'affilicard'
								)}`}
								type="number"
								value={String(
									(settings.throttle_overrides ?? {})[code] ?? 0
								)}
								onChange={(v) => updateThrottleOverride(code, v)}
							/>
						</div>
					);
				})}
			</div>

			<div className="affilicard-queue-panel__section">
				<TextControl
					label={__('完了保持時間 (時間)', 'affilicard')}
					type="number"
					value={String(settings.retention_done_hours ?? 24)}
					onChange={(v) =>
						updateSetting({
							retention_done_hours: parseInt(v, 10) || 0,
						})
					}
				/>
				<TextControl
					label={__('失敗保持日数 (日)', 'affilicard')}
					type="number"
					value={String(settings.retention_failed_days ?? 7)}
					onChange={(v) =>
						updateSetting({
							retention_failed_days: parseInt(v, 10) || 0,
						})
					}
				/>
				<Button
					variant="primary"
					onClick={onSaveSettings}
					disabled={saving}
				>
					{saving
						? __('保存中…', 'affilicard')
						: __('保存', 'affilicard')}
				</Button>
			</div>

			<div className="affilicard-queue-panel__actions">
				<Button
					variant="secondary"
					disabled={busy}
					onClick={onCancelPending}
				>
					{__('未処理をキャンセル', 'affilicard')}
				</Button>
				<Button
					variant="secondary"
					disabled={busy}
					onClick={onRetryFailed}
				>
					{__('失敗分を再試行', 'affilicard')}
				</Button>
				<Button
					variant="secondary"
					isDestructive
					disabled={busy}
					onClick={onDeleteFailed}
				>
					{__('失敗分を削除', 'affilicard')}
				</Button>
				<Button
					variant="secondary"
					isDestructive
					disabled={busy}
					onClick={onClearAll}
				>
					{__('キューを全て削除', 'affilicard')}
				</Button>
			</div>

			<p>
				<a href={SCHEDULED_ACTIONS_URL}>
					{__('Scheduled Actions を開く（Tools）', 'affilicard')}
				</a>
			</p>
		</div>
	);
}
