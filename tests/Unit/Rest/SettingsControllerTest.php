<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Rest;

use Affilicard\Rest\SettingsController;
use Affilicard\Settings\GeneralSettings;
use WP_Mock;
use WP_Mock\Tools\TestCase;
use WP_REST_Request;

final class SettingsControllerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_get_returns_200_with_merged_settings(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn(
				array(
					'cache_ttl_seconds' => 7200,
				)
			);

		$controller = new SettingsController();
		$request    = new WP_REST_Request( 'GET', '/affilicard/v1/settings' );

		$response = $controller->get( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 7200, $data['cache_ttl_seconds'] );
		$this->assertSame( 'generic', $data['default_product_type'] );
	}

	public function test_update_calls_general_settings_update_and_returns_new_settings(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( GeneralSettings::OPTION_KEY, $key );
					$this->assertSame( 1800, $value['cache_ttl_seconds'] );
					$this->assertTrue( $value['cron_enabled'] );
					return true;
				}
			);

		$controller = new SettingsController();
		$request    = new WP_REST_Request( 'PUT', '/affilicard/v1/settings' );
		$request->set_param( 'cache_ttl_seconds', 1800 );
		$request->set_param( 'cron_enabled', true );

		$response = $controller->update( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 1800, $data['cache_ttl_seconds'] );
		$this->assertTrue( $data['cron_enabled'] );
	}

	public function test_get_returns_stocktake_settings(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn(
				array(
					'stocktake_enabled' => false,
					'stocktake_days'    => 90,
				)
			);

		$controller = new SettingsController();
		$request    = new WP_REST_Request( 'GET', '/affilicard/v1/settings' );

		$response = $controller->get( $request );
		$data     = $response->get_data();

		$this->assertFalse( $data['stocktake_enabled'] );
		$this->assertSame( 90, $data['stocktake_days'] );
	}

	public function test_update_persists_stocktake_settings_with_clamping(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( GeneralSettings::OPTION_KEY, $key );
					$this->assertFalse( $value['stocktake_enabled'] );
					// 0 は GeneralSettings::sanitize() 側で 1 へクランプされる
					// （REST を経由しても抜け道にならないことの確認）。
					$this->assertSame( 1, $value['stocktake_days'] );
					return true;
				}
			);

		$controller = new SettingsController();
		$request    = new WP_REST_Request( 'PUT', '/affilicard/v1/settings' );
		$request->set_param( 'stocktake_enabled', false );
		$request->set_param( 'stocktake_days', 0 );

		$response = $controller->update( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['stocktake_enabled'] );
		$this->assertSame( 1, $data['stocktake_days'] );
	}

	public function test_can_manage_options_checks_current_user_can(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'manage_options' )
			->andReturn( true );

		$controller = new SettingsController();
		$this->assertTrue( $controller->canManageOptions() );
	}
}
