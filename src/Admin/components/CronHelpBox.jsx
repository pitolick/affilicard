import { __ } from '@wordpress/i18n';

// 掃引の起点となる WP-Cron イベント（RefreshScheduler::HOOK_ALL 相当）。
// 旧実装は実在しない `affilicard_refresh_listings` を案内していた不具合の修正。
const SWEEP_EVENT_CMD = 'wp cron event run affilicard_refresh_all';
// 積まれたキュージョブ（価格取得）を実際に実行する Action Scheduler ランナー。
// 掃引イベントの発火だけでは価格が取得されないため、両方が必要
// （docs/operations-refresh-queue.md §2-1 参照）。
const QUEUE_RUNNER_CMD = 'wp action-scheduler run --batches=1';

export function CronHelpBox() {
	return (
		<div className="affilicard-cron-help notice notice-info inline">
			<p>
				{__(
					'自動更新が有効です。WP-Cron が動作しない環境では、以下の 2 つのコマンドを両方とも定期実行してください（掃引イベントの発火とキューの実行のどちらか一方だけでは継続更新が回りません）:',
					'affilicard'
				)}
			</p>
			<pre>
				<code>{SWEEP_EVENT_CMD}</code>
			</pre>
			<pre>
				<code>{QUEUE_RUNNER_CMD}</code>
			</pre>
		</div>
	);
}
