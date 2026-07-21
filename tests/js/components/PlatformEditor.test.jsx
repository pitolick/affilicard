/**
 * Tests for src/Admin/components/PlatformEditor.jsx
 */

jest.mock( '../../../src/Admin/api/refresh', () => ( {
	triggerRefresh: jest.fn(),
} ) );

// 実物の providers.js は window.affilicardProviders をモジュール読込時に
// 一度だけスナップショットする（WP が localize したデータを起動時に固定で
// 読む想定のため）。テストごとに window.affilicardProviders を差し替えたい
// ので、ここでは呼び出し時に都度参照する軽量モックに置き換える。
// PlatformEditor は useState (@wordpress/element) を使うため、
// jest.resetModules() + フレッシュ require で本物を都度読み直す手法は
// react インスタンスの二重化（Invalid hook call）を招き使えない。
jest.mock( '../../../src/Admin/providers', () => ( {
	providerLabel: ( code ) =>
		( globalThis.affilicardProviders || [] ).find(
			( p ) => p.code === code
		)?.label ?? code,
} ) );

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { PlatformEditor } from '../../../src/Admin/components/PlatformEditor';
import { triggerRefresh } from '../../../src/Admin/api/refresh';

const basePlatform = {
	code: 'dmm',
	name: 'DMM',
	provider: 'manual',
	enabled: true,
	displayOrder: 1,
	imagePriority: 10,
	applicableTypes: [ 'ebook' ],
	buttonLabel: '購入',
	brandColor: '#444444',
	buttonTextColor: '#ffffff',
};

describe( 'PlatformEditor', () => {
	afterEach( () => {
		delete window.affilicardProviders;
	} );

	test( 'eligibleProvider ありのとき自動取得トグルが provider ラベル付きで表示される', () => {
		window.affilicardProviders = [
			{
				code: 'manual',
				label: '手動入力',
				isAutomatic: false,
				accountCode: null,
			},
			{
				code: 'rakuten-kobo',
				label: '楽天Kobo API',
				isAutomatic: true,
				accountCode: 'rakuten',
			},
		];
		render(
			<PlatformEditor
				platform={ {
					...basePlatform,
					provider: 'manual',
					eligibleProvider: 'rakuten-kobo',
				} }
				onChange={ jest.fn() }
			/>
		);
		expect(
			screen.getByLabelText( '自動取得（楽天Kobo API）' )
		).toBeInTheDocument();
	} );

	test( 'eligibleProvider ありで provider=manual のときトグルは OFF', () => {
		window.affilicardProviders = [
			{
				code: 'manual',
				label: '手動入力',
				isAutomatic: false,
				accountCode: null,
			},
			{
				code: 'rakuten-kobo',
				label: '楽天Kobo API',
				isAutomatic: true,
				accountCode: 'rakuten',
			},
		];
		render(
			<PlatformEditor
				platform={ {
					...basePlatform,
					provider: 'manual',
					eligibleProvider: 'rakuten-kobo',
				} }
				onChange={ jest.fn() }
			/>
		);
		expect(
			screen.getByLabelText( '自動取得（楽天Kobo API）' )
		).not.toBeChecked();
	} );

	test( 'eligibleProvider ありで provider=eligibleProvider のときトグルは ON', () => {
		window.affilicardProviders = [
			{
				code: 'manual',
				label: '手動入力',
				isAutomatic: false,
				accountCode: null,
			},
			{
				code: 'rakuten-kobo',
				label: '楽天Kobo API',
				isAutomatic: true,
				accountCode: 'rakuten',
			},
		];
		render(
			<PlatformEditor
				platform={ {
					...basePlatform,
					provider: 'rakuten-kobo',
					eligibleProvider: 'rakuten-kobo',
				} }
				onChange={ jest.fn() }
			/>
		);
		expect(
			screen.getByLabelText( '自動取得（楽天Kobo API）' )
		).toBeChecked();
	} );

	test( '自動取得トグルを ON にすると provider に eligibleProvider を設定する', () => {
		window.affilicardProviders = [
			{
				code: 'manual',
				label: '手動入力',
				isAutomatic: false,
				accountCode: null,
			},
			{
				code: 'rakuten-kobo',
				label: '楽天Kobo API',
				isAutomatic: true,
				accountCode: 'rakuten',
			},
		];
		const onChange = jest.fn();
		render(
			<PlatformEditor
				platform={ {
					...basePlatform,
					provider: 'manual',
					eligibleProvider: 'rakuten-kobo',
				} }
				onChange={ onChange }
			/>
		);
		fireEvent.click(
			screen.getByLabelText( '自動取得（楽天Kobo API）' )
		);
		expect( onChange ).toHaveBeenCalledWith(
			expect.objectContaining( { provider: 'rakuten-kobo' } )
		);
	} );

	test( '自動取得トグルを OFF にすると provider を manual に戻す', () => {
		window.affilicardProviders = [
			{
				code: 'manual',
				label: '手動入力',
				isAutomatic: false,
				accountCode: null,
			},
			{
				code: 'rakuten-kobo',
				label: '楽天Kobo API',
				isAutomatic: true,
				accountCode: 'rakuten',
			},
		];
		const onChange = jest.fn();
		render(
			<PlatformEditor
				platform={ {
					...basePlatform,
					provider: 'rakuten-kobo',
					eligibleProvider: 'rakuten-kobo',
				} }
				onChange={ onChange }
			/>
		);
		fireEvent.click(
			screen.getByLabelText( '自動取得（楽天Kobo API）' )
		);
		expect( onChange ).toHaveBeenCalledWith(
			expect.objectContaining( { provider: 'manual' } )
		);
	} );

	test( 'eligibleProvider が空のときトグルは出ず手動入力の注記のみ表示する', () => {
		const onChange = jest.fn();
		render(
			<PlatformEditor
				platform={ { ...basePlatform, eligibleProvider: '' } }
				onChange={ onChange }
			/>
		);
		expect(
			screen.queryByText( /自動取得（/ )
		).not.toBeInTheDocument();
		expect(
			screen.getByText(
				'このプラットフォームは手動入力です（対応APIがありません）。'
			)
		).toBeInTheDocument();
	} );

	test( 'renders all editor controls with platform values', () => {
		const onChange = jest.fn();
		render(
			<PlatformEditor platform={ basePlatform } onChange={ onChange } />
		);
		expect( screen.getByLabelText( '有効' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( '表示名' ) ).toBeInTheDocument();
		expect(
			screen.getByLabelText( 'ボタンラベル' )
		).toBeInTheDocument();
		expect( screen.getByLabelText( '表示順' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'ブランド色' ) ).toBeInTheDocument();
		expect(
			screen.getByLabelText( 'ボタン文字色' )
		).toBeInTheDocument();
	} );

	test( 'onChange propagates patch to parent', () => {
		const onChange = jest.fn();
		render(
			<PlatformEditor platform={ basePlatform } onChange={ onChange } />
		);
		fireEvent.change( screen.getByLabelText( '表示名' ), {
			target: { value: 'DMM Books' },
		} );
		expect( onChange ).toHaveBeenCalledWith(
			expect.objectContaining( { name: 'DMM Books' } )
		);
	} );

	test( 'renders imagePriority input with platform value', () => {
		const onChange = jest.fn();
		render(
			<PlatformEditor platform={ basePlatform } onChange={ onChange } />
		);
		expect(
			screen.getByLabelText( '画像優先度（小さいほど優先）' )
		).toHaveValue( 10 );
	} );

	test( 'onChange propagates imagePriority patch to parent', () => {
		const onChange = jest.fn();
		render(
			<PlatformEditor platform={ basePlatform } onChange={ onChange } />
		);
		fireEvent.change(
			screen.getByLabelText( '画像優先度（小さいほど優先）' ),
			{
				target: { value: '20' },
			}
		);
		expect( onChange ).toHaveBeenCalledWith(
			expect.objectContaining( { imagePriority: 20 } )
		);
	} );

	test( 'onChange keeps 0 as a valid imagePriority instead of falling back to 999', () => {
		const onChange = jest.fn();
		render(
			<PlatformEditor platform={ basePlatform } onChange={ onChange } />
		);
		fireEvent.change(
			screen.getByLabelText( '画像優先度（小さいほど優先）' ),
			{
				target: { value: '0' },
			}
		);
		expect( onChange ).toHaveBeenCalledWith(
			expect.objectContaining( { imagePriority: 0 } )
		);
	} );

	test( 'does not render the credential editor inline', () => {
		const onChange = jest.fn();
		const platform = { ...basePlatform, provider: 'dmm-ebook' };
		render(
			<PlatformEditor platform={ platform } onChange={ onChange } />
		);
		expect( screen.queryByText( '認証情報' ) ).not.toBeInTheDocument();
		expect( screen.queryByLabelText( 'API ID' ) ).not.toBeInTheDocument();
	} );

	test( 'renders platform name and code as panel title', () => {
		const onChange = jest.fn();
		render(
			<PlatformEditor platform={ basePlatform } onChange={ onChange } />
		);
		expect( screen.getByText( 'DMM (dmm)' ) ).toBeInTheDocument();
	} );

	test( 'shows 無効 suffix in title when platform disabled', () => {
		const onChange = jest.fn();
		const disabled = { ...basePlatform, enabled: false };
		render(
			<PlatformEditor platform={ disabled } onChange={ onChange } />
		);
		expect( screen.getByText( 'DMM (dmm) — 無効' ) ).toBeInTheDocument();
	} );

	test( 'groups auto-fetch fields under an API section heading', () => {
		const onChange = jest.fn();
		render(
			<PlatformEditor
				platform={ { ...basePlatform, eligibleProvider: '' } }
				onChange={ onChange }
			/>
		);
		expect(
			screen.getByText( '価格の取得方法' )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'このプラットフォームは手動入力です（対応APIがありません）。'
			)
		).toBeInTheDocument();
	} );

	test( 'keeps the refresh button inside the API section', () => {
		const onChange = jest.fn();
		const { container } = render(
			<PlatformEditor platform={ basePlatform } onChange={ onChange } />
		);
		const apiSection = container.querySelector(
			'.affilicard-platform-editor__section--api'
		);
		expect( apiSection ).toBeInTheDocument();
		const refreshButton = apiSection?.querySelector( 'button' );
		expect( refreshButton ).toBeInTheDocument();
		expect( refreshButton ).toHaveTextContent(
			'今すぐこのプラットフォームを更新'
		);
	} );

	describe( '個別更新ボタンの feedback', () => {
		beforeEach( () => {
			triggerRefresh.mockReset();
			triggerRefresh.mockResolvedValue( { ok: true } );
		} );

		test( 'クリックで triggerRefresh(platform.code) を呼び、実行中は disabled かつ「更新中…」になり、完了後に成功通知が出る', async () => {
			let resolveRefresh;
			triggerRefresh.mockImplementation(
				() =>
					new Promise( ( resolve ) => {
						resolveRefresh = resolve;
					} )
			);
			const onChange = jest.fn();
			render(
				<PlatformEditor
					platform={ basePlatform }
					onChange={ onChange }
				/>
			);

			const button = screen.getByText(
				'今すぐこのプラットフォームを更新'
			);
			fireEvent.click( button );
			expect( triggerRefresh ).toHaveBeenCalledWith( 'dmm' );

			const updatingBtn = await screen.findByText( '更新中…' );
			expect( updatingBtn ).toBeDisabled();

			resolveRefresh( { ok: true } );

			expect(
				await screen.findByText( '更新しました。' )
			).toBeInTheDocument();
			await waitFor( () =>
				expect(
					screen.getByText( '今すぐこのプラットフォームを更新' )
				).not.toBeDisabled()
			);
		} );

		test( '失敗時はエラー通知を表示し、ボタンの disabled が解除される', async () => {
			triggerRefresh.mockRejectedValueOnce( new Error( 'network error' ) );
			const onChange = jest.fn();
			render(
				<PlatformEditor
					platform={ basePlatform }
					onChange={ onChange }
				/>
			);

			fireEvent.click(
				screen.getByText( '今すぐこのプラットフォームを更新' )
			);

			expect(
				await screen.findByText( '更新に失敗しました。' )
			).toBeInTheDocument();
			expect(
				screen.getByText( '今すぐこのプラットフォームを更新' )
			).not.toBeDisabled();
		} );
	} );
} );
