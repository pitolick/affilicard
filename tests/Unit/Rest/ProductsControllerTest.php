<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Rest;

use Affilicard\PostType\ProductPostType;
use Affilicard\Repository\ProductLockUnavailable;
use Affilicard\Repository\ProductMetaWriteFailure;
use Affilicard\Repository\ProductRepository;
use Affilicard\Repository\ProductRepositoryInterface;
use Affilicard\Rest\ProductSchema;
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
		// **実 ProductRepository を通すテストが要る WP 関数はここへ集約する。**
		// WP_Mock の userFunction は PHP の関数そのものを定義するため、**別のテスト
		// ファイルが先に定義すると、定義し忘れたファイルまで全体実行では緑になる**。
		// このファイル単体で走らせると undefined function で落ちる、という順序依存を
		// 作らないよう、保存経路が触るものを setUp で揃えておく。
		// - wp_unslash: 保存後の照合（コアと同じ wp_unslash → sanitize の 2 段）
		// - sanitize_text_field: saveMeta() の mask_label / stock_status の照合
		// - sanitize_key: product_type の照合（登録済み sanitize_callback の再現）
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'sanitize_text_field' )
			->andReturnUsing( static fn( $v ) => is_string( $v ) ? trim( $v ) : $v );
		WP_Mock::userFunction( 'sanitize_key' )
			->andReturnUsing(
				static function ( $v ) {
					$v = is_string( $v ) ? strtolower( $v ) : '';
					return (string) preg_replace( '/[^a-z0-9_\-]/', '', $v );
				}
			);
	}

	/**
	 * update_post_meta が書いた meta（キー => 実 WordPress が格納する値）。
	 *
	 * @var array<string, mixed>
	 */
	private array $storedMeta = array();

	/**
	 * 実 WordPress と同じ「書いたものが読み戻る」update_post_meta を登録する。
	 *
	 * **ProductRepository::saveMeta() は要求が運んでくるメタ（product_type /
	 * stock_status / extras / release_date）と listings を書いた直後に読み直し、
	 * 読み戻らなければ ProductMetaWriteFailure を投げる。** 書き込みを捨てるスタブだと、
	 * 実 WP なら 201/200 になる保存がテストの中だけ 500 になる。
	 */
	private function mockMetaWriteThrough(): void {
		WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key, $value ) {
					$key = (string) $key;
					switch ( $key ) {
						case ProductPostType::META_PRODUCT_TYPE:
							$this->storedMeta[ $key ] = sanitize_key( (string) wp_unslash( $value ) );
							break;
						case ProductPostType::META_STOCK_STATUS:
						case ProductPostType::META_MASK_LABEL:
							$this->storedMeta[ $key ] = sanitize_text_field( (string) wp_unslash( $value ) );
							break;
						case ProductPostType::META_EXTRAS:
							$this->storedMeta[ $key ] = ProductSchema::sanitizeExtras( wp_unslash( $value ) );
							break;
						case ProductPostType::META_LISTINGS:
							$this->storedMeta[ $key ] = ProductSchema::sanitizeListings( wp_unslash( $value ) );
							break;
						case ProductPostType::META_RELEASE_DATE:
							$this->storedMeta[ $key ] = ProductSchema::sanitizeReleaseDate( wp_unslash( $value ) );
							break;
						default:
							$this->storedMeta[ $key ] = $value;
					}
					return true;
				}
			);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		if ( isset( $GLOBALS['wpdb'] ) ) {
			unset( $GLOBALS['wpdb'] );
		}
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * ProductRepository::saveMeta() が listings の read-modify-write を
	 * {@see \Affilicard\Repository\ListingLock} で囲むため、保存を通るテストには
	 * GET_LOCK / RELEASE_LOCK を受ける $wpdb が要る。ここでは常に取得成功を返す。
	 */
	private function mockListingLockWpdb(): void {
		$wpdb = Mockery::mock();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( string $query ) => $query );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '1' );
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$GLOBALS['wpdb'] = $wpdb;
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
		// **保存で書かれたキーは書いた値を返す。** 実 WordPress と同じく、保存直後の
		// 読み直しには入れたばかりの値が見える（固定値を返すと、書き込み検証が
		// 「入らなかった」と誤判定して 500 になる）。
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $id, ProductPostType::META_PRODUCT_TYPE, true )
			->andReturnUsing( fn () => $this->storedMeta[ ProductPostType::META_PRODUCT_TYPE ] ?? 'generic' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $id, ProductPostType::META_STOCK_STATUS, true )
			->andReturnUsing( fn () => $this->storedMeta[ ProductPostType::META_STOCK_STATUS ] ?? 'available' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $id, ProductPostType::META_EXTRAS, true )
			->andReturnUsing( fn () => $this->storedMeta[ ProductPostType::META_EXTRAS ] ?? '' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $id, ProductPostType::META_LISTINGS, true )
			->andReturnUsing( fn () => $this->storedMeta[ ProductPostType::META_LISTINGS ] ?? '' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $id, ProductPostType::META_RELEASE_DATE, true )
			->andReturnUsing( fn () => $this->storedMeta[ ProductPostType::META_RELEASE_DATE ] ?? '' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $id, ProductPostType::META_SCHEMA_VERSION, true )
			->andReturn( SchemaVersion::CURRENT );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $id, ProductPostType::META_MASK_BLUR, true )
			->andReturn( '' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $id, ProductPostType::META_MASK_R18, true )
			->andReturn( '' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $id, ProductPostType::META_MASK_LABEL, true )
			->andReturn( '' );
		// extid mirror の stale cleanup 用の全 meta 列挙（既存 stale なし）。
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $id )
			->andReturn( array() );
	}

	public function test_create_upserts_via_repository_and_returns_201_with_saved_data(): void {
		$this->mockListingLockWpdb();
		WP_Mock::userFunction( 'wp_insert_post' )
			->once()
			->andReturnUsing(
				function ( $args, $wp_error ) {
					$this->assertSame( ProductPostType::POST_TYPE, $args['post_type'] );
					$this->assertSame( 'タイトル', $args['post_title'] );
					return 42;
				}
			);
		$this->mockMetaWriteThrough();

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
		$this->mockListingLockWpdb();
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
		$this->mockMetaWriteThrough();

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
		$this->mockListingLockWpdb();
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
		$this->mockMetaWriteThrough();

		$controller = new ProductsController( new ProductRepository() );
		$request    = new WP_REST_Request( 'PATCH', '/affilicard/v1/products/7' );
		$request->set_param( 'id', 7 );
		$request->set_param( 'stock_status', 'out_of_stock' );

		$response = $controller->update( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_bulk_create_returns_207_with_per_item_results_partial_success(): void {
		$this->mockListingLockWpdb();
		WP_Mock::userFunction( 'wp_kses_post' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn( $v ) => $v );
		$this->mockMetaWriteThrough();
		WP_Mock::userFunction( 'get_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key = '', $single = false ) {
					$key = (string) $key;
					// キー無し（stale mirror 掃除の全 meta 列挙）と $single=false
					// （extid ミラーの重複チェック）はここでの関心事ではない。
					if ( '' === $key || ! $single ) {
						return array();
					}
					return $this->storedMeta[ $key ] ?? '';
				}
			);

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

	/**
	 * listings を書かずに見送ったら 409 を返す（500 ではない）。
	 *
	 * **これが「運用者が保存の失敗を知る」経路である。** リポジトリは古い listings を
	 * 書き戻す代わりに {@see ProductLockUnavailable} を投げるようになったので、
	 * ここで捕まえて応答に変えないと、例外が REST の外まで抜けて 500（あるいは致命的
	 * エラー）になる。500 と 409 は運用者にとって意味が逆で、409 は**そのまま
	 * もう一度保存すれば通る**を意味する。
	 */
	public function test_createはロック競合なら409と専用コードを返す(): void {
		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );

		$repository = Mockery::mock( ProductRepositoryInterface::class );
		$repository->shouldReceive( 'save' )
			->once()
			->andThrow( new ProductLockUnavailable( 99 ) );
		$repository->shouldReceive( 'find' )->never();

		$controller = new ProductsController( $repository );
		$request    = new WP_REST_Request( 'POST', '/affilicard/v1/products' );
		$request->set_param( 'title', 'タイトル' );

		$response = $controller->create( $request );
		$data     = $response->get_data();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'affilicard_listing_locked', $data['code'] );
		$this->assertNotSame( '', (string) $data['message'] );
		// **作成済みの商品 ID を返す。** POST がここへ来た時点で投稿行は既にできて
		// いる（save() は wp_insert_post() を通してから saveMeta() を呼ぶ）。ID を
		// 返さないと、呼び出し側は積み直しのたびに POST し直して同じ商品を増やす。
		$this->assertArrayHasKey( 'id', $data );
		$this->assertSame( 99, $data['id'] );
		// **何が保存されなかったかを機械可読で名指しする。** 投稿行も listings 以外の
		// メタも保存済みで、入らなかったのは listings だけ——それが伝わらないと、
		// 呼び出し側は「全部やり直す」か「何も直さない」の二択になる。
		$this->assertSame( array( 'listings' ), $data['unsaved_fields'] );
	}

	/**
	 * 更新も同じ（既存商品の購入リンク編集が、いちばん競合しやすい）。
	 */
	public function test_updateはロック競合なら409と専用コードを返す(): void {
		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );

		$repository = Mockery::mock( ProductRepositoryInterface::class );
		$repository->shouldReceive( 'find' )
			->with( 42 )
			->andReturn(
				array(
					'id'    => 42,
					'title' => '既存',
				)
			);
		$repository->shouldReceive( 'save' )
			->once()
			->andThrow( new ProductLockUnavailable( 42 ) );

		$controller = new ProductsController( $repository );
		$request    = new WP_REST_Request( 'POST', '/affilicard/v1/products/42' );
		$request->set_param( 'id', 42 );
		$request->set_param( 'title', '書き換え' );

		$response = $controller->update( $request );
		$data     = $response->get_data();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'affilicard_listing_locked', $data['code'] );
		// **更新でも同じ形にする。** 呼び出し側は ID を既に知っているので冗長では
		// あるが、エラー本文の形が verb で変わらない方が読み手の分岐が減る。
		$this->assertSame( 42, $data['id'] );
		// **何が保存されなかったかを機械可読で名指しする。** 投稿行も listings 以外の
		// メタも保存済みで、入らなかったのは listings だけ——それが伝わらないと、
		// 呼び出し側は「全部やり直す」か「何も直さない」の二択になる。
		$this->assertSame( array( 'listings' ), $data['unsaved_fields'] );
	}

	/**
	 * 一括作成では、競合した item だけを error にして他の item は通す。
	 *
	 * 1 件の競合で 100 件のバッチ全体を落とすと、呼び出し側は「どれが入ったのか」を
	 * 判別できずに全件を積み直すことになる。207 の per-item 報告に混ぜるのが素直で、
	 * 呼び出し側はこの item だけ積み直せばよい。
	 */
	public function test_bulkCreateはロック競合のitemだけをerrorにする(): void {
		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		// sanitize_text_field / sanitize_key は setUp が登録済み（WP_Mock は同じ関数名に
		// ついて最初の期待だけを保持するため、ここで登録し直しても無言で効かない）。
		WP_Mock::userFunction( 'wp_kses_post' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn( $v ) => $v );

		$repository = Mockery::mock( ProductRepositoryInterface::class );
		$call       = 0;
		$repository->shouldReceive( 'save' )
			->twice()
			->andReturnUsing(
				static function () use ( &$call ) {
					++$call;
					if ( 1 === $call ) {
						throw new ProductLockUnavailable( 11 );
					}
					return 12;
				}
			);

		$controller = new ProductsController( $repository );
		$request    = new WP_REST_Request( 'POST', '/affilicard/v1/products/bulk' );
		$request->set_param(
			'products',
			array(
				array( 'title' => '競合する商品' ),
				array( 'title' => '通る商品' ),
			)
		);

		$response = $controller->bulkCreate( $request );
		$data     = $response->get_data();

		$this->assertSame( 207, $response->get_status() );
		$this->assertSame( 'error', $data['results'][0]['status'] );
		$this->assertSame( 'affilicard_listing_locked', $data['results'][0]['code'] );
		// 部分失敗でも「どの商品ができてしまったか」を名指しする。
		$this->assertArrayHasKey( 'id', $data['results'][0] );
		$this->assertSame( 11, $data['results'][0]['id'] );
		// item ごとの報告でも形をそろえる（単体の応答と読み替えずに済む）。
		$this->assertSame( array( 'listings' ), $data['results'][0]['unsaved_fields'] );
		$this->assertArrayNotHasKey( 'unsaved_fields', $data['results'][1] );
		$this->assertSame( 'created', $data['results'][1]['status'] );
		$this->assertSame( 12, $data['results'][1]['id'] );
		$this->assertSame( 1, $data['created'] );
		$this->assertSame( 1, $data['failed'] );
	}

	/**
	 * 書き込みが効かなかったら 500 を返す（409 ではない）。
	 *
	 * **409 と 500 は運用者にとって意味が違う。** 409（ロック競合）は「そのまま
	 * もう一度保存すれば通る」で、待てば解消する。こちらは「書いたのに入らなかった」で、
	 * 原因（他プラグインの meta フィルタ・壊れた meta 行）を取り除くまで何度やり直しても
	 * 同じ結果になる。同じ 409 に混ぜると、直らない失敗を運用者が延々とリトライする。
	 */
	public function test_createは書き込みが効かなければ500と保存失敗コードを返す(): void {
		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );

		$repository = Mockery::mock( ProductRepositoryInterface::class );
		$repository->shouldReceive( 'save' )
			->once()
			->andThrow( new ProductMetaWriteFailure( 99 ) );
		$repository->shouldReceive( 'find' )->never();

		$controller = new ProductsController( $repository );
		$request    = new WP_REST_Request( 'POST', '/affilicard/v1/products' );
		$request->set_param( 'title', 'タイトル' );

		$response = $controller->create( $request );
		$data     = $response->get_data();

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'affilicard_save_failed', $data['code'] );
		$this->assertNotSame( '', (string) $data['message'] );
		// 409 と同じ理由で ID を返す。500 は「やり直しても直らない」失敗なので
		// なおさら——呼び出し側が積み直すたびに孤児が 1 つずつ増える。
		$this->assertArrayHasKey( 'id', $data );
		$this->assertSame( 99, $data['id'] );
		// **何が保存されなかったかを機械可読で名指しする。** 投稿行も listings 以外の
		// メタも保存済みで、入らなかったのは listings だけ——それが伝わらないと、
		// 呼び出し側は「全部やり直す」か「何も直さない」の二択になる。
		$this->assertSame( array( 'listings' ), $data['unsaved_fields'] );
	}

	/**
	 * 更新も同じ（購入リンクの編集はこちらを通る）。
	 */
	public function test_updateは書き込みが効かなければ500と保存失敗コードを返す(): void {
		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );

		$repository = Mockery::mock( ProductRepositoryInterface::class );
		$repository->shouldReceive( 'find' )
			->with( 42 )
			->andReturn(
				array(
					'id'    => 42,
					'title' => '既存',
				)
			);
		$repository->shouldReceive( 'save' )
			->once()
			->andThrow( new ProductMetaWriteFailure( 42 ) );

		$controller = new ProductsController( $repository );
		$request    = new WP_REST_Request( 'POST', '/affilicard/v1/products/42' );
		$request->set_param( 'id', 42 );
		$request->set_param( 'title', '書き換え' );

		$response = $controller->update( $request );
		$data     = $response->get_data();

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'affilicard_save_failed', $data['code'] );
		$this->assertSame( 42, $data['id'] );
		// **何が保存されなかったかを機械可読で名指しする。** 投稿行も listings 以外の
		// メタも保存済みで、入らなかったのは listings だけ——それが伝わらないと、
		// 呼び出し側は「全部やり直す」か「何も直さない」の二択になる。
		$this->assertSame( array( 'listings' ), $data['unsaved_fields'] );
	}

	/**
	 * 一括作成では、書き込みが効かなかった item だけを error にして他は通す。
	 *
	 * 捕まえないと例外がループの外まで抜け、既に作成できた item の結果ごと 500 で
	 * 消える（呼び出し側はどれが入ったか判別できない）。
	 */
	public function test_bulkCreateは書き込み失敗のitemだけをerrorにする(): void {
		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		// sanitize_text_field / sanitize_key は setUp が登録済み（WP_Mock は同じ関数名に
		// ついて最初の期待だけを保持するため、ここで登録し直しても無言で効かない）。
		WP_Mock::userFunction( 'wp_kses_post' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn( $v ) => $v );

		$repository = Mockery::mock( ProductRepositoryInterface::class );
		$call       = 0;
		$repository->shouldReceive( 'save' )
			->twice()
			->andReturnUsing(
				static function () use ( &$call ) {
					++$call;
					if ( 1 === $call ) {
						throw new ProductMetaWriteFailure( 11 );
					}
					return 12;
				}
			);

		$controller = new ProductsController( $repository );
		$request    = new WP_REST_Request( 'POST', '/affilicard/v1/products/bulk' );
		$request->set_param(
			'products',
			array(
				array( 'title' => '書き込めない商品' ),
				array( 'title' => '通る商品' ),
			)
		);

		$response = $controller->bulkCreate( $request );
		$data     = $response->get_data();

		$this->assertSame( 207, $response->get_status() );
		$this->assertSame( 'error', $data['results'][0]['status'] );
		$this->assertSame( 'affilicard_save_failed', $data['results'][0]['code'] );
		$this->assertArrayHasKey( 'id', $data['results'][0] );
		$this->assertSame( 11, $data['results'][0]['id'] );
		$this->assertSame( array( 'listings' ), $data['results'][0]['unsaved_fields'] );
		$this->assertArrayNotHasKey( 'unsaved_fields', $data['results'][1] );
		$this->assertSame( 'created', $data['results'][1]['status'] );
		$this->assertSame( 1, $data['created'] );
		$this->assertSame( 1, $data['failed'] );
	}

	/**
	 * 投稿行そのものを作れなかった 500 には **id を載せない**。
	 *
	 * `save()` が 0 を返すのは `wp_insert_post()` が失敗したときで、**商品は 1 件も
	 * 作られていない**。ここに id を付けると（0 でも、直前の何かでも）呼び出し側は
	 * 「作成済みの商品がある」と読み、存在しない ID を PATCH しに行く。
	 * **id の有無が「商品ができたか否か」の signal である**——同じ
	 * `affilicard_save_failed` でも、id があれば PATCH、無ければ POST し直しが正解になる。
	 */
	public function test_createは投稿行を作れなかった500にはidを載せない(): void {
		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );

		$repository = Mockery::mock( ProductRepositoryInterface::class );
		$repository->shouldReceive( 'save' )->once()->andReturn( 0 );
		$repository->shouldReceive( 'find' )->never();

		$controller = new ProductsController( $repository );
		$request    = new WP_REST_Request( 'POST', '/affilicard/v1/products' );
		$request->set_param( 'title', 'タイトル' );

		$response = $controller->create( $request );
		$data     = $response->get_data();

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'affilicard_save_failed', $data['code'] );
		$this->assertArrayNotHasKey( 'id', $data );
		// 商品が 1 件も無いので「listings だけ入らなかった」でもない。
		$this->assertArrayNotHasKey( 'unsaved_fields', $data );
	}

	/**
	 * `unsaved_fields` は**例外が名指ししたフィールドをそのまま返す**。
	 *
	 * **以前はここが `array( 'listings' )` を固定で返していた。** それは
	 * {@see \Affilicard\Repository\ProductRepository::saveMeta()} が何を読み直したかを
	 * 知らない側が「listings 以外は入った」と名乗る形で、実際には product_type や
	 * stock_status が入らなかった場合でも同じ本文を返していた——**誰も確かめていない
	 * フィールドを「保存済み」と宣言していた**。何が入らなかったかを知っているのは
	 * 書いた側だけなので、いまは例外が運んできた値を素通しする。
	 */
	public function test_createは例外が名指しした未保存フィールドをそのまま返す(): void {
		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );

		$repository = Mockery::mock( ProductRepositoryInterface::class );
		$repository->shouldReceive( 'save' )
			->once()
			->andThrow( new ProductMetaWriteFailure( 99, array( 'stock_status' ) ) );
		$repository->shouldReceive( 'find' )->never();

		$controller = new ProductsController( $repository );
		$request    = new WP_REST_Request( 'POST', '/affilicard/v1/products' );
		$request->set_param( 'title', 'タイトル' );

		$response = $controller->create( $request );
		$data     = $response->get_data();

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'affilicard_save_failed', $data['code'] );
		$this->assertSame( 99, $data['id'] );
		// listings は入っている。入らなかったのは stock_status だけ——固定値なら
		// ここが `["listings"]` になり、呼び出し側は消えた取扱終了を送り直さない。
		$this->assertSame( array( 'stock_status' ), $data['unsaved_fields'] );
	}

	/**
	 * ロック競合（409）でも同じ——listings 以外が一緒に入らなかったなら、一緒に返す。
	 */
	public function test_updateは409でも例外が名指しした未保存フィールドをそのまま返す(): void {
		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );

		$repository = Mockery::mock( ProductRepositoryInterface::class );
		$repository->shouldReceive( 'find' )
			->with( 42 )
			->andReturn(
				array(
					'id'    => 42,
					'title' => '既存',
				)
			);
		$repository->shouldReceive( 'save' )
			->once()
			->andThrow( new ProductLockUnavailable( 42, '', array( 'extras', 'listings' ) ) );

		$controller = new ProductsController( $repository );
		$request    = new WP_REST_Request( 'POST', '/affilicard/v1/products/42' );
		$request->set_param( 'id', 42 );
		$request->set_param( 'title', '書き換え' );

		$response = $controller->update( $request );
		$data     = $response->get_data();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( array( 'extras', 'listings' ), $data['unsaved_fields'] );
	}

	/**
	 * `/bulk` の item も同じ形で素通しする（単体と読み替えずに済むように）。
	 */
	public function test_bulkCreateは例外が名指しした未保存フィールドをitemに載せる(): void {
		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		WP_Mock::userFunction( 'wp_kses_post' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn( $v ) => $v );

		$repository = Mockery::mock( ProductRepositoryInterface::class );
		$repository->shouldReceive( 'save' )
			->once()
			->andThrow( new ProductMetaWriteFailure( 11, array( 'product_type', 'listings' ) ) );

		$controller = new ProductsController( $repository );
		$request    = new WP_REST_Request( 'POST', '/affilicard/v1/products/bulk' );
		$request->set_param( 'products', array( array( 'title' => '書き込めない商品' ) ) );

		$response = $controller->bulkCreate( $request );
		$data     = $response->get_data();

		$this->assertSame( 'error', $data['results'][0]['status'] );
		$this->assertSame( 11, $data['results'][0]['id'] );
		$this->assertSame( array( 'product_type', 'listings' ), $data['results'][0]['unsaved_fields'] );
	}

	/**
	 * 名指しできるフィールドが無ければ `unsaved_fields` は**載せない**。
	 *
	 * キーの有無が「部分保存かどうか」を表す約束なので、空配列を載せると
	 * 「部分保存だが失われたものは無い」という読めない状態を作る。
	 */
	public function test_createは未保存フィールドが無ければunsaved_fieldsを載せない(): void {
		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );

		$repository = Mockery::mock( ProductRepositoryInterface::class );
		$repository->shouldReceive( 'save' )
			->once()
			->andThrow( new ProductLockUnavailable( 99, '', array() ) );
		$repository->shouldReceive( 'find' )->never();

		$controller = new ProductsController( $repository );
		$request    = new WP_REST_Request( 'POST', '/affilicard/v1/products' );
		$request->set_param( 'title', 'タイトル' );

		$response = $controller->create( $request );
		$data     = $response->get_data();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 99, $data['id'] );
		$this->assertArrayNotHasKey( 'unsaved_fields', $data );
	}

	/**
	 * 更新の 500 は逆に id を**載せる**（商品は実在するため）。
	 *
	 * **規則は「サーバが実在を知っている商品の ID だけを載せる」の 1 つだけ**で、
	 * verb ごとに変えない。更新はここへ来るまでに find() で商品を引けているので、
	 * `wp_update_post()` が失敗しても商品そのものは在る。作成側の同じ分岐が id を
	 * 落とすのと対照的だが、規則は同じものが適用されている。
	 */
	public function test_updateは更新に失敗した500にも商品idを載せる(): void {
		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );

		$repository = Mockery::mock( ProductRepositoryInterface::class );
		$repository->shouldReceive( 'find' )
			->with( 42 )
			->andReturn(
				array(
					'id'    => 42,
					'title' => '既存',
				)
			);
		$repository->shouldReceive( 'save' )->once()->andReturn( 0 );

		$controller = new ProductsController( $repository );
		$request    = new WP_REST_Request( 'POST', '/affilicard/v1/products/42' );
		$request->set_param( 'id', 42 );
		$request->set_param( 'title', '書き換え' );

		$response = $controller->update( $request );
		$data     = $response->get_data();

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'affilicard_save_failed', $data['code'] );
		$this->assertSame( 42, $data['id'] );
		// **部分保存ではない。** listings を書く手前で落ちているため、保存できな
		// かったフィールドの名指しは載せない（載せると「listings 以外は入った」と
		// いう嘘になる）。
		$this->assertArrayNotHasKey( 'unsaved_fields', $data );
	}

	/**
	 * 一括作成でも同じ——保存できなかった（0 が返った）item には id を載せない。
	 */
	public function test_bulkCreateは保存できなかったitemにidを載せない(): void {
		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		// sanitize_text_field / sanitize_key は setUp が登録済み（WP_Mock は同じ関数名に
		// ついて最初の期待だけを保持するため、ここで登録し直しても無言で効かない）。
		WP_Mock::userFunction( 'wp_kses_post' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn( $v ) => $v );

		$repository = Mockery::mock( ProductRepositoryInterface::class );
		$repository->shouldReceive( 'save' )->once()->andReturn( 0 );

		$controller = new ProductsController( $repository );
		$request    = new WP_REST_Request( 'POST', '/affilicard/v1/products/bulk' );
		$request->set_param( 'products', array( array( 'title' => '保存できない商品' ) ) );

		$response = $controller->bulkCreate( $request );
		$data     = $response->get_data();

		$this->assertSame( 207, $response->get_status() );
		$this->assertSame( 'error', $data['results'][0]['status'] );
		$this->assertArrayNotHasKey( 'id', $data['results'][0] );
		$this->assertArrayNotHasKey( 'unsaved_fields', $data['results'][0] );
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
