# プラットフォーム表示順の一元化と並べ替え UI 実装計画（v3.0.0）

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 商品カードの CTA ボタンの並びを listing の登録順ではなく `PlatformDefinition::displayOrder` で決めるようにし、管理画面から ↑ / ↓（アニメーション付き）で直感的に並べ替えられるようにする。

**Architecture:** レンダラー側は `CardRenderer::visibleListings()` の戻りを `displayOrder` 昇順で安定ソートするだけ（表示順の SSOT をプラットフォーム設定に一本化）。管理画面側は並べ替えの意味論を DOM 非依存の純関数モジュール `src/Admin/platformOrder.js` に切り出し、`PlatformsPanel` はそれを呼んで描画するだけにする。アニメーションは FLIP を行うフック `src/Admin/useFlipReorder.js` に隔離し、機能の前提にしない。

**Tech Stack:** PHP 8.2 / PHPUnit 9.6 + WP_Mock / React（`@wordpress/element`）/ `@wordpress/components` / Jest（`@wordpress/scripts test-unit-js`）/ Playwright + wp-env

**設計書:** `docs/superpowers/specs/2026-07-26-platform-display-order-design.md`

## Global Constraints

- 対象バージョンは **v3.0.0（MAJOR）**。Task 7 の `imagePriority` 撤去で公開 IF（`PlatformDefinition`
  のコンストラクタ引数・プロパティ、`/affilicard/v1/platforms` のペイロード）からキーが 1 つ消えるため。
  Task 1〜6 の時点では v2.5.0 として作業しており、Task 7 で 3.0.0 に上げ直す。
- コミットメッセージは日本語の Conventional Commits（`feat:` / `fix:` / `test:` / `docs:` / `chore:`）。
- **新しい npm 依存を追加しない。** アイコンは `@wordpress/components` の `Button` に dashicon 文字列（`arrow-up-alt2` / `arrow-down-alt2`）を渡す。`@wordpress/icons` は依存に無いので import しない。
- PHP のテスト・lint は Docker で実行する（ローカル Mac に PHP を入れない）。
  - テスト: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit`
  - 単体指定: 上記に `--filter <テストメソッド名>` を付ける
- JS のテスト・lint・build は **ローカルの volta（Node 20）で直接**実行する。
  - テスト: `npm run test:js`
  - lint: `npm run lint:js`
  - build: `npm run build`
- ユーザー向け文字列はすべて `__()` / `sprintf()` でテキストドメイン `affilicard` を付ける。
- 公開リポジトリなので、テスト・サンプル・コメントに実在の作品名・人名を書かない（プラットフォーム名は可）。
- 作業ブランチは `feature/platform-display-order`（作成済み）。

---

### Task 1: CardRenderer を displayOrder 昇順で安定ソートする

**Files:**

- Modify: `src/Renderer/CardRenderer.php`（`visibleListings()`、330-359 行付近）
- Test: `tests/Unit/Renderer/CardRendererTest.php`

**Interfaces:**

- Consumes: `Affilicard\Platform\PlatformDefinition::$displayOrder`（既存の public readonly int）
- Produces: `CardRenderer::visibleListings()` が `displayOrder` 昇順・同値は元の出現順で listing を返す。
  `renderListings()` / `renderTimestamp()` / `selectCardImage()` はこの並びを受け取る。
  メソッドは private のままでシグネチャ変更なし。

- [ ] **Step 1: 失敗するテストを 4 本書く**

`tests/Unit/Renderer/CardRendererTest.php` の末尾（最後の `}` の直前）に追加する。

```php
	/**
	 * 表示順テスト用の platform を作る。imagePriority は displayOrder と独立であることを
	 * 明示するため、意図的に displayOrder と逆順の値を渡せるようにしてある。
	 */
	private function orderedPlatform( string $code, string $name, int $displayOrder ): PlatformDefinition {
		return new PlatformDefinition(
			$code,
			$name,
			'manual',
			$displayOrder,
			true,
			array( 'ebook' ),
			$name . 'で読む',
			'#444444',
			'#ffffff'
		);
	}

	/**
	 * @param list<string> $codes
	 * @return array<string, mixed>
	 */
	private function productWithListings( array $codes ): array {
		$listings = array();
		foreach ( $codes as $code ) {
			$listings[] = array(
				'platform'      => $code,
				'enabled'       => true,
				'affiliate_url' => 'https://example.test/' . $code,
			);
		}
		return array(
			'title'        => 'テスト商品',
			'stock_status' => 'available',
			'listings'     => $listings,
		);
	}

	public function test_cta_rows_follow_display_order_not_listing_order(): void {
		// listing は登録順（ストアC → ストアA → ストアB）だが、displayOrder は A=1, B=2, C=3。
		$platforms = array(
			$this->orderedPlatform( 'store-a', 'ストアA', 1 ),
			$this->orderedPlatform( 'store-b', 'ストアB', 2 ),
			$this->orderedPlatform( 'store-c', 'ストアC', 3 ),
		);
		$html = ( new CardRenderer() )->render(
			$this->productWithListings( array( 'store-c', 'store-a', 'store-b' ) ),
			$platforms
		);
		$pos_a = strpos( $html, 'https://example.test/store-a' );
		$pos_b = strpos( $html, 'https://example.test/store-b' );
		$pos_c = strpos( $html, 'https://example.test/store-c' );
		$this->assertLessThan( $pos_b, $pos_a );
		$this->assertLessThan( $pos_c, $pos_b );
	}

	public function test_cta_rows_keep_listing_order_when_display_order_ties(): void {
		// displayOrder が同値なら登録順を保つ（安定ソート）。
		$platforms = array(
			$this->orderedPlatform( 'store-a', 'ストアA', 7 ),
			$this->orderedPlatform( 'store-b', 'ストアB', 7 ),
		);
		$html = ( new CardRenderer() )->render(
			$this->productWithListings( array( 'store-b', 'store-a' ) ),
			$platforms
		);
		$this->assertLessThan(
			strpos( $html, 'https://example.test/store-a' ),
			strpos( $html, 'https://example.test/store-b' )
		);
	}

	public function test_disabled_platform_is_excluded_and_rest_follows_display_order(): void {
		$platforms = array(
			$this->orderedPlatform( 'store-a', 'ストアA', 1 ),
			new PlatformDefinition( 'store-b', 'ストアB', 'manual', 2, false, array( 'ebook' ), 'ストアBで読む', '#444444', '#ffffff' ),
			$this->orderedPlatform( 'store-c', 'ストアC', 3 ),
		);
		$html = ( new CardRenderer() )->render(
			$this->productWithListings( array( 'store-c', 'store-b', 'store-a' ) ),
			$platforms
		);
		$this->assertStringNotContainsString( 'https://example.test/store-b', $html );
		$this->assertLessThan(
			strpos( $html, 'https://example.test/store-c' ),
			strpos( $html, 'https://example.test/store-a' )
		);
	}

	public function test_only_platforms_is_a_filter_and_does_not_define_order(): void {
		// only_platforms の指定順は許可リストであって順序ではない。
		$platforms = array(
			$this->orderedPlatform( 'store-a', 'ストアA', 1 ),
			$this->orderedPlatform( 'store-b', 'ストアB', 2 ),
		);
		$html = ( new CardRenderer() )->render(
			$this->productWithListings( array( 'store-a', 'store-b' ) ),
			$platforms,
			array( 'only_platforms' => array( 'store-b', 'store-a' ) )
		);
		$this->assertLessThan(
			strpos( $html, 'https://example.test/store-b' ),
			strpos( $html, 'https://example.test/store-a' )
		);
	}
```

- [ ] **Step 2: テストが落ちることを確認する**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter test_cta_rows_follow_display_order_not_listing_order`

Expected: FAIL（`assertLessThan` が不成立。現状は listing 登録順で描画されるため）

- [ ] **Step 3: `visibleListings()` の末尾でソートする**

`src/Renderer/CardRenderer.php` の `visibleListings()` の `return $out;` を次で置き換える。

```php
		return $this->sortByDisplayOrder( $out, $by_code );
	}

	/**
	 * listing を platform の displayOrder 昇順に並べ替える。同値は元の出現順を保つ。
	 *
	 * CTA 行の並びを listing の登録順から切り離すのが目的。listing を後から追記する運用
	 * （生成後に別ストアの listing を merge する等）では登録順がカードごとにばらつき、
	 * 同一記事内でボタン位置が食い違うため、表示順はプラットフォーム設定を単一の出所とする。
	 *
	 * PHP 8.0 以降の usort は安定ソートだが、意図を明示するため元 index を第 2 キーにする。
	 *
	 * @param list<array<string, mixed>>        $listings visibleListings() でフィルタ済みの listing
	 * @param array<string, PlatformDefinition> $by_code  code => PlatformDefinition（全 code 存在が保証済み）
	 * @return list<array<string, mixed>>
	 */
	private function sortByDisplayOrder( array $listings, array $by_code ): array {
		$indexed = array();
		foreach ( $listings as $index => $listing ) {
			$indexed[] = array(
				'index'   => $index,
				'order'   => $by_code[ (string) $listing['platform'] ]->displayOrder,
				'listing' => $listing,
			);
		}

		usort(
			$indexed,
			static function ( array $a, array $b ): int {
				if ( $a['order'] === $b['order'] ) {
					return $a['index'] <=> $b['index'];
				}
				return $a['order'] <=> $b['order'];
			}
		);

		$sorted = array();
		foreach ( $indexed as $entry ) {
			$sorted[] = $entry['listing'];
		}
		return $sorted;
	}
```

あわせて `visibleListings()` の docblock に 1 行足す。

```php
	 * 表示対象（platform 既知・hide 非該当・only 許可・platform/listing 有効）の listing だけを、
	 * platform の displayOrder 昇順（同値は元の出現順）で返す。
	 * CTA 行（renderListings）と日時フッター（renderTimestamp）が同一集合・同一順序を見るための共有フィルタ。
```

- [ ] **Step 4: 4 本のテストが通ることを確認する**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter 'test_cta_rows_follow_display_order_not_listing_order|test_cta_rows_keep_listing_order_when_display_order_ties|test_disabled_platform_is_excluded_and_rest_follows_display_order|test_only_platforms_is_a_filter_and_does_not_define_order'`

Expected: PASS（4 tests）

- [ ] **Step 5: PHP スイート全体が通ることを確認する**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit`

Expected: 全 PASS。特に `test_card_image_tiebreak_prefers_lower_display_order_on_equal_priority` が引き続き通ること（書影選択は `imagePriority` 主なので結果不変であるべき）。

- [ ] **Step 6: コミット**

```bash
git add src/Renderer/CardRenderer.php tests/Unit/Renderer/CardRendererTest.php
git commit -m "fix: カードの CTA 行を listing 登録順ではなく displayOrder 順で描画する"
```

---

### Task 2: 並べ替えの純関数モジュールを追加する

**Files:**

- Create: `src/Admin/platformOrder.js`
- Test: `tests/js/Admin/platformOrder.test.js`

**Interfaces:**

- Consumes: なし（外部依存ゼロの純関数）
- Produces: 名前付き export 4 つ。Task 3 の `PlatformsPanel` が使う。
  - `platformsOfType(platforms: Platform[], type: string): Platform[]`
  - `enabledRanks(platforms: Platform[], type: string): Record<string, number>` — 有効な platform の code → そのタブ内 1 始まり順位
  - `movePlatform(platforms: Platform[], type: string, code: string, direction: 'up'|'down'): Platform[]` — 動かせない場合は**引数と同一参照**を返す
  - `renumberDisplayOrder(platforms: Platform[]): Platform[]` — 配列順どおりに `displayOrder` を 1..N で振り直した新配列
  - `Platform` は REST が返す平の object（`{ code, name, enabled, displayOrder, applicableTypes, ... }`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/js/Admin/platformOrder.test.js` を新規作成する。

```js
/**
 * Tests for src/Admin/platformOrder.js
 */

import {
	platformsOfType,
	enabledRanks,
	movePlatform,
	renumberDisplayOrder,
} from '../../../src/Admin/platformOrder';

const make = ( code, displayOrder, enabled = true, types = [ 'ebook' ] ) => ( {
	code,
	name: code.toUpperCase(),
	enabled,
	displayOrder,
	applicableTypes: types,
} );

// a(1) / b(2, 無効) / c(3) / v(4, vod)
const platforms = [
	make( 'a', 1 ),
	make( 'b', 2, false ),
	make( 'c', 3 ),
	make( 'v', 4, true, [ 'vod' ] ),
];

describe( 'platformsOfType', () => {
	test( 'そのタイプの platform だけを配列順で返す', () => {
		expect( platformsOfType( platforms, 'ebook' ).map( ( p ) => p.code ) ).toEqual(
			[ 'a', 'b', 'c' ]
		);
		expect( platformsOfType( platforms, 'vod' ).map( ( p ) => p.code ) ).toEqual(
			[ 'v' ]
		);
	} );

	test( 'applicableTypes が配列でない platform は含めない', () => {
		const broken = [ { code: 'x', enabled: true, displayOrder: 1 } ];
		expect( platformsOfType( broken, 'ebook' ) ).toEqual( [] );
	} );
} );

describe( 'enabledRanks', () => {
	test( '有効な platform にだけ 1 始まりの順位を振る（無効は飛ばす）', () => {
		expect( enabledRanks( platforms, 'ebook' ) ).toEqual( { a: 1, c: 2 } );
	} );

	test( 'タイプごとに 1 から数え直す', () => {
		expect( enabledRanks( platforms, 'vod' ) ).toEqual( { v: 1 } );
	} );
} );

describe( 'movePlatform', () => {
	test( '下へ移動すると次の有効な platform と入れ替わる', () => {
		const next = movePlatform( platforms, 'ebook', 'a', 'down' );
		expect( next.map( ( p ) => p.code ) ).toEqual( [ 'c', 'b', 'a', 'v' ] );
	} );

	test( '上へ移動すると前の有効な platform と入れ替わる', () => {
		const next = movePlatform( platforms, 'ebook', 'c', 'up' );
		expect( next.map( ( p ) => p.code ) ).toEqual( [ 'c', 'b', 'a', 'v' ] );
	} );

	test( '間に挟まる無効な platform の位置は動かない', () => {
		const next = movePlatform( platforms, 'ebook', 'a', 'down' );
		expect( next[ 1 ].code ).toBe( 'b' );
	} );

	test( '他タイプの platform は動かない', () => {
		const next = movePlatform( platforms, 'ebook', 'a', 'down' );
		expect( next[ 3 ].code ).toBe( 'v' );
	} );

	test( '移動後は displayOrder が配列順の 1..N に振り直される', () => {
		const next = movePlatform( platforms, 'ebook', 'a', 'down' );
		expect( next.map( ( p ) => p.displayOrder ) ).toEqual( [ 1, 2, 3, 4 ] );
		expect( next.find( ( p ) => p.code === 'a' ).displayOrder ).toBe( 3 );
	} );

	test( '先頭の platform を上へ移動しようとしても何も起きない（同一参照）', () => {
		expect( movePlatform( platforms, 'ebook', 'a', 'up' ) ).toBe( platforms );
	} );

	test( '末尾の platform を下へ移動しようとしても何も起きない（同一参照）', () => {
		expect( movePlatform( platforms, 'ebook', 'c', 'down' ) ).toBe( platforms );
	} );

	test( '未知の code は何も起きない（同一参照）', () => {
		expect( movePlatform( platforms, 'ebook', 'zzz', 'down' ) ).toBe( platforms );
	} );

	test( '元の配列を破壊しない', () => {
		movePlatform( platforms, 'ebook', 'a', 'down' );
		expect( platforms.map( ( p ) => p.code ) ).toEqual( [ 'a', 'b', 'c', 'v' ] );
	} );
} );

describe( 'renumberDisplayOrder', () => {
	test( '重複値・欠番を配列順の 1..N に正規化する', () => {
		const messy = [ make( 'a', 9 ), make( 'b', 9 ), make( 'c', 40 ) ];
		expect(
			renumberDisplayOrder( messy ).map( ( p ) => p.displayOrder )
		).toEqual( [ 1, 2, 3 ] );
	} );

	test( 'displayOrder 以外のプロパティは保持する', () => {
		const [ first ] = renumberDisplayOrder( [ make( 'a', 9 ) ] );
		expect( first.code ).toBe( 'a' );
		expect( first.applicableTypes ).toEqual( [ 'ebook' ] );
	} );
} );
```

- [ ] **Step 2: テストが落ちることを確認する**

Run: `npm run test:js -- tests/js/Admin/platformOrder.test.js`

Expected: FAIL（`Cannot find module '../../../src/Admin/platformOrder'`）

- [ ] **Step 3: モジュールを実装する**

`src/Admin/platformOrder.js` を新規作成する。

```js
/**
 * プラットフォームの表示順（displayOrder）を扱う純関数群。
 *
 * 並べ替えの意味論を DOM・React から切り離し、単体で検証できるようにする。
 * 表示順の SSOT はプラットフォーム設定であり、カードの CTA ボタンはこの順に並ぶ。
 */

/**
 * そのタイプタブに表示する platform を、配列順のまま抽出する。
 *
 * @param {Array<Object>} platforms 全 platform（displayOrder 昇順で保持している前提）
 * @param {string}        type      商品タイプコード（'ebook' 等）
 * @return {Array<Object>} 該当する platform
 */
export function platformsOfType( platforms, type ) {
	return platforms.filter(
		( p ) =>
			Array.isArray( p.applicableTypes ) &&
			p.applicableTypes.includes( type )
	);
}

/**
 * タイプタブ内の「有効な」platform に 1 始まりの順位を振る。
 *
 * 無効な platform はカードに描画されないため順位を持たない。バッジに出す値は
 * displayOrder そのものではなく「カード上で何番目に出るか」であるべきなので、
 * 無効な行と他タイプの行を飛ばして数える。
 *
 * @param {Array<Object>} platforms 全 platform
 * @param {string}        type      商品タイプコード
 * @return {Object<string, number>} code => 1 始まりの順位
 */
export function enabledRanks( platforms, type ) {
	const ranks = {};
	let rank = 0;
	for ( const platform of platformsOfType( platforms, type ) ) {
		if ( platform.enabled ) {
			rank += 1;
			ranks[ platform.code ] = rank;
		}
	}
	return ranks;
}

/**
 * 配列順どおりに displayOrder を 1..N の連番で振り直した新配列を返す。
 *
 * 既存データの重複値・欠番をここで正規化する。サーバ側は displayOrder 昇順で
 * 保存し直すため、連番にしておかないと UI 上の並びと保存結果がずれる。
 *
 * @param {Array<Object>} platforms 全 platform
 * @return {Array<Object>} displayOrder を振り直した新配列
 */
export function renumberDisplayOrder( platforms ) {
	return platforms.map( ( platform, index ) => ( {
		...platform,
		displayOrder: index + 1,
	} ) );
}

/**
 * 同じタイプタブ内で、code の platform を 1 つ前／後の「有効な」platform と入れ替える。
 *
 * 無効な行・他タイプの行は読み飛ばす。入れ替えは配列位置の交換で行うため、
 * 2 つの間に挟まる行の位置は動かない。交換後に displayOrder を 1..N へ振り直す。
 *
 * 端にいて動かせない場合・未知の code の場合は、引数の配列をそのまま返す
 * （呼び出し側は参照の同一性で「動かなかった」を判定できる）。
 *
 * @param {Array<Object>} platforms 全 platform
 * @param {string}        type      商品タイプコード
 * @param {string}        code      動かす platform の code
 * @param {string}        direction 'up' | 'down'
 * @return {Array<Object>} 並べ替え後の新配列、または引数と同一の配列
 */
export function movePlatform( platforms, type, code, direction ) {
	const enabledIndexes = [];
	platforms.forEach( ( platform, index ) => {
		if (
			platform.enabled &&
			Array.isArray( platform.applicableTypes ) &&
			platform.applicableTypes.includes( type )
		) {
			enabledIndexes.push( index );
		}
	} );

	const at = enabledIndexes.findIndex(
		( index ) => platforms[ index ].code === code
	);
	if ( at === -1 ) {
		return platforms;
	}

	const targetAt = direction === 'up' ? at - 1 : at + 1;
	if ( targetAt < 0 || targetAt >= enabledIndexes.length ) {
		return platforms;
	}

	const from = enabledIndexes[ at ];
	const to = enabledIndexes[ targetAt ];
	const next = [ ...platforms ];
	next[ from ] = platforms[ to ];
	next[ to ] = platforms[ from ];
	return renumberDisplayOrder( next );
}
```

- [ ] **Step 4: テストが通ることを確認する**

Run: `npm run test:js -- tests/js/Admin/platformOrder.test.js`

Expected: PASS（15 tests）

- [ ] **Step 5: lint を通す**

Run: `npm run lint:js`

Expected: エラーなし

- [ ] **Step 6: コミット**

```bash
git add src/Admin/platformOrder.js tests/js/Admin/platformOrder.test.js
git commit -m "feat: プラットフォーム並べ替えの純関数モジュールを追加"
```

---

### Task 3: 設定画面に順位バッジと ↑ / ↓ を組み込み、「表示順」数値入力を撤去する

**Files:**

- Modify: `src/Admin/components/PlatformsPanel.jsx`
- Modify: `src/Admin/components/PlatformEditor.jsx`（「表示順」`TextControl` を削除）
- Modify: `assets/admin-settings.css`（行レイアウト・バッジ・無効行の淡色化）
- Test: `tests/js/components/PlatformsPanel.test.jsx`
- Test: `tests/js/components/PlatformEditor.test.jsx`（表示順入力のテストを削除）

**Interfaces:**

- Consumes: Task 2 の `movePlatform` / `enabledRanks`（`../platformOrder` から import）
- Produces: DOM 契約。Task 4（FLIP）と Task 5（E2E）が依存する。
  - 各行は `<div class="affilicard-platform-row" data-platform-code="{code}">`
  - 並べ替えボタンは `aria-label` が `${name}を上へ移動` / `${name}を下へ移動`
  - 行のラッパは `<div class="affilicard-platform-list" ref={listRef}>`（Task 4 がこの ref を使う）

- [ ] **Step 1: 失敗するテストを書く**

`tests/js/components/PlatformsPanel.test.jsx` の `describe( 'PlatformsPanel', ... )` の中（末尾の `} );` の直前）に追加する。既存の `platforms` 定数は `amazon` が `enabled: false` なので、並べ替え検証用に有効 3 件＋無効 1 件の別データを用意する。

```jsx
	// 並べ替え検証用: 有効 a(1) / 無効 b(2) / 有効 c(3)。
	// 無効行を挟むことで「無効を飛ばして有効同士が入れ替わる」ことを検証できる。
	const orderable = [
		{
			code: 'a',
			name: 'ストアA',
			provider: 'manual',
			enabled: true,
			displayOrder: 1,
			applicableTypes: [ 'ebook' ],
			buttonLabel: 'Aで読む',
			brandColor: '#444',
			buttonTextColor: '#fff',
		},
		{
			code: 'b',
			name: 'ストアB',
			provider: 'manual',
			enabled: false,
			displayOrder: 2,
			applicableTypes: [ 'ebook' ],
			buttonLabel: 'Bで読む',
			brandColor: '#444',
			buttonTextColor: '#fff',
		},
		{
			code: 'c',
			name: 'ストアC',
			provider: 'manual',
			enabled: true,
			displayOrder: 3,
			applicableTypes: [ 'ebook' ],
			buttonLabel: 'Cで読む',
			brandColor: '#444',
			buttonTextColor: '#fff',
		},
	];

	const renderOrderable = async () => {
		fetchPlatforms.mockResolvedValue( orderable );
		const view = render( <PlatformsPanel /> );
		await screen.findByText( /ストアA \(a\)/ );
		return view;
	};

	const rowCodes = ( container ) =>
		Array.from(
			container.querySelectorAll( '.affilicard-platform-row' )
		).map( ( row ) => row.dataset.platformCode );

	test( '並び順の説明文をタブ内に表示する', async () => {
		await renderOrderable();
		expect(
			screen.getByText( /この順番で商品カードのボタンが上から並びます/ )
		).toBeInTheDocument();
		expect(
			screen.getByText( /無効なプラットフォームはカードに表示されない/ )
		).toBeInTheDocument();
		expect(
			screen.getByText( /公開済みの記事のカードにも反映されます/ )
		).toBeInTheDocument();
	} );

	test( '有効な platform には順位バッジ、無効には — を出す', async () => {
		const { container } = await renderOrderable();
		const ranks = Array.from(
			container.querySelectorAll( '.affilicard-platform-row__rank' )
		).map( ( el ) => el.textContent );
		expect( ranks ).toEqual( [ '1', '—', '2' ] );
	} );

	test( '無効な platform には並べ替えボタンを出さない', async () => {
		await renderOrderable();
		expect(
			screen.queryByRole( 'button', { name: 'ストアBを上へ移動' } )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'ストアBを下へ移動' } )
		).not.toBeInTheDocument();
	} );

	test( '↓ を押すと無効行を飛ばして次の有効行と入れ替わる', async () => {
		const { container } = await renderOrderable();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'ストアAを下へ移動' } )
		);
		expect( rowCodes( container ) ).toEqual( [ 'c', 'b', 'a' ] );
	} );

	test( '↑ を押すと前の有効行と入れ替わる', async () => {
		const { container } = await renderOrderable();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'ストアCを上へ移動' } )
		);
		expect( rowCodes( container ) ).toEqual( [ 'c', 'b', 'a' ] );
	} );

	test( '先頭の ↑ と末尾の ↓ は disabled', async () => {
		await renderOrderable();
		expect(
			screen.getByRole( 'button', { name: 'ストアAを上へ移動' } )
		).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: 'ストアCを下へ移動' } )
		).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: 'ストアAを下へ移動' } )
		).toBeEnabled();
	} );

	test( '並べ替えの結果を aria-live で通知する', async () => {
		const { container } = await renderOrderable();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'ストアAを下へ移動' } )
		);
		expect(
			container.querySelector( '[aria-live="polite"]' )
		).toHaveTextContent( 'ストアAを 2 番目に移動しました' );
	} );

	test( '並べ替えて保存すると displayOrder が 1..N の連番で送られる', async () => {
		updatePlatforms.mockResolvedValue( orderable );
		await renderOrderable();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'ストアAを下へ移動' } )
		);
		fireEvent.click( screen.getByRole( 'button', { name: '保存' } ) );
		await waitFor( () => expect( updatePlatforms ).toHaveBeenCalled() );
		const sent = updatePlatforms.mock.calls[ 0 ][ 0 ];
		expect( sent.map( ( p ) => p.code ) ).toEqual( [ 'c', 'b', 'a' ] );
		expect( sent.map( ( p ) => p.displayOrder ) ).toEqual( [ 1, 2, 3 ] );
	} );

	test( 'Element.prototype.animate が無い環境でも並べ替えは成立する', async () => {
		// jsdom には animate が無い。アニメーションは装飾であり機能の前提にしない。
		expect( typeof Element.prototype.animate ).toBe( 'undefined' );
		const { container } = await renderOrderable();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'ストアAを下へ移動' } )
		);
		expect( rowCodes( container ) ).toEqual( [ 'c', 'b', 'a' ] );
	} );
```

- [ ] **Step 2: テストが落ちることを確認する**

Run: `npm run test:js -- tests/js/components/PlatformsPanel.test.jsx`

Expected: FAIL（`.affilicard-platform-row` が見つからない／`ストアAを下へ移動` ボタンが存在しない）

- [ ] **Step 3: `PlatformsPanel.jsx` を書き換える**

`src/Admin/components/PlatformsPanel.jsx` を次の内容に置き換える。

```jsx
import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Notice, TabPanel } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { fetchPlatforms, updatePlatforms } from '../api/platforms';
import { enabledRanks, movePlatform } from '../platformOrder';
import { PlatformEditor } from './PlatformEditor';
import { ApiCredentialsPanel } from './ApiCredentialsPanel';

const TYPE_LABELS = {
	generic: __( '汎用', 'affilicard' ),
	ebook: __( '電子書籍', 'affilicard' ),
	vod: __( 'VOD', 'affilicard' ),
};

const API_TAB = '__api__';

// platforms の applicableTypes から、1 件以上存在する型を出現順に抽出する。
// 注: applicableTypes が空/未設定の platform はどの型タブにも現れない
// （保存対象には含まれる）。シードは全 platform に applicableTypes を持つ前提。
function usedTypes( platforms ) {
	const seen = [];
	for ( const p of platforms ) {
		const types = Array.isArray( p.applicableTypes ) ? p.applicableTypes : [];
		for ( const t of types ) {
			if ( ! seen.includes( t ) ) {
				seen.push( t );
			}
		}
	}
	return seen;
}

export function PlatformsPanel() {
	const [ platforms, setPlatforms ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ announcement, setAnnouncement ] = useState( '' );
	const listRef = useRef( null );

	useEffect( () => {
		fetchPlatforms()
			.then( setPlatforms )
			.catch( () => setPlatforms( [] ) );
	}, [] );

	if ( platforms === null ) {
		return <p>{ __( '読み込み中…', 'affilicard' ) }</p>;
	}
	if ( platforms.length === 0 ) {
		return <p>{ __( 'プラットフォームがありません', 'affilicard' ) }</p>;
	}

	const onChange = ( idx ) => ( next ) => {
		const copy = [ ...platforms ];
		copy[ idx ] = next;
		setPlatforms( copy );
	};

	// ↑ / ↓ 押下。動かせなかったときは movePlatform が同一参照を返すので何もしない。
	const onMove = ( platform, type, direction, event ) => {
		const next = movePlatform( platforms, type, platform.code, direction );
		if ( next === platforms ) {
			return;
		}
		setPlatforms( next );
		setAnnouncement(
			sprintf(
				/* translators: 1: platform display name, 2: new position (1-based) */
				__( '%1$sを %2$d 番目に移動しました', 'affilicard' ),
				platform.name,
				enabledRanks( next, type )[ platform.code ]
			)
		);

		// 端に到達して押したボタン自身が disabled になると、フォーカスが body に落ちて
		// キーボード操作が途切れる。同じ行のもう一方のボタンへ移す。
		const button = event?.currentTarget;
		if ( ! button ) {
			return;
		}
		window.requestAnimationFrame( () => {
			if ( ! button.disabled ) {
				return;
			}
			const sibling = button.parentElement?.querySelector(
				'button:not([disabled])'
			);
			sibling?.focus();
		} );
	};

	const onSave = async () => {
		setSaving( true );
		setNotice( null );
		try {
			const next = await updatePlatforms( platforms );
			setPlatforms( next );
			setNotice( {
				type: 'success',
				message: __( '保存しました', 'affilicard' ),
			} );
		} catch {
			setNotice( {
				type: 'error',
				message: __( '保存に失敗しました', 'affilicard' ),
			} );
		} finally {
			setSaving( false );
		}
	};

	const types = usedTypes( platforms );
	const tabs = [
		...types.map( ( t ) => ( {
			name: t,
			title: TYPE_LABELS[ t ] ?? t,
		} ) ),
		{ name: API_TAB, title: __( 'API 認証', 'affilicard' ) },
	];

	return (
		<div className="affilicard-platforms-panel">
			<h2>{ __( 'プラットフォーム設定', 'affilicard' ) }</h2>
			{ notice && (
				<Notice status={ notice.type } onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }
			<TabPanel className="affilicard-platform-type-tabs" tabs={ tabs }>
				{ ( tab ) => {
					if ( tab.name === API_TAB ) {
						return <ApiCredentialsPanel />;
					}
					const ranks = enabledRanks( platforms, tab.name );
					const indexed = platforms
						.map( ( p, i ) => ( { p, i } ) )
						.filter(
							( { p } ) =>
								Array.isArray( p.applicableTypes ) &&
								p.applicableTypes.includes( tab.name )
						);
					const enabledCodes = indexed
						.filter( ( { p } ) => p.enabled )
						.map( ( { p } ) => p.code );
					return (
						<>
							<div className="affilicard-platforms-panel__order-help">
								<p>
									{ __(
										'この順番で商品カードのボタンが上から並びます。',
										'affilicard'
									) }
								</p>
								<ul>
									<li>
										{ __(
											'無効なプラットフォームはカードに表示されないため、この順番には含まれません。',
											'affilicard'
										) }
									</li>
									<li>
										{ __(
											'順番が意味を持つのはタブ（商品タイプ）の中だけです。',
											'affilicard'
										) }
									</li>
									<li>
										{ __(
											'「保存」を押すと、公開済みの記事のカードにも反映されます。',
											'affilicard'
										) }
									</li>
								</ul>
							</div>
							<div
								className="affilicard-platform-list"
								ref={ listRef }
							>
								{ indexed.map( ( { p, i }, localIdx ) => (
									<div
										className={
											p.enabled
												? 'affilicard-platform-row'
												: 'affilicard-platform-row affilicard-platform-row--disabled'
										}
										data-platform-code={ p.code }
										key={ p.code }
									>
										<div className="affilicard-platform-row__order">
											<span
												className="affilicard-platform-row__rank"
												aria-hidden="true"
											>
												{ ranks[ p.code ] ?? '—' }
											</span>
											{ p.enabled && (
												<>
													<Button
														icon="arrow-up-alt2"
														size="small"
														disabled={
															enabledCodes[ 0 ] ===
															p.code
														}
														label={ sprintf(
															/* translators: %s: platform display name */
															__(
																'%sを上へ移動',
																'affilicard'
															),
															p.name
														) }
														onClick={ ( event ) =>
															onMove(
																p,
																tab.name,
																'up',
																event
															)
														}
													/>
													<Button
														icon="arrow-down-alt2"
														size="small"
														disabled={
															enabledCodes[
																enabledCodes.length -
																	1
															] === p.code
														}
														label={ sprintf(
															/* translators: %s: platform display name */
															__(
																'%sを下へ移動',
																'affilicard'
															),
															p.name
														) }
														onClick={ ( event ) =>
															onMove(
																p,
																tab.name,
																'down',
																event
															)
														}
													/>
												</>
											) }
										</div>
										<div className="affilicard-platform-row__body">
											<PlatformEditor
												platform={ p }
												onChange={ onChange( i ) }
												initialOpen={ localIdx === 0 }
											/>
										</div>
									</div>
								) ) }
							</div>
							<div
								className="screen-reader-text"
								aria-live="polite"
							>
								{ announcement }
							</div>
							<div className="affilicard-platforms-panel__save">
								<Button
									variant="primary"
									onClick={ onSave }
									disabled={ saving }
								>
									{ saving
										? __( '保存中…', 'affilicard' )
										: __( '保存', 'affilicard' ) }
								</Button>
							</div>
						</>
					);
				} }
			</TabPanel>
		</div>
	);
}
```

- [ ] **Step 4: `PlatformEditor.jsx` から「表示順」入力を撤去する**

`src/Admin/components/PlatformEditor.jsx` の次のブロックを丸ごと削除する。

```jsx
				<TextControl
					label={__('表示順', 'affilicard')}
					type="number"
					value={String(platform.displayOrder ?? 1)}
					onChange={(v) =>
						update({ displayOrder: parseInt(v, 10) || 1 })
					}
				/>
```

「画像優先度（小さいほど優先）」の `TextControl` は**残す**（表示順とは別軸のため）。

- [ ] **Step 5: `PlatformEditor.test.jsx` から表示順入力のアサーションを外す**

`tests/js/components/PlatformEditor.test.jsx` の `renders all editor controls with platform values`（314 行目付近）から次の 1 行を削除する。

```jsx
		expect( screen.getByLabelText( '表示順' ) ).toBeInTheDocument();
```

同じテストの末尾（`expect( screen.getByLabelText( 'ボタン文字色' ) )...` の直後）に、撤去されたことを守る 1 行を足す。

```jsx
		// 表示順は PlatformsPanel の ↑ / ↓ に一本化したため、ここには無い
		expect( screen.queryByLabelText( '表示順' ) ).not.toBeInTheDocument();
```

- [ ] **Step 6: CSS を追加する**

`assets/admin-settings.css` の `#affilicard-settings-root .affilicard-platforms-panel__save` の定義の直前に追加する。

```css
/* 並べ替えの説明。何の順番なのかが画面から読み取れるよう、リストの直前に置く。 */
#affilicard-settings-root .affilicard-platforms-panel__order-help {
	margin: 0 0 12px;
	color: #50575e;
	font-size: 13px;
}

#affilicard-settings-root .affilicard-platforms-panel__order-help p {
	margin: 0 0 4px;
}

#affilicard-settings-root .affilicard-platforms-panel__order-help ul {
	margin: 0;
	padding-left: 1.2em;
	list-style: disc;
}

/* 1 行 = 並べ替えコントロール（左） + 既存のアコーディオン（右）。
   ↑ / ↓ は PanelBody のタイトル（button 要素）の中に入れられないため外側に出す。 */
#affilicard-settings-root .affilicard-platform-row {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	border-bottom: 1px solid #e0e0e0;
}

#affilicard-settings-root .affilicard-platform-row:last-child {
	border-bottom: 0;
}

#affilicard-settings-root .affilicard-platform-row__order {
	display: flex;
	flex: 0 0 auto;
	align-items: center;
	gap: 2px;
	padding-top: 10px;
}

#affilicard-settings-root .affilicard-platform-row__rank {
	display: inline-block;
	min-width: 1.5em;
	text-align: center;
	font-size: 12px;
	font-weight: 600;
	color: #50575e;
}

#affilicard-settings-root .affilicard-platform-row__body {
	flex: 1 1 auto;
	min-width: 0;
}

/* 無効なプラットフォームはカードに出ない＝並びに参加しないことを見た目でも示す */
#affilicard-settings-root .affilicard-platform-row--disabled {
	opacity: 0.6;
}
```

なお `.affilicard-platform-row` の宣言ブロックは 1 つにまとめること（同じセレクタを
二度書かない）。FLIP は `element.animate()` が transform を直接当てるため、
`will-change` や `transition` の追加は不要。

- [ ] **Step 7: テストが通ることを確認する**

Run: `npm run test:js`

Expected: 全 PASS（`PlatformsPanel.test.jsx` の新規 9 件を含む）

- [ ] **Step 8: lint と build を通す**

Run: `npm run lint:js && npm run build`

Expected: どちらもエラーなし

- [ ] **Step 9: コミット**

`build/` は `.gitignore` 対象（`.gitignore:12`）なのでコミットしない。

```bash
git add src/Admin/components/PlatformsPanel.jsx src/Admin/components/PlatformEditor.jsx assets/admin-settings.css tests/js/components/PlatformsPanel.test.jsx tests/js/components/PlatformEditor.test.jsx
git commit -m "feat: プラットフォーム設定に順位バッジと上下並べ替えボタンを追加"
```

---

### Task 4: 並べ替えアニメーション（FLIP）を追加する

**Files:**

- Create: `src/Admin/useFlipReorder.js`
- Modify: `src/Admin/components/PlatformsPanel.jsx`（フックを呼ぶ 2 行）
- Test: `tests/js/Admin/useFlipReorder.test.jsx`

**Interfaces:**

- Consumes: Task 3 の DOM 契約 — `listRef` が指す要素の子孫に `[data-platform-code]` を持つ行があること
- Produces: `useFlipReorder(containerRef: React.RefObject<HTMLElement>): void`
  — 引数はコンテナの ref のみ。戻り値なし。副作用は `element.animate()` の呼び出しだけ。

- [ ] **Step 1: 失敗するテストを書く**

`tests/js/Admin/useFlipReorder.test.jsx` を新規作成する。

```jsx
/**
 * Tests for src/Admin/useFlipReorder.js
 *
 * jsdom はレイアウトを持たず getBoundingClientRect が常に 0 を返すため、
 * 「実際に何 px 動いたか」は素の状態では検証できない。位置だけを差し替えた
 * スタブを当てて FLIP の呼び出し契約を検証し、それ以外は degrade を守る。
 */

import { useRef, useState } from '@wordpress/element';
import { render, fireEvent, screen } from '@testing-library/react';
import { useFlipReorder } from '../../../src/Admin/useFlipReorder';

function Rows( { codes, onSwap } ) {
	const ref = useRef( null );
	useFlipReorder( ref );
	return (
		<>
			<button type="button" onClick={ onSwap }>
				swap
			</button>
			<div ref={ ref }>
				{ codes.map( ( code ) => (
					<div key={ code } data-platform-code={ code }>
						{ code }
					</div>
				) ) }
			</div>
		</>
	);
}

function Harness( { initial } ) {
	const [ codes, setCodes ] = useState( initial );
	return (
		<Rows
			codes={ codes }
			onSwap={ () => setCodes( ( prev ) => [ ...prev ].reverse() ) }
		/>
	);
}

const originalMatchMedia = window.matchMedia;
const originalGetRect = Element.prototype.getBoundingClientRect;

/** 行の top を「親の中での並び順 × 100px」として返すスタブを当てる。 */
function stubLayout() {
	Element.prototype.getBoundingClientRect = function () {
		const siblings = Array.from( this.parentElement?.children ?? [] );
		return { top: siblings.indexOf( this ) * 100 };
	};
}

describe( 'useFlipReorder', () => {
	afterEach( () => {
		delete Element.prototype.animate;
		window.matchMedia = originalMatchMedia;
		Element.prototype.getBoundingClientRect = originalGetRect;
	} );

	test( 'animate が無い環境でも並べ替えで例外を投げない', () => {
		expect( typeof Element.prototype.animate ).toBe( 'undefined' );
		render( <Harness initial={ [ 'a', 'b' ] } /> );
		expect( () =>
			fireEvent.click( screen.getByRole( 'button', { name: 'swap' } ) )
		).not.toThrow();
	} );

	test( '並び順が変わると移動量ぶんの translateY からアニメートする', () => {
		const animate = jest.fn();
		Element.prototype.animate = animate;
		window.matchMedia = jest.fn().mockReturnValue( { matches: false } );
		stubLayout();

		render( <Harness initial={ [ 'a', 'b' ] } /> );
		expect( animate ).not.toHaveBeenCalled(); // 初回描画では動かさない

		fireEvent.click( screen.getByRole( 'button', { name: 'swap' } ) );

		expect( animate ).toHaveBeenCalledTimes( 2 );
		const offsets = animate.mock.calls.map(
			( [ keyframes ] ) => keyframes[ 0 ].transform
		);
		// a は 0px→100px（-100px から戻す）、b は 100px→0px（+100px から戻す）
		expect( offsets ).toContain( 'translateY(-100px)' );
		expect( offsets ).toContain( 'translateY(100px)' );
		expect( animate.mock.calls[ 0 ][ 1 ] ).toEqual( {
			duration: 180,
			easing: 'ease-in-out',
		} );
	} );

	test( '並び順が変わらない再描画ではアニメートしない', () => {
		const animate = jest.fn();
		Element.prototype.animate = animate;
		window.matchMedia = jest.fn().mockReturnValue( { matches: false } );
		stubLayout();

		const { rerender } = render( <Harness initial={ [ 'a', 'b' ] } /> );
		rerender( <Harness initial={ [ 'a', 'b' ] } /> );
		expect( animate ).not.toHaveBeenCalled();
	} );

	test( 'prefers-reduced-motion: reduce のとき animate を呼ばない', () => {
		const animate = jest.fn();
		Element.prototype.animate = animate;
		window.matchMedia = jest.fn().mockReturnValue( { matches: true } );
		stubLayout();

		render( <Harness initial={ [ 'a', 'b' ] } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'swap' } ) );
		expect( animate ).not.toHaveBeenCalled();
	} );
} );
```

- [ ] **Step 2: テストが落ちることを確認する**

Run: `npm run test:js -- tests/js/Admin/useFlipReorder.test.jsx`

Expected: FAIL（`Cannot find module '../../../src/Admin/useFlipReorder'`）

- [ ] **Step 3: フックを実装する**

`src/Admin/useFlipReorder.js` を新規作成する。

```js
/**
 * 並べ替え時に行が上下へ滑って入れ替わるアニメーション（FLIP）。
 *
 * アコーディオンの開閉で行の高さが変わるため、固定高を前提とした CSS transition では
 * 破綻する。FLIP（First-Last-Invert-Play）なら任意の高さで成立する。
 *
 * アニメーションは装飾であり、機能の前提にしない。Web Animations API が無い環境
 * （jsdom を含む）や prefers-reduced-motion: reduce の環境では黙ってスキップする。
 */

import { useLayoutEffect, useRef } from '@wordpress/element';

const DURATION_MS = 180;
const ROW_SELECTOR = '[data-platform-code]';

function prefersReducedMotion() {
	return (
		typeof window !== 'undefined' &&
		typeof window.matchMedia === 'function' &&
		window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches === true
	);
}

/**
 * @param {Object} containerRef 行を含む要素の ref
 */
export function useFlipReorder( containerRef ) {
	const positionsRef = useRef( null );
	const orderRef = useRef( null );

	// 依存配列を付けない＝毎描画で位置を測り直す。アコーディオンの開閉でも高さが
	// 変わるため、並べ替え時だけ測ると直前の位置が古くなって不自然に飛ぶ。
	useLayoutEffect( () => {
		const container = containerRef.current;
		if ( ! container ) {
			return;
		}

		const rows = Array.from( container.querySelectorAll( ROW_SELECTOR ) );
		const previousPositions = positionsRef.current;
		const previousOrder = orderRef.current;

		const positions = new Map();
		for ( const row of rows ) {
			positions.set(
				row.dataset.platformCode,
				row.getBoundingClientRect().top
			);
		}
		const order = rows.map( ( row ) => row.dataset.platformCode ).join( ',' );

		positionsRef.current = positions;
		orderRef.current = order;

		// 初回描画、または並び順が変わっていない再描画ではアニメートしない。
		if ( previousOrder === null || previousOrder === order ) {
			return;
		}
		if ( prefersReducedMotion() ) {
			return;
		}

		for ( const row of rows ) {
			if ( typeof row.animate !== 'function' ) {
				return;
			}
			const from = previousPositions.get( row.dataset.platformCode );
			const to = positions.get( row.dataset.platformCode );
			if ( from === undefined || from === to ) {
				continue;
			}
			row.animate(
				[
					{ transform: `translateY(${ from - to }px)` },
					{ transform: 'translateY(0)' },
				],
				{ duration: DURATION_MS, easing: 'ease-in-out' }
			);
		}
	} );
}
```

- [ ] **Step 4: `PlatformsPanel.jsx` からフックを呼ぶ**

import に追加する。

```jsx
import { useFlipReorder } from '../useFlipReorder';
```

`const listRef = useRef( null );` の直後に追加する。

```jsx
	useFlipReorder( listRef );
```

- [ ] **Step 5: テストが通ることを確認する**

Run: `npm run test:js`

Expected: 全 PASS。特に Task 3 で書いた「`Element.prototype.animate` が無い環境でも並べ替えは成立する」が引き続き通ること。

- [ ] **Step 6: lint と build を通す**

Run: `npm run lint:js && npm run build`

Expected: どちらもエラーなし

- [ ] **Step 7: コミット**

```bash
git add src/Admin/useFlipReorder.js src/Admin/components/PlatformsPanel.jsx tests/js/Admin/useFlipReorder.test.jsx
git commit -m "feat: プラットフォーム並べ替えに FLIP アニメーションを追加"
```

---

### Task 5: E2E で「描画順」と「設定 → 保存 → 反映」を通しで検証する

**Files:**

- Modify: `tests/e2e/seed.php`（逆順 listing の商品と投稿を追加、SEED_JSON にキーを追加）
- Create: `tests/e2e/platform-display-order.spec.js`

**Interfaces:**

- Consumes: Task 1 のレンダラー挙動、Task 3 の DOM 契約（`aria-label` = `${name}を上へ移動` / `${name}を下へ移動`）
- Produces: `artifacts/seed.json` に `displayOrderPostId`（number）が増える。既存キーは不変。

- [ ] **Step 1: seed に逆順 listing の商品を追加する**

`tests/e2e/seed.php` の `$available_post = $make_post( ... );` の行の直前に追加する。

```php
// 表示順テスト用。listing は displayOrder の逆順（楽天Kobo=3 → DMMブックス=1）で登録し、
// カードの CTA が登録順ではなく displayOrder 順に並ぶことを検証できるようにする。
$display_order_id = $repo->save(
	array(
		'title'        => 'E2E 表示順テスト商品',
		'status'       => 'publish',
		'product_type' => 'ebook',
		'stock_status' => 'available',
		'listings'     => array(
			array(
				'platform'      => 'rakuten-kobo',
				'enabled'       => true,
				'update_mode'   => 'manual',
				'auto_update'   => false,
				'affiliate_url' => 'https://example.com/aff-order-kobo',
				'regular_url'   => '',
			),
			array(
				'platform'      => 'dmm-books',
				'enabled'       => true,
				'update_mode'   => 'manual',
				'auto_update'   => false,
				'affiliate_url' => 'https://example.com/aff-order-dmm',
				'regular_url'   => '',
			),
		),
	)
);
```

`$future_post = $make_post( ... );` の直後に追加する。

```php
$display_order_post = $make_post( array( 'productId' => $display_order_id ) );
```

`SEED_JSON` の配列に 1 行足す。

```php
		'displayOrderPostId' => $display_order_post,
```

- [ ] **Step 2: E2E spec を書く**

`tests/e2e/platform-display-order.spec.js` を新規作成する。

```js
/**
 * E2E spec: プラットフォーム表示順
 *
 * 1. listing を displayOrder の逆順で登録した商品でも、CTA は displayOrder 順に並ぶ
 * 2. 設定画面の ↑ / ↓ で並べ替えて保存すると、公開済み記事のカードに反映される
 *
 * 2 は affilicard_platforms オプションを書き換えるため、他 spec に影響しないよう
 * 最後に元の並びへ戻す。
 */

'use strict';

const { test, expect } = require('@playwright/test');
const fs = require('fs');

const seed = JSON.parse(fs.readFileSync('artifacts/seed.json', 'utf8'));

const SETTINGS_URL =
	'/wp-admin/edit.php?post_type=affilicard_product&page=affilicard-settings';

/** 先頭カード内の CTA ボタンのラベルを上から順に返す。 */
async function ctaLabels(page) {
	return page
		.locator('.affilicard-card')
		.first()
		.locator('a.affilicard-card__cta')
		.allTextContents();
}

/** 設定 → プラットフォーム → 電子書籍タブを開く。 */
async function openEbookTab(page) {
	await page.goto(SETTINGS_URL);
	await page.getByRole('tab', { name: 'プラットフォーム' }).click();
	await page.getByRole('tab', { name: '電子書籍' }).click();
	await expect(
		page.getByText(/この順番で商品カードのボタンが上から並びます/)
	).toBeVisible({ timeout: 15_000 });
}

test('listing の登録順が逆でも CTA は displayOrder 順に並ぶ', async ({
	page,
}) => {
	await page.goto(`/?p=${seed.displayOrderPostId}`);
	await expect(page.locator('.affilicard-card').first()).toBeVisible();
	expect(await ctaLabels(page)).toEqual([
		'DMMブックスで読む',
		'楽天Koboで読む',
	]);
});

test('設定画面で並べ替えて保存すると公開記事のカードに反映される', async ({
	page,
}) => {
	// 電子書籍タブの既定は DMMブックス(1) / Amazon Kindle(2) / 楽天Kobo(3)。
	// この商品は DMM と楽天Kobo の listing しか持たないため、DMM を 1 回だけ下げても
	// 入れ替わる相手は Amazon で CTA の並びは変わらない。2 回下げて末尾へ動かす。
	await openEbookTab(page);
	const down = page.getByRole('button', { name: 'DMMブックスを下へ移動' });
	await down.click();
	await down.click();
	await page.getByRole('button', { name: '保存' }).click();
	await expect(page.getByText('保存しました')).toBeVisible();

	await page.goto(`/?p=${seed.displayOrderPostId}`);
	expect(await ctaLabels(page)).toEqual([
		'楽天Koboで読む',
		'DMMブックスで読む',
	]);

	// 他 spec に影響しないよう元の並びへ戻す（2 回上げて先頭へ）
	await openEbookTab(page);
	const up = page.getByRole('button', { name: 'DMMブックスを上へ移動' });
	await up.click();
	await up.click();
	await page.getByRole('button', { name: '保存' }).click();
	await expect(page.getByText('保存しました')).toBeVisible();

	await page.goto(`/?p=${seed.displayOrderPostId}`);
	expect(await ctaLabels(page)).toEqual([
		'DMMブックスで読む',
		'楽天Koboで読む',
	]);
});
```

- [ ] **Step 3: wp-env を起動して E2E を実行する**

Run: `npm run env:start && npm run test:e2e -- platform-display-order.spec.js`

Expected: 2 tests PASS

`artifacts/seed.json` に `displayOrderPostId` が無い場合は seed が古い。`npm run env:stop && npm run env:start` でやり直す。

- [ ] **Step 4: E2E スイート全体が通ることを確認する**

Run: `npm run test:e2e`

Expected: 全 PASS（既存 spec が並び順変更の影響を受けていないこと）

- [ ] **Step 5: コミット**

```bash
git add tests/e2e/seed.php tests/e2e/platform-display-order.spec.js
git commit -m "test: プラットフォーム表示順の E2E を追加"
```

---

### Task 6: v2.5.0 へバージョンを上げ、CHANGELOG を書く

**Files:**

- Modify: `affilicard.php`（6 行目付近の `Version:` ヘッダ）
- Modify: `package.json`（`version`）
- Modify: `CHANGELOG.md`

**Interfaces:**

- Consumes: Task 1〜5 の成果
- Produces: リリース可能な状態。`affilicard.php` の `Version:` と `package.json` の `version` が
  同一コミットで `2.5.0` に揃っていること（更新チェッカがタグのツリーのヘッダを読むため）。

- [ ] **Step 1: `affilicard.php` の Version ヘッダを上げる**

```diff
- * Version:     2.4.0
+ * Version:     2.5.0
```

- [ ] **Step 2: `package.json` の version を上げる**

```diff
-	"version": "2.4.0",
+	"version": "2.5.0",
```

- [ ] **Step 3: CHANGELOG に 2.5.0 の節を追加する**

`## [Unreleased]` の直後に挿入する。

```markdown
## [2.5.0] - 2026-07-26

### Fixed

- **商品カードの CTA ボタンの並びを、listing の登録順ではなくプラットフォーム設定の「表示順」で決めるようにした**。従来は商品メタ `affilicard_listings` の登録順でそのまま描画していたため、記事生成後に別ストアの listing を追記する運用では追記分が末尾に付き、同一記事内でカードごとにボタン位置が食い違っていた。`CardRenderer` が表示対象 listing を `displayOrder` 昇順（同値は登録順を保つ安定ソート）に並べ替えるようになり、公開済みの記事も再投稿なしで揃う。

### Added

- **プラットフォーム設定に並べ替え UI を追加**した。各商品タイプタブで、行の左に「カード上で何番目に出るか」を示す順位バッジと ↑ / ↓ ボタンを置き、押すと行が上下に滑って入れ替わる（FLIP アニメーション。`prefers-reduced-motion: reduce` では即座に反映）。保存時に `displayOrder` を 1..N の連番へ正規化する。
- 並べ替えリストの直前に説明を追加した。「この順番で商品カードのボタンが上から並びます」「無効なプラットフォームはカードに表示されないため、この順番には含まれません」「順番が意味を持つのはタブ（商品タイプ）の中だけです」「『保存』を押すと、公開済みの記事のカードにも反映されます」の 4 点を明示する。
- 並べ替えボタンにはプラットフォーム名を含む `aria-label` を付け、移動結果を `aria-live="polite"` で通知する。端に到達してボタンが無効化された場合は、同じ行のもう一方のボタンへフォーカスを移す。

### Changed

- 無効なプラットフォームは並べ替えの対象外とし、淡色表示・順位バッジ `—`・↑ / ↓ 非表示にした。`displayOrder` の値自体は保持するため、再有効化すれば元の位置に戻る。

### Removed

- プラットフォーム設定の各アコーディオン内にあった「表示順」の数値入力を撤去した。↑ / ↓ と二重の入力口になり、値が食い違うと並びが壊れるため。
```

- [ ] **Step 4: 全テストを流す**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit && npm run test:js && npm run lint:js && npm run build`

Expected: すべてエラーなし

- [ ] **Step 5: コミット**

```bash
git add affilicard.php package.json CHANGELOG.md
git commit -m "chore: v2.5.0 へバージョンを上げ CHANGELOG を更新"
```

---

### Task 7: 書影の選択も表示順に従わせ、imagePriority を撤去して v3.0.0 にする

**Files:**

- Modify: `src/Renderer/CardRenderer.php`（`selectCardImage()` と 51 行目付近の呼び出し）
- Modify: `src/Platform/PlatformDefinition.php`（コンストラクタ引数・`toArray()`・`fromArray()`）
- Modify: `src/Platform/PlatformConfig.php`（`defaults()` の `imagePriority: 10 / 20 / 30`）
- Modify: `src/Admin/components/PlatformEditor.jsx`（「画像優先度（小さいほど優先）」入力）
- Modify: `affilicard.php` / `package.json` / `package-lock.json` / `CHANGELOG.md`
- Modify: `docs/superpowers/specs/2026-07-18-card-image-platform-priority-design.md`（廃止note）
- Test: `tests/Unit/Renderer/CardRendererTest.php`
- Test: `tests/Unit/Platform/PlatformDefinitionTest.php`
- Test: `tests/js/components/PlatformEditor.test.jsx`

**Interfaces:**

- Consumes: Task 1 の `visibleListings()`（`displayOrder` 昇順・同値は登録順の安定ソート済み）
- Produces:
  - `CardRenderer::selectCardImage( array $visibleListings, string $fallback ): string`
    — 第 2 引数の `$by_code` を**削除**する（表示順は既に配列順に反映済みで platform 定義を引く必要がない）
  - `PlatformDefinition` のコンストラクタ引数から `imagePriority` を削除。
    残る並びは `code, name, provider, displayOrder, enabled, applicableTypes, buttonLabel,
    brandColor, buttonTextColor, eligibleProvider = '', priceTtlHours = 24`
  - `toArray()` の出力キーから `imagePriority` が消える（`fromArray()` も読まない）

- [ ] **Step 1: 失敗するテストを書く（PHP）**

`tests/Unit/Renderer/CardRendererTest.php` の末尾（最後の `}` の直前）に追加する。
Task 1 で追加済みの `orderedPlatform()` ヘルパを再利用する。

```php
	public function test_card_image_comes_from_the_first_platform_in_display_order(): void {
		$platforms = array(
			$this->orderedPlatform( 'store-a', 'ストアA', 1 ),
			$this->orderedPlatform( 'store-b', 'ストアB', 2 ),
		);
		// listing の登録順は逆（B → A）。表示順の先頭は A なので A の画像が選ばれる。
		$product = array(
			'title'        => 'テスト商品',
			'stock_status' => 'available',
			'listings'     => array(
				array(
					'platform'      => 'store-b',
					'enabled'       => true,
					'affiliate_url' => 'https://example.test/store-b',
					'image_url'     => 'https://cdn.test/b.jpg',
				),
				array(
					'platform'      => 'store-a',
					'enabled'       => true,
					'affiliate_url' => 'https://example.test/store-a',
					'image_url'     => 'https://cdn.test/a.jpg',
				),
			),
		);
		$html = ( new CardRenderer() )->render( $product, $platforms, array( 'image_url' => 'https://cdn.test/eyecatch.jpg' ) );
		$this->assertStringContainsString( 'https://cdn.test/a.jpg', $html );
		$this->assertStringNotContainsString( 'https://cdn.test/b.jpg', $html );
	}

	public function test_card_image_falls_back_to_next_listing_when_first_has_no_image(): void {
		$platforms = array(
			$this->orderedPlatform( 'store-a', 'ストアA', 1 ),
			$this->orderedPlatform( 'store-b', 'ストアB', 2 ),
		);
		$product = array(
			'title'        => 'テスト商品',
			'stock_status' => 'available',
			'listings'     => array(
				array(
					'platform'      => 'store-a',
					'enabled'       => true,
					'affiliate_url' => 'https://example.test/store-a',
					'image_url'     => '',
				),
				array(
					'platform'      => 'store-b',
					'enabled'       => true,
					'affiliate_url' => 'https://example.test/store-b',
					'image_url'     => 'https://cdn.test/b.jpg',
				),
			),
		);
		$html = ( new CardRenderer() )->render( $product, $platforms, array( 'image_url' => 'https://cdn.test/eyecatch.jpg' ) );
		$this->assertStringContainsString( 'https://cdn.test/b.jpg', $html );
	}

	public function test_card_image_falls_back_to_featured_image_when_no_listing_has_one(): void {
		$platforms = array( $this->orderedPlatform( 'store-a', 'ストアA', 1 ) );
		$product   = array(
			'title'        => 'テスト商品',
			'stock_status' => 'available',
			'listings'     => array(
				array(
					'platform'      => 'store-a',
					'enabled'       => true,
					'affiliate_url' => 'https://example.test/store-a',
				),
			),
		);
		$html = ( new CardRenderer() )->render( $product, $platforms, array( 'image_url' => 'https://cdn.test/eyecatch.jpg' ) );
		$this->assertStringContainsString( 'https://cdn.test/eyecatch.jpg', $html );
	}
```

`tests/Unit/Platform/PlatformDefinitionTest.php` の末尾に追加する。

```php
	public function test_toArray_has_no_image_priority_key(): void {
		$def = new PlatformDefinition( 'store-a', 'ストアA', 'manual', 1, true, array( 'ebook' ), 'Aで読む', '#444444', '#ffffff' );
		$this->assertArrayNotHasKey( 'imagePriority', $def->toArray() );
	}

	public function test_fromArray_ignores_leftover_image_priority_from_old_installs(): void {
		// 旧バージョンで保存された option には imagePriority キーが残る。読み捨てて壊れないこと。
		$def = PlatformDefinition::fromArray(
			array(
				'code'          => 'store-a',
				'name'          => 'ストアA',
				'displayOrder'  => 2,
				'imagePriority' => 10,
			)
		);
		$this->assertSame( 'store-a', $def->code );
		$this->assertSame( 2, $def->displayOrder );
		$this->assertArrayNotHasKey( 'imagePriority', $def->toArray() );
	}
```

- [ ] **Step 2: 既存の imagePriority 依存テストを外す（PHP）**

以下を削除・修正する。**削除だけで済ませず、消えた検証の代わりが Step 1 のテストで担保されていることを確認すること。**

1. `tests/Unit/Renderer/CardRendererTest.php` の `test_card_image_tiebreak_prefers_lower_display_order_on_equal_priority`
   — `imagePriority` 同値タイブレークという前提が消えるので**テストごと削除**する。
   代わりの検証は Step 1 の `test_card_image_comes_from_the_first_platform_in_display_order`。
2. 同ファイルの `bookPlatforms()` ヘルパから `imagePriority: 10 / 20 / 30` の名前付き引数を削除する。
3. `tests/Unit/Platform/PlatformDefinitionTest.php` の
   `test_imagePriority_defaults_to_999_when_absent` /
   `test_imagePriority_roundtrips_through_fromArray_and_toArray` /
   `test_defaults_set_image_priority_for_book_platforms` を削除する。
4. `grep -rn "imagePriority" tests/` を実行し、残ったヒットをすべて解消する。

- [ ] **Step 3: テストが落ちることを確認する**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter 'test_card_image_comes_from_the_first_platform_in_display_order|test_toArray_has_no_image_priority_key'`

Expected: FAIL（書影は現状 `imagePriority` で選ばれ、`toArray()` には `imagePriority` キーが残っているため）

- [ ] **Step 4: `selectCardImage()` を書き換える**

`src/Renderer/CardRenderer.php` の `selectCardImage()` を丸ごと次で置き換える。

```php
	/**
	 * 表示中 listing を表示順（displayOrder 昇順）の先頭から走査し、image_url が非空の
	 * 最初の 1 件を書影に採る。どれにも無ければ $fallback（WP アイキャッチ）。
	 *
	 * 書影の選択順は CTA ボタンの並びと同じ「表示順」に従う。かつて存在した imagePriority
	 * （書影だけを別の優先度で選ぶ設定）は撤去した。設定はあるのに描画へ効かない値を
	 * 増やさないため、順序の概念を displayOrder 1 本に統合する。
	 *
	 * @param list<array<string, mixed>> $visibleListings visibleListings() の戻り（displayOrder 昇順）
	 */
	private function selectCardImage( array $visibleListings, string $fallback ): string {
		foreach ( $visibleListings as $listing ) {
			$img = isset( $listing['image_url'] ) ? trim( (string) $listing['image_url'] ) : '';
			// esc_url_raw は javascript: 等の危険スキームを空文字にする。空になった listing は飛ばす。
			$img = esc_url_raw( $img );
			if ( '' !== $img ) {
				return $img;
			}
		}
		return $fallback;
	}
```

あわせて呼び出し側（51 行目付近）から `$by_code` を外す。

```php
		$image_url = $this->selectCardImage( $visible_listings, $fallback_image );
```

- [ ] **Step 5: `imagePriority` をデータモデルから撤去する**

1. `src/Platform/PlatformDefinition.php`
   - コンストラクタから `public readonly int $imagePriority = 999,` の行を削除
   - `toArray()` から `'imagePriority'    => $this->imagePriority,` の行を削除
   - `fromArray()` から `$image_priority = isset( $data['imagePriority'] ) ? (int) $data['imagePriority'] : 999;` の行と、`new self(...)` に渡している `$image_priority,` の行を削除
2. `src/Platform/PlatformConfig.php` の `defaults()` から `imagePriority: 10,` / `imagePriority: 20,` / `imagePriority: 30,` の 3 行を削除
3. `grep -rn "imagePriority" src/` を実行し、残ったヒットをすべて解消する

- [ ] **Step 6: 管理画面から「画像優先度」入力を撤去する**

`src/Admin/components/PlatformEditor.jsx` から次のブロックを丸ごと削除する。

```jsx
				<TextControl
					label={ __( '画像優先度（小さいほど優先）', 'affilicard' ) }
					type="number"
					value={ String( platform.imagePriority ?? 999 ) }
					onChange={ ( v ) => {
						const value = parseInt( v, 10 );
						update( {
							imagePriority: Number.isNaN( value ) ? 999 : value,
						} );
					} }
				/>
```

（実ファイルの整形は Prettier に従っているため、上と細部が異なる可能性がある。`画像優先度` を含む `TextControl` のブロックを削除する、と読むこと。）

`tests/js/components/PlatformEditor.test.jsx` を直す。

1. フィクスチャ（33 行目付近）から `imagePriority: 10,` を削除
2. `renders imagePriority input with platform value` /
   `onChange propagates imagePriority patch to parent` /
   `onChange keeps 0 as a valid imagePriority instead of falling back to 999` の 3 テストを削除
3. `renders all editor controls with platform values` テストの末尾（`表示順` の不在アサーションの隣）に次を足す

```jsx
		// 画像優先度は撤去し、書影も表示順（↑ / ↓）に従うようにした
		expect(
			screen.queryByLabelText( '画像優先度（小さいほど優先）' )
		).not.toBeInTheDocument();
```

4. `grep -rn "imagePriority" tests/js/` を実行し、残ったヒットをすべて解消する

- [ ] **Step 7: テストが通ることを確認する**

Run:

```bash
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpcs
npm run test:js
npm run lint:js
npm run build
```

Expected: すべてエラー 0。phpcs は ERRORS 0 / WARNINGS 0。

- [ ] **Step 8: バージョンを v3.0.0 に上げ直す**

Task 6 で 2.5.0 にしてあるものを 3.0.0 にする。

1. `affilicard.php` の `Version:     2.5.0` → `Version:     3.0.0`（`AFFILICARD_VERSION` 定数も同じ値に）
2. `package.json` の `"version": "2.5.0"` → `"3.0.0"`
3. `package-lock.json` の `version`（ルートと `packages[""]` の 2 箇所）を `3.0.0` に。
   **`npm install` は走らせず手で直す**（依存の解決結果を変えないため）。
   直したら `git diff package-lock.json` で version 以外が変わっていないことを確認する
4. `grep -n "2\.5\.0" affilicard.php package.json package-lock.json` で取り残しが無いことを確認する

- [ ] **Step 9: CHANGELOG を 3.0.0 に書き換える**

`## [2.5.0] - 2026-07-26` の見出しを `## [3.0.0] - 2026-07-27` に変え、既存の Fixed / Added / Changed / Removed
の各項目はそのまま残したうえで、次を追記する。

`### Fixed` の末尾に追加:

```markdown
- **商品カードのサムネイル（書影）もプラットフォーム設定の「表示順」で選ぶようにした**。表示順の先頭から走査し、書影 URL を持つ最初の listing の画像を採る（無ければ次の listing、どれにも無ければ WordPress のアイキャッチへフォールバック）。CTA ボタンの並びと書影の出所が一致する。
```

`### Removed` の末尾に追加:

```markdown
- **破壊的変更**: プラットフォームの `imagePriority`（画像優先度）を撤去した。書影の選択を「表示順」に統合したため役目を失ったもので、設定として存在するのに描画へ効かない値を残さない判断による。`PlatformDefinition` のコンストラクタ引数・プロパティと `GET/PUT /affilicard/v1/platforms` のペイロードからキーが消える。既存インストールのオプションに残った値は読み捨てられ、次回保存時に消える（マイグレーション不要）。「ボタンの並びとは別の優先度で書影を選ぶ」使い分けはできなくなる。
```

- [ ] **Step 10: 旧設計書に廃止note を入れる**

`docs/superpowers/specs/2026-07-18-card-image-platform-priority-design.md` の冒頭（最初の見出しの直後）に 1 行入れる。

```markdown
> **廃止（2026-07-27 / v3.0.0）**: 本設計の `imagePriority` は撤去した。書影の選択は
> プラットフォーム設定の「表示順」に統合されている。
> 現行設計は `2026-07-26-platform-display-order-design.md` を参照。
```

- [ ] **Step 11: 全テストを流してコミット**

Run:

```bash
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpcs
npm run test:js && npm run lint:js && npm run build
```

Expected: すべてエラー 0

```bash
git add src tests affilicard.php package.json package-lock.json CHANGELOG.md docs
git commit -m "feat!: 商品カードの書影も表示順で選ぶようにし imagePriority を撤去する"
```

**注意**: `npm run test:e2e` はこのタスクでは実行しない（wp-env の状態を保つため。E2E は最終レビュー前にまとめて回す）。

---

## 完了条件

- [ ] `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit` が全 PASS
- [ ] `npm run test:js` が全 PASS
- [ ] `npm run lint:js` がエラーなし
- [ ] `npm run build` が成功
- [ ] `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpcs` が ERRORS 0 / WARNINGS 0
- [ ] `npm run test:e2e` が全 PASS
- [ ] `affilicard.php` の `Version:` と `package.json` の `version` が `3.0.0` で一致
- [ ] `imagePriority` が実ロジックから消えている（`PlatformDefinition` のコンストラクタ・`toArray()`・
  `fromArray()`、`PlatformConfig::defaults()`、`selectCardImage()`、管理画面 UI）。
  撤去の経緯を説明する docblock コメントと、「キーが無いこと」「旧データを読み捨てること」を
  検証するテストに名前が残るのは正しい状態
