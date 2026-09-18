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
 *   AS ランナー → `RefreshHandler` → `ListingRefresher::refreshOne()`
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
 * ## 見送った更新が失われないこと
 *
 * 見送り＝書き込まない＝`last_fetched_at` が据え置かれる、なので
 * `PriceFreshness::needsRefetch()` は true のままになり、掃引
 * （`QueueMaintenance::sweep()`）が次の周回で同じ listing をまた積む。移行が完走すれば
 * listing は `offers[]` を持つので見送りは効かなくなり、そのまま通常どおり取得される。
 * 本 spec はその 3 点（見送る／掃引が積み直す／移行後は書き込む）を順に確かめる。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );
const { execFileSync } = require( 'child_process' );

const PLUGIN_PATH = 'wp-content/plugins/affilicard';
const FIXTURE = `${ PLUGIN_PATH }/tests/e2e/migration-race-fixture.php`;

/**
 * `wp-env run tests-cli <args...>` をシェルを介さずに実行する（global-setup.js と同じ流儀）。
 *
 * @param {string[]} args wp-cli 側の argv。
 * @return {string} 標準出力。
 */
function wpEnvRun( args ) {
	return execFileSync( 'npx', [ 'wp-env', 'run', 'tests-cli', ...args ], {
		encoding: 'utf8',
	} );
}

/**
 * 出力から `RACE_JSON:{...}` を取り出す。
 *
 * @param {string} raw 標準出力。
 * @return {Object} パースした JSON。
 */
function parseRaceJson( raw ) {
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

/**
 * @param {string} mode `seed` / `read` / `migrate` / `requeue`。
 * @return {Object} フィクスチャの出力。
 */
function runFixture( mode ) {
	return parseRaceJson(
		wpEnvRun( [ 'wp', 'eval-file', '--use-include', FIXTURE, mode ] )
	);
}

/** 積んだ価格更新を本物の Action Scheduler ランナーで実行する。 */
function runRefreshQueue() {
	// group を絞って、この spec が用意した 2 件以外のキューを巻き込まない。
	wpEnvRun( [
		'wp',
		'action-scheduler',
		'run',
		'--hooks=affilicard_refresh_listing',
		'--group=affilicard-rakuten-kobo',
		'--force',
	] );
}

test.describe( '移行より先に価格更新が届いた flat listing（実 WP）', () => {
	/** @type {Object} 価格更新が走る前（移行は未完・listing は flat）。 */
	let before;
	/** @type {Object} 移行が未完のまま価格更新を走らせたあと。 */
	let afterHold;
	/** @type {Object} 移行を完走させたあと。 */
	let afterMigration;
	/** @type {Object} 移行後にもう一度価格更新を走らせたあと。 */
	let afterResume;

	test.beforeAll( () => {
		runFixture( 'seed' );
		before = runFixture( 'read' );

		runRefreshQueue();
		afterHold = runFixture( 'read' );

		runFixture( 'migrate' );
		afterMigration = runFixture( 'read' );

		runFixture( 'requeue' );
		runRefreshQueue();
		afterResume = runFixture( 'read' );
	} );

	test( '前提: 移行は未完で、2 商品とも v3 の flat な形で購入リンクを 1 件ずつ持つ', async () => {
		// これが崩れていると以降の assertion は何も証明しない。
		expect( before.migrationPending ).toBe( true );
		expect( before.products.no_identity.links ).toBe( 1 );
		expect( before.products.with_id.links ).toBe( 1 );
		expect( before.products.no_identity.listings[ 0 ].offers ).toBeUndefined();
		expect( before.products.with_id.listings[ 0 ].offers ).toBeUndefined();
	} );

	test( '移行が未完のあいだ、価格更新は未変換の listing へ書き込まない', async () => {
		// listing は flat のまま＝保存が 1 度も起きていない。ここが `offers[]` に
		// なっていたら、価格更新が変換を兼ねてしまったということ。
		expect( afterHold.products.no_identity.listings[ 0 ].offers ).toBeUndefined();
		expect( afterHold.products.with_id.listings[ 0 ].offers ).toBeUndefined();
		// **購入リンクが生き残っている。** 修正前はここが 0 になっていた。
		expect( afterHold.products.no_identity.links ).toBe( 1 );
		expect( afterHold.products.with_id.links ).toBe( 1 );
	} );

	test( '見送った更新は失われない（掃引が次の周回でまた積む）', async () => {
		// 見送り＝書き込まない＝last_fetched_at が据え置かれる。
		expect( afterHold.products.no_identity.lastFetchedAt ).toBe( '' );
		expect( afterHold.products.with_id.lastFetchedAt ).toBe( '' );
		// したがって QueueMaintenance::sweep() の再取得判定は true のままで、
		// 次の周回で同じ listing がまた積まれる（判定はフィクスチャが sweep() と
		// 同じ PriceFreshness::needsRefetch() で再現している）。
		expect( afterHold.products.no_identity.sweepWouldEnqueue ).toBe( true );
		expect( afterHold.products.with_id.sweepWouldEnqueue ).toBe( true );
	} );

	test( '移行が到達すれば、身元なしの購入リンクは温存され件数にも計上される', async () => {
		// 見送りが守っていたのはこれ——移行が温存し、運用へ通知するための件数を数える。
		expect( afterMigration.migrationPending ).toBe( false );

		const listing = afterMigration.products.no_identity.listings[ 0 ];
		expect( listing.offers ).toHaveLength( 1 );
		expect( listing.offers[ 0 ].affiliate_url ).toBe(
			'https://example.test/race-no-identity'
		);
		expect( listing.offers[ 0 ].regular_url ).toBe( '' );
		expect( listing.offers[ 0 ].external_id ).toBe( '' );
		// 管理画面の通知が読む件数（OffersMigrationNotice）。0 のままだと
		// 「消えるかもしれない」という警告すら出ない。
		expect( afterMigration.preserved ).toBeGreaterThan( before.preserved );

		// 対照群も変換され、身元があるので当然残る。
		const withId = afterMigration.products.with_id.listings[ 0 ];
		expect( withId.offers ).toHaveLength( 1 );
		expect( withId.offers[ 0 ].external_id ).toBe( 'race-with-id' );
	} );

	test( '移行が完走すれば価格更新は再開する', async () => {
		// 見送りは「移行が未完」かつ「未変換」の積集合でしか効かない。移行後は
		// listing が offers[] を持つので、同じ価格更新が今度は実際に書き込む。
		const withId = afterResume.products.with_id.listings[ 0 ];
		expect( withId.offers ).toHaveLength( 1 );
		expect( withId.offers[ 0 ].last_fetched_at ).not.toBe( '' );
		// 書き込めた＝掃引の再取得判定も落ち着く（＝見送りループから抜けた）。
		expect( afterResume.products.with_id.lastFetchedAt ).not.toBe( '' );
	} );
} );
