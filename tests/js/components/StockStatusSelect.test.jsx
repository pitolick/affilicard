/**
 * Tests for src/Admin/components/StockStatusSelect.jsx
 */

import { render, screen, fireEvent } from '@testing-library/react';
import { StockStatusSelect } from '../../../src/Admin/components/StockStatusSelect';

describe( 'StockStatusSelect', () => {
	test( 'renders 3 stock-status options', () => {
		render( <StockStatusSelect value="available" onChange={ () => {} } /> );
		expect( screen.getByText( '販売中' ) ).toBeInTheDocument();
		expect( screen.getByText( '在庫切れ' ) ).toBeInTheDocument();
		expect( screen.getByText( '取扱終了' ) ).toBeInTheDocument();
	} );

	test( 'defaults to "available" when value is undefined', () => {
		render( <StockStatusSelect onChange={ () => {} } /> );
		const select = screen.getByRole( 'combobox' );
		expect( select.value ).toBe( 'available' );
	} );

	test( 'calls onChange when SelectControl emits a new value', () => {
		const onChange = jest.fn();
		render( <StockStatusSelect value="available" onChange={ onChange } /> );
		const select = screen.getByRole( 'combobox' );
		fireEvent.change( select, { target: { value: 'out_of_stock' } } );
		expect( onChange ).toHaveBeenCalledWith( 'out_of_stock' );
	} );
} );
