<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Cron;

use Affilicard\Cron\RefreshScheduler;
use Affilicard\Settings\GeneralSettings;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class RefreshSchedulerTest extends TestCase {
	private const LEGACY_HOOK = 'affilicard_refresh_platform';

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $t ) => $t );
	}
	public function tearDown(): void {
		WP_Mock::tearDown();
		\Mockery::close();
		parent::tearDown();
	}

	/** affilicard_general option をスタブ（cron_enabled + refresh_interval_hours）。 */
	private function stubGeneral( bool $cronEnabled, int $hours ): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn(
				array(
					'cron_enabled'           => $cronEnabled,
					'refresh_interval_hours' => $hours,
				)
			);
	}

	public function test_addSchedules_グローバル間隔を1本だけ登録する(): void {
		$this->stubGeneral( true, 3 );

		$out = RefreshScheduler::addSchedules( array() );

		$this->assertCount( 1, $out );
		$this->assertArrayHasKey( 'affilicard_ivl_3h', $out );
		$this->assertSame( 3 * 3600, $out['affilicard_ivl_3h']['interval'] );
	}

	public function test_addSchedules_既存のschedulesを保持する(): void {
		$this->stubGeneral( true, 3 );
		$existing = array(
			'hourly' => array(
				'interval' => 3600,
				'display'  => 'Once Hourly',
			),
		);

		$out = RefreshScheduler::addSchedules( $existing );

		$this->assertArrayHasKey( 'hourly', $out );
		$this->assertArrayHasKey( 'affilicard_ivl_3h', $out );
	}

	public function test_reconcile_cron_enabled時にHOOK_ALLを登録し旧hookを解除する(): void {
		$this->stubGeneral( true, 3 );
		WP_Mock::userFunction( 'wp_get_schedule' )->with( RefreshScheduler::HOOK_ALL, array() )->andReturn( false );
		WP_Mock::userFunction( 'wp_unschedule_hook' )->once()->with( self::LEGACY_HOOK )->andReturn( 1 );
		// time() は PHP 組み込みのため WP_Mock でオーバーライド不可。引数は Mockery::type で緩く検証する。
		WP_Mock::userFunction( 'wp_schedule_event' )->once()->with( \Mockery::type( 'int' ), 'affilicard_ivl_3h', RefreshScheduler::HOOK_ALL, array() )->andReturn( true );

		RefreshScheduler::reconcile();

		$this->assertConditionsMet();
	}

	public function test_reconcile_間隔が変わったらrescheduleする(): void {
		$this->stubGeneral( true, 3 );
		WP_Mock::userFunction( 'wp_get_schedule' )->with( RefreshScheduler::HOOK_ALL, array() )->andReturn( 'affilicard_ivl_24h' );
		WP_Mock::userFunction( 'wp_unschedule_hook' )->once()->with( self::LEGACY_HOOK )->andReturn( 1 );
		WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->once()->with( RefreshScheduler::HOOK_ALL, array() )->andReturn( 1 );
		WP_Mock::userFunction( 'wp_schedule_event' )->once()->with( \Mockery::type( 'int' ), 'affilicard_ivl_3h', RefreshScheduler::HOOK_ALL, array() )->andReturn( true );

		RefreshScheduler::reconcile();

		$this->assertConditionsMet();
	}

	public function test_reconcile_間隔が一致していればnoopにする(): void {
		$this->stubGeneral( true, 168 );
		WP_Mock::userFunction( 'wp_get_schedule' )->with( RefreshScheduler::HOOK_ALL, array() )->andReturn( 'affilicard_ivl_168h' );
		WP_Mock::userFunction( 'wp_unschedule_hook' )->once()->with( self::LEGACY_HOOK )->andReturn( 1 );
		WP_Mock::userFunction( 'wp_schedule_event' )->never();
		WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->never();

		RefreshScheduler::reconcile();

		$this->assertConditionsMet();
	}

	public function test_reconcile_master_offで全解除する(): void {
		$this->stubGeneral( false, 168 );
		WP_Mock::userFunction( 'wp_get_schedule' )->with( RefreshScheduler::HOOK_ALL, array() )->andReturn( 'affilicard_ivl_168h' );
		WP_Mock::userFunction( 'wp_unschedule_hook' )->once()->with( self::LEGACY_HOOK )->andReturn( 1 );
		WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->once()->with( RefreshScheduler::HOOK_ALL, array() )->andReturn( 1 );

		RefreshScheduler::reconcile();

		$this->assertConditionsMet();
	}

	public function test_clear_HOOK_ALLと旧hookを両方解除する(): void {
		WP_Mock::userFunction( 'wp_unschedule_hook' )->once()->with( RefreshScheduler::HOOK_ALL )->andReturn( 1 );
		WP_Mock::userFunction( 'wp_unschedule_hook' )->once()->with( self::LEGACY_HOOK )->andReturn( 1 );

		RefreshScheduler::clear();

		$this->assertConditionsMet();
	}

	public function test_scheduleName_時間からカスタムスケジュール名を作る(): void {
		$this->assertSame( 'affilicard_ivl_3h', RefreshScheduler::scheduleName( 3 ) );
	}

	public function test_scheduleName_0以下は1にクランプする(): void {
		$this->assertSame( 'affilicard_ivl_1h', RefreshScheduler::scheduleName( 0 ) );
		$this->assertSame( 'affilicard_ivl_1h', RefreshScheduler::scheduleName( -5 ) );
	}

	public function test_register_cron_schedulesフィルタとHOOK_ALLアクションを配線する(): void {
		WP_Mock::expectFilterAdded( 'cron_schedules', array( RefreshScheduler::class, 'addSchedules' ) );
		WP_Mock::expectActionAdded( RefreshScheduler::HOOK_ALL, 'strlen' );

		RefreshScheduler::register( 'strlen' );

		$this->assertConditionsMet();
	}
}
