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
	private function platform( string $code, bool $autoRefresh, string $freq ): array {
		return array(
			'code'             => $code,
			'name'             => $code,
			'provider'         => 'dmm-ebook',
			'displayOrder'     => 1,
			'enabled'          => true,
			'applicableTypes'  => array( 'ebook' ),
			'buttonLabel'      => '',
			'brandColor'       => '',
			'buttonTextColor'  => '',
			'autoRefresh'      => $autoRefresh,
			'refreshFrequency' => $freq,
		);
	}

	public function test_schedules_enabled_platform_with_its_frequency(): void {
		$this->stub( true, array( $this->platform( 'dmm-books', true, 'daily' ) ) );
		WP_Mock::userFunction( 'wp_get_schedule' )->with( RefreshScheduler::HOOK, array( 'dmm-books' ) )->andReturn( false );
		// time() は PHP 組み込みのため WP_Mock でオーバーライド不可。引数は Mockery::type で緩く検証する。
		WP_Mock::userFunction( 'wp_schedule_event' )->once()->with( \Mockery::type( 'int' ), 'daily', RefreshScheduler::HOOK, array( 'dmm-books' ) )->andReturn( true );
		RefreshScheduler::reconcile();
		$this->assertConditionsMet();
	}

	public function test_clears_platform_when_auto_refresh_off(): void {
		$this->stub( true, array( $this->platform( 'dmm-books', false, 'weekly' ) ) );
		WP_Mock::userFunction( 'wp_get_schedule' )->with( RefreshScheduler::HOOK, array( 'dmm-books' ) )->andReturn( 'weekly' );
		WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->once()->with( RefreshScheduler::HOOK, array( 'dmm-books' ) )->andReturn( 1 );
		RefreshScheduler::reconcile();
		$this->assertConditionsMet();
	}

	public function test_clears_all_when_master_off(): void {
		$this->stub( false, array( $this->platform( 'dmm-books', true, 'weekly' ) ) );
		WP_Mock::userFunction( 'wp_get_schedule' )->with( RefreshScheduler::HOOK, array( 'dmm-books' ) )->andReturn( 'weekly' );
		WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->once()->with( RefreshScheduler::HOOK, array( 'dmm-books' ) )->andReturn( 1 );
		RefreshScheduler::reconcile();
		$this->assertConditionsMet();
	}

	public function test_reschedules_when_frequency_changed(): void {
		$this->stub( true, array( $this->platform( 'dmm-books', true, 'daily' ) ) );
		WP_Mock::userFunction( 'wp_get_schedule' )->with( RefreshScheduler::HOOK, array( 'dmm-books' ) )->andReturn( 'weekly' );
		WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->once()->with( RefreshScheduler::HOOK, array( 'dmm-books' ) )->andReturn( 1 );
		// time() は PHP 組み込みのため WP_Mock でオーバーライド不可。引数は Mockery::type で緩く検証する。
		WP_Mock::userFunction( 'wp_schedule_event' )->once()->with( \Mockery::type( 'int' ), 'daily', RefreshScheduler::HOOK, array( 'dmm-books' ) )->andReturn( true );
		RefreshScheduler::reconcile();
		$this->assertConditionsMet();
	}

	public function test_noop_when_frequency_matches(): void {
		$this->stub( true, array( $this->platform( 'dmm-books', true, 'weekly' ) ) );
		WP_Mock::userFunction( 'wp_get_schedule' )->with( RefreshScheduler::HOOK, array( 'dmm-books' ) )->andReturn( 'weekly' );
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
}
