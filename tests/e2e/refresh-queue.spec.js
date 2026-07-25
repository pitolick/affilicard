/**
 * E2E spec: Task 17 — 更新キュー管理パネル（設定画面 / Task 15,16）
 *
 * 方針:
 * - 「設定画面に『更新キュー』タブが表示される」「タブを開くと QueuePanel が
 *   REST（GET /affilicard/v1/refresh-queue, GET /affilicard/v1/settings）から
 *   summary/depth/paused を取得してサマリーを描画する」は、seed 済み admin
 *   セッションで決定的に検証できる（CI-safe）。Action Scheduler は本体同梱
 *   （vendor/woocommerce/action-scheduler）で外部 API・wp-cli 不要のローカル
 *   REST のため、DMM/楽天アカウント未設定の素の wp-env でも安定して通る。
 * - 「手動一括更新ボタンで enqueue され Tools→Scheduled Actions に
 *   affilicard-* group のジョブが現れる」「pause トグルの状態がリロード後も
 *   保持される」は、Scheduled Actions 一覧画面の行特定・管理 UI セレクタ調整が
 *   壊れやすいため、ロジックは PHPUnit / Jest で網羅済み（QueueControllerTest /
 *   RefreshControllerTest / QueuePanel.test.jsx / PluginTest の wiring テスト）。
 *   対話的な最終確認は Phase P5 終了時に seed 済み Playground 上で実施する
 *   （既存 autocreate-cron.spec.js / product-metabox.spec.js と同じ、未確定分は
 *   理由付きで test.skip する慣習に倣う）。
 */

'use strict';

const { test, expect } = require('@playwright/test');

test.describe('更新キュー管理パネル（設定画面）', () => {
	test('設定画面に「更新キュー」タブが表示され、開くとサマリーが描画される', async ({
		page,
	}) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=affilicard_product&page=affilicard-settings'
		);

		const queueTab = page.getByRole('tab', { name: '更新キュー' });
		await expect(queueTab).toBeVisible({ timeout: 15_000 });

		await queueTab.click();

		// QueuePanel は GET /affilicard/v1/refresh-queue と GET /affilicard/v1/settings
		// の解決を待って「読み込み中…」からサマリー本体へ切り替わる。
		await expect(
			page.getByRole('heading', { name: '更新キュー管理' })
		).toBeVisible({ timeout: 15_000 });
		await expect(page.getByLabel('キューを一時停止する')).toBeVisible();
		await expect(page.getByText(/キューの深さ/)).toBeVisible();
	});
});

test.describe('手動投入 / pause 永続化（対話確認・Phase P5 終了時に Playground で実施）', () => {
	test.skip(
		true,
		'手動一括更新ボタンでの enqueue 確認（Tools → Scheduled Actions に ' +
			'affilicard-* group の pending アクションが現れること）と、pause トグルの ' +
			'状態がリロード後も保持されることの確認は、Scheduled Actions 一覧画面の行特定・ ' +
			'管理 UI セレクタ調整が必要で壊れやすい。ロジックは PHPUnit/Jest で網羅済み ' +
			'（QueueControllerTest::testPause/testClearAll 等 / RefreshControllerTest / ' +
			'QueuePanel.test.jsx の pause トグル・保存テスト / PluginTest の Enqueuer wiring）。' +
			'対話的な最終確認は seed 済み Playground 上で実施する。'
	);

	test('手動一括更新ボタンをクリックすると Scheduled Actions に affilicard-* group のジョブが積まれる', async () => {
		// 1. /wp-admin/edit.php?post_type=affilicard_product&page=affilicard-settings を開く
		// 2. 一般パネルの「一括更新」ボタンをクリック
		//    （POST /affilicard/v1/refresh → Enqueuer::enqueueProductListings が
		//     ELIGIBLE listing を affilicard-{account} group で pending schedule する）
		// 3. /wp-admin/tools.php?page=action-scheduler&s=affilicard を開き、
		//    group=affilicard-* の pending 行が表示されることを確認する
	});

	test('キュー一時停止トグルの状態がリロード後も保持される', async () => {
		// 1. 更新キュー タブの「キューを一時停止する」トグルを ON にする
		//    （POST /affilicard/v1/refresh-queue/pause → GeneralSettings.queue_paused 永続化）
		// 2. page.reload() → 設定画面を開き直し「更新キュー」タブを再度開く
		// 3. トグルが ON のままであることを確認する
	});
});
