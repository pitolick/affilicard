<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Cron\ListingRefresher;
use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\ProviderInterface;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Queue\Enqueuer;
use Affilicard\Queue\RateLimiter;
use Affilicard\Queue\RefreshHandler;
use Affilicard\Settings\GeneralSettings;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class RefreshHandlerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * isAutomatic=true・minRequestIntervalMs=1100 の 'rakuten' provider を登録した ProviderRegistry。
	 */
	private function registry(): ProviderRegistry {
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'minRequestIntervalMs' )->andReturn( 1100 );

		$registry = new ProviderRegistry();
		$registry->register( $provider );
		return $registry;
	}

	/**
	 * PlatformConfig::find('rakuten-kobo')->provider が 'rakuten' を返すよう
	 * affilicard_platforms option を stub する（実 defaults() の provider は 'rakuten-kobo'
	 * なので、RateLimiter option キー affilicard_ratelimit_rakuten と揃えるためスタブを使う）。
	 */
	private function stubPlatform(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'     => 'rakuten-kobo',
						'provider' => 'rakuten',
					),
				)
			);
	}

	public function test_handle_pause中はfetchせずジョブを再投入して保持する(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array( 'queue_paused' => true ) );
		$this->stubPlatform();

		// fetch は呼ばれない（消費しない）が、ジョブは reschedule で温存される
		// （不再投入だと AS がアクションを complete 扱いにしてジョブが消滅してしまう）。
		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldNotReceive( 'refreshOne' );
		WP_Mock::userFunction( 'as_schedule_single_action' )
			->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 12,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten',
				false,
				Enqueuer::PRIORITY_MANUAL
			)
			->andReturn( 1 ); // rescheduleRefresh によるジョブ温存

		$handler = new RefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry() );
		$handler->handle( 12, 'rakuten-kobo' );

		$this->assertConditionsMet();
	}

	public function test_handle_throttle未経過なら再投入してfetchしない(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$this->stubPlatform();
		// 実行時刻(ms)より確実に未来になる値を使い、実時計に依存せず「間隔未経過」を再現する。
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_ratelimit_rakuten', 0 )
			->andReturn( 9999999999999 );
		WP_Mock::userFunction( 'as_schedule_single_action' )->once(); // rescheduleRefresh

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldNotReceive( 'refreshOne' );

		$handler = new RefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry() );
		$handler->handle( 12, 'rakuten-kobo' );

		$this->assertConditionsMet();
	}

	/**
	 * backoff() が MAX_ATTEMPTS 到達時に「打ち切り（AS complete 相当）」ではなく
	 * 例外を投げることを確認する。AS はハンドラ呼び出しを try/catch しており、
	 * 投げられた例外は failed アクションとして記録される（bare return だと
	 * complete 扱いになり、失敗が可視化されない・パネルの failed 件数/再試行が
	 * 機能しない問題の修正）。attemptKey の transient は打ち切り前に削除され、
	 * reschedule（自己再投入）は呼ばれない。
	 */
	public function test_handle_リトライ上限到達で例外を投げてAS失敗として記録させる(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$this->stubPlatform();
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_ratelimit_rakuten', 0 )
			->andReturn( 0 ); // 経過済
		WP_Mock::userFunction( 'update_option' )
			->with( 'affilicard_ratelimit_rakuten', Mockery::type( 'int' ), false )
			->andReturn( true );

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->once()->with( 12, 'rakuten-kobo' )->andReturn( false );

		// MAX_ATTEMPTS(5) - 1 = 4 が記録済み → 今回の試行で 5 回目 = 上限到達。
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( 'affilicard_refresh_attempts_12_rakuten-kobo' )
			->andReturn( 4 );
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_refresh_attempts_12_rakuten-kobo' )
			->andReturn( true );
		WP_Mock::userFunction( 'set_transient' )->never(); // 打ち切りなので再カウントしない
		WP_Mock::userFunction( 'as_schedule_single_action' )->never(); // reschedule されない（打ち切り）

		$handler = new RefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry() );

		$thrown = null;
		try {
			$handler->handle( 12, 'rakuten-kobo' );
		} catch ( \RuntimeException $e ) {
			$thrown = $e;
		}

		$this->assertInstanceOf( \RuntimeException::class, $thrown );
		$this->assertConditionsMet();
	}

	/**
	 * MAX_ATTEMPTS 未満（打ち切り前）は例外を投げず、従来通り backoff で
	 * 自己再投入（reschedule）することを確認する。
	 */
	public function test_handle_リトライ上限未満なら例外を投げず再投入する(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$this->stubPlatform();
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_ratelimit_rakuten', 0 )
			->andReturn( 0 ); // 経過済
		WP_Mock::userFunction( 'update_option' )
			->with( 'affilicard_ratelimit_rakuten', Mockery::type( 'int' ), false )
			->andReturn( true );

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->once()->with( 12, 'rakuten-kobo' )->andReturn( false );

		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( 'affilicard_refresh_attempts_12_rakuten-kobo' )
			->andReturn( 1 ); // 1回目 → 今回で2回目、MAX_ATTEMPTS(5)未満
		WP_Mock::userFunction( 'set_transient' )
			->once()
			->with( 'affilicard_refresh_attempts_12_rakuten-kobo', 2, DAY_IN_SECONDS )
			->andReturn( true );
		WP_Mock::userFunction( 'delete_transient' )->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )
			->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 12,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten',
				false,
				Enqueuer::PRIORITY_MANUAL
			)
			->andReturn( 1 );

		$handler = new RefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry() );
		$handler->handle( 12, 'rakuten-kobo' );

		$this->assertConditionsMet();
	}

	public function test_handle_取得できればrefreshOneを呼ぶ(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$this->stubPlatform();
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_ratelimit_rakuten', 0 )
			->andReturn( 0 ); // 経過済
		WP_Mock::userFunction( 'update_option' )
			->with( 'affilicard_ratelimit_rakuten', Mockery::type( 'int' ), false )
			->andReturn( true );
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_refresh_attempts_12_rakuten-kobo' )
			->andReturn( true );

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->once()->with( 12, 'rakuten-kobo' )->andReturn( true );

		$handler = new RefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry() );
		$handler->handle( 12, 'rakuten-kobo' );

		$this->assertConditionsMet();
	}
}
