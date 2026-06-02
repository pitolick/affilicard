/**
 * E2E spec: affilicard_product metabox — save-on-publish 往復テスト
 *
 * 1. 管理画面で affilicard_product 新規投稿を開く（クラシックエディタ）
 * 2. タイトルを入力し、metabox で在庫状況を変更 + listing を追加してパブリッシュ
 * 3. 保存後にページをリロードし、React metabox に listing が復元されていることを確認
 *
 * 注意：
 * - CPT は show_in_rest=false のためクラシックエディタが使われる。
 * - metabox の React アプリは #affilicard-metabox-root に mount される。
 * - 保存は hidden textarea "affilicard_data" を通じて save_post で処理される。
 * - 新規投稿画面では postId が確定していないため metabox は「保存後に編集できます」
 *   を表示する。そのためまず投稿を下書き保存し、リダイレクト後の編集画面で
 *   metabox を操作する。
 */

const { test, expect, RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );

const TEST_PRODUCT_TITLE = 'E2E Metabox Test Product';
const AFFILIATE_URL = 'https://example.com/aff-test';

test.describe( 'affilicard_product metabox — save-on-publish', () => {
	let productPostId;

	test.beforeAll( async ( { requestUtils } ) => {
		// REST で affilicard_product を作成し、postId を確保してから
		// 管理画面の編集ページに直接アクセスする。
		// (新規投稿画面では postId 未確定のため metabox が表示されない)
		const product = await requestUtils.rest( {
			path: '/affilicard/v1/products',
			method: 'POST',
			data: {
				title: TEST_PRODUCT_TITLE,
				status: 'draft',
				product_type: 'ebook',
				stock_status: 'available',
				listings: [],
			},
		} );
		productPostId = product.id;
		expect( productPostId ).toBeGreaterThan( 0 );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		if ( productPostId ) {
			await requestUtils.rest( {
				path: `/affilicard/v1/products/${ productPostId }`,
				method: 'DELETE',
			} ).catch( () => {} );
		}
	} );

	test( 'listing を追加してパブリッシュ → リロード後に listing が保持される', async ( {
		page,
		admin,
	} ) => {
		// 1. 既存の下書き商品の編集ページを開く
		await admin.visitAdminPage(
			'post.php',
			`post=${ productPostId }&action=edit`
		);

		// metabox の React アプリがロードを完了するまで待つ
		// #affilicard-metabox-root 内に .affilicard-metabox が表示されるまで待機
		const metaboxRoot = page.locator( '#affilicard-metabox-root' );
		await expect( metaboxRoot ).toBeVisible();
		await expect(
			metaboxRoot.locator( '.affilicard-metabox' )
		).toBeVisible( { timeout: 15000 } );

		// 2. 在庫状況を「在庫切れ」に変更
		// StockStatusSelect は label="在庫状況" の SelectControl
		await metaboxRoot
			.getByLabel( '在庫状況' )
			.selectOption( 'out_of_stock' );

		// 3. listing を追加
		// ListingsEditor の「listing を追加」ボタンをクリック
		await metaboxRoot.getByRole( 'button', { name: 'listing を追加' } ).click();

		// プラットフォームが読み込まれるまで待機（SelectControl が描画される）
		const listingRow = metaboxRoot.locator( '.affilicard-listing-row' ).first();
		await expect( listingRow ).toBeVisible( { timeout: 10000 } );

		// プラットフォームを選択（dmm-books）
		// SelectControl label="プラットフォーム" の select 要素
		await listingRow
			.getByLabel( 'プラットフォーム' )
			.selectOption( 'dmm-books' );

		// アフィリエイト URL を入力
		await listingRow
			.getByLabel( 'アフィリエイト URL' )
			.fill( AFFILIATE_URL );

		// 4. パブリッシュボタン（クラシックエディタ）をクリック
		// #publish はクラシックエディタの「公開」「更新」ボタン
		await page.locator( '#publish' ).click();

		// 5. 保存完了を確認（URL が post=X&action=edit になる or 更新通知）
		await page.waitForURL(
			( url ) =>
				url.searchParams.get( 'action' ) === 'edit' &&
				url.searchParams.has( 'post' ),
			{ timeout: 20000 }
		);

		// 更新後の URL から post ID を確認
		const savedPostId = new URL( page.url() ).searchParams.get( 'post' );
		expect( Number( savedPostId ) ).toBeGreaterThan( 0 );

		// 6. ページをリロード
		await page.reload();

		// metabox の React アプリが再マウントされるまで待機
		const reloadedMetaboxRoot = page.locator( '#affilicard-metabox-root' );
		await expect(
			reloadedMetaboxRoot.locator( '.affilicard-metabox' )
		).toBeVisible( { timeout: 15000 } );

		// 7. 在庫状況が保持されていることを確認
		await expect(
			reloadedMetaboxRoot.getByLabel( '在庫状況' )
		).toHaveValue( 'out_of_stock' );

		// 8. listing が復元されており、アフィリエイト URL が保持されていることを確認
		const reloadedListingRow = reloadedMetaboxRoot
			.locator( '.affilicard-listing-row' )
			.first();
		await expect( reloadedListingRow ).toBeVisible( { timeout: 10000 } );

		await expect(
			reloadedListingRow.getByLabel( 'アフィリエイト URL' )
		).toHaveValue( AFFILIATE_URL );

		await expect(
			reloadedListingRow.getByLabel( 'プラットフォーム' )
		).toHaveValue( 'dmm-books' );
	} );
} );
