# 購入リンクの複数保持と優先表示 実装計画

> **For agentic workers:** This plan is executed task by task. Steps use checkbox (`- [ ]`) syntax and are tracked as you go.

**Goal:** listing を「1 platform = 1 SKU」から「複数の購入リンク（offer）を表示優先順で保持する」形へ変更し、先頭が使えなくなったら次へ自動的に倒れるようにする。

**Architecture:** listing には設定フィールドだけを残し、取得結果は `offers[]` へ移す。「どの offer を使うか」を答える選択係を 1 つ作り、描画も価格更新も**その答えだけ**を見る。恒久エラー時に次へ倒すかは設定（既定 OFF）で切り替える。

**Tech Stack:** PHP 8.2 / WordPress / PHPUnit 9.6 + WP_Mock 1.x / React（`@wordpress/components`）/ Jest（wp-scripts）/ Playwright（wp-env）

**Spec:** [`docs/superpowers/specs/2026-09-11-listing-purchase-links-design.md`](../specs/2026-09-11-listing-purchase-links-design.md)

## Global Constraints

- **バージョンは v4.0.0**（MAJOR）。`affilicard.php` の `Version:` ヘッダと `package.json` の `version` を**同じコミットで**揃える（揃えないと自動更新が検知しない）
- **既定の挙動を変えない。** フォールバック設定は既定 OFF。移行後の `offers` は 1 件で、選択係は常にその 1 件を返す
- **テストメソッド名は日本語**（例: `test_表示順が小さい順に並ぶ`）。既存の `tests/Unit/Pricing/PriceFreshnessTest.php` に倣う
- **配列は `array()` 記法・インデントはタブ**（WPCS）。`declare(strict_types=1);` を全ファイル先頭に置く
- **PHP は Docker で実行**する。ローカルに PHP を入れない
  - テスト: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit`
  - Lint: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpcs`
- **JS はローカル volta** で実行する: `npm run test:js` / `npm run lint:js`
- **公開リポジトリである。** コミットメッセージ・コメント・ドキュメントに連携先の非公開リポ名やスキル名を書かない。「外部の運用ツール」等の汎用表現にする
- **`SchemaVersion::CURRENT`（商品ごとの meta）と `GeneralSettings::DEFAULTS['schema_version']`（設定オプションの版数）は別物。** 本計画で上げるのは**前者だけ**

---

### Task 1: FetchStatus（取得状態のコード）

`fetch_error` の日本語文言を廃止し、コードで状態を持つ。文言は表示時に生成する。

**Files:**
- Create: `src/Pricing/FetchStatus.php`
- Test: `tests/Unit/Pricing/FetchStatusTest.php`

**Interfaces:**
- Consumes: なし
- Produces:
  - `FetchStatus::NONE = ''` / `UNSUPPORTED = 'unsupported'` / `TRANSIENT = 'transient'` / `TERMINAL = 'terminal'`
  - `FetchStatus::isTerminal( string $status ): bool`
  - `FetchStatus::label( string $status ): string` — 管理画面向けの日本語文言
  - `FetchStatus::fromLegacyMessage( string $message ): string` — 移行用。未知の文言は `TRANSIENT`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Pricing/FetchStatusTest.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Pricing;

use Affilicard\Pricing\FetchStatus;
use PHPUnit\Framework\TestCase;

final class FetchStatusTest extends TestCase {

	public function test_terminalだけがisTerminalで真になる(): void {
		$this->assertTrue( FetchStatus::isTerminal( FetchStatus::TERMINAL ) );
		$this->assertFalse( FetchStatus::isTerminal( FetchStatus::TRANSIENT ) );
		$this->assertFalse( FetchStatus::isTerminal( FetchStatus::UNSUPPORTED ) );
		$this->assertFalse( FetchStatus::isTerminal( FetchStatus::NONE ) );
	}

	public function test_未知の値はisTerminalで偽になる(): void {
		// 想定外の値で購入リンクを飛ばすのは危険side。飛ばさない側へ倒す。
		$this->assertFalse( FetchStatus::isTerminal( 'とつぜんの値' ) );
	}

	public function test_成功は文言を返さない(): void {
		$this->assertSame( '', FetchStatus::label( FetchStatus::NONE ) );
	}

	public function test_各状態に文言がある(): void {
		$this->assertSame( '自動取得の対象外です', FetchStatus::label( FetchStatus::UNSUPPORTED ) );
		$this->assertSame( '一時的に取得できませんでした', FetchStatus::label( FetchStatus::TRANSIENT ) );
		$this->assertSame( '商品が見つかりません', FetchStatus::label( FetchStatus::TERMINAL ) );
	}

	public function test_旧文言をコードへ写像する(): void {
		$this->assertSame( FetchStatus::UNSUPPORTED, FetchStatus::fromLegacyMessage( '対応する自動 Provider がありません' ) );
		$this->assertSame( FetchStatus::TERMINAL, FetchStatus::fromLegacyMessage( '該当する商品が見つかりませんでした' ) );
		$this->assertSame( FetchStatus::TRANSIENT, FetchStatus::fromLegacyMessage( '価格情報の取得に失敗しました' ) );
		$this->assertSame( FetchStatus::NONE, FetchStatus::fromLegacyMessage( '' ) );
	}

	public function test_未知の旧文言はtransientへ倒す(): void {
		// 恒久と誤認して購入リンクを飛ばすより、飛ばさない側が安全。
		$this->assertSame( FetchStatus::TRANSIENT, FetchStatus::fromLegacyMessage( '手で書き換えられた文言' ) );
	}
}
```

- [ ] **Step 2: テストが落ちることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter FetchStatusTest`
Expected: FAIL — `Class "Affilicard\Pricing\FetchStatus" not found`

- [ ] **Step 3: 最小の実装を書く**

`src/Pricing/FetchStatus.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Pricing;

/**
 * 購入リンク（offer）の取得状態。
 *
 * 以前は日本語の文言を meta に保存していたが、(a) 翻訳結果が固定される
 * (b) 外部から文字列でしか判定できない (c)「自動取得の対象外」が一時失敗に
 * 潰れる、の 3 点があったためコードで持つ。文言は表示時に生成する。
 */
final class FetchStatus {

	/** 成功。 */
	public const NONE = '';

	/** 自動取得の対象外（Provider 未対応・external_id 空）。恒久だがリトライ分類は transient。 */
	public const UNSUPPORTED = 'unsupported';

	/** 一時失敗（API 到達不可・認証未設定・レート制限）。 */
	public const TRANSIENT = 'transient';

	/** 恒久失敗（該当なし・無効 ID）。選択係はこれだけを飛ばす。 */
	public const TERMINAL = 'terminal';

	/**
	 * 選択係が飛ばすべき状態か。
	 *
	 * **未知の値は false を返す。** 想定外の値で購入リンクを飛ばすと、生きている
	 * リンクを勝手に切り替えることになる。飛ばさない側へ倒すのが安全である。
	 */
	public static function isTerminal( string $status ): bool {
		return self::TERMINAL === $status;
	}

	/** 管理画面に出す文言。表示時に生成するため保存しない。 */
	public static function label( string $status ): string {
		switch ( $status ) {
			case self::UNSUPPORTED:
				return (string) __( '自動取得の対象外です', 'affilicard' );
			case self::TRANSIENT:
				return (string) __( '一時的に取得できませんでした', 'affilicard' );
			case self::TERMINAL:
				return (string) __( '商品が見つかりません', 'affilicard' );
			default:
				return '';
		}
	}

	/**
	 * 移行用。v3 以前に保存された `fetch_error` の文言をコードへ写像する。
	 *
	 * **いずれにも一致しない値は TRANSIENT へ倒す。** 恒久と誤認して購入リンクを
	 * 飛ばすより安全である。
	 */
	public static function fromLegacyMessage( string $message ): string {
		$message = trim( $message );
		if ( '' === $message ) {
			return self::NONE;
		}
		if ( '対応する自動 Provider がありません' === $message ) {
			return self::UNSUPPORTED;
		}
		if ( '該当する商品が見つかりませんでした' === $message ) {
			return self::TERMINAL;
		}
		return self::TRANSIENT;
	}
}
```

> **注意**: `label()` は `__()` を呼ぶ。unit test では WP_Mock の passthru が要る。`tests/bootstrap.php` に既存の `__()` スタブがあるか確認し、無ければ `WP_Mock::userFunction( '__' )->andReturnArg( 0 )` をテストの `setUp()` で仕込む（既存テストの書き方に合わせる）。

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter FetchStatusTest`
Expected: PASS（6 tests）

- [ ] **Step 5: Lint とコミット**

```bash
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpcs src/Pricing/FetchStatus.php tests/Unit/Pricing/FetchStatusTest.php
git add src/Pricing/FetchStatus.php tests/Unit/Pricing/FetchStatusTest.php
git commit -m "feat: 購入リンクの取得状態をコードで持つ FetchStatus を追加"
```

---

### Task 2: OfferSelector（選択係）

「この listing でどの購入リンクを使うか」を答える唯一の場所。描画も更新もここだけを見る。

**Files:**
- Create: `src/Pricing/OfferSelector.php`
- Test: `tests/Unit/Pricing/OfferSelectorTest.php`

**Interfaces:**
- Consumes: `FetchStatus::isTerminal()`（Task 1）
- Produces: `OfferSelector::select( array $offers, bool $fallbackEnabled ): array` — **常に配列**を返す。現時点では 0 件または 1 件

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Pricing/OfferSelectorTest.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Pricing;

use Affilicard\Pricing\FetchStatus;
use Affilicard\Pricing\OfferSelector;
use PHPUnit\Framework\TestCase;

final class OfferSelectorTest extends TestCase {

	/** @return array<string, mixed> */
	private function offer( int $order, string $id, string $status = FetchStatus::NONE ): array {
		return array(
			'display_order' => $order,
			'external_id'   => $id,
			'regular_url'   => 'https://example.test/' . $id,
			'fetch_status'  => $status,
		);
	}

	public function test_空なら空配列を返す(): void {
		$this->assertSame( array(), OfferSelector::select( array(), true ) );
	}

	public function test_表示順が小さいものを選ぶ(): void {
		$offers = array( $this->offer( 100, 'b' ), $this->offer( 10, 'a' ) );
		$got    = OfferSelector::select( $offers, false );
		$this->assertCount( 1, $got );
		$this->assertSame( 'a', $got[0]['external_id'] );
	}

	public function test_同値なら配列の出現順を保つ(): void {
		$offers = array( $this->offer( 10, 'first' ), $this->offer( 10, 'second' ) );
		$got    = OfferSelector::select( $offers, false );
		$this->assertSame( 'first', $got[0]['external_id'] );
	}

	public function test_display_order未指定は100として扱う(): void {
		$offers = array(
			array( 'external_id' => 'default', 'regular_url' => 'https://example.test/d' ),
			$this->offer( 10, 'explicit' ),
		);
		$got = OfferSelector::select( $offers, false );
		$this->assertSame( 'explicit', $got[0]['external_id'] );
	}

	public function test_設定OFFならterminalでも先頭を返す(): void {
		$offers = array( $this->offer( 10, 'dead', FetchStatus::TERMINAL ), $this->offer( 100, 'alive' ) );
		$got    = OfferSelector::select( $offers, false );
		$this->assertSame( 'dead', $got[0]['external_id'] );
	}

	public function test_設定ONならterminalを飛ばす(): void {
		$offers = array( $this->offer( 10, 'dead', FetchStatus::TERMINAL ), $this->offer( 100, 'alive' ) );
		$got    = OfferSelector::select( $offers, true );
		$this->assertSame( 'alive', $got[0]['external_id'] );
	}

	public function test_設定ONでもtransientとunsupportedは飛ばさない(): void {
		// 商品は存在している。切り替えると復旧時に戻る往復が起きる。
		$offers = array( $this->offer( 10, 'busy', FetchStatus::TRANSIENT ), $this->offer( 100, 'alive' ) );
		$this->assertSame( 'busy', OfferSelector::select( $offers, true )[0]['external_id'] );

		$offers = array( $this->offer( 10, 'manual', FetchStatus::UNSUPPORTED ), $this->offer( 100, 'alive' ) );
		$this->assertSame( 'manual', OfferSelector::select( $offers, true )[0]['external_id'] );
	}

	public function test_全件terminalなら先頭を返す(): void {
		// 非破壊。リンクは出したまま価格だけ PriceFreshness が隠す。
		$offers = array( $this->offer( 10, 'a', FetchStatus::TERMINAL ), $this->offer( 100, 'b', FetchStatus::TERMINAL ) );
		$got    = OfferSelector::select( $offers, true );
		$this->assertSame( 'a', $got[0]['external_id'] );
	}

	public function test_配列でない要素は無視する(): void {
		$offers = array( 'こわれた値', $this->offer( 10, 'ok' ) );
		$got    = OfferSelector::select( $offers, false );
		$this->assertCount( 1, $got );
		$this->assertSame( 'ok', $got[0]['external_id'] );
	}
}
```

- [ ] **Step 2: テストが落ちることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter OfferSelectorTest`
Expected: FAIL — `Class "Affilicard\Pricing\OfferSelector" not found`

- [ ] **Step 3: 最小の実装を書く**

`src/Pricing/OfferSelector.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Pricing;

/**
 * listing の中からどの購入リンク（offer）を使うかを決める唯一の場所。
 *
 * 描画（CardRenderer）も価格更新（ListingRefresher・QueueMaintenance）も
 * この結果だけを見る。ボタンと書影が別の offer を指すといったズレを
 * 構造的に起こさないための集約である。
 */
final class OfferSelector {

	/** display_order の既定値。小さいほど優先。 */
	public const DEFAULT_ORDER = 100;

	/**
	 * 使う購入リンクを返す。
	 *
	 * **常に配列を返す。** 現時点では 0 件または 1 件しか返さないが、将来
	 * 「同一 platform の複数リンクを同時に見せる」に進むときは、この関数が
	 * 返す件数を増やすだけで描画も更新も追随する。レート制限の枠取りも
	 * 返り値の件数に比例させてあるため、表示を増やして制限を超える事故が起きない。
	 *
	 * @param array<int, mixed> $offers
	 * @return list<array<string, mixed>>
	 */
	public static function select( array $offers, bool $fallbackEnabled ): array {
		$sorted = self::sorted( $offers );
		if ( array() === $sorted ) {
			return array();
		}

		if ( ! $fallbackEnabled ) {
			return array( $sorted[0] );
		}

		foreach ( $sorted as $offer ) {
			$status = isset( $offer['fetch_status'] ) ? (string) $offer['fetch_status'] : FetchStatus::NONE;
			if ( ! FetchStatus::isTerminal( $status ) ) {
				return array( $offer );
			}
		}

		// 全件が terminal。非破壊に倒し、先頭を返す（価格は PriceFreshness が隠す）。
		return array( $sorted[0] );
	}

	/**
	 * display_order 昇順・同値は出現順（安定ソート）。
	 *
	 * PHP の usort は同値の順序を保証しないため、出現位置をタイブレークに使う。
	 *
	 * @param array<int, mixed> $offers
	 * @return list<array<string, mixed>>
	 */
	private static function sorted( array $offers ): array {
		$indexed = array();
		$i       = 0;
		foreach ( $offers as $offer ) {
			if ( ! is_array( $offer ) ) {
				continue;
			}
			$order     = isset( $offer['display_order'] ) ? (int) $offer['display_order'] : self::DEFAULT_ORDER;
			$indexed[] = array( $order, $i, $offer );
			++$i;
		}

		usort(
			$indexed,
			static function ( array $a, array $b ): int {
				return $a[0] === $b[0] ? $a[1] <=> $b[1] : $a[0] <=> $b[0];
			}
		);

		return array_map( static fn( array $row ): array => $row[2], $indexed );
	}
}
```

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter OfferSelectorTest`
Expected: PASS（9 tests）

- [ ] **Step 5: Lint とコミット**

```bash
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpcs src/Pricing/OfferSelector.php tests/Unit/Pricing/OfferSelectorTest.php
git add src/Pricing/OfferSelector.php tests/Unit/Pricing/OfferSelectorTest.php
git commit -m "feat: 購入リンクの選択を一箇所に集約する OfferSelector を追加"
```

---

### Task 3: フォールバック設定のトグル

**Files:**
- Modify: `src/Settings/GeneralSettings.php`
- Test: `tests/Unit/Settings/GeneralSettingsTest.php`（既存。無ければ新規作成）

**Interfaces:**
- Consumes: なし
- Produces: `GeneralSettings::fallbackOnTerminal(): bool` — 既定 `false`

- [ ] **Step 1: 失敗するテストを書く**

既存の `GeneralSettingsTest`（無ければ新規作成し、既存テストの `setUp()` パターンに倣って `get_option` を WP_Mock でスタブする）に追加:

```php
	public function test_フォールバック設定の既定はOFF(): void {
		$this->assertFalse( GeneralSettings::fallbackOnTerminal() );
	}

	public function test_フォールバック設定をONにできる(): void {
		$updated = GeneralSettings::sanitize( array( 'fallback_on_terminal' => true ) );
		$this->assertTrue( $updated['fallback_on_terminal'] );
	}

	public function test_フォールバック設定は真偽値へ正規化される(): void {
		$updated = GeneralSettings::sanitize( array( 'fallback_on_terminal' => '1' ) );
		$this->assertTrue( $updated['fallback_on_terminal'] );
	}
```

> `sanitize()` の実メソッド名は `src/Settings/GeneralSettings.php:152` 付近の既存実装で確認すること（`update()` 内のプライベート関数の可能性がある。その場合は公開経路に合わせてテストを書く）。

- [ ] **Step 2: テストが落ちることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter GeneralSettingsTest`
Expected: FAIL — `Call to undefined method ...::fallbackOnTerminal()`

- [ ] **Step 3: 実装する**

`DEFAULTS` に 1 行追加（`stocktake_days` の隣）:

```php
		'fallback_on_terminal'   => false,
```

アクセサを追加（`hidesProductImages()` の隣に置く）:

```php
	/**
	 * 恒久エラー（商品が見つからない）の購入リンクを飛ばして次を表示するか。
	 *
	 * 既定 OFF。ON にしない限り、v3 以前と同じく常に先頭の購入リンクを使う。
	 */
	public static function fallbackOnTerminal(): bool {
		$settings = self::get();
		return ! empty( $settings['fallback_on_terminal'] );
	}
```

`sanitize`/`update` の正規化に 1 行追加（他の bool 項目に倣う）:

```php
		'fallback_on_terminal'   => ! empty( $values['fallback_on_terminal'] ),
```

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter GeneralSettingsTest`
Expected: PASS

- [ ] **Step 5: 設定画面にトグルを出す**

`src/Admin/components/GeneralPanel.jsx` に `ToggleControl` を 1 つ追加する。**設定は PHP 側に足しただけでは画面に出ない。**

```jsx
<ToggleControl
	label={__('商品が見つからない購入リンクを飛ばして、次の購入リンクを表示する', 'affilicard')}
	help={__(
		'一時的な取得エラー（API 障害・レート制限など）では切り替えません。ストア側で商品ページが無くなった場合だけ切り替わります。',
		'affilicard'
	)}
	checked={!!settings.fallback_on_terminal}
	onChange={(v) => patch({ fallback_on_terminal: v })}
/>
```

`tests/js/components/GeneralPanel.test.jsx`（無ければ新規）に追加:

```jsx
it('フォールバック設定を切り替えられる', async () => {
	const patch = jest.fn();
	render(<GeneralPanel settings={{}} patch={patch} />);

	await userEvent.click(
		screen.getByLabelText('商品が見つからない購入リンクを飛ばして、次の購入リンクを表示する')
	);

	expect(patch).toHaveBeenCalledWith({ fallback_on_terminal: true });
});
```

Run: `npm run test:js -- GeneralPanel && npm run lint:js`
Expected: PASS

- [ ] **Step 6: Lint とコミット**

```bash
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpcs src/Settings/GeneralSettings.php
git add src/Settings/GeneralSettings.php src/Admin/components/GeneralPanel.jsx tests/Unit/Settings/ tests/js/components/GeneralPanel.test.jsx
git commit -m "feat: 恒久エラー時のフォールバック設定を追加（既定 OFF）"
```

---

### Task 4: ProductSchema の offers[] 対応と flat 入力の正規化

データの入口。ここが通らないと何も保存できないため、描画・更新より先に行う。

**Files:**
- Modify: `src/Rest/ProductSchema.php`（`sanitizeListings()`）
- Test: `tests/Unit/Rest/ProductSchemaTest.php`

**Interfaces:**
- Consumes: `OfferSelector::DEFAULT_ORDER`（Task 2）
- Produces: `sanitizeListings()` の出力形 — listing は設定 6 フィールド ＋ `offers[]`。offer は 12 フィールド

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Rest/ProductSchemaTest.php` に追加:

```php
	public function test_offersを保持する(): void {
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array(
						array(
							'display_order' => 10,
							'external_id'   => 'sale',
							'regular_url'   => 'https://example.test/sale',
							'affiliate_url' => 'https://af.example.test/sale',
							'price'         => '0',
							'fetch_status'  => 'terminal',
						),
					),
				),
			)
		);

		$this->assertCount( 1, $result[0]['offers'] );
		$this->assertSame( 10, $result[0]['offers'][0]['display_order'] );
		$this->assertSame( 'sale', $result[0]['offers'][0]['external_id'] );
		$this->assertSame( 'terminal', $result[0]['offers'][0]['fetch_status'] );
	}

	public function test_flatな取得結果はoffers0へ畳まれる(): void {
		// v3 以前の形で送られてきても壊れないこと（外部の投稿パイプライン向けの互換）。
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform'    => 'rakuten-kobo',
					'external_id' => 'legacy',
					'regular_url' => 'https://example.test/legacy',
					'price'       => '660',
				),
			)
		);

		$this->assertCount( 1, $result[0]['offers'] );
		$this->assertSame( 'legacy', $result[0]['offers'][0]['external_id'] );
		$this->assertSame( '660', $result[0]['offers'][0]['price'] );
		$this->assertSame( 100, $result[0]['offers'][0]['display_order'] );
		$this->assertArrayNotHasKey( 'external_id', $result[0] );
		$this->assertArrayNotHasKey( 'fetch_error', $result[0] );
	}

	public function test_offersがあればflatは無視する(): void {
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform'    => 'rakuten-kobo',
					'external_id' => 'flat',
					'regular_url' => 'https://example.test/flat',
					'offers'      => array(
						array( 'external_id' => 'nested', 'regular_url' => 'https://example.test/nested' ),
					),
				),
			)
		);

		$this->assertCount( 1, $result[0]['offers'] );
		$this->assertSame( 'nested', $result[0]['offers'][0]['external_id'] );
	}

	public function test_regular_urlが空のofferは弾く(): void {
		// 生死を判定できない offer は棚卸しの対象外になり永久に残る。
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array(
						array( 'external_id' => 'nourl', 'regular_url' => '' ),
						array( 'external_id' => 'ok', 'regular_url' => 'https://example.test/ok' ),
					),
				),
			)
		);

		$this->assertCount( 1, $result[0]['offers'] );
		$this->assertSame( 'ok', $result[0]['offers'][0]['external_id'] );
	}

	public function test_識別子が重複するofferは後勝ちでマージする(): void {
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array(
						array( 'external_id' => 'dup', 'regular_url' => 'https://example.test/a', 'price' => '100' ),
						array( 'external_id' => 'dup', 'regular_url' => 'https://example.test/b', 'price' => '200' ),
					),
				),
			)
		);

		$this->assertCount( 1, $result[0]['offers'] );
		$this->assertSame( '200', $result[0]['offers'][0]['price'] );
	}

	public function test_external_idが空ならregular_urlが識別子になる(): void {
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array(
						array( 'external_id' => '', 'regular_url' => 'https://example.test/same', 'price' => '100' ),
						array( 'external_id' => '', 'regular_url' => 'https://example.test/same', 'price' => '200' ),
						array( 'external_id' => '', 'regular_url' => 'https://example.test/other', 'price' => '300' ),
					),
				),
			)
		);

		$this->assertCount( 2, $result[0]['offers'] );
	}

	public function test_listingの設定フィールドは不変(): void {
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform'              => 'rakuten-kobo',
					'enabled'               => false,
					'auto_update'           => false,
					'update_mode'           => 'manual',
					'button_label_override' => 'いますぐ買う',
					'platform_extras'       => array( 'k' => 'v' ),
					'offers'                => array(
						array( 'external_id' => 'x', 'regular_url' => 'https://example.test/x' ),
					),
				),
			)
		);

		$this->assertFalse( $result[0]['enabled'] );
		$this->assertFalse( $result[0]['auto_update'] );
		$this->assertSame( 'manual', $result[0]['update_mode'] );
		$this->assertSame( 'いますぐ買う', $result[0]['button_label_override'] );
		$this->assertSame( array( 'k' => 'v' ), $result[0]['platform_extras'] );
	}
```

既存の `test_...` のうち flat フィールドを直接アサートしているもの（`$result[0]['fetch_error']` 等）は、`$result[0]['offers'][0][...]` を見る形に書き換える。

- [ ] **Step 2: テストが落ちることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter ProductSchemaTest`
Expected: FAIL — `Undefined array key "offers"`

- [ ] **Step 3: 実装する**

`sanitizeListings()` の `$row` 組み立てを置き換える:

```php
			$row = array(
				'platform'              => $platform,
				'enabled'               => isset( $entry['enabled'] ) ? (bool) $entry['enabled'] : true,
				'update_mode'           => isset( $entry['update_mode'] ) ? (string) sanitize_key( (string) $entry['update_mode'] ) : 'auto',
				'auto_update'           => isset( $entry['auto_update'] ) ? (bool) $entry['auto_update'] : true,
				'button_label_override' => isset( $entry['button_label_override'] ) ? (string) sanitize_text_field( (string) $entry['button_label_override'] ) : '',
				'platform_extras'       => $platform_extras,
				'offers'                => self::sanitizeOffers( $entry ),
			);
```

同クラスに追加:

```php
	/**
	 * listing から購入リンク（offers）を取り出して正規化する。
	 *
	 * `offers` が無い場合は、v3 以前の flat な取得結果フィールドを offers[0] へ
	 * 畳む。これにより外部の投稿パイプラインの改修を待たずにリリースできる。
	 *
	 * @param array<string, mixed> $entry
	 * @return list<array<string, mixed>>
	 */
	private static function sanitizeOffers( array $entry ): array {
		$raw = array();
		if ( isset( $entry['offers'] ) && is_array( $entry['offers'] ) ) {
			$raw = $entry['offers'];
		} elseif ( self::hasFlatFetchFields( $entry ) ) {
			$raw = array( $entry );
		}

		$byKey = array();
		foreach ( $raw as $offer ) {
			if ( ! is_array( $offer ) ) {
				continue;
			}

			$regular = isset( $offer['regular_url'] ) ? (string) esc_url_raw( (string) $offer['regular_url'] ) : '';
			if ( '' === $regular ) {
				// 生死を判定できない offer は棚卸しの対象外になり永久に残るため弾く。
				continue;
			}

			$externalId = isset( $offer['external_id'] ) ? (string) sanitize_text_field( (string) $offer['external_id'] ) : '';
			$key        = '' !== $externalId ? 'id:' . $externalId : 'url:' . $regular;

			// 識別子が重複したら後勝ち（同じ SKU を 2 つ並べない）。
			$byKey[ $key ] = array(
				'display_order'    => isset( $offer['display_order'] ) ? (int) $offer['display_order'] : 100,
				'external_id'      => $externalId,
				'regular_url'      => $regular,
				'affiliate_url'    => isset( $offer['affiliate_url'] ) ? (string) esc_url_raw( (string) $offer['affiliate_url'] ) : '',
				'price'            => isset( $offer['price'] ) ? (string) sanitize_text_field( (string) $offer['price'] ) : '',
				'list_price'       => isset( $offer['list_price'] ) ? (string) sanitize_text_field( (string) $offer['list_price'] ) : '',
				'badge'            => isset( $offer['badge'] ) ? (string) sanitize_text_field( (string) $offer['badge'] ) : '',
				'image_url'        => isset( $offer['image_url'] ) ? (string) esc_url_raw( (string) $offer['image_url'] ) : '',
				'search_key'       => isset( $offer['search_key'] ) ? (string) sanitize_text_field( (string) $offer['search_key'] ) : '',
				'fetch_status'     => isset( $offer['fetch_status'] ) ? (string) sanitize_key( (string) $offer['fetch_status'] ) : '',
				'last_fetched_at'  => isset( $offer['last_fetched_at'] ) ? (string) sanitize_text_field( (string) $offer['last_fetched_at'] ) : '',
				'last_verified_at' => isset( $offer['last_verified_at'] ) ? (string) sanitize_text_field( (string) $offer['last_verified_at'] ) : '',
			);
		}

		return array_values( $byKey );
	}

	/**
	 * v3 以前の flat な取得結果フィールドを持っているか。
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function hasFlatFetchFields( array $entry ): bool {
		foreach ( array( 'external_id', 'regular_url', 'affiliate_url', 'price', 'image_url', 'search_key' ) as $key ) {
			if ( isset( $entry[ $key ] ) && '' !== (string) $entry[ $key ] ) {
				return true;
			}
		}
		return false;
	}
```

`register_post_meta()` の `show_in_rest` スキーマ（`src/PostType/ProductMeta.php` の `$object_array_schema`）が `additionalProperties` を許していない場合は、`offers` を含む形へ更新する。**ここを忘れると REST 保存時に offers が黙って消える。**

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter ProductSchemaTest`
Expected: PASS

- [ ] **Step 5: Lint とコミット**

```bash
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpcs src/Rest/ProductSchema.php src/PostType/ProductMeta.php tests/Unit/Rest/ProductSchemaTest.php
git add src/Rest/ProductSchema.php src/PostType/ProductMeta.php tests/Unit/Rest/ProductSchemaTest.php
git commit -m "feat: listing に offers[] を導入し flat な入力を offers[0] へ正規化する"
```

---

### Task 5: PriceFreshness の判定対象を offer にする

**Files:**
- Modify: `src/Pricing/PriceFreshness.php:19`（`isPriceDisplayable`）・`:84`（`needsRefetch`）
- Test: `tests/Unit/Pricing/PriceFreshnessTest.php`

**Interfaces:**
- Consumes: なし
- Produces: `isPriceDisplayable( array $offer, ?PlatformDefinition $platform, int $nowTs ): bool` / `needsRefetch( array $offer, ?PlatformDefinition $platform, int $nowTs, int $leadSeconds = 0 ): bool`

判定ロジック（TTL 比較・クールダウン）は**変えない**。第 1 引数の名前と docblock を `$offer` にし、呼び出し元が offer を渡すようにするだけ。

- [ ] **Step 1: テストを更新する**

`PriceFreshnessTest` の各テストで、変数名 `$listing` を `$offer` に変える。**アサーションと期待値は変えない**（挙動が変わらないことの担保になる）。テスト名も対象がわかるよう更新する:

```php
	public function test_確認済みかつ鮮度内は表示可(): void {
		$now   = 1_800_000_000;
		$offer = array(
			'price'            => '693',
			'last_verified_at' => gmdate( 'c', $now - 3600 ),
		);
		$this->assertTrue( PriceFreshness::isPriceDisplayable( $offer, $this->platform( 24 ), $now ) );
	}
```

- [ ] **Step 2: テストが通ることを確認（挙動不変の確認）**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter PriceFreshnessTest`
Expected: PASS（引数名の変更だけなので、実装を変える前から通る）

- [ ] **Step 3: 実装のシグネチャと docblock を更新する**

```php
	/**
	 * 価格をカードに表示してよいか（API 確認済み・鮮度内か）。
	 *
	 * 判定対象は **OfferSelector が選んだ購入リンク（offer）** である。
	 * 取得結果フィールドは listing ではなく offer が持つ。
	 *
	 * @param array<string, mixed> $offer
	 */
	public static function isPriceDisplayable( array $offer, ?PlatformDefinition $platform, int $nowTs ): bool {
```

`needsRefetch()` も同様。本体の `$listing[...]` を `$offer[...]` に置換する。

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter PriceFreshnessTest`
Expected: PASS

- [ ] **Step 5: Lint とコミット**

```bash
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpcs src/Pricing/PriceFreshness.php tests/Unit/Pricing/PriceFreshnessTest.php
git add src/Pricing/PriceFreshness.php tests/Unit/Pricing/PriceFreshnessTest.php
git commit -m "refactor: PriceFreshness の判定対象を listing から購入リンクへ変更"
```

---

### Task 6: ProductRepository の offers 対応（ミラー以外）

**Files:**
- Modify: `src/Repository/ProductRepository.php` — `updateListing()`（`:188`）・`listingSummary()`（`:355`）・`hasFallbackListing()`（`:546`）・`countFallbackProducts()`（`:439`）
- Test: `tests/Unit/Repository/ProductRepositoryTest.php`（無ければ新規作成）

**Interfaces:**
- Consumes: `OfferSelector::select()`（Task 2）・`GeneralSettings::fallbackOnTerminal()`（Task 3）
- Produces: `updateListing( int $postId, string $platform, array $listingFields ): bool` — シグネチャ不変。`$listingFields` に `offers` を含められる

- [ ] **Step 1: 失敗するテストを書く**

```php
	public function test_価格サマリは選択された購入リンクから作る(): void {
		// 先頭（表示順 10）が terminal でも、設定 OFF なら先頭の価格を出す。
		$listings = array(
			array(
				'platform' => 'rakuten-kobo',
				'offers'   => array(
					array( 'display_order' => 10, 'external_id' => 'a', 'regular_url' => 'https://example.test/a', 'price' => '0' ),
					array( 'display_order' => 100, 'external_id' => 'b', 'regular_url' => 'https://example.test/b', 'price' => '660' ),
				),
			),
		);
		$this->assertSame( '0', $this->summaryPriceFor( $listings ) );
	}

	public function test_アフィリURL欠落の判定は選択された購入リンクを見る(): void {
		$listings = array(
			array(
				'platform' => 'rakuten-kobo',
				'offers'   => array(
					array( 'display_order' => 10, 'external_id' => 'a', 'regular_url' => 'https://example.test/a', 'affiliate_url' => '' ),
				),
			),
		);
		$this->assertTrue( ProductRepository::hasFallbackListingForTest( $listings ) );
	}
```

> `hasFallbackListing()` は `private static` のため、テストからは (a) `public` に上げる (b) リフレクションで叩く (c) 呼び出し元の `countFallbackProducts()` 経由で検証する、のいずれかを選ぶ。**既存テストの流儀に合わせること**。無ければ (b) のリフレクションが影響が小さい。

- [ ] **Step 2: テストが落ちることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter ProductRepositoryTest`
Expected: FAIL

- [ ] **Step 3: 実装する**

- `listingSummary()`: `$listing['price']` を `OfferSelector::select( $listing['offers'] ?? array(), GeneralSettings::fallbackOnTerminal() )` の先頭要素の `price` から取る。選択結果が空なら `''`
- `hasFallbackListing()`: 同様に選択結果の offer から `affiliate_url` / `regular_url` を見る
- `updateListing()`: `$listingFields` の `offers` をそのまま listing にマージする（既存の「対象 listing だけを原子的に更新する」方針は維持。**find→save の全件上書きに変えない**——別 platform の更新を消す lost update を防ぐための設計である）

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter ProductRepositoryTest`
Expected: PASS

- [ ] **Step 5: Lint とコミット**

```bash
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpcs src/Repository/ProductRepository.php tests/Unit/Repository/
git add src/Repository/ProductRepository.php tests/Unit/Repository/
git commit -m "feat: 価格サマリとフォールバック判定を選択された購入リンクから行う"
```

---

### Task 7: external_id ミラーの複数値化

**重複商品の自動作成を防ぐ、データ整合性に関わるタスク。**

**Files:**
- Modify: `src/Repository/ProductRepository.php` — `syncExternalIdMirror()`（`:488`）・`purgeStaleExternalIdMirror()`（`:515`）・`findByExternalId()`（`:82`）
- Test: `tests/Unit/Repository/ExternalIdMirrorTest.php`

**Interfaces:**
- Consumes: なし
- Produces: `affilicard_extid_<platform>` meta が**複数値**になる。`findByExternalId()` のシグネチャは不変

- [ ] **Step 1: 失敗するテストを書く**

```php
	public function test_全ての購入リンクのexternal_idがミラーされる(): void {
		// 1 platform に複数 offer があると、v3 の実装は 1 つしかミラーしない。
		// その結果 findByExternalId が後続 offer を引けず、自動作成が重複商品を作る。
		$listings = array(
			array(
				'platform' => 'rakuten-kobo',
				'offers'   => array(
					array( 'external_id' => 'sale', 'regular_url' => 'https://example.test/sale' ),
					array( 'external_id' => 'normal', 'regular_url' => 'https://example.test/normal' ),
				),
			),
		);

		$mirrored = $this->mirroredValuesFor( $listings, 'rakuten-kobo' );
		$this->assertEqualsCanonicalizing( array( 'sale', 'normal' ), $mirrored );
	}

	public function test_今回の集合に無い値だけが削除される(): void {
		// キー単位の削除だと、同じ platform の生きている値まで消える。
		$before = array( 'sale', 'normal' );
		$after  = $this->mirroredValuesAfterReplacing( $before, array( 'normal' ), 'rakuten-kobo' );
		$this->assertSame( array( 'normal' ), $after );
	}

	public function test_external_idが空の購入リンクはミラーしない(): void {
		$listings = array(
			array(
				'platform' => 'rakuten-kobo',
				'offers'   => array(
					array( 'external_id' => '', 'regular_url' => 'https://example.test/manual' ),
				),
			),
		);
		$this->assertSame( array(), $this->mirroredValuesFor( $listings, 'rakuten-kobo' ) );
	}
```

`$this->mirroredValuesFor()` 等のヘルパは、WP_Mock で `add_post_meta` / `delete_post_meta` / `get_post_meta` の呼び出しを記録して返すよう実装する。既存の `ProductListColumnsTest` の WP_Mock の使い方に倣うこと。

- [ ] **Step 2: テストが落ちることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter ExternalIdMirrorTest`
Expected: FAIL — 1 件しかミラーされない

- [ ] **Step 3: 実装する**

```php
	/**
	 * 各購入リンクの external_id を `affilicard_extid_<platform>` meta にミラーする。
	 *
	 * **複数値 meta として保持する。** 1 listing が複数の購入リンクを持つため、
	 * 単一値で上書きすると 1 つしかミラーされず、findByExternalId が後続の
	 * 購入リンクを引けない。その結果、自動作成が既存商品を見落として
	 * 重複商品を作る。
	 *
	 * @param array<int, mixed> $listings
	 */
	private function syncExternalIdMirror( int $postId, array $listings ): void {
		$desired = array();
		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$platform = isset( $listing['platform'] ) ? (string) $listing['platform'] : '';
			if ( '' === $platform ) {
				continue;
			}
			$offers = isset( $listing['offers'] ) && is_array( $listing['offers'] ) ? $listing['offers'] : array();
			foreach ( $offers as $offer ) {
				if ( ! is_array( $offer ) ) {
					continue;
				}
				$externalId = isset( $offer['external_id'] ) ? (string) $offer['external_id'] : '';
				if ( '' === $externalId ) {
					continue;
				}
				$metaKey                        = ProductPostType::externalIdMetaKey( $platform );
				$desired[ $metaKey ][ $externalId ] = true;
			}
		}

		$this->purgeStaleExternalIdMirror( $postId, $desired );

		foreach ( $desired as $metaKey => $values ) {
			$existing = (array) get_post_meta( $postId, $metaKey, false );
			foreach ( array_keys( $values ) as $value ) {
				if ( ! in_array( (string) $value, array_map( 'strval', $existing ), true ) ) {
					add_post_meta( $postId, $metaKey, (string) $value, false );
				}
			}
		}
	}

	/**
	 * 既存の extid mirror meta のうち、今回の集合に含まれない**値**を削除する。
	 *
	 * キー単位で消すと、同じ platform の生きている値まで巻き込む。
	 *
	 * @param array<string, array<string, true>> $desired
	 */
	private function purgeStaleExternalIdMirror( int $postId, array $desired ): void {
		$all = get_post_meta( $postId );
		if ( ! is_array( $all ) ) {
			return;
		}
		foreach ( $all as $metaKey => $values ) {
			if ( ! ProductPostType::isExternalIdMetaKey( (string) $metaKey ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				$value = (string) $value;
				if ( ! isset( $desired[ $metaKey ][ $value ] ) ) {
					delete_post_meta( $postId, (string) $metaKey, $value );
				}
			}
		}
	}
```

`ProductPostType::isExternalIdMetaKey()` が無ければ追加する（プレフィックス一致の 3 行）。既存の `purgeStaleExternalIdMirror` がキー判定に使っている条件をそのまま移すこと。

`findByExternalId()` は `meta_query` の `=` 比較で複数値のいずれかに一致すればヒットするため**変更不要**。ただし変更不要であることをテストで固定する。

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter "ExternalIdMirrorTest|ProductRepositoryTest"`
Expected: PASS

- [ ] **Step 5: Lint とコミット**

```bash
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpcs src/Repository/ProductRepository.php src/PostType/ProductPostType.php tests/Unit/Repository/
git add src/Repository/ProductRepository.php src/PostType/ProductPostType.php tests/Unit/Repository/
git commit -m "fix: external_id ミラーを複数値化し重複商品の自動作成を防ぐ"
```

---

### Task 8: ListingRefresher が選択された購入リンクを更新する

**Files:**
- Modify: `src/Cron/ListingRefresher.php` — `refreshOne()`・`refreshListing()`（`:82`）
- Test: `tests/Unit/Cron/ListingRefresherTest.php`

**Interfaces:**
- Consumes: `OfferSelector::select()`・`FetchStatus::*`・`GeneralSettings::fallbackOnTerminal()`
- Produces: `refreshOne( int $postId, string $platform ): WorkOutcome` — シグネチャ不変。書き戻し先が offer になる

- [ ] **Step 1: 失敗するテストを書く**

既存テストと同じハーネス（`WP_Mock\Tools\TestCase` ＋ `Mockery`、`setUp()` で `__` と `current_time` をスタブ、`stubRakutenPlatform()` で `get_option` をスタブ）を使う。まず共通ヘルパを足す:

```php
	/**
	 * offers 形式の商品を返すリポジトリモック。
	 *
	 * @param list<array<string, mixed>> $offers
	 */
	private function repoWithOffers( array $offers, bool $saveOk = true ): ProductRepositoryInterface {
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 20 )->andReturn(
			array(
				'id'           => 20,
				'title'        => '対象巻',
				'status'       => 'publish',
				'product_type' => 'generic',
				'stock_status' => 'available',
				'listings'     => array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'auto_update' => true,
						'update_mode' => 'auto',
						'offers'      => $offers,
					),
				),
			)
		);
		$this->savedListing = null;
		$repo->shouldReceive( 'updateListing' )->andReturnUsing(
			function ( int $postId, string $platform, array $fields ) use ( $saveOk ): bool {
				$this->savedListing = $fields;
				return $saveOk;
			}
		);
		return $repo;
	}

	/** @var array<string, mixed>|null 直近に updateListing へ渡された listing。 */
	private ?array $savedListing = null;

	/** 保存された offers のうち external_id が一致するものを返す。 */
	private function savedOffer( string $externalId ): array {
		foreach ( (array) ( $this->savedListing['offers'] ?? array() ) as $offer ) {
			if ( isset( $offer['external_id'] ) && $externalId === $offer['external_id'] ) {
				return $offer;
			}
		}
		$this->fail( "保存された offers に {$externalId} がない" );
	}
```

テスト本体:

```php
	public function test_選択された購入リンクだけを更新する(): void {
		// 表示しているものを更新する。リクエスト数は 1 listing につき 1 回のまま。
		$this->stubRakutenPlatform();
		WP_Mock::userFunction( 'get_option' )->andReturn( array() ); // fallback 設定 OFF

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		// 先頭（表示順 10）だけが fetch される。2 件あっても 1 回きり。
		$provider->shouldReceive( 'fetch' )->once()->withArgs(
			static fn( string $id ): bool => 'sale' === $id
		)->andReturn( FetchResult::hit( array( 'price' => '0' ) ) );
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = $this->repoWithOffers(
			array(
				array( 'display_order' => 10, 'external_id' => 'sale', 'regular_url' => 'https://example.test/sale' ),
				array( 'display_order' => 100, 'external_id' => 'normal', 'regular_url' => 'https://example.test/normal' ),
			)
		);

		$outcome = ( new ListingRefresher( $repo, $registry ) )->refreshOne( 20, 'rakuten-kobo' );

		$this->assertSame( WorkOutcome::SUCCESS, $outcome );
		$this->assertSame( '0', $this->savedOffer( 'sale' )['price'] );
		// 後続は触られない。
		$this->assertSame( '', $this->savedOffer( 'normal' )['price'] ?? '' );
	}

	public function test_成功でfetch_statusが空になる(): void {
		$this->stubRakutenPlatform();
		$registry = $this->rakutenProvider( FetchResult::hit( array( 'price' => '660' ) ) );
		$repo     = $this->repoWithOffers(
			array( array( 'display_order' => 100, 'external_id' => 'x', 'regular_url' => 'https://example.test/x', 'fetch_status' => 'transient' ) )
		);

		$outcome = ( new ListingRefresher( $repo, $registry ) )->refreshOne( 20, 'rakuten-kobo' );

		$this->assertSame( WorkOutcome::SUCCESS, $outcome );
		$this->assertSame( FetchStatus::NONE, $this->savedOffer( 'x' )['fetch_status'] );
		$this->assertArrayNotHasKey( 'fetch_error', $this->savedOffer( 'x' ) );
	}

	public function test_terminal_missでfetch_statusがterminalになる(): void {
		$this->stubRakutenPlatform();
		$registry = $this->rakutenProvider( FetchResult::terminalMiss() );
		$repo     = $this->repoWithOffers(
			array( array( 'display_order' => 100, 'external_id' => 'gone', 'regular_url' => 'https://example.test/gone' ) )
		);

		$outcome = ( new ListingRefresher( $repo, $registry ) )->refreshOne( 20, 'rakuten-kobo' );

		$this->assertSame( WorkOutcome::TERMINAL_FAILURE, $outcome );
		$this->assertSame( FetchStatus::TERMINAL, $this->savedOffer( 'gone' )['fetch_status'] );
	}

	public function test_一時失敗でfetch_statusがtransientになる(): void {
		$this->stubRakutenPlatform();
		$registry = $this->rakutenProvider( FetchResult::miss() );
		$repo     = $this->repoWithOffers(
			array( array( 'display_order' => 100, 'external_id' => 'busy', 'regular_url' => 'https://example.test/busy' ) )
		);

		$outcome = ( new ListingRefresher( $repo, $registry ) )->refreshOne( 20, 'rakuten-kobo' );

		$this->assertSame( WorkOutcome::TRANSIENT_FAILURE, $outcome );
		$this->assertSame( FetchStatus::TRANSIENT, $this->savedOffer( 'busy' )['fetch_status'] );
	}

	public function test_external_idが空ならunsupportedで自動取得しない(): void {
		// 管理画面で URL だけ手入力した購入リンク。手動更新専用として扱う。
		$this->stubRakutenPlatform();
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->never();
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = $this->repoWithOffers(
			array( array( 'display_order' => 100, 'external_id' => '', 'regular_url' => 'https://example.test/manual' ) )
		);

		$outcome = ( new ListingRefresher( $repo, $registry ) )->refreshOne( 20, 'rakuten-kobo' );

		// WorkOutcome は TRANSIENT_FAILURE のまま（リトライ挙動は変えない）。
		$this->assertSame( WorkOutcome::TRANSIENT_FAILURE, $outcome );
		$this->assertSame( FetchStatus::UNSUPPORTED, $this->savedListing['offers'][0]['fetch_status'] );
	}

	public function test_書き戻しは識別子で行い配列の位置に依存しない(): void {
		// 他の投入で位置が変わるため、添字で書き戻してはいけない。
		$this->stubRakutenPlatform();
		$registry = $this->rakutenProvider( FetchResult::hit( array( 'price' => '0' ) ) );
		$repo     = $this->repoWithOffers(
			array(
				array( 'display_order' => 100, 'external_id' => 'normal', 'regular_url' => 'https://example.test/normal', 'price' => '660' ),
				array( 'display_order' => 10, 'external_id' => 'sale', 'regular_url' => 'https://example.test/sale' ),
			)
		);

		( new ListingRefresher( $repo, $registry ) )->refreshOne( 20, 'rakuten-kobo' );

		// 表示順 10 の 'sale'（配列では 2 番目）が更新され、'normal' は無傷。
		$this->assertSame( '0', $this->savedOffer( 'sale' )['price'] );
		$this->assertSame( '660', $this->savedOffer( 'normal' )['price'] );
	}
```

`rakutenProvider( FetchResult )` は既存の `dmmProvider()` と同じ形のヘルパを `rakuten-kobo` 用に足す。`FetchResult::terminalMiss()` / `miss()` の実メソッド名は `src/Provider/FetchResult.php` で確認すること（`tests/Unit/Provider/FetchResultTest.php` に用例がある）。

**既存 13 箇所の `fetch_error` アサーションを `fetch_status` に置き換える**のも本タスクに含む。

- [ ] **Step 2: テストが落ちることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter ListingRefresherTest`
Expected: FAIL

- [ ] **Step 3: 実装する**

`refreshListing()` を「listing を受け取って listing を返す」から「**選択された offer を更新して listing を返す**」へ変える。

```php
		$targets = OfferSelector::select(
			isset( $listing['offers'] ) && is_array( $listing['offers'] ) ? $listing['offers'] : array(),
			GeneralSettings::fallbackOnTerminal()
		);
		if ( array() === $targets ) {
			return array( $listing, WorkOutcome::TRANSIENT_FAILURE );
		}
		$offer = $targets[0];
```

以降、`$listing['...']` への代入を `$offer['...']` に置き換え、`fetch_error` の 3 文言を `fetch_status` へ差し替える:

| 分岐 | 代入 | 戻り値 |
| --- | --- | --- |
| provider 無し / 非自動 / `external_id` 空 | `$offer['fetch_status'] = FetchStatus::UNSUPPORTED;` | `TRANSIENT_FAILURE` |
| `isTerminalMiss()` | `$offer['fetch_status'] = FetchStatus::TERMINAL;` | `TERMINAL_FAILURE` |
| `! isHit()` | `$offer['fetch_status'] = FetchStatus::TRANSIENT;` | `TRANSIENT_FAILURE` |
| 成功 | `$offer['fetch_status'] = FetchStatus::NONE;` | `SUCCESS` |

最後に、更新した `$offer` を `identity`（`external_id`、空なら `regular_url`）で元の `offers[]` の該当要素へ書き戻す。**配列の添字で書き戻さないこと**——他の投入で位置が変わる。

`search_key` が空のとき商品タイトルへフォールバックする `$context` の組み立ては**現行のまま**維持する。

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter ListingRefresherTest`
Expected: PASS

- [ ] **Step 5: Lint とコミット**

```bash
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpcs src/Cron/ListingRefresher.php tests/Unit/Cron/ListingRefresherTest.php
git add src/Cron/ListingRefresher.php tests/Unit/Cron/ListingRefresherTest.php
git commit -m "feat: 価格更新の対象を選択された購入リンクに統一し fetch_status を書く"
```

---

### Task 9: CardRenderer を選択係に集約する

**Files:**
- Modify: `src/Renderer/CardRenderer.php` — `visibleListings()`（`:346`）・`selectCardImage()`・`renderTimestamp()`（`:294`）・`renderListings()`（`:439`）
- Test: `tests/Unit/Renderer/CardRendererTest.php`

**Interfaces:**
- Consumes: `OfferSelector::select()`・`GeneralSettings::fallbackOnTerminal()`・`PriceFreshness::isPriceDisplayable()`（offer 版）
- Produces: 描画結果（HTML）。**既定設定では v3 と同一の出力**

- [ ] **Step 1: 失敗するテストを書く**

```php
	/** @param list<array<string, mixed>> $offers */
	private function renderWithOffers( array $offers ): string {
		$product = array(
			'id'       => 1,
			'title'    => '対象巻',
			'listings' => array(
				array( 'platform' => 'rakuten-kobo', 'enabled' => true, 'offers' => $offers ),
			),
		);
		return ( new CardRenderer() )->render( $product, array( $this->rakutenPlatform() ) );
	}

	public function test_購入ボタンと書影が同じ購入リンクを指す(): void {
		// 集約の要点。ボタンと書影が別の offer を指すズレを構造的に潰す。
		$html = $this->renderWithOffers(
			array(
				array( 'display_order' => 10, 'external_id' => 'sale', 'regular_url' => 'https://example.test/sale', 'affiliate_url' => 'https://af.test/sale', 'image_url' => 'https://img.test/sale.jpg' ),
				array( 'display_order' => 100, 'external_id' => 'normal', 'regular_url' => 'https://example.test/normal', 'affiliate_url' => 'https://af.test/normal', 'image_url' => 'https://img.test/normal.jpg' ),
			)
		);

		$this->assertStringContainsString( 'https://af.test/sale', $html );
		$this->assertStringContainsString( 'https://img.test/sale.jpg', $html );
		$this->assertStringNotContainsString( 'https://img.test/normal.jpg', $html );
	}

	public function test_設定OFFならterminalでも先頭の購入リンクを出す(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( array() ); // fallback OFF
		$html = $this->renderWithOffers(
			array(
				array( 'display_order' => 10, 'external_id' => 'dead', 'regular_url' => 'https://example.test/dead', 'fetch_status' => 'terminal' ),
				array( 'display_order' => 100, 'external_id' => 'alive', 'regular_url' => 'https://example.test/alive' ),
			)
		);

		$this->assertStringContainsString( 'https://example.test/dead', $html );
		$this->assertStringNotContainsString( 'https://example.test/alive', $html );
	}

	public function test_設定ONならterminalを飛ばして次の購入リンクを出す(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( array( 'fallback_on_terminal' => true ) );
		$html = $this->renderWithOffers(
			array(
				array( 'display_order' => 10, 'external_id' => 'dead', 'regular_url' => 'https://example.test/dead', 'fetch_status' => 'terminal' ),
				array( 'display_order' => 100, 'external_id' => 'alive', 'regular_url' => 'https://example.test/alive' ),
			)
		);

		$this->assertStringContainsString( 'https://example.test/alive', $html );
		$this->assertStringNotContainsString( 'https://example.test/dead', $html );
	}

	public function test_アフィリURLが空なら通常URLへ倒れる(): void {
		// 既存挙動（affiliate_url ?: regular_url）が offer 単位でも維持されること。
		$html = $this->renderWithOffers(
			array( array( 'display_order' => 100, 'external_id' => 'x', 'regular_url' => 'https://example.test/x', 'affiliate_url' => '' ) )
		);

		$this->assertStringContainsString( 'https://example.test/x', $html );
	}

	public function test_鮮度切れの価格は表示されない(): void {
		// 判定対象が選択された offer になっても、規約（24h）はそのまま効くこと。
		$html = $this->renderWithOffers(
			array(
				array(
					'display_order'    => 100,
					'external_id'      => 'x',
					'regular_url'      => 'https://example.test/x',
					'price'            => '660',
					'last_verified_at' => gmdate( 'c', time() - 25 * 3600 ),
				),
			)
		);

		$this->assertStringNotContainsString( '660', $html );
	}

	public function test_offersが1件なら移行前と同じ出力になる(): void {
		// 移行直後の回帰防止。既存のゴールデン出力と突き合わせる。
		$html = $this->renderWithOffers(
			array(
				array(
					'display_order'    => 100,
					'external_id'      => 'x',
					'regular_url'      => 'https://example.test/x',
					'affiliate_url'    => 'https://af.test/x',
					'price'            => '660',
					'last_verified_at' => gmdate( 'c' ),
				),
			)
		);

		$this->assertStringContainsString( 'https://af.test/x', $html );
		$this->assertStringContainsString( '660', $html );
	}
```

`rakutenPlatform()` は `PlatformDefinition::fromArray( array( 'code' => 'rakuten-kobo', 'priceTtlHours' => 24, ... ) )` を返すヘルパ。既存の `CardRendererTest` に同等のものがあれば流用する。

- [ ] **Step 2: テストが落ちることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter CardRendererTest`
Expected: FAIL

- [ ] **Step 3: 実装する**

`visibleListings()` の戻り値を「表示対象 listing の配列」から「**listing と、それに対して選ばれた offer の組**」に変える:

```php
	/**
	 * @return list<array{listing: array<string, mixed>, offer: array<string, mixed>, platform: PlatformDefinition}>
	 */
	private function visibleListings( array $listings, array $by_code, array $hide, array $only ): array {
```

`selectCardImage()` / `renderTimestamp()` / `renderListings()` は、この組から `offer` を読む。**`affiliate_url ?: regular_url` のフォールバックは 1 箇所のプライベートメソッドに括り出す**（現状 `:367` と `:446` に重複している）:

```php
	/**
	 * 購入リンクの href を決める。アフィリエイト URL が無ければ通常 URL へ倒れる。
	 *
	 * @param array<string, mixed> $offer
	 */
	private function ctaHref( array $offer ): string {
		$affiliate = isset( $offer['affiliate_url'] ) ? trim( (string) $offer['affiliate_url'] ) : '';
		$regular   = isset( $offer['regular_url'] ) ? trim( (string) $offer['regular_url'] ) : '';
		return '' !== $affiliate ? $affiliate : $regular;
	}
```

`sortByDisplayOrder()` は **listing を platform の並び順で**ソートするもので、offer の `display_order` とは別軸である。**混同して書き換えないこと。**

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter CardRendererTest`
Expected: PASS

- [ ] **Step 5: Lint とコミット**

```bash
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpcs src/Renderer/CardRenderer.php tests/Unit/Renderer/CardRendererTest.php
git add src/Renderer/CardRenderer.php tests/Unit/Renderer/CardRendererTest.php
git commit -m "refactor: カードの描画を選択された購入リンクに集約する"
```

---

### Task 10: 掃引とレート制限を offer に合わせる

**Files:**
- Modify: `src/Queue/QueueMaintenance.php:261`・`src/Queue/ThrottledActionHandler.php:108`
- Test: `tests/Unit/Queue/QueueMaintenanceTest.php`・`tests/Unit/Queue/ThrottledActionHandlerTest.php`

**Interfaces:**
- Consumes: `OfferSelector::select()`・`PriceFreshness::needsRefetch()`（offer 版）
- Produces: なし（内部挙動の変更）

> **`QueueMaintenance` は取得結果フィールドを 1 つも直接参照していない。** それでも改修対象である——`needsRefetch()` に渡す対象が listing から offer へ変わるため。フィールド名の検索では拾えない間接依存にあたる。

- [ ] **Step 1: 失敗するテストを書く**

```php
	public function test_掃引の鮮度判定は選択された購入リンクを見る(): void {
		// 先頭が古く後続が新しい listing。先頭（＝表示中）を見て再取得が要ると判定する。
		$now      = 1_800_000_000;
		$listings = array(
			array(
				'platform'    => 'rakuten-kobo',
				'enabled'     => true,
				'auto_update' => true,
				'offers'      => array(
					array( 'display_order' => 10, 'external_id' => 'shown', 'regular_url' => 'https://example.test/a', 'last_fetched_at' => gmdate( 'c', $now - 30 * 3600 ) ),
					array( 'display_order' => 100, 'external_id' => 'hidden', 'regular_url' => 'https://example.test/b', 'last_fetched_at' => gmdate( 'c', $now ) ),
				),
			),
		);

		$enqueued = $this->sweepAndCollectEnqueued( $listings, $now );

		$this->assertSame( array( 'rakuten-kobo' ), $enqueued );
	}

	public function test_選択された購入リンクが新しければ投入しない(): void {
		$now      = 1_800_000_000;
		$listings = array(
			array(
				'platform'    => 'rakuten-kobo',
				'enabled'     => true,
				'auto_update' => true,
				'offers'      => array(
					array( 'display_order' => 10, 'external_id' => 'shown', 'regular_url' => 'https://example.test/a', 'last_fetched_at' => gmdate( 'c', $now ) ),
				),
			),
		);

		$this->assertSame( array(), $this->sweepAndCollectEnqueued( $listings, $now ) );
	}

	public function test_設定OFFで先頭がterminalなら更新が止まる(): void {
		// 意図した挙動。後続は表示されないため更新も不要である（spec §7-2 (b)）。
		$now      = 1_800_000_000;
		$listings = array(
			array(
				'platform'    => 'rakuten-kobo',
				'enabled'     => true,
				'auto_update' => true,
				'offers'      => array(
					array( 'display_order' => 10, 'external_id' => 'dead', 'regular_url' => 'https://example.test/dead', 'fetch_status' => 'terminal', 'last_fetched_at' => gmdate( 'c', $now ) ),
					array( 'display_order' => 100, 'external_id' => 'alive', 'regular_url' => 'https://example.test/alive', 'last_fetched_at' => gmdate( 'c', $now - 30 * 3600 ) ),
				),
			),
		);

		// 先頭（terminal・取得したて）が対象なので投入されない。後続の古さは見ない。
		$this->assertSame( array(), $this->sweepAndCollectEnqueued( $listings, $now, false ) );
	}

	public function test_レート制限の枠は対象件数に比例する(): void {
		$limiter = Mockery::mock( RateLimiter::class );
		$limiter->shouldReceive( 'effectiveIntervalMs' )->andReturn( 1100 );
		// 現時点では常に 1 件なので interval × 1。
		$limiter->shouldReceive( 'tryAcquire' )->once()->with( 'rakuten', 1100, Mockery::any() )
			->andReturn( array( 'ok' => true, 'next_ms' => 0 ) );

		$this->runHandlerWithTargetCount( $limiter, 1 );
	}

	public function test_対象が2件なら枠も2倍になる(): void {
		// 将来「複数の購入リンクを同時に見せる」へ進んだとき、表示を増やした瞬間に
		// レート制限を超える事故が起きないことを固定する。
		$limiter = Mockery::mock( RateLimiter::class );
		$limiter->shouldReceive( 'effectiveIntervalMs' )->andReturn( 1100 );
		$limiter->shouldReceive( 'tryAcquire' )->once()->with( 'rakuten', 2200, Mockery::any() )
			->andReturn( array( 'ok' => true, 'next_ms' => 0 ) );

		$this->runHandlerWithTargetCount( $limiter, 2 );
	}
```

`sweepAndCollectEnqueued()` は `QueueMaintenance::sweep()` を走らせ、`Enqueuer` モックが受け取った platform コードを配列で返すヘルパ。第 3 引数は fallback 設定（既定 true）。`runHandlerWithTargetCount()` は `refreshTargetCount()` が指定件数を返す `ThrottledActionHandler` の匿名サブクラスを作って `run()` を呼ぶ。既存 `ThrottledActionHandlerTest` に同種のサブクラスがあれば流用する。

- [ ] **Step 2: テストが落ちることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter "QueueMaintenanceTest|ThrottledActionHandlerTest"`
Expected: FAIL

- [ ] **Step 3: 実装する**

`QueueMaintenance:261`:

```php
				$targets = OfferSelector::select(
					isset( $listing['offers'] ) && is_array( $listing['offers'] ) ? $listing['offers'] : array(),
					GeneralSettings::fallbackOnTerminal()
				);
				if ( array() === $targets ) {
					continue;
				}
				if ( ! PriceFreshness::needsRefetch( $targets[0], $def, $now, $this->sweepLeadSeconds ) ) {
					continue;
				}
```

`ThrottledActionHandler:108` の枠確保を件数比例にする:

```php
		$slots   = max( 1, $this->refreshTargetCount( $args ) );
		$acquire = $this->limiter->tryAcquire( $account, $interval * $slots, $nowMs );
```

`refreshTargetCount()` は abstract として宣言し、サブクラスが選択係の結果件数を返す。既定は 1 を返す実装を base に置き、`RefreshHandler` が override する。

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter "QueueMaintenanceTest|ThrottledActionHandlerTest"`
Expected: PASS

- [ ] **Step 5: Lint とコミット**

```bash
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpcs src/Queue/
git add src/Queue/QueueMaintenance.php src/Queue/ThrottledActionHandler.php src/Queue/RefreshHandler.php tests/Unit/Queue/
git commit -m "feat: 掃引の鮮度判定とレート制限の枠を購入リンク単位にする"
```

---

### Task 11: ProductAutoCreator が offers[] で listing を作る

**Files:**
- Modify: `src/AutoCreate/ProductAutoCreator.php:81` 付近
- Test: `tests/Unit/AutoCreate/ProductAutoCreatorTest.php`

**Interfaces:**
- Consumes: `FetchStatus::NONE`
- Produces: 生成される listing が `offers[]` を持つ

- [ ] **Step 1: 失敗するテストを書く**

```php
	public function test_自動作成した商品はoffersを持つ(): void {
		$this->stubRakutenPlatform();
		$registry = $this->rakutenProvider(
			FetchResult::hit(
				array(
					'external_id'   => 'abc',
					'regular_url'   => 'https://example.test/abc',
					'affiliate_url' => 'https://af.test/abc',
					'price'         => '660',
					'image_url'     => 'https://img.test/abc.jpg',
				)
			)
		);

		$saved = null;
		$repo  = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'findByExternalId' )->andReturn( null );
		$repo->shouldReceive( 'save' )->andReturnUsing(
			function ( array $data ) use ( &$saved ): int {
				$saved = $data;
				return 42;
			}
		);

		( new ProductAutoCreator( $repo, $registry ) )->create( 'rakuten-kobo', 'abc' );

		$offers = $saved['listings'][0]['offers'];
		$this->assertCount( 1, $offers );
		$this->assertSame( 100, $offers[0]['display_order'] );
		$this->assertSame( 'abc', $offers[0]['external_id'] );
		$this->assertSame( '660', $offers[0]['price'] );
		$this->assertSame( FetchStatus::NONE, $offers[0]['fetch_status'] );
		// 取得結果が listing 直下に残っていないこと。
		$this->assertArrayNotHasKey( 'external_id', $saved['listings'][0] );
	}

	public function test_既存商品があれば作らない(): void {
		// external_id ミラーが複数値化されたことで、後続の購入リンクでも引ける。
		$this->stubRakutenPlatform();
		$registry = $this->rakutenProvider( FetchResult::hit( array( 'external_id' => 'abc' ) ) );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'findByExternalId' )->with( 'rakuten-kobo', 'abc' )->andReturn( array( 'id' => 7 ) );
		$repo->shouldReceive( 'save' )->never();

		( new ProductAutoCreator( $repo, $registry ) )->create( 'rakuten-kobo', 'abc' );
	}
```

`ProductAutoCreator` のコンストラクタ引数と `create()` の実シグネチャは `src/AutoCreate/ProductAutoCreator.php` と既存 `ProductAutoCreatorTest` で確認して合わせること。

- [ ] **Step 2: テストが落ちることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter ProductAutoCreatorTest`
Expected: FAIL

- [ ] **Step 3: 実装する**

listing の組み立てを、取得結果を `offers[0]` に入れる形へ変える:

```php
			$listing = array(
				'platform' => $platformCode,
				'enabled'  => true,
				'offers'   => array(
					array(
						'display_order'    => 100,
						'external_id'      => (string) ( $fetched['external_id'] ?? '' ),
						'regular_url'      => (string) ( $fetched['regular_url'] ?? '' ),
						'affiliate_url'    => (string) ( $fetched['affiliate_url'] ?? '' ),
						'price'            => (string) ( $fetched['price'] ?? '' ),
						'list_price'       => (string) ( $fetched['list_price'] ?? '' ),
						'badge'            => (string) ( $fetched['badge'] ?? '' ),
						'image_url'        => (string) ( $fetched['image_url'] ?? '' ),
						'search_key'       => (string) ( $fetched['search_key'] ?? '' ),
						'fetch_status'     => FetchStatus::NONE,
						'last_fetched_at'  => gmdate( 'c' ),
						'last_verified_at' => gmdate( 'c' ),
					),
				),
			);
```

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter ProductAutoCreatorTest`
Expected: PASS

- [ ] **Step 5: Lint とコミット**

```bash
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpcs src/AutoCreate/ProductAutoCreator.php tests/Unit/AutoCreate/ProductAutoCreatorTest.php
git add src/AutoCreate/ProductAutoCreator.php tests/Unit/AutoCreate/ProductAutoCreatorTest.php
git commit -m "feat: 商品の自動作成が offers 形式で購入リンクを作る"
```

---

### Task 12: ProductListColumns が fetch_status から文言を引く

**Files:**
- Modify: `src/PostType/ProductListColumns.php:197`・`:228`・`:251`・`:276` 付近
- Test: `tests/Unit/PostType/ProductListColumnsTest.php`

**Interfaces:**
- Consumes: `FetchStatus::label()`・`OfferSelector::select()`・`PriceFreshness::isPriceDisplayable()`（offer 版）
- Produces: なし

- [ ] **Step 1: 失敗するテストを書く**

```php
	public function test_fetch_statusから文言を引く(): void {
		// 保存された文言ではなく、コードから生成した文言が出ること。
		$html = $this->renderColumnFor(
			array(
				array(
					'platform' => 'rakuten-kobo',
					'enabled'  => true,
					'offers'   => array(
						array( 'display_order' => 100, 'external_id' => 'x', 'regular_url' => 'https://example.test/x', 'fetch_status' => 'terminal' ),
					),
				),
			)
		);

		$this->assertStringContainsString( '商品が見つかりません', $html );
	}

	public function test_自動取得の対象外は一時失敗と別の文言になる(): void {
		// v3 では両方 TRANSIENT に潰れて「一時的に取得できませんでした」と出ていた。
		$html = $this->renderColumnFor(
			array(
				array(
					'platform' => 'amazon',
					'enabled'  => true,
					'offers'   => array(
						array( 'display_order' => 100, 'external_id' => '', 'regular_url' => 'https://example.test/x', 'fetch_status' => 'unsupported' ),
					),
				),
			)
		);

		$this->assertStringContainsString( '自動取得の対象外です', $html );
		$this->assertStringNotContainsString( '一時的に取得できませんでした', $html );
	}

	public function test_警告の判定は選択された購入リンクを見る(): void {
		// 先頭が鮮度切れ・後続が新しい場合、先頭（表示中）を見て警告を出す。
		$html = $this->renderColumnFor(
			array(
				array(
					'platform' => 'rakuten-kobo',
					'enabled'  => true,
					'offers'   => array(
						array( 'display_order' => 10, 'external_id' => 'shown', 'regular_url' => 'https://example.test/a', 'price' => '660', 'last_verified_at' => gmdate( 'c', time() - 30 * 3600 ) ),
						array( 'display_order' => 100, 'external_id' => 'hidden', 'regular_url' => 'https://example.test/b', 'price' => '660', 'last_verified_at' => gmdate( 'c' ) ),
					),
				),
			)
		);

		$this->assertStringContainsString( 'warning', $html );
	}
```

`renderColumnFor()` は対象商品の listings をスタブして列の HTML を返すヘルパ。既存 `ProductListColumnsTest` の組み立てを流用する。

**既存 7 箇所の `fetch_error` アサーションを `fetch_status` ベースに書き換える**のも本タスクに含む。

- [ ] **Step 2: テストが落ちることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter ProductListColumnsTest`
Expected: FAIL

- [ ] **Step 3: 実装する**

`$raw_error = $listing['fetch_error']` を `FetchStatus::label( (string) ( $offer['fetch_status'] ?? '' ) )` に置き換える。`$offer` は選択係の結果から取る。

**クラス docblock の「`fetch_error` は provider 由来の外部文字列のため」というコメントを修正する**（実際に入るのは本プラグイン固定の文言のみで、前提が誤っている）。サニタイズ自体は残す——将来 provider 由来の詳細を持つフィールドを足す余地のため。

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter ProductListColumnsTest`
Expected: PASS

- [ ] **Step 5: Lint とコミット**

```bash
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpcs src/PostType/ProductListColumns.php tests/Unit/PostType/ProductListColumnsTest.php
git add src/PostType/ProductListColumns.php tests/Unit/PostType/ProductListColumnsTest.php
git commit -m "refactor: 管理画面の取得状態を fetch_status から生成する"
```

---

### Task 13: 繰り上がり検知トリガーとループ防止

**Files:**
- Create: `src/Queue/OfferPromotionTrigger.php`
- Modify: `src/Plugin.php`（フック登録）
- Test: `tests/Unit/Queue/OfferPromotionTriggerTest.php`

**Interfaces:**
- Consumes: `OfferSelector::select()`・`PriceFreshness::needsRefetch()`・`ListingEligibility::isAutoEligible()`・`Enqueuer::enqueueManual()`
- Produces: `OfferPromotionTrigger::onListingsSaved( int $postId ): void`

**検知するのは「切り替わった」というイベントではなく「今使う購入リンクの価格が古い」という状態。** 繰り上がりの経路は 3 つあるが、すべて `update_post_meta( META_LISTINGS, ... )` を通るため、1 つのフックで拾える。

- [ ] **Step 1: 失敗するテストを書く**

```php
	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		OfferPromotionTrigger::resetForTests();
		$this->enqueued = 0;
	}

	private int $enqueued = 0;

	/**
	 * listings をスタブして onListingsSaved を走らせ、投入回数を返す。
	 *
	 * @param list<array<string, mixed>> $offers
	 */
	private function fire( array $offers, bool $cooling = false, int $times = 1 ): int {
		WP_Mock::userFunction( 'get_post_meta' )->andReturn(
			array(
				array(
					'platform'    => 'rakuten-kobo',
					'enabled'     => true,
					'auto_update' => true,
					'update_mode' => 'auto',
					'offers'      => $offers,
				),
			)
		);
		WP_Mock::userFunction( 'get_transient' )->andReturn( $cooling ? 1 : false );
		WP_Mock::userFunction( 'set_transient' )->andReturn( true );
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );

		// Enqueuer の投入回数を数える（実体は DI かフィルタで差し替える）。
		$this->enqueued = 0;
		add_filter( 'affilicard_test_enqueue_counter', function (): void { ++$this->enqueued; } );

		for ( $i = 0; $i < $times; $i++ ) {
			OfferPromotionTrigger::onListingsSaved( 123 );
		}
		return $this->enqueued;
	}

	public function test_繰り上がった購入リンクが古ければ投入する(): void {
		$offers = array(
			array( 'display_order' => 100, 'external_id' => 'promoted', 'regular_url' => 'https://example.test/p', 'last_fetched_at' => gmdate( 'c', time() - 30 * 3600 ) ),
		);
		$this->assertSame( 1, $this->fire( $offers ) );
	}

	public function test_更新直後は投入しない(): void {
		// たった今取得したので鮮度が新しい。
		$offers = array(
			array( 'display_order' => 100, 'external_id' => 'fresh', 'regular_url' => 'https://example.test/f', 'last_fetched_at' => gmdate( 'c' ) ),
		);
		$this->assertSame( 0, $this->fire( $offers ) );
	}

	public function test_取得に失敗し続けている間は投入しない(): void {
		// needsRefetch のクールダウン（last_fetched_at は成功・失敗を問わず記録される）。
		$offers = array(
			array( 'display_order' => 100, 'external_id' => 'failing', 'regular_url' => 'https://example.test/x', 'fetch_status' => 'transient', 'last_fetched_at' => gmdate( 'c' ) ),
		);
		$this->assertSame( 0, $this->fire( $offers ) );
	}

	public function test_自動更新の対象外なら投入しない(): void {
		WP_Mock::userFunction( 'get_post_meta' )->andReturn(
			array( array( 'platform' => 'rakuten-kobo', 'enabled' => false, 'offers' => array() ) )
		);
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->andReturn( true );

		$this->enqueued = 0;
		OfferPromotionTrigger::onListingsSaved( 123 );
		$this->assertSame( 0, $this->enqueued );
	}

	public function test_フックはpost_metaを書かない(): void {
		// ループしない根拠がこの一点に依存する。壊れたらここが落ちる。
		WP_Mock::userFunction( 'update_post_meta' )->never();
		WP_Mock::userFunction( 'add_post_meta' )->never();
		WP_Mock::userFunction( 'delete_post_meta' )->never();
		$this->fire( array( array( 'display_order' => 100, 'external_id' => 'x', 'regular_url' => 'https://example.test/x' ) ) );
	}

	public function test_同一リクエストで2回呼んでも投入は1件(): void {
		// 1層目: 再入ガード。
		$offers = array(
			array( 'display_order' => 100, 'external_id' => 'stale', 'regular_url' => 'https://example.test/s', 'last_fetched_at' => gmdate( 'c', time() - 30 * 3600 ) ),
		);
		$this->assertSame( 1, $this->fire( $offers, false, 2 ) );
	}

	public function test_短期クールダウン中は投入しない(): void {
		// 2層目: リクエスト跨ぎの連打を吸収する。
		$offers = array(
			array( 'display_order' => 100, 'external_id' => 'stale', 'regular_url' => 'https://example.test/s', 'last_fetched_at' => gmdate( 'c', time() - 30 * 3600 ) ),
		);
		$this->assertSame( 0, $this->fire( $offers, true ) );
	}

	public function test_再帰させても深さ1で止まる(): void {
		// 投入処理の中から再度 onListingsSaved を呼んでも、再入ガードで止まる。
		$offers = array(
			array( 'display_order' => 100, 'external_id' => 'stale', 'regular_url' => 'https://example.test/s', 'last_fetched_at' => gmdate( 'c', time() - 30 * 3600 ) ),
		);
		add_filter(
			'affilicard_test_enqueue_counter',
			static function (): void {
				OfferPromotionTrigger::onListingsSaved( 123 ); // 再帰させる
			}
		);
		$this->assertSame( 1, $this->fire( $offers ) );
	}
```

> 投入回数の数え方は実装の DI 次第で変わる。`Enqueuer` をコンストラクタ注入にできるなら Mockery の `->once()` / `->never()` で数えるのが素直で、上記のフィルタ方式は使わない。**実装側を静的メソッドにするか注入可能にするかは Step 3 で決め、テストはそれに合わせる。** 静的メソッドのままにするなら、`Enqueuer` を差し替えられる setter をテスト用に用意する。

- [ ] **Step 2: テストが落ちることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter OfferPromotionTriggerTest`
Expected: FAIL — クラスが無い

- [ ] **Step 3: 実装する**

```php
<?php
declare(strict_types=1);

namespace Affilicard\Queue;

/**
 * listings meta の保存を契機に、今使う購入リンクが古ければ即時取得を 1 件積む。
 *
 * 繰り上がりの経路は「本プラグイン自身が恒久エラーを検知した」「外部ツールが
 * 購入リンクを削除した」「管理画面で並べ替えた」の 3 つあるが、すべて
 * update_post_meta( META_LISTINGS, ... ) を通るため 1 つのフックで拾える。
 * 経路ごとにフックを置くと、新しい経路が増えたときに漏れる。
 *
 * **このクラスは post meta を書かない。** ループしない根拠がこの一点に
 * 依存するため、テストで固定している。
 */
final class OfferPromotionTrigger {

	/** 短期クールダウン（秒）。リクエスト跨ぎの連打を吸収する保険。 */
	private const COOLDOWN_SECONDS = 60;

	/** 同一リクエスト内で処理済みの商品 ID。 */
	private static array $inFlight = array();

	public static function onListingsSaved( int $postId ): void {
		// 1層目: 再入ガード（同一リクエスト内）
		if ( isset( self::$inFlight[ $postId ] ) ) {
			return;
		}
		self::$inFlight[ $postId ] = true;

		// 2層目: 短期クールダウン（リクエスト跨ぎ）
		$key = 'affilicard_offer_promote_' . $postId;
		if ( false !== get_transient( $key ) ) {
			return;
		}

		// ... listings を読み、ListingEligibility → OfferSelector → needsRefetch の順に判定し、
		//     必要なら Enqueuer::enqueueManual() で 1 件積む（3層目: unique=true で冪等）

		set_transient( $key, 1, self::COOLDOWN_SECONDS );
	}

	/** テスト用に再入ガードを解除する。 */
	public static function resetForTests(): void {
		self::$inFlight = array();
	}
}
```

`src/Plugin.php` にフックを登録する（`rest_after_insert_` の隣ではなく、**全経路を拾う `updated_post_meta` / `added_post_meta`** に付ける）:

```php
		foreach ( array( 'updated_post_meta', 'added_post_meta' ) as $hook ) {
			add_action(
				$hook,
				static function ( $meta_id, $post_id, $meta_key ): void {
					if ( ProductPostType::META_LISTINGS !== $meta_key ) {
						return;
					}
					OfferPromotionTrigger::onListingsSaved( (int) $post_id );
				},
				10,
				3
			);
		}
```

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter OfferPromotionTriggerTest`
Expected: PASS（7 tests）

- [ ] **Step 5: Lint とコミット**

```bash
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpcs src/Queue/OfferPromotionTrigger.php src/Plugin.php tests/Unit/Queue/OfferPromotionTriggerTest.php
git add src/Queue/OfferPromotionTrigger.php src/Plugin.php tests/Unit/Queue/OfferPromotionTriggerTest.php
git commit -m "feat: 購入リンクの繰り上がりを検知して即時取得を積む"
```

---

### Task 14: データ移行と SchemaVersion

**Files:**
- Modify: `src/Upgrade/PluginUpgrade.php`・`src/Schema/SchemaVersion.php`
- Test: `tests/Unit/Upgrade/PluginUpgradeTest.php`

**Interfaces:**
- Consumes: `FetchStatus::fromLegacyMessage()`
- Produces: `SchemaVersion::CURRENT = '2'`

> **`GeneralSettings::DEFAULTS['schema_version']`（設定オプションの版数、現在 2）とは別物。** 上げるのは `SchemaVersion::CURRENT`（商品ごとの meta、現在 `'1'`）だけ。

- [ ] **Step 1: 失敗するテストを書く**

```php
	/** v3 以前の flat な listing。 */
	private function legacyListing( string $fetchError = '' ): array {
		return array(
			'platform'         => 'rakuten-kobo',
			'enabled'          => true,
			'auto_update'      => true,
			'update_mode'      => 'auto',
			'external_id'      => 'abc',
			'regular_url'      => 'https://example.test/abc',
			'affiliate_url'    => 'https://af.test/abc',
			'price'            => '660',
			'list_price'       => '900',
			'badge'            => '26%OFF',
			'image_url'        => 'https://img.test/abc.jpg',
			'search_key'       => '対象巻',
			'fetch_error'      => $fetchError,
			'last_fetched_at'  => '2026-09-01T00:00:00+00:00',
			'last_verified_at' => '2026-09-01T00:00:00+00:00',
		);
	}

	public function test_flatな取得結果がoffers0へ移る(): void {
		$migrated = PluginUpgrade::migrateListingToOffers( $this->legacyListing() );

		$this->assertCount( 1, $migrated['offers'] );
		$this->assertSame( 100, $migrated['offers'][0]['display_order'] );
		$this->assertSame( 'abc', $migrated['offers'][0]['external_id'] );
		$this->assertSame( '660', $migrated['offers'][0]['price'] );
		$this->assertSame( '対象巻', $migrated['offers'][0]['search_key'] );
		// 設定フィールドは listing に残る。
		$this->assertTrue( $migrated['enabled'] );
		$this->assertSame( 'auto', $migrated['update_mode'] );
		// 取得結果は listing 直下から消える。
		$this->assertArrayNotHasKey( 'external_id', $migrated );
		$this->assertArrayNotHasKey( 'fetch_error', $migrated );
	}

	public function test_fetch_errorがfetch_statusへ写像される(): void {
		$cases = array(
			'該当する商品が見つかりませんでした' => FetchStatus::TERMINAL,
			'対応する自動 Provider がありません'  => FetchStatus::UNSUPPORTED,
			'価格情報の取得に失敗しました'       => FetchStatus::TRANSIENT,
			''                                   => FetchStatus::NONE,
		);
		foreach ( $cases as $message => $expected ) {
			$migrated = PluginUpgrade::migrateListingToOffers( $this->legacyListing( (string) $message ) );
			$this->assertSame( $expected, $migrated['offers'][0]['fetch_status'], (string) $message );
		}
	}

	public function test_未知のfetch_errorはtransientへ倒れる(): void {
		// 恒久と誤認して購入リンクを飛ばすより安全。
		$migrated = PluginUpgrade::migrateListingToOffers( $this->legacyListing( '手で書き換えられた文言' ) );
		$this->assertSame( FetchStatus::TRANSIENT, $migrated['offers'][0]['fetch_status'] );
	}

	public function test_移行は冪等(): void {
		// offers が既にある listing はスキップする。2 回流しても結果が変わらない。
		$once  = PluginUpgrade::migrateListingToOffers( $this->legacyListing() );
		$twice = PluginUpgrade::migrateListingToOffers( $once );
		$this->assertSame( $once, $twice );
	}

	public function test_移行後のoffersは1件で挙動が変わらない(): void {
		// 選択係が常にその 1 件を返す＝表示も更新も移行前と同じ。
		$migrated = PluginUpgrade::migrateListingToOffers( $this->legacyListing() );

		$this->assertSame( $migrated['offers'], OfferSelector::select( $migrated['offers'], false ) );
		$this->assertSame( $migrated['offers'], OfferSelector::select( $migrated['offers'], true ) );
	}

	public function test_SchemaVersionが2になる(): void {
		$this->assertSame( '2', SchemaVersion::CURRENT );
	}
```

> `migrateListingToOffers()` は **1 listing を変換する純粋な `public static` メソッド**として切り出す。こうすると WP 非依存で単体テストでき、バッチ本体（商品を走査して保存する側）とは分けてテストできる。バッチ本体は商品数ぶんこれを呼ぶだけにする。

- [ ] **Step 2: テストが落ちることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit --filter PluginUpgradeTest`
Expected: FAIL

- [ ] **Step 3: 実装する**

`SchemaVersion::CURRENT` を `'2'` に。`PluginUpgrade::maybeUpgrade()` に移行ステップを追加し、**v3.5.0 のバッチ基盤に乗せる**（商品数が増えても実行時間で切れないこと）。1 商品あたりの処理:

1. 取得結果フィールドを `offers[0]`（`display_order: 100`）へ移す
2. `fetch_error` を `FetchStatus::fromLegacyMessage()` で `fetch_status` へ写像し、`fetch_error` を落とす
3. `syncExternalIdMirror()` を呼んで複数値形式で再構築する
4. `META_SCHEMA_VERSION` を更新する

`offers` が既に存在する listing は**スキップ**する（冪等性）。

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit`
Expected: PASS（全件）

- [ ] **Step 5: Lint とコミット**

```bash
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpcs src/Upgrade/PluginUpgrade.php src/Schema/SchemaVersion.php tests/Unit/Upgrade/
git add src/Upgrade/PluginUpgrade.php src/Schema/SchemaVersion.php tests/Unit/Upgrade/
git commit -m "feat: listing を offers 形式へ移行する冪等なアップグレードを追加"
```

---

### Task 15: 管理 UI（購入リンクの 2 階層編集）

**Files:**
- Modify: `src/Admin/components/ListingsEditor.jsx`
- Test: `tests/js/components/ListingsEditor.test.jsx`

**Interfaces:**
- Consumes: なし（表示順とエラー状態は listing データから読む）
- Produces: `affilicard_listings` を `offers[]` 形式で編集する UI

- [ ] **Step 1: 失敗するテストを書く**

```jsx
const twoOffers = [
	{ display_order: 10, external_id: 'sale', regular_url: 'https://example.test/sale' },
	{ display_order: 100, external_id: 'normal', regular_url: 'https://example.test/normal' },
];

const listingWith = (offers) => [
	{ platform: 'rakuten-kobo', enabled: true, auto_update: true, offers },
];

it('購入リンクを追加できる', async () => {
	const onChange = jest.fn();
	render(<ListingsEditor listings={listingWith([])} platforms={platforms} onChange={onChange} />);

	await userEvent.click(screen.getByRole('button', { name: '購入リンクを追加' }));

	expect(onChange.mock.calls.at(-1)[0][0].offers).toHaveLength(1);
});

it('↑ で購入リンクの順序が上がる', async () => {
	const onChange = jest.fn();
	render(<ListingsEditor listings={listingWith(twoOffers)} platforms={platforms} onChange={onChange} />);

	await userEvent.click(screen.getAllByRole('button', { name: '上へ移動' })[1]);

	const offers = onChange.mock.calls.at(-1)[0][0].offers;
	expect(offers[0].external_id).toBe('normal');
});

it('並べ替えると表示順が採番し直される', async () => {
	// 削除時は詰めないが、人が明示的に並べ替えたときは採番し直す。
	const onChange = jest.fn();
	render(<ListingsEditor listings={listingWith(twoOffers)} platforms={platforms} onChange={onChange} />);

	await userEvent.click(screen.getAllByRole('button', { name: '上へ移動' })[1]);

	const offers = onChange.mock.calls.at(-1)[0][0].offers;
	expect(offers[0].display_order).toBeLessThan(offers[1].display_order);
});

it('同じ表示順が並んでいても ↑ で確実に入れ替わる', async () => {
	const onChange = jest.fn();
	const same = [
		{ display_order: 100, external_id: 'a', regular_url: 'https://example.test/a' },
		{ display_order: 100, external_id: 'b', regular_url: 'https://example.test/b' },
	];
	render(<ListingsEditor listings={listingWith(same)} platforms={platforms} onChange={onChange} />);

	await userEvent.click(screen.getAllByRole('button', { name: '上へ移動' })[1]);

	expect(onChange.mock.calls.at(-1)[0][0].offers[0].external_id).toBe('b');
});

it('並べ替えても開閉状態が保たれる', async () => {
	// PanelBody の initialOpen は手動トグルまで毎レンダー読み直される。
	// 位置依存の値を渡すと、並べ替えで開閉が入れ替わる。
	render(<ListingsEditor listings={listingWith(twoOffers)} platforms={platforms} onChange={jest.fn()} />);

	await userEvent.click(screen.getByRole('button', { name: /sale/ })); // 1件目を開く
	expect(screen.getByLabelText('通常 URL')).toHaveValue('https://example.test/sale');

	await userEvent.click(screen.getAllByRole('button', { name: '上へ移動' })[1]); // 並べ替える

	// 開いているのは依然として 'sale' の行（位置ではなく識別子に紐づく）。
	expect(screen.getByLabelText('通常 URL')).toHaveValue('https://example.test/sale');
});

it('使用中の購入リンクに印が付く', () => {
	render(<ListingsEditor listings={listingWith(twoOffers)} platforms={platforms} onChange={jest.fn()} />);

	const marks = screen.getAllByText('使用中');
	expect(marks).toHaveLength(1);
});

it('設定 ON のとき terminal を飛ばした先に使用中が付く', () => {
	// PHP の OfferSelector と同じ規則であることを固定する。
	const offers = [
		{ display_order: 10, external_id: 'dead', regular_url: 'https://example.test/dead', fetch_status: 'terminal' },
		{ display_order: 100, external_id: 'alive', regular_url: 'https://example.test/alive' },
	];
	render(
		<ListingsEditor listings={listingWith(offers)} platforms={platforms} onChange={jest.fn()} fallbackOnTerminal />
	);

	expect(screen.getByTestId('offer-alive')).toHaveTextContent('使用中');
});

it.each([
	['unsupported', '自動取得の対象外です'],
	['transient', '一時的に取得できませんでした'],
	['terminal', '商品が見つかりません'],
])('fetch_status %s に文言が出る', (status, label) => {
	const offers = [{ display_order: 100, external_id: 'x', regular_url: 'https://example.test/x', fetch_status: status }];
	render(<ListingsEditor listings={listingWith(offers)} platforms={platforms} onChange={jest.fn()} />);

	expect(screen.getByText(label)).toBeInTheDocument();
});
```

`platforms` と `render` / `screen` / `userEvent` の import は既存 `tests/js/components/ListingsEditor.test.jsx` の冒頭をそのまま流用する。**既存 54 箇所のアサーションのうち、flat フィールドを直接見ているものを `offers[0]` 経由に書き換える**のも本タスクに含む。

- [ ] **Step 2: テストが落ちることを確認**

Run: `npm run test:js -- ListingsEditor`
Expected: FAIL

- [ ] **Step 3: 実装する**

listing 行を「設定（有効 / 自動更新 / ボタンラベル）」＋「購入リンクのリスト」の 2 階層にする。購入リンク 1 件あたり: ↑↓ ボタン・表示順（**数値表示のみ**）・使用中の印・状態の文言・外部ID / 通常URL / アフィリエイトURL / 価格 / 参考価格 / バッジ / 画像URL。

- **`● 使用中` は選択係と同じ規則で決める**（表示順とエラー状態から人が暗算しない）。JS 側に選択規則を再実装することになるため、**PHP の `OfferSelector` と同じ分岐**をコメントで参照し、テストで両者の一致を固定する
- **並べ替えたら表示順を採番し直す**（削除時は詰めない、というルールとは別）
- **`PanelBody` の `initialOpen` に位置依存の値を渡さない。** 手動トグルまで毎レンダー読み直されるため、並べ替えで開閉が入れ替わる。開閉は購入リンクの識別子に紐づけて管理する

- [ ] **Step 4: テストが通ることを確認**

```bash
npm run test:js
npm run lint:js
npm run build
```
Expected: すべて PASS

- [ ] **Step 5: コミット**

```bash
git add src/Admin/components/ListingsEditor.jsx tests/js/components/ListingsEditor.test.jsx
git commit -m "feat: 購入リンクを並べ替え・追加できる管理 UI にする"
```

---

### Task 16: バージョン・CHANGELOG・実 WP での E2E

**Files:**
- Modify: `affilicard.php`（`Version:` ヘッダ）・`package.json`（`version`）・`CHANGELOG.md`
- Test: `tests/e2e/` に offers の保存往復を追加

**Interfaces:**
- Consumes: なし
- Produces: v4.0.0 のリリース候補

- [ ] **Step 1: E2E を書く**

**モックリポジトリの unit test では `sanitizeListings` の whitelist 漏れを検出できない。** 新フィールドを whitelist に追加し忘れると保存時に黙って消えるため、実 WP に保存して読み戻す経路を必ず通す。複数値 meta のミラーも同じ理由で実 WP で確認する。

```js
test( 'offers が保存され読み戻せる', async ( { request } ) => {
	// whitelist 漏れがあると、201 は返るが offers が空で返ってくる。
	const created = await request.post( '/wp-json/wp/v2/affilicard_product', {
		headers: authHeaders,
		data: {
			title: 'E2E 購入リンク',
			status: 'publish',
			meta: {
				affilicard_listings: [
					{
						platform: 'rakuten-kobo',
						enabled: true,
						offers: [
							{ display_order: 10, external_id: 'sale', regular_url: 'https://example.test/sale', price: '0', fetch_status: 'terminal' },
							{ display_order: 100, external_id: 'normal', regular_url: 'https://example.test/normal', price: '660' },
						],
					},
				],
			},
		},
	} );
	expect( created.ok() ).toBeTruthy();
	const id = ( await created.json() ).id;

	const read = await request.get( `/wp-json/wp/v2/affilicard_product/${ id }?context=edit`, { headers: authHeaders } );
	const offers = ( await read.json() ).meta.affilicard_listings[ 0 ].offers;

	expect( offers ).toHaveLength( 2 );
	expect( offers[ 0 ].external_id ).toBe( 'sale' );
	expect( offers[ 0 ].display_order ).toBe( 10 );
	expect( offers[ 0 ].fetch_status ).toBe( 'terminal' );
	expect( offers[ 1 ].price ).toBe( '660' );
} );

test( '複数の購入リンクの external_id が両方ミラーされる', async ( { request } ) => {
	// add_post_meta の複数値挙動はモックで再現しにくいため実 WP で確認する。
	// ミラーが 1 件しか作られないと、自動作成が重複商品を作る。
	const id = await createProductWithOffers( request, [
		{ display_order: 10, external_id: 'sale', regular_url: 'https://example.test/sale' },
		{ display_order: 100, external_id: 'normal', regular_url: 'https://example.test/normal' },
	] );

	const mirrored = await readMetaValues( request, id, 'affilicard_extid_rakuten-kobo' );

	expect( mirrored.sort() ).toEqual( [ 'normal', 'sale' ] );
} );

test( '購入リンクを 1 件消すと、その値だけがミラーから消える', async ( { request } ) => {
	// キー単位で消すと、同じ platform の生きている値まで巻き込む。
	const id = await createProductWithOffers( request, [
		{ display_order: 10, external_id: 'sale', regular_url: 'https://example.test/sale' },
		{ display_order: 100, external_id: 'normal', regular_url: 'https://example.test/normal' },
	] );

	await updateOffers( request, id, [
		{ display_order: 100, external_id: 'normal', regular_url: 'https://example.test/normal' },
	] );

	expect( await readMetaValues( request, id, 'affilicard_extid_rakuten-kobo' ) ).toEqual( [ 'normal' ] );
} );

test( 'flat な listing を送っても offers[0] に正規化される', async ( { request } ) => {
	// 外部の投稿パイプラインの改修を待たずにリリースできることの担保。
	const created = await request.post( '/wp-json/wp/v2/affilicard_product', {
		headers: authHeaders,
		data: {
			title: 'E2E 互換',
			status: 'publish',
			meta: {
				affilicard_listings: [
					{ platform: 'rakuten-kobo', external_id: 'legacy', regular_url: 'https://example.test/legacy', price: '660' },
				],
			},
		},
	} );
	const id = ( await created.json() ).id;

	const read = await request.get( `/wp-json/wp/v2/affilicard_product/${ id }?context=edit`, { headers: authHeaders } );
	const listing = ( await read.json() ).meta.affilicard_listings[ 0 ];

	expect( listing.offers ).toHaveLength( 1 );
	expect( listing.offers[ 0 ].external_id ).toBe( 'legacy' );
	expect( listing.offers[ 0 ].display_order ).toBe( 100 );
	expect( listing.external_id ).toBeUndefined();
} );
```

`createProductWithOffers()` / `updateOffers()` / `readMetaValues()` / `authHeaders` は `tests/e2e/` の既存ヘルパに合わせて用意する。`readMetaValues()` は複数値 meta を読むため、REST では取れない場合 `wp-env run cli wp post meta list <id>` を使う。

既存の `tests/e2e/rest-read-hardening.spec.js`（未認証では `affiliate_url` を返さない）は**書き換え不要**。応答全体への文字列検査であり、露出制御は `register_post_meta()` の `auth_callback` で meta 単位にかかっているため、`affiliate_url` が `offers[]` の内側へ移っても等価に効く。**offers[] 化で弱まらないことを実行して確認する。**

3 つめは既存 `tests/e2e/rest-read-hardening.spec.js` がそのまま有効（応答全体への文字列検査のため）。**offers[] 化で弱まらないことを確認する**——露出制御は `register_post_meta()` の `auth_callback` で meta 単位にかかっており、フィールド単位のフィルタではない。

- [ ] **Step 2: E2E を実行して落ちることを確認**

```bash
npm run env:start
npm run test:e2e
```

- [ ] **Step 3: バージョンと CHANGELOG を更新する**

`affilicard.php` の `Version: 4.0.0`、`package.json` の `"version": "4.0.0"`。**同じコミットで揃える**（揃えないと自動更新が検知しない）。

`CHANGELOG.md` に破壊的変更を明記する:

- `listings[].external_id` 等の取得結果フィールドは `listings[].offers[].*` へ移動した
- `listings[].fetch_error` は廃止した。代わりに `listings[].offers[].fetch_status`（`'' | 'unsupported' | 'transient' | 'terminal'`）を参照する
- 書き込みは flat な形も受理し `offers[0]` に正規化される
- `affilicard_extid_<platform>` meta は複数値になった
- **ダウングレード不可**。v3 系は `offers[]` を読めず、カードの購入ボタン・価格・書影がすべて欠落する
- **読み取り側の追随が必要であり、追随しない場合はエラーにならず無処理になる**

- [ ] **Step 4: 全テストを実行する**

```bash
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpunit
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli php vendor/bin/phpcs
npm run test:js && npm run lint:js && npm run build
npm run test:e2e
```
Expected: すべて PASS

- [ ] **Step 5: コミット**

```bash
git add affilicard.php package.json CHANGELOG.md tests/e2e/
git commit -m "chore: リリース 4.0.0（購入リンクの複数保持と優先表示）"
```

---

## 実装後の確認（spec §15）

リリース後に実測して判断する。実装の一部ではない。

- フォールバック設定を ON にした運用で、恒久エラーによる切り替えが意図どおり発生しているか
- 新トリガーによる即時投入が、掃引中に過剰なチャーンを生んでいないか（completed アクション数で観測）
- `offers` が 2 件以上ある listing の比率。1 件のままなら、複数保持の価値が出ていない
