/**
 * Lightweight stand-ins for @wordpress/components used in JSDOM tests.
 *
 * The real package renders heavy emotion + ariakit + SVG-icon stacks that
 * don't play nicely under JSDOM (and tripp the @wordpress/jest-console
 * unexpected-error assertion). We replace them with simple DOM-native
 * controls that preserve the props contract our components care about.
 */

const React = require( 'react' );

function TextControl( { label, value, onChange, type } ) {
	return React.createElement(
		'label',
		null,
		label,
		React.createElement( 'input', {
			type: type ?? 'text',
			value,
			onChange: ( e ) => onChange( e.target.value ),
		} )
	);
}

function ToggleControl( { label, checked, onChange } ) {
	return React.createElement(
		'label',
		null,
		label,
		React.createElement( 'input', {
			type: 'checkbox',
			checked: Boolean( checked ),
			onChange: ( e ) => onChange( e.target.checked ),
		} )
	);
}

function SelectControl( { label, value, options, onChange } ) {
	return React.createElement(
		'label',
		null,
		label,
		React.createElement(
			'select',
			{ value, onChange: ( e ) => onChange( e.target.value ) },
			options.map( ( o ) =>
				React.createElement(
					'option',
					{ key: o.value, value: o.value },
					o.label
				)
			)
		)
	);
}

function Button( { children, onClick, disabled } ) {
	return React.createElement(
		'button',
		{ onClick, disabled, type: 'button' },
		children
	);
}

function Notice( { children, status } ) {
	return React.createElement(
		'div',
		{ role: 'alert', 'data-status': status },
		children
	);
}

function TabPanel( { tabs, children, className } ) {
	const [ active, setActive ] = React.useState( tabs[ 0 ]?.name );
	const activeTab = tabs.find( ( t ) => t.name === active ) ?? tabs[ 0 ];
	return React.createElement(
		'div',
		{ className },
		React.createElement(
			'div',
			{ role: 'tablist' },
			tabs.map( ( t ) =>
				React.createElement(
					'button',
					{
						key: t.name,
						role: 'tab',
						type: 'button',
						'data-tab-name': t.name,
						'aria-selected': t.name === active,
						onClick: () => setActive( t.name ),
					},
					t.title
				)
			)
		),
		React.createElement(
			'div',
			{ role: 'tabpanel' },
			typeof children === 'function' ? children( activeTab ) : children
		)
	);
}

function ComboboxControl( { label, options, onChange, onFilterValueChange, value, __experimentalRenderItem } ) {
	return React.createElement(
		'label',
		null,
		label,
		React.createElement( 'input', {
			'aria-label': label,
			value: value ?? '',
			onChange: ( e ) => {
				if ( onFilterValueChange ) {
					onFilterValueChange( e.target.value );
				}
			},
		} ),
		React.createElement(
			'ul',
			null,
			( options || [] ).map( ( o ) =>
				React.createElement(
					'li',
					{ key: o.value },
					React.createElement(
						'button',
						{ type: 'button', onClick: () => onChange( o.value ) },
						typeof __experimentalRenderItem === 'function'
							? __experimentalRenderItem( { item: o } )
							: o.label
					)
				)
			)
		)
	);
}

function PanelBody( { title, children, initialOpen } ) {
	// テストでは折りたたみ挙動を再現せず子を常に描画する（折りたたみは WP 実装で E2E 検証）。
	// タイトルは getByText で検出できるよう可視テキストとして出す。
	return React.createElement(
		'section',
		{ 'data-panel': title, 'data-initial-open': initialOpen ? 'true' : 'false' },
		React.createElement( 'h3', { className: 'components-panel__body-title' }, title ),
		children
	);
}

function Panel( { children, className } ) {
	return React.createElement( 'div', { className, 'data-panel-container': 'true' }, children );
}

function BaseControl( { label, children } ) {
	return React.createElement( 'div', null, label, children );
}

function ToolbarGroup( { children } ) {
	return React.createElement( 'div', { 'data-toolbar-group': true }, children );
}

function ToolbarButton( { children, onClick } ) {
	return React.createElement(
		'button',
		{ type: 'button', 'data-toolbar-button': true, onClick },
		children
	);
}

function Spinner() {
	return React.createElement( 'span', { 'data-spinner': true, 'aria-label': 'loading' } );
}

function CheckboxControl( { label, checked, onChange } ) {
	return React.createElement(
		'label',
		null,
		label,
		React.createElement( 'input', {
			type: 'checkbox',
			checked: Boolean( checked ),
			onChange: ( e ) => onChange( e.target.checked ),
		} )
	);
}

module.exports = {
	__esModule: true,
	TextControl,
	ToggleControl,
	CheckboxControl,
	SelectControl,
	Button,
	Notice,
	TabPanel,
	ComboboxControl,
	PanelBody,
	Panel,
	BaseControl,
	ToolbarGroup,
	ToolbarButton,
	Spinner,
};
