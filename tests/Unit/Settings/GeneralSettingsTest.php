<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Settings;

use Affilicard\Settings\GeneralSettings;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class GeneralSettingsTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_get_returns_defaults_when_option_missing(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		$result = GeneralSettings::get();

		$this->assertSame( 86400, $result['cache_ttl_seconds'] );
		$this->assertSame( 'generic', $result['default_product_type'] );
		// 既定 ON（自動更新は中核機能・未設定なら空回りで無害）。
		$this->assertTrue( $result['cron_enabled'] );
		$this->assertSame( 3, $result['refresh_interval_hours'] );
		$this->assertSame( 2, $result['schema_version'] );
	}

	public function test_get_merges_defaults_with_stored_values(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn(
				array(
					'cache_ttl_seconds' => 3600,
					'cron_enabled'      => true,
				)
			);

		$result = GeneralSettings::get();

		$this->assertSame( 3600, $result['cache_ttl_seconds'] );
		$this->assertTrue( $result['cron_enabled'] );
		$this->assertSame( 'generic', $result['default_product_type'] );
		$this->assertSame( 3, $result['refresh_interval_hours'] );
		$this->assertSame( 2, $result['schema_version'] );
	}

	public function test_update_clamps_cache_ttl_seconds_and_validates_default_product_type(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( GeneralSettings::OPTION_KEY, $key );
					$this->assertFalse( $autoload );
					// 30 days max
					$this->assertSame( 2592000, $value['cache_ttl_seconds'] );
					// invalid type fallback to default
					$this->assertSame( 'generic', $value['default_product_type'] );
					$this->assertTrue( $value['cron_enabled'] );
					return true;
				}
			);

		$result = GeneralSettings::update(
			array(
				'cache_ttl_seconds'    => 99999999,
				'default_product_type' => 'invalid-type',
				'cron_enabled'         => 1,
			)
		);

		$this->assertSame( 2592000, $result['cache_ttl_seconds'] );
		$this->assertSame( 'generic', $result['default_product_type'] );
		$this->assertTrue( $result['cron_enabled'] );
	}

	public function test_update_clamps_minimum_cache_ttl_seconds(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( 60, $value['cache_ttl_seconds'] );
					return true;
				}
			);

		$result = GeneralSettings::update( array( 'cache_ttl_seconds' => 1 ) );
		$this->assertSame( 60, $result['cache_ttl_seconds'] );
	}

	public function test_update_accepts_ebook_as_default_product_type(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( 'ebook', $value['default_product_type'] );
					return true;
				}
			);

		$result = GeneralSettings::update( array( 'default_product_type' => 'ebook' ) );
		$this->assertSame( 'ebook', $result['default_product_type'] );
	}

	public function test_update_preserves_refresh_interval_hours(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( 6, $value['refresh_interval_hours'] );
					return true;
				}
			);

		$result = GeneralSettings::update( array( 'refresh_interval_hours' => 6 ) );
		$this->assertSame( 6, $result['refresh_interval_hours'] );
	}

	public function test_update_forces_refresh_interval_hours_below_minimum_to_default(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( 3, $value['refresh_interval_hours'] );
					return true;
				}
			);

		$result = GeneralSettings::update( array( 'refresh_interval_hours' => 0 ) );
		$this->assertSame( 3, $result['refresh_interval_hours'] );
	}

	public function test_update_forces_negative_refresh_interval_hours_to_default(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( 3, $value['refresh_interval_hours'] );
					return true;
				}
			);

		$result = GeneralSettings::update( array( 'refresh_interval_hours' => -5 ) );
		$this->assertSame( 3, $result['refresh_interval_hours'] );
	}

	public function test_update_forces_non_numeric_refresh_interval_hours_to_default(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( 3, $value['refresh_interval_hours'] );
					return true;
				}
			);

		$result = GeneralSettings::update( array( 'refresh_interval_hours' => 'not-a-number' ) );
		$this->assertSame( 3, $result['refresh_interval_hours'] );
	}

	public function test_is_cron_enabled_reflects_option(): void {
		WP_Mock::userFunction( 'get_option' )->with( GeneralSettings::OPTION_KEY, array() )->andReturn( array( 'cron_enabled' => true ) );
		$this->assertTrue( GeneralSettings::isCronEnabled() );
	}

	public function test_is_cron_enabled_defaults_true(): void {
		// 既定 ON（自動更新は中核機能）。保存済みで false のサイトは影響を受けない。
		WP_Mock::userFunction( 'get_option' )->with( GeneralSettings::OPTION_KEY, array() )->andReturn( array() );
		$this->assertTrue( GeneralSettings::isCronEnabled() );
	}

	public function test_is_cron_enabled_reflects_saved_false(): void {
		// 既に false を保存済みのサイトは既定変更の影響を受けず OFF のまま。
		WP_Mock::userFunction( 'get_option' )->with( GeneralSettings::OPTION_KEY, array() )->andReturn( array( 'cron_enabled' => false ) );
		$this->assertFalse( GeneralSettings::isCronEnabled() );
	}

	public function test_refresh_interval_hours_reflects_option(): void {
		WP_Mock::userFunction( 'get_option' )->with( GeneralSettings::OPTION_KEY, array() )->andReturn( array( 'refresh_interval_hours' => 6 ) );
		$this->assertSame( 6, GeneralSettings::refreshIntervalHours() );
	}

	public function test_refresh_interval_hours_defaults_to_three(): void {
		WP_Mock::userFunction( 'get_option' )->with( GeneralSettings::OPTION_KEY, array() )->andReturn( array() );
		$this->assertSame( 3, GeneralSettings::refreshIntervalHours() );
	}

	public function test_defaults_キュー設定の既定値(): void {
		WP_Mock::userFunction( 'get_option' )->with( GeneralSettings::OPTION_KEY, array() )->andReturn( array() );
		$this->assertFalse( GeneralSettings::isQueuePaused() );
		$this->assertSame( 500, GeneralSettings::queueDepthCap() );
		$this->assertSame( 24, GeneralSettings::retentionDoneHours() );
		$this->assertSame( 7, GeneralSettings::retentionFailedDays() );
		$this->assertSame( 0, GeneralSettings::throttleOverrideMs( 'rakuten' ) );
	}

	public function test_throttleOverrideMs_account別に返す(): void {
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

	public function test_update_キュー設定がsanitizeを経てupdate_optionに残る(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( GeneralSettings::OPTION_KEY, $key );
					$this->assertFalse( $autoload );
					$this->assertTrue( $value['queue_paused'] );
					$this->assertSame( 1000, $value['queue_depth_cap'] );
					$this->assertSame( array( 'rakuten' => 1500 ), $value['throttle_overrides'] );
					$this->assertSame( 48, $value['retention_done_hours'] );
					$this->assertSame( 14, $value['retention_failed_days'] );
					return true;
				}
			);

		$result = GeneralSettings::update(
			array(
				'queue_paused'          => true,
				'queue_depth_cap'       => 1000,
				'throttle_overrides'    => array( 'rakuten' => 1500 ),
				'retention_done_hours'  => 48,
				'retention_failed_days' => 14,
			)
		);

		// update() の戻り値（sanitize 後）でも新キーが生き残っていることを確認する。
		$this->assertTrue( $result['queue_paused'] );
		$this->assertSame( 1000, $result['queue_depth_cap'] );
		$this->assertSame( array( 'rakuten' => 1500 ), $result['throttle_overrides'] );
		$this->assertSame( 48, $result['retention_done_hours'] );
		$this->assertSame( 14, $result['retention_failed_days'] );
	}

	public function test_update_queue_depth_capの最小値は1にクランプされる(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( 1, $value['queue_depth_cap'] );
					return true;
				}
			);

		$result = GeneralSettings::update( array( 'queue_depth_cap' => 0 ) );
		$this->assertSame( 1, $result['queue_depth_cap'] );
	}

	public function test_update_throttle_overridesの負値は0にクランプされる(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( array( 'rakuten' => 0 ), $value['throttle_overrides'] );
					return true;
				}
			);

		$result = GeneralSettings::update( array( 'throttle_overrides' => array( 'rakuten' => -100 ) ) );
		$this->assertSame( array( 'rakuten' => 0 ), $result['throttle_overrides'] );
	}

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
}
