/**
 * Tests for src/Admin/components/PlatformEditor.jsx
 */

jest.mock( '../../../src/Admin/api/credentials' );

import { render, screen, fireEvent } from '@testing-library/react';
import {
	PlatformEditor,
	CRED_SCHEMAS,
} from '../../../src/Admin/components/PlatformEditor';
import { fetchCredentials } from '../../../src/Admin/api/credentials';

beforeEach( () => {
	fetchCredentials.mockReset();
	fetchCredentials.mockResolvedValue( {} );
} );

const basePlatform = {
	code: 'dmm',
	name: 'DMM',
	provider: 'manual',
	enabled: true,
	displayOrder: 1,
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

	test( 'renders no credential fields for manual provider', () => {
		const onChange = jest.fn();
		render(
			<PlatformEditor platform={ basePlatform } onChange={ onChange } />
		);
		expect(
			screen.getByText( 'この Provider は認証情報を必要としません。' )
		).toBeInTheDocument();
	} );

	test( 'renders DMM credential fields when provider is dmm-ebook', async () => {
		const onChange = jest.fn();
		const platform = { ...basePlatform, provider: 'dmm-ebook' };
		render(
			<PlatformEditor platform={ platform } onChange={ onChange } />
		);
		expect(
			await screen.findByLabelText( 'API ID' )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( 'アフィリエイト ID' )
		).toBeInTheDocument();
	} );

	test( 'CRED_SCHEMAS exports DMM and manual entries', () => {
		expect( CRED_SCHEMAS.manual ).toEqual( [] );
		expect( CRED_SCHEMAS[ 'dmm-ebook' ] ).toHaveLength( 2 );
		expect( CRED_SCHEMAS[ 'dmm-ebook' ][ 0 ].key ).toBe( 'api_id' );
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
			screen.getByText( 'API 連携（自動取得・後回し）' )
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
		expect( apiSection ).toHaveTextContent(
			'今すぐこのプラットフォームを更新'
		);
	} );
} );
