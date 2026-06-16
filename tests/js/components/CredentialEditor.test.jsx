/**
 * Tests for src/Admin/components/CredentialEditor.jsx
 */

jest.mock( '../../../src/Admin/api/credentials' );

import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { CredentialEditor } from '../../../src/Admin/components/CredentialEditor';
import {
	fetchCredentials,
	updateCredentials,
	testConnection,
} from '../../../src/Admin/api/credentials';

const dmmSchema = [
	{ key: 'api_id', label: 'API ID', type: 'password', required: true },
	{
		key: 'affiliate_id',
		label: 'アフィリエイト ID',
		type: 'password',
		required: true,
	},
];

beforeEach( () => {
	fetchCredentials.mockReset();
	updateCredentials.mockReset();
	testConnection.mockReset();
	fetchCredentials.mockResolvedValue( {} );
} );

describe( 'CredentialEditor', () => {
	test( 'renders empty-state message when schema is empty', () => {
		render( <CredentialEditor providerCode="manual" schema={ [] } /> );
		expect(
			screen.getByText(
				'この Provider は認証情報を必要としません。'
			)
		).toBeInTheDocument();
		expect( fetchCredentials ).not.toHaveBeenCalled();
	} );

	test( 'renders a field per schema entry', async () => {
		render(
			<CredentialEditor providerCode="dmm" schema={ dmmSchema } />
		);
		await waitFor( () =>
			expect( fetchCredentials ).toHaveBeenCalledWith( 'dmm' )
		);
		expect( screen.getByLabelText( 'API ID' ) ).toBeInTheDocument();
		expect(
			screen.getByLabelText( 'アフィリエイト ID' )
		).toBeInTheDocument();
	} );

	test( 'updates field state on input', async () => {
		render(
			<CredentialEditor providerCode="dmm" schema={ dmmSchema } />
		);
		const input = await screen.findByLabelText( 'API ID' );
		fireEvent.change( input, { target: { value: 'my-api-id' } } );
		expect( input.value ).toBe( 'my-api-id' );
	} );

	test( 'clicking 認証情報を保存 calls updateCredentials', async () => {
		updateCredentials.mockResolvedValue( {
			api_id: '***',
			affiliate_id: '***',
		} );
		render(
			<CredentialEditor providerCode="dmm" schema={ dmmSchema } />
		);
		await waitFor( () =>
			expect( fetchCredentials ).toHaveBeenCalled()
		);

		const input = screen.getByLabelText( 'API ID' );
		fireEvent.change( input, { target: { value: 'k' } } );

		fireEvent.click(
			screen.getByRole( 'button', { name: '認証情報を保存' } )
		);

		await waitFor( () =>
			expect( updateCredentials ).toHaveBeenCalledWith( 'dmm', {
				api_id: 'k',
			} )
		);
	} );

	test( 'clicking 接続テスト calls testConnection and shows result message', async () => {
		testConnection.mockResolvedValue( {
			ok: true,
			message: 'OK!',
		} );
		render(
			<CredentialEditor providerCode="dmm" schema={ dmmSchema } />
		);
		await waitFor( () =>
			expect( fetchCredentials ).toHaveBeenCalled()
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: '接続テスト' } )
		);

		await waitFor( () =>
			expect( testConnection ).toHaveBeenCalledWith( 'dmm', {} )
		);
		await waitFor( () =>
			expect( screen.getByText( 'OK!' ) ).toBeInTheDocument()
		);
	} );

	test( 'shows error notice when testConnection rejects', async () => {
		testConnection.mockRejectedValue( new Error( 'boom' ) );
		render(
			<CredentialEditor providerCode="dmm" schema={ dmmSchema } />
		);
		await waitFor( () =>
			expect( fetchCredentials ).toHaveBeenCalled()
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: '接続テスト' } )
		);

		await waitFor( () =>
			expect(
				screen.getByText( '接続テストに失敗しました' )
			).toBeInTheDocument()
		);
	} );
} );
