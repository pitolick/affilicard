<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Repository;

use Affilicard\PostType\ProductPostType;
use Affilicard\Repository\ProductRepository;
use Affilicard\Schema\SchemaVersion;
use Affilicard\Util\JsonField;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ProductRepositoryTest extends TestCase {

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
		if ( isset( $GLOBALS['wpdb'] ) ) {
			unset( $GLOBALS['wpdb'] );
		}
		Mockery::close();
		parent::tearDown();
	}

	public function test_find_returns_null_when_post_not_found(): void {
		WP_Mock::userFunction( 'get_post' )
			->with( 42 )
			->andReturn( null );

		$repo = new ProductRepository();
		$this->assertNull( $repo->find( 42 ) );
	}

	public function test_find_returns_null_when_post_type_mismatches(): void {
		$post = (object) array(
			'ID'        => 7,
			'post_type' => 'post',
		);
		WP_Mock::userFunction( 'get_post' )
			->with( 7 )
			->andReturn( $post );

		$repo = new ProductRepository();
		$this->assertNull( $repo->find( 7 ) );
	}

	public function test_find_returns_full_shape_for_valid_post(): void {
		$listings = array(
			array(
				'platform'      => 'dmm-books',
				'external_id'   => 'ext-1',
				'affiliate_url' => 'https://example.com/a',
				'regular_url'   => 'https://example.com/r',
			),
		);
		$extras   = array(
			array(
				'key'   => 'author',
				'label' => '著者',
				'value' => 'たろう',
			),
		);

		$post = (object) array(
			'ID'            => 101,
			'post_type'     => ProductPostType::POST_TYPE,
			'post_title'    => 'タイトル',
			'post_content'  => '内容',
			'post_status'   => 'publish',
			'post_modified' => '2026-05-29 12:00:00',
		);

		WP_Mock::userFunction( 'get_post' )
			->with( 101 )
			->andReturn( $post );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 101, ProductPostType::META_EXTRAS, true )
			->andReturn( JsonField::encode( $extras ) );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 101, ProductPostType::META_LISTINGS, true )
			->andReturn( JsonField::encode( $listings ) );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 101, ProductPostType::META_PRODUCT_TYPE, true )
			->andReturn( 'ebook' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 101, ProductPostType::META_STOCK_STATUS, true )
			->andReturn( 'available' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 101, ProductPostType::META_SCHEMA_VERSION, true )
			->andReturn( SchemaVersion::CURRENT );

		$repo   = new ProductRepository();
		$result = $repo->find( 101 );

		$this->assertNotNull( $result );
		$this->assertSame( 101, $result['id'] );
		$this->assertSame( 'タイトル', $result['title'] );
		$this->assertSame( '内容', $result['content'] );
		$this->assertSame( 'publish', $result['status'] );
		$this->assertSame( 'ebook', $result['product_type'] );
		$this->assertSame( 'available', $result['stock_status'] );
		$this->assertSame( $extras, $result['extras'] );
		$this->assertSame( $listings, $result['listings'] );
		$this->assertSame( SchemaVersion::CURRENT, $result['schema_version'] );
		$this->assertSame( '2026-05-29 12:00:00', $result['modified'] );
	}

	public function test_find_by_external_id_returns_first_match(): void {
		WP_Mock::userFunction( 'get_posts' )
			->once()
			->andReturnUsing(
				function ( $args ) {
					$this->assertSame( ProductPostType::POST_TYPE, $args['post_type'] );
					$this->assertSame( 1, $args['posts_per_page'] );
					$this->assertSame( 'ids', $args['fields'] );
					$this->assertSame(
						ProductPostType::externalIdMetaKey( 'dmm-books' ),
						$args['meta_query'][0]['key']
					);
					$this->assertSame( 'ext-1', $args['meta_query'][0]['value'] );
					return array( 555 );
				}
			);

		$post = (object) array(
			'ID'            => 555,
			'post_type'     => ProductPostType::POST_TYPE,
			'post_title'    => 'X',
			'post_content'  => '',
			'post_status'   => 'publish',
			'post_modified' => '2026-05-29 10:00:00',
		);
		WP_Mock::userFunction( 'get_post' )
			->with( 555 )
			->andReturn( $post );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 555, Mockery::any(), true )
			->andReturn( '' );

		$repo   = new ProductRepository();
		$result = $repo->findByExternalId( 'dmm-books', 'ext-1' );

		$this->assertNotNull( $result );
		$this->assertSame( 555, $result['id'] );
	}

	public function test_find_by_external_id_returns_null_when_no_match(): void {
		WP_Mock::userFunction( 'get_posts' )
			->once()
			->andReturn( array() );

		$repo = new ProductRepository();
		$this->assertNull( $repo->findByExternalId( 'dmm-books', 'missing' ) );
	}

	public function test_save_insert_calls_wp_insert_post_and_meta(): void {
		WP_Mock::userFunction( 'wp_insert_post' )
			->once()
			->andReturnUsing(
				function ( $args, $wp_error ) {
					$this->assertSame( ProductPostType::POST_TYPE, $args['post_type'] );
					$this->assertSame( 'タイトル', $args['post_title'] );
					$this->assertSame( 'publish', $args['post_status'] );
					$this->assertTrue( $wp_error );
					$this->assertArrayNotHasKey( 'ID', $args );
					return 999;
				}
			);

		WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );

		$repo = new ProductRepository();
		$id   = $repo->save(
			array(
				'title'        => 'タイトル',
				'product_type' => 'ebook',
				'extras'       => array(),
				'listings'     => array(),
			)
		);

		$this->assertSame( 999, $id );
	}

	public function test_save_update_calls_wp_update_post(): void {
		WP_Mock::userFunction( 'wp_update_post' )
			->once()
			->andReturnUsing(
				function ( $args, $wp_error ) {
					$this->assertSame( 321, $args['ID'] );
					$this->assertSame( ProductPostType::POST_TYPE, $args['post_type'] );
					$this->assertTrue( $wp_error );
					return 321;
				}
			);

		WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );

		$repo = new ProductRepository();
		$id   = $repo->save(
			array(
				'id'           => 321,
				'title'        => '更新後',
				'product_type' => 'ebook',
			)
		);

		$this->assertSame( 321, $id );
	}

	public function test_save_writes_schema_version_meta(): void {
		WP_Mock::userFunction( 'wp_insert_post' )->andReturn( 800 );

		$saw_schema_version = false;
		WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$saw_schema_version ) {
					if ( ProductPostType::META_SCHEMA_VERSION === $key ) {
						$saw_schema_version = ( SchemaVersion::CURRENT === $value && 800 === $post_id );
					}
					return true;
				}
			);

		$repo = new ProductRepository();
		$repo->save(
			array(
				'title'        => 'A',
				'product_type' => 'generic',
			)
		);

		$this->assertTrue( $saw_schema_version );
	}

	public function test_save_mirrors_external_ids_for_each_listing(): void {
		WP_Mock::userFunction( 'wp_insert_post' )->andReturn( 700 );

		$mirror_calls = array();
		WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$mirror_calls ) {
					if ( 0 === strpos( $key, ProductPostType::META_EXTID_PREFIX ) ) {
						$mirror_calls[ $key ] = $value;
					}
					return true;
				}
			);

		$repo = new ProductRepository();
		$repo->save(
			array(
				'title'        => 'A',
				'product_type' => 'ebook',
				'listings'     => array(
					array(
						'platform'    => 'dmm-books',
						'external_id' => 'dmm-ext-1',
					),
					array(
						'platform'    => 'amazon-kindle',
						'external_id' => 'B0XXX',
					),
					// external_id 欠損は無視する。
					array(
						'platform'    => 'rakuten-kobo',
						'external_id' => '',
					),
				),
			)
		);

		$this->assertSame(
			array(
				ProductPostType::externalIdMetaKey( 'dmm-books' )     => 'dmm-ext-1',
				ProductPostType::externalIdMetaKey( 'amazon-kindle' ) => 'B0XXX',
			),
			$mirror_calls
		);
	}

	public function test_delete_calls_wp_delete_post_with_force_true(): void {
		WP_Mock::userFunction( 'wp_delete_post' )
			->once()
			->with( 12, true )
			->andReturn( (object) array( 'ID' => 12 ) );

		$repo = new ProductRepository();
		$this->assertTrue( $repo->delete( 12 ) );
	}

	public function test_delete_returns_false_when_wp_delete_post_fails(): void {
		WP_Mock::userFunction( 'wp_delete_post' )
			->once()
			->with( 13, true )
			->andReturn( false );

		$repo = new ProductRepository();
		$this->assertFalse( $repo->delete( 13 ) );
	}

	public function test_findBySlug_returns_product_when_post_exists(): void {
		$repo = new ProductRepository();

		\WP_Mock::userFunction(
			'get_posts',
			array(
				'times'  => 1,
				'args'   => array(
					\WP_Mock\Functions::type( 'array' ),
				),
				'return' => array( 4242 ),
			)
		);

		$post = (object) array(
			'ID'            => 4242,
			'post_type'     => 'affilicard_product',
			'post_title'    => 'Slug Hit',
			'post_content'  => '',
			'post_status'   => 'publish',
			'post_modified' => '2026-06-01 00:00:00',
		);
		\WP_Mock::userFunction( 'get_post', array( 'return' => $post ) );
		\WP_Mock::userFunction( 'get_post_meta', array( 'return' => '' ) );

		$result = $repo->findBySlug( 'slug-hit' );

		$this->assertIsArray( $result );
		$this->assertSame( 4242, $result['id'] );
		$this->assertSame( 'Slug Hit', $result['title'] );
	}

	public function test_findBySlug_returns_null_when_no_match(): void {
		$repo = new ProductRepository();

		\WP_Mock::userFunction(
			'get_posts',
			array(
				'times'  => 1,
				'return' => array(),
			)
		);

		$this->assertNull( $repo->findBySlug( 'missing' ) );
	}

	public function test_count_fallback_products_counts_listings_with_empty_affiliate_url(): void {
		$wpdb           = Mockery::mock( 'wpdb' );
		$wpdb->postmeta = 'wp_postmeta';
		$wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				static function ( ...$args ) {
					$query     = (string) $args[0];
					$variables = array_slice( $args, 1 );
					$quoted    = array_map(
						static function ( $v ) {
							return "'" . $v . "'";
						},
						$variables
					);
					return vsprintf( str_replace( '%s', '%s', $query ), $quoted );
				}
			);
		$wpdb->shouldReceive( 'get_col' )->andReturn( array( 1, 2, 3 ) );
		$GLOBALS['wpdb'] = $wpdb;

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 1, ProductPostType::META_LISTINGS, true )
			->andReturn(
				JsonField::encode(
					array(
						array(
							'platform'      => 'a',
							'affiliate_url' => '',
							'regular_url'   => 'https://example.com/r',
						),
					)
				)
			);
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 2, ProductPostType::META_LISTINGS, true )
			->andReturn(
				JsonField::encode(
					array(
						array(
							'platform'      => 'b',
							'affiliate_url' => 'https://example.com/a',
							'regular_url'   => 'https://example.com/r',
						),
					)
				)
			);
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 3, ProductPostType::META_LISTINGS, true )
			->andReturn(
				JsonField::encode(
					array(
						array(
							'platform'      => 'c',
							'affiliate_url' => '',
							'regular_url'   => 'https://example.com/r3',
						),
					)
				)
			);

		$repo = new ProductRepository();
		$this->assertSame( 2, $repo->countFallbackProducts() );
	}
}
