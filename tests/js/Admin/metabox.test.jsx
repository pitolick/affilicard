/**
 * metabox.js エントリは商品設定サイドバーパネルを registerPlugin で登録する。
 */

describe( 'metabox エントリ', () => {
	test( 'affilicard-product-settings プラグインを登録する', () => {
		let registerPlugin;
		jest.isolateModules( () => {
			( { registerPlugin } = require( '@wordpress/plugins' ) );
			require( '../../../src/Admin/metabox' );
		} );
		expect( registerPlugin ).toHaveBeenCalledWith(
			'affilicard-product-settings',
			expect.objectContaining( { render: expect.any( Function ) } )
		);
	} );
} );
