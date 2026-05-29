/**
 * Tests for src/Admin/components/ExtrasEditor.jsx
 */

import { render, screen, fireEvent } from '@testing-library/react';
import { ExtrasEditor } from '../../../src/Admin/components/ExtrasEditor';

describe( 'ExtrasEditor', () => {
	test( 'renders schema rows for ebook (著者/出版社/ISBN)', () => {
		render(
			<ExtrasEditor
				productType="ebook"
				extras={ [] }
				onChange={ () => {} }
			/>
		);
		expect( screen.getByText( '著者' ) ).toBeInTheDocument();
		expect( screen.getByText( '出版社' ) ).toBeInTheDocument();
		expect( screen.getByText( 'ISBN' ) ).toBeInTheDocument();
	} );

	test( 'renders no schema rows for generic product type', () => {
		render(
			<ExtrasEditor
				productType="generic"
				extras={ [] }
				onChange={ () => {} }
			/>
		);
		expect( screen.queryByText( '著者' ) ).not.toBeInTheDocument();
	} );

	test( 'editing schema row triggers onChange with key+label+value', () => {
		const onChange = jest.fn();
		render(
			<ExtrasEditor
				productType="ebook"
				extras={ [] }
				onChange={ onChange }
			/>
		);
		const authorLabel = screen.getByText( '著者' );
		const authorInput = authorLabel.querySelector( 'input' );
		fireEvent.change( authorInput, { target: { value: '山田太郎' } } );
		expect( onChange ).toHaveBeenCalledWith( [
			{ key: 'author', label: '著者', value: '山田太郎' },
		] );
	} );

	test( 'empty value for schema row drops the row from extras', () => {
		const onChange = jest.fn();
		render(
			<ExtrasEditor
				productType="ebook"
				extras={ [
					{ key: 'author', label: '著者', value: '山田太郎' },
				] }
				onChange={ onChange }
			/>
		);
		const authorLabel = screen.getByText( '著者' );
		const authorInput = authorLabel.querySelector( 'input' );
		fireEvent.change( authorInput, { target: { value: '' } } );
		expect( onChange ).toHaveBeenCalledWith( [] );
	} );

	test( '"カスタム項目を追加" appends an empty {label,value} row', () => {
		const onChange = jest.fn();
		render(
			<ExtrasEditor
				productType="ebook"
				extras={ [] }
				onChange={ onChange }
			/>
		);
		const addBtn = screen.getByRole( 'button', {
			name: 'カスタム項目を追加',
		} );
		fireEvent.click( addBtn );
		expect( onChange ).toHaveBeenCalledWith( [ { label: '', value: '' } ] );
	} );

	test( 'editing a custom row updates label and value', () => {
		const onChange = jest.fn();
		render(
			<ExtrasEditor
				productType="generic"
				extras={ [ { label: '色', value: '赤' } ] }
				onChange={ onChange }
			/>
		);
		// The TextControl mock renders <label>label<input/></label>, so the
		// label's own descendant input is the matching control.
		const labelLabelEl = screen.getByText( '項目名' );
		const labelInput = labelLabelEl.querySelector( 'input' );
		fireEvent.change( labelInput, { target: { value: 'カラー' } } );
		expect( onChange ).toHaveBeenCalledWith( [
			{ label: 'カラー', value: '赤' },
		] );

		onChange.mockClear();
		const valueLabelEl = screen.getByText( '値' );
		const valueInput = valueLabelEl.querySelector( 'input' );
		fireEvent.change( valueInput, { target: { value: '青' } } );
		expect( onChange ).toHaveBeenCalledWith( [
			{ label: '色', value: '青' },
		] );
	} );

	test( 'removing a custom row drops it from extras', () => {
		const onChange = jest.fn();
		render(
			<ExtrasEditor
				productType="generic"
				extras={ [
					{ label: '色', value: '赤' },
					{ label: 'サイズ', value: 'M' },
				] }
				onChange={ onChange }
			/>
		);
		const removeButtons = screen.getAllByRole( 'button', {
			name: '削除',
		} );
		fireEvent.click( removeButtons[ 0 ] );
		expect( onChange ).toHaveBeenCalledWith( [
			{ label: 'サイズ', value: 'M' },
		] );
	} );
} );
