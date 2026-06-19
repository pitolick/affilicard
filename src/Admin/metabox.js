import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { ProductSettingsPanel } from './components/ProductSettingsPanel';

registerPlugin('affilicard-product-settings', {
	render: () => (
		<PluginDocumentSettingPanel
			name="affilicard-product-settings"
			title={__('Affilicard 商品設定', 'affilicard')}
			className="affilicard-product-settings-panel"
		>
			<ProductSettingsPanel />
		</PluginDocumentSettingPanel>
	),
});
