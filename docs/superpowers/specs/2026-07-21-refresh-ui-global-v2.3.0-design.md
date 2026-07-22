# 価格更新 UI 簡素化＋全PF一括更新（v2.3.0）設計

> v2.2.0（楽天Kobo refresh 再設計＋価格鮮度表示）を本番投入して顕在化した運用課題を解消する。
> **① provider=手動入力なのに「API自動更新」ON にできる矛盾 UI を撤廃**（手動/自動のトグル1つに）。
> **② 価格自動更新を「全プラットフォーム一括・単一グローバル間隔」に**（カード下部の単一更新日時が整合）。
> **③ 更新ボタンに feedback**（実行中/完了/失敗）。**④ 商品一覧に「最終更新」列**。**⑤ 既存 install の eligibleProvider 漏れ修正**。
>
> **確定日: 2026-07-21**（本番 UI レビューでのユーザー指摘に基づく・会話で design 合意）

---

## 1. 背景（v2.2.0 本番投入で判明した課題）

- **矛盾 UI**: プラットフォーム編集の「API連携（自動取得）」に Provider ドロップダウン＋「API自動更新」トグル＋per-platform 更新間隔が並ぶ。`provider=手動入力` でも「API自動更新」を ON にでき、意味を成さない。
- **既存 install の eligibleProvider 漏れ**: v2.2.0 の seed 変更は新規 install のみに作用。既存 install（本番 e-comi WP）の `rakuten-kobo` platform は `eligibleProvider=''` のままなので、Provider ドロップダウンが `manual＋現在値` しか出さず、**一度 manual にすると `rakuten-kobo` に戻せない＝楽天の自動更新を有効化できない**。
- **per-platform 分散スケジュール**: `RefreshScheduler` が auto platform ごとに独立 cron を登録。プラットフォーム間で更新時刻がズレるが、カード下部の更新日時（`renderTimestamp`）は**表示中 listing の最新 `last_verified_at` を1つだけ**出すため、実態と乖離する。
- **ボタン無反応**: per-platform「今すぐこのプラットフォームを更新」・General の「一括更新/強制一括更新」は `triggerRefresh()` の Promise を await せず feedback が無い。
- **最終更新の不可視**: 商品一覧に更新日時列が無く、実際にいつ更新されたか確認できない。
- **プラットフォーム削除 UI が無い**: 既存 install に残る不要 platform（BookWalker 等）を管理画面から消せない。

## 2. スコープ（v2.3.0・MINOR）

### スコープ内

**S1. per-platform provider を「手動/自動トグル1つ」に**
- 各プラットフォームは API 提供元が1つに定まる（rakuten-kobo→楽天Kobo API、dmm-books→DMM API）。Provider ドロップダウンを廃し、**「手動入力 ⇄ 自動取得（<API provider 名>）」のトグル1つ**にする。
  - OFF=手動入力（`provider='manual'`）／ON=自動取得（`provider` = そのプラットフォームの `eligibleProvider`）。
  - `eligibleProvider` が空（API provider を持たない: VOD・Amazon〈API未解禁〉・BookWalker 等）の platform は**トグルを出さず手動固定**（説明文のみ）。
- **per-platform「API自動更新」トグルと更新間隔 UI を撤去**。
- 「今すぐこのプラットフォームを更新」ボタンは残す（S3 で feedback 付与）。

**S2. 自動更新を全PF一括・単一グローバル間隔に**
- `GeneralSettings` に **`refresh_interval_hours`（int・既定3）** を追加。更新間隔はここ1箇所で管理。
- `RefreshScheduler` を **単一グローバル cron イベント**に変更（`affilicard_refresh_all` 等・引数なし）。ハンドラは `ListingRefresher::run()`（全公開商品・全 listing）。cron master（`cron_enabled`）ON かつ interval に従って全 auto platform を**同時**に refresh → 全 `last_verified_at` がほぼ同時刻 → カードの単一更新日時が整合。
- per-platform スケジュール（`affilicard_refresh_platform`）は廃止（既存スケジュールは unschedule）。
- **自動更新対象の判定は `provider.isAutomatic()`**（provider != manual）。per-platform `autoRefresh` フラグは廃止（provider から導出）。

**S3. 更新ボタンの feedback**
- per-platform・一括・強制一括の各ボタンで `triggerRefresh()` を await し、**実行中（disabled＋ラベル「更新中…」）→ 完了/失敗の Notice**（`@wordpress/components` の Snackbar/Notice か既存パターン）を表示。REST は更新件数を返しているので「N件更新しました」を出せる。

**S4. 商品一覧に「最終更新」列**
- CPT 一覧（`ProductListColumns`）に列を追加。各商品の listing 群の**最新 `last_verified_at`**を `wp_date` で表示（無ければ「—」）。実際の更新タイミングを可視化。

**S5b. ProductAutoCreator が last_verified_at を刻む（issue #87）**
- `ProductAutoCreator::buildProductData` は成功 fetch から listing を組むが `last_verified_at` を刻まないため、Gutenberg ブロック自動作成の商品はカードで価格が非表示のまま（`ListingRefresher` と非対称）。fetch 成功時に `gmdate('c')`（UTC）で刻む。

**S5. eligibleProvider バックフィル migration（汎用・非破壊）**
- プラグイン更新時の upgrade routine（`admin_init`）で、`eligibleProvider` が空の platform を一度きり補完する。適用順:
  1. **既知 code → API provider マップ**を空時のみ適用（`rakuten-kobo`→`rakuten-kobo`、`dmm-books`→`dmm-ebook`）。DMM は現状 `provider='manual'` だが、API 解禁時にトグルで自動化できるよう `eligibleProvider` を用意する。
  2. **汎用則**: マップ未収載の code でも `provider !== 'manual'` かつ `eligibleProvider` が空なら `eligibleProvider = provider` を補完する。これは「自動判定は `provider.isAutomatic()`（provider != manual）だが、トグル表示は `eligibleProvider` 非空を条件とする」二つの基準が食い違い、UI 上は手動固定に見えるのに cron だけ自動 refresh される不整合（CodeRabbit 指摘）を閉じるため。
- したがって `amazon-kindle`・VOD は provider が manual のままなら空を維持するが、既存データで provider が自動系に設定されている platform は eligibleProvider が補完される（UI/cron を一致させる非破壊補正）。既に値がある platform は上書きしない。
- 二重適用防止は **専用オプション `affilicard_eligible_provider_backfilled`** のフラグで行う（`purgeLegacyProviderCredentials` と同じ一度きりパターン）。GeneralSettings の schema_version とは独立。再実行時の no-op はユニットテストで固定する。

### スコープ外／別対応

- **e-comi WP のプラットフォーム設定を v2.3.0 デフォルトに一括リセット**（1回きりの運用作業・プラグインコード変更なし）:
  - `affilicard_platforms` を v2.3.0 の `PlatformConfig::defaults()` で上書き（provider 選択・自動更新・cron/間隔・色/ラベル/表示順などを既定へ）。`affilicard_general` の cron/interval も既定へ。
  - **非破壊手順で実施する**（`affilicard_platforms` の上書きは platform 固有の手編集値を失わせ得るため。CodeRabbit 指摘）:
    1. **export（バックアップ）**: 実行前に現行の `affilicard_platforms`・`affilicard_general` を REST（`--env-file` の本番資格情報でローカルから）で取得し JSON 保存する。
    2. **dry-run（差分確認）**: 上書き予定の defaults と現行を差分表示し、失われる platform 固有値（手編集の色/ラベル/表示順/provider 等）が許容範囲かを目視確認する。
    3. **適用**: 確認後にリセットを実行する。
    4. **実行後検証**: 設定画面と実カード描画（`content.rendered` の `do_blocks`）で楽天Kobo の自動取得・価格・単一更新日時を確認する。
    5. **rollback**: 問題があれば手順1の JSON を書き戻して原状復帰する。
  - **BookWalker 除去はこのリセットに包含**される（defaults に BookWalker が無いため）。
  - **保持するもの**: 認証情報 `affilicard_accounts`（AccountCredentials）と登録済み商品 CPT `affilicard_product`。これらは別オプション/別ポストタイプなのでリセット対象外。
  - 汎用プラグインに platform 固有の削除やリセットを焼き込むのは不可（他 install を壊す）。あくまで未公開の e-comi WP への一度きりのデータ操作とする。実施タイミングは v2.3.0 を e-comi WP に反映した後。
- **プラットフォーム削除／リセットの UI 新設**: 今回は見送り（上記運用で対応）。将来必要なら別 spec。
- **AmazonProvider / DMM 実配線**: API 未解禁のため対象外。`amazon-kindle`・`dmm-books` は API 解禁時にトグルで自動化可能になる素地だけ用意。

## 3. データモデル変更

**PlatformDefinition**
- `provider`（'manual' or API provider code）・`eligibleProvider`（そのPFの API provider・トグルの「自動」が設定する値）を軸にする。
- `autoRefresh`（bool）・`refreshIntervalHours`（int）は**廃止**（provider から導出／グローバルへ移動）。`toArray` から除去、`fromArray` は旧キーを無視（後方互換・stored 側は次回保存で消える）。既存データは害なし。

**GeneralSettings（`affilicard_general`）**
- `refresh_interval_hours`（int・既定3・>=1）を追加。`schema_version` bump（migration 用）。

**listing メタ**: 変更なし（`last_verified_at` は v2.2.0 で追加・sanitizeListings 済）。

## 4. UI 仕様（プラットフォーム編集・API連携セクション）

```text
API 連携（自動取得）
┌─────────────────────────────┐
│ ◉ 手動入力   ○ 自動取得（楽天Kobo API）   │  ← ToggleControl（eligibleProvider 有時のみ）
└─────────────────────────────┘
  自動取得 ON: このプラットフォームは共通の「更新間隔」で自動更新されます。
  手動入力: 価格・URL は手動で入力します。
  [今すぐこのプラットフォームを更新]  ← feedback 付き
```
- `eligibleProvider` 無しの platform: トグルを出さず「このプラットフォームは手動入力です」の静的表示のみ。
- 更新間隔は General 設定に集約（ここには出さない）。

**General 設定**
- 「自動更新を有効化 (WP-Cron)」トグル（既存）＋ **「更新間隔（時間毎）」プリセット（1/3/6/12/24・既定3）** を追加。
- 「一括更新」「強制一括更新」＋各ボタン feedback（S3）。

## 5. 実装フェーズ（TDD）

1. **P1 バックエンド**: GeneralSettings.refresh_interval_hours＋sanitize／RefreshScheduler グローバル化／PlatformDefinition から autoRefresh・refreshIntervalHours 除去／eligibleProvider backfill migration。
2. **P2 UI**: PlatformEditor トグル化／GeneralPanel 間隔＋feedback／per-platform ボタン feedback。
3. **P3 商品一覧**: 最終更新列。
4. **P4 リリース準備**: CHANGELOG＋v2.3.0（Version 3箇所同期）＋E2E（トグル自動化→カード価格/更新日時、商品一覧列）。

## 6. テスト・検証

- PHPUnit（GeneralSettings・RefreshScheduler グローバル・migration・ProductListColumns 列・PlatformDefinition 除去後の後方互換）、JS（PlatformEditor トグル・GeneralPanel 間隔/feedback）。
- E2E（wp-env）: 楽天Kobo を自動トグル ON→保存→refresh→カードに価格＋単一更新日時、商品一覧「最終更新」表示。
- 本番ロールアウト: v2.3.0 更新後に楽天Kobo をトグルで自動化（eligibleProvider backfill 済のはず）→ 認証情報設定 → 一括更新で価格反映確認。BookWalker は e-comi WP から REST 除去。

## 7. 未決事項（実装時）

- 更新ボタン feedback の実装手段（Snackbar vs inline Notice vs `wp.data` notices）。既存 UI パターンに合わせる。
- 「最終更新」列に `last_verified_at` が無い（手動のみ）商品の表示（「—」か「手動」か）。
- migration の適用契機（`upgrader_process_complete` / plugins_loaded での version 比較）。既存の schema_version 運用に合わせる。
