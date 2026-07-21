# 価格更新 UI 簡素化＋全PF一括更新（v2.3.0）Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development で task-by-task に実装。各 step は checkbox。

**Goal:** 価格自動更新を「全PF一括・単一グローバル間隔」にし、プラットフォーム編集の provider UI を手動/自動トグル1つに簡素化。ボタン feedback・商品一覧「最終更新」列・eligibleProvider backfill を追加。

**Architecture:** per-platform の `autoRefresh`/`refreshIntervalHours` を廃し、自動更新対象は `provider.isAutomatic()`、間隔は `GeneralSettings.refresh_interval_hours`（グローバル）。`RefreshScheduler` は単一 cron で `ListingRefresher::run()`。トグルの「自動」は platform の `eligibleProvider` を provider に設定する。

**Tech Stack:** PHP 8.2（PHPUnit/WP_Mock）、JS/JSX（wp-scripts）、WordPress。

## Global Constraints

- 設計: `docs/superpowers/specs/2026-07-21-refresh-ui-global-v2.3.0-design.md`。
- 自動更新対象 = `provider.isAutomatic()`（provider != 'manual'）。per-platform `autoRefresh` フラグは廃止。
- 更新間隔 = `GeneralSettings.refresh_interval_hours`（int・既定3・>=1）。per-platform 間隔は廃止。
- トグル「自動取得」ON = `provider` を platform の `eligibleProvider` に設定。OFF = `provider='manual'`。`eligibleProvider` 空の platform はトグル非表示（手動固定）。
- RefreshScheduler は単一グローバル cron（`affilicard_refresh_all`・引数なし）→ `ListingRefresher::run()`。旧 per-platform hook（`affilicard_refresh_platform`）は unschedule。
- テスト: PHP=Docker `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit`、JS=`npx wp-scripts test-unit-js`、lint=`npm run lint:js`（src のみ）。PHPCS=`vendor/bin/phpcs`（違反は `phpcbf`）。
- TDD（RED→GREEN）。日本語 Conventional Commits。push 前 CodeRabbit CLI。auto-merge しない（Playground 確認）。完了後 v2.3.0（MINOR）＋タグ＋Release（Version 3箇所同期: `affilicard.php` Version ヘッダ・`AFFILICARD_VERSION`・`package.json`）。

---

## Task 1: GeneralSettings に refresh_interval_hours を追加

**Files:** Modify `src/Settings/GeneralSettings.php` / Test `tests/Unit/Settings/GeneralSettingsTest.php`

- [ ] **Step 1: 失敗テスト** — `refresh_interval_hours` の既定が3、`update(['refresh_interval_hours'=>6])` で6保持、`<1` は3に矯正、非数値は3、を検証（既存 GeneralSettingsTest の方式に合わせる）。
- [ ] **Step 2: RED 確認** `--filter GeneralSettingsTest`。
- [ ] **Step 3: 実装** — `DEFAULTS` に `'refresh_interval_hours' => 3` 追加。`sanitize()` に「int 化・`<1` は 3」を追加。`schema_version` を 2 に bump（migration 用。既存の merge/sanitize と整合させる）。ヘルパ `refreshIntervalHours(): int` を追加してもよい。
- [ ] **Step 4: GREEN** 確認＋全体 phpunit。
- [ ] **Step 5: commit** `feat: GeneralSettings に全体更新間隔 refresh_interval_hours を追加`

---

## Task 2: RefreshScheduler を単一グローバル cron に（GeneralSettings 間隔 + provider.isAutomatic）

**Files:** Modify `src/Cron/RefreshScheduler.php`、`register()` 呼び出し元（Plugin 初期化）/ Test `tests/Unit/Cron/RefreshSchedulerTest.php`

> このタスクは per-platform フィールドを**読むのをやめる**だけ（Task 3 で PlatformDefinition から除去）。順序: Task 2 → Task 3。

**Interfaces:** グローバル hook 定数（例 `HOOK_ALL = 'affilicard_refresh_all'`）。`reconcile()` は master(cron_enabled) 時に `HOOK_ALL` を `GeneralSettings` 間隔由来のスケジュールで登録、非 master 時は解除。旧 per-platform hook（`affilicard_refresh_platform`）は常に unschedule。ハンドラは引数なしで `ListingRefresher::run()` を呼ぶ。

- [ ] **Step 1: 失敗テスト** — (a) `scheduleName(int)`（`affilicard_ivl_{h}h` を流用可）＋`addSchedules` が GeneralSettings 間隔で1本登録、(b) `reconcile()` が cron_enabled 時に `HOOK_ALL` を登録し旧 per-platform hook を unschedule、(c) cron_enabled=false で全解除、を検証（既存 RefreshSchedulerTest の WP_Mock 方式に合わせる。`GeneralSettings::get()` の get_option をスタブ）。
- [ ] **Step 2: RED** `--filter RefreshSchedulerTest`。
- [ ] **Step 3: 実装** — `reconcile()` を「PlatformConfig ループで per-platform schedule」から「GeneralSettings 間隔で単一 `HOOK_ALL` schedule ＋ 旧 per-platform hook を wp_unschedule_hook」へ書き換え。`register(callable $handler)` は `HOOK_ALL` に配線＋`cron_schedules` フィルタ登録。ハンドラは platform 引数を取らない（Plugin 側の配線も `ListingRefresher::run()` 直呼びに変更）。`clear()` は両 hook を解除。
- [ ] **Step 4: GREEN** ＋全体 phpunit（この時点で PlatformDefinition の autoRefresh/refreshIntervalHours はまだ存在し、seed も従来通りなのでコンパイルは通る）。
- [ ] **Step 5: commit** `feat: RefreshScheduler を全体間隔の単一グローバルcronに変更`

---

## Task 3: PlatformDefinition から autoRefresh・refreshIntervalHours を除去（＋seed）

**Files:** Modify `src/Platform/PlatformDefinition.php`、`src/Platform/PlatformConfig.php`（defaults）/ Tests 対応

> Task 2 完了後に実施（scheduler が両フィールドを読まなくなってから除去）。`new PlatformDefinition(` の全 call site（seed・tests）を新シグネチャに合わせる。

- [ ] **Step 1: 失敗テスト** — `toArray()` に `autoRefresh`/`refreshIntervalHours` が**含まれない**こと、旧データ（それらのキーを含む配列）を `fromArray` しても例外なく無視されること、`provider`/`eligibleProvider`/`priceTtlHours` は保持されること、を検証。
- [ ] **Step 2: RED** `--filter "PlatformDefinitionTest|PlatformConfigTest"`。
- [ ] **Step 3: 実装** — constructor から `$autoRefresh`・`$refreshIntervalHours` を削除（末尾の `imagePriority`/`eligibleProvider`/`priceTtlHours` は維持）。`toArray()` から両キー削除。`fromArray()` の両キー解決ロジック削除（旧キーは単に無視）。`PlatformConfig::defaults()` の 8 エントリを新シグネチャへ（`autoRefresh`/`refreshIntervalHours` 引数を除去。rakuten-kobo/dmm-books は `eligibleProvider` 維持、priceTtlHours=24 維持）。`CardRendererTest` 等で `new PlatformDefinition(` を使う箇所も更新。
- [ ] **Step 4: GREEN** ＋全体 phpunit（scheduler は既に GeneralSettings 間隔なので影響なし）。
- [ ] **Step 5: commit** `feat: PlatformDefinition から per-platform autoRefresh・間隔を除去`

---

## Task 4: eligibleProvider バックフィル migration

**Files:** Create/Modify migration 実行箇所（既存の schema_version/upgrade 機構に合わせる。無ければ `Plugin` の init で version 比較）/ Test

**Interfaces:** `rakuten-kobo`→`rakuten-kobo`、`dmm-books`→`dmm-ebook` を、対象 platform の `eligibleProvider` が空のときのみ設定。適用済みは schema_version（GeneralSettings か専用オプション）で二重適用防止。

- [ ] **Step 1: 失敗テスト** — 既存 platforms（eligibleProvider 空の rakuten-kobo/dmm-books を含む）を用意し、migration 実行後に各 eligibleProvider が補完されること、既に値がある場合は上書きしないこと、未知 code は変更しないこと。
- [ ] **Step 2: RED**。
- [ ] **Step 3: 実装** — migration 関数を追加し、`PlatformConfig::all()` を読み該当 code の `eligibleProvider` を補完して `PlatformConfig::save()`。適用フラグ（schema_version bump）で1回のみ。プラグイン更新契機（既存機構）に配線。BookWalker 等は対象外（マップに無い）。
- [ ] **Step 4: GREEN** ＋全体。
- [ ] **Step 5: commit** `feat: 既存installのeligibleProviderをバックフィルするmigrationを追加`

---

## Task 5: PlatformEditor を手動/自動トグルに簡素化

**Files:** Modify `src/Admin/components/PlatformEditor.jsx` / Test `tests/js/components/PlatformEditor.test.jsx`

**Interfaces:** provider ドロップダウン＋「API自動更新」トグル＋per-platform 間隔 SelectControl を撤去。代わりに、`platform.eligibleProvider` が非空のとき ToggleControl「自動取得（<provider label>）」を出す（provider label は `window.affilicardProviders` から引く）。ON→`update({provider: platform.eligibleProvider})`、OFF→`update({provider:'manual'})`。checked=`platform.provider !== 'manual'`。`eligibleProvider` 空なら「このプラットフォームは手動入力です」の静的表示のみ。「今すぐこのプラットフォームを更新」ボタンは残す（feedback は Task 7）。

- [ ] **Step 1: 失敗テスト** — eligibleProvider ありで toggle が出て provider を manual⇄eligible に切替える／eligibleProvider 無しで toggle が出ない、を検証（既存 JSX テスト方式・providers モック）。
- [ ] **Step 2: RED** `npx wp-scripts test-unit-js PlatformEditor`。
- [ ] **Step 3: 実装** — 上記 UI へ置換。providerLabel ヘルパ（code→label）を providers.js に足すか PlatformEditor 内で解決。
- [ ] **Step 4: GREEN** ＋`npx wp-scripts test-unit-js`＋`npm run lint:js`。
- [ ] **Step 5: commit** `feat: プラットフォーム編集を手動/自動トグルに簡素化`

---

## Task 6: GeneralPanel に全体更新間隔＋ボタン feedback

**Files:** Modify `src/Admin/components/GeneralPanel.jsx`、必要なら `src/Admin/api/refresh.js` / Test `tests/js/Admin/GeneralPanel.test.jsx`（無ければ最小新規）

**Interfaces:** cron_enabled トグルの下に「更新間隔（時間毎）」SelectControl（1/3/6/12/24・既定3・`update({refresh_interval_hours})`）。一括/強制一括ボタンは `triggerRefresh()` を await し、実行中 disabled＋ラベル「更新中…」、完了/失敗を `@wordpress/components` の通知（createNotice or Snackbar・既存パターン）で表示。REST の戻り（更新件数）を利用可。

- [ ] **Step 1: 失敗テスト** — 間隔 SelectControl が出て 3 を表示・変更で `refresh_interval_hours` を更新、一括ボタン click で triggerRefresh が呼ばれ実行中表示になる、を検証（triggerRefresh をモック）。
- [ ] **Step 2: RED**。
- [ ] **Step 3: 実装** — 上記。`refreshIntervalOptions` を PlatformEditor から共有 util に切り出して再利用可。
- [ ] **Step 4: GREEN** ＋全体 test-unit-js＋lint:js。
- [ ] **Step 5: commit** `feat: General設定に全体更新間隔とボタンfeedbackを追加`

---

## Task 7: per-platform「今すぐ更新」ボタンに feedback

**Files:** Modify `src/Admin/components/PlatformEditor.jsx` / Test 追記

**Interfaces:** 「今すぐこのプラットフォームを更新」を await 化・実行中 disabled＋「更新中…」・完了/失敗通知。Task 6 の通知パターンを共有。

- [ ] **Step 1: 失敗テスト** — ボタン click で triggerRefresh(platform.code) が呼ばれ実行中表示になる。
- [ ] **Step 2: RED**。
- [ ] **Step 3: 実装**。
- [ ] **Step 4: GREEN** ＋lint:js。
- [ ] **Step 5: commit** `feat: プラットフォーム個別更新ボタンにfeedbackを追加`

---

## Task 8: 商品一覧に「最終更新」列

**Files:** Modify `src/PostType/ProductListColumns.php` / Test `tests/Unit/PostType/ProductListColumnsTest.php`

**Interfaces:** 新カラム `affilicard_last_verified`（見出し「最終更新」）。各商品の listing 群から最新 `last_verified_at` を `wp_date('Y-m-d H:i')` で表示。1件も無ければ「—」。既存の Fallback/価格非表示警告カラムはそのまま。

- [ ] **Step 1: 失敗テスト** — listing に `last_verified_at` を持つ商品でカラムに日時が出る／無い商品で「—」。
- [ ] **Step 2: RED** `--filter ProductListColumnsTest`。
- [ ] **Step 3: 実装** — `addColumn` に列追加、`renderColumn` に最新 last_verified_at 抽出＋`wp_date` 表示。`strtotime` で最大を取る。
- [ ] **Step 4: GREEN** ＋全体。
- [ ] **Step 5: commit** `feat: 商品一覧に最終更新列を追加`

---

## Task 9: build・lint・全テスト・CHANGELOG・v2.3.0

**Files:** `CHANGELOG.md`、`affilicard.php`（Version ヘッダ・AFFILICARD_VERSION）、`package.json`、`tests/e2e/`（トグル自動化→カード価格/更新日時、商品一覧列の E2E 追加/更新）

- [ ] **Step 1** 全ゲート: `npx wp-scripts build`／`npm run lint:js`／`npx wp-scripts test-unit-js`／Docker phpunit＋phpcs。
- [ ] **Step 2** E2E seed/spec を新モデルに合わせて更新（provider トグル・グローバル間隔・最終更新列）。
- [ ] **Step 3** CHANGELOG `## [2.3.0]`＋Version 3箇所を **2.2.0 → 2.3.0** に同期（PUC）。
- [ ] **Step 4** commit `chore: v2.3.0（価格更新UI簡素化＋全PF一括更新）`。
- [ ] **Step 5** CodeRabbit CLI → 対応 → push → PR（auto-merge しない・Playground 確認）。マージ後 v2.3.0 タグ＋Release。

---

## ロールアウト（マージ・リリース後の運用作業）

- **e-comi WP を v2.3.0 に更新**（PUC）。
- **e-comi WP のプラットフォーム設定を v2.3.0 デフォルトに一括リセット**（`affilicard_platforms` を defaults で上書き＋`affilicard_general` の cron/interval をデフォルトへ）。**認証情報 `affilicard_accounts` と登録商品 CPT は保持**。BookWalker 除去を包含。REST or 管理操作で実施（正確な手段はロールアウト時に確定）。
- 楽天Kobo をトグルで自動取得 ON → 認証情報設定 → 一括更新で価格反映を確認。

---

## Self-Review

- Spec カバレッジ: S1→T5、S2→T1/T2/T3、S3→T6/T7、S4→T8、S5→T4、リリース→T9、リセット→ロールアウト。✓
- 依存順の注意: **T2（scheduler が per-platform フィールドを読むのをやめる）→ T3（フィールド除去）** の順を厳守。T1 は T2 の前（scheduler が GeneralSettings 間隔を読むため）。
- 型整合: `refresh_interval_hours`（GeneralSettings）、`provider`/`eligibleProvider`（PlatformDefinition・トグル）、`HOOK_ALL`（RefreshScheduler）を各 task で一貫使用。
