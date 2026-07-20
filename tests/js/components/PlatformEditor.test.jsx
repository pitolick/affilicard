/**
 * Tests for src/Admin/components/PlatformEditor.jsx
 */

import { render, screen, fireEvent } from '@testing-library/react';
import { PlatformEditor } from '../../../src/Admin/components/PlatformEditor';

const basePlatform = {
	code: 'dmm',
	name: 'DMM',
	provider: 'manual',
	enabled: true,
	displayOrder: 1,
	imagePriority: 10,
	applicableTypes: [ 'ebook' ],
	buttonLabel: '購入',
	brandColor: '#444444',
	buttonTextColor: '#ffffff',
};

describe( 'PlatformEditor', () => {
	afterEach( () => {
		delete window.affilicardProviders;
		jest.resetModules();
	} );

	test( 'Provider ドロップダウンは manual＋現在選択中の自動 provider のみを出す', () => {
		jest.resetModules();
		window.affilicardProviders = [
			{
				code: 'manual',
				label: '手動入力',
				isAutomatic: false,
				accountCode: null,
			},
			{
				code: 'dmm-ebook',
				label: 'DMM API',
				isAutomatic: true,
				accountCode: 'dmm',
			},
			{
				code: 'rakuten-kobo',
				label: '楽天Kobo API',
				isAutomatic: true,
				accountCode: 'rakuten',
			},
		];
		// providers.js は import 時に window を読むため、fresh に読み直す。
		const {
			PlatformEditor: Fresh,
		} = require( '../../../src/Admin/components/PlatformEditor' );
		render(
			<Fresh
				platform={ { ...basePlatform, provider: 'manual' } }
				onChange={ jest.fn() }
			/>
		);
		const select = screen.getByLabelText( 'Provider' );
		const values = Array.from(
			select.querySelectorAll( 'option' )
		).map( ( o ) => o.value );
		expect( values ).toEqual( [ 'manual' ] );
	} );

	test( 'renders all editor controls with platform values', () => {
		const onChange = jest.fn();
		render(
			<PlatformEditor platform={ basePlatform } onChange={ onChange } />
		);
		expect( screen.getByLabelText( '有効' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( '表示名' ) ).toBeInTheDocument();
		expect(
			screen.getByLabelText( 'ボタンラベル' )
		).toBeInTheDocument();
		expect( screen.getByLabelText( '表示順' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Provider' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'ブランド色' ) ).toBeInTheDocument();
		expect(
			screen.getByLabelText( 'ボタン文字色' )
		).toBeInTheDocument();
	} );

	test( 'onChange propagates patch to parent', () => {
		const onChange = jest.fn();
		render(
			<PlatformEditor platform={ basePlatform } onChange={ onChange } />
		);
		fireEvent.change( screen.getByLabelText( '表示名' ), {
			target: { value: 'DMM Books' },
		} );
		expect( onChange ).toHaveBeenCalledWith(
			expect.objectContaining( { name: 'DMM Books' } )
		);
	} );

	test( 'renders imagePriority input with platform value', () => {
		const onChange = jest.fn();
		render(
			<PlatformEditor platform={ basePlatform } onChange={ onChange } />
		);
		expect(
			screen.getByLabelText( '画像優先度（小さいほど優先）' )
		).toHaveValue( 10 );
	} );

	test( 'onChange propagates imagePriority patch to parent', () => {
		const onChange = jest.fn();
		render(
			<PlatformEditor platform={ basePlatform } onChange={ onChange } />
		);
		fireEvent.change(
			screen.getByLabelText( '画像優先度（小さいほど優先）' ),
			{
				target: { value: '20' },
			}
		);
		expect( onChange ).toHaveBeenCalledWith(
			expect.objectContaining( { imagePriority: 20 } )
		);
	} );

	test( 'onChange keeps 0 as a valid imagePriority instead of falling back to 999', () => {
		const onChange = jest.fn();
		render(
			<PlatformEditor platform={ basePlatform } onChange={ onChange } />
		);
		fireEvent.change(
			screen.getByLabelText( '画像優先度（小さいほど優先）' ),
			{
				target: { value: '0' },
			}
		);
		expect( onChange ).toHaveBeenCalledWith(
			expect.objectContaining( { imagePriority: 0 } )
		);
	} );

	test( 'does not render the credential editor inline', () => {
		const onChange = jest.fn();
		const platform = { ...basePlatform, provider: 'dmm-ebook' };
		render(
			<PlatformEditor platform={ platform } onChange={ onChange } />
		);
		expect( screen.queryByText( '認証情報' ) ).not.toBeInTheDocument();
		expect( screen.queryByLabelText( 'API ID' ) ).not.toBeInTheDocument();
	} );

	test( 'renders platform name and code as panel title', () => {
		const onChange = jest.fn();
		render(
			<PlatformEditor platform={ basePlatform } onChange={ onChange } />
		);
		expect( screen.getByText( 'DMM (dmm)' ) ).toBeInTheDocument();
	} );

	test( 'shows 無効 suffix in title when platform disabled', () => {
		const onChange = jest.fn();
		const disabled = { ...basePlatform, enabled: false };
		render(
			<PlatformEditor platform={ disabled } onChange={ onChange } />
		);
		expect( screen.getByText( 'DMM (dmm) — 無効' ) ).toBeInTheDocument();
	} );

	test( 'groups auto-fetch fields under an API section heading', () => {
		const onChange = jest.fn();
		render(
			<PlatformEditor platform={ basePlatform } onChange={ onChange } />
		);
		expect(
			screen.getByText( 'API 連携（自動取得）' )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Provider' ) ).toBeInTheDocument();
	} );

	test( 'keeps the refresh button inside the API section', () => {
		const onChange = jest.fn();
		const { container } = render(
			<PlatformEditor platform={ basePlatform } onChange={ onChange } />
		);
		const apiSection = container.querySelector(
			'.affilicard-platform-editor__section--api'
		);
		expect( apiSection ).toBeInTheDocument();
		const refreshButton = apiSection?.querySelector( 'button' );
		expect( refreshButton ).toBeInTheDocument();
		expect( refreshButton ).toHaveTextContent(
			'今すぐこのプラットフォームを更新'
		);
	} );
} );
