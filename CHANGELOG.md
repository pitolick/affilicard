# Changelog

本プロジェクトの主な変更点を記録します。バージョニングは [Semantic Versioning](https://semver.org/lang/ja/) に準拠します。

## [Unreleased]

## [2.0.0] - 2026-07-14

### Changed (BREAKING)

- 認証情報の保存単位を provider 単位から **account 単位**（`affilicard_account_<code>_credentials`）へ変更。`ProviderInterface` から `credentialsSchema()` を撤去し `accountCode()` を追加、スキーマは `AccountInterface` へ移設。
- 認証 REST を再構成: credentials は `/accounts/{code}/credentials`（GET/PUT/DELETE）、接続テストは `/providers/{code}/test-connection`（POST・保存前テスト）。旧 platform/provider credentials ルートは撤去。
- 設定画面の認証フィールドを **write-only ＋ dirty 追跡** 化（未編集の秘匿値を再送しない）。required をサーバ検証。認証パネルを account 単位の折り畳み＋provider 単位の接続テストへ刷新。
- Provider スキーマを PHP（`AccountUiList`/`ProviderUiList`）から `window.affilicardAccounts`/`window.affilicardProviders` として注入し、JS のハードコードを廃止。
- 楽天 API transport を `RakutenClient` に分離。

### Notes

- 未公開のため移行は行わない。旧 `affilicard_provider_*` credentials はアップグレード時に削除される。

## [1.9.0] - 2026-07-13

### Added

- 楽天Kobo 電子書籍検索 API を使った自動取得 Provider（`RakutenProvider`）を追加。価格・書影・作品URL・アフィリエイトURL・配信日を取得する。2026 年の楽天 API 刷新（`openapi.rakuten.co.jp`・`accessKey` ヘッダ・`Origin` 必須）に対応。

## [1.8.1] - 2026-07-12

### Changed

- 画像なしプレースホルダの表示ラベルを商品タイプ名（書影/商品画像/キービジュアル）から中立の「画像がありません」に変更（タイプ名の表示は「読み込み失敗」に見えるため）。タイプ名はスクリーンリーダー向けの aria-label（「〜がありません」）として保持。

## [1.8.0] - 2026-07-12

### Added

- 商品カードのメディア枠を product_type ごとのアスペクト比で固定（電子書籍 2:3／汎用・動画配信 1:1・実測ベース）。実画像は `object-fit: contain` で枠内に収め、比率の異なる画像でもレイアウトが崩れない。
- 画像が無いときのプレースホルダを、汎用アイコン＋商品タイプ別ラベル（商品画像／書影／キービジュアル）で意匠化。

### Changed

- メディア枠のアスペクト比固定に伴い、商品カードの書影マークアップを更新（マスクなし画像の従来マークアップから変更）。
- `ProductTypeInterface` に `cardMediaAspectRatio()` を追加。`AbstractProductType` を継承する型（本プラグインの全既定型）は既定値 `1 / 1` を自動で得るため影響なし。`ProductTypeInterface` を直接実装する外部型がある場合は同メソッド（`string` を返す）の追加が必要。

## [1.7.0] - 2026-07-07

### Added

- 商品カードの表紙マスク機能（ぼかし／R18 18+ バッジ＋ぼかし強制／任意ラベル）。商品 meta とブロック属性の両方で設定でき、ブロック属性を優先・未設定は商品 meta を継承する。マスクは時刻非依存で予約表示とは独立。

## [1.6.1] - 2026-06-30

### Fixed

- 予約バッジ（「予約受付中」）と発売日ラベルを横並び（flex）表示に変更。従来は発売日が `<div>` でバッジの下行に折れ返っていたが、`affilicard-card__preorder` flex コンテナでまとめ、発売日を `<span>` に変更することで同一行に横並び表示されるよう修正。
- DMMブックスの CTA ラベルを「この値段で読む →」から「DMMブックスで読む」に統一し、他プラットフォーム（Kindleで読む / 楽天Koboで読む / BookWalkerで読む）と表記を揃えた。

## [1.6.0] - 2026-06-29

### Added

- 商品カードに**予約（発売前）状態**を追加。商品に発売日 `release_date`（`YYYY-MM-DD`）を持たせると、カード描画時に `now < release_date` の場合は「予約受付中」バッジ＋発売日表示＋CTA「予約する」で描画し、**発売日を過ぎると自動的に通常表示へ戻る**（再取得・Cron 不要）。在庫バッジとは別系統で、予約中も CTA は隠さない。CTA ラベルの優先順は block override > listing override > 予約既定「予約する」> platform 既定。
- `release_date` を商品メタ `affilicard_release_date` として永続化（REST CRUD `ProductSchema` ＋ `register_post_meta`／`YYYY-MM-DD` のみ許可）。商品編集 metabox に発売日の日付コントロールを追加。
- 発売日由来の予約判定を行う純粋ヘルパ `Affilicard\Stock\ReleaseDate`（時刻は引数で受け取りテスト可能）。時刻依存は `CardHtmlBuilder` に閉じ、`CardRenderer` は純粋レンダラを維持。

## [1.5.0] - 2026-06-28

### Added

- 商品カードに `onlyPlatforms`（表示プラットフォーム許可リスト）属性を追加。指定した platform の listing のみ描画する（既存 `hidePlatforms` と併用可、未指定なら全表示）。ブロックエディタに選択 UI を追加し、**エディタプレビューにも `onlyPlatforms` を反映**（card-preview REST 経由）。

### Fixed

- 監査で確認した既存バグを修正（#57）:
  - `Uninstall` が現行オプション（`affilicard_platforms` / `affilicard_general` / `affilicard_seeded_at`）と provider credentials を削除せずデータ残留・再インストール seed 不発になる問題を修正。
  - REST の商品 create/update が `publish_posts` を検証せず公開できた問題を修正（公開権限が無ければ `pending` に降格）。
  - `Block` の autoCreate が失敗時に transient ロックを解放せず 5 分間リトライ不能になる問題を修正。
  - extid mirror の再書き込みで stale な `affilicard_extid_*` meta が残り誤 upsert を招く問題を修正。
- 日時フッターが非表示プラットフォーム（`onlyPlatforms`/`hidePlatforms`/URL 無し）の `last_fetched_at` を参照していた不整合を修正し、表示中の listing 集合に揃えた。

## [1.4.1] - 2026-06-21

### Fixed

- 管理画面からの自動更新（plugin-update-checker）が検知されない不具合を修正。PUC はタグ名ではなく**タグにコミットされた `affilicard.php` の `Version:` ヘッダ**を最新版数として採用するため、ソースを `0.1.0` のままビルド時のみ注入する運用では常に「最新は 0.1.0」と誤判定されていた。`affilicard.php` / `package.json` の版数をコミット値として同期し、release ワークフローに「コミット済み版数＝タグ」検証ガードを追加して再発を防止
- 機能内容は v1.4.0 と同一（list_price 取り消し線表示・カードのレスポンシブ重なり/右はみ出し修正）

## [1.4.0] - 2026-06-21

### Added

- 商品カードの価格エリアに通常価格（`list_price`）の取り消し線表示を追加。`list_price` と `price` が共に正の数値で `list_price > price` のとき、`price` の前に取り消し線で通常価格を描画する（割引バッジと併存可）

### Fixed

- 商品カードのレスポンシブ切替をコンテナクエリ化（`container-type` + `@container`）し、サイドバー等でカードが狭いコンテナに入った場合に価格とボタンが重なる・カード右端からはみ出す不具合を修正（判定基準を viewport からカード自身の幅へ）

## [1.3.0] - 2026-06-20

### Added

- 投稿ブロックエディタに公開と同じカードの WYSIWYG プレビュー（認証済み専用 REST `GET affilicard/v1/products/{id}/card-preview` 経由・status 非依存。フロントの publish ガードは不変）
- CTA ラベルのブロック単位上書き（優先順位: ブロック属性 > listing `button_label_override` > プラットフォーム既定）。`block.json` に `ctaLabelOverrides` 属性を追加
- 商品検索の強化: external_id（`affilicard_extid_*` ミラー）の OR 検索、空入力時の最近商品表示、候補のサムネイル＋プラットフォーム＋価格リッチ表示（`__experimentalRenderItem`・不在時テキストフォールバック）

### Changed

- カード描画ロジックを `CardHtmlBuilder` に抽出し、フロント `Block::render` と REST プレビューで共有
- 商品一覧 REST（`/products`）の検索を `ProductRepository::search()` に集約し、各項目に thumbnail/price/platform を付与

## [1.2.0] - 2026-06-20

### Added

- 商品 CPT 登録画面の編集 UI を Gutenberg 右文書サイドバー（`PluginDocumentSettingPanel`）へ移行。商品タイプ・在庫・追加情報・プラットフォーム listing をサイドパネルで編集
- listings/extras をネイティブ配列メタ（`register_post_meta` `type=array` + `show_in_rest` スキーマ）として保存し、Gutenberg core-data（`useEntityProp`）で保存・読み込み
- CPT に `custom-fields` support を追加（`register_post_meta(show_in_rest)` を REST 応答の `meta` として露出させ Gutenberg で保存可能にするため必須）
- 未認証 REST read を拒否する `ProductRestController`（read 系 permission を `edit_posts` 必須に上書き）
- 商品 CPT を `show_in_rest=true` 化（Gutenberg 本文編集を有効化）。本文は `post_content`
- プラットフォーム listing のアコーディオン表示（`Panel`/`PanelBody`）とサイドパネルの余白規律 CSS
- 商品設定の各入力欄にプレースホルダを追加

### Changed

- 商品説明をクラシックエディタから Gutenberg ブロックエディタ（本文）へ
- 派生 meta（external_id ミラー・schema_version）を `rest_after_insert` フックで保存後に同期（autosave/revision はスキップ）

### Removed

- クラシックメタボックス（hidden textarea + `$_POST` 保存）を撤去し、core-data 保存へ全面移行

## [1.1.0] - 2026-06-16

### Added

- 設定画面のプラットフォーム設定を商品タイプ（電子書籍 / VOD …）別のサブタブに分割し、各タイプのプラットフォームを `PanelBody` の折りたたみで表示
- 「API 認証」サブタブを新設し、認証情報を **Provider 単位で 1 回だけ**編集できる `ApiCredentialsPanel` を追加（同一 API を複数プラットフォームで重複入力させない）
- 認証情報の provider 単位 REST ルート `/affilicard/v1/providers/{code}/credentials`（GET/PUT）・`/providers/{code}/test-connection`（POST）
- 設定画面の余白規律 CSS（`assets/admin-settings.css`、`#affilicard-settings-root` スコープ）

### Changed

- 各プラットフォーム編集を `PanelBody` の折りたたみに変更し、API 連携（自動取得）系フィールドをサブセクションへ整理（手動運用を前面に）
- `CredentialEditor` を platform 単位から provider 単位（`providerCode`）に変更し、Provider 定数を `src/Admin/providers.js` に集約
- 一般設定タブのフィールド／ボタン配置に余白規律を適用

## [0.3.2] - 2026-06-15

### Fixed

- 汎用型（書誌ヘッダが無いタイプ）でカードのタイトルが上端に詰まる不具合を修正（`.affilicard-card__body` に上パディングを付与し、先頭要素の上マージンを相殺）

## [0.3.1] - 2026-06-08

### Fixed

- extras の日本語が `著...` のように壊れて保存・表示される不具合を修正（`JsonField::encode` に `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` を付与。`update_post_meta` の `wp_unslash` がバックスラッシュを除去して壊す根本原因に対処）

### Changed

- 商品カードを電子書籍向け本番デザインに刷新：書影 2 カラム（左 160px / SP 全幅）・著者/出版社の書誌ヘッダ・あらすじ・「店名 ｜ ¥価格（税込）＋割引バッジ ｜ CTA」の店舗行（`<ul>/<li>`）・価格時点フッタ（listing の最新 `last_fetched_at` 由来）。CTA はプラットフォーム別ブランド色を維持し、`--affilicard-*` CSS 変数によるテーマ色連携も維持

## [0.3.0] - 2026-06-03

### Added

- Block で `externalId + platform` 指定時、CPT 不在なら Provider 経由で商品を auto-create（`affilicard_autocreate_*` transient で連打抑止、生成は publish）
- プラットフォーム単位の API 価格自動更新設定（PlatformDefinition の `autoRefresh` / `refreshFrequency`=daily/weekly）と、それに応じた WP-Cron `affilicard_refresh_platform`（platform ごとに hook 引数で登録。グローバル `cron_enabled` がマスタースイッチ）
- 価格更新の手動トリガー REST `POST /affilicard/v1/refresh`（全体 / `platform` 別、`force` で取扱終了 listing も更新）と、General 設定の「一括更新」「強制一括更新」ボタン・各 Platform の「今すぐ更新」ボタン
- 予約投稿（future）→ publish 昇格時に listing を最新価格へ refresh（`transition_post_status`）
- `Provider::fetch()` 戻り値に `title`（auto-create 用）／ `GeneralSettings::isCronEnabled()` ／ `ProductRepositoryInterface`

### Notes

- 価格更新（自動 Cron・予約投稿昇格・通常の手動更新）の対象は公開中（publish）商品の `update_mode=auto && auto_update && enabled` listing のみ（非公開はスキップ、`auto_update=false` は更新しない）。「強制一括更新」のみ `auto_update=false` も対象。
- `cron_enabled` の ON/OFF・platform の `autoRefresh`/頻度に応じて WP-Cron を reconcile し、無効化時・プラグイン無効化時に解除

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
