/**
 * E2E spec（Task 16 — claim 3）: v3 以前の flat listing に対する offers 移行
 * （`PluginUpgrade::runOffersMigrationBatch()`）を実 WP 上の実データで確認する。
 *
 * レビューでマージ前必須と指摘された検証。unit test（PluginUpgradeTest 等）は
 * `migrateListingToOffers()`（1 listing → 1 listing の純粋変換）と WP_Mock による
 * バッチのワイヤリングは確認済みだが、「実際に update_post_meta で書いた flat な
 * postmeta を読み、sanitizeListings の whitelist を一度も通さず offers[] へ変換して
 * 保存し直せるか」は実 WP でしか確認できない。
 *
 * 特に `regular_url` を持たず `affiliate_url` だけの listing は、一度 Critical で
 * 修正された「移行がサイレントにデータを消す」退行の再発防止ケース。
 * ProductSchema::sanitizeOffers() は新規保存では regular_url が空の offer を弾くが、
 * 移行はこのルールを外して（withLegacyOfferPreservation）データを温存し、
 * 件数を運用向けカウンタ（PluginUpgrade::preservedWithoutRegularUrlCount()、
 * 管理画面 OffersMigrationNotice が表示する値そのもの）に積む。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );

function runMigrationFixture() {
	const raw = execSync(
		'npx wp-env run tests-cli wp eval-file wp-content/plugins/affilicard/tests/e2e/migration-fixture.php',
		{ encoding: 'utf8' }
	);
	const marker = 'MIGRATION_JSON:';
	const idx = raw.indexOf( marker );
	if ( -1 === idx ) {
		throw new Error( `migration-fixture.php did not output ${ marker }. Output:\n${ raw }` );
	}
	const jsonText = raw
		.slice( idx + marker.length )
		.replace( /✔ Ran `.*$/s, '' )
		.trim();
	return JSON.parse( jsonText );
}

test.describe( 'v3 flat listing → offers[] 移行（実 WP）', () => {
	/** @type {ReturnType<typeof runMigrationFixture>} */
	let result;

	// フィクスチャ作成→移行バッチ実行は 1 回だけ行い、3 テストで結果を共有する
	// （移行はこのファイル環境に既存の ~200 件弱の商品を毎回全走査するため、
	// テストごとに繰り返すと無駄に重いだけでなく、温存カウンタの
	// before/after 差分もテスト間で意味が変わってしまう）。
	test.beforeAll( () => {
		result = runMigrationFixture();
	} );

	test( '通常の flat listing が offers[0] へ移行され、fetch_error が fetch_status コードになる', async () => {
		// 移行前は SchemaVersion::CURRENT（'2'）ではなかった（saveMeta() 経由で作っていない証拠）。
		expect( result.schemaVersionBeforeNormal ).not.toBe( '2' );

		const listings = result.normal.listings;
		expect( listings ).toHaveLength( 1 );
		const offer = listings[ 0 ].offers[ 0 ];

		expect( offer.display_order ).toBe( 100 );
		expect( offer.external_id ).toBe( 'legacy-normal' );
		expect( offer.regular_url ).toBe( 'https://example.test/legacy-normal' );
		expect( offer.affiliate_url ).toBe( 'https://example.test/legacy-normal-aff' );
		expect( offer.price ).toBe( '600' );
		expect( offer.list_price ).toBe( '780' );
		expect( offer.badge ).toBe( '23%OFF' );
		// '該当する商品が見つかりませんでした' は FetchStatus::TERMINAL に写像される
		// （FetchStatus::fromLegacyMessage()）。
		expect( offer.fetch_status ).toBe( 'terminal' );
		// v3 の flat フィールドは listing 直下に残らない。
		expect( listings[ 0 ].fetch_error ).toBeUndefined();
		expect( listings[ 0 ].external_id ).toBeUndefined();

		// 商品単位の schema_version が移行によって現行バージョンへ進む。
		expect( result.normal.schema_version ).toBe( '2' );
	} );

	test( 'affiliate_url だけで regular_url を持たない listing はサイレントに消えない', async () => {
		const listings = result.affOnly.listings;
		expect( listings ).toHaveLength( 1 );
		const offer = listings[ 0 ].offers[ 0 ];

		// regular_url が空でも offer 自体は落ちない（新規保存なら弾かれる形だが、
		// 移行は温存する）。
		expect( offer.regular_url ).toBe( '' );
		expect( offer.affiliate_url ).toBe( 'https://example.test/legacy-aff-only' );
		expect( offer.external_id ).toBe( 'legacy-aff-only' );
		expect( result.affOnly.schema_version ).toBe( '2' );

		// 運用向け「温存件数」カウンタ（管理画面 OffersMigrationNotice が表示する値）に
		// このケースの分だけ加算されている。厳密に 1 件分の増分であることを確認する
		// （温存対象でない通常 listing まで数えていないか、を同時に検証する）。
		expect( result.preservedAfter - result.preservedBefore ).toBe( 1 );
	} );

	test( '運用向けの温存件数が管理画面の通知に反映される', async ( { page } ) => {
		expect( result.preservedAfter ).toBeGreaterThan( 0 );

		await page.goto( '/wp-admin/edit.php?post_type=affilicard_product' );

		await expect(
			page.getByText(
				new RegExp( `商品ページ URL を持たないまま購入リンクを維持した listing が ${ result.preservedAfter } 件` )
			)
		).toBeVisible( { timeout: 15_000 } );
	} );
} );
