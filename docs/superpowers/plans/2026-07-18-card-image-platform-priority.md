# カード書影の表示ストア追従・プラットフォーム優先度選択 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** カード書影を「表示中ストアの listing から `imagePriority` 順で CDN 画像を選び、無ければアイキャッチにフォールバック」する挙動に変える（affilicard 共通仕様・後方互換 MINOR v2.1.0）。

**Architecture:** `PlatformDefinition` に画像優先度 `imagePriority` を追加し、`CardRenderer` が既存の `visibleListings()`（表示中 listing）から `imagePriority` 昇順で `image_url` 非空の 1 枚を選ぶ。無ければ `CardHtmlBuilder` が渡す WP アイキャッチにフォールバック。管理画面（React `PlatformEditor.jsx`）に入力を 1 つ追加。

**Tech Stack:** PHP 8.2（strict_types）/ WordPress / PHPUnit 9.6（WP_Mock）/ phpcs（WordPress standards）/ React（@wordpress/scripts）。PHP は Docker（`php:8.2-cli`）で実行。作業ディレクトリはリポジトリルート `/Users/pitolick/Documents/Develop/pitolick/affilicard`。

## Global Constraints

- **後方互換**: `imagePriority` 未設定は既定 `999`（最低優先）。既存 `affilicard_platforms` オプション・既存商品・既存カードは挙動不変。
- **変更してよいのは** `src/Platform/PlatformDefinition.php`／`src/Platform/PlatformConfig.php`／`src/Renderer/CardRenderer.php`／`src/Admin/components/PlatformEditor.jsx`（＋必要なら `src/Rest/PlatformsController.php`）／各テスト／バージョン系ファイル。`ProductRepository`・マスクロジック・`CardHtmlBuilder`（フォールバック値の受け渡し以外）は無改修。
- **画像は CDN ホットリンク**（listing の `image_url` をそのまま `<img src>`）。rehost しない。
- **既定 imagePriority**: `dmm-books=10 / amazon-kindle=20 / rakuten-kobo=30`。`bookwalker` および VOD 系は既定 `999`（対象外・運用で設定可）。
- **優先度は小さいほど高い**。同値は `displayOrder` 昇順 → listing 出現順で決める。
- **マスク（R18/ぼかし）は選択後の画像に従来どおり適用**（相互作用なし・非改修）。
- PHP: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit`（`--filter <name>` で絞る）。phpcs: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpcs`。JS: `npm run test:js` / `npm run lint:js` / `npm run build`。
- コミットメッセージは日本語 Conventional Commits。末尾に `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`。

## File Structure

- Modify `src/Platform/PlatformDefinition.php` — `imagePriority` を追加（コンストラクタ末尾・`fromArray`・`toArray`）。
- Modify `src/Platform/PlatformConfig.php` — `defaults()` の 3 書籍プラットフォームに `imagePriority` を設定。
- Modify `src/Renderer/CardRenderer.php` — `selectCardImage()` を追加し `render()` の画像決定に配線。`visibleListings()` を早期に 1 回算出して再利用。
- Modify `src/Admin/components/PlatformEditor.jsx` — `imagePriority` 数値入力を追加。
- Verify/Modify `src/Rest/PlatformsController.php` — 保存経路が `imagePriority` を素通しするか確認（whitelist していれば追加）。
- Modify `affilicard.php`・`package.json`・`CHANGELOG.md` — v2.1.0。
- Test: `tests/Unit/Platform/PlatformDefinitionTest.php`・`tests/Unit/Renderer/CardRendererTest.php`。

---

### Task 1: `PlatformDefinition` に `imagePriority` を追加

**Files:**
- Modify: `src/Platform/PlatformDefinition.php`
- Test: `tests/Unit/Platform/PlatformDefinitionTest.php`

**Interfaces:**
- Produces: `PlatformDefinition::$imagePriority`（`public readonly int`・既定 999）／`fromArray` が `imagePriority` キーを読む／`toArray` が `'imagePriority'` を含む。

- [ ] **Step 1: 失敗テストを書く**

`tests/Unit/Platform/PlatformDefinitionTest.php` に追記:

```php
public function test_imagePriority_defaults_to_999_when_absent(): void {
    $def = PlatformDefinition::fromArray( array( 'code' => 'x' ) );
    $this->assertSame( 999, $def->imagePriority );
}

public function test_imagePriority_roundtrips_through_fromArray_and_toArray(): void {
    $def = PlatformDefinition::fromArray( array( 'code' => 'dmm-books', 'imagePriority' => 10 ) );
    $this->assertSame( 10, $def->imagePriority );
    $this->assertSame( 10, $def->toArray()['imagePriority'] );
}
```

- [ ] **Step 2: 失敗を確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter imagePriority`
Expected: FAIL（`imagePriority` プロパティ/キー未定義）

- [ ] **Step 3: 実装**

`src/Platform/PlatformDefinition.php` のコンストラクタ末尾に引数を追加（`refreshFrequency` の後）:

```php
		public readonly bool $autoRefresh = false,
		public readonly string $refreshFrequency = 'weekly',
		public readonly int $imagePriority = 999
	) {
```

`toArray()` の返り配列に追加:

```php
			'refreshFrequency' => $this->refreshFrequency,
			'imagePriority'    => $this->imagePriority,
		);
```

`fromArray()` で読み取り、`new self(...)` の末尾に渡す:

```php
		$image_priority = isset( $data['imagePriority'] ) ? (int) $data['imagePriority'] : 999;

		return new self(
			$code,
			isset( $data['name'] ) ? (string) $data['name'] : $code,
			isset( $data['provider'] ) ? (string) $data['provider'] : 'manual',
			isset( $data['displayOrder'] ) ? (int) $data['displayOrder'] : 999,
			isset( $data['enabled'] ) ? (bool) $data['enabled'] : true,
			$applicable_types,
			isset( $data['buttonLabel'] ) && '' !== (string) $data['buttonLabel'] ? (string) $data['buttonLabel'] : '購入する',
			isset( $data['brandColor'] ) && '' !== (string) $data['brandColor'] ? (string) $data['brandColor'] : '#444444',
			isset( $data['buttonTextColor'] ) && '' !== (string) $data['buttonTextColor'] ? (string) $data['buttonTextColor'] : '#ffffff',
			isset( $data['autoRefresh'] ) ? (bool) $data['autoRefresh'] : false,
			$frequency,
			$image_priority
		);
```

- [ ] **Step 4: 通過を確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter imagePriority`
Expected: PASS

- [ ] **Step 5: phpcs**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpcs src/Platform/PlatformDefinition.php`
Expected: エラーなし（あれば `vendor/bin/phpcbf` で整形）

- [ ] **Step 6: Commit**

```bash
git add src/Platform/PlatformDefinition.php tests/Unit/Platform/PlatformDefinitionTest.php
git commit -m "feat: PlatformDefinition に imagePriority を追加（既定999・後方互換）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: `PlatformConfig::defaults()` の書籍プラットフォームに `imagePriority` を設定

**Files:**
- Modify: `src/Platform/PlatformConfig.php`
- Test: `tests/Unit/Platform/PlatformDefinitionTest.php`（defaults の検証を追記。既存に `PlatformConfigTest` があればそちらでも可）

**Interfaces:**
- Consumes: `PlatformDefinition::$imagePriority`（Task 1）
- Produces: `PlatformConfig::defaults()` の `dmm-books=10 / amazon-kindle=20 / rakuten-kobo=30`、他は既定 999。

- [ ] **Step 1: 失敗テストを書く**

`tests/Unit/Platform/PlatformDefinitionTest.php` に追記（`use Affilicard\Platform\PlatformConfig;` が無ければ先頭に追加）:

```php
public function test_defaults_set_image_priority_for_book_platforms(): void {
    $by_code = array();
    foreach ( PlatformConfig::defaults() as $def ) {
        $by_code[ $def->code ] = $def->imagePriority;
    }
    $this->assertSame( 10, $by_code['dmm-books'] );
    $this->assertSame( 20, $by_code['amazon-kindle'] );
    $this->assertSame( 30, $by_code['rakuten-kobo'] );
    $this->assertSame( 999, $by_code['bookwalker'] );
}
```

- [ ] **Step 2: 失敗を確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter test_defaults_set_image_priority_for_book_platforms`
Expected: FAIL（dmm-books の imagePriority が 999）

- [ ] **Step 3: 実装**

`src/Platform/PlatformConfig.php` の `defaults()` で、3 書籍プラットフォームの `new PlatformDefinition(...)` 末尾に named 引数 `imagePriority:` を付ける（他のプラットフォームは変更しない）。

`dmm-books`（`autoRefresh=true, refreshFrequency='weekly'` を positional で渡している）:

```php
			new PlatformDefinition(
				'dmm-books',
				__( 'DMMブックス', 'affilicard' ),
				'dmm-ebook',
				1,
				true,
				array( 'ebook' ),
				__( 'DMMブックスで読む', 'affilicard' ),
				'#d72d65',
				'#ffffff',
				true,
				'weekly',
				imagePriority: 10
			),
```

`amazon-kindle`（autoRefresh/refreshFrequency は既定・named でスキップ）:

```php
			new PlatformDefinition(
				'amazon-kindle',
				__( 'Amazon Kindle', 'affilicard' ),
				'manual',
				2,
				true,
				array( 'ebook' ),
				__( 'Kindleで読む', 'affilicard' ),
				'#ff9900',
				'#000000',
				imagePriority: 20
			),
```

`rakuten-kobo`:

```php
			new PlatformDefinition(
				'rakuten-kobo',
				__( '楽天Kobo', 'affilicard' ),
				'manual',
				3,
				true,
				array( 'ebook' ),
				__( '楽天Koboで読む', 'affilicard' ),
				'#bf0000',
				'#ffffff',
				imagePriority: 30
			),
```

- [ ] **Step 4: 通過を確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter test_defaults_set_image_priority_for_book_platforms`
Expected: PASS

- [ ] **Step 5: phpcs**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpcs src/Platform/PlatformConfig.php`
Expected: エラーなし

- [ ] **Step 6: Commit**

```bash
git add src/Platform/PlatformConfig.php tests/Unit/Platform/PlatformDefinitionTest.php
git commit -m "feat: 既定プラットフォームの imagePriority を設定（DMM=10/Amazon=20/Kobo=30）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: `CardRenderer` の画像選択（表示ストア追従・imagePriority）

**Files:**
- Modify: `src/Renderer/CardRenderer.php`
- Test: `tests/Unit/Renderer/CardRendererTest.php`

**Interfaces:**
- Consumes: `PlatformDefinition::$imagePriority`（Task 1）／既存 `CardRenderer::visibleListings()`（private・`($listings, $by_code, $hide, $only)` を受け表示中 listing を返す）
- Produces: `CardRenderer::selectCardImage( array $visibleListings, array $by_code, string $fallback ): string`（private）。`render()` の書影 `$image_url` がこの選択結果になる。

**Notes:**
- `visibleListings()` は「URL があり hide/only/enabled を満たす listing」を返す＝表示中（ボタンあり）の集合。画像選択の候補として正しい。
- 選択は「候補のうち `image_url` 非空を `imagePriority` 昇順（同値は `displayOrder` 昇順→出現順）でソートし先頭」。無ければ `$fallback`（WP アイキャッチ）。

- [ ] **Step 1: 失敗テストを書く**

`tests/Unit/Renderer/CardRendererTest.php` に追記（ファイル冒頭の `use` に `Affilicard\Platform\PlatformDefinition;` があることを確認。無ければ追加）。ヘルパとテストを追記:

```php
/** @return list<PlatformDefinition> */
private function bookPlatforms(): array {
    return array(
        new PlatformDefinition( 'dmm-books', 'DMMブックス', 'manual', 1, true, array( 'ebook' ), 'DMMで読む', '#000', '#fff', imagePriority: 10 ),
        new PlatformDefinition( 'amazon-kindle', 'Amazon', 'manual', 2, true, array( 'ebook' ), 'Kindleで読む', '#000', '#fff', imagePriority: 20 ),
        new PlatformDefinition( 'rakuten-kobo', '楽天Kobo', 'manual', 3, true, array( 'ebook' ), 'Koboで読む', '#000', '#fff', imagePriority: 30 ),
    );
}

public function test_card_image_follows_platform_priority_dmm_over_kobo(): void {
    $product = array(
        'title'        => 'X',
        'stock_status' => 'available',
        'listings'     => array(
            array( 'platform' => 'rakuten-kobo', 'affiliate_url' => 'https://a/kobo', 'image_url' => 'https://cdn/kobo.jpg' ),
            array( 'platform' => 'dmm-books', 'affiliate_url' => 'https://a/dmm', 'image_url' => 'https://cdn/dmm.jpg' ),
        ),
    );
    $html = ( new CardRenderer() )->render( $product, $this->bookPlatforms(), array( 'image_url' => 'https://cdn/eyecatch.jpg' ) );
    $this->assertStringContainsString( 'https://cdn/dmm.jpg', $html );
    $this->assertStringNotContainsString( 'https://cdn/eyecatch.jpg', $html );
    $this->assertStringNotContainsString( 'https://cdn/kobo.jpg', $html );
}

public function test_card_image_follows_only_platform_restriction(): void {
    $product = array(
        'title'        => 'X',
        'stock_status' => 'available',
        'listings'     => array(
            array( 'platform' => 'dmm-books', 'affiliate_url' => 'https://a/dmm', 'image_url' => 'https://cdn/dmm.jpg' ),
            array( 'platform' => 'rakuten-kobo', 'affiliate_url' => 'https://a/kobo', 'image_url' => 'https://cdn/kobo.jpg' ),
        ),
    );
    // 楽天Kobo のみ表示 → DMM の方が優先度高いが表示外なので Kobo 画像を使う。
    $html = ( new CardRenderer() )->render( $product, $this->bookPlatforms(), array( 'only_platforms' => array( 'rakuten-kobo' ), 'image_url' => 'https://cdn/eyecatch.jpg' ) );
    $this->assertStringContainsString( 'https://cdn/kobo.jpg', $html );
    $this->assertStringNotContainsString( 'https://cdn/dmm.jpg', $html );
}

public function test_card_image_falls_back_to_eyecatch_when_no_listing_image(): void {
    $product = array(
        'title'        => 'X',
        'stock_status' => 'available',
        'listings'     => array(
            array( 'platform' => 'dmm-books', 'affiliate_url' => 'https://a/dmm' ), // image_url 無し
        ),
    );
    $html = ( new CardRenderer() )->render( $product, $this->bookPlatforms(), array( 'image_url' => 'https://cdn/eyecatch.jpg' ) );
    $this->assertStringContainsString( 'https://cdn/eyecatch.jpg', $html );
}
```

- [ ] **Step 2: 失敗を確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter card_image`
Expected: FAIL（現状はアイキャッチ固定なので DMM/Kobo 画像テストが失敗）

- [ ] **Step 3: 実装 — `selectCardImage()` を追加**

`src/Renderer/CardRenderer.php` に private メソッドを追加（`visibleListings()` の近くに置く）:

```php
	/**
	 * 表示中 listing のうち image_url 非空のものから imagePriority 順で 1 枚選ぶ。
	 * 同値は displayOrder 昇順 → 出現順。無ければ $fallback（WP アイキャッチ）。
	 *
	 * @param list<array<string, mixed>>      $visibleListings visibleListings() の戻り
	 * @param array<string, PlatformDefinition> $by_code       code => PlatformDefinition
	 */
	private function selectCardImage( array $visibleListings, array $by_code, string $fallback ): string {
		$best_url      = '';
		$best_priority = PHP_INT_MAX;
		$best_order    = PHP_INT_MAX;
		foreach ( $visibleListings as $listing ) {
			$img = isset( $listing['image_url'] ) ? trim( (string) $listing['image_url'] ) : '';
			if ( '' === $img ) {
				continue;
			}
			$code     = isset( $listing['platform'] ) ? (string) $listing['platform'] : '';
			$def      = $by_code[ $code ] ?? null;
			$priority = $def instanceof PlatformDefinition ? $def->imagePriority : 999;
			$order    = $def instanceof PlatformDefinition ? $def->displayOrder : 999;
			if ( $priority < $best_priority || ( $priority === $best_priority && $order < $best_order ) ) {
				$best_url      = $img;
				$best_priority = $priority;
				$best_order    = $order;
			}
		}
		return '' !== $best_url ? $best_url : $fallback;
	}
```

- [ ] **Step 4: 実装 — `render()` に配線**

`render()` 内、現在の書影決定行:

```php
		$image_url    = isset( $options['image_url'] ) ? (string) $options['image_url'] : '';
```

を次に置き換える（`$hide`/`$only`/`$by_code` は既にこの行より前で算出済み）:

```php
		$fallback_image   = isset( $options['image_url'] ) ? (string) $options['image_url'] : '';
		$visible_listings = $this->visibleListings(
			isset( $product['listings'] ) && is_array( $product['listings'] ) ? $product['listings'] : array(),
			$by_code,
			$hide,
			$only
		);
		$image_url        = $this->selectCardImage( $visible_listings, $by_code, $fallback_image );
```

さらに、後段の `renderTimestamp` に渡している `$this->visibleListings( ... )` のインライン再計算を、上で作った `$visible_listings` の再利用に置き換える（同一集合・二重計算の回避）:

```php
		if ( $is_available ) {
			$html .= $this->renderTimestamp( $visible_listings );
		}
```

- [ ] **Step 5: 通過を確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter card_image`
Expected: PASS

- [ ] **Step 6: 回帰確認（既存 CardRenderer/マスクテスト不変）**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Renderer`
Expected: 既存＋新規すべて PASS

- [ ] **Step 7: phpcs**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpcs src/Renderer/CardRenderer.php`
Expected: エラーなし

- [ ] **Step 8: Commit**

```bash
git add src/Renderer/CardRenderer.php tests/Unit/Renderer/CardRendererTest.php
git commit -m "feat: カード書影を表示中ストアの imagePriority 順で CDN 選択・無ければアイキャッチ

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: 管理画面（`PlatformEditor.jsx`）に `imagePriority` 入力を追加

**Files:**
- Modify: `src/Admin/components/PlatformEditor.jsx`
- Verify/Modify: `src/Rest/PlatformsController.php`（保存経路の素通し確認）

**Interfaces:**
- Consumes: `PlatformDefinition::fromArray` が `imagePriority` を読む（Task 1）

- [ ] **Step 1: REST 保存経路を確認**

`src/Rest/PlatformsController.php` を Read し、受信 payload を `PlatformConfig::save()`／`PlatformDefinition::fromArray()` に渡す経路を確認する。
- payload の配列をそのまま fromArray に渡している（フィールド whitelist が無い）なら **REST 改修不要**（`imagePriority` は素通しで保存される）。
- 明示的に許可フィールドを列挙・sanitize している場合は、`imagePriority`（`(int)`・既定 999）を許可リストに追加する。

- [ ] **Step 2: `PlatformEditor.jsx` に入力を追加**

`src/Admin/components/PlatformEditor.jsx` の `displayOrder` 入力ブロック（現在 54-56 行付近の
`value={String(platform.displayOrder ?? 1)}` / `update({ displayOrder: parseInt(v, 10) || 1 })`）を
参考に、直後へ `imagePriority` の数値入力を追加する（ラベルは「画像優先度」・小さいほど優先の注記）:

```jsx
<label className="affilicard-platform-editor__field">
    <span>{__('画像優先度（小さいほど優先）', 'affilicard')}</span>
    <input
        type="number"
        value={String(platform.imagePriority ?? 999)}
        onChange={(e) =>
            update({ imagePriority: parseInt(e.target.value, 10) || 999 })
        }
    />
</label>
```

（周辺の JSX 構造・`update`/`__` の呼び出し方は同ファイル内の `displayOrder` フィールドに厳密に合わせること。クラス名・ラッパ要素は既存フィールドと同一にする。）

- [ ] **Step 3: JS lint / build**

Run: `npm run lint:js`
Expected: エラーなし（既存フィールドと同じ書式なら通る）

Run: `npm run build`
Expected: ビルド成功（`build/` 出力・エラーなし）

- [ ] **Step 4: JS ユニットテスト**

Run: `npm run test:js`
Expected: PASS（`--passWithNoTests`。PlatformEditor の JS テストが存在すれば imagePriority 反映も確認。無ければ no-test で緑）

- [ ] **Step 5: Commit**

```bash
git add src/Admin/components/PlatformEditor.jsx src/Rest/PlatformsController.php
git commit -m "feat: プラットフォーム設定に画像優先度(imagePriority)入力を追加

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

（`PlatformsController.php` を変更しなかった場合は add 対象から外す。）

---

### Task 5: バージョン v2.1.0 同期 ＋ CHANGELOG

**Files:**
- Modify: `affilicard.php`（`Version:` ヘッダ ＋ `AFFILICARD_VERSION`）
- Modify: `package.json`（`version`）
- Modify: `CHANGELOG.md`

**Notes:** PUC はタグのツリーの `Version` ヘッダを読むため 3 箇所を同期する（`project_affilicard_puc_version_header`）。

- [ ] **Step 1: バージョンを 2.1.0 に更新**

- `affilicard.php`: ` * Version:     2.0.0` → `2.1.0`、`define( 'AFFILICARD_VERSION', '2.0.0' );` → `'2.1.0'`。
- `package.json`: `"version": "2.0.0"` → `"2.1.0"`（現行値を確認して置換）。

- [ ] **Step 2: CHANGELOG 追記**

`CHANGELOG.md` の既存様式（`## [x.y.z]` ＋ `### Added` 等）に倣い、`## [2.1.0]` を追加:

```markdown
## [2.1.0]

### Added

- カード書影を表示中ストアの `imagePriority`（DMM > Amazon > 楽天Kobo）順で各ストア CDN 画像から選ぶようにした。`only-platform` で表示を絞ると書影もそれに追従する。listing に画像が無ければ従来どおり投稿アイキャッチにフォールバック。
- プラットフォーム設定に「画像優先度（imagePriority）」入力を追加（既定 999・後方互換）。
```

- [ ] **Step 3: 版数一致を確認**

Run: `grep -n "2.1.0" affilicard.php package.json CHANGELOG.md`
Expected: 3 ファイルとも 2.1.0（`affilicard.php` は 2 箇所）。`2.0.0` 残存が無いこと（`grep -n "2.0.0" affilicard.php` で 0 件）。

- [ ] **Step 4: Commit**

```bash
git add affilicard.php package.json CHANGELOG.md
git commit -m "chore: v2.1.0（カード書影の表示ストア追従・画像優先度）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: 全体検証（マージ前ゲート）

**Files:** なし（検証のみ）

- [ ] **Step 1: 全 PHPUnit**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit`
Expected: 全 PASS（既存＋新規・回帰なし）

- [ ] **Step 2: phpcs（全体）**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpcs`
Expected: エラーなし

- [ ] **Step 3: JS lint / test / build**

Run: `npm run lint:js && npm run test:js && npm run build`
Expected: すべて成功

- [ ] **Step 4: 完了処理**

`superpowers:finishing-a-development-branch` に従い PR 作成（push 前に `superpowers:requesting-code-review` ＋ `/coderabbit:review` で Critical/Important を解消）。**auto-merge しない**。マージ後 v2.1.0 タグ push → `release.yml` success → Release 公開（PUC 検知）。視覚確認（表示ストア追従で書影が変わること）は Playwright（ローカル実レンダリング or wp-env）で確認（`project_affilicard_no_playground_preview_ci`）。

---

## Self-Review（記入済み）

- **Spec coverage**: §4.1 imagePriority→Task1／既定シード→Task2／§4.2 画像選択→Task3／§4.4 管理画面→Task4／§7 バージョン→Task5／§6 テスト→各 Task の TDD＋Task6。カバー漏れなし。
- **Placeholder scan**: 各コード step に実コードを記載。Task4 の REST 確認は「読んで分岐」の具体手順（whitelist 有無で追加要否）＝プレースホルダでない。JSX は displayOrder パターンに合わせる具体指示。
- **Type consistency**: `imagePriority`（int）・`selectCardImage(array,array,string):string`・`visibleListings()` の戻り（list<array>）を Task 間で一貫使用。`PlatformDefinition` の named 引数 `imagePriority:` を Task2/Task3 テストで統一使用（PHP 8.2）。
