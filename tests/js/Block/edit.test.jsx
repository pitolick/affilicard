/**
 * Tests for src/Block/edit.jsx
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { Edit } from '../../../src/Block/edit';
import * as productsApi from '../../../src/Admin/api/products';

jest.mock( '../../../src/Admin/api/products' );

describe( 'Edit', () => {
	beforeEach( () => {
		productsApi.searchProducts.mockResolvedValue( [
			{ id: 7, title: 'サンプル漫画 1巻', status: 'publish' },
		] );
		productsApi.getProduct.mockResolvedValue( {
			id: 7,
			title: 'サンプル漫画 1巻',
		} );
	} );

	const setup = ( attributes = {} ) => {
		const setAttributes = jest.fn();
		render(
			<Edit attributes={ attributes } setAttributes={ setAttributes } />
		);
		return { setAttributes };
	};

	test( 'shows combobox when no product selected', () => {
		setup();
		expect(
			screen.getByLabelText( '商品を検索', { selector: 'input' } )
		).toBeInTheDocument();
	} );

	test( 'searches products on filter change', async () => {
		setup();
		fireEvent.change(
			screen.getByLabelText( '商品を検索', { selector: 'input' } ),
			{ target: { value: 'サンプル' } }
		);
		await waitFor( () =>
			expect( productsApi.searchProducts ).toHaveBeenCalledWith(
				expect.objectContaining( { search: 'サンプル' } )
			)
		);
	} );

	test( 'sets productId attribute on selection', async () => {
		const { setAttributes } = setup();
		fireEvent.change(
			screen.getByLabelText( '商品を検索', { selector: 'input' } ),
			{ target: { value: 'サンプル' } }
		);
		const option = await screen.findByText( /サンプル漫画 1巻/ );
		fireEvent.click( option );
		expect( setAttributes ).toHaveBeenCalledWith( { productId: 7 } );
	} );

	test( 'shows selected product title when productId set', async () => {
		setup( { productId: 7 } );
		expect( await screen.findByText( /サンプル漫画 1巻/ ) ).toBeInTheDocument();
	} );

	test( 'renders color palette controls in inspector', async () => {
		setup( { productId: 7 } );
		// Await getProduct resolution to avoid act() warnings.
		await screen.findByText( /サンプル漫画 1巻/ );
		expect( screen.getAllByText( /色/ ).length ).toBeGreaterThan( 0 );
	} );

	test( 'updates ctaBgColor via color palette', async () => {
		const { setAttributes } = setup( { productId: 7 } );
		// Await getProduct resolution to avoid act() warnings.
		await screen.findByText( /サンプル漫画 1巻/ );
		const palettes = document.querySelectorAll( '[data-color-palette]' );
		fireEvent.change( palettes[ 0 ], { target: { value: '#ff0000' } } );
		expect( setAttributes ).toHaveBeenCalledWith(
			expect.objectContaining( { ctaBgColor: '#ff0000' } )
		);
	} );
} );
