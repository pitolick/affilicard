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
		// offers が空だと [0] が undefined になり、後続の期待外れが
		// TypeError として出て「何が壊れたか」が分かりにくくなるため、
		// 先に配列長を期待値として明示する。
		expect( listings[ 0 ].offers ).toHaveLength( 1 );
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
		// offers が空だと [0] が undefined になり TypeError で落ちる
		// （withLegacyOfferPreservation の窓が外れた退行はまさにこの形で壊れる）。
		// 先に配列長を期待値として明示しておけば、そのケースでも
		// TypeError ではなく読みやすい期待外れとして失敗する。
		expect( listings[ 0 ].offers ).toHaveLength( 1 );
		const offer = listings[ 0 ].offers[ 0 ];

		// regular_url が空でも offer 自体は落ちない（新規保存なら弾かれる形だが、
		// 移行は温存する）。
		expect( offer.regular_url ).toBe( '' );
		expect( offer.affiliate_url ).toBe( 'https://example.test/legacy-aff-only' );
		expect( offer.external_id ).toBe( 'legacy-aff-only' );
		expect( result.affOnly.schema_version ).toBe( '2' );
	} );

	test( '外部 ID を持つ購入リンクは、別プラットフォームの保存を挟んでも消えない', async () => {
		// ProductRepository::updateListing() は 1 platform の更新でも商品の全 listing を
		// 保存し直す（= sanitize を通す）。「regular_url が空なら落とす」ルールのままだと、
		// 移行が温存した購入リンクが別プラットフォームの定期価格更新で数時間後に消えていた。
		const rakuten = result.affOnlyAfterResave.find(
			( row ) => 'rakuten-kobo' === row.platform
		);
		expect( rakuten ).toBeTruthy();
		expect( rakuten.offers ).toHaveLength( 1 );
		expect( rakuten.offers[ 0 ].external_id ).toBe( 'legacy-aff-only' );
		expect( rakuten.offers[ 0 ].affiliate_url ).toBe(
			'https://example.test/legacy-aff-only'
		);
		// 追加した別プラットフォームの listing も保存されている（＝実際に保存が起きた）。
		expect(
			result.affOnlyAfterResave.find( ( row ) => 'dmm-books' === row.platform )
		).toBeTruthy();
	} );

	test( '身元を 1 つも持たない購入リンクは温存されるが、次の保存では消える（既知の制限）', async () => {
		// 移行直後は残っている。
		const listings = result.noIdentity.listings;
		expect( listings ).toHaveLength( 1 );
		expect( listings[ 0 ].offers ).toHaveLength( 1 );
		expect( listings[ 0 ].offers[ 0 ].affiliate_url ).toBe(
			'https://example.test/legacy-no-identity'
		);

		// 温存カウンタが増えるのはこのケースだけ（身元を持つ affOnly は数えない
		// ——数えると通知が「消えます」と嘘をつく）。
		expect( result.preservedAfter - result.preservedBefore ).toBe( 1 );
		// 通知から辿れるよう post ID が控えられている。
		expect( result.preservedPostIds ).toContain( result.noIdentityPostId );

		// そして次の保存（別プラットフォームの価格更新）で消える。CHANGELOG と
		// 管理画面通知が「消える」と言っている挙動そのもの。
		const rakuten = result.noIdentityAfterResave.find(
			( row ) => 'rakuten-kobo' === row.platform
		);
		expect( rakuten ).toBeTruthy();
		expect( rakuten.offers ).toHaveLength( 0 );
	} );

	test( '運用向けの温存件数が管理画面の通知に反映され、対象商品への導線と削除予告が出る', async ( {
		page,
	} ) => {
		expect( result.preservedAfter ).toBeGreaterThan( 0 );

		await page.goto( '/wp-admin/edit.php?post_type=affilicard_product' );

		// 件数（レビュー対応で文言を強化した後も変わらない部分）。
		await expect(
			page.getByText(
				new RegExp(
					`持たないまま購入リンクを維持した listing が ${ result.preservedAfter } 件`
				)
			)
		).toBeVisible( { timeout: 15_000 } );
		// 「確認してほしい」ではなく「次の保存で消える」ことを明示しているか
		// （レビュー Important 対応: 通知が『手動での確認』としか言わないと、
		// 通常の保存/価格更新で黙って消えるまでの猶予だと運用が気づけない）。
		await expect(
			page.getByText( /次に保存する.*と自動的に削除されます/ )
		).toBeVisible();
		// 件数だけでは運用が動けない。どの商品かへ辿れること。
		await expect(
			page.getByRole( 'link', { name: result.noIdentityTitle } )
		).toBeVisible();
	} );
} );
