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

function BlockControls( { children } ) {
	return React.createElement( 'div', { 'data-slot': 'block-controls' }, children );
}

function useBlockProps( props = {} ) {
	// 実 WP の useBlockProps はブロック wrapper 用の props（ref/className 等）を返す。
	// テストでは「ルートに適用されたか」を検証できるよう data 属性を付与して返す。
	return { ...props, 'data-block-props': 'applied' };
}
useBlockProps.save = () => ( {} );

module.exports = { __esModule: true, InspectorControls, ColorPalette, BlockControls, useBlockProps };
