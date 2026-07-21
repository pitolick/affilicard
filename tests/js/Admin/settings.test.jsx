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
	test( 'eligibleProvider ありで自動取得トグルと更新ボタンが出る', () => {
		const platform = {
			code: 'dmm-books',
			name: 'DMM',
			provider: 'dmm-ebook',
			eligibleProvider: 'dmm-ebook',
		};
		render( <PlatformEditor platform={ platform } onChange={ () => {} } /> );
		expect(
			screen.getByText( '今すぐこのプラットフォームを更新' )
		).toBeInTheDocument();
		expect( screen.getByLabelText( /自動取得/ ) ).toBeInTheDocument();
	} );

	test( 'eligibleProvider 無しではトグルが出ず手動入力の注記が出る', () => {
		const platform = {
			code: 'dmm-books',
			name: 'DMM',
			provider: 'manual',
			eligibleProvider: '',
		};
		render( <PlatformEditor platform={ platform } onChange={ () => {} } /> );
		expect(
			screen.queryByLabelText( /自動取得/ )
		).not.toBeInTheDocument();
		expect(
			screen.getByText(
				'このプラットフォームは手動入力です（対応APIがありません）。'
			)
		).toBeInTheDocument();
	} );
} );

// GeneralPanel の一括更新ボタンの feedback（disabled/ラベル切替/通知）は
// tests/js/Admin/GeneralPanel.test.jsx で async 挙動込みで検証する。
