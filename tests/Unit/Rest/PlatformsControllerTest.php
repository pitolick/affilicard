<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Rest;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Rest\PlatformsController;
use WP_Mock;
use WP_Mock\Tools\TestCase;
use WP_REST_Request;

final class PlatformsControllerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )
			->andReturnUsing(
				static function ( $text ) {
					return $text;
				}
			);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_list_returns_200_with_platforms_all(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'            => 'dmm-books',
						'name'            => 'DMMブックス',
						'provider'        => 'dmm-ebook',
						'displayOrder'    => 1,
						'enabled'         => true,
						'applicableTypes' => array( 'ebook' ),
						'buttonLabel'     => 'L',
						'brandColor'      => '#d72d65',
						'buttonTextColor' => '#ffffff',
					),
				)
			);

		$controller = new PlatformsController();
		$request    = new WP_REST_Request( 'GET', '/affilicard/v1/platforms' );

		$response = $controller->list( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 1, $data );
		$this->assertSame( 'dmm-books', $data[0]['code'] );
	}

	public function test_update_saves_and_returns_updated_list(): void {
		// 最初の update_option 後の all() で読まれる get_option の返り値を 2 回読みに対応する。
		WP_Mock::userFunction( 'get_option' )
			->andReturn(
				array(
					array(
						'code'            => 'new-platform',
						'name'            => 'New',
						'provider'        => 'manual',
						'displayOrder'    => 1,
						'enabled'         => true,
						'applicableTypes' => array( 'generic' ),
						'buttonLabel'     => 'Buy',
						'brandColor'      => '#000000',
						'buttonTextColor' => '#ffffff',
					),
				)
			);

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( PlatformConfig::OPTION_KEY, $key );
					$this->assertFalse( $autoload );
					$this->assertCount( 1, $value );
					$this->assertSame( 'new-platform', $value[0]['code'] );
					return true;
				}
			);

		$controller = new PlatformsController();
		$request    = new WP_REST_Request( 'PUT', '/affilicard/v1/platforms' );
		$request->set_param(
			'platforms',
			array(
				array(
					'code'            => 'new-platform',
					'name'            => 'New',
					'provider'        => 'manual',
					'displayOrder'    => 1,
					'enabled'         => true,
					'applicableTypes' => array( 'generic' ),
					'buttonLabel'     => 'Buy',
					'brandColor'      => '#000000',
					'buttonTextColor' => '#ffffff',
				),
			)
		);

		$response = $controller->update( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 1, $data );
		$this->assertSame( 'new-platform', $data[0]['code'] );
	}
}
