jest.mock( '../../../src/Admin/api/platforms' );

import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { setEntityMeta, _reset } from '@wordpress/core-data';
import { fetchPlatforms } from '../../../src/Admin/api/platforms';
import { ProductSettingsPanel } from '../../../src/Admin/components/ProductSettingsPanel';

beforeEach( () => {
	_reset();
	fetchPlatforms.mockResolvedValue( [ { code: 'dmm-books', name: 'DMM Books' } ] );
} );

describe( 'ProductSettingsPanel', () => {
	test( 'JSON 文字列メタの listing をそのまま描画する', async () => {
		setEntityMeta( {
			affilicard_product_type: 'ebook',
			affilicard_stock_status: 'available',
			affilicard_listings: JSON.stringify( [ { platform: 'dmm-books', enabled: true, affiliate_url: 'https://a' } ] ),
			affilicard_extras: JSON.stringify( [] ),
		} );
		render( <ProductSettingsPanel /> );
		await waitFor( () => expect( fetchPlatforms ).toHaveBeenCalled() );
		expect( screen.getByDisplayValue( 'https://a' ) ).toBeInTheDocument();
		expect( screen.getByDisplayValue( '電子書籍' ) ).toBeInTheDocument();
	} );

	test( 'meta が未定義/非配列でも空配列で安全に描画する', async () => {
		setEntityMeta( { affilicard_listings: undefined, affilicard_extras: null } );
		render( <ProductSettingsPanel /> );
		await waitFor( () => expect( fetchPlatforms ).toHaveBeenCalled() );
		expect( screen.getByText( 'listing がありません' ) ).toBeInTheDocument();
	} );

	// 回帰防止: setMeta(object) で meta が更新され再レンダーされること。
	// 以前 setMeta を更新関数形式にしたところ useEntityProp が関数を解釈せず
	// listing 追加が反映されない不具合があった（E2E で発覚）。
	test( '「listing を追加」で listing 行が追加される', async () => {
		setEntityMeta( { affilicard_listings: JSON.stringify( [] ), affilicard_extras: JSON.stringify( [] ) } );
		render( <ProductSettingsPanel /> );
		await waitFor( () => expect( fetchPlatforms ).toHaveBeenCalled() );
		expect( screen.getByText( 'listing がありません' ) ).toBeInTheDocument();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'listing を追加' } )
		);
		// 行が追加され、プラットフォーム選択が描画される
		expect(
			await screen.findByLabelText( 'プラットフォーム' )
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'listing がありません' )
		).not.toBeInTheDocument();
	} );
} );
