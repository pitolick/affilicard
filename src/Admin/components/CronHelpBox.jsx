import { __ } from '@wordpress/i18n';

export function CronHelpBox() {
	const cmd = 'wp cron event run affilicard_refresh_listings';
	return (
		<div className="affilicard-cron-help notice notice-info inline">
			<p>
				{ __(
					'自動更新が有効です。WP-Cron が動作しない環境では、以下のコマンドを定期実行してください:',
					'affilicard'
				) }
			</p>
			<pre>
				<code>{ cmd }</code>
			</pre>
		</div>
	);
}
