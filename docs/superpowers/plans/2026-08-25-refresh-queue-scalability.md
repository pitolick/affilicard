# 価格同期スケーラビリティ 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 価格同期の空振り 90% を潰して実効スループットを一桁上げ、記事の掲載日を基礎とした棚卸しで更新需要そのものを抑える。

**Architecture:** 正常系は account 単位のバッチジョブ（1 ジョブが複数 listing をジョブ内でレート間隔を守りながら順次 fetch）で処理し、失敗した listing だけを既存の per-listing ジョブへ落とす。sweep はカーソル方式で分割し、商品数に依存しない実行時間にする。需要側は記事の公開・更新時に記録する「最終掲載日」から一定期間経過した商品を更新対象から外す。

**Tech Stack:** PHP 8.2 / WordPress / Action Scheduler（bundle）/ PHPUnit + WP_Mock + Mockery / phpcs（WordPress Coding Standards）

**Spec:** [docs/superpowers/specs/2026-08-25-refresh-queue-scalability-design.md](../specs/2026-08-25-refresh-queue-scalability-design.md)

## Global Constraints

- **バージョン**: v3.4.0 → **v3.5.0**（MINOR・後方互換）。`affilicard.php` の Version ヘッダを含む 3 箇所を同期する（PUC がタグのツリーのヘッダを読むため、コミット漏れは自動更新の停止に直結する）
- **テスト実行（PHP）**: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit`。ローカル Mac は PHP 非導入（Docker 専用）
- **PHPCS**: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpcs`。違反の自動修正は `vendor/bin/phpcbf`
- **新機能・バグ修正には必ず `tests/Unit/` にユニットテストを追加する**（affilicard CLAUDE.md）
- **外部 API（DMM / 楽天 / Amazon / WP REST）はすべてモックする**
- **特定サイト固有のコードを混入させない**（汎用性を損なう変更は却下）
- **コミットメッセージは日本語 + Conventional Commits prefix**
- 時刻はすべて **UTC epoch 秒**で比較する（`gmdate('c')` で記録する既存方針に揃える）
- 既存の `affilicard_refresh_listing`（per-listing）ハンドラの挙動は**変更しない**。異常系の受け皿として温存する

---

## File Structure

**新規作成**

| ファイル | 責務 |
| --- | --- |
| `src/Queue/BatchRefreshHandler.php` | バッチジョブのハンドラ。ジョブ期限管理・レート待ち・失敗時の per-listing フォールバック |
| `src/Queue/JobDeadline.php` | ジョブ期限の計算と残り時間判定（純粋なロジックとして切り出しテスト可能にする） |
| `src/Queue/SweepCursor.php` | sweep のカーソル永続化（option の読み書きとクリア） |
| `src/Upgrade/PluginUpgrade.php` | プラグインのバージョン移行ルーチン（**新規**。既存に相当する仕組みが無い） |
| `src/Stocktake/StocktakePolicy.php` | 棚卸し判定（最終掲載日の正規化・基準日フォールバック・期間判定） |
| `src/Stocktake/PublicationDate.php` | 最終掲載日 meta の読み書き（単調増加の保証） |

**変更**

| ファイル | 変更内容 |
| --- | --- |
| `src/Queue/Enqueuer.php` | `enqueueBatch()` 追加・`HOOK_REFRESH_BATCH` 定数・`as_schedule_single_action()` 戻り値の扱い修正 |
| `src/Queue/QueueMaintenance.php` | カーソル分割・バッチ投入への切替・棚卸し判定の適用・完走時刻の記録 |
| `src/Queue/PublishTrigger.php` | 最終掲載日の記録を追加 |
| `src/Settings/GeneralSettings.php` | `stocktake_enabled` / `stocktake_days` を追加（クランプ含む） |
| `src/PostType/ProductPostType.php` | 最終掲載日 meta の定数と `register_post_meta`（read-only） |
| `src/PostType/ProductListColumns.php` | 「最終掲載日」列の追加とソート対応 |
| `src/Plugin.php` | バッチハンドラ・sweep ジョブ・アップグレードルーチンの配線 |
| `docs/operations-refresh-queue.md` | サーバー cron の判断基準と落とし穴を追記 |

---

## Task 1: バッチジョブの投入（Enqueuer）

**Files:**
- Modify: `src/Queue/Enqueuer.php`
- Test: `tests/Unit/Queue/EnqueuerTest.php`

**Interfaces:**
- Consumes: 既存の `Enqueuer::group()` / `PRIORITY_SWEEP`
- Produces: `Enqueuer::HOOK_REFRESH_BATCH`（string 定数）、`Enqueuer::enqueueBatch( string $account, array $items, int $when = 0 ): int`（戻り値は action ID。0 は未投入）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Queue/EnqueuerTest.php` に追加する。

```php
public function test_enqueueBatch_は_account_group_と_sweep_優先度で1件のジョブを積む(): void {
	$captured = null;
	WP_Mock::userFunction( 'as_schedule_single_action' )
		->once()
		->andReturnUsing(
			function ( $when, $hook, $args, $group, $unique, $priority ) use ( &$captured ) {
				$captured = compact( 'hook', 'args', 'group', 'unique', 'priority' );
				return 4242;
			}
		);

	$enqueuer = new Enqueuer();
	$items    = array(
		array(
			'post_id'  => 11,
			'platform' => 'rakuten-kobo',
		),
		array(
			'post_id'  => 12,
			'platform' => 'rakuten-kobo',
		),
	);

	$actionId = $enqueuer->enqueueBatch( 'rakuten', $items );

	$this->assertSame( 4242, $actionId );
	$this->assertSame( Enqueuer::HOOK_REFRESH_BATCH, $captured['hook'] );
	$this->assertSame( 'affilicard-rakuten', $captured['group'] );
	$this->assertSame( Enqueuer::PRIORITY_SWEEP, $captured['priority'] );
	$this->assertTrue( $captured['unique'] );
	$this->assertSame( 'rakuten', $captured['args']['account'] );
	$this->assertCount( 2, $captured['args']['items'] );
}

public function test_enqueueBatch_は_items_が空なら何も積まず0を返す(): void {
	WP_Mock::userFunction( 'as_schedule_single_action' )->never();

	$enqueuer = new Enqueuer();

	$this->assertSame( 0, $enqueuer->enqueueBatch( 'rakuten', array() ) );
}
```

- [ ] **Step 2: テストを実行して失敗を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter enqueueBatch`
Expected: FAIL（`Error: Call to undefined method ... enqueueBatch()` および `HOOK_REFRESH_BATCH` 未定義）

- [ ] **Step 3: 最小の実装を書く**

`src/Queue/Enqueuer.php` の定数群に追加する。

```php
	public const HOOK_REFRESH_BATCH = 'affilicard_refresh_batch';
```

同ファイルにメソッドを追加する。

```php
	/**
	 * account 単位のバッチジョブを積む。1 ジョブが複数 listing を担当し、
	 * ハンドラ側がジョブ内でレート間隔を守りながら順次 fetch する。
	 *
	 * per-listing ジョブ（HOOK_REFRESH）は異常系の受け皿として残るため、
	 * ここでは正常系の投入だけを担う。
	 *
	 * @param list<array{post_id: int, platform: string}> $items
	 * @return int action ID。0 は未投入（items が空・重複・投入失敗）。
	 */
	public function enqueueBatch( string $account, array $items, int $when = 0 ): int {
		if ( array() === $items ) {
			return 0;
		}

		$args = array(
			'account' => $account,
			'items'   => array_values( $items ),
		);

		return (int) as_schedule_single_action(
			$when > 0 ? $when : time(),
			self::HOOK_REFRESH_BATCH,
			$args,
			$this->group( $account ),
			true,
			self::PRIORITY_SWEEP
		);
	}
```

- [ ] **Step 4: テストを実行して成功を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter enqueueBatch`
Expected: PASS（2 tests）

- [ ] **Step 5: コミット**

```bash
git add src/Queue/Enqueuer.php tests/Unit/Queue/EnqueuerTest.php
git commit -m "feat: バッチジョブの投入を Enqueuer に追加"
```

---

## Task 2: ジョブ期限の計算（JobDeadline）

**Files:**
- Create: `src/Queue/JobDeadline.php`
- Test: `tests/Unit/Queue/JobDeadlineTest.php`

**Interfaces:**
- Consumes: なし（純粋なロジック）
- Produces: `JobDeadline::__construct( int $startedAt, int $timeLimitSeconds, int $safetyMarginSeconds )` / `remaining( int $nowTs ): int` / `canAfford( int $nowTs, int $needSeconds ): bool` / `clampWait( int $nowTs, int $waitSeconds ): int`

**なぜ切り出すか**: 期限判定はバッチハンドラの正しさの核心（spec §4-1「期限チェックを待機の前に置くこと」）で、ハンドラごとモックするより単体で境界値を突いた方が確実に守れる。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Queue/JobDeadlineTest.php` を新規作成する。

```php
<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Queue\JobDeadline;
use PHPUnit\Framework\TestCase;

final class JobDeadlineTest extends TestCase {

	public function test_remaining_は_安全マージンを差し引いた残り秒を返す(): void {
		$deadline = new JobDeadline( 1000, 30, 5 );

		// 期限 = 1000 + 30 - 5 = 1025
		$this->assertSame( 25, $deadline->remaining( 1000 ) );
		$this->assertSame( 5, $deadline->remaining( 1020 ) );
	}

	public function test_remaining_は_期限超過で0未満にならない(): void {
		$deadline = new JobDeadline( 1000, 30, 5 );

		$this->assertSame( 0, $deadline->remaining( 1025 ) );
		$this->assertSame( 0, $deadline->remaining( 9999 ) );
	}

	public function test_canAfford_は_必要秒を賄えるときだけ真(): void {
		$deadline = new JobDeadline( 1000, 30, 5 );

		$this->assertTrue( $deadline->canAfford( 1000, 25 ) );
		$this->assertFalse( $deadline->canAfford( 1000, 26 ) );
		$this->assertFalse( $deadline->canAfford( 1025, 1 ) );
	}

	public function test_clampWait_は_残り時間を超える待機を切り詰める(): void {
		$deadline = new JobDeadline( 1000, 30, 5 );

		$this->assertSame( 3, $deadline->clampWait( 1000, 3 ) );
		$this->assertSame( 25, $deadline->clampWait( 1000, 60 ) );
		$this->assertSame( 0, $deadline->clampWait( 1025, 60 ) );
	}
}
```

- [ ] **Step 2: テストを実行して失敗を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Queue/JobDeadlineTest.php`
Expected: FAIL（`Class "Affilicard\Queue\JobDeadline" not found`）

- [ ] **Step 3: 最小の実装を書く**

`src/Queue/JobDeadline.php` を新規作成する。

```php
<?php
declare(strict_types=1);

namespace Affilicard\Queue;

/**
 * AS ジョブの実行期限を扱う。
 *
 * バッチハンドラは「待機に入る前に」残り時間を確認し、賄えない場合は待たずに
 * 未処理分を積み直して終了しなければならない（spec §4-1）。待機に入ってから
 * 期限を超えると AS ランナーの時間予算を食い潰したうえ、積み直しも行われず
 * そのバッチの残りが失われる。
 */
final class JobDeadline {

	private int $deadlineTs;

	public function __construct( int $startedAt, int $timeLimitSeconds, int $safetyMarginSeconds ) {
		$this->deadlineTs = $startedAt + $timeLimitSeconds - $safetyMarginSeconds;
	}

	/** 期限までの残り秒（負にはならない）。 */
	public function remaining( int $nowTs ): int {
		return max( 0, $this->deadlineTs - $nowTs );
	}

	/** $needSeconds を期限内に賄えるか。 */
	public function canAfford( int $nowTs, int $needSeconds ): bool {
		return $this->remaining( $nowTs ) >= $needSeconds;
	}

	/** 待機秒を残り時間で切り詰める。 */
	public function clampWait( int $nowTs, int $waitSeconds ): int {
		return min( max( 0, $waitSeconds ), $this->remaining( $nowTs ) );
	}
}
```

- [ ] **Step 4: テストを実行して成功を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Queue/JobDeadlineTest.php`
Expected: PASS（4 tests）

- [ ] **Step 5: コミット**

```bash
git add src/Queue/JobDeadline.php tests/Unit/Queue/JobDeadlineTest.php
git commit -m "feat: ジョブ期限の計算を JobDeadline として追加"
```

---

## Task 3: バッチハンドラ（BatchRefreshHandler）

**Files:**
- Create: `src/Queue/BatchRefreshHandler.php`
- Test: `tests/Unit/Queue/BatchRefreshHandlerTest.php`

**Interfaces:**
- Consumes: `Enqueuer::enqueueBatch()`（Task 1）、`JobDeadline`（Task 2）、既存の `RateLimiter::tryAcquire()` / `ListingRefresher::refreshOne()` / `WorkOutcome` / `ProviderRegistry` / `GeneralSettings::isQueuePaused()`
- Produces: `BatchRefreshHandler::handle( array $args ): void`（`$args` は `{account: string, items: list<array{post_id:int, platform:string}>}`）

**設計の要点**: 正常系はここで完結させ、失敗した listing だけを既存の `Enqueuer::HOOK_REFRESH`（per-listing）へ落とす。per-listing 側の backoff・give-up・failed 可視化はそのまま活きる。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Queue/BatchRefreshHandlerTest.php` を新規作成する。

```php
<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Cron\ListingRefresher;
use Affilicard\Queue\BatchRefreshHandler;
use Affilicard\Queue\Enqueuer;
use Affilicard\Queue\RateLimiter;
use Affilicard\Queue\WorkOutcome;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class BatchRefreshHandlerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	private function args( array ...$items ): array {
		return array(
			'account' => 'rakuten',
			'items'   => $items,
		);
	}

	public function test_全件成功なら per-listing へ落とさない(): void {
		$limiter = Mockery::mock( RateLimiter::class );
		$limiter->shouldReceive( 'effectiveIntervalMs' )->andReturn( 1100 );
		$limiter->shouldReceive( 'tryAcquire' )->andReturn(
			array(
				'ok'      => true,
				'next_ms' => 0,
			)
		);

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->twice()->andReturn( WorkOutcome::SUCCESS );

		$enqueuer = Mockery::mock( Enqueuer::class );
		$enqueuer->shouldReceive( 'enqueueManual' )->never();
		$enqueuer->shouldReceive( 'enqueueBatch' )->never();

		$handler = new BatchRefreshHandler( $enqueuer, $limiter, $refresher, $this->registry(), 30, 5 );

		$handler->handle(
			$this->args(
				array(
					'post_id'  => 1,
					'platform' => 'rakuten-kobo',
				),
				array(
					'post_id'  => 2,
					'platform' => 'rakuten-kobo',
				)
			)
		);

		$this->assertTrue( true ); // Mockery の期待が検証される
	}

	public function test_一時失敗した listing だけが per-listing へ落ちる(): void {
		$limiter = Mockery::mock( RateLimiter::class );
		$limiter->shouldReceive( 'effectiveIntervalMs' )->andReturn( 1100 );
		$limiter->shouldReceive( 'tryAcquire' )->andReturn(
			array(
				'ok'      => true,
				'next_ms' => 0,
			)
		);

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->with( 1, 'rakuten-kobo' )->andReturn( WorkOutcome::SUCCESS );
		$refresher->shouldReceive( 'refreshOne' )->with( 2, 'rakuten-kobo' )->andReturn( WorkOutcome::TRANSIENT_FAILURE );

		$enqueuer = Mockery::mock( Enqueuer::class );
		$enqueuer->shouldReceive( 'enqueueManual' )->once()->with( 2, 'rakuten-kobo', 'rakuten' );

		$handler = new BatchRefreshHandler( $enqueuer, $limiter, $refresher, $this->registry(), 30, 5 );

		$handler->handle(
			$this->args(
				array(
					'post_id'  => 1,
					'platform' => 'rakuten-kobo',
				),
				array(
					'post_id'  => 2,
					'platform' => 'rakuten-kobo',
				)
			)
		);

		$this->assertTrue( true );
	}

	public function test_pause 中は fetch せずジョブを温存する(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( array( 'queue_paused' => true ) );

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->never();

		$enqueuer = Mockery::mock( Enqueuer::class );
		$enqueuer->shouldReceive( 'enqueueBatch' )->once(); // 自己再投入で温存

		$handler = new BatchRefreshHandler(
			$enqueuer,
			Mockery::mock( RateLimiter::class ),
			$refresher,
			$this->registry(),
			30,
			5
		);

		$handler->handle(
			$this->args(
				array(
					'post_id'  => 1,
					'platform' => 'rakuten-kobo',
				)
			)
		);

		$this->assertTrue( true );
	}
}
```

`registry()` は `ProviderRegistry` を返すヘルパ。`tests/Unit/Queue/RefreshHandlerTest.php:56` の同名ヘルパと同じ実装（`rakuten-kobo` provider が `isAutomatic()=true` / `accountCode()='rakuten'` / `minRequestIntervalMs()=1100` を返す）をコピーして使う。

- [ ] **Step 2: テストを実行して失敗を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Queue/BatchRefreshHandlerTest.php`
Expected: FAIL（`Class "Affilicard\Queue\BatchRefreshHandler" not found`）

- [ ] **Step 3: 最小の実装を書く**

`src/Queue/BatchRefreshHandler.php` を新規作成する。

```php
<?php
declare(strict_types=1);

namespace Affilicard\Queue;

use Affilicard\Cron\ListingRefresher;
use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Settings\GeneralSettings;

/**
 * affilicard_refresh_batch のハンドラ。
 *
 * 1 ジョブが複数 listing を担当し、ジョブ内でレート間隔を守りながら順次 fetch する。
 * 枠が取れないときは「待たずに再投入」ではなく「待つ」——これが per-listing 方式で
 * 空振り 90% を生んでいた原因（spec §2-1）。待ち時間は 1 秒前後と短く、待たない
 * コストの方が桁違いに大きいことが実測で判明している。
 *
 * 失敗した listing だけを既存の per-listing ジョブへ落とし、backoff / give-up /
 * failed 可視化という異常系の機構をそのまま活かす（spec §4-1）。
 */
final class BatchRefreshHandler {

	public function __construct(
		private Enqueuer $enqueuer,
		private RateLimiter $limiter,
		private ListingRefresher $refresher,
		private ProviderRegistry $registry,
		private int $timeLimitSeconds = 30,
		private int $safetyMarginSeconds = 5
	) {}

	/**
	 * @param array{account?: string, items?: list<array{post_id:int, platform:string}>} $args
	 */
	public function handle( array $args ): void {
		$account = isset( $args['account'] ) ? (string) $args['account'] : '';
		$items   = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
		if ( '' === $account || array() === $items ) {
			return;
		}

		// pause 中はジョブを失わずに温存する（bare return だと AS が complete 扱いにする）。
		if ( GeneralSettings::isQueuePaused() ) {
			$this->enqueuer->enqueueBatch( $account, $items, time() + 600 );
			return;
		}

		$deadline   = new JobDeadline( time(), $this->timeLimitSeconds, $this->safetyMarginSeconds );
		$intervalMs = $this->intervalMsFor( $account );
		$intervalSec = (int) ceil( $intervalMs / 1000 );
		// 1 件あたりの最悪所要 = レート待ち + Provider の HTTP タイムアウト（DMM/楽天とも 10 秒）。
		$perItemSeconds = $intervalSec + 10;

		foreach ( $items as $index => $item ) {
			$postId   = isset( $item['post_id'] ) ? (int) $item['post_id'] : 0;
			$platform = isset( $item['platform'] ) ? (string) $item['platform'] : '';
			if ( 0 === $postId || '' === $platform ) {
				continue;
			}

			// 待機に入る「前に」期限を確認する。賄えないなら未処理分を積み直して終了。
			if ( ! $deadline->canAfford( time(), $perItemSeconds ) ) {
				$this->requeueRemaining( $account, $items, $index );
				return;
			}

			$nowMs   = (int) round( microtime( true ) * 1000 );
			$acquire = $this->limiter->tryAcquire( $account, $intervalMs, $nowMs );
			if ( ! $acquire['ok'] ) {
				$waitSec = max( 0, (int) ceil( $acquire['next_ms'] / 1000 ) - time() );
				$waitSec = $deadline->clampWait( time(), $waitSec );
				if ( 0 === $waitSec && ! $deadline->canAfford( time(), $perItemSeconds ) ) {
					$this->requeueRemaining( $account, $items, $index );
					return;
				}
				if ( $waitSec > 0 ) {
					usleep( $waitSec * 1000000 );
				}
				$nowMs   = (int) round( microtime( true ) * 1000 );
				$acquire = $this->limiter->tryAcquire( $account, $intervalMs, $nowMs );
				if ( ! $acquire['ok'] ) {
					// 他ワーカーに取られた。この listing は per-listing へ委ねる。
					$this->enqueuer->enqueueManual( $postId, $platform, $account );
					continue;
				}
			}

			$outcome = $this->refresher->refreshOne( $postId, $platform );
			if ( WorkOutcome::TRANSIENT_FAILURE === $outcome ) {
				// 一時失敗は per-listing へ落とし、既存の backoff / failed 可視化に委ねる。
				$this->enqueuer->enqueueManual( $postId, $platform, $account );
				continue;
			}
			if ( WorkOutcome::TERMINAL_FAILURE === $outcome ) {
				// 恒久失敗は give-up マーカーを立てて終わり（per-listing へは落とさない）。
				set_transient(
					RefreshHandler::giveUpTransientKey( $postId, $platform ),
					1,
					3 * DAY_IN_SECONDS
				);
				continue;
			}
			// SUCCESS: give-up マーカーを解除する。
			delete_transient( RefreshHandler::giveUpTransientKey( $postId, $platform ) );
		}
	}

	/**
	 * $fromIndex 以降の未処理分を新しいバッチジョブとして積み直す。
	 *
	 * @param list<array{post_id:int, platform:string}> $items
	 */
	private function requeueRemaining( string $account, array $items, int $fromIndex ): void {
		$remaining = array_slice( $items, $fromIndex );
		if ( array() === $remaining ) {
			return;
		}
		$this->enqueuer->enqueueBatch( $account, $remaining, time() );
	}

	private function intervalMsFor( string $account ): int {
		$floorMs = 0;
		foreach ( $this->registry->all() as $provider ) {
			if ( $provider->accountCode() === $account ) {
				$floorMs = $provider->minRequestIntervalMs();
				break;
			}
		}
		return $this->limiter->effectiveIntervalMs( $floorMs, GeneralSettings::throttleOverrideMs( $account ) );
	}
}
```

`PlatformConfig` は本実装では未使用なら `use` を落とすこと（phpcs が未使用 import を検出する）。

- [ ] **Step 4: テストを実行して成功を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Queue/BatchRefreshHandlerTest.php`
Expected: PASS（3 tests）

- [ ] **Step 5: コミット**

```bash
git add src/Queue/BatchRefreshHandler.php tests/Unit/Queue/BatchRefreshHandlerTest.php
git commit -m "feat: バッチジョブのハンドラを追加"
```

---

## Task 4: `as_schedule_single_action()` 戻り値の扱い

**Files:**
- Modify: `src/Queue/Enqueuer.php:enqueueSweep()`
- Test: `tests/Unit/Queue/EnqueuerTest.php`

**Interfaces:**
- Consumes: Task 1 の `enqueueBatch()`
- Produces: `enqueueSweep()` の戻り値の意味は変えない（bool）。内部の depthMemo 更新条件のみ変更

**現状の問題**: 戻り値を見ずに `++$this->depthMemo` しているため、unique 重複でスキップされた分も depth cap の枠を消費する（spec §1-3 の実測で 775 件中 201 件しか積めなかった一因）。

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_enqueueSweep_は_重複スキップ時に深さを消費しない(): void {
	$this->stubRakutenPlatform();
	WP_Mock::userFunction( 'as_get_scheduled_actions' )->andReturn( array() );
	WP_Mock::userFunction( 'wp_rand' )->andReturn( 0 );
	// 常に 0（＝重複または失敗）を返す
	WP_Mock::userFunction( 'as_schedule_single_action' )->andReturn( 0 );

	$enqueuer = new Enqueuer( 3, 0, array( 'rakuten' ) );
	$listing  = array(
		'platform'        => 'rakuten-kobo',
		'last_fetched_at' => '',
	);
	$def      = $this->platform( 'rakuten-kobo', 24 );

	// depthCap=3。重複で消費しないなら 5 回とも「積もうとする」＝ true が返り続ける。
	for ( $i = 0; $i < 5; $i++ ) {
		$this->assertTrue( $enqueuer->enqueueSweep( $i + 1, 'rakuten-kobo', 'rakuten', $def, $listing, time() ) );
	}
}
```

- [ ] **Step 2: テストを実行して失敗を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit --filter enqueueSweep`
Expected: FAIL（4 回目以降が false になる＝depthMemo が重複でも進んでいる）

- [ ] **Step 3: 最小の実装を書く**

`enqueueSweep()` の末尾を書き換える。

```php
		$actionId = (int) as_schedule_single_action( $when, self::HOOK_REFRESH, $args, $this->group( $account ), true, self::PRIORITY_SWEEP );

		// 戻り値 0 には「unique 重複でスキップ」と「投入失敗」の 2 つの意味がある。
		// どちらも新たな pending を作っていないため深さは消費しない。投入失敗は
		// 呼び出し側（sweep）がカーソル保持で回復する（spec §4-3）。
		if ( 0 !== $actionId ) {
			++$this->depthMemo;
		}

		return true;
```

- [ ] **Step 4: テストを実行して成功を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Queue/EnqueuerTest.php`
Expected: PASS（既存テストも含めて緑）

- [ ] **Step 5: コミット**

```bash
git add src/Queue/Enqueuer.php tests/Unit/Queue/EnqueuerTest.php
git commit -m "fix: 重複スキップ時に depth cap の枠を消費しないよう修正"
```

---

## Task 5: sweep のカーソル永続化（SweepCursor）

**Files:**
- Create: `src/Queue/SweepCursor.php`
- Test: `tests/Unit/Queue/SweepCursorTest.php`

**Interfaces:**
- Consumes: なし
- Produces: `SweepCursor::OPTION_KEY`（string）、`SweepCursor::get(): int`（0 は先頭から）、`SweepCursor::set( int $postId ): void`、`SweepCursor::clear(): void`

- [ ] **Step 1: 失敗するテストを書く**

```php
<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Queue\SweepCursor;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class SweepCursorTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		\Mockery::close();
		parent::tearDown();
	}

	public function test_未設定なら0を返す(): void {
		WP_Mock::userFunction( 'get_option' )->with( SweepCursor::OPTION_KEY, 0 )->andReturn( 0 );

		$this->assertSame( 0, ( new SweepCursor() )->get() );
	}

	public function test_set_は_autoload_無しで保存する(): void {
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( SweepCursor::OPTION_KEY, 42, false );

		( new SweepCursor() )->set( 42 );
	}

	public function test_clear_は_option_を削除する(): void {
		WP_Mock::userFunction( 'delete_option' )->once()->with( SweepCursor::OPTION_KEY );

		( new SweepCursor() )->clear();
	}
}
```

- [ ] **Step 2: テストを実行して失敗を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Queue/SweepCursorTest.php`
Expected: FAIL（`Class "Affilicard\Queue\SweepCursor" not found`）

- [ ] **Step 3: 最小の実装を書く**

```php
<?php
declare(strict_types=1);

namespace Affilicard\Queue;

/**
 * sweep の走査位置（最後に処理した商品 post_id）を永続化する。
 *
 * 継続ジョブの投入に失敗しても、次回の sweep が途中から再開できるようにするための
 * 保険（spec §4-2）。カーソルが無いまま継続ジョブだけが失われると、その位置以降の
 * 商品が次の WP-Cron まで丸ごと更新されない。
 */
final class SweepCursor {

	public const OPTION_KEY = 'affilicard_sweep_cursor';

	/** 0 は「先頭から」を表す。 */
	public function get(): int {
		return (int) get_option( self::OPTION_KEY, 0 );
	}

	public function set( int $postId ): void {
		update_option( self::OPTION_KEY, $postId, false );
	}

	public function clear(): void {
		delete_option( self::OPTION_KEY );
	}
}
```

- [ ] **Step 4: テストを実行して成功を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Queue/SweepCursorTest.php`
Expected: PASS（3 tests）

- [ ] **Step 5: コミット**

```bash
git add src/Queue/SweepCursor.php tests/Unit/Queue/SweepCursorTest.php
git commit -m "feat: sweep のカーソル永続化を追加"
```

---

## Task 6: sweep の分割実行とバッチ投入への切替

**Files:**
- Modify: `src/Queue/QueueMaintenance.php`
- Test: `tests/Unit/Queue/QueueMaintenanceTest.php`

**Interfaces:**
- Consumes: `SweepCursor`（Task 5）、`Enqueuer::enqueueBatch()`（Task 1）
- Produces: `QueueMaintenance::sweep( int $maxProducts = 200 ): bool`（戻り値は「完走したか」。false は継続あり）、`QueueMaintenance::OPTION_LAST_COMPLETED`（string）

**変更の要点**: 商品を `$maxProducts` 件ずつ走査し、対象 listing を account 別にためてバッチ投入する。cap 到達・件数上限・完走のいずれでも**カーソルを正しく扱う**（spec §4-2）。

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_上限件数で打ち切りカーソルを保存して未完走を返す(): void {
	// 商品 5 件、maxProducts=2 → 2 件処理してカーソル保存・false
	WP_Mock::userFunction( 'get_posts' )->andReturn( array( 10, 11 ) );
	WP_Mock::userFunction( 'get_option' )->andReturn( 0 );
	WP_Mock::userFunction( 'update_option' )->once(); // カーソル保存
	WP_Mock::userFunction( 'delete_option' )->never(); // 完走していないので消さない
	WP_Mock::userFunction( 'get_transient' )->andReturn( false );

	$repo = Mockery::mock( ProductRepositoryInterface::class );
	$repo->shouldReceive( 'find' )->andReturn(
		array(
			'title'    => 'x',
			'listings' => array(
				array(
					'platform'        => 'rakuten-kobo',
					'enabled'         => true,
					'update_mode'     => 'auto',
					'auto_update'     => true,
					'last_fetched_at' => '',
				),
			),
		)
	);

	$enqueuer = Mockery::mock( Enqueuer::class );
	$enqueuer->shouldReceive( 'enqueueBatch' )->atLeast()->once();

	$maintenance = new QueueMaintenance( $repo, $enqueuer, $this->registry(), new SweepCursor() );

	$this->assertFalse( $maintenance->sweep( 2 ) );
}

public function test_最後まで到達したらカーソルを消し完走時刻を記録する(): void {
	WP_Mock::userFunction( 'get_posts' )->andReturn( array( 10 ) );
	WP_Mock::userFunction( 'get_option' )->andReturn( 0 );
	WP_Mock::userFunction( 'get_transient' )->andReturn( false );
	WP_Mock::userFunction( 'delete_option' )->once(); // カーソルクリア
	WP_Mock::userFunction( 'update_option' )
		->once()
		->with( QueueMaintenance::OPTION_LAST_COMPLETED, Mockery::type( 'string' ), false );

	$repo = Mockery::mock( ProductRepositoryInterface::class );
	$repo->shouldReceive( 'find' )->andReturn(
		array(
			'title'    => 'x',
			'listings' => array(),
		)
	);

	$enqueuer    = Mockery::mock( Enqueuer::class );
	$maintenance = new QueueMaintenance( $repo, $enqueuer, $this->registry(), new SweepCursor() );

	$this->assertTrue( $maintenance->sweep( 200 ) );
}
```

- [ ] **Step 2: テストを実行して失敗を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Queue/QueueMaintenanceTest.php`
Expected: FAIL（`sweep()` が引数を取らない／戻り値が void）

- [ ] **Step 3: 最小の実装を書く**

`QueueMaintenance` のコンストラクタに `SweepCursor` を追加し、`sweep()` を書き換える。

```php
	public const OPTION_LAST_COMPLETED = 'affilicard_last_sweep_completed_at';

	/** 1 バッチジョブに詰める listing 件数の既定。 */
	private const BATCH_SIZE = 22;

	public function __construct(
		private ProductRepositoryInterface $repository,
		private Enqueuer $enqueuer,
		private ProviderRegistry $providerRegistry,
		private SweepCursor $cursor = new SweepCursor()
	) {}

	/**
	 * 公開商品をカーソル順に $maxProducts 件走査し、対象 listing を account 別の
	 * バッチジョブとして積む。
	 *
	 * @return bool 最後の商品まで到達したら true（完走）。false は継続あり。
	 */
	public function sweep( int $maxProducts = 200 ): bool {
		$after = $this->cursor->get();

		$ids = get_posts(
			array(
				'post_type'      => ProductPostType::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => $maxProducts,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				// カーソルより後ろの ID だけを取る。
				'post__not_in'   => array(),
			)
		);
		if ( ! is_array( $ids ) ) {
			return true;
		}
		$ids = array_values( array_filter( $ids, static fn( $id ) => (int) $id > $after ) );

		$now      = time();
		$buckets  = array(); // account => list<array{post_id, platform}>
		$lastSeen = $after;

		foreach ( $ids as $id ) {
			$id       = (int) $id;
			$lastSeen = $id;
			$product  = $this->repository->find( $id );
			if ( null === $product || ! is_array( $product['listings'] ?? null ) ) {
				continue;
			}

			// 棚卸し対象の商品はここで丸ごと除外する（Task 10 で有効化）。
			if ( $this->stocktake->isRetired( $id, $now ) ) {
				continue;
			}

			foreach ( $product['listings'] as $listing ) {
				if ( ! is_array( $listing ) ) {
					continue;
				}
				$platform = (string) ( $listing['platform'] ?? '' );
				$def      = PlatformConfig::find( $platform );
				if ( null === $def || ! ListingEligibility::isAutoEligible( $listing ) ) {
					continue;
				}
				$account = $this->providerRegistry->get( $def->provider )?->accountCode();
				if ( null === $account ) {
					continue;
				}
				if ( get_transient( RefreshHandler::giveUpTransientKey( $id, $platform ) ) ) {
					continue;
				}
				if ( ! PriceFreshness::needsRefetch( $listing, $def, $now, $this->sweepLeadSeconds ) ) {
					continue;
				}

				$buckets[ $account ][] = array(
					'post_id'  => $id,
					'platform' => $platform,
				);

				if ( count( $buckets[ $account ] ) >= self::BATCH_SIZE ) {
					$this->enqueuer->enqueueBatch( $account, $buckets[ $account ] );
					$buckets[ $account ] = array();
				}
			}
		}

		// 端数を流す。
		foreach ( $buckets as $account => $items ) {
			if ( array() !== $items ) {
				$this->enqueuer->enqueueBatch( $account, $items );
			}
		}

		$completed = count( $ids ) < $maxProducts;
		if ( $completed ) {
			$this->cursor->clear();
			update_option( self::OPTION_LAST_COMPLETED, gmdate( 'c' ), false );
			return true;
		}

		// 未完走。カーソルを保存して次のジョブに委ねる（投入に失敗しても
		// 次回 sweep がここから再開できる）。
		$this->cursor->set( $lastSeen );
		return false;
	}
```

`$this->stocktake` と `$this->sweepLeadSeconds` は Task 10 / 既存の配線で注入する。Task 6 の時点では `StocktakePolicy` が未実装のため、**この行はコメントアウトして Task 10 で有効化**するか、Task 10 を先に実施すること。順序を入れ替える場合は Task 10 → Task 6 の順にする。

- [ ] **Step 4: テストを実行して成功を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Queue/QueueMaintenanceTest.php`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
git add src/Queue/QueueMaintenance.php tests/Unit/Queue/QueueMaintenanceTest.php
git commit -m "feat: sweep をカーソル分割しバッチ投入へ切り替える"
```

---

## Task 7: プラグインのアップグレードルーチン（PluginUpgrade）

**Files:**
- Create: `src/Upgrade/PluginUpgrade.php`
- Test: `tests/Unit/Upgrade/PluginUpgradeTest.php`
- Modify: `src/Plugin.php`（`plugins_loaded` で `maybeUpgrade()` を呼ぶ配線）

**Interfaces:**
- Consumes: なし
- Produces: `PluginUpgrade::OPTION_VERSION`（string）、`PluginUpgrade::maybeUpgrade( string $currentVersion ): void`

**なぜ新規に作るか**: 既存の `SchemaVersion` は post meta のスキーマ用 value object で、**プラグイン全体のバージョン移行機構は存在しない**。`register_activation_hook` は WordPress の自動更新や管理画面からの更新では実行されないため、**有効化したまま更新したサイトでは初期化処理が一度も走らない**（spec §5-3）。棚卸し基準日の記録はここに載せる。

- [ ] **Step 1: 失敗するテストを書く**

```php
<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Upgrade;

use Affilicard\Upgrade\PluginUpgrade;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class PluginUpgradeTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		\Mockery::close();
		parent::tearDown();
	}

	public function test_初回は棚卸し基準日を作成しバージョンを記録する(): void {
		WP_Mock::userFunction( 'get_option' )->with( PluginUpgrade::OPTION_VERSION, '' )->andReturn( '' );
		WP_Mock::userFunction( 'add_option' )
			->once()
			->with( PluginUpgrade::OPTION_STOCKTAKE_BASELINE, \Mockery::type( 'string' ), '', false );
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( PluginUpgrade::OPTION_VERSION, '3.5.0', false );

		PluginUpgrade::maybeUpgrade( '3.5.0' );
	}

	public function test_同一バージョンなら何もしない(): void {
		WP_Mock::userFunction( 'get_option' )->with( PluginUpgrade::OPTION_VERSION, '' )->andReturn( '3.5.0' );
		WP_Mock::userFunction( 'add_option' )->never();
		WP_Mock::userFunction( 'update_option' )->never();

		PluginUpgrade::maybeUpgrade( '3.5.0' );
	}

	public function test_既存の基準日は上書きしない(): void {
		// add_option は既存があれば no-op（false を返す）。二重に update_option しないことを検証。
		WP_Mock::userFunction( 'get_option' )->with( PluginUpgrade::OPTION_VERSION, '' )->andReturn( '3.4.0' );
		WP_Mock::userFunction( 'add_option' )->once()->andReturn( false );
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( PluginUpgrade::OPTION_VERSION, '3.5.0', false );

		PluginUpgrade::maybeUpgrade( '3.5.0' );
	}
}
```

- [ ] **Step 2: テストを実行して失敗を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Upgrade/PluginUpgradeTest.php`
Expected: FAIL（`Class "Affilicard\Upgrade\PluginUpgrade" not found`）

- [ ] **Step 3: 最小の実装を書く**

```php
<?php
declare(strict_types=1);

namespace Affilicard\Upgrade;

/**
 * プラグインのバージョン移行ルーチン。
 *
 * `register_activation_hook` は WordPress の自動更新・管理画面からの更新では
 * 実行されない。有効化したまま更新したサイトでも初期化処理が確実に走るよう、
 * `plugins_loaded` で保存済みバージョンと現在バージョンを比較して差分処理を行う。
 */
final class PluginUpgrade {

	public const OPTION_VERSION = 'affilicard_plugin_version';

	/** 棚卸し基準日。最終掲載日を持たない既存商品の判定基準になる（spec §5-3）。 */
	public const OPTION_STOCKTAKE_BASELINE = 'affilicard_stocktake_baseline';

	public static function maybeUpgrade( string $currentVersion ): void {
		$stored = (string) get_option( self::OPTION_VERSION, '' );
		if ( $stored === $currentVersion ) {
			return;
		}

		// 棚卸し基準日は「無ければ作る」。既にあるサイトでは絶対に書き換えない
		// （更新のたびにリセットされると棚卸しが永久に発動しない）。
		add_option( self::OPTION_STOCKTAKE_BASELINE, gmdate( 'c' ), '', false );

		update_option( self::OPTION_VERSION, $currentVersion, false );
	}
}
```

`src/Plugin.php` の起動処理に配線する。

```php
		add_action(
			'plugins_loaded',
			static function (): void {
				PluginUpgrade::maybeUpgrade( AFFILICARD_VERSION );
			}
		);
```

- [ ] **Step 4: テストを実行して成功を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Upgrade/PluginUpgradeTest.php`
Expected: PASS（3 tests）

- [ ] **Step 5: コミット**

```bash
git add src/Upgrade/PluginUpgrade.php src/Plugin.php tests/Unit/Upgrade/PluginUpgradeTest.php
git commit -m "feat: バージョン移行ルーチンと棚卸し基準日の初期化を追加"
```

---

## Task 8: 棚卸しの設定項目（GeneralSettings）

**Files:**
- Modify: `src/Settings/GeneralSettings.php`
- Test: `tests/Unit/Settings/GeneralSettingsTest.php`

**Interfaces:**
- Consumes: なし
- Produces: `GeneralSettings::isStocktakeEnabled(): bool`、`GeneralSettings::stocktakeDays(): int`

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_stocktake_の既定値は有効かつ180日(): void {
	WP_Mock::userFunction( 'get_option' )->andReturn( array() );

	$this->assertTrue( GeneralSettings::isStocktakeEnabled() );
	$this->assertSame( 180, GeneralSettings::stocktakeDays() );
}

public function test_stocktake_days_は_0以下を1へクランプする(): void {
	WP_Mock::userFunction( 'get_option' )->andReturn( array() );
	WP_Mock::userFunction( 'update_option' )->andReturn( true );

	$saved = GeneralSettings::update( array( 'stocktake_days' => 0 ) );
	$this->assertSame( 1, $saved['stocktake_days'] );

	$saved = GeneralSettings::update( array( 'stocktake_days' => -30 ) );
	$this->assertSame( 1, $saved['stocktake_days'] );
}
```

正規化は `GeneralSettings::update()` の中で行われている（`sanitize()` という公開メソッドは存在しない）。既存の `queue_depth_cap` / `retention_*` と同じ位置に足すこと。

- [ ] **Step 2: テストを実行して失敗を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Settings/GeneralSettingsTest.php`
Expected: FAIL（メソッド未定義）

- [ ] **Step 3: 最小の実装を書く**

`DEFAULTS` に追加する。

```php
		'stocktake_enabled'      => true,
		'stocktake_days'         => 180,
```

正規化処理に追加する（既存の `queue_depth_cap` と同じ場所・同じ形）。

```php
		$stocktake_enabled = isset( $values['stocktake_enabled'] ) ? (bool) $values['stocktake_enabled'] : (bool) self::DEFAULTS['stocktake_enabled'];
		// 0 や負数を許すと基準日との比較が常に真になり、全商品が即座に棚卸しされて
		// 価格が一斉に消える。無効化は stocktake_enabled が担うため 0 に意味を持たせない。
		$stocktake_days = isset( $values['stocktake_days'] ) ? max( 1, (int) $values['stocktake_days'] ) : (int) self::DEFAULTS['stocktake_days'];
```

アクセサを追加する。

```php
	public static function isStocktakeEnabled(): bool {
		return (bool) self::all()['stocktake_enabled'];
	}

	public static function stocktakeDays(): int {
		return max( 1, (int) self::all()['stocktake_days'] );
	}
```

- [ ] **Step 4: テストを実行して成功を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Settings/GeneralSettingsTest.php`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
git add src/Settings/GeneralSettings.php tests/Unit/Settings/GeneralSettingsTest.php
git commit -m "feat: 棚卸しの設定項目を追加"
```

---

## Task 9: 最終掲載日の記録（PublicationDate / PublishTrigger）

**Files:**
- Create: `src/Stocktake/PublicationDate.php`
- Modify: `src/PostType/ProductPostType.php`（meta キー定数のみ）
- Modify: `src/PostType/ProductMeta.php`（`register_post_meta` の追加）
- Modify: `src/Queue/PublishTrigger.php`（記録の呼び出し）
- Test: `tests/Unit/Stocktake/PublicationDateTest.php`
- Test: `tests/Unit/PostType/ProductMetaTest.php`（read-only 登録の検証）
- Test: `tests/Unit/Queue/PublishTriggerTest.php`

**Interfaces:**
- Consumes: なし
- Produces: `ProductPostType::META_LAST_PUBLISHED_AT`（string）、`PublicationDate::touch( int $postId, int $nowTs ): void`、`PublicationDate::get( int $postId ): ?int`（UTC epoch 秒。無効値は null）

**要点**: 記録する値は「記事の公開日時」ではなく**記録時点の現在時刻**。かつ**単調増加**（既存値より新しいときだけ書く）。meta は **read-only**（`auth_callback` で編集拒否）。

- [ ] **Step 1: 失敗するテストを書く**

```php
<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Stocktake;

use Affilicard\PostType\ProductPostType;
use Affilicard\Stocktake\PublicationDate;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class PublicationDateTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		\Mockery::close();
		parent::tearDown();
	}

	public function test_未設定なら現在時刻を記録する(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 7, ProductPostType::META_LAST_PUBLISHED_AT, true )
			->andReturn( '' );
		WP_Mock::userFunction( 'update_post_meta' )->once();

		( new PublicationDate() )->touch( 7, 1000 );
	}

	public function test_既存値より古い時刻では上書きしない(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 7, ProductPostType::META_LAST_PUBLISHED_AT, true )
			->andReturn( gmdate( 'c', 2000 ) );
		WP_Mock::userFunction( 'update_post_meta' )->never();

		( new PublicationDate() )->touch( 7, 1000 );
	}

	public function test_meta_は_read_only_として登録される(): void {
		$captured = null;
		WP_Mock::userFunction( 'register_post_meta' )
			->andReturnUsing(
				function ( $type, $key, $args ) use ( &$captured ) {
					if ( ProductPostType::META_LAST_PUBLISHED_AT === $key ) {
						$captured = $args;
					}
					return true;
				}
			);

		ProductMeta::register();

		$this->assertIsArray( $captured );
		$this->assertTrue( $captured['show_in_rest'] );
		// auth_callback が false を返す＝REST / 編集画面から書き換えられない。
		$this->assertFalse( ( $captured['auth_callback'] )() );
	}

	public function test_get_は_空文字と不正値を null にする(): void {
		WP_Mock::userFunction( 'get_post_meta' )->andReturn( '' );
		$this->assertNull( ( new PublicationDate() )->get( 7 ) );

		WP_Mock::userFunction( 'get_post_meta' )->andReturn( 'not-a-date' );
		$this->assertNull( ( new PublicationDate() )->get( 7 ) );
	}
}
```

- [ ] **Step 2: テストを実行して失敗を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Stocktake/PublicationDateTest.php`
Expected: FAIL（クラス未定義）

- [ ] **Step 3: 最小の実装を書く**

`src/PostType/ProductPostType.php` に定数を追加する（meta キーの定数は既存の `META_*` と同じ場所に置く）。

```php
	public const META_LAST_PUBLISHED_AT = 'affilicard_last_published_at';
```

登録は `src/PostType/ProductMeta.php`（`register_post_meta` を集約している専用クラス）の `register()` に **read-only** で追加する。REST では読めるが書けない。

```php
		register_post_meta(
			self::POST_TYPE,
			self::META_LAST_PUBLISHED_AT,
			array(
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => true,
				// 利用者が直接書き換えると棚卸し判定が意図せず変わるため編集を拒否する。
				'auth_callback' => static fn (): bool => false,
			)
		);
```

`src/Stocktake/PublicationDate.php` を新規作成する。

```php
<?php
declare(strict_types=1);

namespace Affilicard\Stocktake;

use Affilicard\PostType\ProductPostType;

/**
 * 最終掲載日（記事の公開・更新で商品が掲載面に載った最後の時刻）の読み書き。
 *
 * 記録するのは「記事の公開日時」ではなく記録時点の現在時刻。公開日時を使うと、
 * 過去日付の記事を後から編集した場合や予約投稿で未来日時が入る場合に実態とずれる。
 * 判定したいのは「最後に掲載面へ手が入ったのはいつか」である（spec §5-1）。
 */
final class PublicationDate {

	/** 既存値より新しいときだけ書く（単調増加）。 */
	public function touch( int $postId, int $nowTs ): void {
		$current = $this->get( $postId );
		if ( null !== $current && $current >= $nowTs ) {
			return;
		}
		update_post_meta( $postId, ProductPostType::META_LAST_PUBLISHED_AT, gmdate( 'c', $nowTs ) );
	}

	/**
	 * UTC epoch 秒。null・空文字・パース不能はすべて null（無効値）として返す。
	 * 呼び出し側は null を棚卸し基準日へフォールバックさせる。
	 */
	public function get( int $postId ): ?int {
		$raw = get_post_meta( $postId, ProductPostType::META_LAST_PUBLISHED_AT, true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}
		$ts = strtotime( trim( $raw ) );
		return false === $ts ? null : (int) $ts;
	}
}
```

`src/Queue/PublishTrigger.php` の `syncPost()` で、解決した商品 ID ごとに記録する。

```php
		foreach ( $this->resolveProductIds( (string) $post->post_content ) as $productId ) {
			$this->publicationDate->touch( $productId, time() );
			$this->forceEnqueueEligibleListings( $productId );
		}
```

- [ ] **Step 4: テストを実行して成功を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Stocktake/ tests/Unit/Queue/PublishTriggerTest.php`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
git add src/Stocktake/PublicationDate.php src/PostType/ProductPostType.php src/PostType/ProductMeta.php src/Queue/PublishTrigger.php tests/Unit/Stocktake/PublicationDateTest.php tests/Unit/PostType/ProductMetaTest.php tests/Unit/Queue/PublishTriggerTest.php
git commit -m "feat: 最終掲載日の記録を追加"
```

---

## Task 10: 棚卸し判定（StocktakePolicy）

**Files:**
- Create: `src/Stocktake/StocktakePolicy.php`
- Test: `tests/Unit/Stocktake/StocktakePolicyTest.php`
- Modify: `src/Queue/QueueMaintenance.php`（Task 6 でコメントアウトした行を有効化）

**Interfaces:**
- Consumes: `PublicationDate::get()`（Task 9）、`PluginUpgrade::OPTION_STOCKTAKE_BASELINE`（Task 7）、`GeneralSettings::isStocktakeEnabled()` / `stocktakeDays()`（Task 8）
- Produces: `StocktakePolicy::isRetired( int $postId, int $nowTs ): bool`

**判定式**（spec §5-2）: `( 最終掲載日 ?? 棚卸し基準日 ) + stocktake_days × 86400 < 現在時刻`。状態は持たず毎回評価する（期間を延ばせば対象へ復帰する）。

- [ ] **Step 1: 失敗するテストを書く**

```php
<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Stocktake;

use Affilicard\Stocktake\PublicationDate;
use Affilicard\Stocktake\StocktakePolicy;
use Affilicard\Upgrade\PluginUpgrade;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class StocktakePolicyTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	private function policy( ?int $lastPublished, int $days = 180, bool $enabled = true ): StocktakePolicy {
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			function ( $key, $default = false ) use ( $days, $enabled ) {
				if ( PluginUpgrade::OPTION_STOCKTAKE_BASELINE === $key ) {
					return gmdate( 'c', 0 ); // 基準日 = epoch 0
				}
				return array(
					'stocktake_enabled' => $enabled,
					'stocktake_days'    => $days,
				);
			}
		);

		$dates = Mockery::mock( PublicationDate::class );
		$dates->shouldReceive( 'get' )->andReturn( $lastPublished );

		return new StocktakePolicy( $dates );
	}

	public function test_期間内なら棚卸ししない(): void {
		$policy = $this->policy( 1000 );
		// 1000 + 180日 = 15,553,000
		$this->assertFalse( $policy->isRetired( 1, 15552999 ) );
	}

	public function test_期間を過ぎたら棚卸し対象(): void {
		$policy = $this->policy( 1000 );
		$this->assertTrue( $policy->isRetired( 1, 15553001 ) );
	}

	public function test_最終掲載日が無効なら基準日で判定する(): void {
		$policy = $this->policy( null ); // 基準日 = 0
		$this->assertFalse( $policy->isRetired( 1, 15551000 ) );
		$this->assertTrue( $policy->isRetired( 1, 15553000 ) );
	}

	public function test_無効化されていれば常に false(): void {
		$policy = $this->policy( 1000, 180, false );
		$this->assertFalse( $policy->isRetired( 1, PHP_INT_MAX - 1 ) );
	}

	public function test_手動更新の経路は棚卸しの影響を受けない(): void {
		// 棚卸しは QueueMaintenance::sweep()（継続更新）にのみ適用する。
		// RefreshController / 管理画面ボタン経由の Enqueuer::enqueueProductListings()
		// は StocktakePolicy を参照しないことを、静的に保証する（spec §5-5）。
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Queue/Enqueuer.php' );

		$this->assertStringNotContainsString( 'StocktakePolicy', (string) $source );
	}

	public function test_期間を延ばすと対象から復帰する(): void {
		$now = 15553001;
		$this->assertTrue( $this->policy( 1000, 180 )->isRetired( 1, $now ) );

		WP_Mock::tearDown();
		WP_Mock::setUp();

		$this->assertFalse( $this->policy( 1000, 365 )->isRetired( 1, $now ) );
	}
}
```

- [ ] **Step 2: テストを実行して失敗を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Stocktake/StocktakePolicyTest.php`
Expected: FAIL（クラス未定義）

- [ ] **Step 3: 最小の実装を書く**

```php
<?php
declare(strict_types=1);

namespace Affilicard\Stocktake;

use Affilicard\Settings\GeneralSettings;
use Affilicard\Upgrade\PluginUpgrade;

/**
 * 棚卸し判定。
 *
 * 「棚卸し済み」フラグは持たず毎回評価する。これにより設定期間を延ばすだけで
 * 対象から復帰でき、フラグの整合性管理もマイグレーションも不要になる（spec §5-2）。
 */
final class StocktakePolicy {

	public function __construct( private PublicationDate $dates = new PublicationDate() ) {}

	public function isRetired( int $postId, int $nowTs ): bool {
		if ( ! GeneralSettings::isStocktakeEnabled() ) {
			return false;
		}

		$base = $this->dates->get( $postId ) ?? $this->baselineTs();
		if ( null === $base ) {
			// 基準日すら無い（移行前）＝判定不能。安全側に倒して棚卸ししない。
			return false;
		}

		return ( $base + GeneralSettings::stocktakeDays() * DAY_IN_SECONDS ) < $nowTs;
	}

	/** 棚卸し基準日（UTC epoch 秒）。無効値は null。 */
	private function baselineTs(): ?int {
		$raw = get_option( PluginUpgrade::OPTION_STOCKTAKE_BASELINE, '' );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}
		$ts = strtotime( trim( $raw ) );
		return false === $ts ? null : (int) $ts;
	}
}
```

`QueueMaintenance` のコンストラクタに `StocktakePolicy` を追加し、Task 6 でコメントアウトした行を有効化する。

- [ ] **Step 4: テストを実行して成功を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/Stocktake/ tests/Unit/Queue/QueueMaintenanceTest.php`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
git add src/Stocktake/StocktakePolicy.php src/Queue/QueueMaintenance.php tests/Unit/Stocktake/StocktakePolicyTest.php tests/Unit/Queue/QueueMaintenanceTest.php
git commit -m "feat: 棚卸し判定を追加し掃引へ適用する"
```

---

## Task 11: 商品一覧の「最終掲載日」列とソート

**Files:**
- Modify: `src/PostType/ProductListColumns.php`
- Test: `tests/Unit/PostType/ProductListColumnsTest.php`

**Interfaces:**
- Consumes: `ProductPostType::META_LAST_PUBLISHED_AT`（Task 9）、`StocktakePolicy::isRetired()`（Task 10）
- Produces: `ProductListColumns::COLUMN_LAST_PUBLISHED`（string）、`ProductListColumns::sortableColumns( array $columns ): array`、`ProductListColumns::applySortQuery( \WP_Query $query ): void`

**注**: 「最終同期」列のソートは**スコープ外**（listing の JSON 配列内にあり派生 meta が必要なため。spec §5-8）。ここで追加するのは「最終掲載日」のみ。

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_最終掲載日はソート可能列として登録される(): void {
	$columns = ProductListColumns::sortableColumns( array() );

	$this->assertArrayHasKey( ProductListColumns::COLUMN_LAST_PUBLISHED, $columns );
	$this->assertSame( ProductListColumns::COLUMN_LAST_PUBLISHED, $columns[ ProductListColumns::COLUMN_LAST_PUBLISHED ] );
}

public function test_ソート指定時に meta_key と orderby を設定する(): void {
	$query = Mockery::mock( \WP_Query::class );
	$query->shouldReceive( 'is_main_query' )->andReturn( true );
	$query->shouldReceive( 'get' )->with( 'orderby' )->andReturn( ProductListColumns::COLUMN_LAST_PUBLISHED );
	$query->shouldReceive( 'set' )->once()->with( 'meta_key', ProductPostType::META_LAST_PUBLISHED_AT );
	$query->shouldReceive( 'set' )->once()->with( 'orderby', 'meta_value' );

	WP_Mock::userFunction( 'is_admin' )->andReturn( true );

	ProductListColumns::applySortQuery( $query );
}
```

- [ ] **Step 2: テストを実行して失敗を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/PostType/ProductListColumnsTest.php`
Expected: FAIL（メソッド未定義）

- [ ] **Step 3: 最小の実装を書く**

```php
	public const COLUMN_LAST_PUBLISHED = 'affilicard_last_published';

	/**
	 * ソート可能列として登録する。値は ISO8601（UTC）なので meta_value の
	 * 文字列比較で時系列順に並ぶ。
	 *
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function sortableColumns( array $columns ): array {
		$columns[ self::COLUMN_LAST_PUBLISHED ] = self::COLUMN_LAST_PUBLISHED;
		return $columns;
	}

	public static function applySortQuery( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( self::COLUMN_LAST_PUBLISHED !== $query->get( 'orderby' ) ) {
			return;
		}
		$query->set( 'meta_key', ProductPostType::META_LAST_PUBLISHED_AT );
		$query->set( 'orderby', 'meta_value' );
	}

	private static function renderLastPublishedColumn( int $post_id ): void {
		$ts = ( new PublicationDate() )->get( $post_id );
		if ( null === $ts ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}
		echo esc_html( gmdate( 'Y-m-d', $ts ) );

		if ( ( new StocktakePolicy() )->isRetired( $post_id, time() ) ) {
			echo ' <span class="dashicons dashicons-archive" title="'
				. esc_attr__( '棚卸し対象（自動更新を停止中）', 'affilicard' ) . '"></span>';
		}
	}
```

列の追加は既存の `addColumn()`（`manage_{post_type}_posts_columns` フィルタ）に 1 行足し、描画は `renderColumn()` の `switch` に `case self::COLUMN_LAST_PUBLISHED:` を足す。ソート用のフィルタは既存の `register()` に配線する。

```php
		add_filter( 'manage_edit-' . ProductPostType::POST_TYPE . '_sortable_columns', array( self::class, 'sortableColumns' ) );
		add_action( 'pre_get_posts', array( self::class, 'applySortQuery' ) );
```

- [ ] **Step 4: テストを実行して成功を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/PostType/`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
git add src/PostType/ProductListColumns.php tests/Unit/PostType/ProductListColumnsTest.php
git commit -m "feat: 商品一覧に最終掲載日の列とソートを追加"
```

---

## Task 12: 配線（Plugin）と cron 健全性の表示

**Files:**
- Modify: `src/Plugin.php`
- Modify: `src/Rest/QueueController.php`（stats に最終掃引時刻を含める）
- Modify: `src/Admin/components/QueuePanel.jsx`（表示）
- Test: `tests/Unit/PluginTest.php`
- Test: `tests/js/QueuePanel.test.js`

**Interfaces:**
- Consumes: `BatchRefreshHandler`（Task 3）、`QueueMaintenance::OPTION_LAST_COMPLETED`（Task 6）
- Produces: なし（配線のみ）

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_バッチアクションのハンドラが登録される(): void {
	WP_Mock::expectActionAdded( Enqueuer::HOOK_REFRESH_BATCH, Mockery::type( 'array' ), 10, 1 );

	Plugin::boot();
}
```

```javascript
it( '最終掃引時刻を表示する', () => {
	render( <QueuePanel /> );
	// fetch モックが last_sweep_completed_at を返す前提
	expect( screen.getByText( /最後の掃引/ ) ).toBeInTheDocument();
} );
```

- [ ] **Step 2: テストを実行して失敗を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit tests/Unit/PluginTest.php`
Run: `npm run test:js`
Expected: いずれも FAIL

- [ ] **Step 3: 最小の実装を書く**

`Plugin.php` にバッチハンドラを配線する（既存の `RefreshHandler` 配線の隣）。

```php
		$batchHandler = new BatchRefreshHandler( $enqueuer, new RateLimiter(), new ListingRefresher( $providers, $repository ), $providers );
		add_action(
			Enqueuer::HOOK_REFRESH_BATCH,
			static function ( $account, $items ) use ( $batchHandler ): void {
				$batchHandler->handle(
					array(
						'account' => (string) $account,
						'items'   => is_array( $items ) ? $items : array(),
					)
				);
			},
			10,
			2
		);
```

sweep も AS アクション化し、`affilicard_refresh_all`（WP-Cron）は開始ジョブを積むだけにする。sweep ジョブが `false`（未完走）を返したら継続ジョブを積む。

`QueueController::stats()` の応答に追加する。

```php
				'last_sweep_completed_at' => (string) get_option( QueueMaintenance::OPTION_LAST_COMPLETED, '' ),
```

`QueuePanel.jsx` に表示を足す。`refresh_interval_hours` の 3 倍を超えていたら警告文と運用ドキュメントへのリンクを出す。

- [ ] **Step 4: テストを実行して成功を確認する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit`
Run: `npm run test:js && npm run lint:js && npm run build`
Expected: すべて PASS

- [ ] **Step 5: コミット**

```bash
git add src/Plugin.php src/Rest/QueueController.php src/Admin/components/QueuePanel.jsx build tests/
git commit -m "feat: バッチハンドラを配線し掃引の健全性を表示する"
```

---

## Task 13: 運用ドキュメントと設定画面からの導線

**Files:**
- Modify: `docs/operations-refresh-queue.md`
- Modify: `src/Admin/components/GeneralPanel.jsx`（cron トグル付近にリンク）

**Interfaces:**
- Consumes: なし
- Produces: なし

- [ ] **Step 1: ドキュメントを追記する**

既存の cron セクションに以下を足す。

- **WP-Cron のままで足りるか、サーバー cron へ移すべきかの判断基準**: 管理画面の「最後の掃引」が `refresh_interval_hours` の 3 倍以上前を指している場合はサーバー cron を検討する
- **落とし穴**: `DISABLE_WP_CRON=true` にすると掃引の起点である WP-Cron イベント（`affilicard_refresh_all`）自体が発火しなくなる。OS cron には `wp action-scheduler run`（キューの実行）**に加えて** `wp cron event run --due-now`（掃引イベントの発火）も並べて登録する必要がある。どちらか一方では継続更新が回らない

```cron
* * * * * cd /path/to/wp && /usr/bin/wp action-scheduler run --batches=1 >/dev/null 2>&1
*/5 * * * * cd /path/to/wp && /usr/bin/wp cron event run --due-now >/dev/null 2>&1
```

- [ ] **Step 2: 設定画面から導線を張る**

`GeneralPanel.jsx` の cron トグルの説明文に、運用ドキュメントへのリンクを追加する。現状はドキュメントが存在しても管理画面から辿れず利用者が到達できない。

- [ ] **Step 3: ビルドとテスト**

Run: `npm run lint:js && npm run test:js && npm run build`
Expected: PASS

- [ ] **Step 4: コミット**

```bash
git add docs/operations-refresh-queue.md src/Admin/components/GeneralPanel.jsx build
git commit -m "docs: サーバー cron の判断基準と落とし穴を追記する"
```

---

## Task 14: リリース準備（v3.5.0）

**Files:**
- Modify: `affilicard.php`（Version ヘッダ）
- Modify: `package.json` / `composer.json`（バージョン）
- Modify: `CHANGELOG.md`
- Modify: `readme.txt`（存在する場合の Stable tag）

- [ ] **Step 1: バージョンを 3.5.0 へ同期する**

`affilicard.php` の Version ヘッダを含む**すべての箇所**を更新する。PUC はタグのツリーのヘッダを読むため、ここが古いままだと自動更新が検知されない。

Run: `grep -rn "3\.4\.0" affilicard.php package.json composer.json readme.txt 2>/dev/null`
Expected: 該当箇所がすべて 3.5.0 になっていること

- [ ] **Step 2: CHANGELOG を書く**

```markdown
## [3.5.0] - 2026-XX-XX

### Changed

- **価格更新を account 単位のバッチジョブへ変更**: 1 ジョブが複数 listing を担当し、ジョブ内でレート間隔を守りながら順次取得する。従来の 1 listing = 1 ジョブ方式では、Action Scheduler がバッチでジョブをまとめて実行する一方でレート制限が 1 秒に 1 件しか通さないため、大半のジョブが取得せずに完了していた（実測で完了 27,600 件中およそ 595 件しか取得できていなかった）。失敗した listing は従来どおり個別ジョブへ落ち、リトライ・失敗表示はそのまま機能する
- **掃引を分割実行に変更**: 公開商品を一定件数ずつ走査し、続きは次のジョブへ引き継ぐ。商品数が増えても 1 回の実行時間が伸びない

### Added

- **棚卸し**: 記事に掲載されなくなって一定期間が過ぎた商品の自動更新を停止する。既定は 180 日で、設定から無効化・期間変更ができる。記事を更新すれば対象から戻る
- 商品一覧に「最終掲載日」列を追加（ソート可能）
- 更新キュー画面に最後の掃引時刻を表示。長時間実行されていない場合は警告する

### Fixed

- 既に予約済みのジョブと重複した場合に、キューの深さ上限の枠を誤って消費していた問題を修正
```

- [ ] **Step 3: 全テストと lint を実行する**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit`
Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpcs`
Run: `npm run lint:js && npm run test:js && npm run build`
Expected: すべて PASS

- [ ] **Step 4: コミット**

```bash
git add -A
git commit -m "chore: リリース 3.5.0"
```

---

## 実装順序の注意

- **Task 6（sweep 改修）は Task 10（StocktakePolicy）に依存する**。Task 6 内で棚卸し判定を呼ぶため、Task 10 を先に実施するか、Task 6 では該当行をコメントアウトして Task 10 で有効化する
- Task 12（配線）は Task 3・7 の完了後でなければ動作確認できない
- Task 4（HTTP タイムアウト）は独立しているため、いつ実施してもよい

## 本番検証（マージ前に実施）

ローカル Mac は PHP 非導入のため、E2E は wp-env または本番で行う。

1. wp-env で商品を 50 件程度作り、掃引を手動実行してバッチジョブが積まれることを確認する
2. Action Scheduler の一覧で、`affilicard_refresh_batch` が少数（商品数 ÷ 22 程度）だけ積まれ、`affilicard_refresh_listing` が失敗分だけであることを確認する
3. 完了アクション数が実際の取得件数と同じオーダーになっていること（空振りが消えたこと）を確認する
4. 棚卸し期間を 1 日に設定し、対象商品が掃引から除外されることを確認する。期間を戻すと対象へ復帰することも確認する
5. 商品一覧で「最終掲載日」列のソートが機能することを確認する
