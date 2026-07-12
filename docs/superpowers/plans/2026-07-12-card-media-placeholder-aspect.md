# カードメディア枠 type別アスペクト比 ＋ contain ＋ プレースホルダ改善 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 商品カードのメディア枠を product_type ごとの実測アスペクト比で固定し、実画像を `object-fit: contain`（全 type 共通）で枠内に収め、画像なし時のプレースホルダを汎用アイコン＋type別メディアラベルで意匠化する。

**Architecture:** 既存の純粋レンダラ構成（`CardRenderer` は副作用なし・引数のみ）を維持。product_type ごとの定義クラス（`src/Types/*`）に `cardMediaAspectRatio()` を追加し、`CardHtmlBuilder` が options に載せ、`CardRenderer` が inline style ＋ CSS クラスで適用する。CSS は `assets/card.css`。

**Tech Stack:** PHP 8.2（strict_types）/ WordPress / PHPUnit（wp_mock）/ phpcs（WordPress coding standards）/ Playwright（E2E）。PHP は Docker（`php:8.2-cli` / `composer:2`）で実行。

## Global Constraints

（spec `docs/superpowers/specs/2026-07-12-card-media-placeholder-aspect-design.md` 由来。全タスク共通）

- **実測アスペクト比**: `AbstractProductType` 既定 `1 / 1`（generic・vod）／`EbookType` のみ `2 / 3`。VOD は 16:9 不採用。
- **`object-fit: contain` は全 type 共通**（実画像はどの比率でも枠内にレターボックス収め）。
- **プレースホルダは汎用**: 基盤既定ラベル「商品画像」を維持。`EbookType`＝「書影」・`VodType`＝「キービジュアル」は type 固有として維持。汎用アイコン（内蔵インライン SVG）を追加。
- **CardRenderer は純粋維持**（DB/option を読まない・引数のみ・WP escape 関数のみ依存）。
- **mask（blur/R18/label）・予約・timestamp の既存挙動は不変**。R18→blur 強制を維持。
- **「マスクなし `<img>` バイト一致」保証は本 feature で意図的更新**（メディア枠に aspect-ratio＋contain 導入のため）。該当テストを新マークアップへ更新する。
- **公開リポ**: docs/コミット/コードコメントに利用側の特定リポジトリ名・機能名を書かない（汎用表現）。
- **バージョン**: 完了時 `affilicard.php` の `Version:`＋`AFFILICARD_VERSION`＋`package.json` を **1.8.0** に同期（PUC 検知に必須）。
- **コミット**: 日本語 Conventional Commits、末尾 `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`。
- **ブランチ**: `feature/card-media-placeholder-aspect`（作成済）。
- phpcs 0・既存テスト green を維持。

---

## File Structure

| ファイル | 種別 | 責務 |
| --- | --- | --- |
| `src/Types/ProductTypeInterface.php` | 修正 | `cardMediaAspectRatio(): string` を宣言 |
| `src/Types/AbstractProductType.php` | 修正 | 既定 `'1 / 1'` を実装 |
| `src/Types/EbookType.php` | 修正 | `'2 / 3'` を override |
| `src/Renderer/CardHtmlBuilder.php` | 修正 | `media_aspect_ratio` を options に載せる |
| `src/Renderer/CardRenderer.php` | 修正 | メディア枠に aspect-ratio＋contain、プレースホルダ意匠化 |
| `assets/card.css` | 修正 | aspect 枠＋object-fit:contain＋プレースホルダ意匠、hardcode 2/3 撤去 |
| `blueprints/demo-seed.php` | 修正 | vod/no-image/横長 サンプル追加 |
| `affilicard.php` / `package.json` / `CHANGELOG.md` | 修正 | v1.8.0 同期 |
| `tests/Unit/Types/*Test.php` / `tests/Unit/Renderer/*Test.php` | 修正 | アスペクト比・contain・プレースホルダのテスト |

---

## Task 1: product_type に `cardMediaAspectRatio()` を追加

各 type 定義クラスにメディア枠アスペクト比を持たせる。既定 `1 / 1`、ebook のみ `2 / 3`。

**Files:**
- Modify: `src/Types/ProductTypeInterface.php`
- Modify: `src/Types/AbstractProductType.php`
- Modify: `src/Types/EbookType.php`
- Test: `tests/Unit/Types/EbookTypeTest.php` / `GenericTypeTest.php` / `VodTypeTest.php`

**Interfaces:**
- Produces: `ProductTypeInterface::cardMediaAspectRatio(): string`（CSS `aspect-ratio` 値。例 `'2 / 3'` / `'1 / 1'`）。Task 2 が消費。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Types/EbookTypeTest.php` に追加:

```php
public function test_card_media_aspect_ratio_is_portrait(): void {
    $this->assertSame( '2 / 3', ( new \Affilicard\Types\EbookType() )->cardMediaAspectRatio() );
}
```

`tests/Unit/Types/GenericTypeTest.php` に追加:

```php
public function test_card_media_aspect_ratio_defaults_to_square(): void {
    $this->assertSame( '1 / 1', ( new \Affilicard\Types\GenericType() )->cardMediaAspectRatio() );
}
```

`tests/Unit/Types/VodTypeTest.php` に追加:

```php
public function test_card_media_aspect_ratio_defaults_to_square(): void {
    $this->assertSame( '1 / 1', ( new \Affilicard\Types\VodType() )->cardMediaAspectRatio() );
}
```

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter cardMediaAspectRatio 2>&1 | tail -20`
（vendor 未導入なら先に `docker run --rm -v "$PWD":/app -w /app composer:2 install`）
Expected: FAIL（`cardMediaAspectRatio` メソッド未定義）。

- [ ] **Step 3: 実装する**

`src/Types/ProductTypeInterface.php` に宣言を追加（既存メソッド群の近くに）:

```php
	/**
	 * カードのメディア枠アスペクト比（CSS aspect-ratio 値。例 "2 / 3" / "1 / 1"）。
	 */
	public function cardMediaAspectRatio(): string;
```

`src/Types/AbstractProductType.php` に既定実装を追加（`cardMediaLabel()` の近くに）:

```php
	public function cardMediaAspectRatio(): string {
		return '1 / 1';
	}
```

`src/Types/EbookType.php` に override を追加（`cardMediaLabel()` の近くに）:

```php
	public function cardMediaAspectRatio(): string {
		return '2 / 3';
	}
```

- [ ] **Step 4: テストを実行して green を確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter cardMediaAspectRatio 2>&1 | tail -20`
Expected: PASS（3 ケース）。

- [ ] **Step 5: phpcs**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpcs src/Types 2>&1 | tail -20`
Expected: エラー 0（崩れていれば `vendor/bin/phpcbf src/Types` で整形）。

- [ ] **Step 6: Commit**

```bash
git add src/Types tests/Unit/Types
git commit -m "$(cat <<'EOF'
feat: product_type にカードメディア枠アスペクト比を追加

cardMediaAspectRatio() を追加。既定 1/1(generic・vod)、EbookType のみ 2/3。
実測(漫画=縦2:3 / 物販=正方 / 映像=分散大で中立正方)に基づく。

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: CardHtmlBuilder が `media_aspect_ratio` を options に載せる

`CardHtmlBuilder::build()` が type から `cardMediaAspectRatio()` を取り、renderer options に渡す（`media_label` と同じ流儀）。

**Files:**
- Modify: `src/Renderer/CardHtmlBuilder.php`
- Test: `tests/Unit/Renderer/CardHtmlBuilderTest.php`

**Interfaces:**
- Consumes: `ProductTypeInterface::cardMediaAspectRatio()`（Task 1）。
- Produces: renderer options に `media_aspect_ratio: string`。Task 3 が消費。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Renderer/CardHtmlBuilderTest.php` の既存テスト様式に倣い、ebook 商品でレンダリング結果に ebook のアスペクト（`2 / 3`）が反映されることを検証する（HtmlBuilder は最終 HTML を返すため、生成 HTML に `aspect-ratio: 2 / 3` が含まれることを assert する）。既存の product/type モック（`product_type => 'ebook'`）を使うヘルパがあればそれに倣う:

```php
public function test_build_applies_ebook_media_aspect_ratio(): void {
    // 既存テストの product mock（product_type='ebook', id 等）と platforms を用意して build を呼ぶ。
    $html = $this->buildForType( 'ebook' ); // 既存ヘルパが無ければ既存テストの組み立てを踏襲
    $this->assertStringContainsString( 'aspect-ratio: 2 / 3', $html );
}
```

> 既存 `CardHtmlBuilderTest` の build 呼び出し方法（product/platform/registry のモック手順）を踏襲する。type registry が ebook を返すよう product_type='ebook' を渡す。

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Renderer/CardHtmlBuilderTest.php 2>&1 | tail -20`
Expected: FAIL（`aspect-ratio: 2 / 3` が出力に無い）。

- [ ] **Step 3: 実装する**

`src/Renderer/CardHtmlBuilder.php` の `$type` 取得箇所（`$media_label = ...` の直後）に追加:

```php
		$media_aspect = null !== $type ? $type->cardMediaAspectRatio() : '1 / 1';
```

`$options` 配列に追加（`'media_label' => $media_label,` の近く）:

```php
			'media_aspect_ratio'  => $media_aspect,
```

- [ ] **Step 4: テストを実行して green を確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Renderer/CardHtmlBuilderTest.php 2>&1 | tail -20`
Expected: PASS。

> 注: この Step は Task 3（CardRenderer が `media_aspect_ratio` を実際に描画）に依存する。Task 2・3 を連続実行し、Task 3 実装後に本テストが green になることを確認する（Task 2 コミットは Task 3 と合わせてよい。分割レビューの都合で別コミットにする場合は、本テストを Task 3 のコミットに含める）。

- [ ] **Step 5: phpcs ＋ Commit**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpcs src/Renderer/CardHtmlBuilder.php 2>&1 | tail -10`

```bash
git add src/Renderer/CardHtmlBuilder.php tests/Unit/Renderer/CardHtmlBuilderTest.php
git commit -m "$(cat <<'EOF'
feat: CardHtmlBuilder が media_aspect_ratio を renderer に渡す

type の cardMediaAspectRatio() を options に載せる(既定 1/1 フォールバック)。

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: CardRenderer がメディア枠にアスペクト比＋contain＋プレースホルダ意匠を描く

`CardRenderer::render()` のメディアカラム描画を、type 別アスペクト枠＋`object-fit: contain`（全 type 共通）＋意匠化プレースホルダに変更する。mask/予約/timestamp は不変。

**Files:**
- Modify: `src/Renderer/CardRenderer.php`
- Test: `tests/Unit/Renderer/CardRendererTest.php`

**Interfaces:**
- Consumes: options `media_aspect_ratio`（Task 2）、既存 `image_url` / `media_label` / mask 系。
- Produces: メディア枠 HTML（`.affilicard-card__media` に aspect-ratio inline style、画像に contain クラス、プレースホルダにアイコン＋label）。Task 4（CSS）が対応。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Renderer/CardRendererTest.php` に追加:

```php
public function test_media_frame_has_aspect_ratio_from_option(): void {
    $html = ( new CardRenderer() )->render(
        $this->product(),
        array( $this->store() ),
        array( 'image_url' => 'https://img/photo.jpg', 'media_aspect_ratio' => '2 / 3' )
    );
    $this->assertStringContainsString( 'aspect-ratio: 2 / 3', $html );
}

public function test_media_image_uses_object_fit_contain_class(): void {
    $html = ( new CardRenderer() )->render(
        $this->product(),
        array( $this->store() ),
        array( 'image_url' => 'https://img/photo.jpg', 'media_aspect_ratio' => '1 / 1' )
    );
    // 全 type 共通の contain 用クラスが画像に付く。
    $this->assertStringContainsString( 'affilicard-card__media-image', $html );
}

public function test_placeholder_has_label_and_icon_and_aspect(): void {
    $html = ( new CardRenderer() )->render(
        $this->product(),
        array( $this->store() ),
        array( 'media_label' => 'キービジュアル', 'media_aspect_ratio' => '1 / 1' ) // image_url 未指定
    );
    $this->assertStringContainsString( 'affilicard-card__media-placeholder', $html );
    $this->assertStringContainsString( 'キービジュアル', $html );
    $this->assertStringContainsString( 'affilicard-card__media-placeholder-icon', $html ); // 汎用アイコン
    $this->assertStringContainsString( 'aspect-ratio: 1 / 1', $html );
}
```

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Renderer/CardRendererTest.php 2>&1 | tail -30`
Expected: 追加3ケース FAIL（aspect-ratio/contain クラス/アイコン 未実装）。

- [ ] **Step 3: 実装する**

`src/Renderer/CardRenderer.php` の `render()` で、options から `media_aspect_ratio` を取り出す（他 options 取得の近く）:

```php
		$media_aspect = isset( $options['media_aspect_ratio'] ) ? trim( (string) $options['media_aspect_ratio'] ) : '1 / 1';
```

メディアカラム描画（現行 `'<div class="affilicard-card__media">' … '</div>'` のブロック）を次の方針で書き換える:

- メディアラッパに aspect-ratio inline style を付ける:
  `'<div class="affilicard-card__media" style="aspect-ratio: ' . esc_attr( $media_aspect ) . '">'`
- 実画像（マスクなし）の `<img>` に共通クラス `affilicard-card__media-image` を付ける（CSS で `object-fit: contain; width:100%; height:100%`）:
  `'<img class="affilicard-card__media-image" src="' . esc_url( $image_url ) . '" alt="' . esc_attr( (string) ( $product['title'] ?? '' ) ) . '" loading="lazy" />'`
- マスク時の `<img>`（`.affilicard-card__cover-blur` 内）にも同じ `affilicard-card__media-image` クラスを付け、`.affilicard-card__cover--masked` が aspect 枠に収まるようにする（mask overlay/R18/label のロジックは不変）。
- プレースホルダ（`'' === $image_url` 分岐）を次に置き換える:
  ```php
  $html .= '<div class="affilicard-card__media-placeholder">'
      . '<span class="affilicard-card__media-placeholder-icon" aria-hidden="true">' . self::MEDIA_PLACEHOLDER_ICON_SVG . '</span>'
      . '<span class="affilicard-card__media-placeholder-label">' . esc_html( $media_label ) . '</span>'
      . '</div>';
  ```

クラス定数として汎用画像アイコン SVG を追加する（`R18_BADGE_SVG` の近く・静的マークアップのみ・純粋レンダラ制約維持）:

```php
	/** 画像なしプレースホルダの汎用アイコン（中立の「画像」意匠。既存マーク非模倣・静的 SVG）。 */
	private const MEDIA_PLACEHOLDER_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="画像なし"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.6"/><path d="M4 17l5-5 4 4 3-3 4 4"/></svg>';
```

> **バイト一致テストの更新**: 既存の「マスクなし `<img>` がバイト一致」を主張するテスト（`tests/Unit/Renderer/CardRendererTest.php` の該当ケース）は、新マークアップ（`class="affilicard-card__media-image"` 付与・メディアラッパの aspect-ratio style）に合わせて期待値を更新する。マスク時の overlay/R18/label・価格・CTA・timestamp の assertion は不変であることを確認する。

- [ ] **Step 4: テストを実行して green を確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Renderer/CardRendererTest.php 2>&1 | tail -30`
Expected: 追加3ケースを含め全 PASS。マスク系の既存ケースも green（overlay/R18/label 不変）。バイト一致系は更新後の期待値で PASS。

- [ ] **Step 5: 全 PHPUnit ＋ phpcs**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit 2>&1 | tail -8 && docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpcs src/Renderer 2>&1 | tail -10`
Expected: 全テスト green・phpcs 0（Task 2 のテストもここで green になる）。

- [ ] **Step 6: Commit**

```bash
git add src/Renderer/CardRenderer.php tests/Unit/Renderer/CardRendererTest.php
git commit -m "$(cat <<'EOF'
feat: メディア枠に type別アスペクト比+contain、プレースホルダを意匠化

メディア枠を aspect-ratio 固定し実画像は object-fit: contain(全type共通)で収め、
画像なし時は汎用アイコン+type別メディアラベルのプレースホルダに。mask/予約/
timestamp は不変。旧「img バイト一致」テストは新マークアップへ更新。

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: CSS（`assets/card.css`）— aspect 枠＋contain＋プレースホルダ意匠

メディア枠の hardcode `aspect-ratio: 2/3` を撤去し、type 駆動の inline aspect-ratio ＋ `object-fit: contain` ＋ プレースホルダ意匠のスタイルを追加する。

**Files:**
- Modify: `assets/card.css`

**Interfaces:**
- Consumes: CardRenderer が出力するクラス（`.affilicard-card__media`（inline aspect-ratio）・`.affilicard-card__media-image`・`.affilicard-card__media-placeholder`・`-icon`・`-label`）。

- [ ] **Step 1: CSS を編集する**

`assets/card.css` の `.affilicard-card__media` 系を次のとおり変更する:

- `.affilicard-card__media`: 既存 padding は維持しつつ、`display: block;`（または内側に aspect ラッパを持つ場合はそれに合わせる）。メディア枠自身が inline `aspect-ratio` を持つため、内側の画像/プレースホルダは枠いっぱいに収める。
- `.affilicard-card__media-image`（新）: `width: 100%; height: 100%; object-fit: contain; display: block; border-radius: 8px;`（既存 `.affilicard-card__media img` の box-shadow/border-radius を踏襲）。
- `.affilicard-card__media-placeholder`: **ハードコード `aspect-ratio: 2 / 3` を削除**。`width:100%; height:100%;`（枠が aspect を持つ）・`display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px;`・`background:#eef1ee; color:var(--affilicard-text-light); border-radius:8px;`。
- `.affilicard-card__media-placeholder-icon`（新）: `color: var(--affilicard-text-light); opacity: .7;`（SVG は currentColor 追従）。
- `.affilicard-card__media-placeholder-label`（新）: `font-size:11px; font-weight:600; letter-spacing:.04em;`。
- マスク枠 `.affilicard-card__cover--masked` / `.affilicard-card__cover-blur img` にも `object-fit: contain; width:100%; height:100%;` が効くよう調整（枠内に収める）。
- `@container (max-width: 600px)` の縦積みブロックでは、メディア枠の幅制御（既存 100px 相当）を維持。aspect-ratio は inline のままで、狭幅では枠幅が縮むだけ（contain で画像は収まる）。

- [ ] **Step 2: prettier（CSS 整形確認）**

Run: `docker run --rm -v "$PWD":/app -w /app node:20 npx prettier --check assets/card.css 2>&1 | tail -5`
（affilicard の prettier 設定＝`.prettierrc.js` に従う。崩れていれば `npx prettier --write assets/card.css`）
Expected: PASS。

- [ ] **Step 3: Commit**

```bash
git add assets/card.css
git commit -m "$(cat <<'EOF'
style: メディア枠を aspect-ratio 駆動+object-fit contain、プレースホルダ意匠化

hardcode aspect-ratio:2/3 を撤去し type 駆動 inline に。実画像/マスク/プレースホルダ
を枠内に contain 収め。プレースホルダにアイコン+ラベルのレイアウトを追加。

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Playground デモに vod / 画像なし / 横長 サンプルを追加

`blueprints/demo-seed.php` に、各 type × 画像あり/なし ＋ 横長画像のサンプルを追加し、PR プレビューで見た目・レイアウトを確認できるようにする。実在名は使わず架空プレースホルダ。

**Files:**
- Modify: `blueprints/demo-seed.php`

**Interfaces:**
- Consumes: 既存の product seed 構造（`product_type` / `$set_demo_cover` / `$block` ヘルパ）。

- [ ] **Step 1: サンプルを追加する**

`blueprints/demo-seed.php` の商品配列に次を追加（既存の seed 様式・`$set_demo_cover` / `$listing` ヘルパを踏襲）:

- **vod（画像あり）**: `product_type => 'vod'`・`title => 'サンプル映像作品（VOD・キービジュアル）'`。`$set_demo_cover` で正方に近いデモ画像を付ける。
- **generic（画像なし）**: `product_type => 'generic'`・`title => 'サンプル雑貨（画像なし・プレースホルダ）'`。**アイキャッチを設定しない**（`$set_demo_cover` を呼ばない）→ プレースホルダ「商品画像」＋1/1 枠を確認。
- **ebook（画像なし）**: `product_type => 'ebook'`・`title => 'サンプル漫画（書影なし・プレースホルダ）'`。アイキャッチ無し → プレースホルダ「書影」＋2/3 枠を確認。
- **vod（画像なし）**: `product_type => 'vod'`・アイキャッチ無し → プレースホルダ「キービジュアル」＋1/1 枠を確認。
- **generic（横長画像）**: `product_type => 'generic'`・`title => 'サンプル雑貨（横長画像・contain 確認）'`。`$set_demo_cover` を横長比率で生成する（`$set_demo_cover` が SVG 生成なら横長サイズの SVG を作るよう引数/分岐を足す）→ 1/1 枠に contain で収まり、隣の本文カラムが崩れないことを確認。

> `$set_demo_cover` が固定比率 SVG のみ生成する場合、横長サンプル用に「幅広 SVG を生成する」オプション（例 第4引数 `string $ratio = 'portrait'`）を最小追加する。既存呼び出しは既定引数で不変。

- [ ] **Step 2: デモページに新サンプルのブロックを並べる**

`$block( $id, ... )` でデモページ本文に新サンプルのカードを追加する（既存の `$demo_page` 本文組み立てに倣い、各 type・各ケースが 1 枚ずつ並ぶようにする）。

- [ ] **Step 3: PHP 構文チェック ＋ phpcs**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli php -l blueprints/demo-seed.php && docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpcs blueprints/demo-seed.php 2>&1 | tail -10`
Expected: No syntax errors・phpcs 0（blueprints が phpcs スコープ外なら省略可・[project_affilicard_no_playground_preview_ci] 参照）。

- [ ] **Step 4: Commit**

```bash
git add blueprints/demo-seed.php
git commit -m "$(cat <<'EOF'
test: Playground デモに vod/画像なし/横長 サンプルを追加

各 type × 画像あり/なし + 横長画像を並べ、type別アスペクト枠・contain・
プレースホルダ意匠・レイアウト非崩れを PR プレビューで確認できるようにする。

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: v1.8.0 同期 ＋ CHANGELOG

リリースに向けバージョンを 3 箇所同期し CHANGELOG を追記する。

**Files:**
- Modify: `affilicard.php`（`Version:` ヘッダ ＋ `AFFILICARD_VERSION`）
- Modify: `package.json`（`version`）
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes/Produces: なし（リリースメタ）。

- [ ] **Step 1: バージョンを 1.8.0 に同期**

- `affilicard.php` の `* Version:     1.7.0` → `1.8.0`。
- `affilicard.php` の `define( 'AFFILICARD_VERSION', '1.7.0' )` → `'1.8.0'`（該当行を確認して置換）。
- `package.json` の `"version": "1.7.0"` → `"1.8.0"`。

- [ ] **Step 2: CHANGELOG に追記**

`CHANGELOG.md` の `## [Unreleased]` の下に新セクションを追加（既存様式踏襲）:

```markdown
## [1.8.0] - 2026-07-12

### Added

- 商品カードのメディア枠を product_type ごとのアスペクト比で固定（電子書籍 2:3／汎用・動画配信 1:1・実測ベース）。実画像は `object-fit: contain` で枠内に収め、比率の異なる画像でもレイアウトが崩れない。
- 画像が無いときのプレースホルダを、汎用アイコン＋商品タイプ別ラベル（商品画像／書影／キービジュアル）で意匠化。

### Changed

- メディア枠のアスペクト比固定に伴い、商品カードの書影マークアップを更新（マスクなし画像の従来マークアップから変更）。
```

- [ ] **Step 3: バージョン整合を確認**

Run: `grep -nE "1\.8\.0|1\.7\.0" affilicard.php package.json | tail -10`
Expected: `affilicard.php`（Version ヘッダ・AFFILICARD_VERSION）と `package.json` がすべて 1.8.0・1.7.0 残存なし。

- [ ] **Step 4: 全テスト green 最終確認 ＋ Commit**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit 2>&1 | tail -6`
Expected: 全 PHPUnit green。

```bash
git add affilicard.php package.json CHANGELOG.md
git commit -m "$(cat <<'EOF'
chore: v1.8.0(メディア枠アスペクト比+contain+プレースホルダ改善)

Version ヘッダ/AFFILICARD_VERSION/package.json を 1.8.0 同期・CHANGELOG 追記。

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## 検証・E2E（実装完了後）

- **PR プレビュー（Playground）で視覚確認**: pr-preview-build / pr-preview-publish workflow が生成する Playground で、Task 5 のサンプル（各 type × 画像あり/なし ＋ 横長）を人間が目視確認する（type別アスペクト枠・contain 収め・プレースホルダ意匠・本文カラム非崩れ）。CI 未整備箇所は Playwright（ローカル実レンダリング HTML を http 配信・file:// 不可・[project_affilicard_no_playground_preview_ci]）で補う。
- **回帰**: 既存 PHPUnit / JS green・phpcs 0。マスク（blur/R18/label）・予約カード・timestamp が不変であること。
- **リリース**: PR マージ → `v1.8.0` タグ push → `release.yml` success → Release 公開（`affilicard-v1.8.0.zip`）→ PUC が Version 1.8.0 検知。**auto-merge しない**（Playground 視覚確認後に人間がマージ）。

---

## Self-Review（spec 照合）

- **spec §3 実測アスペクト比**: Task 1（type別 cardMediaAspectRatio）→ カバー。
- **spec §4.1/4.2 type→builder**: Task 1・2 → カバー。
- **spec §4.3 renderer（aspect枠＋contain＋プレースホルダ意匠・純粋維持・mask不変）**: Task 3 → カバー。
- **spec §4.4 CSS（hardcode撤去＋contain＋意匠）**: Task 4 → カバー。
- **spec §4.5 Playground サンプル**: Task 5 → カバー。
- **spec §5 テスト（type比率／builder options／renderer aspect・contain・placeholder／mask不変／バイト一致更新）**: Task 1・2・3 → カバー。
- **spec §6 リリース v1.8.0**: Task 6 → カバー。
- **プレースホルダ scan**: 「TBD/後で」等なし。テストヘルパ（`$this->product()` / `$this->store()` / `buildForType`）は既存 `CardRendererTest`/`CardHtmlBuilderTest` の実在ヘルパに合わせる旨を明記。
- **型整合**: `cardMediaAspectRatio(): string`・options キー `media_aspect_ratio`・クラス名 `affilicard-card__media-image` / `-placeholder-icon` / `-placeholder-label` を Task 1〜4 で一貫使用。
- **公開リポ制約**: 本 plan・コミットに利用側の特定リポジトリ名/機能名なし（汎用表現）。
