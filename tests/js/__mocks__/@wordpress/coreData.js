/**
 * @wordpress/core-data の useEntityProp 最小モック（実挙動に忠実）。
 *
 * 実際の useEntityProp の setter は React の setState と異なり「更新関数」を
 * 受け付けず、editEntityRecord に渡した値をそのまま反映する。モックでも
 * 関数を渡されたら例外を投げて検出する（テストで誤用を捕捉するため）。
 * setValue は React state を更新するので、setMeta 後に再レンダーが起きる。
 */
const React = require( 'react' );

let initialMeta = {};
let lastSetterCall = null;

const setEntityMeta = ( meta ) => {
	initialMeta = { ...meta };
};

const useEntityProp = ( kind, name, prop ) => {
	const [ meta, setMetaState ] = React.useState(
		prop === 'meta' ? initialMeta : undefined
	);
	const setValue = ( next ) => {
		if ( typeof next === 'function' ) {
			throw new Error(
				'useEntityProp setter does not accept updater functions'
			);
		}
		lastSetterCall = next;
		setMetaState( ( prev ) => ( { ...( prev || {} ), ...next } ) );
	};
	return [ meta, setValue ];
};

module.exports = {
	__esModule: true,
	useEntityProp,
	setEntityMeta,
	_reset: () => {
		initialMeta = {};
		lastSetterCall = null;
	},
	getLastSetterCall: () => lastSetterCall,
	clearLastSetterCall: () => {
		lastSetterCall = null;
	},
};
