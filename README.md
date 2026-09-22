# affilicard

汎用アフィリエイト商品カードの WordPress プラグイン。電子書籍 / VOD / 家電など複数の商品ジャンルに対応する拡張可能設計。

## 主な機能 (v1.0.0)

- カスタム投稿タイプ `affilicard_product` で 1 商品 = N プラットフォーム listing を管理
- 商品タイプ拡張 (`Affilicard\Types\ProductTypeInterface`): 汎用 / 電子書籍 / VOD (4a-4)
- Provider 拡張 (`Affilicard\Provider\ProviderInterface`): 手動入力 / DMM ebook API / Amazon (4b) / 楽天 (4b)
- React + `@wordpress/scripts` ベースの管理画面 (Settings + 商品編集 Metabox)
- WP REST API (`/wp-json/affilicard/v1/*`) で外部ツールから商品 upsert 可能
- AES-256-CBC で Provider 認証情報を暗号化保存
- URL フォールバック (アフィリエイト URL 未設定時は通常 URL) + admin 可視化 (CPT 一覧 + ダッシュボードウィジェット)
- 自動更新: GitHub Releases から [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) v5.7 で配信
- **Gutenberg Block** (`affilicard/product-card`): ブロックエディタから商品カードを挿入、サーバサイドレンダリングで常に最新情報を表示

## Gutenberg Block の使い方

### ブロックの挿入

1. 投稿・固定ページのブロックエディタで「Affilicard 商品カード」ブロックを挿入
2. 「商品を検索」コンボボックスで登録済み商品を検索・選択（`/products?search=` REST API 経由）
3. 右サイドバーの「色設定」パネルでボタン・カードの色を調整（テーマパレット連携）

表示はサーバサイドレンダリング（dynamic block）で行われるため、常に最新の商品情報・在庫状態が反映されます。在庫切れ (`out_of_stock`) や販売終了 (`discontinued`) 時は CTA ボタンが非表示になります。

### 色のカスタマイズ

ブロック属性（InspectorControls）で設定した色は CSS カスタムプロパティとしてブロック要素に付与されます。テーマやカスタム CSS からも以下のプロパティで上書き可能です。

| CSS カスタムプロパティ     | 説明                 |
| -------------------------- | -------------------- |
| `--affilicard-card-bg`     | カード背景色         |
| `--affilicard-card-border` | カード枠線色         |
| `--affilicard-cta-bg`      | CTA ボタン背景色     |
| `--affilicard-cta-text`    | CTA ボタンテキスト色 |

```css
/* テーマの style.css やカスタム CSS で上書きする例 */
.wp-block-affilicard-product-card {
	--affilicard-cta-bg: #e60033;
	--affilicard-cta-text: #ffffff;
}
```

## 計測用 data 属性

商品カードは、タグマネージャや解析ツールから読めるように `data-affilicard-*` 属性を出力します。
プラグイン自体は計測イベントを送信しません（フロント JS を一切読み込みません）。送信は
Google Tag Manager などの外部ツール側で組み立ててください。

### CTA ボタン（`a.affilicard-card__cta`）

| 属性 | 内容 |
| --- | --- |
| `data-affilicard-platform` | プラットフォームコード（例: `rakuten-kobo`） |
| `data-affilicard-product-id` | 商品 CPT の投稿 ID |
| `data-affilicard-product-slug` | 商品 CPT のスラッグ |
| `data-affilicard-product-title` | 商品タイトル |

### カードのルート要素（`.affilicard-card`）

`data-affilicard-product-id` / `data-affilicard-product-slug` / `data-affilicard-product-title` の 3 つ。
`data-affilicard-platform` は出力しません（1 枚のカードに複数プラットフォームが並ぶため単一値にならないため）。

### 注意

- **値が空の項目は属性ごと出力されません。** 空文字の属性が計測側に空パラメータとして届くのを避けるためです
- クリックを拾うセレクタは `a[data-affilicard-platform]`、カードの表示を拾うセレクタは `.affilicard-card` を推奨します
- 将来 CTA の内部に要素が増えてもクリック対象がずれないよう、イベント側では `closest()` で祖先を辿ってください
- **非 ASCII のタイトルから WordPress が自動生成したスラッグはパーセントエンコードされ、非常に長くなります**（和文 20 文字程度で 180 文字前後）。計測基盤にはパラメータ値の長さ制限があることが多く（例: GA4 は 100 文字で無言の切り詰め）、超えた分は復元できません。長さが問題になる場合は商品に明示的な ASCII スラッグを与えてください。**スラッグは REST API が返す値と一致している必要があるため、プラグイン側でデコードして出力することはしません**

## 動作要件

- WordPress 6.8+
- PHP 8.1+
- Node.js 20+ (開発時)

## インストール

1. [Releases](https://github.com/pitolick/affilicard/releases) から最新の zip をダウンロード
2. WP 管理画面の「プラグイン > 新規追加 > プラグインのアップロード」から導入
3. 有効化すると `Affilicard` メニューが追加されます

## REST API

すべてのエンドポイントは `https://example.com/wp-json/affilicard/v1/` 配下。

| メソッド | パス                                | 必要権限           | 説明                                                                    |
| -------- | ----------------------------------- | ------------------ | ----------------------------------------------------------------------- |
| POST     | `/products`                         | `edit_posts`       | 商品作成 (upsert)                                                       |
| POST     | `/products/bulk`                    | `edit_posts`       | 複数商品を一括作成（最大 100 件・部分成功・per-item 結果を 207 で返す） |
| GET      | `/products?search=&per_page=&page=` | `edit_posts`       | 商品検索                                                                |
| GET      | `/products/{id}`                    | `edit_post` (id)   | 商品取得                                                                |
| PATCH    | `/products/{id}`                    | `edit_post` (id)   | 商品更新                                                                |
| DELETE   | `/products/{id}`                    | `delete_post` (id) | 商品削除                                                                |
| GET      | `/settings`                         | `manage_options`   | 一般設定取得                                                            |
| PUT      | `/settings`                         | `manage_options`   | 一般設定更新                                                            |
| GET      | `/platforms`                        | `manage_options`   | プラットフォーム一覧取得                                                |
| PUT      | `/platforms`                        | `manage_options`   | プラットフォーム一括更新                                                |
| GET      | `/accounts/{code}/credentials`      | `manage_options`   | アカウント単位の認証情報取得 (マスク)                                    |
| PUT      | `/accounts/{code}/credentials`      | `manage_options`   | アカウント単位の認証情報保存                                             |
| DELETE   | `/accounts/{code}/credentials`      | `manage_options`   | アカウント単位の認証情報削除                                             |
| POST     | `/providers/{code}/test-connection` | `manage_options`   | provider 単位の接続テスト（保存前の入力値でテスト）                       |

### 認証

WordPress Application Passwords を推奨。

```bash
curl -u 'username:xxxx xxxx xxxx xxxx xxxx xxxx' \
     -X POST https://example.com/wp-json/affilicard/v1/products \
     -H 'Content-Type: application/json' \
     -d '{
       "title": "サンプル商品",
       "product_type": "ebook",
       "stock_status": "available",
       "extras": [
         { "key": "author",    "label": "著者",   "value": "山田太郎" },
         { "key": "publisher", "label": "出版社", "value": "サンプル社" }
       ],
       "listings": [
         {
           "platform": "dmm-books",
           "enabled": true,
           "auto_update": true,
           "external_id": "56869",
           "regular_url":   "https://book.dmm.com/product/56869/",
           "affiliate_url": "https://al.dmm.com/?lurl=...",
           "price": "600",
           "list_price": "1000",
           "badge": "40%OFF"
         }
       ]
     }'
```

`auto_update`（既定 `true`）は **listing 単位の自動更新スイッチ**で、全プラットフォーム共通。`false` にすると定期実行の価格更新から外れる（同期の負荷を抑えたい listing に使う）。設定画面の「強制一括更新」は `false` の listing も更新する。自動取得そのものの可否はプラットフォームの Provider 側で決まるため、Provider が手動入力のプラットフォームでは `true` でも取得されない。

なお `update_mode`（既定 `auto`）は v3.2.x 以前の互換フィールドで、保存済みの値も判定に使われる。`manual` の listing は `auto_update` が `true` でも自動更新されない（`api` は `auto` の旧表記として扱う）。商品編集画面で「自動更新」トグルを操作すると `auto` へ正規化される。

### エラー応答（商品の作成・更新）

保存した内容が入らなかったときだけ、専用のコードを返す。

| HTTP | `code`                      | 意味                                                             | 呼び出し側の対応                       |
| ---- | --------------------------- | ---------------------------------------------------------------- | -------------------------------------- |
| 409  | `affilicard_listing_locked` | 同じ商品を別の処理が更新中で、購入リンクを**書かずに見送った**    | そのままやり直せば通る（待ち時間 10 秒）|
| 500  | `affilicard_save_failed`    | 書いたのに**入らなかった**フィールドがある、または保存自体に失敗した | 原因を取り除くまで直らない（下記）    |

```json
{
	"code": "affilicard_listing_locked",
	"message": "ほかの処理がこの商品の購入リンクを更新中のため、…",
	"id": 123,
	"unsaved_fields": [ "listings" ]
}
```

**`unsaved_fields` は「入らなかったと分かっているフィールド」である。** 本プラグインは
投稿行（`title` / `content` / `status`）→ 購入リンク以外のメタ → `listings` の順に書き、
**書いたメタは読み直して確かめる**（`update_post_meta()` の戻り値は「入ったかどうか」を
表さない——ほかのプラグインが `update_post_metadata` フィルタで短絡すると、1 バイトも
書かずに成功を返す）。**409 / 500 が返る時点で要求の大半は既に保存されている**ので、
やり直しは全項目の送り直しではなく、**ここに載ったフィールドだけを送れば足りる**
（全項目を送り直すと、同時に入った別経路の編集を巻き戻す）。分岐はフィールド名で
行うこと——`message` の文言は翻訳で変わる。

値に出てくるフィールド名は `product_type` / `stock_status` / `extras` / `release_date` /
`listings` のいずれか。

**ただし「ここに載らない＝保存された」とは読めない。** 読み直して確かめているのは
**要求が実際に運んでくるメタ**（上記 5 つ）だけで、`mask_blur` / `mask_r18` /
`mask_label` / `schema_version` と投稿行の `title` / `content` / `status` は
確かめていない。前者はこの API から設定できず（書かれるのは既存値か既定値なので、
潰されても要求は失われない）、後者は投稿 API の戻り値しか見ていない。
この配列は**確実に送り直すべきものの一覧**であって、保存された範囲の裏返しではない。

**`unsaved_fields` が無い 500 は「1 つも保存されていない」**。投稿行そのものを作れなかった
（作成）／更新できなかった（更新）ときで、商品は元のままである。`id` の有無が「商品ができたか
否か」を表すのと同じように、`unsaved_fields` の有無が「部分保存かどうか」を表す。

**`id` は「サーバが実在を知っている商品」の投稿 ID である。** 作成（`POST /products`）でこれらが返るとき、**商品そのものは既に作られている**——本プラグインは投稿行を作ってから購入リンクを書くため——ので、`id` を無視して `POST` をやり直すと**同じ商品が増える**。やり直しは `PATCH /products/{id}` で行うこと。

**`id` が無い 500 は 1 つだけ**——作成で投稿行そのものを作れなかったときで、このときは商品が 1 件も無い。つまり **`id` の有無がそのまま「`PATCH` で直せるか／`POST` し直すか」を表す**。更新（`PATCH /products/{id}`）は商品が実在するので常に `id` を返す（呼び出し側は既に知っているが、応答の形をメソッドで変えないため）。

`500` の原因は商品のメタ保存に介入する別プラグイン、壊れた meta 行、DB の書き込み失敗など。時間を置いても解消しないため、自動リトライの対象にしないこと。

### 一括作成（bulk）

複数商品を 1 リクエストで投入する（**1 リクエスト最大 100 件**）。1 件の検証エラーは全体を止めず、各アイテムの結果を返す（部分成功）。上限超過時は何も保存せず `400`（`affilicard_bulk_too_many`）を返す。

```bash
curl -u 'username:xxxx xxxx xxxx xxxx xxxx xxxx' \
     -X POST https://example.com/wp-json/affilicard/v1/products/bulk \
     -H 'Content-Type: application/json' \
     -d '{
       "products": [
         { "title": "作品A", "product_type": "vod",
           "extras": [{ "key": "director", "label": "監督", "value": "山田太郎" }],
           "listings": [{ "platform": "u-next", "affiliate_url": "https://example.com/a" }] },
         { "title": "作品B", "product_type": "ebook" }
       ]
     }'
```

レスポンス（HTTP 207 Multi-Status）:

```json
{
	"results": [
		{ "index": 0, "status": "created", "id": 123 },
		{ "index": 1, "status": "created", "id": 124 }
	],
	"created": 2,
	"failed": 0
}
```

失敗したアイテムは `{ "index": N, "status": "error", "message": "..." }` を返す。保存した内容が入らなかった場合は `code`（`affilicard_listing_locked` / `affilicard_save_failed`）と、**作成されてしまった商品の `id`**、**入らなかったフィールドの `unsaved_fields`** が加わる（意味は上の「エラー応答（商品の作成・更新）」と同じ——item ごとの報告でも形は変えない）。

```json
{
	"index": 0,
	"status": "error",
	"code": "affilicard_listing_locked",
	"message": "ほかの処理がこの商品の購入リンクを更新中のため、…",
	"id": 123,
	"unsaved_fields": [ "listings" ]
}
```

この `id` を無視してアイテムを積み直すと、同じ商品が 1 回ごとに増える。積み直しではなく `PATCH /products/{id}` に、`unsaved_fields` が名指ししたフィールドだけを載せて続きをやり直すこと。

### Hybrid extras 形式

```json
[
	{ "key": "author", "label": "著者", "value": "..." },
	{ "key": "publisher", "label": "出版社", "value": "..." },
	{ "label": "ラベル", "value": "値" }
]
```

- `key` を含む行は ProductType の `extrasSchema()` 由来 (electronic book なら `author/publisher/isbn`)
- `key` なしの行はカスタム追加
- 未知の `key` が来た場合は無視 (label/value のみ保持)

## 商品タイプ・プラットフォーム拡張

### 新しい ProductType を追加

1. `src/Types/MyType.php` で `Affilicard\Types\AbstractProductType` を継承
2. `code()` / `label()` / `extrasSchema()` を実装（最低限この 3 つ）
3. `extrasSchema()` の各フィールドに `card` 区分を付与してカード表示を制御:
    - `'card' => 'header'`: 書誌ヘッダ（タイトル下）に昇格
    - `'card' => 'detail'`（既定）: カード詳細部に表示
    - `'card' => 'hidden'`: カード非表示（メタ情報のみ）
4. 画像プレースホルダ文言を変えるなら `cardMediaLabel()` を override
5. 外部 API 連携があれば `extractExtrasFromProvider()` を実装（手動入力のみなら `return array();`）
6. `Plugin::buildProductTypeRegistry()` に `$registry->register(new MyType())` を追加

実装例は `src/Types/EbookType.php`（API provider 連携あり）と `src/Types/VodType.php`（手動入力のみ・監督/出演を header に昇格）を参照。VOD のように provider 連携が無い場合は `extractExtrasFromProvider()` を空配列で返すだけでよい。新しいプラットフォームを使う場合は `PlatformConfig::defaults()` に `applicableTypes` を新タイプの code にした `PlatformDefinition` を追加する（`src/Platform/PlatformConfig.php` の VOD platform 群を参照）。

### Account / Provider を追加する

1. `src/Account/<Name>Account.php` に `AccountInterface`（`code`/`label`/`credentialsSchema`）を実装し、
   `Plugin::buildAccountRegistry()` に register する。
2. `src/Provider/<Name>/<Name>Provider.php` に `ProviderInterface`（`code`/`label`/`isAutomatic`/
   `accountCode`/`fetch`/`testConnection`）を実装し、`Plugin::buildProviderRegistry()` に register する。

設定画面のアカウント認証フィールド・provider ドロップダウンは、`AccountUiList`/`ProviderUiList` →
`wp_add_inline_script` → `accounts.js`/`providers.js` で **自動生成**される（管理画面 JS の改修は不要）。

## 開発

```bash
composer install
npm install --legacy-peer-deps

# テスト
composer test          # PHPUnit 9.6 + WP_Mock
npm run test:js        # Jest (wp-scripts)

# Lint
composer lint
npm run lint:js

# ビルド (build/ 配下に bundle 出力)
npm run build
```

WP Playground でのプレビューは PR ごとに自動で払い出されます。PR で `composer install` + `npm run build` を実行してビルド済みプラグイン zip を生成し (`.github/workflows/pr-preview-build.yml`)、その zip を Playground にインストールする Preview ボタンを PR に投稿します (`.github/workflows/pr-preview-publish.yml`)。これにより React/Block を含むビルド成果物も Playground 上で動作確認できます（`build/` は git 管理せず CI でビルド）。

## ライセンス

MIT
