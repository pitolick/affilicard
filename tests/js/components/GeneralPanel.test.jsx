/**
 * Tests for src/Admin/components/GeneralPanel.jsx
 *
 * We mock @wordpress/components into thin DOM-ish stand-ins to avoid the heavy
 * SVG/emotion rendering pipeline which doesn't cleanly work under JSDOM.
 * This keeps tests focused on our component's logic (state, conditional render,
 * save callback) rather than third-party UI internals.
 */

jest.mock( '../../../src/Admin/api/settings' );

jest.mock( '@wordpress/components', () => ( {
	__esModule: true,
	TextControl: ( { label, value, onChange, type } ) => (
		<label>
			{ label }
			<input
				type={ type ?? 'text' }
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
			/>
		</label>
	),
	ToggleControl: ( { label, checked, onChange } ) => (
		<label>
			{ label }
			<input
				type="checkbox"
				checked={ checked }
				onChange={ ( e ) => onChange( e.target.checked ) }
			/>
		</label>
	),
	SelectControl: ( { label, value, options, onChange } ) => (
		<label>
			{ label }
			<select
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
			>
				{ options.map( ( o ) => (
					<option key={ o.value } value={ o.value }>
						{ o.label }
					</option>
				) ) }
			</select>
		</label>
	),
	Button: ( { children, onClick, disabled } ) => (
		<button onClick={ onClick } disabled={ disabled } type="button">
			{ children }
		</button>
	),
	Notice: ( { children, status } ) => (
		<div role="alert" data-status={ status }>
			{ children }
		</div>
	),
} ) );

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
} );
