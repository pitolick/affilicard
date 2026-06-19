jest.mock( '../../../src/Admin/api/platforms' );

import { render, screen, waitFor } from '@testing-library/react';
import { setEntityMeta, _reset } from '@wordpress/core-data';
import { fetchPlatforms } from '../../../src/Admin/api/platforms';
import { ProductSettingsPanel } from '../../../src/Admin/components/ProductSettingsPanel';

beforeEach( () => {
	_reset();
	fetchPlatforms.mockResolvedValue( [ { code: 'dmm-books', name: 'DMM Books' } ] );
} );

describe( 'ProductSettingsPanel', () => {
	test( '配列メタの listing をそのまま描画する', async () => {
		setEntityMeta( {
			affilicard_product_type: 'ebook',
			affilicard_stock_status: 'available',
			affilicard_listings: [ { platform: 'dmm-books', enabled: true, affiliate_url: 'https://a' } ],
			affilicard_extras: [],
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
} );
