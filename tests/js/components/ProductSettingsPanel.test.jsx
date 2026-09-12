jest.mock( '../../../src/Admin/api/platforms' );
jest.mock( '../../../src/Admin/api/settings' );

import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { setEntityMeta, _reset, getLastSetterCall, clearLastSetterCall } from '@wordpress/core-data';
import { fetchPlatforms } from '../../../src/Admin/api/platforms';
import { fetchSettings } from '../../../src/Admin/api/settings';
import { ProductSettingsPanel } from '../../../src/Admin/components/ProductSettingsPanel';

beforeEach( () => {
	_reset();
	fetchPlatforms.mockResolvedValue( [ { code: 'dmm-books', name: 'DMM Books' } ] );
	// ListingsEditor の「使用中」判定に使う GeneralSettings::fallbackOnTerminal()。
	// 既定 OFF（PHP 側の既定と揃える）。
	fetchSettings.mockResolvedValue( { fallback_on_terminal: false } );
} );

describe( 'ProductSettingsPanel', () => {
	test( '配列メタの listing をそのまま描画する', async () => {
		setEntityMeta( {
			affilicard_product_type: 'ebook',
			affilicard_stock_status: 'available',
			affilicard_listings: [
				{
					platform: 'dmm-books',
					enabled: true,
					offers: [ { display_order: 100, external_id: '', affiliate_url: 'https://a' } ],
				},
			],
			affilicard_extras: [],
		} );
		render( <ProductSettingsPanel /> );
		await waitFor( () => expect( fetchPlatforms ).toHaveBeenCalled() );
		expect( screen.getByDisplayValue( '電子書籍' ) ).toBeInTheDocument();
		// 購入リンクの入力欄は既定で畳まれているため、開いてから値を確認する
		// （external_id 未設定なのでプレースホルダ見出しが行タイトルになる）。
		await userEvent.click(
			screen.getByRole( 'button', { name: /（外部 ID 未設定）/ } )
		);
		expect( screen.getByDisplayValue( 'https://a' ) ).toBeInTheDocument();
	} );

	test( 'meta が未定義/非配列でも空配列で安全に描画する', async () => {
		setEntityMeta( { affilicard_listings: undefined, affilicard_extras: null } );
		render( <ProductSettingsPanel /> );
		await waitFor( () => expect( fetchPlatforms ).toHaveBeenCalled() );
		expect( screen.getByText( 'listing がありません' ) ).toBeInTheDocument();
	} );

	test( '発売日コントロールが描画される', async () => {
		setEntityMeta( {
			affilicard_product_type: 'ebook',
			affilicard_stock_status: 'available',
			affilicard_listings: [],
			affilicard_extras: [],
			affilicard_release_date: '2026-12-31',
		} );
		render( <ProductSettingsPanel /> );
		await waitFor( () => expect( fetchPlatforms ).toHaveBeenCalled() );
		const input = screen.getByLabelText( '発売日（予約商品・任意）' );
		expect( input ).toBeInTheDocument();
		expect( input ).toHaveValue( '2026-12-31' );
	} );

	test( '発売日の変更で affilicard_release_date が patch される', async () => {
		setEntityMeta( {
			affilicard_listings: [],
			affilicard_extras: [],
			affilicard_release_date: '',
		} );
		render( <ProductSettingsPanel /> );
		await waitFor( () => expect( fetchPlatforms ).toHaveBeenCalled() );
		const input = screen.getByLabelText( '発売日（予約商品・任意）' );
		clearLastSetterCall();
		fireEvent.change( input, { target: { value: '2027-03-01' } } );
		expect( input ).toHaveValue( '2027-03-01' );
		// meta setter が正しい affilicard_release_date を持つオブジェクトで呼ばれたことを確認
		expect( getLastSetterCall() ).toEqual( expect.objectContaining( {
			affilicard_release_date: '2027-03-01',
		} ) );
	} );

	test( 'マスク設定を meta に保存する', async () => {
		setEntityMeta( {
			affilicard_product_type: 'ebook',
			affilicard_stock_status: 'available',
			affilicard_listings: [],
			affilicard_extras: [],
			affilicard_mask_blur: false,
			affilicard_mask_r18: false,
			affilicard_mask_label: '',
		} );
		render( <ProductSettingsPanel /> );
		await waitFor( () => expect( fetchPlatforms ).toHaveBeenCalled() );
		clearLastSetterCall();
		fireEvent.click( screen.getByLabelText( '表紙にぼかしを掛ける' ) );
		expect( getLastSetterCall() ).toMatchObject( { affilicard_mask_blur: true } );
	} );

	// 回帰防止: setMeta(object) で meta が更新され再レンダーされること。
	// 以前 setMeta を更新関数形式にしたところ useEntityProp が関数を解釈せず
	// listing 追加が反映されない不具合があった（E2E で発覚）。
	test( '「listing を追加」で listing 行が追加される', async () => {
		setEntityMeta( { affilicard_listings: [], affilicard_extras: [] } );
		render( <ProductSettingsPanel /> );
		await waitFor( () => expect( fetchPlatforms ).toHaveBeenCalled() );
		expect( screen.getByText( 'listing がありません' ) ).toBeInTheDocument();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'listing を追加' } )
		);
		// 行が追加され、プラットフォーム選択が描画される
		expect(
			await screen.findByLabelText( 'プラットフォーム' )
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'listing がありません' )
		).not.toBeInTheDocument();
	} );
} );
