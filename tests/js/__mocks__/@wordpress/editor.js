const React = require( 'react' );
const PluginDocumentSettingPanel = ( { title, children } ) =>
	React.createElement( 'section', { 'data-panel-title': title }, children );
module.exports = { __esModule: true, PluginDocumentSettingPanel };
