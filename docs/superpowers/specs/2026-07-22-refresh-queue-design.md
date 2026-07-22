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

## 3. アーキテクチャ

### 3-1. キュー機構の選択（★次セッションで最終決定すべき最重要オープン事項）

| 案 | 内容 | 長所 | 短所 |
| --- | --- | --- | --- |
| **案A: 自前の軽量キュー（推奨）** | 専用 DB テーブル＋WP-Cron チャンクドワーカー。 | 依存追加なし（standalone プラグイン方針に合致）・throttle/管理UI/優先度を完全に自前制御・要件5にフィット。 | 実装量が多い（テーブル/ワーカー/UI を作り込む）。 |
| **案B: Action Scheduler** | WooCommerce 系の実績あるバックグラウンドジョブ基盤を bundle。 | 実績・堅牢・リトライ/並行制御あり・**管理UI（Tools→Scheduled Actions）が付属**で要件5を一部充足。 | 重い依存を持ち込む・throttle/レート制限は結局自前・独自の管理UI要件（provider別throttle可視化等）には追加実装が必要。 |

**推奨は案A（自前・軽量）**：standalone プラグインで重依存を避けつつ、per-provider throttle・TTL優先度・独自キュー管理UIという要件を素直に満たせるため。ただし「堅牢性/実装コスト」を重視するなら案Bも合理的 → **次セッションの brainstorm で確定**。以下は案A前提で記述する。

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

1. **キュー機構**: 案A(自前) vs 案B(Action Scheduler)。（推奨=A、要確定）
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
- **P3 ワーカー＆配線**: チャンクド cron ワーカー＋`RefreshScheduler`/手動ボタン/publish昇格を **enqueue 化**＋coalesce/dedup＋unit。
- **P4 管理UI**: REST（stats/clear/retry/cancel・manage_options）＋React パネル＋unit＋E2E。
- **P5 バックプレッシャ**: TTL 優先度＋pile-up ガード（上限/置換/drop）＋深さメトリクス。
- **P6 リリース準備**: build/lint/全テスト/phpcs＋CHANGELOG＋**v2.4.0**（Version 3箇所同期）＋E2E＋運用ドキュメント（サーバ cron 推奨）。

## 7. ハンドオフ（次セッションの最初の一手）

1. `feature/v2.4.0-refresh-queue` を checkout（本 spec が commit 済み）。
2. §5 オープン事項を brainstorm で確定（特に §5-1 キュー機構）。
3. `writing-plans` で task-by-task の実装計画を作成 → `subagent-driven-development` で実装（v2.3.0 と同フロー）。TDD・PHP=Docker/JS=volta・CodeRabbit CLI・auto-merge しない・Playground 視覚確認・マージ後 v2.4.0 タグ/Release。
4. 参考: 本セッションの診断（burst→429）と実 API 再現手法（e-comi `.env` の RAKUTEN_* ＋ Origin=e-comi.pitolick.com ＋ node:https）。関連 memory: `reference_affilicard_no_auto_provider_diagnosis` / `project_affilicard_provider_toggle_role`。
