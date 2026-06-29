<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\PostType;

use Affilicard\PostType\ProductMeta;
use Affilicard\PostType\ProductPostType;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ProductMetaTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0:string,1:array<string,mixed>}>
	 */
	private function captureRegistrations(): array {
		$registered = array();
		WP_Mock::userFunction( 'register_post_meta' )->andReturnUsing(
			function ( $post_type, $key, $args ) use ( &$registered ) {
				$registered[ $key ] = array( $post_type, $args );
				return true;
			}
		);
		ProductMeta::register();
		return $registered;
	}

	public function test_registers_listings_and_extras_as_array_meta_with_schema(): void {
		$reg = $this->captureRegistrations();

		foreach ( array( ProductPostType::META_LISTINGS, ProductPostType::META_EXTRAS ) as $key ) {
			$this->assertArrayHasKey( $key, $reg );
			[ $post_type, $args ] = $reg[ $key ];
			$this->assertSame( ProductPostType::POST_TYPE, $post_type );
			$this->assertSame( 'array', $args['type'] );
			$this->assertTrue( $args['single'] );
			$this->assertSame( array(), $args['default'] );
			// show_in_rest は schema 付き配列
			$this->assertIsArray( $args['show_in_rest'] );
			$this->assertArrayHasKey( 'schema', $args['show_in_rest'] );
			$this->assertSame( 'array', $args['show_in_rest']['schema']['type'] );
			$this->assertSame( 'object', $args['show_in_rest']['schema']['items']['type'] );
			$this->assertTrue( $args['show_in_rest']['schema']['items']['additionalProperties'] );
			$this->assertIsCallable( $args['auth_callback'] );
			$this->assertIsCallable( $args['sanitize_callback'] );
		}
	}

	public function test_registers_scalar_meta_as_string(): void {
		$reg = $this->captureRegistrations();
		foreach ( array( ProductPostType::META_PRODUCT_TYPE, ProductPostType::META_STOCK_STATUS ) as $key ) {
			$this->assertArrayHasKey( $key, $reg );
			[ , $args ] = $reg[ $key ];
			$this->assertSame( 'string', $args['type'] );
			$this->assertTrue( $args['show_in_rest'] );
			$this->assertIsCallable( $args['auth_callback'] );
			$this->assertIsCallable( $args['sanitize_callback'] );
		}
	}

	public function test_listings_sanitize_callback_uses_product_schema(): void {
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn( $v ) => $v );

		$reg = $this->captureRegistrations();
		$cb  = $reg[ ProductPostType::META_LISTINGS ][1]['sanitize_callback'];

		$in  = array(
			array(
				'platform'      => 'dmm-books',
				'affiliate_url' => 'https://a',
				'enabled'       => true,
			),
		);
		$out = $cb( $in );
		$this->assertIsArray( $out );
		$this->assertSame( 'dmm-books', $out[0]['platform'] );

		// platform 空のエントリは除外される
		$out2 = $cb( array( array( 'platform' => '' ) ) );
		$this->assertIsArray( $out2 );
		$this->assertSame( array(), $out2 );
	}

	public function test_listings_sanitize_excludes_unknown_fields_and_is_idempotent(): void {
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn( $v ) => $v );

		$reg = $this->captureRegistrations();
		$cb  = $reg[ ProductPostType::META_LISTINGS ][1]['sanitize_callback'];

		$in   = array(
			array(
				'platform'      => 'dmm-books',
				'unknown_field' => '<script>x</script>',
			),
		);
		$once = $cb( $in );
		$this->assertArrayNotHasKey( 'unknown_field', $once[0] );

		// 二重適用で結果が変わらない（冪等）
		$twice = $cb( $once );
		$this->assertSame( $once, $twice );
	}

	public function test_extras_sanitize_excludes_unknown_fields_and_is_idempotent(): void {
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => $v );

		$reg = $this->captureRegistrations();
		$cb  = $reg[ ProductPostType::META_EXTRAS ][1]['sanitize_callback'];

		$in   = array(
			array(
				'label'         => '著者',
				'value'         => '架空 太郎',
				'unknown_field' => '<script>x</script>',
			),
		);
		$once = $cb( $in );
		$this->assertArrayNotHasKey( 'unknown_field', $once[0] );
		$this->assertSame( '著者', $once[0]['label'] );

		// 二重適用で結果が変わらない（冪等）
		$twice = $cb( $once );
		$this->assertSame( $once, $twice );
	}

	public function test_registers_release_date_as_string_meta(): void {
		$reg = $this->captureRegistrations();

		$this->assertArrayHasKey( ProductPostType::META_RELEASE_DATE, $reg );
		[ $post_type, $args ] = $reg[ ProductPostType::META_RELEASE_DATE ];
		$this->assertSame( ProductPostType::POST_TYPE, $post_type );
		$this->assertSame( 'string', $args['type'] );
		$this->assertTrue( $args['single'] );
		$this->assertSame( '', $args['default'] );
		$this->assertTrue( $args['show_in_rest'] );
		$this->assertIsCallable( $args['auth_callback'] );
		$this->assertIsCallable( $args['sanitize_callback'] );
	}

	public function test_release_date_sanitize_callback_delegates_to_product_schema(): void {
		$reg = $this->captureRegistrations();
		$cb  = $reg[ ProductPostType::META_RELEASE_DATE ][1]['sanitize_callback'];

		// 正当な YYYY-MM-DD はそのまま返す
		$this->assertSame( '2026-12-31', $cb( '2026-12-31' ) );

		// 不正な値は空文字を返す
		$this->assertSame( '', $cb( 'not-a-date' ) );
		$this->assertSame( '', $cb( '' ) );
	}
}
