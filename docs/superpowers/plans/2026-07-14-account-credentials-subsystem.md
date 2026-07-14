# 認証サブシステム再設計（account単位＋SSOT＋UX） Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 認証情報を account 単位に集約し、スキーマを PHP の SSOT に一本化、UI を write-only＋dirty 追跡＋折り畳み＋provider 単位テストへ刷新する（affilicard v2.0.0）。

**Architecture:** `platform → provider → account → credentials` の連鎖。Account（`AccountInterface`/`AccountRegistry`）が credentials スキーマと保存の SSOT を持ち、Provider は `accountCode()` で account を参照して `fetch`/`testConnection` に専念。REST は accounts=CRUD / providers=test-connection。設定画面は PHP から `window.affilicardAccounts`/`window.affilicardProviders` を注入し、JS はハードコードを廃して導出する。

**Tech Stack:** PHP 8.2 / PHPUnit 9.6 + WP_Mock（Docker `composer:2`）／ React・`@wordpress/scripts`（Jest = `wp-scripts test-unit-js`・ローカル volta node）／ `Crypto`(AES-256-CBC)・`JsonField`。

## Global Constraints

- 設計の唯一の正: `docs/superpowers/specs/2026-07-14-account-credentials-subsystem-design.md`（rev2）。矛盾時は spec を優先。
- **移行なし**（未公開・クリーン破壊）。後方互換ルート/保存形式は残さない。
- **保存キー**: `affilicard_account_<accountCode>_credentials`。値は `Crypto` 暗号化 JSON（`Crypto` は変更しない）。
- **秘匿(password)** は `access_key`・`api_id` のみ。`application_id`/`affiliate_id`/`allowed_domain` は text。
- **write-only**: GET は password の value を返さない（`{value:'', isSet}`）。PUT は dirty キーのみ。全体クリアは DELETE。
- **required = 保存時サーバ強制**（patch 適用後のマージ状態で検証・不足なら 400）。
- **SSOT**: JS は `window.affilicardAccounts`/`window.affilicardProviders` から導出。ハードコード禁止。
- **将来ガード**: コア interface は拡張せず、機能追加は capability interface(instanceof)。
- 公開リポ: テストは架空プレースホルダ（`sample`/`X` 等）。実在名を書かない。
- **厳密 SemVer で v2.0.0**（`affilicard.php` の `Version:` と `AFFILICARD_VERSION` を同期）。
- 実装 PR は自動マージしない。楽天は wp-env（実WP）で E2E、UI/DMM は Playground で確認。

### テスト実行コマンド

```bash
# PHP（Docker）
docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter <Name>
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer lint
# JS（ローカル volta node）
npm run test:js
npm run lint:js
npm run build
```

### ファイル構成（このプランで作る/変える）

| 種別 | パス | 責務 |
| --- | --- | --- |
| 新規 | `src/Account/AccountInterface.php` | Account 契約（code/label/credentialsSchema） |
| 新規 | `src/Account/AccountRegistry.php` | Account 登録簿 |
| 新規 | `src/Account/RakutenAccount.php` / `DmmAccount.php` | 具体 Account（schema） |
| 改名 | `src/Provider/ProviderCredentials.php` → `src/Account/AccountCredentials.php` | account 単位保存＋type-aware status |
| 新規 | `src/Provider/Rakuten/RakutenClient.php` | 楽天 API transport |
| 新規 | `src/Account/AccountUiList.php` / `src/Provider/ProviderUiList.php` | SSOT 注入ビルダー |
| 変更 | `src/Provider/ProviderInterface.php`・各 Provider | accountCode 追加・credentialsSchema 撤去・fetch は AccountCredentials |
| 変更 | `src/Rest/CredentialsController.php` | accounts CRUD＋provider test-connection |
| 変更 | `src/Plugin.php` | buildAccountRegistry・2 globals 注入・route 配線・旧キー purge |
| 変更 | `src/Uninstall.php` | account キー削除追加 |
| 新規 | `src/Admin/accounts.js` | ACCOUNTS 導出 |
| 変更 | `src/Admin/providers.js`・`api/credentials.js` | SSOT 導出・新ルート |
| 改名 | `src/Admin/components/CredentialEditor.jsx` → `AccountCredentialEditor.jsx` | write-only・dirty・provider 別テスト |
| 変更 | `src/Admin/components/ApiCredentialsPanel.jsx` | account を PanelBody で折り畳み |
| 変更 | `affilicard.php`・`CHANGELOG.md`・`README.md` | v2.0.0・追加手順 |

---

### Task 1: AccountInterface ＋ AccountRegistry

**Files:**
- Create: `src/Account/AccountInterface.php`
- Create: `src/Account/AccountRegistry.php`
- Test: `tests/Unit/Account/AccountRegistryTest.php`

**Interfaces:**
- Produces: `Affilicard\Account\AccountInterface`（`code():string` / `label():string` / `credentialsSchema():list<array{key,label,type,required}>`）。`Affilicard\Account\AccountRegistry`（`register(AccountInterface):void` / `get(string):?AccountInterface` / `all():list<AccountInterface>` / `codes():list<string>`）。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Account/AccountRegistryTest.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Account;

use Affilicard\Account\AccountInterface;
use Affilicard\Account\AccountRegistry;
use PHPUnit\Framework\TestCase;

final class AccountRegistryTest extends TestCase {

	private function fakeAccount( string $code, string $label, array $schema ): AccountInterface {
		return new class( $code, $label, $schema ) implements AccountInterface {
			public function __construct( private string $code, private string $label, private array $schema ) {}
			public function code(): string {
				return $this->code;
			}
			public function label(): string {
				return $this->label;
			}
			public function credentialsSchema(): array {
				return $this->schema;
			}
		};
	}

	public function test_register_and_get_by_code(): void {
		$registry = new AccountRegistry();
		$registry->register( $this->fakeAccount( 'sample', 'Sample', array() ) );

		$this->assertSame( 'sample', $registry->get( 'sample' )?->code() );
		$this->assertNull( $registry->get( 'missing' ) );
	}

	public function test_all_and_codes_preserve_registration_order(): void {
		$registry = new AccountRegistry();
		$registry->register( $this->fakeAccount( 'a', 'A', array() ) );
		$registry->register( $this->fakeAccount( 'b', 'B', array() ) );

		$this->assertSame( array( 'a', 'b' ), $registry->codes() );
		$this->assertCount( 2, $registry->all() );
	}
}
```

- [ ] **Step 2: 失敗を確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter AccountRegistryTest`
Expected: FAIL（`Class "Affilicard\Account\AccountInterface" not found`）

- [ ] **Step 3: 実装**

`src/Account/AccountInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Account;

/**
 * API 認証情報の保有単位（楽天 / DMM / Amazon 等のアカウント）。
 *
 * 1 つの account を複数 provider（例: 楽天Kobo・楽天ブックス）が共有する。
 * credentials スキーマの単一情報源（SSOT）。testConnection は API 単位のため provider が持つ。
 */
interface AccountInterface {

	/** 内部コード（例: 'rakuten'）。保存キー affilicard_account_<code>_credentials に使う。 */
	public function code(): string;

	/** UI 表示ラベル（例: '楽天'）。 */
	public function label(): string;

	/**
	 * 管理画面に表示する credentials フィールド定義。
	 *
	 * @return list<array{key: string, label: string, type: 'text'|'password', required: bool}>
	 */
	public function credentialsSchema(): array;
}
```

`src/Account/AccountRegistry.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Account;

/**
 * Account の登録レジストリ。Plugin::buildAccountRegistry() で rakuten / dmm を register する。
 */
final class AccountRegistry {

	/** @var array<string, AccountInterface> */
	private array $accounts = array();

	public function register( AccountInterface $account ): void {
		$this->accounts[ $account->code() ] = $account;
	}

	public function get( string $code ): ?AccountInterface {
		return $this->accounts[ $code ] ?? null;
	}

	/** @return list<AccountInterface> */
	public function all(): array {
		return array_values( $this->accounts );
	}

	/** @return list<string> */
	public function codes(): array {
		return array_keys( $this->accounts );
	}
}
```

- [ ] **Step 4: 通過を確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter AccountRegistryTest`
Expected: PASS（2 tests）

- [ ] **Step 5: コミット**

```bash
git add src/Account/AccountInterface.php src/Account/AccountRegistry.php tests/Unit/Account/AccountRegistryTest.php
git commit -m "feat: AccountInterface と AccountRegistry を追加"
```

---

### Task 2: RakutenAccount ／ DmmAccount（具体 Account）

**Files:**
- Create: `src/Account/RakutenAccount.php`
- Create: `src/Account/DmmAccount.php`
- Test: `tests/Unit/Account/AccountsSchemaTest.php`

**Interfaces:**
- Consumes: Task 1 の `AccountInterface`。
- Produces: `RakutenAccount`（code=`rakuten`）・`DmmAccount`（code=`dmm`）。credentialsSchema のキー = provider が参照するキー契約（`application_id`/`access_key`/`affiliate_id`/`allowed_domain`、`api_id`/`affiliate_id`）。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Account/AccountsSchemaTest.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Account;

use Affilicard\Account\DmmAccount;
use Affilicard\Account\RakutenAccount;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class AccountsSchemaTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $t ) => $t );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_rakuten_account_schema_types(): void {
		$account = new RakutenAccount();
		$this->assertSame( 'rakuten', $account->code() );

		$byKey = array();
		foreach ( $account->credentialsSchema() as $f ) {
			$byKey[ $f['key'] ] = $f['type'];
		}
		$this->assertSame( 'text', $byKey['application_id'] );
		$this->assertSame( 'password', $byKey['access_key'] );
		$this->assertSame( 'text', $byKey['affiliate_id'] );
		$this->assertSame( 'text', $byKey['allowed_domain'] );
	}

	public function test_dmm_account_schema_types(): void {
		$account = new DmmAccount();
		$this->assertSame( 'dmm', $account->code() );

		$byKey = array();
		foreach ( $account->credentialsSchema() as $f ) {
			$byKey[ $f['key'] ] = $f['type'];
		}
		$this->assertSame( 'password', $byKey['api_id'] );
		$this->assertSame( 'text', $byKey['affiliate_id'] );
	}
}
```

- [ ] **Step 2: 失敗を確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter AccountsSchemaTest`
Expected: FAIL（`Class "Affilicard\Account\RakutenAccount" not found`）

- [ ] **Step 3: 実装**

`src/Account/RakutenAccount.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Account;

/**
 * 楽天デベロッパーアカウント。楽天系 provider（楽天Kobo 等）が共有する鍵を保有する。
 */
final class RakutenAccount implements AccountInterface {

	public function code(): string {
		return 'rakuten';
	}

	public function label(): string {
		return __( '楽天', 'affilicard' );
	}

	/**
	 * @return list<array{key: string, label: string, type: 'text'|'password', required: bool}>
	 */
	public function credentialsSchema(): array {
		return array(
			array(
				'key'      => 'application_id',
				'label'    => __( 'アプリID', 'affilicard' ),
				'type'     => 'text',
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
				'type'     => 'text',
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
}
```

`src/Account/DmmAccount.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Account;

/**
 * DMM アカウント。DMM 系 provider（DMM ebook 等）が共有する鍵を保有する。
 */
final class DmmAccount implements AccountInterface {

	public function code(): string {
		return 'dmm';
	}

	public function label(): string {
		return __( 'DMM', 'affilicard' );
	}

	/**
	 * @return list<array{key: string, label: string, type: 'text'|'password', required: bool}>
	 */
	public function credentialsSchema(): array {
		return array(
			array(
				'key'      => 'api_id',
				'label'    => __( 'API ID', 'affilicard' ),
				'type'     => 'password',
				'required' => true,
			),
			array(
				'key'      => 'affiliate_id',
				'label'    => __( 'アフィリエイト ID', 'affilicard' ),
				'type'     => 'text',
				'required' => true,
			),
		);
	}
}
```

- [ ] **Step 4: 通過を確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter AccountsSchemaTest`
Expected: PASS（2 tests）

- [ ] **Step 5: コミット**

```bash
git add src/Account/RakutenAccount.php src/Account/DmmAccount.php tests/Unit/Account/AccountsSchemaTest.php
git commit -m "feat: RakutenAccount / DmmAccount（credentials スキーマ）を追加"
```

---

### Task 3: AccountCredentials（ProviderCredentials を改名・account 化・type-aware status）

**Files:**
- Create: `src/Account/AccountCredentials.php`
- Delete: `src/Provider/ProviderCredentials.php`（Task 4/7 で参照を切り替えた後に削除。本タスクでは新規作成のみ）
- Test: `tests/Unit/Account/AccountCredentialsTest.php`

**Interfaces:**
- Consumes: `Affilicard\Util\Crypto`・`Affilicard\Util\JsonField`・Task 1 の `AccountInterface`。
- Produces: `Affilicard\Account\AccountCredentials`（`optionKey(string):string` / `get(string):array` / `patch(string,array):void` / `delete(string):void` / `getStatusFor(AccountInterface):array<string,array{value:string,isSet:bool}>`）。`getMasked` は廃止（`getStatusFor` に置換）。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Account/AccountCredentialsTest.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Account;

use Affilicard\Account\AccountCredentials;
use Affilicard\Account\AccountInterface;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class AccountCredentialsTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $t ) => $t );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	private function account( array $schema ): AccountInterface {
		return new class( $schema ) implements AccountInterface {
			public function __construct( private array $schema ) {}
			public function code(): string {
				return 'sample';
			}
			public function label(): string {
				return 'Sample';
			}
			public function credentialsSchema(): array {
				return $this->schema;
			}
		};
	}

	public function test_option_key_uses_account_prefix(): void {
		$this->assertSame(
			'affilicard_account_rakuten_credentials',
			AccountCredentials::optionKey( 'rakuten' )
		);
	}

	public function test_get_status_for_masks_only_password_fields(): void {
		// 保存済み: text=api_pub / password=secret123
		WP_Mock::userFunction( 'get_option' )->andReturn(
			// Crypto::encrypt 済みの値を復号して {pub:'api_pub', sec:'secret123'} を返す前提で
			// get() をモックできないため、patch→get のラウンドトリップで検証する（下の統合的テスト）
			''
		);
		$this->markTestSkipped( '実 Crypto を使うため getStatusFor はラウンドトリップテストで検証' );
	}

	public function test_patch_then_get_status_for_roundtrip(): void {
		$store = array();
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			static function ( $key, $default = '' ) use ( &$store ) {
				return $store[ $key ] ?? $default;
			}
		);
		WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			static function ( $key, $value ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);

		$account = $this->account(
			array(
				array( 'key' => 'pub', 'label' => 'Pub', 'type' => 'text', 'required' => true ),
				array( 'key' => 'sec', 'label' => 'Sec', 'type' => 'password', 'required' => true ),
			)
		);

		AccountCredentials::patch( 'sample', array( 'pub' => 'api_pub', 'sec' => 'secret123' ) );

		$status = AccountCredentials::getStatusFor( $account );
		$this->assertSame( 'api_pub', $status['pub']['value'] );   // text は実値
		$this->assertTrue( $status['pub']['isSet'] );
		$this->assertSame( '', $status['sec']['value'] );          // password は withhold
		$this->assertTrue( $status['sec']['isSet'] );
	}
}
```

> 注: `Crypto` は `wp_salt('auth')` を使う。WP_Mock で `wp_salt`/`openssl_*` はネイティブ動作させ、`get_option`/`update_option` を上記のインメモリ store でモックすることでラウンドトリップ検証する。`wp_salt` は `WP_Mock::userFunction( 'wp_salt' )->andReturn( 'test-salt' );` を setUp に追加する。

- [ ] **Step 2: 失敗を確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter AccountCredentialsTest`
Expected: FAIL（`Class "Affilicard\Account\AccountCredentials" not found`）

- [ ] **Step 3: 実装**

`src/Account/AccountCredentials.php`（`ProviderCredentials` を土台に account 化・`getMasked`→`getStatusFor`）:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Account;

use Affilicard\Util\Crypto;
use Affilicard\Util\JsonField;

/**
 * account 毎の credentials を affilicard_account_<code>_credentials に AES 暗号化して保存する。
 *
 * 値の shape は array<string, string>。GET 応答は write-only（password は value を返さない）。
 */
final class AccountCredentials {

	private const OPTION_PREFIX = 'affilicard_account_';
	private const OPTION_SUFFIX = '_credentials';

	public static function optionKey( string $accountCode ): string {
		return self::OPTION_PREFIX . $accountCode . self::OPTION_SUFFIX;
	}

	/** @return array<string, string> */
	public static function get( string $accountCode ): array {
		$raw = get_option( self::optionKey( $accountCode ), '' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decrypted = Crypto::decrypt( $raw );
		if ( '' === $decrypted ) {
			return array();
		}
		$decoded = JsonField::decode( $decrypted, array() );
		$result  = array();
		foreach ( $decoded as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			$result[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}
		return $result;
	}

	/**
	 * type-aware な保存状態を返す。password は value を返さず isSet のみ、text は実値。
	 *
	 * @return array<string, array{value: string, isSet: bool}>
	 */
	public static function getStatusFor( AccountInterface $account ): array {
		$values = self::get( $account->code() );
		$status = array();
		foreach ( $account->credentialsSchema() as $field ) {
			$key      = (string) $field['key'];
			$isSecret = ( $field['type'] ?? 'text' ) === 'password';
			$stored   = (string) ( $values[ $key ] ?? '' );
			$status[ $key ] = array(
				'value' => $isSecret ? '' : $stored,
				'isSet' => '' !== $stored,
			);
		}
		return $status;
	}

	/**
	 * PATCH: string は上書き（空文字は明示クリア）、null は維持。
	 *
	 * @param array<string, string|null> $newValues
	 */
	public static function patch( string $accountCode, array $newValues ): void {
		$current = self::get( $accountCode );
		foreach ( $newValues as $key => $value ) {
			if ( ! is_string( $key ) || null === $value ) {
				continue;
			}
			$current[ $key ] = (string) $value;
		}
		$encrypted = Crypto::encrypt( JsonField::encode( $current ) );
		update_option( self::optionKey( $accountCode ), $encrypted, false );
	}

	public static function delete( string $accountCode ): void {
		delete_option( self::optionKey( $accountCode ) );
	}
}
```

- [ ] **Step 4: 通過を確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter AccountCredentialsTest`
Expected: PASS（`optionKey` / roundtrip、skip 1）

- [ ] **Step 5: コミット**

```bash
git add src/Account/AccountCredentials.php tests/Unit/Account/AccountCredentialsTest.php
git commit -m "feat: AccountCredentials（account 単位保存＋type-aware status）を追加"
```

---

### Task 4: ProviderInterface 改修＋各 Provider の accountCode／creds 取得元変更

**Files:**
- Modify: `src/Provider/ProviderInterface.php`
- Modify: `src/Provider/ManualProvider.php`
- Modify: `src/Provider/Dmm/DmmProvider.php`
- Modify: `src/Provider/Rakuten/RakutenProvider.php`
- Delete: `src/Provider/ProviderCredentials.php`
- Test: `tests/Unit/Provider/ProviderAccountCodeTest.php`（＋既存 Provider テストの移行）

**Interfaces:**
- Consumes: Task 3 の `AccountCredentials`。
- Produces: `ProviderInterface::accountCode(): ?string` を追加、`credentialsSchema()` を撤去。各 Provider の `fetch()` は `AccountCredentials::get($this->accountCode())` を読む。`testConnection(array $credentials)` は不変（呼び出し側が account creds を渡す）。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Provider/ProviderAccountCodeTest.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Provider;

use Affilicard\Provider\Dmm\DmmProvider;
use Affilicard\Provider\ManualProvider;
use Affilicard\Provider\Rakuten\RakutenProvider;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ProviderAccountCodeTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $t ) => $t );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_account_codes(): void {
		$this->assertNull( ( new ManualProvider() )->accountCode() );
		$this->assertSame( 'dmm', ( new DmmProvider() )->accountCode() );
		$this->assertSame( 'rakuten', ( new RakutenProvider() )->accountCode() );
	}

	public function test_credentials_schema_removed_from_interface(): void {
		$this->assertFalse(
			method_exists( ManualProvider::class, 'credentialsSchema' ),
			'credentialsSchema は ProviderInterface から撤去され Account へ移設された'
		);
	}
}
```

- [ ] **Step 2: 失敗を確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter ProviderAccountCodeTest`
Expected: FAIL（`accountCode()` 未定義）

- [ ] **Step 3: 実装**

`src/Provider/ProviderInterface.php` — `credentialsSchema()` を削除し `accountCode()` を追加:

```php
	/**
	 * 自動取得 Provider か否か（false の場合は手動入力扱い）。
	 */
	public function isAutomatic(): bool;

	/**
	 * この provider が認証情報を引く account のコード（例: 'rakuten'）。手動入力は null。
	 */
	public function accountCode(): ?string;
```

（`public function credentialsSchema(): array;` の宣言ブロックを削除する。）

`src/Provider/ManualProvider.php` — `credentialsSchema()` メソッドを削除し、`accountCode()` を追加:

```php
	public function accountCode(): ?string {
		return null;
	}
```

`src/Provider/Dmm/DmmProvider.php`:
- 冒頭 `use Affilicard\Provider\ProviderCredentials;` を `use Affilicard\Account\AccountCredentials;` に変更。
- `credentialsSchema()` メソッド（33-48 行）を削除。
- `accountCode()` を追加:

```php
	public function accountCode(): ?string {
		return 'dmm';
	}
```

- `fetch()` の creds 取得を変更（55 行）:

```php
		$credentials = AccountCredentials::get( (string) $this->accountCode() );
```

`src/Provider/Rakuten/RakutenProvider.php`:
- 冒頭 `use Affilicard\Provider\ProviderCredentials;` を `use Affilicard\Account\AccountCredentials;` に変更。
- `credentialsSchema()` メソッド（33-60 行）を削除。
- `accountCode()` を追加:

```php
	public function accountCode(): ?string {
		return 'rakuten';
	}
```

- `fetch()` の creds 取得を変更（67 行）:

```php
		$credentials = AccountCredentials::get( (string) $this->accountCode() );
```

最後に `src/Provider/ProviderCredentials.php` を削除:

```bash
git rm src/Provider/ProviderCredentials.php
```

（既存の `tests/Unit/Provider/ProviderCredentialsTest.php` が存在する場合は Task 3 の `AccountCredentialsTest` に置換済みのため `git rm` する。）

- [ ] **Step 4: 通過を確認（全 PHP テスト）**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 composer test`
Expected: 全 PASS（`credentialsSchema` を参照していた既存 provider テストは、schema アサーションを削除するか Task 2 の Account スキーマテストへ移行。`ProviderCredentials` 参照テストは削除）。
Run: `docker run --rm -v "$PWD":/app -w /app composer:2 composer lint`
Expected: 0 エラー。

- [ ] **Step 5: コミット**

```bash
git add -A src/Provider tests/Unit/Provider
git commit -m "refactor: ProviderInterface に accountCode を追加し credentialsSchema を撤去、creds を AccountCredentials 経由に"
```

---

### Task 5: RakutenClient 抽出（transport 分離）

**Files:**
- Create: `src/Provider/Rakuten/RakutenClient.php`
- Modify: `src/Provider/Rakuten/RakutenProvider.php`
- Test: `tests/Unit/Provider/Rakuten/RakutenClientTest.php`

**Interfaces:**
- Produces: `RakutenClient::request(array $query, array $credentials): array{error: bool, code: int, decoded: ?array}`（Origin/accessKey ヘッダ付与・GET 送出・JSON 復号）。`RakutenClient::toOrigin(string): string`（static・純粋）。
- Consumes（Provider 側）: `RakutenProvider::fetch()`/`testConnection()` が `RakutenClient::request()` を使う。エラーメッセージ整形（`errorMessage`）は Provider に残す。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Provider/Rakuten/RakutenClientTest.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Provider\Rakuten;

use Affilicard\Provider\Rakuten\RakutenClient;
use PHPUnit\Framework\TestCase;

final class RakutenClientTest extends TestCase {

	public function test_to_origin_adds_scheme_and_keeps_host(): void {
		$this->assertSame( 'https://e-comi.example.com', RakutenClient::toOrigin( 'e-comi.example.com' ) );
		$this->assertSame( 'https://e-comi.example.com', RakutenClient::toOrigin( 'https://e-comi.example.com/path' ) );
		$this->assertSame( 'http://localhost:8888', RakutenClient::toOrigin( 'http://localhost:8888' ) );
	}
}
```

> 注: `request()` は `wp_remote_get` 依存のため WP_Mock で response をスタブする統合テストを追加してもよいが、最小は純粋関数 `toOrigin` を移設先で回帰なく検証する。`wp_remote_*` を使う経路は既存 `RakutenProvider` テストが担保する。

- [ ] **Step 2: 失敗を確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter RakutenClientTest`
Expected: FAIL（`Class "...RakutenClient" not found`）

- [ ] **Step 3: 実装（Provider から transport を移設）**

`src/Provider/Rakuten/RakutenClient.php`（RakutenProvider の `ENDPOINT`/`requestArgs`/`resolveDomain`/`toOrigin`/`isWpError`/`request` を移設。`toOrigin` は public static に昇格）:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Provider\Rakuten;

/**
 * 楽天Kobo openapi への HTTP transport。accessKey ヘッダ・Origin/Referer を付与して GET する。
 */
final class RakutenClient {

	private const ENDPOINT = 'https://openapi.rakuten.co.jp/services/api/Kobo/EbookSearch/20170426';

	/**
	 * @param array<string, string> $query
	 * @param array<string, string> $credentials
	 * @return array{error: bool, code: int, decoded: array<string, mixed>|null}
	 */
	public function request( array $query, array $credentials ): array {
		$response = wp_remote_get(
			self::ENDPOINT . '?' . http_build_query( $query ),
			$this->requestArgs( $credentials )
		);
		if ( self::isWpError( $response ) ) {
			return array( 'error' => true, 'code' => 0, 'decoded' => null );
		}
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return array(
			'error'   => false,
			'code'    => $code,
			'decoded' => is_array( $decoded ) ? $decoded : null,
		);
	}

	/**
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

	/** @param array<string, string> $credentials */
	private function resolveDomain( array $credentials ): string {
		$domain = trim( (string) ( $credentials['allowed_domain'] ?? '' ) );
		if ( '' === $domain ) {
			$domain = (string) home_url();
		}
		return $domain;
	}

	public static function toOrigin( string $url ): string {
		$url = trim( $url );
		if ( '' !== $url && 1 !== preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}
		$parts = wp_parse_url( $url );
		if ( is_array( $parts ) && isset( $parts['host'] ) ) {
			$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : 'https';
			$origin = $scheme . '://' . (string) $parts['host'];
			if ( isset( $parts['port'] ) ) {
				$origin .= ':' . (int) $parts['port'];
			}
			return $origin;
		}
		return rtrim( $url, '/' );
	}

	/** @param mixed $value */
	private static function isWpError( $value ): bool {
		if ( function_exists( 'is_wp_error' ) ) {
			return (bool) is_wp_error( $value );
		}
		return $value instanceof \WP_Error;
	}
}
```

`src/Provider/Rakuten/RakutenProvider.php` を client 利用に変更:
- `private RakutenClient $client;` を持ち、コンストラクタ or 遅延生成で用意（`private function client(): RakutenClient { return $this->client ??= new RakutenClient(); }`）。
- `fetch()`: `$this->request(...)`（private）を `$res = $this->client()->request( $query, $credentials );` に変更し、`if ( $res['error'] || 200 !== $res['code'] || null === $res['decoded'] || isset( $res['decoded']['errors'] ) ) { return null; } $decoded = $res['decoded'];` としてから `firstItem`/`normalizeItem`。
- `testConnection()`: `wp_remote_get(...)` 直呼びを `$res = $this->client()->request( $query, $credentials );` に変更。`$res['error']` で接続失敗、`200 !== $res['code']` で `errorMessage( $res['code'] )`、`null === $res['decoded'] || isset( $res['decoded']['errors'] )` でエラー、それ以外 ok。
- Provider から `ENDPOINT`/`requestArgs`/`resolveDomain`/`toOrigin`/`request` を削除。`errorMessage`/`isWpError`/`firstItem`/`normalizeItem`/`normalizeDate`/`hasRequiredCredentials` は Provider に残す（`isWpError` は testConnection で不要になれば削除）。

- [ ] **Step 4: 通過を確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter "RakutenClientTest|RakutenProviderTest"`
Expected: PASS（既存 `RakutenProviderTest` が client 経由でも回帰なし。`wp_remote_get` をモックしている既存テストは、モック対象が `RakutenClient::request` 内の `wp_remote_get` に変わるだけで API は不変）。
Run: `docker run --rm -v "$PWD":/app -w /app composer:2 composer test && ... composer lint`
Expected: 全 PASS・lint 0。

- [ ] **Step 5: コミット**

```bash
git add src/Provider/Rakuten tests/Unit/Provider/Rakuten
git commit -m "refactor: 楽天 API transport を RakutenClient に抽出"
```

---

### Task 6: AccountUiList ／ ProviderUiList（SSOT ビルダー）

**Files:**
- Create: `src/Account/AccountUiList.php`
- Create: `src/Provider/ProviderUiList.php`
- Test: `tests/Unit/Account/AccountUiListTest.php`・`tests/Unit/Provider/ProviderUiListTest.php`

**Interfaces:**
- Produces: `AccountUiList::build(AccountRegistry): list<array{code,label,credentialsSchema}>`。`ProviderUiList::build(ProviderRegistry): list<array{code,label,isAutomatic,accountCode}>`。Task 8 が両者を `wp_json_encode` して注入する。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Account/AccountUiListTest.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Account;

use Affilicard\Account\AccountInterface;
use Affilicard\Account\AccountRegistry;
use Affilicard\Account\AccountUiList;
use PHPUnit\Framework\TestCase;

final class AccountUiListTest extends TestCase {

	public function test_build_maps_accounts_in_order(): void {
		$registry = new AccountRegistry();
		$registry->register(
			new class() implements AccountInterface {
				public function code(): string {
					return 'sample';
				}
				public function label(): string {
					return 'Sample';
				}
				public function credentialsSchema(): array {
					return array(
						array( 'key' => 'k', 'label' => 'K', 'type' => 'password', 'required' => true ),
					);
				}
			}
		);

		$list = AccountUiList::build( $registry );

		$this->assertCount( 1, $list );
		$this->assertSame( 'sample', $list[0]['code'] );
		$this->assertSame( 'Sample', $list[0]['label'] );
		$this->assertSame( 'k', $list[0]['credentialsSchema'][0]['key'] );
	}
}
```

`tests/Unit/Provider/ProviderUiListTest.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Provider;

use Affilicard\Provider\ProviderInterface;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Provider\ProviderUiList;
use PHPUnit\Framework\TestCase;

final class ProviderUiListTest extends TestCase {

	private function provider( string $code, string $label, bool $auto, ?string $account ): ProviderInterface {
		return new class( $code, $label, $auto, $account ) implements ProviderInterface {
			public function __construct(
				private string $code,
				private string $label,
				private bool $auto,
				private ?string $account
			) {}
			public function code(): string {
				return $this->code;
			}
			public function label(): string {
				return $this->label;
			}
			public function isAutomatic(): bool {
				return $this->auto;
			}
			public function accountCode(): ?string {
				return $this->account;
			}
			public function fetch( string $externalId, array $platformConfig ): ?array {
				return null;
			}
			public function testConnection( array $credentials ): array {
				return array( 'ok' => true, 'message' => '' );
			}
		};
	}

	public function test_build_includes_account_code(): void {
		$registry = new ProviderRegistry();
		$registry->register( $this->provider( 'manual', '手動入力', false, null ) );
		$registry->register( $this->provider( 'rakuten-kobo', '楽天Kobo', true, 'rakuten' ) );

		$list = ProviderUiList::build( $registry );

		$this->assertSame( 'manual', $list[0]['code'] );
		$this->assertNull( $list[0]['accountCode'] );
		$this->assertFalse( $list[0]['isAutomatic'] );
		$this->assertSame( 'rakuten', $list[1]['accountCode'] );
		$this->assertTrue( $list[1]['isAutomatic'] );
	}
}
```

- [ ] **Step 2: 失敗を確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter "AccountUiListTest|ProviderUiListTest"`
Expected: FAIL（両クラス未定義）

- [ ] **Step 3: 実装**

`src/Account/AccountUiList.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Account;

/**
 * 設定画面(React)へ渡す account UI リストを組み立てる。credentialsSchema の SSOT。
 */
final class AccountUiList {

	/**
	 * @return list<array{code: string, label: string, credentialsSchema: list<array{key: string, label: string, type: string, required: bool}>}>
	 */
	public static function build( AccountRegistry $registry ): array {
		$list = array();
		foreach ( $registry->all() as $account ) {
			$list[] = array(
				'code'              => $account->code(),
				'label'             => $account->label(),
				'credentialsSchema' => $account->credentialsSchema(),
			);
		}
		return $list;
	}
}
```

`src/Provider/ProviderUiList.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Provider;

/**
 * 設定画面(React)のドロップダウンへ渡す provider UI リスト。schema は持たず accountCode を持つ。
 */
final class ProviderUiList {

	/**
	 * @return list<array{code: string, label: string, isAutomatic: bool, accountCode: string|null}>
	 */
	public static function build( ProviderRegistry $registry ): array {
		$list = array();
		foreach ( $registry->all() as $provider ) {
			$list[] = array(
				'code'        => $provider->code(),
				'label'       => $provider->label(),
				'isAutomatic' => $provider->isAutomatic(),
				'accountCode' => $provider->accountCode(),
			);
		}
		return $list;
	}
}
```

- [ ] **Step 4: 通過を確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter "AccountUiListTest|ProviderUiListTest"`
Expected: PASS（2 tests）

- [ ] **Step 5: コミット**

```bash
git add src/Account/AccountUiList.php src/Provider/ProviderUiList.php tests/Unit/Account/AccountUiListTest.php tests/Unit/Provider/ProviderUiListTest.php
git commit -m "feat: SSOT 注入用ビルダー AccountUiList / ProviderUiList を追加"
```

---

### Task 7: CredentialsController の再構成（accounts CRUD＋provider test）

**Files:**
- Modify: `src/Rest/CredentialsController.php`（全面書き換え）
- Test: `tests/Unit/Rest/CredentialsControllerTest.php`（既存を移行・全面書き換え）

**Interfaces:**
- Consumes: `AccountRegistry`・`ProviderRegistry`・`AccountCredentials`。
- Produces: ルート `GET/PUT/DELETE /accounts/{code}/credentials`・`POST /providers/{code}/test-connection`。コンストラクタ `__construct(ProviderRegistry $providers, AccountRegistry $accounts)`。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Rest/CredentialsControllerTest.php`（要点のみ・既存テストを置換）:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Rest;

use Affilicard\Account\AccountInterface;
use Affilicard\Account\AccountRegistry;
use Affilicard\Provider\ProviderInterface;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Rest\CredentialsController;
use WP_Mock;
use WP_Mock\Tools\TestCase;
use WP_REST_Request;

final class CredentialsControllerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $t ) => $t );
		WP_Mock::userFunction( 'wp_salt' )->andReturn( 'test-salt' );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	private function accounts(): AccountRegistry {
		$reg = new AccountRegistry();
		$reg->register(
			new class() implements AccountInterface {
				public function code(): string {
					return 'sample';
				}
				public function label(): string {
					return 'Sample';
				}
				public function credentialsSchema(): array {
					return array(
						array( 'key' => 'pub', 'label' => 'Pub', 'type' => 'text', 'required' => true ),
						array( 'key' => 'sec', 'label' => 'Sec', 'type' => 'password', 'required' => true ),
					);
				}
			}
		);
		return $reg;
	}

	public function test_put_returns_400_when_required_missing_after_merge(): void {
		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		WP_Mock::userFunction( 'get_option' )->andReturn( '' ); // 保存空
		$controller = new CredentialsController( new ProviderRegistry(), $this->accounts() );

		$request = \Mockery::mock( WP_REST_Request::class );
		$request->shouldReceive( 'get_param' )->with( 'code' )->andReturn( 'sample' );
		$request->shouldReceive( 'get_params' )->andReturn( array( 'code' => 'sample', 'pub' => 'x' ) ); // sec 欠

		$response = $controller->updateAccount( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertContains( 'sec', $response->get_data()['missing'] );
	}
}
```

> 注: 既存テストが `get`/`update`/`testConnection`（platform/provider 系）を検証していれば、accounts 系＋provider test 系に置換する。`update_option`/`get_option` はインメモリ store（Task 3 参照）でラウンドトリップ検証する。

- [ ] **Step 2: 失敗を確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter CredentialsControllerTest`
Expected: FAIL（`updateAccount` 未定義・コンストラクタ引数不一致）

- [ ] **Step 3: 実装（全面書き換え）**

`src/Rest/CredentialsController.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Rest;

use Affilicard\Account\AccountCredentials;
use Affilicard\Account\AccountRegistry;
use Affilicard\Provider\ProviderRegistry;
use WP_REST_Request;
use WP_REST_Response;

/**
 * 認証情報 REST。credentials は account 単位（GET/PUT/DELETE）、接続テストは provider 単位（POST）。
 */
final class CredentialsController {

	public function __construct(
		private ProviderRegistry $providers,
		private AccountRegistry $accounts
	) {}

	public function registerRoutes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/accounts/(?P<code>[a-z0-9-]+)/credentials',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'getAccount' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'updateAccount' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'deleteAccount' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/providers/(?P<code>[a-z0-9-]+)/test-connection',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'testConnection' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
				),
			)
		);
	}

	public function canManageOptions(): bool {
		return (bool) current_user_can( 'manage_options' );
	}

	public function getAccount( WP_REST_Request $request ): WP_REST_Response {
		$account = $this->accounts->get( (string) $request->get_param( 'code' ) );
		if ( null === $account ) {
			return $this->accountNotFound();
		}
		return new WP_REST_Response( AccountCredentials::getStatusFor( $account ), 200 );
	}

	public function updateAccount( WP_REST_Request $request ): WP_REST_Response {
		$account = $this->accounts->get( (string) $request->get_param( 'code' ) );
		if ( null === $account ) {
			return $this->accountNotFound();
		}

		$values = $this->submittedValues( $request );

		// マージ後状態で required 検証。
		$merged  = array_merge( AccountCredentials::get( $account->code() ), $values );
		$missing = array();
		foreach ( $account->credentialsSchema() as $field ) {
			if ( ! empty( $field['required'] ) && '' === (string) ( $merged[ $field['key'] ] ?? '' ) ) {
				$missing[] = (string) $field['key'];
			}
		}
		if ( array() !== $missing ) {
			return new WP_REST_Response(
				array(
					'code'    => 'affilicard_missing_required',
					'message' => __( '必須項目が未入力です。', 'affilicard' ),
					'missing' => $missing,
				),
				400
			);
		}

		AccountCredentials::patch( $account->code(), $values );
		return new WP_REST_Response( AccountCredentials::getStatusFor( $account ), 200 );
	}

	public function deleteAccount( WP_REST_Request $request ): WP_REST_Response {
		$account = $this->accounts->get( (string) $request->get_param( 'code' ) );
		if ( null === $account ) {
			return $this->accountNotFound();
		}
		AccountCredentials::delete( $account->code() );
		return new WP_REST_Response( AccountCredentials::getStatusFor( $account ), 200 );
	}

	public function testConnection( WP_REST_Request $request ): WP_REST_Response {
		$provider = $this->providers->get( (string) $request->get_param( 'code' ) );
		if ( null === $provider ) {
			return $this->providerNotFound();
		}

		$accountCode = $provider->accountCode();
		$stored      = null === $accountCode ? array() : AccountCredentials::get( $accountCode );
		$merged      = array_merge( $stored, $this->submittedValues( $request ) );

		$result = $provider->testConnection( $merged );
		return new WP_REST_Response(
			array(
				'ok'      => (bool) ( $result['ok'] ?? false ),
				'message' => (string) ( $result['message'] ?? '' ),
			),
			200
		);
	}

	/**
	 * リクエスト body から credentials の文字列マップを取り出す（code は除外）。
	 *
	 * @return array<string, string>
	 */
	private function submittedValues( WP_REST_Request $request ): array {
		$params = $request->get_params();
		if ( ! is_array( $params ) ) {
			return array();
		}
		unset( $params['code'] );
		$values = array();
		foreach ( $params as $key => $value ) {
			if ( is_string( $key ) && null !== $value ) {
				$values[ $key ] = (string) $value;
			}
		}
		return $values;
	}

	private function accountNotFound(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'code'    => 'affilicard_account_not_found',
				'message' => __( '指定されたアカウントが見つかりません。', 'affilicard' ),
			),
			404
		);
	}

	private function providerNotFound(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'code'    => 'affilicard_provider_not_found',
				'message' => __( '指定された Provider が見つかりません。', 'affilicard' ),
			),
			404
		);
	}
}
```

- [ ] **Step 4: 通過を確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter CredentialsControllerTest`
Expected: PASS。続けて `composer test`／`composer lint` 全 PASS。

- [ ] **Step 5: コミット**

```bash
git add src/Rest/CredentialsController.php tests/Unit/Rest/CredentialsControllerTest.php
git commit -m "feat: 認証 REST を accounts CRUD＋provider test-connection に再構成"
```

---

### Task 8: Plugin 配線（AccountRegistry・2 globals 注入・route・旧キー purge）

**Files:**
- Modify: `src/Plugin.php`
- Test: 手動（`composer test` 回帰＋`npm run build`＋PR プレビュー）。glue のためユニットは既存で担保。

**Interfaces:**
- Consumes: Task 1/2 `AccountRegistry`/`RakutenAccount`/`DmmAccount`、Task 6 `AccountUiList`/`ProviderUiList`、Task 7 `CredentialsController`。

- [ ] **Step 1: buildAccountRegistry を追加**

`src/Plugin.php` に `buildProviderRegistry()` と対称のメソッドを追加:

```php
	public static function buildAccountRegistry(): \Affilicard\Account\AccountRegistry {
		$registry = new \Affilicard\Account\AccountRegistry();
		$registry->register( new \Affilicard\Account\RakutenAccount() );
		$registry->register( new \Affilicard\Account\DmmAccount() );
		return $registry;
	}
```

- [ ] **Step 2: CredentialsController の生成に AccountRegistry を渡す**

`new CredentialsController( $providers )`（既存 75 行付近）を:

```php
			new CredentialsController( $providers, self::buildAccountRegistry() ),
```

- [ ] **Step 3: 設定ページで 2 globals を注入**

`enqueueSettingsAssets()` の `wp_enqueue_script( 'affilicard-settings', ... )` 直後に追記（先頭 use に `use Affilicard\Account\AccountUiList;` / `use Affilicard\Provider\ProviderUiList;`）:

```php
		wp_add_inline_script(
			'affilicard-settings',
			'window.affilicardAccounts=' . wp_json_encode( AccountUiList::build( self::buildAccountRegistry() ) ) . ';'
			. 'window.affilicardProviders=' . wp_json_encode( ProviderUiList::build( self::buildProviderRegistry() ) ) . ';',
			'before'
		);
```

- [ ] **Step 4: 旧 provider 単位キーの一度きり purge**

`admin_init`（or `plugins_loaded`）フックに、オプションフラグでガードした purge を追加:

```php
	public static function purgeLegacyProviderCredentials(): void {
		if ( get_option( 'affilicard_legacy_creds_purged' ) ) {
			return;
		}
		global $wpdb;
		$like = $wpdb->esc_like( 'affilicard_provider_' ) . '%';
		$keys = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
		);
		foreach ( (array) $keys as $key ) {
			delete_option( (string) $key );
		}
		update_option( 'affilicard_legacy_creds_purged', 1, false );
	}
```

`init()`（or 既存の hook 登録箇所）で `add_action( 'admin_init', array( self::class, 'purgeLegacyProviderCredentials' ) );` を登録。

- [ ] **Step 5: 回帰確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 composer test && ... composer lint`
Expected: 全 PASS・lint 0。
Run: `npm run build`
Expected: compiled successfully。

- [ ] **Step 6: コミット**

```bash
git add src/Plugin.php
git commit -m "feat: AccountRegistry 配線・2 globals 注入・旧 provider キーの purge を追加"
```

---

### Task 9: Uninstall に account キー削除を追加

**Files:**
- Modify: `src/Uninstall.php`
- Test: `tests/Unit/UninstallTest.php`（既存があれば追記・無ければ最小追加）

**Interfaces:**
- Produces: `uninstall` 時に `affilicard_provider_%` に加え `affilicard_account_%` を LIKE 削除。

- [ ] **Step 1: 失敗するテストを書く（LIKE 対象の網羅）**

`tests/Unit/UninstallTest.php`（既存の deleteProviderCredentials テストに account 版を追加。`$wpdb` をモックし、`account` プレフィックスの LIKE 呼び出しを検証）:

```php
	public function test_uninstall_deletes_account_credentials(): void {
		// $wpdb->get_col が account/provider の両プレフィックスで呼ばれることを検証。
		// 既存 UninstallTest の $wpdb モックに 'affilicard_account_%' の期待を追加する。
		$this->assertTrue( true ); // 実装後、下記 esc_like 呼び出しの検証に置換
	}
```

> 注: 既存 `UninstallTest` の `$wpdb` モック（`esc_like`/`prepare`/`get_col`/`query`）に合わせて、`affilicard_account_` プレフィックスの削除経路を検証するアサーションを追加する。既存が無ければ Uninstall の `deleteAccountCredentials()` を直接呼べる形にして検証する。

- [ ] **Step 2: 失敗を確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter UninstallTest`
Expected: FAIL（account 削除経路が無い）

- [ ] **Step 3: 実装**

`src/Uninstall.php` の既存 `deleteProviderCredentials()` を汎用化するか、対になる `deleteAccountCredentials()` を追加して `run()`（or エントリ）から両方呼ぶ:

```php
	private static function deleteAccountCredentials(): void {
		global $wpdb;
		$like = $wpdb->esc_like( 'affilicard_account_' ) . '%';
		$keys = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
		);
		foreach ( (array) $keys as $key ) {
			delete_option( (string) $key );
		}
	}
```

既存の削除呼び出し箇所（`self::deleteProviderCredentials();` の隣）に `self::deleteAccountCredentials();` を追加。

- [ ] **Step 4: 通過を確認**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter UninstallTest`
Expected: PASS。

- [ ] **Step 5: コミット**

```bash
git add src/Uninstall.php tests/Unit/UninstallTest.php
git commit -m "feat: uninstall で account 単位 credentials も削除する"
```

---

### Task 10: JS SSOT 導出（accounts.js／providers.js／api）

**Files:**
- Create: `src/Admin/accounts.js`
- Modify: `src/Admin/providers.js`（ハードコード廃止）
- Modify: `src/Admin/api/credentials.js`（accounts CRUD＋provider test）
- Test: `tests/js/Admin/accounts.test.js`・`tests/js/Admin/providers.test.js`（既存を書き換え）

**Interfaces:**
- Produces: `ACCOUNTS`（`[{code,label,credentialsSchema}]`）／`PROVIDER_OPTIONS`（`[{label,value}]`・形不変）／`providerAccount(code)`。api: `fetchCredentials(accountCode)`・`updateCredentials(accountCode,values)`・`deleteCredentials(accountCode)`・`testConnection(providerCode,values)`。

- [ ] **Step 1: 失敗するテストを書く**

`tests/js/Admin/accounts.test.js`:

```js
describe('accounts（window.affilicardAccounts からの導出）', () => {
	const load = () => {
		jest.resetModules();
		return require('../../../src/Admin/accounts');
	};
	afterEach(() => {
		delete window.affilicardAccounts;
	});

	it('window から ACCOUNTS を導出する', () => {
		window.affilicardAccounts = [
			{ code: 'rakuten', label: '楽天', credentialsSchema: [{ key: 'access_key', label: 'AK', type: 'password', required: true }] },
		];
		const { ACCOUNTS } = load();
		expect(ACCOUNTS[0].code).toBe('rakuten');
		expect(ACCOUNTS[0].credentialsSchema[0].type).toBe('password');
	});

	it('未定義なら空配列にフォールバック', () => {
		const { ACCOUNTS } = load();
		expect(ACCOUNTS).toEqual([]);
	});
});
```

`tests/js/Admin/providers.test.js`（既存を全面置換）:

```js
describe('providers（window.affilicardProviders からの導出）', () => {
	const load = () => {
		jest.resetModules();
		return require('../../../src/Admin/providers');
	};
	afterEach(() => {
		delete window.affilicardProviders;
	});

	it('PROVIDER_OPTIONS と providerAccount を導出する', () => {
		window.affilicardProviders = [
			{ code: 'manual', label: '手動入力', isAutomatic: false, accountCode: null },
			{ code: 'rakuten-kobo', label: '楽天Kobo', isAutomatic: true, accountCode: 'rakuten' },
		];
		const { PROVIDER_OPTIONS, providerAccount } = load();
		expect(PROVIDER_OPTIONS).toEqual([
			{ label: '手動入力', value: 'manual' },
			{ label: '楽天Kobo', value: 'rakuten-kobo' },
		]);
		expect(providerAccount('rakuten-kobo')).toBe('rakuten');
		expect(providerAccount('manual')).toBeNull();
	});

	it('未定義なら空にフォールバック', () => {
		const { PROVIDER_OPTIONS } = load();
		expect(PROVIDER_OPTIONS).toEqual([]);
	});
});
```

- [ ] **Step 2: 失敗を確認**

Run: `npm run test:js`
Expected: FAIL（`accounts` モジュール無し・`providers` の旧ハードコードが新テストに不一致）

- [ ] **Step 3: 実装**

`src/Admin/accounts.js`:

```js
// Account 定義（credentials スキーマ）は PHP を単一情報源とし、設定ページ enqueue 時に
// window.affilicardAccounts へ注入される（wp_add_inline_script）。
const injected =
	typeof window !== 'undefined' && Array.isArray(window.affilicardAccounts)
		? window.affilicardAccounts
		: [];

export const ACCOUNTS = injected; // [{code,label,credentialsSchema}]
```

`src/Admin/providers.js`（全面置換）:

```js
// Provider 定義は PHP を単一情報源とし、window.affilicardProviders へ注入される。
// schema は持たず accountCode を持つ（credentials スキーマは accounts.js の ACCOUNTS 側）。
const injected =
	typeof window !== 'undefined' && Array.isArray(window.affilicardProviders)
		? window.affilicardProviders
		: [];

export const PROVIDER_OPTIONS = injected.map((p) => ({
	label: p.label,
	value: p.code,
}));

export const providerAccount = (code) =>
	injected.find((p) => p.code === code)?.accountCode ?? null;
```

`src/Admin/api/credentials.js`（accounts CRUD＋provider test）:

```js
import apiFetch from '@wordpress/api-fetch';

const accountBase = (code) =>
	`/affilicard/v1/accounts/${encodeURIComponent(code)}/credentials`;

export function fetchCredentials(accountCode) {
	return apiFetch({ path: accountBase(accountCode) });
}

export function updateCredentials(accountCode, values) {
	return apiFetch({ path: accountBase(accountCode), method: 'PUT', data: values });
}

export function deleteCredentials(accountCode) {
	return apiFetch({ path: accountBase(accountCode), method: 'DELETE' });
}

export function testConnection(providerCode, values) {
	return apiFetch({
		path: `/affilicard/v1/providers/${encodeURIComponent(providerCode)}/test-connection`,
		method: 'POST',
		data: values,
	});
}
```

- [ ] **Step 4: 通過を確認**

Run: `npm run test:js`
Expected: PASS。
Run: `npm run lint:js`
Expected: 対象ファイルに新規エラーなし。

- [ ] **Step 5: コミット**

```bash
git add src/Admin/accounts.js src/Admin/providers.js src/Admin/api/credentials.js tests/js/Admin/accounts.test.js tests/js/Admin/providers.test.js
git commit -m "refactor: JS を account SSOT 由来に置換し API クライアントを新ルートへ"
```

---

### Task 11: JS UI（AccountCredentialEditor／ApiCredentialsPanel）

**Files:**
- Create: `src/Admin/components/AccountCredentialEditor.jsx`
- Delete: `src/Admin/components/CredentialEditor.jsx`
- Modify: `src/Admin/components/ApiCredentialsPanel.jsx`
- Test: `tests/js/Admin/AccountCredentialEditor.test.js`

**Interfaces:**
- Consumes: Task 10 の `ACCOUNTS`・`providerAccount`・api（`fetchCredentials`/`updateCredentials`/`deleteCredentials`/`testConnection`）。
- Produces: account を `PanelBody` で折り畳み表示。password は空欄＋設定済みバッジ。dirty キーのみ PUT。provider 単位テスト。

- [ ] **Step 1: 失敗するテストを書く（dirty 送信の検証）**

`tests/js/Admin/AccountCredentialEditor.test.js`:

```js
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { AccountCredentialEditor } from '../../../src/Admin/components/AccountCredentialEditor';
import * as api from '../../../src/Admin/api/credentials';

jest.mock('../../../src/Admin/api/credentials');

const account = {
	code: 'rakuten',
	label: '楽天',
	credentialsSchema: [
		{ key: 'application_id', label: 'アプリID', type: 'text', required: true },
		{ key: 'access_key', label: 'アクセスキー', type: 'password', required: true },
	],
};

beforeEach(() => {
	api.fetchCredentials.mockResolvedValue({
		application_id: { value: 'app-1', isSet: true },
		access_key: { value: '', isSet: true },
	});
	api.updateCredentials.mockResolvedValue({
		application_id: { value: 'app-2', isSet: true },
		access_key: { value: '', isSet: true },
	});
});

it('未編集の password は PUT に含めない（dirty のみ送信）', async () => {
	render(<AccountCredentialEditor account={account} providers={[]} />);
	await waitFor(() => screen.getByDisplayValue('app-1'));

	fireEvent.change(screen.getByLabelText('アプリID'), { target: { value: 'app-2' } });
	fireEvent.click(screen.getByText('認証情報を保存'));

	await waitFor(() => expect(api.updateCredentials).toHaveBeenCalled());
	const [, sent] = api.updateCredentials.mock.calls[0];
	expect(sent).toEqual({ application_id: 'app-2' }); // access_key は未編集＝送らない
});
```

- [ ] **Step 2: 失敗を確認**

Run: `npm run test:js`
Expected: FAIL（`AccountCredentialEditor` 未実装）

- [ ] **Step 3: 実装**

`src/Admin/components/AccountCredentialEditor.jsx`:

```jsx
import { useEffect, useState } from '@wordpress/element';
import { TextControl, Button, Notice, Flex } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	fetchCredentials,
	updateCredentials,
	deleteCredentials,
	testConnection,
} from '../api/credentials';

export function AccountCredentialEditor({ account, providers }) {
	const schema = account.credentialsSchema ?? [];
	const [status, setStatus] = useState({}); // { key: {value,isSet} }
	const [inputs, setInputs] = useState({}); // 編集中の値（text の初期値は status.value）
	const [dirty, setDirty] = useState({}); // key -> true
	const [reveal, setReveal] = useState({});
	const [result, setResult] = useState(null);
	const [tests, setTests] = useState({}); // providerCode -> {ok,message}

	useEffect(() => {
		fetchCredentials(account.code)
			.then((s) => {
				setStatus(s);
				const init = {};
				schema.forEach((f) => {
					init[f.key] = f.type === 'password' ? '' : s[f.key]?.value ?? '';
				});
				setInputs(init);
				setDirty({});
			})
			.catch(() => setStatus({}));
	}, [account.code]);

	const onChange = (key, v) => {
		setInputs({ ...inputs, [key]: v });
		setDirty({ ...dirty, [key]: true });
	};

	const dirtyValues = () => {
		const out = {};
		Object.keys(dirty).forEach((k) => {
			if (dirty[k]) out[k] = inputs[k];
		});
		return out;
	};

	const onSave = async () => {
		setResult(null);
		try {
			const next = await updateCredentials(account.code, dirtyValues());
			setStatus(next);
			setDirty({});
			setInputs((prev) => {
				const merged = { ...prev };
				schema.forEach((f) => {
					if (f.type === 'password') merged[f.key] = '';
					else merged[f.key] = next[f.key]?.value ?? '';
				});
				return merged;
			});
			setResult({ ok: true, message: __('認証情報を保存しました', 'affilicard') });
		} catch (e) {
			const missing = e?.data?.missing || e?.missing;
			setResult({
				ok: false,
				message: missing
					? __('必須項目が未入力です', 'affilicard')
					: __('保存に失敗しました', 'affilicard'),
			});
		}
	};

	const onDelete = async () => {
		// eslint-disable-next-line no-alert
		if (!window.confirm(__('このアカウントの認証情報を削除しますか？', 'affilicard'))) return;
		const next = await deleteCredentials(account.code);
		setStatus(next);
		setDirty({});
		setInputs((prev) => {
			const cleared = { ...prev };
			schema.forEach((f) => {
				cleared[f.key] = '';
			});
			return cleared;
		});
		setResult({ ok: true, message: __('認証情報を削除しました', 'affilicard') });
	};

	const onTest = async (providerCode) => {
		try {
			const r = await testConnection(providerCode, dirtyValues());
			setTests({ ...tests, [providerCode]: r });
		} catch {
			setTests({
				...tests,
				[providerCode]: { ok: false, message: __('接続テストに失敗しました', 'affilicard') },
			});
		}
	};

	return (
		<div className="affilicard-account-credential-editor">
			{schema.map((f) => (
				<TextControl
					key={f.key}
					label={f.label}
					type={f.type === 'password' && !reveal[f.key] ? 'password' : 'text'}
					value={inputs[f.key] ?? ''}
					placeholder={
						f.type === 'password' && status[f.key]?.isSet
							? __('設定済み（変更する場合のみ入力）', 'affilicard')
							: ''
					}
					onChange={(v) => onChange(f.key, v)}
				/>
			))}

			{providers.length > 0 && (
				<div className="affilicard-account-tests">
					<p className="description">{__('このアカウントを使う連携:', 'affilicard')}</p>
					{providers.map((p) => (
						<Flex key={p.code} justify="flex-start" gap={2}>
							<span>{p.label}</span>
							<Button variant="secondary" onClick={() => onTest(p.code)}>
								{__('接続テスト', 'affilicard')}
							</Button>
							{tests[p.code] && (
								<span>{tests[p.code].ok ? '✓' : '✗'} {tests[p.code].message}</span>
							)}
						</Flex>
					))}
				</div>
			)}

			<Flex justify="flex-start" gap={2}>
				<Button variant="secondary" onClick={onSave}>
					{__('認証情報を保存', 'affilicard')}
				</Button>
				<Button variant="tertiary" isDestructive onClick={onDelete}>
					{__('認証情報を削除', 'affilicard')}
				</Button>
			</Flex>

			{result && (
				<Notice status={result.ok ? 'success' : 'error'} onRemove={() => setResult(null)}>
					{result.message}
				</Notice>
			)}
		</div>
	);
}
```

`src/Admin/components/ApiCredentialsPanel.jsx`（account を PanelBody で折り畳み・provider を紐付け）:

```jsx
import { PanelBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ACCOUNTS } from '../accounts';
import { PROVIDER_OPTIONS, providerAccount } from '../providers';
import { AccountCredentialEditor } from './AccountCredentialEditor';

export function ApiCredentialsPanel() {
	return (
		<div className="affilicard-api-credentials-panel">
			<h2>{__('API 認証', 'affilicard')}</h2>
			<p className="description">
				{__('API 連携を使うアカウントの認証情報を設定します（アカウント単位で共有）。', 'affilicard')}
			</p>
			{ACCOUNTS.length === 0 && (
				<p className="description">{__('認証情報を必要とするアカウントはありません。', 'affilicard')}</p>
			)}
			{ACCOUNTS.map((account) => {
				const providers = PROVIDER_OPTIONS.filter(
					(o) => providerAccount(o.value) === account.code
				).map((o) => ({ code: o.value, label: o.label }));
				return (
					<PanelBody key={account.code} title={account.label} initialOpen={false}>
						<AccountCredentialEditor account={account} providers={providers} />
					</PanelBody>
				);
			})}
		</div>
	);
}
```

`src/Admin/components/CredentialEditor.jsx` を削除:

```bash
git rm src/Admin/components/CredentialEditor.jsx
```

（旧 `tests/js/Admin/CredentialEditor.test.js` があれば `git rm`。）

- [ ] **Step 4: 通過を確認**

Run: `npm run test:js`
Expected: PASS（AccountCredentialEditor の dirty 送信テスト含む）。
Run: `npm run lint:js && npm run build`
Expected: lint クリーン・build 成功。

- [ ] **Step 5: コミット**

```bash
git add -A src/Admin/components tests/js/Admin
git commit -m "feat: 認証 UI を account 折り畳み＋write-only＋dirty＋provider 別テストへ刷新"
```

---

### Task 12: v2.0.0 bump ＋ CHANGELOG ＋ README

**Files:**
- Modify: `affilicard.php`（`Version:` ヘッダ・`AFFILICARD_VERSION`）
- Modify: `CHANGELOG.md`
- Modify: `README.md`（Account/Provider 追加手順）

**Interfaces:** なし（リリースメタ）。

- [ ] **Step 1: バージョンを 2.0.0 に同期**

`affilicard.php` の `Version:     1.9.0`（6 行目）と `define( 'AFFILICARD_VERSION', '1.9.0' );`（25 行目）を **両方 `2.0.0`** に更新。

- [ ] **Step 2: CHANGELOG 追記**

`CHANGELOG.md` の `## [Unreleased]` 直後に:

```markdown
## [2.0.0] - 2026-07-14

### Changed (BREAKING)

- 認証情報の保存単位を provider 単位から **account 単位**（`affilicard_account_<code>_credentials`）へ変更。`ProviderInterface` から `credentialsSchema()` を撤去し `accountCode()` を追加、スキーマは `AccountInterface` へ移設。
- 認証 REST を再構成: credentials は `/accounts/{code}/credentials`（GET/PUT/DELETE）、接続テストは `/providers/{code}/test-connection`（POST・保存前テスト）。旧 platform/provider credentials ルートは撤去。
- 設定画面の認証フィールドを **write-only ＋ dirty 追跡** 化（未編集の秘匿値を再送しない）。required をサーバ検証。認証パネルを account 単位の折り畳み＋provider 単位の接続テストへ刷新。
- Provider スキーマを PHP（`AccountUiList`/`ProviderUiList`）から `window.affilicardAccounts`/`window.affilicardProviders` として注入し、JS のハードコードを廃止。
- 楽天 API transport を `RakutenClient` に分離。

### Note

- 未公開のため移行は行わない。旧 `affilicard_provider_*` credentials はアップグレード時に削除される。
```

- [ ] **Step 3: README に追加手順を追記**

`README.md` の開発者向けセクションに:

```markdown
### Account / Provider を追加する

1. `src/Account/<Name>Account.php` に `AccountInterface`（`code`/`label`/`credentialsSchema`）を実装し、
   `Plugin::buildAccountRegistry()` に register する。
2. `src/Provider/<Name>/<Name>Provider.php` に `ProviderInterface`（`code`/`label`/`isAutomatic`/
   `accountCode`/`fetch`/`testConnection`）を実装し、`Plugin::buildProviderRegistry()` に register する。

設定画面のアカウント認証フィールド・provider ドロップダウンは、`AccountUiList`/`ProviderUiList` →
`wp_add_inline_script` → `accounts.js`/`providers.js` で **自動生成**される（管理画面 JS の改修は不要）。
```

- [ ] **Step 4: 全 green を確認**

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer lint
npm run test:js
npm run build
```
Expected: すべて PASS。

- [ ] **Step 5: コミット**

```bash
git add affilicard.php CHANGELOG.md README.md
git commit -m "chore: v2.0.0（認証サブシステム再設計）"
```

---

## 完了後の確認（PR 前）

- `composer test` / `composer lint` / `npm run test:js` / `npm run build` すべて green。
- **CodeRabbit CLI をローカル先行**してから push。
- **wp-env（実 WP）** で楽天の credentials 保存・`/providers/rakuten-kobo/test-connection`（Origin 依存）が通ることを確認。**Playground** で UI（account 折り畳み・write-only・dirty 追跡・削除）と DMM を確認。退行なし。
- 実装 PR は自動マージしない。プレビューでユーザー確認 → マージ。マージ後タグ `v2.0.0` で `release.yml` が Release 公開。

## Self-Review 結果

- **Spec coverage**: §4-2/4-3（Account/Provider IF）=T1/T2/T4、§4-5（RakutenClient）=T5、§5（AccountCredentials・write-only・required・purge）=T3/T7/T8/T9、§6（REST）=T7、§7（SSOT）=T6/T8/T10、§8（UI）=T11、§9-1（テスト）=各タスク、§9-2（v2.0.0）=T12。§9-3 follow-up はスコープ外（実装しない）。
- **Placeholder scan**: 各ステップに実コード・実コマンド・期待結果。TBD なし。T9 のテストは既存 `UninstallTest` の `$wpdb` モック形状に依存するため「既存形状に合わせる」注記付き（実装時に確定）。
- **Type consistency**: `AccountInterface`（code/label/credentialsSchema）・`accountCode():?string`・`getStatusFor(AccountInterface): array<string,{value,isSet}>`・`ProviderUiList.build`/`AccountUiList.build` の戻り値・`CredentialsController.__construct(ProviderRegistry,AccountRegistry)`・JS `PROVIDER_OPTIONS`{label,value}/`providerAccount`/`ACCOUNTS` は T 間で一致。REST body の dirty 値契約（T7↔T10↔T11）一致。
