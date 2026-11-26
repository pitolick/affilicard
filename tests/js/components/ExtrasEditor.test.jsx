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
	test( 'renders vod schema rows from the injected types', () => {
		render(
			<ExtrasEditor
				productType="vod"
				extras={ [] }
				onChange={ () => {} }
			/>
		);
		expect( screen.getByText( '監督' ) ).toBeInTheDocument();
		expect( screen.getByText( '配給' ) ).toBeInTheDocument();
	} );

	test( '今のタイプに無い key の行はカスタム項目として編集できる', () => {
		const onChange = jest.fn();
		render(
			<ExtrasEditor
				productType="vod"
				extras={ [
					{ key: 'author', label: '著者', value: '山田太郎' },
					{ key: 'director', label: '監督', value: '監督A' },
				] }
				onChange={ onChange }
			/>
		);
		// 電子書籍の「著者」は動画配信の推奨項目に無いので、カスタム項目の行に出る。
		expect( screen.getByDisplayValue( '著者' ) ).toBeInTheDocument();
		expect( screen.getByDisplayValue( '山田太郎' ) ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: '削除' } ) );
		expect( onChange ).toHaveBeenLastCalledWith( [
			{ key: 'director', label: '監督', value: '監督A' },
		] );
	} );

	test( 'key の残った行を編集しても key は保つ', () => {
		const onChange = jest.fn();
		render(
			<ExtrasEditor
				productType="generic"
				extras={ [ { key: 'author', label: '著者', value: '山田太郎' } ] }
				onChange={ onChange }
			/>
		);
		fireEvent.change( screen.getByDisplayValue( '山田太郎' ), {
			target: { value: '山田花子' },
		} );
		expect( onChange ).toHaveBeenLastCalledWith( [
			{ key: 'author', label: '著者', value: '山田花子' },
		] );
	} );
} );
