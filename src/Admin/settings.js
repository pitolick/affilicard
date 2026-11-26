import { createRoot, createElement } from '@wordpress/element';
import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { GeneralPanel } from './components/GeneralPanel';
import { PlatformsPanel } from './components/PlatformsPanel';
import { QueuePanel } from './components/QueuePanel';

const TAB_NAMES = ['general', 'platforms', 'queue'];

/**
 * URL の `tab` で最初に開くタブを選ぶ（管理画面の通知から「更新キュー」へ直接飛べるように）。
 * 知らない値・未指定なら undefined（TabPanel の既定＝先頭のタブ）。
 *
 * @param {string} search location.search
 * @return {string|undefined} タブ名
 */
export function initialTabFromSearch(search) {
	const tab = new URLSearchParams(search).get('tab');
	return TAB_NAMES.includes(tab) ? tab : undefined;
}

export function SettingsApp() {
	return (
		<TabPanel
			className="affilicard-settings-tabs"
			activeClass="is-active"
			initialTabName={initialTabFromSearch(window.location.search)}
			tabs={[
				{ name: 'general', title: __('一般', 'affilicard') },
				{
					name: 'platforms',
					title: __('プラットフォーム', 'affilicard'),
				},
				{ name: 'queue', title: __('更新キュー', 'affilicard') },
			]}
		>
			{(tab) => {
				if (tab.name === 'platforms') {
					return <PlatformsPanel />;
				}
				if (tab.name === 'queue') {
					return <QueuePanel />;
				}
				return <GeneralPanel />;
			}}
		</TabPanel>
	);
}

document.addEventListener('DOMContentLoaded', () => {
	const root = document.getElementById('affilicard-settings-root');
	if (!root) {
		return;
	}
	if (typeof createRoot === 'function') {
		createRoot(root).render(createElement(SettingsApp));
	}
});
