jest.mock( '../../../src/Admin/api/credentials' );

import { render, screen } from '@testing-library/react';
import { ApiCredentialsPanel } from '../../../src/Admin/components/ApiCredentialsPanel';
import { fetchCredentials } from '../../../src/Admin/api/credentials';

beforeEach( () => {
	fetchCredentials.mockReset();
	fetchCredentials.mockResolvedValue( {} );
} );

describe( 'ApiCredentialsPanel', () => {
	test( 'renders a credential editor only for providers with a schema', async () => {
		render( <ApiCredentialsPanel /> );
		// dmm-ebook はスキーマあり → ラベルと API ID フィールドが出る
		expect( screen.getByText( 'DMM ebook API' ) ).toBeInTheDocument();
		expect(
			await screen.findByLabelText( 'API ID' )
		).toBeInTheDocument();
		// manual はスキーマ空 → 「手動入力」見出しは出ない
		expect( screen.queryByText( '手動入力' ) ).not.toBeInTheDocument();
	} );
} );
