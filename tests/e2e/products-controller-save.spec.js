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
 *
 * **false ではない落とし穴もここで見る**——`update_post_metadata` フィルタの短絡である。
 * コアはこのフィルタが null 以外を返すと `return (bool) $check;` で即座に戻り
 * （`wp-includes/meta.php` L241-243）、$wpdb には触れない。つまり**フィルタが true を
 * 返すと `update_post_meta()` は成功を報告しながら 1 バイトも書いていない**。戻り値が
 * false のときだけ読み直す実装は、この経路を黙って成功として通す。WP_Mock はコア関数ごと
 * 差し替えるためフィルタも短絡も存在せず、**この分岐は実 WP でしか踏めない**——
 * block-listings-write.php が仕込む mu-plugin で本物のフィルタを立てて確かめる。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );
const {
	getApiContext,
	getAuthHeaders,
	cleanupStaleFixtures,
	newApiContext,
	runEvalFileJson,
} = require( './helpers/rest-offers' );

const TITLE = 'E2E 保存検証';
const BLOCKED_TITLE = 'E2E 保存握り潰し';

/** block-listings-write.php（mu-plugin の設置・解除と、対象商品の切り替え）を叩く。 */
function blockFixture( ...args ) {
	return runEvalFileJson( 'tests/e2e/block-listings-write.php', args, 'RESULT_JSON:' );
}

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

test.describe( '書き込みを握り潰すフィルタがあるとき', () => {
	test.beforeAll( () => {
		cleanupStaleFixtures( BLOCKED_TITLE );
		// mu-plugin を置く。option が指す商品にだけ効くので、置いただけでは無害。
		blockFixture( 'install' );
	} );

	test.afterAll( () => {
		// option も mu-plugin も残さない（他の spec へ仕込みを持ち越さないため）。
		blockFixture( 'uninstall' );
		cleanupStaleFixtures( BLOCKED_TITLE );
	} );

	test( 'true を返す短絡フィルタでも 500 を返し、listings は元のまま残る', async () => {
		// **使い回しのコンテキストは使わない。** ここへ来るまでに mu-plugin の設置と
		// stale fixture の掃除で `wp-env run tests-cli` を数回挟んでおり、そのあいだに
		// 使い回しコンテキストの keep-alive 接続をサーバ側が閉じる。閉じた接続を掴んだ
		// まま投げると `socket hang up` で即死する（本題とは無関係な落ち方）。
		const ctx = await newApiContext();
		const headers = getAuthHeaders();

		// まずは普通に作る（フィルタはまだどの商品にも効いていない）。
		const created = await ctx.post( '/wp-json/affilicard/v1/products', {
			headers,
			data: {
				title: BLOCKED_TITLE,
				status: 'draft',
				listings: LISTINGS,
			},
		} );
		expect( created.status() ).toBe( 201 );
		const id = ( await created.json() ).id;

		// ここからこの商品の listings 書き込みだけが「true を返して書かない」になる。
		blockFixture( 'block', id );
		let blockedBody;
		try {
			const blocked = await ctx.patch( `/wp-json/affilicard/v1/products/${ id }`, {
				headers,
				data: {
					title: `${ BLOCKED_TITLE }（改題）`,
					listings: [
						{
							platform: 'rakuten-kobo',
							enabled: true,
							offers: [
								{
									display_order: 10,
									external_id: 'e2e-blocked',
									regular_url: 'https://example.test/e2e-blocked',
									price: '880',
								},
							],
						},
					],
				},
			} );
			// **true を返す短絡フィルタでも成功にしない。** 戻り値だけを見ていると
			// ここは 200 で通り、保存されていない listings が入ったことになる。
			expect( blocked.status() ).toBe( 500 );
			blockedBody = await blocked.json();
		} finally {
			blockFixture( 'unblock' );
		}

		expect( blockedBody.code ).toBe( 'affilicard_save_failed' );
		// 作成済みの商品を名指しする（やり直しを POST ではなく PATCH にするため）。
		expect( blockedBody.id ).toBe( id );
		// **入らなかったのは listings だけ**——それを機械可読で返す。下の 2 つの
		// assertion（listings は元のまま／改題は残る）が、この申告どおりであることを
		// 実 DB 側から裏付ける。
		expect( blockedBody.unsaved_fields ).toEqual( [ 'listings' ] );

		// **listings は 1 文字も変わっていない。** フィルタは書き込みを握り潰しただけで、
		// 古い値はそのまま残る（書きかけで壊れた状態にはならない）。
		const after = await ctx.get( `/wp-json/affilicard/v1/products/${ id }`, { headers } );
		expect( after.status() ).toBe( 200 );
		const afterBody = await after.json();
		expect( afterBody.listings ).toHaveLength( 1 );
		expect( afterBody.listings[ 0 ].offers[ 0 ].external_id ).toBe( 'e2e-save-1' );

		// **ただし listings 以外は保存されている。** 投稿行は listings より先に書かれる
		// ため、500 で返っても改題は残る——これが「部分保存」である。
		expect( afterBody.title ).toBe( `${ BLOCKED_TITLE }（改題）` );

		await ctx.dispose();
	} );
} );
