/**
 * E2E spec: API の上限（429）・利用条件（403）で account を止めている間の表示
 *
 * 1. 設定 → 更新キュー の account 行に「今日の API 呼び出し」と一時停止の警告が出る
 * 2. ダッシュボードに一時停止の通知（AccountPauseNotice）が出る
 * 3. 停止を解く（option を消す）と、どちらも消える
 *
 * 実 API を叩いて 429 を起こすのは不可能（外部 API はモックする方針）なので、
 * AccountPause / ApiUsage が書く option を wp-cli で直接置いて画面だけを確かめる。
 * 停止に入る・解ける判定そのものは PHPUnit（AccountPauseTest / AmazonProviderTest）で網羅済み。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );
const { execFileSync } = require( 'child_process' );

const SETTINGS_URL =
	'/wp-admin/edit.php?post_type=affilicard_product&page=affilicard-settings';

/**
 * `wp-env run tests-cli wp eval <code>` をシェルを介さずに実行する（global-setup.js と同じ流儀）。
 *
 * @param {string} code PHP コード。
 */
function wpEval( code ) {
	execFileSync( 'npx', [ 'wp-env', 'run', 'tests-cli', 'wp', 'eval', code ], {
		encoding: 'utf8',
	} );
}

function pauseAmazon() {
	wpEval(
		"update_option( 'affilicard_account_pause_amazon', array( 'until' => time() + 3600, 'reason' => 'rate_limited', 'since' => time() - 60, 'streak' => 1, 'notified' => true ), false );" +
			" update_option( 'affilicard_api_usage_amazon', array( wp_date( 'Y-m-d' ) => array( 'calls' => 12, 'rate_limited' => 3, 'not_eligible' => 0 ) ), false );"
	);
}

function clearAmazon() {
	wpEval(
		"delete_option( 'affilicard_account_pause_amazon' ); delete_option( 'affilicard_api_usage_amazon' );"
	);
}

test.describe( 'API の上限で account を止めている間の表示', () => {
	test.beforeAll( () => {
		pauseAmazon();
	} );

	test.afterAll( () => {
		clearAmazon();
	} );

	test( '更新キューの Amazon 行に今日の呼び出し回数と一時停止の警告が出る', async ( {
		page,
	} ) => {
		await page.goto( SETTINGS_URL );
		await page.getByRole( 'tab', { name: '更新キュー' } ).click();
		await expect(
			page.getByRole( 'heading', { name: '更新キュー管理' } )
		).toBeVisible( { timeout: 15_000 } );

		const row = page
			.locator( '.affilicard-queue-panel__provider-row' )
			.filter( { has: page.getByText( 'Amazon', { exact: true } ) } );
		await expect(
			row.locator( '.affilicard-queue-panel__api-usage' )
		).toHaveText( /今日の API 呼び出し: 12.*上限 \(429\): 3/ );
		await expect(
			row.locator( '.affilicard-queue-panel__api-pause' )
		).toContainText( '一時停止中' );
	} );

	test( 'ダッシュボードに一時停止の通知が出る', async ( { page } ) => {
		await page.goto( '/wp-admin/index.php' );
		const notice = page.locator( '.affilicard-account-pause-notice' );
		await expect( notice ).toBeVisible();
		await expect( notice ).toContainText(
			'Amazon の価格更新を一時停止しています'
		);
		await expect(
			notice.getByRole( 'link', { name: '設定 → 更新キューで確認' } )
		).toHaveAttribute( 'href', /page=affilicard-settings/ );
	} );

	test( '止めていなければダッシュボードにもパネルにも出ない', async ( {
		page,
	} ) => {
		clearAmazon();

		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '#wpbody-content' ) ).toBeVisible();
		await expect(
			page.locator( '.affilicard-account-pause-notice' )
		).toHaveCount( 0 );

		await page.goto( SETTINGS_URL );
		await page.getByRole( 'tab', { name: '更新キュー' } ).click();
		await expect(
			page.getByRole( 'heading', { name: '更新キュー管理' } )
		).toBeVisible( { timeout: 15_000 } );
		await expect(
			page.locator( '.affilicard-queue-panel__api-pause' )
		).toHaveCount( 0 );
	} );
} );
