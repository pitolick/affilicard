# 価格更新の非同期キュー化（v2.4.0）Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 価格更新を Action Scheduler ベースの非同期キューに載せ、per-provider レート制限・鮮度スキップ・キュー管理UIを備え、AutoCreate の同期API混入も解消する。

**Architecture:** キューストア/ワーカー/claim/memoryガード/失敗保持/管理UI/CLI は **Action Scheduler（bundle）に委譲**。自前は「Enqueuer（AS ラッパ・dedup=`$unique`・優先度=`$priority`）＋ RateLimiter（provider別クロスプロセス throttle・AS を塞がない再スケジュール式）＋トリガー配線（公開/更新 force・cron 掃引＝鮮度スキップ・主役）＋薄い管理UI」。閲覧駆動は不採用。

**Tech Stack:** PHP 8.1+（composer platform 8.2）/ WordPress / Action Scheduler（`woocommerce/action-scheduler`）/ TypeScript 5.6+・React（`@wordpress/*`）/ PHPUnit + 10up/wp_mock / wp-scripts（JS test）/ @wordpress/env（E2E）。

**設計スペック:** [docs/superpowers/specs/2026-07-22-refresh-queue-design.md](../specs/2026-07-22-refresh-queue-design.md)

## Global Constraints

- **PHP**: `>=8.1`（composer `platform.php=8.2`）。ESM/Node 20+。TypeScript 5.6+。
- **テスト実行（PHP）**: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit`。ローカル Mac は PHP 非導入（Docker 専用）。
- **PHPCS**: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpcs`。違反は `vendor/bin/phpcbf`。
- **テスト実行（JS）**: volta ローカルで `npx wp-scripts test-unit-js`。**JS lint gate は `npm run lint:js`（src のみ）**（`wp-scripts lint-js` は既存ノイズで失敗するため使わない）。
- **E2E**: `@wordpress/env`（wp-env）。Playground は Origin ヘッダを送れないため楽天実API検証は wp-env/本番のみ。
- **外部APIは全てモック**（Claude/WP REST/AS/楽天/DMM）。unit で実HTTPを叩かない。
- **時刻は UTC**：`gmdate('c')`。`current_time('c')` は使わない（PriceFreshness は実 UTC epoch と比較するため）。
- **テストに実在の作品名・人名を使わない**（架空プレースホルダ。プラットフォーム名は可）。
- **コミット**: 日本語 Conventional Commits＋末尾に `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`。
- **SemVer**: 機能追加＝MINOR＝**v2.4.0**。Version 同期3箇所（`affilicard.php` の Version ヘッダ／`package.json`／`AFFILICARD_VERSION` 定数。PUC はタグのツリーの `affilicard.php` ヘッダを読むため必須）。
- **AS アクション**: hook=`affilicard_refresh_listing`（args `{post_id, platform}`）・`affilicard_autocreate`（args `{platform, external_id}`）。**group=`affilicard-{provider}`**。優先度=`$priority`（force=0／manual=10／sweep=20）。dedup=`$unique=true`。
- **auto-merge しない**：feature PR は Playground/pr-preview でユーザー視覚確認後にマージ。マージ後 v2.4.0 タグ→release.yml→Release。

---

## File Structure

新規（`src/Queue/`）：

- `src/Queue/Enqueuer.php` — AS への投入ラッパ（dedup=`$unique`・優先度=`$priority`・force 時 unschedule＋再投入・sweep 時 鮮度スキップ＋depth cap＋jitter・AutoCreate 投入・queue 深さ集計）。
- `src/Queue/RateLimiter.php` — provider 別クロスプロセス throttle（last-call を option に原子記録・`tryAcquire`・実効間隔＝`max(下限, 管理値)`）。
- `src/Queue/RefreshHandler.php` — `affilicard_refresh_listing` ハンドラ（pause ゲート→RateLimiter→fetch→listing 反映→429/失敗 backoff 再投入）。
- `src/Queue/AutoCreateHandler.php` — `affilicard_autocreate` ハンドラ（pause ゲート→RateLimiter→ProductAutoCreator）。
- `src/Queue/PublishTrigger.php` — `transition_post_status`/`post_updated` で `parse_blocks` 解決→force 投入（future→publish も統合）。
- `src/Queue/QueueStats.php` — AS API で provider 別 pending/failed 件数・深さを集計。
- `src/Queue/QueueMaintenance.php` — 掃引（stale enqueue）＋reconcile＋AS retention フィルタ。
- `src/Queue/ActionSchedulerLoader.php` — AS の bundle ロード（`plugins_loaded` で `functions.php` require）。
- `src/Rest/QueueController.php` — `/affilicard/v1/refresh-queue`（stats/clear/clearFailed/retryFailed/cancelPending/pause・manage_options）。
- `src/Admin/components/QueuePanel.jsx` ＋ `src/Admin/api/queue.js` — 薄型管理UI（設定＋サマリ＋一括操作＋Scheduled Actions リンク）。

変更：

- `src/Provider/ProviderInterface.php` — `minRequestIntervalMs(): int` 追加。
- `src/Provider/ManualProvider.php` / `Rakuten/RakutenProvider.php` / `Dmm/DmmProvider.php` — 実装追加。
- `src/Pricing/PriceFreshness.php` — `isStale()` 追加。
- `src/Cron/ListingRefresher.php` / `RefreshScheduler.php` — 同期 run を掃引 enqueue へ。
- `src/Block/Block.php` — AutoCreate を inline 生成から enqueue へ。
- `src/AutoCreate/ProductAutoCreator.php` — ハンドラから使う（signature 維持）。
- `src/PostType/ProductListColumns.php` — Fallback 列 tooltip にキュー状態連携。
- `src/Settings/GeneralSettings.php` — throttle 上書き/pause/retention/depth cap の設定追加。
- `src/Plugin.php`（or bootstrap）— AS ロード・ハンドラ/トリガー/REST の配線。
- `composer.json` — `woocommerce/action-scheduler` 追加。
- `affilicard.php` / `package.json` — v2.4.0。

---

## Phase P1: AS bundle ＋ 鮮度ヘルパ ＋ Enqueuer

### Task 1: PriceFreshness::isStale（鮮度スキップの判定）

**Files:**

- Modify: `src/Pricing/PriceFreshness.php`
- Test: `tests/Unit/Pricing/PriceFreshnessTest.php`（既存に追記 or 新規）

**Interfaces:**

- Produces: `PriceFreshness::isStale( array $listing, ?PlatformDefinition $platform, int $nowTs ): bool` — 「再取得が必要か」。`true`＝要更新（platform 不明・`last_verified_at` 欠落/パース不可・age>TTL）。`false`＝TTL 内でフレッシュ。

- [ ] **Step 1: Write the failing test**

`tests/Unit/Pricing/PriceFreshnessTest.php` に追記（`PlatformDefinition` は `priceTtlHours` を持つ）:

```php
public function test_isStale_last_verified_at欠落はstale(): void {
    $platform = $this->platform( 24 ); // priceTtlHours=24
    $this->assertTrue( PriceFreshness::isStale( array( 'price' => '500' ), $platform, 1_000_000 ) );
}

public function test_isStale_TTL内はfresh(): void {
    $platform = $this->platform( 24 );
    $now      = 1_000_000;
    $listing  = array( 'price' => '500', 'last_verified_at' => gmdate( 'c', $now - 3600 ) ); // 1h 前
    $this->assertFalse( PriceFreshness::isStale( $listing, $platform, $now ) );
}

public function test_isStale_TTL超過はstale(): void {
    $platform = $this->platform( 24 );
    $now      = 1_000_000 + 25 * 3600;
    $listing  = array( 'price' => '500', 'last_verified_at' => gmdate( 'c', 1_000_000 ) );
    $this->assertTrue( PriceFreshness::isStale( $listing, $platform, $now ) );
}

public function test_isStale_platformなしはstale扱い(): void {
    $this->assertTrue( PriceFreshness::isStale( array( 'price' => '500' ), null, 1_000_000 ) );
}
```

`platform()` ヘルパ（PlatformDefinition を priceTtlHours 付きで生成。既存テストの生成方法に倣う）をテストクラスに用意する。

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter isStale`
Expected: FAIL（`Call to undefined method Affilicard\Pricing\PriceFreshness::isStale()`）

- [ ] **Step 3: Write minimal implementation**

`src/Pricing/PriceFreshness.php` に追加:

```php
/**
 * 再取得が必要か（stale か）を判定する。フレッシュな価格の再 fetch を避けるための鮮度スキップ用。
 *
 * @param array<string, mixed> $listing
 */
public static function isStale( array $listing, ?PlatformDefinition $platform, int $nowTs ): bool {
    if ( null === $platform ) {
        return true;
    }
    $verified = isset( $listing['last_verified_at'] ) ? trim( (string) $listing['last_verified_at'] ) : '';
    if ( '' === $verified ) {
        return true;
    }
    $verifiedTs = strtotime( $verified );
    if ( false === $verifiedTs ) {
        return true;
    }
    $ttl = $platform->priceTtlHours * 3600;
    return ( $nowTs - $verifiedTs ) > $ttl;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter isStale`
Expected: PASS（4 tests）

- [ ] **Step 5: Commit**

```bash
git add src/Pricing/PriceFreshness.php tests/Unit/Pricing/PriceFreshnessTest.php
git commit -m "feat: PriceFreshness::isStale を追加（鮮度スキップ判定）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Action Scheduler を bundle・ロード

**Files:**

- Modify: `composer.json`（require に `woocommerce/action-scheduler`）
- Create: `src/Queue/ActionSchedulerLoader.php`
- Modify: `src/Plugin.php`（boot 時に Loader を呼ぶ）
- Test: `tests/Unit/Queue/ActionSchedulerLoaderTest.php`

**Interfaces:**

- Produces: `ActionSchedulerLoader::register(): void`（`plugins_loaded`（優先度 0）で AS の `functions.php` を require）。`ActionSchedulerLoader::path(): string`（require 先の絶対パス。vendor 分岐対応）。

- [ ] **Step 1: composer 依存を追加**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 require woocommerce/action-scheduler`
Expected: `vendor/woocommerce/action-scheduler/action-scheduler.php` が入り `composer.lock` 更新。

- [ ] **Step 2: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Queue\ActionSchedulerLoader;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ActionSchedulerLoaderTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); \Mockery::close(); parent::tearDown(); }

    public function test_register_plugins_loadedにフックする(): void {
        WP_Mock::expectActionAdded( 'plugins_loaded', array( ActionSchedulerLoader::class, 'boot' ), 0 );
        ActionSchedulerLoader::register();
        $this->assertConditionsMet();
    }

    public function test_path_action_schedulerのfunctions_phpを指す(): void {
        $this->assertStringEndsWith( 'action-scheduler/action-scheduler.php', ActionSchedulerLoader::path() );
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Queue/ActionSchedulerLoaderTest.php`
Expected: FAIL（class 未定義）

- [ ] **Step 4: Write minimal implementation**

```php
<?php
declare(strict_types=1);
namespace Affilicard\Queue;

/**
 * bundle した Action Scheduler をロードする。AS は複数プラグイン同梱を想定し
 * 最新版を自動選択するため、plugins_loaded（優先度 0）で functions.php を require するだけでよい。
 */
final class ActionSchedulerLoader {

    public static function register(): void {
        add_action( 'plugins_loaded', array( self::class, 'boot' ), 0 );
    }

    public static function boot(): void {
        if ( function_exists( 'as_schedule_single_action' ) ) {
            return;
        }
        $path = self::path();
        if ( is_readable( $path ) ) {
            require_once $path;
        }
    }

    public static function path(): string {
        return AFFILICARD_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Queue/ActionSchedulerLoaderTest.php`
Expected: PASS

- [ ] **Step 6: Plugin.php で register を呼ぶ**

`src/Plugin.php` の boot 配線に `ActionSchedulerLoader::register();` を追加（既存の register 群と同じ場所）。手動確認: `grep -n ActionSchedulerLoader src/Plugin.php`。

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock src/Queue/ActionSchedulerLoader.php src/Plugin.php tests/Unit/Queue/ActionSchedulerLoaderTest.php
git commit -m "feat: Action Scheduler を bundle しロード配線を追加

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Enqueuer（AS 投入・dedup・優先度・depth cap・jitter）

**Files:**

- Create: `src/Queue/Enqueuer.php`
- Test: `tests/Unit/Queue/EnqueuerTest.php`

**Interfaces:**

- Consumes: `PriceFreshness::isStale()`（Task 1）、AS 関数 `as_schedule_single_action`/`as_unschedule_all_actions`/`as_get_scheduled_actions`（テストでモック）。
- Produces:
  - 定数 `HOOK_REFRESH='affilicard_refresh_listing'`、`HOOK_AUTOCREATE='affilicard_autocreate'`、`PRIORITY_FORCE=0`、`PRIORITY_MANUAL=10`、`PRIORITY_SWEEP=20`。
  - `group( string $provider ): string`（`"affilicard-{$provider}"`）。
  - `enqueueForced( int $postId, string $platform, string $provider ): void`（既存を unschedule → 即時 priority 0 unique）。
  - `enqueueManual( int $postId, string $platform, string $provider ): void`（即時 priority 10 unique）。
  - `enqueueSweep( int $postId, string $platform, string $provider, ?PlatformDefinition $def, array $listing, int $nowTs ): bool`（`isStale` かつ深さ<上限のとき `now+jitter` priority 20 unique。投入したら true）。
  - `enqueueAutoCreate( string $platform, string $provider, string $externalId ): void`（即時 priority 0 unique・args `{platform, external_id}`）。
  - `queueDepth(): int`（provider 別 group 横断の pending 件数。`as_get_scheduled_actions([...,'per_page'=>1],'ids')` を各既知 group で叩き合算、実装は `QueueStats` に委譲可）。
  - コンストラクタ `__construct( int $depthCap = 500, int $maxJitterSeconds = 300 )`。

- [ ] **Step 1: Write the failing test（force）**

```php
<?php
declare(strict_types=1);
namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Queue\Enqueuer;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class EnqueuerTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); \Mockery::close(); parent::tearDown(); }

    public function test_enqueueForced_既存を解除し即時priority0uniqueで投入する(): void {
        WP_Mock::userFunction( 'as_unschedule_all_actions' )->once()
            ->with( Enqueuer::HOOK_REFRESH, array( 'post_id' => 12, 'platform' => 'rakuten-kobo' ), 'affilicard-rakuten' );
        WP_Mock::userFunction( 'as_schedule_single_action' )->once()
            ->with(
                \Mockery::type( 'int' ),
                Enqueuer::HOOK_REFRESH,
                array( 'post_id' => 12, 'platform' => 'rakuten-kobo' ),
                'affilicard-rakuten',
                true,           // $unique
                Enqueuer::PRIORITY_FORCE
            )
            ->andReturn( 100 );

        ( new Enqueuer() )->enqueueForced( 12, 'rakuten-kobo', 'rakuten' );
        $this->assertConditionsMet();
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Queue/EnqueuerTest.php`
Expected: FAIL（class 未定義）

- [ ] **Step 3: Minimal implementation（force / manual / autocreate / group）**

```php
<?php
declare(strict_types=1);
namespace Affilicard\Queue;

use Affilicard\Platform\PlatformDefinition;
use Affilicard\Pricing\PriceFreshness;

final class Enqueuer {

    public const HOOK_REFRESH    = 'affilicard_refresh_listing';
    public const HOOK_AUTOCREATE = 'affilicard_autocreate';
    public const PRIORITY_FORCE  = 0;
    public const PRIORITY_MANUAL = 10;
    public const PRIORITY_SWEEP  = 20;

    public function __construct(
        private int $depthCap = 500,
        private int $maxJitterSeconds = 300
    ) {}

    public function group( string $provider ): string {
        return 'affilicard-' . $provider;
    }

    public function enqueueForced( int $postId, string $platform, string $provider ): void {
        $args  = array( 'post_id' => $postId, 'platform' => $platform );
        $group = $this->group( $provider );
        as_unschedule_all_actions( self::HOOK_REFRESH, $args, $group );
        as_schedule_single_action( time(), self::HOOK_REFRESH, $args, $group, true, self::PRIORITY_FORCE );
    }

    public function enqueueManual( int $postId, string $platform, string $provider ): void {
        $args = array( 'post_id' => $postId, 'platform' => $platform );
        as_schedule_single_action( time(), self::HOOK_REFRESH, $args, $this->group( $provider ), true, self::PRIORITY_MANUAL );
    }

    public function enqueueAutoCreate( string $platform, string $provider, string $externalId ): void {
        $args = array( 'platform' => $platform, 'external_id' => $externalId );
        as_schedule_single_action( time(), self::HOOK_AUTOCREATE, $args, $this->group( $provider ), true, self::PRIORITY_FORCE );
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: 同上
Expected: PASS

- [ ] **Step 5: Write failing test（sweep：stale＋depth cap＋jitter）**

```php
public function test_enqueueSweep_freshはスキップしfalse(): void {
    $def     = $this->platform( 'rakuten-kobo', 24 ); // priceTtlHours=24
    $now     = 1_000_000;
    $listing = array( 'price' => '500', 'last_verified_at' => gmdate( 'c', $now - 3600 ) );
    WP_Mock::userFunction( 'as_schedule_single_action' )->never();

    $result = ( new Enqueuer() )->enqueueSweep( 12, 'rakuten-kobo', 'rakuten', $def, $listing, $now );
    $this->assertFalse( $result );
}

public function test_enqueueSweep_staleは深さ内でjitter付priority20投入しtrue(): void {
    $def     = $this->platform( 'rakuten-kobo', 24 );
    $now     = 1_000_000;
    $listing = array( 'price' => '500', 'last_verified_at' => gmdate( 'c', $now - 25 * 3600 ) ); // stale
    WP_Mock::userFunction( 'as_get_scheduled_actions' )->andReturn( array() ); // 深さ 0
    WP_Mock::userFunction( 'wp_rand' )->with( 0, 300 )->andReturn( 42 );
    WP_Mock::userFunction( 'as_schedule_single_action' )->once()
        ->with(
            \Mockery::type( 'int' ),
            Enqueuer::HOOK_REFRESH,
            array( 'post_id' => 12, 'platform' => 'rakuten-kobo' ),
            'affilicard-rakuten',
            true,
            Enqueuer::PRIORITY_SWEEP
        )->andReturn( 101 );

    $result = ( new Enqueuer() )->enqueueSweep( 12, 'rakuten-kobo', 'rakuten', $def, $listing, $now );
    $this->assertTrue( $result );
}
```

`platform( string $code, int $ttl )` ヘルパをテストに用意（PlatformDefinition を code/priceTtlHours で生成）。

- [ ] **Step 6: Run to verify new tests fail**

Run: 同上 → FAIL（`enqueueSweep` 未定義）

- [ ] **Step 7: Implement enqueueSweep ＋ queueDepth**

Enqueuer に追加:

```php
public function enqueueSweep( int $postId, string $platform, string $provider, ?PlatformDefinition $def, array $listing, int $nowTs ): bool {
    if ( ! PriceFreshness::isStale( $listing, $def, $nowTs ) ) {
        return false;
    }
    if ( $this->queueDepth() >= $this->depthCap ) {
        return false; // 掃引起点は深さ上限で skip（force は enqueueForced で常に通す）
    }
    $args = array( 'post_id' => $postId, 'platform' => $platform );
    $when = time() + wp_rand( 0, $this->maxJitterSeconds );
    as_schedule_single_action( $when, self::HOOK_REFRESH, $args, $this->group( $provider ), true, self::PRIORITY_SWEEP );
    return true;
}

public function queueDepth(): int {
    $ids = as_get_scheduled_actions(
        array( 'status' => 'pending', 'per_page' => $this->depthCap + 1, 'group' => '' ),
        'ids'
    );
    return is_array( $ids ) ? count( $ids ) : 0;
}
```

> 注: `queueDepth()` は group 空指定で全 pending を数える（`affilicard-*` に限定したい場合は §Task 14 の QueueStats に集計を寄せ、Enqueuer は QueueStats を注入して委譲してもよい。MVP は全 pending 深さで十分）。

- [ ] **Step 8: Run to verify it passes**

Run: 同上
Expected: PASS（force/manual/autocreate/sweep）

- [ ] **Step 9: PHPCS**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpcs src/Queue/Enqueuer.php`
Expected: 0 errors（あれば `phpcbf`）

- [ ] **Step 10: Commit**

```bash
git add src/Queue/Enqueuer.php tests/Unit/Queue/EnqueuerTest.php
git commit -m "feat: Enqueuer を追加（AS投入・unique dedup・priority・鮮度スキップ・depth cap・jitter）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Phase P2: RateLimiter ＋ ProviderInterface

### Task 4: ProviderInterface::minRequestIntervalMs

**Files:**

- Modify: `src/Provider/ProviderInterface.php`
- Modify: `src/Provider/ManualProvider.php` / `src/Provider/Rakuten/RakutenProvider.php` / `src/Provider/Dmm/DmmProvider.php`
- Test: `tests/Unit/Provider/*ProviderTest.php`（既存に追記）

**Interfaces:**

- Produces: `ProviderInterface::minRequestIntervalMs(): int`（provider の安全下限 ms）。manual=0／楽天=1100／DMM=1000。

- [ ] **Step 1: Write failing tests**

各 provider の既存テストに追記（例 Rakuten）:

```php
public function test_minRequestIntervalMs_楽天は1100(): void {
    $this->assertSame( 1100, $this->makeProvider()->minRequestIntervalMs() );
}
```

Manual: `->assertSame( 0, ... )`、DMM: `->assertSame( 1000, ... )`。

- [ ] **Step 2: Run to verify fail**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter minRequestIntervalMs`
Expected: FAIL（未定義メソッド）

- [ ] **Step 3: Interface＋各実装を追加**

`ProviderInterface.php` に宣言追加:

```php
/**
 * この provider の安全な最小リクエスト間隔（ミリ秒）。手動入力は 0。
 * RateLimiter が provider 別 throttle の下限として使う。
 */
public function minRequestIntervalMs(): int;
```

`ManualProvider.php`:

```php
public function minRequestIntervalMs(): int { return 0; }
```

`RakutenProvider.php`:

```php
public function minRequestIntervalMs(): int { return 1100; } // 楽天 openapi ≈ 1 req/sec/app ＋余裕
```

`DmmProvider.php`:

```php
public function minRequestIntervalMs(): int { return 1000; } // 暫定・公式/実測で確定
```

- [ ] **Step 4: Run to verify pass**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter minRequestIntervalMs`
Expected: PASS（3 provider）

- [ ] **Step 5: Commit**

```bash
git add src/Provider/ProviderInterface.php src/Provider/ManualProvider.php src/Provider/Rakuten/RakutenProvider.php src/Provider/Dmm/DmmProvider.php tests/Unit/Provider/
git commit -m "feat: ProviderInterface に minRequestIntervalMs を追加（provider別throttle下限）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: RateLimiter（provider別クロスプロセス throttle・AS を塞がない再スケジュール式）

**Files:**

- Create: `src/Queue/RateLimiter.php`
- Test: `tests/Unit/Queue/RateLimiterTest.php`

**Interfaces:**

- Consumes: `ProviderInterface::minRequestIntervalMs()`、GeneralSettings（provider 別上書き・Task 15 で追加。ここでは `effectiveIntervalMs()` に override 引数を受ける形にして疎結合）。
- Produces:
  - `__construct( int $fl003 )`（不要。下記の static/instance を確定）→ 実際は `RateLimiter` インスタンスに `intervalResolver`（provider→ms）を注入。
  - `effectiveIntervalMs( int $floorMs, int $overrideMs ): int`（`max($floorMs, $overrideMs)`。override<=0 は floor）。
  - `tryAcquire( string $provider, int $intervalMs, int $nowMs ): array{ ok: bool, next_ms: int }` — last-call を option（`affilicard_ratelimit_{provider}`）から読み、`nowMs - last >= intervalMs` なら last=now に**原子更新**して `ok=true`、未経過なら `ok=false, next_ms=last+interval` を返す（fetch せず後ろ倒し用の時刻）。

- [ ] **Step 1: Write failing test**

```php
<?php
declare(strict_types=1);
namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Queue\RateLimiter;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class RateLimiterTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); \Mockery::close(); parent::tearDown(); }

    public function test_effectiveIntervalMs_下限と上書きの大きい方(): void {
        $rl = new RateLimiter();
        $this->assertSame( 1100, $rl->effectiveIntervalMs( 1100, 0 ) );    // override 無し
        $this->assertSame( 2000, $rl->effectiveIntervalMs( 1100, 2000 ) ); // 遅い側に上書き
        $this->assertSame( 1100, $rl->effectiveIntervalMs( 1100, 500 ) );  // 下限より速い上書きは無効
    }

    public function test_tryAcquire_経過済みならokでlastを更新する(): void {
        WP_Mock::userFunction( 'get_option' )->with( 'affilicard_ratelimit_rakuten', 0 )->andReturn( 1000 );
        WP_Mock::userFunction( 'update_option' )->once()->with( 'affilicard_ratelimit_rakuten', 3000, false )->andReturn( true );

        $out = ( new RateLimiter() )->tryAcquire( 'rakuten', 1100, 3000 );
        $this->assertTrue( $out['ok'] );
    }

    public function test_tryAcquire_未経過ならngでnext_msを返す(): void {
        WP_Mock::userFunction( 'get_option' )->with( 'affilicard_ratelimit_rakuten', 0 )->andReturn( 1000 );
        WP_Mock::userFunction( 'update_option' )->never();

        $out = ( new RateLimiter() )->tryAcquire( 'rakuten', 1100, 1500 ); // 1500-1000=500 < 1100
        $this->assertFalse( $out['ok'] );
        $this->assertSame( 2100, $out['next_ms'] ); // 1000 + 1100
    }
}
```

- [ ] **Step 2: Run to verify fail**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Queue/RateLimiterTest.php`
Expected: FAIL（class 未定義）

- [ ] **Step 3: Minimal implementation**

```php
<?php
declare(strict_types=1);
namespace Affilicard\Queue;

/**
 * provider 別のクロスプロセス throttle。直近呼び出し時刻(ms)を option に記録し、
 * ハンドラは「間隔未経過なら fetch せず後ろ倒し」に使う（AS ランナーを sleep で塞がない）。
 */
final class RateLimiter {

    private function optionKey( string $provider ): string {
        return 'affilicard_ratelimit_' . $provider;
    }

    public function effectiveIntervalMs( int $floorMs, int $overrideMs ): int {
        return $overrideMs > 0 ? max( $floorMs, $overrideMs ) : $floorMs;
    }

    /**
     * @return array{ok: bool, next_ms: int}
     */
    public function tryAcquire( string $provider, int $intervalMs, int $nowMs ): array {
        $key  = $this->optionKey( $provider );
        $last = (int) get_option( $key, 0 );
        if ( $nowMs - $last >= $intervalMs ) {
            update_option( $key, $nowMs, false );
            return array( 'ok' => true, 'next_ms' => $nowMs );
        }
        return array( 'ok' => false, 'next_ms' => $last + $intervalMs );
    }
}
```

> 原子性注記: MVP は `get_option`→`update_option` の read-modify-write。永続オブジェクトキャッシュ非搭載の既定構成では単一プロセス内で十分。より厳密にするなら `$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%d WHERE option_name=%s AND option_value < %d", ... ) )` の条件付き UPDATE で affected-rows により acquire 判定する（実装時に選択・§9-4）。

- [ ] **Step 4: Run to verify pass**

Run: 同上
Expected: PASS

- [ ] **Step 5: PHPCS ＋ Commit**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpcs src/Queue/RateLimiter.php
git add src/Queue/RateLimiter.php tests/Unit/Queue/RateLimiterTest.php
git commit -m "feat: RateLimiter を追加（provider別throttle・再スケジュール式・実効間隔）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: GeneralSettings 追加（pause・throttle上書き・depth cap・retention）

**Files:**

- Modify: `src/Settings/GeneralSettings.php`
- Test: `tests/Unit/Settings/GeneralSettingsTest.php`（既存に追記）

**Interfaces:**

- Produces（GeneralSettings に追加）:
  - `isQueuePaused(): bool`（key `queue_paused`・既定 false）
  - `queueDepthCap(): int`（key `queue_depth_cap`・既定 500・最小 1）
  - `throttleOverrideMs( string $provider ): int`（key `throttle_overrides` = `array<string,int>`・未設定/0 は 0）
  - `retentionDoneHours(): int`（key `retention_done_hours`・既定 24）
  - `retentionFailedDays(): int`（key `retention_failed_days`・既定 7）
- DEFAULTS に上記キーを追加し、`sanitize()` で bool/int/正規化。

- [ ] **Step 1: Write failing tests**

```php
public function test_defaults_キュー設定の既定値(): void {
    WP_Mock::userFunction( 'get_option' )->with( GeneralSettings::OPTION_KEY, array() )->andReturn( array() );
    $this->assertFalse( GeneralSettings::isQueuePaused() );
    $this->assertSame( 500, GeneralSettings::queueDepthCap() );
    $this->assertSame( 24, GeneralSettings::retentionDoneHours() );
    $this->assertSame( 7, GeneralSettings::retentionFailedDays() );
    $this->assertSame( 0, GeneralSettings::throttleOverrideMs( 'rakuten' ) );
}

public function test_throttleOverrideMs_provider別に返す(): void {
    WP_Mock::userFunction( 'get_option' )->with( GeneralSettings::OPTION_KEY, array() )
        ->andReturn( array( 'throttle_overrides' => array( 'rakuten' => 2000 ) ) );
    $this->assertSame( 2000, GeneralSettings::throttleOverrideMs( 'rakuten' ) );
    $this->assertSame( 0, GeneralSettings::throttleOverrideMs( 'dmm' ) );
}

public function test_queue_paused_true(): void {
    WP_Mock::userFunction( 'get_option' )->with( GeneralSettings::OPTION_KEY, array() )
        ->andReturn( array( 'queue_paused' => true ) );
    $this->assertTrue( GeneralSettings::isQueuePaused() );
}
```

- [ ] **Step 2: Run to verify fail**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Settings/GeneralSettingsTest.php`
Expected: FAIL（未定義メソッド）

- [ ] **Step 3: Implement**

`GeneralSettings::DEFAULTS` に追加:

```php
'queue_paused'          => false,
'queue_depth_cap'       => 500,
'throttle_overrides'    => array(),
'retention_done_hours'  => 24,
'retention_failed_days' => 7,
```

アクセサ追加:

```php
public static function isQueuePaused(): bool {
    return ! empty( self::get()['queue_paused'] );
}
public static function queueDepthCap(): int {
    return (int) self::get()['queue_depth_cap'];
}
public static function throttleOverrideMs( string $provider ): int {
    $ov = self::get()['throttle_overrides'];
    return is_array( $ov ) && isset( $ov[ $provider ] ) ? max( 0, (int) $ov[ $provider ] ) : 0;
}
public static function retentionDoneHours(): int {
    return (int) self::get()['retention_done_hours'];
}
public static function retentionFailedDays(): int {
    return (int) self::get()['retention_failed_days'];
}
```

`merge()` は DEFAULTS のキーを走査するため新キーも取り込まれる。`sanitize()` に正規化を追加:

```php
$queue_paused    = ! empty( $values['queue_paused'] );
$queue_depth_cap = isset( $values['queue_depth_cap'] ) ? max( 1, (int) $values['queue_depth_cap'] ) : self::DEFAULTS['queue_depth_cap'];
$overrides_raw   = isset( $values['throttle_overrides'] ) && is_array( $values['throttle_overrides'] ) ? $values['throttle_overrides'] : array();
$throttle_overrides = array();
foreach ( $overrides_raw as $prov => $ms ) {
    $throttle_overrides[ (string) $prov ] = max( 0, (int) $ms );
}
$retention_done_hours  = isset( $values['retention_done_hours'] ) ? max( 1, (int) $values['retention_done_hours'] ) : self::DEFAULTS['retention_done_hours'];
$retention_failed_days = isset( $values['retention_failed_days'] ) ? max( 1, (int) $values['retention_failed_days'] ) : self::DEFAULTS['retention_failed_days'];
```

これらを sanitize の return 配列に追加する。

- [ ] **Step 4: Run to verify pass**

Run: 同上
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Settings/GeneralSettings.php tests/Unit/Settings/GeneralSettingsTest.php
git commit -m "feat: GeneralSettings にキュー設定(pause/depth/throttle上書き/retention)を追加

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Phase P3: ハンドラ ＆ トリガー配線

### Task 7: ListingRefresher::refreshOne（単一 listing・成否を返す）

**Files:**

- Modify: `src/Cron/ListingRefresher.php`
- Test: `tests/Unit/Cron/ListingRefresherTest.php`（既存に追記）

**Interfaces:**

- Produces: `ListingRefresher::refreshOne( int $postId, string $platform ): bool` — 指定 platform の listing を1件 fetch→反映し、fetch 成功で `true`／失敗（provider 無し・fetch null）で `false`。既存 `refreshListing()` を再利用（force 相当・throttle はハンドラ側で担保済みの前提）。

- [ ] **Step 1: Write failing test**

```php
public function test_refreshOne_fetch成功でtrueを返し保存する(): void {
    // provider->fetch が価格を返す → repository->save 呼び出し → true。
    // 既存テストの provider/repository モック構築に倣う（ProviderRegistry・ProductRepository をコンストラクタ注入）。
    // ... 既存の makeRefresher() ヘルパを利用 ...
    $this->assertTrue( $refresher->refreshOne( 12, 'rakuten-kobo' ) );
}

public function test_refreshOne_fetch失敗でfalse(): void {
    // provider->fetch が null → false（保存はされるが success=false）
    $this->assertFalse( $refresher->refreshOne( 12, 'rakuten-kobo' ) );
}
```

（既存 `ListingRefresherTest` の provider/repository スタブ構築を流用。作品名は架空プレースホルダを使う。）

- [ ] **Step 2: Run to verify fail**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter refreshOne`
Expected: FAIL（未定義メソッド）

- [ ] **Step 3: Implement**

`ListingRefresher` に、既存 `refreshProduct()` の save 配列構築を private `saveProduct()` に抽出して共用し（DRY・pre-flight 合意）、`refreshOne()` を追加する:

```php
/**
 * 商品と更新後 listings を Repository 形に組んで保存する（refreshProduct/refreshOne 共用）。
 *
 * @param array<string, mixed>       $product  Repository::find() の戻り
 * @param list<array<string, mixed>> $listings 更新後 listing 群
 */
private function saveProduct( int $postId, array $product, array $listings ): void {
    $this->repository->save(
        array(
            'id'           => $postId,
            'title'        => (string) $product['title'],
            'content'      => (string) $product['content'],
            'status'       => (string) $product['status'],
            'product_type' => (string) $product['product_type'],
            'stock_status' => (string) $product['stock_status'],
            'extras'       => $product['extras'],
            'listings'     => array_values( $listings ),
        )
    );
}

public function refreshOne( int $postId, string $platform ): bool {
    $product = $this->repository->find( $postId );
    if ( null === $product || ! is_array( $product['listings'] ?? null ) ) {
        return false;
    }
    $listings = $product['listings'];
    foreach ( $listings as $index => $listing ) {
        if ( ! is_array( $listing ) || ( $listing['platform'] ?? '' ) !== $platform ) {
            continue;
        }
        $refreshed          = $this->refreshListing( $listing, (string) $product['title'] );
        $listings[ $index ] = $refreshed;
        $this->saveProduct( $postId, $product, $listings );
        return '' === (string) ( $refreshed['fetch_error'] ?? '' );
    }
    return false;
}
```

既存 `refreshProduct()` の `$this->repository->save( array( 'id'=>$postId, ... ) )` ブロックも `$this->saveProduct( $postId, $product, $listings )` 呼び出しに置換する（挙動は等価・重複解消）。

- [ ] **Step 4: Run to verify pass**

Run: 同上 → PASS

- [ ] **Step 5: Commit**

```bash
git add src/Cron/ListingRefresher.php tests/Unit/Cron/ListingRefresherTest.php
git commit -m "feat: ListingRefresher::refreshOne を追加（単一listing・成否を返す）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: RefreshHandler（pause→throttle→fetch→backoff 再投入）

**Files:**

- Create: `src/Queue/ThrottledActionHandler.php`（共通抽象基底・DRY・pre-flight 合意）
- Create: `src/Queue/RefreshHandler.php`
- Modify: `src/Queue/Enqueuer.php`（`rescheduleRefresh()` 追加）
- Test: `tests/Unit/Queue/RefreshHandlerTest.php`

**Interfaces:**

- Consumes: `GeneralSettings::isQueuePaused()`/`throttleOverrideMs()`、`RateLimiter::effectiveIntervalMs()`/`tryAcquire()`、`PlatformConfig::find()`、`ProviderRegistry::get()`、`ProviderInterface::minRequestIntervalMs()`/`isAutomatic()`、`ListingRefresher::refreshOne()`、`Enqueuer`。
- Produces:
  - `ThrottledActionHandler`（abstract）: `run(array $args)`（テンプレート）＋ abstract `providerCodeFor(array):?string`/`performWork(array):bool`/`reschedule(int,array):void`/`attemptKey(array):string`。RefreshHandler・AutoCreateHandler（Task 9）が継承。
  - `Enqueuer::rescheduleRefresh( int $whenSec, int $postId, string $platform, string $provider ): void`（`as_schedule_single_action($whenSec, HOOK_REFRESH, args, group, false, PRIORITY_MANUAL)`・**unique=false**）。
  - `RefreshHandler extends ThrottledActionHandler`・`__construct( Enqueuer $enqueuer, RateLimiter $limiter, ListingRefresher $refresher, ProviderRegistry $registry )`
  - `RefreshHandler::handle( int $postId, string $platform ): void`（AS が `affilicard_refresh_listing` で args を展開して呼ぶ。配線は Task 13）。

- [ ] **Step 1: Enqueuer に rescheduleRefresh を追加（TDD）**

`EnqueuerTest` に（**`$unique=false`**：自己再投入は実行中の自分自身が in-progress 重複となり unique=true だと必ずスキップされるため）:

```php
public function test_rescheduleRefresh_指定時刻にpriority10で再投入する_uniqueはfalse(): void {
    WP_Mock::userFunction( 'as_schedule_single_action' )->once()
        ->with( 5000, Enqueuer::HOOK_REFRESH, array( 'post_id'=>12,'platform'=>'rakuten-kobo' ), 'affilicard-rakuten', false, Enqueuer::PRIORITY_MANUAL )
        ->andReturn( 1 );
    ( new Enqueuer() )->rescheduleRefresh( 5000, 12, 'rakuten-kobo', 'rakuten' );
    $this->assertConditionsMet();
}
```

実装（Enqueuer）:

```php
public function rescheduleRefresh( int $whenSec, int $postId, string $platform, string $provider ): void {
    // unique=false: ハンドラ実行中の自分自身が in-progress として重複判定されるため、
    // unique=true だと backoff/throttle の再投入が必ずスキップされてしまう。単一ワーカー
    // （AS claim による single-flight）実行中の 1 回だけ呼ばれるので false でも増殖しない。
    as_schedule_single_action( $whenSec, self::HOOK_REFRESH, array( 'post_id' => $postId, 'platform' => $platform ), $this->group( $provider ), false, self::PRIORITY_MANUAL );
}
```

- [ ] **Step 2: Write failing test（RefreshHandler：pause）**

```php
public function test_handle_pause中は何もしない(): void {
    WP_Mock::userFunction( 'get_option' )->with( \Affilicard\Settings\GeneralSettings::OPTION_KEY, array() )
        ->andReturn( array( 'queue_paused' => true ) );
    // provider 解決・fetch は呼ばれない
    $refresher = \Mockery::mock( \Affilicard\Cron\ListingRefresher::class );
    $refresher->shouldNotReceive( 'refreshOne' );
    $handler = new RefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry() );
    $handler->handle( 12, 'rakuten-kobo' );
    $this->assertConditionsMet();
}
```

- [ ] **Step 3: Write failing test（throttle 未経過→再投入・fetch しない）**

```php
public function test_handle_throttle未経過なら再投入してfetchしない(): void {
    WP_Mock::userFunction( 'get_option' )->with( \Affilicard\Settings\GeneralSettings::OPTION_KEY, array() )->andReturn( array() );
    WP_Mock::userFunction( 'get_option' )->with( 'affilicard_ratelimit_rakuten', 0 )->andReturn( 999999999000 ); // 直近
    WP_Mock::userFunction( 'as_schedule_single_action' )->once(); // rescheduleRefresh
    $refresher = \Mockery::mock( \Affilicard\Cron\ListingRefresher::class );
    $refresher->shouldNotReceive( 'refreshOne' );
    // PlatformConfig::find('rakuten-kobo')->provider = 'rakuten' が引ける前提（実データ or スタブ）
    $handler = new RefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry() );
    $handler->handle( 12, 'rakuten-kobo' );
    $this->assertConditionsMet();
}
```

- [ ] **Step 4: Write failing test（acquire→fetch 成功）**

```php
public function test_handle_取得できればrefreshOneを呼ぶ(): void {
    WP_Mock::userFunction( 'get_option' )->with( \Affilicard\Settings\GeneralSettings::OPTION_KEY, array() )->andReturn( array() );
    WP_Mock::userFunction( 'get_option' )->with( 'affilicard_ratelimit_rakuten', 0 )->andReturn( 0 ); // 経過済
    WP_Mock::userFunction( 'update_option' )->with( 'affilicard_ratelimit_rakuten', \Mockery::type('int'), false )->andReturn( true );
    $refresher = \Mockery::mock( \Affilicard\Cron\ListingRefresher::class );
    $refresher->shouldReceive( 'refreshOne' )->once()->with( 12, 'rakuten-kobo' )->andReturn( true );
    $handler = new RefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry() );
    $handler->handle( 12, 'rakuten-kobo' );
    $this->assertConditionsMet();
}
```

`registry()` ヘルパ: `rakuten` provider（isAutomatic=true・minRequestIntervalMs=1100）を返す ProviderRegistry を Mockery で構築。`PlatformConfig::find('rakuten-kobo')` が provider `rakuten` を返すのは実 PlatformConfig（静的データ）で成立するなら実物を使い、なければ platform→provider 解決を引数化する。

- [ ] **Step 5: Run to verify fail**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Queue/RefreshHandlerTest.php`
Expected: FAIL

- [ ] **Step 6a: Implement ThrottledActionHandler（共通基底・DRY）**

pause→provider解決→throttle取得/再スケジュール→本処理→backoff の共通骨格をテンプレート化する（RefreshHandler／AutoCreateHandler で共用・pre-flight 合意）。`performWork`/`reschedule`/`providerCodeFor`/`attemptKey` はサブクラス実装。

```php
<?php
declare(strict_types=1);
namespace Affilicard\Queue;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Settings\GeneralSettings;

/**
 * throttle 付き AS ハンドラの共通骨格。
 * pause ゲート → provider 解決 → RateLimiter（未経過なら本処理せず後ろ倒し）→ performWork → 失敗は backoff 再投入。
 * サブクラスは providerCodeFor / performWork / reschedule / attemptKey を実装する。
 */
abstract class ThrottledActionHandler {

    protected const MAX_ATTEMPTS = 5;

    public function __construct(
        protected RateLimiter $limiter,
        protected ProviderRegistry $registry
    ) {}

    /** args から provider コードを解決（不明なら null）。 */
    abstract protected function providerCodeFor( array $args ): ?string;

    /** 本処理（fetch/create）。成功で true。 */
    abstract protected function performWork( array $args ): bool;

    /** 自分自身を $whenSec に再投入（unique=false）。 */
    abstract protected function reschedule( int $whenSec, array $args ): void;

    /** backoff 試行回数を記録する transient キー。 */
    abstract protected function attemptKey( array $args ): string;

    /**
     * @param array<string, mixed> $args
     */
    protected function run( array $args ): void {
        if ( GeneralSettings::isQueuePaused() ) {
            return;
        }
        $providerCode = $this->providerCodeFor( $args );
        if ( null === $providerCode ) {
            return;
        }
        $provider = $this->registry->get( $providerCode );
        if ( null === $provider || ! $provider->isAutomatic() ) {
            return;
        }

        $interval = $this->limiter->effectiveIntervalMs(
            $provider->minRequestIntervalMs(),
            GeneralSettings::throttleOverrideMs( $providerCode )
        );
        $nowMs   = (int) round( microtime( true ) * 1000 );
        $acquire = $this->limiter->tryAcquire( $providerCode, $interval, $nowMs );
        if ( ! $acquire['ok'] ) {
            $this->reschedule( (int) ceil( $acquire['next_ms'] / 1000 ), $args );
            return;
        }

        if ( $this->performWork( $args ) ) {
            delete_transient( $this->attemptKey( $args ) );
            return;
        }
        $this->backoff( $args );
    }

    /**
     * @param array<string, mixed> $args
     */
    private function backoff( array $args ): void {
        $key      = $this->attemptKey( $args );
        $attempts = (int) get_transient( $key ) + 1;
        if ( $attempts >= self::MAX_ATTEMPTS ) {
            delete_transient( $key );
            return; // 打ち切り（failed）。fetch_error は listing に記録済み・Fallback 列で可視化。
        }
        set_transient( $key, $attempts, DAY_IN_SECONDS );
        $delay = min( 3600, (int) pow( 2, $attempts ) * 60 ); // 指数バックオフ・上限 1h クランプ
        $this->reschedule( time() + $delay, $args );
    }
}
```

- [ ] **Step 6b: Implement RefreshHandler（基底を継承）**

```php
<?php
declare(strict_types=1);
namespace Affilicard\Queue;

use Affilicard\Cron\ListingRefresher;
use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\ProviderRegistry;

/**
 * affilicard_refresh_listing アクションのハンドラ。ThrottledActionHandler の骨格に
 * 「refreshOne で fetch＋反映」を差し込む。
 */
final class RefreshHandler extends ThrottledActionHandler {

    public function __construct(
        private Enqueuer $enqueuer,
        RateLimiter $limiter,
        private ListingRefresher $refresher,
        ProviderRegistry $registry
    ) {
        parent::__construct( $limiter, $registry );
    }

    public function handle( int $postId, string $platform ): void {
        $this->run( array( 'post_id' => $postId, 'platform' => $platform ) );
    }

    protected function providerCodeFor( array $args ): ?string {
        $definition = PlatformConfig::find( (string) $args['platform'] );
        return null !== $definition ? $definition->provider : null;
    }

    protected function performWork( array $args ): bool {
        return $this->refresher->refreshOne( (int) $args['post_id'], (string) $args['platform'] );
    }

    protected function reschedule( int $whenSec, array $args ): void {
        $providerCode = $this->providerCodeFor( $args );
        if ( null !== $providerCode ) {
            $this->enqueuer->rescheduleRefresh( $whenSec, (int) $args['post_id'], (string) $args['platform'], $providerCode );
        }
    }

    protected function attemptKey( array $args ): string {
        return 'affilicard_refresh_attempts_' . $args['post_id'] . '_' . $args['platform'];
    }
}
```

> テスト（Step 2-4）は `RefreshHandler::handle( 12, 'rakuten-kobo' )` を対象に、pause/throttle 未経過/acquire→refreshOne の 3 挙動を検証する（基底の run 経由で動く）。基底単体のテストは不要（サブクラス経由で全分岐を通す）。

> **Retry-After について（要件2の範囲注記）**: 現行 `ProviderInterface::fetch()` は失敗を `null` で返し `Retry-After` ヘッダを surface しない。RateLimiter が per-provider バーストを根本的に抑えるため 429 自体が稀になる前提で、本タスクは**上限 1h クランプ付き指数バックオフ**を基本とする。`Retry-After` の厳密尊重は `fetch()` が `{ retry_after }` を返す拡張が前提の**follow-up**（別タスク）とし、その値を `min(clamp, retry_after)` で `$delay` に反映する。この簡略化は spec §9-5 の「上限クランプ」を満たしつつ、`Retry-After 尊重`（要件2）を段階導入とする明示判断。

- [ ] **Step 7: Run to verify pass**

Run: 同上
Expected: PASS（pause / throttle 未経過 / acquire→fetch）

- [ ] **Step 8: PHPCS ＋ Commit**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpcs src/Queue/ThrottledActionHandler.php src/Queue/RefreshHandler.php src/Queue/Enqueuer.php
git add src/Queue/ThrottledActionHandler.php src/Queue/RefreshHandler.php src/Queue/Enqueuer.php tests/Unit/Queue/
git commit -m "feat: RefreshHandler＋共通基底ThrottledActionHandlerを追加（pause/throttle/fetch/backoff再投入）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: AutoCreateHandler（pause→throttle→ProductAutoCreator）

**Files:**

- Create: `src/Queue/AutoCreateHandler.php`
- Test: `tests/Unit/Queue/AutoCreateHandlerTest.php`

**Interfaces:**

- Consumes: `GeneralSettings`、`RateLimiter`、`PlatformConfig::find()`、`ProviderRegistry`、`ProductAutoCreator::create()`、`Enqueuer`（reschedule 用に `rescheduleAutoCreate()` 追加）。
- Produces:
  - `Enqueuer::rescheduleAutoCreate( int $whenSec, string $platform, string $provider, string $externalId ): void`（`rescheduleRefresh` 同様 **`$unique=false`**：実行中の自分自身が in-progress 重複となるため）。
  - `AutoCreateHandler::__construct( Enqueuer $enqueuer, RateLimiter $limiter, ProductAutoCreator $creator, ProviderRegistry $registry )`
  - `AutoCreateHandler::handle( string $platform, string $externalId ): void`。

- [ ] **Step 1: Enqueuer::rescheduleAutoCreate（TDD・`$unique=false`）**

`EnqueuerTest` に1テスト＋実装:

```php
public function rescheduleAutoCreate( int $whenSec, string $platform, string $provider, string $externalId ): void {
    as_schedule_single_action( $whenSec, self::HOOK_AUTOCREATE, array( 'platform' => $platform, 'external_id' => $externalId ), $this->group( $provider ), false, self::PRIORITY_FORCE );
}
```

- [ ] **Step 2: Write failing tests**（pause で no-op／throttle 未経過で `as_schedule_single_action` 再投入・`creator->create` 不呼び出し／acquire で `creator->create($platform,$externalId)` 呼び出し）。Task 8 と同じ throttle モックパターン。
- [ ] **Step 3: Run to verify fail** → FAIL
- [ ] **Step 4: Implement（ThrottledActionHandler を継承）**

```php
<?php
declare(strict_types=1);
namespace Affilicard\Queue;

use Affilicard\AutoCreate\ProductAutoCreator;
use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\ProviderRegistry;

/**
 * affilicard_autocreate アクションのハンドラ。ThrottledActionHandler の骨格に
 * 「ProductAutoCreator::create で商品生成」を差し込む（AutoCreate の AS 非同期化・§3-6）。
 */
final class AutoCreateHandler extends ThrottledActionHandler {

    public function __construct(
        private Enqueuer $enqueuer,
        RateLimiter $limiter,
        private ProductAutoCreator $creator,
        ProviderRegistry $registry
    ) {
        parent::__construct( $limiter, $registry );
    }

    public function handle( string $platform, string $externalId ): void {
        $this->run( array( 'platform' => $platform, 'external_id' => $externalId ) );
    }

    protected function providerCodeFor( array $args ): ?string {
        $definition = PlatformConfig::find( (string) $args['platform'] );
        return null !== $definition ? $definition->provider : null;
    }

    protected function performWork( array $args ): bool {
        return null !== $this->creator->create( (string) $args['platform'], (string) $args['external_id'] );
    }

    protected function reschedule( int $whenSec, array $args ): void {
        $providerCode = $this->providerCodeFor( $args );
        if ( null !== $providerCode ) {
            $this->enqueuer->rescheduleAutoCreate( $whenSec, (string) $args['platform'], $providerCode, (string) $args['external_id'] );
        }
    }

    protected function attemptKey( array $args ): string {
        return 'affilicard_autocreate_attempts_' . $args['platform'] . '_' . $args['external_id'];
    }
}
```

- [ ] **Step 5: Run to verify pass** → PASS
- [ ] **Step 6: PHPCS ＋ Commit**

```bash
git add src/Queue/AutoCreateHandler.php src/Queue/Enqueuer.php tests/Unit/Queue/AutoCreateHandlerTest.php
git commit -m "feat: AutoCreateHandler を追加（AutoCreateのAS非同期化・throttle経由）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: 掃引（QueueMaintenance::sweep）＋ RefreshScheduler ハンドラ差し替え

**Files:**

- Create: `src/Queue/QueueMaintenance.php`
- Modify: `src/Cron/RefreshScheduler.php`（`register()` の handler を掃引へ）／`src/Plugin.php`（`affilicard_refresh_all` に QueueMaintenance::sweep を配線）
- Test: `tests/Unit/Queue/QueueMaintenanceTest.php`

**Interfaces:**

- Consumes: `ProductRepositoryInterface`（全公開商品取得）、`PlatformConfig::find()`、`Enqueuer::enqueueSweep()`、`GeneralSettings::queueDepthCap()`。
- Produces: `QueueMaintenance::__construct( ProductRepositoryInterface $repo, Enqueuer $enqueuer )`／`QueueMaintenance::sweep(): void`（全公開商品の auto listing を走査し、`enqueueSweep`（stale のみ・depth cap 内）を呼ぶ）。

- [ ] **Step 1: Write failing test**

```php
public function test_sweep_公開商品のstale_listingをenqueueSweepする(): void {
    // get_posts で ids=[12] → repository->find(12) が rakuten-kobo listing(stale) を返す
    // → enqueueSweep が1回呼ばれる。fresh のみの商品は呼ばれない。
    // Enqueuer をモックし enqueueSweep の呼び出し回数を検証。
}
```

（`get_posts` を WP_Mock で publish ids 返却にスタブ。作品名は架空。）

- [ ] **Step 2: Run to verify fail** → FAIL
- [ ] **Step 3: Implement QueueMaintenance::sweep**

```php
public function sweep(): void {
    $ids = get_posts( array(
        'post_type' => \Affilicard\PostType\ProductPostType::POST_TYPE,
        'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true,
    ) );
    if ( ! is_array( $ids ) ) { return; }
    $now = time();
    foreach ( $ids as $id ) {
        $product = $this->repository->find( (int) $id );
        if ( null === $product || ! is_array( $product['listings'] ?? null ) ) { continue; }
        foreach ( $product['listings'] as $listing ) {
            if ( ! is_array( $listing ) ) { continue; }
            $platform = (string) ( $listing['platform'] ?? '' );
            $def      = PlatformConfig::find( $platform );
            if ( null === $def ) { continue; }
            // auto listing のみ（update_mode=auto・enabled・auto_update）
            $mode = (string) ( $listing['update_mode'] ?? 'auto' );
            $enabled = ! isset( $listing['enabled'] ) || (bool) $listing['enabled'];
            $auto = ! isset( $listing['auto_update'] ) || (bool) $listing['auto_update'];
            if ( 'auto' !== $mode || ! $enabled || ! $auto ) { continue; }
            $this->enqueuer->enqueueSweep( (int) $id, $platform, $def->provider, $def, $listing, $now );
        }
    }
}
```

- [ ] **Step 4: Run to verify pass** → PASS
- [ ] **Step 5: RefreshScheduler 配線を掃引へ**

`src/Plugin.php` の `affilicard_refresh_all` ハンドラを、旧 `ListingRefresher::run` 直呼びから `QueueMaintenance::sweep` に差し替え（`RefreshScheduler::register( callable )` はそのまま利用）。手動確認: `grep -n 'refresh_all\|QueueMaintenance' src/Plugin.php`。

- [ ] **Step 6: PHPCS ＋ Commit**

```bash
git add src/Queue/QueueMaintenance.php src/Cron/RefreshScheduler.php src/Plugin.php tests/Unit/Queue/QueueMaintenanceTest.php
git commit -m "feat: 掃引を QueueMaintenance::sweep 化（鮮度スキップでstaleのみenqueue）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 11: PublishTrigger（公開/更新・future→publish で記事内商品を force 投入）

**Files:**

- Create: `src/Queue/PublishTrigger.php`
- Test: `tests/Unit/Queue/PublishTriggerTest.php`

**Interfaces:**

- Consumes: `parse_blocks()`、`ProductRepositoryInterface`（`find`/`findBySlug`/`findByExternalId`）、`PlatformConfig::find()`、`Enqueuer::enqueueForced()`。
- Produces:
  - `PublishTrigger::__construct( ProductRepositoryInterface $repo, Enqueuer $enqueuer )`
  - `PublishTrigger::onTransition( string $newStatus, string $oldStatus, \WP_Post $post ): void`（`transition_post_status`・`publish` 昇格時に `syncPost`）。
  - `PublishTrigger::onUpdated( int $postId, \WP_Post $after, \WP_Post $before ): void`（`post_updated`・publish 済み再保存時に `syncPost`）。
  - `PublishTrigger::syncPost( \WP_Post $post ): void`（private 共通：autosave/revision/auto-draft/非 publish はスキップ→本文 `parse_blocks`→`affilicard/product-card` を抽出→商品解決（autoCreate なし）→解決商品の auto listing 全部を `enqueueForced`）。
  - `PublishTrigger::resolveProductIds( string $content ): array`（テスト容易化のため public 抽出：ブロック属性→post_id のリスト）。

- [ ] **Step 1: Write failing test（resolveProductIds）**

```php
public function test_resolveProductIds_productId属性を抽出する(): void {
    WP_Mock::userFunction( 'parse_blocks' )->andReturn( array(
        array( 'blockName' => 'affilicard/product-card', 'attrs' => array( 'productId' => 12 ), 'innerBlocks'=>array() ),
        array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks'=>array() ),
    ) );
    // repository は productId=12 をそのまま find で返す
    $ids = ( new PublishTrigger( $this->repo(), new Enqueuer() ) )->resolveProductIds( '<!-- wp:affilicard/product-card ... -->' );
    $this->assertSame( array( 12 ), $ids );
}
```

- [ ] **Step 2: Run to verify fail** → FAIL
- [ ] **Step 3: Implement**（`parse_blocks` を再帰走査し `affilicard/product-card` を集め、`productId`／`slug`→`findBySlug`／`externalId`+`platform`→`findByExternalId` で post_id 解決。`onSave` は `wp_is_post_autosave`/`wp_is_post_revision`/status ガード後に解決商品の auto listing を `enqueueForced`）。
- [ ] **Step 4: Run to verify pass** → PASS
- [ ] **Step 5: Commit**

```bash
git add src/Queue/PublishTrigger.php tests/Unit/Queue/PublishTriggerTest.php
git commit -m "feat: PublishTrigger を追加（公開/更新時に記事内商品をforce投入・parse_blocks解決）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 12: Block の AutoCreate を enqueue 化

**Files:**

- Modify: `src/Block/Block.php`（`autoCreate()` を inline 生成から enqueue へ）
- Test: `tests/Unit/Block/BlockTest.php`（既存に追記）

**Interfaces:**

- Consumes: `Enqueuer::enqueueAutoCreate()`、`PlatformConfig::find()`、transient ガード（既存の `affilicard_autocreate_{platform}_{externalId}`）。
- 変更: `Block::resolveProduct()` が未登録（`findByExternalId` が null）のとき、inline `autoCreator->create()` の代わりに `enqueueAutoCreate` を1回投入し `null` を返す（カードは今回描画されない＝§3-6）。

- [ ] **Step 1: Write failing test**

```php
public function test_render_未登録ブロックはautoCreateをenqueueしてカードを出さない(): void {
    // findByExternalId=null → get_transient(lock)=false → enqueueAutoCreate 1回 ＆ set_transient → render は ''。
    WP_Mock::userFunction( 'get_transient' )->andReturn( false );
    WP_Mock::userFunction( 'set_transient' )->once();
    WP_Mock::userFunction( 'as_schedule_single_action' )->once(); // enqueueAutoCreate
    // ...
    $this->assertSame( '', $block->render( array( 'externalId' => 'X123', 'platform' => 'rakuten-kobo' ) ) );
}
```

- [ ] **Step 2: Run to verify fail** → FAIL
- [ ] **Step 3: Implement**（`Block::autoCreate()` を書き換え：transient ロック未取得なら set＋`enqueueAutoCreate( $platform, $def->provider, $externalId )` を呼び `null` を返す。`ProductAutoCreator` の inline 呼び出しは削除。Block のコンストラクタ依存を `ProductAutoCreator` から `Enqueuer` に差し替え、`register_hook()` の生成箇所も更新）。
- [ ] **Step 4: Run to verify pass** → PASS
- [ ] **Step 5: PHPCS ＋ Commit**

```bash
git add src/Block/Block.php tests/Unit/Block/BlockTest.php
git commit -m "feat: Block の AutoCreate を同期生成からAS enqueueへ（描画の同期API除去）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 13: Plugin 配線（AS アクション・トリガーの登録）

**Files:**

- Modify: `src/Plugin.php`
- Test: `tests/Unit/PluginQueueWiringTest.php`（配線の add_action を検証／既存 Plugin テストがあれば追記）

**Interfaces:**

- Produces: Plugin boot で以下を配線：
  - `add_action( Enqueuer::HOOK_REFRESH, [ RefreshHandler, 'handle' ], 10, 2 )`（args post_id, platform）
  - `add_action( Enqueuer::HOOK_AUTOCREATE, [ AutoCreateHandler, 'handle' ], 10, 2 )`（args platform, external_id）
  - `add_action( 'transition_post_status', [ PublishTrigger, 'onTransition' ], 10, 3 )` ＋ `add_action( 'post_updated', ... )`（publish 済み再保存）
  - `affilicard_refresh_all` → `QueueMaintenance::sweep`（Task 10）
  - `ActionSchedulerLoader::register()`（Task 2）

- [ ] **Step 1: Write failing test**（`WP_Mock::expectActionAdded` で上記 hook 配線を検証する `Plugin::registerQueue()` を新設）。
- [ ] **Step 2: Run to verify fail** → FAIL
- [ ] **Step 3: Implement**（Plugin に `registerQueue()` を追加し boot から呼ぶ。DI 生成は既存 `buildProviderRegistry()`／`ProductRepository` を利用。AS アクションのハンドラは args 配列をキー展開して呼ぶ薄い closure でよい）。
- [ ] **Step 4: Run to verify pass** → PASS
- [ ] **Step 5: Commit**

```bash
git add src/Plugin.php tests/Unit/PluginQueueWiringTest.php
git commit -m "feat: キューのハンドラ/トリガーをPluginに配線

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Phase P4: 管理UI（薄型・AS 再利用最大化）

### Task 14: QueueStats（provider 別集計・深さ）

**Files:**

- Create: `src/Queue/QueueStats.php`
- Test: `tests/Unit/Queue/QueueStatsTest.php`

**Interfaces:**

- Consumes: `as_get_scheduled_actions( array $query, string $return )`（テストでモック）、既知 provider リスト（`ProviderRegistry` or 固定）。
- Produces:
  - `QueueStats::__construct( array $providers )`（provider コード配列）
  - `QueueStats::forProvider( string $provider ): array{ pending:int, in_progress:int, failed:int }`
  - `QueueStats::summary(): array<string, array{pending:int,in_progress:int,failed:int}>`（provider→件数）
  - `QueueStats::depth(): int`（全 provider の pending 合算）

- [ ] **Step 1: Write failing test**

```php
public function test_forProvider_status別件数を返す(): void {
    WP_Mock::userFunction( 'as_get_scheduled_actions' )
        ->with( array( 'group' => 'affilicard-rakuten', 'status' => 'pending', 'per_page' => -1 ), 'ids' )
        ->andReturn( array( 1, 2, 3 ) );
    WP_Mock::userFunction( 'as_get_scheduled_actions' )
        ->with( array( 'group' => 'affilicard-rakuten', 'status' => 'in-progress', 'per_page' => -1 ), 'ids' )
        ->andReturn( array( 4 ) );
    WP_Mock::userFunction( 'as_get_scheduled_actions' )
        ->with( array( 'group' => 'affilicard-rakuten', 'status' => 'failed', 'per_page' => -1 ), 'ids' )
        ->andReturn( array() );

    $out = ( new QueueStats( array( 'rakuten' ) ) )->forProvider( 'rakuten' );
    $this->assertSame( array( 'pending' => 3, 'in_progress' => 1, 'failed' => 0 ), $out );
}
```

- [ ] **Step 2: Run to verify fail** → FAIL
- [ ] **Step 3: Implement**（各 status を `as_get_scheduled_actions(['group'=>"affilicard-$p",'status'=>$s,'per_page'=>-1],'ids')` の count で数える。`in-progress`/`failed` の AS status 文字列を使う。`summary()`/`depth()` は forProvider 合算。）
- [ ] **Step 4: Run to verify pass** → PASS
- [ ] **Step 5: Commit**

```bash
git add src/Queue/QueueStats.php tests/Unit/Queue/QueueStatsTest.php
git commit -m "feat: QueueStats を追加（provider別 pending/in-progress/failed と深さ集計）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 15: QueueController REST（stats/clear/clearFailed/retryFailed/cancelPending/pause）

**Files:**

- Create: `src/Rest/QueueController.php`
- Modify: REST ルート登録箇所（既存の REST 登録に合流）
- Test: `tests/Unit/Rest/QueueControllerTest.php`

**Interfaces:**

- Consumes: `QueueStats`、`GeneralSettings::update()`/`isQueuePaused()`、AS `as_unschedule_all_actions`、既知 provider group。
- Produces（`register_rest_route( $ns, '/refresh-queue', ... )`・全て `permission_callback => canManageOptions`）:
  - `GET /refresh-queue`（stats：summary＋depth＋paused）
  - `POST /refresh-queue/pause`（body `{paused: bool}`→ `GeneralSettings::update`）
  - `DELETE /refresh-queue`（全削除：全 `affilicard-*` group を `as_unschedule_all_actions`）
  - `DELETE /refresh-queue/failed`（failed 削除）
  - `POST /refresh-queue/retry-failed`（failed を再 enqueue）
  - `POST /refresh-queue/cancel-pending`（pending キャンセル）

- [ ] **Step 1: Write failing test（GET stats・permission）**

```php
public function test_canManageOptions_manage_optionsを要求する(): void {
    WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
    $this->assertTrue( ( new QueueController( $this->stats() ) )->canManageOptions() );
}

public function test_stats_summaryとpausedを返す(): void {
    WP_Mock::userFunction( 'get_option' )->with( \Affilicard\Settings\GeneralSettings::OPTION_KEY, array() )->andReturn( array( 'queue_paused' => false ) );
    // QueueStats をモックし summary()/depth() を固定
    $res = ( new QueueController( $this->statsMock() ) )->stats( $this->request() );
    $data = $res->get_data();
    $this->assertArrayHasKey( 'summary', $data );
    $this->assertArrayHasKey( 'paused', $data );
}
```

- [ ] **Step 2: Run to verify fail** → FAIL
- [ ] **Step 3: Implement QueueController**（既存 `CredentialsController`/`RefreshController` の `registerRoutes()`＋`canManageOptions()` パターンを踏襲。破壊操作は POST/DELETE。`last_error` 等 provider 由来文字列を出す場合は `wp_strip_all_tags()` ＋ `esc_html` 相当でサニタイズしてから返す＝§9-3）。
- [ ] **Step 4: Run to verify pass** → PASS
- [ ] **Step 5: ルート登録配線 ＋ PHPCS ＋ Commit**

```bash
git add src/Rest/QueueController.php src/Plugin.php tests/Unit/Rest/QueueControllerTest.php
git commit -m "feat: QueueController REST を追加（stats/clear/retry/cancel/pause・manage_options）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 16: React 管理パネル（QueuePanel）＋ api

**Files:**

- Create: `src/Admin/api/queue.js`、`src/Admin/components/QueuePanel.jsx`
- Modify: 設定画面のパネル束ね（既存 Admin エントリに QueuePanel を追加）
- Test: `tests/js/queue.test.js`

**Interfaces:**

- Consumes: `@wordpress/api-fetch`（`/affilicard/v1/refresh-queue`）、`@wordpress/element`、`@wordpress/components`。
- Produces: `fetchQueueStats()`/`setPaused(bool)`/`clearQueue()`/`retryFailed()`/`cancelPending()` （`src/Admin/api/queue.js`）と、それらを使う `QueuePanel`（集計サマリ・pause トグル・throttle 上書き入力・保持期間入力・一括操作ボタン・「Scheduled Actions を開く」リンク `tools.php?page=action-scheduler&s=affilicard`）。

- [ ] **Step 1: Write failing JS test**

```js
import { fetchQueueStats } from '../../src/Admin/api/queue';
jest.mock( '@wordpress/api-fetch', () => jest.fn() );
import apiFetch from '@wordpress/api-fetch';

test( 'fetchQueueStats calls the refresh-queue endpoint', async () => {
    apiFetch.mockResolvedValue( { summary: {}, depth: 0, paused: false } );
    const out = await fetchQueueStats();
    expect( apiFetch ).toHaveBeenCalledWith( { path: '/affilicard/v1/refresh-queue' } );
    expect( out.paused ).toBe( false );
} );
```

- [ ] **Step 2: Run to verify fail**

Run: `npx wp-scripts test-unit-js tests/js/queue.test.js`
Expected: FAIL（module 未作成）

- [ ] **Step 3: Implement api/queue.js ＋ QueuePanel.jsx**（api は `apiFetch({ path, method, data })` ラッパ。QueuePanel は `useState`/`useEffect` で stats 取得・各操作ボタン・pause `ToggleControl`・throttle 数値入力。`dangerouslySetInnerHTML` は使わない＝§9-3）。
- [ ] **Step 4: Run to verify pass**

Run: `npx wp-scripts test-unit-js tests/js/queue.test.js`
Expected: PASS

- [ ] **Step 5: JS lint ＋ build ＋ Commit**

```bash
npm run lint:js
npm run build
git add src/Admin/api/queue.js src/Admin/components/QueuePanel.jsx tests/js/queue.test.js build/
git commit -m "feat: 更新キュー管理パネル(React)を追加（集計/pause/throttle/一括操作/Scheduled Actionsリンク）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 17: E2E（wp-env・キューパネルと投入）

**Files:**

- Create: `tests/e2e/refresh-queue.spec.ts`

- [ ] **Step 1: Write E2E**（wp-env で：管理画面にキューパネルが表示される／pause トグルが保存される／手動一括更新でジョブが投入され Scheduled Actions に `affilicard-*` group のアクションが現れる、を Playwright で検証。実 API はモック不要＝スケジュール投入のみ確認）。
- [ ] **Step 2: Run**

Run: `npm run wp-env start && npm run test:e2e -- refresh-queue.spec.ts`
Expected: PASS（緑）

- [ ] **Step 3: Commit**

```bash
git add tests/e2e/refresh-queue.spec.ts
git commit -m "test: 更新キューのE2E（パネル表示/pause保存/投入→Scheduled Actions）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Phase P5: バックプレッシャ ＆ 保守 ＆ 失敗可視化

### Task 18: Fallback 列にキュー状態を連携

**Files:**

- Modify: `src/PostType/ProductListColumns.php`
- Test: `tests/Unit/PostType/ProductListColumnsTest.php`（既存に追記）

**Interfaces:**

- Consumes: AS `as_next_scheduled_action( hook, args, group )` or `as_has_scheduled_action`、listing の `fetch_error`。
- 変更: Fallback 列の警告 tooltip に「キュー待ち（pending）」or「失敗理由（`fetch_error` を `wp_strip_all_tags` ＋ `esc_attr`）」を付記し、キューパネル/Scheduled Actions へのリンクを付ける。

- [ ] **Step 1: Write failing test**（pending なジョブがある商品は tooltip に「更新待ち」を含む／`fetch_error` があれば理由をエスケープして含む）。
- [ ] **Step 2: Run to verify fail** → FAIL
- [ ] **Step 3: Implement**（列描画で `as_has_scheduled_action` を引き、状態文字列を `esc_attr` して title 属性に埋める。`fetch_error` は `esc_html( wp_strip_all_tags( $err ) )`）。
- [ ] **Step 4: Run to verify pass** → PASS
- [ ] **Step 5: Commit**

```bash
git add src/PostType/ProductListColumns.php tests/Unit/PostType/ProductListColumnsTest.php
git commit -m "feat: Fallback列にキュー状態(待ち/失敗理由)を連携（XSS二重防御）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 19: AS retention 設定 ＋ reconcile（掃引 cron 同居）

**Files:**

- Modify: `src/Queue/QueueMaintenance.php`（retention フィルタ登録＋reconcile）
- Modify: `src/Plugin.php`（retention フィルタを配線）
- Test: `tests/Unit/Queue/QueueMaintenanceTest.php`（追記）

**Interfaces:**

- Produces:
  - `QueueMaintenance::registerRetentionFilters(): void`（`action_scheduler_retention_period`＝`GeneralSettings::retentionDoneHours()*3600`、`action_scheduler_retention_period_for_failed`＝`retentionFailedDays()*DAY_IN_SECONDS` を返すフィルタ）。
  - reconcile は `action_scheduler_ensure_recurring_actions` 依存 or sweep 内で失敗回収（MVP は sweep が stale を再投入するため実質回収済み）。

- [ ] **Step 1: Write failing test**（retention フィルタが GeneralSettings 値を秒に変換して返す）。
- [ ] **Step 2: Run to verify fail** → FAIL
- [ ] **Step 3: Implement ＋ Plugin 配線**
- [ ] **Step 4: Run to verify pass** → PASS
- [ ] **Step 5: Commit**

```bash
git add src/Queue/QueueMaintenance.php src/Plugin.php tests/Unit/Queue/QueueMaintenanceTest.php
git commit -m "feat: AS retention を GeneralSettings 連動に（done時間/failed日数）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Phase P6: 運用ドキュメント ＆ リリース

### Task 20: 運用ドキュメント（サーバ実 cron ＋ AS ランナー）

**Files:**

- Create/Modify: `docs/` の運用手順（サーバ実 cron 推奨・`DISABLE_WP_CRON`＋OS cron＋`wp action-scheduler run`）

- [ ] **Step 1: ドキュメント記載**（WP-Cron は擬似 cron のため、青天井運用は `DISABLE_WP_CRON=true` ＋ OS cron で `wp cron event run --due-now` もしくは `wp action-scheduler run` を定期実行。Scheduled Actions（Tools→Scheduled Actions）でキュー確認。pause/throttle/retention の運用注記）。
- [ ] **Step 2: Commit**

```bash
git add docs/
git commit -m "docs: 更新キューの運用手順(サーバ実cron＋wp action-scheduler run)を追加

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 21: バージョン v2.4.0（3箇所同期）＋ CHANGELOG

**Files:**

- Modify: `affilicard.php`（`Version:` ヘッダ ＋ `AFFILICARD_VERSION` 定数）、`package.json`（`version`）、`CHANGELOG.md`

**Interfaces:**

- Produces: 3箇所すべて `2.4.0`。PUC はタグのツリーの `affilicard.php` Version ヘッダを読むため**必ず同期**（memory `project_affilicard_puc_version_header`）。

- [ ] **Step 1: 3箇所を 2.4.0 に更新**（`affilicard.php` の `* Version: 2.4.0` と `define( 'AFFILICARD_VERSION', '2.4.0' )`、`package.json` の `"version": "2.4.0"`）。
- [ ] **Step 2: CHANGELOG に v2.4.0 追記**（価格更新の非同期キュー化＋レート制限耐性＋キュー管理UI＋AutoCreate非同期化）。
- [ ] **Step 3: 同期確認**

Run: `grep -rn "2.4.0" affilicard.php package.json`
Expected: Version ヘッダ・定数・package.json の3箇所すべてヒット。

- [ ] **Step 4: Commit**

```bash
git add affilicard.php package.json CHANGELOG.md
git commit -m "chore: v2.4.0（価格更新の非同期キュー化・Version3箇所同期）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 22: 全テスト・lint・phpcs・build ＋ 本番 E2E ＋ 最終確認

**Files:** （検証のみ・必要なら微修正）

- [ ] **Step 1: PHP 全テスト**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit`
Expected: 全 PASS

- [ ] **Step 2: PHPCS**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpcs`
Expected: 0 errors（あれば `phpcbf`→再確認）

- [ ] **Step 3: JS テスト・lint・build**

Run: `npx wp-scripts test-unit-js && npm run lint:js && npm run build`
Expected: 全 PASS

- [ ] **Step 4: E2E（wp-env）**

Run: `npm run test:e2e`
Expected: 緑

- [ ] **Step 5: push 前 CodeRabbit CLI**

Run: `coderabbit review --base main`
対応: 指摘を反映（`--plain` は 0.7.0 で廃止＝デフォルト）。

- [ ] **Step 6: 本番 E2E（実 draft 投入→REST/レンダリング確認→throwaway force delete）**

実 API 再現は e-comi `.env`（`RAKUTEN_APP_ID`/`RAKUTEN_ACCESS_KEY`/`RAKUTEN_AFFILIATE_ID`）＋ `Origin: https://e-comi.pitolick.com` ＋ `node:https`。楽天投入→AS 処理→`last_verified_at` 更新→カード価格表示を確認。

- [ ] **Step 7: STATUS 更新 ＋ PR（auto-merge しない）**

`docs/STATUS.md` を「v2.4.0 完了・本番E2E済」まで書き切り、PR を作成（Playground/pr-preview でユーザー視覚確認 → マージ → v2.4.0 タグ → release.yml → Release）。

```bash
git add docs/STATUS.md
git commit -m "docs: STATUS を v2.4.0 完了へ更新

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review（spec との突き合わせ）

**1. Spec coverage（spec §→task）:**

- §3-2#1 Enqueuer（unique/priority/force unschedule/depth/jitter）→ Task 3, 8, 9
- §3-2#2 ハンドラ（pause/throttle/fetch/backoff）→ Task 8, 9
- §3-2#3 RateLimiter（再スケジュール式・実効間隔）→ Task 5, 8
- §3-2#4 ProviderInterface::minRequestIntervalMs → Task 4
- §3-2#5 管理UI（AS Scheduled Actions＋薄型自前）→ Task 15, 16
- §3-2#6 掃引/reconcile → Task 10, 19
- §3-6 AutoCreate 非同期化 → Task 9, 12
- §8-1 公開/更新トリガー → Task 11
- §8-2 future→publish → Task 11/13（transition_post_status）
- §8-3 掃引（主役）→ Task 10
- 鮮度スキップ（要件7）→ Task 1, 3(enqueueSweep), 10
- 管理UI 4操作（要件5）→ Task 15
- retention（§3-0#11）→ Task 19
- pause（§10-2）→ Task 6, 8, 15
- 失敗可視化（§3-0#9）→ Task 18
- 運用/CLI（§3-0#10）→ Task 20
- AS bundle（§4）→ Task 2
- 脆弱性（§9：REST認可/SQL=AS委譲/XSS/未認証投入=AutoCreateのみ/原子性/Retry-Afterクランプ）→ Task 8(backoff clamp), 15(認可/XSS), 5(原子性注記)
- v2.4.0 3箇所同期（§6）→ Task 21

**2. Placeholder scan:** 各 code step は実コードを提示。UI/E2E は代表実装コードを提示（プレースホルダ語は不使用）。

**3. Type consistency:** `Enqueuer` のメソッド名（`enqueueForced`/`enqueueManual`/`enqueueSweep`/`enqueueAutoCreate`/`rescheduleRefresh`/`rescheduleAutoCreate`/`queueDepth`/`group`）、`RateLimiter::tryAcquire(): array{ok,next_ms}`、`ListingRefresher::refreshOne(): bool`、`GeneralSettings` アクセサ名は全タスクで一致。AS group は全タスクで `affilicard-{provider}`、hook は `affilicard_refresh_listing`/`affilicard_autocreate` で一致。

---

## Execution Handoff

Plan complete。実装は次のいずれかで進める（brainstorming→writing-plans の後続）：

1. **Subagent-Driven（推奨）** — タスク毎に fresh subagent を dispatch し、実装→2段レビュー→ledger。`superpowers:subagent-driven-development`。
2. **Inline Execution** — 本セッションで `superpowers:executing-plans` によりチェックポイント付きバッチ実行。
