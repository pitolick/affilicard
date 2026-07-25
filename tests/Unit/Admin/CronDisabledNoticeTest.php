<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Admin;

use Affilicard\Admin\CronDisabledNotice;
use Affilicard\Platform\PlatformConfig;
use Affilicard\Settings\GeneralSettings;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * 通知の表示条件（shouldShow）を検証する。
 */
final class CronDisabledNoticeTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/** @param array<string, mixed> $general affilicard_general の保存値 */
	private function stubGeneral( array $general ): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( $general );
	}

	/** @param list<array<string, mixed>> $platforms affilicard_platforms の保存値 */
	private function stubPlatforms( array $platforms ): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn( $platforms );
	}

	private function stubUser( int $dismissed ): void {
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
		WP_Mock::userFunction( 'get_user_meta' )
			->with( 7, 'affilicard_cron_notice_dismissed', true )
			->andReturn( $dismissed );
	}

	private function stubScreen( ?string $postType ): void {
		$screen            = new \stdClass();
		$screen->post_type = $postType;
		WP_Mock::userFunction( 'get_current_screen' )->andReturn( null === $postType ? null : $screen );
	}

	private const AUTO_PLATFORM = array(
		array(
			'code'         => 'rakuten-kobo',
			'name'         => '楽天Kobo',
			'provider'     => 'rakuten-kobo',
			'displayOrder' => 1,
			'enabled'      => true,
		),
	);

	private const MANUAL_PLATFORM = array(
		array(
			'code'         => 'amazon-kindle',
			'name'         => 'Amazon',
			'provider'     => 'manual',
			'displayOrder' => 1,
			'enabled'      => true,
		),
	);

	public function test_cron有効なら表示しない(): void {
		$this->stubGeneral( array( 'cron_enabled' => true ) );
		$this->assertFalse( CronDisabledNotice::shouldShow() );
	}

	public function test_全て手動プロバイダなら表示しない(): void {
		$this->stubGeneral( array( 'cron_enabled' => false ) );
		$this->stubPlatforms( self::MANUAL_PLATFORM );
		$this->assertFalse( CronDisabledNotice::shouldShow() );
	}

	public function test_dismiss済みなら表示しない(): void {
		$this->stubGeneral( array( 'cron_enabled' => false ) );
		$this->stubPlatforms( self::AUTO_PLATFORM );
		$this->stubUser( 1 );
		$this->assertFalse( CronDisabledNotice::shouldShow() );
	}

	public function test_affilicard画面以外なら表示しない(): void {
		$this->stubGeneral( array( 'cron_enabled' => false ) );
		$this->stubPlatforms( self::AUTO_PLATFORM );
		$this->stubUser( 0 );
		$this->stubScreen( 'post' ); // 別 CPT の画面
		$this->assertFalse( CronDisabledNotice::shouldShow() );
	}

	public function test_cron無効かつ自動プロバイダありかつaffilicard画面なら表示する(): void {
		$this->stubGeneral( array( 'cron_enabled' => false ) );
		$this->stubPlatforms( self::AUTO_PLATFORM );
		$this->stubUser( 0 );
		$this->stubScreen( 'affilicard_product' );
		$this->assertTrue( CronDisabledNotice::shouldShow() );
	}
}
