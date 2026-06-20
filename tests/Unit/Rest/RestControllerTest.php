<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Rest;

use Affilicard\Cron\ListingRefresher;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Repository\ProductRepository;
use Affilicard\Rest\CardPreviewController;
use Affilicard\Rest\CredentialsController;
use Affilicard\Rest\PlatformsController;
use Affilicard\Rest\ProductsController;
use Affilicard\Rest\RefreshController;
use Affilicard\Rest\RestController;
use Affilicard\Rest\SettingsController;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class RestControllerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_register_hooks_rest_api_init_and_register_routes_dispatches_to_each_sub_controller(): void {
		$refresher  = Mockery::mock( ListingRefresher::class );
		$controller = new RestController(
			new ProductsController( new ProductRepository() ),
			new SettingsController(),
			new PlatformsController(),
			new CredentialsController( new ProviderRegistry() ),
			new RefreshController( $refresher ),
			new CardPreviewController( new ProductRepository() )
		);

		WP_Mock::expectActionAdded( 'rest_api_init', array( $controller, 'registerRoutes' ) );

		$controller->register();

		// 各サブコントローラが register_rest_route を呼び出すことを確認する。
		// products は 3 ルート（list/create, bulk, get/update/delete）
		// settings は 1 ルート
		// platforms は 1 ルート
		// credentials は 4 ルート（platform credentials, platform test-connection, provider credentials, provider test-connection）
		// refresh は 1 ルート
		// preview は 1 ルート
		$call_count = 0;
		WP_Mock::userFunction( 'register_rest_route' )
			->times( 11 )
			->andReturnUsing(
				function ( $namespace, $route ) use ( &$call_count ) {
					$call_count++;
					$this->assertSame( RestController::NAMESPACE, $namespace );
					$this->assertIsString( $route );
					return true;
				}
			);

		$controller->registerRoutes();

		$this->assertSame( 11, $call_count );
		$this->assertConditionsMet();
	}
}
