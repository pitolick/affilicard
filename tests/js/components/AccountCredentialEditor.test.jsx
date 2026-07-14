/**
 * Tests for src/Admin/components/AccountCredentialEditor.jsx
 */

jest.mock( '../../../src/Admin/api/credentials' );

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { AccountCredentialEditor } from '../../../src/Admin/components/AccountCredentialEditor';
import * as api from '../../../src/Admin/api/credentials';

const account = {
	code: 'rakuten',
	label: '楽天',
	credentialsSchema: [
		{
			key: 'application_id',
			label: 'アプリID',
			type: 'text',
			required: true,
		},
		{
			key: 'access_key',
			label: 'アクセスキー',
			type: 'password',
			required: true,
		},
	],
};

const provider = { code: 'rakuten-kobo', label: '楽天Kobo API' };

beforeEach( () => {
	api.fetchCredentials.mockReset();
	api.updateCredentials.mockReset();
	api.deleteCredentials.mockReset();
	api.testConnection.mockReset();

	api.fetchCredentials.mockResolvedValue( {
		application_id: { value: 'app-1', isSet: true },
		access_key: { value: '', isSet: true },
	} );
	api.updateCredentials.mockResolvedValue( {
		application_id: { value: 'app-2', isSet: true },
		access_key: { value: '', isSet: true },
	} );
} );

describe( 'AccountCredentialEditor', () => {
	test( '取得した値でフィールドを初期化する（password は空欄・設定済みバッジ表示）', async () => {
		render( <AccountCredentialEditor account={ account } providers={ [] } /> );
		await waitFor( () => screen.getByDisplayValue( 'app-1' ) );

		expect( screen.getByLabelText( 'アクセスキー' ).value ).toBe( '' );
		expect( screen.getByText( '設定済み' ) ).toBeInTheDocument();
	} );

	test( '未編集の password は PUT に含めない（dirty のみ送信）', async () => {
		render( <AccountCredentialEditor account={ account } providers={ [] } /> );
		await waitFor( () => screen.getByDisplayValue( 'app-1' ) );

		fireEvent.change( screen.getByLabelText( 'アプリID' ), {
			target: { value: 'app-2' },
		} );
		fireEvent.click( screen.getByText( '認証情報を保存' ) );

		await waitFor( () => expect( api.updateCredentials ).toHaveBeenCalled() );
		const [ , sent ] = api.updateCredentials.mock.calls[ 0 ];
		expect( sent ).toEqual( { application_id: 'app-2' } ); // access_key は未編集＝送らない
	} );

	test( '認証情報を削除すると deleteCredentials(accountCode) を呼び、入力をクリアする', async () => {
		const confirmSpy = jest
			.spyOn( window, 'confirm' )
			.mockReturnValue( true );
		api.deleteCredentials.mockResolvedValue( {
			application_id: { value: '', isSet: false },
			access_key: { value: '', isSet: false },
		} );

		render( <AccountCredentialEditor account={ account } providers={ [] } /> );
		await waitFor( () => screen.getByDisplayValue( 'app-1' ) );

		fireEvent.click( screen.getByText( '認証情報を削除' ) );

		await waitFor( () =>
			expect( api.deleteCredentials ).toHaveBeenCalledWith( 'rakuten' )
		);
		expect( screen.getByLabelText( 'アプリID' ).value ).toBe( '' );

		confirmSpy.mockRestore();
	} );

	test( '削除確認をキャンセルすると deleteCredentials を呼ばない', async () => {
		const confirmSpy = jest
			.spyOn( window, 'confirm' )
			.mockReturnValue( false );

		render( <AccountCredentialEditor account={ account } providers={ [] } /> );
		await waitFor( () => screen.getByDisplayValue( 'app-1' ) );

		fireEvent.click( screen.getByText( '認証情報を削除' ) );

		expect( api.deleteCredentials ).not.toHaveBeenCalled();
		confirmSpy.mockRestore();
	} );

	test( 'provider 単位の接続テストは in-form の dirty 値を testConnection に渡す', async () => {
		api.testConnection.mockResolvedValue( { ok: true, message: 'OK!' } );

		render(
			<AccountCredentialEditor
				account={ account }
				providers={ [ provider ] }
			/>
		);
		await waitFor( () => screen.getByDisplayValue( 'app-1' ) );

		fireEvent.change( screen.getByLabelText( 'アプリID' ), {
			target: { value: 'app-3' },
		} );
		fireEvent.click( screen.getByText( '接続テスト' ) );

		await waitFor( () =>
			expect( api.testConnection ).toHaveBeenCalledWith( 'rakuten-kobo', {
				application_id: 'app-3',
			} )
		);
		await waitFor( () =>
			expect( screen.getByText( /OK!/ ) ).toBeInTheDocument()
		);
	} );

	test( '400 (missing) エラー時に該当フィールドへ必須エラーを表示し、再保存成功でクリアする', async () => {
		api.updateCredentials.mockRejectedValueOnce( {
			code: 'affilicard_missing_required',
			message: '必須項目が未入力です。',
			data: { status: 400 },
			missing: [ 'access_key' ],
		} );

		render( <AccountCredentialEditor account={ account } providers={ [] } /> );
		await waitFor( () => screen.getByDisplayValue( 'app-1' ) );

		fireEvent.change( screen.getByLabelText( 'アプリID' ), {
			target: { value: 'app-2' },
		} );
		fireEvent.click( screen.getByText( '認証情報を保存' ) );

		await waitFor( () =>
			expect( screen.getByText( '必須項目です' ) ).toBeInTheDocument()
		);

		// 再保存が成功したら必須エラーはクリアされる。
		fireEvent.click( screen.getByText( '認証情報を保存' ) );

		await waitFor( () =>
			expect(
				screen.queryByText( '必須項目です' )
			).not.toBeInTheDocument()
		);
	} );

	test( '表示ボタンで password の入力を平文表示に切り替える', async () => {
		render( <AccountCredentialEditor account={ account } providers={ [] } /> );
		await waitFor( () => screen.getByDisplayValue( 'app-1' ) );

		const input = screen.getByLabelText( 'アクセスキー' );
		expect( input.type ).toBe( 'password' );

		fireEvent.click( screen.getByText( '表示' ) );
		expect( input.type ).toBe( 'text' );
	} );
} );
