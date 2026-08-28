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

	public function test_registers_last_published_at_as_read_only_meta(): void {
		$reg = $this->captureRegistrations();

		$this->assertArrayHasKey( ProductPostType::META_LAST_PUBLISHED_AT, $reg );
		[ $post_type, $args ] = $reg[ ProductPostType::META_LAST_PUBLISHED_AT ];
		$this->assertSame( ProductPostType::POST_TYPE, $post_type );
		$this->assertSame( 'string', $args['type'] );
		$this->assertTrue( $args['single'] );
		// REST に露出させない。露出させたまま auth_callback を false にすると Gutenberg が
		// 読み取った meta を保存時に送り返し、投稿保存が丸ごと 403 rest_cannot_update で失敗する。
		$this->assertFalse( $args['show_in_rest'] );
		$this->assertIsCallable( $args['auth_callback'] );
		// cap でも拒否する（REST 以外の書き込み経路を塞ぐ）。書き込みは PublicationDate::touch() のみ。
		$this->assertFalse( ( $args['auth_callback'] )() );
	}

	/**
	 * REST に露出する meta は必ず編集者が書き込める（auth_callback が true を返す）こと。
	 *
	 * Gutenberg の useEntityProp は REST 応答の `meta` を丸ごと読み取り、保存時に丸ごと送り返す。
	 * そのため「REST には出るが書き込みは拒否」という meta が 1 つでもあると、その CPT の
	 * 投稿保存すべてが 403 rest_cannot_update で失敗する（実測: e2e の商品サイドバー保存が全滅）。
	 * read-only にしたい meta は show_in_rest=false にすること。
	 */
	public function test_every_rest_exposed_meta_is_writable_by_editors(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );

		foreach ( $this->captureRegistrations() as $key => $entry ) {
			[ , $args ] = $entry;
			if ( empty( $args['show_in_rest'] ) ) {
				continue;
			}
			$this->assertTrue(
				( $args['auth_callback'] )(),
				"meta '{$key}' は REST に露出しているのに auth_callback が書き込みを拒否している。"
					. ' Gutenberg の保存が 403 rest_cannot_update で失敗するため、'
					. ' read-only にしたいなら show_in_rest=false にすること。'
			);
		}
	}

	public function test_registers_mask_meta_fields(): void {
		$reg = $this->captureRegistrations();

		$this->assertArrayHasKey( ProductPostType::META_MASK_BLUR, $reg );
		[ $post_type, $blur_args ] = $reg[ ProductPostType::META_MASK_BLUR ];
		$this->assertSame( ProductPostType::POST_TYPE, $post_type );
		$this->assertSame( 'boolean', $blur_args['type'] );
		$this->assertTrue( $blur_args['single'] );
		$this->assertFalse( $blur_args['default'] );
		$this->assertTrue( $blur_args['show_in_rest'] );
		$this->assertIsCallable( $blur_args['auth_callback'] );
		$this->assertIsCallable( $blur_args['sanitize_callback'] );

		$this->assertArrayHasKey( ProductPostType::META_MASK_R18, $reg );
		[ , $r18_args ] = $reg[ ProductPostType::META_MASK_R18 ];
		$this->assertSame( 'boolean', $r18_args['type'] );
		$this->assertTrue( $r18_args['single'] );
		$this->assertFalse( $r18_args['default'] );
		$this->assertTrue( $r18_args['show_in_rest'] );
		$this->assertIsCallable( $r18_args['auth_callback'] );
		$this->assertIsCallable( $r18_args['sanitize_callback'] );

		$this->assertArrayHasKey( ProductPostType::META_MASK_LABEL, $reg );
		[ , $label_args ] = $reg[ ProductPostType::META_MASK_LABEL ];
		$this->assertSame( 'string', $label_args['type'] );
		$this->assertTrue( $label_args['single'] );
		$this->assertSame( '', $label_args['default'] );
		$this->assertTrue( $label_args['show_in_rest'] );
		$this->assertIsCallable( $label_args['auth_callback'] );
		$this->assertIsCallable( $label_args['sanitize_callback'] );
	}
}
