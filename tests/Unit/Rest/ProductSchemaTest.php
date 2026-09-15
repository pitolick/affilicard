<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Rest;

use Affilicard\Rest\ProductSchema;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ProductSchemaTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( 'sanitize_text_field' )
			->andReturnUsing(
				static function ( $value ) {
					return is_scalar( $value ) ? trim( (string) $value ) : '';
				}
			);
		WP_Mock::userFunction( 'sanitize_key' )
			->andReturnUsing(
				static function ( $value ) {
					$value = is_scalar( $value ) ? strtolower( (string) $value ) : '';
					return preg_replace( '/[^a-z0-9_\-]/', '', $value );
				}
			);
		WP_Mock::userFunction( 'esc_url_raw' )
			->andReturnUsing(
				static function ( $value ) {
					return is_scalar( $value ) ? (string) $value : '';
				}
			);
		WP_Mock::userFunction( 'wp_kses_post' )
			->andReturnUsing(
				static function ( $value ) {
					return is_scalar( $value ) ? (string) $value : '';
				}
			);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_args_includes_required_title_field(): void {
		$args = ProductSchema::args();

		$this->assertArrayHasKey( 'title', $args );
		$this->assertTrue( $args['title']['required'] );
		$this->assertSame( 'string', $args['title']['type'] );

		$this->assertArrayHasKey( 'status', $args );
		$this->assertContains( 'publish', $args['status']['enum'] );
		$this->assertContains( 'draft', $args['status']['enum'] );

		$this->assertArrayHasKey( 'extras', $args );
		$this->assertSame( 'array', $args['extras']['type'] );

		$this->assertArrayHasKey( 'listings', $args );
		$this->assertSame( 'array', $args['listings']['type'] );
	}

	public function test_sanitize_extras_strips_empties_and_preserves_valid_hybrid_rows(): void {
		$input = array(
			array(
				'key'   => 'author',
				'label' => '著者',
				'value' => 'たろう',
			),
			array(
				'label' => '',
				'value' => '',
			),
			array(
				'label' => 'ページ数',
				'value' => '200',
			),
			'not-an-array',
		);

		$result = ProductSchema::sanitizeExtras( $input );

		$this->assertCount( 2, $result );
		$this->assertSame( 'author', $result[0]['key'] );
		$this->assertSame( '著者', $result[0]['label'] );
		$this->assertSame( 'たろう', $result[0]['value'] );
		$this->assertSame( 'ページ数', $result[1]['label'] );
		$this->assertSame( '200', $result[1]['value'] );
		$this->assertArrayNotHasKey( 'key', $result[1] );
	}

	public function test_sanitize_extras_returns_empty_when_not_array(): void {
		$this->assertSame( array(), ProductSchema::sanitizeExtras( 'string' ) );
		$this->assertSame( array(), ProductSchema::sanitizeExtras( null ) );
	}

	public function test_sanitize_listings_defaults_missing_fields_and_coerces_booleans(): void {
		$input = array(
			array(
				'platform'    => 'dmm-books',
				'enabled'     => 1,
				'auto_update' => 0,
				'price'       => '600',
				'regular_url' => 'https://example.com/r',
			),
			array(
				// platform 欠損 → 除外される
				'price' => '500',
			),
			'not-an-array',
		);

		$result = ProductSchema::sanitizeListings( $input );

		$this->assertCount( 1, $result );
		$this->assertSame( 'dmm-books', $result[0]['platform'] );
		$this->assertTrue( $result[0]['enabled'] );
		$this->assertFalse( $result[0]['auto_update'] );
		$this->assertSame( '', $result[0]['button_label_override'] );
		$this->assertSame( array(), $result[0]['platform_extras'] );
		$this->assertSame( 'auto', $result[0]['update_mode'] );

		$this->assertCount( 1, $result[0]['offers'] );
		$offer = $result[0]['offers'][0];
		$this->assertSame( '600', $offer['price'] );
		$this->assertSame( 'https://example.com/r', $offer['regular_url'] );
		$this->assertSame( '', $offer['affiliate_url'] );
		$this->assertSame( '', $offer['external_id'] );
		$this->assertSame( '', $offer['list_price'] );
		$this->assertSame( '', $offer['badge'] );
		$this->assertSame( '', $offer['image_url'] );
		$this->assertSame( '', $offer['last_fetched_at'] );
	}

	public function test_sanitize_listings_returns_empty_when_not_array(): void {
		$this->assertSame( array(), ProductSchema::sanitizeListings( 'foo' ) );
		$this->assertSame( array(), ProductSchema::sanitizeListings( null ) );
	}

	/**
	 * last_verified_at（価格鮮度ゲートの基準）と search_key（楽天 refresh の検索キー）が
	 * サニタイズで欠落しないことを保証する。register_post_meta の sanitize_callback が
	 * この whitelist を通すため、ここから漏れると保存時に消え、価格が永続的に非表示になる。
	 */
	public function test_sanitize_listings_preserves_last_verified_at_and_search_key(): void {
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform'         => 'rakuten-kobo',
					'price'            => '693',
					'regular_url'      => 'https://example.test/verified',
					'last_verified_at' => '2026-07-20T17:00:00+00:00',
					'search_key'       => '架空作品タイトル 3',
				),
			)
		);

		$this->assertCount( 1, $result );
		$this->assertCount( 1, $result[0]['offers'] );
		$this->assertSame( '2026-07-20T17:00:00+00:00', $result[0]['offers'][0]['last_verified_at'] );
		$this->assertSame( '架空作品タイトル 3', $result[0]['offers'][0]['search_key'] );
	}

	public function test_sanitize_listings_defaults_last_verified_at_and_search_key_to_empty(): void {
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform'    => 'dmm-books',
					'price'       => '600',
					'regular_url' => 'https://example.test/dmm',
				),
			)
		);

		$this->assertCount( 1, $result[0]['offers'] );
		$this->assertSame( '', $result[0]['offers'][0]['last_verified_at'] );
		$this->assertSame( '', $result[0]['offers'][0]['search_key'] );
	}

	public function test_offersを保持する(): void {
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array(
						array(
							'display_order' => 10,
							'external_id'   => 'sale',
							'regular_url'   => 'https://example.test/sale',
							'affiliate_url' => 'https://af.example.test/sale',
							'price'         => '0',
							'fetch_status'  => 'terminal',
						),
					),
				),
			)
		);

		$this->assertCount( 1, $result[0]['offers'] );
		$this->assertSame( 10, $result[0]['offers'][0]['display_order'] );
		$this->assertSame( 'sale', $result[0]['offers'][0]['external_id'] );
		$this->assertSame( 'terminal', $result[0]['offers'][0]['fetch_status'] );
	}

	public function test_flatな取得結果はoffers0へ畳まれる(): void {
		// v3 以前の形で送られてきても壊れないこと（外部の投稿パイプライン向けの互換）。
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform'    => 'rakuten-kobo',
					'external_id' => 'legacy',
					'regular_url' => 'https://example.test/legacy',
					'price'       => '660',
				),
			)
		);

		$this->assertCount( 1, $result[0]['offers'] );
		$this->assertSame( 'legacy', $result[0]['offers'][0]['external_id'] );
		$this->assertSame( '660', $result[0]['offers'][0]['price'] );
		$this->assertSame( 100, $result[0]['offers'][0]['display_order'] );
		$this->assertArrayNotHasKey( 'external_id', $result[0] );
		$this->assertArrayNotHasKey( 'fetch_error', $result[0] );
	}

	public function test_offersがあればflatは無視する(): void {
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform'    => 'rakuten-kobo',
					'external_id' => 'flat',
					'regular_url' => 'https://example.test/flat',
					'offers'      => array(
						array(
							'external_id' => 'nested',
							'regular_url' => 'https://example.test/nested',
						),
					),
				),
			)
		);

		$this->assertCount( 1, $result[0]['offers'] );
		$this->assertSame( 'nested', $result[0]['offers'][0]['external_id'] );
	}

	public function test_regular_urlもexternal_idも無いofferは弾く(): void {
		// 識別子を 1 つも持たない offer は、生死の判定も再同定もできず永久に残る。
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array(
						array(
							'external_id'   => '',
							'regular_url'   => '',
							'affiliate_url' => 'https://aff.test/orphan',
						),
						array(
							'external_id' => 'ok',
							'regular_url' => 'https://example.test/ok',
						),
					),
				),
			)
		);

		$this->assertCount( 1, $result[0]['offers'] );
		$this->assertSame( 'ok', $result[0]['offers'][0]['external_id'] );
	}

	/**
	 * external_id を持つ offer は regular_url が空でも保存で消さない。
	 *
	 * spec §3-4 の身元は「external_id、無ければ regular_url」であり、external_id が
	 * あれば再同定できる。regular_url 必須は新規入力向けのルール（§3-3）であって、
	 * 保存のたびに既存データへ遡って適用してよいものではない——移行が温存した
	 * 購入リンクが、同じ商品の別プラットフォームの価格更新（ProductRepository::
	 * updateListing() が全 listing を再 sanitize する）で数時間後に消えていた。
	 */
	public function test_external_idを持つofferはregular_urlが空でも残す(): void {
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array(
						array(
							'external_id'   => 'rescued',
							'regular_url'   => '',
							'affiliate_url' => 'https://aff.test/rescued',
						),
					),
				),
			)
		);

		$this->assertCount( 1, $result[0]['offers'], 'external_id を持つ購入リンクが保存で消えている' );
		$this->assertSame( 'rescued', $result[0]['offers'][0]['external_id'] );
		$this->assertSame( 'https://aff.test/rescued', $result[0]['offers'][0]['affiliate_url'] );
	}

	/**
	 * flat な listing を offers[0] へ畳むとき、v3 以前の fetch_error（日本語の文言）を
	 * fetch_status へ写す。
	 *
	 * 写さないと fetch_status は空＝成功になる。移行バッチが到達する前に通常の保存
	 * （別プラットフォームの価格更新など）が走った商品は、恒久失敗している購入リンクを
	 * 「取得成功」として持ち続け、以後どの経路でも失敗が見えなくなる。
	 */
	public function test_flatなfetch_errorはfetch_statusへ写像される(): void {
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform'    => 'rakuten-kobo',
					'external_id' => 'legacy',
					'regular_url' => 'https://example.test/legacy',
					'fetch_error' => '該当する商品が見つかりませんでした',
				),
			)
		);

		$this->assertSame( 'terminal', $result[0]['offers'][0]['fetch_status'] );
	}

	public function test_flatな未知のfetch_errorはtransientへ倒れる(): void {
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform'    => 'rakuten-kobo',
					'external_id' => 'legacy',
					'regular_url' => 'https://example.test/legacy',
					'fetch_error' => 'HTTP 503',
				),
			)
		);

		$this->assertSame( 'transient', $result[0]['offers'][0]['fetch_status'] );
	}

	public function test_識別子が重複するofferは後勝ちでマージする(): void {
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array(
						array(
							'external_id' => 'dup',
							'regular_url' => 'https://example.test/a',
							'price'       => '100',
						),
						array(
							'external_id' => 'dup',
							'regular_url' => 'https://example.test/b',
							'price'       => '200',
						),
					),
				),
			)
		);

		$this->assertCount( 1, $result[0]['offers'] );
		$this->assertSame( '200', $result[0]['offers'][0]['price'] );
	}

	/**
	 * 後勝ちでマージするとき、配列上の位置も後の出現に合わせる。
	 *
	 * 値だけ差し替えて最初の位置に残すと、display_order が同値のときの並び
	 * （OfferSelector は同順位を配列の出現順で解く）が入力順とずれる。
	 * ここでは同じ身元の A・別の B・再び A を同順位で並べる。入力順では
	 * B → A なので、マージ後も B が先に来なければならない。
	 */
	public function test_後勝ちのマージは配列上の位置も後の出現に合わせる(): void {
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array(
						array(
							'display_order' => 100,
							'external_id'   => 'dup',
							'regular_url'   => 'https://example.test/a',
							'price'         => '100',
						),
						array(
							'display_order' => 100,
							'external_id'   => 'other',
							'regular_url'   => 'https://example.test/other',
							'price'         => '150',
						),
						array(
							'display_order' => 100,
							'external_id'   => 'dup',
							'regular_url'   => 'https://example.test/b',
							'price'         => '200',
						),
					),
				),
			)
		);

		$offers = $result[0]['offers'];
		$this->assertCount( 2, $offers );
		$this->assertSame( 'other', $offers[0]['external_id'], '後勝ちした offer が先頭に居座っている' );
		$this->assertSame( 'dup', $offers[1]['external_id'] );
		$this->assertSame( '200', $offers[1]['price'] );
	}

	public function test_external_idが空ならregular_urlが識別子になる(): void {
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array(
						array(
							'external_id' => '',
							'regular_url' => 'https://example.test/same',
							'price'       => '100',
						),
						array(
							'external_id' => '',
							'regular_url' => 'https://example.test/same',
							'price'       => '200',
						),
						array(
							'external_id' => '',
							'regular_url' => 'https://example.test/other',
							'price'       => '300',
						),
					),
				),
			)
		);

		$this->assertCount( 2, $result[0]['offers'] );
	}

	public function test_listingの設定フィールドは不変(): void {
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform'              => 'rakuten-kobo',
					'enabled'               => false,
					'auto_update'           => false,
					'update_mode'           => 'manual',
					'button_label_override' => 'いますぐ買う',
					'platform_extras'       => array( 'k' => 'v' ),
					'offers'                => array(
						array(
							'external_id' => 'x',
							'regular_url' => 'https://example.test/x',
						),
					),
				),
			)
		);

		$this->assertFalse( $result[0]['enabled'] );
		$this->assertFalse( $result[0]['auto_update'] );
		$this->assertSame( 'manual', $result[0]['update_mode'] );
		$this->assertSame( 'いますぐ買う', $result[0]['button_label_override'] );
		$this->assertSame( array( 'k' => 'v' ), $result[0]['platform_extras'] );
	}

	public function test_args_requires_title_for_create(): void {
		$args = ProductSchema::args();
		$this->assertTrue( $args['title']['required'] ?? false );
	}

	public function test_sanitize_item_normalizes_fields_and_reuses_sanitizers(): void {
		$item = array(
			'title'        => '  サンプル商品 <b>x</b> ',
			'content'      => '<p>本文</p><script>alert(1)</script>',
			'status'       => 'invalid-status',
			'product_type' => 'VOD',
			'stock_status' => 'bogus',
			'extras'       => array(
				array(
					'key'   => 'director',
					'label' => '監督',
					'value' => 'A',
				),
				array(
					'label' => '',
					'value' => '',
				),
			),
			'listings'     => array(
				array(
					'platform'      => 'u-next',
					'affiliate_url' => 'https://example.com/x',
				),
				array( 'no_platform' => true ),
			),
		);

		$clean = ProductSchema::sanitizeItem( $item );

		// wp_kses_post モックは値をそのまま返すため content はそのまま保持される。
		$this->assertSame( '<p>本文</p><script>alert(1)</script>', $clean['content'] );
		// sanitize_text_field モックは trim のみなので title はタグを含んだまま trim される。
		$this->assertSame( 'サンプル商品 <b>x</b>', $clean['title'] );
		$this->assertSame( 'publish', $clean['status'] );       // invalid enum → default
		$this->assertSame( 'vod', $clean['product_type'] );     // sanitize_key lowercases
		$this->assertSame( 'available', $clean['stock_status'] ); // invalid → default
		$this->assertCount( 1, $clean['extras'] );              // empty row removed
		$this->assertSame( 'director', $clean['extras'][0]['key'] );
		$this->assertCount( 1, $clean['listings'] );            // no-platform row removed
		$this->assertSame( 'u-next', $clean['listings'][0]['platform'] );
	}

	public function test_sanitize_item_keeps_valid_release_date(): void {
		$out = \Affilicard\Rest\ProductSchema::sanitizeItem(
			array(
				'title'        => 'X 5巻',
				'release_date' => '2026-07-17',
			)
		);
		$this->assertSame( '2026-07-17', $out['release_date'] );
	}

	public function test_sanitize_item_clears_invalid_release_date(): void {
		$out = \Affilicard\Rest\ProductSchema::sanitizeItem(
			array(
				'title'        => 'X 5巻',
				'release_date' => '2026/07/17',
			)
		);
		$this->assertSame( '', $out['release_date'] );
	}

	public function test_updateArgs_is_partial_no_required_no_defaults(): void {
		// PATCH（部分更新）用 args は required / default を持たず、
		// 未指定フィールドが null（未送信）扱いになるようにする。
		$args = ProductSchema::updateArgs();

		$this->assertArrayHasKey( 'title', $args );
		$this->assertArrayNotHasKey( 'required', $args['title'] );
		$this->assertArrayNotHasKey( 'default', $args['content'] );
		$this->assertArrayNotHasKey( 'default', $args['status'] );
		$this->assertArrayNotHasKey( 'default', $args['product_type'] );
		$this->assertArrayNotHasKey( 'default', $args['stock_status'] );
		$this->assertArrayNotHasKey( 'default', $args['extras'] );
		$this->assertArrayNotHasKey( 'default', $args['listings'] );

		// sanitize_callback は維持される（送信された値は引き続き sanitize する）。
		$this->assertArrayHasKey( 'sanitize_callback', $args['listings'] );
	}

	/**
	 * REST の入力は配列・オブジェクトを含み得る。`(string)` キャストは配列を
	 * "Array"（＋警告）に、__toString を持たないオブジェクトを致命的エラーにする。
	 * 前者は「身元が "Array" の購入リンク」が保存される形で沈黙するため、
	 * 非スカラーは空文字として扱う。
	 */
	public function test_sanitizeListings_非スカラーの購入リンクフィールドは空文字にする(): void {
		$got = ProductSchema::sanitizeListings(
			array(
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array(
						array(
							'external_id'  => 'ok-1',
							'regular_url'  => array( 'https://example.test/a' ),
							'price'        => array( '660' ),
							'badge'        => array(),
							'search_key'   => new \stdClass(),
							'fetch_status' => array( 'terminal' ),
						),
					),
				),
			)
		);

		$offer = $got[0]['offers'][0];
		// スカラーのフィールドは従来どおり。
		$this->assertSame( 'ok-1', $offer['external_id'] );
		// 非スカラーは "Array" ではなく空文字。
		$this->assertSame( '', $offer['regular_url'] );
		$this->assertSame( '', $offer['price'] );
		$this->assertSame( '', $offer['badge'] );
		$this->assertSame( '', $offer['search_key'] );
		$this->assertSame( '', $offer['fetch_status'] );
	}

	public function test_sanitizeListings_身元が非スカラーだけの購入リンクは捨てる(): void {
		// "Array" という身元で保存されると、再同定も生死判定もできない購入リンクが残る。
		$got = ProductSchema::sanitizeListings(
			array(
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array(
						array(
							'external_id' => array( 'x' ),
							'regular_url' => array( 'https://example.test/a' ),
							'price'       => '660',
						),
					),
				),
			)
		);

		$this->assertSame( array(), $got[0]['offers'] );
	}

	public function test_sanitizeListings_platformが非スカラーならlistingごと捨てる(): void {
		$got = ProductSchema::sanitizeListings(
			array(
				array(
					'platform' => array( 'rakuten-kobo' ),
					'offers'   => array(
						array( 'external_id' => 'ok-1' ),
					),
				),
				array(
					'platform' => 'dmm-books',
					'offers'   => array(
						array( 'external_id' => 'ok-2' ),
					),
				),
			)
		);

		$this->assertCount( 1, $got );
		$this->assertSame( 'dmm-books', $got[0]['platform'] );
	}
}
