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
		).toBeInTheDocument();
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
