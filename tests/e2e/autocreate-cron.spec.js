/**
 * E2E spec: Phase 4a-3 — auto-create / 価格更新 Cron / 手動トリガー / 予約投稿昇格
 *
 * 方針:
 * - 「予約投稿（future）商品はフロント非表示」は seed 済みデータで決定的に検証する（CI-safe）。
 * - 「future→publish 昇格でカード表示」「auto-create」「手動更新ボタン」は
 *   外部 DMM API 依存・wp-cli 昇格手順・管理 UI セレクタ調整が必要なため、
 *   ロジックは PHPUnit / Jest で網羅済み（ProductAutoCreator / ListingRefresher /
 *   RefreshScheduler / RefreshController / Plugin::onTransitionPostStatus / settings.test.jsx）。
 *   対話的な最終確認は Phase 4a 終了時に seed 済み Playground プレビュー上で実施する。
 *   （既存 product-metabox.spec.js と同じく、未確定分は理由付きで test.skip する慣習に倣う）
 *
 * 追記（v2.4.0 更新キュー非同期化）: AutoCreate・手動更新ボタン・future→publish 昇格時の
 * 反映は、いずれも v2.4.0 で同期実行から Action Scheduler 経由の非同期 enqueue に変更された
 * （AutoCreateHandler / RefreshHandler / Plugin::onTransitionPostStatus 内の Enqueuer 呼び出し）。
 * ProductAutoCreator・ListingRefresher 自体の判定ロジックは変わらず上記ハンドラから呼ばれ
 * 続けるため、本ファイルの skip 対象・skip 理由・実行中テスト（future 非表示）は引き続き有効。
 * enqueue 投入と Scheduled Actions への反映は tests/e2e/refresh-queue.spec.js（Task 17）で
 * 別途カバーする。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );

const seed = JSON.parse( fs.readFileSync( 'artifacts/seed.json', 'utf8' ) );

test( '予約投稿（future）商品のブロックはフロントでカードを描画しない', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ seed.futurePostId }` );
	// 商品 CPT が future ステータスのため Block::render は publish 以外を空描画する。
	await expect( page.locator( '.affilicard-card' ) ).toHaveCount( 0 );
} );

test.describe( 'future→publish 昇格 / auto-create / 手動更新（対話確認・4a 終了時に実施）', () => {
	test.skip(
		true,
		'昇格時 refresh・auto-create・手動更新ボタンは PHPUnit/Jest で網羅済み。' +
			'wp-cli 昇格手順・外部 API・管理 UI セレクタは seed 済み Playground で対話確認する。'
	);

	test( 'future 商品を publish へ昇格するとフロントにカードが表示される', async () => {
		// 1. npx wp-env run tests-cli wp post update <futureProductId> --post_status=publish
		//    （wp_update_post が transition_post_status future→publish を発火し
		//     Plugin::onTransitionPostStatus が manual listing を no-op で通過）
		// 2. page.goto(`/?p=${seed.futurePostId}`)
		// 3. expect('.affilicard-card').toBeVisible()
	} );

	test( '設定画面に「一括更新」「強制一括更新」「今すぐこのプラットフォームを更新」ボタンが表示される', async () => {
		// /wp-admin/edit.php?post_type=affilicard_product&page=affilicard-settings を開き
		// General パネルの 2 ボタン / Platforms パネルの platform 別ボタンの可視を確認する。
	} );
} );
