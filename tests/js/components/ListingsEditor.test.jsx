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
		expect( arg[ 0 ].update_mode ).toBe( 'manual' );
		expect( arg[ 0 ].auto_update ).toBe( false );
	} );

	test( 'update_mode "api" reveals 自動更新 toggle', async () => {
		fetchPlatforms.mockResolvedValue( platforms );
		const listings = [
			{
				platform: 'dmm-books',
				enabled: true,
				update_mode: 'api',
				auto_update: true,
				external_id: '111',
				regular_url: '',
				affiliate_url: 'https://a',
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
		expect( screen.getByText( '自動更新' ) ).toBeInTheDocument();
	} );

	test( 'update_mode "manual" hides 自動更新 toggle', async () => {
		fetchPlatforms.mockResolvedValue( platforms );
		const listings = [
			{
				platform: 'dmm-books',
				enabled: true,
				update_mode: 'manual',
				auto_update: false,
				external_id: '111',
				regular_url: '',
				affiliate_url: 'https://a',
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
		expect( screen.queryByText( '自動更新' ) ).not.toBeInTheDocument();
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
