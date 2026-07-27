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

	test( 'renders platform editors flat (no card container, API 認証 と統一)', async () => {
		fetchPlatforms.mockResolvedValue( platforms );
		const { container } = render( <PlatformsPanel /> );
		await waitFor( () =>
			expect( screen.getByText( /DMM \(dmm\)/ ) ).toBeInTheDocument()
		);
		// カード型の Panel ラッパを廃止し、アコーディオンを直接並べる（API 認証タブと同じ見た目）。
		expect(
			container.querySelector( '[data-panel-container="true"]' )
		).not.toBeInTheDocument();
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

	test( 'renders product-type sub-tabs and an API auth tab', async () => {
		const withVod = [
			...platforms,
			{
				code: 'u-next',
				name: 'U-NEXT',
				provider: 'manual',
				enabled: true,
				displayOrder: 5,
				applicableTypes: [ 'vod' ],
				buttonLabel: 'U-NEXTで見る',
				brandColor: '#000',
				buttonTextColor: '#fff',
			},
		];
		fetchPlatforms.mockResolvedValue( withVod );
		render( <PlatformsPanel /> );
		expect(
			await screen.findByRole( 'tab', { name: '電子書籍' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'tab', { name: 'VOD' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'tab', { name: 'API 認証' } )
		).toBeInTheDocument();
	} );
	// 並べ替え検証用: 有効 a(1) / 無効 b(2) / 有効 c(3)。
	// 無効行を挟むことで「無効を飛ばして有効同士が入れ替わる」ことを検証できる。
	const orderable = [
		{
			code: 'a',
			name: 'ストアA',
			provider: 'manual',
			enabled: true,
			displayOrder: 1,
			applicableTypes: [ 'ebook' ],
			buttonLabel: 'Aで読む',
			brandColor: '#444',
			buttonTextColor: '#fff',
		},
		{
			code: 'b',
			name: 'ストアB',
			provider: 'manual',
			enabled: false,
			displayOrder: 2,
			applicableTypes: [ 'ebook' ],
			buttonLabel: 'Bで読む',
			brandColor: '#444',
			buttonTextColor: '#fff',
		},
		{
			code: 'c',
			name: 'ストアC',
			provider: 'manual',
			enabled: true,
			displayOrder: 3,
			applicableTypes: [ 'ebook' ],
			buttonLabel: 'Cで読む',
			brandColor: '#444',
			buttonTextColor: '#fff',
		},
	];

	const renderOrderable = async () => {
		fetchPlatforms.mockResolvedValue( orderable );
		const view = render( <PlatformsPanel /> );
		await screen.findByText( /ストアA \(a\)/ );
		return view;
	};

	const rowCodes = ( container ) =>
		Array.from(
			container.querySelectorAll( '.affilicard-platform-row' )
		).map( ( row ) => row.dataset.platformCode );

	test( '並び順の説明文をタブ内に表示する', async () => {
		await renderOrderable();
		expect(
			screen.getByText( /この順番で商品カードのボタンが上から並びます/ )
		).toBeInTheDocument();
		expect(
			screen.getByText( /無効なプラットフォームはカードに表示されない/ )
		).toBeInTheDocument();
		expect(
			screen.getByText( /公開済みの記事のカードにも反映されます/ )
		).toBeInTheDocument();
	} );

	test( '有効な platform には順位バッジ、無効には — を出す', async () => {
		const { container } = await renderOrderable();
		const ranks = Array.from(
			container.querySelectorAll( '.affilicard-platform-row__rank' )
		).map( ( el ) => el.textContent );
		expect( ranks ).toEqual( [ '1', '—', '2' ] );
	} );

	test( '無効な platform には並べ替えボタンを出さない', async () => {
		await renderOrderable();
		expect(
			screen.queryByRole( 'button', { name: 'ストアBを上へ移動' } )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'ストアBを下へ移動' } )
		).not.toBeInTheDocument();
	} );

	test( '↓ を押すと無効行を飛ばして次の有効行と入れ替わる', async () => {
		const { container } = await renderOrderable();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'ストアAを下へ移動' } )
		);
		expect( rowCodes( container ) ).toEqual( [ 'c', 'b', 'a' ] );
	} );

	test( '↑ を押すと前の有効行と入れ替わる', async () => {
		const { container } = await renderOrderable();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'ストアCを上へ移動' } )
		);
		expect( rowCodes( container ) ).toEqual( [ 'c', 'b', 'a' ] );
	} );

	test( '先頭の ↑ と末尾の ↓ は disabled', async () => {
		await renderOrderable();
		expect(
			screen.getByRole( 'button', { name: 'ストアAを上へ移動' } )
		).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: 'ストアCを下へ移動' } )
		).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: 'ストアAを下へ移動' } )
		).toBeEnabled();
	} );

	test( '並べ替えの結果を aria-live で通知する', async () => {
		const { container } = await renderOrderable();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'ストアAを下へ移動' } )
		);
		expect(
			container.querySelector( '[aria-live="polite"]' )
		).toHaveTextContent( 'ストアAを 2 番目に移動しました' );
	} );

	test( '並べ替えて保存すると displayOrder が 1..N の連番で送られる', async () => {
		updatePlatforms.mockResolvedValue( orderable );
		await renderOrderable();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'ストアAを下へ移動' } )
		);
		fireEvent.click( screen.getByRole( 'button', { name: '保存' } ) );
		await waitFor( () => expect( updatePlatforms ).toHaveBeenCalled() );
		const sent = updatePlatforms.mock.calls[ 0 ][ 0 ];
		expect( sent.map( ( p ) => p.code ) ).toEqual( [ 'c', 'b', 'a' ] );
		expect( sent.map( ( p ) => p.displayOrder ) ).toEqual( [ 1, 2, 3 ] );
	} );

	test( 'Element.prototype.animate が無い環境でも並べ替えは成立する', async () => {
		// jsdom には animate が無い。アニメーションは装飾であり機能の前提にしない。
		expect( typeof Element.prototype.animate ).toBe( 'undefined' );
		const { container } = await renderOrderable();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'ストアAを下へ移動' } )
		);
		expect( rowCodes( container ) ).toEqual( [ 'c', 'b', 'a' ] );
	} );

	test( '端に到達してボタンが disabled になったら同じ行のもう一方へフォーカスを移す', async () => {
		await renderOrderable();
		// ストアAを下へ移動すると A は末尾の有効行になり、A の ↓ が disabled になる。
		fireEvent.click(
			screen.getByRole( 'button', { name: 'ストアAを下へ移動' } )
		);
		// 前提条件: 押した本人のボタンが disabled になっていること。
		expect(
			screen.getByRole( 'button', { name: 'ストアAを下へ移動' } )
		).toBeDisabled();
		// requestAnimationFrame の中で行われるフォーカス移送を待つ。
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'ストアAを上へ移動' } )
			).toHaveFocus()
		);
	} );

	// 非連番 displayOrder フィクスチャ。正規化の検出力をテストするため、
	// displayOrder が [5, 7, 9] という欠番フィクスチャ。
	const nonSequentialOrderable = [
		{
			code: 'x',
			name: 'ストアX',
			provider: 'manual',
			enabled: true,
			displayOrder: 5,
			applicableTypes: [ 'ebook' ],
			buttonLabel: 'Xで読む',
			brandColor: '#444',
			buttonTextColor: '#fff',
		},
		{
			code: 'y',
			name: 'ストアY',
			provider: 'manual',
			enabled: true,
			displayOrder: 7,
			applicableTypes: [ 'ebook' ],
			buttonLabel: 'Yで読む',
			brandColor: '#444',
			buttonTextColor: '#fff',
		},
		{
			code: 'z',
			name: 'ストアZ',
			provider: 'manual',
			enabled: true,
			displayOrder: 9,
			applicableTypes: [ 'ebook' ],
			buttonLabel: 'Zで読む',
			brandColor: '#444',
			buttonTextColor: '#fff',
		},
	];

	const renderNonSequentialOrderable = async () => {
		fetchPlatforms.mockResolvedValue( nonSequentialOrderable );
		const view = render( <PlatformsPanel /> );
		await screen.findByText( /ストアX \(x\)/ );
		return view;
	};

	test( '並べ替えずに保存したときは displayOrder を振り直さない', async () => {
		// 正規化は並べ替え時にだけ起きるため、↑ / ↓ を一度も押さずに保存すると、
		// フィクスチャの非連番 displayOrder 値 [5, 7, 9] のまま保存される。
		// もし onSave が誤って renumberDisplayOrder() を呼ぶようになれば、[1, 2, 3] に変わるため即座に落ちる。
		updatePlatforms.mockResolvedValue( nonSequentialOrderable );
		await renderNonSequentialOrderable();
		fireEvent.click( screen.getByRole( 'button', { name: '保存' } ) );
		await waitFor( () => expect( updatePlatforms ).toHaveBeenCalled() );
		const sent = updatePlatforms.mock.calls[ 0 ][ 0 ];
		expect( sent.map( ( p ) => p.displayOrder ) ).toEqual( [ 5, 7, 9 ] );
	} );
} );
