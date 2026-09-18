/**
 * E2E spec: 移行が到達する前に通常の価格更新が届いた listing の購入リンク。
 *
 * **このファイルには意図的に「失敗するはずのテスト」が 1 件ある**（`test.fail()`）。
 * 既知の未修正欠陥を、直ったら気づける形で記録するためのものである。
 *
 * ## 何が起きるか（実測）
 *
 * 移行（`PluginUpgrade`）が約束しているのは「身元（`regular_url` / `external_id`）を
 * 1 つも持たない購入リンクも温存し、対象商品を名指しで管理画面に通知する」——
 * 運用者が通常 URL を足すための猶予を作ることである（CHANGELOG 4.0.0 の Fixed／
 * 「既知の制限」は *通知が出たあとの* 次の保存で消えると書いている）。
 *
 * ところが v3 の flat な形のまま残っている商品へ通常の価格更新が先に届くと、
 * その保存が flat → `offers[]` の変換を兼ねる:
 *
 *   AS ランナー → `RefreshHandler` → `ListingRefresher::refreshOne()`
 *   → `ProductRepository::updateListingOffer()`（`LegacyOffer::offersWithFallback()` で
 *      offers[] へ揃えて書き戻す）→ `update_post_meta()` → `sanitize_meta()`
 *   → `ProductSchema::sanitizeListings()` → `sanitizeOffers()`
 *
 * `sanitizeOffers()` は身元を 1 つも持たない offer を落とす。移行だけがその規則を
 * `withLegacyOfferPreservation()` で外しているが、この経路は通らない。結果、
 * **購入リンクはその場で消え、温存カウンタは 1 つも増えず、通知も出ない**。
 * 移行があとから到達しても、その listing は既に `offers[]` を持っているため
 * `migrateListingToOffers()` は冪等に素通りし、数える機会も失われている。
 *
 * ## なぜここで直さないか
 *
 * 直し方の候補が 2 つあり、どちらも設計判断を伴うため実装者の判断を仰ぐ:
 *
 * 1. `updateListingOffer()` / `updateListing()` が「保存前の listing がまだ flat だった」
 *    ときだけ `withLegacyOfferPreservation()` で書く。データは残るが、移行が到達した
 *    ときには既に `offers[]` なので**温存カウンタと通知は依然として出ない**（半端な直し）。
 * 2. 移行が未完のあいだ、flat のままの listing への価格更新を no-op にして後回しにする
 *    （`ListingRefresher::refreshOne()`）。データも通知も守れるが、**移行が終わるまで
 *    その商品の価格更新が止まる**——移行が途中で諦めた商品があると長引く。
 *
 * ## 参考: この状態の実インストールでの見え方
 *
 * 移行のトリガー自体が壊れていた（`plugins_loaded` から積んでいたため 1 件も
 * 積まれなかった。tests/e2e/offers-migration-trigger.spec.js 参照）あいだは、
 * すべての商品がこの窓に入りっぱなしだった。シード直後 6 件あった購入リンクが
 * 5 件に減る形で観測されている。
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
 * @param {string} mode `seed` か `read`。
 * @return {Object} フィクスチャの出力。
 */
function runFixture( mode ) {
	return parseRaceJson(
		wpEnvRun( [ 'wp', 'eval-file', '--use-include', FIXTURE, mode ] )
	);
}

test.describe( '移行より先に価格更新が届いた flat listing（実 WP）', () => {
	/** @type {Object} 価格更新が走る前の状態。 */
	let before;
	/** @type {Object} 価格更新が走った後の状態。 */
	let after;

	test.beforeAll( () => {
		runFixture( 'seed' );
		before = runFixture( 'read' );

		// 積んだ価格更新を本物の Action Scheduler ランナーで実行する。
		// group を絞って、この spec が用意した 2 件以外のキューを巻き込まない。
		wpEnvRun( [
			'wp',
			'action-scheduler',
			'run',
			'--hooks=affilicard_refresh_listing',
			'--group=affilicard-rakuten-kobo',
			'--force',
		] );

		after = runFixture( 'read' );
	} );

	test( '前提: 価格更新の前は 2 商品とも v3 の flat な形で購入リンクを 1 件ずつ持つ', async () => {
		// これが崩れていると以降の assertion は何も証明しない。
		expect( before.products.no_identity.links ).toBe( 1 );
		expect( before.products.with_id.links ).toBe( 1 );
		expect( before.products.no_identity.listings[ 0 ].offers ).toBeUndefined();
		expect( before.products.with_id.listings[ 0 ].offers ).toBeUndefined();
	} );

	test( '外部 ID を持つ購入リンクは、価格更新が flat を変換しても残る', async () => {
		// 対照群。ここが落ちるなら「身元なしだけが落ちる」ではなく保存経路全体の退行である。
		const listing = after.products.with_id.listings[ 0 ];
		expect( listing.offers ).toHaveLength( 1 );
		expect( listing.offers[ 0 ].external_id ).toBe( 'race-with-id' );
		expect( listing.offers[ 0 ].affiliate_url ).toBe(
			'https://example.test/race-with-id'
		);
	} );

	test( '【既知の欠陥】身元を持たない購入リンクが、移行の温存も通知も受けずに消える', async () => {
		// **直ったらこのマーカーを外すこと。** Playwright は「失敗するはず」のテストが
		// 通ると run を失敗させるため、修正が入れば必ず気づける。
		test.fail();

		// 移行が到達していれば温存され（`withLegacyOfferPreservation`）、
		// 温存カウンタが増えて管理画面がこの商品を名指しする。価格更新が先に
		// 届いた場合はどちらも起こらない。
		const listing = after.products.no_identity.listings[ 0 ];
		expect( listing.offers ).toHaveLength( 1 );
		expect( listing.offers[ 0 ].affiliate_url ).toBe(
			'https://example.test/race-no-identity'
		);
		expect( after.preserved ).toBeGreaterThan( before.preserved );
	} );
} );
