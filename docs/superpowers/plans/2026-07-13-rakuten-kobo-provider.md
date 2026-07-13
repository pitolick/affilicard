# 楽天Kobo 自動取得 Provider（RakutenProvider）Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 楽天Kobo 電子書籍検索 API を使った自動取得 Provider（`RakutenProvider`）を affilicard に追加する。

**Architecture:** 既存 `DmmProvider` を踏襲した単一クラス。`wp_remote_get` に `accessKey`/`Origin`/`Referer` ヘッダーを付けて `openapi.rakuten.co.jp` を呼び、価格・書影・作品URL・アフィリエイトURL・配信日を `ProviderInterface` の返却 shape に正規化する。共通抽象化はしない（rule of three で保留・`DmmProvider` 無改修）。

**Tech Stack:** PHP 8.2 / WordPress プラグイン / PHPUnit 9.6 + WP_Mock / Composer。ローカルは Docker（`composer:2`）。

## Global Constraints

- 名前空間 `Affilicard\Provider\Rakuten`。クラス `final class RakutenProvider implements ProviderInterface`。
- `code()` = `'rakuten-kobo'` / `label()` = `'楽天Kobo API'` / `isAutomatic()` = `true`。
- エンドポイント `https://openapi.rakuten.co.jp/services/api/Kobo/EbookSearch/20170426`。
- **`accessKey` は HTTP ヘッダー `accessKey` で送る**（クエリに載せない）。**`Origin` ＋ `Referer` ヘッダー必須**（許可ドメイン）。
- クエリは `applicationId`・`affiliateId`・`format=json`・`formatVersion=2`・`hits=1` ＋ 検索キー（`itemNumber` or `keyword`）。
- `release_date` は `YYYY-MM-DD` 形式（`salesDate` の `YYYY年MM月DD日` を正規化）。
- 楽天Koboは割引情報なし＝`list_price=''`・`badge=''`。
- `DmmProvider` には手を入れない。共通ユーティリティ抽出はしない。
- WordPress 外部関数はすべて WP_Mock でモック（実通信しない）。
- **公開リポのため、テストの作品名・ドメインは架空プレースホルダ**（例 `サンプル作品`・`https://shop.example`）を使う。実在名・私的固有名を書かない。
- リリースは **v1.9.0**。`affilicard.php` の `Version:` ヘッダと `AFFILICARD_VERSION` 定数を同期。

### テスト実行コマンド（Docker）

```bash
# 依存インストール（初回のみ）
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
# 単一テスト
docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter <test_method>
# 全テスト
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
```

---

### Task 1: RakutenProvider スケルトン（メタデータ + credentialsSchema）

**Files:**
- Create: `src/Provider/Rakuten/RakutenProvider.php`
- Test: `tests/Unit/Provider/Rakuten/RakutenProviderTest.php`

**Interfaces:**
- Consumes: `Affilicard\Provider\ProviderInterface`
- Produces: `RakutenProvider::code(): string` = `'rakuten-kobo'`、`label(): string`、`isAutomatic(): bool`、`credentialsSchema(): array`（4 フィールド）。`fetch()`/`testConnection()` は本タスクではスタブ（Task 2/3 で実装）。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Provider/Rakuten/RakutenProviderTest.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Provider\Rakuten;

use Affilicard\Provider\Rakuten\RakutenProvider;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class RakutenProviderTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing(
			static function ( $text ) {
				return $text;
			}
		);
		WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing(
			static function ( $value ) {
				return $value instanceof \WP_Error;
			}
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_basic_metadata(): void {
		$provider = new RakutenProvider();
		$this->assertSame( 'rakuten-kobo', $provider->code() );
		$this->assertSame( '楽天Kobo API', $provider->label() );
		$this->assertTrue( $provider->isAutomatic() );
	}

	public function test_credentials_schema_has_four_entries(): void {
		$schema = ( new RakutenProvider() )->credentialsSchema();

		$this->assertCount( 4, $schema );
		$this->assertSame( 'application_id', $schema[0]['key'] );
		$this->assertTrue( $schema[0]['required'] );
		$this->assertSame( 'access_key', $schema[1]['key'] );
		$this->assertTrue( $schema[1]['required'] );
		$this->assertSame( 'affiliate_id', $schema[2]['key'] );
		$this->assertTrue( $schema[2]['required'] );
		$this->assertSame( 'allowed_domain', $schema[3]['key'] );
		$this->assertFalse( $schema[3]['required'] );
		$this->assertSame( 'text', $schema[3]['type'] );
	}
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter RakutenProviderTest`
Expected: FAIL（`Class "Affilicard\Provider\Rakuten\RakutenProvider" not found`）

- [ ] **Step 3: スケルトンを実装**

`src/Provider/Rakuten/RakutenProvider.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Provider\Rakuten;

use Affilicard\Provider\ProviderCredentials;
use Affilicard\Provider\ProviderInterface;

/**
 * 楽天Kobo 電子書籍検索 API を使った電子書籍の自動取得 Provider。
 *
 * 2026 年の楽天 API 刷新に対応（openapi.rakuten.co.jp・accessKey ヘッダ・Origin 必須）。
 */
final class RakutenProvider implements ProviderInterface {

	private const ENDPOINT = 'https://openapi.rakuten.co.jp/services/api/Kobo/EbookSearch/20170426';

	public function code(): string {
		return 'rakuten-kobo';
	}

	public function label(): string {
		return __( '楽天Kobo API', 'affilicard' );
	}

	public function isAutomatic(): bool {
		return true;
	}

	/**
	 * @return list<array{key: string, label: string, type: 'text'|'password', required: bool}>
	 */
	public function credentialsSchema(): array {
		return array(
			array(
				'key'      => 'application_id',
				'label'    => __( 'アプリID', 'affilicard' ),
				'type'     => 'password',
				'required' => true,
			),
			array(
				'key'      => 'access_key',
				'label'    => __( 'アクセスキー', 'affilicard' ),
				'type'     => 'password',
				'required' => true,
			),
			array(
				'key'      => 'affiliate_id',
				'label'    => __( 'アフィリエイトID', 'affilicard' ),
				'type'     => 'password',
				'required' => true,
			),
			array(
				'key'      => 'allowed_domain',
				'label'    => __( '許可ドメイン（Origin。空ならサイトURL）', 'affilicard' ),
				'type'     => 'text',
				'required' => false,
			),
		);
	}

	/**
	 * @param array<string, mixed> $platformConfig
	 * @return array<string, mixed>|null
	 */
	public function fetch( string $externalId, array $platformConfig ): ?array {
		return null; // Task 3 で実装
	}

	/**
	 * @param array<string, string> $credentials
	 * @return array{ok: bool, message: string}
	 */
	public function testConnection( array $credentials ): array {
		return array(
			'ok'      => false,
			'message' => '',
		); // Task 2 で実装
	}
}
```

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter RakutenProviderTest`
Expected: PASS（2 tests）

- [ ] **Step 5: コミット**

```bash
git add src/Provider/Rakuten/RakutenProvider.php tests/Unit/Provider/Rakuten/RakutenProviderTest.php
git commit -m "feat: RakutenProvider スケルトン（メタデータ・credentialsSchema）"
```

---

### Task 2: testConnection とヘッダー/ドメイン解決

**Files:**
- Modify: `src/Provider/Rakuten/RakutenProvider.php`
- Test: `tests/Unit/Provider/Rakuten/RakutenProviderTest.php`

**Interfaces:**
- Consumes: Task 1 の `RakutenProvider`。
- Produces: `testConnection(array $credentials): array{ok,message}`。private `requestArgs()`（timeout+headers を返す・`accessKey`/`Origin`/`Referer` を含む）、`resolveDomain()`、`toOrigin()`、`errorMessage()`、`hasRequiredCredentials()`、`isWpError()`。Task 3 の `fetch()` がこれらを再利用する。

- [ ] **Step 1: 失敗するテストを書く（testConnection）**

`RakutenProviderTest.php` にメソッドを追加:

```php
	public function test_test_connection_fails_with_empty_credentials(): void {
		$result = ( new RakutenProvider() )->testConnection( array() );
		$this->assertFalse( $result['ok'] );
		$this->assertNotEmpty( $result['message'] );
	}

	public function test_test_connection_succeeds_and_sends_accesskey_and_origin_headers(): void {
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://shop.example' );
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static function ( $url ) {
				return parse_url( $url );
			}
		);

		$captured = null;
		WP_Mock::userFunction( 'wp_remote_get' )
			->once()
			->andReturnUsing(
				static function ( $url, $args ) use ( &$captured ) {
					$captured = array(
						'url'  => $url,
						'args' => $args,
					);
					return array( 'response' => array( 'code' => 200 ) );
				}
			);
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn(
			json_encode( array( 'count' => 1, 'Items' => array() ) )
		);

		$result = ( new RakutenProvider() )->testConnection(
			array(
				'application_id' => 'app-1',
				'access_key'     => 'pk_test',
				'affiliate_id'   => 'aff-1',
			)
		);

		$this->assertTrue( $result['ok'] );
		// accessKey はヘッダー・クエリに載らない
		$this->assertSame( 'pk_test', $captured['args']['headers']['accessKey'] );
		$this->assertSame( 'https://shop.example', $captured['args']['headers']['Origin'] );
		$this->assertSame( 'https://shop.example/', $captured['args']['headers']['Referer'] );
		$this->assertStringNotContainsString( 'accessKey=', $captured['url'] );
		$this->assertStringContainsString( 'applicationId=app-1', $captured['url'] );
	}

	public function test_test_connection_uses_allowed_domain_override(): void {
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static function ( $url ) {
				return parse_url( $url );
			}
		);
		$captured = null;
		WP_Mock::userFunction( 'wp_remote_get' )->once()->andReturnUsing(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = $args;
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( json_encode( array( 'Items' => array() ) ) );

		( new RakutenProvider() )->testConnection(
			array(
				'application_id' => 'app-1',
				'access_key'     => 'pk_test',
				'affiliate_id'   => 'aff-1',
				'allowed_domain' => 'https://www.other.example/path',
			)
		);

		$this->assertSame( 'https://www.other.example', $captured['headers']['Origin'] );
	}

	public function test_test_connection_maps_403_to_referrer_message(): void {
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://shop.example' );
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static function ( $url ) {
				return parse_url( $url );
			}
		);
		WP_Mock::userFunction( 'wp_remote_get' )->once()->andReturn( array( 'response' => array( 'code' => 403 ) ) );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 403 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn(
			json_encode( array( 'errors' => array( 'errorCode' => 403 ) ) )
		);

		$result = ( new RakutenProvider() )->testConnection(
			array(
				'application_id' => 'app-1',
				'access_key'     => 'pk_test',
				'affiliate_id'   => 'aff-1',
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( '許可ドメイン', $result['message'] );
	}

	public function test_test_connection_fails_on_wp_error(): void {
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://shop.example' );
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static function ( $url ) {
				return parse_url( $url );
			}
		);
		WP_Mock::userFunction( 'wp_remote_get' )->once()->andReturn( new \WP_Error() );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 0 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '' );

		$result = ( new RakutenProvider() )->testConnection(
			array(
				'application_id' => 'app-1',
				'access_key'     => 'pk_test',
				'affiliate_id'   => 'aff-1',
			)
		);
		$this->assertFalse( $result['ok'] );
	}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter RakutenProviderTest`
Expected: FAIL（testConnection がスタブで `ok=false` 固定・成功ケースやヘッダー assert が落ちる）

- [ ] **Step 3: testConnection とヘルパーを実装**

`RakutenProvider.php` のスタブ `testConnection` を置き換え、private ヘルパーを追加する。

```php
	/**
	 * @param array<string, string> $credentials
	 * @return array{ok: bool, message: string}
	 */
	public function testConnection( array $credentials ): array {
		if ( ! self::hasRequiredCredentials( $credentials ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'アプリID・アクセスキー・アフィリエイトIDを入力してください', 'affilicard' ),
			);
		}

		$query = array(
			'applicationId' => $credentials['application_id'],
			'affiliateId'   => $credentials['affiliate_id'],
			'format'        => 'json',
			'formatVersion' => '2',
			'hits'          => '1',
			'keyword'       => '本',
		);

		$response = wp_remote_get(
			self::ENDPOINT . '?' . http_build_query( $query ),
			$this->requestArgs( $credentials )
		);
		if ( self::isWpError( $response ) ) {
			return array(
				'ok'      => false,
				'message' => __( '楽天APIへの接続に失敗しました', 'affilicard' ),
			);
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return array(
				'ok'      => false,
				'message' => self::errorMessage( $code ),
			);
		}
		if ( ! is_array( $decoded ) || isset( $decoded['errors'] ) ) {
			return array(
				'ok'      => false,
				'message' => __( '楽天APIがエラーを返しました', 'affilicard' ),
			);
		}

		return array(
			'ok'      => true,
			'message' => __( '楽天APIへの接続に成功しました', 'affilicard' ),
		);
	}

	/**
	 * @param array<string, string> $credentials
	 */
	private static function hasRequiredCredentials( array $credentials ): bool {
		return ! empty( $credentials['application_id'] )
			&& ! empty( $credentials['access_key'] )
			&& ! empty( $credentials['affiliate_id'] );
	}

	/**
	 * accessKey はヘッダーで送る（クエリ露出回避）。Origin/Referer は許可ドメイン。
	 *
	 * @param array<string, string> $credentials
	 * @return array{timeout: int, headers: array<string, string>}
	 */
	private function requestArgs( array $credentials ): array {
		$origin = self::toOrigin( $this->resolveDomain( $credentials ) );
		return array(
			'timeout' => 10,
			'headers' => array(
				'accessKey' => (string) ( $credentials['access_key'] ?? '' ),
				'Origin'    => $origin,
				'Referer'   => $origin . '/',
			),
		);
	}

	/**
	 * @param array<string, string> $credentials
	 */
	private function resolveDomain( array $credentials ): string {
		$domain = trim( (string) ( $credentials['allowed_domain'] ?? '' ) );
		if ( '' === $domain ) {
			$domain = (string) home_url();
		}
		return $domain;
	}

	private static function toOrigin( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( is_array( $parts ) && isset( $parts['host'] ) ) {
			$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : 'https';
			return $scheme . '://' . (string) $parts['host'];
		}
		return rtrim( $url, '/' );
	}

	private static function errorMessage( int $code ): string {
		if ( 429 === $code ) {
			return __( 'レート制限に達しました。時間をおいて再試行してください', 'affilicard' );
		}
		if ( 403 === $code ) {
			return __( '許可ドメイン（Origin）が楽天アプリの登録と一致しているか確認してください', 'affilicard' );
		}
		if ( 400 === $code ) {
			return __( 'アクセスキー・アプリIDを確認してください', 'affilicard' );
		}
		/* translators: %d: HTTP status code */
		return sprintf( __( '楽天APIが HTTP %d を返しました', 'affilicard' ), $code );
	}

	/**
	 * @param mixed $value
	 */
	private static function isWpError( $value ): bool {
		if ( function_exists( 'is_wp_error' ) ) {
			return (bool) is_wp_error( $value );
		}
		return $value instanceof \WP_Error;
	}
```

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter RakutenProviderTest`
Expected: PASS（testConnection 系すべて green）

- [ ] **Step 5: コミット**

```bash
git add src/Provider/Rakuten/RakutenProvider.php tests/Unit/Provider/Rakuten/RakutenProviderTest.php
git commit -m "feat: RakutenProvider testConnection とヘッダー/ドメイン解決を実装"
```

---

### Task 3: fetch（itemNumber 分岐・normalizeItem・日付正規化）

**Files:**
- Modify: `src/Provider/Rakuten/RakutenProvider.php`
- Test: `tests/Unit/Provider/Rakuten/RakutenProviderTest.php`

**Interfaces:**
- Consumes: Task 2 の `requestArgs()`/`isWpError()`/`hasRequiredCredentials()`。`Affilicard\Util\Crypto`・`Affilicard\Util\JsonField`（credentials 暗号化保存の再現用）。
- Produces: `fetch(string $externalId, array $platformConfig): ?array`。private `request()`、`firstItem()`、`normalizeItem()`、`normalizeDate()`。返却 shape = `{title, price, list_price, badge, image_url, regular_url, affiliate_url, platform_extras{release_date, series_name, author, publisher}, raw}`。

- [ ] **Step 1: 失敗するテストを書く（fetch happy path）**

`RakutenProviderTest.php` の先頭 `use` に追加:

```php
use Affilicard\Util\Crypto;
use Affilicard\Util\JsonField;
```

setUp に、Crypto/JsonField が使う WP 関数のモックを追加（`DmmProviderTest` と同じ）:

```php
		WP_Mock::userFunction( 'wp_salt' )->with( 'auth' )->andReturn( 'test-salt-1234567890abcdef' );
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing(
			static function ( $value ) {
				return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
		);
```

テストメソッドを追加:

```php
	/**
	 * @return string 暗号化済み credentials（get_option が返す値）
	 */
	private function encryptedCredentials(): string {
		return Crypto::encrypt(
			JsonField::encode(
				array(
					'application_id' => 'app-1',
					'access_key'     => 'pk_test',
					'affiliate_id'   => 'aff-1',
				)
			)
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function stubFetchResponse( array $item, ?string &$captured = null ): void {
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_provider_rakuten-kobo_credentials', '' )
			->andReturn( $this->encryptedCredentials() );
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://shop.example' );
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static function ( $url ) {
				return parse_url( $url );
			}
		);
		WP_Mock::userFunction( 'wp_remote_get' )->once()->andReturnUsing(
			static function ( $url ) use ( &$captured ) {
				$captured = $url;
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn(
			json_encode( array( 'Items' => array( $item ) ) )
		);
	}

	public function test_fetch_uses_item_number_query_for_numeric_external_id(): void {
		$captured = null;
		$this->stubFetchResponse( array( 'title' => 'サンプル作品' ), $captured );

		$result = ( new RakutenProvider() )->fetch( '8913122576600', array() );

		$this->assertSame( 'サンプル作品', $result['title'] );
		$this->assertStringContainsString( 'itemNumber=8913122576600', $captured );
		$this->assertStringNotContainsString( 'keyword=', $captured );
	}

	public function test_fetch_normalizes_all_fields(): void {
		$item = array(
			'title'         => 'サンプル作品',
			'itemPrice'     => 660,
			'salesDate'     => '2026年07月10日',
			'itemUrl'       => 'https://shop.example/item/1',
			'affiliateUrl'  => 'https://aff.example/hgc/xxx',
			'largeImageUrl' => 'https://img.example/large.jpg',
			'mediumImageUrl' => 'https://img.example/medium.jpg',
			'seriesName'    => 'サンプルシリーズ',
			'author'        => 'サンプル著者',
			'publisherName' => 'サンプル出版',
		);
		$this->stubFetchResponse( $item );

		$result = ( new RakutenProvider() )->fetch( '8913122576600', array() );

		$this->assertSame( 'サンプル作品', $result['title'] );
		$this->assertSame( '660', $result['price'] );
		$this->assertSame( '', $result['list_price'] );
		$this->assertSame( '', $result['badge'] );
		$this->assertSame( 'https://img.example/large.jpg', $result['image_url'] );
		$this->assertSame( 'https://shop.example/item/1', $result['regular_url'] );
		$this->assertSame( 'https://aff.example/hgc/xxx', $result['affiliate_url'] );
		$this->assertSame( '2026-07-10', $result['platform_extras']['release_date'] );
		$this->assertSame( 'サンプルシリーズ', $result['platform_extras']['series_name'] );
	}

	public function test_fetch_falls_back_to_medium_image_when_large_missing(): void {
		$this->stubFetchResponse(
			array(
				'title'          => 'サンプル作品',
				'mediumImageUrl' => 'https://img.example/medium.jpg',
			)
		);
		$result = ( new RakutenProvider() )->fetch( '123', array() );
		$this->assertSame( 'https://img.example/medium.jpg', $result['image_url'] );
	}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter RakutenProviderTest`
Expected: FAIL（`fetch` がスタブで null を返す）

- [ ] **Step 3: fetch と正規化ヘルパーを実装**

`RakutenProvider.php` のスタブ `fetch` を置き換え、private ヘルパーを追加:

```php
	/**
	 * @param array<string, mixed> $platformConfig
	 * @return array<string, mixed>|null
	 */
	public function fetch( string $externalId, array $platformConfig ): ?array {
		$credentials = ProviderCredentials::get( $this->code() );
		if ( ! self::hasRequiredCredentials( $credentials ) ) {
			return null;
		}
		if ( '' === $externalId ) {
			return null;
		}

		$query = array(
			'applicationId' => $credentials['application_id'],
			'affiliateId'   => $credentials['affiliate_id'],
			'format'        => 'json',
			'formatVersion' => '2',
			'hits'          => '1',
		);
		if ( 1 === preg_match( '/^\d+$/', $externalId ) ) {
			$query['itemNumber'] = $externalId;
		} else {
			$query['keyword'] = $externalId;
		}

		$decoded = $this->request( $query, $credentials );
		if ( null === $decoded ) {
			return null;
		}

		$item = self::firstItem( $decoded );
		if ( null === $item ) {
			return null;
		}

		return self::normalizeItem( $item );
	}

	/**
	 * @param array<string, string> $query
	 * @param array<string, string> $credentials
	 * @return array<string, mixed>|null
	 */
	private function request( array $query, array $credentials ): ?array {
		$response = wp_remote_get(
			self::ENDPOINT . '?' . http_build_query( $query ),
			$this->requestArgs( $credentials )
		);
		if ( self::isWpError( $response ) ) {
			return null;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || isset( $decoded['errors'] ) ) {
			return null;
		}
		return $decoded;
	}

	/**
	 * @param array<string, mixed> $decoded
	 * @return array<string, mixed>|null
	 */
	private static function firstItem( array $decoded ): ?array {
		if ( ! isset( $decoded['Items'] ) || ! is_array( $decoded['Items'] ) || array() === $decoded['Items'] ) {
			return null;
		}
		$first = $decoded['Items'][0] ?? null;
		return is_array( $first ) ? $first : null;
	}

	/**
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private static function normalizeItem( array $item ): array {
		$image_url = '';
		foreach ( array( 'largeImageUrl', 'mediumImageUrl', 'smallImageUrl' ) as $key ) {
			if ( isset( $item[ $key ] ) && is_string( $item[ $key ] ) && '' !== $item[ $key ] ) {
				$image_url = $item[ $key ];
				break;
			}
		}

		return array(
			'title'           => isset( $item['title'] ) ? (string) $item['title'] : '',
			'price'           => isset( $item['itemPrice'] ) ? (string) $item['itemPrice'] : '',
			'list_price'      => '',
			'badge'           => '',
			'image_url'       => $image_url,
			'regular_url'     => isset( $item['itemUrl'] ) ? (string) $item['itemUrl'] : '',
			'affiliate_url'   => isset( $item['affiliateUrl'] ) ? (string) $item['affiliateUrl'] : '',
			'platform_extras' => array(
				'release_date' => self::normalizeDate( isset( $item['salesDate'] ) ? (string) $item['salesDate'] : '' ),
				'series_name'  => isset( $item['seriesName'] ) ? (string) $item['seriesName'] : '',
				'author'       => isset( $item['author'] ) ? (string) $item['author'] : '',
				'publisher'    => isset( $item['publisherName'] ) ? (string) $item['publisherName'] : '',
			),
			'raw'             => $item,
		);
	}

	private static function normalizeDate( string $salesDate ): string {
		if ( 1 === preg_match( '/^(\d{4})年(\d{1,2})月(\d{1,2})日$/u', $salesDate, $m ) ) {
			return sprintf( '%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
		}
		return '';
	}
```

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter RakutenProviderTest`
Expected: PASS（fetch happy path 系すべて green）

- [ ] **Step 5: コミット**

```bash
git add src/Provider/Rakuten/RakutenProvider.php tests/Unit/Provider/Rakuten/RakutenProviderTest.php
git commit -m "feat: RakutenProvider fetch（itemNumber 分岐・正規化・日付変換）を実装"
```

---

### Task 4: fetch のエッジ・エラー系カバレッジ

**Files:**
- Modify: `tests/Unit/Provider/Rakuten/RakutenProviderTest.php`（テスト追加のみ・実装は Task 3 で完了済み＝回帰ガード）

**Interfaces:**
- Consumes: Task 3 の `fetch()` 全体。
- Produces: なし（テストのみ）。

- [ ] **Step 1: エッジ/エラー系テストを追加**

```php
	public function test_fetch_uses_keyword_query_for_non_numeric_external_id(): void {
		$captured = null;
		$this->stubFetchResponse( array( 'title' => 'サンプル作品' ), $captured );

		( new RakutenProvider() )->fetch( 'sample-slug', array() );

		$this->assertStringContainsString( 'keyword=sample-slug', $captured );
		$this->assertStringNotContainsString( 'itemNumber=', $captured );
	}

	public function test_fetch_returns_null_when_credentials_missing(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_provider_rakuten-kobo_credentials', '' )
			->andReturn( '' );

		$this->assertNull( ( new RakutenProvider() )->fetch( '123', array() ) );
	}

	public function test_fetch_returns_null_for_empty_external_id(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_provider_rakuten-kobo_credentials', '' )
			->andReturn( $this->encryptedCredentials() );

		$this->assertNull( ( new RakutenProvider() )->fetch( '', array() ) );
	}

	/**
	 * @param mixed  $remoteReturn wp_remote_get の戻り値
	 * @param int    $code         HTTP ステータス
	 * @param string $body         レスポンスボディ
	 * @dataProvider provideFetchFailureCases
	 */
	public function test_fetch_returns_null_on_api_failure( $remoteReturn, int $code, string $body ): void {
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_provider_rakuten-kobo_credentials', '' )
			->andReturn( $this->encryptedCredentials() );
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://shop.example' );
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static function ( $url ) {
				return parse_url( $url );
			}
		);
		WP_Mock::userFunction( 'wp_remote_get' )->once()->andReturn( $remoteReturn );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( $code );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );

		$this->assertNull( ( new RakutenProvider() )->fetch( '123', array() ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: int, 2: string}>
	 */
	public function provideFetchFailureCases(): array {
		return array(
			'wp_error'       => array( new \WP_Error(), 0, '' ),
			'non_200'        => array( array( 'response' => array( 'code' => 429 ) ), 429, '' ),
			'errors_in_body' => array( array( 'response' => array( 'code' => 200 ) ), 200, json_encode( array( 'errors' => array( 'errorCode' => 403 ) ) ) ),
			'empty_items'    => array( array( 'response' => array( 'code' => 200 ) ), 200, json_encode( array( 'Items' => array() ) ) ),
			'not_json'       => array( array( 'response' => array( 'code' => 200 ) ), 200, 'not-json' ),
		);
	}

	public function test_normalize_date_returns_empty_for_invalid_format(): void {
		$this->stubFetchResponse(
			array(
				'title'     => 'サンプル作品',
				'salesDate' => '発売日未定',
			)
		);
		$result = ( new RakutenProvider() )->fetch( '123', array() );
		$this->assertSame( '', $result['platform_extras']['release_date'] );
	}
```

- [ ] **Step 2: テストが通ることを確認（回帰ガード）**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter RakutenProviderTest`
Expected: PASS（全 RakutenProvider テスト green。Task 3 実装がエラー系ガードを内包しているため追加テストも通る）

> 補足: Task 4 は Task 3 で実装済みのガード（`request()`/`firstItem()`/creds/externalId）を明示的に固定する回帰テスト。もし失敗するケースがあれば Task 3 の該当ガードを見直す。

- [ ] **Step 3: コミット**

```bash
git add tests/Unit/Provider/Rakuten/RakutenProviderTest.php
git commit -m "test: RakutenProvider の keyword 分岐・null/エラー系カバレッジを追加"
```

---

### Task 5: Provider を Plugin に登録

**Files:**
- Modify: `src/Plugin.php`（`buildProviderRegistry()` 内）
- Test: `tests/Unit/Provider/Rakuten/RakutenRegistrationTest.php`

**Interfaces:**
- Consumes: `Affilicard\Plugin::buildProviderRegistry(): ProviderRegistry`、`RakutenProvider`。
- Produces: レジストリの `codes()` に `'rakuten-kobo'` が含まれる。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Provider/Rakuten/RakutenRegistrationTest.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Provider\Rakuten;

use Affilicard\Plugin;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class RakutenRegistrationTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing(
			static function ( $text ) {
				return $text;
			}
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_registry_includes_rakuten_provider(): void {
		$registry = Plugin::buildProviderRegistry();
		$this->assertContains( 'rakuten-kobo', $registry->codes() );
	}
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter RakutenRegistrationTest`
Expected: FAIL（`rakuten-kobo` が codes() に無い）

- [ ] **Step 3: Plugin に登録を追加**

`src/Plugin.php` の `buildProviderRegistry()`。先頭の `use` に `use Affilicard\Provider\Rakuten\RakutenProvider;` を追加し、メソッドを更新:

```php
	public static function buildProviderRegistry(): ProviderRegistry {
		$registry = new ProviderRegistry();
		$registry->register( new ManualProvider() );
		$registry->register( new DmmProvider() );
		$registry->register( new RakutenProvider() );
		return $registry;
	}
```

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter RakutenRegistrationTest`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
git add src/Plugin.php tests/Unit/Provider/Rakuten/RakutenRegistrationTest.php
git commit -m "feat: RakutenProvider を Plugin のレジストリに登録"
```

---

### Task 6: バージョン v1.9.0 ＋ CHANGELOG

**Files:**
- Modify: `affilicard.php`（`Version:` ヘッダ・`AFFILICARD_VERSION` 定数）
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: なし。
- Produces: プラグインバージョン `1.9.0`。

- [ ] **Step 1: affilicard.php のバージョンを更新**

`affilicard.php:6` と `affilicard.php:25` の `1.8.1` を `1.9.0` に変更:

```php
 * Version:     1.9.0
```

```php
define( 'AFFILICARD_VERSION', '1.9.0' );
```

- [ ] **Step 2: CHANGELOG.md にエントリを追加**

`## [Unreleased]` の直後に追記:

```markdown
## [1.9.0] - 2026-07-13

### Added

- 楽天Kobo 電子書籍検索 API を使った自動取得 Provider（`RakutenProvider`）を追加。価格・書影・作品URL・アフィリエイトURL・配信日を取得する。2026 年の楽天 API 刷新（`openapi.rakuten.co.jp`・`accessKey` ヘッダ・`Origin` 必須）に対応。
```

- [ ] **Step 3: 全テスト・Lint を実行**

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer lint
```
Expected: すべて PASS（既存テスト＋RakutenProvider・登録テスト green、phpcs エラーなし）

- [ ] **Step 4: コミット**

```bash
git add affilicard.php CHANGELOG.md
git commit -m "chore: v1.9.0（RakutenProvider 追加）"
```

---

## 完了後の確認（PR 前）

- `composer test` 全 green・`composer lint`（phpcs）エラーなし。
- PR を作成（自動マージしない）。**PR プレビュー（Playground）で設定画面に楽天Kobo の credentials 入力欄（4 フィールド）が出ること・`testConnection` の挙動を目視確認**してからマージ。
- マージ後タグ付けで `release.yml` が Release 公開（`affilicard.php` Version ヘッダ同期済みのため PUC が検知）。

## Self-Review 結果

- **Spec coverage**: §4-1〜4-7・§5 の各要件を Task 1〜6 で網羅（メタデータ/schema=T1、testConnection・ヘッダー・Origin・エラーメッセージ=T2、fetch・itemNumber/keyword・normalizeItem・日付=T3、null/エラー系=T4、登録=T5、v1.9.0=T6）。§6 リリースは T6＋完了後確認。§7 follow-up はスコープ外で計画に含めない（意図通り）。
- **Placeholder scan**: 全ステップに実コード・実コマンド・期待結果あり。TBD/曖昧記述なし。
- **Type consistency**: `hasRequiredCredentials`/`requestArgs`/`resolveDomain`/`toOrigin`/`errorMessage`/`isWpError`/`request`/`firstItem`/`normalizeItem`/`normalizeDate` の名称・シグネチャは T2・T3 間で一致。返却 shape は spec §4-5 と一致。
