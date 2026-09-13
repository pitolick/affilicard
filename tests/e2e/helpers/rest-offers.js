/**
 * Task 16 E2E ヘルパー。
 *
 * offers[] の REST 往復・複数値 extid ミラーを実 WP（wp-env）に対して検証するための
 * 共通処理をまとめる。unit test（WP_Mock）では sanitizeListings の whitelist 漏れも
 * add_post_meta/delete_post_meta/meta_query の実際の複数値挙動も検出できないため、
 * ここは必ず本物の WordPress REST API と wp-cli 経由で読み書きする。
 *
 * **cookie を一切乗せない専用の APIRequestContext を使う。** playwright.config.js は
 * `use.storageState` で全 fixture（`request` を含む）に管理者の cookie を持たせている。
 * REST に有効な `wordpress_logged_in_*` cookie が乗っていると、Application Password の
 * Basic 認証ヘッダを付けていても WordPress 側の cookie 認証チェック
 * （`rest_cookie_check_errors`）が先に反応し、`X-WP-Nonce` を送っていないという理由で
 * 未認証（`rest_not_logged_in` / `rest_cannot_create`）として弾かれる
 * （実機で確認済み。cookie を持たない `request.newContext()` に切り替えると解消する）。
 * そのため `@playwright/test` の `request.newContext()`（cookie 無しの独立コンテキスト）を
 * 自前で作り、Basic 認証ヘッダだけで REST を叩く。**Playwright のテストランナー内では
 * `request.newContext()` を呼んでも、素の Node スクリプトから呼ぶのとは違って
 * `playwright.config.js` の `use.storageState` がアンビエントに適用されてしまい、
 * オプション省略では cookie が乗ったままになる（実機で確認済み）。`storageState:
 * { cookies: [], origins: [] }` を明示して初めて本当に cookie 無しの独立コンテキストになる。**
 *
 * `getAuthHeaders()` は global-setup.js が作った Application Password
 * （artifacts/app-credentials.json）から Basic 認証ヘッダを組み立てる。**必ず遅延読み込みにする。**
 * Playwright はテスト一覧を作るために spec ファイルを `globalSetup` より前に require するため、
 * モジュール読み込み時（トップレベル）にこのファイルを読むと「前回実行時点のパスワード」を
 * 掴んでしまう。global-setup.js は起動のたびに `wp user application-password delete --all` で
 * 古いものを消してから作り直すため、掴んだままの古いパスワードは REST に投げた瞬間に無効
 * （401 rest_not_logged_in）になる。関数呼び出し（＝テスト本体の実行時＝setup 完了後）の
 * たびにファイルを読み直すことで、常に今回実行分の値を使う。
 */

'use strict';

const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const { request: pwRequest } = require( '@playwright/test' );

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8889';

function getAuthHeaders() {
	const creds = JSON.parse( fs.readFileSync( 'artifacts/app-credentials.json', 'utf8' ) );
	return {
		Authorization:
			'Basic ' + Buffer.from( `${ creds.username }:${ creds.password }` ).toString( 'base64' ),
	};
}

/** cookie を持たない REST 専用コンテキスト（プロセス内で使い回す）。 */
let apiContextPromise = null;
function getApiContext() {
	if ( ! apiContextPromise ) {
		apiContextPromise = pwRequest.newContext( {
			baseURL: BASE_URL,
			// 明示的な空の storageState で playwright.config.js のアンビエントな
			// cookie 継承を打ち消す（省略すると管理者の cookie が乗ってしまう）。
			storageState: { cookies: [], origins: [] },
		} );
	}
	return apiContextPromise;
}

/**
 * `wp-env run tests-cli <cmd>` を実行し、`ℹ Starting ...` / `✔ Ran ...` の
 * バナー行を取り除いた実コマンドの標準出力だけを返す。
 * （バナーはコマンド出力と改行無しで連結されるため、末尾を正規表現で切り落とす）
 */
function runWpCli( cmd ) {
	const raw = execSync( `npx wp-env run tests-cli ${ cmd }`, { encoding: 'utf8' } );
	return raw.replace( /^ℹ[^\n]*\n\n/, '' ).replace( /✔ Ran `.*$/s, '' ).trim();
}

/**
 * タイトル検索に一致する前回実行分の商品を削除する（stale fixture の掃除）。
 *
 * global-setup は test:e2e 実行のたびに DB をリセットしないため、REST 経由で
 * 商品を作るテストは掃除しないと実行のたびに重複が蓄積する。蓄積すると
 * `findByExternalId()`（date DESC で 1 件だけ返す `get_posts`）が「たまたま
 * 一番新しい重複」を拾ってしまい、古い重複が残っていても気づけないまま
 * テストが green であり続ける（stale データに依存した見せかけの成功）。
 * migration-fixture.php が PHP 側でやっている掃除と同じことを、REST 経由で
 * 商品を作るテスト用に wp-cli 側で行う。
 */
function cleanupStaleFixtures( titleSearch ) {
	const ids = runWpCli(
		`wp post list --post_type=affilicard_product --post_status=any "--s=${ titleSearch }" --field=ID --format=csv`
	)
		.split( /\r?\n/ )
		.map( ( s ) => s.trim() )
		.filter( Boolean );

	if ( 0 === ids.length ) {
		return;
	}

	runWpCli( `wp post delete ${ ids.join( ' ' ) } --force` );
}

/**
 * `wp eval-file` を実行し、`<marker>` 以降を JSON として parse する。
 * seed.php / global-setup.js と同じ「マーカー行を探す」方式（シェルクォート問題を避ける）。
 */
function runEvalFileJson( relativePathUnderPlugin, args, marker ) {
	const argStr = args.map( ( a ) => String( a ) ).join( ' ' );
	const raw = execSync(
		`npx wp-env run tests-cli wp eval-file wp-content/plugins/affilicard/${ relativePathUnderPlugin } ${ argStr }`,
		{ encoding: 'utf8' }
	);
	const idx = raw.indexOf( marker );
	if ( -1 === idx ) {
		throw new Error( `${ relativePathUnderPlugin } did not output ${ marker }. Output:\n${ raw }` );
	}
	const jsonText = raw
		.slice( idx + marker.length )
		.replace( /✔ Ran `.*$/s, '' )
		.trim();
	return JSON.parse( jsonText );
}

async function createProduct( { title, listings, status = 'publish' } ) {
	const ctx = await getApiContext();
	const res = await ctx.post( '/wp-json/wp/v2/affilicard_product', {
		headers: getAuthHeaders(),
		data: {
			title,
			status,
			meta: { affilicard_listings: listings },
		},
	} );
	if ( ! res.ok() ) {
		throw new Error( `create failed: ${ res.status() } ${ await res.text() }` );
	}
	return ( await res.json() ).id;
}

/**
 * platform 1 つ・offers[] を持つ listing だけの商品を作る。
 */
async function createProductWithOffers(
	offers,
	{ platform = 'rakuten-kobo', title = 'E2E offers 商品' } = {}
) {
	return createProduct( {
		title,
		listings: [ { platform, enabled: true, offers } ],
	} );
}

/**
 * 商品の listings を丸ごと置き換える（REST は POST /wp/v2/affilicard_product/:id で更新する）。
 */
async function updateOffers( id, offers, { platform = 'rakuten-kobo' } = {} ) {
	const ctx = await getApiContext();
	const res = await ctx.post( `/wp-json/wp/v2/affilicard_product/${ id }`, {
		headers: getAuthHeaders(),
		data: {
			meta: { affilicard_listings: [ { platform, enabled: true, offers } ] },
		},
	} );
	if ( ! res.ok() ) {
		throw new Error( `update failed: ${ res.status() } ${ await res.text() }` );
	}
	return res.json();
}

/**
 * REST（context=edit）から affilicard_listings meta を読み戻す。
 */
async function readListings( id ) {
	const ctx = await getApiContext();
	const res = await ctx.get( `/wp-json/wp/v2/affilicard_product/${ id }?context=edit`, {
		headers: getAuthHeaders(),
	} );
	if ( ! res.ok() ) {
		throw new Error( `read failed: ${ res.status() } ${ await res.text() }` );
	}
	const body = await res.json();
	return body.meta.affilicard_listings;
}

/**
 * 複数値 post meta を wp-cli 経由で読む。
 *
 * `affilicard_extid_<platform>` は REST に露出しない内部ミラー meta（ProductMeta::register()
 * が登録していない）ため、REST では読めない。実 DB の複数行を見るため wp post meta list を使う。
 *
 * @return {string[]} 格納順（sort 済みではない）。
 */
function readMetaValues( postId, metaKey ) {
	const raw = runWpCli( `wp post meta list ${ postId } --keys=${ metaKey } --format=json` );
	if ( '' === raw ) {
		return [];
	}
	const rows = JSON.parse( raw );
	return rows.map( ( row ) => row.meta_value );
}

/**
 * `ProductRepository::findByExternalId()` を実 WP 上で直接叩く。
 *
 * 自動作成のみが使う内部経路のため REST では露出していない。meta_query の `=` が
 * 複数値 meta の中から正しい 1 行に一致することを、モックではなく実 MySQL で確認する。
 *
 * @return {number|null}
 */
function findByExternalId( platform, externalId ) {
	const result = runEvalFileJson(
		'tests/e2e/find-by-external-id.php',
		[ platform, externalId ],
		'RESULT_JSON:'
	);
	return result.id;
}

module.exports = {
	getAuthHeaders,
	createProduct,
	createProductWithOffers,
	updateOffers,
	readListings,
	readMetaValues,
	findByExternalId,
	runEvalFileJson,
	cleanupStaleFixtures,
};
