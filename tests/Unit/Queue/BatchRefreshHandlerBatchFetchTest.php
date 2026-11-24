<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Cron\ListingRefresher;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Queue\BatchRefreshHandler;
use Affilicard\Queue\Enqueuer;
use Affilicard\Queue\RateLimiter;
use Affilicard\Queue\RunnerClock;
use Affilicard\Queue\WorkOutcome;
use Affilicard\Settings\GeneralSettings;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * まとめ取得できる Provider（BatchFetchingProvider）向けの BatchRefreshHandler の挙動
 * （設計 2026-11-24 §4-3）。既存 Provider 向けの挙動は BatchRefreshHandlerTest が守る。
 */
final class BatchRefreshHandlerBatchFetchTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
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
}
