<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Repository;

use Affilicard\PostType\ProductPostType;
use Affilicard\Repository\ProductRepository;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * external_id ミラー（`affilicard_extid_<platform>` meta）の複数値化を検証する。
 *
 * 1 listing が複数の購入リンク（offers）を持てるようになったため、同一 platform の
 * 複数 external_id をすべてミラーできること・今回の集合に無い値だけを個別に削除
 * できることを固定する（Task 7）。
 */
final class ExternalIdMirrorTest extends TestCase {

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
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * syncDerivedMeta() を実行し、指定 platform の meta キーへ add_post_meta で
	 * 新規に書き込まれた値だけを収集して返す。
	 *
	 * @param array<int, mixed> $listings
	 * @return array<int, string>
	 */
	private function mirroredValuesFor( array $listings, string $platform ): array {
		$post_id  = 900;
		$meta_key = ProductPostType::externalIdMetaKey( $platform );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ProductPostType::META_LISTINGS, true )
			->andReturn( $listings );
		// stale cleanup 用の全 meta 列挙（既存 mirror なし）。
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id )
			->andReturn( array() );
		// 「既に mirror 済みか」の判定（add_post_meta 前の重複防止チェック）。既存値なし。
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, Mockery::any(), false )
			->andReturn( array() );
		WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );

		$added = array();
		WP_Mock::userFunction( 'add_post_meta' )
			->andReturnUsing(
				function ( $added_post_id, $key, $value, $unique ) use ( &$added, $meta_key, $post_id ) {
					// $unique を検証しないと、実装が add_post_meta(..., true) に退行しても
					// このモックは全値を記録してしまい、テストが通ってしまう。実 WP では
					// unique=true だと 2 件目以降が保存されず mirror が 1 値に潰れる。
					$this->assertSame( $post_id, $added_post_id );
					$this->assertFalse( $unique, '複数 external_id の mirror には unique=false が必要' );
					if ( $meta_key === $key ) {
						$added[] = (string) $value;
					}
					return true;
				}
			);

		( new ProductRepository() )->syncDerivedMeta( $post_id );

		return $added;
	}

	/**
	 * 既存 mirror 値 $before を持つ状態から listing を $afterExternalIds に差し替えて
	 * syncDerivedMeta() を実行し、削除されなかった（＝残った）値を返す。
	 *
	 * @param array<int, string> $before           既存の mirror 値（同一 platform）。
	 * @param array<int, string> $afterExternalIds 今回の listing に残す external_id。
	 * @return array<int, string>
	 */
	private function mirroredValuesAfterReplacing( array $before, array $afterExternalIds, string $platform ): array {
		$post_id  = 901;
		$meta_key = ProductPostType::externalIdMetaKey( $platform );

		WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => $platform,
						'offers'   => array_map(
							static function ( $external_id ) {
								return array(
									'external_id' => $external_id,
									'regular_url' => 'https://example.test/' . $external_id,
								);
							},
							$afterExternalIds
						),
					),
				)
			);
		// stale cleanup 用の全 meta 列挙（同一 platform の既存 mirror 値 = $before）。
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id )
			->andReturn( array( $meta_key => $before ) );
		// 「既に mirror 済みか」の判定（add_post_meta 前の重複防止チェック）。
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, $meta_key, false )
			->andReturn( $before );
		WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );
		WP_Mock::userFunction( 'add_post_meta' )->andReturn( true );

		$deleted = array();
		WP_Mock::userFunction( 'delete_post_meta' )
			->andReturnUsing(
				function ( $deleted_post_id, $key, $value = null ) use ( &$deleted, $meta_key ) {
					if ( $meta_key === $key ) {
						$deleted[] = (string) $value;
					}
					return true;
				}
			);

		( new ProductRepository() )->syncDerivedMeta( $post_id );

		return array_values( array_diff( $before, $deleted ) );
	}

	public function test_全ての購入リンクのexternal_idがミラーされる(): void {
		// 1 platform に複数 offer があると、v3 の実装は 1 つしかミラーしない。
		// その結果 findByExternalId が後続 offer を引けず、自動作成が重複商品を作る。
		$listings = array(
			array(
				'platform' => 'rakuten-kobo',
				'offers'   => array(
					array(
						'external_id' => 'sale',
						'regular_url' => 'https://example.test/sale',
					),
					array(
						'external_id' => 'normal',
						'regular_url' => 'https://example.test/normal',
					),
				),
			),
		);

		$mirrored = $this->mirroredValuesFor( $listings, 'rakuten-kobo' );
		$this->assertEqualsCanonicalizing( array( 'sale', 'normal' ), $mirrored );
	}

	public function test_今回の集合に無い値だけが削除される(): void {
		// キー単位の削除だと、同じ platform の生きている値まで消える。
		$before = array( 'sale', 'normal' );
		$after  = $this->mirroredValuesAfterReplacing( $before, array( 'normal' ), 'rakuten-kobo' );
		$this->assertSame( array( 'normal' ), $after );
	}

	public function test_external_idが空の購入リンクはミラーしない(): void {
		$listings = array(
			array(
				'platform' => 'rakuten-kobo',
				'offers'   => array(
					array(
						'external_id' => '',
						'regular_url' => 'https://example.test/manual',
					),
				),
			),
		);
		$this->assertSame( array(), $this->mirroredValuesFor( $listings, 'rakuten-kobo' ) );
	}

	/**
	 * ProductAutoCreator::buildProductData() は sanitizeListings()（offers[] への正規化）を
	 * 経由せず、listing 直下に flat な external_id を持つ listing をそのまま save() に渡す。
	 * この経路でミラーが働かないと findByExternalId が引けず、自動作成が重複商品を作る。
	 */
	public function test_offersが無いflat形状のlistingでもexternal_idをミラーする(): void {
		$listings = array(
			array(
				'platform'    => 'dmm-books',
				'external_id' => 'flat-ext-1',
			),
		);
		$this->assertSame( array( 'flat-ext-1' ), $this->mirroredValuesFor( $listings, 'dmm-books' ) );
	}

	/**
	 * 空の offers は「購入リンクが無い」であって旧形式ではない。
	 *
	 * 空を旧形式とみなして listing 直下の external_id を拾い直すと、利用者が購入リンクを
	 * 全て削除しても findByExternalId() が商品を見つけてしまい、ProductAutoCreator が
	 * 新しい商品を作れなくなる。旧形式のフォールバックは offers キー自体が無いときだけ。
	 */
	public function test_offersが空配列ならlisting直下のexternal_idをミラーしない(): void {
		$listings = array(
			array(
				'platform'    => 'dmm-books',
				'offers'      => array(),
				// 移行前の値が listing 直下に残っている状態。
				'external_id' => 'flat-ext-1',
			),
		);
		$this->assertSame( array(), $this->mirroredValuesFor( $listings, 'dmm-books' ) );
	}

	/**
	 * findByExternalId() が渡された external_id をそのまま meta_query の value として
	 * 素通しするだけで、listing/offer の中身を一切参照しないことを固定する。
	 *
	 * 「後続 offer の external_id でも検索できる」という実質のカバレッジは
	 * test_全ての購入リンクのexternal_idがミラーされる() が担う（mirror 側に複数値が
	 * 正しく書き込まれていることを検証しており、その値のどれで検索しても
	 * findByExternalId() の実装は同じ meta_query を組み立てるだけだから引ける）。
	 * このテスト自体は get_posts() の呼び出し引数だけを見ており mirror を経由しないため、
	 * 旧実装（1 platform 1 値しかミラーしない）でも通ってしまう。
	 */
	public function test_findByExternalIdは渡されたexternal_idをそのままmeta_queryに渡す(): void {
		WP_Mock::userFunction( 'get_posts' )
			->once()
			->andReturnUsing(
				function ( $args ) {
					$this->assertSame(
						ProductPostType::externalIdMetaKey( 'rakuten-kobo' ),
						$args['meta_query'][0]['key']
					);
					$this->assertSame( 'normal', $args['meta_query'][0]['value'] );
					$this->assertSame( '=', $args['meta_query'][0]['compare'] );
					return array( 777 );
				}
			);

		$post = (object) array(
			'ID'            => 777,
			'post_type'     => ProductPostType::POST_TYPE,
			'post_title'    => 'Y',
			'post_content'  => '',
			'post_status'   => 'publish',
			'post_modified' => '2026-09-11 00:00:00',
		);
		WP_Mock::userFunction( 'get_post' )
			->with( 777 )
			->andReturn( $post );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 777, Mockery::any(), true )
			->andReturn( '' );

		$repo   = new ProductRepository();
		$result = $repo->findByExternalId( 'rakuten-kobo', 'normal' );

		$this->assertNotNull( $result );
		$this->assertSame( 777, $result['id'] );
	}
}
