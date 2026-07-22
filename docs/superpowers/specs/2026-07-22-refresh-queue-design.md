# 価格更新の非同期キュー化＋レート制限耐性＋キュー管理UI（v2.4.0）設計

> **ステータス**: 設計確定（2026-07-22 brainstorm 済み）。**キュー機構は Action Scheduler（案B）を土台**とし、その上に RateLimiter＋鮮度スキップ＋トリガー配線＋薄い集計UIを載せる。トリガーは**競合実証済みの「イベント（公開/更新）＋ cron 掃引（鮮度スキップ）」モデル**を採用（閲覧駆動は不採用＝§10-1）。現行 v2.3.0 → 本機能で **v2.4.0（MINOR・後方互換）**。次は `writing-plans` で task 化 → `subagent-driven-development` で実装。
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
4. **キューの無限スタック防止**：同一 listing の重複ジョブを**coalesce（重複排除）**。深さ上限バックストップ。
5. **管理画面からキュー管理**：pending/running/failed の可視化、**全削除・failed のみ削除・再試行・キャンセル**、per-job 明細。
6. **全プラットフォーム対象**。
7. **鮮度スキップ（最重要の削減レバー）**：`last_verified_at` が TTL 内の listing は**再取得しない**。stale/期限間近のみ更新対象にする。**キュー生成量そのものを激減**させる。
8. **時間分散（jitter）**：更新を間隔内に分散し一斉バーストを避ける。
9. **throttle を設定可能に**：provider 別のリクエストレートを設定値で持つ。
10. **（provider 対応時）multi-item バッチ**：API が複数商品同時取得に対応する場合はまとめて 1 リクエストにする（Amazon GetItems ≤10 ASIN 等）。楽天Kobo 検索は keyword 単位のため対象外＝provider 別。**後回し最適化**（Amazon は API 未解禁のため保留）。
11. **リフレッシュ・トリガー（§8）**：①公開/更新時（記事内商品を force 事前同期・再掲対応）②future→publish ③スケジュール掃引（鮮度スキップ適用の継続更新＝主役）＋reconcile。すべて同一キュー（AS）へ。**閲覧駆動は不採用**（§10-1）。

## 3. アーキテクチャ（案B：Action Scheduler を土台）

### 3-0. 確定した設計判断（brainstorm 2026-07-22）

| # | 論点 | 確定 |
| --- | --- | --- |
| 1 | キュー機構 | **案B（Action Scheduler を bundle・土台）**。当初 lean は案A（自前）だったが、再検討で **memory 90% 停止・時間予算先読み・claim/ロック・single-flight・resumability・失敗保持・管理UI(Scheduled Actions)・WP-CLI ランナー** という「非同期ジョブの難所」を AS が実績込みで提供し、案A はこれを再実装（車輪の再発明・バグ温床）と判明したため **案B採用**。キュー量の細流化はスケール需要を下げるが、claim/timeout/memory 等の**正しさ需要**は量に依らず必要。トレードオフは AS を bundle する重依存の一点（AS は複数プラグイン同梱を想定し最新版を自動選択）。**outbound レート制限は AS も持たないので自前**。 |
| 2 | ジョブ粒度 | **per-listing（post_id×platform）**。AS アクション hook=`affilicard_refresh_listing`・args=`{post_id, platform}`（＝dedup 同一性）・**group=`affilicard-{provider}`**（provider 別＝Scheduled Actions で provider 別フィルタが標準で効く・§3-2#5）。`force` は enqueue 時の判断でありスケジュール引数に含めない（dedup を安定させるため）。 |
| 3 | 重複排除 | **AS ネイティブ `$unique=true`**（`as_schedule_single_action` の引数）で原子的に重複防止。自前 `as_has_scheduled_action` check-then-schedule（race あり）は使わない。 |
| 4 | 優先度 | **AS ネイティブ `$priority` 引数**（0–255・小さいほど先）。force=0／手動一括=10／cron 掃引=20。時刻オフセットでのエミュレーションはしない。 |
| 5 | ワーカー | **AS ランナー**（WP-Cron loopback or `wp action-scheduler run`）。時間予算・バッチ・memory ガード・claim・single-flight は AS 標準。**pause フラグ**でハンドラを即 return（§10-2）。 |
| 6 | throttle | **`ProviderInterface::minRequestIntervalMs()`（provider 下限）＋ 管理画面で provider 別上書き**。実効 = `max(provider下限, 管理値)`。**AS ランナーを塞がない再スケジュール方式**（§3-2#3）。 |
| 7 | 公開/更新トリガー | `parse_blocks()` で解決 → **解決商品の auto listing 全部を force 投入（即時・priority 0）**。autoCreate なし。 |
| 8 | 管理UI | **AS 再利用を最大化**（§3-2#5）。group=`affilicard-{provider}` により **per-job 明細・status/provider フィルタ・個別 Cancel/Run は AS の Tools→Scheduled Actions が標準提供**。自前は **設定セクション（provider 別 throttle／pause／retention）＋集計サマリ（AS API で件数取得）＋一括操作（全削除／failed 一括再試行）＋Scheduled Actions へのリンク** に縮小（フル自前テーブルは作らない）。 |
| 9 | 失敗可視化 | 既存 Fallback 列に **キュー状態（キュー待ち／失敗理由）を連携**（tooltip＋キューパネル/Scheduled Actions リンク）も今回。 |
| 10 | 運用 | **サーバ実 cron 推奨ドキュメント ＋ AS 付属の `wp action-scheduler run`**（自前 WP-CLI ワーカーは作らず AS 標準を流用）。 |
| 11 | ログ保持 | done=完了後まもなく／failed=既定保持。**AS の retention（`action_scheduler_retention_period`／`..._for_failed`）をフィルタで設定**。GeneralSettings から調整。 |
| 12 | トリガー | **イベント（公開/更新・future→publish）＋ cron 掃引（鮮度スキップ）** の競合実証モデル。**閲覧駆動は不採用**（§10-1）。編集画面プレビューも投入しない（§3-5）。 |
| 13 | AutoCreate | 既存 `ProductAutoCreator`（未登録ブロックのフロント描画時に**同期API**で商品生成）を **AS 非同期化**＝別アクション `affilicard_autocreate` に enqueue し、ハンドラで RateLimiter 経由生成（§3-6）。同期API を描画から除去しレート制限を一元化。 |

### 3-1. 優先度（AS ネイティブ `$priority`・小さいほど先に処理）

| priority | トリガー | スケジュール | 意味 |
| --- | --- | --- | --- |
| **0** | force 系（公開/更新・future→publish・個別「今すぐ更新」） | 即時 | go-live/掲載で確実にフレッシュ。鮮度スキップ無視。 |
| **10** | 手動一括更新 | 即時 | 管理者の明示更新。鮮度スキップ無視。 |
| **20** | スケジュール掃引・reconcile | 定期（jitter 付） | 継続更新の主役＋保険。鮮度スキップ適用。 |

AS は同時刻に due のアクションを `$priority` 昇順で claim するため、force が掃引より先に処理される。

### 3-2. コンポーネント

1. **Enqueuer（AS ラッパ）** — 現行の同期 `run()` を置換。全トリガーが本 API 経由で投入。
   - スケジュール: `as_schedule_single_action( $when, 'affilicard_refresh_listing', array( 'post_id'=>.., 'platform'=>.. ), 'affilicard-{provider}', $unique = true, $priority )`（group は provider 別＝§3-2#5 の Scheduled Actions フィルタ用）。
   - **重複排除**: `$unique=true` で原子的に「同一 hook+group+args の pending/running が在れば投入しない」。
   - **force の優先度確定**: force 投入時は既存の pending 掃引アクション（priority 20）を `as_unschedule_all_actions( 'affilicard_refresh_listing', array( 'post_id'=>.., 'platform'=>.. ), 'affilicard-{provider}' )` で解除してから priority 0 で再投入（force が確実に先行）。※重複しても実行時に鮮度スキップで noop になるため、この unschedule は最適化（必須ではない）。
   - **鮮度スキップ（要件7）**: force 以外は `PriceFreshness` で TTL 内なら投入しない。
   - **深さ上限バックストップ（要件4）**: AS の pending 件数が上限（設定可能・既定 500）超過時は掃引起点の投入だけ skip して log。force は常に通す。
   - **jitter（要件8）**: 掃引は `$when` に 0〜N 秒のランダムオフセット。
2. **Refresh ハンドラ** — `add_action( 'affilicard_refresh_listing', ... )`。AS ランナーが呼ぶ。
   - **pause フラグ**が立っていれば即 return（スケジュールは残し、復旧後に処理・§10-2）。
   - **RateLimiter で throttle 判定**（§3-2#3）。
   - `provider->fetch()` → listing 反映（`last_verified_at=gmdate('c')` 等）。
   - **429/失敗**: `Retry-After`/指数バックオフで `as_schedule_single_action( next_attempt, ... )` に再投入（attempts はアクション args or listing 側カウンタで管理・上限で打ち切り＝failed）。AS のリトライ非自動・失敗保持・memory・time ガードはランナー標準を利用。
3. **RateLimiter / Throttle（AS を塞がない再スケジュール式）** — provider ごとの**直近呼び出し時刻を option/transient に原子的に記録**（クロスプロセス）。ハンドラ冒頭で `tryAcquire(provider)`：
   - 経過が min-interval 未満なら **fetch せず** `as_schedule_single_action( last_call + interval, same_action, priority )` で自分を後ろ倒しして return（**ハンドラ内で sleep しない**＝AS ランナーをブロックしない）。
   - 経過済みなら last_call を now に原子更新（`add_option` ロック or `GET_LOCK` or `UPDATE ... WHERE last_call<?`）してから fetch。
   - これにより AS がバッチで複数アクションを走らせても、**provider 単位のレートはプロセス跨ぎで厳守**される（single-flight は AS の claim が担保）。
   - 実効間隔 = `max(provider下限 minRequestIntervalMs(), 管理画面のprovider別設定)`。
4. **ProviderInterface 拡張** — `minRequestIntervalMs(): int` を追加。manual=0／楽天=1100（1/sec＋余裕）／DMM=要確認（暫定 1000・公式/実測で確定）。
5. **キュー管理 UI（AS 再利用最大化・自前は最小）** — 設定画面に「更新キュー」パネル。
   - **per-job 明細・status/provider フィルタ・個別 Cancel/Run・検索は AS の Tools→Scheduled Actions が標準提供**（group=`affilicard-{provider}` で provider 別に絞れる）。自前でフルテーブルは作らない。
   - 自前は最小：**設定セクション**（provider 別 throttle 上書き・**pause トグル（§10-2）**・retention 保持期間）＋**集計サマリ**（`as_get_scheduled_actions( array( 'group'=>'affilicard-*', 'status'=>.. ), 'ids' )` の件数で provider 別 pending/failed・深さ）＋**一括操作**（全削除＝`as_unschedule_all_actions`／failed 一括再試行＝再 enqueue）＋**Scheduled Actions へのリンク**。
   - REST（`/affilicard/v1/refresh-queue` GET＋操作・**manage_options**）。
6. **Sweep / Reconcile（既存グローバル cron `affilicard_refresh_all`・`refreshIntervalHours` 間隔）＝継続更新の主役** — 鮮度スキップ適用の軽量全件走査で stale を enqueue（priority 20・jitter）＋「スケジュール漏れ/失敗の reconcile 回収」（AS の `action_scheduler_ensure_recurring_actions` も活用可）。**cron は本 hook のみ**（ワーカーは AS ランナー・自前 tick は作らない）。低流入商品は鮮度スキップで大半が noop。ログ保持 purge は AS retention に委譲。
7. **失敗可視化連携** — 既存 `ProductListColumns` の Fallback 列 tooltip に「キュー待ち／失敗理由」を連携し、キューパネル/Scheduled Actions へリンク。

### 3-3. データフロー

```text
公開更新(parse_blocks force) / future→publish(force) / 手動ボタン / 掃引cron(鮮度スキップ)
        │  Enqueuer.enqueue → as_schedule_single_action($when, 'affilicard_refresh_listing', {post_id,platform}, 'affilicard-{provider}', unique=true, priority)
        │  (dedup: unique=true / 鮮度スキップ[force除く] / 深さ上限 / jitter[掃引] / priority=0(force)|10(手動)|20(掃引))
        ▼
   Action Scheduler ストア（claim・single-flight・時間予算・memoryガード・失敗保持・Scheduled Actions UI・WP-CLI は AS 標準）
        │  AS ランナー(WP-Cron loopback or `wp action-scheduler run`) → affilicard_refresh_listing ハンドラ
        ▼
   [pause?→return] → RateLimiter.tryAcquire(provider)
        ├─未経過→ as_schedule_single_action(last_call+interval, same, priority) で後ろ倒し return（sleepしない）
        └─経過→ last_call=now 原子更新 → provider->fetch()
                     ├─失敗/429→ Retry-After/backoff で as_schedule_single_action(next_attempt) 再投入（attempts上限でfailed）
                     └─成功→ listing 反映(last_verified_at=gmdate('c')) → 完了
        ▲
   管理UI(REST) が 集計/clear/retry/cancel ／ Scheduled Actions が per-job 明細 ／ 掃引cron が stale enqueue+reconcile
```

### 3-4. UX

- 手動一括更新ボタン: 「**更新をキューに投入しました（対象 N 件）**」を通知（非同期化）。
- 個別「今すぐ更新」: 1 商品分を force 投入（即時・priority 0）。
- キュー管理パネル: 深さ・provider 別内訳・failed・各操作・throttle/保持/pause 設定・Scheduled Actions へのリンク。

### 3-5. 描画経路（価格更新のキュー投入はしない＝毎閲覧 DB 書き込みを避ける）

閲覧駆動を不採用としたため（§10-1）、**描画時に「価格更新」のキュー投入は行わない**。これは「閲覧数ランキング系プラグインが毎閲覧 DB 書き込みで高負荷になる」問題を避ける明示的な設計判断。鮮度は cron 掃引（主役）と公開/更新トリガー（イベント）で担保する。

| 経路 | 実体 | 価格更新のキュー投入 | AutoCreate 投入 |
| --- | --- | --- | --- |
| **フロント（公開）** | `Block::render()` → CardHtmlBuilder → CardRenderer（純粋レンダラ） | しない | **未登録ブロックのみ 1 回 enqueue**（§3-6・transient ガード） |
| **編集画面プレビュー** | edit.jsx → `GET /products/{id}/card-preview` → CardPreviewController | しない | しない |

`CardRenderer` の純粋レンダラ契約（副作用なし・[src/Renderer/CardRenderer.php](../../../src/Renderer/CardRenderer.php)）は維持される（enqueue は呼び出し元 `Block::render()` 側）。AutoCreate の投入は**未登録商品の初回参照時のみ・商品ごとに一度きり**で、閲覧駆動 refresh のような毎閲覧書き込みにはならない（§3-6・§9-9）。

### 3-6. AutoCreate の AS 非同期化（既存機能の改善）

既存 `ProductAutoCreator` は [Block::render()](../../../src/Block/Block.php#L128) から呼ばれ、未登録の `externalId+platform` ブロックがフロント描画されると**その場で同期 `provider->fetch()`（実HTTP）**を叩いて商品を1件生成していた（[ProductAutoCreator.php:35](../../../src/AutoCreate/ProductAutoCreator.php#L35)・5分 transient ロックのみでガード）。これは (a) 描画をブロックする同期API、(b) 今回根治するレート制限問題と同型、(c) RateLimiter/キューを通らない唯一残る同期API経路。

- **改善**: `Block::render()` は未登録ブロックに対し **`as_schedule_single_action( now, 'affilicard_autocreate', array( 'platform'=>.., 'external_id'=>.. ), 'affilicard-{provider}', $unique=true, priority 0 )`** を投入するだけにし、**ハンドラで RateLimiter 経由生成**。`$unique=true` で重複防止（従来の transient ロックは「AS ストアを毎描画 read しない」ための安価な短絡ガードとして残す）。
- **UX トレードオフ**: 初回描画ではカード未表示（商品未生成）→ 生成完了後の次回描画から表示（**商品ごとに一度きり**）。低頻度イベントのため許容。
- 生成後は既存商品として resolveProduct が即ヒット＝以降は同期処理も enqueue も発生しない。

## 4. 後方互換 / 移行

- `ListingRefresher::run()`（同期・全件）は **enqueue 方式へ置換**。`RefreshScheduler`（グローバル cron `affilicard_refresh_all`）は run 直呼びから **掃引 enqueue＋reconcile** へ（AS ランナーがワーカー）。
- `refreshProduct()`（単一商品・publish 昇格）は enqueue 経由（即時 force）へ。
- `ProductAutoCreator`（フロント描画時の同期生成）は **AS 非同期化**（`affilicard_autocreate` アクション・RateLimiter 経由・§3-6）。`Block::resolveProduct()` は未登録時に inline 生成せず enqueue に置換。
- **AS の bundle/ロード**: `composer require woocommerce/action-scheduler`。プラグイン起動時に AS の `functions.php` を require（vendor 有無で分岐する既存 α 案の autoload と整合。memory `project_affilicard_vendor_optional` 参照）。
- v2.3.0 の GeneralSettings 間隔・PriceFreshness・列などは不変。**MINOR（v2.4.0）・後方互換**。

## 5. 実装フェーズ（概略・次セッションで task 化）

- **P1 AS 統合＋Enqueuer**: AS bundle/ロード（vendor 分岐整合）＋`Enqueuer`（`as_schedule_single_action` の `$unique`/`$priority` による投入・force 時 unschedule＋再投入・鮮度スキップ・深さ上限・jitter）＋unit。
- **P2 レート制御**: `RateLimiter`（provider別クロスプロセス throttle＋**AS を塞がない再スケジュール式**＋429 backoff）＋`ProviderInterface::minRequestIntervalMs()` 宣言（manual/楽天/DMM）＋unit。
- **P3 ハンドラ＆トリガー配線**: `affilicard_refresh_listing` ハンドラ（pause ゲート＋RateLimiter＋fetch＋backoff 再投入）＋**`affilicard_autocreate` ハンドラ（AutoCreate 非同期化・§3-6・RateLimiter 経由）**。**enqueue 化したトリガー**：手動ボタン／**掃引 cron（stale enqueue・主役）**／**公開・更新時（`parse_blocks` で記事内商品を force 投入・§8-1）**／future→publish（§8-2）／**未登録ブロックの AutoCreate 投入（`Block::render`・transient ガード）**。**鮮度スキップ**＋**jitter**＋unit。
- **P4 管理UI（薄型）**: REST（stats/clear/clearFailed/retryFailed/cancelPending/pause・manage_options）＋React パネル（集計・provider別 throttle 設定・保持期間・pause トグル・**Scheduled Actions へのリンク**）＋unit＋E2E。
- **P5 バックプレッシャ＆保守**: 深さ上限バックストップ＋掃引 cron（stale enqueue＋reconcile 回収）＋AS retention 設定＋失敗可視化連携（Fallback 列）＋深さメトリクス＋unit。
- **P6 運用＆リリース**: **AS 付属 `wp action-scheduler run`** の運用ドキュメント（サーバ実 cron 推奨）＋build/lint/全テスト/phpcs＋CHANGELOG＋**v2.4.0**（Version 3箇所同期）＋E2E。

## 6. ハンドオフ（実装セッション）

1. `feature/v2.4.0-refresh-queue` を checkout。
2. 本 spec（確定版）を `writing-plans` で task-by-task の実装計画へ → `subagent-driven-development` で実装（v2.3.0 と同フロー）。TDD・PHP=Docker/JS=volta・CodeRabbit CLI・auto-merge しない・Playground 視覚確認・マージ後 v2.4.0 タグ/Release。
3. 実 API 再現手法（e-comi `.env` の RAKUTEN_* ＋ Origin=e-comi.pitolick.com ＋ node:https）。関連 memory: `reference_affilicard_no_auto_provider_diagnosis` / `project_affilicard_provider_toggle_role` / `project_rakuten_openapi_origin_header` / `project_affilicard_vendor_optional`。

## 7. 類似プラグイン・OSS 調査（設計への反映・2026-07-22）

同種課題（レート制限のある商品API＝Amazon PA-API/Creators・楽天）を扱う WP プラグインと WP 標準のバックグラウンド処理を調査した。

### 7-1. Action Scheduler（WooCommerce・WP標準のジョブキュー）＝本設計の土台

- プラグイン配布向けに設計された**実績あるジョブキュー**。ライブで **5万件超**のキューを 10 並行・>10,000 actions/hour で処理した実績。per-request 時間予算・バッチサイズ・並行・**メモリ 90% 停止・次3件が時間予算超なら停止**・失敗保持・**管理UI（Tools→Scheduled Actions）**・**`wp action-scheduler run` WP-CLI** を標準装備。
- **ネイティブ機能で自前を削減**：`as_schedule_single_action( $ts, $hook, $args, $group, $unique=true, $priority )` の **`$unique`（原子的重複防止）** と **`$priority`（0–255）** を活用し、自前の dedup チェック・優先度エミュレーションを排除。
- ★重要: **AS は outbound API のレート制限をしない**。throttle は利用側が実装（＝本設計の RateLimiter・§3-2#3）。単発アクションの**自動リトライも無い**ため、失敗時 backoff 再投入は自前（AS 公式の定石パターン）。
- **採用理由**: claim/timeout/memory/single-flight/resumability/失敗保持/管理UI/CLI という「非同期ジョブの難所」を実績込みで肩代わり。自前（案A）は再実装＝車輪の再発明。→ **AS を土台に採用**（§3-0#1）。

### 7-2. 主要 WP アフィリエイトプラグインの更新方式（Rinker/Pochipp/WZone 等）

- **すべて cron ベースのスケジュール更新**。描画とは無関係にバックグラウンドで価格/セール情報を更新するため、**ページキャッシュ/CDN の影響を受けない**（Pochipp 有料版「自動セール情報管理」も cron 駆動）。**閲覧駆動（描画時 DB 書き込み）はどれも採らない**（→ 本設計も §10-1 で不採用）。
- WZone/AA-Team は「毎分 cron＋バッチ（例 99件/run）＋別 cron の reconcile 回収」＋「Request Rate 設定 UI」。
- **示唆**: 本設計の「cron 掃引（鮮度スキップ）＋イベント（公開/更新）トリガー」は競合の定番と一致し、**独自価値は per-provider レート制限・鮮度スキップ・AS 委譲による堅牢性**に集中する。

### 7-3. Amazon PA-API 429 対策のベストプラクティス（楽天≈1req/sec と同型）

- **~1 req/sec に throttle し、429 が出たら一時停止**（新規は日次上限 ≈8,640req/day）。
- **24h キャッシュで再取得を回避**（= 我々の `PriceFreshness` TTL/`last_verified_at` を使った**鮮度スキップ**）。**最大の削減レバー**。
- **更新を時間分散**（jitter）。**multi-item バッチ**（GetItems ≤10 ASIN）。**トラフィック/売上のある投稿を優先**（本設計では cron 掃引＋イベントトリガーで代替）。

### 7-4. 参考ソース

- Action Scheduler: <https://actionscheduler.org/> / API <https://actionscheduler.org/api/> / perf <https://actionscheduler.org/perf/> / <https://github.com/woocommerce/action-scheduler>
- AS unique actions: <https://github.com/woocommerce/action-scheduler/pull/831>
- Amazon PA-API 429 対策: <https://www.keywordrush.com/blog/fix-amazon-paapi-too-many-requests/> / <https://webservices.amazon.com/paapi5/documentation/troubleshooting/api-rates.html>
- Pochipp（cron 駆動の自動セール情報管理）: <https://pochipp.com/>

## 8. リフレッシュ・トリガー戦略（イベント＋ cron 掃引・すべて同一キューへ）

競合実証済みの **「cron 掃引（継続更新の主役）＋掲載イベント（公開/更新・force）」** モデル。すべて **同一キュー（AS）**（dedup＋per-provider throttle＋鮮度ポリシー）へ。**閲覧駆動は不採用**（§10-1）。

| トリガー | 役割 | 鮮度スキップ | priority | 同期/非同期 |
| --- | --- | --- | --- | --- |
| **公開/更新時**（§8-1） | 掲載前の事前同期・**再掲対応** | **無視＝force** | 0 | 非同期・即時 |
| **future→publish**（§8-2） | 予約セール記事の go-live 同期 | force 相当 | 0 | 非同期・即時 |
| **スケジュール掃引/reconcile**（§8-3） | **継続更新の主役**＋保険・回収 | 適用 | 20 | 非同期・定期 |
| **手動一括/個別**（§3-4） | 管理者の明示更新 | 無視 | 10/0 | 非同期・即時 |

### 8-1. 公開/更新時トリガー（記事内の商品を事前同期）

- フック: `transition_post_status`（draft→publish・future→publish を捕捉）＋公開済み記事の再保存（`post_updated`／publish 状態の `save_post`）。autosave/revision/auto-draft はスキップ（既存ガードに倣う）。
- その投稿本文を `parse_blocks()` して `affilicard/product-card` ブロックの参照商品を解決（`productId`→find／`slug`→findBySlug／`externalId`+`platform`→findByExternalId、**autoCreate はしない**）→ **解決商品の auto listing 全部**を **force 投入（即時・priority 0・鮮度スキップ無視）**。hide/only は表示制御なので投入対象から除外しない（データの鮮度と表示は別概念）。
- **狙い**：過去登録商品をセール等で再掲した瞬間に掲載前同期。
- **e-comi 固有補足**：e-comi 投稿パイプラインは楽天を投稿時取得し `last_verified_at` を刻む（#124）ため e-comi 自身の投稿は公開時点で概ねフレッシュ。本トリガーは（a）WP 管理画面での手編集/再掲、（b）パイプライン非取得の Provider、（c）時間経過で stale 化した再掲、を拾う保険＋汎用化（他サイト配布時にも有効）。

### 8-2. future→publish 遷移（既存の配線を流用）

- 現行の `transition_post_status`（予約投稿の publish 昇格時 refresh）を、**即時 force 投入**に置き換えて統合。

### 8-3. スケジュール掃引（継続更新の主役・reconcile floor）

- 既存グローバル cron（`affilicard_refresh_all`・`refreshIntervalHours` 間隔）を、**鮮度スキップ適用の軽量掃引（stale を enqueue・priority 20・jitter）＋スケジュール漏れ/失敗の reconcile 回収**とする（§3-2#6・cron はこの hook のみ／ワーカーは AS ランナー）。**これが継続更新の主役**（競合と同じ cron ベース）。低流入商品は鮮度スキップで大半が noop。
- WP-Cron は擬似 cron（リクエスト駆動）。青天井・確実運用では**サーバ実 cron（`DISABLE_WP_CRON`＋OS cron）＋ AS 付属の `wp action-scheduler run`**（WP-CLI ランナー）を推奨（§3-0#10）。

## 9. セキュリティ・堅牢性（脆弱性レビュー・重点）

閲覧駆動を不採用としたため**「価格更新」の未認証投入パスは無い**（価格更新の投入は認証済み管理操作・cron・publish イベントのみ）。唯一の未認証投入は **AutoCreate（未登録ブロックのフロント描画時・§3-6）** だが、これは訪問者入力ではなく**公開記事のブロック内容に由来**し、`$unique=true`＋transient で商品ごと一度きりに束ねられるため**増幅・DoS 面にはならない**（§9-9）。キューストア自体は AS が管理（prepared クエリ・自前の生 SQL データパスは持ち込まない）。以下を実装の必須要件とする。

### 9-1. REST 認可・CSRF

- refresh-queue の**全エンドポイント（GET 集計を含む）を `manage_options`**。既存 `CredentialsController`／`RefreshController` の `permission_callback => canManageOptions` パターンを踏襲。
- **破壊操作（clear／clearFailed／retryFailed／cancelPending／設定変更／pause）は GET ではなく POST/DELETE**。
- WP REST の cookie 認証は core が `X-WP-Nonce`（`wp_rest`）を `rest_cookie_check_errors` で強制。React パネルは `wp.apiFetch`（nonce middleware）で送る＝**CSRF は WP 標準機構で担保**。独自 nonce は設けない。

### 9-2. SQL インジェクション / データパス

- **キュー永続化は AS API 経由**（`as_schedule_single_action`／`as_unschedule_all_actions`／`as_get_scheduled_actions`）で行い、**自前の生 `$wpdb` クエリを AS テーブルに書かない**（AS 内部は prepared）。
- 自前 `$wpdb` は RateLimiter の last-call 記録程度に限定し、`$wpdb->prepare()`／option API 経由。listing 反映は既存 Repository（サニタイズ済み）を通す。

### 9-3. ストアド XSS（provider エラー文字列）

- provider の API エラーメッセージを **管理 UI（集計の直近エラー）と Fallback 列 tooltip に表示**する経路は **stored XSS** 面。
- **二重防御**：保存/受け渡し時に `wp_strip_all_tags()` ＋長さ切り詰め（例 500 字）。出力時も React はデフォルトエスケープ／PHP 側 tooltip は `esc_attr()`／`esc_html()`。`dangerouslySetInnerHTML` は使わない。

### 9-4. 競合・原子性

- **dedup は AS ネイティブ `$unique=true`**（原子的）に委譲。二重処理防止は AS ランナーの claim/single-flight が担保（自前ロック不要）。
- **RateLimiter の last-call 更新は原子的に**（`add_option` ロック or `GET_LOCK` or `UPDATE ... WHERE last_call<?`）行い、並行ハンドラが同一 provider で同時 fetch しないようにする。

### 9-5. 429 / Retry-After の悪用・暴走耐性

- `Retry-After` を鵜呑みにせず**上限クランプ**（例 max 1h）。負値/非数は無視し指数バックオフにフォールバック。
- `attempts` 上限（例 5）到達で **failed 確定＝無限リトライ防止**。

### 9-6. SSRF / 外部 URL

- provider fetch は**固定 API エンドポイント（楽天/DMM）＋ external_id パラメータ**で行い、listing 供給の任意 URL は fetch しない（`regular_url`/`search_key` は API クエリ値であって取得先ではない）。商品登録は信頼された編集者のみ＝SSRF 面は限定的。

### 9-7. bundle / アンインストールの安全性

- AS は複数プラグイン同梱を想定し**最新版を自動選択**するため、他プラグイン（WooCommerce 等）が同梱していても衝突しない。ロードは AS 標準手順（`functions.php` require＋`ActionScheduler::init`）。
- Uninstall では自前オプション（throttle/pause/retention 設定）を削除。AS テーブルは AS 所有のため drop しない（他プラグインが共有し得る）。自プラグインのスケジュール済みは **provider 別 group（`affilicard-{provider}`）ごとに** `as_unschedule_all_actions( '', array(), 'affilicard-{provider}' )` で解除（既知 provider を列挙）。

### 9-8. WP-CLI

- ランナーは **AS 付属の `wp action-scheduler run`**（サーバ管理者コンテキスト＝追加認可不要）。自前 CLI は作らない。

### 9-9. AutoCreate 未認証投入パスの増幅・DoS 耐性

- `Block::render()` の AutoCreate 投入（§3-6）は**未認証訪問者コンテキスト**で AS にスケジュール（DB 書き込み）する唯一の経路。増幅/DoS の懸念に対し：
  - **投入対象は訪問者入力ではなく公開記事のブロック内容に由来**（`externalId`/`platform` は公開済み投稿の属性）。訪問者は投入内容を制御できない＝任意 external_id を注入できない。
  - **商品ごと一度きり**：`$unique=true`（同一 platform+external_id の pending 1件）＋ per-(platform,external_id) 短命 transient で、大量描画・連打でも投入は新規商品分だけに束ねられる。生成後は既存商品として即ヒットし以降投入なし。
  - **深さ上限バックストップ**（§3-2#1）が最終上限。
- 従って閲覧駆動 refresh（全 stale listing を毎閲覧で投入）のような増幅は起きない。

## 10. 類似プラグインとの過不足チェック（over/under-engineering）

§7 の調査（Action Scheduler／Rinker／Pochipp／WZone・Amazon 系）と本設計を突き合わせ、機能の過不足を評価した。**案B 採用＋閲覧駆動不採用で、堅牢性は AS 標準で充足し、トリガーは競合と同型に収束**。

| 観点 | 類似の定番 | 本設計（案B） | 判定 |
| --- | --- | --- | --- |
| バックグラウンド更新 | cron バッチ（WZone 毎分99件） | AS ランナー（時間予算/バッチ/memory 標準） | 過不足なし |
| memory 90% 停止・時間予算先読み | AS 標準 | **AS 標準（委譲）** | 充足 |
| claim/ロック・single-flight | AS 標準 | **AS 標準（委譲）** | 充足 |
| 重複排除・優先度 | AS 標準 | **AS `$unique`/`$priority`（委譲）** | 充足（自前実装を排除） |
| レート制限 throttle | Request Rate 設定（Amazon系） | 自前 RateLimiter（再スケジュール式）＋管理上書き | 過不足なし（AS 非提供を補完） |
| 鮮度キャッシュ | 24h キャッシュ（PA-API） | 鮮度スキップ（既存 PriceFreshness TTL） | 過不足なし（既存資産活用） |
| 継続更新トリガー | cron 掃引 | **cron 掃引（主役）** | 競合と同型 |
| reconcile 回収 | 別 cron の未同期回収（WZone） | 掃引 cron の reconcile（AS ensure hook 活用可） | 過不足なし |
| 失敗保持・クリーンアップ・WP-CLI | AS 標準 | **AS retention／`wp action-scheduler run`（委譲）** | 充足 |
| 管理UI | AS=Scheduled Actions／Rinker=一覧 | **AS Scheduled Actions（per-job/フィルタ/Cancel/Run）＋自前は設定＋サマリ＋一括操作のみ** | 過不足なし（自前フルUI回避） |
| 同期API のフロント混入 | 通常 cron のみ（描画で API 叩かない） | **AutoCreate も AS 非同期化（§3-6）** | 改善（既存の同期API を除去） |
| 閲覧駆動更新 | **無し**（毎閲覧DB書込を避ける） | **不採用（§10-1）** | 競合と同型（過剰回避） |
| multi-item バッチ | GetItems ≤10（Amazon） | 後回し（楽天非対応・Amazon 未解禁） | 妥当（YAGNI・保留明記） |
| 一時停止（pause） | 一部プラグイン/AS 運用で可 | 自前 pause フラグ（§10-2） | 追加採用 |
| 失敗通知メール | 一部プラグインで有 | 既存 DashboardWidget＋Fallback 列で代替 | 過剰回避で不採用 |

### 10-1. 閲覧駆動リフレッシュを不採用とした理由

当初は「閲覧時（stale-while-revalidate）に stale なら enqueue」する閲覧駆動を主トリガー案としていたが、以下から**不採用**：

- **競合（Rinker/Pochipp/WZone）は誰も採用していない**。定番は cron＋鮮度スキップで、これで実運用上十分。
- **毎閲覧の DB 書き込み負荷**：閲覧数ランキング系プラグインが「表示のたびに DB 書き込み」で高負荷になり敬遠される事例がある（ユーザー指摘）。同型のコストを価格更新に持ち込むのは割に合わない。
- **未認証書き込みパス**というセキュリティ面と cooldown/dedup の複雑さを追加する。
- 得られる差別化（人気カードの自己優先更新）は、cron 掃引＋鮮度スキップ＋掲載イベント force で十分代替でき、**限界効用が小さい**。

→ トリガーは「cron 掃引（主役）＋公開/更新イベント（force）」に単純化。将来どうしても必要なら別 PR で再検討可能（設計上は Enqueuer に投入点を足すだけ）。

### 10-2. ワーカー一時停止トグル（pause）

障害時・API 一時 BAN 時にワーカー処理を止める手段。AS には group 単位の一時停止が無いため、**自前 pause フラグをハンドラ冒頭で判定**して即 return（スケジュールは残し復旧後に処理）。既存 `GeneralSettings::isCronEnabled()`（cron マスタ）とは別概念だが UI 上は隣接配置。**低コストで fail-safe 価値が高い**ため MVP に含める（P3 ハンドラ＋P4 UI）。

### 10-3. 意図的に採らない（YAGNI）

- **自前キューストア/ワーカー/memory・time ガード/claim/CLI/dedup/優先度/per-job フルUI**：AS へ委譲（案B の趣旨）。
- **閲覧駆動更新**：§10-1。**失敗通知メール**：既存 DashboardWidget＋Fallback 列＋キューパネルで可視化済み。

### 10-4. 結論

案B 採用＋閲覧駆動不採用により、**堅牢性は AS 標準で充足**、**トリガーは競合と同型（cron＋イベント）**に収束し、自前は **outbound レート制限（AS 非提供）＋鮮度スキップ＋トリガー配線＋薄い集計UI＋pause** という**独自価値のみ**に集中する。車輪の再発明は排除され、設計は妥当かつリーンな範囲に収まっている。
