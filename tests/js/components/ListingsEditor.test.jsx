/**
 * Tests for src/Admin/components/ListingsEditor.jsx
 */

jest.mock( '../../../src/Admin/api/platforms' );

import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { ListingsEditor } from '../../../src/Admin/components/ListingsEditor';
import { fetchPlatforms } from '../../../src/Admin/api/platforms';

const platforms = [
	{ code: 'dmm-books', name: 'DMM Books' },
	{ code: 'amazon', name: 'Amazon' },
];

const EMPTY_LISTING_FIXTURE = {
	platform: '',
	enabled: true,
	update_mode: 'manual',
	auto_update: false,
	external_id: '',
	regular_url: '',
	affiliate_url: '',
	price: '',
	list_price: '',
	badge: '',
	image_url: '',
	button_label_override: '',
};

/** 1 件の listing fixture。上書きしたいフィールドだけ patch で渡す。 */
function listingWith( patch ) {
	return {
		...EMPTY_LISTING_FIXTURE,
		platform: 'dmm-books',
		external_id: '111',
		affiliate_url: 'https://a-aff',
		price: '500',
		...patch,
	};
}

beforeEach( () => {
	fetchPlatforms.mockReset();
} );

describe( 'ListingsEditor', () => {
	test( 'shows loading state while platforms not loaded yet', () => {
		fetchPlatforms.mockReturnValue( new Promise( () => {} ) );
		render( <ListingsEditor listings={ [] } onChange={ () => {} } /> );
		expect(
			screen.getByText( 'プラットフォーム読み込み中…' )
		).toBeInTheDocument();
	} );

	test( 'renders empty message when no listings after platforms load', async () => {
		fetchPlatforms.mockResolvedValue( platforms );
		render( <ListingsEditor listings={ [] } onChange={ () => {} } /> );
		await waitFor( () =>
			expect(
				screen.getByText( 'listing がありません' )
			).toBeInTheDocument()
		);
	} );

	test( 'renders one listing block per listing after platforms load', async () => {
		fetchPlatforms.mockResolvedValue( platforms );
		const listings = [
			{
				platform: 'dmm-books',
				enabled: true,
				update_mode: 'manual',
				auto_update: false,
				external_id: '111',
				regular_url: 'https://a',
				affiliate_url: 'https://a-aff',
				price: '500',
				list_price: '',
				badge: '',
				image_url: '',
				button_label_override: '',
			},
			{
				platform: 'amazon',
				enabled: true,
				update_mode: 'manual',
				auto_update: false,
				external_id: '222',
				regular_url: 'https://b',
				affiliate_url: 'https://b-aff',
				price: '600',
				list_price: '',
				badge: '',
				image_url: '',
				button_label_override: '',
			},
		];
		render(
			<ListingsEditor listings={ listings } onChange={ () => {} } />
		);
		await waitFor( () =>
			expect( fetchPlatforms ).toHaveBeenCalled()
		);
		// Two listings produce two select controls labelled "プラットフォーム"
		const platformLabels = screen.getAllByText( 'プラットフォーム' );
		expect( platformLabels.length ).toBe( 2 );
	} );

	test( 'Fallback Notice shown when affiliate_url empty && regular_url set', async () => {
		fetchPlatforms.mockResolvedValue( platforms );
		const listings = [
			{
				platform: 'dmm-books',
				enabled: true,
				update_mode: 'manual',
				auto_update: false,
				external_id: '111',
				regular_url: 'https://a',
				affiliate_url: '',
				price: '500',
				list_price: '',
				badge: '',
				image_url: '',
				button_label_override: '',
			},
		];
		render(
			<ListingsEditor listings={ listings } onChange={ () => {} } />
		);
		await waitFor( () =>
			expect( fetchPlatforms ).toHaveBeenCalled()
		);
		expect(
			screen.getByText(
				/アフィリエイト URL 未設定、通常 URL にフォールバック中/
			)
		).toBeInTheDocument();
	} );

	test( 'no fallback Notice when affiliate_url is set', async () => {
		fetchPlatforms.mockResolvedValue( platforms );
		const listings = [
			{
				platform: 'dmm-books',
				enabled: true,
				update_mode: 'manual',
				auto_update: false,
				external_id: '111',
				regular_url: 'https://a',
				affiliate_url: 'https://a-aff',
				price: '500',
				list_price: '',
				badge: '',
				image_url: '',
				button_label_override: '',
			},
		];
		render(
			<ListingsEditor listings={ listings } onChange={ () => {} } />
		);
		await waitFor( () =>
			expect( fetchPlatforms ).toHaveBeenCalled()
		);
		expect(
			screen.queryByText(
				/アフィリエイト URL 未設定、通常 URL にフォールバック中/
			)
		).not.toBeInTheDocument();
	} );

	test( 'clicking "listing を追加" appends an EMPTY_LISTING', async () => {
		fetchPlatforms.mockResolvedValue( platforms );
		const onChange = jest.fn();
		render( <ListingsEditor listings={ [] } onChange={ onChange } /> );
		await waitFor( () =>
			expect( fetchPlatforms ).toHaveBeenCalled()
		);
		const addBtn = screen.getByRole( 'button', {
			name: 'listing を追加',
		} );
		fireEvent.click( addBtn );
		expect( onChange ).toHaveBeenCalledTimes( 1 );
		const arg = onChange.mock.calls[ 0 ][ 0 ];
		expect( arg.length ).toBe( 1 );
		expect( arg[ 0 ].platform ).toBe( '' );
		expect( arg[ 0 ].enabled ).toBe( true );
		// 追加した listing がそのまま自動更新の対象になるよう、既定は auto / ON。
		// （既定が 'manual' だと UI から追加した listing は永久に更新されない）
		expect( arg[ 0 ].update_mode ).toBe( 'auto' );
		expect( arg[ 0 ].auto_update ).toBe( true );
	} );

	test( '更新モードのセレクトは表示しない', async () => {
		fetchPlatforms.mockResolvedValue( platforms );
		render(
			<ListingsEditor
				listings={ [ listingWith( { update_mode: 'auto' } ) ] }
				onChange={ () => {} }
			/>
		);
		await waitFor( () => expect( fetchPlatforms ).toHaveBeenCalled() );
		expect( screen.queryByText( '更新モード' ) ).not.toBeInTheDocument();
	} );

	test( '自動更新 toggle は update_mode=auto の listing で表示される', async () => {
		fetchPlatforms.mockResolvedValue( platforms );
		render(
			<ListingsEditor
				listings={ [
					listingWith( { update_mode: 'auto', auto_update: true } ),
				] }
				onChange={ () => {} }
			/>
		);
		await waitFor( () => expect( fetchPlatforms ).toHaveBeenCalled() );
		expect( screen.getByText( '自動更新' ) ).toBeInTheDocument();
	} );

	test( '自動更新 toggle は update_mode=manual の listing でも表示される', async () => {
		// 自動取得の可否はプラットフォームの Provider 側で決まる。listing 側の
		// トグルはプラットフォームや過去の update_mode に関わらず常に出す（統一）。
		fetchPlatforms.mockResolvedValue( platforms );
		render(
			<ListingsEditor
				listings={ [
					listingWith( { update_mode: 'manual', auto_update: false } ),
				] }
				onChange={ () => {} }
			/>
		);
		await waitFor( () => expect( fetchPlatforms ).toHaveBeenCalled() );
		expect( screen.getByText( '自動更新' ) ).toBeInTheDocument();
	} );

	test( '自動更新 toggle の切り替えで auto_update が更新される', async () => {
		fetchPlatforms.mockResolvedValue( platforms );
		const onChange = jest.fn();
		render(
			<ListingsEditor
				listings={ [
					listingWith( { update_mode: 'auto', auto_update: true } ),
				] }
				onChange={ onChange }
			/>
		);
		await waitFor( () => expect( fetchPlatforms ).toHaveBeenCalled() );
		fireEvent.click( screen.getByLabelText( '自動更新' ) );
		expect( onChange ).toHaveBeenCalledTimes( 1 );
		expect( onChange.mock.calls[ 0 ][ 0 ][ 0 ].auto_update ).toBe( false );
	} );

	test( '自動更新 toggle の操作で legacy な update_mode を auto へ正す', async () => {
		// 旧 UI が書いた update_mode='manual' が残っていると、トグルを ON にしても
		// PHP 側で弾かれ、トグルが無言で効かない。トグルは自動更新の唯一のスイッチ
		// なので、操作時に update_mode も auto へ揃える。
		fetchPlatforms.mockResolvedValue( platforms );
		const onChange = jest.fn();
		render(
			<ListingsEditor
				listings={ [
					listingWith( { update_mode: 'manual', auto_update: false } ),
				] }
				onChange={ onChange }
			/>
		);
		await waitFor( () => expect( fetchPlatforms ).toHaveBeenCalled() );
		fireEvent.click( screen.getByLabelText( '自動更新' ) );
		const row = onChange.mock.calls[ 0 ][ 0 ][ 0 ];
		expect( row.auto_update ).toBe( true );
		expect( row.update_mode ).toBe( 'auto' );
	} );

	test( '自動更新 toggle に強制一括更新で更新される旨の注意が出る', async () => {
		fetchPlatforms.mockResolvedValue( platforms );
		render(
			<ListingsEditor
				listings={ [
					listingWith( { update_mode: 'auto', auto_update: false } ),
				] }
				onChange={ () => {} }
			/>
		);
		await waitFor( () => expect( fetchPlatforms ).toHaveBeenCalled() );
		expect( screen.getByText( /強制一括更新/ ) ).toBeInTheDocument();
	} );

	test( 'falls back to empty platforms list when fetchPlatforms rejects', async () => {
		fetchPlatforms.mockRejectedValue( new Error( 'fail' ) );
		render( <ListingsEditor listings={ [] } onChange={ () => {} } /> );
		await waitFor( () =>
			expect(
				screen.getByText( 'listing がありません' )
			).toBeInTheDocument()
		);
	} );

	test( 'renders each listing inside a PanelBody titled by platform name', async () => {
		fetchPlatforms.mockResolvedValue( platforms );
		const listings = [
			{
				platform: 'dmm-books',
				enabled: true,
				update_mode: 'manual',
				auto_update: false,
				external_id: '111',
				regular_url: '',
				affiliate_url: 'https://a-aff',
				price: '500',
				list_price: '',
				badge: '',
				image_url: '',
				button_label_override: '',
			},
			{ ...EMPTY_LISTING_FIXTURE },
		];
		const { container } = render(
			<ListingsEditor listings={ listings } onChange={ () => {} } />
		);
		await waitFor( () => expect( fetchPlatforms ).toHaveBeenCalled() );
		// listing ごとに 1 つの PanelBody
		expect( container.querySelectorAll( '[data-panel]' ) ).toHaveLength( 2 );
		// 選択済み行はプラットフォーム名がヘッダに出る
		expect(
			container.querySelector( '[data-panel="DMM Books"]' )
		).toBeTruthy();
		// 未選択行はフォールバック見出し
		expect(
			container.querySelector( '[data-panel="（プラットフォーム未選択）"]' )
		).toBeTruthy();
		// 先頭行は初期展開
		expect(
			container.querySelector( '[data-panel="DMM Books"]' ).getAttribute(
				'data-initial-open'
			)
		).toBe( 'true' );
	} );

	test( 'fallback listing gets a ⚠ prefix in its panel title', async () => {
		fetchPlatforms.mockResolvedValue( platforms );
		const listings = [
			{
				platform: 'dmm-books',
				enabled: true,
				update_mode: 'manual',
				auto_update: false,
				external_id: '111',
				regular_url: 'https://a',
				affiliate_url: '',
				price: '500',
				list_price: '',
				badge: '',
				image_url: '',
				button_label_override: '',
			},
		];
		const { container } = render(
			<ListingsEditor listings={ listings } onChange={ () => {} } />
		);
		await waitFor( () => expect( fetchPlatforms ).toHaveBeenCalled() );
		expect( container.querySelector( '[data-panel^="⚠"]' ) ).toBeTruthy();
	} );
} );
