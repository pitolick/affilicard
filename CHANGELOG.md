# Changelog

本プロジェクトの主な変更点を記録します。バージョニングは [Semantic Versioning](https://semver.org/lang/ja/) に準拠します。

## [Unreleased]

## [0.2.0] - 2026-06-03

### Added

- Gutenberg ブロック `affilicard/product-card`（React 編集 UI + サーバサイド render）
- 純粋・商品タイプ非依存のレンダラ `CardRenderer`（`--affilicard-*` CSS 変数によるテーマカラー連携、在庫切れ/取扱終了時の CTA 抑制、`affiliate_url ?? regular_url` フォールバック、`sanitize_hex_color` による色値検証）
- ブロック編集 UI：商品検索 ComboboxControl + InspectorControls 色設定パネル
- `ProductRepository::findBySlug()`
- 公開フロントでは公開ステータスの商品のみ描画するガード
- CI: リリース時に Git タグからバージョンを `affilicard.php` へ自動注入
- CI: PR ごとにビルド済みプラグインを WordPress Playground でプレビュー（`build/` を git 管理せず CI でビルド）
- CI: wp-env + Playwright による E2E テスト（ブロックのフロント描画＝CTA リンク・色 CSS 変数・在庫切れ時の CTA 抑制を検証）

### Fixed

- メタボックス保存: 商品の PATCH を真の部分更新にし、`title` 必須による 400 エラーと、未送信フィールド（タイトル等）の空文字上書きを修正
- メタボックス: 投稿の「公開／更新」で商品設定も保存されるようにし（独立保存ボタンを廃止）、Publish のみだと metabox データが欠落する問題を解消

## [0.1.0] - 2026-05-29

### Added

- 汎用 CPT `affilicard_product`、Settings（React）、Provider（Manual / DMM）、ProductType（Generic / Ebook）、REST API、在庫ステータス、Fallback 可視化
- WP 公式ディレクトリ非経由の自動更新（plugin-update-checker）と GitHub Release 自動生成 CI
