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

// affilicard 自身のサブメニュー（更新キュー・ジョブ一覧。QueueJobsPage が
// Action Scheduler の一覧を affilicard-{account} スコープ・日本語で埋め込む）。
// v2.4.0 Phase2 §11-3 で Tools > Scheduled Actions への直リンクから置き換えた。
const QUEUE_JOBS_URL =
	'edit.php?post_type=affilicard_product&page=affilicard-queue-jobs';

export function QueuePanel() {
	const [stats, setStats] = useState(null);
	const [settings, setSettings] = useState(null);
	const [saving, setSaving] = useState(false);
	const [busy, setBusy] = useState(false);
	const [notice, setNotice] = useState(null);
	const [statsError, setStatsError] = useState(false);

	// loadStats は失敗を握り潰さない。以前は catch で空 stats（summary:{},depth:0）へ
	// 差し替えていたため、統計の再取得に失敗しても「成功／空キュー」に見え、runAction が
	// 誤って成功通知を出していた（CodeRabbit 指摘）。ここでは statsError を立てて再 throw し、
	// 呼び出し側（runAction / 初回 useEffect）が失敗を検知できるようにする。
	// 初回に stats が未取得のまま throw すると画面が「読み込み中…」で固まるため、
	// 既存 stats が無い場合のみ空 stats を暫定描画するが、statsError の Notice で失敗を明示する。
	const loadStats = useCallback(
		() =>
			fetchQueueStats()
				.then((data) => {
					setStats(data);
					setStatsError(false);
				})
				.catch((error) => {
					setStats(
						(prev) => prev ?? { summary: {}, depth: 0, paused: false }
					);
					setStatsError(true);
					throw error;
				}),
		[]
	);

	useEffect(() => {
		loadStats().catch(() => {
			// 初回ロード失敗は statsError の Notice で表示するため、ここでは
			// unhandled rejection を防ぐためだけに握る。
		});
		fetchSettings()
			.then(setSettings)
			.catch(() => setSettings({}));
	}, [loadStats]);

	if (stats === null || settings === null) {
		return <p>{__('読み込み中…', 'affilicard')}</p>;
	}

	// v2.4.0: サマリは account コード（'rakuten'/'dmm' 等）単位で REST から返る。
	// 各 account の表示ラベルも REST payload（summary[code].label）に含まれるため、
	// JS 側で account コード→ラベルの対応表をハードコードする必要はない。
	const accountCodes = Object.keys(stats.summary ?? {});

	const runAction = async (action, successMessage) => {
		setBusy(true);
		setNotice(null);
		try {
			await action();
		} catch {
			setNotice({
				type: 'error',
				message: __('操作に失敗しました', 'affilicard'),
			});
			setBusy(false);
			return;
		}

		// 操作自体は成功したので、続けて統計を再取得する。ここが失敗した場合は
		// 「成功」と言い切らず（キュー状態が最新かどうか担保できない）、再取得失敗を通知する。
		try {
			await loadStats();
			setNotice({ type: 'success', message: successMessage });
		} catch {
			setNotice({
				type: 'error',
				message: __(
					'操作は実行しましたが、最新のキュー状態の取得に失敗しました。画面を再読み込みしてください。',
					'affilicard'
				),
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
		runAction(
			async () => {
				// REST の DELETE /refresh-queue は pending action のみを取り消すため、
				// 「全削除」を成立させるには failed action も別途 deleteFailed() で削除する。
				await clearQueue();
				await deleteFailed();
			},
			// clearQueue()=pending の取消 + deleteFailed()=失敗の削除のみ。実行中（in-progress）の
			// ジョブは Action Scheduler の性質上クリーンに停止できないため残る。以前の
			// 「キューを全て削除しました」は実態と不一致（CodeRabbit 指摘）なので正確な文言にする。
			__(
				'未処理と失敗のジョブを削除しました（実行中のジョブは完了まで動きます）',
				'affilicard'
			)
		);

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
			// queue_paused は /pause 専用エンドポイント（setPaused）が単独で所有する。
			// settings フォームの保存に含めると、mount 時に取得した古い
			// queue_paused がサーバー側の array_merge で勝ってしまい、
			// pause 状態を意図せず revert させてしまうため除外する。
			const { queue_paused, ...payload } = settings;
			const next = await updateSettings(payload);
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
			{statsError && (
				<Notice status="error" isDismissible={false}>
					{__(
						'キュー状態の取得に失敗しました。表示が最新でない可能性があります。',
						'affilicard'
					)}
				</Notice>
			)}
			{notice && (
				<Notice status={notice.type} onRemove={() => setNotice(null)}>
					{notice.message}
				</Notice>
			)}

			<div className="affilicard-queue-panel__section">
				<ToggleControl
					label={__('キューを一時停止する', 'affilicard')}
					help={__(
						'オンにすると価格更新の処理（ワーカー）を止めます。新規ジョブはキューに積まれ続け、解除すると溜まった分から処理を再開します。API 障害・レート制限の一時回避に使います。',
						'affilicard'
					)}
					checked={Boolean(stats.paused)}
					disabled={busy}
					onChange={onTogglePause}
				/>
				<p>
					{__('キューの深さ', 'affilicard')}: {stats.depth ?? 0}
				</p>
				<p className="description">
					{__(
						'キューに溜まっている未処理（pending）ジョブの総数です。実処理はサーバの cron / Action Scheduler ランナーが順次進めます。',
						'affilicard'
					)}
				</p>

				{accountCodes.length === 0 && (
					<p className="description">
						{__(
							'自動更新中のプラットフォームはありません。',
							'affilicard'
						)}
					</p>
				)}

				{accountCodes.map((code) => {
					const row = stats.summary[code] ?? {};
					const label = row.label ?? code;
					return (
						<div
							className="affilicard-queue-panel__provider-row"
							key={code}
						>
							<strong>{label}</strong>
							<span>{__('未処理', 'affilicard')}: {row.pending ?? 0}</span>
							<span>{__('処理中', 'affilicard')}: {row.in_progress ?? 0}</span>
							<span>{__('失敗', 'affilicard')}: {row.failed ?? 0}</span>
							<span>{__('完了', 'affilicard')}: {row.complete ?? 0}</span>
							<TextControl
								label={`${label} ${__(
									'throttle上書き (ms)',
									'affilicard'
								)}`}
								help={__(
									'このアカウントの API への最小リクエスト間隔（ミリ秒）。0 で既定値。429（レート制限）が出る場合に大きくすると失敗が減りますが、全体の更新は遅くなります。',
									'affilicard'
								)}
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
					help={__(
						'完了したジョブ履歴を Action Scheduler に残す時間。超過分は自動削除されます（既定 24 時間）。',
						'affilicard'
					)}
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
					help={__(
						'失敗したジョブ履歴を残す日数。超過分は自動削除されます（既定 7 日）。原因調査のため完了より長めに保持します。',
						'affilicard'
					)}
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
					{__('未処理と失敗を削除', 'affilicard')}
				</Button>
			</div>

			<p>
				<a href={QUEUE_JOBS_URL}>
					{__('更新キュー（ジョブ一覧）を開く', 'affilicard')}
				</a>
			</p>
		</div>
	);
}
