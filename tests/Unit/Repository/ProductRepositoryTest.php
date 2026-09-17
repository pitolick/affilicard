<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Repository;

use Affilicard\PostType\ProductPostType;
use Affilicard\Pricing\FetchStatus;
use Affilicard\Repository\ProductLockUnavailable;
use Affilicard\Repository\ProductRepository;
use Affilicard\Schema\SchemaVersion;
use Affilicard\Settings\GeneralSettings;
use Mockery;
use ReflectionMethod;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ProductRepositoryTest extends TestCase {

	/**
	 * get_post_meta( META_LISTINGS ) が返す「保存前の listings」。
	 *
	 * **テストごとに userFunction を登録し直しても切り替わらない。** WP_Mock は
	 * 同じ関数名について最初の期待だけを保持するため、値はここから読む。
	 *
	 * @var array<int, mixed>
	 */
	private array $storedListings = array();

	/**
	 * 直前の saveMetaLockTimeline() が捕まえた「書かずに見送った」例外（無ければ null）。
	 *
	 * helper の中で catch するのは、投げた**あと**の時系列（＝何を書かずに抜けたか）を
	 * 呼び出し側で検証したいためである。expectException() を使うと helper から先が
	 * 走らず、時系列を受け取れない。
	 *
	 * @var ProductLockUnavailable|null
	 */
	private ?ProductLockUnavailable $lastRefusal = null;

	/**
	 * 直前の saveMetaLockTimeline() が update_post_meta へ渡した meta キー一覧。
	 *
	 * @var array<int, string>
	 */
	private array $savedMetaKeys = array();

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )
			->andReturnUsing(
				static function ( $text ) {
					return $text;
				}
			);
		// 実 WordPress の esc_url_raw() は javascript:/data: 等の危険スキームを排除して
		// 空文字を返す。フォールバック判定（OfferUrl）はカードの CTA と同じこの検証を
		// 通すため、passthru ではなく危険スキームの排除だけ最小限に再現する
		// （CardRendererTest と同じ stub）。
		WP_Mock::userFunction( 'esc_url_raw' )
			->andReturnUsing(
				static function ( $value ) {
					$value = is_scalar( $value ) ? (string) $value : '';
					if ( 1 === preg_match( '/^\s*(javascript|data|vbscript)\s*:/i', $value ) ) {
						return '';
					}
					return $value;
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
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 101, ProductPostType::META_MASK_BLUR, true )
			->andReturn( '' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 101, ProductPostType::META_MASK_R18, true )
			->andReturn( '' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 101, ProductPostType::META_MASK_LABEL, true )
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
		$this->assertFalse( $result['mask_blur'] );
		$this->assertFalse( $result['mask_r18'] );
		$this->assertSame( '', $result['mask_label'] );
	}

	public function test_find_returns_slug_from_post_name(): void {
		$post = (object) array(
			'ID'            => 202,
			'post_type'     => ProductPostType::POST_TYPE,
			'post_title'    => 'タイトル',
			'post_name'     => 'sample-title-vol1',
			'post_content'  => '',
			'post_status'   => 'publish',
			'post_modified' => '2026-08-13 12:00:00',
		);

		WP_Mock::userFunction( 'get_post' )
			->with( 202 )
			->andReturn( $post );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 202, ProductPostType::META_EXTRAS, true )
			->andReturn( array() );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 202, ProductPostType::META_LISTINGS, true )
			->andReturn( array() );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 202, ProductPostType::META_PRODUCT_TYPE, true )
			->andReturn( 'ebook' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 202, ProductPostType::META_STOCK_STATUS, true )
			->andReturn( 'available' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 202, ProductPostType::META_SCHEMA_VERSION, true )
			->andReturn( SchemaVersion::CURRENT );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 202, ProductPostType::META_RELEASE_DATE, true )
			->andReturn( '' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 202, ProductPostType::META_MASK_BLUR, true )
			->andReturn( '' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 202, ProductPostType::META_MASK_R18, true )
			->andReturn( '' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 202, ProductPostType::META_MASK_LABEL, true )
			->andReturn( '' );

		$repo   = new ProductRepository();
		$result = $repo->find( 202 );

		$this->assertNotNull( $result );
		$this->assertSame( 'sample-title-vol1', $result['slug'] );
	}

	/**
	 * post_name が無い投稿（下書き直後など）でも例外にせず空文字を返す。
	 */
	public function test_find_returns_empty_slug_when_post_name_missing(): void {
		$post = (object) array(
			'ID'            => 203,
			'post_type'     => ProductPostType::POST_TYPE,
			'post_title'    => 'タイトル',
			'post_content'  => '',
			'post_status'   => 'publish',
			'post_modified' => '2026-08-13 12:00:00',
		);

		WP_Mock::userFunction( 'get_post' )
			->with( 203 )
			->andReturn( $post );

		foreach (
			array(
				ProductPostType::META_EXTRAS,
				ProductPostType::META_LISTINGS,
			) as $meta_key
		) {
			WP_Mock::userFunction( 'get_post_meta' )
				->with( 203, $meta_key, true )
				->andReturn( array() );
		}
		foreach (
			array(
				ProductPostType::META_PRODUCT_TYPE,
				ProductPostType::META_STOCK_STATUS,
				ProductPostType::META_SCHEMA_VERSION,
				ProductPostType::META_RELEASE_DATE,
				ProductPostType::META_MASK_BLUR,
				ProductPostType::META_MASK_R18,
				ProductPostType::META_MASK_LABEL,
			) as $meta_key
		) {
			WP_Mock::userFunction( 'get_post_meta' )
				->with( 203, $meta_key, true )
				->andReturn( '' );
		}

		$repo   = new ProductRepository();
		$result = $repo->find( 203 );

		$this->assertNotNull( $result );
		$this->assertSame( '', $result['slug'] );
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

	public function test_find_includes_mask_fields(): void {
		$post = (object) array(
			'ID'            => 8,
			'post_type'     => ProductPostType::POST_TYPE,
			'post_title'    => 'マスク商品',
			'post_content'  => '',
			'post_status'   => 'publish',
			'post_modified' => '2026-07-07 00:00:00',
		);
		WP_Mock::userFunction( 'get_post', array( 'return' => $post ) );
		WP_Mock::userFunction(
			'get_post_meta',
			array(
				'return' => function ( $id, $key, $single ) {
					$map = array(
						ProductPostType::META_MASK_BLUR  => '1',
						ProductPostType::META_MASK_R18   => '',
						ProductPostType::META_MASK_LABEL => 'サンプル注意文言',
					);
					return $map[ $key ] ?? '';
				},
			)
		);

		$repo    = new ProductRepository();
		$product = $repo->find( 8 );

		$this->assertTrue( $product['mask_blur'] );
		$this->assertFalse( $product['mask_r18'] );
		$this->assertSame( 'サンプル注意文言', $product['mask_label'] );
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
		// saveMeta() の listings RMW は ListingLock の中で行う（GET_LOCK/RELEASE_LOCK）。
		$this->mockLockWpdb( 1 );
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
		// saveMeta() の listings RMW は ListingLock の中で行う（GET_LOCK/RELEASE_LOCK）。
		$this->mockLockWpdb( 1 );
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
		// saveMeta() の listings RMW は ListingLock の中で行う（GET_LOCK/RELEASE_LOCK）。
		$this->mockLockWpdb( 1 );
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
		// saveMeta() の listings RMW は ListingLock の中で行う（GET_LOCK/RELEASE_LOCK）。
		$this->mockLockWpdb( 1 );
		WP_Mock::userFunction( 'wp_insert_post' )->andReturn( 700 );

		$mirror_calls = array();
		WP_Mock::userFunction( 'add_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key, $value, $unique ) use ( &$mirror_calls ) {
					if ( 0 === strpos( (string) $key, ProductPostType::META_EXTID_PREFIX ) ) {
						$mirror_calls[ $key ][] = $value;
					}
					return true;
				}
			);
		// ミラーは保存後の META_LISTINGS を読み直して作るため、書いた値が読み戻る
		// スタブにする（実 WordPress と同じく「書いたものが入っている」状態）。
		WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key, $value ) {
					if ( ProductPostType::META_LISTINGS === $key ) {
						$this->storedListings = is_array( $value ) ? $value : array();
					}
					return true;
				}
			);
		WP_Mock::userFunction( 'get_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key = '', $single = false ) {
					return ProductPostType::META_LISTINGS === $key ? $this->storedListings : array();
				}
			);

		$repo = new ProductRepository();
		$repo->save(
			array(
				'title'        => 'A',
				'product_type' => 'ebook',
				'listings'     => array(
					array(
						'platform' => 'dmm-books',
						'offers'   => array(
							array(
								'external_id' => 'dmm-ext-1',
								'regular_url' => 'https://example.test/dmm-ext-1',
							),
						),
					),
					array(
						'platform' => 'amazon-kindle',
						'offers'   => array(
							array(
								'external_id' => 'B0XXX',
								'regular_url' => 'https://example.test/B0XXX',
							),
						),
					),
					// external_id 欠損の購入リンクは無視する。
					array(
						'platform' => 'rakuten-kobo',
						'offers'   => array(
							array(
								'external_id' => '',
								'regular_url' => 'https://example.test/rakuten',
							),
						),
					),
				),
			)
		);

		$this->assertSame(
			array(
				ProductPostType::externalIdMetaKey( 'dmm-books' )     => array( 'dmm-ext-1' ),
				ProductPostType::externalIdMetaKey( 'amazon-kindle' ) => array( 'B0XXX' ),
			),
			$mirror_calls
		);
	}

	public function test_save_deletes_stale_external_id_mirror_meta(): void {
		// saveMeta() の listings RMW は ListingLock の中で行う（GET_LOCK/RELEASE_LOCK）。
		$this->mockLockWpdb( 1 );
		// 既存は dmm-books の extid mirror を持つが、新 listing は amazon-kindle のみ。
		// 旧 affilicard_extid_dmm-books の値が delete_post_meta で個別に削除され、
		// 新 affilicard_extid_amazon-kindle が add_post_meta で書かれることを検証する。
		WP_Mock::userFunction( 'wp_insert_post' )->andReturn( 800 );

		// saveMeta() は保存前の listings を読み（身元を訂正された購入リンクの
		// fetch_status を白紙に戻すため。OfferStatusReset 参照）、保存後にもう一度
		// 読んでミラーを作る。保存前は空で、書いた値がそのまま読み戻る。
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 800, ProductPostType::META_LISTINGS, true )
			->andReturnUsing( fn () => $this->storedListings );

		// 全 meta 列挙: extid mirror + 無関係 meta を返す。
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 800 )
			->andReturn(
				array(
					ProductPostType::externalIdMetaKey( 'dmm-books' ) => array( 'old-ext' ),
					ProductPostType::META_PRODUCT_TYPE => array( 'ebook' ),
				)
			);
		// 「既に mirror 済みか」の判定(add_post_meta 前の重複防止チェック)。既存値なし。
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 800, Mockery::any(), false )
			->andReturn( array() );

		WP_Mock::userFunction( 'delete_post_meta' )
			->once()
			->with( 800, ProductPostType::externalIdMetaKey( 'dmm-books' ), 'old-ext' )
			->andReturn( true );
		// 無関係 meta は削除されないこと。
		WP_Mock::userFunction( 'delete_post_meta' )
			->with( 800, ProductPostType::META_PRODUCT_TYPE, Mockery::any() )
			->never();

		$mirror_calls = array();
		WP_Mock::userFunction( 'add_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key, $value, $unique ) use ( &$mirror_calls ) {
					if ( 0 === strpos( (string) $key, ProductPostType::META_EXTID_PREFIX ) ) {
						$mirror_calls[ $key ][] = $value;
					}
					return true;
				}
			);
		WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key, $value ) {
					if ( ProductPostType::META_LISTINGS === $key ) {
						$this->storedListings = is_array( $value ) ? $value : array();
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
						'platform' => 'amazon-kindle',
						'offers'   => array(
							array(
								'external_id' => 'B0NEW',
								'regular_url' => 'https://example.test/B0NEW',
							),
						),
					),
				),
			)
		);

		$this->assertSame(
			array( ProductPostType::externalIdMetaKey( 'amazon-kindle' ) => array( 'B0NEW' ) ),
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
		// saveMeta() の listings RMW は ListingLock の中で行う（GET_LOCK/RELEASE_LOCK）。
		$this->mockLockWpdb( 1 );
		// wp_update_post / wp_insert_post should never be called.
		WP_Mock::userFunction( 'wp_update_post' )->never();
		WP_Mock::userFunction( 'wp_insert_post' )->never();
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => is_string( $v ) ? trim( $v ) : $v );

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

		$called_keys   = array();
		$called_values = array();
		WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$called_keys, &$called_values ) {
					$this->assertSame( 5, $post_id );
					$called_keys[]         = $key;
					$called_values[ $key ] = $value;
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
				'mask_blur'    => true,
				'mask_r18'     => false,
				'mask_label'   => 'サンプル注意文言',
			)
		);

		$this->assertContains( ProductPostType::META_PRODUCT_TYPE, $called_keys );
		$this->assertContains( ProductPostType::META_STOCK_STATUS, $called_keys );
		$this->assertContains( ProductPostType::META_EXTRAS, $called_keys );
		$this->assertContains( ProductPostType::META_LISTINGS, $called_keys );
		$this->assertContains( ProductPostType::META_SCHEMA_VERSION, $called_keys );
		$this->assertContains( ProductPostType::META_RELEASE_DATE, $called_keys );
		$this->assertContains( ProductPostType::META_MASK_BLUR, $called_keys );
		$this->assertContains( ProductPostType::META_MASK_R18, $called_keys );
		$this->assertContains( ProductPostType::META_MASK_LABEL, $called_keys );
		$this->assertTrue( $called_values[ ProductPostType::META_MASK_BLUR ] );
		$this->assertFalse( $called_values[ ProductPostType::META_MASK_R18 ] );
		$this->assertSame( 'サンプル注意文言', $called_values[ ProductPostType::META_MASK_LABEL ] );
	}

	public function test_saveMeta_uses_generic_when_product_type_empty(): void {
		// saveMeta() の listings RMW は ListingLock の中で行う（GET_LOCK/RELEASE_LOCK）。
		$this->mockLockWpdb( 1 );
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

	/**
	 * saveMeta() が META_LISTINGS へ書いた値を捕まえる。
	 *
	 * @param array<int, mixed> $stored   保存前の listings meta。
	 * @param array<int, mixed> $incoming saveMeta() へ渡す listings。
	 * @return array<int, mixed>
	 */
	private function saveMetaAndCaptureListings( array $stored, array $incoming ): array {
		// saveMeta() の listings RMW は ListingLock の中で行う（GET_LOCK/RELEASE_LOCK）。
		$this->mockLockWpdb( 1 );
		$this->storedListings = $stored;

		WP_Mock::userFunction( 'wp_update_post' )->never();
		WP_Mock::userFunction( 'wp_insert_post' )->never();
		// extid ミラー同期（syncExternalIdMirror）が触る関数。ここでの関心事ではない。
		WP_Mock::userFunction( 'add_post_meta' )->andReturn( true );
		WP_Mock::userFunction( 'delete_post_meta' )->andReturn( true );
		WP_Mock::userFunction( 'get_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key = '', $single = false ) {
					return ProductPostType::META_LISTINGS === $key ? $this->storedListings : array();
				}
			);

		$saved = array();
		WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing(
				static function ( $post_id, $key, $value ) use ( &$saved ) {
					if ( ProductPostType::META_LISTINGS === $key ) {
						$saved = is_array( $value ) ? $value : array();
					}
					return true;
				}
			);

		$repo = new ProductRepository();
		$repo->saveMeta( 5, array( 'listings' => $incoming ) );

		return $saved;
	}

	/**
	 * extid ミラーは「書こうとした値」ではなく「実際に META_LISTINGS に入っている値」から作る。
	 *
	 * ミラー（`affilicard_extid_<platform>`）は findByExternalId() の索引であり、
	 * 自動作成が既存商品を見つけられるかどうかがこれで決まる。書き込みが落ちたのに
	 * 渡された配列からミラーを作ると、**商品が持っていない external_id で引ける**
	 * ようになり（自動作成が既存商品を誤検出して更新先を間違える）、同時に本当に
	 * 保存されている external_id の行が消える（重複商品を作る）。
	 *
	 * ここでは listings の update_post_meta だけを失敗させ（WordPress は書けなかったとき
	 * false を返す）、保存前の値が残った状態を作る。
	 */
	public function test_saveMetaのミラーは保存後のlistingsから作る(): void {
		// saveMeta() の listings RMW は ListingLock の中で行う（GET_LOCK/RELEASE_LOCK）。
		$this->mockLockWpdb( 1 );
		$this->storedListings = array(
			array(
				'platform' => 'rakuten-kobo',
				'offers'   => array( array( 'external_id' => 'rk-stored' ) ),
			),
		);

		WP_Mock::userFunction( 'wp_update_post' )->never();
		WP_Mock::userFunction( 'wp_insert_post' )->never();
		WP_Mock::userFunction( 'delete_post_meta' )->andReturn( true );
		WP_Mock::userFunction( 'get_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key = '', $single = false ) {
					return ProductPostType::META_LISTINGS === $key ? $this->storedListings : array();
				}
			);
		// listings の書き込みだけ失敗させる（＝保存後も storedListings のまま）。
		WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing(
				static function ( $post_id, $key, $value ) {
					return ProductPostType::META_LISTINGS !== $key;
				}
			);

		$added = array();
		WP_Mock::userFunction( 'add_post_meta' )
			->andReturnUsing(
				static function ( $post_id, $key, $value, $unique = false ) use ( &$added ) {
					$added[] = array( (string) $key, (string) $value );
					return true;
				}
			);

		( new ProductRepository() )->saveMeta(
			5,
			array(
				'listings' => array(
					array(
						'platform' => 'rakuten-kobo',
						'offers'   => array( array( 'external_id' => 'rk-incoming' ) ),
					),
				),
			)
		);

		$this->assertSame(
			array( array( ProductPostType::externalIdMetaKey( 'rakuten-kobo' ), 'rk-stored' ) ),
			$added,
			'ミラーは実際に保存されている external_id から作る'
		);
	}

	/**
	 * 保存前の listings（購入リンク 1 件・恒久失敗）。
	 *
	 * @return array<int, mixed>
	 */
	private function storedTerminalListings(): array {
		return array(
			array(
				'platform' => 'rakuten-kobo',
				'offers'   => array(
					array(
						'external_id'  => 'rk-old',
						'regular_url'  => 'https://example.test/old',
						'fetch_status' => FetchStatus::TERMINAL,
					),
				),
			),
		);
	}

	/**
	 * 運用者が external_id を訂正したら、前の身元で付いた恒久失敗を引き継がない。
	 *
	 * 引き継ぐと RefreshHandler::isGivenUp() が give-up マーカーと offer 自身の
	 * terminal を AND で見るため、訂正した購入リンクが cooldown のあいだ
	 * 再取得されない（＝訂正が数日なにもしない）。
	 */
	public function test_saveMetaはexternal_idを訂正した購入リンクのfetch_statusを白紙に戻す(): void {
		$saved = $this->saveMetaAndCaptureListings(
			$this->storedTerminalListings(),
			array(
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array(
						array(
							'external_id'  => 'rk-fixed',
							'regular_url'  => 'https://example.test/old',
							'fetch_status' => FetchStatus::TERMINAL,
						),
					),
				),
			)
		);

		$this->assertSame( FetchStatus::NONE, $saved[0]['offers'][0]['fetch_status'] );
		$this->assertSame( 'rk-fixed', $saved[0]['offers'][0]['external_id'] );
	}

	/** external_id を持たない購入リンクは regular_url が身元。訂正したら同じく白紙に戻す。 */
	public function test_saveMetaはregular_urlを訂正した購入リンクのfetch_statusを白紙に戻す(): void {
		$stored = array(
			array(
				'platform' => 'rakuten-kobo',
				'offers'   => array(
					array(
						'external_id'  => '',
						'regular_url'  => 'https://example.test/old',
						'fetch_status' => FetchStatus::TERMINAL,
					),
				),
			),
		);

		$saved = $this->saveMetaAndCaptureListings(
			$stored,
			array(
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array(
						array(
							'external_id'  => '',
							'regular_url'  => 'https://example.test/fixed',
							'fetch_status' => FetchStatus::TERMINAL,
						),
					),
				),
			)
		);

		$this->assertSame( FetchStatus::NONE, $saved[0]['offers'][0]['fetch_status'] );
	}

	/**
	 * 身元が変わっていない購入リンクは触らない。
	 *
	 * ここを触ると、恒久失敗した購入リンクが保存のたびに生き返り、廃盤 SKU への
	 * リトライを毎回焼くことになる（give-up の cooldown が意味を失う）。
	 */
	public function test_saveMetaは身元が変わらない購入リンクのfetch_statusを保つ(): void {
		$saved = $this->saveMetaAndCaptureListings(
			$this->storedTerminalListings(),
			array(
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array(
						array(
							'external_id'   => 'rk-old',
							'regular_url'   => 'https://example.test/old',
							'fetch_status'  => FetchStatus::TERMINAL,
							'display_order' => 20,
						),
					),
				),
			)
		);

		$this->assertSame( FetchStatus::TERMINAL, $saved[0]['offers'][0]['fetch_status'] );
		$this->assertSame( 20, $saved[0]['offers'][0]['display_order'] );
	}

	public function test_count_fallback_products_counts_listings_with_empty_affiliate_url(): void {
		WP_Mock::userFunction( 'get_posts' )
			->once()
			->andReturn( array( 1, 2, 3 ) );

		// hasFallbackListing() は listing 自体ではなく OfferSelector::select() が
		// 選んだ offer を見るため、フォールバック判定用フィールドは offers[] に置く。
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 1, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'a',
						'offers'   => array(
							array(
								'affiliate_url' => '',
								'regular_url'   => 'https://example.com/r',
							),
						),
					),
				)
			);
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 2, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'b',
						'offers'   => array(
							array(
								'affiliate_url' => 'https://example.com/a',
								'regular_url'   => 'https://example.com/r',
							),
						),
					),
				)
			);
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 3, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'c',
						'offers'   => array(
							array(
								'affiliate_url' => '',
								'regular_url'   => 'https://example.com/r3',
							),
						),
					),
				)
			);

		$repo = new ProductRepository();
		$this->assertSame( 2, $repo->countFallbackProducts() );
	}

	/**
	 * hasFallbackListing() は private static のため、production コードに
	 * テスト専用の公開エントリポイントを追加せずリフレクションで直接叩く。
	 */
	public function test_アフィリURL欠落の判定は選択された購入リンクを見る(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		$listings = array(
			array(
				'platform' => 'rakuten-kobo',
				'offers'   => array(
					array(
						'display_order' => 10,
						'external_id'   => 'a',
						'regular_url'   => 'https://example.test/a',
						'affiliate_url' => '',
					),
				),
			),
		);

		$method = new ReflectionMethod( ProductRepository::class, 'hasFallbackListing' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( null, $listings ) );
	}

	/**
	 * 先頭 offer に affiliate_url があればフォールバック表示ではない。
	 */
	public function test_アフィリURLがある購入リンクが選択されればフォールバックではない(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		$listings = array(
			array(
				'platform' => 'rakuten-kobo',
				'offers'   => array(
					array(
						'display_order' => 10,
						'external_id'   => 'a',
						'regular_url'   => 'https://example.test/a',
						'affiliate_url' => 'https://example.test/a?aff=1',
					),
				),
			),
		);

		$method = new ReflectionMethod( ProductRepository::class, 'hasFallbackListing' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( null, $listings ) );
	}

	/**
	 * 不正な affiliate_url は「無い」と同じ——カードは regular_url を出しているので
	 * ダッシュボードの件数も「フォールバック中」と数える。
	 *
	 * 判定を素の空判定（`'' === $affiliate`）で書いていたころは、この商品だけ
	 * カードの実物と答えが食い違っていた（`CardRenderer::ctaHref()` は
	 * `esc_url_raw()` で検証してから採否を決めるため regular_url へ倒れる）。
	 */
	public function test_不正なアフィリURLの商品もフォールバックとして数える(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		$listings = array(
			array(
				'platform' => 'rakuten-kobo',
				'offers'   => array(
					array(
						'display_order' => 10,
						'external_id'   => 'a',
						'regular_url'   => 'https://example.test/a',
						'affiliate_url' => 'javascript:alert(1)',
					),
				),
			),
		);

		$method = new ReflectionMethod( ProductRepository::class, 'hasFallbackListing' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( null, $listings ) );
	}

	/**
	 * 通常 URL も不正なら出せる URL が 1 つも無い——フォールバックではない。
	 *
	 * この購入リンクはカード側でも表示対象から外れる（CardRenderer::visibleListings()）。
	 * 「素の商品 URL を出している」件数に混ぜると、出ていないものを数えることになる。
	 */
	public function test_通常URLも不正なら出せるURLが無くフォールバックではない(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		$listings = array(
			array(
				'platform' => 'rakuten-kobo',
				'offers'   => array(
					array(
						'display_order' => 10,
						'external_id'   => 'a',
						'regular_url'   => 'javascript:alert(1)',
						'affiliate_url' => '',
					),
				),
			),
		);

		$method = new ReflectionMethod( ProductRepository::class, 'hasFallbackListing' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( null, $listings ) );
	}

	/**
	 * v3 以前の flat な listing（offers キー無し）でも LegacyOffer::offersWithFallback()
	 * 経由でフォールバック判定の対象になる。これが抜けると、移行バッチが当該商品へ
	 * 到達するまでの窓でフォールバック中の商品がダッシュボードの件数から漏れる。
	 */
	public function test_アフィリURL欠落の判定はoffersが無いflatなlistingでも機能する(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		$listings = array(
			array(
				'platform'      => 'rakuten-kobo',
				'external_id'   => 'a',
				'regular_url'   => 'https://example.test/a',
				'affiliate_url' => '',
			),
		);

		$method = new ReflectionMethod( ProductRepository::class, 'hasFallbackListing' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( null, $listings ) );
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
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
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
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
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
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
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
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 99, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'dmm-books',
						'offers'   => array(
							array( 'price' => '¥660' ),
						),
					),
					array(
						'platform' => 'amazon-kindle',
						'offers'   => array(
							array( 'price' => '¥550' ),
						),
					),
				)
			);

		$repo   = new ProductRepository();
		$result = $repo->listingSummary( 99 );

		$this->assertSame( '¥660', $result['price'] );
		$this->assertSame( 'dmm-books', $result['platform'] );
	}

	public function test_listingSummary_returns_empty_when_no_listings(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 98, ProductPostType::META_LISTINGS, true )
			->andReturn( array() );

		$repo   = new ProductRepository();
		$result = $repo->listingSummary( 98 );

		$this->assertSame( '', $result['price'] );
		$this->assertSame( '', $result['platform'] );
	}

	/**
	 * 購入リンクを 1 件も持たない listing は代表にしない。
	 *
	 * 先頭の listing に platform があるだけで打ち切ると、その listing が購入リンクを
	 * 持たない（OfferSelector::select() が 0 件）ときに「価格は空・platform はその
	 * listing」を返し、価格を持つ後続の listing が管理画面の商品検索結果へ二度と
	 * 出てこない。選択結果が空の listing は飛ばして探し続ける。
	 */
	public function test_購入リンクを持たないlistingは価格サマリの代表にしない(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 96, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'dmm-books',
						'offers'   => array(),
					),
					array(
						'platform' => 'amazon-kindle',
						'offers'   => array(
							array( 'price' => '¥550' ),
						),
					),
				)
			);

		$repo   = new ProductRepository();
		$result = $repo->listingSummary( 96 );

		// 価格と platform は必ず同じ listing から来る（別々の listing を混ぜない）。
		$this->assertSame( '¥550', $result['price'] );
		$this->assertSame( 'amazon-kindle', $result['platform'] );
	}

	/**
	 * どの listing も購入リンクを持たないときは、先頭 listing の platform と空の価格を返す。
	 *
	 * 打ち切る相手がいない（価格を持つ listing が 1 件も無い）ので、隠してしまう価格も
	 * 無い。platform だけは「この商品がどのストア向けに登録されているか」という情報として
	 * 商品検索結果に出す価値があるため、従来どおり返す。
	 */
	public function test_どのlistingも購入リンクを持たなければ先頭platformと空価格を返す(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 95, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'dmm-books',
						'offers'   => array(),
					),
					array(
						'platform' => 'amazon-kindle',
						'offers'   => array(),
					),
				)
			);

		$repo   = new ProductRepository();
		$result = $repo->listingSummary( 95 );

		$this->assertSame( '', $result['price'] );
		$this->assertSame( 'dmm-books', $result['platform'] );
	}

	/**
	 * v3 以前の flat な listing（offers キー無し・取得結果フィールドが listing 直下）でも
	 * LegacyOffer::offersWithFallback() 経由で offers[0] 相当へ変換してから価格を選ぶ。
	 * これが抜けると、移行バッチが当該商品へ到達するまでの窓で管理画面の商品検索結果の
	 * 価格が空欄になる。
	 */
	public function test_listingSummary_offersが無いflatなlistingでも旧フィールドから価格を返す(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 97, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform'      => 'dmm-books',
						'external_id'   => 'X1',
						'regular_url'   => 'https://example.test/X1',
						'affiliate_url' => 'https://example.test/X1?aff=1',
						'price'         => '¥660',
					),
				)
			);

		$repo   = new ProductRepository();
		$result = $repo->listingSummary( 97 );

		$this->assertSame( '¥660', $result['price'] );
		$this->assertSame( 'dmm-books', $result['platform'] );
	}

	/**
	 * 先頭（表示順 10）が terminal でも、fallback_on_terminal 設定 OFF なら
	 * 先頭の購入リンクの価格を出す（OfferSelector::select の既定挙動）。
	 */
	public function test_価格サマリは選択された購入リンクから作る(): void {
		$listings = array(
			array(
				'platform' => 'rakuten-kobo',
				'offers'   => array(
					array(
						'display_order' => 10,
						'external_id'   => 'a',
						'regular_url'   => 'https://example.test/a',
						'price'         => '0',
					),
					array(
						'display_order' => 100,
						'external_id'   => 'b',
						'regular_url'   => 'https://example.test/b',
						'price'         => '660',
					),
				),
			),
		);
		$this->assertSame( '0', $this->summaryPriceFor( $listings ) );
	}

	/**
	 * listingSummary() の price だけを取り出すヘルパ。get_post_meta / get_option の
	 * 間接呼び出し（GeneralSettings::fallbackOnTerminal 既定 OFF）をまとめる。
	 *
	 * @param array<int, mixed> $listings
	 */
	private function summaryPriceFor( array $listings ): string {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 501, ProductPostType::META_LISTINGS, true )
			->andReturn( $listings );

		$repo = new ProductRepository();
		return $repo->listingSummary( 501 )['price'];
	}

	/**
	 * 非スカラーの価格は検索結果のサマリに出さない。
	 *
	 * `(string)` で直にキャストすると `'Array'` が管理画面の商品検索結果へそのまま
	 * 出る（ブロック挿入時の候補一覧＝人が見て選ぶ画面）。
	 */
	public function test_非スカラーの価格はサマリで空になる(): void {
		$listings = array(
			array(
				'platform' => 'rakuten-kobo',
				'offers'   => array(
					array(
						'display_order' => 100,
						'external_id'   => 'a',
						'regular_url'   => 'https://example.test/a',
						'price'         => array( '660' ),
					),
				),
			),
		);
		$this->assertSame( '', $this->summaryPriceFor( $listings ) );
	}

	/**
	 * 非スカラーの external_id はミラー meta に書かない。
	 *
	 * `(string)` で直にキャストすると `affilicard_extid_<platform>` に `'Array'` が
	 * **保存される**。findByExternalId() がそれを引き当てると、自動作成が無関係の商品を
	 * 「既存」とみなして別商品の listing を書き換えることになる。
	 */
	public function test_非スカラーのexternal_idはミラーに書かない(): void {
		// syncDerivedMeta() の listings 読み＋ミラー同期は ListingLock の中で行う。
		$this->mockLockWpdb( 1 );
		$repo = new ProductRepository();
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 43, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'dmm-books',
						'offers'   => array(
							array(
								'external_id' => array( 'X1' ),
								'regular_url' => 'https://example.test/X1',
							),
						),
					),
				)
			);
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 43 )
			->andReturn( array() );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 43, Mockery::any(), false )
			->andReturn( array() );
		WP_Mock::userFunction( 'add_post_meta' )->never();
		WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 43, ProductPostType::META_SCHEMA_VERSION, \Affilicard\Schema\SchemaVersion::CURRENT )->andReturn( true );

		$repo->syncDerivedMeta( 43 );

		$this->assertConditionsMet();
	}

	public function test_syncDerivedMeta_mirrors_external_ids_and_sets_schema_version(): void {
		// syncDerivedMeta() の listings 読み＋ミラー同期は ListingLock の中で行う。
		$this->mockLockWpdb( 1 );
		$repo = new ProductRepository();
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'dmm-books',
						'offers'   => array(
							array(
								'external_id' => 'X1',
								'regular_url' => 'https://example.test/X1',
							),
						),
					),
				)
			);
		// extid mirror の stale cleanup 用の全 meta 列挙（既存 stale なし）。
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42 )
			->andReturn( array() );
		// 「既に mirror 済みか」の判定(add_post_meta 前の重複防止チェック)。既存値なし。
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, Mockery::any(), false )
			->andReturn( array() );
		WP_Mock::userFunction( 'add_post_meta' )
			->once()->with( 42, 'affilicard_extid_dmm-books', 'X1', false )->andReturn( true );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 42, ProductPostType::META_SCHEMA_VERSION, \Affilicard\Schema\SchemaVersion::CURRENT )->andReturn( true );
		$repo->syncDerivedMeta( 42 );
		$this->assertConditionsMet();
	}

	/**
	 * updateListing() が発行する GET_LOCK/RELEASE_LOCK を捕捉する $wpdb モックを
	 * $GLOBALS に設定する（RateLimiterTest::mockWpdb と同じ流儀）。
	 *
	 * @param int                $getLockReturn GET_LOCK の戻り（1=取得成功／0=タイムアウト）。
	 * @param array<int, string> $capturedSql   prepare() に渡された SQL テンプレートを蓄積する参照。
	 */
	private function mockLockWpdb( int $getLockReturn, array &$capturedSql = array() ): void {
		$wpdb = Mockery::mock();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $query ) use ( &$capturedSql ) {
				$capturedSql[] = $query;
				return $query;
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturn( (string) $getLockReturn );
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * GET_LOCK / RELEASE_LOCK の発行を「時系列」へ記録する $wpdb モック。
	 *
	 * ロックを取っただけでは意味がなく、**読みと書きがロックの中に入っているか**が
	 * 検証したいことなので、SQL とメタ操作を同じ配列へ積んで順番を見る。
	 *
	 * @param int                $getLockReturn GET_LOCK の戻り（1=取得成功／0=タイムアウト）。
	 * @param array<int, string> $timeline      出来事を積む参照。
	 */
	private function mockLockWpdbTimeline( int $getLockReturn, array &$timeline ): void {
		$wpdb = Mockery::mock();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $query ) {
				return $query;
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( string $query ) use ( $getLockReturn, &$timeline ) {
				if ( false !== strpos( $query, 'GET_LOCK' ) ) {
					$timeline[] = 'GET_LOCK';
				}
				return (string) $getLockReturn;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( string $query ) use ( &$timeline ) {
				if ( false !== strpos( $query, 'RELEASE_LOCK' ) ) {
					$timeline[] = 'RELEASE_LOCK';
				}
				return 1;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * saveMeta() を走らせ、listings の読み書きとロックの発行順を返す。
	 *
	 * @param array<int, mixed> $stored 保存前の listings meta。
	 * @return array<int, string> 'GET_LOCK' / 'read:listings' / 'write:listings' / 'RELEASE_LOCK' の順列。
	 */
	private function saveMetaLockTimeline( int $getLockReturn, array $stored = array() ): array {
		$timeline = array();
		$this->mockLockWpdbTimeline( $getLockReturn, $timeline );
		$this->storedListings = $stored;

		WP_Mock::userFunction( 'wp_update_post' )->never();
		WP_Mock::userFunction( 'wp_insert_post' )->never();
		// extid ミラーの書き込みも同じ時系列へ積む（ロックの中に入っているかを見る）。
		WP_Mock::userFunction( 'add_post_meta' )
			->andReturnUsing(
				static function ( $post_id, $key, $value, $unique = false ) use ( &$timeline ) {
					$timeline[] = 'mirror:add';
					return true;
				}
			);
		WP_Mock::userFunction( 'delete_post_meta' )
			->andReturnUsing(
				static function ( $post_id, $key, $value = '' ) use ( &$timeline ) {
					$timeline[] = 'mirror:delete';
					return true;
				}
			);
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => is_string( $v ) ? trim( $v ) : $v );
		WP_Mock::userFunction( 'get_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key = '', $single = false ) use ( &$timeline ) {
					if ( ProductPostType::META_LISTINGS === $key ) {
						$timeline[] = 'read:listings';
						return $this->storedListings;
					}
					return array();
				}
			);
		$this->savedMetaKeys = array();
		WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$timeline ) {
					$this->savedMetaKeys[] = (string) $key;
					if ( ProductPostType::META_LISTINGS === $key ) {
						$timeline[] = 'write:listings';
					}
					return true;
				}
			);

		$this->lastRefusal = null;
		$repo              = new ProductRepository();
		try {
			$repo->saveMeta(
				5,
				array(
					'listings' => array(
						array(
							'platform' => 'rakuten-kobo',
							'offers'   => array(
								array(
									'external_id' => 'rk-1',
									'regular_url' => 'https://example.test/rk-1',
								),
							),
						),
					),
				)
			);
		} catch ( ProductLockUnavailable $e ) {
			$this->lastRefusal = $e;
		}

		return $timeline;
	}

	/**
	 * saveMeta() の listings 読み書きはロックの中で行う。
	 *
	 * saveMeta() も META_LISTINGS の read-modify-write である（保存前の listings を
	 * 読んで OfferStatusReset を掛けてから書き戻す）。ロックの外でやると、読みと
	 * 書きのあいだに入った価格更新（updateListingOffer）の書き込みを丸ごと消す。
	 */
	public function test_saveMetaはlistingsの読み書きをロックの中で行う(): void {
		$timeline = $this->saveMetaLockTimeline( 1 );

		// 2 つ目の read は extid ミラー同期の読み直し（ミラーは書こうとした値では
		// なく実際に入っている値から作る）。**これも RELEASE_LOCK の前にある**——
		// ミラーは META_LISTINGS の写しなので、外に出すと別の保存が挟まったとき
		// 古い写しが後着で勝ち、findByExternalId() が実在しない商品を引く。
		$this->assertSame(
			array( 'GET_LOCK', 'read:listings', 'write:listings', 'read:listings', 'RELEASE_LOCK' ),
			$timeline
		);
	}

	/**
	 * extid ミラーの書き込み自体もロックの中で行う。
	 *
	 * 読み直しだけをロックへ入れても足りない。ミラーの更新は「今回の集合に無い値を
	 * 個別に消してから、足りない値を足す」という複数クエリの操作なので、外に出すと
	 * 2 つの保存の削除と追加が交互に並び、どちらの listings とも一致しないミラーが
	 * 残る。ミラーは findByExternalId() の索引そのもので、狂うと自動作成が既存商品を
	 * 見落として重複を作るか、消えた ID で既存商品を引いてしまう。
	 */
	public function test_saveMetaのミラー書き込みもロックの中で行う(): void {
		$timeline = $this->saveMetaLockTimeline(
			1,
			array(
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array( array( 'external_id' => 'rk-1' ) ),
				),
			)
		);

		$this->assertSame(
			array( 'GET_LOCK', 'read:listings', 'write:listings', 'read:listings', 'mirror:add', 'RELEASE_LOCK' ),
			$timeline
		);
	}

	/**
	 * syncDerivedMeta() を走らせ、listings の読みとミラー同期・ロックの発行順を返す。
	 *
	 * @param array<int, mixed> $stored 保存されている listings meta。
	 * @return array<int, string> 'GET_LOCK' / 'read:listings' / 'mirror:add' / 'RELEASE_LOCK' の順列。
	 */
	private function syncDerivedMetaLockTimeline( int $getLockReturn, array $stored ): array {
		$timeline = array();
		$this->mockLockWpdbTimeline( $getLockReturn, $timeline );
		$this->storedListings = $stored;

		WP_Mock::userFunction( 'add_post_meta' )
			->andReturnUsing(
				static function ( $post_id, $key, $value, $unique = false ) use ( &$timeline ) {
					$timeline[] = 'mirror:add';
					return true;
				}
			);
		WP_Mock::userFunction( 'delete_post_meta' )
			->andReturnUsing(
				static function ( $post_id, $key, $value = '' ) use ( &$timeline ) {
					$timeline[] = 'mirror:delete';
					return true;
				}
			);
		WP_Mock::userFunction( 'get_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key = '', $single = false ) use ( &$timeline ) {
					if ( ProductPostType::META_LISTINGS === $key ) {
						$timeline[] = 'read:listings';
						return $this->storedListings;
					}
					return array();
				}
			);
		WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );

		( new ProductRepository() )->syncDerivedMeta( 5 );

		return $timeline;
	}

	/**
	 * syncDerivedMeta() も listings の読みとミラー同期をロックの中で行う。
	 *
	 * ブロックエディタのサイドバー保存（コアの wp/v2 meta 経路）はこの派生 meta 同期で
	 * ミラーを作り直す。saveMeta() 側だけを直列化しても、こちらが外に居ると
	 * 「REST の保存が読んだ listings」と「別経路が書き終えた listings」が入れ替わり、
	 * ミラーが META_LISTINGS と食い違う。
	 */
	public function test_syncDerivedMetaはlistingsの読みとミラー同期をロックの中で行う(): void {
		$timeline = $this->syncDerivedMetaLockTimeline(
			1,
			array(
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array( array( 'external_id' => 'rk-1' ) ),
				),
			)
		);

		$this->assertSame(
			array( 'GET_LOCK', 'read:listings', 'mirror:add', 'RELEASE_LOCK' ),
			$timeline
		);
	}

	/**
	 * ロックを取れなければ syncDerivedMeta() はミラーを作り直さない。
	 *
	 * 押し通しても作られるのは「古い listings から組み直したミラー」で、狂い方は放置と
	 * 同じうえに、先着が正しく作ったミラーを巻き戻す（lost update）。読みにすら行かず
	 * 抜けることを、時系列で固定する。
	 */
	public function test_syncDerivedMetaはロックを取れなければミラーを作り直さない(): void {
		$timeline = $this->syncDerivedMetaLockTimeline(
			0,
			array(
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array( array( 'external_id' => 'rk-1' ) ),
				),
			)
		);

		// 取れていないロックを返しに行かない（RELEASE_LOCK が無い）。listings の読みも
		// ミラーの書き込みも起きない。
		$this->assertSame( array( 'GET_LOCK' ), $timeline );
	}

	/**
	 * ロックを取れなければ syncDerivedMeta() は false を返す（呼び出し側が再投入する）。
	 *
	 * 「やらなかった」が呼び出し側へ届かなければ、{@see \Affilicard\Repository\DerivedMetaSync}
	 * は再試行を積めず、ミラーが古いまま放置される。戻り値そのものが契約なので、
	 * 時系列とは別に固定する。
	 */
	public function test_syncDerivedMetaはロックを取れなければfalseを返す(): void {
		$this->mockLockWpdb( 0 );
		$this->mockSyncDerivedMetaWpFunctions();

		$this->assertFalse( ( new ProductRepository() )->syncDerivedMeta( 5 ) );
	}

	/**
	 * ロックを取れたら syncDerivedMeta() は true を返す（再投入は要らない）。
	 */
	public function test_syncDerivedMetaはロックを取れればtrueを返す(): void {
		$this->mockLockWpdb( 1 );
		$this->mockSyncDerivedMetaWpFunctions();

		$this->assertTrue( ( new ProductRepository() )->syncDerivedMeta( 5 ) );
	}

	/**
	 * ミラーを見送っても schema_version は刻む。
	 *
	 * 刻印が指すのは listings の形式であり、それを書いたのはコアの保存（この関数の手前）
	 * で既に終わっている。見送ったのは写しの作り直しだけなので、刻印まで巻き添えに
	 * すると「移行済みの商品が未移行に見える」側へ倒れる。
	 */
	public function test_syncDerivedMetaはミラーを見送ってもschema_versionを刻む(): void {
		$this->mockLockWpdb( 0 );

		$stamped = array();
		WP_Mock::userFunction( 'get_post_meta' )->andReturn( array() );
		WP_Mock::userFunction( 'add_post_meta' )->andReturn( true );
		WP_Mock::userFunction( 'delete_post_meta' )->andReturn( true );
		WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing(
				static function ( $post_id, $key, $value ) use ( &$stamped ) {
					$stamped[ (string) $key ] = $value;
					return true;
				}
			);

		( new ProductRepository() )->syncDerivedMeta( 5 );

		$this->assertSame(
			SchemaVersion::CURRENT,
			$stamped[ ProductPostType::META_SCHEMA_VERSION ] ?? null
		);
	}

	/**
	 * syncDerivedMeta() の戻り値だけを見るテスト用に、周辺の WP 関数を最小限モックする。
	 */
	private function mockSyncDerivedMetaWpFunctions(): void {
		$this->storedListings = array(
			array(
				'platform' => 'rakuten-kobo',
				'offers'   => array( array( 'external_id' => 'rk-1' ) ),
			),
		);
		WP_Mock::userFunction( 'get_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key = '', $single = false ) {
					return ProductPostType::META_LISTINGS === $key ? $this->storedListings : array();
				}
			);
		WP_Mock::userFunction( 'add_post_meta' )->andReturn( true );
		WP_Mock::userFunction( 'delete_post_meta' )->andReturn( true );
		WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );
	}

	/**
	 * ロックを取れなければ saveMeta() は listings を書かず、例外で報告する。
	 *
	 * 以前は best-effort で書いていた（「運用者の編集を無言で消さない」ため）。だが
	 * 押し通すと、古い読みを書き戻して先着の書き込みを丸ごと消す（lost update）——
	 * 無言で消える編集より広く、しかも消えたことが誰にも分からない。書かない・かつ
	 * 捨てない、という第三の道を取る。
	 *
	 * **例外のメッセージまで固定する。** 素の型だけだと、モック不足で出た
	 * `Mockery\Exception\NoMatchingExpectationException`（これも RuntimeException を
	 * 継承する）で通ってしまい、テストが合否を区別できなくなる。
	 */
	public function test_saveMetaはロックを取れなければlistingsを書かず例外を投げる(): void {
		$timeline = $this->saveMetaLockTimeline( 0 );

		$this->assertInstanceOf( ProductLockUnavailable::class, $this->lastRefusal );
		$this->assertSame(
			'affilicard: 商品 5 の listing ロックを取得できず、listings を書き込まなかった。',
			$this->lastRefusal->getMessage()
		);
		$this->assertSame( 5, $this->lastRefusal->postId() );

		// listings は読みにも行かず、書きもせず、ミラーも触らない。取れていないロックを
		// 返しにも行かない（RELEASE_LOCK が無い）。
		$this->assertSame( array( 'GET_LOCK' ), $timeline );
	}

	/**
	 * ロックを取れなくても listings 以外のメタは保存される。
	 *
	 * 見送るのは listings だけである。他は別キーの冪等な上書きで、書いておけば運用者の
	 * やり直しが素直に通る。「listings を最後に書く」という順序がこの性質を作っているので、
	 * 順序ごと固定する。
	 */
	public function test_saveMetaはロックを取れなくてもlistings以外のメタは保存する(): void {
		$this->saveMetaLockTimeline( 0 );

		$this->assertInstanceOf( ProductLockUnavailable::class, $this->lastRefusal );
		$this->assertContains( ProductPostType::META_PRODUCT_TYPE, $this->savedMetaKeys );
		$this->assertContains( ProductPostType::META_STOCK_STATUS, $this->savedMetaKeys );
		$this->assertContains( ProductPostType::META_EXTRAS, $this->savedMetaKeys );
		$this->assertContains( ProductPostType::META_SCHEMA_VERSION, $this->savedMetaKeys );
		$this->assertContains( ProductPostType::META_RELEASE_DATE, $this->savedMetaKeys );
		$this->assertContains( ProductPostType::META_MASK_BLUR, $this->savedMetaKeys );
		$this->assertContains( ProductPostType::META_MASK_R18, $this->savedMetaKeys );
		$this->assertContains( ProductPostType::META_MASK_LABEL, $this->savedMetaKeys );
		$this->assertNotContains( ProductPostType::META_LISTINGS, $this->savedMetaKeys );
	}

	/**
	 * ロックを取れていれば saveMeta() は例外を投げない（正常系を巻き添えにしない）。
	 */
	public function test_saveMetaはロックを取れれば例外を投げない(): void {
		$this->saveMetaLockTimeline( 1 );

		$this->assertNull( $this->lastRefusal );
	}

	public function test_updateListing_対象platformのみ差し替え他listingを保持して保存する(): void {
		$captured = array();
		$this->mockLockWpdb( 1, $captured );

		$existing = array(
			array(
				'platform'    => 'rakuten-kobo',
				'external_id' => 'r-1',
				'price'       => '500',
			),
			array(
				'platform'    => 'dmm-books',
				'external_id' => 'd-1',
				'price'       => '900',
			),
		);
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ProductPostType::META_LISTINGS, true )
			->andReturn( $existing );

		$saved = null;
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$saved ) {
					$this->assertSame( 42, $post_id );
					$this->assertSame( ProductPostType::META_LISTINGS, $key );
					$saved = $value;
					return true;
				}
			);

		$repo = new ProductRepository();
		$ok   = $repo->updateListing(
			42,
			'rakuten-kobo',
			array(
				'platform'    => 'rakuten-kobo',
				'external_id' => 'r-1',
				'price'       => '693',
			)
		);

		$this->assertTrue( $ok );
		// 対象 platform（rakuten）だけ price が更新され、他 platform（dmm）は不変。
		$this->assertSame( '693', $saved[0]['price'] );
		$this->assertSame( 'dmm-books', $saved[1]['platform'] );
		$this->assertSame( '900', $saved[1]['price'] );
		// GET_LOCK と RELEASE_LOCK が発行される。
		$this->assertStringContainsString( 'GET_LOCK', $captured[0] );
		$this->assertStringContainsString( 'RELEASE_LOCK', $captured[1] );
		$this->assertConditionsMet();
	}

	public function test_updateListing_一致platformが無ければfalseで保存しない(): void {
		$this->mockLockWpdb( 1 );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 7, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform'    => 'dmm-books',
						'external_id' => 'd-1',
					),
				)
			);
		WP_Mock::userFunction( 'update_post_meta' )->never();

		$repo = new ProductRepository();
		$ok   = $repo->updateListing( 7, 'rakuten-kobo', array( 'platform' => 'rakuten-kobo' ) );

		$this->assertFalse( $ok );
		$this->assertConditionsMet();
	}

	/**
	 * GET_LOCK がタイムアウト（0）でも RMW は best-effort で続行し保存する
	 * （fetch は既に成功済みで、ロック不能を理由に更新を捨てる方が有害）。
	 */
	public function test_updateListing_ロック取得失敗でもRMWを続行して保存する(): void {
		$this->mockLockWpdb( 0 );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 9, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'rakuten-kobo',
						'price'    => '500',
					),
				)
			);
		$saved = null;
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$saved ) {
					$saved = $value;
					return true;
				}
			);

		$repo = new ProductRepository();
		$ok   = $repo->updateListing(
			9,
			'rakuten-kobo',
			array(
				'platform' => 'rakuten-kobo',
				'price'    => '693',
			)
		);

		$this->assertTrue( $ok );
		$this->assertSame( '693', $saved[0]['price'] );
		$this->assertConditionsMet();
	}

	/**
	 * $listingFields に offers[] を含めた場合、対象 listing の置き換え先として
	 * そのまま保存される（シグネチャは不変・offers はほかのフィールドと同様に扱う）。
	 */
	public function test_updateListing_offersを含むlistingFieldsをそのまま保存する(): void {
		$this->mockLockWpdb( 1 );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'rakuten-kobo',
						'offers'   => array(
							array(
								'external_id' => 'r-1',
								'price'       => '500',
							),
						),
					),
				)
			);

		$saved = null;
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$saved ) {
					$saved = $value;
					return true;
				}
			);

		$new_offers = array(
			array(
				'display_order' => 10,
				'external_id'   => 'r-1',
				'price'         => '693',
			),
			array(
				'display_order' => 100,
				'external_id'   => 'r-2',
				'price'         => '999',
			),
		);

		$repo = new ProductRepository();
		$ok   = $repo->updateListing(
			42,
			'rakuten-kobo',
			array(
				'platform' => 'rakuten-kobo',
				'offers'   => $new_offers,
			)
		);

		$this->assertTrue( $ok );
		$this->assertSame( $new_offers, $saved[0]['offers'] );
		$this->assertConditionsMet();
	}

	/**
	 * A（PR レビュー Round2）: fetch 中に管理画面が別の購入リンクを追加していても、
	 * 価格更新はその編集を巻き戻さない。
	 *
	 * updateListing() は呼び出し側が持っている listing 全体で置き換えるため、
	 * ロックの中で読み直しても「書き込む値が fetch 前の写し」であり、追加・削除・
	 * 並べ替えが消える。updateListingOffer() は今回 fetch した 1 件だけを受け取り、
	 * 読み直した listing へ差し込むので、並行編集が残る。
	 */
	public function test_updateListingOffer_fetch中に追加された購入リンクを保持して1件だけ差し替える(): void {
		$captured = array();
		$this->mockLockWpdb( 1, $captured );

		// ロックの中で読み直した「今」の listing。r-2 は fetch のあいだに管理者が追加した。
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'rakuten-kobo',
						'offers'   => array(
							array(
								'display_order' => 10,
								'external_id'   => 'r-1',
								'price'         => '500',
							),
							array(
								'display_order' => 20,
								'external_id'   => 'r-2',
								'price'         => '800',
							),
						),
					),
					array(
						'platform' => 'dmm-books',
						'offers'   => array( array( 'external_id' => 'd-1' ) ),
					),
				)
			);

		$saved = null;
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$saved ) {
					$this->assertSame( 42, $post_id );
					$this->assertSame( ProductPostType::META_LISTINGS, $key );
					$saved = $value;
					return true;
				}
			);

		$repo = new ProductRepository();
		$ok   = $repo->updateListingOffer(
			42,
			'rakuten-kobo',
			array(
				'display_order' => 10,
				'external_id'   => 'r-1',
				'price'         => '693',
			),
			'external_id:r-1'
		);

		$this->assertTrue( $ok );
		$this->assertCount( 2, $saved[0]['offers'] );
		$this->assertSame( '693', $saved[0]['offers'][0]['price'] );
		// 追加された購入リンクは残る（旧経路ではここが消えていた）。
		$this->assertSame( 'r-2', $saved[0]['offers'][1]['external_id'] );
		$this->assertSame( '800', $saved[0]['offers'][1]['price'] );
		// 別 platform も不変。
		$this->assertSame( 'dmm-books', $saved[1]['platform'] );
		$this->assertStringContainsString( 'GET_LOCK', $captured[0] );
		$this->assertStringContainsString( 'RELEASE_LOCK', $captured[1] );
		$this->assertConditionsMet();
	}

	/**
	 * 突き合わせは配列の添字ではなく身元（external_id）で行う。offers は複数の
	 * 書き込み元から届き、位置は安定しないため、添字で書き戻すと別の購入リンクを
	 * 上書きする。
	 */
	public function test_updateListingOffer_識別子で突き合わせ配列の位置に依存しない(): void {
		$this->mockLockWpdb( 1 );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'rakuten-kobo',
						'offers'   => array(
							array(
								'display_order' => 100,
								'external_id'   => 'normal',
								'price'         => '660',
							),
							array(
								'display_order' => 10,
								'external_id'   => 'sale',
								'price'         => '',
							),
						),
					),
				)
			);

		$saved = null;
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$saved ) {
					$saved = $value;
					return true;
				}
			);

		$repo = new ProductRepository();
		$ok   = $repo->updateListingOffer(
			42,
			'rakuten-kobo',
			array(
				'display_order' => 10,
				'external_id'   => 'sale',
				'price'         => '0',
			),
			'external_id:sale'
		);

		$this->assertTrue( $ok );
		// 配列の 2 番目にいる 'sale' が更新され、1 番目の 'normal' は無傷。
		$this->assertSame( 'normal', $saved[0]['offers'][0]['external_id'] );
		$this->assertSame( '660', $saved[0]['offers'][0]['price'] );
		$this->assertSame( 'sale', $saved[0]['offers'][1]['external_id'] );
		$this->assertSame( '0', $saved[0]['offers'][1]['price'] );
		$this->assertConditionsMet();
	}

	/**
	 * fetch 中の並べ替え・編集を、取得結果の書き戻しで巻き戻さない。
	 *
	 * 渡すのは「取得が変えたフィールドだけ」の差分で、ロック内で読み直した最新の
	 * 購入リンクへマージする。offer 全体を置き換える実装だと、fetch 前のスナップショットに
	 * 入っていた古い display_order や search_key が復活してしまう。
	 */
	public function test_updateListingOffer_fetch中の並べ替えと編集を巻き戻さない(): void {
		$this->mockLockWpdb( 1 );

		// ロック内で読み直した「最新」の状態。管理者が並べ替え、search_key も直した後。
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'rakuten-kobo',
						'offers'   => array(
							array(
								'external_id'   => 'r-1',
								'display_order' => 50,
								'search_key'    => '管理者が直した検索キー',
								'price'         => '500',
							),
						),
					),
				)
			);

		$saved = null;
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$saved ) {
					$saved = $value;
					return true;
				}
			);

		$repo = new ProductRepository();
		// 取得が変えたのは価格と取得状態だけ。display_order / search_key は送らない。
		$ok = $repo->updateListingOffer(
			42,
			'rakuten-kobo',
			array(
				'price'        => '693',
				'fetch_status' => '',
			),
			'external_id:r-1'
		);

		$this->assertTrue( $ok );
		$offer = $saved[0]['offers'][0];
		$this->assertSame( '693', $offer['price'], '取得した価格は反映される' );
		$this->assertSame( 50, $offer['display_order'], '並べ替えが巻き戻ってはいけない' );
		$this->assertSame( '管理者が直した検索キー', $offer['search_key'], '編集が巻き戻ってはいけない' );
		$this->assertConditionsMet();
	}

	/**
	 * 取得で regular_url が変わっても、取得前の identity で正しい購入リンクへ書き戻す。
	 *
	 * external_id を持たない購入リンクでは identity が regular_url そのものなので、
	 * 取得結果が別 URL を返すと「更新後の offer から identity を求める」実装は
	 * マージ先を見失う。false が返って価格が永久に入らなくなるため、マージ先は
	 * 呼び出し側が取得前に確定させて渡す。
	 */
	public function test_updateListingOffer_取得で通常URLが変わっても取得前の識別子で書き戻す(): void {
		$this->mockLockWpdb( 1 );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'rakuten-kobo',
						'offers'   => array(
							array(
								'external_id' => '',
								'regular_url' => 'https://example.test/old',
								'price'       => '',
							),
						),
					),
				)
			);

		$saved = null;
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$saved ) {
					$saved = $value;
					return true;
				}
			);

		$repo = new ProductRepository();
		$ok   = $repo->updateListingOffer(
			42,
			'rakuten-kobo',
			array(
				'external_id' => '',
				// 取得結果が返した新しい URL。identity はこちらではなく取得前の値。
				'regular_url' => 'https://example.test/new',
				'price'       => '693',
			),
			'regular_url:https://example.test/old'
		);

		$this->assertTrue( $ok );
		$this->assertCount( 1, $saved[0]['offers'], '購入リンクが重複してはいけない' );
		$this->assertSame( 'https://example.test/new', $saved[0]['offers'][0]['regular_url'] );
		$this->assertSame( '693', $saved[0]['offers'][0]['price'] );
		$this->assertConditionsMet();
	}

	/** external_id を持たない購入リンクは通常 URL（regular_url）で突き合わせる。 */
	public function test_updateListingOffer_external_idが空なら通常URLで突き合わせる(): void {
		$this->mockLockWpdb( 1 );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'rakuten-kobo',
						'offers'   => array(
							array(
								'external_id' => '',
								'regular_url' => 'https://example.test/manual',
								'price'       => '',
							),
						),
					),
				)
			);

		$saved = null;
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$saved ) {
					$saved = $value;
					return true;
				}
			);

		$repo = new ProductRepository();
		$ok   = $repo->updateListingOffer(
			42,
			'rakuten-kobo',
			array(
				'external_id'  => '',
				'regular_url'  => 'https://example.test/manual',
				'fetch_status' => 'unsupported',
			),
			'regular_url:https://example.test/manual'
		);

		$this->assertTrue( $ok );
		$this->assertCount( 1, $saved[0]['offers'] );
		$this->assertSame( 'unsupported', $saved[0]['offers'][0]['fetch_status'] );
		$this->assertConditionsMet();
	}

	/**
	 * fetch のあいだに管理者が削除した購入リンクは、**復活させない**。
	 *
	 * 末尾に追加し直すと削除操作が無言で取り消される。何も保存せず false を返し、
	 * 呼び出し側（ListingRefresher）に一時失敗として扱わせる——リトライでは
	 * OfferSelector が「そのとき現存する」購入リンクを選び直すため自然に解消する。
	 */
	public function test_updateListingOffer_fetch中に削除された購入リンクは追加せずfalseを返す(): void {
		$this->mockLockWpdb( 1 );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'rakuten-kobo',
						'offers'   => array(
							array(
								'external_id' => 'r-2',
								'price'       => '800',
							),
						),
					),
				)
			);
		WP_Mock::userFunction( 'update_post_meta' )->never();

		$repo = new ProductRepository();
		$ok   = $repo->updateListingOffer(
			42,
			'rakuten-kobo',
			array(
				'external_id' => 'r-1',
				'price'       => '693',
			),
			'external_id:r-1'
		);

		$this->assertFalse( $ok );
		$this->assertConditionsMet();
	}

	/**
	 * update_post_meta() が失敗したら false を返す（成功として報告しない）。
	 *
	 * 握り潰すと、取得した価格が保存されていないのに ListingRefresher は成功として
	 * 完了し、再試行もしない——サイレントなデータロスになる。
	 */
	public function test_updateListingOffer_meta書き込みに失敗したらfalseを返す(): void {
		$this->mockLockWpdb( 1 );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'rakuten-kobo',
						'offers'   => array(
							array(
								'external_id' => 'r-1',
								'price'       => '500',
							),
						),
					),
				)
			);
		// 書き込み失敗。値は変わっている（500 → 693）ので「変わらなかった」ではない。
		WP_Mock::userFunction( 'update_post_meta' )->once()->andReturn( false );

		$repo = new ProductRepository();
		$ok   = $repo->updateListingOffer(
			42,
			'rakuten-kobo',
			array(
				'external_id' => 'r-1',
				'price'       => '693',
			),
			'external_id:r-1'
		);

		$this->assertFalse( $ok );
		$this->assertConditionsMet();
	}

	/**
	 * 値が変わらないときは書かずに true を返す。
	 *
	 * update_post_meta() は「既存値と同じ」でも false を返すため、戻り値をそのまま
	 * 成否に使うと、取得結果が前回と同じだっただけの正常系が失敗として扱われ、
	 * リトライを繰り返して最後は failed になる。
	 */
	public function test_updateListingOffer_値が変わらなければ書かずにtrueを返す(): void {
		$this->mockLockWpdb( 1 );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'rakuten-kobo',
						'offers'   => array(
							array(
								'external_id' => 'r-1',
								'price'       => '693',
							),
						),
					),
				)
			);
		WP_Mock::userFunction( 'update_post_meta' )->never();

		$repo = new ProductRepository();
		$ok   = $repo->updateListingOffer(
			42,
			'rakuten-kobo',
			array(
				'external_id' => 'r-1',
				'price'       => '693',
			),
			'external_id:r-1'
		);

		$this->assertTrue( $ok );
		$this->assertConditionsMet();
	}

	/**
	 * ロックを取れない窓は、まさに誰かが同じ meta を書いている窓である。そこで
	 * read-modify-write に入ると、並行している管理画面の保存を丸ごと巻き戻す。
	 * false は ListingRefresher が一時失敗として扱い再投入するため、取得した価格は
	 * 次の試行で保存し直される（updateListing() の best-effort とは逆の判断）。
	 */
	public function test_updateListingOffer_ロックを取れなければ何も書かずfalseを返す(): void {
		$captured = array();
		$this->mockLockWpdb( 0, $captured );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'rakuten-kobo',
						'offers'   => array(
							array(
								'external_id' => 'r-1',
								'price'       => '500',
							),
						),
					),
				)
			);
		WP_Mock::userFunction( 'update_post_meta' )->never();

		$repo = new ProductRepository();
		$ok   = $repo->updateListingOffer(
			42,
			'rakuten-kobo',
			array(
				'external_id' => 'r-1',
				'price'       => '693',
			),
			'external_id:r-1'
		);

		$this->assertFalse( $ok );
		// 取れていないロックを返しに行かない（GET_LOCK だけで終わる）。
		$this->assertCount( 1, $captured );
		$this->assertStringContainsString( 'GET_LOCK', $captured[0] );
		$this->assertConditionsMet();
	}

	/** 該当 platform の listing 自体が無ければ false（保存しない）。 */
	public function test_updateListingOffer_一致platformが無ければfalseで保存しない(): void {
		$this->mockLockWpdb( 1 );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 7, ProductPostType::META_LISTINGS, true )
			->andReturn( array( array( 'platform' => 'dmm-books' ) ) );
		WP_Mock::userFunction( 'update_post_meta' )->never();

		$repo = new ProductRepository();
		$ok   = $repo->updateListingOffer( 7, 'rakuten-kobo', array( 'external_id' => 'r-1' ), 'external_id:r-1' );

		$this->assertFalse( $ok );
		$this->assertConditionsMet();
	}

	/**
	 * 移行前の flat な listing（offers を持たない v3 以前の形）でも、読み取り側と同じ
	 * 写像（LegacyOffer::offersWithFallback）を通してから突き合わせる。ここで offers を
	 * 直接読むと、移行バッチが到達していない商品の価格更新が永久に保存できない。
	 */
	public function test_updateListingOffer_移行前のflat_listingでもoffers化して差し替える(): void {
		$this->mockLockWpdb( 1 );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 31, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform'    => 'rakuten-kobo',
						'external_id' => 'flat-1',
						'regular_url' => 'https://example.test/flat',
						'price'       => '700',
					),
				)
			);

		$saved = null;
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$saved ) {
					$saved = $value;
					return true;
				}
			);

		$repo = new ProductRepository();
		$ok   = $repo->updateListingOffer(
			31,
			'rakuten-kobo',
			array(
				'external_id' => 'flat-1',
				'regular_url' => 'https://example.test/flat',
				'price'       => '550',
			),
			'external_id:flat-1'
		);

		$this->assertTrue( $ok );
		$this->assertCount( 1, $saved[0]['offers'] );
		$this->assertSame( '550', $saved[0]['offers'][0]['price'] );
		$this->assertConditionsMet();
	}
}
