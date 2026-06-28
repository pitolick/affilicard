<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Rest;

use Affilicard\PostType\ProductPostType;
use Affilicard\Repository\ProductRepository;
use Affilicard\Repository\ProductRepositoryInterface;
use Affilicard\Rest\ProductsController;
use Affilicard\Schema\SchemaVersion;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;
use WP_REST_Request;

final class ProductsControllerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )
			->andReturnUsing(
				static function ( $text ) {
					return $text;
				}
			);
		WP_Mock::userFunction( 'wp_json_encode' )
			->andReturnUsing(
				static function ( $value ) {
					return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				}
			);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * WP_REST_Request を生成するヘルパ。
	 *
	 * @param array<string, mixed> $params
	 */
	private function makeRequest( array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/affilicard/v1/products' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * 完全な find() 結果を返す helper post object を作る。
	 */
	private function mockFindReturnsProduct( int $id, string $title = 'X' ): void {
		$post = (object) array(
			'ID'            => $id,
			'post_type'     => ProductPostType::POST_TYPE,
			'post_title'    => $title,
			'post_content'  => '',
			'post_status'   => 'publish',
			'post_modified' => '2026-05-29 10:00:00',
		);
		WP_Mock::userFunction( 'get_post' )
			->with( $id )
			->andReturn( $post );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $id, ProductPostType::META_PRODUCT_TYPE, true )
			->andReturn( 'generic' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $id, ProductPostType::META_STOCK_STATUS, true )
			->andReturn( 'available' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $id, ProductPostType::META_EXTRAS, true )
			->andReturn( '' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $id, ProductPostType::META_LISTINGS, true )
			->andReturn( '' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $id, ProductPostType::META_SCHEMA_VERSION, true )
			->andReturn( SchemaVersion::CURRENT );
	}

	public function test_create_upserts_via_repository_and_returns_201_with_saved_data(): void {
		WP_Mock::userFunction( 'wp_insert_post' )
			->once()
			->andReturnUsing(
				function ( $args, $wp_error ) {
					$this->assertSame( ProductPostType::POST_TYPE, $args['post_type'] );
					$this->assertSame( 'タイトル', $args['post_title'] );
					return 42;
				}
			);
		WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );

		$this->mockFindReturnsProduct( 42, 'タイトル' );

		$controller = new ProductsController( new ProductRepository() );
		$request    = new WP_REST_Request( 'POST', '/affilicard/v1/products' );
		$request->set_param( 'title', 'タイトル' );
		$request->set_param( 'product_type', 'ebook' );

		$response = $controller->create( $request );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 42, $data['id'] );
		$this->assertSame( 'タイトル', $data['title'] );
	}

	public function test_create_downgrades_publish_to_pending_without_publish_posts(): void {
		// publish_posts を持たないユーザーが status=publish を要求 → pending に降格して保存。
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'publish_posts' )
			->andReturn( false );

		$repository = Mockery::mock( ProductRepositoryInterface::class );
		$repository->shouldReceive( 'save' )
			->once()
			->andReturnUsing(
				function ( array $data ) {
					$this->assertSame( 'pending', $data['status'] );
					return 99;
				}
			);
		$repository->shouldReceive( 'find' )->with( 99 )->andReturn(
			array(
				'id'     => 99,
				'title'  => 'タイトル',
				'status' => 'pending',
			)
		);

		$controller = new ProductsController( $repository );
		$request    = new WP_REST_Request( 'POST', '/affilicard/v1/products' );
		$request->set_param( 'title', 'タイトル' );
		$request->set_param( 'status', 'publish' );

		$response = $controller->create( $request );

		$this->assertSame( 201, $response->get_status() );
	}

	public function test_create_keeps_publish_when_user_can_publish_posts(): void {
		// publish_posts を持つユーザーは status=publish のまま保存される（降格しない）。
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'publish_posts' )
			->andReturn( true );

		$repository = Mockery::mock( ProductRepositoryInterface::class );
		$repository->shouldReceive( 'save' )
			->once()
			->andReturnUsing(
				function ( array $data ) {
					$this->assertSame( 'publish', $data['status'] );
					return 100;
				}
			);
		$repository->shouldReceive( 'find' )->with( 100 )->andReturn(
			array(
				'id'     => 100,
				'title'  => 'タイトル',
				'status' => 'publish',
			)
		);

		$controller = new ProductsController( $repository );
		$request    = new WP_REST_Request( 'POST', '/affilicard/v1/products' );
		$request->set_param( 'title', 'タイトル' );
		$request->set_param( 'status', 'publish' );

		$response = $controller->create( $request );

		$this->assertSame( 201, $response->get_status() );
	}

	public function test_get_returns_404_when_product_not_found(): void {
		WP_Mock::userFunction( 'get_post' )
			->with( 999 )
			->andReturn( null );

		$controller = new ProductsController( new ProductRepository() );
		$request    = new WP_REST_Request( 'GET', '/affilicard/v1/products/999' );
		$request->set_param( 'id', 999 );

		$response = $controller->get( $request );

		$this->assertSame( 404, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'affilicard_not_found', $data['code'] );
	}

	public function test_get_returns_200_with_body_when_found(): void {
		$this->mockFindReturnsProduct( 5, 'A' );

		$controller = new ProductsController( new ProductRepository() );
		$request    = new WP_REST_Request( 'GET', '/affilicard/v1/products/5' );
		$request->set_param( 'id', 5 );

		$response = $controller->get( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 5, $data['id'] );
		$this->assertSame( 'A', $data['title'] );
	}

	public function test_delete_calls_repository_delete_and_returns_204_on_success(): void {
		$this->mockFindReturnsProduct( 12 );
		WP_Mock::userFunction( 'wp_delete_post' )
			->once()
			->with( 12, true )
			->andReturn( (object) array( 'ID' => 12 ) );

		$controller = new ProductsController( new ProductRepository() );
		$request    = new WP_REST_Request( 'DELETE', '/affilicard/v1/products/12' );
		$request->set_param( 'id', 12 );

		$response = $controller->delete( $request );

		$this->assertSame( 204, $response->get_status() );
		$this->assertNull( $response->get_data() );
	}

	public function test_delete_returns_404_when_product_not_found(): void {
		WP_Mock::userFunction( 'get_post' )
			->with( 99 )
			->andReturn( null );

		$controller = new ProductsController( new ProductRepository() );
		$request    = new WP_REST_Request( 'DELETE', '/affilicard/v1/products/99' );
		$request->set_param( 'id', 99 );

		$response = $controller->delete( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_list_delegates_to_repository_search_and_sets_total_header(): void {
		$repository = $this->createMock( ProductRepositoryInterface::class );
		$repository->method( 'search' )->with( 'abc', 20, 1 )->willReturn(
			array(
				'items' => array(
					array(
						'id'     => 1,
						'title'  => 'X',
						'status' => 'publish',
					),
				),
				'total' => 1,
			)
		);

		$controller = new ProductsController( $repository );
		$response   = $controller->list(
			$this->makeRequest(
				array(
					'search'   => 'abc',
					'per_page' => 20,
					'page'     => 1,
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				array(
					'id'     => 1,
					'title'  => 'X',
					'status' => 'publish',
				),
			),
			$response->get_data()
		);
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
	}

	public function test_list_sets_total_pages_header_correctly(): void {
		$repository = $this->createMock( ProductRepositoryInterface::class );
		$repository->method( 'search' )->willReturn(
			array(
				'items' => array_fill(
					0,
					5,
					array(
						'id'     => 1,
						'title'  => 'X',
						'status' => 'publish',
					)
				),
				'total' => 10,
			)
		);

		$controller = new ProductsController( $repository );
		$response   = $controller->list(
			$this->makeRequest(
				array(
					'search'   => '',
					'per_page' => 5,
					'page'     => 1,
				)
			)
		);

		$this->assertSame( '10', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
	}

	public function test_update_returns_404_when_product_not_found(): void {
		WP_Mock::userFunction( 'get_post' )
			->with( 88 )
			->andReturn( null );

		$controller = new ProductsController( new ProductRepository() );
		$request    = new WP_REST_Request( 'PATCH', '/affilicard/v1/products/88' );
		$request->set_param( 'id', 88 );
		$request->set_param( 'title', 'new' );

		$response = $controller->update( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_update_saves_and_returns_200_with_saved_product(): void {
		$this->mockFindReturnsProduct( 7, 'new' );

		WP_Mock::userFunction( 'wp_update_post' )
			->once()
			->andReturnUsing(
				function ( $args, $wp_error ) {
					$this->assertSame( 7, $args['ID'] );
					$this->assertSame( 'new', $args['post_title'] );
					return 7;
				}
			);
		WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );

		$controller = new ProductsController( new ProductRepository() );
		$request    = new WP_REST_Request( 'PATCH', '/affilicard/v1/products/7' );
		$request->set_param( 'id', 7 );
		$request->set_param( 'title', 'new' );

		$response = $controller->update( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 7, $data['id'] );
		$this->assertSame( 'new', $data['title'] );
	}

	public function test_update_preserves_existing_title_when_omitted(): void {
		// metabox の部分更新は title を送らない（stock_status/extras/listings のみ）。
		// 既存タイトルが空文字で上書きされず保持されることを検証する。
		$this->mockFindReturnsProduct( 7, 'Keep Title' );

		WP_Mock::userFunction( 'wp_update_post' )
			->once()
			->andReturnUsing(
				function ( $args, $wp_error ) {
					$this->assertSame( 7, $args['ID'] );
					$this->assertSame( 'Keep Title', $args['post_title'] );
					return 7;
				}
			);
		WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );

		$controller = new ProductsController( new ProductRepository() );
		$request    = new WP_REST_Request( 'PATCH', '/affilicard/v1/products/7' );
		$request->set_param( 'id', 7 );
		$request->set_param( 'stock_status', 'out_of_stock' );

		$response = $controller->update( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_bulk_create_returns_207_with_per_item_results_partial_success(): void {
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => is_string( $v ) ? trim( $v ) : $v );
		WP_Mock::userFunction( 'wp_kses_post' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static fn( $v ) => strtolower( (string) $v ) );
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );

		$ids  = array( 101, 0 ); // 1st save → 101, 2nd save → 0 (failure)
		$call = 0;
		WP_Mock::userFunction( 'wp_insert_post' )->andReturnUsing(
			static function ( $args, $wp_error ) use ( $ids, &$call ) {
				return $ids[ $call++ ] ?? 0;
			}
		);

		$controller = new ProductsController( new ProductRepository() );
		$request    = new WP_REST_Request( 'POST', '/affilicard/v1/products/bulk' );
		$request->set_param(
			'products',
			array(
				array(
					'title'        => '商品A',
					'product_type' => 'vod',
				), // → save → 101 (created)
				array( 'title' => '' ),                               // → skipped (error, no save)
				array( 'title' => '商品C' ),                          // → save → 0 (error)
			)
		);

		$response = $controller->bulkCreate( $request );
		$data     = $response->get_data();

		$this->assertSame( 207, $response->get_status() );
		$this->assertCount( 3, $data['results'] );
		$this->assertSame( 'created', $data['results'][0]['status'] );
		$this->assertSame( 101, $data['results'][0]['id'] );
		$this->assertSame( 'error', $data['results'][1]['status'] );
		$this->assertSame( 'error', $data['results'][2]['status'] );
		$this->assertSame( 1, $data['created'] );
		$this->assertSame( 2, $data['failed'] );
	}

	public function test_bulk_create_rejects_non_array_products(): void {
		$controller = new ProductsController( new ProductRepository() );
		$request    = new WP_REST_Request( 'POST', '/affilicard/v1/products/bulk' );
		$request->set_param( 'products', 'not-an-array' );

		$response = $controller->bulkCreate( $request );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_bulk_create_rejects_too_many_items(): void {
		$products = array();
		for ( $i = 0; $i < 101; $i++ ) {
			$products[] = array( 'title' => 'item' );
		}
		$controller = new ProductsController( new ProductRepository() );
		$request    = new WP_REST_Request( 'POST', '/affilicard/v1/products/bulk' );
		$request->set_param( 'products', $products );

		$response = $controller->bulkCreate( $request );
		$data     = $response->get_data();
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'affilicard_bulk_too_many', $data['code'] );
	}

	public function test_permission_callbacks_check_current_user_can(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_posts' )
			->andReturn( true );
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_post', 42 )
			->andReturn( true );
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'delete_post', 42 )
			->andReturn( false );

		$controller = new ProductsController( new ProductRepository() );

		$request = new WP_REST_Request( 'GET', '/' );
		$request->set_param( 'id', 42 );

		$this->assertTrue( $controller->canEditPosts() );
		$this->assertTrue( $controller->canEditPostFromRequest( $request ) );
		$this->assertFalse( $controller->canDeletePostFromRequest( $request ) );
	}
}
