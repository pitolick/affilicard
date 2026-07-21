/**
 * Tests for src/Admin/components/GeneralPanel.jsx
 *
 * v2.3.0: the auto-refresh interval becomes a single global setting
 * (refresh_interval_hours) surfaced here, and the bulk-refresh buttons
 * must await triggerRefresh() and give the user real feedback (disabled +
 * "更新中…" label while in flight, a Notice on completion/failure) instead
 * of firing-and-forgetting.
 */

jest.mock( '../../../src/Admin/api/settings' );
jest.mock( '../../../src/Admin/api/refresh', () => ( {
	triggerRefresh: jest.fn(),
} ) );

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { GeneralPanel } from '../../../src/Admin/components/GeneralPanel';
import { fetchSettings, updateSettings } from '../../../src/Admin/api/settings';
import { triggerRefresh } from '../../../src/Admin/api/refresh';

const baseSettings = {
	cache_ttl_seconds: 86400,
	default_product_type: 'generic',
	cron_enabled: false,
	refresh_interval_hours: 3,
};

beforeEach( () => {
	fetchSettings.mockReset();
	updateSettings.mockReset();
	triggerRefresh.mockReset();
	fetchSettings.mockResolvedValue( { ...baseSettings } );
	updateSettings.mockResolvedValue( { ...baseSettings } );
	triggerRefresh.mockResolvedValue( { ok: true, scope: 'all', force: false } );
} );

describe( '更新間隔 SelectControl', () => {
	test( '既定値 3 を表示し、変更で refresh_interval_hours を更新する', async () => {
		render( <GeneralPanel /> );
		await waitFor( () => expect( fetchSettings ).toHaveBeenCalled() );

		const select = await screen.findByLabelText( '更新間隔（時間毎）' );
		expect( select.value ).toBe( '3' );

		fireEvent.change( select, { target: { value: '12' } } );
		expect( select.value ).toBe( '12' );
	} );

	test( 'refresh_interval_hours 未設定時は既定 3 にフォールバックする', async () => {
		fetchSettings.mockResolvedValue( {
			cache_ttl_seconds: 86400,
			default_product_type: 'generic',
			cron_enabled: false,
		} );
		render( <GeneralPanel /> );
		const select = await screen.findByLabelText( '更新間隔（時間毎）' );
		expect( select.value ).toBe( '3' );
	} );
} );

describe( '一括更新ボタンの feedback', () => {
	test( '一括更新クリックで triggerRefresh(null,false) を呼び、実行中は両ボタンが disabled かつラベルが「更新中…」になり、完了後に成功通知が出る', async () => {
		let resolveRefresh;
		triggerRefresh.mockImplementation(
			() =>
				new Promise( ( resolve ) => {
					resolveRefresh = resolve;
				} )
		);

		render( <GeneralPanel /> );
		await waitFor( () => expect( fetchSettings ).toHaveBeenCalled() );

		const bulkBtn = screen.getByText( '一括更新' );
		const forceBtn = screen.getByText( '強制一括更新（取扱終了も含む）' );

		fireEvent.click( bulkBtn );
		expect( triggerRefresh ).toHaveBeenCalledWith( null, false );

		const updatingBtn = await screen.findByText( '更新中…' );
		expect( updatingBtn ).toBeDisabled();
		expect( forceBtn ).toBeDisabled();

		resolveRefresh( { ok: true, scope: 'all', force: false } );

		expect(
			await screen.findByText( '一括更新を実行しました。' )
		).toBeInTheDocument();
		await waitFor( () =>
			expect( screen.getByText( '一括更新' ) ).not.toBeDisabled()
		);
		expect( screen.getByText( '強制一括更新（取扱終了も含む）' ) ).not.toBeDisabled();
	} );

	test( '強制一括更新クリックで triggerRefresh(null,true) を呼び、強制側だけ「更新中…」になる', async () => {
		let resolveRefresh;
		triggerRefresh.mockImplementation(
			() =>
				new Promise( ( resolve ) => {
					resolveRefresh = resolve;
				} )
		);

		render( <GeneralPanel /> );
		await waitFor( () => expect( fetchSettings ).toHaveBeenCalled() );

		fireEvent.click(
			screen.getByText( '強制一括更新（取扱終了も含む）' )
		);
		expect( triggerRefresh ).toHaveBeenCalledWith( null, true );

		expect( await screen.findByText( '更新中…' ) ).toBeDisabled();
		expect( screen.getByText( '一括更新' ) ).toBeDisabled();

		resolveRefresh( { ok: true, scope: 'all', force: true } );
		expect(
			await screen.findByText( '一括更新を実行しました。' )
		).toBeInTheDocument();
	} );

	test( '失敗時はエラー通知を表示し、ボタンの disabled が解除される', async () => {
		triggerRefresh.mockRejectedValueOnce( new Error( 'network error' ) );

		render( <GeneralPanel /> );
		await waitFor( () => expect( fetchSettings ).toHaveBeenCalled() );

		fireEvent.click( screen.getByText( '一括更新' ) );

		expect(
			await screen.findByText( '一括更新に失敗しました。' )
		).toBeInTheDocument();
		expect( screen.getByText( '一括更新' ) ).not.toBeDisabled();
	} );
} );
