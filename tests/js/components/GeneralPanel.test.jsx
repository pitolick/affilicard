/**
 * Tests for src/Admin/components/GeneralPanel.jsx
 *
 * @wordpress/components is mocked globally via jest.config.js moduleNameMapper
 * (see tests/js/__mocks__/wordpress-components.js) to avoid JSDOM rendering
 * issues with the real emotion/ariakit/SVG-icon stack.
 */

jest.mock( '../../../src/Admin/api/settings' );

import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { GeneralPanel } from '../../../src/Admin/components/GeneralPanel';
import {
	fetchSettings,
	updateSettings,
} from '../../../src/Admin/api/settings';

beforeEach( () => {
	fetchSettings.mockReset();
	updateSettings.mockReset();
} );

describe( 'GeneralPanel', () => {
	test( 'shows loading state before fetch resolves', () => {
		fetchSettings.mockReturnValue( new Promise( () => {} ) );
		render( <GeneralPanel /> );
		expect( screen.getByText( '読み込み中…' ) ).toBeInTheDocument();
	} );

	test( 'renders form controls after settings load', async () => {
		fetchSettings.mockResolvedValue( {
			cache_ttl_seconds: 3600,
			default_product_type: 'generic',
			cron_enabled: false,
		} );
		render( <GeneralPanel /> );

		await waitFor( () =>
			expect( screen.queryByText( '読み込み中…' ) ).not.toBeInTheDocument()
		);

		expect(
			screen.getByLabelText( /キャッシュ TTL/ )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( /デフォルト商品タイプ/ )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( /自動更新を有効化/ )
		).toBeInTheDocument();
	} );

	test( 'shows CronHelpBox when cron_enabled is true', async () => {
		fetchSettings.mockResolvedValue( {
			cache_ttl_seconds: 86400,
			default_product_type: 'generic',
			cron_enabled: true,
		} );
		render( <GeneralPanel /> );

		await waitFor( () =>
			expect(
				screen.getByText(
					'wp cron event run affilicard_refresh_listings'
				)
			).toBeInTheDocument()
		);
	} );

	test( 'links to the operations doc near the cron toggle regardless of cron_enabled', async () => {
		fetchSettings.mockResolvedValue( {
			cache_ttl_seconds: 86400,
			default_product_type: 'generic',
			cron_enabled: false,
		} );
		render( <GeneralPanel /> );

		const link = await screen.findByRole( 'link', {
			name: /運用ドキュメント/,
		} );
		expect( link.getAttribute( 'href' ) ).toEqual(
			expect.stringContaining( 'operations-refresh-queue.md' )
		);
	} );

	test( 'does not show CronHelpBox when cron_enabled is false', async () => {
		fetchSettings.mockResolvedValue( {
			cache_ttl_seconds: 86400,
			default_product_type: 'generic',
			cron_enabled: false,
		} );
		render( <GeneralPanel /> );

		await waitFor( () =>
			expect(
				screen.getByLabelText( /自動更新を有効化/ )
			).toBeInTheDocument()
		);

		expect(
			screen.queryByText(
				'wp cron event run affilicard_refresh_listings'
			)
		).not.toBeInTheDocument();
	} );

	test( 'toggling cron_enabled reveals CronHelpBox', async () => {
		fetchSettings.mockResolvedValue( {
			cache_ttl_seconds: 86400,
			default_product_type: 'generic',
			cron_enabled: false,
		} );
		render( <GeneralPanel /> );

		const toggle = await screen.findByLabelText( /自動更新を有効化/ );
		fireEvent.click( toggle );

		await waitFor( () =>
			expect(
				screen.getByText(
					'wp cron event run affilicard_refresh_listings'
				)
			).toBeInTheDocument()
		);
	} );

	test( 'clicking 保存 calls updateSettings with current state', async () => {
		const initial = {
			cache_ttl_seconds: 86400,
			default_product_type: 'generic',
			cron_enabled: false,
		};
		fetchSettings.mockResolvedValue( initial );
		updateSettings.mockResolvedValue( initial );

		render( <GeneralPanel /> );

		const saveButton = await screen.findByRole( 'button', {
			name: '保存',
		} );
		fireEvent.click( saveButton );

		await waitFor( () =>
			expect( updateSettings ).toHaveBeenCalledWith( initial )
		);
	} );

	test( 'falls back to empty settings when fetch rejects', async () => {
		fetchSettings.mockRejectedValue( new Error( 'fail' ) );
		render( <GeneralPanel /> );

		await waitFor( () =>
			expect( screen.queryByText( '読み込み中…' ) ).not.toBeInTheDocument()
		);

		expect(
			screen.getByLabelText( /キャッシュ TTL/ )
		).toBeInTheDocument();
	} );

	test( 'wraps controls in a section and buttons in an actions row', async () => {
		fetchSettings.mockResolvedValue( {} );
		const { container } = render( <GeneralPanel /> );
		await waitFor( () =>
			expect(
				screen.getByLabelText( 'キャッシュ TTL (秒)' )
			).toBeInTheDocument()
		);
		expect(
			container.querySelector( '.affilicard-general-panel__section' )
		).toBeInTheDocument();
		expect(
			container.querySelector( '.affilicard-general-panel__actions' )
		).toBeInTheDocument();
	} );
} );
