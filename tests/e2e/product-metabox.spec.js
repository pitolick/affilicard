'use strict';

const { test, expect } = require( '@playwright/test' );

test.describe( 'affilicard_product サイドバー設定 — core-data save', () => {
	test( 'サイドバーで listing を入力して公開 → リロード後も保持される', async ( {
		page,
	} ) => {
		const affUrl = 'https://example.com/aff-sidebar';

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
		await expect(
			page.getByLabel( 'アフィリエイト URL' ).last()
		).toHaveValue( affUrl );
	} );
} );
