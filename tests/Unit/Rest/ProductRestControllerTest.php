<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Rest;

use Affilicard\Rest\ProductRestController;
use WP_Mock;
use WP_Mock\Tools\TestCase;
use WP_REST_Request;

final class ProductRestControllerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing(
			static function ( $text ) {
				return $text;
			}
		);
		WP_Mock::userFunction( 'rest_authorization_required_code' )->andReturn( 401 );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_get_item_permissions_check_denies_anonymous(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( false );
		$controller = new ProductRestController( 'affilicard_product' );
		$result     = $controller->get_item_permissions_check( new WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_get_items_permissions_check_denies_anonymous(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( false );
		$controller = new ProductRestController( 'affilicard_product' );
		$result     = $controller->get_items_permissions_check( new WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_get_item_permissions_check_allows_editor(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );
		$controller = new ProductRestController( 'affilicard_product' );
		$result     = $controller->get_item_permissions_check( new WP_REST_Request() );
		$this->assertTrue( $result );
	}

	public function test_get_items_permissions_check_allows_editor(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );
		$controller = new ProductRestController( 'affilicard_product' );
		$result     = $controller->get_items_permissions_check( new WP_REST_Request() );
		$this->assertTrue( $result );
	}
}
