/**
 * E2E spec: affilicard_product metabox — Gutenberg 下の save-on-publish 往復テスト
 *
 * 検証スパイク（PR-B）: show_in_rest=true で Gutenberg を有効化した状態で、
 * 1) 本文（post_content）を Gutenberg で入力 → 公開 → リロードで保持
 * 2) metabox（hidden textarea + save_post の $_POST 経路）で listing を追加 → 公開 →
 *    リロードで保持
 * が成立することを確認する。これが赤なら保存方式を register_post_meta+core-data に
 * 切り替える（plan Appendix A）。
 *
 * ロケール: wp-env は en_US。Gutenberg クロームは英語、metabox ラベルは日本語。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );

test.describe( 'affilicard_product metabox — Gutenberg save-on-publish', () => {
	test( '本文と listing を入力して公開 → リロード後も保持される', async ( {
		page,
	} ) => {
		const bodyText = 'スパイク本文テキスト ' + 'abc123';
		const affUrl = 'https://example.com/aff-spike';

		// 1. 新規 affilicard_product を Gutenberg で開く
		await page.goto(
			'/wp-admin/post-new.php?post_type=affilicard_product'
		);

		// ウェルカムガイド等のモーダルが出たら閉じる（存在しなければ無視）
		const closeModal = page.getByRole( 'button', { name: 'Close' } );
		if ( await closeModal.isVisible().catch( () => false ) ) {
			await closeModal.click();
		}

		// 2. タイトル入力
		await page
			.getByRole( 'textbox', { name: 'Add title' } )
			.fill( 'E2E スパイク商品' );

		// 3. 本文（段落ブロック）入力
		await page
			.getByRole( 'button', { name: 'Add default block' } )
			.click();
		await page.keyboard.type( bodyText );

		// 4. metabox が描画されるまで待機し、listing を 1 件追加
		const metabox = page.locator( '#affilicard-metabox-root' );
		await metabox
			.locator( '.affilicard-metabox' )
			.waitFor( { state: 'visible' } );
		await metabox
			.getByRole( 'button', { name: 'listing を追加' } )
			.click();

		// 5. 追加された行（初期展開）でプラットフォームと URL を入力
		await metabox
			.getByLabel( 'プラットフォーム' )
			.last()
			.selectOption( 'dmm-books' );
		await metabox
			.getByLabel( 'アフィリエイト URL' )
			.last()
			.fill( affUrl );

		// 6. 公開（Gutenberg の 2 段階 publish）
		await page
			.getByRole( 'button', { name: 'Publish', exact: true } )
			.click();
		await page
			.locator( '.editor-post-publish-panel' )
			.getByRole( 'button', { name: 'Publish', exact: true } )
			.click();
		await expect(
			page.getByText( 'is now live.', { exact: false } )
		).toBeVisible();

		// 7. リロードして保持を確認
		await page.reload();
		await metabox
			.locator( '.affilicard-metabox' )
			.waitFor( { state: 'visible' } );
		await expect(
			metabox.getByLabel( 'アフィリエイト URL' ).last()
		).toHaveValue( affUrl );
		// 本文も保持
		await expect(
			page.locator( '.block-editor-block-list__layout' )
		).toContainText( bodyText );
	} );
} );
