/**
 * Tests for src/Admin/settings.js
 *
 * Verifies that SettingsApp renders the TabPanel with general/platforms tabs
 * and starts on the "general" tab.
 */

jest.mock( '../../../src/Admin/api/settings' );
jest.mock( '../../../src/Admin/api/platforms' );
jest.mock( '../../../src/Admin/api/credentials' );
jest.mock( '../../../src/Admin/api/refresh', () => ( {
	triggerRefresh: jest.fn( () =>
		Promise.resolve( { ok: true, scope: 'all', force: false } )
	),
} ) );

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { SettingsApp } from '../../../src/Admin/settings';
import { fetchSettings } from '../../../src/Admin/api/settings';
import { fetchPlatforms } from '../../../src/Admin/api/platforms';
import { fetchCredentials } from '../../../src/Admin/api/credentials';
import { triggerRefresh } from '../../../src/Admin/api/refresh';
import { PlatformEditor } from '../../../src/Admin/components/PlatformEditor';
import { GeneralPanel } from '../../../src/Admin/components/GeneralPanel';

beforeEach( () => {
	fetchSettings.mockReset();
	fetchPlatforms.mockReset();
	fetchCredentials.mockReset();
	triggerRefresh.mockReset();
	fetchSettings.mockResolvedValue( {
		cache_ttl_seconds: 86400,
		default_product_type: 'generic',
		cron_enabled: false,
	} );
	fetchPlatforms.mockResolvedValue( [] );
	fetchCredentials.mockResolvedValue( {} );
	triggerRefresh.mockResolvedValue( { ok: true, scope: 'all', force: false } );
} );

describe( 'SettingsApp', () => {
	test( 'renders the two tabs (general / platforms)', async () => {
		render( <SettingsApp /> );
		const tabs = screen.getAllByRole( 'tab' );
		const titles = tabs.map( ( t ) => t.textContent );
		expect( titles ).toEqual( [ '一般', 'プラットフォーム' ] );
		// Wait for the auto-mounted GeneralPanel's effect to flush so React
		// doesn't emit an act(…) warning during test teardown.
		await waitFor( () =>
			expect( fetchSettings ).toHaveBeenCalled()
		);
	} );

	test( 'shows general tab content by default', async () => {
		render( <SettingsApp /> );
		await waitFor( () =>
			expect( fetchSettings ).toHaveBeenCalled()
		);
		expect( screen.getByText( '一般設定' ) ).toBeInTheDocument();
	} );

	test( 'switches to platforms tab when its tab is clicked', async () => {
		render( <SettingsApp /> );
		await waitFor( () =>
			expect( fetchSettings ).toHaveBeenCalled()
		);
		const platformsTab = screen.getByRole( 'tab', {
			name: 'プラットフォーム',
		} );
		fireEvent.click( platformsTab );
		await waitFor( () =>
			expect( fetchPlatforms ).toHaveBeenCalled()
		);
	} );
} );

describe( 'PlatformEditor', () => {
	test( 'autoRefresh ON で頻度 select と更新ボタンが出る', () => {
		const platform = {
			code: 'dmm-books',
			name: 'DMM',
			provider: 'dmm-ebook',
			autoRefresh: true,
			refreshFrequency: 'weekly',
		};
		render( <PlatformEditor platform={ platform } onChange={ () => {} } /> );
		expect(
			screen.getByText( '今すぐこのプラットフォームを更新' )
		).toBeInTheDocument();
		expect( screen.getByText( '更新頻度' ) ).toBeInTheDocument();
	} );

	test( 'autoRefresh OFF で頻度 select は非表示', () => {
		const platform = {
			code: 'dmm-books',
			name: 'DMM',
			provider: 'dmm-ebook',
			autoRefresh: false,
		};
		render( <PlatformEditor platform={ platform } onChange={ () => {} } /> );
		expect( screen.queryByText( '更新頻度' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'GeneralPanel refresh buttons', () => {
	test( '一括更新ボタンが triggerRefresh(null,false)、強制一括更新が triggerRefresh(null,true) を呼ぶ', async () => {
		render( <GeneralPanel /> );
		await waitFor( () => expect( fetchSettings ).toHaveBeenCalled() );

		const bulkBtn = screen.getByText( '一括更新' );
		const forceBtn = screen.getByText( '強制一括更新（取扱終了も含む）' );

		fireEvent.click( bulkBtn );
		expect( triggerRefresh ).toHaveBeenCalledWith( null, false );

		fireEvent.click( forceBtn );
		expect( triggerRefresh ).toHaveBeenCalledWith( null, true );
	} );
} );
