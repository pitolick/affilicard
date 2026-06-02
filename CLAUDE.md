# CLAUDE.md — affilicard

## プロジェクト概要

**汎用アフィリエイト商品カード WordPress プラグイン**。複数のアフィリエイト商品ジャンル（電子書籍・VOD・家電・雑貨など）に対応する拡張可能設計。

- 単独で動作する WordPress プラグイン（複数サイトから利用可能）
- ジャンル固有の処理は `src/Types/` に PHP クラスを追加する拡張パターン
- 外部 API 連携は `src/Provider/` に PHP クラスを追加する拡張パターン
- サイト固有の処理は含めない（汎用性を維持する）

---

## アーキテクチャ (v4)

| 名前空間 / クラス | 役割 |
| --- | --- |
| `Affilicard\Plugin` | プラグインのブートストラップ。CPT + REST + Admin の配線、`onActivate` で seed |
| `Affilicard\PostType\ProductPostType` | CPT `affilicard_product` の登録、`META_*` 定数、`externalIdMetaKey()` |
| `Affilicard\PostType\ProductMetaBox` | 商品編集画面の React metabox 登録 + script enqueue |
| `Affilicard\PostType\ProductListColumns` | CPT 一覧の Fallback カスタム列 |
| `Affilicard\Repository\ProductRepository` | CRUD + extid mirror + `countFallbackProducts()` |
| `Affilicard\Platform\PlatformDefinition` | 1 プラットフォームの設定値オブジェクト |
| `Affilicard\Platform\PlatformConfig` | `affilicard_platforms` option の get/save + `defaults()` |
| `Affilicard\Types\ProductTypeInterface` | 商品タイプの拡張ポイント |
| `Affilicard\Types\AbstractProductType` | Hybrid `validateExtras` の共通実装 |
| `Affilicard\Types\GenericType` / `EbookType` | 汎用 / 電子書籍タイプ |
| `Affilicard\Types\ProductTypeRegistry` | 商品タイプのレジストリ |
| `Affilicard\Provider\ProviderInterface` | Provider の拡張ポイント (`credentialsSchema` + `testConnection` 含む) |
| `Affilicard\Provider\ProviderRegistry` | Provider のレジストリ |
| `Affilicard\Provider\ProviderCredentials` | AES-256-CBC で認証情報を暗号化保存 (`patch` 的更新) |
| `Affilicard\Provider\ManualProvider` | 手動入力（API 不要） |
| `Affilicard\Provider\Dmm\DmmProvider` | DMM Web Service API v3 |
| `Affilicard\Rest\RestController` | `affilicard/v1` 名前空間のルート集約 |
| `Affilicard\Rest\ProductSchema` | Product CRUD の REST schema + sanitize |
| `Affilicard\Rest\ProductsController` | `/products` CRUD + search |
| `Affilicard\Rest\SettingsController` | `/settings` GET/PUT |
| `Affilicard\Rest\PlatformsController` | `/platforms` GET/PUT |
| `Affilicard\Rest\CredentialsController` | `/platforms/{code}/credentials` + `/test-connection` |
| `Affilicard\Settings\GeneralSettings` | `affilicard_general` option ハンドリング |
| `Affilicard\Settings\PlatformsSettings` | `PlatformConfig` の REST wrapper |
| `Affilicard\Settings\DashboardWidget` | WP ダッシュボードに Fallback 件数表示 |
| `Affilicard\Block\Block` | Gutenberg block `affilicard/product-card` の登録 + サーバサイド render（商品解決 → 色 CSS 変数注入 → CardRenderer 委譲） |
| `Affilicard\Renderer\CardRenderer` | 商品データ + PlatformDefinition から商品カード HTML を生成する純粋レンダラ（type 非依存・色は sanitize_hex_color 経由） |
| `Affilicard\Stock\StockStatus` | `available` / `out_of_stock` / `discontinued` |
| `Affilicard\Schema\SchemaVersion` | schema migration トリガ用バージョン番号 (現在 `'1'`) |
| `Affilicard\Util\Crypto` | AES-256-CBC ラッパ |
| `Affilicard\Util\JsonField` | 防御的 JSON encode/decode |
| `Affilicard\Uninstall` | 全 `affilicard_*` option + CPT 削除 (uninstall.php から呼出) |
| `src/Admin/` (React) | Settings + Metabox の React UI (`@wordpress/scripts`) |
| `src/Block/` (React) | `affilicard/product-card` block 登録 + サーバ render（`index.js` / `edit.jsx` / `block.json`） |

---

## ディレクトリ構成

```text
affilicard/
├── affilicard.php             # プラグインエントリ + plugin-update-checker
├── uninstall.php              # アンインストール時の全削除
├── composer.json
├── package.json
├── phpcs.xml.dist
├── phpunit.xml.dist
├── .php-cs-fixer.dist.php
├── .github/
│   └── workflows/             # ci / pr-preview-build / pr-preview-publish / release
├── src/
│   ├── Plugin.php
│   ├── Admin/
│   │   ├── settings.js        # Settings ページの React エントリ
│   │   ├── metabox.js         # 商品編集 metabox の React エントリ
│   │   ├── api/               # @wordpress/api-fetch ラッパ
│   │   └── components/        # React コンポーネント
│   ├── Block/                 # affilicard/product-card block 登録 + サーバ render（index.js / edit.jsx / block.json）
│   │   ├── index.js           # block 登録エントリ
│   │   ├── edit.jsx           # エディタ UI（ComboboxControl + InspectorControls）
│   │   ├── block.json         # block メタデータ・属性定義
│   │   └── Block.php          # PHP: block 登録 + render_callback
│   ├── Renderer/
│   │   └── CardRenderer.php   # 商品カード HTML 生成（type 非依存・純粋レンダラ）
│   ├── PostType/
│   ├── Platform/
│   ├── Provider/
│   ├── Types/
│   ├── Repository/
│   ├── Rest/
│   ├── Settings/
│   ├── Stock/
│   ├── Schema/
│   ├── Util/
│   └── Uninstall.php
├── assets/
│   ├── card.css               # フロント + 共有 CSS 変数（--affilicard-* カスタムプロパティ）
│   └── block-editor.css       # エディタプレビュー用スタイル
├── tests/
│   ├── bootstrap.php          # WP_Mock + WP_Error/WP_REST_* stub
│   ├── Unit/                  # PHPUnit (WP_Mock)
│   └── js/                    # Jest + @testing-library/react
└── vendor/                    # gitignore 対象
```

---

## 重要な設計制約

### Block-first (Shortcode は実装しない)

外部から商品カードを挿入する場合は **REST 経由で商品を upsert し、Gutenberg Block 形式でコンテンツに埋め込む** 方針。Shortcode は実装しない（旧設計から変更）。

### REST API 二段階 capability

- Product CRUD は `edit_posts` (CPT `capability_type='post' + map_meta_cap=true`) で自分の投稿のみ判定
- Settings / Platforms / Credentials は `manage_options`

### Hybrid extras 形式

`[{key?, label, value}]` 配列。schema 由来は `key` 付き、カスタム追加は `key` なし。

### URL フォールバック

全タイプ共通で `affiliate_url ?? regular_url`。両方空なら CTA 非表示。発生時は admin 可視化（CPT 一覧 + ダッシュボードウィジェット）。

### Credentials 部分更新 (PATCH 的)

`ProviderCredentials::patch()` は未指定フィールドを保持。`null` で skip、空文字で明示クリア。

### 自動更新

WP 公式ディレクトリは経由せず、`yahnis-elsts/plugin-update-checker ^5.7` で GitHub Releases から取得。v5.7 の `allowAutoupdateField()` で WP 自動更新 ON 対応。

---

## 技術スタック

| 項目 | 採用技術 |
| --- | --- |
| 言語 | PHP 8.1+, JavaScript (React 18) |
| WordPress | 6.8+ |
| テスト (PHP) | PHPUnit 9.6 + WP_Mock 1.x |
| テスト (JS) | Jest (`@wordpress/scripts` 経由) + `@testing-library/react` |
| Lint (PHP) | PHP_CodeSniffer (WordPress Coding Standards) |
| Lint (JS) | ESLint (`wp-scripts lint-js`) |
| フォーマット | PHP CS Fixer 3 / `wp-scripts format` |
| ビルド | `@wordpress/scripts ^32.3.0` (webpack + Babel) |
| 認証情報暗号化 | AES-256-CBC (`openssl_*`) + `wp_salt('auth')` 派生鍵 |
| 自動更新 | `yahnis-elsts/plugin-update-checker ^5.7` |

---

## 開発ルール

- コミットメッセージ・PR・Issue はすべて日本語で記述する
- Conventional Commits prefix (`feat:` / `fix:` / `chore:` / `test:` / `refactor:` / `style:` / `ci:` / `docs:`)
- 新機能・バグ修正には必ず `tests/Unit/` (PHP) または `tests/js/` (React) にユニットテストを追加する
- 外部 API (DMM / Amazon / 楽天 / WP REST 等) はすべてモックする
- 特定サイト固有のコードを混入させない（汎用性を損なう変更は却下）

### ローカル PHP 実行

ローカル Mac には PHP/Composer を入れず、Docker イメージで実行する:

```bash
docker run --rm -v "$(pwd):/app" -w /app composer:2 composer install
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit
```

### vendor/ なし環境のフォールバック

`affilicard.php` は `vendor/autoload.php` 不在時に `spl_autoload_register` で簡易 PSR-4 autoloader を登録する（vendor/ を持たないソース直置き環境向けの保険）。自動更新機能 (plugin-update-checker) のみ無効化される。なお PR の Playground プレビューは `composer install` + `npm run build` 済みの zip を使うため、プレビューではこのフォールバックには依存しない。

---

## 計画書の場所

設計の全体像は利用側プロジェクト（`pitolick/e-comi`）の `docs/superpowers/plans/2026-05-28-phase4a-affilicard-mvp.md` (v4) を参照する。
