let store = {};
const setEntityMeta = ( meta ) => {
	store = { ...meta };
};
const useEntityProp = ( kind, name, prop ) => {
	const value = prop === 'meta' ? store : undefined;
	const setValue = ( next ) => {
		store = typeof next === 'function' ? next( store ) : { ...store, ...next };
	};
	return [ value, setValue ];
};
module.exports = { __esModule: true, useEntityProp, setEntityMeta, _reset: () => ( store = {} ) };
