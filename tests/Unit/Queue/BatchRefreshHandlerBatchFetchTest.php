<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Cron\ListingRefresher;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Queue\AccountPause;
use Affilicard\Queue\BatchRefreshHandler;
use Affilicard\Queue\Enqueuer;
use Affilicard\Queue\RateLimiter;
use Affilicard\Queue\RunnerClock;
use Affilicard\Queue\WorkOutcome;
use Affilicard\Repository\ProductRepositoryInterface;
use Affilicard\Settings\GeneralSettings;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * まとめ取得できる Provider（BatchFetchingProvider）向けの BatchRefreshHandler の挙動
 * （設計 2026-11-24 §4-3）。既存 Provider 向けの挙動は BatchRefreshHandlerTest が守る。
 */
final class BatchRefreshHandlerBatchFetchTest extends TestCase {

	/** @var array<string, mixed> account の一時停止（AccountPause）の記録。 */
	private array $pauseOptions = array();

	/** @var int 停止の記録のキャッシュを捨てた回数（別のワーカーが書いた停止を読み直したか）。 */
	private int $pauseCachePurges = 0;

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		// 一時失敗の後などの停止の判定は、リクエスト内のキャッシュを捨ててから読む（AccountPause::until の $fresh）。
		$this->pauseCachePurges = 0;
		WP_Mock::userFunction( 'wp_cache_delete' )
			->with( \Mockery::pattern( '/^affilicard_account_pause_/' ), 'options' )
			->andReturnUsing(
				function () {
					++$this->pauseCachePurges;
					return true;
				}
			);
		WP_Mock::userFunction( 'wp_cache_get' )->with( 'notoptions', 'options' )->andReturn( false );
		// account の一時停止（AccountPause）は記録なし＝止めていない。
		WP_Mock::userFunction( 'get_option' )
			->with( \Mockery::pattern( '/^affilicard_account_pause_/' ), null )
			->andReturnUsing( fn( $key ) => $this->pauseOptions[ $key ] ?? null );
		RunnerClock::set( null );
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		WP_Mock::userFunction( 'delete_transient' )->andReturn( true );
		WP_Mock::userFunction( 'add_option' )->andReturn( true );
		WP_Mock::userFunction( 'wp_cache_delete' )->andReturn( true );
	}

	public function tearDown(): void {
		RunnerClock::set( null );
		WP_Mock::tearDown();
		unset( $GLOBALS['wpdb'] );
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * RateLimiter::tryAcquire() の CAS（$wpdb->query）がちょうど $times 回呼ばれることを検証する。
	 */
	private function expectRateAcquisitions( int $times ): void {
		$wpdb          = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( string $query, ...$args ) => $query );
		$wpdb->shouldReceive( 'query' )->times( $times )->andReturn( 1 );
		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * @param list<int> $postIds
	 * @return array{account: string, items: list<array{post_id: int, platform: string}>}
	 */
	private static function args( array $postIds ): array {
		return array(
			'account' => 'amazon',
			'items'   => array_map(
				static fn( int $id ) => array(
					'post_id'  => $id,
					'platform' => 'amazon-kindle',
				),
				$postIds
			),
		);
	}

	private static function asin( int $postId ): string {
		return sprintf( 'B%09d', $postId );
	}

	/**
	 * 外部 ID = post_id から作った ASIN、refreshOne() は Provider の fetch() を呼んで
	 * 実 ListingRefresher と同じく「覚えた結果があれば HTTP しない」経路を通す。
	 */
	private function refresher( FakeBatchProvider $provider ): ListingRefresher {
		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'fetchTargetExternalIds' )->andReturnUsing(
			static fn( int $postId ) => array( self::asin( $postId ) )
		);
		$refresher->shouldReceive( 'refreshOne' )->andReturnUsing(
			static function ( int $postId ) use ( $provider ) {
				$provider->fetch( self::asin( $postId ), array() );
				return WorkOutcome::SUCCESS;
			}
		);
		return $refresher;
	}

	public function test_レート枠1つで10件をまとめて取り_残りは取得済みの結果で処理する(): void {
		$this->expectRateAcquisitions( 2 ); // 12 件 → 10 件 + 2 件の 2 リクエスト
		$provider = new FakeBatchProvider();
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$handler = new BatchRefreshHandler( new Enqueuer(), new RateLimiter(), $this->refresher( $provider ), $registry, 30, 5 );
		$handler->handle( self::args( range( 1, 12 ) ) );

		$this->assertSame(
			array(
				array_map( array( self::class, 'asin' ), range( 1, 10 ) ),
				array_map( array( self::class, 'asin' ), range( 11, 12 ) ),
			),
			$provider->prefetchCalls
		);
		// 個別の HTTP は 1 回も走らない。
		$this->assertSame( 0, $provider->singleFetches );
		// ジョブの開始時と終了時に覚えた結果を捨てる（別ジョブへ持ち越さない）。
		$this->assertSame( 2, $provider->clearCalls );
		$this->assertFalse( $provider->hasPrefetched( self::asin( 1 ) ) );
	}

	/**
	 * バージョン違いが 1 リクエストに載る件数（10）より多い listing は、先取りを 10 件までにする。
	 * prefetch() は複数のリクエストを間を空けずに送るので、上限を超えて渡すと 2 回目が
	 * レート制限の枠なしで出てしまう。残りは ListingRefresher が 1 件ずつ枠を取って取る。
	 */
	public function test_1つのlistingのバージョン違いが10件を超えても先取りは10件まで(): void {
		$this->expectRateAcquisitions( 1 );
		$provider = new FakeBatchProvider();
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$ids       = array_map( array( self::class, 'asin' ), range( 101, 112 ) );
		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'fetchTargetExternalIds' )->andReturn( $ids );
		$refresher->shouldReceive( 'refreshOne' )->once()->andReturn( WorkOutcome::SUCCESS );

		$handler = new BatchRefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $registry, 30, 5 );
		$handler->handle( self::args( array( 1 ) ) );

		$this->assertSame( array( array_slice( $ids, 0, 10 ) ), $provider->prefetchCalls );
	}

	public function test_外部IDの無いlistingはまとめ取得に含めない(): void {
		$this->expectRateAcquisitions( 2 ); // 外部 ID の無い 2 は従来どおり枠を取る
		$provider = new FakeBatchProvider();
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'fetchTargetExternalIds' )->andReturnUsing(
			static fn( int $postId ) => 2 === $postId ? array() : array( self::asin( $postId ) )
		);
		$refresher->shouldReceive( 'refreshOne' )->times( 3 )->andReturn( WorkOutcome::SUCCESS );

		$handler = new BatchRefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $registry, 30, 5 );
		$handler->handle( self::args( array( 1, 2, 3 ) ) );

		$this->assertSame( array( array( self::asin( 1 ), self::asin( 3 ) ) ), $provider->prefetchCalls );
	}

	private function pauseAmazonUntil( int $until ): void {
		$this->pauseOptions[ AccountPause::OPTION_PREFIX . 'amazon' ] = array(
			'until'  => $until,
			'reason' => AccountPause::REASON_RATE_LIMITED,
			'since'  => time() - 60,
			'streak' => 1,
		);
	}

	public function test_止めている間は1件も取らず_全件を再開時刻にまとめて積み直す(): void {
		$this->expectRateAcquisitions( 0 );
		$until = time() + 600;
		$this->pauseAmazonUntil( $until );
		$provider = new FakeBatchProvider();
		$registry = new ProviderRegistry();
		$registry->register( $provider );
		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'fetchTargetExternalIds' )->andReturnUsing(
			static fn( int $postId ) => array( self::asin( $postId ) )
		);
		$refresher->shouldReceive( 'refreshOne' )->never();
		WP_Mock::userFunction( 'wp_rand' )->andReturn( 0 );
		// 1 件ずつのジョブへは回さない。
		WP_Mock::userFunction( 'as_enqueue_async_action' )->never();
		$scheduled = array();
		WP_Mock::userFunction( 'as_schedule_single_action' )->andReturnUsing(
			static function ( $when, $hook, $args ) use ( &$scheduled ) {
				$scheduled[] = array( $when, count( $args[0]['items'] ?? $args['items'] ?? array() ) );
				return 1;
			}
		);

		$handler = new BatchRefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $registry, 30, 5 );
		$handler->handle( self::args( range( 1, 12 ) ) );

		$this->assertSame( array(), $provider->prefetchCalls );
		$this->assertCount( 1, $scheduled );
		$this->assertSame( $until, $scheduled[0][0] );
		$this->assertSame( 12, $scheduled[0][1] );
	}

	/**
	 * まとめ取得の結果が手元にある購入リンクは、その後に（別のジョブが受けた 429 で）止まっても
	 * そのまま使う。捨てて積み直すと、再開後に同じものを取り直して上限を無駄に使う。
	 * 手元に結果が無い残りだけを再開時刻へ積み直す。
	 */
	public function test_止まっても手元にあるまとめ取得の結果は使い_無い残りだけ積み直す(): void {
		$this->expectRateAcquisitions( 1 );
		$until    = time() + 300;
		$provider = new FakeBatchProvider();
		$registry = new ProviderRegistry();
		$registry->register( $provider );
		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'fetchTargetExternalIds' )->andReturnUsing(
			static fn( int $postId ) => array( self::asin( $postId ) )
		);
		$refreshed = array();
		$refresher->shouldReceive( 'refreshOne' )->andReturnUsing(
			function ( int $postId ) use ( &$refreshed, $until ) {
				$refreshed[] = $postId;
				if ( 1 === $postId ) {
					// 1 件目（10 件をまとめて取った直後）の最中に、別のジョブが 429 を受けて止めた。
					$this->pauseAmazonUntil( $until );
				}
				return WorkOutcome::SUCCESS;
			}
		);
		WP_Mock::userFunction( 'wp_rand' )->andReturn( 0 );
		WP_Mock::userFunction( 'as_enqueue_async_action' )->never();
		$scheduled = array();
		WP_Mock::userFunction( 'as_schedule_single_action' )->andReturnUsing(
			static function ( $when, $hook, $args ) use ( &$scheduled ) {
				$scheduled[] = array( $when, count( $args[0]['items'] ?? $args['items'] ?? array() ) );
				return 1;
			}
		);

		$handler = new BatchRefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $registry, 30, 5 );
		$handler->handle( self::args( range( 1, 12 ) ) );

		$this->assertCount( 1, $provider->prefetchCalls );
		$this->assertSame( range( 1, 10 ), $refreshed );
		$this->assertCount( 1, $scheduled );
		$this->assertSame( $until, $scheduled[0][0] );
		$this->assertSame( 2, $scheduled[0][1] );
	}

	public function test_取得の途中で止まったら_その件を含む残りを再開時刻にまとめて積み直す(): void {
		$this->expectRateAcquisitions( 1 );
		$until    = time() + 120;
		$provider = new FakeBatchProvider();
		$registry = new ProviderRegistry();
		$registry->register( $provider );
		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'fetchTargetExternalIds' )->andReturnUsing(
			static fn( int $postId ) => array( self::asin( $postId ) )
		);
		// 1 件目の取得で 429 を受けて account が止まった、という状況を作る。
		$purgesBeforeFailure = 0;
		$refresher->shouldReceive( 'refreshOne' )->once()->andReturnUsing(
			function () use ( $until, &$purgesBeforeFailure ) {
				$this->pauseAmazonUntil( $until );
				// API を呼ぶ前の読み直しと区別するため、失敗した時点の回数を覚えておく。
				$purgesBeforeFailure = $this->pauseCachePurges;
				return WorkOutcome::TRANSIENT_FAILURE;
			}
		);
		WP_Mock::userFunction( 'wp_rand' )->andReturn( 0 );
		WP_Mock::userFunction( 'as_enqueue_async_action' )->never();
		$scheduled = array();
		WP_Mock::userFunction( 'as_schedule_single_action' )->andReturnUsing(
			static function ( $when, $hook, $args ) use ( &$scheduled ) {
				$scheduled[] = array( $when, count( $args[0]['items'] ?? $args['items'] ?? array() ) );
				return 1;
			}
		);

		$handler = new BatchRefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $registry, 30, 5 );
		$handler->handle( self::args( range( 1, 12 ) ) );

		$this->assertCount( 1, $scheduled );
		$this->assertSame( $until, $scheduled[0][0] );
		$this->assertSame( 12, $scheduled[0][1] );
		// 応答を待つ間に別のワーカーが止めた場合も見落とさないよう、一時失敗の後はキャッシュを捨てて読む。
		$this->assertGreaterThan( $purgesBeforeFailure, $this->pauseCachePurges );
	}

	/**
	 * ハンドラ経路の結合テスト（実際の ListingRefresher）: 1 つの listing にバージョン違いが 11 件ある。
	 * 枠は 2 回取る（ハンドラが先取りの 1 回、ListingRefresher が 1 リクエストに載らなかった 11 件目の 1 回）。
	 * 先取りは 10 件、個別の HTTP は 11 件目の 1 回だけ（CodeRabbit #207）。
	 */
	public function test_バージョン違いが11件のlistingは先取り10件と11件目で2回枠を取る(): void {
		$this->expectRateAcquisitions( 2 );
		$provider = new FakeBatchProvider();
		$registry = new ProviderRegistry();
		$registry->register( $provider );
		WP_Mock::userFunction( 'get_option' )
			->with( \Affilicard\Platform\PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'            => 'amazon-kindle',
						'name'            => 'Amazon',
						'provider'        => 'fake-batch',
						'displayOrder'    => 1,
						'enabled'         => true,
						'applicableTypes' => array( 'ebook' ),
					),
				)
			);
		WP_Mock::userFunction( 'get_option' )
			->with( \Affilicard\Upgrade\PluginUpgrade::OPTION_MIGRATION_CURSOR, false )
			->andReturn( false );

		$offers = array();
		foreach ( range( 1, 11 ) as $n ) {
			$offers[] = array(
				'display_order' => 100,
				'external_id'   => sprintf( 'B0VARIANT%02d', $n ),
				'variant_label' => '版' . $n,
			);
		}
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 1 )->andReturn(
			array(
				'id'       => 1,
				'title'    => '11 バージョン',
				'listings' => array(
					array(
						'platform'    => 'amazon-kindle',
						'enabled'     => true,
						'auto_update' => true,
						'update_mode' => 'auto',
						'offers'      => $offers,
					),
				),
			)
		);
		$repo->shouldReceive( 'updateListingOffer' )->times( 11 )->andReturn( true );

		$refresher = new ListingRefresher( $registry, $repo, static function (): void {}, new RateLimiter() );
		$handler   = new BatchRefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $registry, 30, 5 );
		$handler->handle( self::args( array( 1 ) ) );

		$this->assertCount( 1, $provider->prefetchCalls );
		$this->assertCount( 10, $provider->prefetchCalls[0] );
		$this->assertSame( 1, $provider->singleFetches );
	}
}
