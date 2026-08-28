/**
 * E2E spec: 商品一覧「最終掲載日」列の meta ソート（レビュー Major 3）
 *
 * 既存の PHPUnit（ProductListColumnsTest）は `WP_Query::set()` に渡した引数
 * （meta_query / orderby）だけを検証しており、`compare => EXISTS` / `NOT EXISTS`
 * を `relation => OR` で束ねた meta_query が実 SQL で LEFT JOIN として機能するか
 * ――つまり「最終掲載日」meta を持たない商品が結果集合から消えないか――は
 * WP_Query をモックする単体テストでは原理的に検証できない
 * （ProductListColumns::applySortQuery() の docblock 参照）。
 *
 * ここでは実際の wp-env 上の管理画面で、meta の有無・新旧が異なる3商品
 * （seed.php が作る pubDateSortNewId / pubDateSortOldId / pubDateSortUnsetId）を
 * 「最終掲載日」列でソートし、
 *   1. meta を持つ商品・持たない商品の両方が一覧に残ること（INNER JOIN 相当の
 *      挙動に戻っていれば pubDateSortUnsetId が結果から消える）
 *   2. ASC / DESC の表示順が正しいこと
 *      （MySQL の既定で NULL は ASC=先頭・DESC=末尾に来る）
 * を確認する。
 *
 * 対象商品はタイトルの一意な接頭辞（E2E-PubDateSort）で管理画面の検索ボックスから
 * 絞り込む。seed.php はこの接頭辞の商品を実行のたびに掃除してから作り直すため
 * （global-setup が DB をリセットしないため蓄積を防ぐ）、検索結果は常にこの
 * 3 商品だけになり、admin 一覧のページング（既定20件/ページ）にも影響されない。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );

const seed = JSON.parse( fs.readFileSync( 'artifacts/seed.json', 'utf8' ) );

const LIST_URL =
	'/wp-admin/edit.php?post_type=affilicard_product&s=E2E-PubDateSort';

/**
 * 「最終掲載日」列で order 順にソートした一覧を開き、表示されている行の
 * post_id を DOM 上の順序どおりに返す。
 */
async function sortedProductIds( page, order ) {
	await page.goto(
		`${ LIST_URL }&orderby=affilicard_last_published&order=${ order }`
	);
	await expect( page.locator( '#the-list tr' ).first() ).toBeVisible();

	return page.locator( '#the-list tr' ).evaluateAll( ( rows ) =>
		rows
			.map( ( row ) => row.id )
			.filter( ( id ) => id.startsWith( 'post-' ) )
			.map( ( id ) => Number( id.replace( 'post-', '' ) ) )
	);
}

test.describe( '商品一覧「最終掲載日」列のソート', () => {
	test( 'DESC は 新しい → 古い → 未設定 の順に並び、未設定商品も一覧に残る', async ( {
		page,
	} ) => {
		const ids = await sortedProductIds( page, 'desc' );

		// meta を持たない pubDateSortUnsetId が結果から消えていないこと自体が、
		// EXISTS/NOT EXISTS の OR 節が LEFT JOIN として機能している証拠になる。
		expect( ids ).toEqual( [
			seed.pubDateSortNewId,
			seed.pubDateSortOldId,
			seed.pubDateSortUnsetId,
		] );
	} );

	test( 'ASC は 未設定 → 古い → 新しい の順に並ぶ（NULL は先頭）', async ( {
		page,
	} ) => {
		const ids = await sortedProductIds( page, 'asc' );

		expect( ids ).toEqual( [
			seed.pubDateSortUnsetId,
			seed.pubDateSortOldId,
			seed.pubDateSortNewId,
		] );
	} );
} );
