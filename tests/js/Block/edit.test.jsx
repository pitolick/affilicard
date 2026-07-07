/**
 * Tests for src/Block/edit.jsx
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { Edit, renderComboboxItem } from '../../../src/Block/edit';
import * as productsApi from '../../../src/Admin/api/products';

jest.mock( '../../../src/Admin/api/products' );

describe( 'Edit', () => {
	beforeEach( () => {
		productsApi.searchProducts.mockResolvedValue( [
			{ id: 7, title: 'サンプル漫画 1巻', status: 'publish' },
		] );
		productsApi.getProduct.mockResolvedValue( {
			id: 7,
			title: 'サンプル漫画 1巻',
		} );
		productsApi.getCardPreview.mockResolvedValue( {
			html: '<div class="affilicard-card">プレビュー本文</div>',
		} );
	} );

	const setup = ( attributes = {} ) => {
		const setAttributes = jest.fn();
		render(
			<Edit attributes={ attributes } setAttributes={ setAttributes } />
		);
		return { setAttributes };
	};

	test( 'shows combobox when no product selected', () => {
		setup();
		expect(
			screen.getByLabelText( '商品を検索', { selector: 'input' } )
		).toBeInTheDocument();
	} );

	test( 'searches products on filter change', async () => {
		setup();
		fireEvent.change(
			screen.getByLabelText( '商品を検索', { selector: 'input' } ),
			{ target: { value: 'サンプル' } }
		);
		await waitFor( () =>
			expect( productsApi.searchProducts ).toHaveBeenCalledWith(
				expect.objectContaining( { search: 'サンプル' } )
			)
		);
	} );

	test( 'sets productId attribute on selection', async () => {
		const { setAttributes } = setup();
		fireEvent.change(
			screen.getByLabelText( '商品を検索', { selector: 'input' } ),
			{ target: { value: 'サンプル' } }
		);
		const option = await screen.findByText( /サンプル漫画 1巻/ );
		fireEvent.click( option );
		expect( setAttributes ).toHaveBeenCalledWith( { productId: 7 } );
	} );

	// 旧: プレースホルダで商品タイトルを表示していた。
	// 新: getCardPreview が返す HTML でプレビューを描画する。
	test( 'shows preview HTML when productId set', async () => {
		setup( { productId: 7 } );
		await waitFor( () =>
			expect( screen.getByText( 'プレビュー本文' ) ).toBeInTheDocument()
		);
		expect( productsApi.getCardPreview ).toHaveBeenCalledWith( 7, {
			hidePlatforms: [],
			onlyPlatforms: [],
			ctaLabelOverrides: {},
			ctaBgColor: undefined,
			ctaTextColor: undefined,
			cardBgColor: undefined,
			cardBorderColor: undefined,
			maskBlur: undefined,
			maskR18: undefined,
			maskLabel: undefined,
		} );
	} );

	test( 'passes attribute params to getCardPreview', async () => {
		setup( {
			productId: 7,
			hidePlatforms: [ 'dmm-books' ],
			ctaLabelOverrides: { 'dmm-books': '購入' },
			ctaBgColor: '#ff0000',
			ctaTextColor: '#ffffff',
		} );
		await waitFor( () =>
			expect( screen.getByText( 'プレビュー本文' ) ).toBeInTheDocument()
		);
		expect( productsApi.getCardPreview ).toHaveBeenCalledWith(
			7,
			expect.objectContaining( {
				hidePlatforms: [ 'dmm-books' ],
				ctaLabelOverrides: { 'dmm-books': '購入' },
				ctaBgColor: '#ff0000',
				ctaTextColor: '#ffffff',
			} )
		);
	} );

	test( 'renders color palette controls in inspector', async () => {
		setup( { productId: 7 } );
		// getCardPreview の解決を待ってから色設定 UI を確認する。
		await waitFor( () =>
			expect( screen.getByText( 'プレビュー本文' ) ).toBeInTheDocument()
		);
		expect( screen.getAllByText( /色/ ).length ).toBeGreaterThan( 0 );
	} );

	test( 'updates ctaBgColor via color palette', async () => {
		const { setAttributes } = setup( { productId: 7 } );
		// getCardPreview の解決を待ってから操作する。
		await waitFor( () =>
			expect( screen.getByText( 'プレビュー本文' ) ).toBeInTheDocument()
		);
		const palettes = document.querySelectorAll( '[data-color-palette]' );
		fireEvent.change( palettes[ 0 ], { target: { value: '#ff0000' } } );
		expect( setAttributes ).toHaveBeenCalledWith(
			expect.objectContaining( { ctaBgColor: '#ff0000' } )
		);
	} );

	test( 'shows toolbar button to change product when productId set', async () => {
		setup( { productId: 7 } );
		await waitFor( () =>
			expect( screen.getByText( 'プレビュー本文' ) ).toBeInTheDocument()
		);
		expect( screen.getByText( '商品を変更' ) ).toBeInTheDocument();
	} );

	test( 'clicking "商品を変更" calls setAttributes with undefined productId', async () => {
		const { setAttributes } = setup( { productId: 7 } );
		await waitFor( () =>
			expect( screen.getByText( 'プレビュー本文' ) ).toBeInTheDocument()
		);
		fireEvent.click( screen.getByText( '商品を変更' ) );
		expect( setAttributes ).toHaveBeenCalledWith( { productId: undefined } );
	} );

	test( 'shows error message when getCardPreview rejects', async () => {
		productsApi.getCardPreview.mockRejectedValue( new Error( 'network error' ) );
		setup( { productId: 7 } );
		await waitFor( () =>
			expect(
				screen.getByText( 'プレビューを取得できませんでした。' )
			).toBeInTheDocument()
		);
	} );

	test( 'does not show stale HTML when getCardPreview rejects', async () => {
		productsApi.getCardPreview.mockRejectedValue( new Error( 'network error' ) );
		setup( { productId: 7 } );
		await waitFor( () =>
			expect(
				screen.getByText( 'プレビューを取得できませんでした。' )
			).toBeInTheDocument()
		);
		// エラー時は previewHtml がリセットされるためプレビュー本文は表示されない
		expect( screen.queryByText( 'プレビュー本文' ) ).not.toBeInTheDocument();
		// 空メッセージも表示されない（error 状態では idle 条件が false）
		expect(
			screen.queryByText( 'プレビューする内容がありません。' )
		).not.toBeInTheDocument();
	} );

	// Task 6: CTA ラベル上書きパネル
	describe( 'CTA ラベル上書きパネル', () => {
		beforeEach( () => {
			// 有効な listing を持つ商品を返すよう getProduct を上書き
			productsApi.getProduct.mockResolvedValue( {
				id: 7,
				title: 'サンプル漫画 1巻',
				listings: [ { platform: 'dmm-books', enabled: true } ],
			} );
		} );

		test( 'listing のプラットフォームごとに CTA ラベル上書き欄が出る', async () => {
			setup( { productId: 7 } );
			// 表示プラットフォームの checkbox も同名ラベルなので role で textbox に限定する。
			expect(
				await screen.findByRole( 'textbox', { name: 'dmm-books' } )
			).toBeInTheDocument();
		} );

		test( '値入力で setAttributes が ctaLabelOverrides に code を含めて呼ばれる', async () => {
			const { setAttributes } = setup( { productId: 7 } );
			const input = await screen.findByRole( 'textbox', { name: 'dmm-books' } );
			fireEvent.change( input, { target: { value: '今すぐ読む' } } );
			expect( setAttributes ).toHaveBeenCalledWith( {
				ctaLabelOverrides: { 'dmm-books': '今すぐ読む' },
			} );
		} );

		test( '空入力で setAttributes が該当 code を含まない object で呼ばれる', async () => {
			const { setAttributes } = setup( {
				productId: 7,
				ctaLabelOverrides: { 'dmm-books': '購入する' },
			} );
			const input = await screen.findByRole( 'textbox', { name: 'dmm-books' } );
			fireEvent.change( input, { target: { value: '' } } );
			const calls = setAttributes.mock.calls.filter( ( c ) =>
				Object.prototype.hasOwnProperty.call( c[ 0 ], 'ctaLabelOverrides' )
			);
			expect( calls.length ).toBeGreaterThan( 0 );
			const lastCall = calls[ calls.length - 1 ];
			expect( lastCall[ 0 ].ctaLabelOverrides ).not.toHaveProperty( 'dmm-books' );
		} );

		test( 'listing がない場合は CTA ラベル上書きパネルが表示されない', async () => {
			productsApi.getProduct.mockResolvedValue( {
				id: 7,
				title: 'サンプル漫画 1巻',
				listings: [],
			} );
			setup( { productId: 7 } );
			await waitFor( () =>
				expect( productsApi.getCardPreview ).toHaveBeenCalled()
			);
			expect( screen.queryByText( 'CTA ラベル上書き' ) ).not.toBeInTheDocument();
		} );
	} );

	// 表示プラットフォーム（onlyPlatforms）パネル
	describe( '表示プラットフォームパネル', () => {
		beforeEach( () => {
			productsApi.getProduct.mockResolvedValue( {
				id: 7,
				title: 'サンプル漫画 1巻',
				listings: [
					{ platform: 'dmm-books', enabled: true },
					{ platform: 'example-store', enabled: true },
				],
			} );
		} );

		test( 'listing のプラットフォームごとに表示チェックボックスが出る', async () => {
			setup( { productId: 7 } );
			expect(
				await screen.findByRole( 'checkbox', { name: 'dmm-books' } )
			).toBeInTheDocument();
			expect(
				screen.getByRole( 'checkbox', { name: 'example-store' } )
			).toBeInTheDocument();
		} );

		test( 'チェックで setAttributes が onlyPlatforms に code を追加して呼ばれる', async () => {
			const { setAttributes } = setup( { productId: 7 } );
			const checkbox = await screen.findByRole( 'checkbox', {
				name: 'dmm-books',
			} );
			fireEvent.click( checkbox );
			expect( setAttributes ).toHaveBeenCalledWith( {
				onlyPlatforms: [ 'dmm-books' ],
			} );
		} );

		test( '再クリックで setAttributes が onlyPlatforms から code を除去して呼ばれる', async () => {
			const { setAttributes } = setup( {
				productId: 7,
				onlyPlatforms: [ 'dmm-books' ],
			} );
			const checkbox = await screen.findByRole( 'checkbox', {
				name: 'dmm-books',
			} );
			fireEvent.click( checkbox );
			expect( setAttributes ).toHaveBeenCalledWith( { onlyPlatforms: [] } );
		} );

		test( 'onlyPlatforms がプレビュー（getCardPreview）に渡る', async () => {
			setup( { productId: 7, onlyPlatforms: [ 'dmm-books' ] } );
			await waitFor( () =>
				expect( productsApi.getCardPreview ).toHaveBeenCalledWith(
					7,
					expect.objectContaining( { onlyPlatforms: [ 'dmm-books' ] } )
				)
			);
		} );
	} );

	// Task 7: 最近商品とリッチ表示
	describe( '最近商品とリッチ表示', () => {
		beforeEach( () => {
			// Task 7 用のリッチデータを返すモック
			productsApi.searchProducts.mockResolvedValue( [
				{
					id: 7,
					title: 'サンプル漫画 1巻',
					status: 'publish',
					thumbnail: 'https://example.com/thumb.jpg',
					platform: 'dmm-books',
					price: '500',
				},
			] );
		} );

		test( '未選択時は空入力でも searchProducts が呼ばれる', async () => {
			setup();
			await waitFor( () =>
				expect( productsApi.searchProducts ).toHaveBeenCalledWith(
					expect.objectContaining( { search: '' } )
				)
			);
		} );

		test( '最近商品が候補リストに表示される', async () => {
			setup();
			await waitFor( () =>
				expect( productsApi.searchProducts ).toHaveBeenCalled()
			);
			// options に item が含まれ、__experimentalRenderItem で platform が表示される
			const platformText = await screen.findByText( 'dmm-books' );
			expect( platformText ).toBeInTheDocument();
		} );

		test( '__experimentalRenderItem で価格が表示される', async () => {
			setup();
			await waitFor( () =>
				expect( productsApi.searchProducts ).toHaveBeenCalled()
			);
			const priceText = await screen.findByText( '¥500' );
			expect( priceText ).toBeInTheDocument();
		} );

		test( 'item.item あり（raw データ付き）の場合はリッチ表示でタイトルが出る', async () => {
			// searchProducts が thumbnail/platform/price を含むアイテムを返す場合は
			// __experimentalRenderItem によってリッチ表示（item.item.title）が使われる。
			// フォールバック（<span>{label}</span>）には到達しない。
			setup();
			await waitFor( () =>
				expect( productsApi.searchProducts ).toHaveBeenCalled()
			);
			// リッチ表示: item.item.title がレンダリングされる
			const titleText = await screen.findByText( 'サンプル漫画 1巻' );
			expect( titleText ).toBeInTheDocument();
		} );

		test( 'perPage が 20 で searchProducts が呼ばれる（統合）', async () => {
			setup();
			await waitFor( () =>
				expect( productsApi.searchProducts ).toHaveBeenCalledWith(
					expect.objectContaining( { perPage: 20 } )
				)
			);
		} );

		test( '空入力 fetch 後に候補を選択すると productId がセットされる', async () => {
			const { setAttributes } = setup();
			await waitFor( () =>
				expect( productsApi.searchProducts ).toHaveBeenCalled()
			);
			const button = await screen.findByText( 'dmm-books' );
			// button の親の button（option ボタン）をクリック
			fireEvent.click( button.closest( 'button' ) );
			expect( setAttributes ).toHaveBeenCalledWith( { productId: 7 } );
		} );
	} );

	// Task 5: 表紙マスクパネル
	describe( '表紙マスクパネル', () => {
		test( '表紙マスクのトグルで属性を設定する', async () => {
			const setAttributes = jest.fn();
			render( <Edit attributes={ { productId: 5 } } setAttributes={ setAttributes } /> );
			// 「表紙にぼかしを掛ける」チェックボックス
			// productId 選択時の非同期 effect（getProduct / getCardPreview）が act() 外で
			// state 更新しないよう、findByLabelText で解決を待ってから操作する。
			fireEvent.click( await screen.findByLabelText( '表紙にぼかしを掛ける' ) );
			expect( setAttributes ).toHaveBeenCalledWith( { maskBlur: true } );
		} );

		test( 'R18 ラベル入力で maskLabel を設定する', async () => {
			const setAttributes = jest.fn();
			render( <Edit attributes={ { productId: 5 } } setAttributes={ setAttributes } /> );
			fireEvent.change( await screen.findByLabelText( 'ラベルテキスト（任意）' ), {
				target: { value: '成人向け表現を含みます' },
			} );
			expect( setAttributes ).toHaveBeenCalledWith( { maskLabel: '成人向け表現を含みます' } );
		} );
	} );

	describe( 'ブロック選択とプレビュー非インタラクティブ化', () => {
		test( '商品選択時のルートに useBlockProps が適用される', async () => {
			setup( { productId: 7 } );
			await screen.findByText( 'プレビュー本文' );
			const root = document.querySelector(
				'.affilicard-block-preview'
			);
			expect( root ).not.toBeNull();
			// useBlockProps（mock）が付与する data 属性がルートに spread されている＝選択配線済み
			expect( root.getAttribute( 'data-block-props' ) ).toBe( 'applied' );
		} );

		test( '商品未選択時のルートにも useBlockProps が適用される', () => {
			setup();
			const root = document.querySelector(
				'.affilicard-block-placeholder'
			);
			expect( root ).not.toBeNull();
			expect( root.getAttribute( 'data-block-props' ) ).toBe( 'applied' );
		} );

		test( 'プレビュー描画は pointer-events 無効化用クラスでラップされる', async () => {
			setup( { productId: 7 } );
			const rendered = await screen.findByText( 'プレビュー本文' );
			// プレビュー HTML は .affilicard-block-preview__rendered 配下に挿入される
			// （block-editor.css で pointer-events: none → クリックはブロック本体へ通る／CTA 誤遷移防止）
			expect(
				rendered.closest( '.affilicard-block-preview__rendered' )
			).not.toBeNull();
		} );
	} );
} );

// renderComboboxItem 純粋関数の直接テスト（I-1 対応: フォールバック分岐の直接検証）
describe( 'renderComboboxItem', () => {
	test( 'option.item あり: title / platform / price が描画される（リッチ表示）', () => {
		const option = {
			value: 7,
			label: 'サンプル漫画 1巻 (#7)',
			item: {
				id: 7,
				title: 'サンプル漫画 1巻',
				platform: 'dmm-books',
				price: '500',
				thumbnail: '',
			},
		};
		render( renderComboboxItem( option ) );
		expect( screen.getByText( 'サンプル漫画 1巻' ) ).toBeInTheDocument();
		expect( screen.getByText( 'dmm-books' ) ).toBeInTheDocument();
		expect( screen.getByText( '¥500' ) ).toBeInTheDocument();
	} );

	test( 'option.item 無し: label のフォールバック表示になり platform/price は出ない', () => {
		const option = {
			value: 99,
			label: 'フォールバックタイトル (#99)',
			// item プロパティなし
		};
		render( renderComboboxItem( option ) );
		expect( screen.getByText( 'フォールバックタイトル (#99)' ) ).toBeInTheDocument();
		// platform・price 等のリッチ要素は出ない
		expect( screen.queryByText( 'dmm-books' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( /¥/ ) ).not.toBeInTheDocument();
	} );
} );
