const React = require( 'react' );

function InspectorControls( { children } ) {
	return React.createElement( 'div', { 'data-slot': 'inspector' }, children );
}

function ColorPalette( { value, onChange } ) {
	return React.createElement( 'input', {
		'data-color-palette': true,
		value: value ?? '',
		onChange: ( e ) => onChange( e.target.value ),
	} );
}

function useBlockProps() {
	return {};
}
useBlockProps.save = () => ( {} );

module.exports = { __esModule: true, InspectorControls, ColorPalette, useBlockProps };
