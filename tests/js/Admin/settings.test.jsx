/**
 * Tests for src/Admin/settings.js
 *
 * Verifies that SettingsApp renders the TabPanel with general/platforms tabs
 * and starts on the "general" tab.
 */

jest.mock( '../../../src/Admin/api/settings' );
jest.mock( '../../../src/Admin/api/platforms' );
jest.mock( '../../../src/Admin/api/credentials' );

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { SettingsApp } from '../../../src/Admin/settings';
import { fetchSettings } from '../../../src/Admin/api/settings';
import { fetchPlatforms } from '../../../src/Admin/api/platforms';

beforeEach( () => {
	fetchSettings.mockReset();
	fetchPlatforms.mockReset();
	fetchSettings.mockResolvedValue( {
		cache_ttl_seconds: 86400,
		default_product_type: 'generic',
		cron_enabled: false,
	} );
	fetchPlatforms.mockResolvedValue( [] );
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
