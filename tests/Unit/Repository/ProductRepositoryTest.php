<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Repository;

use Affilicard\PostType\ProductPostType;
use Affilicard\Repository\ProductRepository;
use Affilicard\Schema\SchemaVersion;
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
			->andReturn( $extras );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 101, ProductPostType::META_LISTINGS, true )
			->andReturn( $listings );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 101, ProductPostType::META_PRODUCT_TYPE, true )
			->andReturn( 'ebook' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 101, ProductPostType::META_STOCK_STATUS, true )
			->andReturn( 'available' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 101, ProductPostType::META_SCHEMA_VERSION, true )
			->andReturn( SchemaVersion::CURRENT );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 101, ProductPostType::META_RELEASE_DATE, true )
			->andReturn( '' );

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

	public function test_find_includes_release_date(): void {
		$post = (object) array(
			'ID'            => 7,
			'post_type'     => \Affilicard\PostType\ProductPostType::POST_TYPE,
			'post_title'    => 'X 5巻',
			'post_content'  => '',
			'post_status'   => 'publish',
			'post_modified' => '2026-06-01 00:00:00',
		);
		WP_Mock::userFunction( 'get_post', array( 'return' => $post ) );
		WP_Mock::userFunction(
			'get_post_meta',
			array(
				'return' => function ( $id, $key, $single ) {
					if ( \Affilicard\PostType\ProductPostType::META_RELEASE_DATE === $key ) {
						return '2026-07-17';
					}
					return '';
				},
			)
		);
		// StockStatus::normalize 用に空→available。JsonField decode は配列以外で array() を返す。
		$repo = new \Affilicard\Repository\ProductRepository();
		$out  = $repo->find( 7 );
		$this->assertSame( '2026-07-17', $out['release_date'] );
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

	public function test_save_deletes_stale_external_id_mirror_meta(): void {
		// 既存は dmm-books の extid mirror を持つが、新 listing は amazon-kindle のみ。
		// 旧 affilicard_extid_dmm-books が delete_post_meta で削除され、
		// 新 affilicard_extid_amazon-kindle が書かれることを検証する。
		WP_Mock::userFunction( 'wp_insert_post' )->andReturn( 800 );

		// 全 meta 列挙: extid mirror + 無関係 meta を返す。
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 800 )
			->andReturn(
				array(
					ProductPostType::externalIdMetaKey( 'dmm-books' ) => array( 'old-ext' ),
					ProductPostType::META_PRODUCT_TYPE => array( 'ebook' ),
				)
			);

		WP_Mock::userFunction( 'delete_post_meta' )
			->once()
			->with( 800, ProductPostType::externalIdMetaKey( 'dmm-books' ) )
			->andReturn( true );
		// 無関係 meta は削除されないこと。
		WP_Mock::userFunction( 'delete_post_meta' )
			->with( 800, ProductPostType::META_PRODUCT_TYPE )
			->never();

		$mirror_calls = array();
		WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$mirror_calls ) {
					if ( 0 === strpos( (string) $key, ProductPostType::META_EXTID_PREFIX ) ) {
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
						'platform'    => 'amazon-kindle',
						'external_id' => 'B0NEW',
					),
				),
			)
		);

		$this->assertSame(
			array( ProductPostType::externalIdMetaKey( 'amazon-kindle' ) => 'B0NEW' ),
			$mirror_calls
		);
		$this->assertConditionsMet();
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

	public function test_saveMeta_calls_update_post_meta_for_all_keys_and_does_not_call_insert_or_update_post(): void {
		// wp_update_post / wp_insert_post should never be called.
		WP_Mock::userFunction( 'wp_update_post' )->never();
		WP_Mock::userFunction( 'wp_insert_post' )->never();

		$extras   = array(
			array(
				'label' => '著者',
				'value' => 'たろう',
			),
		);
		$listings = array(
			array(
				'platform'    => 'dmm-books',
				'external_id' => 'ext-1',
			),
		);

		$called_keys = array();
		WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$called_keys ) {
					$this->assertSame( 5, $post_id );
					$called_keys[] = $key;
					if ( ProductPostType::META_EXTRAS === $key || ProductPostType::META_LISTINGS === $key ) {
						$this->assertIsArray( $value );
					}
					return true;
				}
			);

		$repo = new ProductRepository();
		$repo->saveMeta(
			5,
			array(
				'product_type' => 'ebook',
				'stock_status' => 'available',
				'extras'       => $extras,
				'listings'     => $listings,
			)
		);

		$this->assertContains( ProductPostType::META_PRODUCT_TYPE, $called_keys );
		$this->assertContains( ProductPostType::META_STOCK_STATUS, $called_keys );
		$this->assertContains( ProductPostType::META_EXTRAS, $called_keys );
		$this->assertContains( ProductPostType::META_LISTINGS, $called_keys );
		$this->assertContains( ProductPostType::META_SCHEMA_VERSION, $called_keys );
		$this->assertContains( ProductPostType::META_RELEASE_DATE, $called_keys );
	}

	public function test_saveMeta_uses_generic_when_product_type_empty(): void {
		WP_Mock::userFunction( 'wp_update_post' )->never();
		WP_Mock::userFunction( 'wp_insert_post' )->never();

		$seen_type = null;
		WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$seen_type ) {
					if ( ProductPostType::META_PRODUCT_TYPE === $key ) {
						$seen_type = $value;
					}
					return true;
				}
			);

		$repo = new ProductRepository();
		$repo->saveMeta( 5, array() );

		$this->assertSame( 'generic', $seen_type );
	}

	public function test_count_fallback_products_counts_listings_with_empty_affiliate_url(): void {
		WP_Mock::userFunction( 'get_posts' )
			->once()
			->andReturn( array( 1, 2, 3 ) );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 1, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform'      => 'a',
						'affiliate_url' => '',
						'regular_url'   => 'https://example.com/r',
					),
				)
			);
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 2, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform'      => 'b',
						'affiliate_url' => 'https://example.com/a',
						'regular_url'   => 'https://example.com/r',
					),
				)
			);
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 3, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform'      => 'c',
						'affiliate_url' => '',
						'regular_url'   => 'https://example.com/r3',
					),
				)
			);

		$repo = new ProductRepository();
		$this->assertSame( 2, $repo->countFallbackProducts() );
	}

	// -------------------------------------------------------
	// search() テスト
	// -------------------------------------------------------

	/**
	 * fakePost ヘルパ: 検索結果用の軽量 post オブジェクト。
	 */
	private function fakePost( int $id, string $title, string $modified ): object {
		return (object) array(
			'ID'            => $id,
			'post_type'     => ProductPostType::POST_TYPE,
			'post_title'    => $title,
			'post_status'   => 'publish',
			'post_modified' => $modified,
		);
	}

	public function test_search_merges_title_and_external_id_matches_unique_by_id(): void {
		$byTitle = array( $this->fakePost( 10, 'タイトル一致', '2026-06-10 00:00:00' ) );
		$byExtId = array(
			$this->fakePost( 10, 'タイトル一致', '2026-06-10 00:00:00' ), // 重複
			$this->fakePost( 20, 'ID一致', '2026-06-15 00:00:00' ),
		);

		$platformCode = 'test-platform';
		$searchTerm   = 'abc';

		WP_Mock::userFunction( 'get_posts' )->andReturnUsing(
			function ( $args ) use ( $byTitle, $byExtId, $platformCode, $searchTerm ) {
				if ( isset( $args['meta_query'] ) ) {
					// extid 用クエリの構造をアサート
					\PHPUnit\Framework\Assert::assertSame( 'OR', $args['meta_query']['relation'] );
					\PHPUnit\Framework\Assert::assertSame(
						array(
							'key'     => \Affilicard\PostType\ProductPostType::externalIdMetaKey( $platformCode ),
							'value'   => $searchTerm,
							'compare' => 'LIKE',
						),
						$args['meta_query'][0]
					);
					\PHPUnit\Framework\Assert::assertSame( \Affilicard\PostType\ProductPostType::POST_TYPE, $args['post_type'] );
					\PHPUnit\Framework\Assert::assertSame( 'any', $args['post_status'] );
					\PHPUnit\Framework\Assert::assertSame( -1, $args['posts_per_page'] );
					return $byExtId;
				}
				// title 用クエリの構造をアサート
				\PHPUnit\Framework\Assert::assertSame( $searchTerm, $args['s'] );
				\PHPUnit\Framework\Assert::assertSame( \Affilicard\PostType\ProductPostType::POST_TYPE, $args['post_type'] );
				\PHPUnit\Framework\Assert::assertSame( 'any', $args['post_status'] );
				\PHPUnit\Framework\Assert::assertSame( -1, $args['posts_per_page'] );
				\PHPUnit\Framework\Assert::assertSame( 'modified', $args['orderby'] );
				\PHPUnit\Framework\Assert::assertSame( 'DESC', $args['order'] );
				return $byTitle;
			}
		);

		// 各 post の共通モック
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_platforms', array() )
			->andReturn(
				array(
					array(
						'code'            => $platformCode,
						'name'            => 'テストプラットフォーム',
						'provider'        => 'manual',
						'displayOrder'    => 1,
						'enabled'         => true,
						'applicableTypes' => array(),
						'buttonLabel'     => 'テスト',
						'brandColor'      => '#000000',
						'buttonTextColor' => '#ffffff',
					),
				)
			);
		WP_Mock::userFunction( 'get_post_meta' )
			->andReturn( 'generic' );
		WP_Mock::userFunction( 'get_the_post_thumbnail_url' )
			->andReturn( false );

		$repo   = new ProductRepository();
		$result = $repo->search( $searchTerm, 20, 1 );

		$ids = array_map( static fn( $i ) => $i['id'], $result['items'] );
		$this->assertSame( array( 20, 10 ), $ids ); // modified 降順・一意
		$this->assertSame( 2, $result['total'] );
	}

	public function test_search_empty_term_returns_recent_products_with_wp_count_posts_total(): void {
		$posts = array(
			$this->fakePost( 5, '最近の商品', '2026-06-18 00:00:00' ),
		);

		WP_Mock::userFunction( 'get_posts' )
			->once()
			->andReturnUsing(
				function ( $args ) use ( $posts ) {
					// term 空時は s キーも meta_query キーもない
					\PHPUnit\Framework\Assert::assertSame( ProductPostType::POST_TYPE, $args['post_type'] );
					\PHPUnit\Framework\Assert::assertSame( 'any', $args['post_status'] );
					\PHPUnit\Framework\Assert::assertSame( 'modified', $args['orderby'] );
					\PHPUnit\Framework\Assert::assertSame( 'DESC', $args['order'] );
					\PHPUnit\Framework\Assert::assertArrayHasKey( 'paged', $args );
					\PHPUnit\Framework\Assert::assertArrayNotHasKey( 's', $args );
					\PHPUnit\Framework\Assert::assertArrayNotHasKey( 'meta_query', $args );
					return ( ! isset( $args['s'] ) && ! isset( $args['meta_query'] ) ) ? $posts : array();
				}
			);

		WP_Mock::userFunction( 'wp_count_posts' )
			->with( ProductPostType::POST_TYPE )
			->andReturn(
				(object) array(
					'publish' => 3,
					'draft'   => 2,
				)
			);

		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_platforms', array() )
			->andReturn( array() );
		WP_Mock::userFunction( 'get_post_meta' )
			->andReturn( 'generic' );
		WP_Mock::userFunction( 'get_the_post_thumbnail_url' )
			->andReturn( false );

		$repo   = new ProductRepository();
		$result = $repo->search( '', 20, 1 );

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 5, $result['items'][0]['id'] );
		// total は全ステータス合算で publish 3 件と draft 2 件の計 5 件。
		$this->assertSame( 5, $result['total'] );
	}

	public function test_search_with_no_enabled_platforms_skips_extid_query_and_returns_title_results_only(): void {
		$byTitle = array( $this->fakePost( 30, 'タイトルのみ一致', '2026-06-19 00:00:00' ) );

		// プラットフォーム未登録: get_posts は1回のみ（title 用 s クエリのみ）
		WP_Mock::userFunction( 'get_posts' )
			->once()
			->andReturnUsing(
				function ( $args ) use ( $byTitle ) {
					// extid 用クエリ（meta_query）が呼ばれていないことを確認
					\PHPUnit\Framework\Assert::assertArrayNotHasKey( 'meta_query', $args );
					\PHPUnit\Framework\Assert::assertSame( 'no-platform-term', $args['s'] );
					return $byTitle;
				}
			);

		// enabled プラットフォーム 0 件
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_platforms', array() )
			->andReturn( array() );
		WP_Mock::userFunction( 'get_post_meta' )
			->andReturn( 'generic' );
		WP_Mock::userFunction( 'get_the_post_thumbnail_url' )
			->andReturn( false );

		$repo   = new ProductRepository();
		$result = $repo->search( 'no-platform-term', 20, 1 );

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 30, $result['items'][0]['id'] );
		$this->assertSame( 1, $result['total'] );
	}

	public function test_listingSummary_returns_first_platform_and_price(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 99, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'dmm-books',
						'price'    => '¥660',
					),
					array(
						'platform' => 'amazon-kindle',
						'price'    => '¥550',
					),
				)
			);

		$repo   = new ProductRepository();
		$result = $repo->listingSummary( 99 );

		$this->assertSame( '¥660', $result['price'] );
		$this->assertSame( 'dmm-books', $result['platform'] );
	}

	public function test_listingSummary_returns_empty_when_no_listings(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 98, ProductPostType::META_LISTINGS, true )
			->andReturn( array() );

		$repo   = new ProductRepository();
		$result = $repo->listingSummary( 98 );

		$this->assertSame( '', $result['price'] );
		$this->assertSame( '', $result['platform'] );
	}

	public function test_syncDerivedMeta_mirrors_external_ids_and_sets_schema_version(): void {
		$repo = new ProductRepository();
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform'    => 'dmm-books',
						'external_id' => 'X1',
					),
				)
			);
		// extid mirror の stale cleanup 用の全 meta 列挙（既存 stale なし）。
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42 )
			->andReturn( array() );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 42, 'affilicard_extid_dmm-books', 'X1' )->andReturn( true );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 42, ProductPostType::META_SCHEMA_VERSION, \Affilicard\Schema\SchemaVersion::CURRENT )->andReturn( true );
		$repo->syncDerivedMeta( 42 );
		$this->assertConditionsMet();
	}
}
