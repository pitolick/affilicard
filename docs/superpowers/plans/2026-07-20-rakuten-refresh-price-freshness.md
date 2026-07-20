# 楽天Kobo refresh 再設計 ＋ API準拠の価格鮮度表示 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 楽天Kobo の商品カード価格をセール変動時も自動更新し、アフィリエイト規約に準拠して「API 確認済み・鮮度内」の価格だけを表示する。

**Architecture:** 柱A=`RakutenProvider` を title 検索→URLハッシュ一致同定に再設計し `ListingRefresher` から listing コンテキストを渡す。柱B=`last_verified_at`（fetch 成功時刻）＋共有ヘルパ `PriceFreshness` による価格表示ゲート（CardRenderer / 管理カラム共用）。柱C=更新頻度を `refreshIntervalHours`（N時間毎・既定3）に変更し WP-Cron カスタムスケジュールを動的登録。

**Tech Stack:** PHP 8.2（affilicard 本体・PHPUnit/WP_Mock）、JS/JSX（@wordpress/*・wp-scripts test-unit-js）、WordPress プラグイン。e-comi 側は TypeScript（別リポ・別 PR・Vitest）。

## Global Constraints

- 設計仕様: `docs/superpowers/specs/2026-07-20-rakuten-refresh-price-freshness-design.md`（本計画の一次根拠）。
- `priceTtlHours` は**全 platform 一律 24**（Amazon PA-API ハード上限に統一）。
- 既定 `refreshIntervalHours` は自動 Provider platform の seed で **3**。int ≥ 1。
- 価格表示条件: `price 非空 かつ last_verified_at 非空 かつ (now - last_verified_at) <= priceTtlHours*3600`。満たさない＝価格スパンを出さず CTA ボタンのみ。
- **手動 Provider listing は `last_verified_at` を持たない → 価格は常に非表示**（規約準拠ポリシー）。
- 楽天ハッシュ抽出正規表現: `#/rk/([^/?#]+)#`（e-comi と同一）。
- 楽天 API: `formatVersion=2`（Items は各要素が **フラットな item**・`itemUrl` を直接持つ）。`accessKey`＋`Origin` ヘッダは既存 `RakutenClient` が付与（変更不要）。
- テスト実行: **PHP=Docker**（`docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit`）、**JS=ローカル volta**（`npx wp-scripts test-unit-js`）。
- push 前に CodeRabbit CLI（`coderabbit review --plain`）。**auto-merge しない**（Playground プレビュー確認 → ユーザーがマージ）。
- コミットは日本語 Conventional Commits。テストなし PR はマージしない。
- 完了後に SemVer bump（Provider 再設計＋機能追加＝**MINOR**）＋タグ＋GitHub Release（`affilicard.php` Version ヘッダ同期）。

---

# Phase 1 — 柱A: 楽天Kobo refresh 再設計 ＋ last_verified_at 記録

## Task 1: RakutenProvider を title 検索 → URLハッシュ一致同定へ再設計

**Files:**
- Modify: `src/Provider/Rakuten/RakutenProvider.php`（`fetch()` 全面書き換え・ハッシュ抽出/一致ヘルパ追加）
- Test: `tests/Unit/Provider/Rakuten/RakutenProviderTest.php`

**Interfaces:**
- Consumes: `RakutenClient::request(array $query, array $credentials): array{error,code,decoded}`（既存・変更なし）、`AccountCredentials::get('rakuten')`（既存）。
- Produces: `RakutenProvider::fetch(string $externalId, array $platformConfig): ?array`。`$platformConfig['search_key']`（任意 string）を keyword に使う。戻り値は従来 `normalizeItem()` 形状（`title,price,list_price,badge,image_url,regular_url,affiliate_url,platform_extras,raw`）または `null`。
- Produces: `private static function extractRkHash(string $itemUrl): string`（`rk/<hash>` の `<hash>`。無ければ `''`）。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Provider/Rakuten/RakutenProviderTest.php` に以下のテストを追加（既存のテストクラス・setUp のモック方式に合わせる。`RakutenClient` はコンストラクタ注入されないため、既存テストがどう `client()` を差し替えているか踏襲する。既存テストが `wp_remote_get` を WP_Mock でスタブしているなら同方式で `Items` を返す）:

```php
public function test_fetch_search_keyでヒットしURLハッシュ一致の1件を採用する(): void {
    // credentials 用意（既存テストの helper に合わせる）
    $this->stubRakutenCredentials();
    // API 応答: 2 件中 1 件だけ external_id と rk ハッシュが一致
    $this->stubRakutenResponse( 200, array(
        'Items' => array(
            array( 'title' => '別巻', 'itemPrice' => 500, 'itemUrl' => 'https://books.rakuten.co.jp/rk/aaaaaaaa/' ),
            array( 'title' => '対象巻', 'itemPrice' => 693, 'itemUrl' => 'https://books.rakuten.co.jp/rk/deadbeef01/', 'affiliateUrl' => 'https://hb.afl.rakuten.co.jp/hgc/xxx/' ),
        ),
    ) );

    $provider = new RakutenProvider();
    $result   = $provider->fetch( 'deadbeef01', array( 'search_key' => '対象巻タイトル' ) );

    $this->assertIsArray( $result );
    $this->assertSame( '693', $result['price'] );
    $this->assertSame( 'https://books.rakuten.co.jp/rk/deadbeef01/', $result['regular_url'] );
}

public function test_fetch_ハッシュ一致0件はnullで非破壊(): void {
    $this->stubRakutenCredentials();
    $this->stubRakutenResponse( 200, array(
        'Items' => array(
            array( 'title' => '別巻', 'itemPrice' => 500, 'itemUrl' => 'https://books.rakuten.co.jp/rk/aaaaaaaa/' ),
        ),
    ) );
    $provider = new RakutenProvider();
    $this->assertNull( $provider->fetch( 'deadbeef01', array( 'search_key' => 'タイトル' ) ) );
}

public function test_fetch_ハッシュ一致が複数はnull_誤上書き防止(): void {
    $this->stubRakutenCredentials();
    $this->stubRakutenResponse( 200, array(
        'Items' => array(
            array( 'title' => 'A', 'itemPrice' => 500, 'itemUrl' => 'https://books.rakuten.co.jp/rk/dup/' ),
            array( 'title' => 'B', 'itemPrice' => 600, 'itemUrl' => 'https://books.rakuten.co.jp/rk/dup/' ),
        ),
    ) );
    $provider = new RakutenProvider();
    $this->assertNull( $provider->fetch( 'dup', array( 'search_key' => 'タイトル' ) ) );
}

public function test_fetch_数字externalIdはitemNumber検索で先頭ヒット採用(): void {
    $this->stubRakutenCredentials();
    $this->stubRakutenResponse( 200, array(
        'Items' => array(
            array( 'title' => '数字ID商品', 'itemPrice' => 1200, 'itemUrl' => 'https://books.rakuten.co.jp/rk/zzzz/' ),
        ),
    ) );
    $provider = new RakutenProvider();
    $result   = $provider->fetch( '123456', array() ); // search_key 無し・数字 → itemNumber
    $this->assertIsArray( $result );
    $this->assertSame( '1200', $result['price'] );
}
```

> 注: `stubRakutenCredentials()` / `stubRakutenResponse()` は既存テストの補助を流用。無ければ既存テストが `RakutenClient` をどうモックしているかに合わせて用意する（`RakutenProvider::$client` は private のため、既存テストと同じ差し替え手段を使う）。

- [ ] **Step 2: テストが失敗することを確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter RakutenProviderTest`
Expected: 新規テストが FAIL（ハッシュ一致ロジック未実装のため別巻や null 期待が外れる）。

- [ ] **Step 3: fetch() を再設計**

`src/Provider/Rakuten/RakutenProvider.php` の `fetch()` を差し替え、ハッシュ抽出/一致を追加:

```php
public function fetch( string $externalId, array $platformConfig ): ?array {
    $credentials = AccountCredentials::get( (string) $this->accountCode() );
    if ( ! self::hasRequiredCredentials( $credentials ) ) {
        return null;
    }

    $search_key = isset( $platformConfig['search_key'] ) ? trim( (string) $platformConfig['search_key'] ) : '';
    $is_numeric = ( '' === $search_key ) && 1 === preg_match( '/^\d+$/', $externalId );

    if ( '' === $search_key && '' === $externalId ) {
        return null;
    }

    $query = array(
        'applicationId' => $credentials['application_id'],
        'affiliateId'   => $credentials['affiliate_id'],
        'format'        => 'json',
        'formatVersion' => '2',
        'hits'          => '30',
    );
    if ( '' !== $search_key ) {
        $query['keyword'] = $search_key;
    } elseif ( $is_numeric ) {
        $query['itemNumber'] = $externalId;
    } else {
        // legacy: search_key 無し＋非数字 external_id（URLハッシュ）。keyword に載せても一致し得ないが後方互換で叩く。
        $query['keyword'] = $externalId;
    }

    $res = $this->client()->request( $query, $credentials );
    if ( $res['error'] || 200 !== $res['code'] || null === $res['decoded'] || isset( $res['decoded']['errors'] ) ) {
        return null;
    }
    $items = ( isset( $res['decoded']['Items'] ) && is_array( $res['decoded']['Items'] ) ) ? $res['decoded']['Items'] : array();
    if ( array() === $items ) {
        return null;
    }

    // URLハッシュ一致で厳密同定（誤上書き防止）。
    if ( '' !== $externalId ) {
        $matches = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $url = isset( $item['itemUrl'] ) ? (string) $item['itemUrl'] : '';
            if ( self::extractRkHash( $url ) === $externalId ) {
                $matches[] = $item;
            }
        }
        if ( 1 === count( $matches ) ) {
            return self::normalizeItem( $matches[0] );
        }
        if ( count( $matches ) > 1 ) {
            return null; // 曖昧 → 非破壊
        }
    }

    // ハッシュ一致なし: 数字 external_id（itemNumber 検索）は先頭ヒットを採用。それ以外は非破壊 null。
    if ( $is_numeric ) {
        $first = self::firstItem( $res['decoded'] );
        return null === $first ? null : self::normalizeItem( $first );
    }
    return null;
}

private static function extractRkHash( string $itemUrl ): string {
    if ( 1 === preg_match( '#/rk/([^/?#]+)#', $itemUrl, $m ) ) {
        return $m[1];
    }
    return '';
}
```

（`firstItem()` / `normalizeItem()` / `hasRequiredCredentials()` / `client()` は既存のまま流用。）

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter RakutenProviderTest`
Expected: PASS（全ケース）。既存の `testConnection` 系テストも緑のまま。

- [ ] **Step 5: コミット**

```bash
git add src/Provider/Rakuten/RakutenProvider.php tests/Unit/Provider/Rakuten/RakutenProviderTest.php
git commit -m "feat: RakutenProvider を title検索→URLハッシュ一致同定へ再設計"
```

---

## Task 2: ListingRefresher が listing コンテキストを渡し last_verified_at を記録

**Files:**
- Modify: `src/Cron/ListingRefresher.php`（`refreshProduct` → `refreshListing` に product title 引き渡し・`fetch` に context・成功時 `last_verified_at`）
- Test: `tests/Unit/Cron/ListingRefresherTest.php`

**Interfaces:**
- Consumes: `ProviderInterface::fetch(string $externalId, array $platformConfig): ?array`（Task 1 の楽天は `search_key`/`external_id` を読む。DMM/manual は無視）。
- Produces: 成功 listing に `last_verified_at`（string ISO8601 = `current_time('c')`）を書き込む。失敗時は書き込まない。
- Produces: `refreshListing(array $listing, string $productTitle): array`（第2引数追加）。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Cron/ListingRefresherTest.php` に追加（既存の provider モック・repository モック方式に合わせる）:

```php
public function test_成功時にlast_verified_atを刻みsearch_keyとexternal_idをfetchへ渡す(): void {
    $provider = $this->createMock( \Affilicard\Provider\ProviderInterface::class );
    $provider->method( 'isAutomatic' )->willReturn( true );
    $provider->expects( $this->once() )
        ->method( 'fetch' )
        ->with(
            $this->equalTo( 'deadbeef01' ),
            $this->callback( function ( $ctx ) {
                return isset( $ctx['search_key'] ) && '対象巻' === $ctx['search_key']
                    && isset( $ctx['external_id'] ) && 'deadbeef01' === $ctx['external_id'];
            } )
        )
        ->willReturn( array( 'price' => '693' ) );

    // registry/repository を組み立て、product title='対象巻'、listing に search_key='対象巻'/external_id='deadbeef01' を用意
    // （既存テストの product fixture 構築 helper に合わせる）
    $refresher = $this->makeRefresherReturningProduct( array(
        'title'    => '対象巻',
        'listings' => array( array(
            'platform'    => 'rakuten-kobo',
            'external_id' => 'deadbeef01',
            'search_key'  => '対象巻',
            'update_mode' => 'auto',
        ) ),
    ), $provider, 'rakuten-kobo' );

    $saved = $refresher->captureSaved(); // 既存 helper: repository->save の引数を捕捉
    $refresher->run();

    $listing = $saved()['listings'][0];
    $this->assertSame( '693', $listing['price'] );
    $this->assertArrayHasKey( 'last_verified_at', $listing );
    $this->assertNotSame( '', (string) $listing['last_verified_at'] );
}

public function test_fetch失敗時はlast_verified_atを更新しない(): void {
    $provider = $this->createMock( \Affilicard\Provider\ProviderInterface::class );
    $provider->method( 'isAutomatic' )->willReturn( true );
    $provider->method( 'fetch' )->willReturn( null );

    $refresher = $this->makeRefresherReturningProduct( array(
        'title'    => 'X',
        'listings' => array( array(
            'platform'        => 'rakuten-kobo',
            'external_id'     => 'deadbeef01',
            'update_mode'     => 'auto',
            'last_verified_at'=> '2020-01-01T00:00:00+09:00',
            'price'           => '500',
        ) ),
    ), $provider, 'rakuten-kobo' );

    $saved = $refresher->captureSaved();
    $refresher->run();
    $listing = $saved()['listings'][0];
    $this->assertSame( '2020-01-01T00:00:00+09:00', $listing['last_verified_at'] ); // 据え置き
    $this->assertSame( '500', $listing['price'] ); // 非破壊
}
```

> 注: `makeRefresherReturningProduct` / `captureSaved` は説明用の擬似 helper 名。既存 `ListingRefresherTest` の repository/registry モック構築に合わせて実装する（既存テストが `PlatformConfig::find` をどう解決させているかも踏襲。platform provider が automatic に解決されるようにする）。

- [ ] **Step 2: テストが失敗することを確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter ListingRefresherTest`
Expected: FAIL（`last_verified_at` 未実装・context 未渡し）。

- [ ] **Step 3: refreshProduct / refreshListing を修正**

`src/Cron/ListingRefresher.php`:

`refreshProduct()` のループで product title を渡す:

```php
$listings[ $index ] = $this->refreshListing( $listing, (string) $product['title'] );
```

`refreshListing()` を差し替え:

```php
private function refreshListing( array $listing, string $productTitle ): array {
    $platformCode = isset( $listing['platform'] ) ? (string) $listing['platform'] : '';
    $externalId   = isset( $listing['external_id'] ) ? (string) $listing['external_id'] : '';
    $now          = (string) current_time( 'c' );

    $definition                 = PlatformConfig::find( $platformCode );
    $provider                   = null !== $definition ? $this->registry->get( $definition->provider ) : null;
    $listing['last_fetched_at'] = $now;

    if ( null === $provider || ! $provider->isAutomatic() || '' === $externalId ) {
        $listing['fetch_error'] = (string) __( '対応する自動 Provider がありません', 'affilicard' );
        return $listing;
    }

    $context = array(
        'search_key'  => isset( $listing['search_key'] ) && '' !== trim( (string) $listing['search_key'] )
            ? (string) $listing['search_key']
            : $productTitle,
        'regular_url' => isset( $listing['regular_url'] ) ? (string) $listing['regular_url'] : '',
        'external_id' => $externalId,
    );

    $fetched = $provider->fetch( $externalId, $context );
    if ( null === $fetched ) {
        $listing['fetch_error'] = (string) __( '価格情報の取得に失敗しました', 'affilicard' );
        return $listing;
    }

    $listing['fetch_error']     = '';
    $listing['last_verified_at'] = $now;
    $listing['price']         = isset( $fetched['price'] ) ? (string) $fetched['price'] : ( $listing['price'] ?? '' );
    $listing['list_price']    = isset( $fetched['list_price'] ) ? (string) $fetched['list_price'] : ( $listing['list_price'] ?? '' );
    $listing['badge']         = isset( $fetched['badge'] ) ? (string) $fetched['badge'] : ( $listing['badge'] ?? '' );
    $listing['image_url']     = isset( $fetched['image_url'] ) ? (string) $fetched['image_url'] : ( $listing['image_url'] ?? '' );
    $listing['regular_url']   = isset( $fetched['regular_url'] ) ? (string) $fetched['regular_url'] : ( $listing['regular_url'] ?? '' );
    $listing['affiliate_url'] = isset( $fetched['affiliate_url'] ) ? (string) $fetched['affiliate_url'] : ( $listing['affiliate_url'] ?? '' );
    return $listing;
}
```

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter ListingRefresherTest`
Expected: PASS。既存 refresher テストも緑。

- [ ] **Step 5: コミット**

```bash
git add src/Cron/ListingRefresher.php tests/Unit/Cron/ListingRefresherTest.php
git commit -m "feat: ListingRefresher が listingコンテキストを渡し成功時に last_verified_at を記録"
```

---

## Task 3: PlatformDefinition に eligibleProvider を追加

**Files:**
- Modify: `src/Platform/PlatformDefinition.php`（constructor 引数・`toArray`・`fromArray`）
- Test: `tests/Unit/Platform/PlatformDefinitionTest.php`

**Interfaces:**
- Produces: `PlatformDefinition::$eligibleProvider`（readonly string・既定 `''`）。`toArray()` に `'eligibleProvider'` キー、`fromArray()` で欠損時 `''`。

> 注: `priceTtlHours` / `refreshIntervalHours` は Task 7 で追加する（本 Task は eligibleProvider のみ・Phase 1 で UI 解禁を成立させるため）。

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_eligibleProvider_toArrayとfromArrayを往復する(): void {
    $def = PlatformDefinition::fromArray( array(
        'code'             => 'rakuten-kobo',
        'eligibleProvider' => 'rakuten-kobo',
    ) );
    $this->assertSame( 'rakuten-kobo', $def->eligibleProvider );
    $this->assertSame( 'rakuten-kobo', $def->toArray()['eligibleProvider'] );
}

public function test_eligibleProvider_欠損時は空文字(): void {
    $def = PlatformDefinition::fromArray( array( 'code' => 'x' ) );
    $this->assertSame( '', $def->eligibleProvider );
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter PlatformDefinitionTest`
Expected: FAIL（`eligibleProvider` プロパティ未定義）。

- [ ] **Step 3: eligibleProvider を追加**

`src/Platform/PlatformDefinition.php` の constructor 末尾に引数を追加（既存の末尾 `$imagePriority = 999` の後）:

```php
        public readonly int $imagePriority = 999,
        public readonly string $eligibleProvider = ''
    ) {
```

`toArray()` に追加:

```php
            'imagePriority'    => $this->imagePriority,
            'eligibleProvider' => $this->eligibleProvider,
        );
```

`fromArray()` の `return new self( ... )` 末尾に追加（`$image_priority` の後）:

```php
            $image_priority,
            isset( $data['eligibleProvider'] ) ? (string) $data['eligibleProvider'] : ''
        );
```

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter PlatformDefinitionTest`
Expected: PASS。

- [ ] **Step 5: コミット**

```bash
git add src/Platform/PlatformDefinition.php tests/Unit/Platform/PlatformDefinitionTest.php
git commit -m "feat: PlatformDefinition に eligibleProvider を追加"
```

---

## Task 4: seed の楽天Kobo を自動 Provider ＋ eligibleProvider に変更

**Files:**
- Modify: `src/Platform/PlatformConfig.php`（`defaults()` の rakuten-kobo エントリ）
- Test: `tests/Unit/Platform/PlatformConfigTest.php`（defaults 検証テストがあれば追記。無ければ新規メソッド）

**Interfaces:**
- Consumes: `PlatformDefinition`（Task 3 で eligibleProvider 追加済み）。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Platform/PlatformConfigTest.php`（既存 defaults テストに追記、無ければ追加）:

```php
public function test_defaults_楽天Koboは自動ProviderかつeligibleProvider付き(): void {
    $byCode = array();
    foreach ( PlatformConfig::defaults() as $d ) {
        $byCode[ $d->code ] = $d;
    }
    $kobo = $byCode['rakuten-kobo'];
    $this->assertSame( 'rakuten-kobo', $kobo->provider );
    $this->assertTrue( $kobo->autoRefresh );
    $this->assertSame( 'rakuten-kobo', $kobo->eligibleProvider );
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter PlatformConfigTest`
Expected: FAIL（provider が 'manual'、autoRefresh false、eligibleProvider 空）。

- [ ] **Step 3: seed を変更**

`src/Platform/PlatformConfig.php` の `defaults()` 内 rakuten-kobo エントリを変更（`provider` を `rakuten-kobo`、`autoRefresh=true`、`eligibleProvider='rakuten-kobo'`）:

```php
            new PlatformDefinition(
                'rakuten-kobo',
                __( '楽天Kobo', 'affilicard' ),
                'rakuten-kobo',
                3,
                true,
                array( 'ebook' ),
                __( '楽天Koboで読む', 'affilicard' ),
                '#bf0000',
                '#ffffff',
                true,
                'weekly',
                imagePriority: 30,
                eligibleProvider: 'rakuten-kobo'
            ),
```

> 注: この時点では `refreshFrequency='weekly'` のまま（Task 7 で `refreshIntervalHours` に置換し、Task 8 で全 auto seed を 3 に更新する）。Amazon Kindle エントリにも `eligibleProvider` は付けない（AmazonProvider 未実装のため空のまま）。

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter PlatformConfigTest`
Expected: PASS。

- [ ] **Step 5: コミット**

```bash
git add src/Platform/PlatformConfig.php tests/Unit/Platform/PlatformConfigTest.php
git commit -m "feat: 既定の楽天Kobo を自動Provider(rakuten-kobo)＋eligibleProvider に変更"
```

---

## Task 5: providers.js の providerOptionsFor に eligibleProvider を反映

**Files:**
- Modify: `src/Admin/providers.js`（`providerOptionsFor` シグネチャ拡張）
- Test: `tests/js/providers.test.js`（無ければ新規・既存 JS テストの配置に合わせる）

**Interfaces:**
- Produces: `providerOptionsFor(currentProvider, eligibleProvider = '')` — `manual`（非自動）＋ `code === currentProvider` ＋ `code === eligibleProvider` の自動 Provider を候補に含める。
- 呼び出し側更新は Task 12（PlatformEditor.jsx）。

- [ ] **Step 1: 失敗するテストを書く**

`tests/js/providers.test.js`:

```js
beforeEach(() => {
	global.window = {
		affilicardProviders: [
			{ code: 'manual', label: '手動', isAutomatic: false },
			{ code: 'rakuten-kobo', label: '楽天Kobo API', isAutomatic: true, accountCode: 'rakuten' },
			{ code: 'dmm-ebook', label: 'DMM API', isAutomatic: true, accountCode: 'dmm' },
		],
	};
});

test('eligibleProvider を候補に含める（現在 manual でも切替可能に）', () => {
	const { providerOptionsFor } = require('../../src/Admin/providers.js');
	const opts = providerOptionsFor('manual', 'rakuten-kobo').map((o) => o.value);
	expect(opts).toContain('manual');
	expect(opts).toContain('rakuten-kobo');
	expect(opts).not.toContain('dmm-ebook');
});

test('eligibleProvider 無指定なら従来通り manual＋現在値のみ', () => {
	const { providerOptionsFor } = require('../../src/Admin/providers.js');
	const opts = providerOptionsFor('manual').map((o) => o.value);
	expect(opts).toEqual(['manual']);
});
```

> 注: `require` パス・ESM/CJS の扱いは既存 JS テスト（`tests/js/`）の設定に合わせる。ESM の場合は `import` に変更。

- [ ] **Step 2: テストが失敗することを確認**

Run: `npx wp-scripts test-unit-js providers`
Expected: FAIL（eligibleProvider 引数未対応）。

- [ ] **Step 3: providerOptionsFor を拡張**

`src/Admin/providers.js`:

```js
export const providerOptionsFor = (currentProvider, eligibleProvider = '') =>
	injected
		.filter(
			(p) =>
				!p.isAutomatic ||
				p.code === currentProvider ||
				(eligibleProvider && p.code === eligibleProvider)
		)
		.map((p) => ({ label: p.label, value: p.code }));
```

- [ ] **Step 4: テストが通ることを確認**

Run: `npx wp-scripts test-unit-js providers`
Expected: PASS。

- [ ] **Step 5: コミット**

```bash
git add src/Admin/providers.js tests/js/providers.test.js
git commit -m "feat: providerOptionsFor に eligibleProvider を反映（現在manualでも切替候補に）"
```

---

# Phase 2 — 柱B: 価格鮮度表示 ＋ 柱C: 更新頻度制御

## Task 6: 価格表示ポリシーの共有ヘルパ PriceFreshness

**Files:**
- Create: `src/Pricing/PriceFreshness.php`
- Test: `tests/Unit/Pricing/PriceFreshnessTest.php`

**Interfaces:**
- Produces: `PriceFreshness::isPriceDisplayable(array $listing, ?PlatformDefinition $platform, int $nowTs): bool`
  - true 条件: `price` 非空 かつ `last_verified_at` 非空 かつ `$platform` 非 null かつ `(nowTs - strtotime(last_verified_at)) <= platform->priceTtlHours * 3600`。
  - `last_verified_at` が空/解釈不能、`price` 空、platform 不明 → false。
- 依存: `PlatformDefinition::$priceTtlHours`（Task 7 で追加）。**本 Task を Task 7 の後に実施しても良いが、テストは `PlatformDefinition::fromArray(['code'=>'x','priceTtlHours'=>24])` を使うため Task 7 を先行させる**。→ 実行順は Task 7 → Task 6。

> 実装順の都合で **Task 7 を先に完了**させること（PriceFreshness が priceTtlHours に依存）。

- [ ] **Step 1: 失敗するテストを書く**

```php
use Affilicard\Platform\PlatformDefinition;
use Affilicard\Pricing\PriceFreshness;

private function platform( int $ttl ): PlatformDefinition {
    return PlatformDefinition::fromArray( array( 'code' => 'rakuten-kobo', 'priceTtlHours' => $ttl ) );
}

public function test_確認済みかつ鮮度内は表示可(): void {
    $now = 1_800_000_000;
    $listing = array( 'price' => '693', 'last_verified_at' => gmdate( 'c', $now - 3600 ) ); // 1時間前
    $this->assertTrue( PriceFreshness::isPriceDisplayable( $listing, $this->platform( 24 ), $now ) );
}

public function test_TTL超過は非表示(): void {
    $now = 1_800_000_000;
    $listing = array( 'price' => '693', 'last_verified_at' => gmdate( 'c', $now - 25 * 3600 ) ); // 25時間前
    $this->assertFalse( PriceFreshness::isPriceDisplayable( $listing, $this->platform( 24 ), $now ) );
}

public function test_last_verified_at無し_手動価格は非表示(): void {
    $now = 1_800_000_000;
    $listing = array( 'price' => '693' ); // 手動入力想定・verified 無し
    $this->assertFalse( PriceFreshness::isPriceDisplayable( $listing, $this->platform( 24 ), $now ) );
}

public function test_price空は非表示(): void {
    $now = 1_800_000_000;
    $listing = array( 'price' => '', 'last_verified_at' => gmdate( 'c', $now ) );
    $this->assertFalse( PriceFreshness::isPriceDisplayable( $listing, $this->platform( 24 ), $now ) );
}

public function test_platformがnullは非表示(): void {
    $now = 1_800_000_000;
    $listing = array( 'price' => '693', 'last_verified_at' => gmdate( 'c', $now ) );
    $this->assertFalse( PriceFreshness::isPriceDisplayable( $listing, null, $now ) );
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter PriceFreshnessTest`
Expected: FAIL（クラス未定義）。

- [ ] **Step 3: PriceFreshness を実装**

`src/Pricing/PriceFreshness.php`:

```php
<?php
declare(strict_types=1);

namespace Affilicard\Pricing;

use Affilicard\Platform\PlatformDefinition;

/**
 * 価格をカードに表示してよいか（API 確認済み・鮮度内か）を判定する共有ポリシー。
 *
 * CardRenderer（表示ゲート）と ProductListColumns（警告アイコン）で共用する。
 * 手動 Provider listing は last_verified_at を持たないため常に非表示（規約準拠）。
 */
final class PriceFreshness {

    /**
     * @param array<string, mixed> $listing
     */
    public static function isPriceDisplayable( array $listing, ?PlatformDefinition $platform, int $nowTs ): bool {
        if ( null === $platform ) {
            return false;
        }
        $price = isset( $listing['price'] ) ? trim( (string) $listing['price'] ) : '';
        if ( '' === $price ) {
            return false;
        }
        $verified = isset( $listing['last_verified_at'] ) ? trim( (string) $listing['last_verified_at'] ) : '';
        if ( '' === $verified ) {
            return false;
        }
        $verifiedTs = strtotime( $verified );
        if ( false === $verifiedTs ) {
            return false;
        }
        $ttl = $platform->priceTtlHours * 3600;
        return ( $nowTs - $verifiedTs ) <= $ttl;
    }
}
```

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter PriceFreshnessTest`
Expected: PASS。

- [ ] **Step 5: コミット**

```bash
git add src/Pricing/PriceFreshness.php tests/Unit/Pricing/PriceFreshnessTest.php
git commit -m "feat: 価格表示ポリシーの共有ヘルパ PriceFreshness を追加"
```

---

## Task 7: PlatformDefinition に priceTtlHours ＋ refreshIntervalHours（refreshFrequency 置換）

> **Task 6 より先に実施すること**（PriceFreshness が priceTtlHours に依存）。

**Files:**
- Modify: `src/Platform/PlatformDefinition.php`
- Test: `tests/Unit/Platform/PlatformDefinitionTest.php`

**Interfaces:**
- Produces: `PlatformDefinition::$priceTtlHours`（readonly int・既定 24）、`PlatformDefinition::$refreshIntervalHours`（readonly int・既定 24）。
- `refreshFrequency`（string）プロパティは削除。`fromArray()` は旧 `refreshFrequency`（`daily`→24 / `weekly`→168）を `refreshIntervalHours` に移行。`toArray()` は `refreshIntervalHours`/`priceTtlHours` を出力し `refreshFrequency` を出さない。
- constructor: `refreshFrequency`（旧 11 番目 string）を `refreshIntervalHours`（int）に置換。末尾に `priceTtlHours` を追加。

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_priceTtlHours_既定は24(): void {
    $def = PlatformDefinition::fromArray( array( 'code' => 'x' ) );
    $this->assertSame( 24, $def->priceTtlHours );
    $this->assertSame( 24, $def->toArray()['priceTtlHours'] );
}

public function test_refreshIntervalHours_明示値を保持(): void {
    $def = PlatformDefinition::fromArray( array( 'code' => 'x', 'refreshIntervalHours' => 3 ) );
    $this->assertSame( 3, $def->refreshIntervalHours );
    $this->assertSame( 3, $def->toArray()['refreshIntervalHours'] );
}

public function test_旧refreshFrequencyを時間へ移行(): void {
    $daily  = PlatformDefinition::fromArray( array( 'code' => 'x', 'refreshFrequency' => 'daily' ) );
    $weekly = PlatformDefinition::fromArray( array( 'code' => 'y', 'refreshFrequency' => 'weekly' ) );
    $this->assertSame( 24, $daily->refreshIntervalHours );
    $this->assertSame( 168, $weekly->refreshIntervalHours );
}

public function test_refreshIntervalHours_1未満は既定24に矯正(): void {
    $def = PlatformDefinition::fromArray( array( 'code' => 'x', 'refreshIntervalHours' => 0 ) );
    $this->assertSame( 24, $def->refreshIntervalHours );
}

public function test_toArrayはrefreshFrequencyを出力しない(): void {
    $def = PlatformDefinition::fromArray( array( 'code' => 'x' ) );
    $this->assertArrayNotHasKey( 'refreshFrequency', $def->toArray() );
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter PlatformDefinitionTest`
Expected: FAIL。

- [ ] **Step 3: PlatformDefinition を修正**

`src/Platform/PlatformDefinition.php`:

`ALLOWED_FREQUENCIES` 定数を削除。constructor の `$refreshFrequency` を `$refreshIntervalHours` に置換し、`$eligibleProvider` の後（末尾）に `$priceTtlHours` を追加:

```php
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $provider,
        public readonly int $displayOrder,
        public readonly bool $enabled,
        public readonly array $applicableTypes,
        public readonly string $buttonLabel,
        public readonly string $brandColor,
        public readonly string $buttonTextColor,
        public readonly bool $autoRefresh = false,
        public readonly int $refreshIntervalHours = 24,
        public readonly int $imagePriority = 999,
        public readonly string $eligibleProvider = '',
        public readonly int $priceTtlHours = 24
    ) {
        if ( '' === $this->code ) {
            throw new InvalidArgumentException( 'PlatformDefinition: code must not be empty.' );
        }
    }
```

`toArray()`:

```php
    public function toArray(): array {
        return array(
            'code'                 => $this->code,
            'name'                 => $this->name,
            'provider'             => $this->provider,
            'displayOrder'         => $this->displayOrder,
            'enabled'              => $this->enabled,
            'applicableTypes'      => $this->applicableTypes,
            'buttonLabel'          => $this->buttonLabel,
            'brandColor'           => $this->brandColor,
            'buttonTextColor'      => $this->buttonTextColor,
            'autoRefresh'          => $this->autoRefresh,
            'refreshIntervalHours' => $this->refreshIntervalHours,
            'imagePriority'        => $this->imagePriority,
            'eligibleProvider'     => $this->eligibleProvider,
            'priceTtlHours'        => $this->priceTtlHours,
        );
    }
```

`fromArray()`: `$frequency` 判定ブロックを次の interval 解決に置換:

```php
        // 更新間隔（時間）。旧 refreshFrequency（daily/weekly）からの移行を含む。
        $interval = 24;
        if ( isset( $data['refreshIntervalHours'] ) ) {
            $interval = (int) $data['refreshIntervalHours'];
        } elseif ( isset( $data['refreshFrequency'] ) ) {
            $interval = ( 'weekly' === (string) $data['refreshFrequency'] ) ? 168 : 24;
        }
        if ( $interval < 1 ) {
            $interval = 24;
        }

        $price_ttl = isset( $data['priceTtlHours'] ) ? (int) $data['priceTtlHours'] : 24;
        if ( $price_ttl < 1 ) {
            $price_ttl = 24;
        }
```

`fromArray()` の `return new self( ... )` を新シグネチャに合わせる（`$frequency` を `$interval` に、末尾に eligibleProvider・priceTtlHours）:

```php
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
            $interval,
            $image_priority,
            isset( $data['eligibleProvider'] ) ? (string) $data['eligibleProvider'] : '',
            $price_ttl
        );
```

> Task 3 で追加した eligibleProvider の位置（末尾）が本 Task で priceTtlHours 追加により1つ前倒しになる。上記シグネチャ順（…, eligibleProvider, priceTtlHours）に統一すること。

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter PlatformDefinitionTest`
Expected: PASS。**この時点で seed（Task 4 で `'weekly'` を位置引数に渡している）がコンパイルエラーになる** → Task 8 で修正するため、Task 8 とセットで CI を通す。ローカルは PlatformDefinitionTest 単体で緑を確認。

- [ ] **Step 5: コミット**

```bash
git add src/Platform/PlatformDefinition.php tests/Unit/Platform/PlatformDefinitionTest.php
git commit -m "feat: PlatformDefinition に priceTtlHours・refreshIntervalHours を追加（refreshFrequency置換）"
```

---

## Task 8: seed を新シグネチャ＋既定間隔3h・TTL24h に更新

**Files:**
- Modify: `src/Platform/PlatformConfig.php`（`defaults()` 全 8 エントリを新シグネチャへ）
- Test: `tests/Unit/Platform/PlatformConfigTest.php`

**Interfaces:**
- Consumes: 新 `PlatformDefinition` シグネチャ（Task 7）。

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_defaults_自動Providerは間隔3h_全PFのTTLは24h(): void {
    foreach ( PlatformConfig::defaults() as $d ) {
        $this->assertSame( 24, $d->priceTtlHours, "priceTtlHours must be 24 for {$d->code}" );
        if ( $d->autoRefresh ) {
            $this->assertSame( 3, $d->refreshIntervalHours, "auto platform {$d->code} interval must be 3h" );
        }
    }
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter PlatformConfigTest`
Expected: FAIL（間隔/TTL 未設定・かつ Task 7 直後は seed が旧シグネチャでエラー）。

- [ ] **Step 3: defaults() を新シグネチャへ**

`src/Platform/PlatformConfig.php` の `defaults()` を全面更新。位置引数 `autoRefresh, refreshIntervalHours, imagePriority` ＋名前付き `eligibleProvider, priceTtlHours` を各エントリに設定（DMM/楽天は auto=true・interval=3・eligibleProvider 自身。全 PF priceTtlHours=24）:

```php
    public static function defaults(): array {
        return array(
            new PlatformDefinition(
                'dmm-books', __( 'DMMブックス', 'affilicard' ), 'dmm-ebook', 1, true,
                array( 'ebook' ), __( 'DMMブックスで読む', 'affilicard' ), '#d72d65', '#ffffff',
                true, 3, imagePriority: 10, eligibleProvider: 'dmm-ebook', priceTtlHours: 24
            ),
            new PlatformDefinition(
                'amazon-kindle', __( 'Amazon Kindle', 'affilicard' ), 'manual', 2, true,
                array( 'ebook' ), __( 'Kindleで読む', 'affilicard' ), '#ff9900', '#000000',
                false, 24, imagePriority: 20, priceTtlHours: 24
            ),
            new PlatformDefinition(
                'rakuten-kobo', __( '楽天Kobo', 'affilicard' ), 'rakuten-kobo', 3, true,
                array( 'ebook' ), __( '楽天Koboで読む', 'affilicard' ), '#bf0000', '#ffffff',
                true, 3, imagePriority: 30, eligibleProvider: 'rakuten-kobo', priceTtlHours: 24
            ),
            new PlatformDefinition(
                'u-next', __( 'U-NEXT', 'affilicard' ), 'manual', 5, true,
                array( 'vod' ), __( 'U-NEXTで見る', 'affilicard' ), '#000000', '#ffffff',
                false, 24, priceTtlHours: 24
            ),
            new PlatformDefinition(
                'netflix', __( 'Netflix', 'affilicard' ), 'manual', 6, true,
                array( 'vod' ), __( 'Netflixで見る', 'affilicard' ), '#e50914', '#ffffff',
                false, 24, priceTtlHours: 24
            ),
            new PlatformDefinition(
                'hulu', __( 'Hulu', 'affilicard' ), 'manual', 7, true,
                array( 'vod' ), __( 'Huluで見る', 'affilicard' ), '#1ce783', '#000000',
                false, 24, priceTtlHours: 24
            ),
            new PlatformDefinition(
                'prime-video', __( 'Prime Video', 'affilicard' ), 'manual', 8, true,
                array( 'vod' ), __( 'Prime Videoで見る', 'affilicard' ), '#00a8e1', '#ffffff',
                false, 24, priceTtlHours: 24
            ),
            new PlatformDefinition(
                'danime', __( 'dアニメストア', 'affilicard' ), 'manual', 9, true,
                array( 'vod' ), __( 'dアニメストアで見る', 'affilicard' ), '#ff6600', '#ffffff',
                false, 24, priceTtlHours: 24
            ),
        );
    }
```

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter PlatformConfigTest`
Expected: PASS。あわせて全体 `vendor/bin/phpunit` でコンパイルエラー解消を確認。

- [ ] **Step 5: コミット**

```bash
git add src/Platform/PlatformConfig.php tests/Unit/Platform/PlatformConfigTest.php
git commit -m "feat: 既定プラットフォームを間隔3h・TTL24h・新シグネチャに更新"
```

---

## Task 9: RefreshScheduler を refreshIntervalHours ベースの動的スケジュールへ

**Files:**
- Modify: `src/Cron/RefreshScheduler.php`
- Modify: 配線箇所（`RefreshScheduler::register()` を呼ぶ Plugin の初期化）— `cron_schedules` フィルタ登録追加
- Test: `tests/Unit/Cron/RefreshSchedulerTest.php`

**Interfaces:**
- Produces: `RefreshScheduler::scheduleName(int $hours): string`（`affilicard_ivl_{hours}h`）。
- Produces: `RefreshScheduler::addSchedules(array $schedules): array`（`cron_schedules` フィルタ）— 使用中の各間隔を登録。
- `reconcile()` は `$definition->refreshIntervalHours` から schedule 名を決める。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Cron/RefreshSchedulerTest.php`（既存があれば追記）:

```php
public function test_scheduleName_時間からカスタムスケジュール名を作る(): void {
    $this->assertSame( 'affilicard_ivl_3h', RefreshScheduler::scheduleName( 3 ) );
}

public function test_addSchedules_使用中の間隔を登録する(): void {
    // PlatformConfig::all() が auto=true / interval=3 の platform を返すようスタブ
    // （既存テストの WP_Mock による get_option スタブ方式に合わせて 1 件用意）
    $this->stubPlatforms( array(
        array( 'code' => 'rakuten-kobo', 'provider' => 'rakuten-kobo', 'autoRefresh' => true, 'refreshIntervalHours' => 3 ),
    ) );
    $out = RefreshScheduler::addSchedules( array() );
    $this->assertArrayHasKey( 'affilicard_ivl_3h', $out );
    $this->assertSame( 3 * 3600, $out['affilicard_ivl_3h']['interval'] );
}
```

> 注: `stubPlatforms()` は `PlatformConfig::all()` が読む `get_option('affilicard_platforms')` を WP_Mock で返す既存方式に合わせる。`HOUR_IN_SECONDS` は WP 定数（テスト bootstrap で定義されていなければ `3600` を直接使う実装にする）。

- [ ] **Step 2: テストが失敗することを確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter RefreshSchedulerTest`
Expected: FAIL（`scheduleName`/`addSchedules` 未定義）。

- [ ] **Step 3: RefreshScheduler を修正**

`src/Cron/RefreshScheduler.php`:

```php
    public static function scheduleName( int $hours ): string {
        return 'affilicard_ivl_' . max( 1, $hours ) . 'h';
    }

    /**
     * @param array<string, array{interval:int, display:string}> $schedules
     * @return array<string, array{interval:int, display:string}>
     */
    public static function addSchedules( array $schedules ): array {
        foreach ( PlatformConfig::all() as $definition ) {
            if ( ! $definition->autoRefresh ) {
                continue;
            }
            $hours = max( 1, $definition->refreshIntervalHours );
            $name  = self::scheduleName( $hours );
            if ( ! isset( $schedules[ $name ] ) ) {
                $schedules[ $name ] = array(
                    'interval' => $hours * 3600,
                    /* translators: %d: hours */
                    'display'  => sprintf( __( '%d時間毎（affilicard）', 'affilicard' ), $hours ),
                );
            }
        }
        return $schedules;
    }
```

`reconcile()` の `$desired` 決定を interval ベースに:

```php
            $args    = array( $definition->code );
            $desired = ( $master && $definition->autoRefresh )
                ? self::scheduleName( $definition->refreshIntervalHours )
                : null;
```

配線: `RefreshScheduler::register()`（または Plugin の init）で `add_filter( 'cron_schedules', array( self::class, 'addSchedules' ) );` を追加。`register()` に次を追記:

```php
    public static function register( callable $handler ): void {
        add_filter( 'cron_schedules', array( self::class, 'addSchedules' ) );
        add_action( self::HOOK, $handler, 10, 1 );
    }
```

- [ ] **Step 4: テストが通ることを確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter RefreshSchedulerTest`
Expected: PASS。

- [ ] **Step 5: コミット**

```bash
git add src/Cron/RefreshScheduler.php tests/Unit/Cron/RefreshSchedulerTest.php
git commit -m "feat: RefreshScheduler を refreshIntervalHours ベースの動的スケジュールへ"
```

---

## Task 10: CardRenderer に価格表示ゲート ＋ 免責を last_verified_at ベースへ

**Files:**
- Modify: `src/Renderer/CardRenderer.php`（`renderListings` 価格ブロックのゲート・`renderTimestamp` を verified 基準へ）
- Test: `tests/Unit/Renderer/CardRendererTest.php`

**Interfaces:**
- Consumes: `PriceFreshness::isPriceDisplayable(array, ?PlatformDefinition, int)`（Task 6）。
- 価格スパン（list-price / price / tax / discount）は displayable の時のみ出力。CTA ボタンは従来通り常に出力。
- `renderTimestamp` は `last_verified_at` の最新を基準にし、**表示中の価格 listing が 1 件以上ある時のみ**文言を出す。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Renderer/CardRendererTest.php`（既存の描画テスト方式・fixture 構築に合わせる。platform は rakuten-kobo・priceTtlHours=24 を解決させる）:

```php
public function test_確認済み鮮度内の価格は表示される(): void {
    $html = $this->renderCardWithListing( array(
        'platform'         => 'rakuten-kobo',
        'price'            => '693',
        'affiliate_url'    => 'https://hb.afl.rakuten.co.jp/hgc/x/',
        'last_verified_at' => gmdate( 'c', time() - 3600 ),
    ) );
    $this->assertStringContainsString( 'affilicard-card__price', $html );
    $this->assertStringContainsString( '693', $html );
}

public function test_未確認価格はCTAのみで価格非表示(): void {
    $html = $this->renderCardWithListing( array(
        'platform'      => 'rakuten-kobo',
        'price'         => '693',
        'affiliate_url' => 'https://hb.afl.rakuten.co.jp/hgc/x/',
        // last_verified_at 無し
    ) );
    $this->assertStringNotContainsString( 'affilicard-card__price', $html );
    $this->assertStringContainsString( 'affilicard-card__cta', $html ); // ボタンは残る
}

public function test_TTL超過価格は非表示(): void {
    $html = $this->renderCardWithListing( array(
        'platform'         => 'rakuten-kobo',
        'price'            => '693',
        'affiliate_url'    => 'https://hb.afl.rakuten.co.jp/hgc/x/',
        'last_verified_at' => gmdate( 'c', time() - 25 * 3600 ),
    ) );
    $this->assertStringNotContainsString( 'affilicard-card__price', $html );
}
```

> 注: `renderCardWithListing()` は既存 CardRendererTest の product/platform fixture 構築に合わせた薄い helper。platform 定義（priceTtlHours=24）が `$by_code` に載るようにする。

- [ ] **Step 2: テストが失敗することを確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter CardRendererTest`
Expected: FAIL（現状は verified 無しでも価格が出る）。

- [ ] **Step 3: 価格ブロックをゲート**

`src/Renderer/CardRenderer.php` 冒頭に `use Affilicard\Pricing\PriceFreshness;` を追加。`renderListings()` の価格エリア（`$pricing` 組み立て・現行 417-439 行）を、displayable 判定でラップ:

```php
            // 価格エリア（API 確認済み・鮮度内のときだけ表示。手動/未確認/期限切れは CTA のみ）。
            $pricing = '';
            if ( PriceFreshness::isPriceDisplayable( $listing, $platform, $now_ts ) ) {
                $price    = isset( $listing['price'] ) ? trim( (string) $listing['price'] ) : '';
                $list_raw = isset( $listing['list_price'] ) ? trim( (string) $listing['list_price'] ) : '';

                $list_num  = self::priceToNumber( $list_raw );
                $price_num = self::priceToNumber( $price );
                if ( null !== $list_num && null !== $price_num && $list_num > $price_num ) {
                    $list_no_yen = (string) preg_replace( '/^[\x{00A5}\x{FFE5}\s]+/u', '', $list_raw );
                    $pricing    .= '<span class="affilicard-card__list-price">¥' . esc_html( $list_no_yen ) . '</span>';
                }
                if ( '' !== $price ) {
                    $price_no_yen = (string) preg_replace( '/^[\x{00A5}\x{FFE5}\s]+/u', '', $price );
                    $pricing     .= '<span class="affilicard-card__price">¥' . esc_html( $price_no_yen ) . '</span>';
                    $pricing     .= '<span class="affilicard-card__tax">' . esc_html__( '（税込）', 'affilicard' ) . '</span>';
                }
                $badge = isset( $listing['badge'] ) ? trim( (string) $listing['badge'] ) : '';
                if ( '' !== $badge ) {
                    $pricing .= '<span class="affilicard-card__discount">' . esc_html( $badge ) . '</span>';
                }
            }
```

`renderListings()` の冒頭（`$rows = '';` 付近）で現在時刻を1回だけ求める:

```php
        $now_ts = time();
```

- [ ] **Step 4: renderTimestamp を verified 基準へ**

`renderTimestamp()`（現行 `last_fetched_at` 参照・272 行〜）を `last_verified_at` かつ「表示中の価格がある listing のみ」に変更:

```php
    private function renderTimestamp( array $listings ): string {
        $now_ts = time();
        $latest = 0;
        foreach ( $listings as $listing ) {
            if ( ! is_array( $listing ) ) {
                continue;
            }
            $code     = isset( $listing['platform'] ) ? (string) $listing['platform'] : '';
            $platform = '' !== $code ? PlatformConfig::find( $code ) : null;
            if ( ! PriceFreshness::isPriceDisplayable( $listing, $platform, $now_ts ) ) {
                continue;
            }
            $at = isset( $listing['last_verified_at'] ) ? trim( (string) $listing['last_verified_at'] ) : '';
            $ts = '' !== $at ? strtotime( $at ) : false;
            if ( false !== $ts && $ts > $latest ) {
                $latest = $ts;
            }
        }
        if ( 0 === $latest ) {
            return '';
        }
        $date = wp_date( 'Y年n月j日', $latest );
        return '<p class="affilicard-card__timestamp">'
            . esc_html( sprintf(
                /* translators: %s: date */
                __( '※ %s時点の価格です。最新の価格は各ストアでご確認ください。', 'affilicard' ),
                $date
            ) )
            . '</p>';
    }
```

> 既存 `renderTimestamp` が `visibleListings` の戻りを受け取る呼び出し（169 行）はそのまま。`PlatformConfig` の use が無ければ追加。既存の文言・class 名に合わせて微調整可（既存テストがある場合はそれに沿う）。

- [ ] **Step 5: テストが通ることを確認 → コミット**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter CardRendererTest`
Expected: PASS。

```bash
git add src/Renderer/CardRenderer.php tests/Unit/Renderer/CardRendererTest.php
git commit -m "feat: カード価格を API確認済み・鮮度内のみ表示し免責を last_verified_at 基準へ"
```

---

## Task 11: 管理カラムに「価格が非表示（未確認/期限切れ）」警告を追加

**Files:**
- Modify: `src/PostType/ProductListColumns.php`
- Test: `tests/Unit/PostType/ProductListColumnsTest.php`（無ければ新規）

**Interfaces:**
- Consumes: `PriceFreshness::isPriceDisplayable(...)`（Task 6）、`PlatformConfig::find(...)`。
- 「price を保持しているが displayable でない」listing があれば警告を出す（既存の Fallback 警告と統合し、理由別 title）。

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_価格保持だが未確認のlistingで警告を出す(): void {
    // get_post_meta で listings を返すよう WP_Mock スタブ（既存テスト方式）
    // rakuten-kobo・price=693・last_verified_at 無し
    $this->stubListings( array( array(
        'platform'      => 'rakuten-kobo',
        'price'         => '693',
        'affiliate_url' => 'https://hb.afl.rakuten.co.jp/hgc/x/',
        'regular_url'   => 'https://books.rakuten.co.jp/rk/x/',
    ) ) );
    ob_start();
    ProductListColumns::renderColumn( ProductListColumns::COLUMN_KEY, 123 );
    $html = (string) ob_get_clean();
    $this->assertStringContainsString( 'dashicons-warning', $html );
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter ProductListColumnsTest`
Expected: FAIL（未確認価格では現状 fallback 警告条件に合致せず警告が出ない場合）。

- [ ] **Step 3: renderColumn に価格非表示検知を追加**

`src/PostType/ProductListColumns.php` の `renderColumn()` に、既存 fallback 判定に加えて「価格保持だが非表示」を検知（`use Affilicard\Pricing\PriceFreshness;`・`use Affilicard\Platform\PlatformConfig;` 追加）:

```php
        $now_ts       = time();
        $has_fallback = false;
        $has_hidden_price = false;
        foreach ( $listings as $listing ) {
            if ( ! is_array( $listing ) ) {
                continue;
            }
            $affiliate = isset( $listing['affiliate_url'] ) ? (string) $listing['affiliate_url'] : '';
            $regular   = isset( $listing['regular_url'] ) ? (string) $listing['regular_url'] : '';
            if ( '' === $affiliate && '' !== $regular ) {
                $has_fallback = true;
            }
            $price = isset( $listing['price'] ) ? trim( (string) $listing['price'] ) : '';
            if ( '' !== $price ) {
                $platform = PlatformConfig::find( (string) ( $listing['platform'] ?? '' ) );
                if ( ! PriceFreshness::isPriceDisplayable( $listing, $platform, $now_ts ) ) {
                    $has_hidden_price = true;
                }
            }
        }

        if ( $has_hidden_price ) {
            echo '<span class="dashicons dashicons-warning" style="color:#d63638" title="' . esc_attr__( '価格が未確認/期限切れのためカードで非表示です', 'affilicard' ) . '"></span> ';
        }
        if ( $has_fallback ) {
            echo '<span class="dashicons dashicons-warning" style="color:#dba617" title="' . esc_attr__( 'アフィリエイト URL 未設定、通常 URL にフォールバック中', 'affilicard' ) . '"></span>';
        }
        if ( ! $has_hidden_price && ! $has_fallback ) {
            echo '<span aria-hidden="true">—</span>';
        }
```

（既存の `$has_fallback` ブロックは上記に統合し、旧 if/else を置き換える。）

- [ ] **Step 4: テストが通ることを確認 → コミット**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter ProductListColumnsTest`
Expected: PASS。

```bash
git add src/PostType/ProductListColumns.php tests/Unit/PostType/ProductListColumnsTest.php
git commit -m "feat: 商品一覧に価格非表示(未確認/期限切れ)の警告アイコンを追加"
```

---

## Task 12: PlatformEditor の更新頻度 UI を N時間毎へ・eligibleProvider を候補に渡す

**Files:**
- Modify: `src/Admin/components/PlatformEditor.jsx`
- Test: `tests/js/PlatformEditor.test.jsx`（無ければ最小の describe を新規。既存 JSX テスト方式に合わせる）

**Interfaces:**
- Consumes: `providerOptionsFor(currentProvider, eligibleProvider)`（Task 5）。
- 更新頻度 UI: `refreshFrequency` の `SelectControl`（daily/weekly）を `refreshIntervalHours` の数値入力＋プリセットへ置換。

- [ ] **Step 1: 失敗するテストを書く**

`tests/js/PlatformEditor.test.jsx`（レンダリングして「更新頻度」入力が数値 3 を扱う・provider 候補に eligibleProvider が出ることを検証。既存の @wordpress/components をどうモックしているかに合わせる。最小限、`providerOptionsFor` の呼び出し引数検証でも可）:

```jsx
import { render } from '@testing-library/react';
import { PlatformEditor } from '../../src/Admin/components/PlatformEditor';

test('provider 候補呼び出しに eligibleProvider を渡す', () => {
	global.window.affilicardProviders = [
		{ code: 'manual', label: '手動', isAutomatic: false },
		{ code: 'rakuten-kobo', label: '楽天Kobo API', isAutomatic: true },
	];
	const platform = {
		code: 'rakuten-kobo',
		name: '楽天Kobo',
		provider: 'manual',
		eligibleProvider: 'rakuten-kobo',
		autoRefresh: true,
		refreshIntervalHours: 3,
		enabled: true,
	};
	const { container } = render(
		<PlatformEditor platform={platform} onChange={() => {}} />
	);
	// 更新頻度の数値入力に 3 が入る
	expect(container.textContent).toContain('更新間隔');
});
```

> 注: 既存 JSX テストが無い/軽量な場合は、UI 詳細検証より「`providerOptionsFor` に eligibleProvider が渡ること」「refreshIntervalHours が onChange で数値化されること」を検証する単体で十分。テストランナー設定（jsx 変換）は既存 `wp-scripts test-unit-js` に従う。

- [ ] **Step 2: テストが失敗することを確認**

Run: `npx wp-scripts test-unit-js PlatformEditor`
Expected: FAIL。

- [ ] **Step 3: PlatformEditor.jsx を更新**

provider 候補に eligibleProvider を渡す（89 行）:

```jsx
					options={providerOptionsFor(
						platform.provider ?? 'manual',
						platform.eligibleProvider ?? ''
					)}
```

更新頻度ブロック（97-110 行の `SelectControl`）を数値入力＋プリセットに置換:

```jsx
				{platform.autoRefresh && (
					<SelectControl
						label={__('更新間隔（時間毎）', 'affilicard')}
						value={String(platform.refreshIntervalHours ?? 3)}
						options={[
							{ label: __('1時間毎', 'affilicard'), value: '1' },
							{ label: __('3時間毎', 'affilicard'), value: '3' },
							{ label: __('6時間毎', 'affilicard'), value: '6' },
							{ label: __('12時間毎', 'affilicard'), value: '12' },
							{ label: __('24時間毎', 'affilicard'), value: '24' },
						]}
						onChange={(v) =>
							update({ refreshIntervalHours: parseInt(v, 10) || 24 })
						}
						help={__(
							'価格は取得から24時間で自動的に非表示になります（24時間より短い間隔を推奨）。',
							'affilicard'
						)}
					/>
				)}
```

- [ ] **Step 4: テストが通ることを確認 → コミット**

Run: `npx wp-scripts test-unit-js PlatformEditor`
Expected: PASS。あわせて `npx wp-scripts test-unit-js` 全体・`npx wp-scripts lint-js`。

```bash
git add src/Admin/components/PlatformEditor.jsx tests/js/PlatformEditor.test.jsx
git commit -m "feat: 更新頻度UIをN時間毎に変更しeligibleProviderを候補に渡す"
```

---

## Task 13: ビルド・lint・全テスト・CHANGELOG・バージョン

**Files:**
- Modify: `CHANGELOG.md`
- Modify: `affilicard.php`（Version ヘッダ）＋ `package.json`/`readme` の version（既存の bump 対象に合わせる）
- Test: 全 PHPUnit / 全 JS

**Interfaces:** なし（リリース準備）。

- [ ] **Step 1: JS build＋lint＋全テスト**

Run:
```bash
npx wp-scripts build
npx wp-scripts lint-js
npx wp-scripts test-unit-js
```
Expected: いずれも成功。

- [ ] **Step 2: PHP 全テスト＋PHPCS**

Run:
```bash
docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit
docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpcs
```
Expected: 全緑・PHPCS クリーン。

- [ ] **Step 3: CHANGELOG＋バージョン bump（MINOR）**

`CHANGELOG.md` に本機能（楽天refresh再設計・価格鮮度表示・更新頻度制御）を追記。**併せて未リリースの PR #85（Provider 候補を platform 対応に絞る・既定から BookWalker 除去）も同じ v2.2.0 エントリに記載する**（#85 はリリース延期で main に unreleased 状態のため本リリースに同梱される）。`affilicard.php` の `Version:` ヘッダ・`package.json` の version を MINOR bump（現行 **v2.1.1 → v2.2.0**）。PUC 検知のため Version ヘッダのコミット同期を厳守（`project_affilicard_puc_version_header`）。

- [ ] **Step 4: コミット**

```bash
git add CHANGELOG.md affilicard.php package.json
git commit -m "chore: v2.1.0（楽天refresh再設計＋価格鮮度表示＋更新頻度制御）"
```

- [ ] **Step 5: CodeRabbit → PR（auto-merge しない）**

Run: `coderabbit review --plain`（上限時は数分待つ）→ 指摘対応 → push → PR 作成。**Playground プレビューでユーザーが確認 → マージ**。マージ後にタグ `v2.1.0` ＋ GitHub Release。

---

# Phase 3 — e-comi 側（別リポ・別 PR）

> **別リポジトリ**（`/Users/pitolick/Documents/Develop/pitolick/e-comi`）の変更のため、**別ブランチ・別 PR**。affilicard 側がマージ・リリースされ、e-comi の `@pitolick/affilicard` 依存とは独立に動く（affilicard は search_key/last_verified_at 無しでも degrade して動作＝title フォールバック・未確認は非表示）。

## Task 14 (e-comi): rakuten-listing が search_key と last_verified_at を格納

**Files:**
- Modify: `scripts/post-products.ts`（`ManifestListing` に `search_key?`・`last_verified_at?` 追加）
- Modify: `scripts/rakuten-listing.ts`（`mapRakutenItem` が `search_key = item.title`・投稿時刻の `last_verified_at` を格納）
- Test: `scripts/rakuten-listing.test.ts`（既存 test に追記）

**Interfaces:**
- Produces: `ManifestListing.search_key?: string`、`ManifestListing.last_verified_at?: string`。
- affilicard 側 listing メタは配列丸ごと保存のため、これらは追加のみで往復保存される。

- [ ] **Step 1: 失敗するテストを書く**

`scripts/rakuten-listing.test.ts`:

```ts
it('mapRakutenItem は search_key に item.title を格納する', () => {
  const r = mapRakutenItem({
    title: '作品タイトル 3',
    itemUrl: 'https://books.rakuten.co.jp/rk/deadbeef01/',
    itemPrice: 693,
    affiliateUrl: 'https://hb.afl.rakuten.co.jp/hgc/x/',
  });
  expect(r.listing.search_key).toBe('作品タイトル 3');
});
```

- [ ] **Step 2: 失敗を確認**

Run: `npx vitest run scripts/rakuten-listing.test.ts`
Expected: FAIL（`search_key` 未設定）。

- [ ] **Step 3: 型と mapping を追加**

`scripts/post-products.ts` の `ManifestListing` に追加:

```ts
export interface ManifestListing {
  platform: string;
  external_id: string;
  affiliate_url?: string;
  regular_url?: string;
  price?: string;
  list_price?: string;
  badge?: string;
  image_url?: string;
  /** affilicard refresh が keyword 検索に使う（楽天は per-item ID 引き不可のため）。 */
  search_key?: string;
  /** API 確認済み時刻（ISO8601）。affilicard の価格鮮度ゲートの起点。 */
  last_verified_at?: string;
}
```

`scripts/rakuten-listing.ts` の `mapRakutenItem` の listing 構築に `search_key` を追加（`last_verified_at` は post-products 投稿時刻で付与するため mapping では入れない）:

```ts
  const listing: ManifestListing = {
    platform: RAKUTEN_PLATFORM,
    external_id: extractRakutenExternalId(item.itemUrl),
    regular_url: item.itemUrl,
    affiliate_url: item.affiliateUrl ?? '',
    image_url: item.largeImageUrl ?? item.mediumImageUrl ?? '',
    price: item.itemPrice != null ? String(item.itemPrice) : '',
    search_key: item.title,
  };
```

- [ ] **Step 4: last_verified_at を投稿時に付与**

`scripts/post-products.ts` の `toUpsertInput()` で、楽天Kobo（API 取得済み＝price 非空）listing に投稿時刻を刻む。`ManifestListing` を meta に載せる直前で、`search_key` を持つ listing（＝API 由来）に `last_verified_at` を付与:

```ts
  const nowIso = new Date().toISOString();
  const listingsWithVerified = listings.map((l) =>
    l.search_key && l.price ? { ...l, last_verified_at: l.last_verified_at ?? nowIso } : l
  );
```

そして meta の `affilicard_listings` を `listingsWithVerified` にする。テストは `search_key` の格納で足りるが、投稿時刻付与の単体テストも追加推奨（`toUpsertInput` の meta に `last_verified_at` が入ることを検証）。

> 注: `new Date().toISOString()` は post-products の CLI 実行時（GitHub Actions）に評価される。affilicard 側は `strtotime` で UTC epoch に解釈するため TZ 整合は問題なし。

- [ ] **Step 5: テスト → コミット → PR**

Run: `npx vitest run scripts/rakuten-listing.test.ts scripts/post-products.test.ts`
Expected: PASS。あわせて `npx tsc --noEmit`・`npx eslint .`・`npx prettier --check .`。

```bash
git add scripts/post-products.ts scripts/rakuten-listing.ts scripts/rakuten-listing.test.ts
git commit -m "feat: 楽天listing に search_key と投稿時 last_verified_at を格納"
```

CodeRabbit → PR。affilicard リリース後に本 PR をマージ。

---

# Self-Review 結果

**Spec coverage:**
- §3-1 fetch contract 拡張 → Task 2 ✓
- §3-2 RakutenProvider 再取得（title検索・ハッシュ一致・null ガード）→ Task 1 ✓
- §3-3 eligibleProvider＋seed auto → Task 3/4/5（＋UI Task 12）✓
- §4-1 last_verified_at 記録 → Task 2 ✓
- §4-2 価格表示ゲート → Task 6/10 ✓
- §4-3 priceTtlHours（一律24）＋免責 → Task 7/8/10 ✓
- §4-4 警告アイコン → Task 11 ✓
- §5 refreshIntervalHours（N時間毎・既定3）＋動的スケジュール → Task 7/8/9/12 ✓
- §6 データモデル（search_key/last_verified_at/eligibleProvider/priceTtlHours/refreshIntervalHours）→ Task 2/3/7/14 ✓
- §利用側 e-comi 小改修 → Task 14 ✓

**Placeholder scan:** 各実装 step に実コードあり。helper 名（`stubRakutenResponse` 等）は「既存テスト方式に合わせる」と明記した擬似名で、実コード生成時に既存パターンへ具体化する前提（プレースホルダではなく実装指示）。

**Type consistency:** `providerOptionsFor(currentProvider, eligibleProvider)`（Task 5/12 一致）、`isPriceDisplayable(array, ?PlatformDefinition, int)`（Task 6/10/11 一致）、`refreshIntervalHours`/`priceTtlHours`/`eligibleProvider`（Task 3/7/8/9/12 一致）、`scheduleName(int)`（Task 9 内一致）を確認。

**依存順の注意:** Task 6（PriceFreshness）は Task 7（priceTtlHours）に依存 → **Task 7 を Task 6 より先に実施**（本文にも明記）。それ以外は番号順で安全。
