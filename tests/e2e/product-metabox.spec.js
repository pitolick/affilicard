/**
 * E2E spec: affilicard_product metabox — save-on-publish 往復テスト
 *
 * NOTE: セレクタは ListingsEditor.jsx / StockStatusSelect.jsx 実装に依存する。
 *       save-on-publish ロジックは PHPUnit + 手動確認済みのため、
 *       E2E セレクタ調整が完了するまで test.skip で保留する。
 *
 * 実装予定ステップ（セレクタ確定後に test.skip を外すこと）:
 *   1. wp-cli で affilicard_product の下書きを作成（postId 確定）
 *   2. /wp-admin/post.php?post=<id>&action=edit を開く
 *   3. #affilicard-metabox-root 内に .affilicard-metabox が表示されるまで待機
 *   4. 在庫状況を「在庫切れ」に変更 (getByLabel('在庫状況').selectOption('out_of_stock'))
 *   5. listing を追加 (getByRole('button', { name: 'listing を追加' }).click())
 *   6. .affilicard-listing-row が表示されるまで待機
 *   7. プラットフォームを選択 (getByLabel('プラットフォーム').selectOption('dmm-books'))
 *   8. アフィリエイト URL を入力 (getByLabel('アフィリエイト URL').fill(...))
 *   9. #publish をクリックして保存完了を待機
 *  10. リロード後に listing 行と URL が保持されていることを確認
 */

'use strict';

const { test } = require( '@playwright/test' );

test.describe( 'affilicard_product metabox — save-on-publish', () => {
	test.skip(
		true,
		'metabox UI E2E は別途セレクタ調整が必要。save-on-publish は PHPUnit + 手動確認済み。'
	);

	test( 'listing を追加してパブリッシュ → リロード後に listing が保持される', async () => {
		// セレクタ調整後に上記ステップを実装する
	} );
} );
