/**
 * Tests for src/Admin/components/ListingsEditor.jsx
 */

jest.mock( '../../../src/Admin/api/platforms' );

import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from '@wordpress/element';
import {
	ListingsEditor,
	selectInUseOffer,
	withNormalisedOffers,
} from '../../../src/Admin/components/ListingsEditor';
import { fetchPlatforms } from '../../../src/Admin/api/platforms';
import { resetLocaleData, setLocaleData } from '@wordpress/i18n';

const platforms = [
	{ code: 'dmm-books', name: 'DMM Books' },
	{ code: 'amazon', name: 'Amazon' },
];

const EMPTY_LISTING_FIXTURE = {
	platform: '',
	enabled: true,
	update_mode: 'manual',
	auto_update: false,
	button_label_override: '',
	offers: [],
};

/**
 * 1 件の listing fixture。上書きしたいフィールドだけ patch で渡す。
 * 購入リンク（offers）は listing の設定とは別の階層なので、patch で offers を
 * 渡さない限り既定で 1 件（affiliate_url / price 入り）を積んでおく。
 */
function listingWith( patch ) {
	const { offers, ...listingPatch } = patch || {};
	return {
		...EMPTY_LISTING_FIXTURE,
		platform: 'dmm-books',
		offers: offers ?? [
			{
				display_order: 100,
				external_id: '111',
				regular_url: '',
				affiliate_url: 'https://a-aff',
				price: '500',
				list_price: '',
				badge: '',
				image_url: '',
			},
		],
		...listingPatch,
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
			listingWith( { platform: 'dmm-books' } ),
			listingWith( { platform: 'amazon' } ),
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

	test( 'Fallback Notice shown when a offer has affiliate_url empty && regular_url set', async () => {
		fetchPlatforms.mockResolvedValue( platforms );
		const listings = [
			listingWith( {
				offers: [
					{
						display_order: 100,
						external_id: '111',
						regular_url: 'https://a',
						affiliate_url: '',
					},
				],
			} ),
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

	test( 'no fallback Notice when the offer affiliate_url is set', async () => {
		fetchPlatforms.mockResolvedValue( platforms );
		const listings = [
			listingWith( {
				offers: [
					{
						display_order: 100,
						external_id: '111',
						regular_url: 'https://a',
						affiliate_url: 'https://a-aff',
					},
				],
			} ),
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
		// 購入リンクはまだ 0 件（listing の設定と購入リンクは別の階層）。
		expect( arg[ 0 ].offers ).toEqual( [] );
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
			listingWith( { platform: 'dmm-books', offers: [] } ),
			{ ...EMPTY_LISTING_FIXTURE },
		];
		const { container } = render(
			<ListingsEditor listings={ listings } onChange={ () => {} } />
		);
		await waitFor( () => expect( fetchPlatforms ).toHaveBeenCalled() );
		// listing ごとに 1 つの PanelBody（購入リンクは 0 件の listing なので
		// 購入リンク用の入れ子 PanelBody は生えない）
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
} );

describe( 'ListingsEditor 購入リンク（offers）', () => {
	// このブロックのテストは platforms を直接渡し、fetchPlatforms の内部フェッチを
	// 経由しない（プラットフォーム読み込みの非同期待ちは上の describe で別途検証済み）。

	const twoOffers = [
		{ display_order: 10, external_id: 'sale', regular_url: 'https://example.test/sale' },
		{ display_order: 100, external_id: 'normal', regular_url: 'https://example.test/normal' },
	];

	const listingWithOffers = ( offers ) => [
		{ platform: 'rakuten-kobo', enabled: true, auto_update: true, offers },
	];

	test( '購入リンクを追加できる', async () => {
		const onChange = jest.fn();
		render(
			<ListingsEditor
				listings={ listingWithOffers( [] ) }
				platforms={ platforms }
				onChange={ onChange }
			/>
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: '購入リンクを追加' } )
		);

		expect( onChange.mock.calls.at( -1 )[ 0 ][ 0 ].offers ).toHaveLength( 1 );
	} );

	test( '↑ で購入リンクの順序が上がる', async () => {
		const onChange = jest.fn();
		render(
			<ListingsEditor
				listings={ listingWithOffers( twoOffers ) }
				platforms={ platforms }
				onChange={ onChange }
			/>
		);

		await userEvent.click(
			screen.getAllByRole( 'button', { name: '上へ移動' } )[ 1 ]
		);

		const offers = onChange.mock.calls.at( -1 )[ 0 ][ 0 ].offers;
		expect( offers[ 0 ].external_id ).toBe( 'normal' );
	} );

	test( '並べ替えると表示順が採番し直される', async () => {
		// 削除時は詰めないが、人が明示的に並べ替えたときは採番し直す。
		const onChange = jest.fn();
		render(
			<ListingsEditor
				listings={ listingWithOffers( twoOffers ) }
				platforms={ platforms }
				onChange={ onChange }
			/>
		);

		await userEvent.click(
			screen.getAllByRole( 'button', { name: '上へ移動' } )[ 1 ]
		);

		const offers = onChange.mock.calls.at( -1 )[ 0 ][ 0 ].offers;
		expect( offers[ 0 ].display_order ).toBeLessThan( offers[ 1 ].display_order );
	} );

	test( '同じ表示順が並んでいても ↑ で確実に入れ替わる', async () => {
		const onChange = jest.fn();
		const same = [
			{ display_order: 100, external_id: 'a', regular_url: 'https://example.test/a' },
			{ display_order: 100, external_id: 'b', regular_url: 'https://example.test/b' },
		];
		render(
			<ListingsEditor
				listings={ listingWithOffers( same ) }
				platforms={ platforms }
				onChange={ onChange }
			/>
		);

		await userEvent.click(
			screen.getAllByRole( 'button', { name: '上へ移動' } )[ 1 ]
		);

		expect( onChange.mock.calls.at( -1 )[ 0 ][ 0 ].offers[ 0 ].external_id ).toBe( 'b' );
	} );

	test( '削除しても残りの購入リンクの表示順は詰めない', async () => {
		// ルールは 2 つ：並べ替えは採番し直す／削除は詰めない。ここでは後者を固定する。
		// display_order は他の書き手（外部パイプライン等）が明示的に振った番号かもしれず、
		// 削除のたびに詰め直すとその意図を壊す。
		const onChange = jest.fn();
		const three = [
			{ display_order: 10, external_id: 'a', regular_url: 'https://example.test/a' },
			{ display_order: 50, external_id: 'b', regular_url: 'https://example.test/b' },
			{ display_order: 90, external_id: 'c', regular_url: 'https://example.test/c' },
		];
		render(
			<ListingsEditor
				listings={ listingWithOffers( three ) }
				platforms={ platforms }
				onChange={ onChange }
			/>
		);

		// 'b' の行を開いて、その行の「購入リンクを削除」を押す
		// （パネルは既定で閉じているため、開いている行にしかボタンは無い）。
		await userEvent.click( screen.getByRole( 'button', { name: /^b$/ } ) );
		await userEvent.click(
			screen.getByRole( 'button', { name: '購入リンクを削除' } )
		);

		const offers = onChange.mock.calls.at( -1 )[ 0 ][ 0 ].offers;
		expect( offers.map( ( o ) => o.external_id ) ).toEqual( [ 'a', 'c' ] );
		// 削除で残りの番号を詰め直していないこと（10, 90 のまま）。
		expect( offers.map( ( o ) => o.display_order ) ).toEqual( [ 10, 90 ] );
	} );

	test( '並べ替えても開閉状態が保たれる', async () => {
		// PanelBody の initialOpen は手動トグルまで毎レンダー読み直される。
		// 位置依存の値を渡すと、並べ替えで開閉が入れ替わる。
		//
		// onChange をスタブ（jest.fn()）のままにすると listings prop が実際には
		// 更新されず、並べ替えクリックは DOM に何の変化も起こさないまま
		// このテストは通ってしまう（並べ替え自体を検証しない、なにも固定しない
		// テストになる）。ここでは onChange を実際に state へ反映する
		// controlled wrapper で描画し、本当に並べ替えさせてから確認する。
		function Wrapper() {
			const [ listings, setListings ] = useState(
				listingWithOffers( twoOffers )
			);
			return (
				<ListingsEditor
					listings={ listings }
					platforms={ platforms }
					onChange={ setListings }
				/>
			);
		}
		render( <Wrapper /> );

		await userEvent.click( screen.getByRole( 'button', { name: /sale/ } ) ); // 1件目を開く
		expect( screen.getByLabelText( /通常 URL/ ) ).toHaveValue( 'https://example.test/sale' );

		// 並べ替え前は sale → normal の順。
		expect(
			screen.getAllByText( /^(sale|normal)$/ ).map( ( el ) => el.textContent )
		).toEqual( [ 'sale', 'normal' ] );

		await userEvent.click(
			screen.getAllByRole( 'button', { name: '上へ移動' } )[ 1 ]
		); // 並べ替える

		// 並べ替えが実際に起きたこと自体を確認する（vacuous にしないため）。
		expect(
			screen.getAllByText( /^(sale|normal)$/ ).map( ( el ) => el.textContent )
		).toEqual( [ 'normal', 'sale' ] );

		// 位置は入れ替わったが、開いているのは依然として 'sale' の行
		// （位置ではなく識別子に紐づく）。
		expect( screen.getByLabelText( /通常 URL/ ) ).toHaveValue( 'https://example.test/sale' );
	} );

	test( '外部 ID を入力してもパネルが閉じない（識別子が入力内容から導出されていないこと）', async () => {
		// 識別子を external_id 等の中身から導出すると、1 文字打つたびに識別子が
		// 変わって行が作り直され、開いていたパネルが閉じてフォーカスが失われる
		// （1 文字ごとに入力が止まる致命的な不具合になる）。
		function Wrapper() {
			const [ listings, setListings ] = useState(
				listingWithOffers( [
					{ display_order: 100, external_id: '', regular_url: 'https://example.test/new' },
				] )
			);
			return (
				<ListingsEditor
					listings={ listings }
					platforms={ platforms }
					onChange={ setListings }
				/>
			);
		}
		render( <Wrapper /> );

		// external_id 未設定なのでプレースホルダ見出しが行タイトルになる。
		await userEvent.click(
			screen.getByRole( 'button', { name: /（外部 ID 未設定）/ } )
		);

		const externalIdInput = screen.getByLabelText( '外部 ID' );
		await userEvent.type( externalIdInput, 'a' );
		// 1 文字目を打った直後も、通常 URL の入力欄が見えている
		// （パネルが閉じていない）ことを確認する。
		expect( screen.getByLabelText( /通常 URL/ ) ).toHaveValue( 'https://example.test/new' );

		await userEvent.type( externalIdInput, 'b' );
		expect( screen.getByLabelText( /通常 URL/ ) ).toHaveValue( 'https://example.test/new' );
	} );

	test( '使用中の購入リンクに印が付く', () => {
		render(
			<ListingsEditor
				listings={ listingWithOffers( twoOffers ) }
				platforms={ platforms }
				onChange={ jest.fn() }
			/>
		);

		const marks = screen.getAllByText( '使用中' );
		expect( marks ).toHaveLength( 1 );
	} );

	test( '設定 ON のとき terminal を飛ばした先に使用中が付く', () => {
		// PHP の OfferSelector と同じ規則であることを固定する。
		const offers = [
			{ display_order: 10, external_id: 'dead', regular_url: 'https://example.test/dead', fetch_status: 'terminal' },
			{ display_order: 100, external_id: 'alive', regular_url: 'https://example.test/alive' },
		];
		render(
			<ListingsEditor
				listings={ listingWithOffers( offers ) }
				platforms={ platforms }
				onChange={ jest.fn() }
				fallbackOnTerminal
			/>
		);

		expect( screen.getByTestId( 'offer-alive' ) ).toHaveTextContent( '使用中' );
	} );

	// D: PHP（OfferSelector）は display_order 昇順で選ぶのに、編集画面は配列の並び順で
	// 描画していた。配列の並びと display_order がずれている listing（外部パイプラインが
	// 書いた・過去 UI で並べ替えた等）では、「使用中」の印が付いている行が先頭に無く、
	// ↑↓ も配列の位置で動くため、見えている並びと実際の優先順位が食い違う。
	const unsortedOffers = [
		{ display_order: 100, external_id: 'later', regular_url: 'https://example.test/later' },
		{ display_order: 10, external_id: 'first', regular_url: 'https://example.test/first' },
	];

	/** 描画されている購入リンク行の data-testid を上から順に返す。 */
	const renderedOfferIds = ( container ) =>
		Array.from( container.querySelectorAll( '.affilicard-offer-row' ) ).map(
			( el ) => el.getAttribute( 'data-testid' )
		);

	test( '配列の並びではなく表示順で描画する', () => {
		const { container } = render(
			<ListingsEditor
				listings={ listingWithOffers( unsortedOffers ) }
				platforms={ platforms }
				onChange={ jest.fn() }
			/>
		);

		expect( renderedOfferIds( container ) ).toEqual( [
			'offer-first',
			'offer-later',
		] );
		// 「使用中」の印は必ず先頭の行に付く（PHP が選ぶのと同じ行）。
		expect(
			container.querySelectorAll( '.affilicard-offer-row' )[ 0 ]
		).toHaveTextContent( '使用中' );
	} );

	test( '↑↓ は配列の位置ではなく描画されている並びで動く', async () => {
		const onChange = jest.fn();
		const { container } = render(
			<ListingsEditor
				listings={ listingWithOffers( unsortedOffers ) }
				platforms={ platforms }
				onChange={ onChange }
			/>
		);

		// 描画上の先頭（= 使用中の 'first'）を下へ動かす。
		await userEvent.click(
			screen.getAllByRole( 'button', { name: '下へ移動' } )[ 0 ]
		);

		const offers = onChange.mock.calls.at( -1 )[ 0 ][ 0 ].offers;
		const byOrder = [ ...offers ]
			.sort( ( a, b ) => a.display_order - b.display_order )
			.map( ( o ) => o.external_id );
		expect( byOrder ).toEqual( [ 'later', 'first' ] );
	} );

	test.each( [
		[ 'unsupported', '自動取得の対象外です' ],
		[ 'transient', '一時的に取得できませんでした' ],
		[ 'terminal', '商品が見つかりません' ],
	] )( 'fetch_status %s に文言が出る', ( status, label ) => {
		const offers = [ { display_order: 100, external_id: 'x', regular_url: 'https://example.test/x', fetch_status: status } ];
		render(
			<ListingsEditor
				listings={ listingWithOffers( offers ) }
				platforms={ platforms }
				onChange={ jest.fn() }
			/>
		);

		expect( screen.getByText( label ) ).toBeInTheDocument();
	} );
} );

describe( 'selectInUseOffer（Affilicard\\Pricing\\OfferSelector::select() と同じ規則であることの固定）', () => {
	// tests/Unit/Pricing/OfferSelectorTest.php と同じケースを JS 側にも置く。
	// PHP の分岐を変えたら、このテストも一緒に直すこと（管理画面の「使用中」表示と
	// カード描画が食い違わないようにするための固定）。
	const offer = ( order, id, status = '' ) => ( {
		display_order: order,
		external_id: id,
		regular_url: `https://example.test/${ id }`,
		fetch_status: status,
	} );

	test( '空なら null を返す', () => {
		expect( selectInUseOffer( [], true ) ).toBeNull();
	} );

	test( '表示順が小さいものを選ぶ', () => {
		const offers = [ offer( 100, 'b' ), offer( 10, 'a' ) ];
		expect( selectInUseOffer( offers, false ).external_id ).toBe( 'a' );
	} );

	test( '同値なら配列の出現順を保つ', () => {
		const offers = [ offer( 10, 'first' ), offer( 10, 'second' ) ];
		expect( selectInUseOffer( offers, false ).external_id ).toBe( 'first' );
	} );

	test( 'display_order 未指定は 100 として扱う', () => {
		const offers = [
			{ external_id: 'default', regular_url: 'https://example.test/d' },
			offer( 10, 'explicit' ),
		];
		expect( selectInUseOffer( offers, false ).external_id ).toBe( 'explicit' );
	} );

	test( 'display_order が null でも 100 として扱う', () => {
		// PHP の isset() は null を「値が無い」と見なすため既定値になる。
		const offers = [
			{ display_order: null, external_id: 'nulled', regular_url: 'https://example.test/n' },
			offer( 10, 'explicit' ),
		];
		expect( selectInUseOffer( offers, false ).external_id ).toBe( 'explicit' );
	} );

	test( 'display_order が空文字なら 0（PHP の (int) キャストと同じ）', () => {
		// PHP: isset() は true なので既定値ではなく (int) '' ＝ 0 ＝ 最優先。
		// 既定値（100）へ倒すと、保存前の「使用中」の印が保存後に PHP が選ぶ行とずれる。
		const offers = [
			offer( 10, 'ten' ),
			{ display_order: '', external_id: 'empty', regular_url: 'https://example.test/e' },
		];
		expect( selectInUseOffer( offers, false ).external_id ).toBe( 'empty' );
	} );

	test( 'display_order が数字でない文字列でも 0', () => {
		// PHP: (int) 'あ' === 0。JS の Number() は NaN になり並べ替えが未定義になる。
		const offers = [
			offer( 10, 'ten' ),
			{ display_order: 'あ', external_id: 'bogus', regular_url: 'https://example.test/b' },
		];
		expect( selectInUseOffer( offers, false ).external_id ).toBe( 'bogus' );
	} );

	test( 'display_order が数字で始まる文字列は先頭の数値だけを読む', () => {
		// PHP: (int) '12abc' === 12。
		const offers = [
			offer( 50, 'fifty' ),
			{ display_order: '12abc', external_id: 'twelve', regular_url: 'https://example.test/t' },
		];
		expect( selectInUseOffer( offers, false ).external_id ).toBe( 'twelve' );
	} );

	test( '設定 OFF なら terminal でも先頭を返す', () => {
		const offers = [ offer( 10, 'dead', 'terminal' ), offer( 100, 'alive' ) ];
		expect( selectInUseOffer( offers, false ).external_id ).toBe( 'dead' );
	} );

	test( '設定 ON なら terminal を飛ばす', () => {
		const offers = [ offer( 10, 'dead', 'terminal' ), offer( 100, 'alive' ) ];
		expect( selectInUseOffer( offers, true ).external_id ).toBe( 'alive' );
	} );

	test( '設定 ON でも transient と unsupported は飛ばさない', () => {
		// 商品は存在している。切り替えると復旧時に戻る往復が起きる。
		expect(
			selectInUseOffer( [ offer( 10, 'busy', 'transient' ), offer( 100, 'alive' ) ], true ).external_id
		).toBe( 'busy' );
		expect(
			selectInUseOffer( [ offer( 10, 'manual', 'unsupported' ), offer( 100, 'alive' ) ], true ).external_id
		).toBe( 'manual' );
	} );

	test( '全件 terminal なら先頭を返す', () => {
		// 非破壊。リンクは出したまま価格だけ PriceFreshness が隠す。
		const offers = [ offer( 10, 'a', 'terminal' ), offer( 100, 'b', 'terminal' ) ];
		expect( selectInUseOffer( offers, true ).external_id ).toBe( 'a' );
	} );

	test( '配列でない要素は無視する', () => {
		const offers = [ 'こわれた値', offer( 10, 'ok' ) ];
		expect( selectInUseOffer( offers, false ).external_id ).toBe( 'ok' );
	} );
} );

describe( 'withNormalisedOffers（Affilicard\\Pricing\\LegacyOffer::offersWithFallback() と同じ規則であることの固定）', () => {
	// src/Pricing/LegacyOffer.php（offersWithFallback / hasFlatFetchFields / toOffer）
	// と同じケースを JS 側にも置く。PHP の分岐を変えたら、このテストも一緒に直すこと
	// （移行前の listing を編集したときに、保存側と編集側で「購入リンクが何件あるか」
	// の答えが食い違わないための固定）。
	const flatListing = ( patch ) => ( {
		platform: 'rakuten-kobo',
		enabled: true,
		update_mode: 'auto',
		auto_update: true,
		external_id: 'flat-1',
		regular_url: 'https://example.test/flat',
		affiliate_url: 'https://example.test/aff',
		price: '700',
		search_key: 'flat-key',
		...patch,
	} );

	test( 'offers を持つ listing はそのまま返す', () => {
		const listing = {
			platform: 'rakuten-kobo',
			offers: [ { external_id: 'a', regular_url: 'https://example.test/a' } ],
		};
		expect( withNormalisedOffers( listing ) ).toBe( listing );
	} );

	test( '移行前の flat な listing を offers[0] へ写す', () => {
		const normalised = withNormalisedOffers( flatListing() );

		expect( normalised.offers ).toHaveLength( 1 );
		expect( normalised.offers[ 0 ].external_id ).toBe( 'flat-1' );
		expect( normalised.offers[ 0 ].regular_url ).toBe( 'https://example.test/flat' );
		expect( normalised.offers[ 0 ].affiliate_url ).toBe( 'https://example.test/aff' );
		expect( normalised.offers[ 0 ].price ).toBe( '700' );
		expect( normalised.offers[ 0 ].search_key ).toBe( 'flat-key' );
		// display_order 未指定は 100（PHP の OfferSelector::DEFAULT_ORDER）。
		expect( normalised.offers[ 0 ].display_order ).toBe( 100 );
		// 設定フィールドは残す。
		expect( normalised.platform ).toBe( 'rakuten-kobo' );
	} );

	test( '写した後は flat な取得結果フィールドを listing から取り除く', () => {
		const normalised = withNormalisedOffers( flatListing() );

		// PHP の PluginUpgrade::LEGACY_FETCH_FIELDS と同じ一覧を落とす。
		expect( normalised ).not.toHaveProperty( 'external_id' );
		expect( normalised ).not.toHaveProperty( 'regular_url' );
		expect( normalised ).not.toHaveProperty( 'affiliate_url' );
		expect( normalised ).not.toHaveProperty( 'price' );
		expect( normalised ).not.toHaveProperty( 'search_key' );
		// 設定フィールドは残る。
		expect( normalised.platform ).toBe( 'rakuten-kobo' );
	} );

	test( '購入リンクを全て削除した後に再度正規化しても復活しない', () => {
		// 1 回目: flat から offers[0] を合成し、旧フィールドを落とす。
		const normalised = withNormalisedOffers( flatListing() );
		// 利用者が唯一の購入リンクを削除する。
		const emptied = { ...normalised, offers: [] };

		// 2 回目: 旧フィールドが残っていると、ここで削除した購入リンクが復活する。
		expect( withNormalisedOffers( emptied ).offers ).toEqual( [] );
	} );

	test( 'offers と旧 flat フィールドが併存していても offers が勝ち旧フィールドは落とす', () => {
		// 移行前に外部パイプラインが offers を書いた listing など、両方を持つ形。
		const listing = flatListing( {
			offers: [
				{ display_order: 100, external_id: 'kept', regular_url: 'https://example.test/kept' },
			],
		} );

		const normalised = withNormalisedOffers( listing );

		// 非空の offers が正（旧フィールドから合成し直さない）。
		expect( normalised.offers ).toHaveLength( 1 );
		expect( normalised.offers[ 0 ].external_id ).toBe( 'kept' );
		// 旧フィールドは落ちる。
		expect( normalised ).not.toHaveProperty( 'external_id' );
		expect( normalised ).not.toHaveProperty( 'regular_url' );
		expect( normalised ).not.toHaveProperty( 'price' );
		expect( normalised.platform ).toBe( 'rakuten-kobo' );
	} );

	test( 'offers と旧 flat フィールドが併存する listing でも全削除が取り消されない', () => {
		const normalised = withNormalisedOffers(
			flatListing( {
				offers: [
					{ display_order: 100, external_id: 'kept', regular_url: 'https://example.test/kept' },
				],
			} )
		);
		// 利用者が購入リンクを全て削除する。
		const emptied = { ...normalised, offers: [] };

		// 旧フィールドが残っていると、ここで削除した購入リンクが復活する。
		expect( withNormalisedOffers( emptied ).offers ).toEqual( [] );
	} );

	test( 'display_order は PHP の (int) キャストと同じ整数へ揃える', () => {
		// PHP の LegacyOffer::toOffer() は
		// isset() ? (int) $listing['display_order'] : DEFAULT_ORDER。
		expect(
			withNormalisedOffers( flatListing( { display_order: '' } ) ).offers[ 0 ]
				.display_order
		).toBe( 0 );
		expect(
			withNormalisedOffers( flatListing( { display_order: 'あ' } ) ).offers[ 0 ]
				.display_order
		).toBe( 0 );
		expect(
			withNormalisedOffers( flatListing( { display_order: '12abc' } ) )
				.offers[ 0 ].display_order
		).toBe( 12 );
		expect(
			withNormalisedOffers( flatListing( { display_order: '3' } ) ).offers[ 0 ]
				.display_order
		).toBe( 3 );
		// 値が無いときだけ既定値（PHP の isset() は null を「無い」と見なす）。
		expect(
			withNormalisedOffers( flatListing( { display_order: null } ) ).offers[ 0 ]
				.display_order
		).toBe( 100 );
	} );

	test( '取得結果フィールドを持たない listing は 0 件のまま', () => {
		const normalised = withNormalisedOffers( {
			platform: 'rakuten-kobo',
			enabled: true,
		} );

		expect( normalised.offers ).toEqual( [] );
	} );

	test( '取得結果フィールドが空文字だけでも 0 件のまま', () => {
		const normalised = withNormalisedOffers( {
			platform: 'rakuten-kobo',
			external_id: '',
			regular_url: '',
			price: '',
		} );

		expect( normalised.offers ).toEqual( [] );
	} );

	test( 'offers が空配列でも flat なフィールドがあれば写す', () => {
		const normalised = withNormalisedOffers( flatListing( { offers: [] } ) );

		expect( normalised.offers ).toHaveLength( 1 );
		expect( normalised.offers[ 0 ].external_id ).toBe( 'flat-1' );
	} );

	test( '旧 fetch_error の文言を fetch_status へ写す', () => {
		// 写さないと、恒久失敗していた購入リンクが「取得成功」として振る舞う。
		expect(
			withNormalisedOffers( flatListing( { fetch_error: '該当する商品が見つかりませんでした' } ) )
				.offers[ 0 ].fetch_status
		).toBe( 'terminal' );
		expect(
			withNormalisedOffers( flatListing( { fetch_error: '対応する自動 Provider がありません' } ) )
				.offers[ 0 ].fetch_status
		).toBe( 'unsupported' );
		// 未知の文言は transient へ倒す（恒久と誤認して購入リンクを飛ばすより安全）。
		expect(
			withNormalisedOffers( flatListing( { fetch_error: '手で書き換えられた文言' } ) )
				.offers[ 0 ].fetch_status
		).toBe( 'transient' );
		expect(
			withNormalisedOffers( flatListing() ).offers[ 0 ].fetch_status
		).toBe( '' );
	} );

	describe( '翻訳済みの旧 fetch_error（PHP の FetchStatus::matchesLegacy() と同じ規則）', () => {
		// v3 の ListingRefresher は __() の戻り値を fetch_error に保存していたため、
		// affilicard の翻訳を入れているサイトでは日本語リテラルと一致しない。
		// PHP（FetchStatus::fromLegacyMessage）は現在ロケールの訳語も拾うので、
		// JS が日本語リテラルしか見ないと恒久失敗が transient に化け、
		// fallback_on_terminal が ON のとき編集画面の保存でサーバと違う
		// fetch_status を書き込んでしまう。
		afterEach( () => {
			resetLocaleData();
		} );

		test( '翻訳済みの恒久失敗メッセージも terminal として扱う', () => {
			setLocaleData(
				{
					'': { domain: 'affilicard', lang: 'en_US' },
					該当する商品が見つかりませんでした: [ 'No matching product was found' ],
				},
				'affilicard'
			);

			expect(
				withNormalisedOffers(
					flatListing( { fetch_error: 'No matching product was found' } )
				).offers[ 0 ].fetch_status
			).toBe( 'terminal' );
		} );

		test( '翻訳済みの対象外メッセージも unsupported として扱う', () => {
			setLocaleData(
				{
					'': { domain: 'affilicard', lang: 'en_US' },
					'対応する自動 Provider がありません': [
						'No automatic provider is available',
					],
				},
				'affilicard'
			);

			expect(
				withNormalisedOffers(
					flatListing( { fetch_error: 'No automatic provider is available' } )
				).offers[ 0 ].fetch_status
			).toBe( 'unsupported' );
		} );

		test( '翻訳が入っていても日本語リテラルは従来どおり写る', () => {
			// 訳語を足したせいで、翻訳前に保存された値を取りこぼしてはいけない。
			setLocaleData(
				{
					'': { domain: 'affilicard', lang: 'en_US' },
					該当する商品が見つかりませんでした: [ 'No matching product was found' ],
				},
				'affilicard'
			);

			expect(
				withNormalisedOffers(
					flatListing( { fetch_error: '該当する商品が見つかりませんでした' } )
				).offers[ 0 ].fetch_status
			).toBe( 'terminal' );
		} );

		test( '訳語と無関係な文言は transient のまま', () => {
			setLocaleData(
				{
					'': { domain: 'affilicard', lang: 'en_US' },
					該当する商品が見つかりませんでした: [ 'No matching product was found' ],
				},
				'affilicard'
			);

			expect(
				withNormalisedOffers(
					flatListing( { fetch_error: 'Something else went wrong' } )
				).offers[ 0 ].fetch_status
			).toBe( 'transient' );
		} );
	} );
} );

describe( 'ListingsEditor 移行前の flat な listing', () => {
	const flatListings = () => [
		{
			platform: 'dmm-books',
			enabled: true,
			auto_update: true,
			external_id: 'flat-1',
			regular_url: 'https://example.test/flat',
			affiliate_url: 'https://example.test/aff',
			price: '700',
		},
	];

	test( '既存の購入リンクを 1 件として描画する', async () => {
		render(
			<ListingsEditor
				listings={ flatListings() }
				platforms={ platforms }
				onChange={ jest.fn() }
			/>
		);

		expect( screen.queryByText( '購入リンクがありません' ) ).toBeNull();
		// 行の見出しは flat な external_id から作られる（＝1 件として認識している）。
		await userEvent.click( screen.getByRole( 'button', { name: 'flat-1' } ) );
		expect( screen.getByLabelText( /通常 URL/ ) ).toHaveValue(
			'https://example.test/flat'
		);
	} );

	test( '購入リンクを追加しても既存の購入リンクが消えない', async () => {
		const onChange = jest.fn();
		render(
			<ListingsEditor
				listings={ flatListings() }
				platforms={ platforms }
				onChange={ onChange }
			/>
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: '購入リンクを追加' } )
		);

		const next = onChange.mock.calls.at( -1 )[ 0 ][ 0 ].offers;
		expect( next ).toHaveLength( 2 );
		expect( next[ 0 ].external_id ).toBe( 'flat-1' );
		expect( next[ 0 ].regular_url ).toBe( 'https://example.test/flat' );
	} );

	test( '既存の購入リンクを編集しても他のフィールドが落ちない', async () => {
		const onChange = jest.fn();
		render(
			<ListingsEditor
				listings={ flatListings() }
				platforms={ platforms }
				onChange={ onChange }
			/>
		);

		await userEvent.click( screen.getByRole( 'button', { name: 'flat-1' } ) );
		await userEvent.type( screen.getByLabelText( '外部 ID' ), '9' );

		const next = onChange.mock.calls.at( -1 )[ 0 ][ 0 ].offers;
		expect( next ).toHaveLength( 1 );
		expect( next[ 0 ].external_id ).toBe( 'flat-19' );
		expect( next[ 0 ].affiliate_url ).toBe( 'https://example.test/aff' );
		expect( next[ 0 ].price ).toBe( '700' );
	} );
} );
