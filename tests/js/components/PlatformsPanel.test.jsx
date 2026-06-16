/**
 * Tests for src/Admin/components/PlatformsPanel.jsx
 */

jest.mock( '../../../src/Admin/api/platforms' );
jest.mock( '../../../src/Admin/api/credentials' );

import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { PlatformsPanel } from '../../../src/Admin/components/PlatformsPanel';
import {
	fetchPlatforms,
	updatePlatforms,
} from '../../../src/Admin/api/platforms';
import { fetchCredentials } from '../../../src/Admin/api/credentials';

beforeEach( () => {
	fetchPlatforms.mockReset();
	updatePlatforms.mockReset();
	fetchCredentials.mockReset();
	fetchCredentials.mockResolvedValue( {} );
} );

const platforms = [
	{
		code: 'dmm',
		name: 'DMM',
		provider: 'manual',
		enabled: true,
		displayOrder: 1,
		applicableTypes: [ 'ebook' ],
		buttonLabel: '購入',
		brandColor: '#444',
		buttonTextColor: '#fff',
	},
	{
		code: 'amazon',
		name: 'Amazon',
		provider: 'manual',
		enabled: false,
		displayOrder: 2,
		applicableTypes: [ 'ebook' ],
		buttonLabel: 'Amazon で見る',
		brandColor: '#ff9900',
		buttonTextColor: '#000',
	},
];

describe( 'PlatformsPanel', () => {
	test( 'shows loading state before fetch resolves', () => {
		fetchPlatforms.mockReturnValue( new Promise( () => {} ) );
		render( <PlatformsPanel /> );
		expect( screen.getByText( '読み込み中…' ) ).toBeInTheDocument();
	} );

	test( 'shows empty message when zero platforms returned', async () => {
		fetchPlatforms.mockResolvedValue( [] );
		render( <PlatformsPanel /> );
		await waitFor( () =>
			expect(
				screen.getByText( 'プラットフォームがありません' )
			).toBeInTheDocument()
		);
	} );

	test( 'renders one PlatformEditor per platform after fetch', async () => {
		fetchPlatforms.mockResolvedValue( platforms );
		render( <PlatformsPanel /> );
		await waitFor( () =>
			expect( screen.getByText( /DMM \(dmm\)/ ) ).toBeInTheDocument()
		);
		expect(
			screen.getByText( /Amazon \(amazon\)/ )
		).toBeInTheDocument();
	} );

	test( 'clicking 保存 calls updatePlatforms with current list', async () => {
		fetchPlatforms.mockResolvedValue( platforms );
		updatePlatforms.mockResolvedValue( platforms );
		render( <PlatformsPanel /> );
		const saveButton = await screen.findByRole( 'button', {
			name: '保存',
		} );
		fireEvent.click( saveButton );
		await waitFor( () =>
			expect( updatePlatforms ).toHaveBeenCalledWith( platforms )
		);
	} );

	test( 'falls back to empty list when fetch rejects', async () => {
		fetchPlatforms.mockRejectedValue( new Error( 'fail' ) );
		render( <PlatformsPanel /> );
		await waitFor( () =>
			expect(
				screen.getByText( 'プラットフォームがありません' )
			).toBeInTheDocument()
		);
	} );

	test( 'wraps platform editors in a panel container', async () => {
		fetchPlatforms.mockResolvedValue( platforms );
		const { container } = render( <PlatformsPanel /> );
		await waitFor( () =>
			expect( screen.getByText( /DMM \(dmm\)/ ) ).toBeInTheDocument()
		);
		expect(
			container.querySelector( '[data-panel-container="true"]' )
		).toBeInTheDocument();
	} );

	test( 'opens the first platform by default', async () => {
		fetchPlatforms.mockResolvedValue( platforms );
		const { container } = render( <PlatformsPanel /> );
		await waitFor( () =>
			expect( screen.getByText( /DMM \(dmm\)/ ) ).toBeInTheDocument()
		);
		const panels = container.querySelectorAll( '[data-panel]' );
		expect( panels[ 0 ] ).toHaveAttribute( 'data-initial-open', 'true' );
		expect( panels[ 1 ] ).toHaveAttribute( 'data-initial-open', 'false' );
	} );
} );
