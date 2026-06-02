# affilicard

汎用アフィリエイト商品カードの WordPress プラグイン。電子書籍 / VOD / 家電など複数の商品ジャンルに対応する拡張可能設計。

## 主な機能 (v0.1.0)

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

| CSS カスタムプロパティ | 説明 |
| --- | --- |
| `--affilicard-card-bg` | カード背景色 |
| `--affilicard-card-border` | カード枠線色 |
| `--affilicard-cta-bg` | CTA ボタン背景色 |
| `--affilicard-cta-text` | CTA ボタンテキスト色 |

```css
/* テーマの style.css やカスタム CSS で上書きする例 */
.wp-block-affilicard-product-card {
  --affilicard-cta-bg: #e60033;
  --affilicard-cta-text: #ffffff;
}
```

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

| メソッド | パス | 必要権限 | 説明 |
| --- | --- | --- | --- |
| POST | `/products` | `edit_posts` | 商品作成 (upsert) |
| GET | `/products?search=&per_page=&page=` | `edit_posts` | 商品検索 |
| GET | `/products/{id}` | `edit_post` (id) | 商品取得 |
| PATCH | `/products/{id}` | `edit_post` (id) | 商品更新 |
| DELETE | `/products/{id}` | `delete_post` (id) | 商品削除 |
| GET | `/settings` | `manage_options` | 一般設定取得 |
| PUT | `/settings` | `manage_options` | 一般設定更新 |
| GET | `/platforms` | `manage_options` | プラットフォーム一覧取得 |
| PUT | `/platforms` | `manage_options` | プラットフォーム一括更新 |
| GET | `/platforms/{code}/credentials` | `manage_options` | 認証情報取得 (マスク) |
| PUT | `/platforms/{code}/credentials` | `manage_options` | 認証情報部分更新 |
| POST | `/platforms/{code}/test-connection` | `manage_options` | 接続テスト |

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
           "update_mode": "manual",
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

### Hybrid extras 形式

```json
[
  { "key": "author",    "label": "著者",   "value": "..." },
  { "key": "publisher", "label": "出版社", "value": "..." },
  { "label": "ラベル",  "value": "値" }
]
```

- `key` を含む行は ProductType の `extrasSchema()` 由来 (electronic book なら `author/publisher/isbn`)
- `key` なしの行はカスタム追加
- 未知の `key` が来た場合は無視 (label/value のみ保持)

## 商品タイプ・プラットフォーム拡張

### 新しい ProductType を追加

1. `src/Types/MyType.php` で `Affilicard\Types\AbstractProductType` を継承
2. `code()` / `label()` / `extrasSchema()` / `extractExtrasFromProvider()` を実装
3. `Plugin::buildProductTypeRegistry()` に `$registry->register(new MyType())` を追加

### 新しい Provider を追加

1. `src/Provider/My/MyProvider.php` で `ProviderInterface` を実装
2. `credentialsSchema()` で認証情報フィールドを宣言 (admin UI が自動生成)
3. `testConnection()` で疎通確認ロジックを書く
4. `Plugin::buildProviderRegistry()` に登録

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
