# 購入リンクの複数保持と優先表示（listing の offers 化）

> **ステータス**: 設計完了（2026-09-11 brainstorm）。実装未着手。
> **バージョン**: v3.5.0 → **v4.0.0**（MAJOR・listing の形が変わるため公開 IF 破壊）
> **先行 spec**: [2026-07-22-refresh-queue-design.md](2026-07-22-refresh-queue-design.md)（キュー機構）／[2026-08-25-refresh-queue-scalability-design.md](2026-08-25-refresh-queue-scalability-design.md)（`needsRefetch` と掃引）／[2026-07-26-platform-display-order-design.md](2026-07-26-platform-display-order-design.md)（表示順の規約）

---

## 1. 背景

### 1-1. 解けていない問題

**同一ストア内で、同じ作品が複数の SKU として売られていることがある。** 現在の listing は 1 platform = 1 SKU を前提としており、この状況を表現できない。

実例（電子書籍ストア）:

- 期間限定の無料版・試し読み増量版は、**通常版とは別の商品ページ**として提供される。キャンペーン終了とともにストアから消滅する
- モール型ストアでは、**同じ商品を複数の出品者が別ページで売っている**のが恒常状態である

前者では、キャンペーンが終わると listing が指す商品ページごと無くなり、カードには 404 に飛ぶ購入ボタンが残る。後者では、出品者を切り替える手段が無い。

### 1-2. 現在の回避策とその限界

連携する外部の運用ツール側で「消滅した listing を検知し、検索し直して通常版へ差し替える」棚卸しを行っている。しかしストア API の検索で通常版を一意に同定できないことが多く（同名の別メディア・巻数表記の揺れ）、**差し替えに失敗して listing ごと削除される**のが実態である。削除されると、その platform の購入ボタンがカードから消える。

根本原因は「**通常版の情報を持っていないので、消滅してから探し直している**」ことにある。登録時点では通常版も同じ検索結果に含まれており、**その時点で保存しておけば探し直す必要がない**。

### 1-3. 本 spec の目的

listing が**複数の購入リンクを表示優先順で保持**できるようにし、先頭が使えなくなったら次に切り替わるようにする。これにより:

- キャンペーン終了時に、検索し直すことなく通常版へ倒せる
- モール型ストアの出品者違いを順位付きで持てる
- 外部ツールによるストア API の再検索が不要になる

---

## 2. 用語

| 語 | 意味 |
| --- | --- |
| **listing** | 商品 × platform の組。platform ごとの設定（有効・自動更新・ボタンラベル）を持つ |
| **offer**（UI 表記: **購入リンク**） | 1 つの SKU を指す取得結果の集合。URL・価格・書影・取得状態を持つ |
| **表示順**（`display_order`） | offer の優先度。**小さいほど優先**。同値は配列の出現順 |
| **恒久エラー** | ストア側に商品が存在しない（該当なし・無効 ID）。リトライしても解決しない |
| **一時エラー** | API 障害・レート制限・保存競合。リトライで解決しうる |

コード上の識別子は英語（`offers`）、ユーザーに見える文言は日本語（「購入リンク」）で統一する。

---

## 3. データモデル

### 3-1. listing

```text
listing
├ platform                string    platform コード
├ enabled                 bool      この platform を使うか
├ update_mode             string    更新モード
├ auto_update             bool      自動更新するか
├ button_label_override   string    ボタンラベルの上書き
├ platform_extras         object    platform 固有の付加情報
└ offers                  Offer[]   購入リンク（新設）
```

listing には**人が決める設定だけ**が残る。取得結果はすべて offer へ移る。

`enabled` / `auto_update` / `update_mode` を listing 単位に据え置くのは、「この platform を使うか」「自動更新するか」を SKU ごとに変える意味が無いためである。offer 単位にすると設定項目が増えるだけで、運用上の利得がない。

### 3-2. Offer

```text
Offer
├ display_order           int       既定 100。小さいほど優先
├ external_id             string    ストア側の SKU 識別子
├ regular_url             string    商品ページ URL（必須）
├ affiliate_url           string    アフィリエイト URL（任意）
├ price                   string
├ list_price              string
├ badge                   string
├ image_url               string
├ search_key              string    再取得時の検索キー
├ fetch_status            string    '' | 'unsupported' | 'transient' | 'terminal'（新設・§5）
├ last_fetched_at         string    成功・失敗を問わない最終試行時刻
└ last_verified_at        string    最終成功時刻
```

`fetch_error`（文言）は保存しない。§5-5 のとおり廃止し、表示時に `fetch_status` から生成する。

### 3-3. `regular_url` と `affiliate_url` を同居させる理由

この 2 つは**同一 SKU への 2 つの経路**であり、別の offer ではない。

- ストア API は両方を同じ応答で返すか、通常 URL からアフィリエイト URL を組み立てる。分離すると 1 回の取得結果を 2 箇所に書き分けることになる
- **生死判定はアフィリエイト URL では行えない**。リダイレクタは転送先が 404 でも 302 を返す。`offer の生死 = regular_url の生死` と一意に定義できることが必要である
- 分離すると `price` / `image_url` / `last_verified_at` を 2 重に持つことになる

したがって `regular_url` は**必須**とし、空の offer は保存時に弾く。生死を判定できない offer は棚卸しの対象外となり、永久に残るためである。`affiliate_url` は任意で、空なら CTA は `affiliate_url ?: regular_url` により通常 URL へ倒れる（既存挙動）。

**既知の弱点**: アフィリエイト ID を変更すると、保存済みの `affiliate_url` が一斉に陳腐化する。正規化（通常 URL だけ保存して描画時に組み立てる）は、ストアによっては決定的なビルダーを作れず都度 API 取得が必要なため採用できない。これは既存の弱点であり本 spec が新たに作る問題ではないが、offer の複数化により保存件数が増えるため影響範囲は広がる。アフィリエイト ID 変更時は全 offer の強制再取得が必要である。

### 3-4. identity と重複

- 識別子は **`external_id`**、空の場合は `regular_url`
- **両方が空の offer は保存時に弾く**（指せない offer は更新も削除もできず必ず孤児になる）
- 同一 listing 内で識別子が重複する offer は**後勝ちでマージ**する（同じ SKU が 2 つ並ぶ状態を作らない）

### 3-5. 表示順の規約

- `display_order` **昇順**、**同値は配列の出現順**を保つ（安定ソート）。platform の `displayOrder` と同じ規約である
- 既定値は **100**
- 配列の位置そのものは意味を持たない。投入者が互いを知らなくても意図した順序になるよう、**絶対値で指定する**

> **命名の衝突に注意。** platform 側にも `displayOrder` があり、`CardRenderer::sortByDisplayOrder()` は **listing を platform の並び順で**ソートしている。本 spec で追加する `display_order` は **offer を listing 内で**並べるもので、別の軸である。実装時は「listing 間の並び（platform の `displayOrder`）」と「listing 内の並び（offer の `display_order`）」を混同しないこと。

| 操作 | 表示順の扱い |
| --- | --- |
| 削除により後続が繰り上がった | **詰めない**。他の投入者が入れた値の意図を壊さないため |
| 管理画面で人が ↑↓ で並べ替えた | **その並びで採番し直す**。人の明示的な意図が優先。同値が並んでいても確実に入れ替わる |

### 3-6. `external_id` ミラーの複数値化

`ProductRepository::syncExternalIdMirror()` は各 listing の `external_id` を `affilicard_extid_<platform>` meta にミラーし、`findByExternalId()` がこれを引いて「その SKU の商品が既にあるか」を判定する。自動作成（`ProductAutoCreator`）はこの判定に依存している。

現在の実装は **1 platform = 1 `external_id`** を前提としており、`$desired_keys[ $meta_key ] = $external_id` と連想配列で組み立てたうえで `update_post_meta()` で**単一値として上書き**している。offers[] で 1 listing が複数の `external_id` を持つと、**ミラーされるのは 1 つだけ**になり、残りの SKU では商品を引けない。その結果、**自動作成が既存商品を見落として重複商品を作る**。

したがってミラーを**複数値 meta** へ変更する。

- 同一キー `affilicard_extid_<platform>` に、その platform の**全 offer の `external_id`** を `add_post_meta()` で複数値として保持する
- `findByExternalId()` の `meta_query` は `=` 比較のため、**複数値のいずれか 1 つが一致すればヒットする**。呼び出し側の変更は不要である
- stale の削除は、キー単位の削除から**「今回の集合に含まれない値だけを削除する」**方式へ書き換える（`purgeStaleExternalIdMirror()`）
- 空の `external_id` はミラーしない（現行どおり）

---

## 4. offer の選択

### 4-1. 選択係

**「この listing でどの購入リンクを使うか」を答える箇所を 1 つに集約する。** 描画も更新もこの答えだけを見る。

```text
selectOffers( Offer[] $offers, bool $fallbackEnabled ) → Offer[]

  1. display_order 昇順・同値は出現順で安定ソート
  2. $offers が空            → 空配列 []
  3. $fallbackEnabled が false → [ 先頭 ]
  4. true                      → [ fetch_status !== 'terminal' の最初 ]
  5. 全件が terminal           → [ 先頭 ]（非破壊）
```

返り値は常に配列である。現時点では 0 件または 1 件しか返さない。

### 4-2. 必ずリストを返す

**現時点では常に 1 件しか返さない**が、返り値は配列とする。将来「同一 platform の複数の購入リンクを同時に見せる」（出品者違いの並列表示、通常価格の打ち消し表示）に進む場合、**この関数が返す件数を増やすだけ**で描画も更新も追随する。描画と更新が別々に改修対象になることを避ける。

後述のレート制限の枠取りも**返り値の件数に比例**させる。表示を増やした瞬間に枠も増えるため、「表示を増やしたらレート制限を超えた」という事故が構造的に起きない。

### 4-3. 全件エラーでも先頭を返す理由

空配列を返して listing ごと非表示にすると、現在の挙動（取得に失敗している listing もカードには出る／価格だけ `PriceFreshness` が隠す）から変わってしまう。非破壊に倒し、リンクは出したまま価格を隠す。

### 4-4. 飛ばすのは恒久エラーだけ

一時エラー（API 障害・レート制限）では切り替えない。商品は存在しているため、切り替えると復旧時に戻る往復が起きる。表示リンクが行き来すると、閲覧者にも計測にも不利益である。

---

## 5. `fetch_status` の新設と `fetch_error` の廃止

### 5-1. 現状

`fetch_error` に入るのは、`ListingRefresher::refreshOne()` が書く**本プラグイン自身の固定文言 3 種**である。ストア API 由来の文字列ではなく、provider にも依存しない。

| 分岐 | `fetch_error` | `WorkOutcome` |
| --- | --- | --- |
| provider 無し / 非自動 / `external_id` 空 | `対応する自動 Provider がありません` | TRANSIENT_FAILURE |
| `ProviderResult::isTerminalMiss()` | `該当する商品が見つかりませんでした` | TERMINAL_FAILURE |
| `! ProviderResult::isHit()` | `価格情報の取得に失敗しました` | TRANSIENT_FAILURE |
| 成功 | `''` | SUCCESS |

### 5-2. 3 つの問題

**(a) 翻訳結果を保存している。** `__()` の戻り値をそのまま meta に書いているため、**保存時のロケールの文言が固定される**。サイトの言語を変えても古い言語のまま残り、文言を改善しても既存データには反映されない。

**(b) 恒久／一時の区別が、外部からは文字列でしか取れない。** 内部では `WorkOutcome` と `ProviderResult::isTerminalMiss()` で構造化されているが、**永続化されるのは文言だけ**である。連携する外部ツールは `該当する商品が見つかりませんでした` との完全一致で候補を抽出しており、文言を直すと外部の判定が壊れる。

**(c) 「自動取得の対象外」が一時失敗に潰れている。** provider が未実装の platform は恒久的に 1 行目の状態になるが、分類は TRANSIENT_FAILURE である。文言をそのまま出すのをやめて分類で表示すると、「一時的に取得できませんでした」という**実態と異なる説明**になる。

### 5-3. give-up マーカーを使わない理由

`RefreshHandler::onTerminalFailure()` が立てる give-up マーカーは `set_transient` で `GIVEUP_COOLDOWN` 付き、キーは `{post_id, platform}` である。**期限で消える掃引用のクールダウン**であり、offer 単位でもなく、描画が参照できる永続状態ではない。

### 5-4. `fetch_status`（4 値）

保存するのは**コードのみ**とする。

| `fetch_status` | 条件 | `WorkOutcome` | 選択係が飛ばすか | 管理画面の文言（表示時に生成） |
| --- | --- | --- | --- | --- |
| `''` | 成功 | SUCCESS | — | （表示なし） |
| `'unsupported'` | provider 無し / 非自動 / `external_id` 空 | TRANSIENT_FAILURE | いいえ | 「自動取得の対象外です」 |
| `'transient'` | API 到達不可・認証未設定・レート制限 | TRANSIENT_FAILURE | いいえ | 「一時的に取得できませんでした」 |
| `'terminal'` | 該当なし・無効 ID | TERMINAL_FAILURE | **はい** | 「商品が見つかりません」 |

`WorkOutcome` の分類は変更しない（リトライ挙動は現行のまま）。**永続化する状態だけを 4 つに分ける**。`'unsupported'` と `'transient'` は同じ `WorkOutcome` に写るが、管理画面での説明が異なるため区別する。

### 5-5. `fetch_error` の廃止

**文言は保存せず、`fetch_status` から表示時に生成する。** これにより §5-2 の (a)(b)(c) がすべて解消する。

実コードの影響範囲は以下に限られる。

| 場所 | 変更 |
| --- | --- |
| `Cron/ListingRefresher.php` | 文言の代わりに `fetch_status` を書く |
| `PostType/ProductListColumns.php` | **唯一の表示先**。`fetch_status` から文言を引いて警告アイコンの `title` に出す |
| `Rest/ProductSchema.php` | whitelist を `fetch_error` → `fetch_status` に差し替え |
| `Admin/components/ListingsEditor.jsx` | 新規行テンプレートの初期値のみ（画面には出していない） |

**カードには出ない。** `CardRenderer` は `fetch_error` を読んでいないため、コード化によるフロント側の表示変化もセキュリティ上の懸念もない。

### 5-6. 移行

既存の `fetch_error` はデータ移行で `fetch_status` へ写像する。固定文言 3 種なので確実に変換できる。**いずれにも一致しない値（手で書き換えられた等）は `'transient'` に倒す** — 恒久と誤認して購入リンクを飛ばすより、飛ばさない側に倒すほうが安全である。

あわせて `ProductListColumns` の「`fetch_error` は provider 由来の外部文字列のため」というコメントを修正する。実際に入るのは本プラグイン固定の文言のみであり、前提が誤っている。サニタイズ自体は残す（将来 provider 由来の詳細を持つ `fetch_error_detail` を足す余地のため）。

---

## 6. 描画

`CardRenderer` の CTA・書影・価格・最終確認日時は、**すべて選択係の結果を使う**。

現状は `visibleListings()` / `selectCardImage()` / `renderTimestamp()` / CTA がそれぞれ listing 配列を走査しており、`affiliate_url ?: regular_url` のフォールバックは 2 箇所に重複している（`CardRenderer.php:367` と `446`）。offer の選択が加わると判断が 3 箇所に散るため、ここで集約する。

集約により、**ボタンと書影が別の offer を指すといったズレが構造的に起きなくなる**。

### 6-1. `PriceFreshness` の引数を offer にする

`PriceFreshness` の 2 つのメソッドは、いずれも **listing を受け取って取得結果フィールドを読んでいる**。

```text
isPriceDisplayable( array $listing, ?PlatformDefinition $platform, int $nowTs )
    → $listing['price'] / $listing['last_verified_at']

needsRefetch( array $listing, ?PlatformDefinition $platform, int $nowTs, int $leadSeconds )
    → $listing['last_fetched_at']
```

これらのフィールドは offer へ移るため、**両メソッドの第 1 引数を offer に変更する**。判定ロジック自体（TTL 比較・クールダウン）は変えない。

呼び出し元は 4 箇所で、いずれも**選択係が返した offer を渡す**ように変える。

| 呼び出し元 | 用途 |
| --- | --- |
| `CardRenderer:303` | 最終確認日時の表示可否 |
| `CardRenderer:480` | 価格の表示可否 |
| `QueueMaintenance:261` | 掃引の再取得判定 |
| `ProductListColumns:197` | 管理画面の警告アイコン |

**`QueueMaintenance` は取得結果フィールドを 1 つも直接参照していない**が、`needsRefetch()` に渡す対象が listing から offer へ変わるため改修対象である。フィールド名の検索だけでは拾えない**間接依存**にあたる。

---

## 7. 更新

### 7-1. 原則 — 表示しているものを更新する

**refresh の対象 = 描画の対象 = 選択係の結果。** ルールを 1 つにすることで、「見せている価格が更新されていない」状態が作れなくなる。

フォールバック設定が OFF なら常に先頭、ON なら恒久エラーでない先頭。どちらの設定でも原則は一貫する。

リクエスト数は 1 listing につき 1 回のままで、現行から増えない。

`ListingRefresher::refreshOne( $postId, $platform )` は選択係で対象 offer を決め、その offer に書き戻す。

**`search_key` は offer 単位で持つ。** 空の場合に商品タイトルへフォールバックする現行の挙動（`refreshOne()` の `$context`）はそのまま維持する。フォールバック先の商品タイトルは商品単位のため、どの offer を取得していても同じ値になる。

### 7-2. 更新が止まる 2 つの状態

いずれも意図した挙動であり、異常ではない。

**(a) `external_id` が空の offer。** `refreshOne()` は `'' === $externalId` で `'unsupported'` を返すため、**自動取得されない**。管理画面で URL だけ手入力した購入リンクがこれに当たる。手動更新専用の offer として扱う。

**(b) フォールバック設定が OFF で、先頭 offer が `'terminal'` の場合。** 表示も更新も先頭のまま固定され、give-up クールダウンにより掃引からも外れる。**その listing は実質的に更新が止まる。** 後続 offer は表示されないため更新も不要であり、矛盾はない。設定を ON にするか、外部ツールが先頭 offer を削除すれば、後続が繰り上がって更新が再開する。

### 7-3. 取得のトリガー

既存 2 本に **1 本追加**する。新しい cron やイベントは作らない。

| トリガー | 契機 | ゲート | 状態 |
| --- | --- | --- | --- |
| 記事の公開・更新 | 記事の保存 | `ListingEligibility` のみ（鮮度を見ず強制取得） | 既存・**無改修** |
| 定期掃引 | スケジュール | `ListingEligibility` ＋ `needsRefetch` ＋ 棚卸し除外 | 既存・offer 対応のみ |
| **listing の変化** | **`listings` meta の保存** | `ListingEligibility` ＋ `needsRefetch` | **追加** |

新トリガーのゲートを掃引と同じにするのが要点である。`needsRefetch` は「毎周回リトライでキューが溢れる」問題を潰すために作られたクールダウン付きの判定であり、これを借りることで同じ事故を繰り返さない。

### 7-4. 単一のフックで全経路を拾う

offer の並びが変わる経路は 3 つあるが、**すべて `update_post_meta( META_LISTINGS, ... )` を通る**。

```text
内部の価格更新           → Repository::updateListing()  → update_post_meta
外部ツールの offer 削除  → REST の meta 書き込み         → update_post_meta
管理画面での並べ替え・追加 → 同上                        → update_post_meta
```

したがって `listings` meta の保存に反応する **1 つのフック**で足りる。経路ごとにフックを置くと新しい経路が増えたときに漏れるが、この形なら自動的に拾える。

`rest_after_insert_` フックには乗せない。REST 経由しか発火せず、内部の価格更新を取りこぼすためである。

### 7-5. 何を検知するか

**「切り替わった」というイベントではなく、「今使う offer の価格が古い」という状態を見る。**

```text
listings meta が保存された
  → listing が ListingEligibility::isAutoEligible() を満たすか確認する
  → 選択係を呼んで使用中の offer を得る
  → needsRefetch() で取り直すべきか判定する
  → 必要なら即時実行キューに 1 件積む
```

| 起きたこと | 投入 |
| --- | --- |
| 価格更新が成功して保存された | しない（たった今取得したため鮮度が新しい） |
| offer が削除され、後続が繰り上がった | **する**（繰り上がった offer は古い） |
| 管理画面で並べ替え、先頭が入れ替わった | **する**（先頭に来た offer が古ければ） |
| 取得に失敗し続けている | しない（`needsRefetch` のクールダウン中） |

### 7-6. レート制限

`ThrottledActionHandler` は `tryAcquire()` を `performWork()` の**前に 1 回だけ**呼ぶ。performWork が複数回 API を叩くと 2 回目以降が枠を素通りする。

したがって**枠の確保は選択係が返した件数に比例させる**。

```text
$targets = selectOffers( $offers, $fallbackEnabled );
$acquire = $limiter->tryAcquire( $account, $interval * count( $targets ), $nowMs );
```

現時点では `count($targets)` が常に 1 なので挙動は現行と同一である。複数を叩くようになった場合は、performWork 内で各取得の間に `$interval` の待機を入れる（確保済みの幅に収まる）。

### 7-7. キューのキーは変更しない

Action Scheduler の args は `{post_id, platform}` ＋ `unique=true` のままとする。offer 単位のジョブにするとキー設計を変えることになり、v2.4.0 の非同期キューと v3.5.0 の throttle 修正の中核に触れる。1 ジョブが選択係の結果を処理する形なら、キーを変えずに済む。

---

## 8. ループ防止

新トリガーは「保存 → 判定 → 投入」であり、投入先は Action Scheduler のテーブルで post meta を書かないため再帰しない。ただしこの領域では過去に `perpetual retry` と completed アクションのチャーンが発生しているため、理屈上の安全に頼らず明示的に止める。

**3 層で止める。**

1. **再入ガード（同一リクエスト内）** — 処理中の商品 ID を記録し、同一リクエストで同じ商品が再度来たら何もしない。1 回の保存で `listings` meta が複数回書かれる経路（sanitize → 更新 → 派生 meta の再構築）を吸収する
2. **短期クールダウン（リクエスト跨ぎ）** — 「この商品について直近 N 秒は投入しない」を transient で持つ。外部ツールが複数の offer を連続削除する場合の連打を吸収する。`needsRefetch` の長いクールダウンとは別の短い保険である
3. **投入の冪等性（既存）** — `enqueueManual` は `unique=true` のため、1・2 をすり抜けても pending は 1 件にまとまる

**ループしない根拠は「このフックが post meta を書かない」という一点に依存する。** 将来「ついでに派生 meta も更新しよう」と書かれた瞬間に崩れるため、テストで固定する（§13）。

---

## 9. 設定

`GeneralSettings` にトグルを 1 つ追加する。

> **商品が見つからない購入リンクを飛ばして、次の購入リンクを表示する**（既定: **OFF**）
>
> 補足: 一時的な取得エラー（API 障害・レート制限など）では切り替えません。ストア側で商品ページが無くなった場合だけ切り替わります。

**既定 OFF とする。** v4.0.0 へ更新しただけでは表示も更新の挙動も一切変わらない。オプトインで有効化する。

粒度はグローバル 1 つとする。platform 単位・listing 単位は設定項目が増えるだけで、サイト単位の方針としてはグローバルで足りる。

---

## 10. 管理 UI

`ListingsEditor` を 2 階層にする。

```text
楽天Kobo                                   ← listing の設定
  有効 / 自動更新 / ボタンラベル上書き

  購入リンク
  ┌────────────────────────────────────────────┐
  │ ↑ ↓   表示順 10    ● 使用中                 │
  │       外部ID / 通常URL / アフィリエイトURL   │
  │       価格 / 参考価格 / バッジ / 画像URL      │
  ├────────────────────────────────────────────┤
  │ ↑ ↓   表示順 100                            │
  │       ⚠ 商品が見つかりません                 │
  │       …                                     │
  └────────────────────────────────────────────┘
  ＋ 購入リンクを追加
```

### 10-1. 使用中の表示

**`● 使用中` は描画が使うのと同じ選択係の答えをそのまま出す。** 表示順とエラー状態から人が暗算するのではなく、同じ関数の結果を出すことで管理画面とカードがズレない。

### 10-2. 状態の表示

`fetch_status` を日本語で出す。

| `fetch_status` | 表示 |
| --- | --- |
| `''` | （表示なし） |
| `'unsupported'` | 「自動取得の対象外です」 |
| `'transient'` | 「一時的に取得できませんでした」 |
| `'terminal'` | 「商品が見つかりません」 |

### 10-3. 並べ替え

操作は **↑↓ ボタン**、表示順は**数値表示のみ**とする。人が数値を直接編集する必要はほぼない。数値による指定は外部の投稿パイプラインが使う経路として REST 側に残す。

### 10-4. 既知の罠

`PanelBody` の `initialOpen` は手動トグルまで毎レンダー読み直される。購入リンクは並べ替えるため、**位置依存の値を渡すと開閉状態が入れ替わる**。開閉は offer の識別子に紐づけて管理する。

---

## 11. 移行と後方互換

### 11-1. データ移行

`PluginUpgrade::maybeUpgrade()` にステップを追加し、全商品の listing を走査して以下を行う。

1. 取得結果フィールドを **`offers[0]`（`display_order: 100`）** へ移す
2. `fetch_error` の文言を `fetch_status` のコードへ写像する（§5-6）
3. `external_id` ミラーを複数値形式で再構築する（§3-6）
4. `SchemaVersion::CURRENT` を **`'1'` → `'2'`** へ上げ、商品ごとの `META_SCHEMA_VERSION` を更新する（`syncDerivedMeta()` が再構築する）

- v3.5.0 のバッチ基盤に乗せる。商品数が増えても実行時間で切れない
- **冪等**とする。`offers` が既に存在する listing はスキップするため、途中で止まっても再実行できる
- 移行後の `offers` は必ず 1 件であり、選択係は常にその 1 件を返す。**挙動は移行前と完全に同一**である

**ダウングレードはできない。** v3.5.0 以前は `offers[]` を読めず、listing から取得結果が消えた状態に見える（カードの購入ボタン・価格・書影がすべて欠落する）。移行は冪等だが**不可逆**であり、切り戻しはデータのバックアップからの復元でしか行えない。リリースノートに明記する。

### 11-2. 入力の後方互換

`ProductSchema::sanitizeListings()` は、**flat な取得結果フィールドを受け取ったら `offers[0]` に畳んで正規化する**。出力は常に `offers[]` のみとする。

これにより、**本プラグインの v4.0.0 を、連携する外部パイプラインの改修を待たずにリリースできる**。外部側は当面 flat な形で送り続けても壊れず、`offers[]` を送るように変えるのは独立したタイミングで行える。この互換が無いと両者の同時リリースが必須になり、問題が起きたときの切り分けができない。

### 11-3. 破壊的変更の告知

listing の読み出し形が変わるため、REST の応答を直接解釈している利用者には影響がある。CHANGELOG に以下を明記する。

- `listings[].external_id` 等の取得結果フィールドは `listings[].offers[].*` へ移動した
- **`listings[].fetch_error` は廃止した。** 代わりに `listings[].offers[].fetch_status`（`'' | 'unsupported' | 'transient' | 'terminal'`）を参照する。文言は表示時に生成される
- 書き込みは flat な形も受理し `offers[0]` に正規化される
- `affilicard_extid_<platform>` meta は**複数値**になった
- `listings[].platform` / `enabled` / `auto_update` / `update_mode` / `button_label_override` / `platform_extras` は不変
- **ダウングレード不可**（§11-1）

### 11-4. 読み取り側の失敗は静かである

§11-2 の後方互換は**書き込み**にしか効かない。**listing を読む側は追随が必要**であり、しかもその失敗は**例外にならない**。

典型例は「`fetch_error` が特定の値の listing を候補として抽出し、何か処理する」という外部ツールである。v4.0.0 では `fetch_error` が存在しないため、候補は**常に 0 件**になる。処理は正常終了し、ログにも異常が出ず、**何もしなくなったことに気づけない**。

したがってリリース時は以下を行う。

- CHANGELOG に「**読み取り側の追随が必要**であり、追随しない場合は**エラーにならず無処理になる**」と明記する
- 連携する外部ツールの改修を、本プラグインのリリースと**同じ運用サイクル内**で完了させる（書き込み側の互換があるため同時リリースは不要だが、読み取り側は放置できない）

---

## 12. 影響を受けるコンポーネント

実装前に調べ直さなくて済むよう、`external_id` / `regular_url` / `affiliate_url` / `price` / `image_url` / `search_key` / `last_verified_at` の参照箇所を全走査した結果を残す。

### 12-1. 改修が必要

| コンポーネント | 参照数 | 内容 |
| --- | --- | --- |
| **`Repository/ProductRepository.php`** | **17** | `updateListing()`（書き戻し先が offer になる）／`listingSummary()`（価格サマリを選択係の結果から作る）／`countFallbackProducts()`・`hasFallbackListing()`（アフィリ URL 欠落の集計が offer 単位になる）／`syncExternalIdMirror()`・`purgeStaleExternalIdMirror()`（複数値化・§3-6）／`findByExternalId()`・`search()`（複数値ミラーへの追随） |
| **`Cron/ListingRefresher.php`** | 4 | 選択係で対象 offer を決めて書き戻す。`fetch_status` を書く |
| **`AutoCreate/ProductAutoCreator.php`** | 6 | 生成する listing を `offers[]` 形式にする |
| **`PostType/ProductListColumns.php`** | 6 | 警告アイコンの判定が offer 単位になる。`fetch_status` から文言を引く |
| **`Renderer/CardRenderer.php`** | — | CTA・書影・価格・タイムスタンプを選択係の結果に統一（§6） |
| **`Rest/ProductSchema.php`** | 1 | `offers[]` の sanitize、flat 入力の正規化、whitelist |
| **`Admin/components/ListingsEditor.jsx`** | 1 | 2 階層化・↑↓ 並べ替え・使用中表示（§10） |
| **`Settings/GeneralSettings.php`** | — | フォールバックのトグル追加（§9） |
| **`Queue/ThrottledActionHandler.php`** | — | 枠の確保を件数に比例させる（§7-6） |
| **`Upgrade/PluginUpgrade.php`** | — | 移行ステップの追加（§11-1） |
| **`Pricing/PriceFreshness.php`** | 3 | 両メソッドの第 1 引数を listing → offer（§6-1） |
| **`Queue/QueueMaintenance.php`** | **0**（間接） | `needsRefetch()` に渡す対象が offer になる。**フィールド名の検索では拾えない**（§6-1） |
| **`Schema/SchemaVersion.php`** | — | `CURRENT` を `'1'` → `'2'` へ（§11-1） |

### 12-2. 未認証への露出制御は弱まらない

`affiliate_url` を未認証の REST 応答に含めないという hardening があり、E2E（`tests/e2e/rest-read-hardening.spec.js`）で検証されている。

この制御は **`register_post_meta()` の `auth_callback`（`edit_posts`）で meta 単位**にかかっており、フィールド単位のフィルタではない。`affilicard_listings` meta そのものが未認証には出ないため、**`affiliate_url` が `offers[]` の内側へ移っても制御は等価に効く**。offers[] のためにフィールド単位の除外を追加する必要はない。

既存の E2E は応答全体に対する文字列検査であり、`offers[]` 化後もそのまま有効である。

### 12-3. 改修が不要（確認済み）

**フィールド名の検索が 0 件でも、`PriceFreshness` のように listing を受け取るヘルパへ渡している箇所は改修対象になる**（§6-1 の `QueueMaintenance`）。以下は「0 件であり、かつ間接依存も無い」ことを確認したものである。

| コンポーネント | 理由 |
| --- | --- |
| `Rest/CardPreviewController.php` | 取得結果フィールドを直接参照していない（0 箇所）。`CardRenderer` 経由のため自動的に追随する |
| `Block/edit.jsx` | `listings` を 1 箇所読むが、参照するのは `enabled` と `platform` のみ。**どちらも listing に残す**フィールドである |
| `Admin/components/ProductSettingsPanel.jsx` | `affilicard_listings` meta を `ListingsEditor` へ渡すだけの中継。listing の中身を解釈していない |
| `Pricing/ListingEligibility.php` | 読むのは `auto_update` / `update_mode` / `enabled` のみ。いずれも listing に残す設定フィールドである |
| `Queue/BatchRefreshHandler.php` | 0 箇所。listing の中身ではなくキュー投入の単位のみを扱う |
| `Queue/PublishTrigger.php` | 0 箇所。`enqueueProductListings()` 経由で `ListingEligibility` のみを見る |
| `Rest/RefreshController.php` / `Rest/ProductsController.php` | 0 箇所。listing の中身を解釈しない |
| `Queue/Enqueuer.php` | args は `{post_id, platform}` のまま変更しない（§7-7） |
| `Stocktake/StocktakePolicy.php` | 判定は listing 単位ではなく商品単位（最終掲載日）のため影響しない |

---

## 13. テスト方針

| 層 | 対象 |
| --- | --- |
| **PHPUnit**（Docker `php:8.2-cli`） | 選択係の全分岐（表示順の昇順・同値の安定性・`terminal` を飛ばす／飛ばさない・`unsupported` と `transient` は飛ばさない・全件エラー・空配列）／`fetch_status` の 4 値の書き分け／`fetch_error` → `fetch_status` の写像（**未知の文字列が `'transient'` に倒れること**）／`external_id` ミラーの複数値化（全 offer がミラーされる・stale が**値単位**で消える・**後続 offer の `external_id` でも `findByExternalId()` が引ける**）／`PriceFreshness` の両メソッドが offer を受け取って従来どおり判定すること（TTL 境界・クールダウン境界）／移行の冪等性と `SchemaVersion` の更新／レート制限の枠が件数に比例すること／ループ防止 3 層 |
| **JS テスト**（`npm run test:js`） | `ListingsEditor` の 2 階層化・↑↓ による並べ替えと採番・並べ替え後も開閉状態が保たれること・`● 使用中` が選択係と一致すること・`fetch_status` 4 値それぞれの文言表示 |
| **E2E（実 WP / wp-env）** | **`sanitizeListings` の whitelist**。新フィールドを whitelist に追加し忘れると保存時に黙って消えるが、**モックリポジトリの unit test では検出できない**。実 WP に保存して読み戻す経路を必ず通す。**複数値 meta のミラー**も同じ理由で実 WP で確認する（`add_post_meta` の複数値挙動はモックで再現しにくい） |

ループ防止は「フックが `update_post_meta` を呼ばないこと」「同一リクエストで 2 回保存しても投入が 1 件に収まること」「意図的に再帰させても深さ 1 で止まること」の 3 点をテストで固定する。理屈をコメントで残すのではなく、壊れたらテストが落ちる形にする。

---

## 14. スコープ外

- **複数の購入リンクの同時表示**（出品者違いの並列 CTA、通常価格の打ち消し表示）。選択係が配列を返す形にして余地だけ残す。実装しない
- **有効期限フィールド**。ストア API はセールの終了日を返さないため埋める情報源が無い。offer を分ける理由は「別 SKU であること」であり、終了は SKU の消滅として現れるため生死判定で足りる
- **最安値による自動並べ替え**。表示順は固定順であり、価格順で選ぶのは別機能である
- **アフィリエイト URL の正規化**（通常 URL から都度組み立てる）。ストアによっては決定的なビルダーを作れない
- **Action Scheduler のキー設計変更**（§7-7）

---

## 15. 実装後に再評価する項目

- フォールバック設定を ON にした運用で、恒久エラーによる切り替えが意図どおり発生しているか
- 新トリガーによる即時投入が、掃引中に過剰なチャーンを生んでいないか（completed アクション数で観測）
- `offers` が 2 件以上ある listing の比率。1 件のままなら、複数保持の価値が出ていない
