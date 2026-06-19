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

		const openPanel = async () => {
			const toggle = page.getByRole( 'button', { name: 'Affilicard 商品設定' } );
			if ( await toggle.count() ) {
				const expanded = await toggle.getAttribute( 'aria-expanded' );
				if ( expanded === 'false' ) {
					await toggle.click();
				}
			}
		};
		await openPanel();

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
		await openPanel();
		await expect(
			page.getByLabel( 'アフィリエイト URL' ).last()
		).toHaveValue( affUrl );
	} );
} );
