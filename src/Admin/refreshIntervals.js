import { __ } from '@wordpress/i18n';

// 全体更新間隔（一般設定）の候補プリセット（時間単位）。
export const REFRESH_INTERVAL_PRESETS = [1, 3, 6, 12, 24];

// currentHours がプリセットに無い場合（旧設定からの移行等）は選択肢へ追加して表示する。
export function refreshIntervalOptions(currentHours) {
	const options = [
		{ label: __('1時間毎', 'affilicard'), value: '1' },
		{ label: __('3時間毎', 'affilicard'), value: '3' },
		{ label: __('6時間毎', 'affilicard'), value: '6' },
		{ label: __('12時間毎', 'affilicard'), value: '12' },
		{ label: __('24時間毎', 'affilicard'), value: '24' },
	];
	if (!REFRESH_INTERVAL_PRESETS.includes(currentHours)) {
		options.push({
			label: `${currentHours}時間毎`,
			value: String(currentHours),
		});
	}
	return options;
}
