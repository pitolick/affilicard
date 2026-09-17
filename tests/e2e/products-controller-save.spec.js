/**
 * E2E spec: `affilicard/v1/products` の保存が「書き込み検証」で壊れていないことを実 WP で確認する。
 *
 * 保存は `update_post_meta()` の戻り値を見て、false なら META_LISTINGS を読み直し、
 * 入っていなければ 500（`affilicard_save_failed`）を返す。**この判定の正しさは
 * WordPress コアの挙動に依存する**——コアは「既存値と同じものを書こうとしたとき」にも
 * 何も書かずに false を返す（`wp-includes/meta.php` の `update_metadata()`）。
 * WP_Mock を使う unit test はコア関数をスタブに差し替えるため、**この 2 つ目の false を
 * 実際に踏むことができない**。踏み損ねたまま「false＝失敗」と実装すると、listings を
 * 変えない普通の更新が軒並み 500 になる——実 WP でしか捕まえられない事故なのでここで見る。
 *
 * 併せて、listings を保存できたときにだけ押される `schema_version` の刻印も読み戻す
 * （刻印は listings の形式を指すため、保存より先に押してはならない）。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );
const {
	getApiContext,
	getAuthHeaders,
	cleanupStaleFixtures,
} = require( './helpers/rest-offers' );

const TITLE = 'E2E 保存検証';

const LISTINGS = [
	{
		platform: 'rakuten-kobo',
		enabled: true,
		offers: [
			{
				display_order: 10,
				external_id: 'e2e-save-1',
				regular_url: 'https://example.test/e2e-save-1',
				price: '660',
			},
		],
	},
];

test.describe( 'affilicard/v1 の商品保存（書き込み検証つき）', () => {
	test.beforeAll( () => {
		cleanupStaleFixtures( TITLE );
	} );

	test.afterAll( () => {
		cleanupStaleFixtures( TITLE );
	} );

	test( '作成は 201 で listings を保存し、同じ listings で更新し直しても 200 を返す', async () => {
		const ctx = await getApiContext();
		const headers = getAuthHeaders();

		const created = await ctx.post( '/wp-json/affilicard/v1/products', {
			headers,
			data: {
				title: TITLE,
				status: 'draft',
				listings: LISTINGS,
			},
		} );
		expect( created.status() ).toBe( 201 );

		const body = await created.json();
		expect( body.listings ).toHaveLength( 1 );
		expect( body.listings[ 0 ].offers[ 0 ].external_id ).toBe( 'e2e-save-1' );
		// listings を保存できた商品にだけ刻印が押される。
		expect( body.schema_version ).not.toBe( '' );

		const id = body.id;

		// **同じ listings をもう一度送る。** コアは値が変わらないので何も書かずに
		// false を返す。ここで 500 になるなら、false を一律に失敗として扱っている。
		const unchanged = await ctx.patch( `/wp-json/affilicard/v1/products/${ id }`, {
			headers,
			data: { listings: LISTINGS },
		} );
		expect( unchanged.status() ).toBe( 200 );
		const unchangedBody = await unchanged.json();
		expect( unchangedBody.listings[ 0 ].offers[ 0 ].external_id ).toBe( 'e2e-save-1' );

		// listings を送らない部分更新（タイトルだけ）。既存の listings がそのまま
		// 書き戻されるため、これも「値が変わらない」false を踏む経路である。
		const titleOnly = await ctx.patch( `/wp-json/affilicard/v1/products/${ id }`, {
			headers,
			data: { title: `${ TITLE }（改題）` },
		} );
		expect( titleOnly.status() ).toBe( 200 );
		const titleOnlyBody = await titleOnly.json();
		expect( titleOnlyBody.title ).toBe( `${ TITLE }（改題）` );
		expect( titleOnlyBody.listings[ 0 ].offers[ 0 ].external_id ).toBe( 'e2e-save-1' );
		expect( titleOnlyBody.schema_version ).not.toBe( '' );

		// listings を実際に変える更新も通る（検証が書き込み全般を塞いでいない）。
		const changed = await ctx.patch( `/wp-json/affilicard/v1/products/${ id }`, {
			headers,
			data: {
				listings: [
					{
						platform: 'rakuten-kobo',
						enabled: true,
						offers: [
							{
								display_order: 10,
								external_id: 'e2e-save-2',
								regular_url: 'https://example.test/e2e-save-2',
								price: '770',
							},
						],
					},
				],
			},
		} );
		expect( changed.status() ).toBe( 200 );
		const changedBody = await changed.json();
		expect( changedBody.listings[ 0 ].offers[ 0 ].external_id ).toBe( 'e2e-save-2' );
		expect( changedBody.listings[ 0 ].offers[ 0 ].price ).toBe( '770' );
	} );
} );
