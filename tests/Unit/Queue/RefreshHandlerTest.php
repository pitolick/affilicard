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

	public function test_handle_pause中は何もしない(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array( 'queue_paused' => true ) );

		// provider 解決・fetch は呼ばれない
		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldNotReceive( 'refreshOne' );

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
