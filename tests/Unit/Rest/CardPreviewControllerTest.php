<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Rest;

use Affilicard\Repository\ProductRepositoryInterface;
use Affilicard\Rest\CardPreviewController;
use WP_Mock;
use WP_Mock\Tools\TestCase;
use WP_REST_Request;

final class CardPreviewControllerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		// CardHtmlBuilder 経由で呼ばれる WP 関数の共通 stub
		WP_Mock::userFunction( '__', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			static fn( $v ) => is_string( $v ) ? trim( $v ) : ''
		);
		WP_Mock::userFunction( 'sanitize_hex_color', array( 'return_arg' => 0 ) );
		WP_Mock::passthruFunction( 'esc_html' );
		WP_Mock::passthruFunction( 'esc_attr' );
		WP_Mock::passthruFunction( 'esc_url' );
		// PlatformConfig::all() → get_option を空配列で返す
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );
		// featured image → get_post_thumbnail_id を 0（サムネイルなし）で返す
		WP_Mock::userFunction( 'get_post_thumbnail_id' )->andReturn( 0 );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * テスト用に WP_REST_Request stub へパラメータを設定するヘルパ。
	 *
	 * @param array<string, mixed> $params
	 */
	private function makeRequest( array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/affilicard/v1/products/' . ( $params['id'] ?? 0 ) . '/card-preview' );
		foreach ( $params as $key => $value ) {
			$request->set_param( (string) $key, $value );
		}
		return $request;
	}

	public function test_preview_loads_draft_product_and_returns_html(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );

		$repository = $this->createMock( ProductRepositoryInterface::class );
		$repository->method( 'find' )->with( 42 )->willReturn(
			array(
				'id'           => 42,
				'title'        => '下書き商品',
				'content'      => '',
				'status'       => 'draft',
				'product_type' => 'generic',
				'stock_status' => 'available',
				'extras'       => array(),
				'listings'     => array(),
				'modified'     => '',
			)
		);

		$controller = new CardPreviewController( $repository );
		$request    = $this->makeRequest( array( 'id' => 42 ) );

		$response = $controller->preview( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'html', $data );
		$this->assertIsString( $data['html'] );
	}

	public function test_preview_returns_404_when_product_missing(): void {
		$repository = $this->createMock( ProductRepositoryInterface::class );
		$repository->method( 'find' )->willReturn( null );

		$controller = new CardPreviewController( $repository );
		$response   = $controller->preview( $this->makeRequest( array( 'id' => 999 ) ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_preview_passes_hide_platforms_and_colors_to_builder(): void {
		$repository = $this->createMock( ProductRepositoryInterface::class );
		$repository->method( 'find' )->willReturn(
			array(
				'id'           => 1,
				'title'        => 'テスト商品',
				'content'      => '',
				'status'       => 'publish',
				'product_type' => 'generic',
				'stock_status' => 'available',
				'extras'       => array(),
				'listings'     => array(),
				'modified'     => '',
			)
		);

		$controller = new CardPreviewController( $repository );
		$request    = $this->makeRequest(
			array(
				'id'              => 1,
				'hidePlatforms'   => array( 'dmm-books' ),
				'ctaBgColor'      => '#ff0000',
				'ctaTextColor'    => '#ffffff',
				'cardBgColor'     => '#fafafa',
				'cardBorderColor' => '#cccccc',
			)
		);

		$response = $controller->preview( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'html', $data );
	}

	public function test_preview_passes_only_platforms_to_builder(): void {
		$repository = $this->createMock( ProductRepositoryInterface::class );
		$repository->method( 'find' )->willReturn(
			array(
				'id'           => 1,
				'title'        => 'テスト商品',
				'content'      => '',
				'status'       => 'publish',
				'product_type' => 'generic',
				'stock_status' => 'available',
				'extras'       => array(),
				'listings'     => array(),
				'modified'     => '',
			)
		);

		$controller = new CardPreviewController( $repository );
		$request    = $this->makeRequest(
			array(
				'id'            => 1,
				'onlyPlatforms' => array( 'dmm-books' ),
			)
		);

		$response = $controller->preview( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'html', $response->get_data() );
	}

	public function test_preview_guards_only_platforms_non_array(): void {
		$repository = $this->createMock( ProductRepositoryInterface::class );
		$repository->method( 'find' )->willReturn(
			array(
				'id'           => 3,
				'title'        => '商品C',
				'content'      => '',
				'status'       => 'draft',
				'product_type' => 'generic',
				'stock_status' => 'available',
				'extras'       => array(),
				'listings'     => array(),
				'modified'     => '',
			)
		);

		$controller = new CardPreviewController( $repository );
		// onlyPlatforms に非配列を渡しても 200 で返ること（空配列として処理）
		$request = $this->makeRequest(
			array(
				'id'            => 3,
				'onlyPlatforms' => 'not-an-array',
			)
		);

		$response = $controller->preview( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'html', $response->get_data() );
	}

	public function test_preview_guards_hide_platforms_non_array(): void {
		$repository = $this->createMock( ProductRepositoryInterface::class );
		$repository->method( 'find' )->willReturn(
			array(
				'id'           => 2,
				'title'        => '商品B',
				'content'      => '',
				'status'       => 'draft',
				'product_type' => 'generic',
				'stock_status' => 'available',
				'extras'       => array(),
				'listings'     => array(),
				'modified'     => '',
			)
		);

		$controller = new CardPreviewController( $repository );
		// hidePlatforms に非配列を渡しても 200 で返ること（空配列として処理）
		$request = $this->makeRequest(
			array(
				'id'            => 2,
				'hidePlatforms' => 'not-an-array',
			)
		);

		$response = $controller->preview( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_can_edit_posts_delegates_to_current_user_can(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_posts' )
			->andReturn( true );

		$repository = $this->createMock( ProductRepositoryInterface::class );
		$controller = new CardPreviewController( $repository );

		$this->assertTrue( $controller->canEditPosts() );
	}
}
