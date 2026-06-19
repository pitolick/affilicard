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

		// ウェルカムガイド（初回起動モーダル）を core/preferences で確実に無効化する。
		// Close ボタンのクリック競合を避けるため、設定値で消す。
		await page.waitForFunction(
			() =>
				window.wp &&
				window.wp.data &&
				window.wp.data.select( 'core/edit-post' ) &&
				window.wp.data.dispatch( 'core/preferences' )
		);
		await page.evaluate( () => {
			window.wp.data
				.dispatch( 'core/preferences' )
				.set( 'core/edit-post', 'welcomeGuide', false );
		} );

		// Gutenberg 6.x はエディタキャンバスを iframe 化する。タイトル・本文は
		// iframe 内にあるため frameLocator 経由で操作する（metabox・公開は top frame）。
		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );

		// 2. タイトル入力
		await canvas
			.getByRole( 'textbox', { name: 'Add title' } )
			.fill( 'E2E スパイク商品' );

		// 3. 本文（段落ブロック）入力
		await canvas
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
		await page.waitForFunction( () => {
			const editor = window.wp?.data?.select( 'core/editor' );
			return (
				!! editor &&
				!! editor.getCurrentPostId() &&
				! editor.isSavingPost() &&
				editor.getCurrentPostAttribute( 'status' ) === 'publish'
			);
		} );

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
			canvas.locator( '.block-editor-block-list__layout' )
		).toContainText( bodyText );
	} );
} );
