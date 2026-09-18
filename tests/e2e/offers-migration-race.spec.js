/**
 * E2E spec: 移行が到達する前に通常の価格更新が届いた flat listing。
 *
 * ## 塞いだ穴
 *
 * 移行（`PluginUpgrade`）は「身元（`regular_url` / `external_id`）を 1 つも持たない
 * 購入リンクも温存し、対象商品を名指しで管理画面に通知する」——運用者が通常 URL を
 * 足すための猶予を作る——と約束している。CHANGELOG の「既知の制限」も、消えるのは
 * *通知が出たあとの* 次の保存だと書いている。
 *
 * ところが v3 の flat な形のまま残っている商品へ通常の価格更新が先に届くと、
 * その保存が flat → `offers[]` の変換を兼ねてしまう:
 *
 *   `RefreshHandler` → `ListingRefresher::refreshOne()`
 *   → `ProductRepository::updateListingOffer()`（`LegacyOffer::offersWithFallback()` で
 *      offers[] へ揃えて書き戻す）→ `update_post_meta()` → `sanitize_meta()`
 *   → `ProductSchema::sanitizeListings()` → `sanitizeOffers()`
 *
 * `sanitizeOffers()` は身元を 1 つも持たない offer を落とす。移行だけがその規則を
 * `withLegacyOfferPreservation()` で外しているが、この経路は通らない。結果、購入リンクが
 * その場で消え、温存カウンタは増えず、通知も出なかった。しかも移行があとから到達しても
 * その listing は既に `offers[]` を持つため `migrateListingToOffers()` は冪等に素通りし、
 * 数える機会そのものが失われていた。
 *
 * `ListingRefresher::isHeldForMigration()` で塞いだ——**移行が未完で、かつその listing が
 * まだ変換されていないあいだは、価格更新は書き込まずに見送る**。
 *
 * ## 予防的な措置であること
 *
 * 本番カタログ（商品 1,500 件）を実測した時点では `affiliate_url` だけを持つ listing は
 * 0 件で、運用者もそのようなデータを手入力した覚えはないとのことだった。つまりこの窓を
 * 通って実際に失われたデータは無い。それでも塞ぐのは、失われたときに**気づく手段が無い**
 * （通知も件数も出ない）種類の損失だからである。
 *
 * ## シナリオを 1 プロセスで回す理由
 *
 * 真っさらな DB では offers 移行がインストール直後から未完で、管理画面リクエストのたびに
 * Action Scheduler の非同期ランナーが起きうる。ランナーは毎リクエスト積み直される
 * `affilicard_migrate_offers_batch` を拾い、**観測の途中で移行を走らせてしまう**
 * （CI と、ローカルで DB をリセットした再現で実際に起きた）。そのためシードから
 * 見送り・移行・再開までをフィクスチャの 1 プロセスで順に行い、プロセス間の隙を無くす。
 * 詳細と、AS のランナーを介さず同じフックを直接発火させる理由は
 * tests/e2e/migration-race-fixture.php の冒頭を参照。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );
const { execFileSync } = require( 'child_process' );

const FIXTURE =
	'wp-content/plugins/affilicard/tests/e2e/migration-race-fixture.php';

/**
 * シナリオを 1 プロセスで走らせ、各段階のスナップショットを受け取る。
 *
 * `wp-env run tests-cli <args...>` をシェルを介さずに実行する（global-setup.js と同じ流儀）。
 *
 * @return {Object} フィクスチャの出力。
 */
function runScenario() {
	const raw = execFileSync(
		'npx',
		[ 'wp-env', 'run', 'tests-cli', 'wp', 'eval-file', '--use-include', FIXTURE ],
		{ encoding: 'utf8' }
	);
	const marker = 'RACE_JSON:';
	const idx = raw.indexOf( marker );
	if ( -1 === idx ) {
		throw new Error( `出力に ${ marker } がありません。Output:\n${ raw }` );
	}
	return JSON.parse(
		raw
			.slice( idx + marker.length )
			.replace( /✔ Ran `.*$/s, '' )
			.trim()
	);
}

test.describe( '移行より先に価格更新が届いた flat listing（実 WP）', () => {
	/** @type {Object} */
	let result;

	test.beforeAll( () => {
		result = runScenario();
	} );

	test( '前提: 移行は未完で、2 商品とも v3 の flat な形で購入リンクを 1 件ずつ持つ', async () => {
		// これが崩れていると以降の assertion は何も証明しない。
		expect( result.before.migrationPending ).toBe( true );
		expect( result.before.products.no_identity.links ).toBe( 1 );
		expect( result.before.products.with_id.links ).toBe( 1 );
		expect(
			result.before.products.no_identity.listings[ 0 ].offers
		).toBeUndefined();
		expect( result.before.products.with_id.listings[ 0 ].offers ).toBeUndefined();
		// 価格更新のハンドラが配線されていること。配線が無ければ「書き込みが無い」は
		// 見送りの証拠にならない。
		expect( result.refreshHookRegistered ).toBe( true );
	} );

	test( '移行が未完のあいだ、価格更新は未変換の listing へ書き込まない', async () => {
		// 観測のあいだ移行が横から走っていないこと（走っていれば見送りとは無関係に
		// listing が変換されるため、この主張は成立しない）。
		expect( result.afterHold.migrationPending ).toBe( true );
		// listing は flat のまま＝保存が 1 度も起きていない。ここが `offers[]` に
		// なっていたら、価格更新が変換を兼ねてしまったということ。
		expect(
			result.afterHold.products.no_identity.listings[ 0 ].offers
		).toBeUndefined();
		expect(
			result.afterHold.products.with_id.listings[ 0 ].offers
		).toBeUndefined();
		// **購入リンクが生き残っている。** 修正前はここが 0 になっていた。
		expect( result.afterHold.products.no_identity.links ).toBe( 1 );
		expect( result.afterHold.products.with_id.links ).toBe( 1 );
	} );

	test( '見送った更新は失われない（掃引が次の周回でまた積む）', async () => {
		// 見送り＝書き込まない＝last_fetched_at が据え置かれる。
		expect( result.afterHold.products.no_identity.lastFetchedAt ).toBe( '' );
		expect( result.afterHold.products.with_id.lastFetchedAt ).toBe( '' );
		// したがって QueueMaintenance::sweep() の再取得判定は true のままで、
		// 次の周回で同じ listing がまた積まれる（判定はフィクスチャが sweep() と
		// 同じ PriceFreshness::needsRefetch() で再現している）。
		expect( result.afterHold.products.no_identity.sweepWouldEnqueue ).toBe(
			true
		);
		expect( result.afterHold.products.with_id.sweepWouldEnqueue ).toBe( true );
	} );

	test( '移行が到達すれば、身元なしの購入リンクは温存され件数にも計上される', async () => {
		// 見送りが守っていたのはこれ——移行が温存し、運用へ通知するための件数を数える。
		expect( result.afterMigration.migrationPending ).toBe( false );

		const listing = result.afterMigration.products.no_identity.listings[ 0 ];
		expect( listing.offers ).toHaveLength( 1 );
		expect( listing.offers[ 0 ].affiliate_url ).toBe(
			'https://example.test/race-no-identity'
		);
		expect( listing.offers[ 0 ].regular_url ).toBe( '' );
		expect( listing.offers[ 0 ].external_id ).toBe( '' );
		// 管理画面の通知が読む件数（OffersMigrationNotice）。0 のままだと
		// 「消えるかもしれない」という警告すら出ない。
		expect( result.afterMigration.preserved ).toBeGreaterThan(
			result.before.preserved
		);

		// 対照群も変換され、身元があるので当然残る。
		const withId = result.afterMigration.products.with_id.listings[ 0 ];
		expect( withId.offers ).toHaveLength( 1 );
		expect( withId.offers[ 0 ].external_id ).toBe( 'race-with-id' );
	} );

	test( '移行が完走すれば価格更新は再開する', async () => {
		// 見送りは「移行が未完」かつ「未変換」の積集合でしか効かない。移行後は
		// listing が offers[] を持つので、同じ価格更新が今度は実際に書き込む。
		//
		// **このテストは上の 2 つの守り手でもある。** 見送りが「実は配線ミスで
		// ハンドラが何もしていなかった」「レート制限で枠を取れなかった」だけなら、
		// ここも書き込めずに落ちる。
		const withId = result.afterResume.products.with_id.listings[ 0 ];
		expect( withId.offers ).toHaveLength( 1 );
		expect( withId.offers[ 0 ].last_fetched_at ).not.toBe( '' );
		expect( result.afterResume.products.with_id.lastFetchedAt ).not.toBe( '' );
	} );
} );
