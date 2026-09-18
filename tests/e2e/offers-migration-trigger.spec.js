/**
 * E2E spec: offers 移行の**開始トリガー**を、通常のリクエスト経路で実 WP 上から確かめる。
 *
 * **なぜ tests/e2e/offers-migration.spec.js では足りないか。** あちらは
 * migration-fixture.php が `PluginUpgrade::runOffersMigrationBatch()` を直接呼び、
 * トリガー（`plugins_loaded` のバージョン差分 → Action Scheduler へ投入）は
 * 「PHPUnit で検証済み」として素通ししていた。だが PHPUnit の
 * `as_schedule_single_action` は WP_Mock のスタブで**必ず成功する**。
 * 実機では事情が違う:
 *
 * - `as_schedule_single_action()` は入口で
 *   `if ( ! ActionScheduler::is_initialized( __FUNCTION__ ) ) { return 0; }` と書かれている
 *   （`vendor/woocommerce/action-scheduler/functions.php`）。
 * - そのフラグが立つのは **`init` の優先度 1**
 *   （`vendor/woocommerce/action-scheduler/classes/abstracts/ActionScheduler.php`）。
 * - `PluginUpgrade::maybeUpgrade()` は **`plugins_loaded`**（`src/Plugin.php`）で走る。
 *
 * つまり**アップグレードのたびに 0 が返って 1 件も積まれず**、カーソルが消えないので
 * 毎リクエスト同じ空振りを繰り返していた。加えて `is_initialized()` は関数名を
 * 渡されると `_doing_it_wrong()` を鳴らすため、`WP_DEBUG` のサイトでは
 * `plugins_loaded` の時点で出力が始まり「headers already sent」で管理画面が壊れる。
 *
 * **スタブでは絶対に見えない不具合**（スタブしていた当の関数が失敗の当事者だった）
 * なので、ここは実 WordPress 上で「本物のリクエストを 1 本流し、
 * `wp_actionscheduler_actions` に行ができるか」を見る。
 *
 * 点検は必ず `--skip-plugins` で行う。`wp` は 1 回ごとに WordPress を起動し、その
 * `plugins_loaded` で maybeUpgrade() が走るため、affilicard を読み込んだまま
 * 点検すると**点検そのものがアクションを積んでしまう**。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );
const { execFileSync } = require( 'child_process' );

const STORAGE_STATE = 'artifacts/storage-state.json';
const PLUGIN_PATH = 'wp-content/plugins/affilicard';
const HOOK = 'affilicard_migrate_offers_batch';

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
 * 出力から `MARKER:{...}` の JSON を取り出す。
 *
 * @param {string} raw    標準出力。
 * @param {string} marker 行頭マーカー。
 * @return {Object} パースした JSON。
 */
function parseMarkedJson( raw, marker ) {
	const idx = raw.indexOf( marker );
	if ( -1 === idx ) {
		throw new Error( `出力に ${ marker } がありません。Output:\n${ raw }` );
	}
	const jsonText = raw
		.slice( idx + marker.length )
		.replace( /✔ Ran `.*$/s, '' )
		.trim();
	return JSON.parse( jsonText );
}

/** 移行前（v3 系）のインストールを作る。affilicard を読み込んだまま実行する。 */
function seed() {
	return parseMarkedJson(
		wpEnvRun( [
			'wp',
			'eval-file',
			'--use-include',
			`${ PLUGIN_PATH }/tests/e2e/migration-trigger-seed.php`,
		] ),
		'TRIGGER_SEED_JSON:'
	);
}

/** 状態を読む。**affilicard を読み込まない**（読み込むと点検自体が積んでしまう）。 */
function inspect() {
	return parseMarkedJson(
		wpEnvRun( [
			'wp',
			'--skip-plugins',
			'--skip-themes',
			'eval-file',
			'--use-include',
			`${ PLUGIN_PATH }/tests/e2e/migration-trigger-inspect.php`,
		] ),
		'TRIGGER_INSPECT_JSON:'
	);
}

/**
 * 移行が完走するまで Action Scheduler のランナーを回す。
 *
 * 1 回で済まないのは 2 つの理由による。(1) 1 バッチ 200 件なので、この環境に
 * 溜まっている商品数によっては継続ジョブへ分かれる。(2) 管理画面リクエストが
 * AS の非同期ランナーを起こしていると、こちらの CLI と claim が競合して
 * その回は 0 件で返ることがある。カーソル（＝未完の印）が消えるまで回す。
 *
 * @return {Object} 完走後（または打ち切り後）の状態。
 */
function runMigrationQueue() {
	let state = inspect();
	for ( let i = 0; i < 10 && false !== state.cursor; i++ ) {
		wpEnvRun( [
			'wp',
			'action-scheduler',
			'run',
			`--hooks=${ HOOK }`,
			'--force',
		] );
		state = inspect();
	}
	return state;
}

/** mu-plugin の記録係を片付ける（seed が書き出したもの）。 */
function removeRecorder() {
	wpEnvRun( [
		'wp',
		'--skip-plugins',
		'--skip-themes',
		'eval',
		"@unlink( WPMU_PLUGIN_DIR . '/affilicard-e2e-doing-it-wrong.php' ); delete_option( 'affilicard_e2e_doing_it_wrong' );",
	] );
}

test.describe( 'offers 移行の開始トリガー（通常のリクエスト経路・実 WP）', () => {
	/** @type {Object} 種まき直後・リクエスト前の状態。 */
	let before;
	/** @type {Object} 管理画面リクエスト直後の状態。 */
	let afterRequest;
	/** @type {Object} 移行を回し切った後の状態。 */
	let afterQueue;
	/** @type {number} 管理画面リクエストの HTTP ステータス。 */
	let adminStatus;
	/** @type {boolean} 商品一覧のテーブルが描画されたか。 */
	let adminRendered;

	test.beforeAll( async ( { browser } ) => {
		seed();
		before = inspect();

		// **ここが本題。** `runOffersMigrationBatch()` も `maybeUpgrade()` も直接呼ばない。
		// 本物の（ログイン済みの）HTTP リクエストを 1 本流し、`plugins_loaded` の
		// バージョン差分トリガーに引かせる。
		const context = await browser.newContext( {
			storageState: STORAGE_STATE,
		} );
		const page = await context.newPage();
		const response = await page.goto(
			'/wp-admin/edit.php?post_type=affilicard_product'
		);
		adminStatus = response ? response.status() : 0;
		adminRendered = await page
			.locator( 'table.wp-list-table' )
			.first()
			.isVisible();
		await context.close();

		afterRequest = inspect();
		afterQueue = runMigrationQueue();
	} );

	test.afterAll( () => {
		removeRecorder();
	} );

	test( '前提: リクエスト前はアクションも移行マーカーも無く、バージョンは v3 系である', async () => {
		// この 3 つが揃っていないと、以降の assertion は何も証明しない
		// （前回の残骸で「行がある」が通ってしまう）。
		expect( before.actionRows ).toHaveLength( 0 );
		expect( before.cursor ).toBe( false );
		expect( before.version ).toBe( '3.5.1' );
		expect( before.doingItWrong ).toEqual( [] );
	} );

	test( '管理画面を 1 回開くだけで移行アクションが実際に作られる', async () => {
		// **「呼ばれたこと」ではなく「行ができたこと」を見る。**
		// 旧実装は as_schedule_single_action() を確かに呼んでいた——呼んだ上で
		// 0 が返り、1 件も積まれていなかった。
		expect( afterRequest.actionRows.length ).toBeGreaterThan( 0 );
		expect( afterRequest.actionRows[ 0 ].hook ).toBe( HOOK );
		// 未完の印も立っている（AS のジョブが飛んでも次のリクエストが拾い直せる）。
		expect( afterRequest.cursor ).not.toBe( false );
	} );

	test( '管理画面が _doing_it_wrong を鳴らさない', async () => {
		// `is_initialized( __FUNCTION__ )` は未初期化のときに _doing_it_wrong() を
		// 鳴らす。`WP_DEBUG` のサイトではその出力が `plugins_loaded` の時点で始まり、
		// 「Cannot modify header information — headers already sent」で管理画面が壊れる。
		//
		// **記録は mu-plugin の `doing_it_wrong_run` フックで取る。**
		// `_doing_it_wrong()` は `WP_DEBUG` を見る前にこのアクションを do_action する
		// ので、表示設定に依存せず観測できる（wp-env の tests 環境は
		// `WP_DEBUG=false` / `display_errors=stderr` で、HTML には 1 文字も出ない）。
		expect( afterRequest.doingItWrong ).toEqual( [] );

		// 壊れていないことの直接の確認（表示設定次第では出力が混ざらないため、
		// 上の assertion の方が本体である）。
		expect( adminStatus ).toBe( 200 );
		expect( adminRendered ).toBe( true );
	} );

	test( '積まれた移行が完走し、flat な listing が offers[] へ変換される', async () => {
		// カーソルが消える＝完走（finishOffersMigration）。
		expect( afterQueue.cursor ).toBe( false );

		const normal = afterQueue.products.normal;
		expect( normal.listings ).toHaveLength( 1 );
		expect( normal.listings[ 0 ].offers ).toHaveLength( 1 );
		const offer = normal.listings[ 0 ].offers[ 0 ];
		expect( offer.external_id ).toBe( 'trigger-normal' );
		expect( offer.regular_url ).toBe( 'https://example.test/trigger-normal' );
		expect( offer.affiliate_url ).toBe(
			'https://example.test/trigger-normal-aff'
		);
		expect( offer.price ).toBe( '600' );
		// v3 の flat フィールドは listing 直下に残らない。
		expect( normal.listings[ 0 ].external_id ).toBeUndefined();
		// 派生 meta も再構築されている。
		expect( normal.schemaVersion ).toBe( '2' );
	} );

	test( '身元を持たない購入リンクの温存も、トリガー経由で初めて起こる', async () => {
		// この温存（と運用への通知）は移行の中でしか起きない。トリガーが引かれない
		// 限り、カウンタは 0 のまま＝「次の保存で消える」という警告すら出ないまま、
		// 通常の保存が先に来てデータが黙って消える。
		const noIdentity = afterQueue.products.no_identity;
		expect( noIdentity.listings ).toHaveLength( 1 );
		expect( noIdentity.listings[ 0 ].offers ).toHaveLength( 1 );
		expect( noIdentity.listings[ 0 ].offers[ 0 ].affiliate_url ).toBe(
			'https://example.test/trigger-no-identity'
		);
		expect( afterQueue.preserved ).toBeGreaterThan( 0 );
	} );
} );
