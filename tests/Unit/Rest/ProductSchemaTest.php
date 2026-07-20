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
		$this->assertSame( '600', $result[0]['price'] );
		$this->assertSame( 'https://example.com/r', $result[0]['regular_url'] );
		$this->assertSame( '', $result[0]['affiliate_url'] );
		$this->assertSame( '', $result[0]['external_id'] );
		$this->assertSame( '', $result[0]['list_price'] );
		$this->assertSame( '', $result[0]['badge'] );
		$this->assertSame( '', $result[0]['image_url'] );
		$this->assertSame( '', $result[0]['button_label_override'] );
		$this->assertSame( '', $result[0]['last_fetched_at'] );
		$this->assertSame( '', $result[0]['fetch_error'] );
		$this->assertSame( array(), $result[0]['platform_extras'] );
		$this->assertSame( 'auto', $result[0]['update_mode'] );
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
					'last_verified_at' => '2026-07-20T17:00:00+00:00',
					'search_key'       => '架空作品タイトル 3',
				),
			)
		);

		$this->assertCount( 1, $result );
		$this->assertSame( '2026-07-20T17:00:00+00:00', $result[0]['last_verified_at'] );
		$this->assertSame( '架空作品タイトル 3', $result[0]['search_key'] );
	}

	public function test_sanitize_listings_defaults_last_verified_at_and_search_key_to_empty(): void {
		$result = ProductSchema::sanitizeListings(
			array(
				array(
					'platform' => 'dmm-books',
					'price'    => '600',
				),
			)
		);

		$this->assertSame( '', $result[0]['last_verified_at'] );
		$this->assertSame( '', $result[0]['search_key'] );
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
}
