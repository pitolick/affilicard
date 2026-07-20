<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Cron;

use Affilicard\Cron\RefreshScheduler;
use Affilicard\Settings\GeneralSettings;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class RefreshSchedulerTest extends TestCase {
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

	/** cron_enabled と platforms option をまとめてスタブ。 */
	private function stub( bool $master, array $platforms ): void {
		WP_Mock::userFunction( 'get_option' )->with( GeneralSettings::OPTION_KEY, array() )->andReturn( array( 'cron_enabled' => $master ) );
		WP_Mock::userFunction( 'get_option' )->with( 'affilicard_platforms', array() )->andReturn( $platforms );
	}

	/** platforms option のみスタブ（cron_enabled 判定を経由しない addSchedules 用）。 */
	private function stubPlatforms( array $platforms ): void {
		WP_Mock::userFunction( 'get_option' )->with( 'affilicard_platforms', array() )->andReturn( $platforms );
	}

	private function platform( string $code, bool $autoRefresh, int $hours ): array {
		return array(
			'code'                 => $code,
			'name'                 => $code,
			'provider'             => 'dmm-ebook',
			'displayOrder'         => 1,
			'enabled'              => true,
			'applicableTypes'      => array( 'ebook' ),
			'buttonLabel'          => '',
			'brandColor'           => '',
			'buttonTextColor'      => '',
			'autoRefresh'          => $autoRefresh,
			'refreshIntervalHours' => $hours,
		);
	}

	public function test_schedules_enabled_platform_with_its_interval(): void {
		$this->stub( true, array( $this->platform( 'dmm-books', true, 3 ) ) );
		WP_Mock::userFunction( 'wp_get_schedule' )->with( RefreshScheduler::HOOK, array( 'dmm-books' ) )->andReturn( false );
		// time() は PHP 組み込みのため WP_Mock でオーバーライド不可。引数は Mockery::type で緩く検証する。
		WP_Mock::userFunction( 'wp_schedule_event' )->once()->with( \Mockery::type( 'int' ), 'affilicard_ivl_3h', RefreshScheduler::HOOK, array( 'dmm-books' ) )->andReturn( true );
		RefreshScheduler::reconcile();
		$this->assertConditionsMet();
	}

	public function test_clears_platform_when_auto_refresh_off(): void {
		$this->stub( true, array( $this->platform( 'dmm-books', false, 168 ) ) );
		WP_Mock::userFunction( 'wp_get_schedule' )->with( RefreshScheduler::HOOK, array( 'dmm-books' ) )->andReturn( 'affilicard_ivl_168h' );
		WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->once()->with( RefreshScheduler::HOOK, array( 'dmm-books' ) )->andReturn( 1 );
		RefreshScheduler::reconcile();
		$this->assertConditionsMet();
	}

	public function test_clears_all_when_master_off(): void {
		$this->stub( false, array( $this->platform( 'dmm-books', true, 168 ) ) );
		WP_Mock::userFunction( 'wp_get_schedule' )->with( RefreshScheduler::HOOK, array( 'dmm-books' ) )->andReturn( 'affilicard_ivl_168h' );
		WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->once()->with( RefreshScheduler::HOOK, array( 'dmm-books' ) )->andReturn( 1 );
		RefreshScheduler::reconcile();
		$this->assertConditionsMet();
	}

	public function test_reschedules_when_interval_changed(): void {
		$this->stub( true, array( $this->platform( 'dmm-books', true, 3 ) ) );
		WP_Mock::userFunction( 'wp_get_schedule' )->with( RefreshScheduler::HOOK, array( 'dmm-books' ) )->andReturn( 'affilicard_ivl_24h' );
		WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->once()->with( RefreshScheduler::HOOK, array( 'dmm-books' ) )->andReturn( 1 );
		// time() は PHP 組み込みのため WP_Mock でオーバーライド不可。引数は Mockery::type で緩く検証する。
		WP_Mock::userFunction( 'wp_schedule_event' )->once()->with( \Mockery::type( 'int' ), 'affilicard_ivl_3h', RefreshScheduler::HOOK, array( 'dmm-books' ) )->andReturn( true );
		RefreshScheduler::reconcile();
		$this->assertConditionsMet();
	}

	public function test_noop_when_interval_matches(): void {
		$this->stub( true, array( $this->platform( 'dmm-books', true, 168 ) ) );
		WP_Mock::userFunction( 'wp_get_schedule' )->with( RefreshScheduler::HOOK, array( 'dmm-books' ) )->andReturn( 'affilicard_ivl_168h' );
		WP_Mock::userFunction( 'wp_schedule_event' )->never();
		WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->never();
		RefreshScheduler::reconcile();
		$this->assertConditionsMet();
	}

	public function test_clear_unschedules_whole_hook(): void {
		WP_Mock::userFunction( 'wp_unschedule_hook' )->once()->with( RefreshScheduler::HOOK )->andReturn( 1 );
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

	public function test_addSchedules_使用中の間隔を登録する(): void {
		// PlatformConfig::all() が auto=true / interval=3 の platform を返すようスタブ
		// （既存テストの WP_Mock による get_option スタブ方式に合わせて 1 件用意）
		$this->stubPlatforms(
			array(
				array(
					'code'                 => 'rakuten-kobo',
					'provider'             => 'rakuten-kobo',
					'autoRefresh'          => true,
					'refreshIntervalHours' => 3,
				),
			)
		);
		$out = RefreshScheduler::addSchedules( array() );
		$this->assertArrayHasKey( 'affilicard_ivl_3h', $out );
		$this->assertSame( 3 * 3600, $out['affilicard_ivl_3h']['interval'] );
	}

	public function test_addSchedules_autoRefresh無効のplatformはスキップする(): void {
		$this->stubPlatforms(
			array(
				array(
					'code'                 => 'amazon-kindle',
					'provider'             => 'manual',
					'autoRefresh'          => false,
					'refreshIntervalHours' => 24,
				),
			)
		);
		$out = RefreshScheduler::addSchedules( array() );
		$this->assertArrayNotHasKey( 'affilicard_ivl_24h', $out );
	}

	public function test_addSchedules_既存のschedulesを保持する(): void {
		$this->stubPlatforms( array() );
		$existing = array(
			'hourly' => array(
				'interval' => 3600,
				'display'  => 'Once Hourly',
			),
		);
		$out      = RefreshScheduler::addSchedules( $existing );
		$this->assertSame( $existing, $out );
	}

	public function test_register_cron_schedulesフィルタを配線する(): void {
		WP_Mock::expectFilterAdded( 'cron_schedules', array( RefreshScheduler::class, 'addSchedules' ) );
		WP_Mock::expectActionAdded( RefreshScheduler::HOOK, 'strlen', 10, 1 );
		RefreshScheduler::register( 'strlen' );
		$this->assertConditionsMet();
	}
}
