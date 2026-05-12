# CLAUDE.md — pitolick/affilicard

## プロジェクト概要

「ええこみ！」ブログ（`e-comi.pitolick.com`）で使用する **汎用アフィリエイト商品カード WordPress プラグイン**。

- **ecomi リポジトリのサブモジュール**として `plugins/affilicard/` に配置される
- 漫画に限らず VOD・家電・雑貨など将来の商品ジャンルへの転用を前提とした設計
- ええこみ固有の処理は含めない。ジャンル固有の処理は `Types/` に追加する

Issue の起票・Claude Code GitHub Actions の起動は **`pitolick/ecomi` リポジトリで行う**。
このリポジトリへの直接 PR は手動モード時に発生する。

---

## このリポジトリの責務

| クラス | 役割 |
|--------|------|
| `src/Core/ProductCardBase.php` | カスタム投稿タイプ・REST API・Gutenberg ブロック基盤 |
| `src/Core/PriceManager.php` | 価格更新・タイムスタンプ管理（WP-Cron 週1回） |
| `src/Core/LinkChecker.php` | アフィリエイトリンク確認（WP-Cron 週1回）・予約投稿昇格 |
| `src/Core/AffiliateButtons.php` | ボタン描画（商品タイプ別に動的切り替え） |
| `src/Settings/AmazonSettings.php` | Amazon PA API キーを WP 管理画面で設定 |
| `src/Settings/DmmSettings.php` | DMM API キー・アフィリエイト ID を WP 管理画面で設定 |
| `src/Settings/RakutenSettings.php` | 楽天アプリ ID・アフィリエイト ID を WP 管理画面で設定 |
| `src/Settings/LinkCheckerSettings.php` | リンク確認後の自動予約投稿昇格 ON/OFF トグル |
| `src/Types/ProductTypeInterface.php` | 全商品タイプが実装する PHP インターフェース |
| `src/Types/MangaType.php` | 漫画固有フィールド・ボタン定義 |
| `src/Blocks/product-card/` | Gutenberg ブロック（管理画面ビジュアル編集用） |

---

## ディレクトリ構成

```
affilicard/
├── CLAUDE.md
├── composer.json
├── grumphp.yml
├── phpcs.xml
├── .php-cs-fixer.php
├── phpunit.xml
├── .coderabbit.yaml
├── .env.example
├── .github/
│   ├── workflows/
│   │   ├── ci.yml
│   │   └── auto-merge.yml
│   ├── dependabot.yml
│   └── pull_request_template.md
├── src/
│   ├── Core/
│   ├── Settings/
│   ├── Types/
│   │   ├── ProductTypeInterface.php
│   │   └── MangaType.php
│   └── Blocks/
│       └── product-card/
├── tests/
│   └── Unit/
├── vendor/                   # gitignore 対象
└── affilicard.php
```

---

## 重要な設計制約

### PHP インターフェース型の拡張パターン

新ジャンルの追加は `src/Types/` に PHP クラスを 1 ファイル追加するだけで完結する設計を維持すること。

```php
// ProductTypeInterface.php が定義するメソッドを実装する
interface ProductTypeInterface {
    public function getFields(): array;
    public function getButtons(int $productId): array;
    public function getButtonLayout(): string; // 'grid' | 'stack'
}
```

### 商品カード UI 仕様

- 価格が `null` の場合は該当プラットフォームの行ごと非表示（`―` 表示は禁止）
- 価格とリンクボタンは分離して表示する
- ボタンのレイアウトは CSS Grid / Flexbox でレスポンシブに対応する
- プラットフォームの自動ソートは行わない（登録順を維持）

### REST API エンドポイント

```
POST  /wp-json/affilicard/v1/products          商品登録
PATCH /wp-json/affilicard/v1/products/{id}/prices  価格更新
GET   /wp-json/affilicard/v1/products/{id}     商品取得
```

### AI での記事挿入

AI が記事中に商品カードを挿入する際はショートコードを使う（Gutenberg ブロックは人間の手動編集用）。

```
[affilicard id="123"]
```

### API キー管理

API キーはすべて WordPress 管理画面から設定し、`update_option()` で AES-256-CBC 暗号化して DB に保存する。`.env` ファイルはローカル開発時のみ使用する。

### Amazon 価格取得フェーズ

- **Phase 1（現在）**: Amazon PA API が未解放のため、価格はスクレイピングで取得
- **Phase 2（PA API 解放後）**: PA API に切り替え（エンドポイント・フィールド構造は変更しない設計で実装すること）

---

## 技術スタック

| 項目 | 採用技術 |
|------|---------|
| 言語 | PHP 8.1 |
| テスト | PHPUnit + WP_Mock |
| Lint | PHP_CodeSniffer（WordPress Coding Standards） |
| フォーマット | PHP CS Fixer |
| Git フック | GrumPHP |

---

## 開発ルール

- コミットメッセージ・PR・Issue はすべて日本語で記述する
- 新機能・バグ修正には必ず `tests/Unit/` にユニットテストを追加する
- ええこみ固有のコードを混入させない（汎用性を損なう変更は却下）

### コミットメッセージ形式

```
feat: 〇〇機能を追加
fix: 〇〇のバグを修正
chore: ライブラリを更新
test: テストを追加・修正
refactor: 〇〇をリファクタリング
style: フォーマット修正
```

---

## 仕様書の場所

| ドキュメント | 参照すべきセクション |
|------------|------------------|
| Cowork: `docs/01_企画・要件定義.md` | §4-4（商品カードプラグイン仕様・UI 仕様・フェーズ定義） |
| Cowork: `docs/02_プラグイン開発・運用手順書.md` | §6（テスト）・§7（lint）・§5（環境変数管理） |

---

## 関連リポジトリ

| リポジトリ | 関係 |
|-----------|------|
| `pitolick/ecomi` | 親リポジトリ（このリポジトリをサブモジュールとして管理・Issue はここに起票） |
| `pitolick/ai-article-poster` | `[affilicard id="xxx"]` ショートコードを記事に埋め込む |
