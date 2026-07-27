# カード書影の表示ストア追従・プラットフォーム優先度選択 設計

> **廃止（2026-07-27 / v3.0.0）**: 本設計の `imagePriority` は撤去した。書影の選択は
> プラットフォーム設定の「表示順」に統合されている。
> 現行設計は `2026-07-26-platform-display-order-design.md` を参照。

---

> **作成: 2026-07-18**
> 対象: affilicard（全 product_type 共通仕様）。想定バージョン **v2.1.0**（後方互換の MINOR）。

## 1. 背景と問題

現在 affilicard のカード書影は **WP の投稿アイキャッチ 1 枚**（`CardHtmlBuilder::featuredImageUrl` → `get_post_thumbnail_id` → `wp_get_attachment_image_url`）で決まり、**カードに表示しているストアと無関係**に固定される。

これには 2 つの問題がある:

1. **各プラットフォームの規約対応**: 各ストアは「そのストアの商品画像は、そのストアの CDN 画像を使う」ことを求める場合がある。カード書影が表示ストアと無関係だと、規約・審査の観点で不適切になり得る。
2. **表示ストア制限（`only-platform`）への非追従**: セール記事などで `onlyPlatforms` により表示ストアを 1 つに絞っても、書影は元のアイキャッチのままで、表示ストアに追従しない。

## 2. やりたいこと（確定要件）

- カード書影を、**表示中のストアの中から規約・審査の厳しさに応じた優先度で選び、そのストアの CDN 画像（ホットリンク）を使う**。
- 優先度は **DMM > Amazon > 楽天Kobo > （フォールバック）アイキャッチ**。この順序は「各プラットフォームの規約・審査の厳しさ」に基づく（DMM が最も厳しい＝最優先で満たす）。
- **`only-platform` 等で表示ストアを絞った場合は、表示中のストアに追従して書影を選ぶ**。
- **affilicard の共通仕様**（電子書籍に限らず全 product_type）。優先度は各プラットフォームが持つ rank として設定する（VOD 等にも一般化）。
- **BookWalker は対象外**（Provider/API が無く CDN 画像を取得できないため、既定の優先度を与えない）。

## 3. スコープ

- **変更**: affilicard のみ。`PlatformDefinition`/`PlatformConfig`（`imagePriority` 追加）／管理画面 `PlatformsSettings`（入力追加）／`CardRenderer`（画像選択）／`CardHtmlBuilder`（フォールバック値の受け渡し）。
- **無改修**: `ProductRepository`（`find()` は既に listings をそのまま返す）／マスク（R18・ぼかし）ロジック／REST・Block・Provider。
- **consumer（e-comi・別リポ・本 spec 外）**: 各 listing にそのストア CDN の `image_url` を入れる（e-comi 側で対応済み方針）。post-products のアイキャッチ設定はフォールバック用に現状維持。

## 4. 設計

### 4.1 `imagePriority` フィールド（`PlatformDefinition`）

`PlatformDefinition`（immutable value object）に **`imagePriority`（int・既定 999）** を追加する。

- `fromArray()` は欠損時 `999` を補完（**既存の `affilicard_platforms` オプションは移行不要**＝後方互換）。
- `toArray()` に含める（保存・管理画面往復）。
- 意味: **小さいほど書影の優先度が高い**（DMM=10 が最優先など）。`displayOrder`（ボタン表示順）とは**独立**（規約由来の画像優先度をボタン順と切り離す）。

**既定シード**（affilicard のデフォルトプラットフォーム定義に設定）:

| platform | imagePriority |
| --- | --- |
| `dmm-books` | 10 |
| `amazon-kindle` | 20 |
| `rakuten-kobo` | 30 |
| `bookwalker` | （既定 999・対象外） |
| その他（VOD 等） | 既定 999（運用者が管理画面で設定） |

### 4.2 画像選択ロジック（`CardRenderer`）

画像選択は **`CardRenderer` 内**で行う（CardRenderer は既に `only`/`hide` で表示プラットフォームを絞り、`$product['listings']` も参照するため、絞り込みロジックを重複させない）。

`CardHtmlBuilder::build()` は現在 `image_url` オプションに `featuredImageUrl($id)`（WP アイキャッチ）を渡している。これを **フォールバック値**として渡す位置づけに変え、`CardRenderer` が次の順で実効書影を決める:

1. **表示中プラットフォーム**（`CardRenderer` が既に算出する enabled ∩ listings、`hide` 除外、`only` 指定時はそれに限定した集合）に属する listing のうち、**`image_url` が非空**のものを候補にする。
2. 候補を **`imagePriority` 昇順**（同値は `displayOrder` 昇順 → listing 出現順）でソートし、先頭の `image_url` を採用する（＝各ストア CDN 画像のホットリンク）。
3. 候補が無ければ、`CardHtmlBuilder` から渡された **WP アイキャッチ（`image_url` オプション）** にフォールバックする。
4. それも空なら、従来どおり affilicard のプレースホルダ（「画像がありません」）を描画する。

`CardRenderer` は表示プラットフォーム集合を PlatformDefinition（`imagePriority` 込み）とともに受け取るため、優先度ソートに必要な情報は既に手元にある。

### 4.3 マスク（R18・ぼかし）との関係

マスクは「選ばれた書影に CSS でぼかし／バッジを重ねる」処理で、**どの画像を選ぶか**とは独立。選択後の `image_url` に対して従来どおり適用され、**相互作用は無い**（ホットリンク CDN 画像でも CSS 効果は成立）。

### 4.4 管理画面（`PlatformsSettings`）

各プラットフォーム行に **`imagePriority` の数値入力を 1 つ追加**（`displayOrder` の隣）。保存は既存の PlatformConfig 経路（`toArray()` に含めたので往復する）。

## 5. エッジ・整合

- **Amazon は通常 `image_url` を持たない**（e-comi の enrich はリンクのみ投入）ため、Amazon は画像選択で自然に skip され DMM/楽天Kobo が選ばれる。**規約リスクの高い Amazon 画像を無理に使わない**挙動になる（`imagePriority` で Amazon を 2 位に置いても、画像が無ければ選ばれない）。
- **CDN ホットリンク**なので、書影を自サーバに複製する rehost に伴う否認リスクを回避する（`project_cover_image_rehost_risk` の方向と一致）。フォールバックのアイキャッチは consumer が設定した WP 画像。
- **後方互換**: `imagePriority` 未設定のプラットフォームは 999（最低優先）。既存商品・既存設定・既存カードは、listing に `image_url` が無ければ従来どおりアイキャッチにフォールバックするため、**挙動は不変**。
- **表示ストア追従**: `onlyPlatforms` で 1 ストアに絞ったカードは、その表示ストアの listing 画像（あれば）を使う。表示していないストアの画像は選ばれない。

## 6. テスト方針

- **`CardRenderer` の画像選択**（PHPUnit）: (a) 複数 listing で `imagePriority` 順に選ぶ／(b) `only`/`hide` で表示ストアを絞ると選択もそれに追従／(c) 表示中 listing に画像が無ければアイキャッチにフォールバック／(d) `image_url` 空の listing は skip／(e) アイキャッチも無ければプレースホルダ。
- **`PlatformDefinition`**: `imagePriority` の既定 999・`fromArray`/`toArray` 往復。
- **管理画面 JS**（`PlatformsSettings`）: `imagePriority` 入力の描画・保存 payload 反映（`npm run test:js`）。
- 既存のマスク（R18/ぼかし）・`CardRenderer` の他テストは**不変**であることを確認（回帰なし）。

## 7. バージョン・リリース

- **v2.1.0**（新機能・後方互換の MINOR）。`affilicard.php` の `Version` ヘッダ＋`AFFILICARD_VERSION`＋`package.json` を同期（`project_affilicard_puc_version_header`）。
- リリースは `release.yml`（タグ push → Release 公開）。PUC がタグの Version を検知。

## 8. 非スコープ / follow-up

- post-products（e-comi）の rehost/アイキャッチ設定の見直し（本 spec 外・現状維持でフォールバックとして機能）。
- BookWalker の Provider/API 実装（無いので画像対象外・別途）。
- VOD 等の各プラットフォームの `imagePriority` 既定値の作り込み（運用で設定可・必要時に既定を追加）。
