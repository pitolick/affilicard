<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Rest;

use Affilicard\Provider\ProviderRegistry;
use Affilicard\Repository\ProductRepository;
use Affilicard\Rest\CredentialsController;
use Affilicard\Rest\PlatformsController;
use Affilicard\Rest\ProductsController;
use Affilicard\Rest\RestController;
use Affilicard\Rest\SettingsController;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class RestControllerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_register_hooks_rest_api_init_and_register_routes_dispatches_to_each_sub_controller(): void {
		$controller = new RestController(
			new ProductsController( new ProductRepository() ),
			new SettingsController(),
			new PlatformsController(),
			new CredentialsController( new ProviderRegistry() )
		);

		WP_Mock::expectActionAdded( 'rest_api_init', array( $controller, 'registerRoutes' ) );

		$controller->register();

		// 各サブコントローラが register_rest_route を呼び出すことを確認する。
		// products は 2 ルート（list/create, get/update/delete）
		// settings は 1 ルート
		// platforms は 1 ルート
		// credentials は 2 ルート（credentials, test-connection）
		$call_count = 0;
		WP_Mock::userFunction( 'register_rest_route' )
			->times( 6 )
			->andReturnUsing(
				function ( $namespace, $route ) use ( &$call_count ) {
					$call_count++;
					$this->assertSame( RestController::NAMESPACE, $namespace );
					$this->assertIsString( $route );
					return true;
				}
			);

		$controller->registerRoutes();

		$this->assertSame( 6, $call_count );
		$this->assertConditionsMet();
	}
}
