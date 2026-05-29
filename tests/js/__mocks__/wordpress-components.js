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

module.exports = {
	__esModule: true,
	TextControl,
	ToggleControl,
	SelectControl,
	Button,
	Notice,
	TabPanel,
};
