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
				screen.getByText( 'wp cron event run --due-now' )
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
			screen.queryByText( 'wp cron event run --due-now' )
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
				screen.getByText( 'wp cron event run --due-now' )
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

	// GeneralSettings::DEFAULTS (PHP) の既定値と表示を一致させる回帰テスト。
	// fetchSettings が失敗すると settings は {} になるため、`Boolean(settings.x)` のような
	// フォールバック無しの読み出しはサーバー既定が true のトグルを誤って false 表示してしまう
	// （CodeRabbit 指摘）。
	test( '取得失敗時、サーバー側の既定値（true）がトグルに反映される', async () => {
		fetchSettings.mockRejectedValue( new Error( 'fail' ) );
		render( <GeneralPanel /> );

		const cronToggle = await screen.findByLabelText( /自動更新を有効化/ );
		expect( cronToggle.checked ).toBe( true );
		// cron_enabled が既定 true として扱われるなら、CronHelpBox も表示されるはず。
		expect(
			screen.getByText( 'wp cron event run --due-now' )
		).toBeInTheDocument();

		const stocktakeToggle = await screen.findByLabelText(
			/棚卸しを有効化/
		);
		expect( stocktakeToggle.checked ).toBe( true );

		const daysInput = screen.getByLabelText( /棚卸し期間 \(日\)/ );
		expect( daysInput.value ).toBe( '180' );
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

describe( '棚卸し設定', () => {
	test( '棚卸しトグルと期間入力が表示され、既定値を反映する', async () => {
		fetchSettings.mockResolvedValue( {
			cache_ttl_seconds: 86400,
			default_product_type: 'generic',
			cron_enabled: false,
			stocktake_enabled: true,
			stocktake_days: 180,
		} );
		render( <GeneralPanel /> );

		const toggle = await screen.findByLabelText( /棚卸しを有効化/ );
		expect( toggle.checked ).toBe( true );

		const daysInput = screen.getByLabelText( /棚卸し期間 \(日\)/ );
		expect( daysInput.value ).toBe( '180' );
	} );

	test( '棚卸しトグルを切り替えると保存時に stocktake_enabled が反映される', async () => {
		const initial = {
			cache_ttl_seconds: 86400,
			default_product_type: 'generic',
			cron_enabled: false,
			stocktake_enabled: true,
			stocktake_days: 180,
		};
		fetchSettings.mockResolvedValue( initial );
		updateSettings.mockResolvedValue( initial );
		render( <GeneralPanel /> );

		const toggle = await screen.findByLabelText( /棚卸しを有効化/ );
		fireEvent.click( toggle );

		const saveButton = await screen.findByRole( 'button', {
			name: '保存',
		} );
		fireEvent.click( saveButton );

		await waitFor( () =>
			expect( updateSettings ).toHaveBeenCalledWith(
				expect.objectContaining( { stocktake_enabled: false } )
			)
		);
	} );

	// CodeRabbit 指摘: 即時クランプだと「全選択して消す→入力する」という通常の編集操作が
	// 壊れる（消した瞬間に 1 が入り、続く入力が連結されて 190 のような値になる）。
	// 編集中は空文字・1 未満のドラフトをそのまま保持し、確定時（blur・保存）だけクランプする。
	test( '棚卸し期間の入力中は空文字や 1 未満の値をそのまま表示する（即時クランプしない）', async () => {
		fetchSettings.mockResolvedValue( {
			cache_ttl_seconds: 86400,
			default_product_type: 'generic',
			cron_enabled: false,
			stocktake_enabled: true,
			stocktake_days: 180,
		} );
		render( <GeneralPanel /> );

		const daysInput = await screen.findByLabelText(
			/棚卸し期間 \(日\)/
		);

		fireEvent.change( daysInput, { target: { value: '0' } } );
		expect( daysInput.value ).toBe( '0' );

		fireEvent.change( daysInput, { target: { value: '-30' } } );
		expect( daysInput.value ).toBe( '-30' );

		fireEvent.change( daysInput, { target: { value: '' } } );
		expect( daysInput.value ).toBe( '' );
	} );

	test( '180 を全選択して削除してから 90 を入力しても値が連結されない', async () => {
		fetchSettings.mockResolvedValue( {
			cache_ttl_seconds: 86400,
			default_product_type: 'generic',
			cron_enabled: false,
			stocktake_enabled: true,
			stocktake_days: 180,
		} );
		render( <GeneralPanel /> );

		const daysInput = await screen.findByLabelText(
			/棚卸し期間 \(日\)/
		);
		expect( daysInput.value ).toBe( '180' );

		// 全選択して削除（ブラウザ操作の再現）。
		fireEvent.change( daysInput, { target: { value: '' } } );
		expect( daysInput.value ).toBe( '' );

		// 続けて 90 を入力する。
		fireEvent.change( daysInput, { target: { value: '9' } } );
		fireEvent.change( daysInput, { target: { value: '90' } } );
		expect( daysInput.value ).toBe( '90' );
	} );

	test( '棚卸し期間はフォーカスを外す（blur）と 1 未満・非数値が 1 にクランプされる', async () => {
		fetchSettings.mockResolvedValue( {
			cache_ttl_seconds: 86400,
			default_product_type: 'generic',
			cron_enabled: false,
			stocktake_enabled: true,
			stocktake_days: 180,
		} );
		render( <GeneralPanel /> );

		const daysInput = await screen.findByLabelText(
			/棚卸し期間 \(日\)/
		);

		fireEvent.change( daysInput, { target: { value: '0' } } );
		fireEvent.blur( daysInput );
		expect( daysInput.value ).toBe( '1' );

		fireEvent.change( daysInput, { target: { value: '-30' } } );
		fireEvent.blur( daysInput );
		expect( daysInput.value ).toBe( '1' );

		fireEvent.change( daysInput, { target: { value: '' } } );
		fireEvent.blur( daysInput );
		expect( daysInput.value ).toBe( '1' );

		fireEvent.change( daysInput, { target: { value: '90' } } );
		fireEvent.blur( daysInput );
		expect( daysInput.value ).toBe( '90' );
	} );

	test( '棚卸し期間を空のまま保存すると、blur を経なくても 1 として送信される', async () => {
		const initial = {
			cache_ttl_seconds: 86400,
			default_product_type: 'generic',
			cron_enabled: false,
			stocktake_enabled: true,
			stocktake_days: 180,
		};
		fetchSettings.mockResolvedValue( initial );
		updateSettings.mockResolvedValue( {
			...initial,
			stocktake_days: 1,
		} );
		render( <GeneralPanel /> );

		const daysInput = await screen.findByLabelText(
			/棚卸し期間 \(日\)/
		);
		fireEvent.change( daysInput, { target: { value: '' } } );

		const saveButton = await screen.findByRole( 'button', {
			name: '保存',
		} );
		fireEvent.click( saveButton );

		await waitFor( () =>
			expect( updateSettings ).toHaveBeenCalledWith(
				expect.objectContaining( { stocktake_days: 1 } )
			)
		);
	} );
} );
