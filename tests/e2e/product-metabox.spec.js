'use strict';

const { test, expect } = require( '@playwright/test' );

test.describe( 'affilicard_product サイドバー設定 — core-data save', () => {
	test( 'サイドバーで listing を入力して公開 → リロード後も保持される', async ( {
		page,
	} ) => {
		const affUrl = 'https://example.com/aff-sidebar';
		const regularUrl = 'https://example.com/product-sidebar';

		await page.goto( '/wp-admin/post-new.php?post_type=affilicard_product' );

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

		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await canvas
			.getByRole( 'textbox', { name: 'Add title' } )
			.fill( 'E2E サイドバー商品' );

		const expandSection = async ( name ) => {
			const btn = page.getByRole( 'button', { name } );
			await expect( btn ).toBeVisible( { timeout: 15_000 } );
			if ( ( await btn.getAttribute( 'aria-expanded' ) ) === 'false' ) {
				await btn.click();
			}
		};

		await expandSection( 'Affilicard 商品設定' );
		await expandSection( 'プラットフォーム listing' );
		await page.getByRole( 'button', { name: 'listing を追加' } ).click();
		await page.getByLabel( 'プラットフォーム' ).last().selectOption( 'dmm-books' );
		// 購入リンク（offer）は listing 追加時点では 0 件（「購入リンクがありません」）
		// のため、フィールドを触る前に「購入リンクを追加」でまず 1 件作る必要がある
		// （Task 15 の並べ替え UI 導入で listing 直下から offers[] へ分離された）。
		await page.getByRole( 'button', { name: '購入リンクを追加' } ).click();
		// 追加直後の購入リンクの PanelBody は閉じた状態（openKeys の初期値は空集合）で
		// 始まるため、中のフィールドを触る前に開く。external_id 未入力なので
		// タイトルは固定文言になる。
		await expandSection( '（外部 ID 未設定）' );
		// 通常 URL（regular_url）は必須。空のまま保存すると
		// ProductSchema::sanitizeOffers() がこの購入リンクごと破棄する。
		await page.getByLabel( '通常 URL（必須）' ).last().fill( regularUrl );
		await page.getByLabel( 'アフィリエイト URL' ).last().fill( affUrl );

		await page.getByRole( 'button', { name: 'Publish', exact: true } ).click();
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

		await page.reload();
		await page.waitForFunction(
			() => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId()
		);
		await expandSection( 'Affilicard 商品設定' );
		await expandSection( 'プラットフォーム listing' );
		await expandSection( '（外部 ID 未設定）' );
		await expect(
			page.getByLabel( '通常 URL（必須）' ).last()
		).toHaveValue( regularUrl );
		await expect(
			page.getByLabel( 'アフィリエイト URL' ).last()
		).toHaveValue( affUrl );
	} );
} );
