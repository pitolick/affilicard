/**
 * E2E spec（Task 16 — claim 1）: `offers[]` が REST 保存往復で欠落しないことを実 WP で確認する。
 *
 * listings meta には whitelist 方式の sanitizer（ProductSchema::sanitizeListings /
 * sanitizeOffers）がかかっており、新フィールドを whitelist に追加し忘れると
 * **保存は 201/200 で成功したまま、そのフィールドだけが黙って消える**。
 * WP_Mock を使う unit test は sanitizer を直接呼ぶため、この「REST 経由で保存 →
 * 読み戻す」経路そのものを一度も通らず、whitelist 漏れを検出できない。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );
const {
	createProductWithOffers,
	createProduct,
	readListings,
	updateOffers,
} = require( './helpers/rest-offers' );

test.describe( 'offers[] の REST 保存往復', () => {
	test( 'offers が保存され、display_order・fetch_status を含めて読み戻せる', async () => {
		const id = await createProductWithOffers(
			[
				{
					display_order: 10,
					external_id: 'sale',
					regular_url: 'https://example.test/sale',
					price: '0',
					fetch_status: 'terminal',
				},
				{
					display_order: 100,
					external_id: 'normal',
					regular_url: 'https://example.test/normal',
					price: '660',
				},
			],
			{ title: 'E2E 購入リンク往復' }
		);

		const listings = await readListings( id );

		expect( listings ).toHaveLength( 1 );
		const offers = listings[ 0 ].offers;

		// whitelist 漏れがあると、201/200 は返るのに offers が空、または
		// 個別フィールド（display_order 等）だけが欠落した状態で返ってくる。
		expect( offers ).toHaveLength( 2 );
		expect( offers[ 0 ].external_id ).toBe( 'sale' );
		expect( offers[ 0 ].display_order ).toBe( 10 );
		expect( offers[ 0 ].fetch_status ).toBe( 'terminal' );
		expect( offers[ 0 ].regular_url ).toBe( 'https://example.test/sale' );
		expect( offers[ 1 ].external_id ).toBe( 'normal' );
		expect( offers[ 1 ].display_order ).toBe( 100 );
		expect( offers[ 1 ].price ).toBe( '660' );
	} );

	test( '外部 ID を訂正すると恒久失敗の印が落ちる（give-up の巻き添えを解く）', async () => {
		// ブロックエディタのサイドバーは core-data（wp/v2 の meta）で保存するため、
		// affilicard/v1 の商品コントローラ（ProductRepository::saveMeta）を通らない。
		// unit test では両経路の配線までは押さえられないので、実 WP の REST 往復で確認する。
		const id = await createProductWithOffers(
			[
				{
					display_order: 10,
					external_id: 'rk-wrong',
					regular_url: 'https://example.test/rk-wrong',
					fetch_status: 'terminal',
				},
				{
					display_order: 100,
					external_id: 'rk-other',
					regular_url: 'https://example.test/rk-other',
					fetch_status: 'terminal',
				},
			],
			{ title: 'E2E 身元の訂正' }
		);

		// 作成時は「訂正」ではないので、持ち込んだ取得状態はそのまま残る。
		const created = await readListings( id );
		expect( created[ 0 ].offers[ 0 ].fetch_status ).toBe( 'terminal' );

		// 運用者が 1 件目の外部 ID だけを直す。
		await updateOffers( id, [
			{
				display_order: 10,
				external_id: 'rk-fixed',
				regular_url: 'https://example.test/rk-wrong',
				fetch_status: 'terminal',
			},
			{
				display_order: 100,
				external_id: 'rk-other',
				regular_url: 'https://example.test/rk-other',
				fetch_status: 'terminal',
			},
		] );

		const listings = await readListings( id );
		const offers = listings[ 0 ].offers;

		// 訂正した購入リンクは取得状態が白紙に戻り、give-up の抑止から外れる。
		expect( offers[ 0 ].external_id ).toBe( 'rk-fixed' );
		expect( offers[ 0 ].fetch_status ).toBe( '' );
		// 触っていない購入リンクは恒久失敗のまま（廃盤 SKU へのリトライを再開させない）。
		expect( offers[ 1 ].external_id ).toBe( 'rk-other' );
		expect( offers[ 1 ].fetch_status ).toBe( 'terminal' );
	} );

	test( 'flat な listing を送っても offers[0] へ正規化される（互換の保証）', async () => {
		const id = await createProduct( {
			title: 'E2E 互換 flat 入力',
			listings: [
				{
					platform: 'rakuten-kobo',
					external_id: 'legacy',
					regular_url: 'https://example.test/legacy',
					price: '660',
				},
			],
		} );

		const listings = await readListings( id );
		const listing = listings[ 0 ];

		expect( listing.offers ).toHaveLength( 1 );
		expect( listing.offers[ 0 ].external_id ).toBe( 'legacy' );
		expect( listing.offers[ 0 ].display_order ).toBe( 100 );
		expect( listing.external_id ).toBeUndefined();
	} );
} );
