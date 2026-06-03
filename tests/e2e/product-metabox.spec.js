/**
 * E2E spec: affilicard_product metabox — save-on-publish 往復テスト
 *
 * 1. wp-cli で affilicard_product の下書きを作成（postId 確定）
 * 2. wp-admin の編集画面を開き、React metabox が表示されることを確認
 * 3. 在庫状況を変更 + listing を追加してパブリッシュ
 * 4. リロード後に listing が保持されていることを確認
 *
 * NOTE: セレクタは ListingsEditor.jsx / StockStatusSelect.jsx 実装に依存する。
 *       wp-env の初回起動直後は REST エンドポイントが遅い場合があるため
 *       metabox の描画待機に十分な timeout を設けている。
 *       セレクタがずれる場合はコメントの「要調整」箇所を修正すること。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );

const TEST_PRODUCT_TITLE = 'E2E Metabox Test Product';
const AFFILIATE_URL = 'https://example.com/aff-test';

/**
 * wp-env の tests-cli コンテナでコマンドを実行する。
 *
 * @param {string} cmd  `wp ...` 以降のコマンド文字列
 * @returns {string}    stdout (trimmed)
 */
function wpCli( cmd ) {
	return execSync( `npx wp-env run tests-cli wp ${ cmd }`, {
		encoding: 'utf8',
		stdio: [ 'pipe', 'pipe', 'pipe' ],
	} ).trim();
}

// ------------------------------------------------------------
// テスト本体
// ------------------------------------------------------------

test.describe( 'affilicard_product metabox — save-on-publish', () => {
	/** @type {number} */
	let productPostId;

	test.beforeAll( () => {
		// wp-cli で下書きを作成して postId を確定させる
		// （新規投稿画面は postId 未確定のため React metabox が表示されない）
		const id = wpCli(
			`post create --post_type=affilicard_product --post_status=draft --post_title='${ TEST_PRODUCT_TITLE }' --porcelain`
		);
		productPostId = parseInt( id, 10 );
		if ( ! productPostId || isNaN( productPostId ) ) {
			throw new Error( `Failed to create draft product, got: ${ id }` );
		}
	} );

	test.afterAll( () => {
		if ( productPostId ) {
			try {
				wpCli( `post delete ${ productPostId } --force` );
			} catch {
				// クリーンアップ失敗は無視
			}
		}
	} );

	test( 'listing を追加してパブリッシュ → リロード後に listing が保持される', async ( {
		page,
	} ) => {
		// 1. 既存の下書き商品の編集ページを開く
		await page.goto(
			`/wp-admin/post.php?post=${ productPostId }&action=edit`
		);

		// 2. React metabox がマウントされるまで待機
		//    #affilicard-metabox-root 内に .affilicard-metabox が表示されるまで
		const metaboxRoot = page.locator( '#affilicard-metabox-root' );
		await expect( metaboxRoot ).toBeVisible( { timeout: 20_000 } );
		await expect(
			metaboxRoot.locator( '.affilicard-metabox' )
		).toBeVisible( { timeout: 20_000 } );

		// 3. 在庫状況を「在庫切れ」に変更
		//    StockStatusSelect: label="在庫状況" の SelectControl（要調整）
		await metaboxRoot
			.getByLabel( '在庫状況' )
			.selectOption( 'out_of_stock' );

		// 4. listing を追加
		//    ListingsEditor: ボタンテキスト「listing を追加」（要調整）
		await metaboxRoot
			.getByRole( 'button', { name: 'listing を追加' } )
			.click();

		// listing 行が表示されるまで待機
		const listingRow = metaboxRoot
			.locator( '.affilicard-listing-row' )
			.first();
		await expect( listingRow ).toBeVisible( { timeout: 15_000 } );

		// プラットフォームを選択（dmm-books）
		//    SelectControl label="プラットフォーム"（要調整）
		await listingRow
			.getByLabel( 'プラットフォーム' )
			.selectOption( 'dmm-books' );

		// アフィリエイト URL を入力
		//    TextControl label="アフィリエイト URL"（要調整）
		await listingRow
			.getByLabel( 'アフィリエイト URL' )
			.fill( AFFILIATE_URL );

		// 5. パブリッシュ（クラシックエディタの #publish ボタン）
		await page.locator( '#publish' ).click();

		// 6. 保存完了まで待機（URL が post=X&action=edit に変わる）
		await page.waitForURL(
			( url ) =>
				url.searchParams.get( 'action' ) === 'edit' &&
				url.searchParams.has( 'post' ),
			{ timeout: 30_000 }
		);

		// 7. ページをリロードして React metabox を再マウント
		await page.reload();
		const reloadedRoot = page.locator( '#affilicard-metabox-root' );
		await expect(
			reloadedRoot.locator( '.affilicard-metabox' )
		).toBeVisible( { timeout: 20_000 } );

		// 8. 最低限の assertion:
		//    listing 行が少なくとも 1 件表示されていること（metabox がデータを復元した証拠）
		const reloadedRow = reloadedRoot
			.locator( '.affilicard-listing-row' )
			.first();
		await expect( reloadedRow ).toBeVisible( { timeout: 15_000 } );

		// アフィリエイト URL が保持されていること（要調整: label が異なる場合はセレクタを修正）
		await expect(
			reloadedRow.getByLabel( 'アフィリエイト URL' )
		).toHaveValue( AFFILIATE_URL );
	} );
} );
