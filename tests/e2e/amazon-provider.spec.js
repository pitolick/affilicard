/**
 * E2E spec: Amazon Creators API Provider（設計 2026-11-24）
 *
 * 1. 設定 → プラットフォーム → API 認証 に Amazon アカウントの入力欄が並ぶ
 * 2. Amazon Kindle に「自動取得（Amazon Creators API）」の切り替えが出る。
 *    既定は手動のまま（自動取得を始めるかは管理者が決める）
 *
 * 実 API は叩かない（認証情報を保存しない）。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );

const SETTINGS_URL =
	'/wp-admin/edit.php?post_type=affilicard_product&page=affilicard-settings';

async function openPlatformsTab( page, subTab ) {
	await page.goto( SETTINGS_URL );
	await page.getByRole( 'tab', { name: 'プラットフォーム' } ).click();
	await page.getByRole( 'tab', { name: subTab } ).click();
}

test( 'API 認証に Amazon アカウントの入力欄が並ぶ', async ( { page } ) => {
	await openPlatformsTab( page, 'API 認証' );

	const panel = page
		.locator( '.affilicard-api-credentials-panel .components-panel__body' )
		.filter( { has: page.getByRole( 'button', { name: 'Amazon', exact: true } ) } );
	await expect( panel ).toBeVisible( { timeout: 15_000 } );
	// 既に設定済み（initialOpen=false）の環境でも中身を検証できるよう、閉じていれば開く。
	const toggle = panel.getByRole( 'button', { name: 'Amazon', exact: true } );
	if ( ( await toggle.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
		await toggle.click();
	}

	await expect( panel.getByLabel( 'Credential ID（Creators API）' ) ).toBeVisible();
	await expect( panel.getByLabel( 'Credential Secret' ) ).toHaveAttribute( 'type', 'password' );
	await expect( panel.getByLabel( 'トラッキングID（Partner Tag）' ) ).toBeVisible();
	await expect( panel.getByLabel( /マーケットプレイス/ ) ).toBeVisible();
} );

test( 'Amazon Kindle は既定で手動のまま、自動取得（Amazon Creators API）を選べる', async ( {
	page,
} ) => {
	await openPlatformsTab( page, '電子書籍' );
	await page.getByRole( 'button', { name: 'Amazon Kindle (amazon-kindle)' } ).click();

	const toggle = page.getByLabel( '自動取得（Amazon Creators API）' );
	await expect( toggle ).toBeVisible( { timeout: 15_000 } );
	await expect( toggle ).not.toBeChecked();
} );
