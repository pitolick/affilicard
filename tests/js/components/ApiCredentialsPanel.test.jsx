/**
 * Tests for src/Admin/components/ApiCredentialsPanel.jsx
 *
 * ACCOUNTS/PROVIDER_OPTIONS の window 導出そのものは
 * tests/js/Admin/accounts.test.js と tests/js/Admin/providers.test.js で
 * 検証済みのため、ここでは ApiCredentialsPanel 自身の account 単位グルーピング・
 * provider 絞り込みロジックを、それらのモジュールをモックして検証する。
 */

jest.mock( '../../../src/Admin/accounts', () => ( { ACCOUNTS: [] } ) );
jest.mock( '../../../src/Admin/providers', () => ( {
	PROVIDER_OPTIONS: [],
	providerAccount: jest.fn( () => null ),
} ) );
jest.mock( '../../../src/Admin/api/credentials' );

import { render, screen, waitFor } from '@testing-library/react';
import { ApiCredentialsPanel } from '../../../src/Admin/components/ApiCredentialsPanel';
import { fetchCredentials } from '../../../src/Admin/api/credentials';

// `import * as X` goes through Babel's _interopRequireWildcard, which returns
// a *copy* namespace object — mutating it wouldn't be visible to
// ApiCredentialsPanel's own `import { ACCOUNTS } from '../accounts'` (which
// reads straight off the raw required module). Use plain require() so both
// sides share the same underlying mocked module object.
const accountsModule = require( '../../../src/Admin/accounts' );
const providersModule = require( '../../../src/Admin/providers' );

beforeEach( () => {
	fetchCredentials.mockReset();
	fetchCredentials.mockResolvedValue( {} );
	accountsModule.ACCOUNTS = [];
	providersModule.PROVIDER_OPTIONS = [];
	providersModule.providerAccount = () => null;
} );

describe( 'ApiCredentialsPanel', () => {
	test( 'アカウントが 0 件のときはフォールバックメッセージを表示する', () => {
		render( <ApiCredentialsPanel /> );
		expect(
			screen.getByText( '認証情報を必要とするアカウントはありません。' )
		).toBeInTheDocument();
	} );

	test( 'account ごとに PanelBody を描画し、accountCode が一致する provider だけ渡す', async () => {
		accountsModule.ACCOUNTS = [
			{
				code: 'rakuten',
				label: '楽天',
				credentialsSchema: [
					{
						key: 'access_key',
						label: 'アクセスキー',
						type: 'password',
						required: true,
					},
				],
			},
		];
		providersModule.PROVIDER_OPTIONS = [
			{ label: '楽天Kobo API', value: 'rakuten-kobo' },
			{ label: '手動入力', value: 'manual' },
		];
		providersModule.providerAccount = ( code ) =>
			code === 'rakuten-kobo' ? 'rakuten' : null;

		render( <ApiCredentialsPanel /> );

		// account label が PanelBody のタイトルとして出る
		expect( screen.getByText( '楽天' ) ).toBeInTheDocument();
		// account の credentialsSchema に基づくフィールドが描画される
		expect(
			await screen.findByLabelText( 'アクセスキー' )
		).toBeInTheDocument();
		// providerAccount が 'rakuten' を返す provider だけが紐付く
		expect( screen.getByText( '楽天Kobo API' ) ).toBeInTheDocument();
		// accountCode が一致しない provider（manual）は出ない
		expect( screen.queryByText( '手動入力' ) ).not.toBeInTheDocument();
	} );

	test( '複数 account はそれぞれ独立した PanelBody で描画される', async () => {
		accountsModule.ACCOUNTS = [
			{ code: 'rakuten', label: '楽天', credentialsSchema: [] },
			{ code: 'dmm', label: 'DMM', credentialsSchema: [] },
		];

		render( <ApiCredentialsPanel /> );

		expect( screen.getByText( '楽天' ) ).toBeInTheDocument();
		expect( screen.getByText( 'DMM' ) ).toBeInTheDocument();
		// 各 account の AccountCredentialEditor が fetchCredentials を呼び終える
		// (state 更新が act() の外で起きて console.error が飛ぶ) のを待ってから終了する。
		await waitFor( () =>
			expect( fetchCredentials ).toHaveBeenCalledTimes( 2 )
		);
	} );
} );
