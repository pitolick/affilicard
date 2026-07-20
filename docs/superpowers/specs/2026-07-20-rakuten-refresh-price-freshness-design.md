# 楽天Kobo refresh 再設計 ＋ API準拠の価格鮮度表示 設計

> **柱A** 楽天Kobo の商品カード価格を、セール等で変動したタイミングでも自動更新できるようにする（現状は登録時1回きり）。
> **柱B** アフィリエイト規約に準拠し、**API で確認済み・かつ鮮度内**の価格だけをカードに表示する。未確認/期限切れ/手動入力の価格は**非表示（CTAボタンのみ）＋管理画面で警告**。
> **柱C** refresh 更新頻度を「N時間毎」で制御可能にし、既定を 3 時間毎（24h TTL に整合）とする。
>
> **確定日: 2026-07-20**（brainstorming セッションで確定・規約調査に基づく）

---

## 1. 背景と目的

### 1-1. 楽天Kobo refresh の欠陥

affilicard の価格更新は `ListingRefresher`（Cron／手動ボタン／公開昇格）が `Provider::fetch($externalId)` を呼ぶ設計。**DMM** は安定 ID（`content_id`）で refetch が成立するが、**楽天Kobo は現状そのままでは動かない**:

- 利用側（e-comi `scripts/rakuten-listing.ts`）は `external_id = itemUrl のハッシュ`（`books.rakuten.co.jp/rk/<hash>/` の `<hash>`）を listing に保存する。
- `RakutenProvider::fetch()` は非数字の `external_id` を **keyword 検索**扱い → URLハッシュは keyword として無意味 → `fetch=null`。
- **Kobo EbookSearch API は per-item ID 一発引きが無い**（keyword/title 検索のみ）。「保存済み external_id で正確に再取得」という前提が成立しない。

→ 結果、楽天価格は登録時に固定され、セール時も更新されない。

### 1-2. 価格表示のコンプライアンス要件（規約調査）

各アフィリエイトプログラムの価格表示規約を調査した結果（2026-07-20）:

- **Amazon PA-API（一次規約で確定・最厳格）**:
  - 価格・テキストデータのキャッシュ保存は**最長 24 時間**。超過後は**直ちに PA-API で刷新するか表示を停止**する義務（ASIN のみ無期限保存可）。
  - 価格の隣に**免責文言**（「価格および発送可能時期は表示された日付/時刻の時点のものであり、変更される場合があります」）の表示義務。
  - **価格トラッキング/アラート機能を持ってはならない**。
  - 実質、**手動入力の価格を出し続けることは不可**。→ Amazon API 未解禁の現状、Amazon カード価格は本来表示できない。
- **楽天アフィリエイト**: 税込表示必須・虚偽の参考価格禁止（景表法）。API 鮮度/キャッシュ期限の明文は無いが、古い価格の出し続けは景表法リスク。楽天は API 疎通済で refresh により鮮度を保てる。
- **DMM**: 明文の価格鮮度規約は未確認（API 解禁時に再確認）。

出典:
- Amazon PA-API ライセンス契約: https://affiliate.amazon.co.jp/help/operating/paapilicenseagreement
- Amazon PA-API 利用要件: https://affiliate.amazon.co.jp/help/node/topic/GVJ2BJP35457CLML
- 楽天アフィリエイトガイドライン: https://affiliate.rakuten.co.jp/guideline/rule/

→ **「API で確認済み・鮮度内の価格だけを表示する」ことは、特に Amazon では規約上ほぼ必須**であり、楽天でも安全側。本設計はこの方針を横断ポリシーとして採用する。

### 1-3. 目的

1. 楽天Kobo の価格を affilicard の refresh Cron で自動更新できるようにする（DMM と同じ枠組みに乗せる）。
2. カードに表示する価格を「API 確認済み・鮮度内」に限定し、規約準拠かつ古い価格の誤表示を防ぐ。
3. refresh 頻度を制御可能にし、鮮度ゲートと整合させる。

---

## 2. スコープ

### スコープ内

**柱A（楽天 refresh）**
- `RakutenProvider::fetch()` の再取得ロジック再設計（title 検索 → URLハッシュ一致同定）。
- `ListingRefresher` → `fetch()` の contract 拡張（listing コンテキスト受け渡し）。
- `PlatformDefinition` に「eligible な自動 Provider」を明示するフィールド追加。
- 管理画面 Provider ドロップダウンを eligibleProvider も表示するよう拡張。
- seed の楽天Kobo を自動 Provider（`rakuten-kobo`）へ変更（新規 install）。

**柱B（価格鮮度表示）**
- listing に `last_verified_at`（**fetch 成功時刻**）を追加。
- カード描画に価格表示ゲート（確認済み＆鮮度内のみ価格スパンを出す）を追加。
- `PlatformDefinition` に `priceTtlHours`（鮮度窓）と免責必須フラグを追加。
- 価格の免責文言表示（`renderTimestamp` を `last_verified_at` ベースへ精緻化）。
- 管理画面で「価格を保持しているが非表示になっている」listing への警告アイコン。

**柱C（頻度制御）**
- `refreshFrequency`（enum）を `refreshIntervalHours`（int・N時間毎）へ置き換え、WP-Cron カスタムスケジュールを動的登録。
- 自動 Provider platform の既定 `refreshIntervalHours` を **3**（24h TTL に整合・コケ耐性重視）。

**利用側（e-comi・別リポ・小改修）**
- `ManifestListing` に任意 `search_key` を追加できるようにする（rakuten-listing.ts が API の `item.title` を格納）。
- 投稿時に `last_verified_at` を刻む（投稿直後から価格表示可にする）。
- ※ e-comi の変更は本 spec の実装後に別 PR。affilicard 側は search_key/last_verified_at が無くても degrade して動く（title フォールバック・未確認は非表示）。

- テスト（PHPUnit / WP_Mock、JS）追加。CHANGELOG ＋ バージョン bump ＋ Release（`affilicard.php` Version ヘッダ同期）。

### スコープ外

- **AmazonProvider / DMM の価格鮮度規約の実装差分**（API 未解禁。Amazon の 24h TTL 値は seed に入れておくが、実 API 配線・免責文言の最終確定は API 解禁時）。
- **429 レート制限のリトライ/backoff**（MVP は失敗として扱い非破壊）。
- **共通 HttpClient 抽出**（rule of three で保留・DMM には手を入れない）。
- **e-comi 側パイプラインの本改修**（affilicard の範囲外。small change のみ本 spec に記載）。

---

## 3. 柱A: 楽天Kobo refresh 再設計

### 3-1. fetch() の contract 拡張（listing コンテキスト）

現状 `ListingRefresher::refreshListing()` は `$provider->fetch($externalId, array())` と第2引数に空配列を渡す（[ListingRefresher.php:119]）。`ProviderInterface::fetch(string $externalId, array $platformConfig)` のシグネチャは維持したまま、第2引数（既に `array<string,mixed>` の拡張点）に **listing コンテキスト**を載せる:

```php
$context = array(
    'search_key'  => (string) ( $listing['search_key'] ?? $productTitle ),
    'regular_url' => (string) ( $listing['regular_url'] ?? '' ),
    'external_id' => $externalId,
);
$fetched = $provider->fetch( $externalId, $context );
```

- `refreshProduct()` → `refreshListing()` に **product title を引き渡す**小改修（`refreshListing(array $listing, string $productTitle)`）。
- DMM/manual Provider は `$context` を無視するだけ（後方互換）。ProviderInterface の docblock に第2引数が listing コンテキストを含み得ることを追記。

### 3-2. RakutenProvider の再取得ロジック

`RakutenProvider::fetch()` を次のように再設計（[RakutenProvider.php:38]）:

1. **検索キーの決定**: `$platformConfig['search_key']` が非空ならそれを keyword に使う。無ければ従来通り `$externalId`（数字なら `itemNumber`、非数字なら `keyword`）。
   - ※ e-comi の楽天 external_id は URLハッシュ（非数字）なので、search_key が無いと従来通り無意味 keyword になり `null`（非破壊）。search_key があって初めて正しく機能する。
2. **検索**: `hits=30`（API 上限）で Kobo EbookSearch を叩く。
3. **URLハッシュ一致同定**: 各 hit の `itemUrl` から `rk/<hash>` を抽出し、`$externalId`（保存済みハッシュ）または `$platformConfig['external_id']` と**厳密一致**する 1 件を採用。
   - ハッシュ抽出は e-comi と同じ正規表現 `#/rk/([^/?#]+)#`。末尾スラッシュ/クエリ差に頑健。
4. **ガード**: 一致 0 件（top-30 圏外含む）／複数一致 → **`null`**（既存データ非破壊）。誤同定による別巻・別版の誤上書きを防ぐ。
5. 一致 1 件は従来の `normalizeItem()` で price/list_price/badge/image_url/regular_url/affiliate_url/platform_extras に写像。

**検索キーの持ち方（決定）**: 商品タイトル既定＋任意 `search_key` 上書き。happy path（title 一致）では e-comi 変更不要、recall を上げたい時だけ e-comi が search_key を格納。

### 3-3. 自動化解禁（eligible 自動 Provider 機構）

PR #85 で Provider ドロップダウンは「manual＋現在選択中の自動 Provider のみ」に絞られ、`provider='manual'` の楽天Kobo は UI 上 `rakuten-kobo` に切り替えられない。これを解く:

- `PlatformDefinition` に `eligibleProvider`（任意・その platform が切替可能な自動 Provider の code）を追加。
- `providers.js` の `providerOptionsFor(currentProvider)` を **`manual` ＋ 現在値 ＋ platform の eligibleProvider** を表示するよう拡張（PHP から `eligibleProvider` を注入）。
- seed の楽天Kobo を `provider='rakuten-kobo'`（auto）へ変更。新規 install は自動 ON。既存 e-comi install は eligibleProvider によりドロップダウンから自己解決（migration 不要・foot-gun なし）。

**e-comi listing の refresh 対象化**: e-comi の listing は `update_mode` を持たず、`ListingRefresher::isListingEligible()` は既定 `auto` 扱い。よって**楽天Kobo platform の provider を自動に切り替えるだけで既存 listing は refresh 対象**になる（e-comi 側の update_mode 追加は不要）。

---

## 4. 柱B: API準拠の価格鮮度表示

### 4-1. `last_verified_at`（確認成功時刻）

listing に新フィールド `last_verified_at`（ISO8601）を追加。**`fetch` が成功し価格を反映したときだけ** `current_time('c')` で刻む。既存の `last_fetched_at`（=試行時刻・失敗時も更新）とは別物。

- listing メタは配列丸ごと保存されるため、新フィールドは追加のみで永続化される（field whitelist なし）。
- refresh 失敗（`fetch=null`）時は `last_verified_at` を更新しない＝価格の鮮度は据え置き（やがて期限切れ→非表示）。

### 4-2. 価格表示ゲート

`CardRenderer::renderListings()`（[CardRenderer.php:419-438]）は listing ごとに **CTA ボタン（URL 由来）と価格スパンを別々に**組み立てる。ここに `isPriceDisplayable(array $listing, ?PlatformDefinition $platform, int $nowTs): bool` を導入し、**価格/取消線/バッジのスパンは displayable の時だけ出す**（TTL は秒単位で比較する）:

```
isPriceDisplayable =
    platform が非null
    かつ price が非空
    かつ last_verified_at が非空・パース可能
    かつ 0 <= (nowTs - last_verified_at) <= priceTtlHours * 3600
```

- displayable でない → 価格スパンを出さず **CTA ボタンのみ**（VOD 等で既に使われている描画状態）。
- **手動 Provider listing は `last_verified_at` を持たない → 常に価格非表示**（規約準拠ポリシー）。

### 4-3. 鮮度窓（TTL）と免責文言

`PlatformDefinition` に `priceTtlHours`（int・鮮度窓）を追加。**全 platform 一律 24h**（Amazon PA-API のハード上限に全 PF を合わせ、最も安全側に倒す）。フィールドは残し（既定 24）将来の override 余地は保つが、seed は全て 24:

| platform | provider | priceTtlHours | 免責 |
| --- | --- | --- | --- |
| 楽天Kobo | rakuten-kobo (auto) | 24 | 表示（既存 timestamp を流用） |
| DMM ブックス | dmm-ebook (auto) | 24 | 表示 |
| Amazon Kindle | manual→(将来 amazon) | 24（規約ハード上限） | **必須** |
| VOD 各種 | manual | 24（価格なしのため実質未使用） | — |

- TTL は **refresh 間隔 ≤ TTL** を満たす必要がある（間隔 > TTL だと価格が非表示期間を持つ）。既定 refresh 間隔は **3 時間毎**（§5）なので、24h TTL に対し 8 回/日・7 回連続失敗まで価格を維持できる十分な猶予がある。
- `renderTimestamp()`（[CardRenderer.php:272]）を **`last_verified_at` ベース**に変更し、**価格を表示している listing がある時のみ**「※ YYYY年M月D日時点の価格です。最新の価格は各ストアでご確認ください」を出す。Amazon（免責必須 platform）が将来価格表示する際はこの文言で規約を満たす。

### 4-4. 管理画面の警告アイコン

**価格を保持しているが表示ゲートで非表示**になっている listing（未確認/期限切れ/refresh 失敗）に、商品一覧・エディタで警告アイコン＋理由（`fetch_error` または「価格が未確認/期限切れのため非表示」）を表示する。運用者が「なぜ価格が出ていないか」を把握できるようにする。

---

## 5. 柱C: 更新頻度の制御（N時間毎）

固定 enum（daily/weekly）をやめ、**「N 時間毎」の数値**で更新間隔を指定する:

- `PlatformDefinition` の `refreshFrequency`（string enum）を **`refreshIntervalHours`（int・時間）** に置き換える。`fromArray()` の後方互換で旧値を移行: `daily`→24、`weekly`→168、未設定→既定。
- **既定 `refreshIntervalHours` = 3**（自動 Provider platform の seed）。24h TTL に対し猶予十分・低トラフィックの擬似 cron でも発火機会が多く、コケ耐性が高い。
- **WP-Cron のカスタムスケジュールを間隔に応じて動的登録**する。`RefreshScheduler` が `cron_schedules` フィルタで必要な間隔（例 `affilicard_every_3h` = 3×3600 秒）を登録し、`reconcile()` で `wp_schedule_event(time(), $scheduleName, HOOK, [$code])` に使う。間隔変更時は unschedule → 再 schedule。
- **バリデーション**: `refreshIntervalHours` は int ≥ 1。`> priceTtlHours`（=24）は許可はするが、価格が非表示期間を持つため UI で警告する。
- 管理 UI（PlatformEditor）は数値入力＋プリセット選択（例: 1/3/6/12/24 時間）で指定できるようにする。

---

## 6. データモデル変更まとめ

**listing（配列メタ・追加のみ）**
- `search_key`（任意・string）: keyword 検索語。無ければ product title フォールバック。
- `last_verified_at`（string・ISO8601）: fetch 成功時刻。表示ゲート・免責の基準。

**PlatformDefinition（フィールド変更・`toArray`/`fromArray` に配線）**
- `eligibleProvider`（任意・string・新規）: この platform が切替可能な自動 Provider code。
- `priceTtlHours`（int・新規・**全 PF 既定 24**）: 価格鮮度窓。
- `refreshIntervalHours`（int・**既存 `refreshFrequency` enum を置換**・既定 3）: 更新間隔。`fromArray()` で旧 `daily`→24／`weekly`→168 を移行。
- 免責必須は platform 単位で判定（Amazon=必須）。実装は独立 bool フラグ or Amazon code 判定（実装時に決定）。

いずれも `fromArray()` の欠損デフォルト＋旧値移行で後方互換（既存保存データは補完される）。

---

## 7. 実装フェーズ（TDD）

1. **Phase 1 — 柱A**: contract 拡張・RakutenProvider 再取得・eligibleProvider・seed 変更・`last_verified_at` 記録（成功時刻の書き込みまで）。
2. **Phase 2 — 柱B＋C**: 表示ゲート・priceTtlHours・免責精緻化・警告アイコン・頻度制御。
3. **e-comi 小改修（別 PR）**: `search_key` optional・投稿時 `last_verified_at`。

各 Phase はテスト先行。PHP=Docker（`php:8.2-cli vendor/bin/phpunit`）、JS=ローカル volta（`npx wp-scripts test-unit-js`）。push 前に CodeRabbit CLI。auto-merge しない（Playground プレビュー確認 → マージ）。完了後に SemVer bump（Provider 再設計＋機能追加＝MINOR）＋タグ＋Release（Version ヘッダ同期）。

---

## 8. スコープ確認（重要な運用影響）

本ポリシー適用後、**Amazon/DMM は API 未解禁の間、カード価格が非表示**（CTA ボタンのみ）になる。これは規約準拠の意図的挙動で、各 API 解禁＋自動 Provider 化＋refresh 成功時に価格が自動復活する。楽天Kobo は API 疎通済のため、自動化解禁＋refresh 成功後は価格表示・自動更新される。

---

## 9. 未決事項（実装時に確定）

- `priceTtlHours` の免責必須判定を独立 bool フラグにするか Amazon code 判定にするか。
- 警告アイコンの表示箇所（商品一覧カラム／エディタ Notice／両方）と重複表示の抑制。
- `refreshIntervalHours` を短く設定した際の楽天 API レート制限（1 アプリあたりの上限）との整合（既定 3 時間毎・逐次呼び出しなら当面問題なし。1 時間毎など更に短縮する場合は要監視）。
