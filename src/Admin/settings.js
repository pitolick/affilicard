import { createRoot, createElement } from '@wordpress/element';
import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { GeneralPanel } from './components/GeneralPanel';
import { PlatformsPanel } from './components/PlatformsPanel';

export function SettingsApp() {
  return (
    <TabPanel
      className="affilicard-settings-tabs"
      activeClass="is-active"
      tabs={[
        { name: 'general', title: __('一般', 'affilicard') },
        {
          name: 'platforms',
          title: __('プラットフォーム', 'affilicard'),
        },
      ]}
    >
      {(tab) => (tab.name === 'general' ? <GeneralPanel /> : <PlatformsPanel />)}
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
