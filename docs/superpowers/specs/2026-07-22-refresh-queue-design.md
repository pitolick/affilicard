# 価格更新の非同期キュー化＋レート制限耐性＋キュー管理UI（v2.4.0）設計

> **ステータス**: 設計（ハンドオフ用）。実装は別セッションに切り出す。現行 v2.3.0 → 本機能で **v2.4.0（MINOR・後方互換）**。
> **前提となった実インシデント（2026-07-22）**: 楽天Kobo の価格更新で、一括更新が3件のAPI呼び出しを同一秒に一斉発火 → 楽天 openapi の約 **1 req/sec/app** 制限に当たり毎回1件が **429** で失敗 → その listing の `last_verified_at` が更新されず TTL(24h) 超過で価格が非表示（赤 Fallback）。「同時2件までは通る・3件目が弾かれる」を実測で確認。診断は memory `reference_affilicard_no_auto_provider_diagnosis` 参照。

## 1. 問題

現行の価格更新（`src/Cron/ListingRefresher.php` `run()`）は **同期・直列・レート制御なし**：

- `run()` は全公開商品を走査し、各 eligible listing に対し `provider->fetch()`（実 HTTP）を**タイトループで即時発火**する。
- 楽天のような **per-provider レート制限**（openapi ≈ 1 req/sec/app）に対する throttle も 429 リトライも無い → バーストで一部が 429 → 価格が更新されない。
- **商品数が青天井**になると、同期実行は (a) レート制限で多数失敗し、(b) N 商品で N 秒級の実行時間となり **PHP/ゲートウェイのタイムアウト**にも当たる。手動一括更新ボタン（REST 同期）で顕著。
- これは楽天固有ではなく、**API を持つ全 Provider 共通**の構造問題。

## 2. 確定要件（ユーザー合意済み）

1. **非同期・バックグラウンド処理**：手動一括更新・cron は「更新ジョブをキューに投入」するだけ。ワーカーがリクエストをブロックせず順次処理する。手動ボタンは即返し。
2. **per-provider レート制限耐性**：Provider ごとの最小リクエスト間隔（throttle）＋ 429 のバックオフ再試行（`Retry-After` 尊重・指数バックオフ・上限到達で failed）。
3. **青天井スケール**：タイムアウトしない。キューは時間をかけて drain する。
4. **キューの無限スタック防止**：同一 listing の重複ジョブを**coalesce（重複排除）**。更新が TTL に追いつかず新ジョブが積み上がる事態を防ぐ（**TTL 認識の優先度付け**・上限/古いジョブの置換・drop）。
5. **管理画面からキュー管理**：pending/running/failed の可視化、**全削除・failed のみ削除・再試行・キャンセル**。
6. **全プラットフォーム対象**。
7. **鮮度スキップ（最重要の削減レバー・調査で判明）**：`last_verified_at` が TTL 内の listing は**再取得しない**（Amazon PA-API 対策の「24h キャッシュ」相当）。stale/期限間近のみ更新対象にする。現行 `run()` は鮮度に関わらず全件 fetch しており、これが API 呼び出しとキュー圧の主因。**キュー生成量そのものを激減**させる → pile-up 防止に直結。
8. **時間分散（jitter）**：更新を間隔内に分散し一斉バーストを避ける（PA-API 対策の「spread across the day」）。
9. **throttle を設定可能に**：provider 別（or 全体）のリクエストレートを設定値で持つ（Amazon Affiliates 系プラグインの「Request Rate」設定に相当）。
10. **（provider 対応時）multi-item バッチ**：API が複数商品同時取得に対応する場合はまとめて 1 リクエストにする（Amazon GetItems ≤10 ASIN 等）。楽天Kobo 検索は keyword 単位のため対象外＝provider 別。

## 3. アーキテクチャ

### 3-1. キュー機構の選択（★次セッションで最終決定すべき最重要オープン事項）

| 案 | 内容 | 長所 | 短所 |
| --- | --- | --- | --- |
| **案A: 自前の軽量キュー（推奨）** | 専用 DB テーブル＋WP-Cron チャンクドワーカー。 | 依存追加なし（standalone プラグイン方針に合致）・throttle/管理UI/優先度を完全に自前制御・要件5にフィット。 | 実装量が多い（テーブル/ワーカー/UI を作り込む）。 |
| **案B: Action Scheduler** | WooCommerce 系の実績あるバックグラウンドジョブ基盤を bundle。 | 実績・堅牢・リトライ/並行制御あり・**管理UI（Tools→Scheduled Actions）が付属**で要件5を一部充足。 | 重い依存を持ち込む・throttle/レート制限は結局自前・独自の管理UI要件（provider別throttle可視化等）には追加実装が必要。 |

**調査を踏まえた lean の更新**：当初は案A（自前）を推奨していたが、§8 の調査で **Action Scheduler（案B）が「非同期ジョブの難所」＝チャンクドワーカー・時間予算・claim/ロック・並行制御・失敗クリーンアップ・管理UI(Scheduled Actions) を実績込みで標準提供**すると判明。**案A はこれらを一から再実装する**ことになる（バグの出やすい箇所）。一方、**AS は outbound API のレート制限をしない**ため、`RateLimiter`（per-provider throttle＋429backoff）と鮮度スキップは**どちらの案でも自前実装**が必要。

→ **調査後の lean は案B（Action Scheduler を土台にし、その上に RateLimiter＋鮮度スキップ＋薄い管理サマリUI を載せる）** に傾く。トレードオフは「重依存の持ち込み（bundle）」のみ。standalone 方針との兼ね合いで **最終決定は次セッションの brainstorm** とする（案A/案B のどちらでも下記コンポーネントの責務は不変。案B なら「キューストア/ワーカー」を AS に委譲し、自前は Enqueuer/RateLimiter/鮮度スキップ/管理UI に集中）。以下は責務ベースで記述する。

### 3-2. コンポーネント（案A）

1. **RefreshQueue（ストア）** — 専用 DB テーブル `{$wpdb->prefix}affilicard_refresh_queue`。1 ジョブ＝1 listing（`post_id` × `platform`）。カラム: `id, post_id, platform, provider, status(pending|running|done|failed), attempts, priority, enqueued_at, next_attempt_at, started_at, finished_at, last_error, dedup_key(UNIQUE)`。option ではなくテーブル（青天井を索引付きで扱う・管理UIの集計/一覧に必要）。
2. **Enqueuer** — 現行の同期 `run()` を置換。手動ボタン・cron・`transition_post_status`(publish昇格) が**ジョブを投入**。**coalesce**: 同一 `dedup_key`(=post_id:platform) の pending/running が在れば投入しない（重複スタック防止）。TTL 認識の `priority`（期限が近い/切れた listing を優先）。
3. **Worker（チャンクド cron）** — 短間隔の WP-Cron tick（例: 毎分）で、**時間予算内**で pending バッチを claim → 処理 → 反映。**per-provider throttle**（≤ provider の rate）と 429 バックオフを適用。処理途中で時間予算を超えたら次 tick へ（idempotent/resumable）。単一プロセスでの多重実行防止（claim を `status=running` の原子的更新で）。
4. **RateLimiter / Throttle** — provider ごとの最小間隔・トークンバケット等。429 は `Retry-After`/指数バックオフで `next_attempt_at` を後ろ倒し、`attempts` 上限で `failed`。
5. **ProviderInterface 拡張** — 各 Provider が `rateLimitPerSecond()`（や min interval）を宣言 → throttle が provider 別に効く。
6. **キュー管理 UI** — 設定画面に「更新キュー」パネル/タブ。表示: provider 別 pending/running/failed 件数・キュー深さ・直近エラー。操作: 全削除／failed 削除／failed 再試行／pending キャンセル。REST（`/affilicard/v1/refresh-queue` GET＋操作・**manage_options**）。
7. **バックプレッシャ / pile-up ガード** — dedup に加え、キュー深さの上限 or 「drain 速度 < 生成速度」検知。古い重複/期限外ジョブの置換・drop。深さメトリクスを UI に出す。

### 3-3. データフロー

```text
手動ボタン / global cron / publish昇格
        │  (enqueue, coalesce by dedup_key, TTL優先度)
        ▼
   RefreshQueue (DB table)
        │  Worker tick (毎分・時間予算・provider別throttle・429backoff)
        ▼
   provider->fetch()  ──失敗──▶ backoff/attempts++ (上限で failed)
        │ 成功
        ▼
   listing 反映 (last_verified_at=gmdate('c') 等) → job=done
        ▲
   管理UI (REST) が read / clear / retry / cancel
```

### 3-4. UX

- 手動一括更新ボタン: 同期結果ではなく「**更新をキューに投入しました（対象 N 件）**」を通知（v2.3.0 のボタン feedback を踏襲しつつ非同期化）。
- 個別「今すぐ更新」: 1 商品分を投入（1〜数リクエスト）。即時同期でも可だが throttle 経由が一貫。
- キュー管理パネル: 深さ・provider 別内訳・failed・各操作。

## 4. 後方互換 / 移行

- `ListingRefresher::run()`（同期・全件）は **enqueue 方式へ置換/非推奨化**。`RefreshScheduler`（グローバル cron `affilicard_refresh_all`）は run 直呼びから **enqueue 呼び出し**へ。
- `refreshProduct()`（単一商品・publish 昇格）は 1〜数リクエストなので enqueue 経由 or throttle 付き直呼び。
- v2.3.0 の GeneralSettings 間隔・PriceFreshness・列などは不変。**MINOR（v2.4.0）・後方互換**。DB テーブルは有効化/アップグレードで作成（migration）。

## 5. オープン事項（次セッションの brainstorm で確定）

1. **キュー機構**: 案A(自前) vs 案B(Action Scheduler)。（§8 調査後の lean=**B**：AS が難所を肩代わり・throttle は両案とも自前。standalone 方針での重依存許容可否を確定）
2. **ジョブ粒度**: per-listing vs per-(product×provider)。
3. **throttle 値**: 楽天(≈1/sec)・DMM 等を Provider 宣言で。実測/公式レート確認。
4. **ワーカー方式/頻度**: WP-Cron 毎分＋バッチ N 件／時間予算 vs 自己再スケジュールのループ。バッチサイズ。
5. **管理UIの深さ**: MVP(件数＋全削除/failed再試行) → 後で per-job 明細/フィルタ。
6. **pile-up ポリシー**: dedup のみ か、深さ上限＋TTL優先＋古い drop まで。
7. **失敗の可視化**: 既存の商品一覧「価格非表示」赤アイコン／DashboardWidget との連携。
8. **WP-Cron 信頼性**: 実トラフィック依存。青天井運用では **サーバ実 cron（`DISABLE_WP_CRON`＋OS cron）** 推奨をドキュメント化。

## 6. 実装フェーズ（概略・次セッションで task 化）

- **P1 キューストア**: DB テーブル＋migration＋`RefreshQueue` API（enqueue/claim/complete/fail/stats）＋unit。
- **P2 レート制御**: `RateLimiter`（provider別 throttle＋429 backoff）＋`ProviderInterface::rateLimit*` 宣言＋unit。
- **P3 ワーカー＆配線**: ワーカー（案B なら AS ハンドラ／案A なら チャンクド cron）＋`RefreshScheduler`/手動ボタン/publish昇格を **enqueue 化**＋coalesce/dedup＋**鮮度スキップ（TTL 内は enqueue しない・要件7）**＋**jitter（要件8）**＋unit。※鮮度スキップは最大の削減レバーなので P3 で必ず入れる。
- **P4 管理UI**: REST（stats/clear/retry/cancel・manage_options）＋React パネル（provider 別 pending/running/failed・深さ・throttle 設定）＋unit＋E2E。
- **P5 バックプレッシャ**: TTL 優先度＋pile-up ガード（上限/置換/drop）＋**reconcile パス（未処理/failed 定期回収・§8-3）**＋深さメトリクス。
- **P6 リリース準備**: build/lint/全テスト/phpcs＋CHANGELOG＋**v2.4.0**（Version 3箇所同期）＋E2E＋運用ドキュメント（サーバ cron 推奨）。

## 7. ハンドオフ（次セッションの最初の一手）

1. `feature/v2.4.0-refresh-queue` を checkout（本 spec が commit 済み）。
2. §5 オープン事項を brainstorm で確定（特に §5-1 キュー機構）。
3. `writing-plans` で task-by-task の実装計画を作成 → `subagent-driven-development` で実装（v2.3.0 と同フロー）。TDD・PHP=Docker/JS=volta・CodeRabbit CLI・auto-merge しない・Playground 視覚確認・マージ後 v2.4.0 タグ/Release。
4. 参考: 本セッションの診断（burst→429）と実 API 再現手法（e-comi `.env` の RAKUTEN_* ＋ Origin=e-comi.pitolick.com ＋ node:https）。関連 memory: `reference_affilicard_no_auto_provider_diagnosis` / `project_affilicard_provider_toggle_role`。

## 8. 類似プラグイン・OSS 調査（設計への反映・2026-07-22）

同種課題（レート制限のある商品API＝Amazon PA-API/Creators・楽天）を扱う WP プラグインと WP 標準のバックグラウンド処理を調査した。

### 8-1. Action Scheduler（WooCommerce・WP標準のジョブキュー）
- プラグイン配布向けに設計された**実績あるジョブキュー**（サーバ権限不要）。ライブで **5万件超**のキューを 10 並行・>10,000 actions/hour で処理した実績。
- 標準装備: **per-request 時間予算**（既定 30s・`action_scheduler_queue_runner_time_limit`）／**バッチサイズ**（既定 25・`..._batch_size`）／**並行**（既定 1・`..._concurrent_batches`・上げると負荷大の警告）／**メモリ 90% で停止・次3件が時間予算超なら停止**／**失敗アクション保持**（`action_scheduler_retention_period_for_failed`）／**WP-Cron loopback or WP-CLI ランナー**（大規模は WP-CLI 推奨）／**管理UI**（Tools→Scheduled Actions）。
- ★重要: **AS は outbound API のレート制限をしない**。throttle は利用側が実装する必要がある。
- **示唆**: キュー/ワーカー/時間予算/失敗クリーンアップ/管理UI という「難所」を AS が肩代わりする。案A（自前）はこれを再実装することになる。→ §3-1 の lean を案Bへ更新。

### 8-2. Amazon PA-API 429 対策のベストプラクティス（楽天≈1req/sec と同型）
- **~1 req/sec に throttle し、429 が出たら一時停止**（新規アカウントは日次上限 ≈8,640req/day）。
- **24h キャッシュで再取得を回避**（= 我々の `PriceFreshness` TTL/`last_verified_at` を使った**鮮度スキップ**に相当）。**最大の削減レバー**。
- **更新を時間分散**（一斉実行しない）。
- **multi-item バッチ**（Amazon GetItems ≤10 ASIN/req）。楽天Kobo 検索は keyword 単位で非対応＝provider 別。
- **トラフィック/売上のある投稿を優先**、古いものは後回し。

### 8-3. 実プラグインの運用パターン（WZone/AA-Team・Amazon Affiliates 等）
- **毎分 cron でバッチ処理（例: 99件/run）＋別途 15分ごとの reconcile cron で未同期を回収**（= バウンドバッチ＋照合パス）。
- **「Request Rate」設定 UI** でユーザーが API 速度を調整（= 要件9 の throttle 設定）。

### 8-4. 設計への具体反映
- **鮮度スキップ**（要件7）を第一級に：`last_verified_at` が TTL 内なら enqueue しない。キュー生成量＝pile-up の主因を断つ。
- **キュー機構は案B（Action Scheduler）を土台に**する lean（§3-1）。ただし RateLimiter・鮮度スキップ・薄い管理サマリUI は自前。案Bなら DB テーブル/ワーカー/失敗保持は AS 委譲。
- **reconcile パス**（未処理/failed を定期回収）を追加（§8-3）。
- **throttle は設定値**（provider 別・要件9）。
- **時間分散（jitter）**を enqueue に（要件8）。

### 8-5. 参考ソース
- Action Scheduler perf: <https://actionscheduler.org/perf/> / <https://github.com/woocommerce/action-scheduler>
- Amazon PA-API 429 対策: <https://www.keywordrush.com/blog/fix-amazon-paapi-too-many-requests/> / <https://webservices.amazon.com/paapi5/documentation/troubleshooting/api-rates.html>
- 実プラグイン: WZone/AA-Team（cron/min＋batch99＋reconcile）、Amazon Affiliates（Request Rate 設定）
- Rinker/ポチップ: 自動更新は ON/OFF 可（PA-API 制限回避に手動 off を許容）＝過剰 fetch を避ける思想は鮮度スキップと同じ
