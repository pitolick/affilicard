# 価格更新キュー運用ガイド（Action Scheduler ベース）

> **バージョン**: affilicard v2.4.0 以降
> **対象読者**: サイト管理者・インフラエンジニア

本ドキュメントは、v2.4.0 で導入された非同期価格更新キュー（Action Scheduler 土台）の構成と運用方法を説明します。

---

## 1. 概要

affilicard v2.4.0 では、価格更新を **Action Scheduler（AS）** で管理する非同期キュー方式に移行しました。これにより：

- **レート制限耐性**: アカウント（共有 API）ごと（例 楽天 ≈ 1 req/sec）のリクエスト間隔を厳守し、429 エラーを削減
- **スケーラビリティ**: 商品数が多くても、タイムアウトすることなくバックグラウンドで順序立てて処理
- **復旧性**: 処理失敗時の自動再試行・失敗履歴の保持
- **管理UI**: WordPress 管理画面でキュー状態を監視・操作可能

---

## 2. 前提条件と推奨構成

### 2-1. WP-Cron から実 OS Cron へ（推奨・必須に近い）

**重要**: WordPress の「WP-Cron」は **擬似 cron**（リクエスト駆動）であり、サイト訪問がなければ実行されません。価格更新キューを確実に動かすため、**実 OS Cron を使う必要があります**。

#### 手順 1: WP-Cron を無効化

wp-config.php に以下を追加：

```php
define( 'DISABLE_WP_CRON', true );
```

#### 手順 2: OS Cron に「掃引イベント発火」と「AS ランナー」の2本を追加

`DISABLE_WP_CRON=true` にすると WP-Cron イベントが自動発火しなくなります。価格更新の継続更新は次の2段構えで回るため、**両方**を OS cron に登録してください（どちらか一方だけでは継続更新が止まります）。

1. **掃引 WP-Cron イベントの発火**（stale listing をキューへ積む起点＝`affilicard_refresh_all`）
2. **Action Scheduler ランナー**（積まれたキュージョブを実際に実行する）

サーバの cron ジョブ（`crontab` または cPanel）に以下を追加します：

```bash
# 1) 期限到来した WP-Cron イベント（掃引 affilicard_refresh_all を含む）を発火してキューへ積む
* * * * * cd /path/to/wp && /usr/bin/wp cron event run --due-now >/dev/null 2>&1
# 2) 積まれた Action Scheduler ジョブ（価格取得）を実行する
* * * * * cd /path/to/wp && /usr/bin/wp action-scheduler run --batches=1 >/dev/null 2>&1
```

**パラメータ説明**:
- `* * * * *`: 毎分実行
- `/path/to/wp`: WordPress のルートディレクトリパス（`wp-config.php` がある場所）
- `/usr/bin/wp`: WP-CLI の実行パス（`which wp` で確認）
- `wp cron event run --due-now`: 期限到来した WP-Cron イベントを発火。`DISABLE_WP_CRON=true` 下では掃引（`affilicard_refresh_all`）がこれ無しには回らない
- `--batches=1`: 1 バッチ（デフォルト約10アクション）処理して終了。複数バッチ連続実行でタイムアウトを防ぐ
- `>/dev/null 2>&1`: ログ出力を捨てる（不要ログで cron メール満杯を避ける）。トラブル時は一時的に外してログ確認

> **なぜ2本必要か**: `wp action-scheduler run` は「既にキューに積まれたジョブ」を実行するだけで、stale listing をキューへ積む掃引イベント自体は発火しません。掃引は WP-Cron イベント（`affilicard_refresh_all`）なので、`DISABLE_WP_CRON=true` 下では `wp cron event run --due-now` で明示的に発火させる必要があります。

#### Cron 登録の確認

```bash
crontab -l | grep "action-scheduler"
```

実行テスト：

```bash
cd /path/to/wp && /usr/bin/wp action-scheduler run --batches=1 --dry-run
```

---

## 3. キューの監視

### 3-1. WordPress 管理画面 → Tools → Scheduled Actions

Action Scheduler 標準の UI。以下が可能です：

- **状態フィルタ**: pending（待機中）/ running（実行中）/ complete（完了）/ failed（失敗）
- **グループフィルタ**: `affilicard-rakuten`、`affilicard-dmm` など（アカウントごと＝認証画面と同単位。自動アカウントのみ。`manual` は対象外）。affilicard メニュー内「更新キュー（ジョブ一覧）」からも同じ一覧を日本語で確認可能
- **個別アクション**: 詳細・実行・キャンセル・再試行
- **検索**: Hook 名・args・スケジュール日時

> **日時のタイムゾーンについて**: Scheduled Actions／「更新キュー（ジョブ一覧）」の日時カラムは **Action Scheduler 本体が常に UTC（`+0000`）で描画する仕様**で、WordPress 設定 → 一般の「タイムゾーン」を設定しても変わりません（`timezone_string=Asia/Tokyo` でも `+0000` のままであることを実測確認済み）。affilicard は AS の埋め込み一覧の日時描画を制御しないため、UTC 表示のまま運用します（日本時間 = UTC + 9 時間で読み替え）。

**定期的な確認ポイント**:
1. failed アクション件数 → 多数失敗しているなら [§4-2 トラブルシューティング](#4-2-トラブルシューティング) を参照
2. pending 件数が増え続ける → キューが drain しきれていない。次回実行がつまっている可能性

### 3-2. affilicard 設定 → 更新キュー パネル

WordPress 管理画面の affilicard 設定から「更新キュー」セクションを開くと、以下が表示されます：

- **キューサマリ**: 
  - pending 件数（待機中）
  - failed 件数（失敗）
  - account 別内訳（`rakuten`／`dmm` 等・認証画面と同単位）
  
- **操作パネル**:
  - **削除**: すべての pending アクションを削除（慎重に）
  - **失敗を削除**: failed アクションのみ削除
  - **失敗を再試行**: failed アクションを pending に戻す
  - **Scheduled Actions へリンク**: Tools → Scheduled Actions へ移動（詳細確認用）

- **設定**:
  - **一時停止**: チェックで価格更新ワーカーをハルト（スケジュール自体は保持され、復旧時に処理）
  - **アカウント別スロットル**: 各アカウント（DMM／楽天）のリクエスト最小間隔（ms）を上書き
  - **ログ保持期間**: 完了・失敗ログの自動削除日数（既定 完了 24 時間 / 失敗 7 日）

---

## 4. 運用上の注意点

### 4-1. Rate Limit と一時停止（Pause）

各 Provider には API リクエスト制限があります：

| Provider | 目安 | 推奨スロットル |
| --- | --- | --- |
| Amazon | 多数 / 要 API キー | （未対応） |
| 楽天 Kobo | ≈ 1 req/sec | 1100 ms（1 秒 + 余裕） |
| DMM | 要確認 | 1000 ms（実測中） |
| 手動 | 即時 | 0 ms |

**API 一時停止イベント発生時**:

1. affilicard 設定 → 更新キュー → **一時停止にチェック**
2. API 復旧まで待機
3. チェックを外す → キューが再開

一時停止中もキューに新規アクションは積まれます（ワーカーだけ止まる）。解除後、スケジュール済みのアクションから処理を開始。

### 4-2. トラブルシューティング

#### ① キューが溜まり続ける（pending が増え続ける）

**原因確認**:

```bash
# Action Scheduler ストアの pending 件数
cd /path/to/wp && /usr/bin/wp db query "SELECT COUNT(*) FROM $(wp db prefix)actionscheduler_actions WHERE status='pending';"
```

**対処**:

1. **Cron が動いているか確認**:
   ```bash
   crontab -l | grep action-scheduler
   ```
   登録なければ [§2-2 手順 2](#手順-2-os-cron-に-action-scheduler-ランナーを追加) を実行

2. **一度手動実行試す**:
   ```bash
   cd /path/to/wp && /usr/bin/wp action-scheduler run --batches=5
   ```
   エラーが出れば特定可能。出なければ cron 実行環境を疑う（次項）

3. **Cron 実行環境確認**:
   - サーバ時刻が正確か: `date` コマンドで確認
   - WP-CLI が認識するパスは正しいか: `which wp` + crontab の路を照合
   - PHP メモリ制限: `wp config get 'memory_limit'` で十分か（≥ 256M 推奨）
   - 環境変数: cron 実行時は PATH/WP_HOME が異なり得る。絶対パス指定を使用

#### ② Failed アクション多発

Tools → Scheduled Actions で failed をフィルタ。詳細確認：

| 症状 | 原因 | 対処 |
| --- | --- | --- |
| `429 Too Many Requests` | API レート制限ヒット | スロットル値を上げる（§4-1）、または API 復旧待機 + 一時停止 + 解除で再試行 |
| `404 Not Found` / `Unauthorized` | API エンドポイント変更・認証情報期限切れ | affilicard 設定 → API 認証情報を再確認 |
| `Timeout` | リクエストが長すぎる | ネットワーク遅延・Provider 側の処理遅延。一度手動実行し URL 疎通確認 |
| `Connection refused` | 外部 API に到達不可 | ファイアウォール・プロキシ設定、または Provider 側ダウンタイム |

**一括再試行**:

管理画面 → 更新キュー → **失敗を再試行** ボタンを使う。

Action Scheduler のテーブル（`actionscheduler_actions` 等）を直接 `UPDATE` する運用は非対応。
AS の API/hook を経由しない直接書き換えは、AS 内部の状態（ロック・グループ集計・
アクション履歴）と不整合を起こしうるため行わない。件数確認のような読み取り専用の
`SELECT`（§4-2 ①）は問題ない。

#### ③ ログが肥大化（retention）

完了・失敗ログは自動削除対象。保持期間は **管理画面 設定 → affilicard → 更新キュー → ログ保持期間** で調整する（完了=時間、失敗=日数）。この設定値は `affilicard_general` オプション（`retention_done_hours` / `retention_failed_days`）に保存され、プラグインが AS の `action_scheduler_retention_period` / `action_scheduler_retention_period_for_failed` フィルタへ秒換算して渡す（既定: 完了 24 時間 / 失敗 7 日）。

削除は AS 内部のクリーンアップジョブで自動実行される。

---

## 5. パフォーマンスチューニング

### 5-1. Cron バッチサイズ

本文の OS Cron 例では `--batches=1` を推奨（タイムアウト回避）。処理が十分早い環境なら増やせます：

```bash
# 毎分 3 バッチ = 約 30 アクション
* * * * * cd /path/to/wp && /usr/bin/wp action-scheduler run --batches=3 >/dev/null 2>&1
```

目安:
- **低流量サイト**: `--batches=1`
- **中流量**: `--batches=2`
- **高流量・リソース充分**: `--batches=5`

超高流量（商品数 1000+）なら、cron を高頻度に実行：

```bash
*/2 * * * * cd /path/to/wp && /usr/bin/wp action-scheduler run --batches=2 >/dev/null 2>&1
# 2 分ごと
```

### 5-2. 鮮度スキップ（自動最適化）

キューの流入量は **鮮度スキップ** により大幅に削減されています。`last_verified_at`（最終検証時刻）が **TTL（既定 24 時間）** 内の商品は、cron 掃引では投入されません。

これにより：
- **低流入商品**: 大半が noop（投入されない）
- **セール記事**: 公開・更新時に force で即座に同期（TTL 無視）

**設定確認**:

```bash
wp option get 'affilicard_price_freshness_ttl_hours'  # 既定 24
```

---

## 6. 運用チェックリスト

### 初回導入時

- [ ] WP-Cron を disable した（wp-config.php に `DISABLE_WP_CRON=true`）
- [ ] OS Cron に **掃引イベント発火**（`wp cron event run --due-now`）と **AS ランナー**（`wp action-scheduler run`）の**2本**を登録した（§手順2。片方だけだと継続更新が止まる）
- [ ] `wp action-scheduler run --dry-run` で動作確認
- [ ] Tools → Scheduled Actions にアクセス可能か確認
- [ ] affilicard 設定 → 更新キュー パネルが表示されるか確認

### 定期監視（週 1 回程度）

- [ ] failed アクション件数が 0 か、許容範囲か
- [ ] pending 件数が増え続けていないか
- [ ] 一時停止トグルが意図通り（通常は OFF）か

### 月 1 回

- [ ] ログ保持期間は適切か（ディスク圧迫していないか）
- [ ] アカウント別スロットル値は最新の API 仕様に合っているか
- [ ] アクション履歴から「常に失敗する商品」や「ブロックされたキーワード」がないか

---

## 7. トラブル連絡先 & リソース

- **Action Scheduler 公式ドキュメント**: <https://actionscheduler.org/>
- **Action Scheduler API リファレンス**: <https://actionscheduler.org/api/>
- **WP-CLI `action-scheduler` コマンド**: `wp action-scheduler help run`

affilicard 固有の問題の場合、GitHub Issues を参照または作成してください。

---

## 8. バージョン履歴

| バージョン | 日付 | 更新内容 |
| --- | --- | --- |
| 1.0 | 2026-07-24 | 初版作成（v2.4.0） |

